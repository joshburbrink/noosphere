<?php
// NWR section — always included from /weather/index.php.
// Alert history is always visible. Live stream only shown when radio_mode=nwr.

$nwr_db_path = '/var/lib/noosphere/weather/alerts.db';
$nwr_status = ['frequency' => '', 'started_at' => '', 'heartbeat' => 0, 'last_alert' => '', 'last_alert_ts' => 0];
$nwr_alerts = [];
$nwr_active = null;
$nwr_stream_mode = (get_setting('radio_mode','off') === 'nwr');
$nwr_svc_active = $nwr_stream_mode && (trim(shell_exec('systemctl is-active noaa-weather.service 2>/dev/null') ?? '') === 'active');

if (file_exists($nwr_db_path)) {
    try {
        $ndb = new SQLite3($nwr_db_path, SQLITE3_OPEN_READONLY);
        $r = $ndb->query("SELECT key,value FROM status");
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $nwr_status[$row['key']] = $row['value'];
        }
        $r = $ndb->query("SELECT id,ts,event_code,event_name,station,fips,duration,matched_local FROM alerts ORDER BY ts DESC LIMIT 30");
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) $nwr_alerts[] = $row;
        // Active = matched-local alert whose (ts + duration) > now. Duration is HHMM (e.g. 0030 = 30 min)
        foreach ($nwr_alerts as $a) {
            if (!$a['matched_local']) continue;
            $d = $a['duration'] ?? '0000';
            $secs = ((int)substr($d, 0, 2)) * 3600 + ((int)substr($d, 2, 2)) * 60;
            if (($a['ts'] + $secs) > time()) { $nwr_active = $a; break; }
        }
        $ndb->close();
    } catch (Exception $e) { /* swallow */ }
}

// Heartbeat from playlist mtime — only relevant when streaming
$m3u8 = '/var/www/noosphere/weather/stream/live.m3u8';
$stream_age = file_exists($m3u8) ? (time() - filemtime($m3u8)) : 9999;
$stream_alive = $nwr_stream_mode && ($stream_age < 10);
?>

<?php if ($nwr_active): ?>
<div style="background:#3a0a0a;border:2px solid #e94560;padding:14px;border-radius:8px;margin-bottom:12px">
  <div style="font-weight:bold;color:#e94560;font-size:16px;margin-bottom:4px">⚠ ACTIVE ALERT — <?= htmlspecialchars($nwr_active['event_name']) ?></div>
  <div style="font-size:13px;color:#ccc">Issued <?= date('M j g:i a', $nwr_active['ts']) ?> by <?= htmlspecialchars($nwr_active['station']) ?> · counties: <?= htmlspecialchars($nwr_active['fips']) ?> · duration <?= htmlspecialchars($nwr_active['duration']) ?></div>
</div>
<?php endif; ?>

<?php if ($nwr_stream_mode): ?>
<div class="card" style="border:1px solid #e94560;background:#1a1a2e;margin-bottom:18px;padding:16px">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
    <span style="font-size:20px">📡</span>
    <strong style="font-size:16px">Live NOAA Weather Radio</strong>
    <span style="font-family:monospace;color:#7ad;font-size:13px"><?= htmlspecialchars($nwr_status['frequency'] ?? '') ?></span>
    <span style="margin-left:auto;display:flex;align-items:center;gap:6px;font-size:12px;color:<?= $stream_alive ? '#2ecc71' : '#e94560' ?>">
      <span style="width:8px;height:8px;border-radius:50%;background:<?= $stream_alive ? '#2ecc71' : '#e94560' ?>;<?= $stream_alive ? 'animation:nwrpulse 1.5s infinite' : '' ?>"></span>
      <?= $stream_alive ? 'Receiving (' . $stream_age . 's ago)' : ($nwr_svc_active ? 'Tuning…' : 'Service stopped') ?>
    </span>
  </div>
  <div style="margin-bottom:10px">
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
      <audio id="nwr-audio" controls preload="none" style="flex:1;min-width:260px;max-width:480px"></audio>
      <div style="font-size:11px;color:#666">Live audio (HLS, ~5s latency). Click play to start.</div>
    </div>
    <div style="background:#000;border:1px solid #2a2a4a;border-radius:6px;padding:6px;position:relative">
      <canvas id="nwr-spectrum" style="display:block;width:100%;height:80px" height="80"></canvas>
      <div id="nwr-spectrum-label" style="position:absolute;top:4px;left:8px;font-size:10px;color:#555;pointer-events:none">Signal · play audio to activate</div>
    </div>
  </div>
</div>
<?php else: ?>
<div style="background:#1a1a2e;border:1px solid #2a2a4a;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:12px;color:#555">
  📡 Live NWR stream unavailable in this mode — enable NWR in Admin → SDR Radio to stream.
</div>
<?php endif; ?>

