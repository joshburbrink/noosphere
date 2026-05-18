<?php
require_once '/var/www/noosphere/shared/settings.php';
if (get_setting('show_games','0') !== '1') { http_response_code(404); exit; }

define('GAMES_DB', '/var/lib/noosphere/games.db');

function gdb() {
    static $db = null;
    if (!$db) {
        $db = new PDO('sqlite:' . GAMES_DB);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE IF NOT EXISTS battleship_rooms (
            id TEXT PRIMARY KEY,
            p1 TEXT, p2 TEXT,
            state TEXT NOT NULL,
            created_at INTEGER, updated_at INTEGER
        )");
    }
    return $db;
}

function room_get($id) {
    $r = gdb()->prepare('SELECT * FROM battleship_rooms WHERE id=?');
    $r->execute([$id]);
    $row = $r->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['state'] = json_decode($row['state'], true);
    return $row;
}

function room_save($id, $state, $p1=null, $p2=null) {
    $db = gdb();
    $existing = $db->prepare('SELECT id FROM battleship_rooms WHERE id=?');
    $existing->execute([$id]);
    if ($existing->fetch()) {
        $s = $db->prepare('UPDATE battleship_rooms SET state=?, p1=COALESCE(?,p1), p2=COALESCE(?,p2), updated_at=? WHERE id=?');
        $s->execute([json_encode($state), $p1, $p2, time(), $id]);
    } else {
        $s = $db->prepare('INSERT INTO battleship_rooms (id,p1,p2,state,created_at,updated_at) VALUES (?,?,?,?,?,?)');
        $s->execute([$id, $p1, $p2, json_encode($state), time(), time()]);
    }
}

function new_state() {
    return ['phase'=>'waiting','turn'=>'p1','boards'=>['p1'=>['ships'=>[],'shots'=>[]],'p2'=>['ships'=>[],'shots'=>[]]],'placed'=>[],'winner'=>null];
}

function check_hit($ships, $cell) {
    foreach ($ships as $ship) {
        if (in_array($cell, $ship['cells'])) return true;
    }
    return false;
}

function check_sunk($ships, $shots) {
    foreach ($ships as $ship) {
        $all_hit = true;
        foreach ($ship['cells'] as $c) {
            if (!in_array($c, $shots)) { $all_hit = false; break; }
        }
        if ($all_hit) return $ship;
    }
    return null;
}

function all_sunk($ships, $shots) {
    foreach ($ships as $ship) {
        foreach ($ship['cells'] as $c) {
            if (!in_array($c, $shots)) return false;
        }
    }
    return true;
}

