<?php
// Polls / Voting  -  lightweight community polls. Self-contained: POST JSON
// {act:...} hits this same file as the API. No account needed; one vote per
// device (token in localStorage). Creator or admin can close/delete.
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
require_once '/var/www/noosphere/shared/identity.php';
sec_session_start();
if (get_setting('show_vote','1') !== '1') { http_response_code(404); exit; }

$IS_ADMIN   = legacy_is_admin();
$IS_RO      = is_readonly();

function vdb(): PDO {
    static $db = null;
    if ($db) return $db;
    $db = new PDO('sqlite:/var/lib/noosphere/vote.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec("CREATE TABLE IF NOT EXISTS polls (
        id INTEGER PRIMARY KEY, question TEXT NOT NULL, type TEXT DEFAULT 'single',
        options TEXT NOT NULL, creator_token TEXT, hide_results INTEGER DEFAULT 0,
        closed INTEGER DEFAULT 0, created_at INTEGER)");
    $db->exec("CREATE TABLE IF NOT EXISTS votes (
        id INTEGER PRIMARY KEY, poll_id INTEGER, voter_token TEXT,
        choices TEXT, created_at INTEGER)");
    $db->exec("CREATE INDEX IF NOT EXISTS v_poll ON votes(poll_id)");
    return $db;
}
function vout($a){ header('Content-Type: application/json'); echo json_encode($a); exit; }
function vbody(): array { $j=json_decode(file_get_contents('php://input'),true); return is_array($j)?$j:[]; }

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if ($IS_RO) vout(['ok'=>false,'error'=>'instance is read-only']);
    $b=vbody(); $act=$b['act']??''; $vtok=substr((string)($b['vtoken']??''),0,64);
    try {
        if ($act==='create') {
            $q=trim($b['question']??''); if($q==='') vout(['ok'=>false,'error'=>'question required']);
            $type=in_array($b['type']??'',['single','multi','yesno'])?$b['type']:'single';
            if ($type==='yesno') { $opts=['Yes','No']; }
            else {
                $opts=array_values(array_filter(array_map(fn($o)=>trim((string)$o),(array)($b['options']??[])), fn($o)=>$o!==''));
                if (count($opts)<2) vout(['ok'=>false,'error'=>'need at least 2 options']);
                $opts=array_slice($opts,0,12);
            }
            $hide=!empty($b['hide_results'])?1:0;
            $s=vdb()->prepare("INSERT INTO polls (question,type,options,creator_token,hide_results,closed,created_at) VALUES (?,?,?,?,?,0,?)");
            $s->execute([substr($q,0,300),$type,json_encode($opts),$vtok,$hide,time()]);
            vout(['ok'=>true,'id'=>vdb()->lastInsertId()]);
        }
        if ($act==='vote') {
            $pid=(int)($b['poll_id']??0); $sel=array_map('intval',(array)($b['choices']??[]));
            $p=vdb()->prepare("SELECT * FROM polls WHERE id=?"); $p->execute([$pid]); $poll=$p->fetch(PDO::FETCH_ASSOC);
            if(!$poll) vout(['ok'=>false,'error'=>'poll not found']);
            if($poll['closed']) vout(['ok'=>false,'error'=>'poll is closed']);
            $nopt=count(json_decode($poll['options'],true));
            $sel=array_values(array_unique(array_filter($sel,fn($i)=>$i>=0&&$i<$nopt)));
            if(!$sel) vout(['ok'=>false,'error'=>'pick an option']);
            if($poll['type']!=='multi') $sel=[$sel[0]];
            // one vote per device
            $c=vdb()->prepare("SELECT COUNT(*) FROM votes WHERE poll_id=? AND voter_token=?"); $c->execute([$pid,$vtok]);
            if($c->fetchColumn()>0) vout(['ok'=>false,'error'=>'you already voted']);
            vdb()->prepare("INSERT INTO votes (poll_id,voter_token,choices,created_at) VALUES (?,?,?,?)")
                ->execute([$pid,$vtok,json_encode($sel),time()]);
            vout(['ok'=>true]);
        }
        // manage actions: creator (by token) or admin
        if (in_array($act,['close','reopen','delete'])) {
            $pid=(int)($b['poll_id']??0);
            $p=vdb()->prepare("SELECT * FROM polls WHERE id=?"); $p->execute([$pid]); $poll=$p->fetch(PDO::FETCH_ASSOC);
            if(!$poll) vout(['ok'=>false,'error'=>'poll not found']);
            if(!$IS_ADMIN && $poll['creator_token']!==$vtok) vout(['ok'=>false,'error'=>'not your poll']);
            if($act==='close')  vdb()->prepare("UPDATE polls SET closed=1 WHERE id=?")->execute([$pid]);
            if($act==='reopen') vdb()->prepare("UPDATE polls SET closed=0 WHERE id=?")->execute([$pid]);
            if($act==='delete'){ vdb()->prepare("DELETE FROM votes WHERE poll_id=?")->execute([$pid]); vdb()->prepare("DELETE FROM polls WHERE id=?")->execute([$pid]); }
            vout(['ok'=>true]);
        }
        if ($act==='list') {
            $polls=vdb()->query("SELECT * FROM polls ORDER BY closed ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
            $out=[];
            foreach($polls as $poll){
                $pid=$poll['id']; $opts=json_decode($poll['options'],true);
                $vc=vdb()->prepare("SELECT choices FROM votes WHERE poll_id=?"); $vc->execute([$pid]);
                $counts=array_fill(0,count($opts),0); $total=0;
                $mine=vdb()->prepare("SELECT choices FROM votes WHERE poll_id=? AND voter_token=?"); $mine->execute([$pid,$vtok]);
                $myrow=$mine->fetch(PDO::FETCH_ASSOC); $myvote=$myrow?json_decode($myrow['choices'],true):null;
                foreach($vc as $row){ $total++; foreach(json_decode($row['choices'],true) as $i){ if(isset($counts[$i])) $counts[$i]++; } }
                $voted=$myvote!==null;
                $reveal = $voted || $poll['closed'] || !$poll['hide_results'] || $IS_ADMIN;
                $out[]=[
                    'id'=>$pid,'question'=>$poll['question'],'type'=>$poll['type'],'options'=>$opts,
                    'closed'=>(int)$poll['closed'],'hide_results'=>(int)$poll['hide_results'],
                    'total'=>$total,'voted'=>$voted,'my'=>$myvote,
                    'counts'=>$reveal?$counts:null,'reveal'=>$reveal,
                    'mine'=>($poll['creator_token']===$vtok)||$IS_ADMIN,
                ];
            }
            vout(['ok'=>true,'polls'=>$out,'admin'=>$IS_ADMIN]);
        }
        vout(['ok'=>false,'error'=>'unknown action']);
    } catch (Throwable $e){ vout(['ok'=>false,'error'=>'server error']); }
}
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Polls  -  Noosphere</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#0f0f1a;color:#e6e6ee;min-height:100vh;padding:14px;max-width:640px;margin:0 auto}
a.back{color:#888;text-decoration:none;font-size:13px}
h1{font-size:20px;color:#4aa3ff;margin:6px 0 2px}
.sub{color:#888;font-size:12px;margin-bottom:14px}
.card{background:#1a1a2e;border:1px solid #2a2a4a;border-radius:10px;padding:14px;margin-bottom:12px}
.card.closed{opacity:.7}
input,button,select,textarea{font-size:16px;font-family:inherit}
input,textarea,select{width:100%;background:#10101e;border:1px solid #2a2a4a;color:#fff;border-radius:7px;padding:10px;margin:5px 0}
button{background:#2d7dff;color:#fff;border:none;border-radius:7px;padding:11px 14px;cursor:pointer;font-weight:600;margin-top:6px}
button.sec{background:#23233e}button.ghost{background:none;border:1px solid #2a2a4a;color:#9aa;font-size:12px;padding:6px 10px;width:auto;margin:0}
button.full{width:100%}
.q{font-size:16px;font-weight:600;margin-bottom:8px}
.opt{display:flex;align-items:center;gap:9px;background:#15152a;border:1px solid #2a2a4a;border-radius:7px;padding:10px;margin:5px 0;cursor:pointer}
.opt.sel{border-color:#2d7dff;background:#16294a}
.opt input{width:auto;margin:0}
.bar{position:relative;background:#15152a;border-radius:7px;overflow:hidden;margin:5px 0;height:34px;display:flex;align-items:center}
.bar .fill{position:absolute;left:0;top:0;bottom:0;background:#1f3a66}
.bar .lab{position:relative;padding:0 10px;font-size:14px;z-index:1;display:flex;justify-content:space-between;width:100%}
.bar.win .fill{background:#2d5fa6}
.tag{font-size:11px;color:#888}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.err{color:#e8503a;font-size:13px;min-height:16px;margin:6px 0}
.opt-edit{display:flex;gap:6px}.opt-edit input{margin:3px 0}
</style></head><body>
<a class="back" href="/">&larr; Home</a>
<h1>🗳️ Polls</h1>
<div class="sub">Quick community votes  -  no account needed, one vote per device.</div>
<div class="err" id="err"></div>

<div class="card">
  <button class="full" onclick="toggleNew()" id="newbtn">➕ New poll</button>
  <div id="newform" style="display:none;margin-top:8px">
    <input id="q" placeholder="Your question" maxlength="300">
    <select id="ptype" onchange="renderOpts()">
      <option value="single">Single choice (pick one)</option>
      <option value="multi">Multiple choice (pick any)</option>
      <option value="yesno">Yes / No</option>
    </select>
    <div id="opts"></div>
    <label class="row tag" style="margin-top:6px"><input type="checkbox" id="hide" style="width:auto;margin:0"> Hide results until a person votes</label>
    <button class="full" onclick="createPoll()">Create poll</button>
  </div>
</div>

<div id="list"></div>

<script>
var VT = (function(){ try{var t=localStorage.getItem('vtoken'); if(!t){t=(Date.now().toString(36)+Math.random().toString(36).slice(2,12)); localStorage.setItem('vtoken',t);} return t;}catch(e){return 'anon'+Math.random()} })();
var sel={};
function $(i){return document.getElementById(i)}
function api(d){d.vtoken=VT;return fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)}).then(r=>r.json())}
function err(m){$('err').textContent=m||''}
function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}

function toggleNew(){var f=$('newform');f.style.display=f.style.display==='none'?'block':'none';if(f.style.display==='block')renderOpts()}
function renderOpts(){
  var t=$('ptype').value, c=$('opts');
  if(t==='yesno'){c.innerHTML='<div class="tag">Options: Yes / No</div>';return}
  if(!c.dataset.init){c.innerHTML='';for(var i=0;i<3;i++)addOpt();c.dataset.init='1'}
}
function addOpt(){var d=document.createElement('div');d.className='opt-edit';d.innerHTML='<input placeholder="Option" maxlength="120">';$('opts').appendChild(d)}
function createPoll(){
  var q=$('q').value.trim(); if(!q)return err('Enter a question');
  var t=$('ptype').value, opts=[];
  if(t!=='yesno')opts=[].slice.call($('opts').querySelectorAll('input')).map(i=>i.value.trim()).filter(Boolean);
  err('');
  api({act:'create',question:q,type:t,options:opts,hide_results:$('hide').checked?1:0}).then(r=>{
    if(!r.ok)return err(r.error);
    $('q').value='';$('opts').innerHTML='';$('opts').dataset.init='';toggleNew();load();
  });
}
function pick(pid,i,type){
  if(type==='multi'){sel[pid]=sel[pid]||{};sel[pid][i]=!sel[pid][i];}
  else{sel[pid]={};sel[pid][i]=true;}
  render(window._p);
}
function submitVote(pid,type){
  var chosen=Object.keys(sel[pid]||{}).filter(i=>sel[pid][i]).map(Number);
  if(!chosen.length)return err('Pick an option');
  err('');api({act:'vote',poll_id:pid,choices:chosen}).then(r=>{if(!r.ok)return err(r.error);delete sel[pid];load()});
}
function manage(act,pid){if(act==='delete'&&!confirm('Delete this poll?'))return;api({act:act,poll_id:pid}).then(r=>{if(!r.ok)return err(r.error);load()})}

function render(d){
  window._p=d;if(!d||!d.ok)return;
  var h='';
  if(!d.polls.length)h='<div class="card sub">No polls yet. Create the first one above.</div>';
  d.polls.forEach(p=>{
    h+='<div class="card'+(p.closed?' closed':'')+'">';
    h+='<div class="q">'+esc(p.question)+(p.closed?' <span class="tag">(closed)</span>':'')+'</div>';
    var canVote=!p.closed&&!p.voted;
    if(canVote){
      p.options.forEach((o,i)=>{
        var s=sel[p.id]&&sel[p.id][i];
        h+='<div class="opt'+(s?' sel':'')+'" onclick="pick('+p.id+','+i+',\''+p.type+'\')">'+
           '<input type="'+(p.type==='multi'?'checkbox':'radio')+'" '+(s?'checked':'')+' onclick="event.stopPropagation();pick('+p.id+','+i+',\''+p.type+'\')"><span>'+esc(o)+'</span></div>';
      });
      h+='<button onclick="submitVote('+p.id+',\''+p.type+'\')">Vote</button>';
    } else if(p.reveal){
      var max=Math.max.apply(null,(p.counts||[0]));
      p.options.forEach((o,i)=>{
        var c=p.counts?p.counts[i]:0, pct=p.total?Math.round(c/p.total*100):0;
        var mine=p.my&&p.my.indexOf(i)>=0;
        h+='<div class="bar'+(c===max&&c>0?' win':'')+'"><div class="fill" style="width:'+pct+'%"></div>'+
           '<div class="lab"><span>'+(mine?'✓ ':'')+esc(o)+'</span><span>'+c+' · '+pct+'%</span></div></div>';
      });
      h+='<div class="tag">'+p.total+' vote(s)'+(p.voted?' · you voted':'')+'</div>';
    } else {
      h+='<div class="tag">Results hidden until you vote.</div>';
      p.options.forEach((o,i)=>{h+='<div class="opt'+((sel[p.id]&&sel[p.id][i])?' sel':'')+'" onclick="pick('+p.id+','+i+',\''+p.type+'\')"><input type="'+(p.type==='multi'?'checkbox':'radio')+'"><span>'+esc(o)+'</span></div>'});
      h+='<button onclick="submitVote('+p.id+',\''+p.type+'\')">Vote</button>';
    }
    if(p.mine){
      h+='<div class="row" style="margin-top:8px">';
      h+=p.closed?'<button class="ghost" onclick="manage(\'reopen\','+p.id+')">Reopen</button>':'<button class="ghost" onclick="manage(\'close\','+p.id+')">Close</button>';
      h+='<button class="ghost" onclick="manage(\'delete\','+p.id+')">Delete</button></div>';
    }
    h+='</div>';
  });
  $('list').innerHTML=h;
}
function load(){api({act:'list'}).then(render)}
load();setInterval(load,5000);
</script>
</body></html>
