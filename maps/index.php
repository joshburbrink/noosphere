<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();
$is_admin    = !empty($_SESSION['admin']);
$is_readonly = is_readonly();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Map — Noosphere</title>
<?= csrf_js() ?>
<link rel="stylesheet" href="/maps/lib/maplibre-gl.css">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; overflow: hidden; }
body { font-family: system-ui, sans-serif; background: #0f0f1a; color: #e0e0e0; display: flex; flex-direction: column; }
header {
    background: #1a1a2e; border-bottom: 2px solid #e94560;
    padding: 8px 12px; display: flex; align-items: center; gap: 8px;
    flex-shrink: 0; flex-wrap: wrap;
}
header a.back { color: #888; text-decoration: none; font-size: 13px; }
header a.back:hover { color: #e94560; }
header h1 { font-size: 15px; color: #e94560; flex: 1; min-width: 60px; }
.theme-bar { display: flex; gap: 4px; }
.theme-btn {
    background: #16213e; border: 1px solid #2a2a4a; color: #888;
    padding: 4px 9px; border-radius: 4px; cursor: pointer; font-size: 12px;
}
.theme-btn.active { border-color: #e94560; color: #e94560; }
.theme-btn:hover  { border-color: #e94560; color: #e94560; }
.topo-link { font-size: 12px; color: #666; text-decoration: none; white-space: nowrap; }
.topo-link:hover { color: #e94560; }
#map { flex: 1; }
.tap-hint { text-align: center; font-size: 11px; color: #444; padding: 3px 0; background: #0f0f1a; flex-shrink: 0; }
.mk-icon {
    width: 30px; height: 30px; border-radius: 50%;
    border: 2px solid rgba(255,255,255,0.5);
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: bold; color: #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,0.6); cursor: pointer;
}
.veil { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); z-index: 2000; align-items: center; justify-content: center; }
.veil.open { display: flex; }
.modal { background: #1a1a2e; border: 1px solid #2a2a4a; border-radius: 10px; padding: 20px; width: 320px; max-width: 95vw; }
.modal h2 { color: #e94560; font-size: 14px; margin-bottom: 12px; }
.modal label { display: block; font-size: 11px; color: #777; margin: 10px 0 3px; }
.modal input, .modal select, .modal textarea {
    width: 100%; background: #16213e; border: 1px solid #2a2a4a;
    color: #e0e0e0; border-radius: 5px; padding: 7px 9px; font-size: 13px; font-family: inherit;
}
.modal textarea { resize: vertical; height: 58px; }
.modal input:focus, .modal select:focus, .modal textarea:focus { outline: none; border-color: #e94560; }
.modal-btns { display: flex; gap: 8px; margin-top: 14px; }
.modal-btns button { flex: 1; padding: 8px; border-radius: 5px; border: none; cursor: pointer; font-size: 13px; font-weight: bold; }
.btn-red { background: #e94560; color: #fff; }
.btn-cancel { background: #16213e; color: #aaa; border: 1px solid #2a2a4a !important; }
.btn-cancel:hover { border-color: #e94560 !important; color: #e94560; }
.maplibregl-popup-content {
    background: #1a1a2e !important; color: #e0e0e0; border: 1px solid #2a2a4a;
    border-radius: 8px; padding: 12px; font-family: system-ui; font-size: 13px;
}
.maplibregl-popup-tip { border-top-color: #1a1a2e !important; }
</style>
</head>
<body>
<header>
  <a class="back" href="/">← Home</a>
  <h1>Map</h1>
  <div class="theme-bar">
    <button class="theme-btn" data-theme="dark"  onclick="setTheme('dark')">Dark</button>
    <button class="theme-btn" data-theme="light" onclick="setTheme('light')">Light</button>
    <button class="theme-btn" data-theme="hc"    onclick="setTheme('hc')">Hi-Vis</button>
    <button class="theme-btn" id="btn-satellite" onclick="toggleSatellite()">Satellite</button>
  </div>
  <a class="topo-link" href="/maps/topo/">Topo PDFs →</a>
</header>
<?php if (!$is_readonly): ?>
<div class="tap-hint">Tap map to add a marker</div>
<?php endif; ?>
<div id="map"></div>
<div id="mk-veil" class="veil">
  <div class="modal">
    <h2>Add Marker</h2>
    <label>Type</label>
    <select id="mk-type">
      <option value="pin">📍  Pin — general</option>
      <option value="search">🔍  Search area</option>
      <option value="camp">⛺  Camp / staging</option>
      <option value="hazard">⚠️  Hazard</option>
      <option value="medical">🏥  Medical / first aid</option>
      <option value="resource">📦  Resource / supply</option>
      <option value="blocked">🚫  Blocked / impassable</option>
    </select>
    <label>Title *</label>
    <input type="text" id="mk-title" placeholder="e.g. Water distribution point" maxlength="80">
    <label>Note</label>
    <textarea id="mk-note" placeholder="Optional details…"></textarea>
    <label>Your name (optional)</label>
    <input type="text" id="mk-by" placeholder="Leave blank to stay anonymous" maxlength="40">
    <div class="modal-btns">
      <button class="btn-red" onclick="submitMarker()">Add Marker</button>
      <button class="btn-cancel" onclick="closeMkDialog()">Cancel</button>
    </div>
  </div>
</div>
<script src="/maps/lib/maplibre-gl.js"></script>
<script>
var IS_ADMIN    = <?= $is_admin    ? 'true' : 'false' ?>;
var IS_READONLY = <?= $is_readonly ? 'true' : 'false' ?>;

var THEMES = {
    dark: {
        bg: '#1a1f2e', water: '#162236', waterway: '#1e3a5a',
        road_min: '#2c3152', road_sec: '#3a3e62', road_pri: '#4a4028', road_hwy: '#6a5a28',
        bldg: '#2a1840', bldg_out: '#3a2858',
        label: '#b0b0cc', label_halo: '#0a0a1a',
    },
    light: {
        bg: '#f8f4f0', water: '#b8d4ea', waterway: '#8ab8d8',
        road_min: '#c8c0b0', road_sec: '#b8b0a0', road_pri: '#e8c878', road_hwy: '#f0a830',
        bldg: '#d8cce8', bldg_out: '#c0b0d0',
        label: '#222233', label_halo: '#f8f4f0',
    },
    hc: {
        bg: '#000000', water: '#0033cc', waterway: '#0055ff',
        road_min: '#555555', road_sec: '#888888', road_pri: '#cccc00', road_hwy: '#ffff00',
        bldg: '#220022', bldg_out: '#ff00ff',
        label: '#ffffff', label_halo: '#000000',
    },
};

var currentTheme = localStorage.getItem('map_theme') || 'dark';
if (currentTheme === 'topo') currentTheme = 'dark';
var satelliteOn = false;

function buildStyle(theme) {
    var t = THEMES[theme];
    return {
        version: 8,
        glyphs: '/maps/fonts/{fontstack}/{range}.pbf',
        sources: {
            counties: {
                type: 'vector',
                tiles: [window.location.origin + '/tiles/counties/tiles/{z}/{x}/{y}.pbf'],
                minzoom: 4, maxzoom: 14,
            },
            satellite: {
                type: 'raster',
                tiles: [window.location.origin + '/tiles/satellite/tiles/{z}/{x}/{y}.jpg'],
                tileSize: 256, minzoom: 10, maxzoom: 16,
            },
        },
        layers: [
            { id: 'background', type: 'background',
              paint: { 'background-color': t.bg } },

            { id: 'satellite-layer', type: 'raster', source: 'satellite',
              layout: { visibility: 'none' },
              paint: { 'raster-opacity': 1 } },

            { id: 'water', type: 'fill', source: 'counties', 'source-layer': 'water',
              paint: { 'fill-color': t.water } },

            { id: 'waterway', type: 'line', source: 'counties', 'source-layer': 'waterway',
              paint: { 'line-color': t.waterway,
                       'line-width': ['match', ['get', 'class'], ['river', 'canal'], 2, 1] } },

            { id: 'building-fill', type: 'fill', source: 'counties', 'source-layer': 'building',
              minzoom: 13,
              paint: { 'fill-color': t.bldg, 'fill-opacity': 0.9 } },

            { id: 'building-outline', type: 'line', source: 'counties', 'source-layer': 'building',
              minzoom: 13,
              paint: { 'line-color': t.bldg_out, 'line-width': 0.5 } },

            { id: 'road-service', type: 'line', source: 'counties', 'source-layer': 'transportation',
              filter: ['in', ['get', 'class'], ['literal', ['service', 'track', 'path', 'footway', 'cycleway']]],
              paint: { 'line-color': t.road_min, 'line-width': 0.8 } },

            { id: 'road-minor', type: 'line', source: 'counties', 'source-layer': 'transportation',
              filter: ['in', ['get', 'class'], ['literal', ['minor', 'tertiary']]],
              paint: { 'line-color': t.road_min,
                       'line-width': ['interpolate', ['linear'], ['zoom'], 10, 0.8, 14, 2.5] } },

            { id: 'road-secondary', type: 'line', source: 'counties', 'source-layer': 'transportation',
              filter: ['==', ['get', 'class'], 'secondary'],
              paint: { 'line-color': t.road_sec,
                       'line-width': ['interpolate', ['linear'], ['zoom'], 10, 1, 14, 3] } },

            { id: 'road-primary', type: 'line', source: 'counties', 'source-layer': 'transportation',
              filter: ['in', ['get', 'class'], ['literal', ['primary', 'trunk']]],
              paint: { 'line-color': t.road_pri,
                       'line-width': ['interpolate', ['linear'], ['zoom'], 8, 1.5, 14, 4] } },

            { id: 'road-highway', type: 'line', source: 'counties', 'source-layer': 'transportation',
              filter: ['==', ['get', 'class'], 'motorway'],
              paint: { 'line-color': t.road_hwy,
                       'line-width': ['interpolate', ['linear'], ['zoom'], 8, 2, 14, 5] } },

            { id: 'road-label', type: 'symbol', source: 'counties', 'source-layer': 'transportation_name',
              minzoom: 11,
              layout: {
                  'text-field': ['get', 'name:latin'],
                  'symbol-placement': 'line',
                  'text-size': ['interpolate', ['linear'], ['zoom'], 11, 10, 14, 13],
                  'text-max-angle': 30,
                  'text-padding': 5,
                  'text-font': ['Noto Sans Regular'],
              },
              paint: {
                  'text-color': t.label,
                  'text-halo-color': t.label_halo,
                  'text-halo-width': 1.5,
              } },

            { id: 'place-label', type: 'symbol', source: 'counties', 'source-layer': 'place',
              minzoom: 8,
              layout: {
                  'text-field': ['get', 'name:latin'],
                  'text-size': ['interpolate', ['linear'], ['zoom'], 8, 11, 14, 15],
                  'text-font': ['Noto Sans Regular'],
                  'text-anchor': 'center',
                  'text-max-width': 8,
              },
              paint: {
                  'text-color': t.label,
                  'text-halo-color': t.label_halo,
                  'text-halo-width': 2,
              } },
        ],
    };
}

var map = new maplibregl.Map({
    container: 'map',
    style: buildStyle(currentTheme),
    center: [-85.90, 39.20],
    zoom: 11,
    maxZoom: 19,
    minZoom: 7,
    attributionControl: false,
});
map.addControl(new maplibregl.NavigationControl(), 'top-left');

document.querySelectorAll('.theme-btn[data-theme]').forEach(function(b) {
    b.classList.toggle('active', b.dataset.theme === currentTheme);
});

function setTheme(name) {
    if (!THEMES[name]) return;
    currentTheme = name;
    var t = THEMES[name];
    map.setPaintProperty('background',       'background-color', t.bg);
    map.setPaintProperty('water',            'fill-color',       t.water);
    map.setPaintProperty('waterway',         'line-color',       t.waterway);
    map.setPaintProperty('building-fill',    'fill-color',       t.bldg);
    map.setPaintProperty('building-outline', 'line-color',       t.bldg_out);
    map.setPaintProperty('road-service',     'line-color',       t.road_min);
    map.setPaintProperty('road-minor',       'line-color',       t.road_min);
    map.setPaintProperty('road-secondary',   'line-color',       t.road_sec);
    map.setPaintProperty('road-primary',     'line-color',       t.road_pri);
    map.setPaintProperty('road-highway',     'line-color',       t.road_hwy);
    if (!satelliteOn) {
        map.setPaintProperty('road-label',   'text-color',       t.label);
        map.setPaintProperty('road-label',   'text-halo-color',  t.label_halo);
        map.setPaintProperty('place-label',  'text-color',       t.label);
        map.setPaintProperty('place-label',  'text-halo-color',  t.label_halo);
    }
    document.querySelectorAll('.theme-btn[data-theme]').forEach(function(b) {
        b.classList.toggle('active', b.dataset.theme === name);
    });
    localStorage.setItem('map_theme', name);
}

function toggleSatellite() {
    satelliteOn = !satelliteOn;
    map.setLayoutProperty('satellite-layer', 'visibility', satelliteOn ? 'visible' : 'none');
    if (satelliteOn) {
        map.setPaintProperty('water',          'fill-opacity', 0);
        map.setPaintProperty('waterway',       'line-opacity', 0);
        map.setPaintProperty('building-fill',  'fill-opacity', 0);
        map.setPaintProperty('building-outline','line-opacity', 0);
        map.setPaintProperty('road-service',   'line-color',   'rgba(255,255,255,0.5)');
        map.setPaintProperty('road-minor',     'line-color',   '#ffffff');
        map.setPaintProperty('road-secondary', 'line-color',   '#ffff88');
        map.setPaintProperty('road-primary',   'line-color',   '#ffcc44');
        map.setPaintProperty('road-highway',   'line-color',   '#ff8800');
        map.setPaintProperty('road-label',     'text-color',   '#ffffff');
        map.setPaintProperty('road-label',     'text-halo-color', 'rgba(0,0,0,0.8)');
        map.setPaintProperty('place-label',    'text-color',   '#ffffff');
        map.setPaintProperty('place-label',    'text-halo-color', 'rgba(0,0,0,0.8)');
    } else {
        var t = THEMES[currentTheme];
        map.setPaintProperty('water',          'fill-opacity', 1);
        map.setPaintProperty('waterway',       'line-opacity', 1);
        map.setPaintProperty('building-fill',  'fill-opacity', 0.9);
        map.setPaintProperty('building-outline','line-opacity', 1);
        map.setPaintProperty('road-service',   'line-color',   t.road_min);
        map.setPaintProperty('road-minor',     'line-color',   t.road_min);
        map.setPaintProperty('road-secondary', 'line-color',   t.road_sec);
        map.setPaintProperty('road-primary',   'line-color',   t.road_pri);
        map.setPaintProperty('road-highway',   'line-color',   t.road_hwy);
        map.setPaintProperty('road-label',     'text-color',   t.label);
        map.setPaintProperty('road-label',     'text-halo-color', t.label_halo);
        map.setPaintProperty('place-label',    'text-color',   t.label);
        map.setPaintProperty('place-label',    'text-halo-color', t.label_halo);
    }
    document.getElementById('btn-satellite').classList.toggle('active', satelliteOn);
}

// ── Markers ───────────────────────────────────────────────────────────────────
var mlMarkers = {};
var MTYPE = {
    pin:      { color: '#4a9eff', glyph: '●', name: 'Pin' },
    search:   { color: '#f8c000', glyph: '◎', name: 'Search' },
    camp:     { color: '#2ecc71', glyph: '▲', name: 'Camp' },
    hazard:   { color: '#e94560', glyph: '!',  name: 'Hazard' },
    medical:  { color: '#ff6b9d', glyph: '+',  name: 'Medical' },
    resource: { color: '#9b59b6', glyph: '◆',  name: 'Resource' },
    blocked:  { color: '#e67e22', glyph: '✖',  name: 'Blocked' },
};

function mkTokens() {
    try { return JSON.parse(localStorage.getItem('mk_tokens') || '{}'); } catch(e) { return {}; }
}
function saveToken(id, token) {
    var t = mkTokens(); t[id] = token;
    localStorage.setItem('mk_tokens', JSON.stringify(t));
}

function mkEl(mtype) {
    var cfg = MTYPE[mtype] || MTYPE.pin;
    var el = document.createElement('div');
    el.className = 'mk-icon';
    el.style.background = cfg.color;
    el.innerHTML = cfg.glyph;
    return el;
}

function popupHtml(row, myToken) {
    var cfg = MTYPE[row.mtype] || MTYPE.pin;
    var d = new Date(parseInt(row.created_at) * 1000);
    var ts = d.toLocaleDateString([], {month:'short',day:'numeric'}) + ' ' +
             d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
    var canDelete = IS_ADMIN || !!myToken;
    var deleteBtn = canDelete
        ? '<button onclick="deleteMarker(' + parseInt(row.id) + ')" ' +
          'style="margin-top:10px;background:#e94560;color:#fff;border:none;' +
          'padding:4px 12px;border-radius:4px;cursor:pointer;font-size:12px">Delete</button>'
        : '';
    return '<div style="min-width:160px">' +
        '<b style="display:block;margin-bottom:4px">' + esc(row.title) + '</b>' +
        '<span style="font-size:11px;color:#888">' + cfg.name + ' · ' + ts + '</span>' +
        (row.note       ? '<p style="margin:6px 0 0;font-size:12px">'                 + esc(row.note)       + '</p>' : '') +
        (row.created_by ? '<p style="margin:4px 0 0;font-size:11px;color:#888">By: ' + esc(row.created_by) + '</p>' : '') +
        deleteBtn +
        '</div>';
}

function addMarkerToMap(row) {
    var id = parseInt(row.id);
    if (mlMarkers[id]) return;
    var myToken = row.my_token || mkTokens()[id] || null;
    var el = mkEl(row.mtype);
    var popup = new maplibregl.Popup({ offset: 18 }).setHTML(popupHtml(row, myToken));
    el.addEventListener('click', function(e) {
        e.stopPropagation();
        if (popup.isOpen()) {
            popup.remove();
        } else {
            popup.setLngLat([parseFloat(row.lng), parseFloat(row.lat)]).addTo(map);
        }
    });
    var marker = new maplibregl.Marker({ element: el, anchor: 'center' })
        .setLngLat([parseFloat(row.lng), parseFloat(row.lat)])
        .addTo(map);
    marker._mkId = id;
    marker._popup = popup;
    mlMarkers[id] = marker;
}

function loadMarkers() {
    fetch('/maps/markers.php?action=list')
        .then(function(r) { return r.json(); })
        .then(function(rows) { rows.forEach(addMarkerToMap); })
        .catch(function(e) { console.warn('marker poll failed:', e); });
}

function deleteMarker(id) {
    if (!confirm('Delete this marker?')) return;
    var token = IS_ADMIN ? '' : (mkTokens()[id] || '');
    fetch('/maps/markers.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=delete&id=' + id + '&token=' + encodeURIComponent(token) + '&_csrf=' + encodeURIComponent(CSRF_TOKEN),
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (!d.ok) return;
        if (mlMarkers[id]) { mlMarkers[id]._popup.remove(); mlMarkers[id].remove(); delete mlMarkers[id]; }
        var t = mkTokens(); delete t[id]; localStorage.setItem('mk_tokens', JSON.stringify(t));
    });
}

// ── Add marker dialog ─────────────────────────────────────────────────────────
var pendingLL = null;

map.on('click', function(e) {
    if (IS_READONLY) return;
    if (e.originalEvent && e.originalEvent.target.closest && e.originalEvent.target.closest('.mk-icon')) return;
    pendingLL = e.lngLat;
    document.getElementById('mk-type').value  = 'pin';
    document.getElementById('mk-title').value = '';
    document.getElementById('mk-note').value  = '';
    document.getElementById('mk-by').value    = localStorage.getItem('mk_name') || '';
    document.getElementById('mk-veil').classList.add('open');
    setTimeout(function() { document.getElementById('mk-title').focus(); }, 60);
});

function closeMkDialog() {
    document.getElementById('mk-veil').classList.remove('open');
    pendingLL = null;
}

function submitMarker() {
    if (!pendingLL) return;
    var title = document.getElementById('mk-title').value.trim();
    if (!title) { document.getElementById('mk-title').focus(); return; }
    var ll    = pendingLL;
    var mtype = document.getElementById('mk-type').value;
    var note  = document.getElementById('mk-note').value.trim();
    var by    = document.getElementById('mk-by').value.trim();
    if (by) localStorage.setItem('mk_name', by);
    closeMkDialog();
    fetch('/maps/markers.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=add' +
              '&lat='        + encodeURIComponent(ll.lat) +
              '&lng='        + encodeURIComponent(ll.lng) +
              '&title='      + encodeURIComponent(title) +
              '&note='       + encodeURIComponent(note) +
              '&mtype='      + encodeURIComponent(mtype) +
              '&created_by=' + encodeURIComponent(by) +
              '&_csrf='      + encodeURIComponent(CSRF_TOKEN),
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (!d.ok) return;
        saveToken(d.id, d.token);
        addMarkerToMap({ id: d.id, lat: ll.lat, lng: ll.lng, title: title,
                         note: note, mtype: mtype, created_by: by,
                         created_at: Math.floor(Date.now() / 1000), my_token: d.token });
    }).catch(function(e) { console.warn('marker add failed:', e); });
}

document.getElementById('mk-title').addEventListener('keydown', function(e) { if (e.key === 'Enter') submitMarker(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeMkDialog(); });

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

loadMarkers();
setInterval(loadMarkers, 20000);
</script>
</body>
</html>
