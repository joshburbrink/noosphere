<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();
if (get_setting('show_canvas','0') !== '1') { http_response_code(404); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Canvas — <?= htmlspecialchars(get_setting('instance_name','Noosphere')) ?></title>
<?php require_once '/var/www/noosphere/shared/head.php'; ?>
<?php echo csrf_js(); ?>
<link rel="stylesheet" href="/maps/lib/maplibre-gl.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:sans-serif;display:flex;flex-direction:column;height:100vh;overflow:hidden}
#toolbar{display:flex;align-items:center;gap:6px;padding:8px 12px;background:var(--tile-bg);border-bottom:1px solid var(--tile-border);flex-wrap:wrap;flex-shrink:0}
#toolbar h1{font-size:1rem;font-weight:bold;margin-right:4px}
.sep{width:1px;height:24px;background:var(--tile-border);margin:0 2px}
button.tb{background:var(--tile-bg);color:var(--text);border:1px solid var(--tile-border);border-radius:6px;padding:5px 10px;cursor:pointer;font-size:13px;transition:.15s}
button.tb:hover,button.tb.active{background:var(--accent);color:#fff;border-color:var(--accent)}
button.tb.danger:hover{background:#e94560;border-color:#e94560}
#size-slider{width:80px;accent-color:var(--accent)}
.color-input{width:28px;height:28px;border:2px solid var(--tile-border);padding:0;cursor:pointer;background:none;border-radius:4px}
.color-input:hover{border-color:#fff}
#color-row{display:flex;align-items:center;gap:4px}
.swatch{width:22px;height:22px;border-radius:50%;cursor:pointer;border:2px solid transparent;flex-shrink:0;transition:.1s}
.swatch:hover,.swatch.active{border-color:#fff;transform:scale(1.15)}
#canvas-wrap{position:relative;flex:1;overflow:hidden}
#mapgl{position:absolute;top:0;left:0;width:100%;height:100%}
#draw-canvas{position:absolute;top:0;left:0;touch-action:none}
#status-bar{font-size:11px;color:var(--text-muted);padding:4px 12px;background:var(--tile-bg);border-top:1px solid var(--tile-border);flex-shrink:0}
#save-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:200;align-items:center;justify-content:center}
#save-modal.open{display:flex}
#save-box{background:var(--tile-bg);border:1px solid var(--tile-border);border-radius:10px;padding:24px 28px;width:340px;max-width:95vw}
#save-box h2{font-size:1rem;margin-bottom:14px}
#save-box input{width:100%;padding:8px 10px;background:var(--bg);color:var(--text);border:1px solid var(--tile-border);border-radius:6px;font-size:13px;margin-bottom:12px}
#save-box .btns{display:flex;gap:10px}
.btn-primary{background:var(--accent);color:#fff;border:none;border-radius:6px;padding:8px 18px;cursor:pointer;font-size:13px}
.btn-secondary{background:var(--tile-bg);color:var(--text);border:1px solid var(--tile-border);border-radius:6px;padding:8px 18px;cursor:pointer;font-size:13px}
.btn-secondary:hover{border-color:var(--accent)}
#toast{position:fixed;bottom:18px;left:50%;transform:translateX(-50%);background:#2ecc71;color:#fff;padding:10px 22px;border-radius:8px;font-size:13px;display:none;z-index:300}
#toast.err{background:#e94560}
@media(max-width:600px){
  #toolbar{gap:4px}
  .sep{display:none}
  #size-slider{width:60px}
}
</style>
</head>
<body>

<div id="toolbar">
  <h1>🎨 Canvas</h1>
  <div class="sep"></div>

  <button class="tb active" id="btn-pen" title="Pen (P)">✏️ Pen</button>
  <button class="tb" id="btn-eraser" title="Eraser (E)">🧹 Eraser</button>
  <button class="tb" id="btn-text" title="Place text (T)">T Text</button>
  <button class="tb" id="btn-map" title="Annotated map mode">🗺 Map</button>

  <div class="sep"></div>

  <label style="font-size:12px">Size</label>
  <input type="range" id="size-slider" min="1" max="60" value="5">

  <div class="sep"></div>

  <div id="color-row">
    <?php
    $palette = ['#e94560','#f39c12','#2ecc71','#3498db','#9b59b6','#ffffff','#000000','#888888'];
    foreach ($palette as $c):
    ?>
    <div class="swatch <?= $c==='#e94560'?'active':'' ?>" data-color="<?= $c ?>" style="background:<?= $c ?>;<?= $c==='#ffffff'?'border-color:#888':'' ?>"></div>
    <?php endforeach; ?>
    <input type="color" class="color-input" id="custom-color" title="Custom draw color" value="#e94560">
  </div>

  <div class="sep"></div>

  <label style="font-size:12px">BG</label>
  <input type="color" class="color-input" id="bg-color" title="Canvas background color" value="#1a1a2e">
  <button class="tb" id="btn-bg-transparent" title="Transparent background">⬜</button>

  <div class="sep"></div>

  <button class="tb" id="btn-undo" title="Undo (Ctrl+Z)">↩ Undo</button>
  <button class="tb danger" id="btn-clear" title="Clear drawing layer">✕ Clear</button>

  <div class="sep"></div>

  <button class="tb" id="btn-save-server" title="Save PNG to Files">💾 Save</button>
  <button class="tb" id="btn-download" title="Download PNG">⬇ Download</button>

  <div style="margin-left:auto">
    <a href="/" style="font-size:12px;color:var(--text-muted);text-decoration:none">← Home</a>
  </div>
</div>

<div id="canvas-wrap">
  <div id="mapgl"></div>
  <canvas id="draw-canvas"></canvas>
</div>

<div id="status-bar">Pen · size 5 · draw to begin</div>

<div id="save-modal">
  <div id="save-box">
    <h2>Save to Files</h2>
    <input type="text" id="save-title" placeholder="Filename (e.g. briefing-sketch)" maxlength="120">
    <div class="btns">
      <button class="btn-primary" id="btn-do-save">Save</button>
      <button class="btn-secondary" id="btn-cancel-save">Cancel</button>
    </div>
  </div>
</div>

<div id="toast"></div>

<script src="/maps/lib/maplibre-gl.js"></script>
<script>
(function(){
'use strict';

// ── State ─────────────────────────────────────────────────────────────────────
var tool        = 'pen';     // pen | eraser | text
var mapMode     = false;
var mapReady    = false;
var drawMode    = true;      // in map mode: true=draw, false=pan
var color       = '#e94560';
var bgColor     = '#1a1a2e';
var bgTransp    = false;
var size        = 5;
var drawing     = false;
var strokes     = [];
var MAX_UNDO    = 30;
var textInput   = null;
var mapgl       = null;

// ── Elements ──────────────────────────────────────────────────────────────────
var wrap    = document.getElementById('canvas-wrap');
var dc      = document.getElementById('draw-canvas');
var ctx     = dc.getContext('2d');
var statusEl = document.getElementById('status-bar');

// ── Resize ────────────────────────────────────────────────────────────────────
function resize() {
  var w = wrap.clientWidth, h = wrap.clientHeight;
  var tmp = ctx.getImageData(0, 0, dc.width, dc.height);
  dc.width = w; dc.height = h;
  ctx.putImageData(tmp, 0, 0);
  if (mapgl) mapgl.resize();
}
window.addEventListener('resize', resize);
resize();

// ── Background ────────────────────────────────────────────────────────────────
function applyBg() {
  wrap.style.background = bgTransp ? 'repeating-conic-gradient(#444 0% 25%, #222 0% 50%) 0 0/20px 20px' : bgColor;
}
applyBg();

document.getElementById('bg-color').addEventListener('input', function(){
  bgColor = this.value;
  bgTransp = false;
  document.getElementById('btn-bg-transparent').classList.remove('active');
  applyBg();
});

document.getElementById('btn-bg-transparent').addEventListener('click', function(){
  bgTransp = !bgTransp;
  this.classList.toggle('active', bgTransp);
  applyBg();
});

// ── Tool helpers ──────────────────────────────────────────────────────────────
function setStatus(s) { statusEl.textContent = s; }

function setCtxStyle() {
  if (tool === 'eraser') {
    ctx.globalCompositeOperation = 'destination-out';
    ctx.strokeStyle = 'rgba(0,0,0,1)';
  } else {
    ctx.globalCompositeOperation = 'source-over';
    ctx.strokeStyle = color;
  }
  ctx.lineWidth = size;
  ctx.lineCap   = 'round';
  ctx.lineJoin  = 'round';
}

function toolLabel() {
  var base = mapMode ? '[MAP] ' : '';
  if (tool==='pen')    return base + 'Pen · size ' + size + (mapMode ? ' · hold Shift to pan map' : '');
  if (tool==='eraser') return base + 'Eraser · size ' + size;
  if (tool==='text')   return base + 'Text — click to place';
  return base;
}

// ── Undo ──────────────────────────────────────────────────────────────────────
function pushUndo() {
  strokes.push(ctx.getImageData(0, 0, dc.width, dc.height));
  if (strokes.length > MAX_UNDO) strokes.shift();
}
function undo() {
  if (!strokes.length) return;
  ctx.putImageData(strokes.pop(), 0, 0);
  setStatus('Undo');
}

// ── Pointer coords ────────────────────────────────────────────────────────────
function ptFrom(e) {
  var r = dc.getBoundingClientRect();
  var src = e.touches ? e.touches[0] : e;
  return { x: src.clientX - r.left, y: src.clientY - r.top };
}

// ── Drawing events ────────────────────────────────────────────────────────────
dc.addEventListener('pointerdown', function(e) {
  if (tool === 'text') { handleTextClick(e); return; }
  // In map mode, Shift key = pan (pass through to MapLibre)
  if (mapMode && e.shiftKey) return;
  drawing = true;
  pushUndo();
  setCtxStyle();
  var p = ptFrom(e);
  ctx.beginPath();
  ctx.moveTo(p.x, p.y);
  dc.setPointerCapture(e.pointerId);
  e.preventDefault();
});

dc.addEventListener('pointermove', function(e) {
  if (!drawing) return;
  var p = ptFrom(e);
  ctx.lineTo(p.x, p.y);
  ctx.stroke();
  e.preventDefault();
});

dc.addEventListener('pointerup', function(e) {
  if (!drawing) return;
  drawing = false;
  ctx.closePath();
});

dc.addEventListener('pointercancel', function() { drawing = false; });

dc.addEventListener('touchstart', function(e){
  if (mapMode && e.touches.length > 1) return; // allow pinch-zoom on map
  e.preventDefault();
}, {passive:false});
dc.addEventListener('touchmove', function(e){
  if (mapMode && e.touches.length > 1) return;
  e.preventDefault();
}, {passive:false});

// ── Text tool ─────────────────────────────────────────────────────────────────
function handleTextClick(e) {
  if (textInput) { commitText(); return; }
  var p = ptFrom(e);
  textInput = document.createElement('input');
  textInput.type = 'text';
  textInput.style.cssText = [
    'position:absolute',
    'left:'+(p.x-2)+'px',
    'top:'+(p.y-14)+'px',
    'font-size:'+Math.max(14,size*2.5)+'px',
    'color:'+color,
    'background:transparent',
    'border:1px dashed '+color,
    'outline:none',
    'font-family:sans-serif',
    'min-width:80px',
    'z-index:20',
  ].join(';');
  wrap.appendChild(textInput);
  textInput.focus();
  textInput.addEventListener('keydown', function(ev){
    if (ev.key==='Enter') commitText();
    if (ev.key==='Escape'){ wrap.removeChild(textInput); textInput=null; }
  });
  textInput._pos = p;
}
function commitText() {
  if (!textInput) return;
  var text = textInput.value.trim();
  var p    = textInput._pos;
  wrap.removeChild(textInput);
  textInput = null;
  if (!text) return;
  pushUndo();
  ctx.globalCompositeOperation = 'source-over';
  ctx.font      = Math.max(14,size*2.5)+'px sans-serif';
  ctx.fillStyle = color;
  ctx.fillText(text, p.x, p.y);
}

// ── Map mode ──────────────────────────────────────────────────────────────────
function buildMapStyle() {
  return {
    version: 8,
    glyphs: '/maps/fonts/{fontstack}/{range}.pbf',
    sources: {
      counties: {
        type: 'vector',
        tiles: [window.location.origin + '/tiles/counties/tiles/{z}/{x}/{y}.pbf'],
        minzoom: 4, maxzoom: 14,
      }
    },
    layers: [
      { id:'background',  type:'background', paint:{'background-color':'#1a1a2e'} },
      { id:'water',       type:'fill',   source:'counties', 'source-layer':'water',          paint:{'fill-color':'#1a3a5c'} },
      { id:'waterway',    type:'line',   source:'counties', 'source-layer':'waterway',        paint:{'line-color':'#2a5a8c','line-width':1} },
      { id:'road-minor',  type:'line',   source:'counties', 'source-layer':'transportation',
        filter:['in',['get','class'],['literal',['minor','tertiary','service','track']]],
        paint:{'line-color':'#444','line-width':['interpolate',['linear'],['zoom'],10,0.8,14,2]} },
      { id:'road-sec',    type:'line',   source:'counties', 'source-layer':'transportation',
        filter:['==',['get','class'],'secondary'],
        paint:{'line-color':'#666','line-width':['interpolate',['linear'],['zoom'],10,1,14,3]} },
      { id:'road-pri',    type:'line',   source:'counties', 'source-layer':'transportation',
        filter:['in',['get','class'],['literal',['primary','trunk']]],
        paint:{'line-color':'#aaaa00','line-width':['interpolate',['linear'],['zoom'],8,1.5,14,4]} },
      { id:'road-hwy',    type:'line',   source:'counties', 'source-layer':'transportation',
        filter:['==',['get','class'],'motorway'],
        paint:{'line-color':'#dddd00','line-width':['interpolate',['linear'],['zoom'],8,2,14,5]} },
      { id:'building',    type:'fill',   source:'counties', 'source-layer':'building',   minzoom:13,
        paint:{'fill-color':'#2a1a2a','fill-opacity':0.9} },
      { id:'place-label', type:'symbol', source:'counties', 'source-layer':'place',      minzoom:8,
        layout:{'text-field':['get','name:latin'],'text-size':['interpolate',['linear'],['zoom'],8,11,14,15],'text-font':['Noto Sans Regular']},
        paint:{'text-color':'#ddd','text-halo-color':'#111','text-halo-width':2} },
      { id:'road-label',  type:'symbol', source:'counties', 'source-layer':'transportation_name', minzoom:11,
        layout:{'text-field':['get','name:latin'],'symbol-placement':'line','text-size':11,'text-font':['Noto Sans Regular']},
        paint:{'text-color':'#ccc','text-halo-color':'#111','text-halo-width':1.5} },
    ],
  };
}

function enterMapMode() {
  mapMode = true;
  document.getElementById('btn-map').classList.add('active');

  if (!mapgl) {
    mapgl = new maplibregl.Map({
      container: 'mapgl',
      style: buildMapStyle(),
      center: [-85.90, 39.20],
      zoom: 11,
      maxZoom: 19,
      minZoom: 7,
      attributionControl: false,
    });
    mapgl.addControl(new maplibregl.NavigationControl({showCompass:false}), 'bottom-right');
    mapgl.on('load', function(){ mapReady = true; });
  }

  // Draw canvas sits above map; map gets pointer events only when not drawing
  dc.style.pointerEvents = 'auto';
  document.getElementById('mapgl').style.pointerEvents = 'auto';

  setStatus(toolLabel());
}

function exitMapMode() {
  mapMode = false;
  document.getElementById('btn-map').classList.remove('active');
  // Hide the map container (keep instance for re-entry)
  if (mapgl) {
    document.getElementById('mapgl').style.visibility = 'hidden';
  }
  setStatus(toolLabel());
}

// In map mode, clicks that don't start a stroke should reach MapLibre.
// We achieve this by making the draw canvas transparent to pointer events
// when the Shift key is held OR when the active tool is neither pen/eraser/text.
// Simpler: always let draw canvas capture, but forward non-drawing interactions
// to the map by making dc pointer-events:none momentarily — handled via Shift.
document.addEventListener('keydown', function(e){
  if (mapMode && e.key==='Shift') {
    dc.style.pointerEvents = 'none';
    setStatus('[MAP] Pan/zoom mode — release Shift to draw');
  }
});
document.addEventListener('keyup', function(e){
  if (mapMode && e.key==='Shift') {
    dc.style.pointerEvents = 'auto';
    setStatus(toolLabel());
  }
});

// Restore map visibility when entering map mode again
document.getElementById('btn-map').addEventListener('click', function(){
  if (mapMode) {
    exitMapMode();
  } else {
    if (mapgl) document.getElementById('mapgl').style.visibility = 'visible';
    enterMapMode();
  }
});

// ── Flatten for export ────────────────────────────────────────────────────────
function flattenedPNG(cb) {
  var w = dc.width, h = dc.height;
  var flat = document.createElement('canvas');
  flat.width = w; flat.height = h;
  var fc = flat.getContext('2d');

  if (mapMode && mapgl && mapReady) {
    try {
      // MapLibre preserveDrawingBuffer is false by default; getCanvas() still
      // works for a snapshot at the moment of export.
      fc.drawImage(mapgl.getCanvas(), 0, 0, w, h);
    } catch(ex) {
      // WebGL canvas tainted or unavailable — fall back to bg color
      if (!bgTransp) { fc.fillStyle = bgColor; fc.fillRect(0,0,w,h); }
    }
  } else if (!bgTransp) {
    fc.fillStyle = bgColor;
    fc.fillRect(0, 0, w, h);
  }
  fc.drawImage(dc, 0, 0);
  cb(flat.toDataURL('image/png'));
}

// ── Save / download ───────────────────────────────────────────────────────────
document.getElementById('btn-download').addEventListener('click', function(){
  flattenedPNG(function(dataurl){
    var a = document.createElement('a');
    a.href = dataurl;
    a.download = 'canvas-' + new Date().toISOString().slice(0,16).replace('T','-') + '.png';
    a.click();
  });
});

var saveModal = document.getElementById('save-modal');
document.getElementById('btn-save-server').addEventListener('click', function(){
  saveModal.classList.add('open');
  var inp = document.getElementById('save-title');
  inp.value = 'canvas-' + new Date().toISOString().slice(0,10);
  setTimeout(function(){ inp.focus(); inp.select(); }, 50);
});
document.getElementById('btn-cancel-save').addEventListener('click', function(){
  saveModal.classList.remove('open');
});
document.getElementById('btn-do-save').addEventListener('click', doSave);
document.getElementById('save-title').addEventListener('keydown', function(e){
  if (e.key==='Enter') doSave();
});

function doSave() {
  var title = document.getElementById('save-title').value.trim();
  if (!title) { showToast('Enter a filename', true); return; }
  flattenedPNG(function(dataurl){
    var fd = new FormData();
    fd.append('_csrf', CSRF_TOKEN);
    fd.append('title', title);
    fd.append('dataurl', dataurl);
    fetch('/canvas/save.php', {method:'POST', body:fd})
      .then(function(r){ return r.json(); })
      .then(function(d){
        saveModal.classList.remove('open');
        if (d.ok) showToast('Saved: ' + d.filename);
        else      showToast(d.err || 'Save failed', true);
      })
      .catch(function(){ showToast('Network error', true); });
  });
}

function showToast(msg, err) {
  var t = document.getElementById('toast');
  t.textContent = msg;
  t.className = err ? 'err' : '';
  t.style.display = 'block';
  setTimeout(function(){ t.style.display='none'; }, 3000);
}

// ── Toolbar ───────────────────────────────────────────────────────────────────
function setTool(t) {
  tool = t;
  ['pen','eraser','text'].forEach(function(n){
    document.getElementById('btn-'+n).classList.toggle('active', n===t);
  });
  dc.style.cursor = (t==='text') ? 'text' : 'crosshair';
  setStatus(toolLabel());
}

document.getElementById('btn-pen').addEventListener('click',    function(){ setTool('pen'); });
document.getElementById('btn-eraser').addEventListener('click', function(){ setTool('eraser'); });
document.getElementById('btn-text').addEventListener('click',   function(){ setTool('text'); });
document.getElementById('btn-undo').addEventListener('click',   undo);

document.getElementById('btn-clear').addEventListener('click', function(){
  if (!confirm('Clear the drawing?')) return;
  pushUndo();
  ctx.clearRect(0, 0, dc.width, dc.height);
  setStatus('Cleared');
});

document.getElementById('size-slider').addEventListener('input', function(){
  size = parseInt(this.value);
  setStatus(toolLabel());
});

document.querySelectorAll('.swatch').forEach(function(sw){
  sw.addEventListener('click', function(){
    color = this.dataset.color;
    document.querySelectorAll('.swatch').forEach(function(s){ s.classList.remove('active'); });
    this.classList.add('active');
    document.getElementById('custom-color').value = color;
    if (tool==='eraser') setTool('pen');
  });
});

document.getElementById('custom-color').addEventListener('input', function(){
  color = this.value;
  document.querySelectorAll('.swatch').forEach(function(s){ s.classList.remove('active'); });
  if (tool==='eraser') setTool('pen');
});

// ── Keyboard shortcuts ────────────────────────────────────────────────────────
document.addEventListener('keydown', function(e){
  if (e.target.tagName==='INPUT') return;
  if ((e.ctrlKey||e.metaKey) && e.key==='z') { undo(); return; }
  if (!e.ctrlKey && !e.metaKey) {
    if (e.key==='p') setTool('pen');
    if (e.key==='e') setTool('eraser');
    if (e.key==='t') setTool('text');
    if (e.key===']') { size=Math.min(60,size+2); document.getElementById('size-slider').value=size; setStatus(toolLabel()); }
    if (e.key==='[') { size=Math.max(1,size-2);  document.getElementById('size-slider').value=size; setStatus(toolLabel()); }
  }
});

// ── Init ──────────────────────────────────────────────────────────────────────
dc.style.cursor = 'crosshair';
setStatus(toolLabel());

})();
</script>
</body>
</html>
