<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
require_once '/var/www/noosphere/shared/analytics.php';
sec_session_start();

$db = new SQLite3('/var/lib/noosphere/registry.db');
$db->exec("CREATE TABLE IF NOT EXISTS registry (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    location TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'OK',
    notes TEXT,
    pin TEXT NOT NULL,
    entry_type TEXT DEFAULT 'checkin',
    is_child INTEGER DEFAULT 0,
    age TEXT,
    photo TEXT,
    is_admin INTEGER DEFAULT 0,
    extra_fields TEXT,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
)");
// Legacy column migrations (keep for backward compat, no longer written)
foreach (['missing TEXT','skills TEXT','have TEXT','need TEXT','bunk TEXT',
          'dietary TEXT','next_of_kin TEXT','extra_fields TEXT'] as $col) {
    @$db->exec("ALTER TABLE registry ADD COLUMN $col");
}

// One-time migration: move old individual columns into extra_fields JSON
$db->exec("UPDATE registry
    SET extra_fields = json_object(
        'skills',       COALESCE(skills,''),
        'have',         COALESCE(have,''),
        'need',         COALESCE(need,''),
        'bunk',         COALESCE(bunk,''),
        'dietary',      COALESCE(dietary,''),
        'next_of_kin',  COALESCE(next_of_kin,'')
    )
    WHERE extra_fields IS NULL OR extra_fields = ''");

$photo_dir = '/var/lib/noosphere/registry_photos/';
if (!is_dir($photo_dir)) mkdir($photo_dir, 0755, true);

$msg = ''; $error = '';

function save_photo($field) {
    global $photo_dir;
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) return null;
    if ($_FILES[$field]['size'] > 5 * 1024 * 1024) return null;
    if (!allowed_image_mime($_FILES[$field]['tmp_name'])) return null;
    $fname = 'reg_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    move_uploaded_file($_FILES[$field]['tmp_name'], $photo_dir . $fname);
    return $fname;
}

function collect_extra_fields() {
    $extra = [];
    foreach (get_registry_fields() as $field) {
        if (!$field['enabled']) continue;
        $k = $field['key'];
        if ($field['type'] === 'checkbox') {
            $extra[$k] = !empty($_POST['ef_' . $k]) ? '1' : '0';
        } else {
            $extra[$k] = trim($_POST['ef_' . $k] ?? '');
        }
    }
    return json_encode($extra ?: (object)[]);
}

