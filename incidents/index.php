<?php
require_once __DIR__ . '/_init.php';
sec_session_start();
if (get_setting('show_incidents','0') !== '1') { http_response_code(404); exit; }

$is_admin    = !empty($_SESSION['admin']);
$is_readonly = is_readonly();
$is_command  = incidents_command_mode();
$db          = incidents_db();

$msg = ''; $error = '';

// Handle POST (submit / admin actions). Non-JS fallback path — api.php is the JS path.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_readonly) {
    csrf_verify();
    $act = $_POST['act'] ?? '';

    if ($act === 'submit') {
        $type = $_POST['type'] ?? 'general';
        if (!isset(INCIDENT_TYPES[$type])) $type = 'general';
        $title = trim($_POST['title'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $loc   = trim($_POST['location_text'] ?? '');
        $rep   = trim($_POST['reporter_name'] ?? '');
        $sev   = $_POST['severity'] ?? '';
        if (!isset(INCIDENT_SEVERITIES[$sev])) $sev = null;
        if (!$sev && $is_command) $sev = 'minor';

        $lat_raw = trim($_POST['lat'] ?? '');
        $lng_raw = trim($_POST['lng'] ?? '');
        $lat = ($lat_raw !== '' && is_numeric($lat_raw) && abs((float)$lat_raw) <= 90)  ? (float)$lat_raw : null;
        $lng = ($lng_raw !== '' && is_numeric($lng_raw) && abs((float)$lng_raw) <= 180) ? (float)$lng_raw : null;

        if ($title === '') {
            $error = 'Title is required.';
        } else {
            $photo = null;
            if (!empty($_FILES['photo']['tmp_name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK
                && is_uploaded_file($_FILES['photo']['tmp_name'])) {
                $photo = incident_process_photo($_FILES['photo']['tmp_name']);
            }
            $token = bin2hex(random_bytes(16));
            $now   = time();

            $meta = null;
            if ($type === 'damage') {
                $dm = incident_collect_damage_meta($_POST);
                if ($dm) $meta = json_encode($dm, JSON_UNESCAPED_SLASHES);
                if (!$sev && !empty($dm['damage_level'])) {
                    $sev = damage_level_to_severity($dm['damage_level']);
                }
            }

            $stmt = $db->prepare("INSERT INTO incidents
                (submitted_at, updated_at, type, severity, title, description, location_text,
                 lat, lng, reporter_name, creator_token, photo_path, status, meta)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'open',?)");
            $stmt->execute([
                $now, $now, $type, $sev, $title, $desc ?: null, $loc ?: null,
                $lat, $lng, $rep ?: null, $token, $photo, $meta,
            ]);
            $msg = 'Incident reported.';
        }
    }
    elseif ($act === 'status' && $is_admin) {
        $id = (int)($_POST['id'] ?? 0);
        $s  = $_POST['status'] ?? '';
        if ($id && isset(INCIDENT_STATUSES[$s])) {
            if ($s === 'resolved') {
                $db->prepare("UPDATE incidents SET status=?, resolved_at=?, resolved_by=?, updated_at=? WHERE id=?")
                   ->execute([$s, time(), $_SESSION['admin_name'] ?? 'admin', time(), $id]);
            } else {
                $db->prepare("UPDATE incidents SET status=?, resolved_at=NULL, resolved_by=NULL, updated_at=? WHERE id=?")
                   ->execute([$s, time(), $id]);
            }
            $msg = 'Status updated.';
        }
    }
    elseif ($act === 'assign' && $is_admin) {
        $id = (int)($_POST['id'] ?? 0);
        $to = trim($_POST['assigned_to'] ?? '');
        if ($id) {
            $db->prepare("UPDATE incidents SET assigned_to=?, updated_at=? WHERE id=?")
               ->execute([$to ?: null, time(), $id]);
            $msg = 'Assignment updated.';
        }
    }
    elseif ($act === 'delete' && $is_admin) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $row = $db->prepare("SELECT photo_path FROM incidents WHERE id=?");
            $row->execute([$id]);
            $r = $row->fetch(PDO::FETCH_ASSOC);
            if ($r && $r['photo_path']) @unlink(INCIDENT_PHOTO_DIR . '/' . basename($r['photo_path']));
            $db->prepare("DELETE FROM incidents WHERE id=?")->execute([$id]);
            $msg = 'Incident deleted.';
        }
    }
}

// Filters
$f_type   = $_GET['type']   ?? '';
$f_status = $_GET['status'] ?? '';
$show_resolved = ($_GET['resolved'] ?? '') === '1';

$where = []; $params = [];
if ($f_type !== '' && isset(INCIDENT_TYPES[$f_type]))     { $where[] = 'type=?';   $params[] = $f_type; }
if ($f_status !== '' && isset(INCIDENT_STATUSES[$f_status])) { $where[] = 'status=?'; $params[] = $f_status; }
elseif (!$show_resolved) { $where[] = "status != 'resolved'"; }

$sql = "SELECT * FROM incidents";
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY submitted_at DESC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counts
$counts_by_status = ['open'=>0,'acknowledged'=>0,'resolved'=>0];
foreach ($db->query("SELECT status, COUNT(*) c FROM incidents GROUP BY status") as $r) {
    $counts_by_status[$r['status']] = (int)$r['c'];
}
$open_total = $counts_by_status['open'] + $counts_by_status['acknowledged'];

$name = get_setting('instance_name', 'Noosphere');
$maps_on = get_setting('show_maps','1') === '1';
$module_label = $is_command ? 'Incident Reports' : 'Map Reports';
$module_icon  = '📍';

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= esc($module_label) ?> — <?= esc($name) ?></title>
<?php if ($maps_on): ?>
<link rel="stylesheet" href="/maps/lib/maplibre-gl.css">
<script src="/maps/lib/maplibre-gl.js"></script>
<script src="/shared/js/incidents-map.js"></script>
<?php endif; ?>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:sans-serif; background:#1a1a2e; color:#eee; min-height:100vh; padding:1.5rem; }
.topbar { display:flex; align-items:center; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap; }
.topbar h1 { font-size:1.5rem; color:#e94560; flex:1; }
a.back { color:#aaa; text-decoration:none; font-size:13px; }
a.back:hover { color:#e94560; }
.msg   { background:#1a3a1a; border:1px solid #2ecc71; color:#2ecc71; padding:8px 14px; border-radius:6px; margin-bottom:1rem; font-size:13px; }
.error { background:#3a0a0a; border:1px solid #e94560; color:#e94560; padding:8px 14px; border-radius:6px; margin-bottom:1rem; font-size:13px; }
.card  { background:#16213e; border:1px solid #2a2a4a; border-radius:10px; padding:1.25rem; margin-bottom:1.25rem; }
.card h2 { font-size:1rem; color:#e94560; margin-bottom:1rem; }
.form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:10px; }
label { display:block; font-size:12px; color:#aaa; margin-bottom:3px; }
input[type=text], input[type=number], select, textarea {
  width:100%; background:#0f0f1a; border:1px solid #2a2a4a; border-radius:5px;
  color:#eee; padding:7px 10px; font-size:13px; }
textarea { resize:vertical; min-height:60px; }
.form-full { grid-column:1/-1; }
.btn { background:#e94560; color:#fff; border:none; border-radius:6px; padding:8px 20px; font-size:13px; cursor:pointer; }
.btn:hover { background:#c73652; }
.btn-sm { background:none; border:1px solid #555; color:#aaa; border-radius:4px; padding:3px 10px; font-size:11px; cursor:pointer; }
.btn-sm:hover { border-color:#e94560; color:#e94560; }
table { width:100%; border-collapse:collapse; font-size:13px; }
th { text-align:left; color:#aaa; font-weight:normal; padding:6px 8px; border-bottom:1px solid #2a2a4a; white-space:nowrap; }
td { padding:7px 8px; border-bottom:1px solid #1a1a2e; vertical-align:top; }
tr:hover td { background:#1a1f35; }
.badge { display:inline-block; font-size:10px; font-weight:bold; border-radius:3px; padding:2px 7px; }
.filter-bar { display:flex; gap:8px; align-items:center; margin-bottom:1rem; flex-wrap:wrap; }
.filter-btn { font-size:11px; border:1px solid #2a2a4a; border-radius:4px; padding:4px 10px; cursor:pointer; background:#0f0f1a; color:#aaa; text-decoration:none; }
.filter-btn:hover,.filter-btn.active { border-color:#e94560; color:#e94560; }
.summary-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:8px; margin-bottom:1.25rem; }
.sum-card { background:#0f0f1a; border-radius:6px; padding:10px; text-align:center; border:1px solid #2a2a4a; }
.sum-count { font-size:1.5rem; font-weight:bold; }
.sum-label { font-size:10px; color:#666; margin-top:2px; text-transform:uppercase; letter-spacing:.5px; }
.status-open { color:#e94560; }
.status-acknowledged { color:#f39c12; }
.status-resolved { color:#2ecc71; }
.report-grid { display:grid; grid-template-columns:1fr; gap:14px; }
@media (min-width:900px) {
  .report-grid { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); align-items:start; }
  .report-grid > .map-wrap { position:sticky; top:1rem; }
}
.map-wrap { position:relative; }
#mainmap { width:100%; height:60vh; min-height:380px; border:1px solid #2a2a4a; border-radius:6px; overflow:hidden; }
@media (max-width:899px) { #mainmap { height:45vh; min-height:300px; } }
#mainmap:fullscreen { height:100vh !important; min-height:0 !important; border-radius:0; border:0; }
.drop-pin-banner { position:absolute; top:10px; left:50%; transform:translateX(-50%);
  background:rgba(233,69,96,.95); color:#fff; padding:6px 14px; border-radius:6px;
  font-size:12px; font-weight:bold; box-shadow:0 2px 8px rgba(0,0,0,.5); z-index:5;
  display:none; pointer-events:none; }
body.drop-pin-mode .drop-pin-banner { display:block; }
body.drop-pin-mode #mainmap .maplibregl-canvas-container { cursor: crosshair !important; }
.pin-pick-btn { background:#0f0f1a; border:1px solid #2a2a4a; color:#eee;
  padding:8px 14px; border-radius:5px; font-size:13px; cursor:pointer; }
.pin-pick-btn:hover { border-color:#e94560; color:#e94560; }
.pin-pick-btn.has-pin { border-color:#2ecc71; color:#2ecc71; }
.pin-pick-btn.active { background:#e94560; color:#fff; border-color:#e94560; }
.maplibregl-popup-content { background:#16213e !important; color:#eee !important; border:1px solid #2a2a4a; border-radius:6px; font-size:12px; padding:10px; }
.maplibregl-popup-tip { border-top-color:#16213e !important; border-bottom-color:#16213e !important; }
.maplibregl-popup-content a { color:#7aa7d9; }
.maplibregl-ctrl-group { background:#16213e !important; border:1px solid #2a2a4a !important; }
.maplibregl-ctrl-group button { background:#16213e !important; }
.maplibregl-ctrl-group button:hover { background:#1f2c4d !important; }
.maplibregl-ctrl-scale { background:rgba(15,15,26,.7) !important; color:#eee !important; border-color:#2a2a4a !important; }
</style>
</head>
<body>
<div class="topbar">
  <h1><?= $module_icon ?> <?= esc($module_label) ?></h1>
  <a class="back" href="/">← Home</a>
</div>

<?php if ($msg):   ?><div class="msg"><?=   esc($msg)   ?></div><?php endif ?>
<?php if ($error): ?><div class="error"><?= esc($error) ?></div><?php endif ?>

<?php if ($is_command): ?>
<div class="summary-grid">
  <div class="sum-card">
    <div class="sum-count" style="color:#e94560"><?= $counts_by_status['open'] ?></div>
    <div class="sum-label">Open</div>
  </div>
  <div class="sum-card">
    <div class="sum-count" style="color:#f39c12"><?= $counts_by_status['acknowledged'] ?></div>
    <div class="sum-label">Acknowledged</div>
  </div>
  <div class="sum-card">
    <div class="sum-count" style="color:#2ecc71"><?= $counts_by_status['resolved'] ?></div>
    <div class="sum-label">Resolved</div>
  </div>
  <div class="sum-card">
    <div class="sum-count" style="color:#7aa7d9"><?= $open_total ?></div>
    <div class="sum-label">Active</div>
  </div>
</div>
<?php endif; ?>

<?php if (!$is_readonly): ?>
<div class="card">
  <h2>Submit <?= $is_command ? 'Incident Report' : 'Map Report' ?></h2>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="submit">
    <div class="form-grid">
      <div class="form-full">
        <label>Title *</label>
        <input type="text" name="title" maxlength="200" required placeholder="Short summary — e.g. 'Tree across Marr Rd at 5th'">
      </div>
      <div>
        <label>Type</label>
        <select name="type">
          <?php foreach (INCIDENT_TYPES as $k => $v): ?>
            <option value="<?= $k ?>"<?= $k === 'general' ? ' selected' : '' ?>><?= esc($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($is_command): ?>
      <div>
        <label>Severity</label>
        <select name="severity">
          <?php foreach (INCIDENT_SEVERITIES as $k => $v): ?>
            <option value="<?= $k ?>"<?= $k === 'minor' ? ' selected' : '' ?>><?= esc($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div<?= $is_command ? '' : ' class="form-full"' ?>>
        <label>Location (address / landmark)</label>
        <input type="text" name="location_text" maxlength="200" placeholder="123 Main St, or '5th and Oak'">
      </div>
      <div class="form-full">
        <label>Description</label>
        <textarea name="description" placeholder="Details — what, when, who, what's needed…"></textarea>
      </div>
      <div>
        <label>Reporter (optional)</label>
        <input type="text" name="reporter_name" maxlength="80" placeholder="Your name or call sign">
      </div>
      <div>
        <label>Photo (optional)</label>
        <input type="file" name="photo" accept="image/*" capture="environment" style="color:#aaa;font-size:12px">
      </div>

      <!-- Damage-specific (visible when Type = Damage) -->
      <div class="form-full damage-fields" style="display:none;border-top:1px solid #2a2a4a;padding-top:10px;margin-top:6px">
        <div style="font-size:11px;color:#7aa7d9;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">Damage Details</div>
        <div class="form-grid">
          <div>
            <label>Structure Type</label>
            <select name="damage_structure_type">
              <?php foreach (DAMAGE_STRUCTURE_TYPES as $k => $v): ?>
                <option value="<?= $k ?>"><?= esc($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Damage Level</label>
            <select name="damage_level">
              <?php foreach (DAMAGE_LEVELS as $k => $v): ?>
                <option value="<?= $k ?>"<?= $k === 'minor' ? ' selected' : '' ?>><?= esc($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Occupants Accounted For</label>
            <select name="damage_accounted">
              <?php foreach (DAMAGE_ACCOUNTED as $k => $v): ?>
                <option value="<?= $k ?>"<?= $k === 'unknown' ? ' selected' : '' ?>><?= esc($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Number of Occupants (if known)</label>
            <input type="number" name="damage_occupant_count" min="0" max="999" placeholder="—">
          </div>
          <div class="form-full">
            <label>Utilities Affected</label>
            <div style="display:flex;gap:16px;flex-wrap:wrap;padding:6px 0">
              <?php foreach (DAMAGE_UTILITIES as $k => $v): ?>
              <label style="display:flex;align-items:center;gap:5px;color:#ccc;cursor:pointer;margin-bottom:0">
                <input type="checkbox" name="damage_utilities[]" value="<?= $k ?>" style="width:auto"> <?= esc($v) ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-full">
            <label>Hazards Present</label>
            <textarea name="damage_hazards" placeholder="Gas leak, structural collapse, flooding, downed lines…"></textarea>
          </div>
        </div>
      </div>

      <?php if ($maps_on): ?>
      <div class="form-full">
        <label>Pin Location <span style="color:#555;font-weight:normal">(optional)</span></label>
        <input type="hidden" name="lat" id="inc-lat">
        <input type="hidden" name="lng" id="inc-lng">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          <button type="button" class="pin-pick-btn" id="inc-pick-btn">📍 Choose location on map</button>
          <span id="inc-pin-status" style="font-size:12px;color:#888"></span>
        </div>
      </div>
      <?php endif; ?>
      <div class="form-full">
        <button type="submit" class="btn">Submit</button>
      </div>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div class="filter-bar">
    <a href="?" class="filter-btn<?= !$f_type && !$f_status ? ' active' : '' ?>">All</a>
    <?php foreach (INCIDENT_TYPES as $k => $v): ?>
    <a href="?type=<?= $k ?><?= $show_resolved ? '&resolved=1' : '' ?>" class="filter-btn<?= $f_type === $k ? ' active' : '' ?>"
       style="<?= $f_type === $k ? 'border-color:'.INCIDENT_TYPE_COLORS[$k].';color:'.INCIDENT_TYPE_COLORS[$k] : '' ?>">
      <span style="color:<?= INCIDENT_TYPE_COLORS[$k] ?>">●</span> <?= esc($v) ?>
    </a>
    <?php endforeach; ?>
    <span style="border-left:1px solid #2a2a4a;height:18px"></span>
    <a href="?<?= $show_resolved ? '' : 'resolved=1' ?><?= $f_type ? ($show_resolved ? '?' : '&') . 'type=' . $f_type : '' ?>"
       class="filter-btn<?= $show_resolved ? ' active' : '' ?>">
      <?= $show_resolved ? '✓ ' : '' ?>Show resolved
    </a>
    <a href="export.php" style="margin-left:auto" class="filter-btn">⬇ CSV</a>
  </div>

  <div class="report-grid">
  <div id="listview">
    <?php if (!$rows): ?>
      <div style="text-align:center;color:#555;padding:2rem">No incidents<?= ($f_type || $f_status || !$show_resolved) ? ' match this filter' : ' yet' ?>.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table>
      <thead>
        <tr>
          <th>Time</th>
          <th>Type</th>
          <?php if ($is_command): ?><th>Sev</th><th>Status</th><?php endif; ?>
          <th>Title</th>
          <th>Location</th>
          <th>Reporter</th>
          <?php if ($is_command): ?><th>Assigned</th><?php endif; ?>
          <?php if ($is_admin): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
        $tc = INCIDENT_TYPE_COLORS[$r['type']] ?? '#7aa7d9';
        $sc = $r['severity'] ? (INCIDENT_SEVERITY_COLORS[$r['severity']] ?? '#aaa') : '#555';
      ?>
        <tr>
          <td style="white-space:nowrap;color:#aaa;font-size:12px"><?= date('m/d H:i', $r['submitted_at']) ?></td>
          <td><span class="badge" style="background:<?= $tc ?>22;color:<?= $tc ?>;border:1px solid <?= $tc ?>44"><?= esc(INCIDENT_TYPES[$r['type']] ?? $r['type']) ?></span></td>
          <?php if ($is_command): ?>
          <td><?= $r['severity'] ? '<span class="badge" style="background:'.$sc.'22;color:'.$sc.';border:1px solid '.$sc.'44">'.esc(INCIDENT_SEVERITIES[$r['severity']] ?? $r['severity']).'</span>' : '<span style="color:#555">—</span>' ?></td>
          <td>
            <?php if ($is_admin): ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="act" value="status">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <select name="status" onchange="this.form.submit()" style="font-size:11px;padding:2px 4px;background:transparent;border:1px solid #2a2a4a;color:#eee">
                <?php foreach (INCIDENT_STATUSES as $sk => $sv): ?>
                  <option value="<?= $sk ?>"<?= $sk === $r['status'] ? ' selected' : '' ?>><?= esc($sv) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
            <?php else: ?>
              <span class="status-<?= esc($r['status']) ?>"><?= esc(INCIDENT_STATUSES[$r['status']] ?? $r['status']) ?></span>
            <?php endif; ?>
          </td>
          <?php endif; ?>
          <td>
            <strong><?= esc($r['title']) ?></strong>
            <?php if ($r['description']): ?>
              <div style="color:#aaa;font-size:11px;margin-top:2px;max-width:280px"><?= esc(mb_strimwidth($r['description'], 0, 140, '…')) ?></div>
            <?php endif; ?>
            <?php if ($r['type'] === 'damage' && !empty($r['meta'])):
              $dm = json_decode($r['meta'], true) ?: [];
              $bits = [];
              if (!empty($dm['structure_type'])) $bits[] = DAMAGE_STRUCTURE_TYPES[$dm['structure_type']] ?? $dm['structure_type'];
              if (!empty($dm['damage_level']))   $bits[] = DAMAGE_LEVELS[$dm['damage_level']] ?? $dm['damage_level'];
              if (isset($dm['occupants_accounted'])) {
                $a = DAMAGE_ACCOUNTED[$dm['occupants_accounted']] ?? '?';
                if (isset($dm['occupant_count'])) $a .= ' (' . (int)$dm['occupant_count'] . ')';
                $bits[] = 'occupants: ' . $a;
              }
              if (!empty($dm['utilities_affected'])) $bits[] = 'utils off: ' . implode(',', $dm['utilities_affected']);
              if ($bits): ?>
                <div style="color:#7aa7d9;font-size:10px;margin-top:3px"><?= esc(implode(' · ', $bits)) ?></div>
              <?php endif;
              if (!empty($dm['hazards'])): ?>
                <div style="color:#f39c12;font-size:11px;margin-top:2px">⚠ <?= esc($dm['hazards']) ?></div>
              <?php endif;
            endif; ?>
            <?php if ($r['photo_path']): ?>
              <a href="/incident-photos/<?= urlencode($r['photo_path']) ?>" target="_blank" style="font-size:10px;color:#7ad;display:inline-block;margin-top:3px">📷 photo</a>
            <?php endif; ?>
            <?php if ($r['lat'] !== null && $r['lng'] !== null): ?>
              <span style="font-size:10px;color:#555;margin-left:6px">📍 pinned</span>
            <?php endif; ?>
          </td>
          <td style="color:#aaa;font-size:12px;max-width:160px"><?= esc($r['location_text'] ?? '') ?></td>
          <td style="color:#aaa;font-size:12px"><?= esc($r['reporter_name'] ?? '') ?></td>
          <?php if ($is_command): ?>
          <td style="color:#aaa;font-size:12px">
            <?php if ($is_admin): ?>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="act" value="assign">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="text" name="assigned_to" value="<?= esc($r['assigned_to'] ?? '') ?>" onblur="if(this.value!==this.defaultValue)this.form.submit()" style="font-size:11px;padding:2px 4px;background:transparent;border:1px solid #2a2a4a;color:#eee;width:90px" placeholder="—">
              </form>
            <?php else: ?>
              <?= esc($r['assigned_to'] ?? '') ?>
            <?php endif; ?>
          </td>
          <?php endif; ?>
          <?php if ($is_admin): ?>
          <td>
            <form method="post" onsubmit="return confirm('Delete this incident?')" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="act" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="btn-sm">Del</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($maps_on): ?>
  <div class="map-wrap">
    <div id="mainmap"></div>
    <div class="drop-pin-banner">📍 Tap the map to set incident location · ESC to cancel</div>
  </div>
  <?php endif; ?>
  </div><!-- /report-grid -->
</div>

<script>
// Always: toggle damage-specific fields when Type = Damage
(function() {
  var typeSel = document.querySelector('select[name="type"]');
  var damageFields = document.querySelector('.damage-fields');
  if (typeSel && damageFields) {
    var sync = function() { damageFields.style.display = typeSel.value === 'damage' ? '' : 'none'; };
    typeSel.addEventListener('change', sync);
    sync();
  }
})();
</script>

<?php if ($maps_on): ?>
<script>
(function() {
  var NI = window.NoosphereIncidents;
  var IS_COMMAND = <?= $is_command ? 'true' : 'false' ?>;

  var map = new maplibregl.Map({
    container: 'mainmap',
    style: {
      version: 8,
      glyphs: '/maps/fonts/{fontstack}/{range}.pbf',
      sources: { counties: { type:'vector', tiles:[window.location.origin+'/tiles/counties/tiles/{z}/{x}/{y}.pbf'], minzoom:4, maxzoom:14 } },
      layers: [
        { id:'bg',    type:'background', paint:{'background-color':'#1a1f2e'} },
        { id:'water', type:'fill', source:'counties', 'source-layer':'water', paint:{'fill-color':'#162236'} },
        { id:'roads', type:'line', source:'counties', 'source-layer':'transportation', paint:{'line-color':'#3a3e62','line-width':1} },
        { id:'place', type:'symbol', source:'counties', 'source-layer':'place', minzoom:8,
          layout:{'text-field':['get','name:latin'],'text-size':12,'text-font':['Noto Sans Regular']},
          paint:{'text-color':'#b0b0cc','text-halo-color':'#0a0a1a','text-halo-width':1.5} },
      ]
    },
    center: [-85.90, 39.20], zoom: 10, maxZoom: 19, minZoom: 6,
    attributionControl: false,
  });
  map.addControl(new maplibregl.NavigationControl({visualizePitch:true}), 'top-right');
  map.addControl(new maplibregl.FullscreenControl({container: document.getElementById('mainmap')}), 'top-right');
  map.addControl(new maplibregl.GeolocateControl({positionOptions:{enableHighAccuracy:true}, trackUserLocation:true, showUserHeading:true}), 'top-right');
  map.addControl(new maplibregl.ScaleControl({maxWidth:120, unit:'imperial'}), 'bottom-left');

  // ── Existing-pin layer (auto-refresh) ──────────────────────────────────────
  var markerLayer = [];
  function loadMarkers() {
    var withResolved = <?= $show_resolved ? 'true' : 'false' ?>;
    var typeFilter = <?= $f_type ? "'".addslashes($f_type)."'" : 'null' ?>;
    var qs = '?action=list&only_pinned=1' + (withResolved ? '&with_resolved=1' : '') + (typeFilter ? '&type=' + typeFilter : '');
    fetch('/incidents/api.php' + qs).then(function(r){ return r.json(); }).then(function(rows) {
      markerLayer.forEach(function(m){ m.remove(); });
      markerLayer = [];
      var bounds = null;
      rows.forEach(function(r) {
        if (r.lat == null || r.lng == null) return;
        var m = NI.addToMap(map, r, { command: IS_COMMAND });
        if (!m) return;
        markerLayer.push(m);
        if (!bounds) bounds = new maplibregl.LngLatBounds([r.lng,r.lat],[r.lng,r.lat]);
        else bounds.extend([r.lng,r.lat]);
      });
      if (bounds && map.getZoom() < 9 && !pickingPin) {
        map.fitBounds(bounds, {padding:60, maxZoom:14, duration:0});
      }
    }).catch(function(){});
  }
  map.on('load', loadMarkers);
  setInterval(loadMarkers, 30000);

  // ── Drop-pin mode for the submit form ──────────────────────────────────────
  var newPinMarker = null;
  var pickingPin   = false;
  var pickBtn = document.getElementById('inc-pick-btn');
  var statusEl = document.getElementById('inc-pin-status');
  var latIn  = document.getElementById('inc-lat');
  var lngIn  = document.getElementById('inc-lng');

  function startPick() {
    pickingPin = true;
    document.body.classList.add('drop-pin-mode');
    if (pickBtn) { pickBtn.classList.add('active'); pickBtn.textContent = '× Cancel'; }
    document.getElementById('mainmap').scrollIntoView({behavior:'smooth', block:'nearest'});
  }
  function endPick() {
    pickingPin = false;
    document.body.classList.remove('drop-pin-mode');
    if (pickBtn) {
      pickBtn.classList.remove('active');
      if (latIn.value && lngIn.value) {
        pickBtn.classList.add('has-pin');
        pickBtn.textContent = '📍 Change location';
      } else {
        pickBtn.classList.remove('has-pin');
        pickBtn.textContent = '📍 Choose location on map';
      }
    }
  }
  function setPin(lng, lat) {
    latIn.value = lat.toFixed(6);
    lngIn.value = lng.toFixed(6);
    if (newPinMarker) newPinMarker.remove();
    var el = document.createElement('div');
    el.style.cssText = 'width:30px;height:30px;border-radius:50%;background:#e94560;border:3px solid #fff;display:flex;align-items:center;justify-content:center;font-size:16px;box-shadow:0 3px 10px rgba(0,0,0,.7);z-index:10';
    el.textContent = '📍';
    el.title = 'New incident location';
    newPinMarker = new maplibregl.Marker({element:el, anchor:'center'}).setLngLat([lng, lat]).addTo(map);
    if (statusEl) { statusEl.textContent = 'Pinned at ' + lat.toFixed(5) + ', ' + lng.toFixed(5); statusEl.style.color = '#2ecc71'; }
  }

  if (pickBtn) pickBtn.addEventListener('click', function() { pickingPin ? endPick() : startPick(); });
  map.on('click', function(e) {
    if (!pickingPin) return;
    setPin(e.lngLat.lng, e.lngLat.lat);
    endPick();
  });
  document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && pickingPin) endPick(); });
})();
</script>
<?php endif; ?>
</body>
</html>
