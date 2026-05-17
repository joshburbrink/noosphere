<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();
if (get_setting('show_damage','0') !== '1') { http_response_code(404); exit; }
if (!get_setting('readonly','0') !== '1' && empty($_SESSION['admin'])) {
    http_response_code(403); exit;
}

$db = new SQLite3('/var/lib/noosphere/damage.db', SQLITE3_OPEN_READONLY);
$result = $db->query("SELECT * FROM reports ORDER BY submitted_at DESC");
$rows = [];
while ($r = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
$db->close();

$fname = 'damage_reports_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv');
header("Content-Disposition: attachment; filename=\"$fname\"");

$out = fopen('php://output', 'w');
fputcsv($out, ['ID','Submitted','Address','Structure','Damage','Occupants Accounted','Occupant Count','Utilities Affected','Hazards','Reporter','Notes']);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['id'],
        date('Y-m-d H:i:s', $r['submitted_at']),
        $r['address'],
        $r['structure_type'],
        $r['damage_level'],
        $r['occupants_accounted'],
        $r['occupant_count'] ?? '',
        $r['utilities_affected'] ?? '',
        $r['hazards'] ?? '',
        $r['reporter_name'] ?? '',
        $r['notes'] ?? '',
    ]);
}
fclose($out);
