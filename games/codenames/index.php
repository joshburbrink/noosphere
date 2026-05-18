<?php
require_once '/var/www/noosphere/shared/settings.php';
if (get_setting('show_games','0') !== '1') { http_response_code(404); exit; }

define('GAMES_DB', '/var/lib/noosphere/games.db');

$WORD_PACKS = [
  'emergency' => [
    'shelter','radio','water','fire','map','signal','rope','knife','tent','compass',
    'flare','rescue','storm','flood','power','fuel','medicine','bandage','splint','stretcher',
    'food','battery','generator','whistle','mirror','smoke','gate','bridge','road','tower',
    'creek','hill','barn','school','church','hospital','police','triage','code','net',
    'relay','channel','frequency','beacon','patrol','volunteer','command','resource','cache','drop',
    'supply','convoy','route','checkpoint','coordinate','grid','perimeter','sector','zone','ops',
    'post','base','field','mobile','unit','team','lead','shift','log','report',
    'alert','warning','staging','decon','ppe','hazmat','spill','leak','breach','dam',
    'scan','monitor','track','mark','flag','tag','pin','wrap','splint','pump',
    'county','district','township','trail','ford','ridge','hollow','bottoms','bluff','levee',
  ],
  'classic' => [
    'apple','bank','bar','bat','bolt','book','bow','box','cap','card',
    'cast','cat','cell','chair','change','charge','check','chip','club','cold',
    'cook','crane','cross','date','deal','deck','die','dog','door','dream',
    'drop','duck','ear','face','fall','fan','fight','file','fish','flag',
    'fly','foot','force','fork','frame','glass','glue','gold','hand','head',
    'heart','horn','ice','iron','jack','key','kick','king','knife','lamp',
    'leaf','light','line','lock','log','map','mark','match','mine','mole',
    'moon','mouse','nail','net','note','nut','palm','park','pass','patch',
    'pen','piano','pilot','pin','pipe','plane','plant','plate','play','plot',
    'point','pool','port','post','press','pump','queen','ring','rock','roll',
  ],
  'indiana' => [
    'columbus','nashville','bedford','martinsville','seymour','crothersville','brownstown','edinburgh',
    'greenwood','franklin','shelbyville','greensburg','madison','north-vernon','scottsburg','salem',
    'french-lick','paoli','mitchell','loogootee','washington','vincennes','terre-haute','linton',
    'bloomington','spencer','ellettsville','martinsville','mooresville','danville','plainfield',
    'crane','muscatatuck','atterbury','camp-atterbury','hoosier','covered-bridge','cardinal',
    'covered-bridge','peony','limestone','quarry','coal','corn','soybean','tomato','popcorn',
    '500','brickyard','speedway','colts','pacers','pacer','hoosier','purdue','notre-dame',
    'wabash','ohio','white-river','blue-river','patoka','deer','turkey','coyote','hawk',
    'brown-county','monroe-lake','hardy-lake','patoka-lake','brookville-lake','harmonie',
    'lincoln','mad-anthony','tecumseh','miami','potawatomi','shawnee','delaware',
    'covered-bridge','grist-mill','barn','silo','creek','bottom','flat','ridge','draw','ford',
  ],
  'wilderness' => [
    'acorn','antler','ash','aspen','badger','bark','beaver','birch','blaze','bluff',
    'bog','boulder','brook','buck','burrow','cache','canyon','cave','cedar','cliff',
    'coyote','creek','crow','current','dawn','deer','den','dew','doe','drift',
    'drought','dusk','eagle','eddy','elk','falls','fawn','fern','flint','flood',
    'fog','ford','frost','glen','gorge','grass','grove','gulch','hawk','hazel',
    'hollow','ice','inlet','ivy','jay','kelp','knoll','lake','ledge','lichen',
    'log','loon','meadow','mesa','mink','mist','moor','moss','mud','nest',
    'oak','otter','owl','pass','peat','pike','pine','pond','prey','quail',
    'ravine','reed','ridge','rill','river','robin','rock','root','rush','sage',
    'sedge','slate','slough','snare','snipe','spawn','spring','spruce','stone','swamp',
  ],
];

function get_word_pack($theme) {
    global $WORD_PACKS;
    return $WORD_PACKS[$theme] ?? $WORD_PACKS['emergency'];
}