// API
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $act  = $body['act'] ?? '';
    $room_id = preg_replace('/[^a-zA-Z0-9]/', '', $body['room'] ?? '');
    $pid  = preg_replace('/[^a-zA-Z0-9]/', '', $body['pid'] ?? '');

    if ($act === 'join') {
        // Find waiting room or create new
        if (!$room_id) {
            $r = gdb()->query('SELECT id,p1,p2,state FROM battleship_rooms WHERE json_extract(state,\'$.phase\')=\'waiting\' AND p2 IS NULL ORDER BY created_at LIMIT 1');
            $waiting = $r->fetch(PDO::FETCH_ASSOC);
            if ($waiting) { $room_id = $waiting['id']; }
            else {
                $room_id = substr(md5(uniqid('',true)),0,8);
                room_save($room_id, new_state(), $pid, null);
                echo json_encode(['ok'=>true,'room'=>$room_id,'role'=>'p1']);
                exit;
            }
        }
        $room = room_get($room_id);
        if (!$room) { echo json_encode(['ok'=>false,'error'=>'Room not found']); exit; }
        if ($room['p1'] === $pid) { echo json_encode(['ok'=>true,'room'=>$room_id,'role'=>'p1','state'=>$room['state']]); exit; }
        if ($room['p2'] === $pid) { echo json_encode(['ok'=>true,'room'=>$room_id,'role'=>'p2','state'=>$room['state']]); exit; }
        if (!$room['p2']) {
            $st = $room['state'];
            $st['phase'] = 'placing';
            room_save($room_id, $st, null, $pid);
            echo json_encode(['ok'=>true,'room'=>$room_id,'role'=>'p2','state'=>$st]);
        } else {
            echo json_encode(['ok'=>true,'room'=>$room_id,'role'=>'spectator','state'=>$room['state']]);
        }
        exit;
    }

    if ($act === 'place') {
        $room = room_get($room_id); if (!$room) { echo json_encode(['ok'=>false]); exit; }
        $st = $room['state'];
        $role = ($room['p1']===$pid)?'p1':(($room['p2']===$pid)?'p2':null);
        if (!$role || $st['phase']!=='placing' || in_array($role,$st['placed']??[])) { echo json_encode(['ok'=>false]); exit; }
        $ships = $body['ships'] ?? [];
        $st['boards'][$role]['ships'] = $ships;
        $st['placed'][] = $role;
        if (count($st['placed']) === 2) $st['phase'] = 'battle';
        room_save($room_id, $st);
        echo json_encode(['ok'=>true,'state'=>$st]);
        exit;
    }

    if ($act === 'shoot') {
        $room = room_get($room_id); if (!$room) { echo json_encode(['ok'=>false]); exit; }
        $st = $room['state'];
        $role = ($room['p1']===$pid)?'p1':(($room['p2']===$pid)?'p2':null);
        $opp = $role==='p1'?'p2':'p1';
        if (!$role||$st['phase']!=='battle'||$st['turn']!==$role) { echo json_encode(['ok'=>false,'error'=>'not your turn']); exit; }
        $cell = (int)($body['cell'] ?? -1);
        if ($cell<0||$cell>99||in_array($cell,$st['boards'][$role]['shots'])) { echo json_encode(['ok'=>false]); exit; }
        $st['boards'][$role]['shots'][] = $cell;
        $hit = check_hit($st['boards'][$opp]['ships'], $cell);
        if ($hit && all_sunk($st['boards'][$opp]['ships'], $st['boards'][$role]['shots'])) {
            $st['phase'] = 'done';
            $st['winner'] = $role;
        } else {
            $st['turn'] = $opp;
        }
        room_save($room_id, $st);
        echo json_encode(['ok'=>true,'hit'=>$hit,'state'=>$st]);
        exit;
    }

    if ($act === 'poll') {
        $room = room_get($room_id); if (!$room) { echo json_encode(['ok'=>false]); exit; }
        $role = ($room['p1']===$pid)?'p1':(($room['p2']===$pid)?'p2':'spectator');
        echo json_encode(['ok'=>true,'role'=>$role,'state'=>$room['state'],'updated_at'=>$room['updated_at']]);
        exit;
    }

    if ($act === 'new_room') {
        $room_id = substr(md5(uniqid('',true)),0,8);
        room_save($room_id, new_state(), $pid, null);
        echo json_encode(['ok'=>true,'room'=>$room_id,'role'=>'p1']);
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>'unknown action']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>Battleship — Noosphere</title>
<?php require_once '/var/www/noosphere/shared/head.php'; ?>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:sans-serif;background:var(--bg,#1a1a2e);color:var(--text,#eee);min-height:100vh;display:flex;flex-direction:column;align-items:center;padding:1rem}
h1{color:var(--accent,#e94560);font-size:1.4rem;margin-bottom:.25rem}
.sub{color:var(--text-muted,#aaa);font-size:.85rem;margin-bottom:1rem}
.status{background:var(--bg-card,#16213e);border:1px solid var(--border,#2a2a4a);border-radius:8px;padding:.6rem 1rem;font-size:.9rem;margin-bottom:.75rem;text-align:center;min-height:2rem}
.boards{display:flex;gap:1.5rem;flex-wrap:wrap;justify-content:center}
.board-wrap{text-align:center}
.board-label{font-size:.8rem;color:var(--text-muted,#aaa);margin-bottom:.3rem}
.grid{display:grid;grid-template-columns:repeat(10,2.8rem);gap:2px}
.cell{width:2.8rem;height:2.8rem;background:var(--bg-deep,#0f0f1a);border:1px solid var(--border,#2a2a4a);border-radius:3px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1rem;transition:.1s;user-select:none}
.cell.ship{background:#2a4a6a}
.cell.hit{background:#e94560!important;cursor:default}
.cell.miss{background:#2a2a4a!important;cursor:default;opacity:.5}
.cell.sunk{background:#6a1020!important;cursor:default}
.cell.placing{cursor:crosshair}
.cell.preview{background:#1a3a5a;opacity:.8}
.cell.disabled{cursor:default}
.btn{background:var(--accent,#e94560);color:#fff;border:none;border-radius:6px;padding:.5rem 1.2rem;font-size:.9rem;cursor:pointer;margin:.25rem}
.btn:disabled{opacity:.4;cursor:default}
.btn-sec{background:var(--bg-card,#16213e);color:var(--text,#eee);border:1px solid var(--border,#2a2a4a)}
.ships-tray{display:flex;flex-wrap:wrap;gap:.5rem;justify-content:center;margin-bottom:.75rem}
.ship-btn{background:var(--bg-card,#16213e);border:2px solid var(--border,#2a2a4a);border-radius:6px;padding:.35rem .7rem;cursor:pointer;font-size:.8rem;color:var(--text,#eee)}
.ship-btn.selected{border-color:var(--accent2,#4a9eff);color:var(--accent2,#4a9eff)}
.ship-btn.placed{opacity:.4;cursor:default;text-decoration:line-through}
.dir-btn{background:var(--bg-card,#16213e);border:1px solid var(--border,#2a2a4a);border-radius:6px;padding:.35rem .7rem;cursor:pointer;font-size:.8rem;color:var(--text,#eee)}
.dir-btn.active{border-color:var(--accent,#e94560);color:var(--accent,#e94560)}
.log{font-size:.75rem;color:var(--text-muted,#aaa);text-align:center;margin-top:.5rem;min-height:1.2rem}
.back{margin-top:1.5rem;font-size:.82rem}
.back a{color:var(--text-dim,#555);text-decoration:none;border:1px solid var(--border,#2a2a4a);padding:4px 12px;border-radius:5px}
@media(max-width:640px){.grid{grid-template-columns:repeat(10,2.2rem)}.cell{width:2.2rem;height:2.2rem;font-size:.8rem}}
@media(max-width:480px){.grid{grid-template-columns:repeat(10,1.8rem)}.cell{width:1.8rem;height:1.8rem;font-size:.7rem}}
</style>
</head>
<body>
<h1>⚓ Battleship</h1>
<div class="sub">2 players — share the room link</div>
<div class="status" id="status">Connecting…</div>

<div id="setup-panel" style="display:none;text-align:center;margin-bottom:.75rem">
  <div style="font-size:.85rem;color:var(--text-muted,#aaa);margin-bottom:.5rem">Place your ships — click a ship, then click your grid. <button class="dir-btn active" id="dir-btn" onclick="toggleDir()">→ Horizontal</button></div>
  <div class="ships-tray" id="ships-tray"></div>
</div>

<div class="boards">
  <div class="board-wrap">
    <div class="board-label" id="my-label">My Board</div>
    <div class="grid" id="my-grid"></div>
  </div>
  <div class="board-wrap">
    <div class="board-label" id="opp-label">Opponent</div>
    <div class="grid" id="opp-grid"></div>
  </div>
</div>

<div class="log" id="log"></div>
<div style="margin-top:.75rem;text-align:center">
  <button class="btn btn-sec" id="new-btn" onclick="newRoom()" style="display:none">New Game</button>
</div>
<div class="back"><a href="/games/">← Games</a></div>

<script>
var SHIPS = [
  {name:'Carrier',    size:5},
  {name:'Battleship', size:4},
  {name:'Cruiser',    size:3},
  {name:'Submarine',  size:3},
  {name:'Destroyer',  size:2},
];
var pid = localStorage.getItem('bs_pid') || (Math.random().toString(36).slice(2)+Date.now().toString(36));
localStorage.setItem('bs_pid', pid);
var room = new URLSearchParams(location.search).get('r') || '';
var role = '';
var state = null;
var placedShips = [];
var selShip = null;
var selDir = 'h';
var lastUpdated = 0;
var pollTimer = null;

function api(data) {
  data.pid = pid;
  if (room) data.room = room;
  return fetch('', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data)}).then(r=>r.json());
}

function setStatus(msg, col) {
  var s = document.getElementById('status');
  s.textContent = msg;
  s.style.color = col || '';
}
function setLog(msg) { document.getElementById('log').textContent = msg; }

function buildGrid(id, cells, clickable, isEnemy) {
  var g = document.getElementById(id);
  g.innerHTML = '';
  for (var i=0; i<100; i++) {
    var c = document.createElement('div');
    c.className = 'cell';
    c.dataset.i = i;
    var info = cells[i] || {};
    if (info.ship && !isEnemy) c.classList.add('ship');
    if (info.hit) c.classList.add(info.sunk ? 'sunk' : 'hit');
    else if (info.miss) c.classList.add('miss');
    if (clickable && !info.hit && !info.miss) {
      c.onclick = function(){ handleClick(parseInt(this.dataset.i)); };
    } else {
      c.classList.add('disabled');
    }
    g.appendChild(c);
  }
}

function computeCells(st) {
  var my = {}, opp = {};
  if (!st || !st.boards) return {my, opp};
  var myB = st.boards[role] || {};
  var oppRole = role==='p1'?'p2':'p1';
  var oppB = st.boards[oppRole] || {};

  (myB.ships||[]).forEach(function(ship){
    ship.cells.forEach(function(c){ my[c] = my[c]||{}; my[c].ship = true; });
  });
  (myB.shots||[]).forEach(function(c){
    my[c] = my[c]||{};
    if (oppB.ships && oppB.ships.some(function(s){return s.cells.indexOf(c)!==-1;})) my[c].hit=true; else my[c].miss=true;
  });

  // Own shots on opp board
  var oppShots = myB.shots || [];
  oppShots.forEach(function(c){
    opp[c] = opp[c]||{};
    if (oppB.ships && oppB.ships.some(function(s){return s.cells.indexOf(c)!==-1;})) opp[c].hit=true; else opp[c].miss=true;
  });

  return {my, opp};
}

function render(st) {
  if (!st) return;
  state = st;
  var oppRole = role==='p1'?'p2':'p1';
  document.getElementById('my-label').textContent = 'My Board';
  document.getElementById('opp-label').textContent = "Opponent's Board";

  if (st.phase === 'waiting') {
    setStatus('Waiting for opponent… Share: ' + location.href + (location.search?'':'?r='+room));
    buildGrid('my-grid', {}, false, false);
    buildGrid('opp-grid', {}, false, true);
    document.getElementById('setup-panel').style.display = 'none';
  } else if (st.phase === 'placing') {
    var myPlaced = (st.placed||[]).indexOf(role) !== -1;
    var oppPlaced = (st.placed||[]).indexOf(oppRole) !== -1;
    if (!myPlaced) {
      setStatus('Place your ships!');
      document.getElementById('setup-panel').style.display = 'block';
      renderPlacingGrid();
    } else {
      setStatus(oppPlaced ? 'Both placed! Starting…' : 'Ships placed — waiting for opponent…');
      document.getElementById('setup-panel').style.display = 'none';
      var cells = {};
      placedShips.forEach(function(s){ s.cells.forEach(function(c){ cells[c]={ship:true}; }); });
      buildGrid('my-grid', cells, false, false);
      buildGrid('opp-grid', {}, false, true);
    }
  } else if (st.phase === 'battle') {
    var myTurn = st.turn === role;
    setStatus(myTurn ? '🎯 Your turn — click a cell to fire!' : "⏳ Opponent's turn…", myTurn ? '#4a9eff' : '');
    document.getElementById('setup-panel').style.display = 'none';
    var cells = computeCells(st);
    buildGrid('my-grid', cells.my, false, false);
    buildGrid('opp-grid', cells.opp, myTurn, true);
  } else if (st.phase === 'done') {
    var won = st.winner === role;
    setStatus(won ? '🏆 You win!' : '💀 You lose!', won ? '#2ecc71' : '#e94560');
    document.getElementById('setup-panel').style.display = 'none';
    var cells = computeCells(st);
    buildGrid('my-grid', cells.my, false, false);
    buildGrid('opp-grid', cells.opp, false, true);
    document.getElementById('new-btn').style.display = 'inline-block';
    stopPoll();
  }
}

function renderPlacingGrid() {
  var g = document.getElementById('my-grid');
  g.innerHTML = '';
  var previewCells = getPreviewCells(hoveredCell);
  for (var i=0; i<100; i++) {
    var c = document.createElement('div');
    c.className = 'cell placing';
    c.dataset.i = i;
    var isShip = placedShips.some(function(s){ return s.cells.indexOf(i)!==-1; });
    if (isShip) c.classList.add('ship');
    else if (previewCells && previewCells.valid && previewCells.cells.indexOf(i)!==-1) c.classList.add('preview');
    else if (previewCells && !previewCells.valid && previewCells.cells.indexOf(i)!==-1) { c.classList.add('preview'); c.style.background='#4a1020'; }
    c.onclick = function(){ placeShip(parseInt(this.dataset.i)); };
    c.onmouseenter = function(){ hoveredCell=parseInt(this.dataset.i); renderPlacingGrid(); };
    g.appendChild(c);
  }
  renderShipTray();
}

var hoveredCell = -1;
function getPreviewCells(cell) {
  if (!selShip || cell<0) return null;
  var cells = [];
  var size = selShip.size;
  var row = Math.floor(cell/10), col = cell%10;
  if (selDir==='h') {
    if (col+size>10) return {cells:[], valid:false};
    for (var i=0;i<size;i++) cells.push(row*10+col+i);
  } else {
    if (row+size>10) return {cells:[], valid:false};
    for (var i=0;i<size;i++) cells.push((row+i)*10+col);
  }
  var conflict = cells.some(function(c){ return placedShips.some(function(s){ return s.cells.indexOf(c)!==-1; }); });
  return {cells:cells, valid:!conflict};
}

function placeShip(cell) {
  if (!selShip) return;
  var preview = getPreviewCells(cell);
  if (!preview || !preview.valid) return;
  placedShips.push({name:selShip.name, size:selShip.size, cells:preview.cells});
  var idx = SHIPS.indexOf(selShip);
  selShip = null;
  // Auto-select next unplaced
  for (var i=idx+1;i<SHIPS.length;i++) {
    if (!placedShips.some(function(s){return s.name===SHIPS[i].name;})) { selShip=SHIPS[i]; break; }
  }
  if (placedShips.length === SHIPS.length) {
    document.getElementById('setup-panel').style.display = 'none';
    api({act:'place', ships:placedShips}).then(function(r){ if(r.ok) render(r.state); });
  } else {
    renderPlacingGrid();
  }
}

function renderShipTray() {
  var t = document.getElementById('ships-tray');
  t.innerHTML = '';
  SHIPS.forEach(function(sh){
    var placed = placedShips.some(function(s){return s.name===sh.name;});
    var btn = document.createElement('button');
    btn.className = 'ship-btn' + (placed?' placed':'') + (selShip===sh?' selected':'');
    btn.textContent = sh.name + ' (' + sh.size + ')';
    if (!placed) btn.onclick = function(){ selShip=sh; renderPlacingGrid(); };
    t.appendChild(btn);
  });
}

function toggleDir() {
  selDir = selDir==='h' ? 'v' : 'h';
  document.getElementById('dir-btn').textContent = selDir==='h' ? '→ Horizontal' : '↓ Vertical';
  renderPlacingGrid();
}

function handleClick(cell) {
  if (!state || state.phase!=='battle' || state.turn!==role) return;
  api({act:'shoot', cell:cell}).then(function(r){
    if (r.ok) { render(r.state); if(r.hit) setLog('Hit!'); else setLog('Miss.'); }
  });
}

function startPoll() {
  if (pollTimer) return;
  pollTimer = setInterval(function(){
    api({act:'poll'}).then(function(r){
      if (r.ok && r.updated_at > lastUpdated) {
        lastUpdated = r.updated_at;
        role = r.role||role;
        render(r.state);
      }
    });
  }, 2000);
}
function stopPoll() { if(pollTimer){ clearInterval(pollTimer); pollTimer=null; } }

function newRoom() {
  api({act:'new_room'}).then(function(r){
    if (r.ok) {
      room = r.room; role = r.role;
      history.replaceState(null,'','?r='+room);
      placedShips = []; selShip = SHIPS[0];
      render({phase:'waiting',boards:{p1:{ships:[],shots:[]},p2:{ships:[],shots:[]}},placed:[],turn:'p1',winner:null});
      document.getElementById('new-btn').style.display='none';
      startPoll();
    }
  });
}

// Init
(function(){
  api({act:'join'}).then(function(r){
    if (!r.ok) { setStatus('Error joining game'); return; }
    room = r.room; role = r.role;
    history.replaceState(null,'','?r='+room);
    selShip = SHIPS[0];
    if (r.state) { lastUpdated = Date.now()/1000|0; render(r.state); }
    else render({phase:'waiting',boards:{p1:{ships:[],shots:[]},p2:{ships:[],shots:[]}},placed:[],turn:'p1',winner:null});
    setStatus(role==='p1' ? 'Waiting for opponent… Share this page link!' : (role==='p2'?'Joined!':'Spectating'));
    startPoll();
  });
})();
</script>
</body>
</html>
