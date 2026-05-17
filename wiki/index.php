<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();
if (get_setting('show_wiki','1') !== '1') { http_response_code(404); exit; }

$is_admin    = !empty($_SESSION['admin']);
$is_readonly = is_readonly();

$CATEGORIES = [
    'roads'      => 'Roads & Access',
    'water'      => 'Water & Utilities',
    'people'     => 'People & Skills',
    'supplies'   => 'Supplies & Equipment',
    'structures' => 'Structures',
    'history'    => 'History & Context',
    'other'      => 'Other',
];

$db = new PDO('sqlite:/var/lib/noosphere/wiki.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("CREATE TABLE IF NOT EXISTS articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    slug       TEXT NOT NULL UNIQUE,
    title      TEXT NOT NULL,
    category   TEXT NOT NULL DEFAULT 'other',
    body       TEXT NOT NULL DEFAULT '',
    author     TEXT,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    locked     INTEGER NOT NULL DEFAULT 0
)");
$db->exec("CREATE TABLE IF NOT EXISTS revisions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL,
    body       TEXT NOT NULL,
    author     TEXT,
    edited_at  INTEGER NOT NULL
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_rev_article ON revisions(article_id, edited_at)");

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function make_slug($title) {
    $s = strtolower(trim($title));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'article';
}

function unique_slug($db, $base, $exclude_id = null) {
    $slug = $base;
    $n = 1;
    while (true) {
        $s = $db->prepare("SELECT id FROM articles WHERE slug=?");
        $s->execute([$slug]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row || ($exclude_id && (int)$row['id'] === $exclude_id)) return $slug;
        $slug = $base . '-' . (++$n);
    }
}

// ── Markdown renderer ────────────────────────────────────────────────────────

function wiki_inline($text) {
    // Bold **text**
    $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
    // Italic *text*
    $text = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $text);
    // Inline code `code`
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    // Wiki-style internal links [[Article Title]]
    $text = preg_replace_callback('/\[\[([^\]]+)\]\]/', function($m) {
        $title = $m[1];
        $slug = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return '<a href="/wiki/?slug=' . esc($slug) . '" class="wiki-link">' . esc($title) . '</a>';
    }, $text);
    return $text;
}

function render_markdown($raw) {
    // Escape all HTML first — no raw HTML from users
    $text = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');

    // Extract fenced code blocks before line processing
    $code_blocks = [];
    $text = preg_replace_callback('/```(?:[^\n]*)?\n(.*?)```/s', function($m) use (&$code_blocks) {
        $ph = "\x02CB" . count($code_blocks) . "\x03";
        $code_blocks[] = '<pre><code>' . $m[1] . '</code></pre>';
        return $ph;
    }, $text);

    $lines  = explode("\n", $text);
    $out    = '';
    $i      = 0;
    $total  = count($lines);

    while ($i < $total) {
        $line = $lines[$i];

        // Code block placeholder
        if (preg_match('/^\x02CB(\d+)\x03$/', $line, $m)) {
            $out .= $code_blocks[(int)$m[1]] . "\n";
            $i++; continue;
        }

        // Blank line
        if (trim($line) === '') { $i++; continue; }

        // Heading
        if (preg_match('/^(#{1,4})\s+(.+)$/', $line, $m)) {
            $level = min(strlen($m[1]) + 1, 5); // h2-h5 (h1 reserved for page title)
            $out .= "<h{$level}>" . wiki_inline($m[2]) . "</h{$level}>\n";
            $i++; continue;
        }

        // HR
        if (preg_match('/^---+$/', $line)) {
            $out .= "<hr>\n";
            $i++; continue;
        }

        // Blockquote — after htmlspecialchars, > became &gt;
        if (preg_match('/^&gt;\s?(.*)/', $line, $m)) {
            $bq = wiki_inline($m[1]);
            $i++;
            while ($i < $total && preg_match('/^&gt;\s?(.*)/', $lines[$i], $m2)) {
                $bq .= '<br>' . wiki_inline($m2[1]);
                $i++;
            }
            $out .= '<blockquote>' . $bq . '</blockquote>' . "\n";
            continue;
        }

        // Unordered list
        if (preg_match('/^[-*]\s+(.+)$/', $line, $m)) {
            $out .= '<ul>';
            while ($i < $total && preg_match('/^[-*]\s+(.+)$/', $lines[$i], $m2)) {
                $out .= '<li>' . wiki_inline($m2[1]) . '</li>';
                $i++;
            }
            $out .= '</ul>' . "\n";
            continue;
        }

        // Ordered list
        if (preg_match('/^\d+\.\s+(.+)$/', $line, $m)) {
            $out .= '<ol>';
            while ($i < $total && preg_match('/^\d+\.\s+(.+)$/', $lines[$i], $m2)) {
                $out .= '<li>' . wiki_inline($m2[1]) . '</li>';
                $i++;
            }
            $out .= '</ol>' . "\n";
            continue;
        }

        // Paragraph — collect until blank line or block element
        $para_lines = [];
        while ($i < $total) {
            $l = $lines[$i];
            if (trim($l) === '') break;
            if (preg_match('/^(#{1,4}\s|---+$|[-*]\s|\d+\.\s|&gt;|\x02CB)/', $l)) break;
            $para_lines[] = $l;
            $i++;
        }
        if ($para_lines) {
            $out .= '<p>' . wiki_inline(implode(' ', $para_lines)) . '</p>' . "\n";
        }
    }

    return $out;
}

