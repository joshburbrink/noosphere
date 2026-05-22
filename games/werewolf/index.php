<?php
// Werewolf  -  LAN multiplayer party game (phones on the local WiFi).
// Self-contained: POST JSON {act:...} hits this same file as the API.
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();
if (get_setting('show_games','1') !== '1') { http_response_code(404); exit; }

define('GAMES_DB', '/var/lib/noosphere/games.db');

function wdb(): PDO {
    static $db = null;
    if ($db) return $db;
    $db = new PDO('sqlite:' . GAMES_DB);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec("CREATE TABLE IF NOT EXISTS ww_games (
        id INTEGER PRIMARY KEY, code TEXT UNIQUE, host_token TEXT,
        state TEXT DEFAULT 'lobby', round INTEGER DEFAULT 0,
        log TEXT DEFAULT '[]', seer_results TEXT DEFAULT '{}',
        last_death TEXT DEFAULT '', winner TEXT DEFAULT '',
        created_at INTEGER, updated_at INTEGER)");
    $db->exec("CREATE TABLE IF NOT EXISTS ww_players (
        id INTEGER PRIMARY KEY, game_id INTEGER, token TEXT, name TEXT,
        role TEXT DEFAULT '', alive INTEGER DEFAULT 1,
        night_target TEXT DEFAULT '', day_vote TEXT DEFAULT '',
        joined_at INTEGER, last_seen INTEGER)");
    return $db;
}

