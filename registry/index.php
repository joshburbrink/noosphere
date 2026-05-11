<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();

$db = new SQLite3('/var/lib/noosphere/registry.db');
$db->exec("CREATE TABLE IF NOT EXISTS registry (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    location TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'OK',
    missing TEXT,
    skills TEXT,
    have TEXT,
    need TEXT,
    notes TEXT,
    pin TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
)");
// Migrations
foreach (['age TEXT', 'is_child INTEGER DEFAULT 0', 'photo TEXT', "entry_type TEXT DEFAULT 'checkin'", 'is_admin INTEGER DEFAULT 0',
          'bunk TEXT', 'dietary TEXT', 'next_of_kin TEXT'] as $col) {
    @$db->exec("ALTER TABLE registry ADD COLUMN $col");
}

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

// Admin delete action
if (isset($_GET['del']) && !empty($_SESSION['reg_admin'])) {
    $del_id = (int)$_GET['del'];
    if ($del_id > 0) {
        $del_row = $db->querySingle("SELECT photo FROM registry WHERE id=$del_id", true);
        if ($del_row && $del_row['photo']) {
            $photo_path = $photo_dir . $del_row['photo'];
            if (file_exists($photo_path) && strpos(realpath($photo_path), realpath($photo_dir)) === 0) {
                unlink($photo_path);
            }
        }
        $stmt = $db->prepare('DELETE FROM registry WHERE id=?');
        $stmt->bindValue(1, $del_id, SQLITE3_INTEGER);
        $stmt->execute();
    }
    header('Location: /registry/');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    readonly_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'register') {
        ban_check_or_die();
        $entry_type = in_array($_POST['entry_type'] ?? '', ['checkin','found_person']) ? $_POST['entry_type'] : 'checkin';
        $name     = trim($_POST['name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $valid_statuses = get_status_options();
        $status   = in_array($_POST['status'] ?? '', $valid_statuses) ? $_POST['status'] : $valid_statuses[0];
        $missing  = trim($_POST['missing'] ?? '');
        $skills   = implode(', ', array_filter(array_map('trim', (array)($_POST['skills'] ?? []))));
        $have     = trim($_POST['have'] ?? '');
        $need     = trim($_POST['need'] ?? '');
        $notes    = trim($_POST['notes'] ?? '');
        $pin      = trim($_POST['pin'] ?? '');
        $age      = trim($_POST['age'] ?? '');
        $is_child = !empty($_POST['is_child']) ? 1 : 0;
        $photo    = save_photo('photo');
        $bunk     = trim($_POST['bunk']       ?? '');
        $dietary  = trim($_POST['dietary']    ?? '');
        $next_of_kin = trim($_POST['next_of_kin'] ?? '');

        if (!$name || !$location || strlen($pin) < 4) {
            $error = 'Name/description, location, and a 4+ digit PIN are required.';
        } else {
            $pin_hash = password_hash($pin, PASSWORD_DEFAULT);
            $now = time();
            $stmt = $db->prepare("INSERT INTO registry (name,location,status,missing,skills,have,need,notes,pin,created_at,updated_at,age,is_child,photo,entry_type,bunk,dietary,next_of_kin) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bindValue(1,$name); $stmt->bindValue(2,$location); $stmt->bindValue(3,$status);
            $stmt->bindValue(4,$missing); $stmt->bindValue(5,$skills); $stmt->bindValue(6,$have);
            $stmt->bindValue(7,$need); $stmt->bindValue(8,$notes); $stmt->bindValue(9,$pin_hash);
            $stmt->bindValue(10,$now); $stmt->bindValue(11,$now); $stmt->bindValue(12,$age);
            $stmt->bindValue(13,$is_child,SQLITE3_INTEGER); $stmt->bindValue(14,$photo); $stmt->bindValue(15,$entry_type);
            $stmt->bindValue(16,$bunk); $stmt->bindValue(17,$dietary); $stmt->bindValue(18,$next_of_kin);
            $stmt->execute();
            if ($entry_type === 'checkin') $msg = "Registered. Remember your PIN to update later.";
            elseif ($entry_type === 'found_person') $msg = "Found person reported. Thank you.";
            else $msg = "Found item reported. Thank you.";
        }
    }

    if ($action === 'update') {
        $id  = intval($_POST['id'] ?? 0);
        $pin = trim($_POST['pin'] ?? '');
        $row = $db->querySingle("SELECT pin,photo,is_admin FROM registry WHERE id=$id", true);
        rate_limit('pin', 5, 900);
        if ($row && password_verify($pin, $row['pin'])) {
            rate_reset('pin');
            // Set admin session if this entry has is_admin flag
            if (!empty($row['is_admin'])) {
                $_SESSION['reg_admin'] = true;
            }
            $location = trim($_POST['location'] ?? '');
            $valid_statuses = get_status_options();
            $status   = in_array($_POST['status'] ?? '', $valid_statuses) ? $_POST['status'] : $valid_statuses[0];
            $missing  = trim($_POST['missing'] ?? '');
            $skills   = implode(', ', array_filter(array_map('trim', (array)($_POST['skills'] ?? []))));
            $have     = trim($_POST['have'] ?? '');
            $need     = trim($_POST['need'] ?? '');
            $notes    = trim($_POST['notes'] ?? '');
            $age      = trim($_POST['age'] ?? '');
            $is_child = !empty($_POST['is_child']) ? 1 : 0;
            $photo    = save_photo('photo') ?? $row['photo'];
            $bunk        = trim($_POST['bunk']       ?? '');
            $dietary     = trim($_POST['dietary']    ?? '');
            $next_of_kin = trim($_POST['next_of_kin'] ?? '');
            $now = time();
            $stmt = $db->prepare("UPDATE registry SET location=?,status=?,missing=?,skills=?,have=?,need=?,notes=?,updated_at=?,age=?,is_child=?,photo=?,bunk=?,dietary=?,next_of_kin=? WHERE id=?");
            $stmt->bindValue(1,$location); $stmt->bindValue(2,$status); $stmt->bindValue(3,$missing);
            $stmt->bindValue(4,$skills); $stmt->bindValue(5,$have); $stmt->bindValue(6,$need);
            $stmt->bindValue(7,$notes); $stmt->bindValue(8,$now); $stmt->bindValue(9,$age);
            $stmt->bindValue(10,$is_child,SQLITE3_INTEGER); $stmt->bindValue(11,$photo);
            $stmt->bindValue(12,$bunk); $stmt->bindValue(13,$dietary); $stmt->bindValue(14,$next_of_kin);
            $stmt->bindValue(15,$id,SQLITE3_INTEGER);
            $stmt->execute();
            $msg = "Entry updated.";
        } else {
            $error = "Incorrect PIN.";
        }
    }
}

$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$where = "1=1";
if ($filter === 'help')         $where .= " AND status='Need Help'";
if ($filter === 'missing')      $where .= " AND (missing != '' AND missing IS NOT NULL)";
if ($filter === 'children')     $where .= " AND is_child=1";
if ($filter === 'found_person') $where .= " AND entry_type='found_person'";
if ($filter === 'checkin')      $where .= " AND (entry_type='checkin' OR entry_type IS NULL)";
if ($search) {
    $stmt_search = $db->prepare("SELECT * FROM registry WHERE $where AND (name LIKE ? OR location LIKE ? OR skills LIKE ? OR need LIKE ? OR have LIKE ? OR notes LIKE ?) ORDER BY updated_at DESC");
    $like = '%' . $search . '%';
    $stmt_search->bindValue(1,$like); $stmt_search->bindValue(2,$like); $stmt_search->bindValue(3,$like);
    $stmt_search->bindValue(4,$like); $stmt_search->bindValue(5,$like); $stmt_search->bindValue(6,$like);
    $rows_result = $stmt_search->execute();
} else {
    $rows_result = $db->query("SELECT * FROM registry WHERE $where ORDER BY updated_at DESC");
}

$all = [];
while ($r = $rows_result->fetchArray(SQLITE3_ASSOC)) $all[] = $r;
$total      = $db->querySingle("SELECT COUNT(*) FROM registry");
$need_help  = $db->querySingle("SELECT COUNT(*) FROM registry WHERE status='Need Help'");
$children   = $db->querySingle("SELECT COUNT(*) FROM registry WHERE is_child=1");
$fnd_person = $db->querySingle("SELECT COUNT(*) FROM registry WHERE entry_type='found_person'");

function esc($s) { return htmlspecialchars($s, ENT_QUOTES); }
function ago($ts) {
    $d = time() - $ts;
    if ($d < 60) return "just now";
    if ($d < 3600) return intval($d/60)."m ago";
    if ($d < 86400) return intval($d/3600)."h ago";
    return intval($d/86400)."d ago";
}

$skill_opts = ['Medical/First Aid','Construction/Repair','Food/Cooking','Communications/Radio','Vehicle/Mechanical','Childcare','Water/Sanitation','Navigation','Security','Other'];
$edit_id  = intval($_GET['edit'] ?? 0);
$edit_row = $edit_id ? $db->querySingle("SELECT * FROM registry WHERE id=$edit_id", true) : null;
$entry_type_default = $_GET['type'] ?? 'checkin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Community Registry — Noosphere</title>
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
  .skill-grid { display:grid; grid-template-columns:1fr 1fr; gap:3px; margin-top:5px; }
  .skill-grid label { display:flex; align-items:center; gap:5px; color:var(--text); font-size:12px; margin:0; cursor:pointer; }
  .child-row { display:flex; align-items:center; gap:10px; margin-top:9px; }
  .child-row label { margin:0; display:flex; align-items:center; gap:6px; color:var(--text); font-size:13px; cursor:pointer; }
  .photo-row { margin-top:9px; }
  .photo-row input[type=file] { background:none; border:1px dashed var(--border); padding:6px; font-size:12px; color:var(--muted); }
  .btn { background:var(--accent); color:#fff; border:none; padding:9px 18px; border-radius:6px; cursor:pointer; font-size:13px; font-weight:bold; margin-top:12px; width:100%; }
  .btn:hover { opacity:.85; }
  .btn-sm { background:var(--border); border:none; padding:4px 10px; font-size:11px; margin-top:0; width:auto; border-radius:4px; cursor:pointer; color:var(--text); text-decoration:none; display:inline-block; }
  .btn-danger { background:none; border:1px solid #3a2a2a; color:var(--accent); padding:4px 10px; border-radius:4px; font-size:11px; cursor:pointer; text-decoration:none; display:inline-block; }
  .btn-danger:hover { background:#3a1a1a; }
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
  .badge-item    { background:#1a1a3a; color:#aaa; }
  .card-loc { color:var(--muted); font-size:12px; margin-top:3px; }
  .tags { display:flex; flex-wrap:wrap; gap:5px; margin-top:8px; }
  .tag { background:var(--border); padding:2px 8px; border-radius:10px; font-size:11px; }
  .card-row { margin-top:6px; font-size:12px; color:var(--muted); }
  .card-row span { color:var(--text); }
  .card-footer { margin-top:8px; font-size:11px; color:var(--muted); display:flex; justify-content:space-between; align-items:center; }
  .empty { text-align:center; color:var(--muted); padding:40px; }
  .pin-note { font-size:11px; color:var(--muted); margin-top:4px; }
  .section-hidden { display:none; }
</style>
</head>
<body>
<header>
  <div>
    <a href="/">&#x2190; Home</a>
    <h1>Community Registry</h1>
  </div>
  <div class="stats">
    <div><span><?= $total ?></span> total</div>
    <div><span style="color:<?= $need_help > 0 ? 'var(--accent)' : 'var(--green)' ?>"><?= $need_help ?></span> need help</div>
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
            <div style="font-size:12px;color:var(--muted);margin-bottom:8px;">Updating: <strong><?= esc($edit_row['name']) ?></strong></div>
          <?php else: ?>
            <label id="name-label">Your Name *</label>
            <input type="text" name="name" required id="name-field" placeholder="Jane Smith">
          <?php endif; ?>

          <div class="child-row">
            <label><input type="checkbox" name="is_child" id="is_child" value="1" <?= !empty($edit_row['is_child']) ? 'checked' : '' ?>> Child (under 18)</label>
            <div style="flex:1">
              <input type="text" name="age" id="age" value="<?= esc($edit_row['age'] ?? '') ?>" placeholder="Age (e.g. 8, ~35)" style="width:100%">
            </div>
          </div>

          <label id="loc-label">Location *</label>
          <input type="text" name="location" value="<?= esc($edit_row['location'] ?? '') ?>" required id="loc-field" placeholder="123 Oak St / Shelter B / Near the dam">

          <div id="status-section">
            <label>Status</label>
            <select name="status">
              <?php $status_opts = get_status_options(); foreach ($status_opts as $s): ?>
                <option value="<?= $s ?>" <?= ($edit_row['status'] ?? $status_opts[0]) === $s ? 'selected' : '' ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div id="checkin-section">
            <?php if (get_setting('registry_missing','1')==='1'): ?>
            <label>Missing family members</label>
            <input type="text" name="missing" value="<?= esc($edit_row['missing'] ?? '') ?>" placeholder="John Smith (husband), Sara age 8">
            <?php endif; ?>

            <?php if (get_setting('registry_skills','1')==='1'): ?>
            <label>Skills you can offer</label>
            <div class="skill-grid">
              <?php foreach ($skill_opts as $sk):
                $checked = $edit_row && strpos($edit_row['skills'] ?? '', $sk) !== false ? 'checked' : ''; ?>
                <label><input type="checkbox" name="skills[]" value="<?= esc($sk) ?>" <?= $checked ?>><?= esc($sk) ?></label>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (get_setting('registry_supplies','0')==='1'): ?>
            <label>Supplies / resources you need</label>
            <textarea name="need" placeholder="Insulin, baby formula, crutches..."><?= esc($edit_row['need'] ?? '') ?></textarea>
            <?php endif; ?>

            <?php if (get_setting('registry_shelter','0')==='1'): ?>
            <label>Bunk / Room Assignment</label>
            <input type="text" name="bunk" value="<?= esc($edit_row['bunk'] ?? '') ?>" placeholder="Shelter B, Bunk 14">
            <label>Dietary / Medical Notes</label>
            <input type="text" name="dietary" value="<?= esc($edit_row['dietary'] ?? '') ?>" placeholder="Diabetic, peanut allergy, wheelchair...">
            <label>Next of Kin / Emergency Contact</label>
            <input type="text" name="next_of_kin" value="<?= esc($edit_row['next_of_kin'] ?? '') ?>" placeholder="Jane Smith, 555-1234">
            <?php endif; ?>
          </div>

          <label>Notes / Description</label>
          <textarea name="notes" placeholder="Any other details..."><?= esc($edit_row['notes'] ?? '') ?></textarea>

          <div class="photo-row">
            <label>Photo <?php if ($edit_row && $edit_row['photo']): ?>(current photo on file -- upload new to replace)<?php endif; ?></label>
            <input type="file" name="photo" accept="image/*">
            <div class="pin-note">Max 5MB / jpg, png, gif, webp</div>
            <?php if ($edit_row && $edit_row['photo']): ?>
              <img src="/registry/photos/<?= esc($edit_row['photo']) ?>" style="width:80px;height:80px;object-fit:cover;border-radius:6px;margin-top:6px;">
            <?php endif; ?>
          </div>

          <label>PIN (4+ digits)</label>
          <input type="password" name="pin" required minlength="4" placeholder="<?= $edit_row ? 'Enter your PIN to confirm' : 'Choose a PIN -- write it down' ?>">
          <?php if (!$edit_row): ?><div class="pin-note">You'll need this PIN to update the entry later.</div><?php endif; ?>

          <button type="submit" class="btn"><?= $edit_row ? 'Save Changes' : 'Submit' ?></button>
          <?php if ($edit_row): ?>
            <a href="/registry/" style="display:block;text-align:center;margin-top:8px;font-size:12px;color:var(--muted);">Cancel</a>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <!-- LIST -->
    <div>
      <div class="filters">
        <a href="/registry/" class="<?= $filter==='all'?'active':'' ?>">All (<?= $total ?>)</a>
        <a href="/registry/?filter=checkin" class="<?= $filter==='checkin'?'active':'' ?>">Check-ins</a>
        <a href="/registry/?filter=help" class="<?= $filter==='help'?'active':'' ?>">Need Help (<?= $need_help ?>)</a>
        <a href="/registry/?filter=missing" class="<?= $filter==='missing'?'active':'' ?>">Missing</a>
        <a href="/registry/?filter=children" class="<?= $filter==='children'?'active':'' ?>">Children (<?= $children ?>)</a>
        <?php if ($fnd_person): ?><a href="/registry/?filter=found_person" class="<?= $filter==='found_person'?'active':'' ?>">Found Persons (<?= $fnd_person ?>)</a><?php endif; ?>
        <form method="get" style="flex:1;min-width:140px;">
          <input type="hidden" name="filter" value="<?= esc($filter) ?>">
          <input type="text" name="q" value="<?= esc($search) ?>" placeholder="Search...">
        </form>
      </div>

      <div class="cards">
        <?php if (!$all): ?>
          <div class="empty">No entries found.</div>
        <?php endif; ?>
        <?php foreach ($all as $r):
          $etype = $r['entry_type'] ?? 'checkin';
          $is_child = !empty($r['is_child']);
          $card_class = 'card' . ($is_child ? ' child' : '') . ($etype !== 'checkin' ? ' found' : '');
          $bc = ['OK'=>'badge-ok','Need Help'=>'badge-help','Checking In'=>'badge-checking'];
          $bclass = $bc[$r['status']] ?? 'badge-ok';
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
                  <?php if ($etype === 'found_person'): ?><span class="badge badge-found">Found Person</span>
                  <?php else: ?><span class="badge <?= $bclass ?>"><?= esc($r['status']) ?></span><?php endif; ?>
                  <?php if ($is_child): ?><span class="badge badge-child">Child<?= $r['age'] ? ' / Age '.$r['age'] : '' ?></span>
                  <?php elseif ($r['age']): ?><span class="badge" style="background:var(--border);color:var(--muted)">Age <?= esc($r['age']) ?></span><?php endif; ?>
                </div>
              </div>

              <?php if ($r['missing']): ?>
                <div class="card-row" style="margin-top:6px;"><span style="color:var(--accent);font-weight:bold;">Missing: </span><span><?= esc($r['missing']) ?></span></div>
              <?php endif; ?>
              <?php if ($r['skills']): ?>
                <div class="tags">
                  <?php foreach (explode(', ', $r['skills']) as $sk): ?><span class="tag"><?= esc(trim($sk)) ?></span><?php endforeach; ?>
                </div>
              <?php endif; ?>
              <?php if ($r['need']): ?><div class="card-row"><span style="color:#ff9999">Needs: </span><span><?= esc($r['need']) ?></span></div><?php endif; ?>
              <?php if ($r['notes']): ?><div class="card-row"><span>Notes: </span><span><?= esc($r['notes']) ?></span></div><?php endif; ?>
              <?php if (!empty($r['bunk'])): ?><div class="card-row"><span style="color:#2ecc71">Bunk: </span><span><?= esc($r['bunk']) ?></span></div><?php endif; ?>
              <?php if (!empty($r['dietary'])): ?><div class="card-row"><span>Dietary/Medical: </span><span><?= esc($r['dietary']) ?></span></div><?php endif; ?>
              <?php if (!empty($r['next_of_kin'])): ?><div class="card-row"><span>Next of Kin: </span><span><?= esc($r['next_of_kin']) ?></span></div><?php endif; ?>

              <div class="card-footer">
                <span>Updated <?= ago($r['updated_at']) ?></span>
                <div style="display:flex;gap:8px;align-items:center;">
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
var typeLabels = {
  checkin:      { name:'Your Name *',                  loc:'Where can you be found? *',       nameHint:'Jane Smith' },
  found_person: { name:"Person's name (or Unknown)",   loc:'Where was this person found?',    nameHint:'Unknown' }
};

function setType(t) {
  document.getElementById('entry_type').value = t;
  var lbl = typeLabels[t];
  document.getElementById('name-label').textContent = lbl.name;
  document.getElementById('name-field').placeholder = lbl.nameHint;
  document.getElementById('loc-label').textContent = lbl.loc;
  var checkinSec = document.getElementById('checkin-section');
  var statusSec  = document.getElementById('status-section');
  checkinSec.style.display = (t === 'checkin') ? '' : 'none';
  statusSec.style.display  = (t === 'checkin') ? '' : 'none';
  document.querySelectorAll('.type-tab').forEach(function(el){ el.classList.remove('active'); });
  document.querySelectorAll('.type-tab').forEach(function(el){
    if (el.textContent.toLowerCase().includes(t === 'checkin' ? 'check' : 'person'))
      el.classList.add('active');
  });
}
// Init on load
setType(document.getElementById('entry_type').value);
</script>
</body>
</html>
