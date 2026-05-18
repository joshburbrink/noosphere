<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();
header('Content-Type: application/json');

if (get_setting('show_canvas','0') !== '1') { echo json_encode(['ok'=>false,'err'=>'Disabled']); exit; }
readonly_die();
csrf_verify();
rate_limit('canvas_save', 20, 300);

$title   = trim($_POST['title'] ?? '');
$dataurl = $_POST['dataurl'] ?? '';

if (!$title) $title = 'canvas-' . date('Ymd-His');
$title   = preg_replace('/[^a-zA-Z0-9._\- ]/', '_', $title);
if (!preg_match('/\.png$/i', $title)) $title .= '.png';

if (!preg_match('/^data:image\/png;base64,/', $dataurl)) {
    echo json_encode(['ok'=>false,'err'=>'Invalid image data']); exit;
}

$b64  = substr($dataurl, strpos($dataurl, ',') + 1);
$data = base64_decode($b64);
if ($data === false || strlen($data) < 8) {
    echo json_encode(['ok'=>false,'err'=>'Corrupt image data']); exit;
}
if (strlen($data) > 20 * 1024 * 1024) {
    echo json_encode(['ok'=>false,'err'=>'Image too large (max 20MB)']); exit;
}

// Verify PNG magic bytes
if (substr($data, 0, 8) !== "\x89PNG\r\n\x1a\n") {
    echo json_encode(['ok'=>false,'err'=>'Not a valid PNG']); exit;
}

$dir = '/var/lib/noosphere/files/';
if (!is_dir($dir)) mkdir($dir, 0755, true);

// Avoid overwrite
$base = pathinfo($title, PATHINFO_FILENAME);
$dest = $dir . $title;
$i    = 1;
while (file_exists($dest)) {
    $dest = $dir . $base . '-' . ($i++) . '.png';
}

file_put_contents($dest, $data);
echo json_encode(['ok'=>true,'filename'=>basename($dest)]);
