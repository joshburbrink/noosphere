<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
require_once '/var/www/noosphere/shared/identity.php';
require_once '/var/www/noosphere/shared/region.php';
sec_session_start();
if (get_setting('show_maps','1') !== '1') { http_response_code(404); exit; }
$is_admin    = legacy_is_admin();
$is_readonly = is_readonly();
$aprs_active   = (get_setting('radio_mode','off') === 'aprs');
$incidents_active = (get_setting('show_incidents','0') === '1');
$incidents_command = (get_setting('show_incidents_command','0') === '1');
$runners_active    = (get_setting('show_runners','1') === '1');

$map_center_lat  = (float)(region_meta('center.lat', 39.5));
$map_center_lng  = (float)(region_meta('center.lng', -98.35));
$map_zoom        = (int)(region_meta('zoom', 4));
$map_min_zoom    = (int)(region_meta('min_zoom', 2));
$map_max_zoom    = (int)(region_meta('max_zoom', 19));
$tile_vector     = region_meta('tiles.vector', '/tiles/counties/tiles/{z}/{x}/{y}.pbf');
$tile_satellite  = region_meta('tiles.satellite', '/tiles/satellite/tiles/{z}/{x}/{y}.jpg');
$tile_v_minzoom  = (int)(region_meta('tiles.vector_minzoom', 4));
$tile_v_maxzoom  = (int)(region_meta('tiles.vector_maxzoom', 14));
$tile_s_minzoom  = (int)(region_meta('tiles.satellite_minzoom', 10));
$tile_s_maxzoom  = (int)(region_meta('tiles.satellite_maxzoom', 16));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Map  -  Noosphere</title>
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
<?php if ($incidents_active): ?>
  <button class="theme-btn" id="btn-incidents" onclick="toggleIncidents()" title="Toggle incident / map-report pins">Incidents</button>
<?php endif; ?>
<?php if ($runners_active): ?>
  <button class="theme-btn" id="btn-runners" onclick="toggleRunners()" title="Toggle runner destination pins">Runners</button>
<?php endif; ?>
<?php if ($aprs_active): ?>
  <button class="theme-btn" id="btn-aprs" onclick="toggleAprs()" title="Toggle APRS station markers">APRS</button>
<?php endif; ?>
<?php if (get_setting('show_topo','1')==='1'): ?>  <a class="topo-link" href="/topo/">Topo PDFs -></a><?php endif; ?>
</header>
<?php if (!$is_readonly && $incidents_active): ?>
<div class="tap-hint">Tap map to drop a pin</div>
<?php endif; ?>
<div id="map"></div>
<?php if ($incidents_active): ?>
<div id="mk-veil" class="veil">
  <div class="modal">
    <h2>Drop a Pin</h2>
    <label>Type</label>
    <select id="mk-type">
      <option value="general">📍  General / Observation</option>
      <option value="damage">🏚  Damage</option>
      <option value="medical">🏥  Medical</option>
      <option value="hazard">⚠️  Hazard</option>
      <option value="missing">🔍  Missing Person</option>
      <option value="resource">📦  Resource</option>
    </select>
    <label>Title *</label>
    <input type="text" id="mk-title" placeholder="Short summary  -  e.g. 'Tree across Marr Rd'" maxlength="100">
    <label>Description</label>
    <textarea id="mk-note" placeholder="Optional details…"></textarea>
    <label>Reporter name (optional)</label>
    <input type="text" id="mk-by" placeholder="Leave blank to stay anonymous" maxlength="80">
    <label>Photo <span style="font-size:10px;color:#555">(optional  -  auto-resized)</span></label>
    <input type="file" id="mk-photo" accept="image/*" capture="environment"
           style="padding:5px 0;background:none;border:none;color:#888;font-size:12px;cursor:pointer">
    <div class="modal-btns">
      <button class="btn-red" onclick="submitIncident()">Submit</button>
      <button class="btn-cancel" onclick="closeMkDialog()">Cancel</button>
    </div>
  </div>
