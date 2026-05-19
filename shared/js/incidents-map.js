// Shared incident map helpers for /incidents/ and /maps/.
// Provides: dictionaries, marker DOM, popup HTML, token storage, addToMap,
// and a simple add/diff loader. Requires window.maplibregl to be loaded.
(function() {
'use strict';

var TYPE = {
  damage:   { color:'#e67e22', icon:'🏚',  label:'Damage' },
  medical:  { color:'#e94560', icon:'🏥',  label:'Medical' },
  hazard:   { color:'#f39c12', icon:'⚠️', label:'Hazard' },
  missing:  { color:'#9b59b6', icon:'🔍',  label:'Missing Person' },
  resource: { color:'#2ecc71', icon:'📦',  label:'Resource' },
  general:  { color:'#7aa7d9', icon:'📍',  label:'General' },
};
var SEV = {
  critical: { color:'#e94560', label:'Critical' },
  serious:  { color:'#e67e22', label:'Serious' },
  minor:    { color:'#f39c12', label:'Minor' },
  info:     { color:'#7aa7d9', label:'Info' },
};
var STATUS = {
  open:         { color:'#e94560', label:'Open' },
  acknowledged: { color:'#f39c12', label:'Acknowledged' },
  resolved:     { color:'#2ecc71', label:'Resolved' },
};

function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
  });
}

function colorFor(r) {
  if (r.severity && SEV[r.severity]) return SEV[r.severity].color;
  return (TYPE[r.type] || TYPE.general).color;
}

function tokens() {
  try { return JSON.parse(localStorage.getItem('inc_tokens') || '{}'); } catch(e) { return {}; }
}
function saveToken(id, token) {
  var t = tokens(); t[id] = token;
  localStorage.setItem('inc_tokens', JSON.stringify(t));
}
function clearToken(id) {
  var t = tokens(); delete t[id];
  localStorage.setItem('inc_tokens', JSON.stringify(t));
}

// Build a marker DOM element for an incident.
// opts.size  -  pixel size of the square (default 24)
function markerEl(r, opts) {
  opts = opts || {};
  var tcfg = TYPE[r.type] || TYPE.general;
  var color = colorFor(r);
  var size = opts.size || 24;
  var dim = r.status === 'resolved' ? 'opacity:.4;' : '';
  var el = document.createElement('div');
  el.style.cssText =
    'width:'+size+'px;height:'+size+'px;border-radius:4px;background:'+color+
    ';border:2px solid #fff;display:flex;align-items:center;justify-content:center;' +
    'font-size:'+Math.round(size*0.55)+'px;box-shadow:0 2px 4px rgba(0,0,0,.5);cursor:pointer;'+dim;
  el.textContent = tcfg.icon;
  el.title = r.title + (r.severity ? ' ('+r.severity+')' : '');
  return el;
}

// Render popup HTML for an incident.
// opts.command    -  true to show status badge
// opts.canDelete  -  true to render a Delete button (caller wires onclick)
// opts.deleteFn   -  name of the global function to call from the button (default 'deleteIncident')
function popupHtml(r, opts) {
  opts = opts || {};
  var tcfg = TYPE[r.type] || TYPE.general;
  var scfg = r.severity ? SEV[r.severity] : null;
  var stcfg = STATUS[r.status] || STATUS.open;
  var color = colorFor(r);
  var d = new Date(r.submitted_at * 1000);
  var ts = d.toLocaleDateString([], {month:'short',day:'numeric'}) + ' ' +
           d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});

  var meta = '';
  if (r.type === 'damage' && r.meta) {
    var parts = [];
    if (r.meta.damage_level)        parts.push(r.meta.damage_level);
    if (r.meta.structure_type)      parts.push(r.meta.structure_type);
    if (r.meta.occupants_accounted) parts.push('occupants: ' + r.meta.occupants_accounted +
      (r.meta.occupant_count != null ? ' ('+r.meta.occupant_count+')' : ''));
    if (r.meta.utilities_affected && r.meta.utilities_affected.length)
      parts.push('utils off: ' + r.meta.utilities_affected.join(','));
    if (parts.length) meta = '<div style="font-size:11px;color:#7aa7d9;margin-top:4px">'+esc(parts.join(' · '))+'</div>';
    if (r.meta.hazards) meta += '<div style="font-size:12px;color:#f39c12;margin-top:3px">⚠ '+esc(r.meta.hazards)+'</div>';
  }

  var delBtn = '';
  if (opts.canDelete) {
    var fn = opts.deleteFn || 'deleteIncident';
    delBtn = '<button onclick="'+fn+'('+r.id+')" style="margin-top:8px;background:#e94560;color:#fff;border:none;padding:4px 12px;border-radius:4px;cursor:pointer;font-size:12px">Delete</button>';
  }

  return '<div style="min-width:200px;max-width:280px">' +
    '<b style="display:block;margin-bottom:4px;color:'+color+'">'+esc(r.title)+'</b>' +
    '<span style="font-size:11px;padding:1px 7px;border-radius:3px;background:'+tcfg.color+'22;color:'+tcfg.color+';border:1px solid '+tcfg.color+'44">'+tcfg.icon+' '+tcfg.label+'</span>' +
    (scfg  ? ' <span style="font-size:11px;padding:1px 7px;border-radius:3px;background:'+scfg.color+'22;color:'+scfg.color+';border:1px solid '+scfg.color+'44">'+scfg.label+'</span>' : '') +
    (opts.command ? ' <span style="font-size:11px;padding:1px 7px;border-radius:3px;background:'+stcfg.color+'22;color:'+stcfg.color+';border:1px solid '+stcfg.color+'44">'+stcfg.label+'</span>' : '') +
    meta +
    (r.description ? '<p style="margin:6px 0 0;font-size:12px">'+esc(r.description.slice(0,200))+(r.description.length>200?'…':'')+'</p>' : '') +
    (r.location_text ? '<p style="margin:4px 0 0;font-size:11px;color:#888">📍 '+esc(r.location_text)+'</p>' : '') +
    '<p style="margin:4px 0 0;font-size:11px;color:#555">'+ts+(r.reporter_name ? ' · '+esc(r.reporter_name) : '')+'</p>' +
    (r.photo_path ? '<p style="margin:4px 0 0"><a href="/incident-photos/'+encodeURIComponent(r.photo_path)+'" target="_blank" style="font-size:11px">📷 photo</a></p>' : '') +
    delBtn +
    '</div>';
}

// Convenience: add an incident as a MapLibre Marker on the given map.
// Returns the Marker. opts forwarded to markerEl + popupHtml.
function addToMap(map, r, opts) {
  if (r.lat == null || r.lng == null) return null;
  opts = opts || {};
  var el = markerEl(r, opts);
  var popup = new maplibregl.Popup({offset: opts.popupOffset || 14}).setHTML(popupHtml(r, opts));
  var m = new maplibregl.Marker({element: el, anchor:'center'}).setLngLat([r.lng, r.lat]).setPopup(popup).addTo(map);
  m._incidentId = r.id;
  return m;
}

window.NoosphereIncidents = {
  TYPE: TYPE, SEV: SEV, STATUS: STATUS,
  esc: esc,
  colorFor: colorFor,
  tokens: tokens, saveToken: saveToken, clearToken: clearToken,
  markerEl: markerEl,
  popupHtml: popupHtml,
  addToMap: addToMap,
};
})();