$WORDS = $WORD_PACKS['emergency'];

function gdb() {
    static $db = null;
    if (!$db) {
        $db = new PDO('sqlite:' . GAMES_DB);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE IF NOT EXISTS codenames_rooms (
            id TEXT PRIMARY KEY,
            state TEXT NOT NULL,
            created_at INTEGER, updated_at INTEGER
        )");
    }
    return $db;
}

function room_get($id) {
    $r = gdb()->prepare('SELECT * FROM codenames_rooms WHERE id=?');
    $r->execute([$id]);
    $row = $r->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['state'] = json_decode($row['state'], true);
    return $row;
}

function room_save($id, $state) {
    $db = gdb();
    $ex = $db->prepare('SELECT id FROM codenames_rooms WHERE id=?');
    $ex->execute([$id]);
    if ($ex->fetch()) {
        $db->prepare('UPDATE codenames_rooms SET state=?, updated_at=? WHERE id=?')->execute([json_encode($state), time(), $id]);
    } else {
        $db->prepare('INSERT INTO codenames_rooms (id,state,created_at,updated_at) VALUES (?,?,?,?)')->execute([$id, json_encode($state), time(), time()]);
    }
}

function new_game_state($theme = 'emergency') {
    $pool = get_word_pack($theme);
    shuffle($pool);
    $words = array_slice($pool, 0, 25);
    // Colors: 9 red, 8 blue, 7 neutral, 1 assassin (red starts)
    $colors = array_merge(array_fill(0,9,'red'), array_fill(0,8,'blue'), array_fill(0,7,'neutral'), ['assassin']);
    shuffle($colors);
    return [
        'phase'    => 'lobby',
        'theme'    => $theme,
        'words'    => $words,
        'colors'   => $colors,
        'revealed' => array_fill(0, 25, false),
        'players'  => [],
        'turn'     => 'red',
        'clue'     => null,
        'remaining'=> ['red'=>9,'blue'=>8],
        'guesses_left' => 0,
        'winner'   => null,
        'log'      => [],
    ];
}

