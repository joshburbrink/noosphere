<?php
require_once '/var/www/noosphere/shared/settings.php';

$name    = get_setting('instance_name',    'Noosphere');
$tagline = get_setting('instance_tagline', 'Offline information hub — no internet required');
$alert   = get_setting('homepage_alert',   '');

$tiles = [];
if (get_setting('show_registry','1')==='1') {
    $label = get_setting('registry_label', 'Registry');
    if (!$label) $label = 'Registry';

    $desc = get_registry_description();

    // Shelter capacity badge
    $cap_badge = '';
    if (get_setting('registry_shelter','0')==='1' && (int)get_setting('shelter_capacity','0') > 0) {
        try {
            $rdb = new PDO('sqlite:/var/lib/noosphere/registry.db');
            $occupied = $rdb->query("SELECT COUNT(*) FROM registry WHERE entry_type='checkin' OR entry_type IS NULL OR entry_type=''")->fetchColumn();
            $capacity = (int)get_setting('shelter_capacity','0');
            $pct  = $capacity ? min(100, round($occupied/$capacity*100)) : 0;
            $col  = $pct >= 90 ? '#e94560' : ($pct >= 70 ? '#f39c12' : '#2ecc71');
            $cap_badge = "<div style='font-size:12px;margin-top:6px;color:$col'>$occupied / $capacity occupied</div>";
        } catch(Exception $e) {}
    }
    $tiles[] = ['href'=>'/registry/', 'icon'=>'🧑‍🤝‍🧑', 'label'=>$label, 'desc'=>$desc.$cap_badge];
}
if (get_setting('show_chat','1')==='1')
    $tiles[] = ['href'=>'/chat/',     'icon'=>'💬', 'label'=>'Chat',            'desc'=>'Real-time group messaging'];
if (get_setting('show_forum','1')==='1')
    $tiles[] = ['href'=>'/forum/',    'icon'=>'📋', 'label'=>'Community Board', 'desc'=>'Announcements, coordination &amp; discussion'];
if (get_setting('show_files','1')==='1')
    $tiles[] = ['href'=>'/files/',    'icon'=>'📁', 'label'=>'Files',           'desc'=>'Share documents, notices &amp; resources'];
if (get_setting('show_library','1')==='1')
    $tiles[] = ['href'=>'/kiwix/',    'icon'=>'📚', 'label'=>'Library',         'desc'=>'Offline Wikipedia, WikiMed, guides &amp; more'];
if (get_setting('show_maps','1')==='1')
    $tiles[] = ['href'=>'/maps/',     'icon'=>'🗺️',  'label'=>'Maps',            'desc'=>'Bartholomew &amp; Brown County — offline vector map'];
if (get_setting('show_topo','1')==='1')
    $tiles[] = ['href'=>'/topo/',      'icon'=>'🗾',  'label'=>'Topo Maps',         'desc'=>'USGS 1:24,000 topographic quads — Bartholomew &amp; Brown County'];
if (get_setting('show_calendar','1')==='1')
    $tiles[] = ['href'=>'/calendar/', 'icon'=>'📅', 'label'=>'Calendar',        'desc'=>'Community events &amp; schedules'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($name) ?></title>
    <style>
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:sans-serif; background:#1a1a2e; color:#eee; min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:2rem; }
        .alert { background:#3a0a0a; border:2px solid #e94560; color:#e94560; padding:12px 24px; border-radius:8px; font-size:14px; font-weight:bold; margin-bottom:2rem; text-align:center; max-width:700px; width:100%; }
        h1 { font-size:3rem; margin-bottom:0.5rem; color:#e94560; }
        p { color:#aaa; margin-bottom:3rem; font-size:1.1rem; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:1.5rem; width:100%; max-width:1100px; }
        a.tile { display:block; background:#16213e; border:1px solid #e94560; border-radius:12px; padding:2rem; text-align:center; text-decoration:none; color:#eee; transition:0.2s; }
        a.tile:hover { background:#e94560; transform:translateY(-3px); }
        .icon { font-size:2.5rem; margin-bottom:0.75rem; }
        .label { font-size:1.1rem; font-weight:bold; }
        .desc { font-size:0.85rem; color:#aaa; margin-top:0.4rem; }
        a.tile:hover .desc { color:#fff; }
    </style>
</head>
<body>
    <?php if ($alert): ?>
    <div class="alert">⚠ <?= htmlspecialchars($alert) ?></div>
    <?php endif; ?>
    <h1><?= htmlspecialchars($name) ?></h1>
    <p><?= htmlspecialchars($tagline) ?></p>
    <div class="grid">
        <?php foreach ($tiles as $t): ?>
        <a class="tile" href="<?= $t['href'] ?>">
            <div class="icon"><?= $t['icon'] ?></div>
            <div class="label"><?= $t['label'] ?></div>
            <div class="desc"><?= $t['desc'] ?></div>
        </a>
        <?php endforeach; ?>
    </div>
    <div style="margin-top:2.5rem">
        <a href="/admin/" style="font-size:12px;color:#333;text-decoration:none;padding:6px 14px;border:1px solid #333;border-radius:5px;transition:.15s" onmouseover="this.style.color='#e94560';this.style.borderColor='#e94560'" onmouseout="this.style.color='#333';this.style.borderColor='#333'">Admin</a>
    </div>
<script>
(function(){
  var seq=[],t=0;
  document.addEventListener('keydown',function(e){
    var now=Date.now();
    if(now-t>1500) seq=[];
    t=now; seq.push(e.key.toLowerCase());
    if(seq.length>3) seq.shift();
    if(seq.join('')==='aaa') window.location.href='/admin/';
  });
})();
</script>
</body>
</html>
