<?php
require_once '/var/www/noosphere/shared/settings.php';

$name    = get_setting('instance_name', 'Noosphere');
$alert   = get_setting('homepage_alert', '');
$tagline = get_setting('instance_tagline', '');

$show_registry  = get_setting('show_registry','1')  === '1';
$show_weather   = get_setting('show_weather','1')   === '1';
$show_supplies  = get_setting('show_supplies','1')  === '1';
$show_incidents = get_setting('show_incidents','0') === '1';
$show_forum     = get_setting('show_forum','1')     === '1';
$shelter_mode   = get_setting('shelter_mode','0')   === '1';

// Registry stats
$reg_total = $reg_shelter = 0;
if ($show_registry) {
    try {
        $rdb = new SQLite3('/var/lib/noosphere/registry.db', SQLITE3_OPEN_READONLY);
        $reg_total   = (int)$rdb->querySingle("SELECT COUNT(*) FROM registry");
        try { $reg_shelter = (int)$rdb->querySingle("SELECT COUNT(*) FROM registry WHERE shelter_status='checked_in'"); } catch(Throwable $e) {}
    } catch(Throwable $e) {}
}

// Latest weather observation
$weather = null;
if ($show_weather) {
    try {
        $wdb = new SQLite3('/var/lib/noosphere/weather.db', SQLITE3_OPEN_READONLY);
        $weather = $wdb->querySingle("SELECT * FROM weather_log ORDER BY logged_at DESC LIMIT 1", true) ?: null;
    } catch(Throwable $e) {}
}

// Supply urgency counts
$supplies_critical = $supplies_low = 0;
if ($show_supplies) {
    try {
        $sdb = new SQLite3('/var/lib/noosphere/inventory.db', SQLITE3_OPEN_READONLY);
        $res = $sdb->query("SELECT quantity, low_threshold, consumption_per_day FROM items");
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $days = ($row['consumption_per_day'] > 0) ? ($row['quantity'] / $row['consumption_per_day']) : 999;
            if ($days < 1)      $supplies_critical++;
            elseif ($days < 3)  $supplies_low++;
        }
    } catch(Throwable $e) {}
}

// Active incident count
$incident_count = 0;
if ($show_incidents) {
    try {
        $ndb = new SQLite3('/var/lib/noosphere/incidents.db', SQLITE3_OPEN_READONLY);
        $incident_count = (int)$ndb->querySingle("SELECT COUNT(*) FROM incidents WHERE status NOT IN ('resolved','closed')");
    } catch(Throwable $e) {}
}

// Forum announcements  -  latest 3 from announcements category
$announcements = [];
if ($show_forum) {
    try {
        $fdb = new SQLite3('/var/lib/noosphere/forum.db', SQLITE3_OPEN_READONLY);
        $res = $fdb->query("SELECT t.title, p.body, p.created_at
                            FROM threads t
                            JOIN posts p ON p.thread_id = t.id AND p.parent_id IS NULL
                            WHERE t.category LIKE '%nnouncement%'
                            ORDER BY p.created_at DESC LIMIT 3");
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) $announcements[] = $row;
    } catch(Throwable $e) {}
}

