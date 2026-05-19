<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
require_once '/var/www/noosphere/shared/region.php';
sec_session_start();
if (get_setting('show_radio','0') !== '1') { http_response_code(404); exit; }

$name = get_setting('instance_name', 'Noosphere');
$MODELS_JSON  = '/var/lib/noosphere/radio/chirp-models.json';
$COUNTY_DIR   = region_path('radio');
$PROG_SCRIPT  = '/usr/local/bin/noosphere-radio-program.py';

// AJAX: start programming job
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'program') {
    csrf_verify();
    header('Content-Type: application/json');

    $driver  = preg_replace('/[^A-Za-z0-9_\-]/', '', $_POST['driver']  ?? '');
    $port    = preg_replace('/[^A-Za-z0-9_\/\-]/', '', $_POST['port']   ?? '');
    $counties = array_filter(explode(',', $_POST['counties'] ?? ''));

    if (!$driver || !$port) { echo json_encode(['ok'=>false,'error'=>'Missing driver or port']); exit; }

    $county_files = [];
    foreach ($counties as $c) {
        $slug = preg_replace('/[^a-z]/', '', strtolower(trim($c)));
        $path = "$COUNTY_DIR/$slug.json";
        if (file_exists($path)) $county_files[] = $path;
    }
    if (!$county_files) { echo json_encode(['ok'=>false,'error'=>'No valid county data selected']); exit; }
    if (!file_exists($port) && !preg_match('/^\/dev\/tty/', $port)) {
        echo json_encode(['ok'=>false,'error'=>'Invalid port']); exit;
    }

    $job_id = bin2hex(random_bytes(8));
    $params = ['driver'=>$driver, 'port'=>$port, 'county_files'=>$county_files];
    file_put_contents("/tmp/noosphere-prog-{$job_id}-params.json", json_encode($params));
    file_put_contents("/tmp/noosphere-prog-{$job_id}-status.json",
        json_encode(['state'=>'queued','message'=>'Starting…','progress'=>0,'channels'=>0]));

    $log = "/tmp/noosphere-prog-{$job_id}.log";
    $cmd = "nohup python3 " . escapeshellarg($PROG_SCRIPT) . " " . escapeshellarg($job_id) . " > " . escapeshellarg($log) . " 2>&1 &";
    shell_exec($cmd);

    echo json_encode(['ok'=>true, 'job_id'=>$job_id]);
    exit;
}

// AJAX: job status
if (isset($_GET['status'])) {
    $job_id = preg_replace('/[^a-f0-9]/', '', $_GET['status']);
    header('Content-Type: application/json');
    $path = "/tmp/noosphere-prog-{$job_id}-status.json";
    echo file_exists($path) ? file_get_contents($path) : json_encode(['state'=>'unknown']);
    exit;
}

// AJAX: available ports
if (isset($_GET['ports'])) {
    header('Content-Type: application/json');
    $ports = [];
    foreach (glob('/dev/ttyUSB*') ?: [] as $p) $ports[] = $p;
    foreach (glob('/dev/ttyACM*') ?: [] as $p) $ports[] = $p;
    sort($ports);
    echo json_encode($ports);
    exit;
}

