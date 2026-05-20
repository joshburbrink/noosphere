<?php
/*
 * /admin/downloads.php  -  optional content downloader (#82 Phase B).
 *
 * Browse cached Kiwix catalog, queue ZIM downloads, watch progress. Worker:
 * scripts/noosphere-download-worker.sh -> noosphere-download-worker.service.
 */
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/audit.php';
require_once '/var/www/noosphere/shared/settings.php';
require_once '/var/www/noosphere/shared/identity.php';
require_once '/var/www/noosphere/shared/capabilities.php';
require_once '/var/www/noosphere/shared/downloads.php';
sec_session_start();

if (!can('content.manage')) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:2rem;color:#e94560;background:#0f0f1a;min-height:100vh;margin:0">Admin login required.</p>');
}

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function fmt_size($b) {
    if ($b <= 0) return ' - ';
    if ($b < 1048576) return round($b/1024) . ' KB';
    if ($b < 1073741824) return round($b/1048576) . ' MB';
    return round($b/1073741824, 1) . ' GB';
}

$msg = ''; $err = '';

// JSON status endpoint (polled by progress UI).
if (isset($_GET['json']) && $_GET['json'] === 'status') {
    header('Content-Type: application/json');
    echo json_encode(['downloads' => dl_active(), 'now' => time()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'refresh_catalog') {
            $p = catalog_refresh();
            $msg = 'Catalog refreshed  -  ' . $p['count'] . ' ZIM entries cached.';
            log_audit('catalog_refresh', $p['count'] . ' entries', 'info');
        } elseif ($act === 'queue_zim') {
            $entry_id = $_POST['entry_id'] ?? '';
            $cat = catalog_load();
            if (!$cat) throw new RuntimeException('No catalog cache. Refresh while online first.');
            $hit = null;
            foreach ($cat['entries'] as $e) if ($e['id'] === $entry_id) { $hit = $e; break; }
            if (!$hit) throw new RuntimeException('Catalog entry not found.');
            $target = '/var/lib/kiwix/zim/' . $hit['filename'];
            if (file_exists($target)) throw new RuntimeException('Already installed: ' . $hit['filename']);
            $id = dl_queue('zim', $hit['title'], $hit['url'], $target, (int)$hit['size']);
            log_audit('queue_download', 'zim ' . $hit['filename'] . ' (' . fmt_size($hit['size']) . ')', 'info');
            $msg = 'Queued: ' . $hit['title'];
        } elseif ($act === 'cancel') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id && dl_cancel($id)) {
                log_audit('cancel_download', '#' . $id, 'warn');
                $msg = 'Cancelled #' . $id;
            }
        } elseif ($act === 'clear_finished') {
            $n = dl_clear_finished();
            $msg = "Cleared $n finished entries.";
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['_no_redirect'])) {
        $_SESSION['_dl_msg'] = $msg ?: null;
        $_SESSION['_dl_err'] = $err ?: null;
        header('Location: /admin/downloads.php' . (isset($_POST['back_qs']) ? '?' . $_POST['back_qs'] : ''));
        exit;
    }
}
$msg = $msg ?: ($_SESSION['_dl_msg'] ?? ''); unset($_SESSION['_dl_msg']);
$err = $err ?: ($_SESSION['_dl_err'] ?? ''); unset($_SESSION['_dl_err']);

$cat = catalog_load();
$q   = trim($_GET['q'] ?? '');
$lang = trim($_GET['lang'] ?? '');
$active_downloads = dl_active();
$recent_downloads = dl_list(20);

// Build filter facets from catalog.
$langs = [];
if ($cat) {
    foreach ($cat['entries'] as $e) if ($e['language']) $langs[$e['language']] = ($langs[$e['language']] ?? 0) + 1;
    arsort($langs);
}

// Apply filter.
$filtered = [];
if ($cat) {
    $qlc = strtolower($q);
    foreach ($cat['entries'] as $e) {
        if ($lang && $e['language'] !== $lang) continue;
        if ($q !== '') {
            $hay = strtolower($e['title'] . ' ' . $e['summary'] . ' ' . $e['filename']);
            if (strpos($hay, $qlc) === false) continue;
        }
        $filtered[] = $e;
    }
    // Cap rendering.
    $filtered = array_slice($filtered, 0, 200);
}

