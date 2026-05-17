<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();
if (get_setting('show_damage','0') !== '1') { http_response_code(404); exit; }

$is_admin    = !empty($_SESSION['admin']);
$is_readonly = is_readonly();

$db = new SQLite3('/var/lib/noosphere/damage.db');
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("CREATE TABLE IF NOT EXISTS reports (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    submitted_at     INTEGER NOT NULL,
    address          TEXT NOT NULL,
    structure_type   TEXT NOT NULL DEFAULT 'residence',
    damage_level     TEXT NOT NULL DEFAULT 'minor',
    occupants_accounted TEXT NOT NULL DEFAULT 'unknown',
    occupant_count   INTEGER,
    utilities_affected TEXT,
    hazards          TEXT,
    reporter_name    TEXT,
    photo_path       TEXT,
    notes            TEXT
)");
@$db->exec("ALTER TABLE reports ADD COLUMN lat REAL");
@$db->exec("ALTER TABLE reports ADD COLUMN lng REAL");

$STRUCTURE_TYPES = ['residence'=>'Residence','commercial'=>'Commercial','agricultural'=>'Agricultural','infrastructure'=>'Infrastructure','other'=>'Other'];
$DAMAGE_LEVELS   = ['none'=>'None / Undamaged','minor'=>'Minor','major'=>'Major','destroyed'=>'Destroyed','inaccessible'=>'Inaccessible'];
$ACCOUNTED       = ['yes'=>'Yes','no'=>'No','unknown'=>'Unknown'];
$UTILITIES       = ['electric'=>'Electric','gas'=>'Gas','water'=>'Water'];
$DAMAGE_COLORS   = ['none'=>'#2ecc71','minor'=>'#f39c12','major'=>'#e67e22','destroyed'=>'#e94560','inaccessible'=>'#9b59b6'];

$msg = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_readonly) {
    csrf_verify();
    $act = $_POST['act'] ?? '';

    if ($act === 'submit') {
        $address  = trim($_POST['address'] ?? '');
        $stype    = $_POST['structure_type'] ?? 'residence';
        $dlevel   = $_POST['damage_level'] ?? 'minor';
        $acct     = $_POST['occupants_accounted'] ?? 'unknown';
        $ocount   = strlen(trim($_POST['occupant_count'] ?? '')) ? (int)$_POST['occupant_count'] : null;
        $utils    = array_intersect(array_keys($UTILITIES), (array)($_POST['utilities_affected'] ?? []));
        $hazards  = trim($_POST['hazards'] ?? '');
        $reporter = trim($_POST['reporter_name'] ?? '');
        $notes    = trim($_POST['notes'] ?? '');
        $lat_raw  = trim($_POST['lat'] ?? '');
        $lng_raw  = trim($_POST['lng'] ?? '');
        $lat      = ($lat_raw !== '' && is_numeric($lat_raw) && abs((float)$lat_raw) <= 90)  ? (float)$lat_raw : null;
        $lng      = ($lng_raw !== '' && is_numeric($lng_raw) && abs((float)$lng_raw) <= 180) ? (float)$lng_raw : null;

        if (!$address) {
            $error = 'Address / location description is required.';
        } else {
            $stype  = array_key_exists($stype, $STRUCTURE_TYPES) ? $stype : 'residence';
            $dlevel = array_key_exists($dlevel, $DAMAGE_LEVELS)  ? $dlevel : 'minor';
            $acct   = array_key_exists($acct, $ACCOUNTED)        ? $acct   : 'unknown';
            $utils_str = $utils ? implode(',', $utils) : null;

            $photo_path = null;
            if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
                $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                    $dest_dir = '/var/lib/noosphere/damage_photos';
                    if (!is_dir($dest_dir)) mkdir($dest_dir, 0750, true);
                    $fname = uniqid('dmg_', true) . '.' . $ext;
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], "$dest_dir/$fname")) {
                        $photo_path = $fname;
                    }
                }
            }

            $s = $db->prepare("INSERT INTO reports (submitted_at,address,structure_type,damage_level,occupants_accounted,occupant_count,utilities_affected,hazards,reporter_name,photo_path,notes,lat,lng)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $s->bindValue(1,  time(), SQLITE3_INTEGER);
            $s->bindValue(2,  $address, SQLITE3_TEXT);
            $s->bindValue(3,  $stype, SQLITE3_TEXT);
            $s->bindValue(4,  $dlevel, SQLITE3_TEXT);
            $s->bindValue(5,  $acct, SQLITE3_TEXT);
            $s->bindValue(6,  $ocount, $ocount !== null ? SQLITE3_INTEGER : SQLITE3_NULL);
            $s->bindValue(7,  $utils_str, $utils_str ? SQLITE3_TEXT : SQLITE3_NULL);
            $s->bindValue(8,  $hazards ?: null, $hazards ? SQLITE3_TEXT : SQLITE3_NULL);
            $s->bindValue(9,  $reporter ?: null, $reporter ? SQLITE3_TEXT : SQLITE3_NULL);
            $s->bindValue(10, $photo_path, $photo_path ? SQLITE3_TEXT : SQLITE3_NULL);
            $s->bindValue(11, $notes ?: null, $notes ? SQLITE3_TEXT : SQLITE3_NULL);
            $s->bindValue(12, $lat, $lat !== null ? SQLITE3_FLOAT : SQLITE3_NULL);
            $s->bindValue(13, $lng, $lng !== null ? SQLITE3_FLOAT : SQLITE3_NULL);
            $s->execute();
            $msg = 'Damage report submitted.';
        }
    }

    if ($act === 'delete' && $is_admin) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $row = $db->querySingle("SELECT photo_path FROM reports WHERE id=$id", true);
            if ($row && $row['photo_path']) @unlink('/var/lib/noosphere/damage_photos/' . $row['photo_path']);
            $db->exec("DELETE FROM reports WHERE id=$id");
        }
        $msg = 'Report deleted.';
    }
}