</div>
<?php endif; ?>
<script src="/maps/lib/maplibre-gl.js"></script>
<?php if ($incidents_active): ?>
<script src="/shared/js/incidents-map.js"></script>
<?php endif; ?>
<script>
var IS_ADMIN    = <?= $is_admin    ? 'true' : 'false' ?>;
var IS_READONLY = <?= $is_readonly ? 'true' : 'false' ?>;
var APRS_ACTIVE   = <?= $aprs_active   ? 'true' : 'false' ?>;
var INCIDENTS_ACTIVE  = <?= $incidents_active  ? 'true' : 'false' ?>;
var INCIDENTS_COMMAND = <?= $incidents_command ? 'true' : 'false' ?>;
var RUNNERS_ACTIVE    = <?= $runners_active ? 'true' : 'false' ?>;

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
                tiles: [window.location.origin + <?= json_encode($tile_vector) ?>],
                minzoom: <?= $tile_v_minzoom ?>, maxzoom: <?= $tile_v_maxzoom ?>,
            },
            satellite: {
                type: 'raster',
                tiles: [window.location.origin + <?= json_encode($tile_satellite) ?>],
                tileSize: 256, minzoom: <?= $tile_s_minzoom ?>, maxzoom: <?= $tile_s_maxzoom ?>,
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
    center: [<?= $map_center_lng ?>, <?= $map_center_lat ?>],
    zoom: <?= $map_zoom ?>,
    maxZoom: <?= $map_max_zoom ?>,
    minZoom: <?= $map_min_zoom ?>,
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

// ── Add-pin dialog (posts to /incidents/api.php) ─────────────────────────────
function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

var NI = window.NoosphereIncidents || null;
function incTokens()                 { return NI ? NI.tokens() : {}; }
function saveIncToken(id, token)     { if (NI) NI.saveToken(id, token); }

var pendingLL = null;

map.on('click', function(e) {
    if (IS_READONLY || !INCIDENTS_ACTIVE) return;
    if (e.originalEvent && e.originalEvent.target.closest && e.originalEvent.target.closest('.maplibregl-marker')) return;
    var veil = document.getElementById('mk-veil');
    if (!veil) return;
    pendingLL = e.lngLat;
    document.getElementById('mk-type').value  = 'general';
    document.getElementById('mk-title').value = '';
    document.getElementById('mk-note').value  = '';
    document.getElementById('mk-by').value    = localStorage.getItem('inc_name') || '';
    veil.classList.add('open');
    setTimeout(function() { document.getElementById('mk-title').focus(); }, 60);
});

function closeMkDialog() {
    var veil = document.getElementById('mk-veil');
    if (veil) veil.classList.remove('open');
    var photo = document.getElementById('mk-photo');
    if (photo) photo.value = '';
    pendingLL = null;
}

function submitIncident() {
    if (!pendingLL) return;
    var title = document.getElementById('mk-title').value.trim();
    if (!title) { document.getElementById('mk-title').focus(); return; }
    var ll   = pendingLL;
    var type = document.getElementById('mk-type').value;
    var note = document.getElementById('mk-note').value.trim();
    var by   = document.getElementById('mk-by').value.trim();
    if (by) localStorage.setItem('inc_name', by);
    closeMkDialog();

    var fd = new FormData();
    fd.append('action',        'add');
    fd.append('lat',           ll.lat);
    fd.append('lng',           ll.lng);
    fd.append('type',          type);
    fd.append('title',         title);
    fd.append('description',   note);
    fd.append('reporter_name', by);
    fd.append('_csrf',         CSRF_TOKEN);
    var photoFile = document.getElementById('mk-photo').files[0];
    if (photoFile) fd.append('photo', photoFile);

    fetch('/incidents/api.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); }).then(function(d) {
        if (!d.ok) return;
        saveIncToken(d.id, d.token);
        // Refresh incidents layer to pick up the new pin immediately.
        if (typeof loadIncidents === 'function') {
            if (!incVisible) { toggleIncidents(); } else { loadIncidents(); }
        }
    }).catch(function(e) { console.warn('incident add failed:', e); });
}

var mkTitle = document.getElementById('mk-title');
if (mkTitle) mkTitle.addEventListener('keydown', function(e) { if (e.key === 'Enter') submitIncident(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeMkDialog(); });

// ── Incidents layer (default ON when enabled  -  it IS the pin layer) ──────────
var incMarkers = {};
var incVisible = false;
var incTimer   = null;

function deleteIncident(id) {
  if (!confirm('Delete this pin?')) return;
  var token = IS_ADMIN ? '' : (incTokens()[id] || '');
  var fd = new FormData();
  fd.append('action', 'delete'); fd.append('id', id); fd.append('token', token); fd.append('_csrf', CSRF_TOKEN);
  fetch('/incidents/api.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); }).then(function(d) {
      if (!d.ok) { alert(d.err || 'delete failed'); return; }
      if (incMarkers[id]) { incMarkers[id].remove(); delete incMarkers[id]; }
      if (NI) NI.clearToken(id);
    });
}

