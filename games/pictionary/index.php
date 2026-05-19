<?php
require_once '/var/www/noosphere/shared/settings.php';
if (get_setting('show_games','0') !== '1') { http_response_code(404); exit; }

define('GAMES_DB', '/var/lib/noosphere/games.db');

$WORDS = ['shelter','radio','water','fire','map','rope','tent','compass','bridge','tower',
  'truck','dog','cat','bird','fish','tree','sun','moon','star','cloud',
  'house','door','window','chair','table','book','pen','phone','knife','fork',
  'apple','bread','soup','coffee','candle','lantern','flashlight','battery','generator','antenna',
  'backpack','boots','helmet','glove','mask','barrel','bucket','shovel','axe','saw',
  'hospital','police','church','school','barn','fence','well','road','trail','river',
  'rain','snow','wind','lightning','smoke','fire','flood','mud','ice','fog',
  'bandage','splint','stretcher','syringe','pill','stethoscope','crutch','wheelchair','ambulance','helicopter'];

function gdb() {
    static $db = null;
    if (!$db) {
        $db = new PDO('sqlite:' . GAMES_DB);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE IF NOT EXISTS pictionary_rooms (
            id TEXT PRIMARY KEY,
            state TEXT NOT NULL,
            created_at INTEGER, updated_at INTEGER
        )");
    }
    return $db;
}

function room_get($id) {
    $r = gdb()->prepare('SELECT * FROM pictionary_rooms WHERE id=?');
    $r->execute([$id]);
    $row = $r->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['state'] = json_decode($row['state'], true);
    return $row;
}

function room_save($id, $state) {
    $db = gdb();
    $ex = $db->prepare('SELECT id FROM pictionary_rooms WHERE id=?');
    $ex->execute([$id]);
    if ($ex->fetch()) {
        $db->prepare('UPDATE pictionary_rooms SET state=?, updated_at=? WHERE id=?')->execute([json_encode($state), time(), $id]);
    } else {
        $db->prepare('INSERT INTO pictionary_rooms (id,state,created_at,updated_at) VALUES (?,?,?,?)')->execute([$id, json_encode($state), time(), time()]);
    }
}

function new_state($words) {
    return ['phase'=>'lobby','players'=>[],'drawer'=>null,'word'=>null,'strokes'=>[],'stroke_ver'=>0,
            'guesses'=>[],'scores'=>[],'round'=>0,'turn_start'=>null,'words'=>$words];
}