// ── POST handler ─────────────────────────────────────────────────────────────

$msg = ''; $error = '';
$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_readonly) {
    csrf_verify();
    $act = $_POST['act'] ?? '';

    if ($act === 'save') {
        $title    = trim($_POST['title'] ?? '');
        $body     = $_POST['body'] ?? '';
        $category = $_POST['category'] ?? 'other';
        $author   = trim($_POST['author'] ?? '');
        $edit_id  = (int)($_POST['edit_id'] ?? 0);

        if (!isset($CATEGORIES[$category])) $category = 'other';

        if (!$title) {
            $error = 'Title is required.';
        } else {
            $now = time();
            if ($edit_id) {
                // Check lock
                $existing = $db->prepare("SELECT * FROM articles WHERE id=?");
                $existing->execute([$edit_id]);
                $existing = $existing->fetch(PDO::FETCH_ASSOC);
                if (!$existing) { $error = 'Article not found.'; }
                elseif ($existing['locked'] && !$is_admin) { $error = 'This article is locked. Only admins can edit it.'; }
                else {
                    // Save revision of old body
                    $db->prepare("INSERT INTO revisions (article_id,body,author,edited_at) VALUES (?,?,?,?)")
                       ->execute([$edit_id, $existing['body'], $existing['author'], $existing['updated_at']]);
                    $new_slug = unique_slug($db, make_slug($title), $edit_id);
                    $db->prepare("UPDATE articles SET title=?,slug=?,category=?,body=?,author=?,updated_at=? WHERE id=?")
                       ->execute([$title, $new_slug, $category, $body, $author ?: null, $now, $edit_id]);
                    $msg = 'Article updated.';
                    $action = 'view';
                    $_GET['slug'] = $new_slug;
                }
            } else {
                $base_slug = make_slug($title);
                $slug = unique_slug($db, $base_slug);
                $db->prepare("INSERT INTO articles (slug,title,category,body,author,created_at,updated_at,locked) VALUES (?,?,?,?,?,?,?,0)")
                   ->execute([$slug, $title, $category, $body, $author ?: null, $now, $now]);
                $msg = 'Article created.';
                $action = 'view';
                $_GET['slug'] = $slug;
            }
        }
    }

    if ($act === 'delete' && $is_admin) {
        $id = (int)($_POST['del_id'] ?? 0);
        if ($id) {
            $db->prepare("DELETE FROM revisions WHERE article_id=?")->execute([$id]);
            $db->prepare("DELETE FROM articles WHERE id=?")->execute([$id]);
            $msg = 'Article deleted.';
            $action = 'list';
        }
    }

    if ($act === 'lock' && $is_admin) {
        $id  = (int)($_POST['lock_id'] ?? 0);
        $val = (int)($_POST['lock_val'] ?? 0);
        if ($id) {
            $db->prepare("UPDATE articles SET locked=? WHERE id=?")->execute([$val, $id]);
            $msg = $val ? 'Article locked.' : 'Article unlocked.';
            $action = 'view';
            $row = $db->prepare("SELECT slug FROM articles WHERE id=?");
            $row->execute([$id]);
            $row = $row->fetch(PDO::FETCH_ASSOC);
            if ($row) $_GET['slug'] = $row['slug'];
        }
    }
}

// ── Data fetching ─────────────────────────────────────────────────────────────

$article = null;
$revisions = [];

