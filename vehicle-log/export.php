<?php
require_once __DIR__ . '/_init.php';
sec_session_start();
alpr_require_access();

$q = trim($_GET['q'] ?? '');
$show_all = isset($_GET['all']);
$since = $show_all ? 0 : (time() - 7 * 86400);

$db = alpr_db();
$sql = "SELECT ts, plate_text, reads_considered, image_path FROM sightings WHERE ts >= :since";
$params = [':since' => $since];
if ($q !== '') {
    $sql .= " AND plate_text LIKE :q";
    $params[':q'] = '%' . $q . '%';
}
$sql .= " ORDER BY ts DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="vehicle-log-' . date('Ymd-Hi') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['timestamp', 'plate_text', 'reads_considered', 'image_path']);
foreach ($rows as $r) {
    fputcsv($out, [
        date('Y-m-d H:i:s', (int)$r['ts']),
        $r['plate_text'],
        $r['reads_considered'],
        $r['image_path'],
    ]);
}
fclose($out);
