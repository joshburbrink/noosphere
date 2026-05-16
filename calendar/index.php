<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();

if (get_setting('show_calendar','1') !== '1') { header('Location: /'); exit; }

$msg = $err = '';

try {
    $cdb = new PDO('sqlite:/var/lib/noosphere/calendar.db');
    $cdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $cdb->exec("CREATE TABLE IF NOT EXISTS events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL, event_date TEXT NOT NULL,
        event_time TEXT, location TEXT, notes TEXT,
        created_by TEXT, created_at INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'approved'
    )");
    @$cdb->exec("ALTER TABLE events ADD COLUMN status TEXT NOT NULL DEFAULT 'approved'");
    $cdb->exec("UPDATE events SET status='approved' WHERE status IS NULL OR status=''");
} catch (Exception $e) { $cdb = null; }

// ── Handle submission ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'submit_event') {
    csrf_verify();
    if (get_setting('readonly','0') === '1') {
        $err = 'The hub is currently in read-only mode.';
    } else {
        rate_limit('calendar_submit', 5, 600);
        $title = trim($_POST['title']       ?? '');
        $date  = trim($_POST['edate']       ?? '');
        $time  = trim($_POST['etime']       ?? '');
        $loc   = trim($_POST['eloc']        ?? '');
        $notes = trim($_POST['enotes']      ?? '');
        $name  = trim($_POST['submitted_by'] ?? '');
        if (!$title) {
            $err = 'A title is required.';
        } elseif (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $err = 'A valid date is required.';
        } elseif ($cdb) {
            $cdb->prepare('INSERT INTO events (title,event_date,event_time,location,notes,created_by,created_at,status) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$title, $date, $time ?: null, $loc ?: null, $notes ?: null, $name ?: 'Anonymous', time(), 'pending']);
            $msg = 'Your event suggestion has been submitted for review. An admin will approve it before it appears on the calendar.';
        } else {
            $err = 'Database unavailable. Please try again.';
        }
    }
}

// ── Fetch approved events ─────────────────────────────────────────────────────
$upcoming = $past = [];
if ($cdb) {
    try {
        $upcoming = $cdb->query("SELECT * FROM events WHERE status='approved' AND event_date >= date('now') ORDER BY event_date ASC, event_time ASC")->fetchAll(PDO::FETCH_ASSOC);
        $past     = $cdb->query("SELECT * FROM events WHERE status='approved' AND event_date < date('now') ORDER BY event_date DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

$readonly = get_setting('readonly','0') === '1';

function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Calendar — <?= esc(get_setting('instance_name','Noosphere')) ?></title>
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

/* Submission form */
.suggest-box { background:#1a1a2e; border:1px solid #2a2a4a; border-radius:10px; padding:20px; margin-top:32px; }
.suggest-box h2 { margin-bottom:16px; }
.form-row { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px; }
.form-row > div { flex:1; min-width:140px; display:flex; flex-direction:column; gap:4px; }
label { font-size:12px; color:#888; }
input[type=text], input[type=date], input[type=time], textarea {
    background:#111126; border:1px solid #2a2a4a; border-radius:6px;
    color:#e0e0e0; padding:8px 10px; font-size:13px; width:100%;
    font-family:inherit;
}
input:focus, textarea:focus { outline:none; border-color:#e94560; }
textarea { resize:vertical; }
.btn { background:#e94560; color:#fff; border:none; border-radius:6px; padding:9px 22px; font-size:13px; cursor:pointer; }
.btn:hover { background:#c73652; }
.msg-ok  { background:#1a2e1a; border:1px solid #2ecc71; color:#90d090; border-radius:6px; padding:10px 14px; font-size:13px; margin-bottom:16px; }
.msg-err { background:#2e1a1a; border:1px solid #e94560; color:#e09090; border-radius:6px; padding:10px 14px; font-size:13px; margin-bottom:16px; }
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
    $ts      = strtotime($ev['event_date']);
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

  <?php if (!$readonly): ?>
  <div class="suggest-box">
    <h2>Suggest an Event</h2>
    <?php if ($msg): ?><div class="msg-ok"><?= esc($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="msg-err"><?= esc($err) ?></div><?php endif; ?>
    <?php if (!$msg): ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="submit_event">
      <div class="form-row">
        <div style="flex:2"><label>Event title *</label><input type="text" name="title" required maxlength="120" placeholder="Community meeting, supply run…"></div>
        <div><label>Your name</label><input type="text" name="submitted_by" maxlength="60" placeholder="Optional"></div>
      </div>
      <div class="form-row">
        <div><label>Date *</label><input type="date" name="edate" required></div>
        <div><label>Time</label><input type="time" name="etime"></div>
        <div style="flex:2"><label>Location</label><input type="text" name="eloc" maxlength="120" placeholder="Shelter B, Courthouse square…"></div>
      </div>
      <div style="margin-bottom:14px"><label>Details / notes</label><textarea name="enotes" rows="2" maxlength="500" placeholder="Additional info…"></textarea></div>
      <button type="submit" class="btn">Submit for Review</button>
      <span style="font-size:12px;color:#555;margin-left:12px">An admin will approve before it goes live.</span>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>
</body>
</html>
