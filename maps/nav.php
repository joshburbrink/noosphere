<?php
/*
 * maps/nav.php  -  offline turn-by-turn to one destination.
 *
 * Reached by tapping a row on car/poi.php. Everything on this page is on
 * board: MapLibre from maps/lib, vector tiles from the local tile server,
 * the route itself from GraphHopper on 127.0.0.1 via maps/nav-api.php, and
 * the position from gpsd. There is no network call to anywhere.
 *
 * The starting point is resolved in this order, and the page says out loud
 * which one it used:
 *
 *   1. flat/flon in the URL          - the driver picked a start by hand
 *   2. a live gpsd fix               - the normal driving case
 *   3. NOTHING                       - a designed state, not an error. It
 *                                      says there is no fix and why, and
 *                                      offers two ways to set a start:
 *                                      search the offline place index, or
 *                                      tap the map.
 *
 * The third case is the reason this page renders its state server-side
 * before any script runs: parked in a garage there may never be a fix, and
 * a spinner would spin forever.
 *
 * Voice guidance is deliberately absent - out of scope. The turn list is
 * GraphHopper's own narrative, which comes free with the route.
 */

require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
require_once '/var/www/noosphere/shared/region.php';
require_once '/var/www/noosphere/maps/_gps.php';
sec_session_start();

function nv(string $k): ?float
{
    if (!isset($_GET[$k]) || !is_scalar($_GET[$k]) || $_GET[$k] === '') return null;
    $v = filter_var((string)$_GET[$k], FILTER_VALIDATE_FLOAT);
    return $v === false ? null : (float)$v;
}
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES); }

$tlat = nv('tlat');
$tlon = nv('tlon');
$dest_ok = $tlat !== null && $tlon !== null
        && $tlat >= -90 && $tlat <= 90 && $tlon >= -180 && $tlon <= 180;

$dest_name = isset($_GET['name']) && is_string($_GET['name'])
           ? mb_substr(trim($_GET['name']), 0, 80) : '';
if ($dest_name === '') $dest_name = 'Destination';
$dest_sub = isset($_GET['sub']) && is_string($_GET['sub'])
          ? mb_substr(trim($_GET['sub']), 0, 120) : '';

// Where "back" goes. Only ever a same-site path, never an absolute URL.
$back = isset($_GET['back']) && is_string($_GET['back']) ? $_GET['back'] : '/car/poi.php';
if (!preg_match('#^/[A-Za-z0-9_\-/.?=&%]*$#', $back) || str_contains($back, '..')) {
    $back = '/car/poi.php';
}

// Manual start point, if the driver already chose one.
$flat = nv('flat');
$flon = nv('flon');
$manual = $flat !== null && $flon !== null;

$gps = $manual ? ['fix' => false, 'why' => ''] : nav_gps_fix();
$have_fix = !$manual && !empty($gps['fix']);

if ($manual) {
    $olat = $flat; $olon = $flon; $osrc = 'manual';
} elseif ($have_fix) {
    $olat = (float)$gps['lat']; $olon = (float)$gps['lon']; $osrc = 'gps';
} else {
    $olat = null; $olon = null; $osrc = 'none';
}

$region_lat = (float)get_setting('region_lat', '39.2');
$region_lon = (float)get_setting('region_lng', '-85.92');

$tile_vector    = region_meta('tiles.vector', '/tiles/region-indiana/tiles/{z}/{x}/{y}.pbf');
$tile_v_minzoom = (int)(region_meta('tiles.vector_minzoom', 4));
$tile_v_maxzoom = (int)(region_meta('tiles.vector_maxzoom', 14));

