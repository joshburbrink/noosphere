<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();

$db = new PDO('sqlite:/var/lib/noosphere/forum.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("
CREATE TABLE IF NOT EXISTS threads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category TEXT NOT NULL,
    title TEXT NOT NULL,
    author TEXT NOT NULL,
    pinned INTEGER DEFAULT 0,
    reg_status TEXT,
    reg_location TEXT,
    created_at INTEGER NOT NULL,
    reply_count INTEGER DEFAULT 0,
    last_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    thread_id INTEGER NOT NULL,
    author TEXT NOT NULL,
    body TEXT NOT NULL,
    reg_status TEXT,
    reg_location TEXT,
    created_at INTEGER NOT NULL
);
");

$CATS = ['announcements' => ['label' => 'Announcements', 'icon' => '📢', 'desc' => 'Official updates — shelter, roads, water, power']];
foreach (get_forum_categories() as $cat) {
    $CATS[$cat['key']] = ['label' => $cat['label'], 'icon' => $cat['icon'], 'desc' => $cat['desc'] ?? ''];
}

function dotClass($s) {
    if (!$s) return 'dot-unknown';
    $s = strtolower($s);
    if ($s === 'ok') return 'dot-ok';
    if (strpos($s, 'help') !== false) return 'dot-help';
    if (strpos($s, 'evacuat') !== false) return 'dot-evac';
    return 'dot-unknown';
}

function esc($s) { return htmlspecialchars($s, ENT_QUOTES); }
function ago($ts) {
    $d = time() - $ts;
    if ($d < 60) return $d . 's ago';
    if ($d < 3600) return floor($d/60) . 'm ago';
    if ($d < 86400) return floor($d/3600) . 'h ago';
    return floor($d/86400) . 'd ago';
}

$view = $_GET['view'] ?? 'home';
$cat  = $_GET['cat']  ?? '';
$tid  = (int)($_GET['thread'] ?? 0);

// Handle admin delete actions (GET with session check)
if (isset($_GET['del_thread']) && !empty($_SESSION['forum_admin'])) {
    $del_tid = (int)$_GET['del_thread'];
    if ($del_tid > 0) {
        $db->prepare('DELETE FROM posts WHERE thread_id=?')->execute([$del_tid]);
        $db->prepare('DELETE FROM threads WHERE id=?')->execute([$del_tid]);
    }
    header('Location: /forum/');
    exit;
}

