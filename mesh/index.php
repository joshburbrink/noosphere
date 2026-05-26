<?php
require_once __DIR__ . '/_init.php';
$ready = mesh_db_ready();
$can_send = can('mesh.send');
?><!DOCTYPE html>
<html><head><meta charset=utf-8><title>Mesh - Noosphere</title>
<?php include '/var/www/noosphere/shared/head.php'; ?>
<style>
  .mesh-wrap { max-width: 760px; margin: 0 auto; padding: 12px; }
  .mesh-tabs { display:flex; gap:6px; margin-bottom:10px; }
  .mesh-tabs a { padding:6px 12px; border:1px solid #2a2a4a; border-radius:6px; color:#7ad; text-decoration:none; font-size:13px; }
  .mesh-tabs a.active { background:#1a1a2e; color:#fff; }
  .msg-list { background:#0d0d1a; border:1px solid #2a2a4a; border-radius:8px; padding:10px; height:55vh; overflow-y:auto; font-family:monospace; font-size:13px; }
  .msg { margin-bottom:8px; line-height:1.4; }
  .msg .meta { color:#666; font-size:11px; }
  .msg .from { color:#7ad; font-weight:bold; }
  .msg.out .from { color:#2ecc71; }
  .msg .body { color:#eee; white-space:pre-wrap; word-break:break-word; }
  .msg .sig { color:#555; font-size:10px; margin-left:8px; }
  .send-row { display:flex; gap:6px; margin-top:10px; }
  .send-row input[type=text] { flex:1; padding:8px; background:#0d0d1a; border:1px solid #2a2a4a; color:#eee; border-radius:6px; font-size:14px; }
  .send-row select { padding:8px; background:#0d0d1a; border:1px solid #2a2a4a; color:#eee; border-radius:6px; }
  .send-row button { padding:8px 16px; background:#1a4d7a; color:#fff; border:0; border-radius:6px; cursor:pointer; }
  .send-row button:disabled { opacity:0.5; cursor:not-allowed; }
  .status-bar { font-size:11px; color:#666; margin-bottom:8px; }
</style>
</head><body>
<?php include '/var/www/noosphere/shared/topnav.php'; ?>
<div class="mesh-wrap">
  <h2 style="margin:8px 0">📡 Mesh</h2>
  <div class="mesh-tabs">
    <a href="/mesh/" class="active">Chat</a>
    <a href="/mesh/nodes.php">Nodes</a>
    <a href="/mesh/log.php">Raw Log</a>
  </div>
  <div class="status-bar" id="mesh-status">Loading…</div>

  <?php if (!$ready): ?>
    <div style="background:#2a1a1a;border:1px solid #4a2a2a;color:#eaa;padding:10px;border-radius:6px;font-size:13px">
      Mesh daemon hasn't started yet. Check <code>systemctl status noosphere-meshtastic</code>.
    </div>
  <?php else: ?>
    <div class="msg-list" id="msg-list"></div>
    <?php if ($can_send): ?>
    <form class="send-row" id="send-form" autocomplete="off">
      <?= csrf_field() ?>
      <select name="channel">
        <option value="0">ch 0 (Primary)</option>
        <option value="1">ch 1</option>
        <option value="2">ch 2</option>
        <option value="3">ch 3</option>
      </select>
      <input type="text" name="body" id="msg-body" maxlength="200" placeholder="Message (max 200 chars)" required>
      <button type="submit">Send</button>
    </form>
    <?php else: ?>
    <div style="font-size:11px;color:#666;margin-top:8px">Read-only - mesh.send capability required to broadcast.</div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<script>
(function(){
  var lastId = 0;
  var listEl = document.getElementById('msg-list');
  var statusEl = document.getElementById('mesh-status');
  function fmtTime(t){ var d=new Date(t*1000); return d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}); }
  function esc(s){ return (s||'').replace(/[&<>]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;'}[c]; }); }
  function render(rows){
    rows.forEach(function(m){
      var div = document.createElement('div');
      div.className = 'msg ' + (m.direction==='out' ? 'out' : 'in');
      var who = m.from_short || (m.from_id ? m.from_id.slice(-4) : '?');
      var sig = '';
      if (m.rssi || m.snr) sig = ' <span class=sig>['+(m.rssi||'')+'dBm '+(m.snr||'').toString().slice(0,4)+'SNR]</span>';
      div.innerHTML =
        '<span class=meta>'+fmtTime(m.received_at)+' ch'+m.channel+'</span> ' +
        '<span class=from>'+esc(who)+'</span> ' +
        '<span class=body>'+esc(m.body)+'</span>' + sig;
      listEl.appendChild(div);
      lastId = Math.max(lastId, m.id);
    });
    if (rows.length) listEl.scrollTop = listEl.scrollHeight;
  }
  function poll(){
    fetch('/mesh/api.php?action=recv&since='+lastId)
      .then(r=>r.json()).then(function(rows){ if (Array.isArray(rows)) render(rows); })
      .catch(function(){});
  }
  function status(){
    fetch('/mesh/api.php?action=status').then(r=>r.json()).then(function(s){
      if (!s.ok) return;
      var who = s.self ? (s.self.long_name+' ['+s.self.short_name+']') : 'node ?';
      statusEl.textContent = who+' · '+s.node_count+' nodes seen · '+s.msg_count+' msgs · '+s.pending+' queued';
    });
  }
  if (document.getElementById('msg-list')) {
    poll(); status();
    setInterval(poll, 4000);
    setInterval(status, 15000);
  }
  var f = document.getElementById('send-form');
  if (f) {
    f.addEventListener('submit', function(e){
      e.preventDefault();
      var fd = new FormData(f); fd.append('action','send');
      fetch('/mesh/api.php', {method:'POST', body:fd}).then(r=>r.json()).then(function(j){
        if (j.ok) { document.getElementById('msg-body').value=''; setTimeout(poll, 500); }
        else alert('Send failed: '+(j.error||'?'));
      });
    });
  }
})();
</script>
</body></html>
