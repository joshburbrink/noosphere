<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();
if (get_setting('show_weather','1') !== '1') { http_response_code(404); exit; }

$is_admin    = !empty($_SESSION['admin']);
$is_readonly = is_readonly();

$db = new SQLite3('/var/lib/noosphere/weather.db');
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("CREATE TABLE IF NOT EXISTS weather_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    logged_at  INTEGER NOT NULL,
    temp_f     REAL,
    conditions TEXT,
    wind_dir   TEXT,
    wind_speed TEXT,
    humidity   TEXT,
    notes      TEXT,
    logged_by  TEXT,
    source     TEXT
)");
@$db->exec("ALTER TABLE weather_log ADD COLUMN source TEXT");

$CONDITIONS = ['Clear','Partly Cloudy','Cloudy','Overcast','Rain','Heavy Rain',
               'Thunderstorm','Snow','Fog','Smoke','Haze','Other'];
$WIND_DIRS  = ['','N','NE','E','SE','S','SW','W','NW','Variable'];

$msg = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_readonly) {
    csrf_verify();
    $act = $_POST['act'] ?? '';

    if ($act === 'add') {
        $temp       = trim($_POST['temp_f'] ?? '');
        $conditions = in_array($_POST['conditions'] ?? '', $CONDITIONS) ? $_POST['conditions'] : '';
        $wind_dir   = in_array($_POST['wind_dir'] ?? '', $WIND_DIRS) ? $_POST['wind_dir'] : '';
        $wind_speed = trim($_POST['wind_speed'] ?? '');
        $humidity   = trim($_POST['humidity'] ?? '');
        $notes      = trim($_POST['notes'] ?? '');
        $by         = trim($_POST['logged_by'] ?? '') ?: ($is_admin ? 'Operator' : 'Volunteer');
        $temp_val   = ($temp !== '' && is_numeric($temp)) ? (float)$temp : null;

        $s = $db->prepare("INSERT INTO weather_log (logged_at,temp_f,conditions,wind_dir,wind_speed,humidity,notes,logged_by)
                           VALUES (?,?,?,?,?,?,?,?)");
        $s->bindValue(1, time(), SQLITE3_INTEGER);
        $s->bindValue(2, $temp_val, $temp_val !== null ? SQLITE3_FLOAT : SQLITE3_NULL);
        $s->bindValue(3, $conditions ?: null, $conditions ? SQLITE3_TEXT : SQLITE3_NULL);
        $s->bindValue(4, $wind_dir ?: null, $wind_dir ? SQLITE3_TEXT : SQLITE3_NULL);
        $s->bindValue(5, $wind_speed ?: null, $wind_speed ? SQLITE3_TEXT : SQLITE3_NULL);
        $s->bindValue(6, $humidity ?: null, $humidity ? SQLITE3_TEXT : SQLITE3_NULL);
        $s->bindValue(7, $notes ?: null, $notes ? SQLITE3_TEXT : SQLITE3_NULL);
        $s->bindValue(8, $by, SQLITE3_TEXT);
        $s->execute();
        $msg = 'Entry logged.';
    }

    if ($act === 'delete' && $is_admin) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) $db->exec("DELETE FROM weather_log WHERE id=$id");
        $msg = 'Entry deleted.';
    }
}

// Fetch recent entries (last 7 days default, or all)
$show_all = isset($_GET['all']);
$since    = $show_all ? 0 : (time() - 7 * 86400);
$rows     = $db->query("SELECT * FROM weather_log WHERE logged_at >= $since ORDER BY logged_at DESC")->fetchArray(SQLITE3_ASSOC)
            ? [] : [];
