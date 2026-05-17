<?php
require_once __DIR__ . '/_init.php';
sec_session_start();
if (get_setting('show_incidents','0') !== '1') { http_response_code(404); exit; }

$db = incidents_db();
$rows = $db->query("SELECT submitted_at, type, severity, status, title, description, location_text, lat, lng,
                           reporter_name, assigned_to, resolved_at, resolved_by
                    FROM incidents ORDER BY submitted_at DESC")->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="incidents-' . date('Ymd-Hi') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['submitted_at','type','severity','status','title','description','location_text','lat','lng','reporter','assigned_to','resolved_at','resolved_by']);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['submitted_at'] ? date('Y-m-d H:i:s', $r['submitted_at']) : '',
        $r['type'], $r['severity'], $r['status'],
        $r['title'], $r['description'], $r['location_text'],
        $r['lat'], $r['lng'],
        $r['reporter_name'], $r['assigned_to'],
        $r['resolved_at'] ? date('Y-m-d H:i:s', $r['resolved_at']) : '',
        $r['resolved_by'],
    ]);
}
fclose($out);
