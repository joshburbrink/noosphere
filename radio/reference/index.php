<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
require_once '/var/www/noosphere/shared/region.php';
sec_session_start();
if (get_setting('show_radio','0') !== '1') { http_response_code(404); exit; }

$data_dir   = region_path('radio');
$reg_counties = region_meta('radio.counties', []);
$counties = [];
foreach ($reg_counties as $rc) {
    $slug = $rc['slug'] ?? '';
    if (!$slug) continue;
    $path = "$data_dir/$slug.json";
    if (file_exists($path)) {
        $c = json_decode(file_get_contents($path), true);
        if ($c) $counties[$slug] = $c;
    }
}

$name = get_setting('instance_name', 'Noosphere');
$region_label = get_setting('region_label', region_meta('label', ''));

// CHIRP CSV export
if (isset($_GET['chirp'])) {
    $county_slug = $_GET['chirp'];
    $c = $counties[$county_slug] ?? null;
    if (!$c) { http_response_code(404); exit; }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $c['county'] . '_County_IN.csv"');
    echo "Location,Name,Frequency,Duplex,Offset,Tone,rToneFreq,cToneFreq,DtcsCode,DtcsRxCode,DtcsPolarity,Mode,TStep,Skip,Comment,URCALL,RPT1CALL,RPT2CALL,DVCODE\r\n";
    $loc = 0;
    foreach ($c['agencies'] as $a) {
        foreach ($a['frequencies'] as $f) {
            $freq = number_format((float)$f['freq'], 4, '.', '');
            $tone_mode = $f['tone'] ? 'Tone' : '';
            $tone_val  = $f['tone'] ?? '88.5';
            $name_short = substr(strtoupper(preg_replace('/[^A-Z0-9]/', '', $a['short'] . '_' . $f['tag'])), 0, 7);
            echo implode(',', [
                $loc++,                     // Location
                $name_short,               // Name (7 char max)
                $freq,                     // Frequency
                '',                        // Duplex
                '0.000000',               // Offset
                $tone_mode,               // Tone
                $tone_val,                // rToneFreq
                '88.5',                   // cToneFreq
                '023',                    // DtcsCode
                '023',                    // DtcsRxCode
                'NN',                     // DtcsPolarity
                $f['mode'],               // Mode
                '5.00',                   // TStep
                '',                       // Skip
                addslashes($a['name'] . '  -  ' . $f['tag']),  // Comment
                '', '', '', ''            // URCALL/RPT
            ]) . "\r\n";
        }
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Radio Reference  -  <?= htmlspecialchars($name) ?></title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:sans-serif; background:#1a1a2e; color:#eee; min-height:100vh; padding:1.5rem; }
.topbar { display:flex; align-items:center; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap; }
.topbar h1 { font-size:1.4rem; color:#e94560; flex:1; }
a.back { color:#aaa; text-decoration:none; font-size:13px; }
a.back:hover { color:#e94560; }
.card { background:#16213e; border:1px solid #2a2a4a; border-radius:10px; padding:1.25rem; margin-bottom:1.25rem; }
.card h2 { font-size:1rem; color:#e94560; margin-bottom:.75rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; }
.card h2 a { font-size:11px; color:#7ad; text-decoration:none; background:#111126; border:1px solid #2a2a4a; border-radius:4px; padding:3px 10px; }
.card h2 a:hover { border-color:#e94560; color:#e94560; }
.note { font-size:11px; color:#555; margin-bottom:1rem; }
.filter-bar { display:flex; gap:10px; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; }
.filter-bar input { flex:1; min-width:200px; background:#0f0f1a; border:1px solid #2a2a4a; border-radius:5px; color:#eee; padding:7px 10px; font-size:13px; }
.agency-section { margin-bottom:1.25rem; }
.agency-header { font-size:13px; font-weight:bold; color:#7ad; padding:5px 0 5px 2px; border-bottom:1px solid #2a2a4a; margin-bottom:6px; display:flex; justify-content:space-between; align-items:center; }
.agency-type { font-size:10px; font-weight:normal; color:#555; background:#0f0f1a; border:1px solid #2a2a4a; border-radius:3px; padding:1px 6px; }
table { width:100%; border-collapse:collapse; font-size:12px; }
th { text-align:left; color:#666; padding:4px 8px; border-bottom:1px solid #2a2a4a; white-space:nowrap; }
td { padding:6px 8px; border-bottom:1px solid #161628; vertical-align:top; }
.freq { font-family:monospace; font-size:14px; font-weight:bold; color:#2ecc71; }
.tone { font-family:monospace; font-size:11px; color:#aaa; }
.tag  { font-size:10px; background:#0f0f1a; border:1px solid #2a2a4a; border-radius:3px; padding:1px 6px; color:#aaa; white-space:nowrap; }
.hidden { display:none !important; }
.county-tabs { display:flex; gap:8px; margin-bottom:1.25rem; flex-wrap:wrap; }
.county-tab { background:#16213e; border:1px solid #2a2a4a; border-radius:6px; padding:6px 18px; font-size:13px; cursor:pointer; color:#aaa; }
.county-tab.active { background:#e94560; border-color:#e94560; color:#fff; }
.county-panel { display:none; }
.county-panel.active { display:block; }
</style>
</head>
<body>
<div class="topbar">
  <h1>📡 Radio Reference<?= $region_label ? '  -  ' . htmlspecialchars($region_label) : '' ?></h1>
  <a class="back" href="/radio/">← Radio Log</a>
  <a href="/radio/program/" style="font-size:13px;color:#2ecc71;text-decoration:none;border:1px solid #2a2a4a;border-radius:4px;padding:4px 10px;">⚡ Program Radio</a>
</div>

<div class="card">
<?php if (!$counties): ?>
  <div style="padding:1.5rem;text-align:center;color:#aaa">
    <p style="margin-bottom:.5rem"><strong>No frequency data for the active region.</strong></p>
    <p style="font-size:13px;color:#777">Install a region pack from <a href="/admin/" style="color:#7ad">Admin -> Region</a> to load county frequencies.</p>
  </div>
</div>
<?php else: ?>
<div class="card">
  <div class="note">⚠ Approximate data  -  verify frequencies at <strong>radioreference.com</strong> before programming radios. Last updated: <?= htmlspecialchars(array_values($counties)[0]['last_updated'] ?? ' - ') ?></div>

  <div class="filter-bar">
    <input type="text" id="search" placeholder="Filter by agency, frequency, or tag…" oninput="filterRef()">
  </div>

  <div class="county-tabs">
    <?php $i=0; foreach ($counties as $slug => $c): ?>
    <button class="county-tab<?= $i===0?' active':'' ?>" onclick="switchCounty('<?= htmlspecialchars($slug) ?>')" id="tab_<?= htmlspecialchars($slug) ?>">
      <?= htmlspecialchars($c['county']) ?> County
    </button>
    <?php $i++; endforeach; ?>
  </div>

  <?php $i=0; foreach ($counties as $slug => $c): ?>
  <div class="county-panel<?= $i===0?' active':'' ?>" id="panel_<?= htmlspecialchars($slug) ?>">
    <div class="card" style="background:#111126;border-color:#1a1a3a;padding:10px 14px;margin-bottom:1rem">
      <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
        <span style="font-size:13px;color:#7ad"><?= htmlspecialchars($c['county']) ?> County, <?= htmlspecialchars($c['state']) ?></span>
        <span style="font-size:11px;color:#555">FIPS <?= htmlspecialchars($c['fips']) ?></span>
        <a href="?chirp=<?= urlencode($slug) ?>" style="margin-left:auto;font-size:11px;color:#7ad;text-decoration:none;background:#0f0f1a;border:1px solid #2a2a4a;border-radius:4px;padding:3px 10px">⬇ CHIRP CSV</a>
      </div>
    </div>

    <?php foreach ($c['agencies'] as $ai => $agency): ?>
    <div class="agency-section" data-county="<?= htmlspecialchars($slug) ?>">
      <div class="agency-header">
        <span><?= htmlspecialchars($agency['name']) ?></span>
        <span class="agency-type"><?= htmlspecialchars($agency['type']) ?></span>
      </div>
      <div style="overflow-x:auto">
      <table>
        <thead>
          <tr>
            <th>Frequency</th>
            <th>Tag</th>
            <th>Tone</th>
            <th>Mode</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($agency['frequencies'] as $f): ?>
          <tr>
            <td><span class="freq"><?= htmlspecialchars($f['freq']) ?> MHz</span></td>
            <td><span class="tag"><?= htmlspecialchars($f['tag']) ?></span></td>
            <td><span class="tone"><?= $f['tone'] ? htmlspecialchars($f['tone']) . ' Hz' : ' - ' ?></span></td>
            <td style="color:#aaa;font-size:11px"><?= htmlspecialchars($f['mode']) ?></td>
            <td style="color:#888;font-size:11px"><?= htmlspecialchars($f['notes'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php $i++; endforeach; ?>
</div>
<?php endif; ?>

<script>
var activeCounty = '<?= htmlspecialchars(array_key_first($counties) ?? '') ?>';

function switchCounty(slug) {
  document.querySelectorAll('.county-panel').forEach(function(el){ el.classList.remove('active'); });
  document.querySelectorAll('.county-tab').forEach(function(el){ el.classList.remove('active'); });
  var panel = document.getElementById('panel_' + slug);
  var tab   = document.getElementById('tab_' + slug);
  if (panel) panel.classList.add('active');
  if (tab)   tab.classList.add('active');
  activeCounty = slug;
  filterRef();
}

function filterRef() {
  var q = document.getElementById('search').value.toLowerCase().trim();
  var panel = document.getElementById('panel_' + activeCounty);
  if (!panel) return;
  panel.querySelectorAll('.agency-section').forEach(function(sec) {
    if (!q) { sec.classList.remove('hidden'); sec.querySelectorAll('tbody tr').forEach(function(r){ r.classList.remove('hidden'); }); return; }
    var agencyText = sec.querySelector('.agency-header').textContent.toLowerCase();
    var anyRow = false;
    sec.querySelectorAll('tbody tr').forEach(function(row) {
      var match = row.textContent.toLowerCase().includes(q) || agencyText.includes(q);
      row.classList.toggle('hidden', !match);
      if (match) anyRow = true;
    });
    sec.classList.toggle('hidden', !anyRow && !agencyText.includes(q));
  });
}
</script>
</body>
</html>