$result   = $db->query("SELECT * FROM weather_log WHERE logged_at >= $since ORDER BY logged_at DESC");
$rows     = [];
while ($r = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;

$name    = get_setting('instance_name','Noosphere');
$site_label = get_setting('weather_label','Weather Log');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($site_label) ?> — <?= htmlspecialchars($name) ?></title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:sans-serif; background:#1a1a2e; color:#eee; min-height:100vh; padding:1.5rem; }
.topbar { display:flex; align-items:center; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap; }
.topbar h1 { font-size:1.5rem; color:#e94560; flex:1; }
a.back { color:#aaa; text-decoration:none; font-size:13px; }
a.back:hover { color:#e94560; }
.msg  { background:#1a3a1a; border:1px solid #2ecc71; color:#2ecc71; padding:8px 14px; border-radius:6px; margin-bottom:1rem; font-size:13px; }
.card { background:#16213e; border:1px solid #2a2a4a; border-radius:10px; padding:1.25rem; margin-bottom:1.25rem; }
.card h2 { font-size:1rem; color:#e94560; margin-bottom:1rem; }
.form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:10px; }
label { display:block; font-size:12px; color:#aaa; margin-bottom:3px; }
input[type=text], input[type=number], select, textarea {
  width:100%; background:#0f0f1a; border:1px solid #2a2a4a; border-radius:5px;
  color:#eee; padding:7px 10px; font-size:13px; }
textarea { resize:vertical; min-height:60px; }
.form-full { grid-column:1/-1; }
.btn { background:#e94560; color:#fff; border:none; border-radius:6px; padding:8px 20px; font-size:13px; cursor:pointer; }
.btn:hover { background:#c73652; }
.btn-sm { background:none; border:1px solid #555; color:#aaa; border-radius:4px; padding:3px 10px; font-size:11px; cursor:pointer; }
.btn-sm:hover { border-color:#e94560; color:#e94560; }
table { width:100%; border-collapse:collapse; font-size:13px; }
th { text-align:left; color:#aaa; font-weight:normal; padding:6px 8px; border-bottom:1px solid #2a2a4a; white-space:nowrap; }
td { padding:7px 8px; border-bottom:1px solid #1a1a2e; vertical-align:top; }
tr:hover td { background:#1a1f35; }
.cond-badge { display:inline-block; background:#1a2a3a; border:1px solid #2a4a6a; border-radius:4px; padding:2px 8px; font-size:11px; color:#7ab; }
.notes-cell { color:#aaa; max-width:260px; }
.no-data { text-align:center; color:#555; padding:2rem; }
.filter-bar { display:flex; gap:10px; align-items:center; margin-bottom:1rem; flex-wrap:wrap; }
.filter-bar input { flex:1; min-width:160px; }
</style>
</head>
<body>
<div class="topbar">
  <h1>⛅ <?= htmlspecialchars($site_label) ?></h1>
  <a class="back" href="/">← Home</a>
</div>

<?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif ?>

<?php include __DIR__ . "/_nwr_section.php"; ?>

<?php if (!$is_readonly): ?>
<div class="card">
  <h2>Log Observation</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="add">
    <div class="form-grid">
      <div>
        <label>Temperature (°F)</label>
        <input type="number" name="temp_f" step="0.1" placeholder="e.g. 72.5">
      </div>
      <div>
        <label>Conditions</label>
        <select name="conditions">
          <option value="">— select —</option>
          <?php foreach ($CONDITIONS as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
          <?php endforeach ?>
        </select>
      </div>
      <div>
        <label>Wind Direction</label>
        <select name="wind_dir">
          <?php foreach ($WIND_DIRS as $d): ?>
            <option value="<?= htmlspecialchars($d) ?>"><?= $d ?: '— none —' ?></option>
          <?php endforeach ?>
        </select>
      </div>
      <div>
        <label>Wind Speed</label>
        <input type="text" name="wind_speed" placeholder="e.g. 15 mph, calm">
      </div>
      <div>
        <label>Humidity %</label>
        <input type="text" name="humidity" placeholder="e.g. 65">
      </div>
      <div>
        <label>Logged by</label>
        <input type="text" name="logged_by" value="<?= htmlspecialchars($_SESSION['reg_name'] ?? '') ?>"
               placeholder="Your name" maxlength="60">
      </div>
      <div class="form-full">
        <label>Notes</label>
        <textarea name="notes" placeholder="Additional observations, hazards, visibility..."></textarea>
      </div>
      <div class="form-full">
        <button type="submit" class="btn">Log Observation</button>
      </div>
    </div>
  </form>
</div>
<?php endif ?>

<div class="card">
  <h2>Observation Log <?= $show_all ? '(all time)' : '(last 7 days)' ?></h2>

  <div class="filter-bar">
    <input type="text" id="search" placeholder="Filter by conditions, notes, name..." oninput="filterLog()">
    <a href="?<?= $show_all ? '' : 'all' ?>" style="font-size:12px;color:#aaa;white-space:nowrap">
      <?= $show_all ? 'Last 7 days' : 'Show all' ?>
    </a>
  </div>

  <?php if (!$rows): ?>
    <div class="no-data">No observations logged yet.</div>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table id="log-table">
    <thead>
      <tr>
        <th>Time</th>
        <th>Temp</th>
        <th>Conditions</th>
        <th>Wind</th>
        <th>Humidity</th>
        <th>Notes</th>
        <th>By</th>
        <?php if ($is_admin): ?><th></th><?php endif ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td style="white-space:nowrap;color:#aaa"><?= date('m/d H:i', $r['logged_at']) ?></td>
        <td style="white-space:nowrap"><?= $r['temp_f'] !== null ? htmlspecialchars($r['temp_f']) . '°F' : '—' ?></td>
        <td><?= $r['conditions'] ? '<span class="cond-badge">'.htmlspecialchars($r['conditions']).'</span>' : '—' ?></td>
        <td style="white-space:nowrap"><?= htmlspecialchars(trim(($r['wind_dir'] ?? '') . ' ' . ($r['wind_speed'] ?? ''))) ?: '—' ?></td>
        <td><?= $r['humidity'] ? htmlspecialchars($r['humidity']).'%' : '—' ?></td>
        <td class="notes-cell">
          <?php
            $note = $r['notes'] ?? '';
            if (($r['source'] ?? '') === 'nwr-auto' && str_starts_with($note, '[NWR transcript] ')):
              $transcript = substr($note, strlen('[NWR transcript] '));
          ?>
            <details style="font-size:11px">
              <summary style="cursor:pointer;color:#7ad">📝 View transcript</summary>
              <div style="margin-top:4px;color:#888;line-height:1.5"><?= htmlspecialchars($transcript) ?></div>
            </details>
          <?php else: ?>
            <?= htmlspecialchars($note) ?>
          <?php endif ?>
        </td>
        <td style="white-space:nowrap;color:#aaa">
          <?= htmlspecialchars($r['logged_by'] ?? '') ?>
          <?php if (($r['source'] ?? '') === 'rtl433'): ?>
            <span style="display:inline-block;background:#1a2a3a;border:1px solid #2a4a6a;border-radius:3px;padding:1px 5px;font-size:10px;color:#4af;margin-left:4px">rtl_433</span>
          <?php elseif (($r['source'] ?? '') === 'nwr-auto'): ?>
            <span style="display:inline-block;background:#1a2a1a;border:1px solid #2a5a2a;border-radius:3px;padding:1px 5px;font-size:10px;color:#2ecc71;margin-left:4px">NWR Auto</span>
          <?php endif ?>
        </td>
        <?php if ($is_admin): ?>
        <td>
          <form method="post" onsubmit="return confirm('Delete this entry?')">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="delete">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="btn-sm">Del</button>
          </form>
        </td>
        <?php endif ?>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  </div>
  <?php endif ?>
</div>

<script>
function filterLog() {
  var q = document.getElementById('search').value.toLowerCase();
  document.querySelectorAll('#log-table tbody tr').forEach(function(row) {
    row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}
</script>
</body>
</html>