if (isset($_GET['del_post']) && !empty($_SESSION['forum_admin'])) {
    $del_pid = (int)$_GET['del_post'];
    if ($del_pid > 0) {
        // Find thread_id so we can redirect back
        $del_post_row = $db->prepare('SELECT thread_id FROM posts WHERE id=?');
        $del_post_row->execute([$del_pid]);
        $del_post_row = $del_post_row->fetch(PDO::FETCH_ASSOC);
        $db->prepare('DELETE FROM posts WHERE id=?')->execute([$del_pid]);
        if ($del_post_row) {
            $db->prepare('UPDATE threads SET reply_count=reply_count-1 WHERE id=?')->execute([$del_post_row['thread_id']]);
            header('Location: /forum/?view=thread&thread=' . $del_post_row['thread_id']);
            exit;
        }
    }
    header('Location: /forum/');
    exit;
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    readonly_die();
    $act = $_POST['act'] ?? '';
    $author = trim($_POST['author'] ?? '');
    $pin    = trim($_POST['pin']    ?? '');

    ban_check_or_die($author);

    $reg = null;
    $is_admin_post = false;
    if ($author && $pin) {
        // Try admin verify first for announcements
        $reg_admin = verify_pin($author, $pin, true);
        if ($reg_admin) {
            $reg = $reg_admin;
            $is_admin_post = true;
            $_SESSION['forum_admin'] = true;
            $_SESSION['forum_admin_name'] = $reg['name'];
        } else {
            $reg = verify_pin($author, $pin, false);
        }
    }

    if ($act === 'new_thread' && $author && isset($CATS[$_POST['cat']])) {
        $title = trim($_POST['title'] ?? '');
        $body  = trim($_POST['body']  ?? '');
        $post_cat = $_POST['cat'];

        if ($post_cat === 'announcements' && !$is_admin_post) {
            $error_msg = 'Posting to Announcements requires an admin PIN.';
        } elseif (!$reg && get_setting('require_registration','0') === '1') {
            $error_msg = 'You must be signed in to the registry to post. Enter your registry name and PIN.';
        } elseif ($title && $body) {
            $now = time();
            $db->prepare('INSERT INTO threads (category,title,author,reg_status,reg_location,created_at,last_at) VALUES(?,?,?,?,?,?,?)')
               ->execute([$post_cat, $title, $reg['name'],
                          $reg['status'], $reg['location'], $now, $now]);
            $new_tid = $db->lastInsertId();
            $db->prepare('INSERT INTO posts (thread_id,author,body,reg_status,reg_location,created_at) VALUES(?,?,?,?,?,?)')
               ->execute([$new_tid, $reg['name'], $body,
                          $reg['status'], $reg['location'], $now]);
            header('Location: /forum/?view=thread&thread=' . $new_tid);
            exit;
        }
    }

    if ($act === 'reply' && $tid && $author) {
        $body = trim($_POST['body'] ?? '');
        if (!$reg && get_setting('require_registration','0') === '1') {
            $error_msg = 'You must be signed in to the registry to post. Enter your registry name and PIN.';
        } elseif ($body) {
            $now = time();
            $db->prepare('INSERT INTO posts (thread_id,author,body,reg_status,reg_location,created_at) VALUES(?,?,?,?,?,?)')
               ->execute([$tid, $reg['name'], $body,
                          $reg['status'], $reg['location'], $now]);
            $db->prepare('UPDATE threads SET reply_count=reply_count+1, last_at=? WHERE id=?')->execute([$now, $tid]);
            header('Location: /forum/?view=thread&thread=' . $tid . '#bottom');
            exit;
        }
    }
}