function loadIncidents() {
  if (!incVisible || !NI) return;
  fetch('/incidents/api.php?action=list&only_pinned=1')
    .then(function(r) { return r.json(); })
    .then(function(rows) {
      var seen = {};
      rows.forEach(function(r) {
        seen[r.id] = true;
        if (incMarkers[r.id]) return;
        var myToken = NI.tokens()[r.id] || null;
        var canDelete = IS_ADMIN || !!myToken;
        var mk = NI.addToMap(map, r, { size: 26, command: INCIDENTS_COMMAND, canDelete: canDelete, popupOffset: 15 });
        if (mk) incMarkers[r.id] = mk;
      });
      Object.keys(incMarkers).forEach(function(id) {
        if (!seen[id]) { incMarkers[id].remove(); delete incMarkers[id]; }
      });
    })
    .catch(function(e) { console.warn('incidents poll failed:', e); });
}

function clearIncidents() {
  Object.values(incMarkers).forEach(function(mk) { mk._popup.remove(); mk.remove(); });
  incMarkers = {};
}

function toggleIncidents() {
  incVisible = !incVisible;
  var btn = document.getElementById('btn-incidents');
  if (btn) btn.classList.toggle('active', incVisible);
  if (incVisible) {
    loadIncidents();
    if (!incTimer) incTimer = setInterval(loadIncidents, 30000);
  } else {
    clearIncidents();
    if (incTimer) { clearInterval(incTimer); incTimer = null; }
  }
}

// Auto-show incidents layer if module is enabled  -  pins are the primary map content now.
if (INCIDENTS_ACTIVE) { toggleIncidents(); }

// ── Runner layer ─────────────────────────────────────────────────────────────
var runnerMarkers = {};
var runnersVisible = false;
var runnersTimer   = null;

function runnerPopup(r) {
  var now  = Math.floor(Date.now() / 1000);
  var dep  = Math.floor((now - r.departed_at) / 60);
  var eta  = '';
  if (r.expected_at) {
    var diff = r.expected_at - now;
    eta = diff < 0
      ? '<div style="color:#e94560;font-weight:bold;margin-top:3px">&#9888; Overdue ' + Math.floor(-diff/60) + 'm</div>'
      : '<div style="color:#aaa;font-size:12px;margin-top:2px">ETA in ' + Math.floor(diff/60) + 'm (' + new Date(r.expected_at*1000).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}) + ')</div>';
  }
  return '<div style="min-width:150px">' +
    '<b style="font-size:14px">&#127939; ' + esc(r.name) + '</b>' +
    '<div style="color:#4a9eff;margin-top:3px">' + esc(r.destination) + '</div>' +
    '<div style="color:#aaa;font-size:12px;margin-top:2px">Out ' + dep + 'm</div>' +
    eta +
    (r.notes ? '<div style="color:#888;font-size:12px;margin-top:3px">' + esc(r.notes) + '</div>' : '') +
    '</div>';
}

function loadRunners() {
  if (!runnersVisible) return;
  fetch('/runners/api.php')
    .then(function(r) { return r.json(); })
    .then(function(rows) {
      var seen = {};
      rows.forEach(function(r) {
        seen[r.id] = true;
        if (runnerMarkers[r.id]) {
          runnerMarkers[r.id]._popup.setHTML(runnerPopup(r));
          return;
        }
        var el = document.createElement('div');
        el.style.cssText = 'width:30px;height:30px;border-radius:50%;background:#f39c12;border:2px solid #fff;display:flex;align-items:center;justify-content:center;font-size:15px;cursor:pointer;box-shadow:0 2px 6px rgba(0,0,0,.6)';
        el.textContent = '\uD83C\uDFC3';
        var popup = new maplibregl.Popup({ offset: 18 }).setHTML(runnerPopup(r));
        el.addEventListener('click', function(e) {
          e.stopPropagation();
          popup.isOpen() ? popup.remove() : popup.setLngLat([r.lng, r.lat]).addTo(map);
        });
        var mk = new maplibregl.Marker({ element: el, anchor: 'center' })
          .setLngLat([r.lng, r.lat]).addTo(map);
        mk._popup = popup;
        runnerMarkers[r.id] = mk;
      });
      Object.keys(runnerMarkers).forEach(function(id) {
        if (!seen[id]) { runnerMarkers[id]._popup.remove(); runnerMarkers[id].remove(); delete runnerMarkers[id]; }
      });
    })
    .catch(function(e) { console.warn('runners poll failed:', e); });
}

