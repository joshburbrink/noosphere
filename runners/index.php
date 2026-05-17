<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();
if (get_setting('show_runners','1') !== '1') { http_response_code(404); exit; }

$is_admin    = !empty($_SESSION['admin']);
$is_readonly = is_readonly();

$db = new SQLite3('/var/lib/noosphere/runners.db');
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("CREATE TABLE IF NOT EXISTS runners (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    name         TEXT NOT NULL,
    destination  TEXT NOT NULL,
    departed_at  INTEGER NOT NULL,
    expected_at  INTEGER,
    returned_at  INTEGER,
    notes        TEXT,
    status       TEXT NOT NULL DEFAULT 'out',
    logged_by    TEXT,
    created_at   INTEGER NOT NULL
)");

$msg = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_readonly) {
    csrf_verify();
    $act = $_POST['act'] ?? '';

    if ($act === 'log') {
        $name        = trim($_POST['name'] ?? '');
        $destination = trim($_POST['destination'] ?? '');
        $notes       = trim($_POST['notes'] ?? '');
        $by          = trim($_POST['logged_by'] ?? '') ?: ($is_admin ? 'Operator' : 'Volunteer');

        // Parse expected return — offset in minutes from now, or blank
        $expected_min = (int)($_POST['expected_min'] ?? 0);
        $expected_at  = $expected_min > 0 ? time() + ($expected_min * 60) : null;

        // Custom departure time — default to now
        $depart_raw  = trim($_POST['departed_at'] ?? '');
        $departed_at = $depart_raw ? strtotime($depart_raw) : time();
        if (!$departed_at) $departed_at = time();

        if (!$name)        { $error = 'Name is required.'; }
        elseif (!$destination) { $error = 'Destination is required.'; }
        else {
            $now = time();
            $s = $db->prepare("INSERT INTO runners (name,destination,departed_at,expected_at,notes,status,logged_by,created_at)
                               VALUES (?,?,?,?,?,'out',?,?)");
            $s->bindValue(1, $name, SQLITE3_TEXT);
            $s->bindValue(2, $destination, SQLITE3_TEXT);
            $s->bindValue(3, $departed_at, SQLITE3_INTEGER);
            $s->bindValue(4, $expected_at, $expected_at ? SQLITE3_INTEGER : SQLITE3_NULL);
            $s->bindValue(5, $notes ?: null, $notes ? SQLITE3_TEXT : SQLITE3_NULL);
            $s->bindValue(6, $by, SQLITE3_TEXT);
            $s->bindValue(7, $now, SQLITE3_INTEGER);
            $s->execute();
            $msg = htmlspecialchars($name) . ' logged out.';
        }
    }

    if ($act === 'return') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $now = time();
            $s = $db->prepare("UPDATE runners SET status='returned',returned_at=? WHERE id=? AND status='out'");
            $s->bindValue(1, $now, SQLITE3_INTEGER);
            $s->bindValue(2, $id, SQLITE3_INTEGER);
            $s->execute();
            $msg = 'Marked as returned.';
        }
    }

    if ($act === 'edit' && $is_admin) {
        $id          = (int)($_POST['id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $destination = trim($_POST['destination'] ?? '');
        $notes       = trim($_POST['notes'] ?? '');
        $expected_min = (int)($_POST['expected_min'] ?? 0);

        $row = $id ? $db->querySingle("SELECT departed_at FROM runners WHERE id=$id", true) : null;
        $expected_at = $expected_min > 0 && $row
            ? $row['departed_at'] + ($expected_min * 60)
            : null;

        if ($id && $name && $destination) {
            $s = $db->prepare("UPDATE runners SET name=?,destination=?,expected_at=?,notes=? WHERE id=?");
            $s->bindValue(1, $name, SQLITE3_TEXT);
            $s->bindValue(2, $destination, SQLITE3_TEXT);
            $s->bindValue(3, $expected_at, $expected_at ? SQLITE3_INTEGER : SQLITE3_NULL);
            $s->bindValue(4, $notes ?: null, $notes ? SQLITE3_TEXT : SQLITE3_NULL);
            $s->bindValue(5, $id, SQLITE3_INTEGER);
            $s->execute();
            $msg = 'Entry updated.';
        }
    }

    if ($act === 'delete' && $is_admin) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) $db->exec("DELETE FROM runners WHERE id=$id");
        $msg = 'Entry deleted.';
    }

    header('Location: /runners/' . ($msg ? '?msg=' . urlencode($msg) : ''));
    exit;
}

if (isset($_GET['msg'])) $msg = htmlspecialchars($_GET['msg']);

$now = time();

