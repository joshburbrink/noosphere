<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();
if (get_setting('show_tasks','1') !== '1') { http_response_code(404); exit; }

$is_admin = !empty($_SESSION['admin']);
$is_readonly = is_readonly();

$db = new SQLite3('/var/lib/noosphere/tasks.db');
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("CREATE TABLE IF NOT EXISTS tasks (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    title        TEXT NOT NULL,
    category     TEXT NOT NULL DEFAULT 'other',
    priority     TEXT NOT NULL DEFAULT 'normal',
    status       TEXT NOT NULL DEFAULT 'open',
    location     TEXT,
    notes        TEXT,
    created_by   TEXT,
    claimed_by   TEXT,
    claimed_at   INTEGER,
    completed_at INTEGER,
    created_at   INTEGER NOT NULL,
    updated_at   INTEGER NOT NULL
)");

$CATEGORIES = ['rescue','logistics','medical','maintenance','other'];
$PRIORITIES = ['urgent','normal','low'];
$msg = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    readonly_die();
    $act = $_POST['act'] ?? '';

    if ($act === 'create' && $is_admin) {
        $title    = trim($_POST['title'] ?? '');
        $category = in_array($_POST['category'] ?? '', $CATEGORIES) ? $_POST['category'] : 'other';
        $priority = in_array($_POST['priority'] ?? '', $PRIORITIES) ? $_POST['priority'] : 'normal';
        $location = trim($_POST['location'] ?? '');
        $notes    = trim($_POST['notes'] ?? '');
        $by       = trim($_POST['created_by'] ?? '') ?: 'Operator';
        if (!$title) { $error = 'Title is required.'; }
        else {
            $now = time();
            $s = $db->prepare("INSERT INTO tasks (title,category,priority,status,location,notes,created_by,created_at,updated_at) VALUES (?,?,?,'open',?,?,?,?,?)");
            $s->bindValue(1,$title); $s->bindValue(2,$category); $s->bindValue(3,$priority);
            $s->bindValue(4,$location); $s->bindValue(5,$notes); $s->bindValue(6,$by);
            $s->bindValue(7,$now,SQLITE3_INTEGER); $s->bindValue(8,$now,SQLITE3_INTEGER);
            $s->execute();
            $msg = 'Task created.';
        }
    }

    if ($act === 'claim') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['claimed_by'] ?? '');
        if ($id && $name) {
            $now = time();
            $s = $db->prepare("UPDATE tasks SET status='claimed',claimed_by=?,claimed_at=?,updated_at=? WHERE id=? AND status='open'");
            $s->bindValue(1,$name); $s->bindValue(2,$now,SQLITE3_INTEGER);
            $s->bindValue(3,$now,SQLITE3_INTEGER); $s->bindValue(4,$id,SQLITE3_INTEGER);
            $s->execute();
            $msg = 'Task claimed.';
        } else { $error = 'Your name is required to claim a task.'; }
    }

    if ($act === 'done') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $row = $db->querySingle("SELECT claimed_by,status FROM tasks WHERE id=$id", true);
            $claimer = $_SESSION['task_claim'][$id] ?? '';
            if ($row && ($is_admin || ($row['status'] === 'claimed'))) {
                $now = time();
                $s = $db->prepare("UPDATE tasks SET status='done',completed_at=?,updated_at=? WHERE id=?");
                $s->bindValue(1,$now,SQLITE3_INTEGER); $s->bindValue(2,$now,SQLITE3_INTEGER);
                $s->bindValue(3,$id,SQLITE3_INTEGER);
                $s->execute();
                $msg = 'Task marked done.';
            }
        }
    }

    if ($act === 'release' && $is_admin) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $now = time();
            $s = $db->prepare("UPDATE tasks SET status='open',claimed_by=NULL,claimed_at=NULL,updated_at=? WHERE id=?");
            $s->bindValue(1,$now,SQLITE3_INTEGER); $s->bindValue(2,$id,SQLITE3_INTEGER);
            $s->execute();
            $msg = 'Task released back to open.';
        }
    }

    if ($act === 'delete' && $is_admin) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $s = $db->prepare("DELETE FROM tasks WHERE id=?");
            $s->bindValue(1,$id,SQLITE3_INTEGER);
            $s->execute();
            $msg = 'Task deleted.';
        }
    }

    if ($act === 'edit' && $is_admin) {
        $id       = (int)($_POST['id'] ?? 0);
        $title    = trim($_POST['title'] ?? '');
        $category = in_array($_POST['category'] ?? '', $CATEGORIES) ? $_POST['category'] : 'other';
        $priority = in_array($_POST['priority'] ?? '', $PRIORITIES) ? $_POST['priority'] : 'normal';
        $location = trim($_POST['location'] ?? '');
        $notes    = trim($_POST['notes'] ?? '');
        if ($id && $title) {
            $now = time();
            $s = $db->prepare("UPDATE tasks SET title=?,category=?,priority=?,location=?,notes=?,updated_at=? WHERE id=?");
            $s->bindValue(1,$title); $s->bindValue(2,$category); $s->bindValue(3,$priority);
            $s->bindValue(4,$location); $s->bindValue(5,$notes);
            $s->bindValue(6,$now,SQLITE3_INTEGER); $s->bindValue(7,$id,SQLITE3_INTEGER);
            $s->execute();
            $msg = 'Task updated.';
        }
    }

    header('Location: /tasks/' . ($msg ? '?msg=' . urlencode($msg) : ''));
    exit;
}

