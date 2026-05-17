<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();
if (get_setting('show_tasks','1') !== '1') { http_response_code(404); exit; }

$is_admin    = !empty($_SESSION['admin']);
$is_readonly = is_readonly();

// Global feature flags
$show_rewards      = get_setting('tasks_show_rewards','0') === '1';
$require_login     = get_setting('tasks_require_login','0') === '1';
$allow_self_create = get_setting('tasks_allow_self_create','0') === '1';
$auto_close_hours  = max(0, (int)get_setting('tasks_auto_close_hours','0'));
$session_name      = $_SESSION['reg_name'] ?? '';

$db = new PDO('sqlite:/var/lib/noosphere/tasks.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA journal_mode=WAL");

// ── Schema ────────────────────────────────────────────────────────────────────

$db->exec("CREATE TABLE IF NOT EXISTS boards (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    slug       TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT '',
    categories TEXT NOT NULL DEFAULT 'General,Other',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL
)");

$db->exec("CREATE TABLE IF NOT EXISTS tasks (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    board_id     INTEGER NOT NULL DEFAULT 1,
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
    group_name   TEXT,
    created_at   INTEGER NOT NULL,
    updated_at   INTEGER NOT NULL
)");

// Migrate legacy columns
foreach (['reward_type TEXT NOT NULL DEFAULT \'none\'','reward_desc TEXT','group_name TEXT','board_id INTEGER NOT NULL DEFAULT 1'] as $col) {
    try { $db->exec("ALTER TABLE tasks ADD COLUMN $col"); } catch (Exception $e) {}
}

// Seed default board from global setting if boards table is empty
$board_count = (int)$db->query("SELECT COUNT(*) FROM boards")->fetchColumn();
if ($board_count === 0) {
    $default_cats = get_setting('tasks_categories', 'Rescue,Logistics,Medical,Maintenance,Other');
    $now = time();
    $db->prepare("INSERT INTO boards (name,slug,description,categories,sort_order,created_at) VALUES (?,?,?,?,0,?)")
       ->execute(['Tasks', 'tasks', 'General task board', $default_cats, $now]);
    // All existing tasks → board 1
    $db->exec("UPDATE tasks SET board_id=1 WHERE board_id IS NULL OR board_id=0");
}

// Auto-close claimed tasks past threshold
if ($auto_close_hours > 0) {
    $cutoff = time() - ($auto_close_hours * 3600);
    $db->exec("UPDATE tasks SET status='done',completed_at=updated_at WHERE status='claimed' AND claimed_at <= $cutoff");
}

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function make_board_slug($name) {
    $s = strtolower(trim($name));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'board';
}

function unique_board_slug($db, $base, $exclude_id = null) {
    $slug = $base; $n = 1;
    while (true) {
        $s = $db->prepare("SELECT id FROM boards WHERE slug=?");
        $s->execute([$slug]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row || ($exclude_id && (int)$row['id'] === $exclude_id)) return $slug;
        $slug = $base . '-' . (++$n);
    }
}

// ── Route ─────────────────────────────────────────────────────────────────────

$route      = $_GET['route'] ?? 'auto';   // auto | board | manage
$board_slug = trim($_GET['board'] ?? '');
$manage     = isset($_GET['manage']) && $is_admin;

// Load current board if slug given
$board = null;
if ($board_slug) {
    $s = $db->prepare("SELECT * FROM boards WHERE slug=?");
    $s->execute([$board_slug]);
    $board = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$board) $board_slug = ''; // invalid slug → fall through to auto
}