// Admin delete
if (isset($_GET['del']) && !empty($_SESSION['reg_admin'])) {
    $del_id = (int)$_GET['del'];
    if ($del_id > 0) {
        $del_row = $db->querySingle("SELECT photo FROM registry WHERE id=$del_id", true);
        if ($del_row && $del_row['photo']) {
            $p = $photo_dir . $del_row['photo'];
            if (file_exists($p) && strpos(realpath($p), realpath($photo_dir)) === 0) unlink($p);
        }
        $s = $db->prepare('DELETE FROM registry WHERE id=?');
        $s->bindValue(1, $del_id, SQLITE3_INTEGER);
        $s->execute();
    }
    header('Location: /registry/');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    readonly_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'register') {
        if (get_setting('registry_allow_self_register','1') !== '1' &&
            empty($_SESSION['admin']) && empty($_SESSION['reg_admin'])) {
            http_response_code(403);
            $error = 'Self-registration is currently disabled.';
            goto skip_register;
        }
        ban_check_or_die();
        $entry_type = in_array($_POST['entry_type'] ?? '', ['checkin','found_person'])
                      ? $_POST['entry_type'] : 'checkin';
        $name     = trim($_POST['name']     ?? '');
        $location = trim($_POST['location'] ?? '');
        $valid_statuses = get_status_options();
        $status   = in_array($_POST['status'] ?? '', $valid_statuses)
                    ? $_POST['status'] : $valid_statuses[0];
        $notes    = trim($_POST['notes']    ?? '');
        $pin      = trim($_POST['pin']      ?? '');
        $age      = trim($_POST['age']      ?? '');
        $is_child = !empty($_POST['is_child']) ? 1 : 0;
        $photo    = save_photo('photo');
        $extra_json = ($entry_type === 'checkin') ? collect_extra_fields() : '{}';

        if (!$name || ($loc_required && !$location) || strlen($pin) < 4) {
            $error = 'Name' . ($loc_required ? ', location,' : '') . ' and a 4+ digit PIN are required.';
        } else {
            $pin_hash = password_hash($pin, PASSWORD_DEFAULT);
            $now = time();
            $s = $db->prepare("INSERT INTO registry
                (name,location,status,notes,pin,entry_type,is_child,age,photo,extra_fields,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $s->bindValue(1,$name); $s->bindValue(2,$location); $s->bindValue(3,$status);
            $s->bindValue(4,$notes); $s->bindValue(5,$pin_hash); $s->bindValue(6,$entry_type);
            $s->bindValue(7,$is_child,SQLITE3_INTEGER); $s->bindValue(8,$age);
            $s->bindValue(9,$photo); $s->bindValue(10,$extra_json);
            $s->bindValue(11,$now); $s->bindValue(12,$now);
            $s->execute();
            if ($entry_type === 'checkin') {
                mark_registered($name);
                $_SESSION['reg_name'] = $name;
                $msg = 'Registered. Remember your PIN to update later.';
            } else {
                $msg = 'Found person reported. Thank you.';
            }
        }
        skip_register:;
    }

    if ($action === 'update') {
        $id  = intval($_POST['id'] ?? 0);
        $pin = trim($_POST['pin'] ?? '');
        $row = $db->querySingle("SELECT pin,photo,is_admin FROM registry WHERE id=$id", true);
        rate_limit('pin', 5, 900);
        if ($row && password_verify($pin, $row['pin'])) {
            rate_reset('pin');
            if (!empty($row['is_admin'])) $_SESSION['reg_admin'] = true;
            $location = trim($_POST['location'] ?? '');
            $valid_statuses = get_status_options();
            $status   = in_array($_POST['status'] ?? '', $valid_statuses)
                        ? $_POST['status'] : $valid_statuses[0];
            $notes    = trim($_POST['notes']    ?? '');
            $age      = trim($_POST['age']      ?? '');
            $is_child = !empty($_POST['is_child']) ? 1 : 0;
            $photo    = save_photo('photo') ?? $row['photo'];
            $extra_json = collect_extra_fields();
            $now = time();
            $s = $db->prepare("UPDATE registry
                SET location=?,status=?,notes=?,updated_at=?,age=?,is_child=?,photo=?,extra_fields=?
                WHERE id=?");
            $s->bindValue(1,$location); $s->bindValue(2,$status); $s->bindValue(3,$notes);
            $s->bindValue(4,$now); $s->bindValue(5,$age);
            $s->bindValue(6,$is_child,SQLITE3_INTEGER); $s->bindValue(7,$photo);
            $s->bindValue(8,$extra_json); $s->bindValue(9,$id,SQLITE3_INTEGER);
            $s->execute();
            $msg = 'Entry updated.';
        } else {
            $error = 'Incorrect PIN.';
        }
    }
}

$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$where  = '1=1';
$loc_required = get_setting('registry_location_required','1') === '1';
$help_status_val = '';
foreach (get_status_options() as $so) {
    if (stripos($so, 'help') !== false) { $help_status_val = $so; break; }
}
$has_help_status = $help_status_val !== '';
if ($filter === 'help' && $has_help_status) $where .= " AND status='" . SQLite3::escapeString($help_status_val) . "'";
if ($filter === 'found_person') $where .= " AND entry_type='found_person'";
if ($filter === 'checkin')      $where .= " AND (entry_type='checkin' OR entry_type IS NULL)";
if ($filter === 'children')     $where .= " AND is_child=1";

if ($search) {
    $stmt = $db->prepare("SELECT * FROM registry WHERE $where
        AND (name LIKE ? OR location LIKE ? OR notes LIKE ? OR extra_fields LIKE ?)
        ORDER BY updated_at DESC");
    $like = '%' . $search . '%';
    $stmt->bindValue(1,$like); $stmt->bindValue(2,$like);
    $stmt->bindValue(3,$like); $stmt->bindValue(4,$like);
    $res = $stmt->execute();
} else {
    $res = $db->query("SELECT * FROM registry WHERE $where ORDER BY updated_at DESC");
}

$all        = [];
while ($r = $res->fetchArray(SQLITE3_ASSOC)) $all[] = $r;
$total      = $db->querySingle("SELECT COUNT(*) FROM registry");
$need_help  = $has_help_status ? $db->querySingle("SELECT COUNT(*) FROM registry WHERE status='" . SQLite3::escapeString($help_status_val) . "'") : 0;
$children   = $db->querySingle("SELECT COUNT(*) FROM registry WHERE is_child=1");
$fnd_person = $db->querySingle("SELECT COUNT(*) FROM registry WHERE entry_type='found_person'");

$edit_id   = intval($_GET['edit'] ?? 0);
$edit_row  = $edit_id ? $db->querySingle("SELECT * FROM registry WHERE id=$edit_id", true) : null;
$entry_type_default = $_GET['type'] ?? 'checkin';
$self_reg  = get_setting('registry_allow_self_register','1') === '1';
$is_admin  = !empty($_SESSION['admin']) || !empty($_SESSION['reg_admin']);
$reg_fields = get_registry_fields();

function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES); }
function ago($ts) {
    $d = time() - $ts;
    if ($d < 60) return 'just now';
    if ($d < 3600) return intval($d/60).'m ago';
    if ($d < 86400) return intval($d/3600).'h ago';
    return intval($d/86400).'d ago';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc(get_setting('registry_label','Registry')) ?> — Noosphere</title>
<style>
  :root { --bg:#0f0f1a; --card:#1a1a2e; --border:#2a2a4a; --accent:#e94560; --green:#2ecc71; --yellow:#f39c12; --text:#e0e0e0; --muted:#888; }
  * { box-sizing:border-box; margin:0; padding:0; }
  body { background:var(--bg); color:var(--text); font-family:system-ui,sans-serif; font-size:15px; }
  header { background:var(--card); border-bottom:2px solid var(--accent); padding:12px 20px; display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
  header a { color:var(--muted); text-decoration:none; font-size:13px; }
  header a:hover { color:var(--accent); }
  h1 { font-size:18px; color:var(--accent); }
  .stats { margin-left:auto; font-size:12px; color:var(--muted); display:flex; gap:14px; flex-wrap:wrap; }
  .stats span { color:var(--text); font-weight:bold; }
  .container { max-width:1100px; margin:0 auto; padding:16px; }
  .msg { background:#1a3a2a; border:1px solid var(--green); color:var(--green); padding:10px 16px; border-radius:6px; margin-bottom:14px; }
  .err { background:#3a1a1a; border:1px solid var(--accent); color:var(--accent); padding:10px 16px; border-radius:6px; margin-bottom:14px; }
  .grid { display:grid; grid-template-columns:320px 1fr; gap:18px; align-items:start; }
  @media(max-width:760px) { .grid { grid-template-columns:1fr; } }
  .panel { background:var(--card); border:1px solid var(--border); border-radius:10px; padding:18px; position:sticky; top:16px; }
  .panel h2 { font-size:14px; color:var(--accent); margin-bottom:12px; text-transform:uppercase; letter-spacing:.05em; }
  .type-tabs { display:flex; gap:4px; margin-bottom:14px; flex-wrap:wrap; }
  .type-tab { flex:1; text-align:center; padding:7px 4px; border-radius:6px; border:1px solid var(--border); font-size:12px; cursor:pointer; color:var(--muted); background:var(--bg); }
  .type-tab.active { border-color:var(--accent); color:var(--accent); background:#1a0d12; }
  label { display:block; font-size:12px; color:var(--muted); margin-bottom:3px; margin-top:9px; }
  input[type=text], input[type=password], input[type=number], textarea, select {
    width:100%; background:#111126; border:1px solid var(--border); color:var(--text);
    padding:7px 9px; border-radius:5px; font-size:13px; font-family:inherit;
  }
  input:focus, textarea:focus, select:focus { outline:none; border-color:var(--accent); }
  textarea { resize:vertical; min-height:52px; }
  .child-row { display:flex; align-items:center; gap:10px; margin-top:9px; }
  .child-row label { margin:0; display:flex; align-items:center; gap:6px; color:var(--text); font-size:13px; cursor:pointer; }
  .photo-row { margin-top:9px; }
  .photo-row input[type=file] { background:none; border:1px dashed var(--border); padding:6px; font-size:12px; color:var(--muted); }
  .btn { background:var(--accent); color:#fff; border:none; padding:9px 18px; border-radius:6px; cursor:pointer; font-size:13px; font-weight:bold; margin-top:12px; width:100%; }
  .btn:hover { opacity:.85; }
  .btn-sm { background:var(--border); border:none; padding:4px 10px; font-size:11px; margin-top:0; width:auto; border-radius:4px; cursor:pointer; color:var(--text); text-decoration:none; display:inline-block; }
  .btn-danger { background:none; border:1px solid #3a2a2a; color:var(--accent); padding:4px 10px; border-radius:4px; font-size:11px; cursor:pointer; text-decoration:none; display:inline-block; }
  .btn-danger:hover { background:#3a1a1a; }
  .cb-field { display:flex; align-items:center; gap:8px; margin-top:9px; }
  .cb-field input[type=checkbox] { width:auto; }
  .cb-field label { margin:0; color:var(--text); font-size:13px; cursor:pointer; }
  .filters { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; align-items:center; }
  .filters a { padding:5px 12px; border-radius:18px; font-size:12px; text-decoration:none; border:1px solid var(--border); color:var(--muted); white-space:nowrap; }
  .filters a.active, .filters a:hover { border-color:var(--accent); color:var(--accent); }
  .filters form { flex:1; min-width:140px; }
  .cards { display:flex; flex-direction:column; gap:10px; }
  .card { background:var(--card); border:1px solid var(--border); border-radius:8px; padding:14px; display:flex; gap:12px; }
  .card.child { border-color:#f39c12; }
  .card.found { border-left:3px solid #4a9eff; }
  .card-photo { width:72px; height:72px; border-radius:6px; object-fit:cover; flex-shrink:0; background:#111; }
  .card-photo-placeholder { width:72px; height:72px; border-radius:6px; background:#16213e; display:flex; align-items:center; justify-content:center; font-size:28px; flex-shrink:0; }
  .card-body { flex:1; min-width:0; }
  .card-header { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; flex-wrap:wrap; }
  .card-name { font-size:15px; font-weight:bold; }
  .badges { display:flex; gap:5px; flex-wrap:wrap; }
  .badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:bold; }
  .badge-ok      { background:#1a3a2a; color:var(--green); }
  .badge-help    { background:#3a1a1a; color:var(--accent); }
  .badge-checking{ background:#2a2a1a; color:var(--yellow); }
  .badge-child   { background:#2a200a; color:#f39c12; }
  .badge-found   { background:#0a1a3a; color:#4a9eff; }
  .card-loc { color:var(--muted); font-size:12px; margin-top:3px; }
  .card-row { margin-top:6px; font-size:12px; color:var(--muted); }
  .card-row span { color:var(--text); }
  .card-footer { margin-top:8px; font-size:11px; color:var(--muted); display:flex; justify-content:space-between; align-items:center; }
  .empty { text-align:center; color:var(--muted); padding:40px; }
  .pin-note { font-size:11px; color:var(--muted); margin-top:4px; }
</style>
</head>
<body>
<header>
  <div>
    <a href="/">&#x2190; Home</a>
    <h1><?= esc(get_setting('registry_label','Registry')) ?></h1>
  </div>
  <div class="stats">
    <div><span><?= $total ?></span> total</div>
    <?php if ($has_help_status): ?><div><span style="color:<?= $need_help > 0 ? 'var(--accent)' : 'var(--green)' ?>"><?= $need_help ?></span> need help</div><?php endif; ?>
    <?php if ($children > 0): ?><div><span style="color:#f39c12"><?= $children ?></span> children</div><?php endif; ?>
    <?php if ($fnd_person > 0): ?><div><span style="color:#4a9eff"><?= $fnd_person ?></span> found persons</div><?php endif; ?>
    <?php if (!empty($_SESSION['reg_admin'])): ?><div><span style="color:#f39c12">Admin mode</span></div><?php endif; ?>
  </div>
</header>

<div class="container">
  <?php if ($msg): ?><div class="msg"><?= esc($msg) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="err"><?= esc($error) ?></div><?php endif; ?>

  <div class="grid">
    <!-- FORM -->
    <div>
      <?php if ($self_reg || $is_admin || $edit_row): ?>
      <div class="panel">
        <h2><?= $edit_row ? 'Update Entry' : 'Add Entry' ?></h2>
        <?php $cur_type = $edit_row['entry_type'] ?? $entry_type_default; ?>

        <?php if (!$edit_row): ?>
        <div class="type-tabs">
          <?php if (get_setting('registry_checkin','1')==='1'): ?>
          <div class="type-tab <?= $cur_type==='checkin'?'active':'' ?>" onclick="setType('checkin')">Check In</div>
          <?php endif; ?>
          <?php if (get_setting('registry_found_person','1')==='1'): ?>
          <div class="type-tab <?= $cur_type==='found_person'?'active':'' ?>" onclick="setType('found_person')">Found Person</div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="action" value="<?= $edit_row ? 'update' : 'register' ?>">
          <input type="hidden" name="entry_type" id="entry_type" value="<?= esc($cur_type) ?>">
          <?= csrf_field() ?>

          <?php if ($edit_row): ?>
            <input type="hidden" name="id" value="<?= $edit_row['id'] ?>">
            <div style="font-size:12px;color:var(--muted);margin-bottom:8px">Updating: <strong><?= esc($edit_row['name']) ?></strong></div>
          <?php else: ?>
            <label id="name-label">Your Name *</label>
            <input type="text" name="name" required id="name-field" placeholder="Jane Smith">
          <?php endif; ?>

          <div class="child-row">
            <label><input type="checkbox" name="is_child" id="is_child" value="1" <?= !empty($edit_row['is_child']) ? 'checked' : '' ?>> Child (under 18)</label>
            <div style="flex:1">
              <input type="text" name="age" value="<?= esc($edit_row['age'] ?? '') ?>" placeholder="Age (e.g. 8)" style="width:100%">
            </div>
          </div>

          <label id="loc-label">Location <?= $loc_required ? '*' : '' ?></label>
          <input type="text" name="location" value="<?= esc($edit_row['location'] ?? '') ?>" <?= $loc_required ? 'required' : '' ?> id="loc-field" placeholder="123 Oak St / Shelter B / Near the dam">

          <div id="status-section">
            <label>Status</label>
            <select name="status">
              <?php $status_opts = get_status_options(); foreach ($status_opts as $s): ?>
                <option value="<?= esc($s) ?>" <?= ($edit_row['status'] ?? $status_opts[0]) === $s ? 'selected' : '' ?>><?= esc($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Dynamic extra fields — only shown for check-in -->
          <div id="checkin-section">
            <?php
            $edit_ef = $edit_row ? (json_decode($edit_row['extra_fields'] ?? '{}', true) ?: []) : [];
            foreach ($reg_fields as $field):
                if (!$field['enabled']) continue;
                $k   = $field['key'];
                $val = $edit_ef[$k] ?? '';
            ?>
              <?php if ($field['type'] === 'checkbox'): ?>
                <div class="cb-field">
                  <input type="checkbox" name="ef_<?= esc($k) ?>" value="1" id="ef_<?= esc($k) ?>" <?= $val === '1' ? 'checked' : '' ?>>
                  <label for="ef_<?= esc($k) ?>"><?= esc($field['label']) ?></label>
                </div>
              <?php elseif ($field['type'] === 'textarea'): ?>
                <label><?= esc($field['label']) ?></label>
                <textarea name="ef_<?= esc($k) ?>"><?= esc($val) ?></textarea>
              <?php else: ?>
                <label><?= esc($field['label']) ?></label>
                <input type="text" name="ef_<?= esc($k) ?>" value="<?= esc($val) ?>">
              <?php endif; ?>
            <?php endforeach; ?>
          </div>

          <label>Notes / Additional details</label>
          <textarea name="notes"><?= esc($edit_row['notes'] ?? '') ?></textarea>

          <div class="photo-row">
            <label>Photo <?php if ($edit_row && $edit_row['photo']): ?>(upload new to replace)<?php endif; ?></label>
            <input type="file" name="photo" accept="image/*">
            <div class="pin-note">Max 5MB / jpg, png, gif, webp</div>
            <?php if ($edit_row && $edit_row['photo']): ?>
              <img src="/registry/photos/<?= esc($edit_row['photo']) ?>" style="width:80px;height:80px;object-fit:cover;border-radius:6px;margin-top:6px">
            <?php endif; ?>
          </div>

          <label>PIN (4+ digits)</label>
          <input type="password" name="pin" required minlength="4" placeholder="<?= $edit_row ? 'Enter your PIN to confirm' : 'Choose a PIN — write it down' ?>">
          <?php if (!$edit_row): ?><div class="pin-note">You need this PIN to update your entry later.</div><?php endif; ?>

          <button type="submit" class="btn"><?= $edit_row ? 'Save Changes' : 'Submit' ?></button>
          <?php if ($edit_row): ?>
            <a href="/registry/" style="display:block;text-align:center;margin-top:8px;font-size:12px;color:var(--muted)">Cancel</a>
          <?php endif; ?>
        </form>
      </div>
      <?php else: ?>
      <div class="panel">
        <div style="color:#888;font-size:13px;padding:8px 0">Registration is currently managed by administrators.</div>
      </div>
      <?php endif; ?>
    </div>

    <!-- LIST -->
    <div>
      <div class="filters">
        <a href="/registry/"                           class="<?= $filter==='all'?'active':'' ?>">All (<?= $total ?>)</a>
        <a href="/registry/?filter=checkin"            class="<?= $filter==='checkin'?'active':'' ?>">Check-ins</a>
        <?php if ($has_help_status): ?>
        <a href="/registry/?filter=help"               class="<?= $filter==='help'?'active':'' ?>">Need Help (<?= $need_help ?>)</a>
        <?php endif; ?>
        <?php if ($children > 0): ?>
        <a href="/registry/?filter=children"           class="<?= $filter==='children'?'active':'' ?>">Children (<?= $children ?>)</a>
        <?php endif; ?>
        <?php if ($fnd_person): ?>
        <a href="/registry/?filter=found_person"       class="<?= $filter==='found_person'?'active':'' ?>">Found Persons (<?= $fnd_person ?>)</a>
        <?php endif; ?>
        <form method="get" style="flex:1;min-width:140px">
          <input type="hidden" name="filter" value="<?= esc($filter) ?>">
          <input type="text" name="q" value="<?= esc($search) ?>" placeholder="Search...">
        </form>
      </div>

      <div class="cards">
        <?php if (!$all): ?>
          <div class="empty">No entries found.</div>
        <?php endif; ?>
        <?php foreach ($all as $r):
          $etype    = $r['entry_type'] ?? 'checkin';
          $is_child = !empty($r['is_child']);
          $ef       = json_decode($r['extra_fields'] ?? '{}', true) ?: [];
          $card_class = 'card' . ($is_child ? ' child' : '') . ($etype !== 'checkin' ? ' found' : '');
          $status_bc  = [];
          foreach (get_status_options() as $i => $so) {
              $lc = strtolower($so);
              if (strpos($lc,'help') !== false) $status_bc[$so] = 'badge-help';
              elseif ($i === 0) $status_bc[$so] = 'badge-ok';
              else $status_bc[$so] = 'badge-checking';
          }
          $bclass = $status_bc[$r['status']] ?? 'badge-ok';
        ?>
          <div class="<?= $card_class ?>">
            <?php if ($r['photo']): ?>
              <img class="card-photo" src="/registry/photos/<?= esc($r['photo']) ?>" alt="Photo">
            <?php else: ?>
              <div class="card-photo-placeholder"><?= $is_child ? '&#x1F476;' : '&#x1F464;' ?></div>
            <?php endif; ?>
            <div class="card-body">
              <div class="card-header">
                <div>
                  <div class="card-name"><?= esc($r['name']) ?></div>
                  <div class="card-loc">&#x1F4CD; <?= esc($r['location']) ?></div>
                </div>
                <div class="badges">
                  <?php if ($etype === 'found_person'): ?>
                    <span class="badge badge-found">Found Person</span>
                  <?php else: ?>
                    <span class="badge <?= esc($bclass) ?>"><?= esc($r['status']) ?></span>
                  <?php endif; ?>
                  <?php if ($is_child): ?>
                    <span class="badge badge-child">Child<?= $r['age'] ? ' · Age '.$r['age'] : '' ?></span>
                  <?php elseif ($r['age']): ?>
                    <span class="badge" style="background:var(--border);color:var(--muted)">Age <?= esc($r['age']) ?></span>
                  <?php endif; ?>
                </div>
              </div>

              <?php foreach ($reg_fields as $field):
                if (!$field['enabled']) continue;
                $val = $ef[$field['key']] ?? '';
                if ($val === '' || $val === '0') continue;
              ?>
                <?php if ($field['type'] === 'checkbox'): ?>
                  <div class="card-row"><span><?= esc($field['label']) ?></span></div>
                <?php else: ?>
                  <div class="card-row"><span style="color:var(--muted)"><?= esc($field['label']) ?>: </span><span><?= esc($val) ?></span></div>
                <?php endif; ?>
              <?php endforeach; ?>

              <?php if ($r['notes']): ?>
                <div class="card-row"><span style="color:var(--muted)">Notes: </span><span><?= esc($r['notes']) ?></span></div>
              <?php endif; ?>

              <div class="card-footer">
                <span>Updated <?= ago($r['updated_at']) ?></span>
                <div style="display:flex;gap:8px;align-items:center">
                  <?php if ($etype === 'checkin'): ?>
                    <a href="/registry/?edit=<?= $r['id'] ?>" class="btn btn-sm">Update my entry</a>
                  <?php endif; ?>
                  <?php if (!empty($_SESSION['reg_admin'])): ?>
                    <a href="/registry/?del=<?= $r['id'] ?>" class="btn-danger" onclick="return confirm('Delete this entry?')">Delete</a>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script>
var locRequired = <?= $loc_required ? 'true' : 'false' ?>;
var typeLabels = {
  checkin:      { name:'Your Name *', loc:'Where can you be found?' + (locRequired ? ' *' : ''), nameHint:'Jane Smith' },
  found_person: { name:"Person's name (or Unknown)", loc:'Where was this person found?', nameHint:'Unknown' }
};
function setType(t) {
  document.getElementById('entry_type').value = t;
  var lbl = typeLabels[t];
  document.getElementById('name-label').textContent = lbl.name;
  document.getElementById('name-field').placeholder = lbl.nameHint;
  document.getElementById('loc-label').textContent  = lbl.loc;
  document.getElementById('loc-field').required = (t !== 'checkin') || locRequired;
  document.getElementById('checkin-section').style.display = (t === 'checkin') ? '' : 'none';
  document.getElementById('status-section').style.display  = (t === 'checkin') ? '' : 'none';
  document.querySelectorAll('.type-tab').forEach(function(el) {
    el.classList.toggle('active',
      (t === 'checkin' && el.textContent.toLowerCase().includes('check')) ||
      (t === 'found_person' && el.textContent.toLowerCase().includes('person'))
    );
  });
}
setType(document.getElementById('entry_type').value);
</script>
</body>
</html>