if (isset($_GET['msg'])) $msg = htmlspecialchars($_GET['msg']);

$cat_filter = $_GET['cat'] ?? 'all';
$where = $cat_filter !== 'all' && in_array($cat_filter, $CATEGORIES)
    ? "WHERE category='" . SQLite3::escapeString($cat_filter) . "'"
    : '';

$tasks = ['open'=>[], 'claimed'=>[], 'done'=>[]];
$res = $db->query("SELECT * FROM tasks $where ORDER BY
    CASE priority WHEN 'urgent' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END,
    created_at ASC");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $tasks[$row['status']][] = $row;
}

$PRIO_COLOR  = ['urgent'=>'#e94560','normal'=>'#4a9eff','low'=>'#555'];
$PRIO_LABEL  = ['urgent'=>'URGENT','normal'=>'Normal','low'=>'Low'];
$CAT_ICONS   = ['rescue'=>'🚨','logistics'=>'📦','medical'=>'🏥','maintenance'=>'🔧','other'=>'📋'];

function task_card($row, $is_admin, $is_readonly, $PRIO_COLOR, $PRIO_LABEL, $CAT_ICONS) {
    $id    = (int)$row['id'];
    $title = htmlspecialchars($row['title']);
    $cat   = $row['category'];
    $pri   = $row['priority'];
    $loc   = htmlspecialchars($row['location'] ?? '');
    $notes = htmlspecialchars($row['notes'] ?? '');
    $by    = htmlspecialchars($row['created_by'] ?? '');
    $icon  = $CAT_ICONS[$cat] ?? '📋';
    $pcolor= $PRIO_COLOR[$pri] ?? '#555';
    $plabel= $PRIO_LABEL[$pri] ?? $pri;
    $claimed_by = htmlspecialchars($row['claimed_by'] ?? '');
    $status = $row['status'];
    $csrf = csrf_field();

    $age = '';
    if ($row['created_at']) {
        $diff = time() - $row['created_at'];
        $age = $diff < 3600 ? round($diff/60).'m ago' : round($diff/3600).'h ago';
    }
    ?>
    <div class="card pri-<?= $pri ?>" id="task-<?= $id ?>">
      <div class="card-head">
        <span class="cat-icon"><?= $icon ?></span>
        <span class="card-title"><?= $title ?></span>
        <span class="pri-badge" style="background:<?= $pcolor ?>"><?= $plabel ?></span>
      </div>
      <?php if ($loc): ?><div class="card-loc">📍 <?= $loc ?></div><?php endif ?>
      <?php if ($notes): ?><div class="card-notes"><?= $notes ?></div><?php endif ?>
      <div class="card-meta">
        <?php if ($by): ?>by <?= $by ?><?php endif ?>
        <?php if ($age): ?> · <?= $age ?><?php endif ?>
        <?php if ($claimed_by && $status !== 'open'): ?> · <?= $status === 'done' ? '✓' : '⚙' ?> <?= $claimed_by ?><?php endif ?>
      </div>
      <?php if (!$is_readonly): ?>
      <div class="card-actions">
        <?php if ($status === 'open'): ?>
          <button class="btn-claim" onclick="openClaim(<?= $id ?>)">Claim</button>
        <?php endif ?>
        <?php if ($status === 'claimed'): ?>
          <form method="post" style="display:inline"><?= $csrf ?>
            <input type="hidden" name="act" value="done">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn-done">Mark Done</button>
          </form>
        <?php endif ?>
        <?php if ($is_admin): ?>
          <?php if ($status === 'claimed'): ?>
            <form method="post" style="display:inline"><?= $csrf ?>
              <input type="hidden" name="act" value="release">
              <input type="hidden" name="id" value="<?= $id ?>">
              <button class="btn-release">Release</button>
            </form>
          <?php endif ?>
          <?php if ($status === 'open' || $status === 'claimed'): ?>
            <form method="post" style="display:inline"><?= $csrf ?>
              <input type="hidden" name="act" value="done">
              <input type="hidden" name="id" value="<?= $id ?>">
              <button class="btn-done">Done</button>
            </form>
          <?php endif ?>
          <button class="btn-edit" onclick="openEdit(<?= $id ?>, <?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>)">Edit</button>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this task?')"><?= $csrf ?>
            <input type="hidden" name="act" value="delete">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn-del">✕</button>
          </form>
        <?php endif ?>
      </div>
      <?php endif ?>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tasks — Noosphere</title>
<?= csrf_js() ?>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#0f0f1a;color:#e0e0e0;min-height:100vh;display:flex;flex-direction:column}
header{background:#1a1a2e;border-bottom:2px solid #e94560;padding:10px 14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
header a.back{color:#888;text-decoration:none;font-size:13px}
header a.back:hover{color:#e94560}
header h1{font-size:15px;color:#e94560;flex:1}
.msg{background:#1a3a1a;border:1px solid #2a6a2a;color:#6fcf6f;padding:8px 14px;font-size:13px}
.err{background:#3a1a1a;border:1px solid #6a2a2a;color:#cf6f6f;padding:8px 14px;font-size:13px}
.filters{background:#13131f;border-bottom:1px solid #1e1e3a;padding:8px 14px;display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.filter-btn{background:#16213e;border:1px solid #2a2a4a;color:#888;padding:4px 10px;border-radius:4px;font-size:12px;cursor:pointer;text-decoration:none}
.filter-btn.active,.filter-btn:hover{border-color:#e94560;color:#e94560}
.filter-btn.active{font-weight:bold}
.board{display:flex;gap:0;flex:1;overflow:hidden}
.col{flex:1;min-width:0;border-right:1px solid #1e1e3a;display:flex;flex-direction:column;overflow:hidden}
.col:last-child{border-right:none}
.col-head{background:#13131f;padding:10px 14px;font-size:12px;font-weight:bold;color:#888;text-transform:uppercase;letter-spacing:.08em;display:flex;justify-content:space-between;align-items:center;flex-shrink:0}
.col-head .count{background:#1e1e3a;color:#555;padding:2px 7px;border-radius:10px;font-size:11px}
.col-head .count.has{background:#e94560;color:#fff}
.cards{flex:1;overflow-y:auto;padding:10px;display:flex;flex-direction:column;gap:8px}
.card{background:#16213e;border:1px solid #2a2a4a;border-radius:8px;padding:10px 12px}
.card.pri-urgent{border-left:3px solid #e94560}
.card.pri-normal{border-left:3px solid #4a9eff}
.card.pri-low{border-left:3px solid #555}
.col:last-child .card{opacity:.55}
.card-head{display:flex;align-items:flex-start;gap:7px;margin-bottom:6px}
.cat-icon{font-size:16px;flex-shrink:0;line-height:1.3}
.card-title{font-size:13px;font-weight:bold;flex:1;line-height:1.3}
.pri-badge{font-size:10px;padding:2px 6px;border-radius:3px;color:#fff;font-weight:bold;flex-shrink:0}
.card-loc{font-size:11px;color:#4a9eff;margin-bottom:4px}
.card-notes{font-size:12px;color:#888;margin-bottom:4px;line-height:1.4}
.card-meta{font-size:11px;color:#555;margin-bottom:6px}
.card-actions{display:flex;gap:5px;flex-wrap:wrap}
.card-actions button,.card-actions .btn{padding:4px 10px;border:none;border-radius:4px;font-size:12px;cursor:pointer;font-weight:bold}
.btn-claim{background:#e94560;color:#fff}
.btn-done{background:#2a6a2a;color:#9eff9e}
.btn-release{background:#16213e;color:#aaa;border:1px solid #2a2a4a!important}
.btn-edit{background:#16213e;color:#aaa;border:1px solid #2a2a4a!important}
.btn-del{background:none;color:#555;border:1px solid #2a2a4a!important;padding:4px 7px!important}
.btn-del:hover{color:#e94560;border-color:#e94560!important}
.empty{color:#333;font-size:12px;text-align:center;padding:20px}
/* Modals */
.veil{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:2000;align-items:center;justify-content:center}
.veil.open{display:flex}
.modal{background:#1a1a2e;border:1px solid #2a2a4a;border-radius:10px;padding:20px;width:340px;max-width:95vw;max-height:90vh;overflow-y:auto}
.modal h2{color:#e94560;font-size:14px;margin-bottom:14px}
.modal label{display:block;font-size:11px;color:#777;margin:10px 0 3px}
.modal input,.modal select,.modal textarea{width:100%;background:#16213e;border:1px solid #2a2a4a;color:#e0e0e0;border-radius:5px;padding:7px 9px;font-size:13px;font-family:inherit}
.modal textarea{resize:vertical;height:60px}
.modal input:focus,.modal select:focus,.modal textarea:focus{outline:none;border-color:#e94560}
.modal-btns{display:flex;gap:8px;margin-top:14px}
.modal-btns button{flex:1;padding:9px;border-radius:5px;border:none;cursor:pointer;font-size:13px;font-weight:bold}
.btn-red{background:#e94560;color:#fff}
.btn-cancel{background:#16213e;color:#aaa;border:1px solid #2a2a4a!important}
/* Create button */
.create-btn{background:#e94560;color:#fff;border:none;padding:6px 14px;border-radius:5px;font-size:13px;font-weight:bold;cursor:pointer}
/* Responsive */
@media(max-width:600px){
  .board{flex-direction:column;overflow:auto}
  .col{border-right:none;border-bottom:1px solid #1e1e3a;min-height:200px}
  .cards{overflow-y:visible}
}
</style>
</head>
<body>
<header>
  <a class="back" href="/">← Home</a>
  <h1>📋 Task Board</h1>
  <?php if ($is_admin && !$is_readonly): ?>
    <button class="create-btn" onclick="openCreate()">+ New Task</button>
  <?php endif ?>
</header>

<?php if ($msg): ?><div class="msg"><?= $msg ?></div><?php endif ?>
<?php if ($error): ?><div class="err"><?= $error ?></div><?php endif ?>

<div class="filters">
  <span style="font-size:11px;color:#555;margin-right:4px">Filter:</span>
  <a href="/tasks/" class="filter-btn <?= $cat_filter==='all'?'active':'' ?>">All</a>
  <?php foreach ($CAT_ICONS as $k => $icon): ?>
    <a href="/tasks/?cat=<?= $k ?>" class="filter-btn <?= $cat_filter===$k?'active':'' ?>"><?= $icon ?> <?= ucfirst($k) ?></a>
  <?php endforeach ?>
</div>

<div class="board">
  <?php foreach ([
    'open'    => ['Open',        count($tasks['open'])],
    'claimed' => ['In Progress', count($tasks['claimed'])],
    'done'    => ['Done',        count($tasks['done'])],
  ] as $status => [$label, $cnt]): ?>
  <div class="col">
    <div class="col-head">
      <?= $label ?>
      <span class="count <?= $cnt?'has':'' ?>"><?= $cnt ?></span>
    </div>
    <div class="cards">
      <?php if (!$cnt): ?>
        <div class="empty">No tasks</div>
      <?php else: ?>
        <?php foreach ($tasks[$status] as $row): ?>
          <?php task_card($row, $is_admin, $is_readonly, $PRIO_COLOR, $PRIO_LABEL, $CAT_ICONS) ?>
        <?php endforeach ?>
      <?php endif ?>
    </div>
  </div>
  <?php endforeach ?>
</div>

<!-- Claim modal -->
<div class="veil" id="veil-claim">
  <div class="modal">
    <h2>Claim Task</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="claim">
      <input type="hidden" name="id" id="claim-id">
      <label>Your name</label>
      <input type="text" name="claimed_by" id="claim-name" placeholder="Enter your name" maxlength="60" required>
      <div class="modal-btns">
        <button type="submit" class="btn-red">Claim Task</button>
        <button type="button" class="btn-cancel" onclick="closeModals()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Create modal -->
<?php if ($is_admin && !$is_readonly): ?>
<div class="veil" id="veil-create">
  <div class="modal">
    <h2>New Task</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="create">
      <label>Title *</label>
      <input type="text" name="title" placeholder="What needs to be done?" maxlength="120" required>
      <label>Category</label>
      <select name="category">
        <?php foreach ($CATEGORIES as $c): ?>
          <option value="<?= $c ?>"><?= $CAT_ICONS[$c] ?> <?= ucfirst($c) ?></option>
        <?php endforeach ?>
      </select>
      <label>Priority</label>
      <select name="priority">
        <option value="urgent">🔴 Urgent</option>
        <option value="normal" selected>🔵 Normal</option>
        <option value="low">⚫ Low</option>
      </select>
      <label>Location / Address</label>
      <input type="text" name="location" placeholder="Optional" maxlength="120">
      <label>Notes</label>
      <textarea name="notes" placeholder="Additional details…"></textarea>
      <label>Created by</label>
      <input type="text" name="created_by" placeholder="Operator name" maxlength="60">
      <div class="modal-btns">
        <button type="submit" class="btn-red">Create Task</button>
        <button type="button" class="btn-cancel" onclick="closeModals()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit modal -->
<div class="veil" id="veil-edit">
  <div class="modal">
    <h2>Edit Task</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="edit">
      <input type="hidden" name="id" id="edit-id">
      <label>Title *</label>
      <input type="text" name="title" id="edit-title" maxlength="120" required>
      <label>Category</label>
      <select name="category" id="edit-category">
        <?php foreach ($CATEGORIES as $c): ?>
          <option value="<?= $c ?>"><?= $CAT_ICONS[$c] ?> <?= ucfirst($c) ?></option>
        <?php endforeach ?>
      </select>
      <label>Priority</label>
      <select name="priority" id="edit-priority">
        <option value="urgent">🔴 Urgent</option>
        <option value="normal">🔵 Normal</option>
        <option value="low">⚫ Low</option>
      </select>
      <label>Location / Address</label>
      <input type="text" name="location" id="edit-location" maxlength="120">
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
function openClaim(id) {
  document.getElementById('claim-id').value = id;
  document.getElementById('claim-name').value = '';
  document.getElementById('veil-claim').classList.add('open');
  document.getElementById('claim-name').focus();
}
function openCreate() {
  document.getElementById('veil-create').classList.add('open');
}
function openEdit(id, row) {
  document.getElementById('edit-id').value        = id;
  document.getElementById('edit-title').value     = row.title;
  document.getElementById('edit-category').value  = row.category;
  document.getElementById('edit-priority').value  = row.priority;
  document.getElementById('edit-location').value  = row.location || '';
  document.getElementById('edit-notes').value     = row.notes || '';
  document.getElementById('veil-edit').classList.add('open');
}
function closeModals() {
  document.querySelectorAll('.veil').forEach(v => v.classList.remove('open'));
}
document.querySelectorAll('.veil').forEach(v => {
  v.addEventListener('click', e => { if (e.target === v) closeModals(); });
});
</script>
</body>
</html>