// Auto-route: single board → redirect straight in
if (!$board_slug && !$manage) {
    $all_boards = $db->query("SELECT * FROM boards ORDER BY sort_order ASC, created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
    if (count($all_boards) === 1) {
        header('Location: /tasks/?board=' . urlencode($all_boards[0]['slug']));
        exit;
    }
}

// ── POST ──────────────────────────────────────────────────────────────────────

$msg = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    readonly_die();
    $act = $_POST['act'] ?? '';

    // ── Board management (admin only) ─────────────────────────────────────────
    if ($is_admin && $act === 'board_create') {
        $bname = trim($_POST['board_name'] ?? '');
        $bdesc = trim($_POST['board_desc'] ?? '');
        $bcats = trim($_POST['board_cats'] ?? '');
        if (!$bname) { $error = 'Board name is required.'; }
        else {
            $slug = unique_board_slug($db, make_board_slug($bname));
            $db->prepare("INSERT INTO boards (name,slug,description,categories,sort_order,created_at) VALUES (?,?,?,?,?,?)")
               ->execute([$bname, $slug, $bdesc, $bcats ?: 'General,Other', 0, time()]);
            $msg = "Board \"{$bname}\" created.";
        }
        header('Location: /tasks/?manage=1' . ($msg ? '&msg='.urlencode($msg) : '&err='.urlencode($error)));
        exit;
    }

    if ($is_admin && $act === 'board_edit') {
        $bid   = (int)($_POST['board_id'] ?? 0);
        $bname = trim($_POST['board_name'] ?? '');
        $bdesc = trim($_POST['board_desc'] ?? '');
        $bcats = trim($_POST['board_cats'] ?? '');
        if ($bid && $bname) {
            $new_slug = unique_board_slug($db, make_board_slug($bname), $bid);
            $db->prepare("UPDATE boards SET name=?,slug=?,description=?,categories=? WHERE id=?")
               ->execute([$bname, $new_slug, $bdesc, $bcats ?: 'General,Other', $bid]);
            $msg = 'Board updated.';
        }
        header('Location: /tasks/?manage=1&msg=' . urlencode($msg ?: 'No changes'));
        exit;
    }

    if ($is_admin && $act === 'board_delete') {
        $bid = (int)($_POST['board_id'] ?? 0);
        if ($bid) {
            $count = (int)$db->query("SELECT COUNT(*) FROM boards")->fetchColumn();
            if ($count <= 1) {
                header('Location: /tasks/?manage=1&err=' . urlencode('Cannot delete the last board.'));
                exit;
            }
            // Move tasks to board 1 (or another board)
            $fallback = $db->query("SELECT id FROM boards WHERE id != $bid ORDER BY sort_order ASC, created_at ASC LIMIT 1")->fetchColumn();
            $db->prepare("UPDATE tasks SET board_id=? WHERE board_id=?")->execute([$fallback, $bid]);
            $db->prepare("DELETE FROM boards WHERE id=?")->execute([$bid]);
            $msg = 'Board deleted.';
        }
        header('Location: /tasks/?manage=1&msg=' . urlencode($msg));
        exit;
    }

    // ── Task actions (require a valid board) ──────────────────────────────────
    $post_board_slug = trim($_POST['board_slug'] ?? '');
    $pb = null;
    if ($post_board_slug) {
        $s = $db->prepare("SELECT * FROM boards WHERE slug=?");
        $s->execute([$post_board_slug]);
        $pb = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $redirect_back = '/tasks/' . ($pb ? '?board=' . urlencode($pb['slug']) : '');

    if ($pb) {
        $pb_cats = array_values(array_filter(array_map('trim', explode(',', $pb['categories']))));
        if (!$pb_cats) $pb_cats = ['Other'];

        $PRIORITIES = ['urgent','normal','low'];
        $REWARD_TYPES = ['none'=>'No reward','cash'=>'💵 Cash','barter'=>'🔄 Barter',
                         'community_credit'=>'⭐ Community Credit','other'=>'🎁 Other'];

        $can_create = $is_admin || ($allow_self_create && !$is_readonly);

        if ($act === 'create' && $can_create) {
            $title      = trim($_POST['title'] ?? '');
            $category   = in_array($_POST['category'] ?? '', $pb_cats) ? $_POST['category'] : $pb_cats[0];
            $priority   = $is_admin && in_array($_POST['priority'] ?? '', $PRIORITIES) ? $_POST['priority'] : 'normal';
            $location   = trim($_POST['location'] ?? '');
            $notes      = trim($_POST['notes'] ?? '');
            $group_name = trim($_POST['group_name'] ?? '');
            $by         = trim($_POST['created_by'] ?? '') ?: ($session_name ?: ($is_admin ? 'Operator' : 'Community'));
            $rtype      = ($show_rewards && array_key_exists($_POST['reward_type'] ?? '', $REWARD_TYPES))
                          ? $_POST['reward_type'] : 'none';
            $rdesc      = $show_rewards ? trim($_POST['reward_desc'] ?? '') : '';
            $new_status = $is_admin ? 'open' : 'pending';

            if (!$title) { $error = 'Title is required.'; }
            else {
                $now = time();
                $db->prepare("INSERT INTO tasks (board_id,title,category,priority,status,location,notes,group_name,created_by,reward_type,reward_desc,created_at,updated_at)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$pb['id'],$title,$category,$priority,$new_status,$location,$notes,
                              $group_name ?: null,$by,$rtype,$rdesc,$now,$now]);
                $msg = $is_admin ? 'Task created.' : 'Task submitted — awaiting operator approval.';
            }
        }

        if ($act === 'approve' && $is_admin) {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $db->prepare("UPDATE tasks SET status='open',updated_at=? WHERE id=? AND status='pending'")
                   ->execute([time(), $id]);
                $msg = 'Task approved.';
            }
        }

        if ($act === 'claim') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = $session_name ?: trim($_POST['claimed_by'] ?? '');
            if ($require_login && !$session_name) {
                $error = 'You must be registered to claim a task.';
            } elseif (!$name) {
                $error = 'Your name is required to claim a task.';
            } elseif ($id) {
                $now = time();
                $db->prepare("UPDATE tasks SET status='claimed',claimed_by=?,claimed_at=?,updated_at=? WHERE id=? AND status='open'")
                   ->execute([$name, $now, $now, $id]);
                $msg = 'Task claimed.';
            }
        }

        if ($act === 'done') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $row = $db->prepare("SELECT status FROM tasks WHERE id=?");
                $row->execute([$id]);
                $row = $row->fetch(PDO::FETCH_ASSOC);
                if ($row && ($is_admin || $row['status'] === 'claimed')) {
                    $now = time();
                    $db->prepare("UPDATE tasks SET status='done',completed_at=?,updated_at=? WHERE id=?")
                       ->execute([$now, $now, $id]);
                    $msg = 'Task marked done.';
                }
            }
        }

        if ($act === 'release' && $is_admin) {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $db->prepare("UPDATE tasks SET status='open',claimed_by=NULL,claimed_at=NULL,updated_at=? WHERE id=?")
                   ->execute([time(), $id]);
                $msg = 'Task released.';
            }
        }

        if ($act === 'delete' && $is_admin) {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $db->prepare("DELETE FROM tasks WHERE id=?")->execute([$id]);
                $msg = 'Task deleted.';
            }
        }

        if ($act === 'edit' && $is_admin) {
            $id         = (int)($_POST['id'] ?? 0);
            $title      = trim($_POST['title'] ?? '');
            $category   = in_array($_POST['category'] ?? '', $pb_cats) ? $_POST['category'] : $pb_cats[0];
            $priority   = in_array($_POST['priority'] ?? '', $PRIORITIES) ? $_POST['priority'] : 'normal';
            $location   = trim($_POST['location'] ?? '');
            $notes      = trim($_POST['notes'] ?? '');
            $group_name = trim($_POST['group_name'] ?? '');
            $rtype      = ($show_rewards && array_key_exists($_POST['reward_type'] ?? '', $REWARD_TYPES))
                          ? $_POST['reward_type'] : 'none';
            $rdesc      = $show_rewards ? trim($_POST['reward_desc'] ?? '') : '';
            if ($id && $title) {
                $db->prepare("UPDATE tasks SET title=?,category=?,priority=?,location=?,notes=?,group_name=?,reward_type=?,reward_desc=?,updated_at=? WHERE id=?")
                   ->execute([$title,$category,$priority,$location,$notes,$group_name ?: null,$rtype,$rdesc,time(),$id]);
                $msg = 'Task updated.';
            }
        }
    }

    header('Location: ' . $redirect_back . ($msg ? (strpos($redirect_back,'?')!==false?'&':'?') . 'msg=' . urlencode($msg) : ''));
    exit;
}

