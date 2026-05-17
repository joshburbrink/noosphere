<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();
if (get_setting('show_maps','1') !== '1') { http_response_code(404); exit; }

header('Content-Type: application/json');

$db_path = '/var/lib/noosphere/aprs.db';
if (!file_exists($db_path)) { echo json_encode([]); exit; }

$expiry_hours = max(1, (int)(get_setting('aprs_expiry_hours','2')));
$cutoff       = time() - ($expiry_hours * 3600);

$db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
$db->exec('PRAGMA journal_mode=WAL');

$db->exec("CREATE TABLE IF NOT EXISTS aprs_stations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    callsign    TEXT NOT NULL,
    lat         REAL NOT NULL,
    lng         REAL NOT NULL,
    symbol      TEXT,
    comment     TEXT,
    last_heard  INTEGER NOT NULL
)");

$result = $db->query(
    "SELECT callsign, lat, lng, symbol, comment, last_heard
     FROM aprs_stations
     WHERE last_heard >= $cutoff
     ORDER BY last_heard DESC"
);

$rows = [];
while ($r = $result->fetchArray(SQLITE3_ASSOC)) {
    $rows[] = [
        'callsign'   => $r['callsign'],
        'lat'        => (float)$r['lat'],
        'lng'        => (float)$r['lng'],
        'symbol'     => $r['symbol'] ?? '',
        'comment'    => $r['comment'] ?? '',
        'last_heard' => (int)$r['last_heard'],
    ];
}

echo json_encode($rows);
