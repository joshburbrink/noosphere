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
#color-row{display:flex;align-items:center;gap:4px}
.swatch{width:22px;height:22px;border-radius:50%;cursor:pointer;border:2px solid transparent;flex-shrink:0;transition:.1s}
.swatch:hover,.swatch.active{border-color:#fff;transform:scale(1.15)}
#custom-color{width:28px;height:28px;border:none;padding:0;cursor:pointer;background:none;border-radius:4px}
#mode-row{display:flex;align-items:center;gap:6px}
#canvas-wrap{position:relative;flex:1;overflow:hidden;cursor:crosshair;background:var(--bg)}
#bg-canvas{position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none}
#draw-canvas{position:absolute;top:0;left:0;cursor:crosshair;touch-action:none}
#map-container{position:absolute;top:0;left:0;width:100%;height:100%;display:none}
#map-container.active{display:block}
#map-canvas{position:absolute;top:0;left:0;pointer-events:none}
/* MapLibre GL */
#mapgl{position:absolute;top:0;left:0;width:100%;height:100%}
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
<link rel="stylesheet" href="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.css" crossorigin>
</head>
<body>

<div id="toolbar">
  <h1>🎨 Canvas</h1>
  <div class="sep"></div>

  <div id="mode-row">
    <button class="tb active" id="btn-pen" title="Pen">✏️ Pen</button>
    <button class="tb" id="btn-eraser" title="Eraser">🧹 Eraser</button>
    <button class="tb" id="btn-text" title="Type text">T Text</button>
    <button class="tb" id="btn-map" title="Annotated map mode">🗺 Map Mode</button>
  </div>

  <div class="sep"></div>

  <label style="font-size:12px">Size</label>
  <input type="range" id="size-slider" min="1" max="60" value="5">

  <div class="sep"></div>

  <div id="color-row">
    <?php
    $palette = ['#e94560','#f39c12','#2ecc71','#3498db','#9b59b6','#fff','#000','#555'];
    foreach ($palette as $c):
    ?>
    <div class="swatch <?= $c==='#e94560'?'active':'' ?>" data-color="<?= $c ?>" style="background:<?= $c ?>"></div>
    <?php endforeach; ?>
    <input type="color" id="custom-color" title="Custom color" value="#e94560">
  </div>

  <div class="sep"></div>

  <button class="tb" id="btn-undo" title="Undo (Ctrl+Z)">↩ Undo</button>
  <button class="tb danger" id="btn-clear" title="Clear canvas">✕ Clear</button>

  <div class="sep"></div>

  <button class="tb" id="btn-save-server" title="Save PNG to Files">💾 Save to Files</button>
  <button class="tb" id="btn-download" title="Download PNG">⬇ Download</button>

  <div style="margin-left:auto">
    <a href="/" style="font-size:12px;color:var(--text-muted);text-decoration:none">← Home</a>
  </div>
</div>

<div id="canvas-wrap">
  <div id="map-container">
    <div id="mapgl"></div>
    <canvas id="map-canvas"></canvas>
  </div>
  <canvas id="draw-canvas"></canvas>
</div>

<div id="status-bar" id="status">Pen · size 5 · draw to begin</div>

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

<script src="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.js" crossorigin></script>
<script>
(function(){
'use strict';

// ── State ────────────────────────────────────────────────────────────────────
var tool     = 'pen';      // pen | eraser | text
var mapMode  = false;
var color    = '#e94560';
var size     = 5;
var drawing  = false;
var strokes  = [];         // undo stack: array of ImageData snapshots
var MAX_UNDO = 30;
var textInput = null;
var mapgl    = null;

// ── Elements ─────────────────────────────────────────────────────────────────
var wrap   = document.getElementById('canvas-wrap');
var dc     = document.getElementById('draw-canvas');
var ctx    = dc.getContext('2d');
var mapCan = document.getElementById('map-canvas');
var mapCtx = mapCan.getContext('2d');
var status = document.getElementById('status-bar');

// ── Resize ───────────────────────────────────────────────────────────────────
function resize() {
  var w = wrap.clientWidth, h = wrap.clientHeight;
  // Preserve content
  var tmp = ctx.getImageData(0, 0, dc.width, dc.height);
  dc.width = w; dc.height = h;
  ctx.putImageData(tmp, 0, 0);
  mapCan.width = w; mapCan.height = h;
}
window.addEventListener('resize', resize);
resize();

// ── Tool helpers ──────────────────────────────────────────────────────────────
function setStatus(s) { status.textContent = s; }

function activeColor() { return tool === 'eraser' ? null : color; }

function setCtxStyle() {
  if (tool === 'eraser') {
    ctx.globalCompositeOperation = 'destination-out';
    ctx.strokeStyle = 'rgba(0,0,0,1)';
  } else {
    ctx.globalCompositeOperation = 'source-over';
    ctx.strokeStyle = color;
  }
  ctx.lineWidth   = size;
  ctx.lineCap     = 'round';
  ctx.lineJoin    = 'round';
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

// ── Pointer helpers ───────────────────────────────────────────────────────────
function ptFromEvent(e) {
  var r = dc.getBoundingClientRect();
  if (e.touches) {
    return { x: e.touches[0].clientX - r.left, y: e.touches[0].clientY - r.top };
  }
  return { x: e.clientX - r.left, y: e.clientY - r.top };
}

// ── Drawing ───────────────────────────────────────────────────────────────────
dc.addEventListener('pointerdown', function(e) {
  if (tool === 'text') { handleTextClick(e); return; }
  drawing = true;
  pushUndo();
  setCtxStyle();
  var p = ptFromEvent(e);
  ctx.beginPath();
  ctx.moveTo(p.x, p.y);
  e.preventDefault();
});

dc.addEventListener('pointermove', function(e) {
  if (!drawing) return;
  var p = ptFromEvent(e);
  ctx.lineTo(p.x, p.y);
  ctx.stroke();
  e.preventDefault();
});

dc.addEventListener('pointerup', function(e) {
  if (!drawing) return;
  drawing = false;
  ctx.closePath();
  e.preventDefault();
});

dc.addEventListener('pointerleave', function() { drawing = false; });

// Touch passthrough prevention
dc.addEventListener('touchstart', function(e){ e.preventDefault(); }, {passive:false});
dc.addEventListener('touchmove',  function(e){ e.preventDefault(); }, {passive:false});

// ── Text tool ─────────────────────────────────────────────────────────────────
function handleTextClick(e) {
  if (textInput) { commitText(); return; }
  var p = ptFromEvent(e);
  textInput = document.createElement('input');
  textInput.type = 'text';
  textInput.style.cssText = [
    'position:absolute',
    'left:'+(p.x-2)+'px',
    'top:'+(p.y-12)+'px',
    'font-size:'+Math.max(14,size*2.5)+'px',
    'color:'+color,
    'background:transparent',
    'border:1px dashed '+color,
    'outline:none',
    'font-family:sans-serif',
    'min-width:80px',
    'z-index:10',
  ].join(';');
  wrap.appendChild(textInput);
  textInput.focus();
  textInput.addEventListener('keydown', function(e){
    if (e.key==='Enter') commitText();
    if (e.key==='Escape') { wrap.removeChild(textInput); textInput=null; }
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
  ctx.font = Math.max(14, size*2.5)+'px sans-serif';
  ctx.fillStyle = color;
  ctx.fillText(text, p.x, p.y);
}

// ── Map mode ──────────────────────────────────────────────────────────────────
var mapContainer = document.getElementById('map-container');

function enterMapMode() {
  mapMode = true;
  mapContainer.classList.add('active');
  // Move draw canvas on top of map
  dc.style.zIndex = '10';
  mapCan.style.zIndex = '5';

  if (!mapgl) {
    mapgl = new maplibregl.Map({
      container: 'mapgl',
      style: {
        version: 8,
        sources: {
          'osm-tiles': {
            type: 'raster',
            tiles: ['/tiles/bartholomew/{z}/{x}/{y}.pbf'],
            tileSize: 256,
          }
        },
        layers: [{id:'bg',type:'background',paint:{'background-color':'#1a1a2e'}}]
      },
      center: [-85.8908, 39.2062],
      zoom: 11,
    });

    // Actually use the vector tile source we have
    mapgl.on('load', function() {
      if (mapgl.getSource('osm-tiles')) mapgl.removeSource('osm-tiles');
      mapgl.addSource('vector-tiles', {
        type: 'vector',
        tiles: [window.location.protocol + '//' + window.location.host + '/tiles/bartholomew/{z}/{x}/{y}.pbf'],
        minzoom: 4, maxzoom: 14,
      });
      // Basic road/land layers to give context
      mapgl.addLayer({id:'water-fill',type:'fill','source':'vector-tiles','source-layer':'water',paint:{'fill-color':'#1a3a5c'}});
      mapgl.addLayer({id:'land-fill', type:'fill','source':'vector-tiles','source-layer':'landuse',paint:{'fill-color':'#1a2a1a','fill-opacity':0.5}});
      mapgl.addLayer({id:'roads',     type:'line','source':'vector-tiles','source-layer':'transportation',paint:{'line-color':'#555','line-width':1.5}});
      mapgl.addLayer({id:'roads-main',type:'line','source':'vector-tiles','source-layer':'transportation',filter:['in','class','primary','secondary','trunk','motorway'],paint:{'line-color':'#888','line-width':3}});
      mapgl.addLayer({id:'labels',    type:'symbol','source':'vector-tiles','source-layer':'place',layout:{'text-field':'{name}','text-size':11,'text-font':['Noto Sans Regular']},paint:{'text-color':'#ccc','text-halo-color':'#111','text-halo-width':1}});
    });
  }

  setStatus('Map mode — zoom/pan with two fingers or scroll, draw annotations on top');
  document.getElementById('btn-map').classList.add('active');
}

function exitMapMode() {
  mapMode = false;
  mapContainer.classList.remove('active');
  dc.style.zIndex = '';
  setStatus(toolLabel());
  document.getElementById('btn-map').classList.remove('active');
}

function toolLabel() {
  if (tool==='pen')    return 'Pen · size '+size+' · draw freely';
  if (tool==='eraser') return 'Eraser · size '+size;
  if (tool==='text')   return 'Text · click on canvas to place text';
  return '';
}

// ── Save / export ─────────────────────────────────────────────────────────────
function flattenedPNG(cb) {
  // Create off-screen canvas compositing map snapshot + drawing
  var w = dc.width, h = dc.height;
  var flat = document.createElement('canvas');
  flat.width = w; flat.height = h;
  var fc = flat.getContext('2d');

  if (mapMode && mapgl) {
    // Capture map WebGL canvas
    var mapCanvas = mapgl.getCanvas();
    fc.drawImage(mapCanvas, 0, 0, w, h);
  } else {
    fc.fillStyle = '#1a1a2e';
    fc.fillRect(0, 0, w, h);
  }
  fc.drawImage(dc, 0, 0);
  cb(flat.toDataURL('image/png'));
}

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
        if (d.ok) showToast('Saved to Files: ' + d.filename);
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
  setTimeout(function(){ t.style.display = 'none'; }, 3000);
}

// ── Toolbar buttons ───────────────────────────────────────────────────────────
function setTool(t) {
  tool = t;
  ['btn-pen','btn-eraser','btn-text'].forEach(function(id){
    document.getElementById(id).classList.toggle('active', id === 'btn-'+t);
  });
  dc.style.cursor = (t === 'text') ? 'text' : 'crosshair';
  setStatus(toolLabel());
}

document.getElementById('btn-pen').addEventListener('click',    function(){ setTool('pen'); });
document.getElementById('btn-eraser').addEventListener('click', function(){ setTool('eraser'); });
document.getElementById('btn-text').addEventListener('click',   function(){ setTool('text'); });

document.getElementById('btn-map').addEventListener('click', function(){
  if (mapMode) exitMapMode(); else enterMapMode();
});

document.getElementById('btn-undo').addEventListener('click', undo);

document.getElementById('btn-clear').addEventListener('click', function(){
  if (!confirm('Clear the canvas?')) return;
  pushUndo();
  ctx.clearRect(0, 0, dc.width, dc.height);
  setStatus('Canvas cleared');
});

document.getElementById('size-slider').addEventListener('input', function(){
  size = parseInt(this.value);
  setStatus(toolLabel());
});

// Color swatches
document.querySelectorAll('.swatch').forEach(function(sw){
  sw.addEventListener('click', function(){
    color = this.dataset.color;
    document.querySelectorAll('.swatch').forEach(function(s){ s.classList.remove('active'); });
    this.classList.add('active');
    document.getElementById('custom-color').value = color;
    if (tool === 'eraser') setTool('pen');
  });
});

document.getElementById('custom-color').addEventListener('input', function(){
  color = this.value;
  document.querySelectorAll('.swatch').forEach(function(s){ s.classList.remove('active'); });
  if (tool === 'eraser') setTool('pen');
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e){
  if (e.target.tagName === 'INPUT') return;
  if ((e.ctrlKey||e.metaKey) && e.key==='z') { undo(); return; }
  if (e.key==='p') setTool('pen');
  if (e.key==='e') setTool('eraser');
  if (e.key==='t') setTool('text');
  if (e.key===']') { size = Math.min(60, size+2); document.getElementById('size-slider').value=size; setStatus(toolLabel()); }
  if (e.key==='[') { size = Math.max(1, size-2); document.getElementById('size-slider').value=size; setStatus(toolLabel()); }
});

setStatus(toolLabel());

})();
</script>
</body>
</html>