if (isset($_GET['msg'])) $msg = esc($_GET['msg']);
if (isset($_GET['err'])) $error = esc($_GET['err']);

// ── Board-level data ──────────────────────────────────────────────────────────

$all_boards = $db->query("SELECT * FROM boards ORDER BY sort_order ASC, created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
$multi_board = count($all_boards) > 1;

$PRIO_COLOR = ['urgent'=>'#e94560','normal'=>'#4a9eff','low'=>'#555'];
$PRIO_LABEL = ['urgent'=>'URGENT','normal'=>'Normal','low'=>'Low'];
$_ICON_MAP  = ['rescue'=>'🚨','search'=>'🔍','medical'=>'🏥','logistics'=>'📦',
               'maintenance'=>'🔧','communications'=>'📻','command'=>'⭐',
               'staffing'=>'👥','intake'=>'📝','cleanup'=>'🧹','setup'=>'🏗',
               'distribution'=>'📤','collection'=>'📥','volunteers'=>'🙋',
               'other'=>'📋','general'=>'📋'];

$name = get_setting('instance_name', 'Noosphere');

function time_since($ts) {
    if (!$ts) return '';
    $d = time() - $ts;
    if ($d < 3600) return round($d/60) . 'm ago';
    return round($d/3600) . 'h ago';
}

function task_card_html($row, $is_admin, $is_readonly, $show_rewards, $require_login,
                        $session_name, $PRIO_COLOR, $PRIO_LABEL, $CAT_ICONS, $REWARD_TYPES,
                        $board_slug, $PRIORITIES, $pb_cats) {
    $id         = (int)$row['id'];
    $title      = esc($row['title']);
    $cat        = $row['category'];
    $pri        = $row['priority'];
    $loc        = esc($row['location'] ?? '');
    $notes      = esc($row['notes'] ?? '');
    $by         = esc($row['created_by'] ?? '');
    $icon       = $CAT_ICONS[$cat] ?? '📋';
    $pcolor     = $PRIO_COLOR[$pri] ?? '#555';
    $plabel     = $PRIO_LABEL[$pri] ?? $pri;
    $claimed_by = esc($row['claimed_by'] ?? '');
    $group_name = esc($row['group_name'] ?? '');
    $status     = $row['status'];
    $rtype      = $row['reward_type'] ?? 'none';
    $rdesc      = esc($row['reward_desc'] ?? '');
    $age        = time_since($row['created_at']);
    $csrf       = csrf_field();
    $bs         = esc($board_slug);
    ob_start(); ?>
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
        <div class="card-reward"><?= $REWARD_TYPES[$rtype] ?? $rtype ?><?php if ($rdesc): ?> — <?= $rdesc ?><?php endif ?></div>
      <?php endif ?>
      <div class="card-meta">
        <?php if ($by): ?>by <?= $by ?><?php endif ?>
        <?php if ($age): ?> · <?= $age ?><?php endif ?>
        <?php if ($claimed_by && !in_array($status, ['open','pending'])): ?>
          · <?= $status === 'done' ? '✓' : '⚙' ?> <?= $claimed_by ?>
        <?php endif ?>
      </div>
      <?php if (!$is_readonly): ?>
      <div class="card-actions">
        <?php if ($status === 'pending' && $is_admin): ?>
          <form method="post" style="display:inline"><?= $csrf ?>
            <input type="hidden" name="board_slug" value="<?= $bs ?>">
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
            <input type="hidden" name="board_slug" value="<?= $bs ?>">
            <input type="hidden" name="act" value="done">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn-done">Mark Done</button>
          </form>
        <?php endif ?>
        <?php if ($is_admin): ?>
          <?php if ($status === 'claimed'): ?>
            <form method="post" style="display:inline"><?= $csrf ?>
              <input type="hidden" name="board_slug" value="<?= $bs ?>">
              <input type="hidden" name="act" value="release">
              <input type="hidden" name="id" value="<?= $id ?>">
              <button class="btn-release">Release</button>
            </form>
          <?php endif ?>
          <?php if (in_array($status, ['open','claimed'])): ?>
            <form method="post" style="display:inline"><?= $csrf ?>
              <input type="hidden" name="board_slug" value="<?= $bs ?>">
              <input type="hidden" name="act" value="done">
              <input type="hidden" name="id" value="<?= $id ?>">
              <button class="btn-done">Done</button>
            </form>
          <?php endif ?>
          <button class="btn-edit" onclick="openEdit(<?= $id ?>, <?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>)">Edit</button>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this task?')"><?= $csrf ?>
            <input type="hidden" name="board_slug" value="<?= $bs ?>">
            <input type="hidden" name="act" value="delete">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn-del">✕</button>
          </form>
        <?php endif ?>
      </div>
      <?php endif ?>
    </div>
    <?php
    return ob_get_clean();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $board ? esc($board['name']).' — ' : '' ?>Tasks — <?= esc($name) ?></title>
<?= csrf_js() ?>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#0f0f1a;color:#e0e0e0;min-height:100vh;display:flex;flex-direction:column}
a{color:#7ad;text-decoration:none}
a:hover{color:#e94560}
header{background:#1a1a2e;border-bottom:2px solid #e94560;padding:10px 14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
header h1{font-size:15px;color:#e94560;flex:1}
header a.back{color:#888;text-decoration:none;font-size:13px}
header a.back:hover{color:#e94560}
.msg{background:#1a3a1a;border:1px solid #2a6a2a;color:#6fcf6f;padding:8px 14px;font-size:13px}
.err{background:#3a1a1a;border:1px solid #6a2a2a;color:#cf6f6f;padding:8px 14px;font-size:13px}

/* Board picker */
.picker{padding:2rem;max-width:1000px;margin:0 auto;width:100%}
.picker h2{font-size:1.1rem;color:#555;margin-bottom:1.5rem;font-weight:normal}
.board-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1.25rem}
.board-tile{display:block;background:#16213e;border:1px solid #e94560;border-radius:12px;padding:1.5rem;text-decoration:none;color:#eee;transition:.2s;position:relative}
.board-tile:hover{background:#e94560;transform:translateY(-3px)}
.board-tile h3{font-size:1.1rem;font-weight:bold;margin-bottom:.4rem}
.board-tile .tile-desc{font-size:12px;color:#aaa;margin-bottom:.85rem;min-height:2em}
.board-tile:hover .tile-desc{color:#fff}
.board-tile .tile-stats{display:flex;gap:.75rem;flex-wrap:wrap}
.tile-badge{font-size:11px;border-radius:3px;padding:2px 8px;font-weight:bold}
.tile-badge.open{background:#e9456020;color:#e94560;border:1px solid #e9456044}
.tile-badge.claimed{background:#4a9eff20;color:#4a9eff;border:1px solid #4a9eff44}
.tile-badge.done{background:#2ecc7120;color:#2ecc71;border:1px solid #2ecc7144}
.board-tile:hover .tile-badge{background:rgba(0,0,0,.2);color:#fff;border-color:rgba(255,255,255,.3)}
.tile-cats{font-size:10px;color:#555;margin-top:.5rem}
.board-tile:hover .tile-cats{color:#fffd}

/* Manager */
.manage-wrap{padding:1.5rem;max-width:700px}
.manage-wrap h2{font-size:1.1rem;color:#e94560;margin-bottom:1.25rem}
.board-row{background:#16213e;border:1px solid #2a2a4a;border-radius:8px;padding:1rem;margin-bottom:.75rem;display:flex;gap:1rem;align-items:flex-start}
.board-row-info{flex:1;min-width:0}
.board-row-info strong{font-size:14px}
.board-row-info small{font-size:11px;color:#555;display:block;margin-top:2px}
.board-row-actions{display:flex;gap:6px;flex-shrink:0;align-items:center}
.create-form{background:#16213e;border:1px solid #2a2a4a;border-radius:8px;padding:1.25rem;margin-top:1.25rem}
.create-form h3{font-size:13px;color:#aaa;margin-bottom:1rem}
label.fl{display:block;font-size:11px;color:#777;margin-bottom:3px;margin-top:10px}
input.fi,textarea.fi{width:100%;background:#0f0f1a;border:1px solid #2a2a4a;border-radius:5px;color:#eee;padding:7px 10px;font-size:13px}
input.fi:focus,textarea.fi:focus{outline:none;border-color:#e94560}
textarea.fi{resize:vertical;height:48px}
.btn{background:#e94560;color:#fff;border:none;border-radius:6px;padding:7px 16px;font-size:13px;cursor:pointer;display:inline-block}
.btn:hover{background:#c73652}
.btn-sm{background:none;border:1px solid #555;color:#aaa;border-radius:4px;padding:3px 10px;font-size:12px;cursor:pointer}
.btn-sm:hover{border-color:#e94560;color:#e94560}
.btn-danger{background:none;border:1px solid #555;color:#555;border-radius:4px;padding:3px 10px;font-size:12px;cursor:pointer}
.btn-danger:hover{border-color:#e94560;color:#e94560}

/* Board view */
.filters{background:#13131f;border-bottom:1px solid #1e1e3a;padding:8px 14px;display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.filter-btn{background:#16213e;border:1px solid #2a2a4a;color:#888;padding:4px 10px;border-radius:4px;font-size:12px;cursor:pointer;text-decoration:none}
.filter-btn.active,.filter-btn:hover{border-color:#e94560;color:#e94560}
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
.create-btn{background:#e94560;color:#fff;border:none;padding:6px 14px;border-radius:5px;font-size:13px;font-weight:bold;cursor:pointer}
.request-btn{background:#16213e;color:#aaa;border:1px solid #2a2a4a;padding:6px 14px;border-radius:5px;font-size:13px;cursor:pointer}

/* Modal */
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
.reward-row{display:none}
.reward-row.visible{display:block}

/* Board switcher nav */
.board-nav{background:#0d0d1f;border-bottom:1px solid #1e1e3a;padding:6px 14px;display:flex;gap:6px;flex-wrap:wrap;align-items:center;font-size:12px}
.board-nav a{color:#555;padding:3px 10px;border-radius:4px;text-decoration:none;border:1px solid transparent}
.board-nav a:hover{color:#aaa;border-color:#2a2a4a}
.board-nav a.active-board{color:#e94560;border-color:#e94560}

@media(max-width:600px){
  .board{flex-direction:column;overflow:auto}
  .col{border-right:none;border-bottom:1px solid #1e1e3a;min-height:200px}
  .cards{overflow-y:visible}
}
</style>
</head>
<body>

<header>
  <?php if ($board && $multi_board): ?>
    <a class="back" href="/tasks/">← Boards</a>
  <?php elseif (!$board && !$manage): ?>
    <a class="back" href="/">← Home</a>
  <?php elseif ($manage): ?>
    <a class="back" href="/tasks/">← Tasks</a>
  <?php else: ?>
    <a class="back" href="/">← Home</a>
  <?php endif ?>
  <h1>📋 <?= $board ? esc($board['name']) : ($manage ? 'Manage Boards' : 'Task Boards') ?></h1>
  <?php if ($board): ?>
    <?php $can_create = $is_admin || ($allow_self_create && !$is_readonly); ?>
    <?php if ($can_create): ?>
      <?php if ($is_admin): ?>
        <button class="create-btn" onclick="openCreate()">+ New Task</button>
      <?php else: ?>
        <button class="request-btn" onclick="openCreate()">+ Request Task</button>
      <?php endif ?>
    <?php endif ?>
  <?php endif ?>
  <?php if (!$manage && $is_admin): ?>
    <a href="/tasks/?manage=1" class="back" style="font-size:12px;color:#555">⚙ Manage Boards</a>
  <?php endif ?>
  <?php if (!$board && !$manage): ?>
    <a href="/" class="back" style="font-size:13px">← Home</a>
  <?php endif ?>
</header>

<?php if ($msg): ?><div class="msg"><?= $msg ?></div><?php endif ?>
<?php if ($error): ?><div class="err"><?= $error ?></div><?php endif ?>

<?php
// ══════════════════════════════════════════════════════════════════════════════
// MANAGE BOARDS VIEW
// ══════════════════════════════════════════════════════════════════════════════
if ($manage):
?>
<div class="manage-wrap">
  <h2>Boards</h2>

  <?php foreach ($all_boards as $b):
    $task_count = (int)$db->prepare("SELECT COUNT(*) FROM tasks WHERE board_id=?")->execute([$b['id']]) ? $db->query("SELECT COUNT(*) FROM tasks WHERE board_id={$b['id']}")->fetchColumn() : 0;
  ?>
  <div class="board-row">
    <div class="board-row-info">
      <strong><?= esc($b['name']) ?></strong>
      <small>/tasks/?board=<?= esc($b['slug']) ?> &nbsp;·&nbsp; <?= $task_count ?> task<?= $task_count!==1?'s':'' ?></small>
      <?php if ($b['description']): ?><small style="color:#666;margin-top:2px"><?= esc($b['description']) ?></small><?php endif ?>
      <small style="color:#444;margin-top:2px">Categories: <?= esc($b['categories']) ?></small>
    </div>
    <div class="board-row-actions">
      <a href="/tasks/?board=<?= esc($b['slug']) ?>" class="btn-sm">Open</a>
      <button class="btn-sm" onclick="openBoardEdit(<?= htmlspecialchars(json_encode($b), ENT_QUOTES) ?>)">Edit</button>
      <?php if (count($all_boards) > 1): ?>
      <form method="post" onsubmit="return confirm('Delete this board? Tasks will be moved to another board.')">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="board_delete">
        <input type="hidden" name="board_id" value="<?= (int)$b['id'] ?>">
        <button type="submit" class="btn-danger">Delete</button>
      </form>
      <?php endif ?>
    </div>
  </div>
  <?php endforeach; ?>

  <div class="create-form">
    <h3>Create New Board</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="board_create">
      <label class="fl">Board Name *</label>
      <input type="text" name="board_name" class="fi" placeholder="e.g. Medical, Logistics, Field Ops" maxlength="80" required>
      <label class="fl">Description</label>
      <input type="text" name="board_desc" class="fi" placeholder="Short description shown on the board picker" maxlength="160">
      <label class="fl">Categories <span style="color:#444;font-weight:normal">(comma-separated)</span></label>
      <input type="text" name="board_cats" class="fi" placeholder="e.g. Triage,Treatment,Transport,Other" value="<?= esc(get_setting('tasks_categories','General,Other')) ?>">
      <div style="margin-top:14px">
        <button type="submit" class="btn">Create Board</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit board modal -->
<div class="veil" id="veil-board-edit">
  <div class="modal">
    <h2>Edit Board</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="board_edit">
      <input type="hidden" name="board_id" id="bedit-id">
      <label>Board Name *</label>
      <input type="text" name="board_name" id="bedit-name" maxlength="80" required>
      <label>Description</label>
      <input type="text" name="board_desc" id="bedit-desc" maxlength="160">
      <label>Categories (comma-separated)</label>
      <input type="text" name="board_cats" id="bedit-cats">
      <div class="modal-btns">
        <button type="submit" class="btn-red">Save</button>
        <button type="button" class="btn-cancel" onclick="closeBoardEdit()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openBoardEdit(b) {
  document.getElementById('bedit-id').value   = b.id;
  document.getElementById('bedit-name').value = b.name;
  document.getElementById('bedit-desc').value = b.description;
  document.getElementById('bedit-cats').value = b.categories;
  document.getElementById('veil-board-edit').classList.add('open');
}
function closeBoardEdit() {
  document.getElementById('veil-board-edit').classList.remove('open');
}
document.getElementById('veil-board-edit').addEventListener('click', function(e) {
  if (e.target === this) closeBoardEdit();
});
</script>

<?php
// ══════════════════════════════════════════════════════════════════════════════
// BOARD PICKER
// ══════════════════════════════════════════════════════════════════════════════
elseif (!$board):
?>
<div class="picker">
  <h2>Select a board</h2>
  <div class="board-grid">
  <?php foreach ($all_boards as $b):
    $open_count     = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE board_id={$b['id']} AND status='open'")->fetchColumn();
    $claimed_count  = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE board_id={$b['id']} AND status='claimed'")->fetchColumn();
    $done_count     = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE board_id={$b['id']} AND status='done'")->fetchColumn();
    $cats_preview   = implode(', ', array_slice(array_map('trim', explode(',', $b['categories'])), 0, 4));
  ?>
  <a class="board-tile" href="/tasks/?board=<?= esc($b['slug']) ?>">
    <h3><?= esc($b['name']) ?></h3>
    <div class="tile-desc"><?= $b['description'] ? esc($b['description']) : '&nbsp;' ?></div>
    <div class="tile-stats">
      <?php if ($open_count):    ?><span class="tile-badge open"><?= $open_count ?> open</span><?php endif ?>
      <?php if ($claimed_count): ?><span class="tile-badge claimed"><?= $claimed_count ?> active</span><?php endif ?>
      <?php if ($done_count):    ?><span class="tile-badge done"><?= $done_count ?> done</span><?php endif ?>
      <?php if (!$open_count && !$claimed_count && !$done_count): ?><span style="font-size:11px;color:#444">No tasks yet</span><?php endif ?>
    </div>
    <div class="tile-cats"><?= esc($cats_preview) ?></div>
  </a>
  <?php endforeach; ?>
  </div>
</div>

<?php
// ══════════════════════════════════════════════════════════════════════════════
// BOARD KANBAN VIEW
// ══════════════════════════════════════════════════════════════════════════════
else:
  $pb_cats_raw = $board['categories'];
  $pb_cats = array_values(array_filter(array_map('trim', explode(',', $pb_cats_raw))));
  if (!$pb_cats) $pb_cats = ['Other'];

  $CAT_ICONS = [];
  foreach ($pb_cats as $cat) {
    $CAT_ICONS[$cat] = $_ICON_MAP[strtolower($cat)] ?? '📋';
  }

  $PRIORITIES   = ['urgent','normal','low'];
  $REWARD_TYPES = ['none'=>'No reward','cash'=>'💵 Cash','barter'=>'🔄 Barter',
                   'community_credit'=>'⭐ Community Credit','other'=>'🎁 Other'];

  $can_create = $is_admin || ($allow_self_create && !$is_readonly);

  $cat_filter = $_GET['cat'] ?? 'all';
  $cat_where  = ($cat_filter !== 'all' && in_array($cat_filter, $pb_cats))
                ? " AND category=" . $db->quote($cat_filter)
                : '';

  $statuses_to_show = $is_admin ? ['pending','open','claimed','done'] : ['open','claimed','done'];
  $tasks = array_fill_keys($statuses_to_show, []);

  $stmt = $db->query("SELECT * FROM tasks WHERE board_id={$board['id']} AND status IN ('"
      . implode("','", $statuses_to_show) . "')" . $cat_where
      . " ORDER BY CASE priority WHEN 'urgent' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END, created_at ASC");
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      if (isset($tasks[$row['status']])) $tasks[$row['status']][] = $row;
  }

  $col_labels = ['pending'=>'Pending Approval','open'=>'Open','claimed'=>'In Progress','done'=>'Done'];
?>

<?php if ($multi_board): ?>
<div class="board-nav">
  <?php foreach ($all_boards as $b): ?>
  <a href="/tasks/?board=<?= esc($b['slug']) ?>" class="<?= $b['slug'] === $board['slug'] ? 'active-board' : '' ?>"><?= esc($b['name']) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="filters">
  <span style="font-size:11px;color:#555;margin-right:4px">Filter:</span>
  <a href="/tasks/?board=<?= esc($board['slug']) ?>" class="filter-btn <?= $cat_filter==='all'?'active':'' ?>">All</a>
  <?php foreach ($CAT_ICONS as $k => $icon): ?>
    <a href="/tasks/?board=<?= esc($board['slug']) ?>&cat=<?= urlencode($k) ?>" class="filter-btn <?= $cat_filter===$k?'active':'' ?>"><?= $icon ?> <?= esc($k) ?></a>
  <?php endforeach ?>
  <input type="text" id="task-search" placeholder="Search tasks…" oninput="filterTasks()"
         style="margin-left:auto;background:#0f0f1a;border:1px solid #2a2a4a;color:#eee;border-radius:4px;padding:4px 10px;font-size:12px;width:180px">
  <input type="text" id="group-search" placeholder="Group…" oninput="filterTasks()"
         style="background:#0f0f1a;border:1px solid #2a2a4a;color:#eee;border-radius:4px;padding:4px 10px;font-size:12px;width:120px">
</div>

<div class="board">
  <?php foreach ($statuses_to_show as $status):
    $cnt = count($tasks[$status]);
  ?>
  <div class="col col-<?= $status ?>">
    <div class="col-head">
      <?= $col_labels[$status] ?>
      <span class="count <?= $cnt?'has':'' ?>"><?= $cnt ?></span>
    </div>
    <div class="cards">
      <?php if (!$cnt): ?>
        <div class="empty">No tasks</div>
      <?php else: ?>
        <?php foreach ($tasks[$status] as $row):
          echo task_card_html($row, $is_admin, $is_readonly, $show_rewards, $require_login,
                              $session_name, $PRIO_COLOR, $PRIO_LABEL, $CAT_ICONS, $REWARD_TYPES,
                              $board['slug'], $PRIORITIES, $pb_cats);
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
      <input type="hidden" name="board_slug" value="<?= esc($board['slug']) ?>">
      <input type="hidden" name="act" value="claim">
      <input type="hidden" name="id" id="claim-id">
      <?php if ($session_name): ?>
        <label>Claiming as</label>
        <input type="text" value="<?= esc($session_name) ?>" disabled style="color:#888">
        <input type="hidden" name="claimed_by" value="<?= esc($session_name) ?>">
      <?php elseif ($require_login): ?>
        <p style="color:#cf6f6f;font-size:13px;margin:8px 0">You must be registered to claim tasks. <a href="/registry/" style="color:#4a9eff">Register →</a></p>
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
<!-- Create modal -->
<div class="veil" id="veil-create">
  <div class="modal">
    <h2><?= $is_admin ? 'New Task' : 'Request a Task' ?></h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="board_slug" value="<?= esc($board['slug']) ?>">
      <input type="hidden" name="act" value="create">
      <label>Title *</label>
      <input type="text" name="title" placeholder="What needs to be done?" maxlength="120" required>
      <label>Category</label>
      <select name="category">
        <?php foreach ($pb_cats as $c): ?>
          <option value="<?= esc($c) ?>"><?= $CAT_ICONS[$c] ?? '📋' ?> <?= esc($c) ?></option>
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
      <input type="text" name="created_by" value="<?= esc($session_name) ?>"
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
        <input type="text" name="reward_desc" placeholder='e.g. "$20", "1 meal ticket"' maxlength="120">
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
      <input type="hidden" name="board_slug" value="<?= esc($board['slug']) ?>">
      <input type="hidden" name="act" value="edit">
      <input type="hidden" name="id" id="edit-id">
      <label>Title *</label>
      <input type="text" name="title" id="edit-title" maxlength="120" required>
      <label>Category</label>
      <select name="category" id="edit-category">
        <?php foreach ($pb_cats as $c): ?>
          <option value="<?= esc($c) ?>"><?= $CAT_ICONS[$c] ?? '📋' ?> <?= esc($c) ?></option>
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
      <label>Group / Team</label>
      <input type="text" name="group_name" id="edit-group-name" maxlength="60">
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

<?php endif; // end board view ?>

<script>
var SHOW_REWARDS = <?= $show_rewards ? 'true' : 'false' ?>;
function toggleRewardDesc(prefix) {
  var sel = document.getElementById(prefix+'-reward-type');
  var row = document.getElementById(prefix+'-reward-desc-row');
  if (sel && row) row.classList.toggle('visible', sel.value !== 'none');
}
function openClaim(id) {
  document.getElementById('claim-id').value = id;
  var n = document.getElementById('claim-name');
  if (n) n.value = '';
  document.getElementById('veil-claim').classList.add('open');
  if (n) n.focus();
}
function openCreate() { document.getElementById('veil-create').classList.add('open'); }
function openEdit(id, row) {
  document.getElementById('edit-id').value         = id;
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
  var q  = (document.getElementById('task-search')?.value  || '').toLowerCase();
  var gq = (document.getElementById('group-search')?.value || '').toLowerCase();
  document.querySelectorAll('.card[id^="task-"]').forEach(function(card) {
    var text  = card.textContent.toLowerCase();
    var group = (card.querySelector('.card-group') || {textContent:''}).textContent.toLowerCase();
    card.style.display = ((!q || text.includes(q)) && (!gq || group.includes(gq))) ? '' : 'none';
  });
}
document.querySelectorAll('.veil').forEach(v => {
  v.addEventListener('click', e => { if (e.target === v) closeModals(); });
});
</script>
</body>
</html>