function jbody(): array {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function out($a) { header('Content-Type: application/json'); echo json_encode($a); exit; }
function tok() { return bin2hex(random_bytes(16)); }
function now() { return time(); }

function game_by_code($code) {
    $s = wdb()->prepare("SELECT * FROM ww_games WHERE code=?");
    $s->execute([strtoupper(trim($code))]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}
function players($gid) {
    $s = wdb()->prepare("SELECT * FROM ww_players WHERE game_id=? ORDER BY id");
    $s->execute([$gid]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}
function me_in($gid, $token) {
    $s = wdb()->prepare("SELECT * FROM ww_players WHERE game_id=? AND token=?");
    $s->execute([$gid, $token]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}
function push_log(&$game, $msg) {
    $log = json_decode($game['log'] ?? '[]', true) ?: [];
    $log[] = $msg;
    $game['log'] = json_encode(array_slice($log, -40));
}
function touch_game($gid) { wdb()->prepare("UPDATE ww_games SET updated_at=? WHERE id=?")->execute([now(), $gid]); }

// ── Role assignment by player count ─────────────────────────────────────────
function assign_roles($plist) {
    $n = count($plist);
    $wolves = max(1, intdiv($n, 4));          // ~1 wolf per 4 players
    $special = [];
    if ($n >= 4) $special[] = 'seer';
    if ($n >= 6) $special[] = 'doctor';
    $roles = array_fill(0, $wolves, 'werewolf');
    foreach ($special as $sp) $roles[] = $sp;
    while (count($roles) < $n) $roles[] = 'villager';
    $roles = array_slice($roles, 0, $n);
    shuffle($roles);
    return $roles;
}

function count_alive_wolves($gid) {
    $s = wdb()->prepare("SELECT COUNT(*) FROM ww_players WHERE game_id=? AND alive=1 AND role='werewolf'");
    $s->execute([$gid]); return (int)$s->fetchColumn();
}
function count_alive_nonwolves($gid) {
    $s = wdb()->prepare("SELECT COUNT(*) FROM ww_players WHERE game_id=? AND alive=1 AND role!='werewolf'");
    $s->execute([$gid]); return (int)$s->fetchColumn();
}
function check_win(&$game) {
    $w = count_alive_wolves($game['id']);
    $v = count_alive_nonwolves($game['id']);
    if ($w === 0)      { $game['state']='ended'; $game['winner']='village'; push_log($game,'☀️ The last werewolf is gone. The village wins!'); return true; }
    if ($w >= $v)      { $game['state']='ended'; $game['winner']='wolves';  push_log($game,'🐺 The werewolves equal the villagers. The wolves win!'); return true; }
    return false;
}
function save_game($game) {
    wdb()->prepare("UPDATE ww_games SET state=?,round=?,log=?,seer_results=?,last_death=?,winner=?,updated_at=? WHERE id=?")
        ->execute([$game['state'],$game['round'],$game['log'],$game['seer_results'],$game['last_death'],$game['winner'],now(),$game['id']]);
}
function clear_actions($gid) {
    wdb()->prepare("UPDATE ww_players SET night_target='', day_vote='' WHERE game_id=?")->execute([$gid]);
}

// ── Night resolution ────────────────────────────────────────────────────────
function maybe_resolve_night(&$game) {
    $gid = $game['id'];
    $ps = players($gid);
    $aliveWolves = array_filter($ps, fn($p)=>$p['alive'] && $p['role']==='werewolf');
    $seer   = null; $doctor = null;
    foreach ($ps as $p) { if ($p['alive'] && $p['role']==='seer') $seer=$p; if ($p['alive'] && $p['role']==='doctor') $doctor=$p; }
    // all expected night actors must have acted
    foreach ($aliveWolves as $w) if ($w['night_target']==='') return false;
    if ($seer   && $seer['night_target']==='')   return false;
    if ($doctor && $doctor['night_target']==='') return false;
    resolve_night($game, $ps, $aliveWolves, $seer, $doctor);
    return true;
}
function resolve_night(&$game, $ps, $aliveWolves, $seer, $doctor) {
    $gid = $game['id'];
    // wolves: plurality target
    $votes = [];
    foreach ($aliveWolves as $w) if ($w['night_target']!=='') $votes[$w['night_target']] = ($votes[$w['night_target']]??0)+1;
    $victim = '';
    if ($votes) { arsort($votes); $top = max($votes); $cands = array_keys(array_filter($votes, fn($v)=>$v===$top)); $victim = $cands[array_rand($cands)]; }
    // seer learns
    if ($seer && $seer['night_target']!=='') {
        $tgt = null; foreach ($ps as $p) if ($p['id']==$seer['night_target']) $tgt=$p;
        if ($tgt) { $sr = json_decode($game['seer_results']??'{}',true)?:[]; $sr[$seer['id']] = ['name'=>$tgt['name'],'wolf'=>($tgt['role']==='werewolf')]; $game['seer_results']=json_encode($sr); }
    }
    // doctor save
    $saved = $doctor ? $doctor['night_target'] : '';
    $game['last_death'] = '';
    if ($victim !== '' && $victim !== $saved) {
        wdb()->prepare("UPDATE ww_players SET alive=0 WHERE id=? AND game_id=?")->execute([$victim,$gid]);
        $vn=''; foreach ($ps as $p) if ($p['id']==$victim) $vn=$p['name'];
        $game['last_death']=$vn; push_log($game, "🌅 Morning. ".$vn." was found dead.");
    } else {
        push_log($game, "🌅 Morning. Everyone survived the night.");
    }
    clear_actions($gid);
    if (!check_win($game)) { $game['state']='day'; }
}

// ── Day resolution (lynch) ──────────────────────────────────────────────────
function maybe_resolve_day(&$game, $force=false) {
    $gid=$game['id']; $ps=players($gid);
    $alive = array_filter($ps, fn($p)=>$p['alive']);
    foreach ($alive as $p) if (!$force && $p['day_vote']==='') return false;
    $votes=[];
    foreach ($alive as $p) if ($p['day_vote']!=='') $votes[$p['day_vote']] = ($votes[$p['day_vote']]??0)+1;
    if (!$votes) { clear_actions($gid); $game['state']='night'; $game['round']++; push_log($game,'🌙 No votes. Night falls again.'); return true; }
    arsort($votes); $top=max($votes); $cands=array_keys(array_filter($votes,fn($v)=>$v===$top));
    if (count($cands) > 1) {
        push_log($game, "⚖️ The vote is tied. No one is eliminated.");
    } else {
        $elim=$cands[0]; $en=''; $er='';
        foreach ($ps as $p) if ($p['id']==$elim){ $en=$p['name']; $er=$p['role']; }
        wdb()->prepare("UPDATE ww_players SET alive=0 WHERE id=? AND game_id=?")->execute([$elim,$gid]);
        push_log($game, "🗳️ ".$en." was voted out. They were a ".ucfirst($er).".");
    }
    clear_actions($gid);
    if (!check_win($game)) { $game['state']='night'; $game['round']++; push_log($game,'🌙 Night falls.'); }
    return true;
}

// ════════════════════ API ════════════════════
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $b = jbody(); $act = $b['act'] ?? '';
    try {
        if ($act==='create') {
            $name = trim($b['name'] ?? ''); if ($name==='') out(['ok'=>false,'error'=>'name required']);
            $code=''; for($i=0;$i<6;$i++){ $code=strtoupper(substr(bin2hex(random_bytes(3)),0,4)); if(!game_by_code($code)) break; }
            $ht=tok();
            wdb()->prepare("INSERT INTO ww_games (code,host_token,state,log,created_at,updated_at) VALUES (?,?,?,?,?,?)")
                ->execute([$code,$ht,'lobby',json_encode(['🪵 Lobby created. Share the code: '.$code]),now(),now()]);
            $gid=wdb()->lastInsertId();
            // host's player token IS the host_token, so host checks match
            wdb()->prepare("INSERT INTO ww_players (game_id,token,name,joined_at,last_seen) VALUES (?,?,?,?,?)")
                ->execute([$gid,$ht,substr($name,0,24),now(),now()]);
            out(['ok'=>true,'code'=>$code,'token'=>$ht,'host'=>true]);
        }
        if ($act==='join') {
            $g=game_by_code($b['code']??''); if(!$g) out(['ok'=>false,'error'=>'game not found']);
            if ($g['state']!=='lobby') out(['ok'=>false,'error'=>'game already started']);
            $name=trim($b['name']??''); if($name==='') out(['ok'=>false,'error'=>'name required']);
            $pt=tok();
            wdb()->prepare("INSERT INTO ww_players (game_id,token,name,joined_at,last_seen) VALUES (?,?,?,?,?)")
                ->execute([$g['id'],$pt,substr($name,0,24),now(),now()]);
            $gg=$g; push_log($gg,$name.' joined.'); save_game($gg);
            out(['ok'=>true,'code'=>$g['code'],'token'=>$pt,'host'=>false]);
        }

        // all other actions need a valid game + player
        $g=game_by_code($b['code']??''); if(!$g) out(['ok'=>false,'error'=>'game not found']);
        $me=me_in($g['id'], $b['token']??''); if(!$me) out(['ok'=>false,'error'=>'not in this game']);
        wdb()->prepare("UPDATE ww_players SET last_seen=? WHERE id=?")->execute([now(),$me['id']]);
        $isHost = ($b['token']??'')===$g['host_token'];

        if ($act==='start') {
            if (!$isHost) out(['ok'=>false,'error'=>'only host can start']);
            if ($g['state']!=='lobby') out(['ok'=>false,'error'=>'already started']);
            $ps=players($g['id']); if(count($ps)<3) out(['ok'=>false,'error'=>'need at least 3 players']);
            $roles=assign_roles($ps);
            foreach ($ps as $i=>$p) wdb()->prepare("UPDATE ww_players SET role=?,alive=1,night_target='',day_vote='' WHERE id=?")->execute([$roles[$i],$p['id']]);
            $gg=$g; $gg['state']='night'; $gg['round']=1; $gg['winner']=''; $gg['seer_results']='{}'; $gg['last_death']='';
            push_log($gg,'🌙 The first night falls. Roles have been dealt.');
            save_game($gg); out(['ok'=>true]);
        }
        if ($act==='night_action') {
            if ($g['state']!=='night') out(['ok'=>false,'error'=>'not night']);
            if (!$me['alive']) out(['ok'=>false,'error'=>'you are out']);
            if (!in_array($me['role'],['werewolf','seer','doctor'])) out(['ok'=>true]); // villagers idle
            $target=(string)($b['target']??'');
            wdb()->prepare("UPDATE ww_players SET night_target=? WHERE id=?")->execute([$target,$me['id']]);
            $gg=game_by_code($g['code']); maybe_resolve_night($gg); save_game($gg);
            out(['ok'=>true]);
        }
        if ($act==='day_vote') {
            if ($g['state']!=='day') out(['ok'=>false,'error'=>'not day']);
            if (!$me['alive']) out(['ok'=>false,'error'=>'you are out']);
            wdb()->prepare("UPDATE ww_players SET day_vote=? WHERE id=?")->execute([(string)($b['target']??''),$me['id']]);
            $gg=game_by_code($g['code']); maybe_resolve_day($gg); save_game($gg);
            out(['ok'=>true]);
        }
        if ($act==='force_day') { // host: resolve day now even if not all voted
            if (!$isHost) out(['ok'=>false,'error'=>'host only']);
            if ($g['state']==='day'){ $gg=game_by_code($g['code']); maybe_resolve_day($gg,true); save_game($gg); }
            out(['ok'=>true]);
        }
        if ($act==='restart') {
            if (!$isHost) out(['ok'=>false,'error'=>'host only']);
            wdb()->prepare("UPDATE ww_players SET role='',alive=1,night_target='',day_vote='' WHERE game_id=?")->execute([$g['id']]);
            $gg=$g; $gg['state']='lobby'; $gg['round']=0; $gg['winner']=''; $gg['seer_results']='{}'; $gg['last_death']='';
            $gg['log']=json_encode(['🔄 New game. Same players. Code: '.$g['code']]); save_game($gg);
            out(['ok'=>true]);
        }
        if ($act==='poll') {
            // auto-resolve guards (in case a player dropped)
            if ($g['state']==='night'){ $gg=game_by_code($g['code']); if(maybe_resolve_night($gg)) save_game($gg); $g=game_by_code($g['code']); }
            $ps=players($g['id']); $me=me_in($g['id'],$b['token']);
            $pub=array_map(fn($p)=>['id'=>$p['id'],'name'=>$p['name'],'alive'=>(int)$p['alive']], $ps);
            $resp=[
                'ok'=>true,'state'=>$g['state'],'round'=>(int)$g['round'],'code'=>$g['code'],
                'you'=>['id'=>$me['id'],'name'=>$me['name'],'role'=>$me['role'],'alive'=>(int)$me['alive'],
                        'acted_night'=>$me['night_target']!=='','voted_day'=>$me['day_vote']!==''],
                'host'=>$isHost,'players'=>$pub,'log'=>json_decode($g['log']??'[]',true),
                'winner'=>$g['winner'],
            ];
            // wolves see each other
            if ($me['role']==='werewolf') $resp['wolves']=array_values(array_map(fn($p)=>$p['name'], array_filter($ps,fn($p)=>$p['role']==='werewolf')));
            // seer result
            if ($me['role']==='seer'){ $sr=json_decode($g['seer_results']??'{}',true)?:[]; if(isset($sr[$me['id']])) $resp['seer_result']=$sr[$me['id']]; }
            // day tally
            if ($g['state']==='day'){ $t=[]; foreach($ps as $p) if($p['alive']&&$p['day_vote']!=='') $t[$p['day_vote']]=($t[$p['day_vote']]??0)+1; $resp['tally']=$t; }
            if ($g['state']==='ended'){ $resp['reveal']=array_map(fn($p)=>['name'=>$p['name'],'role'=>$p['role']], $ps); }
            out($resp);
        }
        out(['ok'=>false,'error'=>'unknown action']);
    } catch (Throwable $e) { out(['ok'=>false,'error'=>'server error']); }
}
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Werewolf  -  Noosphere</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#0f0f1a;color:#e6e6ee;min-height:100vh;padding:14px;max-width:600px;margin:0 auto}
h1{font-size:20px;color:#b07cff;margin-bottom:4px}
.sub{color:#888;font-size:12px;margin-bottom:14px}
.card{background:#1a1a2e;border:1px solid #2a2a4a;border-radius:10px;padding:14px;margin-bottom:12px}
input,button,select{font-size:16px;font-family:inherit}
input{width:100%;background:#10101e;border:1px solid #2a2a4a;color:#fff;border-radius:7px;padding:10px;margin:6px 0}
button{background:#7a3cff;color:#fff;border:none;border-radius:7px;padding:11px 14px;cursor:pointer;font-weight:600;width:100%;margin-top:6px}
button.sec{background:#23233e}
button.danger{background:#c0392b}
button:disabled{opacity:.5}
.code{font-size:28px;letter-spacing:4px;color:#b07cff;font-weight:700;text-align:center;font-family:monospace}
.role{font-size:22px;font-weight:700;text-align:center;padding:10px 0}
.role.werewolf{color:#e8503a}.role.seer{color:#3aa0e8}.role.doctor{color:#2ecc71}.role.villager{color:#ccc}
.plist{list-style:none}
.plist li{padding:8px 10px;border-radius:6px;background:#15152a;margin:4px 0;display:flex;justify-content:space-between;align-items:center}
.plist li.dead{opacity:.45;text-decoration:line-through}
.tag{font-size:11px;color:#888}
.log{font-size:13px;color:#bbb;max-height:170px;overflow:auto}
.log div{padding:3px 0;border-bottom:1px solid #ffffff0d}
.tgt{background:#15152a;border:1px solid #2a2a4a;border-radius:7px;padding:9px;margin:4px 0;cursor:pointer;display:flex;justify-content:space-between}
.tgt.sel{border-color:#7a3cff;background:#241a3a}
.err{color:#e8503a;font-size:13px;margin-top:6px;min-height:16px}
.banner{text-align:center;font-size:15px;padding:8px;border-radius:7px;margin-bottom:10px}
.night{background:#161636;color:#9aa}.day{background:#3a3320;color:#f3d27a}.ended{background:#1f2f1f;color:#9de89d}
</style></head><body>
<h1>🐺 Werewolf <button onclick="toggleRules()" style="float:right;width:auto;padding:6px 12px;font-size:13px;margin:0;background:#23233e">📖 Rules</button></h1>
<div class="sub">Local party game  -  everyone on this WiFi, phones in hand. A narrator helps, but the app runs the game.</div>
<div id="rules" class="card" style="display:none">
  <b>How to play</b>
  <p style="font-size:13px;color:#cfcfe0;margin:8px 0">A hidden team of <b style="color:#e8503a">Werewolves</b> is mixed into the <b style="color:#2ecc71">Village</b>. Each round has a <b>Night</b> and a <b>Day</b>.</p>
  <ul style="font-size:13px;color:#bbb;margin:0 0 8px 18px;line-height:1.5">
    <li><b>🌙 Night:</b> everyone "sleeps." On their phones, the wolves secretly pick someone to eliminate, the Seer inspects one person, the Doctor protects one person.</li>
    <li><b>☀️ Day:</b> the app reveals who (if anyone) died overnight. Everyone talks it out in person, then votes on their phone to eliminate a suspect.</li>
    <li>Repeat until one side wins.</li>
  </ul>
  <b>Roles</b>
  <ul style="font-size:13px;color:#bbb;margin:6px 0 8px 18px;line-height:1.55">
    <li><b style="color:#e8503a">🐺 Werewolf</b> - knows the other wolves. Each night the pack picks one victim. Win when wolves equal the villagers. Blend in by day!</li>
    <li><b style="color:#3aa0e8">🔮 Seer</b> - each night, inspect one player to learn if they are a werewolf. Guide the village without exposing yourself.</li>
    <li><b style="color:#2ecc71">⚕️ Doctor</b> - each night, protect one player (even yourself). If the wolves target them, they survive.</li>
    <li><b style="color:#ccc">🧑‍🌾 Villager</b> - no special power. Use discussion and the day vote to root out the wolves.</li>
  </ul>
  <b>Winning</b>
  <p style="font-size:13px;color:#cfcfe0;margin:6px 0"><b style="color:#2ecc71">Village</b> wins when every werewolf is gone. <b style="color:#e8503a">Wolves</b> win when they equal the number of remaining villagers.</p>
  <div class="tag">Tip: a narrator can read the night/day aloud, but the app tracks roles, deaths and votes for you.</div>
  <button class="sec" onclick="toggleRules()">Close</button>
</div>
<div id="app"></div>
<div class="err" id="err"></div>

<script>
var S={code:null,token:null,host:false,sel:null,timer:null};
function $(id){return document.getElementById(id)}
function api(d){return fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)}).then(r=>r.json())}
function err(m){$('err').textContent=m||''}
function toggleRules(){var r=$('rules');r.style.display=r.style.display==='none'?'block':'none'}
function save(){try{localStorage.setItem('ww',JSON.stringify({code:S.code,token:S.token,host:S.host}))}catch(e){}}
function load(){try{var d=JSON.parse(localStorage.getItem('ww')||'{}');if(d.code){S.code=d.code;S.token=d.token;S.host=d.host}}catch(e){}}

function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}

function home(){
  stopPoll();
  $('app').innerHTML=
    '<div class="card"><b>New game</b><input id="cname" placeholder="Your name" maxlength="24">'+
    '<button onclick="doCreate()">Create game</button></div>'+
    '<div class="card"><b>Join a game</b><input id="jcode" placeholder="Game code (e.g. A1B2)" maxlength="6" style="text-transform:uppercase">'+
    '<input id="jname" placeholder="Your name" maxlength="24"><button class="sec" onclick="doJoin()">Join</button></div>';
}
function doCreate(){var n=$('cname').value.trim();if(!n)return err('Enter a name');err('');api({act:'create',name:n}).then(r=>{if(!r.ok)return err(r.error);S.code=r.code;S.token=r.token;S.host=true;save();startPoll()})}
function doJoin(){var c=$('jcode').value.trim(),n=$('jname').value.trim();if(!c||!n)return err('Code and name');err('');api({act:'join',code:c,name:n}).then(r=>{if(!r.ok)return err(r.error);S.code=r.code;S.token=r.token;S.host=false;save();startPoll()})}
function leave(){localStorage.removeItem('ww');S.code=null;S.token=null;home()}

function render(st){
  if(!st||!st.ok){return}
  var h='';
  if(st.state==='lobby'){
    h+='<div class="card"><div class="sub">Game code</div><div class="code">'+esc(st.code)+'</div></div>';
    h+='<div class="card"><b>Players ('+st.players.length+')</b><ul class="plist">'+
       st.players.map(p=>'<li><span>'+esc(p.name)+'</span></li>').join('')+'</ul>';
    if(st.host){h+='<button onclick="act({act:\'start\'})">Start game ('+st.players.length+' players)</button><div class="tag" style="margin-top:6px">Need 3+. Roles auto-assigned: ~1 wolf per 4, plus Seer (4+) and Doctor (6+).</div>';}
    else{h+='<div class="sub">Waiting for the host to start…</div>';}
    h+='<button class="sec" onclick="leave()">Leave</button></div>';
    $('app').innerHTML=h;return;
  }
  // banner
  var bn=st.state==='night'?'night':(st.state==='day'?'day':'ended');
  var bt=st.state==='night'?('🌙 Night '+st.round):(st.state==='day'?('☀️ Day '+st.round):'🏁 Game over');
  h+='<div class="banner '+bn+'">'+bt+'</div>';

  // your role
  var alive=st.you.alive;
  h+='<div class="card"><div class="role '+st.you.role+'">'+roleLabel(st.you.role)+(alive?'':' (out)')+'</div>';
  if(st.you.role==='werewolf'&&st.wolves)h+='<div class="sub" style="text-align:center">Pack: '+st.wolves.map(esc).join(', ')+'</div>';
  if(st.you.role==='seer'&&st.seer_result)h+='<div class="sub" style="text-align:center">🔮 '+esc(st.seer_result.name)+' is '+(st.seer_result.wolf?'<b style="color:#e8503a">a Werewolf</b>':'<b style="color:#2ecc71">not a wolf</b>')+'</div>';
  h+='</div>';

  if(st.state==='ended'){
    h+='<div class="card"><b>'+(st.winner==='wolves'?'🐺 Werewolves win!':'☀️ Village wins!')+'</b><ul class="plist">'+
      st.reveal.map(p=>'<li><span>'+esc(p.name)+'</span><span class="tag">'+roleLabel(p.role)+'</span></li>').join('')+'</ul>';
    if(st.host)h+='<button onclick="act({act:\'restart\'})">Play again (same players)</button>';
    h+='<button class="sec" onclick="leave()">Leave</button></div>';
    $('app').innerHTML=h;return;
  }

  // action area
  var targets=st.players.filter(p=>p.alive && p.id!==st.you.id);
  if(st.state==='night'){
    if(!alive){h+='<div class="card sub">You are out. Watch the chaos unfold. 👻</div>';}
    else if(st.you.role==='werewolf'){h+=pickCard('Choose a victim',targets,st.you.acted_night,'night_action');}
    else if(st.you.role==='seer'){h+=pickCard('Inspect someone',targets,st.you.acted_night,'night_action');}
    else if(st.you.role==='doctor'){h+=pickCard('Protect someone (can be yourself)',st.players.filter(p=>p.alive),st.you.acted_night,'night_action');}
    else{h+='<div class="card sub">😴 The village sleeps. Waiting for the night to pass…</div>';}
  } else if(st.state==='day'){
    if(!alive){h+='<div class="card sub">You are out. 👻</div>';}
    else{h+=pickCard('Vote to eliminate',targets,st.you.voted_day,'day_vote',st.tally);}
    if(st.host)h+='<button class="danger" onclick="act({act:\'force_day\'})" style="margin-top:6px">Force resolve vote (host)</button>';
  }

  // players + log
  h+='<div class="card"><b>Players</b><ul class="plist">'+st.players.map(p=>
    '<li class="'+(p.alive?'':'dead')+'"><span>'+esc(p.name)+(p.id===st.you.id?' (you)':'')+'</span><span class="tag">'+(p.alive?'alive':'out')+'</span></li>').join('')+'</ul></div>';
  h+='<div class="card"><b>Story</b><div class="log">'+st.log.slice().reverse().map(l=>'<div>'+esc(l)+'</div>').join('')+'</div></div>';
  $('app').innerHTML=h;
}
function roleLabel(r){return {werewolf:'🐺 Werewolf',seer:'🔮 Seer',doctor:'⚕️ Doctor',villager:'🧑‍🌾 Villager'}[r]||'…'}
function pickCard(title,list,done,action,tally){
  if(done)return '<div class="card"><b>'+title+'</b><div class="sub" style="margin-top:6px">✅ Choice locked in. Waiting for others…</div></div>';
  var h='<div class="card"><b>'+title+'</b>';
  h+=list.map(p=>'<div class="tgt'+(S.sel==p.id?' sel':'')+'" onclick="S.sel='+p.id+';render(window._st)">'+
     '<span>'+esc(p.name)+'</span>'+(tally&&tally[p.id]?'<span class="tag">'+tally[p.id]+' vote(s)</span>':'')+'</div>').join('');
  h+='<button onclick="if(S.sel){act({act:\''+action+'\',target:S.sel});S.sel=null}else err(\'Pick someone\')">Confirm</button></div>';
  return h;
}
function act(d){d.code=S.code;d.token=S.token;err('');api(d).then(r=>{if(!r.ok)return err(r.error);poll()})}
function poll(){if(!S.code)return;api({act:'poll',code:S.code,token:S.token}).then(r=>{if(!r.ok){if(r.error==='not in this game'||r.error==='game not found'){leave();}return}window._st=r;render(r)})}
function startPoll(){stopPoll();poll();S.timer=setInterval(poll,2500)}
function stopPoll(){if(S.timer){clearInterval(S.timer);S.timer=null}}

load();
if(S.code){startPoll()}else{home()}
</script>
</body></html>
