<?php
require_once '/var/www/noosphere/shared/settings.php';

if (get_setting('show_games','0') !== '1') {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:2rem;color:#eee;background:#1a1a2e;min-height:100vh;margin:0">Games module is not enabled.</p>');
}

$multiplayer = [
    ['file'=>'battleship/', 'icon'=>'⚓', 'title'=>'Battleship',  'desc'=>'2 players — sink the fleet · share link to join'],
    ['file'=>'codenames/',  'icon'=>'🕵️', 'title'=>'Codenames',   'desc'=>'2–8 players — one-word clues, two teams'],
    ['file'=>'pictionary/', 'icon'=>'🎨', 'title'=>'Pictionary',  'desc'=>'2+ players — draw and guess · 90s per turn'],
];
$games = [
    ['file'=>'snake.html',      'icon'=>'🐍', 'title'=>'Snake',       'desc'=>'Classic snake — eat, grow, survive'],
    ['file'=>'tetris.html',     'icon'=>'🧱', 'title'=>'Tetris',      'desc'=>'Stack falling blocks — clear lines'],
    ['file'=>'2048.html',       'icon'=>'🔢', 'title'=>'2048',        'desc'=>'Slide tiles — reach 2048'],
    ['file'=>'breakout.html',   'icon'=>'🏓', 'title'=>'Breakout',    'desc'=>'Paddle + ball — smash the bricks'],
    ['file'=>'minesweeper.html','icon'=>'💣', 'title'=>'Minesweeper', 'desc'=>'Find all mines without triggering one'],
    ['file'=>'tictactoe.html',  'icon'=>'⭕', 'title'=>'Tic Tac Toe', 'desc'=>'Two players or vs computer'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Games — Noosphere</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:sans-serif;background:#1a1a2e;color:#eee;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2rem}
h1{font-size:1.8rem;margin-bottom:.5rem;color:#e94560}
.sub{font-size:.95rem;color:#888;margin-bottom:2rem}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1.5rem;width:100%;max-width:900px}
a.tile{display:block;background:#16213e;border:1px solid #e94560;border-radius:12px;padding:2rem;text-align:center;text-decoration:none;color:#eee;transition:.2s}
a.tile:hover{background:#e94560;transform:translateY(-3px)}
.icon{font-size:2.5rem;margin-bottom:.75rem}
.label{font-size:1.1rem;font-weight:bold}
.desc{font-size:.82rem;color:#aaa;margin-top:.4rem}
a.tile:hover .desc{color:#fff}
.back{margin-top:2rem;font-size:.85rem}
.back a{color:#555;text-decoration:none;border:1px solid #333;padding:5px 14px;border-radius:5px;transition:.15s}
.back a:hover{color:#e94560;border-color:#e94560}
</style>
</head>
<body>
<h1>🎮 Games</h1>
<div class="sub">Browser-based games — no internet required · high scores saved per device</div>
<div style="font-size:.82rem;color:#888;margin-bottom:.6rem;text-transform:uppercase;letter-spacing:.05em">Multiplayer</div>
<div class="grid" style="margin-bottom:1.5rem">
<?php foreach ($multiplayer as $g): ?>
<a class="tile" href="/games/<?= $g['file'] ?>">
  <div class="icon"><?= $g['icon'] ?></div>
  <div class="label"><?= $g['title'] ?></div>
  <div class="desc"><?= $g['desc'] ?></div>
</a>
<?php endforeach; ?>
</div>
<div style="font-size:.82rem;color:#888;margin-bottom:.6rem;text-transform:uppercase;letter-spacing:.05em">Single Player</div>
<div class="grid">
<?php foreach ($games as $g): ?>
<a class="tile" href="/games/<?= $g['file'] ?>">
  <div class="icon"><?= $g['icon'] ?></div>
  <div class="label"><?= $g['title'] ?></div>
  <div class="desc"><?= $g['desc'] ?></div>
</a>
<?php endforeach; ?>
</div>
<div class="back"><a href="/">← Home</a></div>
</body>
</html>