// Active runners — status=out
$res  = $db->query("SELECT * FROM runners WHERE status='out' ORDER BY departed_at ASC");
$active = [];
while ($r = $res->fetchArray(SQLITE3_ASSOC)) $active[] = $r;

// Returned — last 24h
$since  = $now - 86400;
$res2   = $db->query("SELECT * FROM runners WHERE status='returned' AND returned_at >= $since ORDER BY returned_at DESC");
$returned = [];
while ($r = $res2->fetchArray(SQLITE3_ASSOC)) $returned[] = $r;

$name_setting = get_setting('instance_name','Noosphere');
$label        = get_setting('runners_label','Runner Board');

function fmt_time($ts) {
    if (!$ts) return '—';
    $diff = time() - $ts;
    $prefix = $diff < 0 ? 'in ' : '';
    $diff = abs($diff);
    if ($diff < 60)   return $prefix . $diff . 's';
    if ($diff < 3600) return $prefix . round($diff/60) . 'm';
    $h = floor($diff/3600); $m = round(($diff%3600)/60);
    return $prefix . $h . 'h' . ($m ? ' '.$m.'m' : '');
}

function expected_label($row) {
    if (!$row['expected_at']) return '—';
    $now  = time();
    $diff = $row['expected_at'] - $now;
    if ($row['status'] === 'returned') return date('H:i', $row['expected_at']);
    if ($diff < 0) return '<span class="overdue-label">overdue ' . fmt_time($row['expected_at']) . '</span>';
    return 'in ' . fmt_time($row['expected_at']) . ' <span style="color:#555">(' . date('H:i',$row['expected_at']) . ')</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($label) ?> — <?= htmlspecialchars($name_setting) ?></title>
<?= csrf_js() ?>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:system-ui,sans-serif; background:#0f0f1a; color:#e0e0e0; min-height:100vh; display:flex; flex-direction:column; }
header { background:#1a1a2e; border-bottom:2px solid #e94560; padding:10px 16px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
header h1 { font-size:15px; color:#e94560; flex:1; }
a.back { color:#888; text-decoration:none; font-size:13px; }
a.back:hover { color:#e94560; }
.msg  { background:#1a3a1a; border:1px solid #2a6a2a; color:#6fcf6f; padding:8px 16px; font-size:13px; }
.err  { background:#3a1a1a; border:1px solid #6a2a2a; color:#cf6f6f; padding:8px 16px; font-size:13px; }
.content { padding:16px; max-width:1000px; width:100%; margin:0 auto; }
.section-head { font-size:12px; font-weight:bold; color:#555; text-transform:uppercase; letter-spacing:.08em; margin:20px 0 10px; display:flex; align-items:center; gap:10px; }
.section-head .badge { background:#e94560; color:#fff; border-radius:10px; padding:2px 8px; font-size:11px; }
.section-head .badge.ok { background:#2a6a2a; }
table { width:100%; border-collapse:collapse; font-size:13px; }
th { text-align:left; color:#555; font-weight:normal; padding:6px 10px; border-bottom:1px solid #1e1e3a; white-space:nowrap; font-size:11px; text-transform:uppercase; letter-spacing:.05em; }
td { padding:9px 10px; border-bottom:1px solid #1a1a2e; vertical-align:middle; }
tr.overdue td { background:#1a0a0a; }
tr.overdue td:first-child { border-left:3px solid #e94560; }
tr:not(.overdue):hover td { background:#141428; }
.name-cell { font-weight:bold; }
.dest-cell { color:#4a9eff; }
.time-cell { white-space:nowrap; color:#aaa; font-size:12px; }
.overdue-label { color:#e94560; font-weight:bold; }
.notes-cell { color:#777; font-size:12px; max-width:200px; }
.by-cell { color:#555; font-size:11px; }
.actions { display:flex; gap:5px; }
.btn-return { background:#2a5a2a; color:#9eff9e; border:none; border-radius:4px; padding:5px 12px; font-size:12px; font-weight:bold; cursor:pointer; }
.btn-return:hover { background:#3a7a3a; }
.btn-sm { background:none; border:1px solid #333; color:#666; border-radius:4px; padding:4px 9px; font-size:11px; cursor:pointer; }
.btn-sm:hover { border-color:#e94560; color:#e94560; }
.log-btn { background:#e94560; color:#fff; border:none; border-radius:5px; padding:6px 16px; font-size:13px; font-weight:bold; cursor:pointer; }
.log-btn:hover { background:#c73652; }
.no-runners { text-align:center; color:#333; padding:24px; font-size:13px; }
.overdue-banner { background:#3a0a0a; border:1px solid #e94560; color:#e94560; border-radius:6px; padding:8px 14px; font-size:13px; margin-bottom:12px; display:flex; align-items:center; gap:8px; }
/* Modal */
.veil { display:none; position:fixed; inset:0; background:rgba(0,0,0,.8); z-index:1000; align-items:center; justify-content:center; }
.veil.open { display:flex; }
.modal { background:#1a1a2e; border:1px solid #2a2a4a; border-radius:10px; padding:20px; width:340px; max-width:95vw; }
.modal h2 { color:#e94560; font-size:14px; margin-bottom:14px; }
.modal label { display:block; font-size:11px; color:#777; margin:10px 0 3px; }
.modal input, .modal select, .modal textarea {
    width:100%; background:#0f0f1a; border:1px solid #2a2a4a; color:#e0e0e0;
    border-radius:5px; padding:7px 9px; font-size:13px; font-family:inherit; }
.modal textarea { resize:vertical; height:55px; }
.modal-btns { display:flex; gap:8px; margin-top:14px; }
.modal-btns button { flex:1; padding:9px; border-radius:5px; border:none; cursor:pointer; font-size:13px; font-weight:bold; }
.btn-red { background:#e94560; color:#fff; }
.btn-cancel { background:#16213e; color:#aaa; border:1px solid #2a2a4a !important; }
.returned-section { opacity:.65; }
.returned-section td { font-size:12px; }
</style>
</head>
<body>
<header>
  <a class="back" href="/">← Home</a>
  <h1>🏃 <?= htmlspecialchars($label) ?></h1>
  <?php if (!$is_readonly): ?>
    <button class="log-btn" onclick="openLog()">+ Log Runner</button>
  <?php endif ?>
</header>

<?php if ($msg): ?><div class="msg"><?= $msg ?></div><?php endif ?>
<?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif ?>

<div class="content">

<?php
$overdue = array_filter($active, fn($r) => $r['expected_at'] && $r['expected_at'] < $now);
if ($overdue):
?>
<div class="overdue-banner">
  ⚠ <?= count($overdue) ?> runner<?= count($overdue) !== 1 ? 's are' : ' is' ?> overdue —
  <?= implode(', ', array_map(fn($r) => htmlspecialchars($r['name']), $overdue)) ?>
</div>
<?php endif ?>

<div class="section-head">
  Out in the Field
  <span class="badge <?= count($active) ? '' : 'ok' ?>"><?= count($active) ?></span>
</div>

<?php if (!$active): ?>
  <div class="no-runners">No runners currently out.</div>
<?php else: ?>
<table>
  <thead>
    <tr>
      <th>Name</th>
      <th>Destination</th>
      <th>Departed</th>
      <th>Expected Return</th>
      <th>Notes</th>
      <th>By</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($active as $r):
    $is_overdue = $r['expected_at'] && $r['expected_at'] < $now;
    $dep_ago    = fmt_time($r['departed_at']);
  ?>
    <tr class="<?= $is_overdue ? 'overdue' : '' ?>">
      <td class="name-cell"><?= htmlspecialchars($r['name']) ?></td>
      <td class="dest-cell"><?= htmlspecialchars($r['destination']) ?></td>
      <td class="time-cell"><?= date('H:i', $r['departed_at']) ?> <span style="color:#555">(<?= $dep_ago ?> ago)</span></td>
      <td class="time-cell"><?= expected_label($r) ?></td>
      <td class="notes-cell"><?= htmlspecialchars($r['notes'] ?? '') ?></td>
      <td class="by-cell"><?= htmlspecialchars($r['logged_by'] ?? '') ?></td>
      <td>
        <div class="actions">
          <?php if (!$is_readonly): ?>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="return">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="btn-return">✓ Returned</button>
          </form>
          <?php endif ?>
          <?php if ($is_admin): ?>
            <button class="btn-sm" onclick="openEdit(<?= (int)$r['id'] ?>,<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)">Edit</button>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this entry?')">
              <?= csrf_field() ?>
              <input type="hidden" name="act" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="btn-sm">✕</button>
            </form>
          <?php endif ?>
        </div>
      </td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>

<?php if ($returned): ?>
<div class="returned-section">
  <div class="section-head" style="margin-top:28px">
    Returned (last 24h)
    <span class="badge ok"><?= count($returned) ?></span>
  </div>
  <table>
    <thead>
      <tr>
        <th>Name</th>
        <th>Destination</th>
        <th>Departed</th>
        <th>Returned</th>
        <th>Notes</th>
        <?php if ($is_admin): ?><th></th><?php endif ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($returned as $r): ?>
      <tr>
        <td class="name-cell"><?= htmlspecialchars($r['name']) ?></td>
        <td class="dest-cell"><?= htmlspecialchars($r['destination']) ?></td>
        <td class="time-cell"><?= date('H:i', $r['departed_at']) ?></td>
        <td class="time-cell" style="color:#2ecc71"><?= $r['returned_at'] ? date('H:i', $r['returned_at']) : '—' ?></td>
        <td class="notes-cell"><?= htmlspecialchars($r['notes'] ?? '') ?></td>
        <?php if ($is_admin): ?>
        <td>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this entry?')">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="delete">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="btn-sm">✕</button>
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

<!-- Log Runner Modal -->
<div class="veil" id="veil-log">
  <div class="modal">
    <h2>Log Runner Out</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="log">
      <label>Name *</label>
      <input type="text" name="name" value="<?= htmlspecialchars($_SESSION['reg_name'] ?? '') ?>"
             placeholder="Who is going out?" maxlength="80" required autofocus>
      <label>Destination *</label>
      <input type="text" name="destination" placeholder="Where are they headed?" maxlength="120" required>
      <label>Expected return</label>
      <select name="expected_min">
        <option value="0">— no ETA —</option>
        <option value="15">15 minutes</option>
        <option value="30">30 minutes</option>
        <option value="45">45 minutes</option>
        <option value="60">1 hour</option>
        <option value="90">1.5 hours</option>
        <option value="120">2 hours</option>
        <option value="180">3 hours</option>
        <option value="240">4 hours</option>
        <option value="360">6 hours</option>
        <option value="480">8 hours</option>
      </select>
      <label>Notes</label>
      <textarea name="notes" placeholder="Vehicle, radio channel, task..."></textarea>
      <label>Logged by</label>
      <input type="text" name="logged_by" value="<?= htmlspecialchars($_SESSION['reg_name'] ?? '') ?>"
             placeholder="Your name" maxlength="60">
      <div class="modal-btns">
        <button type="submit" class="btn-red">Log Out</button>
        <button type="button" class="btn-cancel" onclick="closeModals()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Modal (admin) -->
<?php if ($is_admin): ?>
<div class="veil" id="veil-edit">
  <div class="modal">
    <h2>Edit Entry</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="edit">
      <input type="hidden" name="id" id="edit-id">
      <label>Name *</label>
      <input type="text" name="name" id="edit-name" maxlength="80" required>
      <label>Destination *</label>
      <input type="text" name="destination" id="edit-destination" maxlength="120" required>
      <label>Expected return (from departure time)</label>
      <select name="expected_min" id="edit-expected">
        <option value="0">— no ETA —</option>
        <option value="15">15 minutes</option>
        <option value="30">30 minutes</option>
        <option value="45">45 minutes</option>
        <option value="60">1 hour</option>
        <option value="90">1.5 hours</option>
        <option value="120">2 hours</option>
        <option value="180">3 hours</option>
        <option value="240">4 hours</option>
        <option value="360">6 hours</option>
        <option value="480">8 hours</option>
      </select>
      <label>Notes</label>
      <textarea name="notes" id="edit-notes"></textarea>
      <div class="modal-btns">
        <button type="submit" class="btn-red">Save</button>
        <button type="button" class="btn-cancel" onclick="closeModals()">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php endif ?>

<script>
function openLog()  { document.getElementById('veil-log').classList.add('open'); }
function closeModals() { document.querySelectorAll('.veil').forEach(v => v.classList.remove('open')); }
document.querySelectorAll('.veil').forEach(v => v.addEventListener('click', e => { if (e.target===v) closeModals(); }));

function openEdit(id, row) {
  document.getElementById('edit-id').value          = id;
  document.getElementById('edit-name').value        = row.name;
  document.getElementById('edit-destination').value = row.destination;
  document.getElementById('edit-notes').value       = row.notes || '';
  // Compute expected_min from departed_at and expected_at
  var sel = document.getElementById('edit-expected');
  if (row.expected_at && row.departed_at) {
    var mins = Math.round((row.expected_at - row.departed_at) / 60);
    var opts = sel.options;
    var best = 0;
    for (var i = 0; i < opts.length; i++) {
      if (parseInt(opts[i].value) === mins) { best = i; break; }
    }
    sel.selectedIndex = best;
  } else {
    sel.selectedIndex = 0;
  }
  document.getElementById('veil-edit').classList.add('open');
}

// Auto-refresh every 60s to update overdue status
setTimeout(function(){ location.reload(); }, 60000);
</script>
</body>
</html>
