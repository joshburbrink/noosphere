<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();

$modules = [];
if (get_setting('show_supplies','0')==='1')
    $modules[] = ['href'=>'/supplies/', 'icon'=>'📦', 'label'=>'Supply Inventory', 'desc'=>'Track shelter resources — water, food, fuel, medical'];
if (get_setting('show_seeds','0')==='1')
    $modules[] = ['href'=>'/seeds/', 'icon'=>'🌱', 'label'=>'Seed Library', 'desc'=>'Zone 6a seed catalog and planting calendar'];
if (get_setting('show_tools','0')==='1')
    $modules[] = ['href'=>'/tools/', 'icon'=>'🔧', 'label'=>'Tool Lending', 'desc'=>'Borrow and return community tools and equipment'];

if (empty($modules)) { http_response_code(404); exit; }
if (count($modules) === 1) { header('Location: '.$modules[0]['href']); exit; }

$name = get_setting('instance_name', 'Noosphere');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Resources — <?= htmlspecialchars($name) ?></title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:sans-serif; background:#1a1a2e; color:#eee; min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:2rem; }
h1 { font-size:1.6rem; margin-bottom:0.4rem; }
.sub { color:#aaa; font-size:.9rem; margin-bottom:2rem; }
.grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:1.5rem; width:100%; max-width:800px; }
a.tile { display:block; background:#16213e; border:1px solid #e94560; border-radius:12px; padding:2rem; text-align:center; text-decoration:none; color:#eee; transition:.2s; }
a.tile:hover { background:#e94560; transform:translateY(-3px); }
.icon { font-size:2.5rem; margin-bottom:.75rem; }
.label { font-size:1.1rem; font-weight:bold; }
.desc { font-size:.85rem; color:#aaa; margin-top:.4rem; }
a.tile:hover .desc { color:#fff; }
.back { margin-top:2rem; font-size:12px; color:#555; text-decoration:none; }
.back:hover { color:#e94560; }
</style>
</head>
<body>
<h1>Resources</h1>
<div class="sub">Community tools, supplies, and knowledge</div>
<div class="grid">
<?php foreach ($modules as $m): ?>
<a class="tile" href="<?= $m['href'] ?>">
  <div class="icon"><?= $m['icon'] ?></div>
  <div class="label"><?= $m['label'] ?></div>
  <div class="desc"><?= $m['desc'] ?></div>
</a>
<?php endforeach; ?>
</div>
<a class="back" href="/">← Home</a>
</body>
</html>
