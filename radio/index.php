<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
require_once '/var/www/noosphere/shared/identity.php';
sec_session_start();
if (get_setting('show_radio','1') !== '1') { http_response_code(404); exit; }

$is_admin    = legacy_is_admin();
$is_readonly = is_readonly();

$db = new SQLite3('/var/lib/noosphere/radio.db');
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("CREATE TABLE IF NOT EXISTS radio_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    logged_at  INTEGER NOT NULL,
    callsign   TEXT NOT NULL,
    frequency  TEXT,
    signal     INTEGER,
    traffic    TEXT,
    notes      TEXT,
    logged_by  TEXT
)");
@$db->exec("ALTER TABLE radio_log ADD COLUMN source TEXT");
@$db->exec("ALTER TABLE radio_log ADD COLUMN transcript TEXT");
@$db->exec("ALTER TABLE radio_log ADD COLUMN clip_path TEXT");
@$db->exec("ALTER TABLE radio_log ADD COLUMN duration REAL");

$SIGNAL_LABELS = [1=>'1  -  Barely readable', 2=>'2  -  Readable with effort',
                  3=>'3  -  Readable', 4=>'4  -  Good', 5=>'5  -  Excellent'];

$msg = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_readonly) {
    csrf_verify();
    $act = $_POST['act'] ?? '';

    if ($act === 'add') {
        $callsign  = trim($_POST['callsign'] ?? '');
        $frequency = trim($_POST['frequency'] ?? '');
        $signal    = (int)($_POST['signal'] ?? 0);
        $traffic   = trim($_POST['traffic'] ?? '');
        $notes     = trim($_POST['notes'] ?? '');
        $by        = trim($_POST['logged_by'] ?? '') ?: ($is_admin ? 'Operator' : 'Volunteer');

        if (!$callsign) {
            $error = 'Callsign is required.';
        } else {
            $signal_val = ($signal >= 1 && $signal <= 5) ? $signal : null;
            $s = $db->prepare("INSERT INTO radio_log (logged_at,callsign,frequency,signal,traffic,notes,logged_by)
                               VALUES (?,?,?,?,?,?,?)");
            $s->bindValue(1, time(), SQLITE3_INTEGER);
            $s->bindValue(2, $callsign, SQLITE3_TEXT);
            $s->bindValue(3, $frequency ?: null, $frequency ? SQLITE3_TEXT : SQLITE3_NULL);
            $s->bindValue(4, $signal_val, $signal_val !== null ? SQLITE3_INTEGER : SQLITE3_NULL);
            $s->bindValue(5, $traffic ?: null, $traffic ? SQLITE3_TEXT : SQLITE3_NULL);
            $s->bindValue(6, $notes ?: null, $notes ? SQLITE3_TEXT : SQLITE3_NULL);
            $s->bindValue(7, $by, SQLITE3_TEXT);
            $s->execute();
            $msg = 'Contact logged.';
        }
    }

    if ($act === 'delete') {
        require_capability('radio.delete_log');
        $id = (int)($_POST['id'] ?? 0);
        if ($id) $db->exec("DELETE FROM radio_log WHERE id=$id");
        $msg = 'Entry deleted.';
    }
}

$show_all = isset($_GET['all']);
$since    = $show_all ? 0 : (time() - 7 * 86400);
$result   = $db->query("SELECT * FROM radio_log WHERE logged_at >= $since ORDER BY logged_at DESC");
$rows = [];
while ($r = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;

$name       = get_setting('instance_name','Noosphere');
$site_label = get_setting('radio_label','Radio Net Log');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($site_label) ?>  -  <?= htmlspecialchars($name) ?></title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:sans-serif; background:#1a1a2e; color:#eee; min-height:100vh; padding:1.5rem; }
.topbar { display:flex; align-items:center; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap; }
.topbar h1 { font-size:1.5rem; color:#e94560; flex:1; }
a.back { color:#aaa; text-decoration:none; font-size:13px; }
a.back:hover { color:#e94560; }
.msg   { background:#1a3a1a; border:1px solid #2ecc71; color:#2ecc71; padding:8px 14px; border-radius:6px; margin-bottom:1rem; font-size:13px; }
.error { background:#3a0a0a; border:1px solid #e94560; color:#e94560; padding:8px 14px; border-radius:6px; margin-bottom:1rem; font-size:13px; }
.card { background:#16213e; border:1px solid #2a2a4a; border-radius:10px; padding:1.25rem; margin-bottom:1.25rem; }
.card h2 { font-size:1rem; color:#e94560; margin-bottom:1rem; }
.form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:10px; }
label { display:block; font-size:12px; color:#aaa; margin-bottom:3px; }
input[type=text], select, textarea {
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
.callsign { font-family:monospace; font-size:14px; font-weight:bold; color:#7af; }
.freq     { font-family:monospace; font-size:12px; color:#aaa; }
.sig-dots { letter-spacing:1px; }
.notes-cell { color:#aaa; max-width:260px; }
.no-data { text-align:center; color:#555; padding:2rem; }
.filter-bar { display:flex; gap:10px; align-items:center; margin-bottom:1rem; flex-wrap:wrap; }
.filter-bar input { flex:1; min-width:160px; }
</style>
</head>
<body>
<div class="topbar">
  <h1>📻 <?= htmlspecialchars($site_label) ?></h1>
  <a class="back" href="/">← Home</a>
  <a href="/radio/reference/" style="font-size:13px;color:#7ad;text-decoration:none;border:1px solid #2a2a4a;border-radius:4px;padding:4px 10px;">📡 Reference</a>
  <a href="/radio/program/" style="font-size:13px;color:#2ecc71;text-decoration:none;border:1px solid #2a2a4a;border-radius:4px;padding:4px 10px;">⚡ Program Radio</a>
</div>

<?php if ($msg):   ?><div class="msg"><?=   htmlspecialchars($msg)   ?></div><?php endif ?>

<?php include __DIR__ . "/_scanner_section.php"; ?>
<?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif ?>

<?php if (!$is_readonly): ?>
<div class="card">
  <h2>Log Contact</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="add">
    <div class="form-grid">
      <div>
        <label>Callsign / Station *</label>
        <input type="text" name="callsign" placeholder="KD9XYZ, Net Control, Team 2..." maxlength="40" required>
      </div>
      <div>
        <label>Frequency / Channel</label>
        <input type="text" name="frequency" placeholder="146.520 MHz, Ch 3..." maxlength="40">
      </div>
      <div>
        <label>Signal Strength (RS)</label>
        <select name="signal">
          <option value="0"> -  not rated  - </option>
          <?php foreach ($SIGNAL_LABELS as $v => $l): ?>
            <option value="<?= $v ?>"><?= htmlspecialchars($l) ?></option>
          <?php endforeach ?>
        </select>
      </div>
      <div>
        <label>Logged by</label>
        <input type="text" name="logged_by" value="<?= htmlspecialchars($_SESSION['reg_name'] ?? '') ?>"
               placeholder="Your name / callsign" maxlength="60">
      </div>
      <div class="form-full">
        <label>Traffic / Message</label>
        <textarea name="traffic" placeholder="What was passed  -  status update, request, info..."></textarea>
      </div>
      <div class="form-full">
        <label>Notes</label>
        <textarea name="notes" placeholder="Noise, skip, interference, relay used..."></textarea>
      </div>
      <div class="form-full">
        <button type="submit" class="btn">Log Contact</button>
      </div>
    </div>
  </form>
</div>
<?php endif ?>

<div class="card">
  <h2>Radio Log <?= $show_all ? '(all time)' : '(last 7 days)' ?></h2>

  <div class="filter-bar">
    <input type="text" id="search" placeholder="Filter by callsign, frequency, traffic..." oninput="filterLog()">
    <a href="?<?= $show_all ? '' : 'all' ?>" style="font-size:12px;color:#aaa;white-space:nowrap">
      <?= $show_all ? 'Last 7 days' : 'Show all' ?>
    </a>
  </div>

  <?php if (!$rows): ?>
    <div class="no-data">No contacts logged yet.</div>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table id="log-table">
    <thead>
      <tr>
        <th>Time</th>
        <th>Callsign</th>
        <th>Frequency</th>
        <th>Signal</th>
        <th>Traffic</th>
        <th>Notes</th>
        <th>By</th>
        <?php if ($is_admin): ?><th></th><?php endif ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
      $sig = (int)($r['signal'] ?? 0);
      $sig_html = $sig ? str_repeat('●',$sig).str_repeat('○',5-$sig) : ' - ';
      $is_auto = ($r['source'] ?? '') === 'monitor-auto';
    ?>
      <tr>
        <td style="white-space:nowrap;color:#aaa">
          <?= date('m/d H:i', $r['logged_at']) ?>
          <?php if ($is_auto): ?>
            <br><span style="font-size:10px;background:#1a2a1a;border:1px solid #2a5a2a;border-radius:3px;padding:1px 5px;color:#2ecc71">SDR Auto</span>
          <?php endif ?>
        </td>
        <td><span class="callsign"><?= htmlspecialchars($r['callsign']) ?></span></td>
        <td><span class="freq"><?= htmlspecialchars($r['frequency'] ?? ' - ') ?></span></td>
        <td><span class="sig-dots" title="<?= $sig ? htmlspecialchars($SIGNAL_LABELS[$sig]) : '' ?>"><?= $sig_html ?></span></td>
        <td class="notes-cell">
          <?php if ($is_auto && !empty($r['transcript'])): ?>
            <details>
              <summary style="cursor:pointer;color:#7ad">📝 Transcript<?= !empty($r['duration']) ? ' ('.round($r['duration']).'s)' : '' ?></summary>
              <div style="margin-top:4px;color:#888;line-height:1.5;font-size:12px"><?= htmlspecialchars($r['transcript']) ?></div>
            </details>
          <?php else: ?>
            <?= htmlspecialchars($r['traffic'] ?? '') ?>
          <?php endif ?>
        </td>
        <td class="notes-cell"><?= htmlspecialchars($r['notes'] ?? '') ?></td>
        <td style="white-space:nowrap;color:#aaa"><?= htmlspecialchars($r['logged_by'] ?? '') ?></td>
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