$error_msg = $error_msg ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Forum — Noosphere</title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:system-ui,sans-serif; background:#0f0f1a; color:#e0e0e0; min-height:100vh; }
header { background:#1a1a2e; border-bottom:2px solid #e94560; padding:10px 16px; display:flex; align-items:center; gap:12px; }
header h1 { font-size:15px; color:#e94560; }
header a { color:#888; text-decoration:none; font-size:13px; }
header a:hover { color:#e94560; }
.spacer { flex:1; }
.container { max-width:800px; margin:0 auto; padding:20px 16px; }
.breadcrumb { font-size:12px; color:#666; margin-bottom:16px; }
.breadcrumb a { color:#4a9eff; text-decoration:none; }
h2 { font-size:16px; color:#e94560; margin-bottom:14px; }
.cat-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:24px; }
.cat-card { background:#1a1a2e; border:1px solid #2a2a4a; border-radius:8px; padding:16px; text-decoration:none; color:#e0e0e0; display:block; transition:0.15s; }
.cat-card:hover { border-color:#e94560; }
.cat-card .cat-icon { font-size:22px; margin-bottom:6px; }
.cat-card .cat-label { font-size:14px; font-weight:bold; margin-bottom:3px; }
.cat-card .cat-desc { font-size:12px; color:#888; }
.cat-card .cat-count { font-size:11px; color:#666; margin-top:6px; }
.thread-list { display:flex; flex-direction:column; gap:1px; background:#2a2a4a; border-radius:8px; overflow:hidden; margin-bottom:20px; }
.thread-row { background:#1a1a2e; padding:12px 16px; display:flex; align-items:center; gap:12px; text-decoration:none; color:#e0e0e0; transition:0.1s; }
.thread-row:hover { background:#16213e; }
.thread-row.pinned { border-left:3px solid #e94560; }
.thread-title { flex:1; font-size:14px; font-weight:bold; }
.thread-sub { font-size:12px; color:#888; margin-top:2px; }
.thread-meta { font-size:11px; color:#666; text-align:right; flex-shrink:0; }
.dot { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:3px; vertical-align:middle; }
.dot-ok { background:#2ecc71; }
.dot-help { background:#e94560; }
.dot-evac { background:#f8c000; }
.dot-unknown { background:#555; }
.post { background:#1a1a2e; border:1px solid #2a2a4a; border-radius:8px; padding:14px 16px; margin-bottom:10px; }
.post-header { font-size:12px; color:#888; margin-bottom:8px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.post-author { font-weight:bold; color:#e0e0e0; font-size:13px; }
.post-loc { color:#666; }
.post-body { font-size:14px; line-height:1.55; white-space:pre-wrap; word-break:break-word; }
.post.op { border-color:#e94560; }
.form-box { background:#1a1a2e; border:1px solid #2a2a4a; border-radius:8px; padding:16px; margin-top:20px; }
.form-box h3 { font-size:14px; color:#e94560; margin-bottom:14px; }
.form-row { display:flex; gap:10px; margin-bottom:10px; flex-wrap:wrap; }
.form-row label { font-size:12px; color:#888; display:block; margin-bottom:4px; }
.form-row > div { flex:1; min-width:140px; }
input[type=text], input[type=password], textarea, select {
    width:100%; padding:8px 10px; background:#16213e; border:1px solid #2a2a4a;
    border-radius:5px; color:#e0e0e0; font-size:14px; font-family:inherit;
}
input:focus, textarea:focus, select:focus { outline:none; border-color:#e94560; }
textarea { resize:vertical; min-height:80px; }
.pin-note { font-size:11px; color:#666; margin-top:4px; }
.btn { background:#e94560; color:#fff; border:none; padding:9px 20px; border-radius:5px; cursor:pointer; font-size:13px; font-weight:bold; margin-top:4px; }
.btn:hover { background:#c73652; }
.btn-sm { background:#16213e; border:1px solid #2a2a4a; color:#e0e0e0; padding:6px 14px; border-radius:5px; cursor:pointer; font-size:12px; text-decoration:none; display:inline-block; }
.btn-sm:hover { border-color:#e94560; color:#e94560; }
.btn-danger { background:none; border:1px solid #3a2a2a; color:#e94560; padding:4px 10px; border-radius:4px; font-size:11px; cursor:pointer; text-decoration:none; display:inline-block; }
.btn-danger:hover { background:#3a1a1a; }
.empty { color:#666; font-size:13px; padding:20px 0; text-align:center; }
.err-msg { background:#3a1a1a; border:1px solid #e94560; color:#e94560; padding:10px 14px; border-radius:6px; margin-bottom:14px; font-size:13px; }
@media(max-width:500px){ .cat-grid { grid-template-columns:1fr; } .form-row { flex-direction:column; } }
</style>
</head>
<body>
<header>
  <a href="/">← Home</a>
  <h1>Community Board</h1>
  <div class="spacer"></div>
  <?php if ($view !== 'home'): ?>
  <a href="/forum/">All Categories</a>
  <?php endif; ?>
</header>
<div class="container">

<?php if ($error_msg): ?><div class="err-msg"><?= esc($error_msg) ?></div><?php endif; ?>

<?php if ($view === 'home'): ?>
  <h2>Categories</h2>
  <div class="cat-grid">
  <?php foreach ($CATS as $key => $c):
    $count = $db->query("SELECT COUNT(*) FROM threads WHERE category='$key'")->fetchColumn(); ?>
    <a class="cat-card" href="/forum/?view=cat&cat=<?= $key ?>">
      <div class="cat-icon"><?= $c['icon'] ?></div>
      <div class="cat-label"><?= $c['label'] ?></div>
      <div class="cat-desc"><?= $c['desc'] ?></div>
      <div class="cat-count"><?= $count ?> thread<?= $count != 1 ? 's' : '' ?></div>
    </a>
  <?php endforeach; ?>
  </div>

  <h2>Recent Activity</h2>
  <?php
  $recent = $db->query('SELECT t.*, c.label cat_label FROM threads t LEFT JOIN (VALUES '.
    implode(',', array_map(fn($k,$v) => "('$k','{$v['label']}')", array_keys($CATS), $CATS)).
    ') AS c(key,label) ON t.category=c.key ORDER BY last_at DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
  ?>
  <div class="thread-list">
  <?php if (!$recent): ?><div class="empty" style="background:#1a1a2e;padding:20px">No posts yet. Be the first!</div>
  <?php else: foreach ($recent as $t): ?>
    <a class="thread-row<?= $t['pinned'] ? ' pinned' : '' ?>" href="/forum/?view=thread&thread=<?= $t['id'] ?>">
      <div style="flex:1">
        <div class="thread-title"><?= esc($t['title']) ?></div>
        <div class="thread-sub">
          <?php if ($t['reg_status']): ?><span class="dot <?= dotClass($t['reg_status']) ?>"></span><?php endif; ?>
          <?= esc($t['author']) ?> · <?= esc($t['cat_label'] ?? $t['category']) ?>
        </div>
      </div>
      <div class="thread-meta"><?= ago($t['last_at']) ?><br><?= $t['reply_count'] ?> repl<?= $t['reply_count'] != 1 ? 'ies' : 'y' ?></div>
    </a>
  <?php endforeach; endif; ?>
  </div>

<?php elseif ($view === 'cat' && isset($CATS[$cat])): ?>
  <div class="breadcrumb"><a href="/forum/">Forum</a> › <?= $CATS[$cat]['icon'] ?> <?= $CATS[$cat]['label'] ?></div>
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
    <h2><?= $CATS[$cat]['label'] ?></h2>
    <a class="btn-sm" href="/forum/?view=new&cat=<?= $cat ?>">+ New Thread</a>
  </div>
  <?php
  $threads = $db->prepare('SELECT * FROM threads WHERE category=? ORDER BY pinned DESC, last_at DESC');
  $threads->execute([$cat]);
  $threads = $threads->fetchAll(PDO::FETCH_ASSOC);
  ?>
  <div class="thread-list">
  <?php if (!$threads): ?><div class="empty" style="background:#1a1a2e;padding:20px">No threads yet.</div>
  <?php else: foreach ($threads as $t): ?>
    <a class="thread-row<?= $t['pinned'] ? ' pinned' : '' ?>" href="/forum/?view=thread&thread=<?= $t['id'] ?>">
      <div style="flex:1">
        <div class="thread-title"><?= esc($t['title']) ?></div>
        <div class="thread-sub">
          <?php if ($t['reg_status']): ?><span class="dot <?= dotClass($t['reg_status']) ?>"></span><?php endif; ?>
          <?= esc($t['author']) ?><?= $t['reg_location'] ? ' · ' . esc($t['reg_location']) : '' ?>
        </div>
      </div>
      <div class="thread-meta"><?= ago($t['last_at']) ?><br><?= $t['reply_count'] ?> repl<?= $t['reply_count'] != 1 ? 'ies' : 'y' ?></div>
    </a>
    <?php if (!empty($_SESSION['forum_admin'])): ?>
    <div style="background:#1a1a2e;padding:4px 16px 8px;text-align:right;">
      <a class="btn-danger" href="/forum/?del_thread=<?= $t['id'] ?>" onclick="return confirm('Delete thread and all its posts?')">Delete Thread</a>
    </div>
    <?php endif; ?>
  <?php endforeach; endif; ?>
  </div>

<?php elseif ($view === 'thread' && $tid): ?>
  <?php
  $thread = $db->prepare('SELECT * FROM threads WHERE id=?');
  $thread->execute([$tid]);
  $thread = $thread->fetch(PDO::FETCH_ASSOC);
  if (!$thread) { echo '<p>Thread not found.</p>'; exit; }
  $catinfo = $CATS[$thread['category']] ?? ['label' => $thread['category'], 'icon' => ''];
  $posts = $db->prepare('SELECT * FROM posts WHERE thread_id=? ORDER BY created_at ASC');
  $posts->execute([$tid]);
  $posts = $posts->fetchAll(PDO::FETCH_ASSOC);
  ?>
  <div class="breadcrumb">
    <a href="/forum/">Forum</a> › <a href="/forum/?view=cat&cat=<?= $thread['category'] ?>"><?= $catinfo['icon'] ?> <?= $catinfo['label'] ?></a> › <?= esc($thread['title']) ?>
  </div>
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
    <h2><?= esc($thread['title']) ?></h2>
    <?php if (!empty($_SESSION['forum_admin'])): ?>
      <a class="btn-danger" href="/forum/?del_thread=<?= $tid ?>" onclick="return confirm('Delete this thread and all its posts?')">Delete Thread</a>
    <?php endif; ?>
  </div>
  <?php foreach ($posts as $i => $p): ?>
  <div class="post<?= $i === 0 ? ' op' : '' ?>">
    <div class="post-header">
      <?php if ($p['reg_status']): ?><span class="dot <?= dotClass($p['reg_status']) ?>"></span><?php endif; ?>
      <span class="post-author"><?= esc($p['author']) ?></span>
      <?php if ($p['reg_location']): ?><span class="post-loc">· <?= esc($p['reg_location']) ?></span><?php endif; ?>
      <span>· <?= ago($p['created_at']) ?></span>
      <?php if ($i === 0): ?><span style="color:#e94560;font-size:11px">OP</span><?php endif; ?>
      <?php if (!empty($_SESSION['forum_admin']) && $i > 0): ?>
        <a class="btn-danger" href="/forum/?del_post=<?= $p['id'] ?>" onclick="return confirm('Delete this post?')">Delete</a>
      <?php endif; ?>
    </div>
    <div class="post-body"><?= esc($p['body']) ?></div>
  </div>
  <?php endforeach; ?>

  <div id="bottom"></div>
  <div class="form-box">
    <h3>Reply</h3>
    <form method="post">
      <input type="hidden" name="act" value="reply">
      <?= csrf_field() ?>
      <div class="form-row">
        <div><label>Registry name</label><input type="text" name="author" required maxlength="60" value="<?= esc($_SESSION['fname'] ?? '') ?>" placeholder="As entered in the Registry"></div>
        <div><label>Registry PIN <span class="pin-note">(required)</span></label><input type="password" name="pin" required placeholder="Your registry PIN"></div>
      </div>
      <div style="margin-bottom:10px"><label>Reply</label><textarea name="body" required maxlength="2000"></textarea></div>
      <button type="submit" class="btn">Post Reply</button>
    </form>
  </div>

<?php elseif ($view === 'new'): ?>
  <?php $c = $_GET['cat'] ?? 'general'; if (!isset($CATS[$c])) $c = 'general'; ?>
  <div class="breadcrumb"><a href="/forum/">Forum</a> › New Thread</div>
  <h2>New Thread</h2>
  <?php if ($c === 'announcements'): ?>
    <p style="font-size:13px;color:#f39c12;margin-bottom:14px;background:#2a200a;padding:10px 14px;border-radius:6px;border:1px solid #f39c12;">Announcements require an admin PIN.</p>
  <?php endif; ?>
  <div class="form-box">
    <form method="post">
      <input type="hidden" name="act" value="new_thread">
      <?= csrf_field() ?>
      <div class="form-row">
        <div><label>Registry name</label><input type="text" name="author" required maxlength="60" placeholder="As entered in the Registry"></div>
        <div><label>Registry PIN <?php if ($c === 'announcements'): ?><span class="pin-note">(admin PIN required)</span><?php else: ?><span class="pin-note">(required)</span><?php endif; ?></label><input type="password" name="pin" required placeholder="Your registry PIN"></div>
      </div>
      <div style="margin-bottom:10px">
        <label>Category</label>
        <select name="cat">
          <?php foreach ($CATS as $key => $cv): ?>
          <option value="<?= $key ?>"<?= $key === $c ? ' selected' : '' ?>><?= $cv['icon'] ?> <?= $cv['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="margin-bottom:10px"><label>Title</label><input type="text" name="title" required maxlength="120"></div>
      <div style="margin-bottom:10px"><label>Message</label><textarea name="body" required maxlength="4000" style="min-height:120px"></textarea></div>
      <button type="submit" class="btn">Post Thread</button>
    </form>
  </div>
<?php endif; ?>

</div>
</body>
</html>
