<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();
if (get_setting('show_tasks','1') !== '1') { http_response_code(404); exit; }

$is_admin    = !empty($_SESSION['admin']);
$is_readonly = is_readonly();

// Feature flags from settings
$show_rewards      = get_setting('tasks_show_rewards','0') === '1';
$require_login     = get_setting('tasks_require_login','0') === '1';
$allow_self_create = get_setting('tasks_allow_self_create','0') === '1';
$auto_close_hours  = max(0, (int)get_setting('tasks_auto_close_hours','0'));

// Registered user name from session (registry login)
$session_name = $_SESSION['reg_name'] ?? '';

$db = new SQLite3('/var/lib/noosphere/tasks.db');
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("CREATE TABLE IF NOT EXISTS tasks (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    title        TEXT NOT NULL,
    category     TEXT NOT NULL DEFAULT 'Other',
    priority     TEXT NOT NULL DEFAULT 'normal',
    status       TEXT NOT NULL DEFAULT 'open',
    location     TEXT,
    notes        TEXT,
    created_by   TEXT,
    claimed_by   TEXT,
    claimed_at   INTEGER,
    completed_at INTEGER,
    reward_type  TEXT NOT NULL DEFAULT 'none',
    reward_desc  TEXT,
    created_at   INTEGER NOT NULL,
    updated_at   INTEGER NOT NULL
)");
// Migrate existing tables
foreach (['reward_type TEXT NOT NULL DEFAULT \'none\'','reward_desc TEXT','group_name TEXT'] as $col) {
    @$db->exec("ALTER TABLE tasks ADD COLUMN $col");
}

// Auto-close claimed tasks past threshold
if ($auto_close_hours > 0) {
    $cutoff = time() - ($auto_close_hours * 3600);
    $db->exec("UPDATE tasks SET status='done',completed_at=updated_at WHERE status='claimed' AND claimed_at <= $cutoff");
}

// Categories and priorities
$_raw_cats = get_setting('tasks_categories', 'Rescue,Logistics,Medical,Maintenance,Other');
$CATEGORIES = array_values(array_filter(array_map('trim', explode(',', $_raw_cats))));
if (!$CATEGORIES) $CATEGORIES = ['Other'];

$PRIORITIES   = ['urgent','normal','low'];
$REWARD_TYPES = ['none'=>'No reward','cash'=>'💵 Cash','barter'=>'🔄 Barter','community_credit'=>'⭐ Community Credit','other'=>'🎁 Other'];