$boot = [
    'dest'     => $dest_ok ? ['lat' => $tlat, 'lon' => $tlon] : null,
    'destName' => $dest_name,
    'origin'   => $olat !== null ? ['lat' => $olat, 'lon' => $olon, 'source' => $osrc] : null,
    'noFixWhy' => $osrc === 'none' ? (string)($gps['why'] ?? '') : '',
    'region'   => ['lat' => $region_lat, 'lon' => $region_lon],
    'tiles'    => $tile_vector,
    'tminz'    => $tile_v_minzoom,
    'tmaxz'    => $tile_v_maxzoom,
];
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Route - Noosphere</title>
<link rel="stylesheet" href="/maps/lib/maplibre-gl.css">
<style>
  :root {
    --bg:#0b0f18; --panel:#151c2c; --panel-2:#1d2740; --line:#2b3855;
    --text:#eef2f8; --dim:#93a3bd; --accent:#4d8dff;
    --good:#31c48d; --warn:#f5a524; --bad:#f2545b;
  }
  * { box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
  html,body { margin:0; height:100%; overflow:hidden; }
  body {
    background:var(--bg); color:var(--text); user-select:none;
    font:18px/1.35 system-ui,-apple-system,"Segoe UI",sans-serif;
    display:flex; flex-direction:column;
  }
  a { color:inherit; text-decoration:none; }

  header {
    flex:0 0 auto; display:flex; align-items:center; gap:12px;
    padding:8px 14px; border-bottom:1px solid var(--line); background:var(--panel);
  }
  header .ttl { min-width:0; flex:1 1 auto; }
  header .ttl b { display:block; font-size:22px; font-weight:750;
                  overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  header .ttl span { display:block; font-size:15px; color:var(--dim);
                     overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .btn {
    display:flex; align-items:center; justify-content:center;
    min-height:54px; padding:0 18px; border-radius:12px;
    background:var(--panel-2); border:1px solid var(--line);
    color:var(--text); font-size:18px; font-weight:650; white-space:nowrap;
    cursor:pointer;
  }
  .btn:active { background:var(--accent); }
  .btn.primary { background:var(--accent); border-color:var(--accent); }
  .btn[disabled] { opacity:.45; }

  .main { flex:1 1 auto; display:flex; min-height:0; }
  #map { flex:1 1 auto; min-width:0; }
  .side {
    flex:0 0 380px; border-left:1px solid var(--line); background:var(--panel);
    display:flex; flex-direction:column; min-height:0;
  }

  .summary { flex:0 0 auto; padding:12px 16px; border-bottom:1px solid var(--line); }
  .summary .big { display:flex; align-items:baseline; gap:14px; }
  .summary .mi { font-size:44px; font-weight:800; line-height:1;
                 font-variant-numeric:tabular-nums; }
  .summary .mi span { font-size:19px; font-weight:600; color:var(--dim); margin-left:4px; }
  .summary .eta { font-size:27px; font-weight:700; color:var(--good);
                  font-variant-numeric:tabular-nums; }
  .summary .arrive { font-size:15px; color:var(--dim); margin-top:5px; }
  .summary .from { font-size:15px; color:var(--dim); margin-top:6px; }
  .summary .from b { color:var(--text); font-weight:650; }
  .summary .from.warn b { color:var(--warn); }

  .steps { flex:1 1 auto; overflow-y:auto; padding:6px 10px 14px; }
  .step {
    display:flex; gap:12px; align-items:flex-start;
    padding:11px 10px; border-bottom:1px solid var(--line);
  }
  .step .g { flex:0 0 34px; font-size:26px; line-height:1.1; color:var(--accent);
             text-align:center; }
  .step .tx { flex:1 1 auto; min-width:0; font-size:18px; line-height:1.3; }
  .step .d  { flex:0 0 74px; text-align:right; font-size:16px; color:var(--dim);
              font-variant-numeric:tabular-nums; padding-top:3px; }
  .step.now { background:var(--panel-2); }

  .note { padding:18px 16px; font-size:18px; line-height:1.5; color:var(--dim); }
  .note b { color:var(--text); display:block; font-size:21px; margin-bottom:6px; }
  .note.bad b { color:var(--bad); }
  .note.warn b { color:var(--warn); }

  .pick { padding:12px 16px; border-top:1px solid var(--line); }
  .pick .row { display:flex; gap:8px; }
  .pick input {
    flex:1 1 auto; min-width:0; min-height:52px; padding:0 14px; font-size:18px;
    background:#0a1120; color:var(--text);
    border:1px solid var(--line); border-radius:12px;
  }
  .pick input:focus { outline:none; border-color:var(--accent); }
  .hits { margin-top:8px; max-height:240px; overflow-y:auto; }
  .hit {
    padding:11px 12px; border:1px solid var(--line); border-radius:11px;
    margin-bottom:6px; background:var(--panel-2); cursor:pointer;
  }
  .hit:active { border-color:var(--accent); }
  .hit .n { font-size:18px; font-weight:650; overflow:hidden;
            text-overflow:ellipsis; white-space:nowrap; }
  .hit .s { font-size:14px; color:var(--dim); overflow:hidden;
            text-overflow:ellipsis; white-space:nowrap; }

  .banner {
    position:absolute; left:12px; top:12px; right:12px; z-index:5;
    background:rgba(21,28,44,.95); border:1px solid var(--warn);
    border-radius:12px; padding:10px 14px; font-size:16px; color:var(--text);
    display:none;
  }
  .mapwrap { position:relative; flex:1 1 auto; min-width:0; display:flex; }

  .dot-me {
    width:22px; height:22px; border-radius:50%; background:var(--accent);
    border:3px solid #fff; box-shadow:0 0 0 6px rgba(77,141,255,.25);
  }
  .dot-me.manual { background:var(--warn); box-shadow:0 0 0 6px rgba(245,165,36,.25); }
  .pin-dest {
    width:26px; height:26px; border-radius:50% 50% 50% 0; transform:rotate(-45deg);
    background:var(--bad); border:3px solid #fff;
  }
  footer {
    flex:0 0 auto; padding:6px 16px; font-size:14px; color:var(--dim);
    border-top:1px solid var(--line); display:flex; gap:14px;
  }
</style>
</head>
<body>

<header>
  <a class="btn" href="<?= h($back) ?>">&#8592; Back</a>
  <div class="ttl">
    <b><?= h($dest_name) ?></b>
    <span><?= $dest_ok
        ? h($dest_sub !== '' ? $dest_sub : sprintf('%.5f, %.5f', $tlat, $tlon))
        : 'no destination given' ?></span>
  </div>
  <button class="btn" id="btn-refix" type="button">Use GPS</button>
  <a class="btn" href="/maps/">Full map</a>
</header>

<div class="main">
  <div class="mapwrap">
    <div id="map"></div>
    <div class="banner" id="banner"></div>
  </div>
  <div class="side">
    <div id="panel"></div>
  </div>
</div>

<footer>
  <span>Offline routing &middot; GraphHopper car profile on the Indiana OSM extract</span>
  <span>No voice guidance</span>
</footer>

<script src="/maps/lib/maplibre-gl.js"></script>
<script>
var BOOT = <?= json_encode($boot, JSON_UNESCAPED_SLASHES) ?>;

function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}
function miles(m) {
  var mi = m / 1609.344;
  return mi < 10 ? mi.toFixed(1) : Math.round(mi).toString();
}
function shortDist(m) {
  if (m < 160) return Math.round(m / 0.3048) + ' ft';
  var mi = m / 1609.344;
  return (mi < 10 ? mi.toFixed(1) : Math.round(mi)) + ' mi';
}
function hms(s) {
  var t = Math.round(s / 60);
  if (t < 60) return t + ' min';
  return Math.floor(t / 60) + ' h ' + (t % 60) + ' m';
}
function clockAfter(s) {
  var d = new Date(Date.now() + s * 1000);
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}
function metresBetween(a, b) {
  var R = 6371000, p1 = a[1] * Math.PI / 180, p2 = b[1] * Math.PI / 180;
  var dp = p2 - p1, dl = (b[0] - a[0]) * Math.PI / 180;
  var x = Math.sin(dp / 2) * Math.sin(dp / 2) +
          Math.cos(p1) * Math.cos(p2) * Math.sin(dl / 2) * Math.sin(dl / 2);
  return 2 * R * Math.asin(Math.min(1, Math.sqrt(x)));
}

/* ---- map -------------------------------------------------------------- */

var T = {
  bg: '#0b0f18', water: '#12203a', waterway: '#1b3355',
  road_min: '#3a465e', road_sec: '#55637f', road_pri: '#7d8aa6',
  road_hwy: '#b0791f', label: '#9fb0cc', label_halo: '#0b0f18',
};

function buildStyle() {
  return {
    version: 8,
    glyphs: '/maps/fonts/{fontstack}/{range}.pbf',
    sources: {
      base: {
        type: 'vector',
        tiles: [window.location.origin + BOOT.tiles],
        minzoom: BOOT.tminz, maxzoom: BOOT.tmaxz
      },
      route: { type: 'geojson', data: { type: 'FeatureCollection', features: [] } }
    },
    layers: [
      { id: 'background', type: 'background', paint: { 'background-color': T.bg } },
      { id: 'water', type: 'fill', source: 'base', 'source-layer': 'water',
        paint: { 'fill-color': T.water } },
      { id: 'waterway', type: 'line', source: 'base', 'source-layer': 'waterway',
        paint: { 'line-color': T.waterway, 'line-width': 1 } },
      { id: 'road-minor', type: 'line', source: 'base', 'source-layer': 'transportation',
        filter: ['in', ['get', 'class'], ['literal', ['minor', 'tertiary', 'service', 'track']]],
        paint: { 'line-color': T.road_min,
                 'line-width': ['interpolate', ['linear'], ['zoom'], 10, 0.8, 14, 2.5] } },
      { id: 'road-secondary', type: 'line', source: 'base', 'source-layer': 'transportation',
        filter: ['==', ['get', 'class'], 'secondary'],
        paint: { 'line-color': T.road_sec,
                 'line-width': ['interpolate', ['linear'], ['zoom'], 10, 1, 14, 3] } },
      { id: 'road-primary', type: 'line', source: 'base', 'source-layer': 'transportation',
        filter: ['in', ['get', 'class'], ['literal', ['primary', 'trunk']]],
        paint: { 'line-color': T.road_pri,
                 'line-width': ['interpolate', ['linear'], ['zoom'], 8, 1.5, 14, 4] } },
      { id: 'road-highway', type: 'line', source: 'base', 'source-layer': 'transportation',
        filter: ['==', ['get', 'class'], 'motorway'],
        paint: { 'line-color': T.road_hwy,
                 'line-width': ['interpolate', ['linear'], ['zoom'], 8, 2, 14, 5] } },

      /* The route sits above every road and below every label, so a driver
         can still read the street names it runs along. */
      { id: 'route-casing', type: 'line', source: 'route',
        layout: { 'line-cap': 'round', 'line-join': 'round' },
        paint: { 'line-color': '#0b1830', 'line-opacity': 0.9,
                 'line-width': ['interpolate', ['linear'], ['zoom'], 6, 7, 14, 15] } },
      { id: 'route-line', type: 'line', source: 'route',
        layout: { 'line-cap': 'round', 'line-join': 'round' },
        paint: { 'line-color': '#4d8dff',
                 'line-width': ['interpolate', ['linear'], ['zoom'], 6, 4, 14, 9] } },

      { id: 'road-label', type: 'symbol', source: 'base', 'source-layer': 'transportation_name',
        minzoom: 11,
        layout: { 'text-field': ['get', 'name:latin'], 'symbol-placement': 'line',
                  'text-size': ['interpolate', ['linear'], ['zoom'], 11, 10, 14, 13],
                  'text-max-angle': 30, 'text-padding': 5, 'text-font': ['Noto Sans Regular'] },
        paint: { 'text-color': T.label, 'text-halo-color': T.label_halo, 'text-halo-width': 1.5 } },
      { id: 'place-label', type: 'symbol', source: 'base', 'source-layer': 'place',
        minzoom: 8,
        layout: { 'text-field': ['get', 'name:latin'],
                  'text-size': ['interpolate', ['linear'], ['zoom'], 8, 11, 14, 15],
                  'text-font': ['Noto Sans Regular'], 'text-max-width': 8 },
        paint: { 'text-color': T.label, 'text-halo-color': T.label_halo, 'text-halo-width': 2 } }
    ]
  };
}

var startCenter = BOOT.dest ? [BOOT.dest.lon, BOOT.dest.lat]
                            : [BOOT.region.lon, BOOT.region.lat];
var map = new maplibregl.Map({
  container: 'map',
  style: buildStyle(),
  center: startCenter,
  zoom: 11,
  minZoom: 4,
  maxZoom: 17,
  attributionControl: false
});
map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-left');

var meMarker = null, destMarker = null;
var origin = BOOT.origin ? [BOOT.origin.lon, BOOT.origin.lat] : null;
var originSource = BOOT.origin ? BOOT.origin.source : 'none';
var lastRoutedFrom = null;
var currentRoute = null;
var pickingOnMap = false;
var mapReady = false;

function setDestMarker() {
  if (!BOOT.dest || destMarker) return;
  var el = document.createElement('div');
  el.className = 'pin-dest';
  destMarker = new maplibregl.Marker({ element: el, anchor: 'bottom' })
    .setLngLat([BOOT.dest.lon, BOOT.dest.lat]).addTo(map);
}

function setMeMarker() {
  if (!origin) { if (meMarker) { meMarker.remove(); meMarker = null; } return; }
  if (!meMarker) {
    var el = document.createElement('div');
    el.className = 'dot-me';
    meMarker = new maplibregl.Marker({ element: el, anchor: 'center' })
      .setLngLat(origin).addTo(map);
  } else {
    meMarker.setLngLat(origin);
  }
  meMarker.getElement().classList.toggle('manual', originSource === 'manual');
}

function drawRoute(geo, bbox) {
  if (!mapReady) return;
  var src = map.getSource('route');
  if (!src) return;
  src.setData(geo ? { type: 'Feature', geometry: geo, properties: {} }
                  : { type: 'FeatureCollection', features: [] });
  if (geo && bbox && bbox.length === 4) {
    map.fitBounds([[bbox[0], bbox[1]], [bbox[2], bbox[3]]],
                  { padding: 70, duration: 500, maxZoom: 15 });
  }
}

function banner(msg) {
  var b = document.getElementById('banner');
  if (!msg) { b.style.display = 'none'; return; }
  b.innerHTML = esc(msg);
  b.style.display = 'block';
}

/* ---- panel ------------------------------------------------------------ */

var panel = document.getElementById('panel');

function renderNote(cls, title, body, extra) {
  panel.innerHTML =
    '<div class="note ' + cls + '"><b>' + esc(title) + '</b>' + esc(body) + '</div>' +
    (extra || '');
}

function pickerHtml() {
  return '' +
    '<div class="pick">' +
      '<div class="row">' +
        '<input id="q" type="text" placeholder="Search a starting place" ' +
               'autocomplete="off" autocapitalize="off" spellcheck="false">' +
        '<button class="btn" id="btn-find" type="button">Find</button>' +
      '</div>' +
      '<div class="row" style="margin-top:8px">' +
        '<button class="btn" id="btn-tap" type="button" style="flex:1">' +
          'Or tap the map to set a start</button>' +
      '</div>' +
      '<div class="hits" id="hits"></div>' +
    '</div>';
}

function wirePicker() {
  var q = document.getElementById('q');
  var bf = document.getElementById('btn-find');
  var bt = document.getElementById('btn-tap');
  if (bf) bf.addEventListener('click', doFind);
  if (q) q.addEventListener('keydown', function (e) { if (e.key === 'Enter') doFind(); });
  if (bt) bt.addEventListener('click', function () {
    pickingOnMap = true;
    banner('Tap anywhere on the map to set your starting point.');
    map.getCanvas().style.cursor = 'crosshair';
  });
}

function doFind() {
  var q = document.getElementById('q');
  var hits = document.getElementById('hits');
  if (!q || !hits) return;
  var s = q.value.trim();
  if (s.length < 2) { hits.innerHTML = '<div class="note">Type at least two characters.</div>'; return; }
  hits.innerHTML = '<div class="note">Searching the offline index...</div>';
  var ref = 'lat=' + BOOT.region.lat + '&lon=' + BOOT.region.lon;
  fetch('/maps/nav-api.php?op=find&' + ref + '&q=' + encodeURIComponent(s))
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d.ok) { hits.innerHTML = '<div class="note">' + esc(d.error) + '</div>'; return; }
      if (!d.results.length) {
        hits.innerHTML = '<div class="note">Nothing in the offline index matches that.</div>';
        return;
      }
      hits.innerHTML = d.results.map(function (r, i) {
        return '<div class="hit" data-i="' + i + '">' +
                 '<div class="n">' + esc(r.name) + '</div>' +
                 '<div class="s">' + esc(r.sub || (r.lat.toFixed(4) + ', ' + r.lon.toFixed(4))) + '</div>' +
               '</div>';
      }).join('');
      Array.prototype.forEach.call(hits.querySelectorAll('.hit'), function (el) {
        el.addEventListener('click', function () {
          var r = d.results[parseInt(el.dataset.i, 10)];
          setOrigin([r.lon, r.lat], 'manual');
        });
      });
    })
    .catch(function () {
      hits.innerHTML = '<div class="note">The place index did not answer.</div>';
    });
}

