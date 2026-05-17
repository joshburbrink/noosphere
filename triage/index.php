<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();
if (get_setting('show_triage','0') !== '1') { http_response_code(404); exit; }

$is_admin    = !empty($_SESSION['admin']);
$is_readonly = is_readonly();

$db = new SQLite3('/var/lib/noosphere/triage.db');
$db->exec('PRAGMA journal_mode=WAL');
$db->exec("CREATE TABLE IF NOT EXISTS patients (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    tag_id      TEXT NOT NULL,
    name        TEXT,
    age         TEXT,
    priority    TEXT NOT NULL DEFAULT 'minor',
    complaint   TEXT,
    caregiver   TEXT,
    location    TEXT,
    disposition TEXT NOT NULL DEFAULT 'on-site',
    notes       TEXT,
    created_at  INTEGER NOT NULL,
    updated_at  INTEGER NOT NULL
)");

$PRIORITIES = [
    'immediate' => ['label'=>'Immediate', 'color'=>'#e94560', 'bg'=>'#2a0a12', 'tag'=>'I'],
    'delayed'   => ['label'=>'Delayed',   'color'=>'#f39c12', 'bg'=>'#2a1a00', 'tag'=>'D'],
    'minor'     => ['label'=>'Minor',     'color'=>'#2ecc71', 'bg'=>'#0a2a12', 'tag'=>'M'],
    'expectant' => ['label'=>'Expectant', 'color'=>'#888',    'bg'=>'#111',    'tag'=>'E'],
];
$DISPOSITIONS = ['on-site'=>'On-site', 'transferred'=>'Transferred', 'deceased'=>'Deceased'];

function next_tag_id($db) {
    $last = $db->querySingle("SELECT tag_id FROM patients ORDER BY id DESC LIMIT 1");
    if (!$last) return 'T-001';
    if (preg_match('/^T-(\d+)$/', $last, $m)) {
        return 'T-' . str_pad((int)$m[1] + 1, 3, '0', STR_PAD_LEFT);
    }
    $count = (int)$db->querySingle("SELECT COUNT(*) FROM patients");
    return 'T-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);
}

