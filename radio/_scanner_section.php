<?php
// Scanner section — always included from /radio/index.php.
// Waterfall only rendered when radio_mode=scanner; otherwise shows a quiet status note.

$scanner_waterfall_mode = (get_setting('radio_mode','off') === 'scanner');
$scanner_png  = '/var/www/noosphere/radio/scanner/waterfall.png';
$scanner_meta_path = '/var/www/noosphere/radio/scanner/waterfall.json';
$scanner_svc_active = $scanner_waterfall_mode && (trim(shell_exec('systemctl is-active scanner-waterfall.service 2>/dev/null') ?? '') === 'active');
$scanner_meta = [];
if ($scanner_waterfall_mode && file_exists($scanner_meta_path)) {
    $raw = file_get_contents($scanner_meta_path);
    if ($raw) $scanner_meta = json_decode($raw, true) ?: [];
}
$scanner_png_age = file_exists($scanner_png) ? (time() - filemtime($scanner_png)) : 9999;
$scanner_alive = $scanner_waterfall_mode && ($scanner_png_age < 5);

function _fmt_hz($hz) {
    if ($hz >= 1e9) return number_format($hz / 1e9, 3) . ' GHz';
    if ($hz >= 1e6) return number_format($hz / 1e6, 3) . ' MHz';
    if ($hz >= 1e3) return number_format($hz / 1e3, 1) . ' kHz';
    return (int)$hz . ' Hz';
}
?>
<?php if ($scanner_waterfall_mode): ?>
<div class="card" style="border:1px solid #e94560;background:#1a1a2e;margin-bottom:18px;padding:16px">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
    <span style="font-size:20px">📡</span>
    <strong style="font-size:16px">Spectrum Scanner</strong>
    <?php if ($scanner_meta): ?>
      <span style="font-family:monospace;color:#7ad;font-size:13px"><?= htmlspecialchars(_fmt_hz($scanner_meta['f_low'] ?? 0)) ?> – <?= htmlspecialchars(_fmt_hz($scanner_meta['f_high'] ?? 0)) ?></span>
      <span style="color:#666;font-size:12px">· bin <?= htmlspecialchars(_fmt_hz($scanner_meta['f_step'] ?? 0)) ?></span>
    <?php endif; ?>
    <span style="margin-left:auto;display:flex;align-items:center;gap:6px;font-size:12px;color:<?= $scanner_alive ? '#2ecc71' : '#e94560' ?>">
      <span style="width:8px;height:8px;border-radius:50%;background:<?= $scanner_alive ? '#2ecc71' : '#e94560' ?>;<?= $scanner_alive ? 'animation:scnpulse 1.5s infinite' : '' ?>"></span>
      <?= $scanner_alive ? 'Scanning (' . $scanner_png_age . 's ago)' : ($scanner_svc_active ? 'Warming up…' : 'Service stopped') ?>
    </span>
  </div>

  <div style="background:#000;border:1px solid #2a2a4a;border-radius:6px;padding:6px;overflow:auto;max-height:320px">
    <?php if (file_exists($scanner_png)): ?>
      <img id="waterfall-img" src="/radio/scanner/waterfall.png?<?= time() ?>" alt="Spectrum waterfall" style="display:block;width:100%;height:auto;image-rendering:pixelated;min-width:200px">
    <?php else: ?>
      <div style="color:#666;text-align:center;padding:40px;font-size:13px">Scanner starting — first sweep in a few seconds.</div>
    <?php endif; ?>
  </div>

  <?php if ($scanner_meta && isset($scanner_meta['db_min'])): ?>
  <div style="margin-top:6px;display:flex;gap:8px;align-items:center;font-size:11px;color:#888;flex-wrap:wrap">
    <span>Power scale:</span>
    <span style="color:#544"><?= $scanner_meta['db_min'] ?> dB</span>
    <span style="flex:1;height:8px;background:linear-gradient(to right,#440154,#3a528b,#21918c,#5ec962,#fde725);border-radius:2px;min-width:60px;max-width:300px"></span>
    <span style="color:#fde725"><?= $scanner_meta['db_max'] ?> dB</span>
    <span style="color:#555;font-size:10px;width:100%">Time flows top→bottom · low freq ←→ high freq</span>
  </div>
  <?php endif; ?>

  <div id="scn-signal-bar" style="display:flex;align-items:center;gap:8px;margin-top:8px;font-size:11px;color:#555">
    <span>Peak signal</span>
    <div style="flex:1;height:6px;background:#111;border-radius:3px;overflow:hidden">
      <div id="scn-signal-fill" style="height:100%;width:0%;background:#555;border-radius:3px;transition:width 0.4s,background 0.4s"></div>
    </div>
    <span id="scn-signal-db" style="font-family:monospace;min-width:52px;text-align:right">— dB</span>
  </div>

  <div style="margin-top:10px;font-size:11px;color:#666">
    Band + gain configured in <code style="color:#888">/etc/noosphere/scanner.conf</code>. Admin band-picker coming soon.
  </div>
</div>

<style>
@keyframes scnpulse { 0%,100% { opacity:1 } 50% { opacity:0.35 } }
</style>
<script>
(function(){
  var img = document.getElementById('waterfall-img');
  if (img) {
    setInterval(function(){
      img.src = '/radio/scanner/waterfall.png?' + Date.now();
    }, 1000);
  }
  setTimeout(function(){ location.reload(); }, 60000);

  function updateScnSignal() {
    fetch('/radio/scanner/signal.php').then(function(r){ return r.json(); }).then(function(d){
      var fill = document.getElementById('scn-signal-fill');
      var lbl  = document.getElementById('scn-signal-db');
      var bar  = document.getElementById('scn-signal-bar');
      if (!fill) return;
      if (!d.ok || d.peak_db === null) {
        fill.style.width = '0%'; fill.style.background = '#555';
        lbl.textContent = '— dB'; return;
      }
      var pct   = Math.max(0, Math.min(100, (d.peak_db + 60) / 60 * 100));
      var color = d.peak_db > -30 ? '#2ecc71' : d.peak_db > -50 ? '#f39c12' : '#e94560';
      fill.style.width = pct + '%';
      fill.style.background = color;
      lbl.style.color = color;
      lbl.textContent = d.peak_db.toFixed(1) + ' dB';
      bar.style.color = color;
    }).catch(function(){});
  }
  updateScnSignal();
  setInterval(updateScnSignal, 5000);
})();
</script>
<?php else: ?>
<div style="background:#1a1a2e;border:1px solid #2a2a4a;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:12px;color:#555">
  📡 Spectrum waterfall unavailable in this mode — enable Scanner in Admin → SDR Radio to view live RF.
</div>
<?php endif; ?>
