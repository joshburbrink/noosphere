<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();
if (get_setting('show_files','1') !== '1') { http_response_code(404); exit; }

$upload_dir = '/var/lib/noosphere/files/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

$error = '';
$success = '';
$MAX = 100 * 1024 * 1024; // 100MB

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    readonly_die();

    // Upload and delete require admin login
    if (empty($_SESSION['admin'])) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'err'=>'Admin login required.']);
        exit;
    }

    // Handle delete via POST
    if (isset($_POST['delete'])) {
        $del_name = $_POST['delete'];
        if (preg_match('/^[a-zA-Z0-9._\- ]+$/', $del_name)) {
            $target = $upload_dir . $del_name;
            $real_target = realpath($target);
            $real_dir    = realpath($upload_dir);
            if ($real_target && $real_dir && strpos($real_target, $real_dir) === 0 && file_exists($real_target)) {
                unlink($real_target);
            }
        }
        header('Location: /files/');
        exit;
    }

    // Handle file upload
    if (isset($_FILES['file'])) {
        rate_limit('upload', 10, 300);
        $f = $_FILES['file'];
        if ($f['error'] === UPLOAD_ERR_OK) {
            if ($f['size'] > $MAX) {
                $error = 'File too large (max 100MB).';
            } elseif (!check_mime_safe($f['tmp_name'])) {
                $error = 'File type not allowed.';
            } else {
                $name = preg_replace('/[^a-zA-Z0-9._\- ]/', '_', $f['name']);
                $name = preg_replace('/_+/', '_', $name);
                $dest = $upload_dir . $name;
                if (file_exists($dest)) {
                    $info = pathinfo($name);
                    $name = $info['filename'] . '_' . date('His') . '.' . ($info['extension'] ?? 'bin');
                    $dest = $upload_dir . $name;
                }
                if (move_uploaded_file($f['tmp_name'], $dest)) {
                    $success = 'Uploaded: ' . htmlspecialchars($name);
                } else {
                    $error = 'Upload failed -- check server permissions.';
                }
            }
        } elseif ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
            $error = 'File too large.';
        } else {
            $error = 'Upload error (code ' . $f['error'] . ').';
        }
    }
}

$files = glob($upload_dir . '*');
usort($files, fn($a,$b) => filemtime($b) - filemtime($a));

// External drive files
$ext_dir   = '/media/noosphere-ext/files/';
$ext_files = [];
if (get_setting('ext_drive_mounted','0') === '1' && is_dir($ext_dir)) {
    $ext_files = glob($ext_dir . '*') ?: [];
    usort($ext_files, fn($a,$b) => strcmp(basename($a), basename($b)));
}

function fmtSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes/1024, 1) . ' KB';
    return round($bytes/1048576, 1) . ' MB';
}
function esc($s) { return htmlspecialchars($s, ENT_QUOTES); }