function renderNoFix() {
  renderNote('warn', 'No GPS fix',
    (BOOT.noFixWhy || 'gpsd has no position') +
    '. Nothing is going to appear on its own, so pick a starting point ' +
    'and the route is built from there. If a fix arrives it is used ' +
    'automatically.',
    pickerHtml());
  wirePicker();
}

function renderRouting() {
  renderNote('', 'Working out the route', 'Reading the on-board road graph.');
}

function renderError(code, msg) {
  var title = code === 'no_engine' ? 'Routing engine is not running'
            : code === 'no_route'  ? 'No road route'
            : 'Could not route';
  renderNote('bad', title, msg, code === 'no_route' || code === 'no_engine' ? pickerHtml() : '');
  if (code === 'no_route' || code === 'no_engine') wirePicker();
}

function renderRoute(d) {
  var fromLine =
    d.origin.source === 'gps'
      ? '<div class="from"><b>From your GPS position</b> ' +
        d.origin.lat.toFixed(5) + ', ' + d.origin.lon.toFixed(5) + '</div>'
      : '<div class="from warn"><b>From a start you picked</b> ' +
        d.origin.lat.toFixed(5) + ', ' + d.origin.lon.toFixed(5) +
        ' - not a GPS fix</div>';

  var steps = d.steps.map(function (s) {
    return '<div class="step">' +
             '<div class="g">' + esc(s.glyph) + '</div>' +
             '<div class="tx">' + esc(s.text) + '</div>' +
             '<div class="d">' + (s.distance_m > 0 ? esc(shortDist(s.distance_m)) : '') + '</div>' +
           '</div>';
  }).join('');

  panel.innerHTML =
    '<div class="summary">' +
      '<div class="big">' +
        '<div class="mi">' + esc(miles(d.distance_m)) + '<span>mi</span></div>' +
        '<div class="eta">' + esc(hms(d.time_s)) + '</div>' +
      '</div>' +
      '<div class="arrive">Arrive about ' + esc(clockAfter(d.time_s)) +
        ' &middot; ' + d.steps.length + ' turns</div>' +
      fromLine +
    '</div>' +
    '<div class="steps">' + steps + '</div>';
}

