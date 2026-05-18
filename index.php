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
if (get_setting('show_tasks','1')==='1')
    $tiles[] = ['href'=>'/tasks/',    'icon'=>'📋', 'label'=>'Tasks',            'desc'=>'Volunteer jobs — claim a task and help out'];
if (get_setting('show_library','1')==='1')
    $tiles[] = ['href'=>'/library/',  'icon'=>'📚', 'label'=>'Library',         'desc'=>'Offline Wikipedia, WikiMed, guides &amp; more'];
if (get_setting('show_maps','1')==='1')
    $tiles[] = ['href'=>'/maps/',     'icon'=>'🗺️',  'label'=>'Maps',            'desc'=>'Bartholomew &amp; Brown County — offline map with shared markers'];
if (get_setting('show_topo','1')==='1')
    $tiles[] = ['href'=>'/topo/',      'icon'=>'⛰️',  'label'=>'Topo Maps',         'desc'=>'USGS 1:24,000 topographic quads — Bartholomew &amp; Brown County'];
if (get_setting('show_calendar','1')==='1')
    $tiles[] = ['href'=>'/calendar/', 'icon'=>'📅', 'label'=>'Calendar',        'desc'=>'Community events &amp; schedules'];
if (get_setting('show_runners','0')==='1')
    $tiles[] = ['href'=>'/runners/',  'icon'=>'🏃', 'label'=>get_setting('runners_label','Runner Board'),  'desc'=>'Track who\'s out in the field — flags overdue runners'];
if (get_setting('show_weather','0')==='1')
    $tiles[] = ['href'=>'/weather/',  'icon'=>'⛅', 'label'=>get_setting('weather_label','Weather Log'), 'desc'=>'Log weather observations &amp; conditions'];
if (get_setting('show_radio','0')==='1')
    $tiles[] = ['href'=>'/radio/',    'icon'=>'📻', 'label'=>get_setting('radio_label','Radio Net Log'),  'desc'=>'Log radio contacts, traffic &amp; net check-ins'];
if (get_setting('show_incidents','0')==='1') {
    $inc_cmd = get_setting('show_incidents_command','0')==='1';
    $tiles[] = ['href'=>'/incidents/', 'icon'=>'📍',
                'label'=>$inc_cmd ? 'Incident Reports' : 'Map Reports',
                'desc'=>$inc_cmd
                    ? 'Field reports with severity, status &amp; assignment — drop pins on the map'
                    : 'Drop pins on the map — report what you see out there'];
}
if (get_setting('show_games','0')==='1')
    $tiles[] = ['href'=>'/games/',  'icon'=>'🎮', 'label'=>'Games',        'desc'=>'Browser-based games — Snake, Tetris, 2048 and more'];
if (get_setting('show_wiki','1')==='1')
    $tiles[] = ['href'=>'/wiki/',    'icon'=>'📖', 'label'=>'Local Knowledge Wiki', 'desc'=>'Community-editable reference — roads, water, skills, local know-how'];
if (get_setting('show_supplies','0')==='1' || get_setting('show_seeds','0')==='1' || get_setting('show_tools','0')==='1')
    $tiles[] = ['href'=>'/resources/', 'icon'=>'🗃️', 'label'=>'Resources', 'desc'=>'Supply inventory, seed library &amp; tool lending'];
if (get_setting('show_triage','0')==='1')
    $tiles[] = ['href'=>'/triage/',  'icon'=>'🏥', 'label'=>'Triage Log', 'desc'=>'MCI patient tracking — START triage priority, print patient tags'];
if (get_setting('show_canvas','0')==='1')
    $tiles[] = ['href'=>'/canvas/',  'icon'=>'🎨', 'label'=>'Canvas',     'desc'=>'Freehand drawing, diagrams &amp; annotated map sketches'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($name) ?></title>
    <?php require_once '/var/www/noosphere/shared/head.php'; ?>
    <style>
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:sans-serif; background:var(--bg); color:var(--text); min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:2rem; }
        .alert { background:color-mix(in srgb, var(--accent) 15%, transparent); border:2px solid var(--accent); color:var(--accent); padding:12px 24px; border-radius:8px; font-size:14px; font-weight:bold; margin-bottom:2rem; text-align:center; max-width:700px; width:100%; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:1.5rem; width:100%; max-width:1100px; }
        a.tile { display:block; background:var(--tile-bg); border:1px solid var(--tile-border); border-radius:12px; padding:2rem; text-align:center; text-decoration:none; color:var(--text); transition:0.2s; }
        a.tile:hover { background:var(--tile-hover); transform:translateY(-3px); }
        .icon { font-size:2.5rem; margin-bottom:0.75rem; }
        .label { font-size:1.1rem; font-weight:bold; }
        .desc { font-size:0.85rem; color:var(--text-muted); margin-top:0.4rem; }
        a.tile:hover .desc { color:#fff; }
    </style>
</head>
<body>
    <?php if ($alert): ?>
    <div class="alert">⚠ <?= htmlspecialchars($alert) ?></div>
    <?php endif; ?>
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
        <a href="/admin/" style="font-size:12px;color:var(--admin-link);text-decoration:none;padding:6px 14px;border:1px solid var(--admin-link);border-radius:5px;transition:.15s" onmouseover="this.style.color='var(--accent)';this.style.borderColor='var(--accent)'" onmouseout="this.style.color='';this.style.borderColor=''">Admin</a>
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
