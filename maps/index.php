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
<link rel="stylesheet" href="/maps/lib/leaflet.css">
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

#map { flex: 1; background: #1a1f2e; }

.tap-hint {
    text-align: center; font-size: 11px; color: #444; padding: 3px 0;
    background: #0f0f1a; flex-shrink: 0;
}

/* ── Marker icons ────────────────────────────────────────────────── */
.mk-icon {
    width: 30px; height: 30px; border-radius: 50%;
    border: 2px solid rgba(255,255,255,0.5);
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; line-height: 1;
    box-shadow: 0 2px 8px rgba(0,0,0,0.6);
    cursor: pointer;
}

/* ── Modal ───────────────────────────────────────────────────────── */
.veil {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.75); z-index: 2000;
    align-items: center; justify-content: center;
}
.veil.open { display: flex; }
.modal {
    background: #1a1a2e; border: 1px solid #2a2a4a;
    border-radius: 10px; padding: 20px; width: 320px; max-width: 95vw;
}
.modal h2 { color: #e94560; font-size: 14px; margin-bottom: 12px; }
.modal label { display: block; font-size: 11px; color: #777; margin: 10px 0 3px; }
.modal input, .modal select, .modal textarea {
    width: 100%; background: #16213e; border: 1px solid #2a2a4a;
    color: #e0e0e0; border-radius: 5px; padding: 7px 9px;
    font-size: 13px; font-family: inherit;
}
.modal textarea { resize: vertical; height: 58px; }
.modal input:focus, .modal select:focus, .modal textarea:focus {
    outline: none; border-color: #e94560;
}
.modal-btns { display: flex; gap: 8px; margin-top: 14px; }
.modal-btns button {
    flex: 1; padding: 8px; border-radius: 5px; border: none;
    cursor: pointer; font-size: 13px; font-weight: bold;
}
.btn-red    { background: #e94560; color: #fff; }
.btn-cancel { background: #16213e; color: #aaa; border: 1px solid #2a2a4a !important; }
.btn-cancel:hover { border-color: #e94560 !important; color: #e94560; }
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
  </div>
  <a class="topo-link" href="/maps/topo/">Topo PDFs →</a>
</header>

<?php if (!$is_readonly): ?>
<div class="tap-hint">Tap map to add a marker</div>
<?php endif; ?>

<div id="map"></div>

<!-- Add Marker Dialog -->
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
      <button class="btn-red"    onclick="submitMarker()">Add Marker</button>
      <button class="btn-cancel" onclick="closeMkDialog()">Cancel</button>
    </div>
  </div>
</div>

<script src="/maps/lib/leaflet.js"></script>
<script src="/maps/lib/Leaflet.VectorGrid.bundled.js"></script>
<script>
var IS_ADMIN    = <?= $is_admin    ? 'true' : 'false' ?>;
var IS_READONLY = <?= $is_readonly ? 'true' : 'false' ?>;

// ── Themes ────────────────────────────────────────────────────────────────────
var THEMES = {
    dark: {
        mapBg: '#1a1f2e',
        land:  '#1a1f2e', water: '#162236', waterway: '#1e3a5a',
        park: '#192819',  res: '#1e2038',   comm: '#22203a', indus: '#20241e',
        road: '#2c3152',  major: '#4a4028', hwy: '#6a5a28',
        bldg: '#2a1840',  bldgB: '#3a2858',
        bdy: '#3a3a6a',
    },
    light: {
        mapBg: '#f8f4f0',
        land:  '#f8f4f0', water: '#b8d4ea', waterway: '#8ab8d8',
        park: '#d4e8c8',  res: '#f0ece8',   comm: '#ede8f4', indus: '#e8eae0',
        road: '#d8d0c0',  major: '#e8c878', hwy: '#f0a830',
        bldg: '#d8cce8',  bldgB: '#c0b0d0',
        bdy: '#c0b8d0',
    },
};

var currentTheme = localStorage.getItem('map_theme') || 'dark'; if (currentTheme === 'topo') currentTheme = 'dark';

// ── Map ───────────────────────────────────────────────────────────────────────
var map = L.map('map', {
    center: [39.20, -85.90],
    zoom: 11,
    zoomControl: true,
    attributionControl: false,
});

// ── Vector tiles ──────────────────────────────────────────────────────────────
var vtLayer = null;

function buildStyles(name) {
    var t = THEMES[name];
    return {
        'land': [{fill: true, fillColor: t.land, fillOpacity: 1, stroke: false, weight: 0}],
        'water': [{fill: true, fillColor: t.water, fillOpacity: 1, stroke: false, weight: 0}],
        'waterway': function(p) {
            var w = (p.class === 'river' || p.class === 'canal') ? 2 : 1;
            return {stroke: true, color: t.waterway, weight: w, fill: false};
        },
        'landuse': function(p) {
            var c = p.class || '';
            var fill;
            if (/^(park|grass|meadow|recreation_ground|forest|village_green)$/.test(c)) fill = t.park;
            else if (c === 'residential') fill = t.res;
            else if (/^(commercial|retail|shopping)$/.test(c)) fill = t.comm;
            else if (/^(industrial|military|quarry)$/.test(c)) fill = t.indus;
            else return {fill: false, stroke: false};
            return {fill: true, fillColor: fill, fillOpacity: 0.85, stroke: false, weight: 0};
        },
        'landcover': function(p) {
            var c = p.class || '';
            if (/^(wood|forest|tree)$/.test(c))
                return {fill: true, fillColor: t.park, fillOpacity: 0.7, stroke: false, weight: 0};
            if (/^(grass|farmland|crop|scrub)$/.test(c))
                return {fill: true, fillColor: t.park, fillOpacity: 0.4, stroke: false, weight: 0};
            if (/^(ice|snow|glacier)$/.test(c))
                return {fill: true, fillColor: '#e8f4ff', fillOpacity: 0.7, stroke: false, weight: 0};
            return {fill: false, stroke: false};
        },
        'transportation': function(p) {
            var c = p.class || '';
            var color, weight, dash;
            if (/^(motorway|trunk)$/.test(c))       { color = t.hwy;   weight = 3.5; }
            else if (/^(primary|secondary)$/.test(c)) { color = t.major; weight = 2.5; }
            else if (/^(tertiary|minor)$/.test(c))    { color = t.road;  weight = 1.5; }
            else if (/^(service|track)$/.test(c))     { color = t.road;  weight = 1;   }
            else if (/^(path|footway|cycleway)$/.test(c)) { color = t.road; weight = 0.8; dash = '3,3'; }
            else { color = t.road; weight = 1; }
            var s = {stroke: true, color: color, weight: weight, fill: false};
            if (dash) s.dashArray = dash;
            return s;
        },
        'building': [{fill: true, fillColor: t.bldg, fillOpacity: 0.9, stroke: true, color: t.bldgB, weight: 0.5}],
        'boundary': function(p) {
            var lvl = parseInt(p.admin_level) || 10;
            if (lvl <= 2)  return {stroke: true, color: t.bdy, weight: 2,   dashArray: '6,4', fill: false};
            if (lvl <= 4)  return {stroke: true, color: t.bdy, weight: 1.5, dashArray: '4,4', fill: false};
            if (lvl <= 6)  return {stroke: true, color: t.bdy, weight: 1,   dashArray: '3,3', fill: false, opacity: 0.7};
            return {stroke: true, color: t.bdy, weight: 0.8, dashArray: '2,3', fill: false, opacity: 0.4};
        },
        'park': [{fill: true, fillColor: t.park, fillOpacity: 0.6, stroke: false, weight: 0}],
        'aeroway': [{fill: true, fillColor: t.indus, fillOpacity: 0.8, stroke: true, color: t.road, weight: 0.5}],
    };
}

function setTheme(name) {
    currentTheme = name;
    if (vtLayer) map.removeLayer(vtLayer);

    var t = THEMES[name];
    document.getElementById('map').style.background = t.mapBg;

    vtLayer = L.vectorGrid.protobuf('/tiles/counties/tiles/{z}/{x}/{y}.pbf', {
        vectorTileLayerStyles: buildStyles(name),
        maxNativeZoom: 14,
        maxZoom: 19,
        interactive: false,
    }).addTo(map);

    document.querySelectorAll('.theme-btn').forEach(function(b) {
        b.classList.toggle('active', b.dataset.theme === name);
    });
    localStorage.setItem('map_theme', name);
}

// ── Markers ───────────────────────────────────────────────────────────────────
var markersLayer = L.layerGroup().addTo(map);
var knownIds = new Set();

var MTYPE = {
    pin:      {color: '#4a9eff', glyph: '●', name: 'Pin'},
    search:   {color: '#f8c000', glyph: '◎', name: 'Search'},
    camp:     {color: '#2ecc71', glyph: '▲', name: 'Camp'},
    hazard:   {color: '#e94560', glyph: '!',  name: 'Hazard'},
    medical:  {color: '#ff6b9d', glyph: '+',  name: 'Medical'},
    resource: {color: '#9b59b6', glyph: '◆',  name: 'Resource'},
    blocked:  {color: '#e67e22', glyph: '✖',  name: 'Blocked'},
};

function mkIcon(mtype) {
    var cfg = MTYPE[mtype] || MTYPE.pin;
    return L.divIcon({
        className: '',
        html: '<div class="mk-icon" style="background:' + cfg.color +
              ';font-size:15px;font-weight:bold;color:#fff">' + cfg.glyph + '</div>',
        iconSize:   [30, 30],
        iconAnchor: [15, 15],
        popupAnchor:[0, -18],
    });
}

function popupHtml(row) {
    var cfg = MTYPE[row.mtype] || MTYPE.pin;
    var d   = new Date(parseInt(row.created_at) * 1000);
    var ts  = d.toLocaleDateString([], {month:'short',day:'numeric'}) + ' ' +
              d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
    return '<div style="font-family:system-ui;min-width:160px;font-size:13px">' +
        '<b style="display:block;margin-bottom:4px">' + esc(row.title) + '</b>' +
        '<span style="font-size:11px;color:#888">' + cfg.name + ' · ' + ts + '</span>' +
        (row.note       ? '<p style="margin:6px 0 0;font-size:12px">' + esc(row.note) + '</p>' : '') +
        (row.created_by ? '<p style="margin:4px 0 0;font-size:11px;color:#888">By: ' + esc(row.created_by) + '</p>' : '') +
        (IS_ADMIN
            ? '<button onclick="deleteMarker(' + parseInt(row.id) + ')" ' +
              'style="margin-top:10px;background:#e94560;color:#fff;border:none;' +
              'padding:4px 12px;border-radius:4px;cursor:pointer;font-size:12px">Delete</button>'
            : '') +
        '</div>';
}

function loadMarkers() {
    fetch('/maps/markers.php?action=list')
        .then(function(r) { return r.json(); })
        .then(function(rows) {
            rows.forEach(function(row) {
                var id = parseInt(row.id);
                if (knownIds.has(id)) return;
                knownIds.add(id);
                var m = L.marker([parseFloat(row.lat), parseFloat(row.lng)], {icon: mkIcon(row.mtype)});
                m.bindPopup(popupHtml(row));
                m._mkId = id;
                markersLayer.addLayer(m);
            });
        })
        .catch(function(e) { console.warn('marker poll failed:', e); });
}

function deleteMarker(id) {
    if (!confirm('Delete this marker?')) return;
    map.closePopup();
    fetch('/maps/markers.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=delete&id=' + id + '&_csrf=' + encodeURIComponent(CSRF_TOKEN),
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (!d.ok) return;
        knownIds.delete(id);
        markersLayer.eachLayer(function(m) {
            if (m._mkId === id) markersLayer.removeLayer(m);
        });
    });
}

// ── Add marker dialog ─────────────────────────────────────────────────────────
var pendingLL = null;

map.on('click', function(e) {
    if (IS_READONLY) return;
    pendingLL = e.latlng;
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

    var ll    = pendingLL;   // capture before close
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
        // Optimistically render; poll will skip duplicate
        var fakeRow = {id: d.id, lat: ll.lat, lng: ll.lng,
                       title: title, note: note, mtype: mtype,
                       created_by: by, created_at: Math.floor(Date.now()/1000)};
        var id = parseInt(d.id);
        if (!knownIds.has(id)) {
            knownIds.add(id);
            var m = L.marker([ll.lat, ll.lng], {icon: mkIcon(mtype)});
            m.bindPopup(popupHtml(fakeRow));
            m._mkId = id;
            markersLayer.addLayer(m);
            m.openPopup();
        }
    }).catch(function(e) { console.warn('marker add failed:', e); });
}

document.getElementById('mk-title').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') submitMarker();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeMkDialog();
});

function esc(s) {
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Boot ──────────────────────────────────────────────────────────────────────
setTheme(currentTheme);
loadMarkers();
setInterval(loadMarkers, 20000);
</script>
</body>
</html>