$msg = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin && !$is_readonly) {
    csrf_verify();
    $act = $_POST['act'] ?? '';

    if ($act === 'add') {
        $name        = trim($_POST['name'] ?? '');
        $age         = trim($_POST['age'] ?? '');
        $priority    = array_key_exists($_POST['priority'] ?? '', $PRIORITIES) ? $_POST['priority'] : 'minor';
        $complaint   = trim($_POST['complaint'] ?? '');
        $caregiver   = trim($_POST['caregiver'] ?? '');
        $location    = trim($_POST['location'] ?? '');
        $notes       = trim($_POST['notes'] ?? '');
        $tag_id      = next_tag_id($db);
        $now         = time();

        $s = $db->prepare("INSERT INTO patients
            (tag_id,name,age,priority,complaint,caregiver,location,disposition,notes,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,'on-site',?,?,?)");
        $s->bindValue(1,  $tag_id,    SQLITE3_TEXT);
        $s->bindValue(2,  $name ?: null,      $name    ? SQLITE3_TEXT : SQLITE3_NULL);
        $s->bindValue(3,  $age  ?: null,      $age     ? SQLITE3_TEXT : SQLITE3_NULL);
        $s->bindValue(4,  $priority,  SQLITE3_TEXT);
        $s->bindValue(5,  $complaint ?: null, $complaint ? SQLITE3_TEXT : SQLITE3_NULL);
        $s->bindValue(6,  $caregiver ?: null, $caregiver ? SQLITE3_TEXT : SQLITE3_NULL);
        $s->bindValue(7,  $location  ?: null, $location  ? SQLITE3_TEXT : SQLITE3_NULL);
        $s->bindValue(8,  $notes     ?: null, $notes     ? SQLITE3_TEXT : SQLITE3_NULL);
        $s->bindValue(9,  $now,       SQLITE3_INTEGER);
        $s->bindValue(10, $now,       SQLITE3_INTEGER);
        $s->execute();
        $msg = $tag_id . ' logged — ' . $PRIORITIES[$priority]['label'];
    }

    if ($act === 'edit') {
        $id          = (int)($_POST['id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $age         = trim($_POST['age'] ?? '');
        $priority    = array_key_exists($_POST['priority'] ?? '', $PRIORITIES) ? $_POST['priority'] : 'minor';
        $complaint   = trim($_POST['complaint'] ?? '');
        $caregiver   = trim($_POST['caregiver'] ?? '');
        $location    = trim($_POST['location'] ?? '');
        $disposition = array_key_exists($_POST['disposition'] ?? '', $DISPOSITIONS) ? $_POST['disposition'] : 'on-site';
        $notes       = trim($_POST['notes'] ?? '');
        if ($id) {
            $s = $db->prepare("UPDATE patients SET
                name=?,age=?,priority=?,complaint=?,caregiver=?,location=?,disposition=?,notes=?,updated_at=?
                WHERE id=?");
            $s->bindValue(1,  $name ?: null,      $name      ? SQLITE3_TEXT : SQLITE3_NULL);
            $s->bindValue(2,  $age  ?: null,       $age       ? SQLITE3_TEXT : SQLITE3_NULL);
            $s->bindValue(3,  $priority,   SQLITE3_TEXT);
            $s->bindValue(4,  $complaint ?: null,  $complaint  ? SQLITE3_TEXT : SQLITE3_NULL);
            $s->bindValue(5,  $caregiver ?: null,  $caregiver  ? SQLITE3_TEXT : SQLITE3_NULL);
            $s->bindValue(6,  $location  ?: null,  $location   ? SQLITE3_TEXT : SQLITE3_NULL);
            $s->bindValue(7,  $disposition, SQLITE3_TEXT);
            $s->bindValue(8,  $notes    ?: null,   $notes      ? SQLITE3_TEXT : SQLITE3_NULL);
            $s->bindValue(9,  time(),      SQLITE3_INTEGER);
            $s->bindValue(10, $id,         SQLITE3_INTEGER);
            $s->execute();
            $msg = 'Patient updated.';
        }
    }

    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) $db->exec("DELETE FROM patients WHERE id=$id");
        $msg = 'Record deleted.';
    }

    header('Location: /triage/' . ($msg ? '?msg=' . urlencode($msg) : ''));
    exit;
}

if (isset($_GET['msg'])) $msg = htmlspecialchars($_GET['msg']);

$result = $db->query("SELECT * FROM patients ORDER BY
    CASE priority WHEN 'immediate' THEN 1 WHEN 'delayed' THEN 2 WHEN 'minor' THEN 3 WHEN 'expectant' THEN 4 END,
    created_at ASC");
$patients = [];
while ($r = $result->fetchArray(SQLITE3_ASSOC)) $patients[] = $r;

$counts = ['immediate'=>0, 'delayed'=>0, 'minor'=>0, 'expectant'=>0];
foreach ($patients as $p) {
    if (isset($counts[$p['priority']])) $counts[$p['priority']]++;
}

$name = get_setting('instance_name', 'Noosphere');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Triage Log — <?= htmlspecialchars($name) ?></title>
<?= csrf_js() ?>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:system-ui,sans-serif; background:#0f0f1a; color:#e0e0e0; min-height:100vh; display:flex; flex-direction:column; }
header { background:#1a1a2e; border-bottom:2px solid #e94560; padding:10px 16px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
header h1 { font-size:15px; color:#e94560; flex:1; }
a.back { color:#888; text-decoration:none; font-size:13px; }
a.back:hover { color:#e94560; }
.msg { background:#1a3a1a; border:1px solid #2ecc71; color:#2ecc71; padding:8px 16px; font-size:13px; }
.content { padding:16px; max-width:1100px; width:100%; margin:0 auto; }

/* Summary bar */
.summary { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:18px; }
.sum-box { flex:1; min-width:100px; border-radius:8px; padding:12px 14px; border:2px solid; text-align:center; }
.sum-box .count { font-size:2rem; font-weight:bold; line-height:1; }
.sum-box .lbl { font-size:11px; margin-top:4px; text-transform:uppercase; letter-spacing:.06em; }

/* Patient table */
table { width:100%; border-collapse:collapse; font-size:13px; }
th { text-align:left; color:#555; font-size:11px; text-transform:uppercase; letter-spacing:.05em; font-weight:normal; padding:6px 10px; border-bottom:1px solid #1e1e3a; white-space:nowrap; }
td { padding:9px 10px; border-bottom:1px solid #1a1a2e; vertical-align:middle; }
.tag-cell { font-family:monospace; font-size:15px; font-weight:bold; white-space:nowrap; }
.pri-badge { display:inline-block; border-radius:4px; padding:2px 8px; font-size:11px; font-weight:bold; text-transform:uppercase; letter-spacing:.05em; }
.name-cell { font-weight:bold; }
.notes-cell { color:#777; font-size:12px; max-width:180px; }
.disp-transferred { color:#f39c12; }
.disp-deceased    { color:#888; }
.actions { display:flex; gap:5px; }
.btn-sm { background:none; border:1px solid #333; color:#666; border-radius:4px; padding:4px 9px; font-size:11px; cursor:pointer; white-space:nowrap; }
.btn-sm:hover { border-color:#e94560; color:#e94560; }
.btn-print { border-color:#2a4a7a; color:#4a9eff; }
.btn-print:hover { border-color:#4a9eff; color:#4a9eff; }
.no-patients { text-align:center; color:#333; padding:32px; font-size:13px; }
.add-btn { background:#e94560; color:#fff; border:none; border-radius:5px; padding:7px 18px; font-size:13px; font-weight:bold; cursor:pointer; }
.add-btn:hover { background:#c73652; }

/* Modal */
.veil { display:none; position:fixed; inset:0; background:rgba(0,0,0,.82); z-index:1000; align-items:center; justify-content:center; overflow-y:auto; padding:20px; }
.veil.open { display:flex; }
.modal { background:#1a1a2e; border:1px solid #2a2a4a; border-radius:10px; padding:20px; width:480px; max-width:100%; }
.modal h2 { font-size:14px; color:#e94560; margin-bottom:14px; }
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.form-full { grid-column:1/-1; }
.field-lbl { font-size:11px; color:#777; margin-bottom:3px; }
.modal input, .modal select, .modal textarea {
    width:100%; background:#0f0f1a; border:1px solid #2a2a4a; color:#e0e0e0;
    border-radius:5px; padding:7px 9px; font-size:13px; font-family:inherit; }
.modal textarea { resize:vertical; height:56px; }
.modal input:focus, .modal select:focus, .modal textarea:focus { outline:none; border-color:#e94560; }
.modal-btns { display:flex; gap:8px; margin-top:14px; }
.modal-btns button { flex:1; padding:9px; border-radius:5px; border:none; cursor:pointer; font-size:13px; font-weight:bold; }
.btn-red { background:#e94560; color:#fff; }
.btn-cancel { background:#16213e; color:#aaa; border:1px solid #2a2a4a !important; }

/* Priority radio buttons */
.pri-radios { display:flex; gap:6px; flex-wrap:wrap; }
.pri-radio-lbl { flex:1; min-width:90px; padding:8px 10px; border:2px solid #2a2a4a; border-radius:6px; cursor:pointer; text-align:center; transition:.1s; }
.pri-radio-lbl input { display:none; }
.pri-radio-lbl.sel-immediate { border-color:#e94560; background:#2a0a12; color:#e94560; }
.pri-radio-lbl.sel-delayed   { border-color:#f39c12; background:#2a1a00; color:#f39c12; }
.pri-radio-lbl.sel-minor     { border-color:#2ecc71; background:#0a2a12; color:#2ecc71; }
.pri-radio-lbl.sel-expectant { border-color:#666;    background:#111;    color:#888;    }
</style>
</head>
<body>
<header>
  <a class="back" href="/">← Home</a>
  <h1>🏥 Triage Log</h1>
  <?php if ($is_admin && !$is_readonly): ?>
    <button class="add-btn" onclick="openAdd()">+ Log Patient</button>
  <?php endif ?>
</header>

<?php if ($msg): ?><div class="msg"><?= $msg ?></div><?php endif ?>

<div class="content">

<div class="summary">
<?php foreach ($PRIORITIES as $pkey => $p): ?>
  <div class="sum-box" style="border-color:<?= $p['color'] ?>;background:<?= $p['bg'] ?>">
    <div class="count" style="color:<?= $p['color'] ?>"><?= $counts[$pkey] ?></div>
    <div class="lbl" style="color:<?= $p['color'] ?>"><?= $p['label'] ?></div>
  </div>
<?php endforeach ?>
  <div class="sum-box" style="border-color:#2a2a4a;background:#1a1a2e">
    <div class="count" style="color:#aaa"><?= count($patients) ?></div>
    <div class="lbl" style="color:#555">Total</div>
  </div>
</div>

<?php if (!$patients): ?>
  <div class="no-patients">No patients logged yet.</div>
<?php else: ?>
<div style="overflow-x:auto">
<table>
  <thead>
    <tr>
      <th>Tag</th>
      <th>Priority</th>
      <th>Name / Age</th>
      <th>Complaint</th>
      <th>Location</th>
      <th>Caregiver</th>
      <th>Disposition</th>
      <th>Logged</th>
      <th>Updated</th>
      <?php if ($is_admin): ?><th></th><?php endif ?>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($patients as $p):
    $pr = $PRIORITIES[$p['priority']] ?? $PRIORITIES['minor'];
    $disp_cls = $p['disposition'] === 'transferred' ? 'disp-transferred' : ($p['disposition'] === 'deceased' ? 'disp-deceased' : '');
  ?>
    <tr style="background:<?= $pr['bg'] ?>22">
      <td class="tag-cell" style="color:<?= $pr['color'] ?>"><?= htmlspecialchars($p['tag_id']) ?></td>
      <td>
        <span class="pri-badge" style="background:<?= $pr['bg'] ?>;color:<?= $pr['color'] ?>;border:1px solid <?= $pr['color'] ?>44">
          <?= $pr['label'] ?>
        </span>
      </td>
      <td class="name-cell">
        <?= $p['name'] ? htmlspecialchars($p['name']) : '<span style="color:#444">Unknown</span>' ?>
        <?php if ($p['age']): ?>
          <span style="color:#555;font-size:11px;font-weight:normal"> · <?= htmlspecialchars($p['age']) ?></span>
        <?php endif ?>
      </td>
      <td class="notes-cell"><?= htmlspecialchars($p['complaint'] ?? '') ?></td>
      <td style="color:#aaa;white-space:nowrap"><?= htmlspecialchars($p['location'] ?? '—') ?></td>
      <td style="color:#aaa"><?= htmlspecialchars($p['caregiver'] ?? '—') ?></td>
      <td class="<?= $disp_cls ?>"><?= htmlspecialchars($DISPOSITIONS[$p['disposition']] ?? $p['disposition']) ?></td>
      <td style="color:#555;white-space:nowrap;font-size:12px"><?= date('H:i', $p['created_at']) ?></td>
      <td style="color:#555;white-space:nowrap;font-size:12px"><?= date('H:i', $p['updated_at']) ?></td>
      <?php if ($is_admin): ?>
      <td>
        <div class="actions">
          <a class="btn-sm btn-print" href="/triage/print.php?id=<?= (int)$p['id'] ?>" target="_blank">🖨 Tag</a>
          <button class="btn-sm" onclick="openEdit(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)">Edit</button>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete <?= htmlspecialchars($p['tag_id']) ?>?')">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="delete">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button type="submit" class="btn-sm">✕</button>
          </form>
        </div>
      </td>
      <?php endif ?>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
</div>
<?php endif ?>

</div>

<!-- ── Add modal ──────────────────────────────────────────────────────────── -->
<?php if ($is_admin && !$is_readonly): ?>
<div class="veil" id="veil-add">
  <div class="modal">
    <h2>Log Patient</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="add">
      <div class="form-full" style="margin-bottom:10px">
        <div class="field-lbl">Priority *</div>
        <div class="pri-radios">
          <?php foreach ($PRIORITIES as $pkey => $p): ?>
            <label class="pri-radio-lbl" id="lbl-add-<?= $pkey ?>" style="border-color:<?= $p['color'] ?>33">
              <input type="radio" name="priority" value="<?= $pkey ?>"
                     onchange="updatePriLabel('add',this.value)"
                     <?= $pkey === 'immediate' ? 'checked' : '' ?>>
              <?= $p['label'] ?>
            </label>
          <?php endforeach ?>
        </div>
      </div>
      <div class="form-grid">
        <div>
          <div class="field-lbl">Name <span style="color:#444">(optional)</span></div>
          <input type="text" name="name" placeholder="Unknown if not established" maxlength="80">
        </div>
        <div>
          <div class="field-lbl">Age / Est. Age <span style="color:#444">(optional)</span></div>
          <input type="text" name="age" placeholder="e.g. 40s, 8, ~65" maxlength="20">
        </div>
        <div class="form-full">
          <div class="field-lbl">Chief complaint / injuries *</div>
          <textarea name="complaint" placeholder="Describe injuries or chief complaint" required></textarea>
        </div>
        <div>
          <div class="field-lbl">Caregiver / Assigned to</div>
          <input type="text" name="caregiver" placeholder="Name or team" maxlength="60">
        </div>
        <div>
          <div class="field-lbl">Location in shelter</div>
          <input type="text" name="location" placeholder="e.g. Room 3, Gym floor B" maxlength="80">
        </div>
        <div class="form-full">
          <div class="field-lbl">Notes</div>
          <textarea name="notes" placeholder="Vitals, allergies, treatments given…"></textarea>
        </div>
      </div>
      <div class="modal-btns">
        <button type="submit" class="btn-red">Log Patient</button>
        <button type="button" class="btn-cancel" onclick="closeModals()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Edit modal ─────────────────────────────────────────────────────────── -->
<div class="veil" id="veil-edit">
  <div class="modal">
    <h2>Edit Patient <span id="edit-tag-label" style="color:#aaa;font-weight:normal"></span></h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="edit">
      <input type="hidden" name="id" id="edit-id">
      <div class="form-full" style="margin-bottom:10px">
        <div class="field-lbl">Priority *</div>
        <div class="pri-radios">
          <?php foreach ($PRIORITIES as $pkey => $p): ?>
            <label class="pri-radio-lbl" id="lbl-edit-<?= $pkey ?>" style="border-color:<?= $p['color'] ?>33">
              <input type="radio" name="priority" id="edit-pri-<?= $pkey ?>" value="<?= $pkey ?>"
                     onchange="updatePriLabel('edit',this.value)">
              <?= $p['label'] ?>
            </label>
          <?php endforeach ?>
        </div>
      </div>
      <div class="form-grid">
        <div>
          <div class="field-lbl">Name</div>
          <input type="text" name="name" id="edit-name" maxlength="80">
        </div>
        <div>
          <div class="field-lbl">Age / Est. Age</div>
          <input type="text" name="age" id="edit-age" maxlength="20">
        </div>
        <div class="form-full">
          <div class="field-lbl">Chief complaint / injuries</div>
          <textarea name="complaint" id="edit-complaint"></textarea>
        </div>
        <div>
          <div class="field-lbl">Caregiver / Assigned to</div>
          <input type="text" name="caregiver" id="edit-caregiver" maxlength="60">
        </div>
        <div>
          <div class="field-lbl">Location</div>
          <input type="text" name="location" id="edit-location" maxlength="80">
        </div>
        <div>
          <div class="field-lbl">Disposition</div>
          <select name="disposition" id="edit-disposition">
            <?php foreach ($DISPOSITIONS as $k => $v): ?>
              <option value="<?= $k ?>"><?= $v ?></option>
            <?php endforeach ?>
          </select>
        </div>
        <div class="form-full">
          <div class="field-lbl">Notes</div>
          <textarea name="notes" id="edit-notes"></textarea>
        </div>
      </div>
      <div class="modal-btns">
        <button type="submit" class="btn-red">Save Changes</button>
        <button type="button" class="btn-cancel" onclick="closeModals()">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php endif ?>

<script>
var PRI_COLORS = {
  immediate: { color:'#e94560', bg:'#2a0a12' },
  delayed:   { color:'#f39c12', bg:'#2a1a00' },
  minor:     { color:'#2ecc71', bg:'#0a2a12' },
  expectant: { color:'#888',    bg:'#111'    },
};

function updatePriLabel(prefix, val) {
  Object.keys(PRI_COLORS).forEach(function(k) {
    var lbl = document.getElementById('lbl-' + prefix + '-' + k);
    if (!lbl) return;
    var p = PRI_COLORS[k];
    if (k === val) {
      lbl.style.borderColor = p.color;
      lbl.style.background  = p.bg;
      lbl.style.color       = p.color;
    } else {
      lbl.style.borderColor = p.color + '33';
      lbl.style.background  = 'transparent';
      lbl.style.color       = '';
    }
  });
}

function openAdd() {
  document.getElementById('veil-add').classList.add('open');
  updatePriLabel('add', 'immediate');
  setTimeout(function() { document.querySelector('#veil-add [name=complaint]').focus(); }, 60);
}

function openEdit(row) {
  document.getElementById('edit-id').value          = row.id;
  document.getElementById('edit-tag-label').textContent = row.tag_id;
  document.getElementById('edit-name').value        = row.name || '';
  document.getElementById('edit-age').value         = row.age  || '';
  document.getElementById('edit-complaint').value   = row.complaint || '';
  document.getElementById('edit-caregiver').value   = row.caregiver || '';
  document.getElementById('edit-location').value    = row.location  || '';
  document.getElementById('edit-notes').value       = row.notes     || '';
  var pri = row.priority || 'minor';
  var radio = document.getElementById('edit-pri-' + pri);
  if (radio) radio.checked = true;
  updatePriLabel('edit', pri);
  var disp = document.getElementById('edit-disposition');
  if (disp) disp.value = row.disposition || 'on-site';
  document.getElementById('veil-edit').classList.add('open');
}

function closeModals() {
  document.querySelectorAll('.veil').forEach(function(v) { v.classList.remove('open'); });
}
document.querySelectorAll('.veil').forEach(function(v) {
  v.addEventListener('click', function(e) { if (e.target === v) closeModals(); });
});
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModals(); });

// Initial state for add modal priority
updatePriLabel('add', 'immediate');

// Auto-refresh every 30s
setTimeout(function() { location.reload(); }, 30000);
</script>
</body>
</html>
