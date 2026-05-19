<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
require_once '/var/www/noosphere/shared/region.php';
sec_session_start();

if (get_setting('show_topo','1') !== '1') { http_response_code(404); exit; }

$pdf_dir   = region_path('topo') . '/';
$url_base  = region_url('topo') . '/';
$files     = glob($pdf_dir . '*.pdf') ?: [];
$quads     = [];
foreach ($files as $f) {
    $name    = basename($f, '.pdf');
    $display = ucwords(str_replace('_', ' ', $name));
    $size    = round(filesize($f) / 1024 / 1024, 1);
    $quads[] = ['name' => $display, 'slug' => $name, 'size' => $size];
}
$region_label = get_setting('region_label', region_meta('label', ''));
usort($quads, fn($a,$b) => strcmp($a['name'], $b['name']));
$count    = count($quads);
$total_mb = array_sum(array_column($quads, 'size'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Topo Maps  -  Noosphere</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: system-ui, sans-serif; background: #0f0f1a; color: #e0e0e0; min-height: 100vh; }
header {
    background: #1a1a2e; border-bottom: 2px solid #e94560;
    padding: 12px 16px; display: flex; align-items: center; gap: 12px;
}
header a { color: #888; text-decoration: none; font-size: 13px; }
header a:hover { color: #e94560; }
header h1 { font-size: 16px; color: #e94560; flex: 1; }
.meta { font-size: 12px; color: #555; white-space: nowrap; }

.container { max-width: 900px; margin: 0 auto; padding: 20px 16px 60px; }

.info {
    background: #16213e; border: 1px solid #2a2a4a; border-radius: 8px;
    padding: 12px 16px; margin-bottom: 20px; font-size: 13px; color: #888; line-height: 1.6;
}
.info b { color: #b0b0cc; }

.search-bar {
    width: 100%; background: #16213e; border: 1px solid #2a2a4a;
    color: #e0e0e0; border-radius: 6px; padding: 9px 12px;
    font-size: 14px; font-family: inherit; margin-bottom: 16px;
}
.search-bar:focus { outline: none; border-color: #e94560; }

.grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 10px;
}

.quad-card {
    background: #1a1a2e; border: 1px solid #2a2a4a; border-radius: 8px;
    padding: 14px 16px; text-decoration: none; color: inherit;
    display: flex; align-items: center; gap: 12px;
    transition: border-color .15s;
}
.quad-card:hover { border-color: #e94560; }
.quad-icon { font-size: 22px; flex-shrink: 0; }
.quad-info { flex: 1; min-width: 0; }
.quad-name { font-size: 14px; font-weight: 600; color: #e0e0e0; }
.quad-meta { font-size: 11px; color: #555; margin-top: 2px; }
.quad-badge {
    font-size: 10px; background: #16213e; border: 1px solid #2a2a4a;
    color: #666; padding: 2px 7px; border-radius: 10px; white-space: nowrap;
}

.no-results { text-align: center; color: #555; padding: 40px 0; font-size: 14px; }
</style>
</head>
<body>
<header>
  <a href="/">← Home</a>
  <h1>Topo Maps</h1>
  <span class="meta"><?= $count ?> quads &nbsp;·&nbsp; <?= number_format($total_mb, 1) ?> MB</span>
</header>

<div class="container">
  <div class="info">
    <b>USGS 1:24,000 Topographic Quadrangles</b><?= $region_label ? '  -  ' . htmlspecialchars($region_label) . '.' : '.' ?>
    Opens as a PDF you can print or zoom. Each sheet covers a 7.5-minute area (~9 × 7 miles) at full survey detail.
  </div>

  <input class="search-bar" type="search" id="q" placeholder="Search quads…" oninput="filter()" autocomplete="off">

  <div class="grid" id="grid">
    <?php foreach ($quads as $quad): ?>
    <a class="quad-card" href="<?= htmlspecialchars($url_base . $quad['slug']) ?>.pdf" target="_blank" data-name="<?= strtolower($quad['name']) ?>">
      <span class="quad-icon">🗾</span>
      <div class="quad-info">
        <div class="quad-name"><?= htmlspecialchars($quad['name']) ?></div>
        <div class="quad-meta">USGS 7.5-minute quad</div>
      </div>
      <span class="quad-badge"><?= $quad['size'] ?> MB</span>
    </a>
    <?php endforeach; ?>
  </div>
  <div class="no-results" id="no-results" style="display:none">No quads match your search.</div>
</div>

<script>
function filter() {
    var q = document.getElementById('q').value.toLowerCase();
    var cards = document.querySelectorAll('.quad-card');
    var shown = 0;
    cards.forEach(function(c) {
        var match = !q || c.dataset.name.indexOf(q) !== -1;
        c.style.display = match ? '' : 'none';
        if (match) shown++;
    });
    document.getElementById('no-results').style.display = shown ? 'none' : 'block';
}
</script>
</body>
</html>
