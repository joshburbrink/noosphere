<?php
require_once __DIR__ . '/_init.php';
sec_session_start();
alpr_require_access();

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$can_manage  = can('alpr.manage');
$is_readonly = is_readonly();

$db = alpr_db();
$msg = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_readonly) {
    csrf_verify();
    require_capability('alpr.manage');
    $act = $_POST['act'] ?? '';

    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $s = $db->prepare('SELECT image_path FROM sightings WHERE id=?');
            $s->execute([$id]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $db->prepare('DELETE FROM sightings WHERE id=?')->execute([$id]);
                if (!empty($row['image_path'])) {
                    @unlink(ALPR_IMG_DIR . '/' . basename($row['image_path']));
                }
                $msg = 'Sighting deleted.';
            }
        }
    }
}

// ── Filters ──────────────────────────────────────────────────────────────────
$show_all = isset($_GET['all']);
$since    = $show_all ? 0 : (time() - 7 * 86400);
$q        = trim($_GET['q'] ?? '');

$sort_cols = ['ts' => 'ts', 'plate' => 'plate_text', 'reads' => 'reads_considered'];
$sort = $sort_cols[$_GET['sort'] ?? ''] ?? 'ts';
$dir  = (($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';

$sql = "SELECT id, ts, plate_text, reads_considered, image_path FROM sightings WHERE ts >= :since";
$params = [':since' => $since];
if ($q !== '') {
    $sql .= " AND plate_text LIKE :q";
    $params[':q'] = '%' . $q . '%';
}
$sql .= " ORDER BY $sort $dir";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$name       = get_setting('instance_name', 'Noosphere');
$site_label = 'Vehicle Log';

// Toggle-link helper: keep current query string, flip one param.
function sort_link($col, $sort, $dir) {
    $next_dir = ($sort === $col && $dir === 'ASC') ? 'desc' : 'asc';
    $params = $_GET;
    $params['sort'] = $col;
    $params['dir']  = $next_dir;
    return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= esc($site_label) ?>  -  <?= esc($name) ?></title>
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
.btn { background:#e94560; color:#fff; border:none; border-radius:6px; padding:8px 20px; font-size:13px; cursor:pointer; text-decoration:none; display:inline-block; }
.btn:hover { background:#c73652; }
.btn-sm { background:none; border:1px solid #555; color:#aaa; border-radius:4px; padding:3px 10px; font-size:11px; cursor:pointer; }
.btn-sm:hover { border-color:#e94560; color:#e94560; }
table { width:100%; border-collapse:collapse; font-size:13px; }
th { text-align:left; color:#aaa; font-weight:normal; padding:6px 8px; border-bottom:1px solid #2a2a4a; white-space:nowrap; }
th a { color:#aaa; text-decoration:none; }
th a:hover { color:#e94560; }
td { padding:7px 8px; border-bottom:1px solid #1a1a2e; vertical-align:top; }
tr:hover td { background:#1a1f35; }
.plate { font-family:monospace; font-size:14px; font-weight:bold; color:#7af; letter-spacing:1px; }
.thumb { width:80px; height:auto; border-radius:4px; border:1px solid #2a2a4a; display:block; }
.confidence { font-size:12px; color:#aaa; }
.no-data { text-align:center; color:#555; padding:2rem; }
.filter-bar { display:flex; gap:10px; align-items:center; margin-bottom:1rem; flex-wrap:wrap; }
.filter-bar input { flex:1; min-width:160px; background:#0f0f1a; border:1px solid #2a2a4a; border-radius:5px; color:#eee; padding:7px 10px; font-size:13px; }
.filter-bar a { font-size:12px; color:#7ad; white-space:nowrap; text-decoration:none; }
</style>
</head>
<body>
<div class="topbar">
  <h1>🚗 <?= esc($site_label) ?></h1>
  <a class="back" href="/">← Home</a>
  <a href="/vehicle-log/export.php?<?= esc(http_build_query(['q'=>$q,'all'=>$show_all?1:null])) ?>" style="font-size:13px;color:#2ecc71;text-decoration:none;border:1px solid #2a2a4a;border-radius:4px;padding:4px 10px;">⬇ Export CSV</a>
</div>

<div style="font-size:12px;color:#888;margin-bottom:1rem">
  Read from the ALPR capture daemon's log. Detections are automated (Haar cascade + OCR consensus)  -  plate text may
  be imperfect or missing. Reads considered is a rough confidence indicator only.
</div>

<?php if ($msg):   ?><div class="msg"><?=   esc($msg)   ?></div><?php endif ?>
<?php if ($error): ?><div class="error"><?= esc($error) ?></div><?php endif ?>

<div class="card">
  <h2>Sightings <?= $show_all ? '(all time)' : '(last 7 days)' ?></h2>

  <form class="filter-bar" method="get">
    <?php if ($show_all): ?><input type="hidden" name="all" value="1"><?php endif ?>
    <input type="text" name="q" value="<?= esc($q) ?>" placeholder="Filter by plate text...">
    <button type="submit" class="btn-sm">Filter</button>
    <a href="?<?= $show_all ? '' : 'all=1' ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">
      <?= $show_all ? 'Last 7 days' : 'Show all' ?>
    </a>
  </form>

  <?php if (!$rows): ?>
    <div class="no-data">No sightings logged<?= $q !== '' ? ' matching that filter' : '' ?>.</div>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table>
    <thead>
      <tr>
        <th></th>
        <th><a href="<?= esc(sort_link('ts', $sort, $dir)) ?>">Time <?= $sort==='ts' ? ($dir==='ASC'?'▲':'▼') : '' ?></a></th>
        <th><a href="<?= esc(sort_link('plate', $sort, $dir)) ?>">Plate <?= $sort==='plate_text' ? ($dir==='ASC'?'▲':'▼') : '' ?></a></th>
        <th><a href="<?= esc(sort_link('reads', $sort, $dir)) ?>">Reads <?= $sort==='reads_considered' ? ($dir==='ASC'?'▲':'▼') : '' ?></a></th>
        <?php if ($can_manage): ?><th></th><?php endif ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td>
          <?php if (!empty($r['image_path'])): ?>
            <a href="/vehicle-log/image.php?id=<?= (int)$r['id'] ?>&full=1" target="_blank">
              <img class="thumb" src="/vehicle-log/image.php?id=<?= (int)$r['id'] ?>" alt="plate crop" loading="lazy">
            </a>
          <?php endif ?>
        </td>
        <td style="white-space:nowrap;color:#aaa"><?= esc(date('m/d H:i:s', (int)$r['ts'])) ?></td>
        <td><span class="plate"><?= esc($r['plate_text'] ?: '(unreadable)') ?></span></td>
        <td class="confidence"><?= (int)$r['reads_considered'] ?></td>
        <?php if ($can_manage): ?>
        <td>
          <form method="post" onsubmit="return confirm('Delete this sighting and its image?')">
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
</body>
</html>
