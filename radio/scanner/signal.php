<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once '/var/www/noosphere/shared/settings.php';
if (get_setting('show_radio','1') !== '1') { http_response_code(404); exit; }

$meta_path = '/var/www/noosphere/radio/scanner/waterfall.json';
if (!file_exists($meta_path)) {
    echo json_encode(['ok'=>false]); exit;
}
$meta = json_decode(file_get_contents($meta_path), true);
if (!$meta) {
    echo json_encode(['ok'=>false]); exit;
}

$age = isset($meta['updated']) ? (time() - $meta['updated']) : 9999;
echo json_encode([
    'ok'      => true,
    'peak_db' => $meta['peak_db'] ?? null,
    'mean_db' => $meta['mean_db'] ?? null,
    'age_s'   => $age,
]);