function log_entry(&$st, $msg) {
    $st['log'][] = $msg;
    if (count($st['log']) > 20) $st['log'] = array_slice($st['log'], -20);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $act  = $body['act'] ?? '';
    $room_id = preg_replace('/[^a-zA-Z0-9]/', '', $body['room'] ?? '');
    $pid  = preg_replace('/[^a-zA-Z0-9]/', '', $body['pid'] ?? '');
    $name = htmlspecialchars(substr($body['name'] ?? 'Player', 0, 20), ENT_QUOTES);

    $valid_themes = ['emergency','classic','indiana','wilderness'];
    $req_theme = in_array($body['theme']??'', $valid_themes, true) ? $body['theme'] : 'emergency';

    if ($act === 'new_room') {
        $room_id = substr(md5(uniqid('',true)),0,8);
        room_save($room_id, new_game_state($req_theme));
        echo json_encode(['ok'=>true,'room'=>$room_id]);
        exit;
    }

    if ($act === 'join') {
        if (!$room_id) {
            // Find open lobby
            $r = gdb()->query("SELECT id FROM codenames_rooms WHERE json_extract(state,'$.phase')='lobby' ORDER BY created_at DESC LIMIT 1");
            $row = $r->fetch(PDO::FETCH_ASSOC);
            if ($row) $room_id = $row['id'];
            else {
                $room_id = substr(md5(uniqid('',true)),0,8);
                room_save($room_id, new_game_state($req_theme));
            }
        }
        $room = room_get($room_id);
        if (!$room) { echo json_encode(['ok'=>false]); exit; }
        $st = $room['state'];
        if (!isset($st['players'][$pid])) {
            $st['players'][$pid] = ['name'=>$name,'role'=>null];
            room_save($room_id, $st);
        }
        echo json_encode(['ok'=>true,'room'=>$room_id,'state'=>filter_state($st,$pid),'updated_at'=>$room['updated_at']]);
        exit;
    }

    if ($act === 'set_role') {
        $room = room_get($room_id); if(!$room){echo json_encode(['ok'=>false]);exit;}
        $st = $room['state'];
        $valid_roles = ['spymaster_red','spymaster_blue','operative_red','operative_blue'];
        $new_role = in_array($body['role']??'',$valid_roles,true) ? $body['role'] : null;
        // prevent two spymasters of same color
        if ($new_role && str_starts_with($new_role,'spymaster')) {
            foreach ($st['players'] as $ppid => $p) {
                if ($ppid!==$pid && $p['role']===$new_role) { echo json_encode(['ok'=>false,'error'=>'Role taken']); exit; }
            }
        }
        $st['players'][$pid]['role'] = $new_role;
        $st['players'][$pid]['name'] = $name;
        room_save($room_id, $st);
        echo json_encode(['ok'=>true,'state'=>filter_state($st,$pid)]);
        exit;
    }

    if ($act === 'start') {
        $room = room_get($room_id); if(!$room){echo json_encode(['ok'=>false]);exit;}
        $st = $room['state'];
        // Need both spymasters
        $roles = array_column($st['players'],'role');
        if (!in_array('spymaster_red',$roles)||!in_array('spymaster_blue',$roles)) {
            echo json_encode(['ok'=>false,'error'=>'Need both spymasters to start']); exit;
        }
        $st['phase'] = 'playing';
        log_entry($st, 'Game started! Red goes first.');
        room_save($room_id, $st);
        echo json_encode(['ok'=>true,'state'=>filter_state($st,$pid)]);
        exit;
    }

    if ($act === 'give_clue') {
        $room = room_get($room_id); if(!$room){echo json_encode(['ok'=>false]);exit;}
        $st = $room['state'];
        $prole = $st['players'][$pid]['role'] ?? '';
        $expected_sm = 'spymaster_' . $st['turn'];
        if ($prole !== $expected_sm || $st['clue'] !== null || $st['phase']!=='playing') {
            echo json_encode(['ok'=>false]); exit;
        }
        $clue_word  = htmlspecialchars(substr(preg_replace('/[^a-zA-Z\-]/','',$body['clue']??''),0,20),ENT_QUOTES);
        $clue_count = max(0, min(9, (int)($body['count']??1)));
        if (!$clue_word) { echo json_encode(['ok'=>false,'error'=>'Enter a clue word']); exit; }
        $st['clue'] = ['word'=>$clue_word,'count'=>$clue_count,'by'=>$st['players'][$pid]['name']];
        $st['guesses_left'] = $clue_count + 1;
        log_entry($st, $st['players'][$pid]['name'].' clued: "'.$clue_word.'" for '.$clue_count);
        room_save($room_id, $st);
        echo json_encode(['ok'=>true,'state'=>filter_state($st,$pid)]);
        exit;
    }

    if ($act === 'guess') {
        $room = room_get($room_id); if(!$room){echo json_encode(['ok'=>false]);exit;}
        $st = $room['state'];
        $prole = $st['players'][$pid]['role'] ?? '';
        $expected_op = 'operative_' . $st['turn'];
        $idx = (int)($body['idx']??-1);
        if ($prole !== $expected_op || $st['clue']===null || $st['phase']!=='playing' || $idx<0||$idx>24||$st['revealed'][$idx]) {
            echo json_encode(['ok'=>false]); exit;
        }
        $st['revealed'][$idx] = true;
        $color = $st['colors'][$idx];
        $word  = $st['words'][$idx];
        log_entry($st, $st['players'][$pid]['name'].' guessed "'.$word.'" — '.$color);
        if ($color === 'assassin') {
            $st['phase'] = 'done';
            $st['winner'] = $st['turn']==='red' ? 'blue' : 'red';
            log_entry($st, 'ASSASSIN! '.ucfirst($st['winner']).' wins!');
        } elseif ($color === $st['turn']) {
            $st['remaining'][$color]--;
            if ($st['remaining'][$color] === 0) {
                $st['phase'] = 'done';
                $st['winner'] = $color;
                log_entry($st, ucfirst($color).' found all agents — '.ucfirst($color).' wins!');
            } else {
                $st['guesses_left']--;
                if ($st['guesses_left'] <= 0) {
                    $st['turn'] = $st['turn']==='red'?'blue':'red';
                    $st['clue'] = null;
                    log_entry($st, 'Out of guesses — '.ucfirst($st['turn']).' team\'s turn.');
                }
            }
        } else {
            // Wrong color or neutral — end turn
            if ($color !== 'neutral') $st['remaining'][$color]--;
            if (isset($st['remaining'][$color]) && $st['remaining'][$color]===0) {
                $st['phase'] = 'done';
                $st['winner'] = $color;
                log_entry($st, ucfirst($color).' found — '.ucfirst($color).' wins!');
            } else {
                $st['turn'] = $st['turn']==='red'?'blue':'red';
                $st['clue'] = null;
                log_entry($st, 'Wrong card — '.ucfirst($st['turn']).' team\'s turn.');
            }
        }
        room_save($room_id, $st);
        echo json_encode(['ok'=>true,'state'=>filter_state($st,$pid)]);
        exit;
    }

    if ($act === 'pass') {
        $room = room_get($room_id); if(!$room){echo json_encode(['ok'=>false]);exit;}
        $st = $room['state'];
        $prole = $st['players'][$pid]['role'] ?? '';
        if ($prole !== 'operative_'.$st['turn'] || $st['clue']===null || $st['phase']!=='playing') {
            echo json_encode(['ok'=>false]); exit;
        }
        $st['turn'] = $st['turn']==='red'?'blue':'red';
        $st['clue'] = null;
        log_entry($st, 'Passed — '.ucfirst($st['turn']).' team\'s turn.');
        room_save($room_id, $st);
        echo json_encode(['ok'=>true,'state'=>filter_state($st,$pid)]);
        exit;
    }

    if ($act === 'new_game') {
        $room = room_get($room_id); if(!$room){echo json_encode(['ok'=>false]);exit;}
        $old_players = $room['state']['players'];
        $old_theme   = $room['state']['theme'] ?? 'emergency';
        $use_theme   = in_array($body['theme']??'', $valid_themes, true) ? $body['theme'] : $old_theme;
        $st = new_game_state($use_theme);
        $st['players'] = $old_players;
        foreach ($st['players'] as &$p) $p['role'] = null;
        $st['phase'] = 'lobby';
        room_save($room_id, $st);
        echo json_encode(['ok'=>true,'state'=>filter_state($st,$pid)]);
        exit;
    }

    if ($act === 'poll') {
        $room = room_get($room_id); if(!$room){echo json_encode(['ok'=>false]);exit;}
        echo json_encode(['ok'=>true,'state'=>filter_state($room['state'],$pid),'updated_at'=>$room['updated_at']]);
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>'unknown action']);
    exit;
}