// Installed ZIMs (mark as already installed).
$installed = [];
foreach (glob('/var/lib/kiwix/zim/*.zim') ?: [] as $p) $installed[basename($p)] = true;
foreach (glob('/var/lib/kiwix/zim/disabled/*.zim') ?: [] as $p) $installed[basename($p)] = true;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Optional Downloads  -  Noosphere Admin</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/static/mobile.css">
<style>
body{font-family:system-ui,sans-serif;background:#0f0f1a;color:#e0e0e0;margin:0;padding:20px;max-width:1100px;margin:0 auto}
h1{font-size:22px;margin:0 0 6px}
.sub{color:#888;font-size:13px;margin-bottom:18px}
a{color:#4a9eff;text-decoration:none}
a:hover{text-decoration:underline}
.card{background:#1a1a2e;border:1px solid #2a2a4a;border-radius:8px;padding:16px;margin-bottom:18px}
.card h2{font-size:15px;margin:0 0 12px;color:#e0e0e0;font-weight:600}
.msg{padding:10px 14px;border-radius:6px;font-size:13px;margin-bottom:14px}
.msg.ok{background:#1a3a1a;border:1px solid #2a5a2a;color:#9ee99e}
.msg.err{background:#3a1a1a;border:1px solid #5a2a2a;color:#e9a0a0}
input[type=text],select{background:#0f0f1a;border:1px solid #2a2a4a;color:#e0e0e0;padding:7px 10px;border-radius:5px;font-size:13px}
.btn,button{background:#1a3a5a;border:1px solid #2a5a8a;color:#e0e0e0;padding:6px 14px;border-radius:5px;cursor:pointer;font-size:12px}
.btn-red{background:#3a1a2a;border-color:#5a2a3a;color:#e9a0a0}
.btn-green{background:#1a3a1a;border-color:#2a5a2a;color:#9ee99e}
.btn-sm{padding:4px 10px;font-size:11px}
table{width:100%;border-collapse:collapse;font-size:12px}
th{text-align:left;color:#666;font-weight:500;padding:6px 8px;border-bottom:1px solid #2a2a4a}
td{padding:7px 8px;border-bottom:1px solid #1a1a2e;vertical-align:top}
.progress{background:#0f0f1a;border:1px solid #2a2a4a;border-radius:4px;height:14px;overflow:hidden;position:relative;min-width:120px}
.progress > .bar{background:#2a5a8a;height:100%;transition:width 0.4s ease}
.progress > .pct{position:absolute;inset:0;text-align:center;font-size:10px;line-height:14px;color:#e0e0e0;text-shadow:0 0 3px #000}
.pill{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600}
.pill.queued{background:#2a2a4a;color:#aaa}
.pill.running{background:#1a3a5a;color:#9ec5e9}
.pill.done{background:#1a3a1a;color:#9ee99e}
.pill.failed{background:#3a1a1a;color:#e9a0a0}
.pill.cancelled{background:#2a2a2a;color:#888}
.online-dot{display:inline-block;width:8px;height:8px;border-radius:50%;vertical-align:middle;margin-right:6px}
.toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
.muted{color:#666;font-size:11px}
form.inline{display:inline;margin:0}
.title-cell{max-width:320px}
.title-cell .ttl{color:#e0e0e0;font-size:13px}
.title-cell .fn{color:#555;font-size:10px;margin-top:2px;font-family:monospace}
</style>
</head>
<body>

<div style="margin-bottom:14px"><a href="/admin/">&larr; Back to admin</a></div>
<h1>Optional Downloads</h1>
<div class="sub">Browse the Kiwix catalog and queue ZIM downloads. Works offline once cached.</div>

<?php if ($msg): ?><div class="msg ok"><?= esc($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="msg err"><?= esc($err) ?></div><?php endif; ?>

<!-- ACTIVE DOWNLOADS -->
<div class="card" id="active-card">
  <h2>Active downloads
    <span class="muted" id="active-count">(<?= count($active_downloads) ?>)</span>
  </h2>
  <div id="active-body">
    <?php if (!$active_downloads): ?>
      <div class="muted">Nothing in the queue.</div>
    <?php else: ?>
      <table>
        <thead><tr><th>Item</th><th>Status</th><th>Progress</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($active_downloads as $d):
          $pct = ($d['bytes_total'] > 0) ? min(100, round($d['bytes_done'] * 100 / $d['bytes_total'])) : 0;
        ?>
        <tr data-id="<?= (int)$d['id'] ?>">
          <td class="title-cell">
            <div class="ttl"><?= esc($d['label']) ?></div>
            <div class="fn"><?= esc(basename($d['target_path'])) ?></div>
          </td>
          <td><span class="pill <?= esc($d['status']) ?>"><?= esc($d['status']) ?></span></td>
          <td>
            <div class="progress"><div class="bar" style="width:<?= $pct ?>%"></div><div class="pct"><?= $pct ?>% &middot; <?= fmt_size($d['bytes_done']) ?> / <?= fmt_size($d['bytes_total']) ?></div></div>
          </td>
          <td>
            <form class="inline" method="post" onsubmit="return confirm('Cancel this download?')">
              <?= csrf_field() ?>
              <input type="hidden" name="act" value="cancel">
              <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <button class="btn-red btn-sm">Cancel</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<!-- CATALOG BROWSER -->
<div class="card">
  <h2>
    <?php $online = catalog_online_check(); ?>
    <span class="online-dot" style="background:<?= $online ? '#2ecc71' : '#888' ?>"></span>
    Kiwix catalog
    <?php if ($cat): ?>
      <span class="muted">- <?= number_format(count($cat['entries'])) ?> entries cached <?= date('Y-m-d', $cat['fetched_at']) ?></span>
    <?php else: ?>
      <span class="muted">- no cache yet</span>
    <?php endif; ?>
  </h2>

  <form method="post" class="inline" style="margin-bottom:12px">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="refresh_catalog">
    <button class="btn-green btn-sm" <?= $online ? '' : 'disabled title="No internet detected"' ?>>Refresh catalog (online)</button>
  </form>

  <?php if (!$cat): ?>
    <div class="muted">Catalog not yet downloaded. Connect to the internet and click <strong>Refresh catalog</strong> above. The cache persists offline so you can browse and queue downloads without reconnecting (downloads themselves still need internet).</div>
  <?php else: ?>
    <form method="get" class="toolbar">
      <input type="text" name="q" value="<?= esc($q) ?>" placeholder="search title / summary / filename" style="flex:1;min-width:200px">
      <select name="lang">
        <option value="">all languages</option>
        <?php foreach ($langs as $L => $n): ?>
          <option value="<?= esc($L) ?>" <?= $L === $lang ? 'selected' : '' ?>><?= esc($L) ?> (<?= $n ?>)</option>
        <?php endforeach; ?>
      </select>
      <button class="btn-sm">Filter</button>
      <?php if ($q || $lang): ?><a href="/admin/downloads.php" class="btn-sm" style="background:none;border:1px solid #333;color:#888;text-decoration:none">clear</a><?php endif; ?>
      <span class="muted">showing <?= count($filtered) ?> result(s)<?= count($filtered) >= 200 ? ' (capped at 200  -  narrow your search)' : '' ?></span>
    </form>

    <table>
      <thead><tr><th>Title</th><th>Lang</th><th>Size</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($filtered as $e):
        $is_installed = isset($installed[$e['filename']]);
      ?>
      <tr>
        <td class="title-cell">
          <div class="ttl"><?= esc($e['title']) ?></div>
          <div class="fn"><?= esc($e['filename']) ?></div>
          <?php if ($e['summary']): ?><div class="muted" style="margin-top:3px;max-width:480px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= esc($e['summary']) ?></div><?php endif; ?>
        </td>
        <td style="color:#888"><?= esc($e['language']) ?></td>
        <td><?= fmt_size($e['size']) ?></td>
        <td>
          <?php if ($is_installed): ?>
            <span class="pill done">installed</span>
          <?php else: ?>
            <form class="inline" method="post" onsubmit="return confirm('Queue download (<?= esc(fmt_size($e['size'])) ?>)?')">
              <?= csrf_field() ?>
              <input type="hidden" name="act" value="queue_zim">
              <input type="hidden" name="entry_id" value="<?= esc($e['id']) ?>">
              <input type="hidden" name="back_qs" value="<?= esc(http_build_query(['q'=>$q,'lang'=>$lang])) ?>">
              <button class="btn-sm">Queue</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- RECENT (finished) -->
<div class="card">
  <h2>Recent downloads
    <form method="post" class="inline" style="float:right" onsubmit="return confirm('Clear all finished entries?')">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="clear_finished">
      <button class="btn-sm" style="background:none;border:1px solid #333;color:#888">Clear finished</button>
    </form>
  </h2>
  <?php
    $finished = array_filter($recent_downloads, fn($r) => !in_array($r['status'], ['queued','running'], true));
  ?>
  <?php if (!$finished): ?>
    <div class="muted">No history yet.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Item</th><th>Status</th><th>Size</th><th>When</th></tr></thead>
      <tbody>
      <?php foreach ($finished as $d): ?>
      <tr>
        <td class="title-cell">
          <div class="ttl"><?= esc($d['label']) ?></div>
          <div class="fn"><?= esc(basename($d['target_path'])) ?></div>
        </td>
        <td><span class="pill <?= esc($d['status']) ?>"><?= esc($d['status']) ?></span>
          <?php if ($d['status'] === 'failed' && $d['error']): ?><div class="muted"><?= esc($d['error']) ?></div><?php endif; ?>
        </td>
        <td><?= fmt_size($d['bytes_done']) ?></td>
        <td class="muted"><?= $d['finished_at'] ? date('m/d H:i', $d['finished_at']) : ' - ' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<script>
function fmtBytes(b){if(!b)return' - ';if(b<1048576)return Math.round(b/1024)+' KB';if(b<1073741824)return Math.round(b/1048576)+' MB';return (b/1073741824).toFixed(1)+' GB'}
function pollStatus(){
  fetch('/admin/downloads.php?json=status').then(r=>r.json()).then(d=>{
    document.getElementById('active-count').textContent='('+d.downloads.length+')';
    var body=document.getElementById('active-body');
    if(!d.downloads.length){body.innerHTML='<div class="muted">Nothing in the queue.</div>';return}
    var rows=d.downloads.map(function(x){
      var pct=x.bytes_total>0?Math.min(100,Math.round(x.bytes_done*100/x.bytes_total)):0;
      return '<tr><td class="title-cell"><div class="ttl">'+escapeHtml(x.label)+'</div><div class="fn">'+escapeHtml(x.target_path.split('/').pop())+'</div></td>'+
             '<td><span class="pill '+x.status+'">'+x.status+'</span></td>'+
             '<td><div class="progress"><div class="bar" style="width:'+pct+'%"></div><div class="pct">'+pct+'% &middot; '+fmtBytes(x.bytes_done)+' / '+fmtBytes(x.bytes_total)+'</div></div></td>'+
             '<td></td></tr>';
    }).join('');
    body.innerHTML='<table><thead><tr><th>Item</th><th>Status</th><th>Progress</th><th></th></tr></thead><tbody>'+rows+'</tbody></table>';
  }).catch(function(){});
}
function escapeHtml(s){return (s||'').replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})}
setInterval(pollStatus,3000);
</script>

<script>
// #83: Scroll + open-details preservation
(function(){
  function captureState() {
    var openKeys = [];
    document.querySelectorAll('details[open]').forEach(function(d){
      var s = d.querySelector('summary');
      if (s) openKeys.push(s.textContent.replace(/[▾▸]/g,'').trim().split('\n')[0].trim());
    });
    try {
      sessionStorage.setItem('ns_scroll_y',    String(Math.round(window.scrollY)));
      sessionStorage.setItem('ns_open_details', JSON.stringify(openKeys));
    } catch(e){}
  }
  document.addEventListener('submit', captureState, true);
  function restoreState() {
    var scrollY, openKeys;
    try {
      scrollY  = sessionStorage.getItem('ns_scroll_y');
      openKeys = JSON.parse(sessionStorage.getItem('ns_open_details') || 'null');
      sessionStorage.removeItem('ns_scroll_y');
      sessionStorage.removeItem('ns_open_details');
    } catch(e){ return; }
    if (openKeys === null) return;
    document.querySelectorAll('details').forEach(function(d){
      var s = d.querySelector('summary');
      if (!s) return;
      var text = s.textContent.replace(/[▾▸]/g,'').trim().split('\n')[0].trim();
      if (openKeys.indexOf(text) !== -1) d.setAttribute('open','');
      else d.removeAttribute('open');
    });
    if (scrollY) window.scrollTo(0, parseInt(scrollY, 10));
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){ setTimeout(restoreState, 80); });
  } else {
    setTimeout(restoreState, 80);
  }
})();
</script>

</body>
</html>