// Load model list and county list
$brands = file_exists($MODELS_JSON) ? json_decode(file_get_contents($MODELS_JSON), true) : [];
$counties = [];
foreach (glob("$COUNTY_DIR/*.json") ?: [] as $f) {
    $data = json_decode(file_get_contents($f), true);
    if ($data) $counties[] = ['slug'=>basename($f,'.json'), 'name'=>$data['county'].' County', 'data'=>$data];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Radio Programmer  -  <?= htmlspecialchars($name) ?></title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:sans-serif; background:#1a1a2e; color:#eee; min-height:100vh; padding:1.5rem; }
.topbar { display:flex; align-items:center; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap; }
.topbar h1 { font-size:1.4rem; color:#e94560; flex:1; }
a.back { color:#aaa; text-decoration:none; font-size:13px; }
a.back:hover { color:#e94560; }
.card { background:#16213e; border:1px solid #2a2a4a; border-radius:10px; padding:1.25rem; margin-bottom:1.25rem; }
.card h2 { font-size:.95rem; color:#e94560; margin-bottom:1rem; text-transform:uppercase; letter-spacing:.05em; }
.grid2 { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; }
@media(max-width:640px){ .grid2 { grid-template-columns:1fr; } }
label { display:block; font-size:11px; color:#888; margin-bottom:4px; text-transform:uppercase; letter-spacing:.04em; }
select, input[type=text] {
  width:100%; background:#0f0f1a; border:1px solid #2a2a4a; border-radius:5px;
  color:#eee; padding:8px 10px; font-size:13px; }
select:focus, input:focus { outline:none; border-color:#e94560; }
.port-row { display:flex; gap:8px; align-items:flex-end; }
.port-row select { flex:1; }
.btn-icon { background:#0f0f1a; border:1px solid #2a2a4a; color:#aaa; border-radius:5px; padding:8px 12px; cursor:pointer; font-size:14px; }
.btn-icon:hover { border-color:#e94560; color:#e94560; }
.county-checks { display:flex; gap:14px; flex-wrap:wrap; padding:4px 0; }
.county-checks label { text-transform:none; font-size:13px; color:#ccc; display:flex; align-items:center; gap:5px; cursor:pointer; }
.county-checks input[type=checkbox] { width:auto; accent-color:#e94560; }
.search-box { margin-bottom:8px; }
#brand-sel { margin-bottom:8px; }
.btn-program { width:100%; background:#e94560; color:#fff; border:none; border-radius:8px;
  padding:14px; font-size:15px; font-weight:bold; cursor:pointer; letter-spacing:.03em; }
.btn-program:hover { background:#c73652; }
.btn-program:disabled { background:#555; cursor:not-allowed; }
.preview-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; font-size:12px; }
th { color:#666; text-align:left; padding:5px 8px; border-bottom:1px solid #2a2a4a; white-space:nowrap; }
td { padding:5px 8px; border-bottom:1px solid #161628; }
.freq-val { font-family:monospace; color:#2ecc71; font-weight:bold; }
.ch-name { font-family:monospace; color:#7ad; font-size:11px; }
.badge-sm { font-size:10px; background:#0f0f1a; border:1px solid #2a2a4a; border-radius:3px; padding:1px 5px; color:#888; }
.status-box { border-radius:8px; padding:14px 16px; font-size:13px; display:none; }
.status-box.active { display:block; }
.status-box.starting,.status-box.reading,.status-box.writing,.status-box.saving { background:#111126; border:1px solid #2a2a4a; }
.status-box.done    { background:#0d2d0d; border:1px solid #2ecc71; color:#2ecc71; }
.status-box.error   { background:#2d0d0d; border:1px solid #e94560; color:#e94560; }
.progress-bar { height:6px; background:#0f0f1a; border-radius:3px; margin-top:10px; overflow:hidden; }
.progress-fill { height:100%; background:#e94560; border-radius:3px; transition:width .3s; }
.no-port { background:#111126; border:1px solid #2a2a4a; border-radius:6px; padding:10px 14px; font-size:12px; color:#555; }
</style>
</head>
<body>
<div class="topbar">
  <h1>📻 Radio Programmer</h1>
  <a class="back" href="/radio/reference/">← Reference</a>
  <a class="back" href="/radio/">Radio Log</a>
</div>

<div class="grid2">
  <!-- LEFT: Hardware -->
  <div>
    <div class="card">
      <h2>1  -  USB Port</h2>
      <div class="port-row">
        <select id="port-sel">
          <option value=""> -  scanning…  - </option>
        </select>
        <button class="btn-icon" onclick="refreshPorts()" title="Refresh">↺</button>
      </div>
      <div style="font-size:11px;color:#555;margin-top:8px">
        Plug in the radio programming cable before selecting. Common cables use CP2102 or PL2303 adapters.
      </div>
    </div>

    <div class="card">
      <h2>2  -  Radio Model</h2>
      <div class="search-box">
        <input type="text" id="model-search" placeholder="Search models… (e.g. UV-5R, FT-60R)" oninput="filterModels()">
      </div>
      <select id="brand-sel" onchange="populateModels()">
        <option value=""> -  select brand  - </option>
        <?php foreach ($brands as $b): ?>
        <option value="<?= htmlspecialchars($b['brand']) ?>"><?= htmlspecialchars($b['brand']) ?> (<?= count($b['radios']) ?>)</option>
        <?php endforeach; ?>
      </select>
      <select id="model-sel" size="7" style="height:160px;margin-top:0">
        <option value=""> -  select brand first  - </option>
      </select>
    </div>
  </div>

  <!-- RIGHT: Data -->
  <div>
    <div class="card">
      <h2>3  -  Frequency Data</h2>
      <label>Counties to include</label>
      <div class="county-checks">
        <?php foreach ($counties as $c): ?>
        <label>
          <input type="checkbox" class="county-chk" value="<?= htmlspecialchars($c['slug']) ?>" checked>
          <?= htmlspecialchars($c['name']) ?>
        </label>
        <?php endforeach; ?>
      </div>
      <div style="font-size:11px;color:#555;margin-top:10px">
        Source: <code style="color:#888"><?= htmlspecialchars($COUNTY_DIR) ?>/</code>
      </div>
    </div>

    <div class="card">
      <h2>4  -  Channel Preview</h2>
      <div id="preview-area">
        <div class="no-port">Select radio model and county data to preview channels.</div>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div id="status-box" class="status-box">
    <div id="status-msg"> - </div>
    <div class="progress-bar"><div id="progress-fill" class="progress-fill" style="width:0%"></div></div>
    <div id="status-detail" style="font-size:11px;color:#555;margin-top:6px"></div>
  </div>
  <button id="prog-btn" class="btn-program" onclick="startProgramming()" disabled>
    Select port + model to program
  </button>
</div>

<script>
var BRANDS  = <?= json_encode($brands) ?>;
var COUNTIES = <?= json_encode($counties) ?>;
var csrfToken = '<?= csrf_token() ?>';
var currentJob = null, pollTimer = null;

// ── Port detection ──────────────────────────────────────────────────
function refreshPorts() {
  fetch('?ports=1').then(r=>r.json()).then(function(ports){
    var sel = document.getElementById('port-sel');
    var prev = sel.value;
    sel.innerHTML = ports.length
      ? ports.map(p=>`<option value="${p}"${p===prev?' selected':''}>${p}</option>`).join('')
      : '<option value="">No USB serial devices found  -  plug in cable</option>';
    checkReady();
  });
}

// ── Model picker ────────────────────────────────────────────────────
function populateModels() {
  var brand = document.getElementById('brand-sel').value;
  var q     = document.getElementById('model-search').value.toLowerCase().trim();
  var sel   = document.getElementById('model-sel');
  sel.innerHTML = '';
  var brandData = BRANDS.find(b=>b.brand===brand);
  var radios = brandData ? brandData.radios : [];
  if (q) radios = BRANDS.flatMap(b=>b.radios).filter(r=>r.label.toLowerCase().includes(q)||r.model.toLowerCase().includes(q));
  if (!radios.length) { sel.innerHTML = '<option value="">No models found</option>'; return; }
  sel.innerHTML = radios.map(r=>`<option value="${r.key}">${r.label}</option>`).join('');
  sel.size = Math.min(radios.length, 10);
  sel.addEventListener('change', function(){ updatePreview(); checkReady(); }, {once:false});
  checkReady();
}

function filterModels() {
  var q = document.getElementById('model-search').value.toLowerCase().trim();
  if (q.length < 2) { populateModels(); return; }
  var sel = document.getElementById('model-sel');
  var radios = BRANDS.flatMap(b=>b.radios).filter(r=>
    r.label.toLowerCase().includes(q) || r.model.toLowerCase().includes(q)
  );
  sel.innerHTML = radios.length
    ? radios.map(r=>`<option value="${r.key}">[${r.model}] ${r.label}</option>`).join('')
    : '<option value="">No matches</option>';
  sel.size = Math.min(Math.max(radios.length, 1), 10);
  checkReady();
}

// ── Channel preview ─────────────────────────────────────────────────
function getSelectedCounties() {
  return Array.from(document.querySelectorAll('.county-chk:checked')).map(c=>c.value);
}

function buildChannelList() {
  var slugs = getSelectedCounties();
  var entries = [];
  var maxCh = 128;
  var validChars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ 0123456789';
  var maxName = 7;
  COUNTIES.filter(c=>slugs.includes(c.slug)).forEach(function(county){
    county.data.agencies.forEach(function(ag){
      ag.frequencies.forEach(function(f){
        if (entries.length >= maxCh) return;
        var raw = (ag.short + ' ' + f.tag).toUpperCase();
        var name = '';
        for (var i=0;i<raw.length&&name.length<maxName;i++){
          if (validChars.includes(raw[i])) name += raw[i];
          else if (name.slice(-1) !== ' ') name += ' ';
        }
        name = name.trim().substring(0, maxName);
        entries.push({ch: entries.length, freq: f.freq, name: name, tone: f.tone, mode: f.mode, tag: f.tag, agency: ag.name});
      });
    });
  });
  return entries;
}

function updatePreview() {
  var entries = buildChannelList();
  var area = document.getElementById('preview-area');
  if (!entries.length) {
    area.innerHTML = '<div class="no-port">No county data selected.</div>'; return;
  }
  var rows = entries.map(e=>`
    <tr>
      <td style="color:#555;font-size:11px">${String(e.ch).padStart(3,'0')}</td>
      <td><span class="freq-val">${e.freq} MHz</span></td>
      <td><span class="ch-name">${e.name}</span></td>
      <td><span class="badge-sm">${e.tone ? e.tone+' Hz' : ' - '}</span></td>
      <td style="color:#555;font-size:11px">${e.mode}</td>
      <td style="color:#666;font-size:11px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${e.agency}</td>
    </tr>`).join('');
  area.innerHTML = `
    <div style="font-size:11px;color:#555;margin-bottom:8px">${entries.length} channel${entries.length!==1?'s':''} will be written</div>
    <div class="preview-wrap">
    <table>
      <thead><tr>
        <th>Ch</th><th>Frequency</th><th>Name</th><th>Tone</th><th>Mode</th><th>Agency</th>
      </tr></thead>
      <tbody>${rows}</tbody>
    </table></div>`;
}

// ── Readiness check ─────────────────────────────────────────────────
function checkReady() {
  var port  = document.getElementById('port-sel').value;
  var model = document.getElementById('model-sel').value;
  var btn   = document.getElementById('prog-btn');
  if (port && model && !currentJob) {
    var modelName = document.getElementById('model-sel').selectedOptions[0]?.text || 'radio';
    btn.disabled = false;
    btn.textContent = '⚡ Program ' + modelName + ' on ' + port;
  } else if (!port && !model) {
    btn.disabled = true;
    btn.textContent = 'Select port + model to program';
  } else if (!port) {
    btn.disabled = true;
    btn.textContent = 'Connect programming cable first';
  } else {
    btn.disabled = true;
    btn.textContent = 'Select radio model to continue';
  }
}

// ── Programming ─────────────────────────────────────────────────────
function startProgramming() {
  if (currentJob) return;
  var port    = document.getElementById('port-sel').value;
  var driver  = document.getElementById('model-sel').value;
  var counties = getSelectedCounties().join(',');
  if (!port || !driver || !counties) return;

  var btn = document.getElementById('prog-btn');
  btn.disabled = true;
  btn.textContent = 'Programming…';
  setStatus('starting', 'Sending job to server…', 0);

  var fd = new FormData();
  fd.append('act', 'program');
  fd.append('driver', driver);
  fd.append('port', port);
  fd.append('counties', counties);
  fd.append('csrf_token', csrfToken);

  fetch('', {method:'POST', body: fd})
    .then(r=>r.json())
    .then(function(r){
      if (!r.ok) { setStatus('error', r.error || 'Server error'); btn.disabled=false; btn.textContent='Retry'; return; }
      currentJob = r.job_id;
      pollStatus();
    })
    .catch(function(e){ setStatus('error', 'Network error: ' + e); btn.disabled=false; });
}

function pollStatus() {
  if (!currentJob) return;
  fetch('?status=' + currentJob)
    .then(r=>r.json())
    .then(function(s){
      setStatus(s.state, s.message, s.progress, s.channels, s.error);
      if (s.state === 'done' || s.state === 'error') {
        currentJob = null;
        var btn = document.getElementById('prog-btn');
        btn.disabled = false;
        btn.textContent = s.state === 'done' ? '⚡ Program Another Radio' : '⚡ Retry';
      } else {
        pollTimer = setTimeout(pollStatus, 1000);
      }
    })
    .catch(function(){ pollTimer = setTimeout(pollStatus, 2000); });
}

function setStatus(state, msg, pct, channels, error) {
  var box  = document.getElementById('status-box');
  var msgEl = document.getElementById('status-msg');
  var fill = document.getElementById('progress-fill');
  var det  = document.getElementById('status-detail');
  box.className = 'status-box active ' + state;
  msgEl.textContent = msg || state;
  fill.style.width  = (pct || 0) + '%';
  var parts = [];
  if (channels) parts.push(channels + ' channels written');
  if (error)    parts.push('Error: ' + error);
  det.textContent = parts.join(' · ');
}

// ── County change listener ───────────────────────────────────────────
document.querySelectorAll('.county-chk').forEach(function(cb){
  cb.addEventListener('change', updatePreview);
});

// ── Init ─────────────────────────────────────────────────────────────
document.getElementById('model-sel').addEventListener('change', function(){
  updatePreview(); checkReady();
});
document.getElementById('port-sel').addEventListener('change', checkReady);
refreshPorts();
setInterval(refreshPorts, 5000);
updatePreview();
</script>
</body>
</html>