function filter_state($st, $pid) {
    $role = $st['players'][$pid]['role'] ?? 'spectator';
    $is_spymaster = str_starts_with($role ?? '', 'spymaster');
    if (!$is_spymaster && $st['phase'] !== 'done') {
        // Hide colors of unrevealed cards
        $filtered = $st['colors'];
        foreach ($filtered as $i => $c) {
            if (!$st['revealed'][$i]) $filtered[$i] = 'hidden';
        }
        $st['colors'] = $filtered;
    }
    $st['my_role'] = $role;
    return $st;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Codenames — Noosphere</title>
<?php require_once '/var/www/noosphere/shared/head.php'; ?>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:sans-serif;background:var(--bg,#1a1a2e);color:var(--text,#eee);min-height:100vh;display:flex;flex-direction:column;align-items:center;padding:1rem}
h1{color:var(--accent,#e94560);font-size:1.4rem;margin-bottom:.25rem}
.sub{color:var(--text-muted,#aaa);font-size:.85rem;margin-bottom:.75rem}
.status{background:var(--bg-card,#16213e);border:1px solid var(--border,#2a2a4a);border-radius:8px;padding:.5rem 1rem;font-size:.9rem;margin-bottom:.5rem;text-align:center;max-width:700px;width:100%}
.board{display:grid;grid-template-columns:repeat(5,1fr);gap:6px;max-width:700px;width:100%;margin-bottom:.75rem}
.card{padding:.6rem .4rem;border-radius:8px;text-align:center;font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;cursor:pointer;border:2px solid transparent;transition:.15s;min-height:3rem;display:flex;align-items:center;justify-content:center;word-break:break-word;user-select:none}
.card.hidden{background:var(--bg-card,#16213e);border-color:var(--border,#2a2a4a);color:var(--text,#eee)}
.card.red{background:#8b1a1a;border-color:#c0392b;color:#fff}
.card.blue{background:#1a2a8b;border-color:#2980b9;color:#fff}
.card.neutral{background:#3a3a2a;border-color:#5a5a3a;color:#ccc}
.card.assassin{background:#111;border-color:#444;color:#888}
.card.revealed-red{background:#e74c3c;border-color:#c0392b;color:#fff;cursor:default;opacity:.85}
.card.revealed-blue{background:#2980b9;border-color:#1a6fa0;color:#fff;cursor:default;opacity:.85}
.card.revealed-neutral{background:#7a7a5a;border-color:#5a5a3a;color:#eee;cursor:default;opacity:.75}
.card.revealed-assassin{background:#1a1a1a;border-color:#444;color:#888;cursor:default}
.card.clickable:hover{transform:scale(1.04);border-color:var(--accent2,#4a9eff)}
.card.clickable{cursor:pointer}
.panels{display:flex;gap:1rem;max-width:700px;width:100%;flex-wrap:wrap;margin-bottom:.5rem}
.panel{flex:1;min-width:200px;background:var(--bg-card,#16213e);border:1px solid var(--border,#2a2a4a);border-radius:8px;padding:.75rem}
.panel h3{font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-dim,#555);margin-bottom:.5rem}
.role-btn{display:block;width:100%;text-align:left;padding:.35rem .6rem;margin-bottom:.3rem;border-radius:5px;border:1px solid var(--border,#2a2a4a);background:transparent;color:var(--text,#eee);cursor:pointer;font-size:.82rem;transition:.1s}
.role-btn:hover{border-color:var(--accent2,#4a9eff)}
.role-btn.active-red{background:#8b1a1a;border-color:#e74c3c;color:#fff}
.role-btn.active-blue{background:#1a2a8b;border-color:#2980b9;color:#fff}
.clue-form{display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.4rem}
.clue-form input{flex:1;min-width:80px;background:var(--bg-input,#0d0d1a);border:1px solid var(--border,#2a2a4a);color:var(--text,#eee);border-radius:5px;padding:.3rem .6rem;font-size:.85rem}
.clue-form input[type=number]{width:52px;flex:none}
.btn{background:var(--accent,#e94560);color:#fff;border:none;border-radius:6px;padding:.4rem .9rem;font-size:.82rem;cursor:pointer;white-space:nowrap}
.btn:disabled{opacity:.4;cursor:default}
.btn-blue{background:#2980b9}
.btn-sec{background:var(--bg-deep,#0f0f1a);color:var(--text,#eee);border:1px solid var(--border,#2a2a4a)}
.log{font-size:.75rem;color:var(--text-muted,#aaa);max-height:7rem;overflow-y:auto}
.log p{padding:.15rem 0;border-bottom:1px solid var(--border,#2a2a4a)}
.remain{display:flex;gap:.75rem;justify-content:center;font-size:.9rem;font-weight:bold;margin-bottom:.4rem}
.remain .r{color:#e74c3c}.remain .b{color:#2980b9}
.name-form{display:flex;gap:.4rem;margin-bottom:.5rem}
.name-form input{flex:1;background:var(--bg-input,#0d0d1a);border:1px solid var(--border,#2a2a4a);color:var(--text,#eee);border-radius:5px;padding:.3rem .6rem;font-size:.85rem}
.back{margin-top:1rem;font-size:.82rem}
.back a{color:var(--text-dim,#555);text-decoration:none;border:1px solid var(--border,#2a2a4a);padding:4px 12px;border-radius:5px}
</style>
</head>
<body>
<h1>🕵️ Codenames</h1>
<div class="sub">2–8 players · Two teams · One word clues</div>
<div class="status" id="status">Connecting…</div>

<div id="name-area" style="max-width:700px;width:100%;margin-bottom:.5rem">
  <div class="name-form">
    <input id="name-in" placeholder="Your name" maxlength="20" value="">
    <button class="btn btn-sec" onclick="setName()">Set Name</button>
  </div>
</div>

<div class="remain" id="remain" style="display:none">
  <span class="r">🔴 <span id="rem-red">9</span></span>
  <span class="b">🔵 <span id="rem-blue">8</span></span>
</div>

<div class="board" id="board"></div>

<div class="panels" id="panels">
  <div class="panel" id="role-panel">
    <h3>Choose Role</h3>
    <button class="role-btn" data-role="spymaster_red" onclick="setRole(this)">🔴 Spymaster Red</button>
    <button class="role-btn" data-role="operative_red" onclick="setRole(this)">🔴 Operative Red</button>
    <button class="role-btn" data-role="spymaster_blue" onclick="setRole(this)">🔵 Spymaster Blue</button>
    <button class="role-btn" data-role="operative_blue" onclick="setRole(this)">🔵 Operative Blue</button>
  </div>
  <div class="panel" id="action-panel">
    <h3>Actions</h3>
    <div id="action-body" style="font-size:.85rem;color:var(--text-muted,#aaa)">Waiting for roles…</div>
  </div>
  <div class="panel">
    <h3>Log</h3>
    <div class="log" id="log-box"></div>
  </div>
</div>

<div style="text-align:center;margin-bottom:.5rem" id="ctrl-area"></div>

<details style="max-width:700px;width:100%;margin-top:.75rem;font-size:.82rem;color:var(--text-muted,#aaa);background:var(--bg-card,#16213e);border:1px solid var(--border,#2a2a4a);border-radius:8px;padding:.5rem .75rem">
  <summary style="cursor:pointer;color:var(--text,#eee);font-weight:bold;list-style:none">📖 How to Play</summary>
  <div style="margin-top:.6rem;line-height:1.6">
    <p><strong>Goal:</strong> Each team has a Spymaster and at least one Operative. Find all your team's agents before the other team finds theirs.</p>
    <p style="margin-top:.4rem"><strong>Roles:</strong></p>
    <ul style="margin:.3rem 0 .3rem 1.2rem">
      <li><strong>Spymaster</strong> — sees all card colors. Gives one-word clues + a number (how many cards the clue applies to).</li>
      <li><strong>Operative</strong> — sees the board. Guesses which cards match the clue by clicking them.</li>
    </ul>
    <p style="margin-top:.4rem"><strong>Taking a turn:</strong></p>
    <ol style="margin:.3rem 0 .3rem 1.2rem">
      <li>Spymaster gives a one-word clue and a count.</li>
      <li>Operatives discuss, then click cards to guess. You get count+1 guesses max.</li>
      <li>If you hit your color, keep guessing. Hit the wrong color or run out → your turn ends.</li>
    </ol>
    <p style="margin-top:.4rem"><strong>Special cards:</strong> ⬛ Assassin — if you guess it, your team <em>immediately loses</em>. Neutral cards just end your turn.</p>
    <p style="margin-top:.4rem"><strong>Win:</strong> Reveal all your agents first. Red has 9 cards, Blue has 8 — Red always goes first.</p>
    <p style="margin-top:.4rem"><strong>Word themes:</strong> Change the word pack in the lobby — Emergency (disaster ops), Classic (everyday nouns), Indiana (local places), Wilderness (nature).</p>
  </div>
</details>
<div class="back"><a href="/games/">← Games</a></div>

<script>
var pid = localStorage.getItem('cn_pid') || (Math.random().toString(36).slice(2)+Date.now().toString(36));
localStorage.setItem('cn_pid', pid);
var myName = localStorage.getItem('cn_name') || '';
var room = new URLSearchParams(location.search).get('r') || '';
var state = null;
var lastUpdated = 0;
var pollTimer = null;
var selectedTheme = localStorage.getItem('cn_theme') || 'emergency';
var THEMES = {emergency:'🚨 Emergency',classic:'📖 Classic',indiana:'🌽 Indiana',wilderness:'🌲 Wilderness'};

document.getElementById('name-in').value = myName;

function api(data) {
  data.pid = pid; data.name = myName||'Player';
  if (room) data.room = room;
  return fetch('', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data)}).then(r=>r.json());
}
function setStatus(msg, col){ var s=document.getElementById('status'); s.textContent=msg; s.style.color=col||''; }

function setName() {
  var v = document.getElementById('name-in').value.trim();
  if (!v) return;
  myName = v; localStorage.setItem('cn_name', v);
  if (room) api({act:'join'}).then(r=>{ if(r.ok) applyState(r.state,r.updated_at); });
}

function setRole(btn) {
  var r = btn.dataset.role;
  api({act:'set_role', role:r}).then(function(res){ if(res.ok) applyState(res.state); });
}

function buildBoard(st) {
  var b = document.getElementById('board');
  b.innerHTML = '';
  var myRole = st.my_role;
  var isOp = myRole === 'operative_' + st.turn;
  var canGuess = isOp && st.phase==='playing' && st.clue!==null;
  st.words.forEach(function(w, i){
    var c = document.createElement('div');
    c.className = 'card';
    var col = st.colors[i];
    if (st.revealed[i]) c.className += ' revealed-' + st.colors_real[i];
    else { c.className += ' ' + col; if(canGuess) c.className += ' clickable'; }
    c.textContent = w;
    if (canGuess && !st.revealed[i]) c.onclick = function(){ guess(i); };
    b.appendChild(c);
  });
  // Reveal actual colors in done state
  document.getElementById('remain').style.display = 'flex';
  document.getElementById('rem-red').textContent = st.remaining.red;
  document.getElementById('rem-blue').textContent = st.remaining.blue;
}

function buildActions(st) {
  var body = document.getElementById('action-body');
  var ctrl = document.getElementById('ctrl-area');
  ctrl.innerHTML = '';
  var myRole = st.my_role;
  if (st.phase === 'lobby') {
    var roles = Object.values(st.players).map(function(p){return p.role;});
    var hasBothSM = roles.indexOf('spymaster_red')!==-1 && roles.indexOf('spymaster_blue')!==-1;
    var curTheme = st.theme || 'emergency';
    var themeOpts = Object.entries(THEMES).map(function(kv){
      return '<option value="'+kv[0]+'"'+(kv[0]===curTheme?' selected':'')+'>'+kv[1]+'</option>';
    }).join('');
    body.innerHTML = '<div style="margin-bottom:.5rem"><label style="font-size:.8rem;color:var(--text-muted,#aaa)">Word theme: </label>'
      +'<select id="theme-sel" onchange="changeTheme(this.value)" style="background:var(--bg-input,#0d0d1a);color:var(--text,#eee);border:1px solid var(--border,#2a2a4a);border-radius:4px;padding:.2rem .4rem;font-size:.82rem">'+themeOpts+'</select></div>'
      +'Share link: <code style="font-size:.75rem;word-break:break-all">' + location.origin + '/games/codenames/?r=' + room + '</code>';
    if (hasBothSM) ctrl.innerHTML = '<button class="btn" onclick="startGame()">▶ Start Game</button>';
    else ctrl.innerHTML = '<span style="font-size:.82rem;color:var(--text-muted,#aaa)">Need both Spymasters to start</span>';
  } else if (st.phase === 'playing') {
    var isMySMTurn = myRole === 'spymaster_' + st.turn;
    var isMyOpTurn = myRole === 'operative_' + st.turn;
    if (st.clue === null) {
      if (isMySMTurn) {
        body.innerHTML = '<strong>Give a clue:</strong>';
        body.innerHTML += '<div class="clue-form"><input id="clue-w" placeholder="One word" maxlength="20"><input id="clue-n" type="number" min="1" max="9" value="2"><button class="btn'+(st.turn==='blue'?' btn-blue':'')+'" onclick="giveClue()">Send</button></div>';
      } else {
        body.textContent = (st.turn==='red'?'🔴':'🔵') + ' Spymaster is thinking…';
      }
    } else {
      var clue = st.clue;
      body.innerHTML = '<strong>Clue:</strong> "' + clue.word + '" for ' + clue.count + '<br><small style="color:var(--text-muted,#aaa)">by ' + clue.by + ' · ' + st.guesses_left + ' guess(es) left</small>';
      if (isMyOpTurn) ctrl.innerHTML = '<button class="btn btn-sec" onclick="passGuess()">Pass</button>';
    }
  } else if (st.phase === 'done') {
    body.textContent = '';
    ctrl.innerHTML = '<button class="btn" onclick="newGame()">🔄 New Game</button>';
  }
}

function buildPlayerList(st) {
  var rp = document.getElementById('role-panel');
  rp.innerHTML = '<h3>Players</h3>';
  var myRole = st.my_role;
  Object.entries(st.players).forEach(function(kv){
    var ppid = kv[0], p = kv[1];
    var roleLabel = p.role ? p.role.replace('_',' ').replace('red','🔴').replace('blue','🔵') : 'no role';
    var me = ppid===pid;
    rp.innerHTML += '<div style="font-size:.8rem;padding:.2rem 0;color:'+(me?'var(--accent2,#4a9eff)':'var(--text,#eee)')+'">'+p.name+' — '+roleLabel+'</div>';
  });
  if (st.phase === 'lobby') {
    rp.innerHTML += '<h3 style="margin-top:.5rem">Choose Role</h3>';
    var taken = Object.values(st.players).filter(function(p){return p.pid!==pid;}).map(function(p){return p.role;});
    ['spymaster_red','operative_red','spymaster_blue','operative_blue'].forEach(function(r){
      var active = myRole===r;
      var col = r.includes('red')?'red':'blue';
      var btn = document.createElement('button');
      btn.className = 'role-btn' + (active?' active-'+col:'');
      btn.dataset.role = r;
      btn.textContent = (col==='red'?'🔴':'🔵') + ' ' + r.replace('_',' ').replace('red','Red').replace('blue','Blue');
      btn.onclick = function(){ setRole(this); };
      rp.appendChild(btn);
    });
  }
}

function applyState(st, ts) {
  state = st;
  if (ts) lastUpdated = ts;
  var phase = st.phase;
  var myRole = st.my_role;
  var turn = st.turn;

  // Status
  if (phase==='lobby') setStatus('Lobby — choose a role and wait for others');
  else if (phase==='playing') {
    var myTurn = myRole && myRole.includes(turn);
    setStatus((turn==='red'?'🔴 Red':'🔵 Blue') + ' team\'s turn' + (myTurn?' — your move!':''), myTurn?(turn==='red'?'#e74c3c':'#2980b9'):'');
  }
  else if (phase==='done') setStatus((st.winner==='red'?'🔴 Red wins!':'🔵 Blue wins!'), st.winner==='red'?'#e74c3c':'#2980b9');

  // Board needs real colors for revealed cells — store in st
  if (!st.colors_real) st.colors_real = st.colors;

  buildBoard(st);
  buildPlayerList(st);
  buildActions(st);

  // Log
  var lb = document.getElementById('log-box');
  lb.innerHTML = '';
  (st.log||[]).slice().reverse().forEach(function(msg){ lb.innerHTML += '<p>'+msg+'</p>'; });
}

function guess(idx) {
  api({act:'guess',idx:idx}).then(function(r){ if(r.ok) applyState(r.state); });
}
function giveClue() {
  var w=document.getElementById('clue-w').value.trim(), n=document.getElementById('clue-n').value;
  if(!w) return;
  api({act:'give_clue',clue:w,count:parseInt(n)}).then(function(r){ if(r.ok) applyState(r.state); });
}
function passGuess() { api({act:'pass'}).then(function(r){ if(r.ok) applyState(r.state); }); }
function startGame() { api({act:'start'}).then(function(r){ if(r.ok) applyState(r.state); }); }
function newGame() { api({act:'new_game',theme:selectedTheme}).then(function(r){ if(r.ok) applyState(r.state); }); }
function changeTheme(t) {
  selectedTheme = t;
  localStorage.setItem('cn_theme', t);
  api({act:'new_game', theme:t}).then(function(r){ if(r.ok) applyState(r.state); });
}

function startPoll() {
  if (pollTimer) return;
  pollTimer = setInterval(function(){
    api({act:'poll'}).then(function(r){
      if (r.ok && r.updated_at > lastUpdated) { lastUpdated=r.updated_at; applyState(r.state,r.updated_at); }
    });
  }, 2000);
}

// Init
(function(){
  if (!myName) myName = 'Player' + Math.floor(Math.random()*99+1);
  document.getElementById('name-in').value = myName;
  api({act:'join'}).then(function(r){
    if (!r.ok) { setStatus('Error'); return; }
    room = r.room;
    history.replaceState(null,'','?r='+room);
    applyState(r.state, r.updated_at);
    startPoll();
  });
})();
</script>
</body>
</html>