/* ---- routing ---------------------------------------------------------- */

var routeInFlight = false;

function requestRoute() {
  if (!BOOT.dest) {
    renderNote('bad', 'No destination', 'This page was opened without a place to route to.');
    return;
  }
  if (!origin) { renderNoFix(); return; }
  if (routeInFlight) return;
  routeInFlight = true;
  renderRouting();
  var u = '/maps/nav-api.php?op=route' +
          '&flat=' + origin[1] + '&flon=' + origin[0] +
          '&tlat=' + BOOT.dest.lat + '&tlon=' + BOOT.dest.lon;
  fetch(u)
    .then(function (r) { return r.json(); })
    .then(function (d) {
      routeInFlight = false;
      if (!d.ok) { drawRoute(null); renderError(d.code, d.error); return; }
      currentRoute = d;
      lastRoutedFrom = [d.origin.lon, d.origin.lat];
      drawRoute(d.geometry, d.bbox);
      renderRoute(d);
      banner('');
    })
    .catch(function () {
      routeInFlight = false;
      renderError('engine_error',
        'The routing endpoint did not answer. The engine runs on the CAR-DATA card.');
    });
}

function setOrigin(lngLat, source) {
  origin = lngLat;
  originSource = source;
  pickingOnMap = false;
  map.getCanvas().style.cursor = '';
  banner('');
  setMeMarker();
  requestRoute();
}

