<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';

header('Content-Type: application/json');

$db = new PDO('sqlite:/var/lib/noosphere/map_markers.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("CREATE TABLE IF NOT EXISTS markers (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    lat         REAL    NOT NULL,
    lng         REAL    NOT NULL,
    title       TEXT    NOT NULL,
    note        TEXT,
    mtype       TEXT    NOT NULL DEFAULT 'pin',
    created_by  TEXT,
    created_at  INTEGER NOT NULL
)");

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── List ──────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $rows = $db->query('SELECT * FROM markers ORDER BY created_at ASC')->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows);
    exit;
}

// ── Add ───────────────────────────────────────────────────────────────────────
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    sec_session_start();
    csrf_verify();
    readonly_die();

    $lat   = (float)($_POST['lat']   ?? 0);
    $lng   = (float)($_POST['lng']   ?? 0);
    $title = trim($_POST['title']    ?? '');
    $note  = trim($_POST['note']     ?? '');
    $by    = trim($_POST['created_by'] ?? '');
    $valid_types = ['pin','search','camp','hazard','medical','resource','blocked'];
    $mtype = in_array($_POST['mtype'] ?? '', $valid_types) ? $_POST['mtype'] : 'pin';

    if (!$lat || !$lng || !$title) {
        echo json_encode(['ok'=>false, 'err'=>'Lat, lng, and title are required.']);
        exit;
    }

    $db->prepare('INSERT INTO markers (lat,lng,title,note,mtype,created_by,created_at) VALUES (?,?,?,?,?,?,?)')
       ->execute([$lat, $lng, $title, $note, $mtype, $by, time()]);
    echo json_encode(['ok'=>true, 'id'=>(int)$db->lastInsertId()]);
    exit;
}

// ── Delete ────────────────────────────────────────────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    sec_session_start();
    csrf_verify();
    if (empty($_SESSION['admin'])) {
        http_response_code(403);
        echo json_encode(['ok'=>false, 'err'=>'Admin login required to delete markers.']);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $db->prepare('DELETE FROM markers WHERE id=?')->execute([$id]);
        echo json_encode(['ok'=>true]);
    } else {
        echo json_encode(['ok'=>false, 'err'=>'Invalid ID.']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['ok'=>false, 'err'=>'Unknown action.']);