function ks_age($ts) {
    $d = time() - $ts;
    if ($d < 60)   return $d . 's ago';
    if ($d < 3600) return round($d/60) . 'm ago';
    $h = floor($d/3600); $m = round(($d%3600)/60);
    return $h . 'h' . ($m ? ' '.$m.'m' : '') . ' ago';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kiosk  -  <?= htmlspecialchars($name) ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; background: #0a0a14; color: #e0e0e0; font-family: system-ui, sans-serif; }
body { display: flex; flex-direction: column; min-height: 100vh; }

.alert-banner {
  background: #7a0000; color: #fff; font-size: clamp(16px, 2.5vw, 28px);
  font-weight: bold; padding: 14px 24px; text-align: center; border-bottom: 3px solid #e94560;
  animation: pulse-bg 3s ease-in-out infinite;
}
@keyframes pulse-bg { 0%,100%{background:#7a0000} 50%{background:#9a0010} }

.top-bar {
  background: #12122a; border-bottom: 2px solid #2a2a4a;
  padding: 16px 32px; display: flex; align-items: center; justify-content: space-between;
}
.top-bar .instance-name { font-size: clamp(22px, 3.5vw, 44px); font-weight: bold; color: #e94560; }
.top-bar .tagline { font-size: clamp(11px, 1.5vw, 16px); color: #555; margin-top: 3px; }
.clock { font-size: clamp(28px, 5vw, 64px); font-weight: bold; color: #e0e0e0; font-variant-numeric: tabular-nums; text-align: right; }
.date-str { font-size: clamp(11px, 1.6vw, 18px); color: #666; text-align: right; margin-top: 2px; }

.main { flex: 1; padding: 20px 24px; display: flex; flex-direction: column; gap: 18px; }

.stat-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; }
.stat-card {
  background: #12122a; border: 1px solid #2a2a4a; border-radius: 10px;
  padding: 18px 20px; text-align: center;
}
.stat-card .stat-label { font-size: clamp(11px, 1.4vw, 14px); color: #666; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 8px; }
.stat-card .stat-value { font-size: clamp(36px, 6vw, 80px); font-weight: bold; line-height: 1; }
.stat-card .stat-sub { font-size: clamp(11px, 1.3vw, 14px); color: #555; margin-top: 6px; }
.stat-ok    { color: #2ecc71; }
.stat-warn  { color: #f39c12; }
.stat-alert { color: #e94560; }
.stat-info  { color: #e0e0e0; }

.panel-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; flex: 1; }

.panel {
  background: #12122a; border: 1px solid #2a2a4a; border-radius: 10px;
  padding: 18px 22px; display: flex; flex-direction: column; gap: 10px;
}
.panel-title { font-size: clamp(12px, 1.5vw, 16px); color: #555; text-transform: uppercase; letter-spacing: .06em; font-weight: bold; display: flex; align-items: center; gap: 8px; }
.panel-title span { font-size: 1.3em; }

.weather-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 4px; }
.w-field { }
.w-label { font-size: clamp(10px, 1.2vw, 13px); color: #555; }
.w-value { font-size: clamp(18px, 2.8vw, 36px); font-weight: bold; color: #e0e0e0; }
.w-big   { font-size: clamp(32px, 5vw, 60px); font-weight: bold; color: #4a9eff; }

.announce-list { display: flex; flex-direction: column; gap: 10px; }
.announce-item { border-left: 3px solid #e94560; padding-left: 12px; }
.announce-title { font-size: clamp(14px, 2vw, 22px); font-weight: bold; color: #e0e0e0; }
.announce-body  { font-size: clamp(11px, 1.4vw, 16px); color: #888; margin-top: 3px;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.announce-age   { font-size: clamp(10px, 1.2vw, 13px); color: #444; margin-top: 3px; }

.no-data { color: #333; font-size: clamp(13px, 1.8vw, 20px); text-align: center; padding: 20px 0; }

.footer {
  background: #0a0a14; border-top: 1px solid #1a1a2e;
  padding: 10px 24px; display: flex; align-items: center; justify-content: space-between;
  font-size: clamp(10px, 1.2vw, 13px); color: #333;
}
.refresh-bar { display: flex; align-items: center; gap: 8px; }
.progress-wrap { width: 80px; height: 3px; background: #1a1a2e; border-radius: 2px; overflow: hidden; }
.progress-fill { height: 100%; background: #2a2a4a; border-radius: 2px; transition: width 1s linear; }
</style>
</head>
<body>

<?php if ($alert): ?>
<div class="alert-banner">⚠ <?= htmlspecialchars($alert) ?></div>
<?php endif; ?>

<div class="top-bar">
  <div>
    <div class="instance-name"><?= htmlspecialchars($name) ?></div>
    <?php if ($tagline): ?><div class="tagline"><?= htmlspecialchars($tagline) ?></div><?php endif ?>
  </div>
  <div>
    <div class="clock" id="clock">--:--</div>
    <div class="date-str" id="date-str"></div>
  </div>
</div>

<div class="main">

  <!-- Stat cards row -->
  <?php $any_stat = $show_registry || $show_incidents || $show_supplies; ?>
  <?php if ($any_stat): ?>
  <div class="stat-row">

    <?php if ($show_registry): ?>
    <div class="stat-card">
      <div class="stat-label">Registered</div>
      <div class="stat-value stat-info"><?= $reg_total ?></div>
      <?php if ($shelter_mode && $reg_shelter): ?>
        <div class="stat-sub"><?= $reg_shelter ?> checked in</div>
      <?php endif ?>
    </div>
    <?php endif ?>

    <?php if ($show_incidents): ?>
    <div class="stat-card">
      <div class="stat-label">Active Incidents</div>
      <div class="stat-value <?= $incident_count > 0 ? 'stat-warn' : 'stat-ok' ?>"><?= $incident_count ?></div>
      <div class="stat-sub"><?= $incident_count > 0 ? 'open / in progress' : 'all clear' ?></div>
    </div>
    <?php endif ?>

    <?php if ($show_supplies): ?>
    <div class="stat-card">
      <div class="stat-label">Supply Status</div>
      <?php if ($supplies_critical > 0): ?>
        <div class="stat-value stat-alert"><?= $supplies_critical ?></div>
        <div class="stat-sub">critical shortage<?= $supplies_critical !== 1 ? 's' : '' ?><?= $supplies_low ? ' + '.$supplies_low.' low' : '' ?></div>
      <?php elseif ($supplies_low > 0): ?>
        <div class="stat-value stat-warn"><?= $supplies_low ?></div>
        <div class="stat-sub">item<?= $supplies_low !== 1 ? 's' : '' ?> running low</div>
      <?php else: ?>
        <div class="stat-value stat-ok">OK</div>
        <div class="stat-sub">no shortages</div>
      <?php endif ?>
    </div>
    <?php endif ?>

  </div>
  <?php endif ?>

  <!-- Main panels -->
  <?php $any_panel = $show_weather || ($show_forum && !empty($announcements)); ?>
  <?php if ($any_panel): ?>
  <div class="panel-row">

    <?php if ($show_weather): ?>
    <div class="panel">
      <div class="panel-title"><span>🌤</span> Weather</div>
      <?php if ($weather): ?>
        <div class="weather-grid">
          <?php if ($weather['temp_f'] !== null && $weather['temp_f'] !== ''): ?>
          <div class="w-field" style="grid-column:1/-1">
            <div class="w-label">Temperature</div>
            <div class="w-big"><?= round((float)$weather['temp_f']) ?>°F</div>
          </div>
          <?php endif ?>
          <?php if ($weather['conditions']): ?>
          <div class="w-field">
            <div class="w-label">Conditions</div>
            <div class="w-value"><?= htmlspecialchars($weather['conditions']) ?></div>
          </div>
          <?php endif ?>
          <?php if ($weather['wind_dir'] || $weather['wind_speed']): ?>
          <div class="w-field">
            <div class="w-label">Wind</div>
            <div class="w-value"><?= htmlspecialchars(trim(($weather['wind_dir'] ?? '') . ' ' . ($weather['wind_speed'] ?? ''))) ?></div>
          </div>
          <?php endif ?>
          <?php if ($weather['humidity']): ?>
          <div class="w-field">
            <div class="w-label">Humidity</div>
            <div class="w-value"><?= htmlspecialchars($weather['humidity']) ?>%</div>
          </div>
          <?php endif ?>
        </div>
        <div style="font-size:clamp(10px,1.1vw,12px);color:#333;margin-top:4px">
          Observed <?= ks_age($weather['logged_at']) ?>
          <?php if ($weather['logged_by']): ?> · <?= htmlspecialchars($weather['logged_by']) ?><?php endif ?>
        </div>
      <?php else: ?>
        <div class="no-data">No weather observations logged yet</div>
      <?php endif ?>
    </div>
    <?php endif ?>

    <?php if ($show_forum && !empty($announcements)): ?>
    <div class="panel">
      <div class="panel-title"><span>📢</span> Announcements</div>
      <div class="announce-list">
        <?php foreach ($announcements as $a): ?>
        <div class="announce-item">
          <div class="announce-title"><?= htmlspecialchars($a['title']) ?></div>
          <?php if ($a['body']): ?>
            <div class="announce-body"><?= htmlspecialchars(strip_tags($a['body'])) ?></div>
          <?php endif ?>
          <div class="announce-age"><?= ks_age($a['created_at']) ?></div>
        </div>
        <?php endforeach ?>
      </div>
    </div>
    <?php endif ?>

  </div>
  <?php endif ?>

</div><!-- .main -->

<div class="footer">
  <span>Updated <?= date('H:i:s') ?> · <a href="/" style="color:#333;text-decoration:none">← Home</a></span>
  <div class="refresh-bar">
    <span>Refreshing in <span id="countdown">60</span>s</span>
    <div class="progress-wrap"><div class="progress-fill" id="progress" style="width:100%"></div></div>
  </div>
</div>

<script>
var REFRESH_S = 60;
var remaining = REFRESH_S;

function tick() {
  var now = new Date();
  var hh = String(now.getHours()).padStart(2,'0');
  var mm = String(now.getMinutes()).padStart(2,'0');
  var ss = String(now.getSeconds()).padStart(2,'0');
  document.getElementById('clock').textContent = hh + ':' + mm + ':' + ss;
  var days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
  var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  document.getElementById('date-str').textContent = days[now.getDay()] + ', ' + months[now.getMonth()] + ' ' + now.getDate() + ', ' + now.getFullYear();

  remaining--;
  if (remaining <= 0) { location.reload(); return; }
  document.getElementById('countdown').textContent = remaining;
  document.getElementById('progress').style.width = (remaining / REFRESH_S * 100) + '%';
}

tick();
setInterval(tick, 1000);
</script>
</body>
</html>