function clearRunners() {
  Object.values(runnerMarkers).forEach(function(mk) { mk._popup.remove(); mk.remove(); });
  runnerMarkers = {};
}

function toggleRunners() {
  runnersVisible = !runnersVisible;
  var btn = document.getElementById('btn-runners');
  if (btn) btn.classList.toggle('active', runnersVisible);
  if (runnersVisible) {
    loadRunners();
    if (!runnersTimer) runnersTimer = setInterval(loadRunners, 30000);
  } else {
    clearRunners();
    if (runnersTimer) { clearInterval(runnersTimer); runnersTimer = null; }
  }
}

if (RUNNERS_ACTIVE) { toggleRunners(); }

// ── APRS layer ─────────────────────────────────────────────────────────────────
var aprsMarkers = {};
var aprsVisible = false;

function fmtAge(ts) {
    var secs = Math.floor(Date.now() / 1000) - ts;
    if (secs < 60)   return secs + 's ago';
    if (secs < 3600) return Math.floor(secs / 60) + 'm ago';
    return Math.floor(secs / 3600) + 'h ' + Math.floor((secs % 3600) / 60) + 'm ago';
}

function aprsPopup(r) {
    return '<div style="min-width:160px">' +
        '<b style="font-family:monospace;font-size:14px">' + esc(r.callsign) + '</b>' +
        '<div style="font-size:11px;color:#888;margin-top:3px">Last heard: ' + fmtAge(r.last_heard) + '</div>' +
        (r.symbol  ? '<div style="font-size:11px;color:#aaa;margin-top:2px">Symbol: ' + esc(r.symbol) + '</div>' : '') +
        (r.comment ? '<div style="font-size:12px;margin-top:4px">' + esc(r.comment) + '</div>' : '') +
        '</div>';
}

function loadAprs() {
    if (!aprsVisible) return;
    fetch('/maps/aprs.php')
        .then(function(r) { return r.json(); })
        .then(function(rows) {
            var seen = {};
            rows.forEach(function(r) {
                seen[r.callsign] = true;
                if (aprsMarkers[r.callsign]) {
                    aprsMarkers[r.callsign]._popup.setHTML(aprsPopup(r));
                    return;
                }
                var el = document.createElement('div');
                el.style.cssText = 'width:28px;height:28px;border-radius:50%;background:#ff8c00;border:2px solid #fff;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:bold;color:#fff;cursor:pointer;box-shadow:0 2px 6px rgba(0,0,0,0.6)';
                el.textContent = r.callsign.charAt(0);
                var popup = new maplibregl.Popup({ offset: 16 }).setHTML(aprsPopup(r));
                el.addEventListener('click', function(e) {
                    e.stopPropagation();
                    popup.isOpen() ? popup.remove() : popup.setLngLat([r.lng, r.lat]).addTo(map);
                });
                var mk = new maplibregl.Marker({ element: el, anchor: 'center' })
                    .setLngLat([r.lng, r.lat]).addTo(map);
                mk._popup = popup;
                aprsMarkers[r.callsign] = mk;
            });
            // Remove expired
            Object.keys(aprsMarkers).forEach(function(cs) {
                if (!seen[cs]) {
                    aprsMarkers[cs]._popup.remove();
                    aprsMarkers[cs].remove();
                    delete aprsMarkers[cs];
                }
            });
        })
        .catch(function(e) { console.warn('APRS poll failed:', e); });
}

function clearAprs() {
    Object.values(aprsMarkers).forEach(function(mk) { mk._popup.remove(); mk.remove(); });
    aprsMarkers = {};
}

function toggleAprs() {
    aprsVisible = !aprsVisible;
    var btn = document.getElementById('btn-aprs');
    if (btn) btn.classList.toggle('active', aprsVisible);
    if (aprsVisible) {
        loadAprs();
    } else {
        clearAprs();
    }
}

if (APRS_ACTIVE) {
    setInterval(loadAprs, 30000);
}
</script>
</body>
</html>