$msg = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    readonly_die();
    $act = $_POST['act'] ?? '';

    // Determine if user can create — admin always can; non-admin only if allow_self_create
    $can_create = $is_admin || ($allow_self_create && !$is_readonly);

    if ($act === 'create' && $can_create) {
        $title       = trim($_POST['title'] ?? '');
        $category    = in_array($_POST['category'] ?? '', $CATEGORIES) ? $_POST['category'] : $CATEGORIES[0];
        $priority    = $is_admin && in_array($_POST['priority'] ?? '', $PRIORITIES) ? $_POST['priority'] : 'normal';
        $location    = trim($_POST['location'] ?? '');
        $notes       = trim($_POST['notes'] ?? '');
        $group_name  = trim($_POST['group_name'] ?? '');
        $by          = trim($_POST['created_by'] ?? '') ?: ($session_name ?: ($is_admin ? 'Operator' : 'Community'));
        $rtype       = ($show_rewards && array_key_exists($_POST['reward_type'] ?? '', $REWARD_TYPES))
                       ? $_POST['reward_type'] : 'none';
        $rdesc       = $show_rewards ? trim($_POST['reward_desc'] ?? '') : '';
        $new_status  = $is_admin ? 'open' : 'pending';

        if (!$title) { $error = 'Title is required.'; }
        else {
            $now = time();
            $s = $db->prepare("INSERT INTO tasks
                (title,category,priority,status,location,notes,group_name,created_by,reward_type,reward_desc,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $s->bindValue(1,$title); $s->bindValue(2,$category); $s->bindValue(3,$priority);
            $s->bindValue(4,$new_status); $s->bindValue(5,$location); $s->bindValue(6,$notes);
            $s->bindValue(7,$group_name ?: null, $group_name ? SQLITE3_TEXT : SQLITE3_NULL);
            $s->bindValue(8,$by); $s->bindValue(9,$rtype); $s->bindValue(10,$rdesc);
            $s->bindValue(11,$now,SQLITE3_INTEGER); $s->bindValue(12,$now,SQLITE3_INTEGER);
            $s->execute();
            $msg = $is_admin ? 'Task created.' : 'Task request submitted — awaiting operator approval.';
        }
    }

    if ($act === 'approve' && $is_admin) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $now = time();
            $s = $db->prepare("UPDATE tasks SET status='open',updated_at=? WHERE id=? AND status='pending'");
            $s->bindValue(1,$now,SQLITE3_INTEGER); $s->bindValue(2,$id,SQLITE3_INTEGER);
            $s->execute();
            $msg = 'Task approved.';
        }
    }

    if ($act === 'claim') {
        $id = (int)($_POST['id'] ?? 0);
        // Name resolution: session > POST > required
        $name = $session_name ?: trim($_POST['claimed_by'] ?? '');
        if ($require_login && !$session_name) {
            $error = 'You must be registered to claim a task.';
        } elseif (!$name) {
            $error = 'Your name is required to claim a task.';
        } elseif ($id) {
            $now = time();
            $s = $db->prepare("UPDATE tasks SET status='claimed',claimed_by=?,claimed_at=?,updated_at=? WHERE id=? AND status='open'");
            $s->bindValue(1,$name); $s->bindValue(2,$now,SQLITE3_INTEGER);
            $s->bindValue(3,$now,SQLITE3_INTEGER); $s->bindValue(4,$id,SQLITE3_INTEGER);
            $s->execute();
            $msg = 'Task claimed.';
        }
    }

    if ($act === 'done') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $row = $db->querySingle("SELECT status FROM tasks WHERE id=$id", true);
            if ($row && ($is_admin || $row['status'] === 'claimed')) {
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
            $msg = 'Task released.';
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
        $id         = (int)($_POST['id'] ?? 0);
        $title      = trim($_POST['title'] ?? '');
        $category   = in_array($_POST['category'] ?? '', $CATEGORIES) ? $_POST['category'] : $CATEGORIES[0];
        $priority   = in_array($_POST['priority'] ?? '', $PRIORITIES) ? $_POST['priority'] : 'normal';
        $location   = trim($_POST['location'] ?? '');
        $notes      = trim($_POST['notes'] ?? '');
        $group_name = trim($_POST['group_name'] ?? '');
        $rtype      = ($show_rewards && array_key_exists($_POST['reward_type'] ?? '', $REWARD_TYPES))
                      ? $_POST['reward_type'] : 'none';
        $rdesc      = $show_rewards ? trim($_POST['reward_desc'] ?? '') : '';
        if ($id && $title) {
            $now = time();
            $s = $db->prepare("UPDATE tasks SET title=?,category=?,priority=?,location=?,notes=?,group_name=?,reward_type=?,reward_desc=?,updated_at=? WHERE id=?");
            $s->bindValue(1,$title); $s->bindValue(2,$category); $s->bindValue(3,$priority);
            $s->bindValue(4,$location); $s->bindValue(5,$notes);
            $s->bindValue(6,$group_name ?: null, $group_name ? SQLITE3_TEXT : SQLITE3_NULL);
            $s->bindValue(7,$rtype); $s->bindValue(8,$rdesc);
            $s->bindValue(9,$now,SQLITE3_INTEGER); $s->bindValue(10,$id,SQLITE3_INTEGER);
            $s->execute();
            $msg = 'Task updated.';
        }
    }

    header('Location: /tasks/' . ($msg ? '?msg=' . urlencode($msg) : ''));
    exit;
}

if (isset($_GET['msg'])) $msg = htmlspecialchars($_GET['msg']);

$cat_filter = $_GET['cat'] ?? 'all';
$cat_where = ($cat_filter !== 'all' && in_array($cat_filter, $CATEGORIES))
    ? "AND category='" . SQLite3::escapeString($cat_filter) . "'"
    : '';

$statuses_to_show = $is_admin ? ['pending','open','claimed','done'] : ['open','claimed','done'];
$tasks = array_fill_keys($statuses_to_show, []);

