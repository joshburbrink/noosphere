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
            $stmt = $db->prepare("INSERT INTO incidents
                (submitted_at, updated_at, type, severity, title, description, location_text,
                 lat, lng, reporter_name, creator_token, photo_path, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'open')");
            $stmt->execute([
                $now, $now, $type, $sev, $title, $desc ?: null, $loc ?: null,
                $lat, $lng, $rep ?: null, $token, $photo,
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
.tab-bar { display:flex; gap:4px; margin-bottom:1rem; border-bottom:1px solid #2a2a4a; }
.tab-btn { background:none; border:none; border-bottom:2px solid transparent; color:#aaa; padding:8px 14px; cursor:pointer; font-size:13px; }
.tab-btn.active { color:#e94560; border-bottom-color:#e94560; }
#mapview { display:none; height:520px; border:1px solid #2a2a4a; border-radius:6px; overflow:hidden; }
.maplibregl-popup-content { background:#16213e !important; color:#eee !important; border:1px solid #2a2a4a; border-radius:6px; font-size:12px; padding:10px; }
.maplibregl-popup-tip { border-top-color:#16213e !important; border-bottom-color:#16213e !important; }
.maplibregl-popup-content a { color:#7aa7d9; }
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
      <?php if ($maps_on): ?>
      <div class="form-full">
        <label>Pin on Map <span style="color:#555;font-weight:normal">(optional — tap to mark exact location)</span></label>
        <input type="hidden" name="lat" id="inc-lat">
        <input type="hidden" name="lng" id="inc-lng">
        <div id="inc-map-wrap" style="height:220px;border:1px solid #2a2a4a;border-radius:6px;overflow:hidden;position:relative;cursor:crosshair">
          <div id="inc-map" style="height:100%"></div>
          <div id="inc-map-hint" style="position:absolute;bottom:6px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,.65);color:#aaa;font-size:11px;padding:3px 10px;border-radius:4px;pointer-events:none">Tap to pin location</div>
        </div>
        <div id="inc-pin-status" style="font-size:11px;color:#555;margin-top:4px"></div>
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
  <div class="tab-bar">
    <button type="button" class="tab-btn active" id="tab-list">List</button>
    <?php if ($maps_on): ?>
    <button type="button" class="tab-btn" id="tab-map">Map</button>
    <?php endif; ?>
  </div>

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
  <div id="mapview"></div>
  <?php endif; ?>
</div>

<?php if ($maps_on): ?>
<script>
(function() {
  // Shared map style builder
  function buildStyle() {
    return {
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
    };
  }

  // ── Submission map (pin-drop)
  var pinMapEl = document.getElementById('inc-map');
  if (pinMapEl) {
    var pmap = new maplibregl.Map({
      container: 'inc-map',
      style: buildStyle(),
      center: [-85.90, 39.20], zoom: 11, maxZoom: 19, minZoom: 7,
      attributionControl: false,
    });
    var pinMarker = null;
    var hint = document.getElementById('inc-map-hint');
    var status = document.getElementById('inc-pin-status');
    pmap.on('click', function(e) {
      var lat = e.lngLat.lat.toFixed(6);
      var lng = e.lngLat.lng.toFixed(6);
      document.getElementById('inc-lat').value = lat;
      document.getElementById('inc-lng').value = lng;
      if (pinMarker) pinMarker.remove();
      var el = document.createElement('div');
      el.style.cssText = 'width:22px;height:22px;border-radius:50%;background:#e94560;border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.6)';
      pinMarker = new maplibregl.Marker({ element: el, anchor: 'center' })
        .setLngLat([parseFloat(lng), parseFloat(lat)]).addTo(pmap);
      if (hint) hint.style.display = 'none';
      if (status) { status.textContent = 'Pinned at ' + lat + ', ' + lng + ' — tap again to move'; status.style.color = '#2ecc71'; }
    });
  }

  // ── List/Map view tabs
  var tabList = document.getElementById('tab-list');
  var tabMap  = document.getElementById('tab-map');
  var lv = document.getElementById('listview');
  var mv = document.getElementById('mapview');
  var bigMap = null;
  var markerLayer = [];
  var refreshTimer = null;

  function showList() {
    tabList.classList.add('active'); tabMap && tabMap.classList.remove('active');
    lv.style.display = ''; mv.style.display = 'none';
    if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null; }
  }
  function showMap() {
    tabMap.classList.add('active'); tabList.classList.remove('active');
    lv.style.display = 'none'; mv.style.display = 'block';
    if (!bigMap) {
      bigMap = new maplibregl.Map({
        container: 'mapview',
        style: buildStyle(),
        center: [-85.90, 39.20], zoom: 10, maxZoom: 19, minZoom: 6,
        attributionControl: false,
      });
      bigMap.addControl(new maplibregl.NavigationControl(), 'top-right');
    }
    loadMarkers();
    if (!refreshTimer) refreshTimer = setInterval(loadMarkers, 30000);
  }

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
        var color = r.severity ? ({critical:'#e94560',serious:'#e67e22',minor:'#f39c12',info:'#7aa7d9'}[r.severity] || '#7aa7d9')
                               : ({damage:'#e67e22',medical:'#e94560',hazard:'#f39c12',missing:'#9b59b6',resource:'#2ecc71',general:'#7aa7d9'}[r.type] || '#7aa7d9');
        var dim = r.status === 'resolved' ? 'opacity:.4;' : '';
        var el = document.createElement('div');
        el.style.cssText = 'width:18px;height:18px;border-radius:50%;background:'+color+';border:2px solid #fff;box-shadow:0 2px 4px rgba(0,0,0,.5);cursor:pointer;'+dim;
        var popup = new maplibregl.Popup({offset:14}).setHTML(
          '<div style="font-weight:bold;color:'+color+';margin-bottom:4px">'+escapeHtml(r.title)+'</div>'
          + '<div style="color:#aaa;font-size:11px;margin-bottom:4px">'
          +   (r.type||'') + (r.severity?' · '+r.severity:'') + ' · '+r.status
          + '</div>'
          + (r.description ? '<div style="font-size:12px;margin-bottom:4px">'+escapeHtml(r.description.slice(0,200))+(r.description.length>200?'…':'')+'</div>' : '')
          + (r.location_text ? '<div style="color:#aaa;font-size:11px">📍 '+escapeHtml(r.location_text)+'</div>' : '')
          + (r.reporter_name ? '<div style="color:#aaa;font-size:11px">— '+escapeHtml(r.reporter_name)+'</div>' : '')
          + (r.photo_path ? '<div style="margin-top:4px"><a href="/incident-photos/'+encodeURIComponent(r.photo_path)+'" target="_blank">📷 photo</a></div>' : '')
        );
        var m = new maplibregl.Marker({element:el, anchor:'center'}).setLngLat([r.lng, r.lat]).setPopup(popup).addTo(bigMap);
        markerLayer.push(m);
        if (!bounds) bounds = new maplibregl.LngLatBounds([r.lng,r.lat],[r.lng,r.lat]);
        else bounds.extend([r.lng,r.lat]);
      });
      if (bounds && bigMap.getZoom() < 8) bigMap.fitBounds(bounds, {padding:60, maxZoom:14});
    });
  }
  function escapeHtml(s){ return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

  if (tabList) tabList.addEventListener('click', showList);
  if (tabMap)  tabMap.addEventListener('click', showMap);
})();
</script>
<?php endif; ?>
</body>
</html>
