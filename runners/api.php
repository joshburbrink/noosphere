<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once '/var/www/noosphere/shared/settings.php';
if (get_setting('show_runners','1') !== '1') { http_response_code(404); exit; }

$db = new SQLite3('/var/lib/noosphere/runners.db');
$db->exec("PRAGMA journal_mode=WAL");

$res = $db->query("SELECT id,name,destination,dest_lat,dest_lng,departed_at,expected_at,notes
                   FROM runners WHERE status='out' AND dest_lat IS NOT NULL ORDER BY departed_at ASC");
$rows = [];
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $rows[] = [
        'id'          => (int)$r['id'],
        'name'        => $r['name'],
        'destination' => $r['destination'],
        'lat'         => (float)$r['dest_lat'],
        'lng'         => (float)$r['dest_lng'],
        'departed_at' => (int)$r['departed_at'],
        'expected_at' => $r['expected_at'] ? (int)$r['expected_at'] : null,
        'notes'       => $r['notes'],
    ];
}
echo json_encode($rows);