$res = $db->query("SELECT * FROM tasks
    WHERE status IN ('" . implode("','", $statuses_to_show) . "') $cat_where
    ORDER BY CASE priority WHEN 'urgent' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END, created_at ASC");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    if (isset($tasks[$row['status']])) $tasks[$row['status']][] = $row;
}

$PRIO_COLOR = ['urgent'=>'#e94560','normal'=>'#4a9eff','low'=>'#555'];
$PRIO_LABEL = ['urgent'=>'URGENT','normal'=>'Normal','low'=>'Low'];
$_ICON_MAP  = [
    'rescue'=>'🚨','search'=>'🔍','medical'=>'🏥','logistics'=>'📦',
    'maintenance'=>'🔧','communications'=>'📻','command'=>'⭐',
    'staffing'=>'👥','intake'=>'📝','cleanup'=>'🧹','setup'=>'🏗',
    'distribution'=>'📤','collection'=>'📥','volunteers'=>'🙋',
    'other'=>'📋','general'=>'📋',
];
$CAT_ICONS = [];
foreach ($CATEGORIES as $cat) {
    $CAT_ICONS[$cat] = $_ICON_MAP[strtolower($cat)] ?? '📋';
}

function task_card($row, $is_admin, $is_readonly, $show_rewards, $require_login,
                   $session_name, $PRIO_COLOR, $PRIO_LABEL, $CAT_ICONS, $REWARD_TYPES) {
    $id         = (int)$row['id'];
    $title      = htmlspecialchars($row['title']);
    $cat        = $row['category'];
    $pri        = $row['priority'];
    $loc        = htmlspecialchars($row['location'] ?? '');
    $notes      = htmlspecialchars($row['notes'] ?? '');
    $by         = htmlspecialchars($row['created_by'] ?? '');
    $icon       = $CAT_ICONS[$cat] ?? '📋';
    $pcolor     = $PRIO_COLOR[$pri] ?? '#555';
    $plabel     = $PRIO_LABEL[$pri] ?? $pri;
    $claimed_by = htmlspecialchars($row['claimed_by'] ?? '');
    $group_name = htmlspecialchars($row['group_name'] ?? '');
    $status     = $row['status'];
    $rtype      = $row['reward_type'] ?? 'none';
    $rdesc      = htmlspecialchars($row['reward_desc'] ?? '');
    $csrf       = csrf_field();
    $age = '';
    if ($row['created_at']) {
        $diff = time() - $row['created_at'];
        $age  = $diff < 3600 ? round($diff/60).'m ago' : round($diff/3600).'h ago';
    }
    ?>
    <div class="card pri-<?= $pri ?>" id="task-<?= $id ?>">
      <div class="card-head">
        <span class="cat-icon"><?= $icon ?></span>
        <span class="card-title"><?= $title ?></span>
        <span class="pri-badge" style="background:<?= $pcolor ?>"><?= $plabel ?></span>
      </div>
      <?php if ($group_name): ?><div class="card-group">👥 <?= $group_name ?></div><?php endif ?>
      <?php if ($loc): ?><div class="card-loc">📍 <?= $loc ?></div><?php endif ?>
      <?php if ($notes): ?><div class="card-notes"><?= $notes ?></div><?php endif ?>
      <?php if ($show_rewards && $rtype !== 'none'): ?>
        <div class="card-reward">
          <?= $REWARD_TYPES[$rtype] ?? $rtype ?>
          <?php if ($rdesc): ?> — <?= $rdesc ?><?php endif ?>
        </div>
      <?php endif ?>
      <div class="card-meta">
        <?php if ($by): ?>by <?= $by ?><?php endif ?>
        <?php if ($age): ?> · <?= $age ?><?php endif ?>
        <?php if ($claimed_by && $status !== 'open' && $status !== 'pending'): ?>
          · <?= $status === 'done' ? '✓' : '⚙' ?> <?= $claimed_by ?>
        <?php endif ?>
      </div>
      <?php if (!$is_readonly): ?>
      <div class="card-actions">
        <?php if ($status === 'pending' && $is_admin): ?>
          <form method="post" style="display:inline"><?= $csrf ?>
            <input type="hidden" name="act" value="approve">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn-done">✓ Approve</button>
          </form>
        <?php endif ?>
        <?php if ($status === 'open'): ?>
          <?php if ($require_login && !$session_name): ?>
            <span class="need-login">Register to claim</span>
          <?php else: ?>
            <button class="btn-claim" onclick="openClaim(<?= $id ?>)">Claim</button>
          <?php endif ?>
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
          <?php if (in_array($status, ['open','claimed'])): ?>
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

$col_labels = ['pending'=>'Pending Approval','open'=>'Open','claimed'=>'In Progress','done'=>'Done'];
$can_create = $is_admin || ($allow_self_create && !$is_readonly);
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
.col-pending .col-head{background:#1a1a10}
.cards{flex:1;overflow-y:auto;padding:10px;display:flex;flex-direction:column;gap:8px}
.card{background:#16213e;border:1px solid #2a2a4a;border-radius:8px;padding:10px 12px}
.card.pri-urgent{border-left:3px solid #e94560}
.card.pri-normal{border-left:3px solid #4a9eff}
.card.pri-low{border-left:3px solid #555}
.col-pending .card{border-left:3px solid #f5a623;opacity:.85}
.col:last-child .card{opacity:.55}
.card-head{display:flex;align-items:flex-start;gap:7px;margin-bottom:6px}
.cat-icon{font-size:16px;flex-shrink:0;line-height:1.3}
.card-title{font-size:13px;font-weight:bold;flex:1;line-height:1.3}
.pri-badge{font-size:10px;padding:2px 6px;border-radius:3px;color:#fff;font-weight:bold;flex-shrink:0}
.card-group{font-size:11px;color:#a78bfa;margin-bottom:4px}
.card-loc{font-size:11px;color:#4a9eff;margin-bottom:4px}
.card-notes{font-size:12px;color:#888;margin-bottom:4px;line-height:1.4}
.card-reward{font-size:11px;color:#f5a623;background:#1a1500;border:1px solid #3a3000;border-radius:4px;padding:3px 7px;margin-bottom:5px;display:inline-block}
.card-meta{font-size:11px;color:#555;margin-bottom:6px}
.card-actions{display:flex;gap:5px;flex-wrap:wrap}
.card-actions button{padding:4px 10px;border:none;border-radius:4px;font-size:12px;cursor:pointer;font-weight:bold}
.btn-claim{background:#e94560;color:#fff}
.btn-done{background:#2a6a2a;color:#9eff9e}
.btn-release{background:#16213e;color:#aaa;border:1px solid #2a2a4a!important}
.btn-edit{background:#16213e;color:#aaa;border:1px solid #2a2a4a!important}
.btn-del{background:none;color:#555;border:1px solid #2a2a4a!important;padding:4px 7px!important}
.btn-del:hover{color:#e94560;border-color:#e94560!important}
.need-login{font-size:11px;color:#555;font-style:italic}
.empty{color:#333;font-size:12px;text-align:center;padding:20px}
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
.create-btn{background:#e94560;color:#fff;border:none;padding:6px 14px;border-radius:5px;font-size:13px;font-weight:bold;cursor:pointer}
.request-btn{background:#16213e;color:#aaa;border:1px solid #2a2a4a;padding:6px 14px;border-radius:5px;font-size:13px;cursor:pointer}
.reward-row{display:none}
.reward-row.visible{display:block}
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
  <?php if ($can_create): ?>
    <?php if ($is_admin): ?>
      <button class="create-btn" onclick="openCreate()">+ New Task</button>
    <?php else: ?>
      <button class="request-btn" onclick="openCreate()">+ Request Task</button>
    <?php endif ?>
  <?php endif ?>
</header>

<?php if ($msg): ?><div class="msg"><?= $msg ?></div><?php endif ?>
<?php if ($error): ?><div class="err"><?= $error ?></div><?php endif ?>

<div class="filters">
  <span style="font-size:11px;color:#555;margin-right:4px">Filter:</span>
  <a href="/tasks/" class="filter-btn <?= $cat_filter==='all'?'active':'' ?>">All</a>
  <?php foreach ($CAT_ICONS as $k => $icon): ?>
    <a href="/tasks/?cat=<?= urlencode($k) ?>" class="filter-btn <?= $cat_filter===$k?'active':'' ?>"><?= $icon ?> <?= htmlspecialchars($k) ?></a>
  <?php endforeach ?>
  <input type="text" id="task-search" placeholder="Search tasks..." oninput="filterTasks()"
         style="margin-left:auto;background:#0f0f1a;border:1px solid #2a2a4a;color:#eee;border-radius:4px;padding:4px 10px;font-size:12px;width:180px">
  <input type="text" id="group-search" placeholder="Group..." oninput="filterTasks()"
         style="background:#0f0f1a;border:1px solid #2a2a4a;color:#eee;border-radius:4px;padding:4px 10px;font-size:12px;width:120px">
</div>

<div class="board">
  <?php foreach ($statuses_to_show as $status):
    $cnt = count($tasks[$status]);
    $label = $col_labels[$status];
  ?>
  <div class="col col-<?= $status ?>">
    <div class="col-head">
      <?= $label ?>
      <span class="count <?= $cnt?'has':'' ?>"><?= $cnt ?></span>
    </div>
    <div class="cards">
      <?php if (!$cnt): ?>
        <div class="empty">No tasks</div>
      <?php else: ?>
        <?php foreach ($tasks[$status] as $row):
          task_card($row, $is_admin, $is_readonly, $show_rewards, $require_login,
                    $session_name, $PRIO_COLOR, $PRIO_LABEL, $CAT_ICONS, $REWARD_TYPES);
        endforeach ?>
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
      <?php if ($session_name): ?>
        <label>Claiming as</label>
        <input type="text" value="<?= htmlspecialchars($session_name) ?>" disabled style="color:#888">
        <input type="hidden" name="claimed_by" value="<?= htmlspecialchars($session_name) ?>">
      <?php elseif ($require_login): ?>
        <p style="color:#cf6f6f;font-size:13px;margin:8px 0">You must be registered to claim tasks. <a href="/registry/" style="color:#4a9eff">Register here →</a></p>
      <?php else: ?>
        <label>Your name</label>
        <input type="text" name="claimed_by" id="claim-name" placeholder="Enter your name" maxlength="60" required>
      <?php endif ?>
      <div class="modal-btns">
        <?php if (!$require_login || $session_name): ?>
          <button type="submit" class="btn-red">Claim Task</button>
        <?php endif ?>
        <button type="button" class="btn-cancel" onclick="closeModals()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<?php if ($can_create): ?>
<!-- Create / Request modal -->
<div class="veil" id="veil-create">
  <div class="modal">
    <h2><?= $is_admin ? 'New Task' : 'Request a Task' ?></h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="create">
      <label>Title *</label>
      <input type="text" name="title" placeholder="What needs to be done?" maxlength="120" required>
      <label>Category</label>
      <select name="category">
        <?php foreach ($CATEGORIES as $c): ?>
          <option value="<?= htmlspecialchars($c) ?>"><?= $CAT_ICONS[$c] ?> <?= htmlspecialchars($c) ?></option>
        <?php endforeach ?>
      </select>
      <?php if ($is_admin): ?>
      <label>Priority</label>
      <select name="priority">
        <option value="urgent">🔴 Urgent</option>
        <option value="normal" selected>🔵 Normal</option>
        <option value="low">⚫ Low</option>
      </select>
      <?php endif ?>
      <label>Location / Address</label>
      <input type="text" name="location" placeholder="Optional" maxlength="120">
      <label>Group / Team <span style="color:#555;font-weight:normal">(optional)</span></label>
      <input type="text" name="group_name" placeholder="e.g. Team Alpha, Medical, Crew 3" maxlength="60">
      <label>Notes</label>
      <textarea name="notes" placeholder="Additional details…"></textarea>
      <label><?= $is_admin ? 'Created by' : 'Your name' ?></label>
      <input type="text" name="created_by" value="<?= htmlspecialchars($session_name) ?>"
             placeholder="<?= $is_admin ? 'Operator name' : 'Your name (optional)' ?>" maxlength="60">
      <?php if ($show_rewards): ?>
      <label>Reward</label>
      <select name="reward_type" id="create-reward-type" onchange="toggleRewardDesc('create')">
        <?php foreach ($REWARD_TYPES as $rk => $rl): ?>
          <option value="<?= $rk ?>"><?= $rl ?></option>
        <?php endforeach ?>
      </select>
      <div class="reward-row" id="create-reward-desc-row">
        <label>Reward description</label>
        <input type="text" name="reward_desc" id="create-reward-desc" placeholder='e.g. "$20", "1 meal ticket", "2h generator access"' maxlength="120">
      </div>
      <?php endif ?>
      <div class="modal-btns">
        <button type="submit" class="btn-red"><?= $is_admin ? 'Create Task' : 'Submit Request' ?></button>
        <button type="button" class="btn-cancel" onclick="closeModals()">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php endif ?>

<?php if ($is_admin): ?>
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
          <option value="<?= htmlspecialchars($c) ?>"><?= $CAT_ICONS[$c] ?> <?= htmlspecialchars($c) ?></option>
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
      <label>Group / Team <span style="color:#555;font-weight:normal">(optional)</span></label>
      <input type="text" name="group_name" id="edit-group-name" maxlength="60" placeholder="e.g. Team Alpha">
      <label>Notes</label>
      <textarea name="notes" id="edit-notes"></textarea>
      <?php if ($show_rewards): ?>
      <label>Reward</label>
      <select name="reward_type" id="edit-reward-type" onchange="toggleRewardDesc('edit')">
        <?php foreach ($REWARD_TYPES as $rk => $rl): ?>
          <option value="<?= $rk ?>"><?= $rl ?></option>
        <?php endforeach ?>
      </select>
      <div class="reward-row" id="edit-reward-desc-row">
        <label>Reward description</label>
        <input type="text" name="reward_desc" id="edit-reward-desc" maxlength="120">
      </div>
      <?php endif ?>
      <div class="modal-btns">
        <button type="submit" class="btn-red">Save</button>
        <button type="button" class="btn-cancel" onclick="closeModals()">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php endif ?>

<script>
var SHOW_REWARDS = <?= $show_rewards ? 'true' : 'false' ?>;

function toggleRewardDesc(prefix) {
  var sel = document.getElementById(prefix + '-reward-type');
  var row = document.getElementById(prefix + '-reward-desc-row');
  if (sel && row) row.classList.toggle('visible', sel.value !== 'none');
}

function openClaim(id) {
  document.getElementById('claim-id').value = id;
  var nameEl = document.getElementById('claim-name');
  if (nameEl) { nameEl.value = ''; }
  document.getElementById('veil-claim').classList.add('open');
  if (nameEl) nameEl.focus();
}
function openCreate() {
  document.getElementById('veil-create').classList.add('open');
}
function openEdit(id, row) {
  document.getElementById('edit-id').value       = id;
  document.getElementById('edit-title').value      = row.title;
  document.getElementById('edit-category').value   = row.category;
  document.getElementById('edit-priority').value   = row.priority;
  document.getElementById('edit-location').value   = row.location || '';
  document.getElementById('edit-notes').value      = row.notes || '';
  document.getElementById('edit-group-name').value = row.group_name || '';
  if (SHOW_REWARDS) {
    document.getElementById('edit-reward-type').value = row.reward_type || 'none';
    document.getElementById('edit-reward-desc').value = row.reward_desc || '';
    toggleRewardDesc('edit');
  }
  document.getElementById('veil-edit').classList.add('open');
}
function closeModals() {
  document.querySelectorAll('.veil').forEach(v => v.classList.remove('open'));
}

function filterTasks() {
  var q  = (document.getElementById('task-search').value  || '').toLowerCase();
  var gq = (document.getElementById('group-search').value || '').toLowerCase();
  document.querySelectorAll('.card[id^="task-"]').forEach(function(card) {
    var text  = card.textContent.toLowerCase();
    var group = (card.querySelector('.card-group') || {textContent:''}).textContent.toLowerCase();
    var matchQ = !q  || text.includes(q);
    var matchG = !gq || group.includes(gq);
    card.style.display = (matchQ && matchG) ? '' : 'none';
  });
}
document.querySelectorAll('.veil').forEach(v => {
  v.addEventListener('click', e => { if (e.target === v) closeModals(); });
});
</script>
</body>
</html>