function next_turn(&$st) {
    $pids = array_keys($st['players']);
    if (!$pids) return;
    shuffle($pids);
    global $WORDS;
    $st['drawer']     = $pids[0];
    $st['word']       = $WORDS[array_rand($WORDS)];
    $st['strokes']    = [];
    $st['stroke_ver'] = 0;
    $st['guesses']    = [];
    $st['phase']      = 'drawing';
    $st['turn_start'] = time();
    $st['round']++;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $act  = $body['act'] ?? '';
    $room_id = preg_replace('/[^a-zA-Z0-9]/', '', $body['room'] ?? '');
    $pid  = preg_replace('/[^a-zA-Z0-9]/', '', $body['pid'] ?? '');
    $name = htmlspecialchars(substr($body['name'] ?? 'Player', 0, 20), ENT_QUOTES);
    global $WORDS;

    if ($act === 'join') {
        if (!$room_id) {
            $r = gdb()->query("SELECT id FROM pictionary_rooms WHERE json_extract(state,'$.phase')='lobby' ORDER BY created_at DESC LIMIT 1");
            $row = $r->fetch(PDO::FETCH_ASSOC);
            $room_id = $row ? $row['id'] : substr(md5(uniqid('',true)),0,8);
            if (!$row) room_save($room_id, new_state($WORDS));
        }
        $room = room_get($room_id);
        if (!$room) { echo json_encode(['ok'=>false]); exit; }
        $st = $room['state'];
        if (!isset($st['players'][$pid])) {
            $st['players'][$pid] = ['name'=>$name];
            if (!isset($st['scores'][$pid])) $st['scores'][$pid] = 0;
            room_save($room_id, $st);
        }
        echo json_encode(['ok'=>true,'room'=>$room_id,'state'=>filter_state($st,$pid),'updated_at'=>$room['updated_at']]);
        exit;
    }

    if ($act === 'start') {
        $room = room_get($room_id); if(!$room){echo json_encode(['ok'=>false]);exit;}
        $st = $room['state'];
        if (count($st['players']) < 2) { echo json_encode(['ok'=>false,'error'=>'Need at least 2 players']); exit; }
        next_turn($st);
        room_save($room_id, $st);
        echo json_encode(['ok'=>true,'state'=>filter_state($st,$pid)]);
        exit;
    }

    if ($act === 'stroke') {
        // Append drawing strokes [{x,y,type,c,w}]
        $room = room_get($room_id); if(!$room){echo json_encode(['ok'=>false]);exit;}
        $st = $room['state'];
        if ($st['drawer'] !== $pid || $st['phase'] !== 'drawing') { echo json_encode(['ok'=>false]); exit; }
        $new_strokes = array_slice($body['strokes'] ?? [], 0, 500);
        foreach ($new_strokes as $pt) {
            $st['strokes'][] = [
                'x' => round((float)($pt['x']??0),1),
                'y' => round((float)($pt['y']??0),1),
                't' => $pt['t']??'move',  // 'start'|'move'|'end'
                'c' => preg_replace('/[^#a-zA-Z0-9]/','',$pt['c']??'#fff'),
                'w' => min(40, max(1, (int)($pt['w']??4))),
            ];
        }
        if ($body['clear'] ?? false) { $st['strokes'] = []; }
        $st['stroke_ver']++;
        // Check turn time (90s)
        if ($st['turn_start'] && time() - $st['turn_start'] > 90) {
            $st['phase'] = 'roundend';
        }
        room_save($room_id, $st);
        echo json_encode(['ok'=>true,'stroke_ver'=>$st['stroke_ver']]);
        exit;
    }

    if ($act === 'guess') {
        $room = room_get($room_id); if(!$room){echo json_encode(['ok'=>false]);exit;}
        $st = $room['state'];
        if ($pid === $st['drawer'] || $st['phase'] !== 'drawing') { echo json_encode(['ok'=>false]); exit; }
        $guess = htmlspecialchars(substr(trim($body['guess']??''),0,60),ENT_QUOTES);
        if (!$guess) { echo json_encode(['ok'=>false]); exit; }
        $correct = strcasecmp($guess, $st['word']) === 0;
        $st['guesses'][] = ['pid'=>$pid,'name'=>$st['players'][$pid]['name']??'?','text'=>$guess,'correct'=>$correct,'ts'=>time()];
        if ($correct) {
            $st['scores'][$pid] = ($st['scores'][$pid]??0) + 1;
            $st['scores'][$st['drawer']] = ($st['scores'][$st['drawer']]??0) + 1;
            $st['phase'] = 'roundend';
        }
        room_save($room_id, $st);
        echo json_encode(['ok'=>true,'correct'=>$correct,'state'=>filter_state($st,$pid)]);
        exit;
    }

    if ($act === 'next_turn') {
        $room = room_get($room_id); if(!$room){echo json_encode(['ok'=>false]);exit;}
        $st = $room['state'];
        if ($st['phase'] !== 'roundend') { echo json_encode(['ok'=>false]); exit; }
        next_turn($st);
        room_save($room_id, $st);
        echo json_encode(['ok'=>true,'state'=>filter_state($st,$pid)]);
        exit;
    }

    if ($act === 'poll') {
        $room = room_get($room_id); if(!$room){echo json_encode(['ok'=>false]);exit;}
        $st = $room['state'];
        $since = (int)($body['stroke_ver']??0);
        $new_strokes = array_slice($st['strokes'], $since);
        $resp = filter_state($st,$pid);
        $resp['new_strokes'] = $new_strokes;
        $resp['stroke_ver']  = $st['stroke_ver'];
        echo json_encode(['ok'=>true,'state'=>$resp,'updated_at'=>$room['updated_at']]);
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>'unknown action']);
    exit;
}