if (in_array($action, ['view','edit','history'])) {
    $slug = trim($_GET['slug'] ?? '');
    if ($slug) {
        $s = $db->prepare("SELECT * FROM articles WHERE slug=?");
        $s->execute([$slug]);
        $article = $s->fetch(PDO::FETCH_ASSOC);
    }
    if (!$article && $action !== 'edit') { $action = 'list'; }
    if ($action === 'history' && $article) {
        $s = $db->prepare("SELECT * FROM revisions WHERE article_id=? ORDER BY edited_at DESC LIMIT 30");
        $s->execute([$article['id']]);
        $revisions = $s->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Search + list data
$search   = trim($_GET['q'] ?? '');
$cat_filter = trim($_GET['cat'] ?? '');
$page     = max(1, (int)($_GET['p'] ?? 1));
$per_page = 20;
$offset   = ($page - 1) * $per_page;

$where_parts = [];
$params = [];

if ($search) {
    $where_parts[] = "(title LIKE ? OR body LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($cat_filter && isset($CATEGORIES[$cat_filter])) {
    $where_parts[] = "category=?";
    $params[] = $cat_filter;
}
$where = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

$total_count = 0;
if ($action === 'list') {
    $s = $db->prepare("SELECT COUNT(*) FROM articles $where");
    $s->execute($params);
    $total_count = (int)$s->fetchColumn();
}

$articles = [];
if ($action === 'list') {
    $s = $db->prepare("SELECT id,slug,title,category,author,updated_at,locked FROM articles $where ORDER BY updated_at DESC LIMIT $per_page OFFSET $offset");
    $s->execute($params);
    $articles = $s->fetchAll(PDO::FETCH_ASSOC);
}

// Recent changes (sidebar + tab)
$recent = $db->query("SELECT a.slug,a.title,a.author,a.updated_at,a.category FROM articles a ORDER BY updated_at DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

// Category counts
$cat_counts = [];
foreach ($CATEGORIES as $k => $_) {
    $s = $db->prepare("SELECT COUNT(*) FROM articles WHERE category=?");
    $s->execute([$k]);
    $cat_counts[$k] = (int)$s->fetchColumn();
}
$total_articles = array_sum($cat_counts);

$name = get_setting('instance_name', 'Noosphere');

function time_ago($ts) {
    $d = time() - $ts;
    if ($d < 60)    return $d . 's ago';
    if ($d < 3600)  return floor($d/60) . 'm ago';
    if ($d < 86400) return floor($d/3600) . 'h ago';
    return date('m/d', $ts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Local Knowledge Wiki — <?= esc($name) ?></title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:sans-serif; background:#1a1a2e; color:#eee; min-height:100vh; }
a { color:#7ad; text-decoration:none; }
a:hover { color:#e94560; }

.topbar { display:flex; align-items:center; gap:1rem; padding:1rem 1.5rem; border-bottom:1px solid #2a2a4a; flex-wrap:wrap; }
.topbar h1 { font-size:1.3rem; color:#e94560; flex:1; min-width:200px; }
.topbar-links { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
a.back { color:#aaa; font-size:13px; }
a.back:hover { color:#e94560; }

.layout { display:grid; grid-template-columns:1fr 240px; gap:0; min-height:calc(100vh - 56px); }
.main   { padding:1.5rem; border-right:1px solid #2a2a4a; min-width:0; }
.sidebar { padding:1.25rem; background:#111126; }

@media (max-width:700px) {
    .layout { grid-template-columns:1fr; }
    .sidebar { border-top:1px solid #2a2a4a; }
}

.card { background:#16213e; border:1px solid #2a2a4a; border-radius:8px; padding:1.25rem; margin-bottom:1.25rem; }
.card h2 { font-size:1rem; color:#e94560; margin-bottom:1rem; }

.msg   { background:#1a3a1a; border:1px solid #2ecc71; color:#2ecc71; padding:8px 14px; border-radius:6px; margin-bottom:1rem; font-size:13px; }
.error { background:#3a0a0a; border:1px solid #e94560; color:#e94560; padding:8px 14px; border-radius:6px; margin-bottom:1rem; font-size:13px; }

.btn       { background:#e94560; color:#fff; border:none; border-radius:6px; padding:8px 18px; font-size:13px; cursor:pointer; display:inline-block; }
.btn:hover { background:#c73652; }
.btn-sm    { background:none; border:1px solid #555; color:#aaa; border-radius:4px; padding:4px 12px; font-size:12px; cursor:pointer; display:inline-block; }
.btn-sm:hover  { border-color:#e94560; color:#e94560; }
.btn-blue  { background:#1a5c8a; border:1px solid #2a7ab4; color:#7ad; }
.btn-blue:hover { background:#2a7ab4; color:#fff; }
.btn-ghost { background:none; border:1px solid #2a2a4a; color:#aaa; }
.btn-ghost:hover { border-color:#aaa; color:#eee; }

label { display:block; font-size:12px; color:#aaa; margin-bottom:3px; }
input[type=text], select, textarea {
    width:100%; background:#0f0f1a; border:1px solid #2a2a4a; border-radius:5px;
    color:#eee; padding:7px 10px; font-size:13px;
}
input[type=text]:focus, select:focus, textarea:focus {
    outline:none; border-color:#e94560;
}
textarea { resize:vertical; }
.form-row { margin-bottom:12px; }
.form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; }

/* Article list */
.article-list { list-style:none; }
.article-item { display:flex; align-items:baseline; gap:10px; padding:10px 0; border-bottom:1px solid #1a1a2e; }
.article-item:last-child { border-bottom:none; }
.article-title { font-size:14px; color:#eee; }
.article-title a { color:#eee; }
.article-title a:hover { color:#e94560; }
.article-meta { font-size:11px; color:#555; margin-top:2px; }
.cat-badge { display:inline-block; font-size:10px; border-radius:3px; padding:2px 7px; background:#1a2a3a; color:#7ad; border:1px solid #2a3a4a; }
.locked-badge { font-size:10px; color:#f39c12; border:1px solid #f39c12; border-radius:3px; padding:1px 6px; }

/* Article view */
.article-view-header { margin-bottom:1.5rem; }
.article-view-header h2 { font-size:1.6rem; color:#eee; margin-bottom:6px; }
.article-view-meta { font-size:12px; color:#555; display:flex; gap:1rem; flex-wrap:wrap; align-items:center; }
.article-body { line-height:1.7; font-size:14px; }
.article-body h2 { font-size:1.2rem; color:#e94560; margin:1.5rem 0 .5rem; border-bottom:1px solid #2a2a4a; padding-bottom:4px; }
.article-body h3 { font-size:1.05rem; color:#7ad; margin:1.2rem 0 .4rem; }
.article-body h4, .article-body h5 { font-size:.95rem; color:#aaa; margin:1rem 0 .3rem; }
.article-body p  { margin-bottom:.85rem; }
.article-body ul, .article-body ol { margin:0 0 .85rem 1.5rem; }
.article-body li { margin-bottom:.3rem; }
.article-body blockquote { border-left:3px solid #e94560; padding:.5rem 1rem; margin:.75rem 0; color:#aaa; background:#0f0f1a; border-radius:0 4px 4px 0; }
.article-body pre  { background:#0f0f1a; border:1px solid #2a2a4a; border-radius:6px; padding:1rem; overflow-x:auto; margin:.85rem 0; }
.article-body code { font-family:monospace; font-size:13px; background:#0f0f1a; padding:2px 5px; border-radius:3px; color:#7ad; }
.article-body pre code { background:none; padding:0; color:#ccc; }
.article-body hr { border:none; border-top:1px solid #2a2a4a; margin:1.25rem 0; }
.article-body strong { color:#eee; }
.wiki-link { color:#adb; text-decoration:underline dotted; }
.wiki-link:hover { color:#e94560; }

/* Search */
.search-bar { display:flex; gap:8px; margin-bottom:1rem; }
.search-bar input { flex:1; }

/* Filter bar */
.filter-bar { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:1rem; }
.filter-chip { font-size:11px; border:1px solid #2a2a4a; border-radius:20px; padding:3px 12px; cursor:pointer; background:#0f0f1a; color:#aaa; text-decoration:none; }
.filter-chip:hover, .filter-chip.active { border-color:#e94560; color:#e94560; }

/* Sidebar */
.sidebar h3 { font-size:12px; color:#555; text-transform:uppercase; letter-spacing:.08em; margin-bottom:.75rem; }
.sidebar-list { list-style:none; font-size:12px; }
.sidebar-list li { padding:5px 0; border-bottom:1px solid #1a1a2e; }
.sidebar-list li:last-child { border-bottom:none; }
.sidebar-list a { color:#aaa; }
.sidebar-list a:hover { color:#e94560; }
.sidebar-meta { font-size:10px; color:#444; }

/* Editor */
.editor-wrap { position:relative; }
textarea.md-editor { font-family:monospace; font-size:13px; min-height:360px; line-height:1.6; }
.md-hint { font-size:11px; color:#555; margin-top:6px; line-height:1.7; }

/* History */
.rev-list { list-style:none; }
.rev-item { padding:10px 0; border-bottom:1px solid #1a1a2e; font-size:13px; }
.rev-item:last-child { border-bottom:none; }

/* Print styles */
@media print {
    .topbar, .sidebar, .card .btn, .card .btn-sm, form { display:none !important; }
    .layout { grid-template-columns:1fr; }
    body { background:#fff; color:#000; }
    .article-body { font-size:12pt; }
}

.pagination { display:flex; gap:6px; justify-content:center; margin-top:1rem; flex-wrap:wrap; }
.page-btn { font-size:12px; border:1px solid #2a2a4a; border-radius:4px; padding:4px 12px; cursor:pointer; background:#0f0f1a; color:#aaa; text-decoration:none; }
.page-btn.active, .page-btn:hover { border-color:#e94560; color:#e94560; }
</style>
</head>
<body>

<div class="topbar">
  <h1>📖 Local Knowledge Wiki</h1>
  <div class="topbar-links">
    <?php if ($action !== 'list'): ?>
    <a href="/wiki/" class="btn-sm">← All Articles</a>
    <?php endif; ?>
    <?php if ($action === 'list' && !$is_readonly): ?>
    <a href="/wiki/?action=new" class="btn">+ New Article</a>
    <?php endif; ?>
    <a href="/" class="back">← Home</a>
  </div>
</div>

<div class="layout">
<div class="main">

<?php if ($msg):   ?><div class="msg"><?=   esc($msg)   ?></div><?php endif ?>
<?php if ($error): ?><div class="error"><?= esc($error) ?></div><?php endif ?>

<?php
// ── LIST VIEW ─────────────────────────────────────────────────────────────────
if ($action === 'list'):
?>

<form method="get" action="/wiki/" class="search-bar">
  <input type="text" name="q" placeholder="Search articles…" value="<?= esc($search) ?>" autofocus>
  <?php if ($cat_filter): ?><input type="hidden" name="cat" value="<?= esc($cat_filter) ?>"><?php endif ?>
  <button type="submit" class="btn">Search</button>
  <?php if ($search): ?><a href="/wiki/<?= $cat_filter ? '?cat='.$cat_filter : '' ?>" class="btn-sm">Clear</a><?php endif ?>
</form>

<div class="filter-bar">
  <a href="/wiki/<?= $search ? '?q='.urlencode($search) : '' ?>" class="filter-chip<?= !$cat_filter?' active':'' ?>">All (<?= $total_articles ?>)</a>
  <?php foreach ($CATEGORIES as $k => $v): if (!$cat_counts[$k] && $cat_filter !== $k) continue; ?>
  <a href="/wiki/?cat=<?= $k ?><?= $search ? '&q='.urlencode($search) : '' ?>" class="filter-chip<?= $cat_filter===$k?' active':'' ?>"><?= esc($v) ?> (<?= $cat_counts[$k] ?>)</a>
  <?php endforeach; ?>
</div>

<?php if (!$articles): ?>
  <div style="text-align:center;color:#555;padding:3rem">
    <?= $search ? 'No articles match "'.esc($search).'".' : 'No articles yet.' ?>
    <?php if (!$is_readonly): ?><br><br><a href="/wiki/?action=new" class="btn">Write the first article</a><?php endif ?>
  </div>
<?php else: ?>
<ul class="article-list">
  <?php foreach ($articles as $a): ?>
  <li class="article-item">
    <div style="flex:1;min-width:0">
      <div class="article-title">
        <a href="/wiki/?slug=<?= esc($a['slug']) ?>"><?= esc($a['title']) ?></a>
        <?php if ($a['locked']): ?><span class="locked-badge">locked</span><?php endif ?>
      </div>
      <div class="article-meta">
        <span class="cat-badge"><?= esc($CATEGORIES[$a['category']] ?? $a['category']) ?></span>
        &nbsp;edited <?= time_ago($a['updated_at']) ?>
        <?php if ($a['author']): ?> by <?= esc($a['author']) ?><?php endif ?>
      </div>
    </div>
  </li>
  <?php endforeach; ?>
</ul>

<?php
  $total_pages = (int)ceil($total_count / $per_page);
  if ($total_pages > 1):
?>
<div class="pagination">
  <?php for ($pg=1; $pg<=$total_pages; $pg++): ?>
  <a href="/wiki/?p=<?= $pg ?><?= $cat_filter?'&cat='.$cat_filter:'' ?><?= $search?'&q='.urlencode($search):'' ?>"
     class="page-btn<?= $pg===$page?' active':'' ?>"><?= $pg ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php
// ── VIEW ARTICLE ──────────────────────────────────────────────────────────────
elseif ($action === 'view' && $article):
?>

<div class="article-view-header">
  <h2><?= esc($article['title']) ?>
    <?php if ($article['locked']): ?><span class="locked-badge" style="font-size:12px;vertical-align:middle">locked</span><?php endif ?>
  </h2>
  <div class="article-view-meta">
    <span class="cat-badge"><?= esc($CATEGORIES[$article['category']] ?? $article['category']) ?></span>
    <span>Created <?= date('m/d/Y', $article['created_at']) ?></span>
    <span>Edited <?= time_ago($article['updated_at']) ?>
      <?php if ($article['author']): ?> by <?= esc($article['author']) ?><?php endif ?>
    </span>
    <?php
      $s = $db->prepare("SELECT COUNT(*) FROM revisions WHERE article_id=?");
      $s->execute([$article['id']]);
      $rev_count = (int)$s->fetchColumn();
      if ($rev_count):
    ?><a href="/wiki/?action=history&slug=<?= esc($article['slug']) ?>" style="font-size:11px;color:#555"><?= $rev_count ?> revision<?= $rev_count>1?'s':'' ?></a><?php endif ?>
  </div>
</div>

<div class="article-body">
  <?= render_markdown($article['body']) ?>
</div>

<?php if (!$is_readonly && (!$article['locked'] || $is_admin)): ?>
<div style="margin-top:2rem;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
  <a href="/wiki/?action=edit&slug=<?= esc($article['slug']) ?>" class="btn-sm">✏ Edit</a>
  <?php if ($is_admin): ?>
  <form method="post" style="display:inline">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="lock">
    <input type="hidden" name="action" value="view">
    <input type="hidden" name="lock_id" value="<?= (int)$article['id'] ?>">
    <input type="hidden" name="lock_val" value="<?= $article['locked'] ? '0' : '1' ?>">
    <button type="submit" class="btn-sm"><?= $article['locked'] ? '🔓 Unlock' : '🔒 Lock' ?></button>
  </form>
  <form method="post" style="display:inline" onsubmit="return confirm('Delete this article and all its history?')">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="delete">
    <input type="hidden" name="del_id" value="<?= (int)$article['id'] ?>">
    <button type="submit" class="btn-sm" style="color:#e94560;border-color:#e94560">🗑 Delete</button>
  </form>
  <?php endif; ?>
  <a href="/wiki/?action=view&slug=<?= esc($article['slug']) ?>" onclick="window.print();return false" class="btn-sm">🖨 Print</a>
</div>
<?php endif; ?>

<?php
// ── EDIT / NEW ARTICLE ────────────────────────────────────────────────────────
elseif ($action === 'edit' || $action === 'new'):
  $form_blocked = '';
  if ($is_readonly) $form_blocked = 'This system is in read-only mode.';
  elseif ($article && $article['locked'] && !$is_admin) $form_blocked = 'This article is locked.';
  if ($form_blocked): ?>
    <div class="error"><?= esc($form_blocked) ?></div>
  <?php else:
?>

<div class="card">
  <h2><?= $article ? 'Edit: ' . esc($article['title']) : 'New Article' ?></h2>
  <form method="post" action="/wiki/?action=<?= $article ? 'edit&slug='.esc($article['slug']) : 'new' ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="save">
    <input type="hidden" name="action" value="<?= $article ? 'edit' : 'new' ?>">
    <?php if ($article): ?><input type="hidden" name="edit_id" value="<?= (int)$article['id'] ?>"><?php endif ?>

    <div class="form-grid" style="margin-bottom:12px">
      <div>
        <label>Title *</label>
        <input type="text" name="title" value="<?= esc($article['title'] ?? '') ?>" required maxlength="200" autofocus>
      </div>
      <div>
        <label>Category</label>
        <select name="category">
          <?php foreach ($CATEGORIES as $k => $v): ?>
          <option value="<?= $k ?>"<?= ($article['category'] ?? 'other') === $k ? ' selected' : '' ?>><?= esc($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Your name (optional)</label>
        <input type="text" name="author" value="<?= esc($article['author'] ?? '') ?>" maxlength="80" placeholder="Anonymous">
      </div>
    </div>

    <div class="form-row editor-wrap">
      <label>Article body (Markdown supported)</label>
      <textarea name="body" class="md-editor"><?= esc($article['body'] ?? '') ?></textarea>
      <div class="md-hint">
        <strong style="color:#aaa">Markdown:</strong>
        <code># Heading</code> &nbsp;
        <code>**bold**</code> &nbsp;
        <code>*italic*</code> &nbsp;
        <code>`code`</code> &nbsp;
        <code>- list item</code> &nbsp;
        <code>&gt; quote</code> &nbsp;
        <code>[[Article Name]]</code> to link another article
      </div>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:4px">
      <button type="submit" class="btn">💾 Save</button>
      <?php if ($article): ?>
      <a href="/wiki/?slug=<?= esc($article['slug']) ?>" class="btn-sm">Cancel</a>
      <?php else: ?>
      <a href="/wiki/" class="btn-sm">Cancel</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php
  endif; // form_blocked

// ── HISTORY ───────────────────────────────────────────────────────────────────
elseif ($action === 'history' && $article):
?>

<div style="margin-bottom:1rem">
  <h2 style="font-size:1.2rem;color:#e94560"><?= esc($article['title']) ?> — Edit History</h2>
  <div style="font-size:12px;color:#555;margin-top:4px">
    <a href="/wiki/?slug=<?= esc($article['slug']) ?>">← Back to article</a>
  </div>
</div>

<?php if (!$revisions): ?>
<div style="color:#555;font-size:13px">No edit history — this article has never been revised.</div>
<?php else: ?>
<ul class="rev-list">
  <?php foreach ($revisions as $rev): ?>
  <li class="rev-item">
    <span style="color:#aaa"><?= date('m/d/Y H:i', $rev['edited_at']) ?></span>
    <?php if ($rev['author']): ?> &mdash; <span style="color:#aaa"><?= esc($rev['author']) ?></span><?php endif ?>
    <details style="margin-top:6px">
      <summary style="font-size:11px;color:#555;cursor:pointer">View snapshot</summary>
      <div class="article-body" style="margin-top:.75rem;padding:1rem;background:#0f0f1a;border-radius:6px;border:1px solid #2a2a4a">
        <?= render_markdown($rev['body']) ?>
      </div>
    </details>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php endif; ?>

</div><!-- .main -->

<!-- ── SIDEBAR ───────────────────────────────────────────────────────────────── -->
<div class="sidebar">

  <?php if (!$is_readonly && $action !== 'edit' && $action !== 'new'): ?>
  <a href="/wiki/?action=new" class="btn" style="width:100%;text-align:center;margin-bottom:1.25rem;display:block">+ New Article</a>
  <?php endif; ?>

  <div style="margin-bottom:1.5rem">
    <h3>Categories</h3>
    <ul class="sidebar-list">
      <li><a href="/wiki/" style="<?= !$cat_filter?'color:#e94560':'' ?>">All Articles <span style="color:#444">(<?= $total_articles ?>)</span></a></li>
      <?php foreach ($CATEGORIES as $k => $v): ?>
      <li><a href="/wiki/?cat=<?= $k ?>" style="<?= $cat_filter===$k?'color:#e94560':'' ?>"><?= esc($v) ?> <span style="color:#444">(<?= $cat_counts[$k] ?>)</span></a></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div>
    <h3>Recently Edited</h3>
    <ul class="sidebar-list">
      <?php foreach ($recent as $r): ?>
      <li>
        <a href="/wiki/?slug=<?= esc($r['slug']) ?>"><?= esc($r['title']) ?></a>
        <div class="sidebar-meta"><?= esc($CATEGORIES[$r['category']] ?? $r['category']) ?> · <?= time_ago($r['updated_at']) ?></div>
      </li>
      <?php endforeach; ?>
      <?php if (!$recent): ?>
      <li style="color:#444">No articles yet</li>
      <?php endif; ?>
    </ul>
  </div>

</div><!-- .sidebar -->
</div><!-- .layout -->

</body>
</html>
