<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
require_once '/var/www/noosphere/shared/identity.php';
require_once '/var/www/noosphere/shared/capabilities.php';
sec_session_start();
if (get_setting('show_chat','1') !== '1') { http_response_code(404); exit; }

// Mesh tab integration (#101) - only surface if operator enabled the mesh module,
// the daemon has initialised the DB, and the per-chat tab toggle is on.
$mesh_available = false;
$mesh_can_send  = false;
if (get_setting('show_mesh','0') === '1' && get_setting('chat_mesh_tab','1') === '1') {
    try {
        $mdb = new PDO('sqlite:/var/lib/noosphere/mesh.db');
        $mdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $r = $mdb->query("SELECT name FROM sqlite_master WHERE type='table' AND name='mesh_messages'")->fetch();
        $mesh_available = (bool)$r;
    } catch (Exception $e) { $mesh_available = false; }
    if ($mesh_available) $mesh_can_send = function_exists('can') ? can('mesh.send') : false;
}

$db = new PDO('sqlite:/var/lib/noosphere/chat.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("PRAGMA busy_timeout=2000");
$db->exec("CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    body TEXT NOT NULL,
    reg_status TEXT,
    reg_location TEXT,
    created_at INTEGER NOT NULL
)");

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'poll') {
    $since = (int)($_GET['since'] ?? 0);
    $s = $db->prepare('SELECT * FROM messages WHERE id > ? ORDER BY id ASC LIMIT 60');
    $s->execute([$since]);
    header('Content-Type: application/json');
    echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action === 'verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    ban_check_or_die();
    $name = trim($_POST['name'] ?? '');
    $pin  = trim($_POST['pin']  ?? '');
    $row  = verify_pin($name, $pin);
    if ($row) {
        $_SESSION['cname']    = $row['name'];
        $_SESSION['cstatus']  = $row['status'];
        $_SESSION['cloc']     = $row['location'];
        $_SESSION['cverified']= true;
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => (bool)$row, 'name' => $row ? $row['name'] : '']);
    exit;
}

if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (is_readonly()) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'err'=>'readonly']); exit; }
    if (get_setting('require_registration','0') === '1') {
        $cname_post = trim($_POST['name'] ?? '');
        $verified = !empty($_SESSION['cverified']) && ($_SESSION['cname'] ?? '') === $cname_post;
        $reg_session = !empty($_SESSION['reg_name']) && strtolower($_SESSION['reg_name']) === strtolower($cname_post);
        if (!$verified && !$reg_session) {
            header('Content-Type: application/json');
            echo json_encode(['ok'=>false,'err'=>'You must be verified in the registry to chat. Click the "Verify" button to link your registry name.']);
            exit;
        }
    }
    $name = trim($_POST['name'] ?? '');
    ban_check_or_die($name);
    $body = trim($_POST['body'] ?? '');
    if ($name && $body && mb_strlen($body) <= 1000) {
        $status = $loc = null;
        if (!empty($_SESSION['cverified']) && $_SESSION['cname'] === $name) {
            $status = $_SESSION['cstatus'];
            $loc    = $_SESSION['cloc'];
        }
        rate_limit('chat', 20, 60);
        $s = $db->prepare('INSERT INTO messages (name,body,reg_status,reg_location,created_at) VALUES(?,?,?,?,?)');
        $s->execute([$name, $body, $status, $loc, time()]);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'id' => $db->lastInsertId()]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false]);
    }
    exit;
}