function filter_state($st, $pid) {
    $s = $st;
    $s['i_am_drawer'] = ($st['drawer'] === $pid);
    if (!$s['i_am_drawer'] && $st['phase'] === 'drawing') $s['word'] = null;
    $s['time_left'] = $st['turn_start'] ? max(0, 90 - (time() - $st['turn_start'])) : 0;
    return $s;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>Pictionary  -  Noosphere</title>
<?php require_once '/var/www/noosphere/shared/head.php'; ?>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:sans-serif;background:var(--bg,#1a1a2e);color:var(--text,#eee);min-height:100vh;display:flex;flex-direction:column;align-items:center;padding:.75rem}
h1{color:var(--accent,#e94560);font-size:1.3rem;margin-bottom:.2rem}
.sub{color:var(--text-muted,#aaa);font-size:.82rem;margin-bottom:.6rem}
.status{background:var(--bg-card,#16213e);border:1px solid var(--border,#2a2a4a);border-radius:7px;padding:.45rem .9rem;font-size:.88rem;margin-bottom:.5rem;text-align:center;max-width:760px;width:100%}
.main{display:flex;gap:.75rem;max-width:760px;width:100%;flex-wrap:wrap}
.canvas-wrap{flex:1;min-width:280px;position:relative}
canvas{background:#fff;border-radius:8px;border:2px solid var(--border,#2a2a4a);display:block;width:100%;touch-action:none;cursor:crosshair}
.toolbar{display:flex;gap:.4rem;align-items:center;margin-top:.4rem;flex-wrap:wrap}
.color-dot{width:24px;height:24px;border-radius:50%;cursor:pointer;border:2px solid transparent}
.color-dot.active{border-color:#fff;transform:scale(1.2)}
.brush-btn{background:var(--bg-card,#16213e);border:1px solid var(--border,#2a2a4a);color:var(--text,#eee);border-radius:5px;padding:.2rem .6rem;cursor:pointer;font-size:.8rem}
.brush-btn.active{border-color:var(--accent,#e94560);color:var(--accent,#e94560)}
.sidebar{width:200px;min-width:160px;display:flex;flex-direction:column;gap:.5rem}
.panel{background:var(--bg-card,#16213e);border:1px solid var(--border,#2a2a4a);border-radius:8px;padding:.6rem}
.panel h3{font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-dim,#555);margin-bottom:.4rem}
.guess-list{max-height:10rem;overflow-y:auto;font-size:.78rem}
.guess-list p{padding:.15rem 0;border-bottom:1px solid var(--border,#2a2a4a)}
.guess-list .correct{color:#2ecc71;font-weight:bold}
.guess-form{display:flex;gap:.3rem;margin-top:.4rem}
.guess-form input{flex:1;min-width:0;background:var(--bg-input,#0d0d1a);border:1px solid var(--border,#2a2a4a);color:var(--text,#eee);border-radius:5px;padding:.3rem .5rem;font-size:.82rem}
.btn{background:var(--accent,#e94560);color:#fff;border:none;border-radius:6px;padding:.35rem .8rem;font-size:.82rem;cursor:pointer;white-space:nowrap}
.btn:disabled{opacity:.4;cursor:default}
.btn-sec{background:var(--bg-deep,#0f0f1a);color:var(--text,#eee);border:1px solid var(--border,#2a2a4a)}
.score-row{display:flex;justify-content:space-between;font-size:.8rem;padding:.1rem 0}
.name-row{display:flex;gap:.3rem;margin-bottom:.4rem}
.name-row input{flex:1;min-width:0;background:var(--bg-input,#0d0d1a);border:1px solid var(--border,#2a2a4a);color:var(--text,#eee);border-radius:5px;padding:.3rem .5rem;font-size:.82rem}
.back{margin-top:1rem;font-size:.82rem}
.back a{color:var(--text-dim,#555);text-decoration:none;border:1px solid var(--border,#2a2a4a);padding:4px 12px;border-radius:5px}
@media(max-width:520px){.sidebar{width:100%;flex-direction:row;flex-wrap:wrap}.sidebar .panel{flex:1;min-width:140px}}
</style>
</head>
<body>
<h1>🎨 Pictionary</h1>
<div class="sub">Draw and guess  -  90 seconds per turn</div>
<div class="status" id="status">Connecting…</div>

<div class="main">
  <div class="canvas-wrap">
    <canvas id="canvas" width="540" height="380"></canvas>
    <div class="toolbar" id="toolbar" style="display:none">
      <span style="font-size:.78rem;color:var(--text-muted,#aaa)">Color:</span>
      <?php foreach (['#fff','#111','#e74c3c','#3498db','#2ecc71','#f39c12','#9b59b6','#1abc9c'] as $c): ?>
      <span class="color-dot<?= $c==='#fff'?' active':'' ?>" style="background:<?= $c ?>" data-c="<?= $c ?>" onclick="setColor('<?= $c ?>',this)"></span>
      <?php endforeach; ?>
      <span style="font-size:.78rem;color:var(--text-muted,#aaa);margin-left:.4rem">Size:</span>
      <button class="brush-btn active" data-w="4" onclick="setWidth(4,this)">S</button>
      <button class="brush-btn" data-w="10" onclick="setWidth(10,this)">M</button>
      <button class="brush-btn" data-w="22" onclick="setWidth(22,this)">L</button>
      <button class="btn btn-sec" onclick="clearCanvas()" style="margin-left:.4rem;padding:.2rem .6rem;font-size:.78rem">🗑 Clear</button>
    </div>
    <div id="word-display" style="text-align:center;margin-top:.4rem;font-size:.9rem;color:var(--text-muted,#aaa)"></div>
  </div>
  <div class="sidebar">
    <div class="panel">
      <h3>Players</h3>
      <div class="name-row">
        <input id="name-in" placeholder="Your name" maxlength="20">
        <button class="btn btn-sec" onclick="setName()" style="padding:.3rem .5rem;font-size:.75rem">Set</button>
      </div>
      <div id="player-list"></div>
    </div>
    <div class="panel" style="flex:1">
      <h3>Guesses</h3>
      <div class="guess-list" id="guess-list"></div>
      <div class="guess-form" id="guess-form">
        <input id="guess-in" placeholder="Guess…" maxlength="60" onkeydown="if(event.key==='Enter')sendGuess()">
        <button class="btn" onclick="sendGuess()">Go</button>
      </div>
    </div>
    <div class="panel">
      <h3>Scores</h3>
      <div id="scores"></div>
    </div>
    <div id="ctrl-area"></div>
  </div>
</div>
<details style="max-width:900px;width:100%;margin-top:.75rem;font-size:.82rem;color:var(--text-muted,#aaa);background:var(--bg-card,#16213e);border:1px solid var(--border,#2a2a4a);border-radius:8px;padding:.5rem .75rem">
  <summary style="cursor:pointer;color:var(--text,#eee);font-weight:bold;list-style:none">📖 How to Play</summary>
  <div style="margin-top:.6rem;line-height:1.6">
    <p><strong>Goal:</strong> Take turns drawing a secret word while your teammates guess it. More correct guesses = more points.</p>
    <p style="margin-top:.4rem"><strong>Starting:</strong> Share the link with 2+ players. Anyone can click <em>Start Game</em> once there are at least 2 players in the lobby.</p>
    <p style="margin-top:.4rem"><strong>Drawing turn (90 seconds):</strong></p>
    <ul style="margin:.3rem 0 .3rem 1.2rem">
      <li>The active drawer sees the secret word at the top. Everyone else sees only the canvas.</li>
      <li>Draw using your mouse or finger. Pick a color and brush size from the toolbar.</li>
      <li>No writing the word, no spelling it out, no speaking it aloud.</li>
    </ul>
    <p style="margin-top:.4rem"><strong>Guessing:</strong> Type your guess in the chat box and press Enter. Correct guesses are highlighted  -  drawer gets +2 pts, guesser gets +1 pt.</p>
    <p style="margin-top:.4rem"><strong>Turn ends</strong> when the timer hits zero or everyone has guessed correctly. The next player becomes the drawer.</p>
    <p style="margin-top:.4rem"><strong>Controls:</strong> Mouse drag or touch drag to draw. Toolbar has 8 colors, 3 brush sizes, and an eraser. Clear button wipes the canvas.</p>
  </div>
</details>
<div class="back"><a href="/games/">← Games</a></div>

<script>
var pid = localStorage.getItem('pic_pid') || (Math.random().toString(36).slice(2)+Date.now().toString(36));
localStorage.setItem('pic_pid', pid);
var myName = localStorage.getItem('pic_name') || ('Player'+Math.floor(Math.random()*99+1));
var room = new URLSearchParams(location.search).get('r') || '';
var state = null;
var lastUpdated = 0;
var strokeVer = 0;
var pollTimer = null;

var canvas = document.getElementById('canvas');
var ctx = canvas.getContext('2d');
var drawing = false;
var curColor = '#fff';
var curWidth = 4;
var strokeBuf = [];
var flushTimer = null;

document.getElementById('name-in').value = myName;

function api(data) {
  data.pid = pid; data.name = myName;
  if (room) data.room = room;
  return fetch('', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data)}).then(r=>r.json());
}
function setStatus(msg, col){ var s=document.getElementById('status'); s.textContent=msg; s.style.color=col||''; }

function setName() {
  var v = document.getElementById('name-in').value.trim();
  if (!v) return;
  myName = v; localStorage.setItem('pic_name', v);
}

function setColor(c, el) {
  curColor = c;
  document.querySelectorAll('.color-dot').forEach(function(d){ d.classList.remove('active'); });
  el.classList.add('active');
}
function setWidth(w, el) {
  curWidth = w;
  document.querySelectorAll('.brush-btn').forEach(function(b){ b.classList.remove('active'); });
  el.classList.add('active');
}

function getXY(e) {
  var r = canvas.getBoundingClientRect();
  var scaleX = canvas.width / r.width, scaleY = canvas.height / r.height;
  var src = e.touches ? e.touches[0] : e;
  return {x: (src.clientX - r.left)*scaleX, y: (src.clientY - r.top)*scaleY};
}

canvas.addEventListener('mousedown', function(e){ if(!state||!state.i_am_drawer)return; drawing=true; var p=getXY(e); strokeBuf.push({x:p.x,y:p.y,t:'start',c:curColor,w:curWidth}); ctx.beginPath(); ctx.moveTo(p.x,p.y); });
canvas.addEventListener('mousemove', function(e){ if(!drawing)return; var p=getXY(e); strokeBuf.push({x:p.x,y:p.y,t:'move',c:curColor,w:curWidth}); ctx.lineTo(p.x,p.y); ctx.strokeStyle=curColor; ctx.lineWidth=curWidth; ctx.lineCap='round'; ctx.lineJoin='round'; ctx.stroke(); });
canvas.addEventListener('mouseup', function(){ if(!drawing)return; drawing=false; strokeBuf.push({x:0,y:0,t:'end',c:curColor,w:curWidth}); scheduleFlush(); });
canvas.addEventListener('mouseleave', function(){ if(drawing){ drawing=false; strokeBuf.push({x:0,y:0,t:'end',c:curColor,w:curWidth}); scheduleFlush(); } });
canvas.addEventListener('touchstart', function(e){ e.preventDefault(); if(!state||!state.i_am_drawer)return; drawing=true; var p=getXY(e); strokeBuf.push({x:p.x,y:p.y,t:'start',c:curColor,w:curWidth}); ctx.beginPath(); ctx.moveTo(p.x,p.y); }, {passive:false});
canvas.addEventListener('touchmove', function(e){ e.preventDefault(); if(!drawing)return; var p=getXY(e); strokeBuf.push({x:p.x,y:p.y,t:'move',c:curColor,w:curWidth}); ctx.lineTo(p.x,p.y); ctx.strokeStyle=curColor; ctx.lineWidth=curWidth; ctx.lineCap='round'; ctx.lineJoin='round'; ctx.stroke(); }, {passive:false});
canvas.addEventListener('touchend', function(e){ e.preventDefault(); if(!drawing)return; drawing=false; strokeBuf.push({x:0,y:0,t:'end',c:curColor,w:curWidth}); scheduleFlush(); }, {passive:false});

function scheduleFlush() {
  if (flushTimer) return;
  flushTimer = setTimeout(flushStrokes, 300);
}
function flushStrokes() {
  flushTimer = null;
  if (!strokeBuf.length) return;
  var toSend = strokeBuf.slice();
  strokeBuf = [];
  api({act:'stroke', strokes:toSend});
}

function clearCanvas() {
  ctx.clearRect(0,0,canvas.width,canvas.height);
  api({act:'stroke', strokes:[], clear:true});
}

function replayStrokes(strokes) {
  ctx.clearRect(0,0,canvas.width,canvas.height);
  var inPath = false;
  strokes.forEach(function(pt){
    if (pt.t==='start') { ctx.beginPath(); ctx.moveTo(pt.x,pt.y); inPath=true; ctx.strokeStyle=pt.c; ctx.lineWidth=pt.w; ctx.lineCap='round'; ctx.lineJoin='round'; }
    else if (pt.t==='move' && inPath) { ctx.lineTo(pt.x,pt.y); ctx.stroke(); ctx.beginPath(); ctx.moveTo(pt.x,pt.y); }
    else if (pt.t==='end') { inPath=false; }
  });
}

function applyStrokes(newStrokes) {
  if (!newStrokes || !newStrokes.length) return;
  if (!state || state.i_am_drawer) return;
  // Replay all strokes cumulatively
  replayStrokes((state.allStrokes||[]).concat(newStrokes));
  state.allStrokes = (state.allStrokes||[]).concat(newStrokes);
}

function applyState(st) {
  var prevPhase = state ? state.phase : null;
  state = st;
  if (!state.allStrokes) state.allStrokes = [];

  var phase = st.phase;
  var amDrawer = st.i_am_drawer;
  var drawerName = (st.players && st.drawer && st.players[st.drawer]) ? st.players[st.drawer].name : 'someone';

  if (phase==='lobby') {
    setStatus('Lobby  -  share link and wait for players: ' + location.href + (location.search?'':'?r='+room));
  } else if (phase==='drawing') {
    if (amDrawer) {
      setStatus('Your turn to draw! Word: ' + st.word + ' (' + (st.time_left||90) + 's)', '#4a9eff');
      document.getElementById('word-display').textContent = '🎨 Draw: ' + st.word;
    } else {
      setStatus(drawerName + ' is drawing… Guess below! (' + (st.time_left||90) + 's)');
      document.getElementById('word-display').textContent = '_ '.repeat((st.word_len||5)).trim();
    }
  } else if (phase==='roundend') {
    var word = st.word || '?';
    setStatus('Round over! The word was: ' + word, '#2ecc71');
    document.getElementById('word-display').textContent = 'Word was: ' + word;
    // Clear canvas for everyone
    if (prevPhase === 'drawing') { ctx.clearRect(0,0,canvas.width,canvas.height); state.allStrokes=[]; strokeVer=0; }
  }

  // Toolbar visibility
  document.getElementById('toolbar').style.display = amDrawer && phase==='drawing' ? 'flex' : 'none';
  document.getElementById('guess-form').style.display = !amDrawer && phase==='drawing' ? 'flex' : 'none';

  // Players
  var pl = document.getElementById('player-list');
  pl.innerHTML = '';
  Object.entries(st.players||{}).forEach(function(kv){
    var ppid=kv[0], p=kv[1];
    var isDrawer = ppid===st.drawer;
    pl.innerHTML += '<div style="font-size:.8rem;padding:.1rem 0;color:'+(ppid===pid?'var(--accent2,#4a9eff)':'var(--text,#eee)')+'">'+p.name+(isDrawer?' ✏️':'')+'</div>';
  });

  // Guesses
  var gl = document.getElementById('guess-list');
  gl.innerHTML = '';
  (st.guesses||[]).slice().reverse().forEach(function(g){
    gl.innerHTML += '<p class="'+(g.correct?'correct':'')+'"><strong>'+g.name+':</strong> '+g.text+(g.correct?' ✓':'')+'</p>';
  });

  // Scores
  var sc = document.getElementById('scores');
  sc.innerHTML = '';
  var sorted = Object.entries(st.scores||{}).sort(function(a,b){return b[1]-a[1];});
  sorted.forEach(function(kv){
    var name = (st.players[kv[0]]||{}).name || kv[0];
    sc.innerHTML += '<div class="score-row"><span>'+(kv[0]===pid?'<strong>'+name+'</strong>':name)+'</span><span>'+kv[1]+'</span></div>';
  });

  // Controls
  var ctrl = document.getElementById('ctrl-area');
  ctrl.innerHTML = '';
  if (phase==='lobby' && Object.keys(st.players||{}).length >= 2) {
    ctrl.innerHTML = '<button class="btn" onclick="startGame()">▶ Start</button>';
  } else if (phase==='lobby') {
    ctrl.innerHTML = '<div style="font-size:.78rem;color:var(--text-muted,#aaa)">Need 2+ players</div>';
  } else if (phase==='roundend') {
    ctrl.innerHTML = '<button class="btn" onclick="nextTurn()">▶ Next Turn</button>';
  }
}

function sendGuess() {
  var inp = document.getElementById('guess-in');
  var g = inp.value.trim();
  if (!g) return;
  inp.value = '';
  api({act:'guess', guess:g}).then(function(r){ if(r.ok) applyState(r.state); });
}

function startGame() { api({act:'start'}).then(function(r){ if(r.ok) applyState(r.state); }); }
function nextTurn() {
  state.allStrokes = []; strokeVer = 0;
  ctx.clearRect(0,0,canvas.width,canvas.height);
  api({act:'next_turn'}).then(function(r){ if(r.ok) applyState(r.state); });
}

function startPoll() {
  if (pollTimer) return;
  pollTimer = setInterval(function(){
    api({act:'poll', stroke_ver:strokeVer}).then(function(r){
      if (!r.ok) return;
      var st = r.state;
      // Apply new strokes if not drawer
      if (!st.i_am_drawer && st.new_strokes && st.new_strokes.length) {
        if (!state.allStrokes) state.allStrokes = [];
        state.allStrokes = state.allStrokes.concat(st.new_strokes);
        replayStrokes(state.allStrokes);
        strokeVer = st.stroke_ver;
      }
      if (r.updated_at > lastUpdated) {
        lastUpdated = r.updated_at;
        var was = state ? state.phase : null;
        st.allStrokes = state ? state.allStrokes : [];
        applyState(st);
        if (was==='drawing' && st.phase==='roundend') {
          ctx.clearRect(0,0,canvas.width,canvas.height);
          if(state) state.allStrokes=[];
          strokeVer=0;
        }
      }
    });
  }, 800);
}

// Init
(function(){
  api({act:'join'}).then(function(r){
    if (!r.ok) { setStatus('Error'); return; }
    room = r.room;
    history.replaceState(null,'','?r='+room);
    lastUpdated = r.updated_at||0;
    applyState(r.state);
    startPoll();
  });
})();
</script>
</body>
</html>