<div class="card" style="background:#16213e;border:1px solid #2a2a4a;border-radius:10px;padding:1.25rem;margin-bottom:1.25rem">
  <h2 style="font-size:1rem;color:#e94560;margin-bottom:1rem">SAME Alert History (<?= count($nwr_alerts) ?> recent)</h2>
  <?php if (!$nwr_alerts): ?>
    <div style="color:#666;font-size:12px;padding:6px 0">No alerts decoded yet. Routine weekly tests (RWT) fire most Wednesdays around noon — if you don't see one in a week, check antenna placement.</div>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table style="width:100%;border-collapse:collapse;font-size:12px">
    <thead><tr style="color:#666">
      <th style="text-align:left;padding:5px 8px;border-bottom:1px solid #2a2a4a">When</th>
      <th style="text-align:left;padding:5px 8px;border-bottom:1px solid #2a2a4a">Event</th>
      <th style="text-align:left;padding:5px 8px;border-bottom:1px solid #2a2a4a">Station</th>
      <th style="text-align:left;padding:5px 8px;border-bottom:1px solid #2a2a4a">Counties</th>
      <th style="padding:5px 8px;border-bottom:1px solid #2a2a4a">Local?</th>
    </tr></thead>
    <tbody>
    <?php foreach ($nwr_alerts as $a): ?>
      <tr style="<?= $a['matched_local'] ? '' : 'opacity:0.55' ?>">
        <td style="padding:5px 8px;border-bottom:1px solid #161628;color:#aaa;white-space:nowrap"><?= date('M j H:i', $a['ts']) ?></td>
        <td style="padding:5px 8px;border-bottom:1px solid #161628"><?= htmlspecialchars($a['event_name']) ?> <span style="color:#666;font-size:10px">(<?= htmlspecialchars($a['event_code']) ?>)</span></td>
        <td style="padding:5px 8px;border-bottom:1px solid #161628;font-family:monospace;color:#7ad"><?= htmlspecialchars($a['station']) ?></td>
        <td style="padding:5px 8px;border-bottom:1px solid #161628;color:#888;font-size:11px"><?= htmlspecialchars($a['fips']) ?></td>
        <td style="padding:5px 8px;border-bottom:1px solid #161628;text-align:center"><?= $a['matched_local'] ? '<span style="color:#e94560">●</span>' : '<span style="color:#444">·</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<style>
@keyframes nwrpulse { 0%,100% { opacity:1 } 50% { opacity:0.35 } }
</style>
<?php if ($nwr_stream_mode): ?>
<script src="/shared/js/hls.light.min.js"></script>
<script>
(function(){
  var audio = document.getElementById('nwr-audio');
  var src = '/weather/stream/live.m3u8';
  if (window.Hls && Hls.isSupported()) {
    var hls = new Hls({ liveSyncDuration: 4, liveMaxLatencyDuration: 8, maxBufferLength: 10 });
    hls.loadSource(src);
    hls.attachMedia(audio);
  } else if (audio.canPlayType('application/vnd.apple.mpegurl')) {
    audio.src = src;
  }

  var specCtx = null, analyser = null;
  var canvas = document.getElementById('nwr-spectrum');
  var label  = document.getElementById('nwr-spectrum-label');

  function initSpectrum() {
    if (analyser) return;
    try {
      var ctx = new (window.AudioContext || window.webkitAudioContext)();
      var src2 = ctx.createMediaElementSource(audio);
      analyser = ctx.createAnalyser();
      analyser.fftSize = 256;
      analyser.smoothingTimeConstant = 0.8;
      src2.connect(analyser);
      analyser.connect(ctx.destination);
      specCtx = canvas.getContext('2d');
      label.textContent = 'Signal';
      drawSpectrum();
    } catch(e) { label.textContent = 'Visualizer unavailable'; }
  }

  function drawSpectrum() {
    requestAnimationFrame(drawSpectrum);
    var W = canvas.clientWidth, H = canvas.clientHeight;
    if (canvas.width !== W) canvas.width = W;
    var data = new Uint8Array(analyser.frequencyBinCount);
    analyser.getByteFrequencyData(data);
    specCtx.fillStyle = '#000';
    specCtx.fillRect(0, 0, W, H);
    var bw = W / data.length;
    for (var i = 0; i < data.length; i++) {
      var v = data[i] / 255;
      var bh = v * H;
      var r = Math.round(v < 0.5 ? v * 2 * 68 : 68 + (v - 0.5) * 2 * 185);
      var g = Math.round(v * 220);
      var b = Math.round(v < 0.5 ? 84 + v * 2 * 56 : 140 - (v - 0.5) * 2 * 140);
      specCtx.fillStyle = 'rgb(' + r + ',' + g + ',' + b + ')';
      specCtx.fillRect(i * bw, H - bh, Math.max(1, bw - 1), bh);
    }
  }

  audio.addEventListener('play', initSpectrum, { once: true });
  setTimeout(function(){ location.reload(); }, 30000);
})();
</script>
<?php endif; ?>
