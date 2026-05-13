<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';

header('Content-Type: application/json');

$db = new PDO('sqlite:/var/lib/noosphere/map_markers.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("CREATE TABLE IF NOT EXISTS markers (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    lat           REAL    NOT NULL,
    lng           REAL    NOT NULL,
    title         TEXT    NOT NULL,
    note          TEXT,
    mtype         TEXT    NOT NULL DEFAULT 'pin',
    created_by    TEXT,
    created_at    INTEGER NOT NULL,
    creator_token TEXT
)");
// migrate existing tables that lack creator_token
try { $db->exec("ALTER TABLE markers ADD COLUMN creator_token TEXT"); } catch (Exception $e) {}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── List ──────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $rows = $db->query('SELECT id,lat,lng,title,note,mtype,created_by,created_at FROM markers ORDER BY created_at ASC')
               ->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows);
    exit;
}

// ── Add ───────────────────────────────────────────────────────────────────────
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    sec_session_start();
    csrf_verify();
    readonly_die();

    $lat   = (float)($_POST['lat']       ?? 0);
    $lng   = (float)($_POST['lng']       ?? 0);
    $title = trim($_POST['title']        ?? '');
    $note  = trim($_POST['note']         ?? '');
    $by    = trim($_POST['created_by']   ?? '');
    $valid = ['pin','search','camp','hazard','medical','resource','blocked'];
    $mtype = in_array($_POST['mtype'] ?? '', $valid) ? $_POST['mtype'] : 'pin';

    if (!$lat || !$lng || !$title) {
        echo json_encode(['ok'=>false, 'err'=>'lat, lng, and title required']);
        exit;
    }

    $token = bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO markers (lat,lng,title,note,mtype,created_by,created_at,creator_token) VALUES (?,?,?,?,?,?,?,?)')
       ->execute([$lat, $lng, $title, $note, $mtype, $by, time(), $token]);
    echo json_encode(['ok'=>true, 'id'=>(int)$db->lastInsertId(), 'token'=>$token]);
    exit;
}

// ── Delete ────────────────────────────────────────────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    sec_session_start();
    csrf_verify();

    $id    = (int)trim($_POST['id']    ?? 0);
    $token = trim($_POST['token']      ?? '');

    if (!$id) { echo json_encode(['ok'=>false, 'err'=>'Invalid ID']); exit; }

    if (!empty($_SESSION['admin'])) {
        $db->prepare('DELETE FROM markers WHERE id=?')->execute([$id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($token) {
        $row = $db->prepare('SELECT id FROM markers WHERE id=? AND creator_token=?');
        $row->execute([$id, $token]);
        if ($row->fetch()) {
            $db->prepare('DELETE FROM markers WHERE id=?')->execute([$id]);
            echo json_encode(['ok'=>true]);
        } else {
            http_response_code(403);
            echo json_encode(['ok'=>false, 'err'=>'Token mismatch']);
        }
        exit;
    }

    http_response_code(403);
    echo json_encode(['ok'=>false, 'err'=>'Admin login or creator token required']);
    exit;
}

http_response_code(400);
echo json_encode(['ok'=>false, 'err'=>'Unknown action']);
