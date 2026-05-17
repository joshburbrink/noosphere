<?php
// Returns signal level from latest HLS segment
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once '/var/www/noosphere/shared/settings.php';
if (get_setting('show_weather','1') !== '1') { http_response_code(404); exit; }

$m3u8 = '/var/www/noosphere/weather/stream/live.m3u8';
$age  = file_exists($m3u8) ? (time() - filemtime($m3u8)) : 9999;

$segs = glob('/var/www/noosphere/weather/stream/seg*.aac');
if (!$segs) {
    echo json_encode(['ok'=>false,'age_s'=>$age]); exit;
}
rsort($segs);
$latest = $segs[0];

$out = shell_exec('ffmpeg -i ' . escapeshellarg($latest) . ' -af volumedetect -f null /dev/null 2>&1');
preg_match('/mean_volume:\s*([-\d.]+)\s*dB/', $out ?? '', $mm);
preg_match('/max_volume:\s*([-\d.]+)\s*dB/', $out ?? '', $mx);

echo json_encode([
    'ok'      => true,
    'mean_db' => isset($mm[1]) ? (float)$mm[1] : null,
    'max_db'  => isset($mx[1]) ? (float)$mx[1] : null,
    'age_s'   => $age,
]);