$filter = $_GET['filter'] ?? '';
$where  = $filter && array_key_exists($filter, $DAMAGE_LEVELS) ? "WHERE damage_level=" . $db->escapeString($filter) : '';
// use prepared approach for filter
$filter_safe = array_key_exists($filter, $DAMAGE_LEVELS) ? $filter : '';
$sql = "SELECT * FROM reports" . ($filter_safe ? " WHERE damage_level='" . $filter_safe . "'" : "") . " ORDER BY submitted_at DESC";
$result = $db->query($sql);
$rows = [];
while ($r = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;

$counts = [];
foreach ($DAMAGE_LEVELS as $k => $v) {
    $counts[$k] = (int)$db->querySingle("SELECT COUNT(*) FROM reports WHERE damage_level='$k'");
}
$total = array_sum($counts);

$name = get_setting('instance_name', 'Noosphere');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Damage Reports — <?= htmlspecialchars($name) ?></title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:sans-serif; background:#1a1a2e; color:#eee; min-height:100vh; padding:1.5rem; }
.topbar { display:flex; align-items:center; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap; }
.topbar h1 { font-size:1.5rem; color:#e94560; flex:1; }
a.back { color:#aaa; text-decoration:none; font-size:13px; }
a.back:hover { color:#e94560; }
.msg   { background:#1a3a1a; border:1px solid #2ecc71; color:#2ecc71; padding:8px 14px; border-radius:6px; margin-bottom:1rem; font-size:13px; }
.error { background:#3a0a0a; border:1px solid #e94560; color:#e94560; padding:8px 14px; border-radius:6px; margin-bottom:1rem; font-size:13px; }
.card { background:#16213e; border:1px solid #2a2a4a; border-radius:10px; padding:1.25rem; margin-bottom:1.25rem; }
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
.check-row { display:flex; gap:16px; flex-wrap:wrap; padding:6px 0; }
.check-row label { display:flex; align-items:center; gap:5px; color:#ccc; cursor:pointer; }
.check-row input { width:auto; }
table { width:100%; border-collapse:collapse; font-size:13px; }
th { text-align:left; color:#aaa; font-weight:normal; padding:6px 8px; border-bottom:1px solid #2a2a4a; white-space:nowrap; }
td { padding:7px 8px; border-bottom:1px solid #1a1a2e; vertical-align:top; }
tr:hover td { background:#1a1f35; }
.badge { display:inline-block; font-size:10px; font-weight:bold; border-radius:3px; padding:2px 7px; }
.filter-bar { display:flex; gap:8px; align-items:center; margin-bottom:1rem; flex-wrap:wrap; }
.filter-btn { font-size:11px; border:1px solid #2a2a4a; border-radius:4px; padding:4px 10px; cursor:pointer; background:#0f0f1a; color:#aaa; text-decoration:none; }
.filter-btn:hover,.filter-btn.active { border-color:#e94560; color:#e94560; }
.summary-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(100px,1fr)); gap:8px; margin-bottom:1.25rem; }
.sum-card { background:#0f0f1a; border-radius:6px; padding:10px; text-align:center; border:1px solid #2a2a4a; }
.sum-count { font-size:1.5rem; font-weight:bold; }
.sum-label { font-size:10px; color:#666; margin-top:2px; }
</style>
</head>
<body>
<div class="topbar">
  <h1>🏚 Damage Reports</h1>
  <a class="back" href="/">← Home</a>
</div>

<?php if ($msg):   ?><div class="msg"><?=   htmlspecialchars($msg)   ?></div><?php endif ?>
<?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif ?>

<div class="summary-grid">
  <div class="sum-card">
    <div class="sum-count" style="color:#7ad"><?= $total ?></div>
    <div class="sum-label">Total Reports</div>
  </div>
  <?php foreach ($DAMAGE_LEVELS as $k => $v):
    if (!$counts[$k]) continue; ?>
  <div class="sum-card">
    <div class="sum-count" style="color:<?= $DAMAGE_COLORS[$k] ?>"><?= $counts[$k] ?></div>
    <div class="sum-label"><?= htmlspecialchars($v) ?></div>
  </div>
  <?php endforeach; ?>
</div>

<?php if (!$is_readonly): ?>
<div class="card">
  <h2>Submit Damage Report</h2>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="submit">
    <div class="form-grid">
      <div class="form-full">
        <label>Address / Location Description *</label>
        <input type="text" name="address" placeholder="123 Main St, Columbus — or: 'Blue house at 5th and Oak'" maxlength="200" required>
      </div>
      <div>
        <label>Structure Type</label>
        <select name="structure_type">
          <?php foreach ($STRUCTURE_TYPES as $k => $v): ?>
            <option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Damage Level</label>
        <select name="damage_level">
          <?php foreach ($DAMAGE_LEVELS as $k => $v): ?>
            <option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Occupants Accounted For</label>
        <select name="occupants_accounted">
          <?php foreach ($ACCOUNTED as $k => $v): ?>
            <option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Number of Occupants (if known)</label>
        <input type="number" name="occupant_count" min="0" max="999" placeholder="—">
      </div>
      <div class="form-full">
        <label>Utilities Affected</label>
        <div class="check-row">
          <?php foreach ($UTILITIES as $k => $v): ?>
          <label><input type="checkbox" name="utilities_affected[]" value="<?= $k ?>"> <?= htmlspecialchars($v) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="form-full">
        <label>Hazards Present</label>
        <textarea name="hazards" placeholder="Gas leak, structural collapse, flooding, downed lines…"></textarea>
      </div>
      <div>
        <label>Reporting Party Name</label>
        <input type="text" name="reporter_name" placeholder="Your name" maxlength="80">
      </div>
      <div>
        <label>Photo (optional)</label>
        <input type="file" name="photo" accept="image/*" capture="environment" style="color:#aaa;font-size:12px">
      </div>
      <div class="form-full">
        <label>Additional Notes</label>
        <textarea name="notes" placeholder="Any other relevant information…"></textarea>
      </div>
      <?php if (get_setting('show_maps','1') === '1'): ?>
      <div class="form-full">
        <label>Pin on Map <span style="color:#555;font-weight:normal">(optional — tap the map to mark the exact location)</span></label>
        <input type="hidden" name="lat" id="dmg-lat">
        <input type="hidden" name="lng" id="dmg-lng">
        <div id="dmg-map-wrap" style="height:220px;border:1px solid #2a2a4a;border-radius:6px;overflow:hidden;position:relative;cursor:crosshair">
          <div id="dmg-map" style="height:100%"></div>
          <div id="dmg-map-hint" style="position:absolute;bottom:6px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,.65);color:#aaa;font-size:11px;padding:3px 10px;border-radius:4px;pointer-events:none">Tap to pin location</div>
        </div>
        <div id="dmg-pin-status" style="font-size:11px;color:#555;margin-top:4px"></div>
      </div>
      <?php endif ?>
      <div class="form-full">
        <button type="submit" class="btn">Submit Report</button>
      </div>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2>Reports (<?= $total ?> total<?= $filter_safe ? ' — filtered: ' . htmlspecialchars($DAMAGE_LEVELS[$filter_safe]) : '' ?>)</h2>

  <div class="filter-bar">
    <a href="?" class="filter-btn<?= !$filter_safe?' active':'' ?>">All</a>
    <?php foreach ($DAMAGE_LEVELS as $k => $v): ?>
    <a href="?filter=<?= $k ?>" class="filter-btn<?= $filter_safe===$k?' active':'' ?>"
       style="<?= $filter_safe===$k ? 'border-color:'.$DAMAGE_COLORS[$k].';color:'.$DAMAGE_COLORS[$k] : '' ?>">
      <span style="color:<?= $DAMAGE_COLORS[$k] ?>">●</span> <?= htmlspecialchars($v) ?> (<?= $counts[$k] ?>)
    </a>
    <?php endforeach; ?>
    <a href="export.php" style="margin-left:auto" class="filter-btn">⬇ CSV</a>
  </div>

  <div style="overflow-x:auto">
  <?php if (!$rows): ?>
    <div style="text-align:center;color:#555;padding:2rem">No reports<?= $filter_safe ? ' for this filter' : ' yet' ?>.</div>
  <?php else: ?>
  <table id="report-table">
    <thead>
      <tr>
        <th>Time</th>
        <th>Address</th>
        <th>Damage</th>
        <th>Type</th>
        <th>Occupants</th>
        <th>Utilities</th>
        <th>Hazards</th>
        <th>Reporter</th>
        <?php if ($is_admin): ?><th></th><?php endif ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
      $col = $DAMAGE_COLORS[$r['damage_level']] ?? '#aaa';
    ?>
      <tr>
        <td style="white-space:nowrap;color:#aaa;font-size:12px"><?= date('m/d H:i', $r['submitted_at']) ?></td>
        <td style="max-width:200px">
          <?= htmlspecialchars($r['address']) ?>
          <?php if ($r['photo_path']): ?>
            <a href="/damage-photos/<?= urlencode($r['photo_path']) ?>" target="_blank" style="font-size:10px;color:#7ad;display:block">📷 photo</a>
          <?php endif; ?>
        </td>
        <td><span class="badge" style="background:<?= $col ?>22;color:<?= $col ?>;border:1px solid <?= $col ?>44"><?= htmlspecialchars($DAMAGE_LEVELS[$r['damage_level']] ?? $r['damage_level']) ?></span></td>
        <td style="color:#aaa;font-size:12px"><?= htmlspecialchars($STRUCTURE_TYPES[$r['structure_type']] ?? $r['structure_type']) ?></td>
        <td style="color:#aaa;font-size:12px">
          <?= htmlspecialchars($ACCOUNTED[$r['occupants_accounted']] ?? '?') ?>
          <?= $r['occupant_count'] !== null ? ' (' . (int)$r['occupant_count'] . ')' : '' ?>
        </td>
        <td style="color:#aaa;font-size:11px"><?= $r['utilities_affected'] ? htmlspecialchars(implode(', ', array_map(fn($u)=>$UTILITIES[$u]??$u, explode(',', $r['utilities_affected'])))) : '—' ?></td>
        <td style="color:#f39c12;max-width:160px;font-size:11px"><?= htmlspecialchars($r['hazards'] ?? '') ?></td>
        <td style="color:#aaa;font-size:12px"><?= htmlspecialchars($r['reporter_name'] ?? '') ?></td>
        <?php if ($is_admin): ?>
        <td>
          <form method="post" onsubmit="return confirm('Delete this report?')">
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
  <?php endif; ?>
  </div>
</div>

<?php if (get_setting('show_maps','1') === '1'): ?>
<link rel="stylesheet" href="/maps/lib/maplibre-gl.css">
<script src="/maps/lib/maplibre-gl.js"></script>
<script>
(function() {
  var wrap = document.getElementById('dmg-map');
  if (!wrap) return;

  var map = new maplibregl.Map({
    container: 'dmg-map',
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
    center: [-85.90, 39.20], zoom: 11, maxZoom: 19, minZoom: 7,
    attributionControl: false,
  });

  var pinMarker = null;
  var hint = document.getElementById('dmg-map-hint');
  var status = document.getElementById('dmg-pin-status');

  map.on('click', function(e) {
    var lat = e.lngLat.lat.toFixed(6);
    var lng = e.lngLat.lng.toFixed(6);
    document.getElementById('dmg-lat').value = lat;
    document.getElementById('dmg-lng').value = lng;

    if (pinMarker) pinMarker.remove();
    var el = document.createElement('div');
    el.style.cssText = 'width:22px;height:22px;border-radius:50%;background:#e94560;border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.6)';
    pinMarker = new maplibregl.Marker({ element: el, anchor: 'center' })
      .setLngLat([parseFloat(lng), parseFloat(lat)]).addTo(map);

    if (hint) hint.style.display = 'none';
    if (status) status.textContent = 'Pinned at ' + lat + ', ' + lng + ' — tap again to move';
    status.style.color = '#2ecc71';
  });
})();
</script>
<?php endif ?>
</body>
</html>
