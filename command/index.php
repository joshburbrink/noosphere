<?php
/*
 * /command/ — Incident Command dense operator dashboard (#76).
 * Counterpart to /kiosk/ — same compact data feeds, opposite audience.
 */
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
require_once '/var/www/noosphere/shared/identity.php';
sec_session_start();

if (get_setting('show_incidents_command', '0') !== '1') { http_response_code(404); exit; }
if (!can('command.view')) {
    $next = urlencode($_SERVER['REQUEST_URI'] ?? '/command/');
    header("Location: /registry/login.php?next=$next");
    exit;
}

$name = get_setting('instance_name', 'Noosphere');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Command — <?= htmlspecialchars($name) ?></title>
<?php require_once '/var/www/noosphere/shared/head.php'; ?>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { background: #0a0a14; color: #e0e0e0; font-family: system-ui, sans-serif; min-height: 100vh; }
a { color: #4fc3f7; text-decoration: none; }
a:hover { text-decoration: underline; }

.topbar {
  background: #12122a; border-bottom: 2px solid #2a2a4a;
  padding: 10px 18px; display: flex; align-items: center; justify-content: space-between; gap: 16px;
}
.topbar .title { font-size: 18px; font-weight: bold; color: #e94560; }
.topbar .clock { font-family: ui-monospace, monospace; font-size: 22px; font-variant-numeric: tabular-nums; }
.topbar .meta  { font-size: 11px; color: #666; }
.topbar .links a { font-size: 12px; margin-left: 12px; }

.grid {
  display: grid; gap: 12px; padding: 12px;
  grid-template-columns: 2fr 1fr 1fr;
  grid-auto-rows: minmax(180px, auto);
}
@media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }

.panel {
  background: #12122a; border: 1px solid #2a2a4a; border-radius: 8px;
  padding: 12px 14px; display: flex; flex-direction: column; gap: 8px; min-height: 180px;
}
.panel h2 {
  font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: .08em; color: #777;
  display: flex; align-items: center; justify-content: space-between;
}
.panel h2 .count { color: #4fc3f7; font-size: 13px; }
.panel-body { flex: 1; font-size: 13px; overflow-y: auto; }

.row { display: flex; align-items: center; gap: 8px; padding: 5px 0; border-bottom: 1px solid #1a1a2e; }
.row:last-child { border-bottom: none; }
.row .sev { font-size: 10px; padding: 1px 6px; border-radius: 3px; font-weight: bold; }
.sev-critical { background: #5a0010; color: #fff; }
.sev-major    { background: #5a3000; color: #fff; }
.sev-minor    { background: #0a3a1a; color: #fff; }
.sev-none     { background: #222; color: #999; }
.row .title   { flex: 1; }
.row .meta    { font-size: 11px; color: #666; }
.row .overdue { color: #e94560; font-weight: bold; }

.alert-row {
  background: #5a0010; color: #fff; padding: 8px 12px; border-radius: 4px;
  display: flex; gap: 10px; align-items: center; animation: pulse 2s ease-in-out infinite;
}
@keyframes pulse { 0%,100%{background:#5a0010} 50%{background:#7a0010} }
.alert-row .ev { font-weight: bold; font-size: 13px; }
.alert-row .hd { font-size: 11px; opacity: .85; }

.weather-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.weather-grid .field { text-align: center; }
.weather-grid .field .v { font-size: 18px; font-weight: bold; color: #4a9eff; }
.weather-grid .field .l { font-size: 10px; color: #555; }

.stat { text-align: center; flex: 1; }
.stat .n { font-size: 32px; font-weight: bold; line-height: 1.1; }
.stat .l { font-size: 10px; color: #555; text-transform: uppercase; letter-spacing: .06em; }
.stat .sub { font-size: 11px; color: #888; margin-top: 2px; }
.stat-ok { color: #2ecc71; } .stat-warn { color: #f39c12; } .stat-alert { color: #e94560; }
.stats-row { display: flex; gap: 12px; }

.supply-row { display: flex; gap: 8px; padding: 4px 0; font-size: 12px; }
.supply-row .name { flex: 1; }
.supply-row .qty  { color: #888; font-family: ui-monospace, monospace; font-size: 11px; }
.supply-row.red    .name, .supply-row.red    .qty { color: #e94560; }
.supply-row.yellow .name, .supply-row.yellow .qty { color: #f39c12; }

.signal-bar { height: 6px; background: #1a1a2e; border-radius: 3px; overflow: hidden; }
.signal-fill { height: 100%; background: #2ecc71; transition: width .4s, background .4s; }

.actions { display: flex; flex-wrap: wrap; gap: 6px; }
.actions a {
  background: #2a2a4a; color: #e0e0e0; padding: 8px 12px; border-radius: 4px;
  font-size: 12px; text-decoration: none;
}
.actions a:hover { background: #3a3a6a; text-decoration: none; }

.empty { color: #444; font-size: 12px; text-align: center; padding: 16px 0; }
.fade-stale { opacity: .4; transition: opacity .3s; }
</style>
</head>
<body>

<div class="topbar">
  <div>
    <div class="title">🚨 Incident Command · <?= htmlspecialchars($name) ?></div>
    <div class="meta">Auto-refreshing every 30s · <span id="last-update">just now</span></div>
  </div>
  <div class="clock" id="clock">--:--:--</div>
  <div class="links">
    <a href="/">← Home</a>
    <a href="/admin/">Admin</a>
    <a href="/incidents/">All incidents</a>
  </div>
</div>

<div class="grid">
  <div class="panel" style="grid-column: span 2; grid-row: span 2">
    <h2>🚨 Active Incidents <span class="count" id="cnt-incidents">—</span></h2>
    <div class="panel-body" id="p-incidents"><div class="empty">Loading…</div></div>
  </div>

  <div class="panel">
    <h2>⛅ Weather + NWR</h2>
    <div class="panel-body" id="p-weather"><div class="empty">Loading…</div></div>
  </div>

  <div class="panel">
    <h2>🧑‍🤝‍🧑 Registry</h2>
    <div class="panel-body" id="p-registry"><div class="empty">Loading…</div></div>
  </div>

  <div class="panel">
    <h2>🏃 Runners <span class="count" id="cnt-runners">—</span></h2>
    <div class="panel-body" id="p-runners"><div class="empty">Loading…</div></div>
  </div>

  <div class="panel">
    <h2>📦 Supply Alerts <span class="count" id="cnt-supplies">—</span></h2>
    <div class="panel-body" id="p-supplies"><div class="empty">Loading…</div></div>
  </div>

  <div class="panel">
    <h2>📻 Radio</h2>
    <div class="panel-body" id="p-radio"><div class="empty">Loading…</div></div>
  </div>

  <div class="panel" style="grid-column: span 3">
    <h2>⚡ Quick Actions</h2>
    <div class="actions">
      <a href="/incidents/?new=1">+ Report incident</a>
      <a href="/runners/">+ Dispatch runner</a>
      <a href="/forum/?new=1&category=Announcements">📣 Post alert</a>
      <a href="/weather/">+ Log observation</a>
      <a href="/triage/">+ Triage patient</a>
      <a href="/radio/">+ Log radio contact</a>
    </div>
  </div>
</div>

<script>
const esc = s => String(s ?? '').replace(/[<>&"]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'})[c]);
const tick = () => { const d = new Date(); document.getElementById('clock').textContent =
  String(d.getHours()).padStart(2,'0') + ':' +
  String(d.getMinutes()).padStart(2,'0') + ':' +
  String(d.getSeconds()).padStart(2,'0'); };
setInterval(tick, 1000); tick();

function ageStr(ts) {
  const d = Math.floor(Date.now()/1000) - ts;
  if (d < 60)   return d + 's';
  if (d < 3600) return Math.round(d/60) + 'm';
  const h = Math.floor(d/3600), m = Math.round((d%3600)/60);
  return h + 'h' + (m ? ' ' + m + 'm' : '');
}

function renderIncidents(d) {
  if (!d.enabled) return '<div class="empty">Module disabled</div>';
  if (!d.rows.length) return '<div class="empty">No active incidents.</div>';
  return d.rows.map(r => {
    const sev = r.severity || 'none';
    return '<div class="row">' +
      '<span class="sev sev-' + sev + '">' + esc(sev.toUpperCase()) + '</span>' +
      '<span class="title"><a href="/incidents/?id=' + r.id + '">' + esc(r.title) + '</a>' +
        (r.location_text ? ' <span class="meta">· ' + esc(r.location_text) + '</span>' : '') +
        (r.assigned_to ? ' <span class="meta">· → ' + esc(r.assigned_to) + '</span>' : '') +
      '</span>' +
      '<span class="meta">' + esc(r.status) + ' · ' + ageStr(r.submitted_at) + '</span>' +
    '</div>';
  }).join('');
}

function renderRunners(d) {
  if (!d.enabled) return '<div class="empty">Module disabled</div>';
  if (!d.rows.length) return '<div class="empty">No runners out.</div>';
  return d.rows.map(r => {
    const overdue = r.overdue ? '<span class="overdue">OVERDUE</span> · ' : '';
    return '<div class="row"><span class="title">' + esc(r.name) +
      ' <span class="meta">→ ' + esc(r.destination) + '</span></span>' +
      '<span class="meta">' + overdue + ageStr(r.departed_at) + ' out</span></div>';
  }).join('');
}

function renderWeather(d) {
  if (!d.enabled) return '<div class="empty">Module disabled</div>';
  let html = '';
  if (d.alert) {
    html += '<div class="alert-row"><div><div class="ev">' + esc(d.alert.event) + '</div>' +
            '<div class="hd">' + esc((d.alert.headline || '').slice(0, 90)) + '</div></div></div>';
  }
  if (d.obs) {
    html += '<div class="weather-grid">' +
      '<div class="field"><div class="v">' + (d.obs.temp_f !== null ? Math.round(d.obs.temp_f) + '°' : '—') + '</div><div class="l">temp</div></div>' +
      '<div class="field"><div class="v" style="font-size:13px">' + esc(d.obs.conditions || '—') + '</div><div class="l">cond</div></div>' +
      '<div class="field"><div class="v" style="font-size:13px">' + esc((d.obs.wind_dir || '') + ' ' + (d.obs.wind_speed || '')) + '</div><div class="l">wind</div></div>' +
    '</div>';
  }
  if (!html) html = '<div class="empty">No weather data.</div>';
  return html;
}

function renderRegistry(d) {
  if (!d.enabled) return '<div class="empty">Module disabled</div>';
  let html = '<div class="stats-row"><div class="stat"><div class="n stat-info">' + d.total +
             '</div><div class="l">total registered</div></div>';
  if (d.capacity > 0) {
    const pct = Math.round(d.shelter / d.capacity * 100);
    const cls = pct >= 90 ? 'stat-alert' : pct >= 70 ? 'stat-warn' : 'stat-ok';
    html += '<div class="stat"><div class="n ' + cls + '">' + d.shelter + '/' + d.capacity +
            '</div><div class="l">shelter</div><div class="sub">' + pct + '% full</div></div>';
  }
  html += '</div>';
  return html;
}

function renderSupplies(d) {
  if (!d.enabled) return '<div class="empty">Module disabled</div>';
  if (!d.rows.length) return '<div class="empty stat-ok" style="color:#2ecc71">All supplies OK.</div>';
  return d.rows.map(r => {
    const qty = (Math.round(r.qty * 10) / 10) + ' ' + esc(r.unit || '');
    const days = r.days !== null ? ' · ' + r.days + 'd' : '';
    return '<div class="supply-row ' + r.status + '"><span class="name">' + esc(r.name) +
           '</span><span class="qty">' + qty + days + '</span></div>';
  }).join('');
}

function renderRadio(d) {
  if (!d.enabled) return '<div class="empty">Module disabled</div>';
  let html = '<div style="font-size:12px;color:#888">Mode: <strong style="color:#e0e0e0">' + esc(d.mode) + '</strong></div>';
  if (d.signal_dbfs !== null) {
    // -80 → 0% green, -20 → 100% red
    const pct = Math.max(0, Math.min(100, ((d.signal_dbfs + 80) / 80) * 100));
    const col = d.signal_dbfs > -30 ? '#e94560' : d.signal_dbfs > -50 ? '#f39c12' : '#2ecc71';
    html += '<div style="margin-top:8px"><div class="signal-bar"><div class="signal-fill" style="width:' + pct +
            '%;background:' + col + '"></div></div>' +
            '<div style="font-size:11px;color:#666;margin-top:3px;font-family:ui-monospace,monospace">' +
            d.signal_dbfs.toFixed(1) + ' dBFS</div></div>';
  }
  return html;
}

let lastTs = 0;
function refresh() {
  fetch('/command/data.php', {credentials:'same-origin'})
    .then(r => r.ok ? r.json() : Promise.reject(r.status))
    .then(d => {
      document.getElementById('p-incidents').innerHTML = renderIncidents(d.incidents);
      document.getElementById('cnt-incidents').textContent = d.incidents.count ?? '';
      document.getElementById('p-runners').innerHTML   = renderRunners(d.runners);
      document.getElementById('cnt-runners').textContent = d.runners.count ?? '';
      document.getElementById('p-weather').innerHTML   = renderWeather(d.weather);
      document.getElementById('p-registry').innerHTML  = renderRegistry(d.registry);
      document.getElementById('p-supplies').innerHTML  = renderSupplies(d.supplies);
      document.getElementById('cnt-supplies').textContent = d.supplies.count ?? '';
      document.getElementById('p-radio').innerHTML     = renderRadio(d.radio);
      document.getElementById('last-update').textContent = new Date().toLocaleTimeString();
      lastTs = Date.now();
      document.querySelectorAll('.panel-body').forEach(b => b.classList.remove('fade-stale'));
    })
    .catch(err => {
      document.querySelectorAll('.panel-body').forEach(b => b.classList.add('fade-stale'));
      document.getElementById('last-update').textContent = 'error — retrying';
    });
}
refresh();
setInterval(refresh, 30000);
</script>
</body>
</html>