// Inline CSRF token for delete forms
$csrf_token = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Files — Noosphere</title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:system-ui,sans-serif; background:#0f0f1a; color:#e0e0e0; min-height:100vh; }
header { background:#1a1a2e; border-bottom:2px solid #e94560; padding:10px 16px; display:flex; align-items:center; gap:12px; }
header h1 { font-size:15px; color:#e94560; }
header a { color:#888; text-decoration:none; font-size:13px; }
header a:hover { color:#e94560; }
.container { max-width:800px; margin:0 auto; padding:24px 16px; }
.upload-box { background:#1a1a2e; border:2px dashed #2a2a4a; border-radius:10px; padding:24px; text-align:center; margin-bottom:24px; transition:0.15s; }
.upload-box:hover { border-color:#e94560; }
.upload-box p { color:#888; font-size:13px; margin-bottom:12px; }
.upload-box input[type=file] { display:none; }
.upload-label { display:inline-block; background:#16213e; border:1px solid #2a2a4a; color:#e0e0e0; padding:8px 18px; border-radius:6px; cursor:pointer; font-size:13px; margin-bottom:10px; }
.upload-label:hover { border-color:#e94560; }
#file-name { font-size:13px; color:#aaa; margin-bottom:12px; min-height:18px; }
.btn { background:#e94560; color:#fff; border:none; padding:9px 22px; border-radius:6px; cursor:pointer; font-size:13px; font-weight:bold; }
.btn:hover { background:#c73652; }
.msg { padding:10px 14px; border-radius:6px; margin-bottom:16px; font-size:13px; }
.msg.ok  { background:#1a3a1a; border:1px solid #2ecc71; color:#2ecc71; }
.msg.err { background:#2a1a1a; border:1px solid #e94560; color:#e94560; }
h2 { font-size:15px; color:#e94560; margin-bottom:12px; }
.file-list { display:flex; flex-direction:column; gap:1px; background:#2a2a4a; border-radius:8px; overflow:hidden; }
.file-row { background:#1a1a2e; padding:12px 16px; display:flex; align-items:center; gap:12px; }
.file-row:hover { background:#16213e; }
.file-icon { font-size:20px; flex-shrink:0; }
.file-info { flex:1; min-width:0; }
.file-name { font-size:14px; font-weight:bold; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.file-meta { font-size:11px; color:#666; margin-top:2px; }
.file-actions { display:flex; gap:8px; flex-shrink:0; }
.dl-btn { background:#16213e; border:1px solid #2a2a4a; color:#4a9eff; padding:5px 14px; border-radius:5px; text-decoration:none; font-size:12px; }
.dl-btn:hover { border-color:#4a9eff; }
.del-btn { background:none; border:1px solid #3a2a2a; color:#666; padding:5px 10px; border-radius:5px; cursor:pointer; font-size:12px; }
.del-btn:hover { border-color:#e94560; color:#e94560; }
.empty { color:#666; font-size:13px; padding:24px; text-align:center; background:#1a1a2e; }
</style>
</head>
<body>
<header>
  <a href="/">&#x2190; Home</a>
  <h1>Shared Files</h1>
</header>
<div class="container">

  <?php if ($success): ?><div class="msg ok"><?= $success ?></div><?php endif; ?>
  <?php if ($error):   ?><div class="msg err"><?= esc($error) ?></div><?php endif; ?>

  <?php if (!empty($_SESSION['admin'])): ?>
  <div class="upload-box">
    <form method="post" enctype="multipart/form-data" id="upload-form">
      <?= csrf_field() ?>
      <p>Share documents, notices, or any file with everyone on the network.</p>
      <label class="upload-label" for="file-input">Choose File</label>
      <input type="file" id="file-input" name="file" onchange="showName(this)">
      <div id="file-name">No file chosen</div>
      <button type="submit" class="btn">Upload</button>
      <div style="font-size:11px;color:#555;margin-top:10px">Max 100MB per file</div>
    </form>
  </div>
  <?php else: ?>
  <div style="font-size:13px;color:#555;text-align:center;padding:16px 0 20px">Files are uploaded by administrators. Download any file below.</div>
  <?php endif; ?>

  <h2>Available Files (<?= count($files) + count($ext_files) ?>)</h2>
  <div class="file-list">
  <?php if (!$files && !$ext_files): ?>
    <div class="empty">No files available yet.</div>
  <?php endif; ?>
  <?php if ($files): foreach ($files as $fp):
    $fname = basename($fp);
    $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
    $icons = ['pdf'=>'&#x1F4C4;','jpg'=>'&#x1F5BC;','jpeg'=>'&#x1F5BC;','png'=>'&#x1F5BC;','gif'=>'&#x1F5BC;',
               'mp3'=>'&#x1F3B5;','mp4'=>'&#x1F3AC;','zip'=>'&#x1F5DC;','txt'=>'&#x1F4DD;','doc'=>'&#x1F4DD;','docx'=>'&#x1F4DD;',
               'xls'=>'&#x1F4CA;','xlsx'=>'&#x1F4CA;','csv'=>'&#x1F4CA;'];
    $icon = $icons[$ext] ?? '&#x1F4CE;';
    $mtime = filemtime($fp);
    $size = fmtSize(filesize($fp));
  ?>
    <div class="file-row">
      <div class="file-icon"><?= $icon ?></div>
      <div class="file-info">
        <div class="file-name"><?= esc($fname) ?></div>
        <div class="file-meta"><?= $size ?> / <?= date('M j, g:ia', $mtime) ?></div>
      </div>
      <div class="file-actions">
        <a class="dl-btn" href="/files/dl/<?= urlencode($fname) ?>" download>Download</a>
        <?php if (!empty($_SESSION['admin'])): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete <?= esc(addslashes($fname)) ?>?')">
          <?= csrf_field() ?>
          <input type="hidden" name="delete" value="<?= esc($fname) ?>">
          <button type="submit" class="del-btn">X</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; endif; ?>

  <?php if ($ext_files): ?>
  <div style="margin-top:16px">
    <h2 style="font-size:15px;color:#e94560;margin-bottom:10px">&#x1F4BE; External Drive <span style="font-size:11px;color:#555;font-weight:normal">(read-only)</span></h2>
    <div class="file-list">
    <?php foreach ($ext_files as $fp):
      $fname = basename($fp);
      $ext   = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
      $icons = ['pdf'=>'&#x1F4C4;','jpg'=>'&#x1F5BC;','jpeg'=>'&#x1F5BC;','png'=>'&#x1F5BC;','gif'=>'&#x1F5BC;',
                'mp3'=>'&#x1F3B5;','mp4'=>'&#x1F3AC;','zip'=>'&#x1F5DC;','txt'=>'&#x1F4DD;','doc'=>'&#x1F4DD;','docx'=>'&#x1F4DD;',
                'xls'=>'&#x1F4CA;','xlsx'=>'&#x1F4CA;','csv'=>'&#x1F4CA;'];
      $icon  = $icons[$ext] ?? '&#x1F4CE;';
      $size  = fmtSize(filesize($fp));
    ?>
      <div class="file-row" style="border-left:3px solid #2a2a4a">
        <div class="file-icon"><?= $icon ?></div>
        <div class="file-info">
          <div class="file-name"><?= esc($fname) ?></div>
          <div class="file-meta"><?= $size ?></div>
        </div>
        <div class="file-actions">
          <a class="dl-btn" href="/files/ext/<?= urlencode($fname) ?>" download>Download</a>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
  </div>
</div>
<script>
function showName(input) {
  document.getElementById('file-name').textContent = input.files[0] ? input.files[0].name : 'No file chosen';
}
</script>
</body>
</html>