map.on('load', function () {
  mapReady = true;
  setDestMarker();
  setMeMarker();
  if (currentRoute) drawRoute(currentRoute.geometry, currentRoute.bbox);
  else if (!origin && BOOT.dest) map.easeTo({ center: [BOOT.dest.lon, BOOT.dest.lat], zoom: 12 });
});

map.on('click', function (e) {
  if (!pickingOnMap) return;
  setOrigin([e.lngLat.lng, e.lngLat.lat], 'manual');
});

/* Live position. Polls gpsd through the same endpoint the page rendered
   from. Three jobs: keep the "you are here" dot honest, adopt a fix the
   moment one arrives when we had none, and re-route once the car has
   actually moved a meaningful distance from where the current route
   starts. It never re-routes off a start the driver picked by hand -
   that would silently undo their choice. */
var GPS_POLL_MS = 5000;
var REROUTE_M   = 200;

function pollFix() {
  fetch('/maps/nav-api.php?op=fix')
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d.ok || !d.fix) return;
      var p = [d.lon, d.lat];
      if (originSource === 'manual') {
        // Respect the manual choice, but let the driver know GPS is alive.
        banner('GPS fix acquired. Tap "Use GPS" to route from where you are.');
        return;
      }
      origin = p;
      originSource = 'gps';
      setMeMarker();
      if (!lastRoutedFrom || metresBetween(lastRoutedFrom, p) > REROUTE_M) {
        requestRoute();
      }
    })
    .catch(function () { /* a dropped poll is not an error worth shouting about */ });
}

document.getElementById('btn-refix').addEventListener('click', function () {
  banner('');
  fetch('/maps/nav-api.php?op=fix')
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d.ok && d.fix) { setOrigin([d.lon, d.lat], 'gps'); }
      else {
        banner('Still no GPS fix - ' + (d.why || 'no position from gpsd') + '.');
      }
    })
    .catch(function () { banner('gpsd could not be reached.'); });
});

requestRoute();
setInterval(pollFix, GPS_POLL_MS);
</script>
</body>
</html>
