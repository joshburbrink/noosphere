<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();

try {
    $cdb = new PDO('sqlite:/var/lib/noosphere/calendar.db');
    $cdb->exec("CREATE TABLE IF NOT EXISTS events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL, event_date TEXT NOT NULL,
        event_time TEXT, location TEXT, notes TEXT,
        created_by TEXT, created_at INTEGER NOT NULL
    )");
    $upcoming = $cdb->query("SELECT * FROM events WHERE event_date >= date('now') ORDER BY event_date ASC, event_time ASC")->fetchAll(PDO::FETCH_ASSOC);
    $past     = $cdb->query("SELECT * FROM events WHERE event_date < date('now') ORDER BY event_date DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $upcoming = []; $past = []; }

function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES); }
function fmt_date($d) {
    $ts = strtotime($d);
    return $ts ? date('D, M j', $ts) : $d;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Calendar — Noosphere</title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:system-ui,sans-serif; background:#0f0f1a; color:#e0e0e0; min-height:100vh; }
header { background:#1a1a2e; border-bottom:2px solid #e94560; padding:10px 16px; display:flex; align-items:center; gap:12px; }
header h1 { font-size:15px; color:#e94560; }
header a { color:#888; text-decoration:none; font-size:13px; }
header a:hover { color:#e94560; }
.container { max-width:700px; margin:0 auto; padding:24px 16px; }
h2 { font-size:14px; color:#e94560; text-transform:uppercase; letter-spacing:.05em; margin-bottom:14px; border-bottom:1px solid #2a2a4a; padding-bottom:7px; }
.event-list { display:flex; flex-direction:column; gap:8px; margin-bottom:28px; }
.event-card { background:#1a1a2e; border:1px solid #2a2a4a; border-radius:8px; padding:14px 16px; display:flex; gap:14px; align-items:flex-start; }
.event-card.today { border-color:#e94560; }
.event-date { flex-shrink:0; text-align:center; width:70px; }
.event-date .day { font-size:22px; font-weight:bold; color:#e94560; line-height:1; }
.event-date .month { font-size:11px; color:#888; text-transform:uppercase; }
.event-date .time { font-size:12px; color:#aaa; margin-top:4px; }
.event-body { flex:1; }
.event-title { font-size:15px; font-weight:bold; margin-bottom:4px; }
.event-loc { font-size:12px; color:#888; margin-bottom:4px; }
.event-notes { font-size:13px; color:#aaa; }
.empty { color:#666; font-size:13px; padding:20px 0; }
.past { opacity:0.6; }
.admin-note { font-size:12px; color:#555; text-align:center; margin-top:24px; }
.admin-note a { color:#666; text-decoration:none; }
.admin-note a:hover { color:#e94560; }
</style>
</head>
<body>
<header>
  <a href="/">← Home</a>
  <h1>Community Calendar</h1>
</header>
<div class="container">

  <h2>Upcoming Events</h2>
  <?php if (!$upcoming): ?>
    <div class="empty">No upcoming events scheduled.</div>
  <?php else: ?>
  <div class="event-list">
  <?php foreach ($upcoming as $ev):
    $ts    = strtotime($ev['event_date']);
    $isToday = date('Y-m-d') === $ev['event_date'];
  ?>
    <div class="event-card<?= $isToday ? ' today' : '' ?>">
      <div class="event-date">
        <div class="day"><?= date('j', $ts) ?></div>
        <div class="month"><?= date('M', $ts) ?></div>
        <?php if ($ev['event_time']): ?><div class="time"><?= esc($ev['event_time']) ?></div><?php endif; ?>
      </div>
      <div class="event-body">
        <div class="event-title"><?= esc($ev['title']) ?><?= $isToday ? ' <span style="color:#e94560;font-size:12px">— TODAY</span>' : '' ?></div>
        <?php if ($ev['location']): ?><div class="event-loc">📍 <?= esc($ev['location']) ?></div><?php endif; ?>
        <?php if ($ev['notes']): ?><div class="event-notes"><?= esc($ev['notes']) ?></div><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($past): ?>
  <h2>Past Events</h2>
  <div class="event-list past">
  <?php foreach ($past as $ev): $ts = strtotime($ev['event_date']); ?>
    <div class="event-card">
      <div class="event-date">
        <div class="day"><?= date('j', $ts) ?></div>
        <div class="month"><?= date('M', $ts) ?></div>
      </div>
      <div class="event-body">
        <div class="event-title"><?= esc($ev['title']) ?></div>
        <?php if ($ev['location']): ?><div class="event-loc">📍 <?= esc($ev['location']) ?></div><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="admin-note">Events are managed by administrators. <a href="/admin/">Admin panel →</a></div>
</div>
</body>
</html>