// Initial load  -  last 60 messages for seeding
$seed = $db->query('SELECT * FROM messages ORDER BY id DESC LIMIT 60')->fetchAll(PDO::FETCH_ASSOC);
$seed = array_reverse($seed);
$last_id = $seed ? end($seed)['id'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Chat  -  Noosphere</title>
<?= csrf_js() ?>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
html,body { height:100%; }
body { font-family:system-ui,sans-serif; background:#0f0f1a; color:#e0e0e0; display:flex; flex-direction:column; }
header { background:#1a1a2e; border-bottom:2px solid #e94560; padding:10px 16px; display:flex; align-items:center; gap:12px; flex-shrink:0; }
header h1 { font-size:15px; color:#e94560; }
header a { color:#888; text-decoration:none; font-size:13px; }
header a:hover { color:#e94560; }
.badge-bar { background:#16213e; border-bottom:1px solid #2a2a4a; padding:8px 14px; font-size:12px; color:#888; display:flex; align-items:center; gap:10px; flex-shrink:0; }
.badge-bar .verified { color:#2ecc71; }
.badge-bar button { background:none; border:1px solid #2a2a4a; color:#aaa; padding:3px 10px; border-radius:4px; cursor:pointer; font-size:11px; }
.badge-bar button:hover { border-color:#e94560; color:#e94560; }
#messages { flex:1; overflow-y:auto; padding:12px 14px; display:flex; flex-direction:column; gap:8px; }
.msg { max-width:80%; }
.msg.mine { align-self:flex-end; }
.msg-meta { font-size:11px; color:#666; margin-bottom:2px; }
.msg.mine .msg-meta { text-align:right; }
.msg-bubble { background:#1a1a2e; border:1px solid #2a2a4a; border-radius:10px; padding:8px 12px; font-size:14px; line-height:1.4; word-break:break-word; }
.msg.mine .msg-bubble { background:#16213e; border-color:#e94560; }
.dot { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:3px; }
.dot-ok { background:#2ecc71; }
.dot-help { background:#e94560; }
.dot-evacuated { background:#f8c000; }
.dot-unknown { background:#888; }
.loc { color:#888; font-size:11px; }
#compose { background:#1a1a2e; border-top:1px solid #2a2a4a; padding:10px 12px; display:flex; gap:8px; flex-shrink:0; align-items:flex-end; }
#compose input[type=text], #compose textarea { background:#16213e; border:1px solid #2a2a4a; color:#e0e0e0; border-radius:6px; padding:8px 10px; font-size:14px; font-family:inherit; }
#compose input[type=text] { width:110px; flex-shrink:0; }
#compose textarea { flex:1; resize:none; height:40px; max-height:100px; }
#compose input:focus, #compose textarea:focus { outline:none; border-color:#e94560; }
#compose button { background:#e94560; color:#fff; border:none; border-radius:6px; padding:8px 16px; cursor:pointer; font-size:14px; font-weight:bold; white-space:nowrap; }
#compose button:hover { background:#c73652; }
/* verify modal */
#veil { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:100; align-items:center; justify-content:center; }
#veil.open { display:flex; }
#vmodal { background:#1a1a2e; border:1px solid #2a2a4a; border-radius:10px; padding:24px; width:320px; }
#vmodal h2 { color:#e94560; font-size:15px; margin-bottom:14px; }
#vmodal input { width:100%; padding:8px 10px; background:#16213e; border:1px solid #2a2a4a; border-radius:5px; color:#e0e0e0; font-size:14px; margin-bottom:10px; }
#vmodal input:focus { outline:none; border-color:#e94560; }
#vmodal .row { display:flex; gap:8px; }
#vmodal .row button { flex:1; padding:8px; border-radius:5px; border:none; cursor:pointer; font-size:13px; font-weight:bold; }
#vmodal .row .ok { background:#e94560; color:#fff; }
#vmodal .row .cancel { background:#16213e; color:#aaa; border:1px solid #2a2a4a; }
#verr { color:#e94560; font-size:12px; margin-bottom:8px; display:none; }
/* tab strip + mesh pane */
.tab-strip { background:#13132a; border-bottom:1px solid #2a2a4a; padding:6px 12px; display:flex; gap:6px; flex-shrink:0; }
.tab-strip button { background:transparent; border:1px solid #2a2a4a; color:#aaa; padding:5px 14px; border-radius:5px; cursor:pointer; font-size:13px; font-family:inherit; }
.tab-strip button.active { background:#1a1a2e; color:#fff; border-color:#e94560; }
.tab-strip .mesh-badge { margin-left:auto; font-size:11px; color:#666; align-self:center; }
.pane { flex:1; display:flex; flex-direction:column; min-height:0; }
.pane[hidden] { display:none !important; }
#mesh-list { flex:1; overflow-y:auto; padding:10px 14px; font-family:ui-monospace,Consolas,monospace; font-size:13px; }
#mesh-list .m { margin-bottom:7px; line-height:1.4; word-break:break-word; }
#mesh-list .m .t { color:#666; font-size:11px; }
#mesh-list .m .who { color:#7ad; font-weight:bold; }
#mesh-list .m.out .who { color:#2ecc71; }
#mesh-list .m .sig { color:#555; font-size:10px; margin-left:6px; }
#mesh-empty { color:#555; font-style:italic; text-align:center; padding:30px 10px; font-family:system-ui,sans-serif; }
#mesh-compose { background:#1a1a2e; border-top:1px solid #2a2a4a; padding:10px 12px; display:flex; gap:8px; flex-shrink:0; align-items:center; }
#mesh-compose select { background:#16213e; border:1px solid #2a2a4a; color:#e0e0e0; border-radius:6px; padding:8px; font-size:13px; }
#mesh-compose input[type=text] { flex:1; background:#16213e; border:1px solid #2a2a4a; color:#e0e0e0; border-radius:6px; padding:8px 10px; font-size:14px; font-family:inherit; }
#mesh-compose input:focus, #mesh-compose select:focus { outline:none; border-color:#e94560; }
#mesh-compose button { background:#1a4d7a; color:#fff; border:none; border-radius:6px; padding:8px 16px; cursor:pointer; font-size:14px; font-weight:bold; }
#mesh-compose button:disabled { opacity:0.5; cursor:not-allowed; }
#mesh-compose .count { color:#666; font-size:11px; width:40px; text-align:center; }
#mesh-readonly { background:#0d0d1a; border-top:1px solid #2a2a4a; color:#aaa; font-size:12px; padding:10px 14px; flex-shrink:0; }
#mesh-readonly a { color:#4a9eff; }
</style>
</head>
<body>
<header>
  <a href="/">← Home</a>
  <h1>Community Chat</h1>
</header>
<?php if ($mesh_available): ?>
<div class="tab-strip" id="tab-strip">
  <button id="tab-local" class="active" onclick="showTab('local')">💬 Local</button>
  <button id="tab-mesh" onclick="showTab('mesh')">📻 Mesh</button>
  <span class="mesh-badge" id="mesh-status">…</span>
</div>
<?php endif; ?>

<div class="pane" id="pane-local">
  <div class="badge-bar" id="badge-bar">
    <span id="badge-text">Chatting as guest</span>
    <button onclick="openVerify()">Link Registry</button>
  </div>
  <div id="messages"></div>
  <div id="compose">
    <input type="text" id="cname" placeholder="Your name" maxlength="40">
    <textarea id="cbody" placeholder="Message…" rows="1"></textarea>
    <button onclick="sendMsg()">Send</button>
  </div>
</div>

<?php if ($mesh_available): ?>
<div class="pane" id="pane-mesh" hidden>
  <div class="badge-bar" style="color:#7ad">
    LoRa broadcast - 200 char max - audible to every node in range. <a href="/mesh/" style="color:#aaa">Full mesh view →</a>
  </div>
  <div id="mesh-list"><div id="mesh-empty">No mesh traffic yet. Listening on channel 0 (Primary, LongFast).</div></div>
  <?php if ($mesh_can_send): ?>
    <form id="mesh-compose" autocomplete="off">
      <?= csrf_field() ?>
      <select id="mch" name="channel">
        <option value="0">ch 0</option><option value="1">ch 1</option>
        <option value="2">ch 2</option><option value="3">ch 3</option>
      </select>
      <input type="text" id="mbody" name="body" maxlength="200" placeholder="Broadcast over LoRa…">
      <span class="count" id="mcount">200</span>
      <button type="submit">Send</button>
    </form>
  <?php else: ?>
    <div id="mesh-readonly">
      Read-only - sending costs RF airtime. Requires the <strong>mesh.send</strong> capability (operator or <em>comms</em> role).
      <a href="/registry/login.php">Sign in</a> or <a href="/admin/">log in as admin</a>.
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div id="veil">
  <div id="vmodal">
    <h2>Link Registry Profile</h2>
    <p style="font-size:12px;color:#888;margin-bottom:12px">Enter your registry name + PIN to show your status badge on messages.</p>
    <div id="verr">Name or PIN not found.</div>
    <input type="text" id="vname" placeholder="Registry name">
    <input type="password" id="vpin" placeholder="PIN">
    <div class="row">
      <button class="ok" onclick="doVerify()">Link</button>
      <button class="cancel" onclick="closeVerify()">Cancel</button>
    </div>
  </div>
</div>

<script>
var lastId = <?= $last_id ?>;
var myName = localStorage.getItem('chat_name') || '';
var verified = false;
var verName = '', verStatus = '', verLoc = '';
var renderedIds = new Set();

document.getElementById('cname').value = myName;

var seedData = <?= json_encode($seed) ?>;
seedData.forEach(appendMsg);
scrollBottom();

function dotClass(s) {
  if (!s) return 'dot-unknown';
  s = s.toLowerCase();
  if (s === 'ok') return 'dot-ok';
  if (s.includes('help')) return 'dot-help';
  if (s.includes('evacuat')) return 'dot-evacuated';
  return 'dot-unknown';
}

function timeStr(ts) {
  var d = new Date(ts * 1000);
  return d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
}

function appendMsg(m) {
  var id = parseInt(m.id) || 0;
  if (id && renderedIds.has(id)) return;
  if (id) { renderedIds.add(id); if (id > lastId) lastId = id; }

  var div = document.createElement('div');
  var mine = (m.name === myName);
  div.className = 'msg' + (mine ? ' mine' : '');

  var meta = esc(m.name);
  if (m.reg_status) {
    meta = '<span class="dot ' + dotClass(m.reg_status) + '"></span>' + esc(m.name);
    if (m.reg_location) meta += ' <span class="loc">· ' + esc(m.reg_location) + '</span>';
  }

  div.innerHTML =
    '<div class="msg-meta">' + meta + ' · ' + timeStr(m.created_at) + '</div>' +
    '<div class="msg-bubble">' + esc(m.body) + '</div>';

  document.getElementById('messages').appendChild(div);
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
}

function scrollBottom() {
  var el = document.getElementById('messages');
  el.scrollTop = el.scrollHeight;
}

function sendMsg() {
  var name = document.getElementById('cname').value.trim();
  var body = document.getElementById('cbody').value.trim();
  if (!name || !body) return;
  localStorage.setItem('chat_name', name);
  myName = name;
  document.getElementById('cbody').value = '';
  var ts = Math.floor(Date.now() / 1000);
  fetch('/chat/?action=send', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'action=send&name=' + encodeURIComponent(name) + '&body=' + encodeURIComponent(body) + '&_csrf=' + encodeURIComponent(CSRF_TOKEN)
  }).then(function(r){ return r.json(); }).then(function(d){
    if (d.ok) {
      // Render immediately, mark ID so poll won't double-render it
      appendMsg({id: d.id, name: name, body: body, reg_status: verStatus||null, reg_location: verLoc||null, created_at: ts});
      scrollBottom();
      poll(); // catch any messages that arrived concurrently
    }
  }).catch(function(){ poll(); }); // always poll even on send error
}

document.getElementById('cbody').addEventListener('keydown', function(e){
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMsg(); }
});

function poll() {
  fetch('/chat/?action=poll&since=' + lastId)
    .then(function(r){
      if (!r.ok) throw new Error('http ' + r.status);
      return r.json();
    })
    .then(function(msgs){
      if (msgs.length) {
        var el = document.getElementById('messages');
        var atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 60;
        msgs.forEach(appendMsg);
        if (atBottom) scrollBottom();
      }
    })
    .catch(function(e){ console.warn('poll failed:', e); });
}
setInterval(poll, 2000);

function openVerify() {
  document.getElementById('verr').style.display = 'none';
  document.getElementById('vname').value = document.getElementById('cname').value;
  document.getElementById('vpin').value = '';
  document.getElementById('veil').classList.add('open');
  document.getElementById('vpin').focus();
}
function closeVerify() { document.getElementById('veil').classList.remove('open'); }

function doVerify() {
  var name = document.getElementById('vname').value.trim();
  var pin  = document.getElementById('vpin').value.trim();
  fetch('/chat/?action=verify', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'action=verify&name=' + encodeURIComponent(name) + '&pin=' + encodeURIComponent(pin) + '&_csrf=' + encodeURIComponent(CSRF_TOKEN)
  }).then(function(r){ return r.json(); }).then(function(d){
    if (d.ok) {
      document.getElementById('cname').value = d.name;
      myName = d.name;
      localStorage.setItem('chat_name', d.name);
      document.getElementById('badge-text').innerHTML = '<span class="verified">✓ Linked to registry as ' + esc(d.name) + '</span>';
      document.getElementById('badge-bar').querySelector('button').style.display = 'none';
      closeVerify();
    } else {
      document.getElementById('verr').style.display = 'block';
    }
  });
}
document.getElementById('vpin').addEventListener('keydown', function(e){ if(e.key==='Enter') doVerify(); });

<?php if ($mesh_available): ?>
// ----- Mesh tab (#101) -----
var meshLastId = 0, meshActive = false, meshPollTimer = null, meshStatusTimer = null;
var meshListEl   = document.getElementById('mesh-list');
var meshStatusEl = document.getElementById('mesh-status');

function showTab(which){
  var local = which !== 'mesh';
  document.getElementById('pane-local').hidden = !local;
  document.getElementById('pane-mesh').hidden  =  local;
  document.getElementById('tab-local').classList.toggle('active',  local);
  document.getElementById('tab-mesh').classList.toggle('active',  !local);
  localStorage.setItem('chat_tab', which);
  meshActive = !local;
  if (meshActive) {
    meshPoll(); meshStatus();
    if (!meshPollTimer)   meshPollTimer   = setInterval(meshPoll, 4000);
    if (!meshStatusTimer) meshStatusTimer = setInterval(meshStatus, 15000);
  } else {
    if (meshPollTimer)   { clearInterval(meshPollTimer); meshPollTimer = null; }
    if (meshStatusTimer) { clearInterval(meshStatusTimer); meshStatusTimer = null; }
  }
}

function meshFmtTime(t){ var d=new Date(t*1000); return d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}); }

function meshAppend(m){
  var emp = document.getElementById('mesh-empty'); if (emp) emp.remove();
  var div = document.createElement('div');
  div.className = 'm ' + (m.direction === 'out' ? 'out' : 'in');
  var who = m.from_short || (m.from_id ? String(m.from_id).slice(-4) : '?');
  var sig = '';
  if (m.rssi || m.snr) sig = ' <span class=sig>['+(m.rssi||'')+'dBm '+(String(m.snr||'')).slice(0,4)+'SNR]</span>';
  div.innerHTML = '<span class=t>'+meshFmtTime(m.received_at)+' ch'+m.channel+'</span> ' +
                  '<span class=who>'+esc(who)+'</span> ' + esc(m.body) + sig;
  meshListEl.appendChild(div);
  if (m.id > meshLastId) meshLastId = m.id;
}

function meshPoll(){
  fetch('/mesh/api.php?action=recv&since='+meshLastId)
    .then(function(r){ return r.json(); })
    .then(function(rows){
      if (!Array.isArray(rows) || !rows.length) return;
      var atBottom = meshListEl.scrollHeight - meshListEl.scrollTop - meshListEl.clientHeight < 60;
      rows.forEach(meshAppend);
      if (atBottom) meshListEl.scrollTop = meshListEl.scrollHeight;
    })
    .catch(function(){});
}

function meshStatus(){
  fetch('/mesh/api.php?action=status').then(function(r){return r.json();}).then(function(s){
    if (!s.ok) { meshStatusEl.textContent = 'mesh offline'; return; }
    var who = s.self ? s.self.short_name : 'node ?';
    meshStatusEl.textContent = who + ' · ' + s.node_count + ' nodes · ' + s.pending + ' queued';
  }).catch(function(){ meshStatusEl.textContent = 'mesh offline'; });
}

var mbody = document.getElementById('mbody');
if (mbody) {
  var mcount = document.getElementById('mcount');
  mbody.addEventListener('input', function(){ mcount.textContent = (200 - mbody.value.length); });
}
var mform = document.getElementById('mesh-compose');
if (mform) {
  mform.addEventListener('submit', function(e){
    e.preventDefault();
    var fd = new FormData(mform); fd.append('action','send');
    var btn = mform.querySelector('button[type=submit]');
    btn.disabled = true; var orig = btn.textContent; btn.textContent = '…';
    fetch('/mesh/api.php', {method:'POST', body:fd, credentials:'same-origin'})
      .then(function(r){ return r.text(); })
      .then(function(txt){
        var j; try { j = JSON.parse(txt); } catch(e){ throw new Error('non-JSON: '+txt.slice(0,140)); }
        if (j.ok) { mbody.value=''; if (mcount) mcount.textContent='200'; setTimeout(meshPoll, 400); }
        else { alert('Mesh send failed: '+(j.error||'unknown')); }
      })
      .catch(function(err){ alert('Mesh send failed: '+err.message); })
      .finally(function(){ btn.disabled = false; btn.textContent = orig; });
  });
}

// Restore last-used tab.
if (localStorage.getItem('chat_tab') === 'mesh') showTab('mesh');
else meshStatus(); // still show status badge on local tab
<?php endif; ?>
</script>
</body>
</html>
