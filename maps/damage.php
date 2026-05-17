<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();
if (get_setting('show_maps','1') !== '1') { http_response_code(404); exit; }
if (get_setting('show_damage','0') !== '1') { echo json_encode([]); exit; }

header('Content-Type: application/json');

$db_path = '/var/lib/noosphere/damage.db';
if (!file_exists($db_path)) { echo json_encode([]); exit; }

$db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
$db->exec('PRAGMA journal_mode=WAL');

$result = $db->query(
    "SELECT id, address, structure_type, damage_level, hazards, reporter_name, submitted_at, lat, lng
     FROM reports WHERE lat IS NOT NULL AND lng IS NOT NULL
     ORDER BY submitted_at DESC"
);

$rows = [];
while ($r = $result->fetchArray(SQLITE3_ASSOC)) {
    $rows[] = [
        'id'             => (int)$r['id'],
        'address'        => $r['address'],
        'structure_type' => $r['structure_type'],
        'damage_level'   => $r['damage_level'],
        'hazards'        => $r['hazards'] ?? '',
        'reporter_name'  => $r['reporter_name'] ?? '',
        'submitted_at'   => (int)$r['submitted_at'],
        'lat'            => (float)$r['lat'],
        'lng'            => (float)$r['lng'],
    ];
}

echo json_encode($rows);
