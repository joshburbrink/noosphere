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
    creator_token TEXT,
    photo         TEXT
)");
try { $db->exec("ALTER TABLE markers ADD COLUMN creator_token TEXT"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE markers ADD COLUMN photo TEXT"); } catch (Exception $e) {}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── List ──────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $rows = $db->query('SELECT id,lat,lng,title,note,mtype,created_by,created_at,photo FROM markers ORDER BY created_at ASC')
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
    $photo = null;

    // Handle optional photo upload
    if (!empty($_FILES['photo']['tmp_name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $photo = process_marker_photo($_FILES['photo']['tmp_name']);
    }

    $db->prepare('INSERT INTO markers (lat,lng,title,note,mtype,created_by,created_at,creator_token,photo) VALUES (?,?,?,?,?,?,?,?,?)')
       ->execute([$lat, $lng, $title, $note, $mtype, $by, time(), $token, $photo]);
    $id = (int)$db->lastInsertId();
    echo json_encode(['ok'=>true, 'id'=>$id, 'token'=>$token, 'photo'=>$photo]);
    exit;
}

// ── Delete ────────────────────────────────────────────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    sec_session_start();
    csrf_verify();

    $id    = (int)trim($_POST['id']    ?? 0);
    $token = trim($_POST['token']      ?? '');

    if (!$id) { echo json_encode(['ok'=>false, 'err'=>'Invalid ID']); exit; }

    $can_delete = !empty($_SESSION['admin']);
    if (!$can_delete && $token) {
        $row = $db->prepare('SELECT photo FROM markers WHERE id=? AND creator_token=?');
        $row->execute([$id, $token]);
        $can_delete = (bool)$row->fetch();
    }

    if (!$can_delete) {
        http_response_code(403);
        echo json_encode(['ok'=>false, 'err'=>'Admin login or creator token required']);
        exit;
    }

    // Delete photo file if it exists
    $row = $db->prepare('SELECT photo FROM markers WHERE id=?');
    $row->execute([$id]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if ($r && $r['photo']) {
        $path = '/var/lib/noosphere/marker_photos/' . basename($r['photo']);
        if (file_exists($path)) unlink($path);
    }

    $db->prepare('DELETE FROM markers WHERE id=?')->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok'=>false, 'err'=>'Unknown action']);

// ── Image processing ──────────────────────────────────────────────────────────
function process_marker_photo(string $tmp): ?string {
    $info = @getimagesize($tmp);
    if (!$info) return null;

    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($info['mime'], $allowed)) return null;

    $src = match($info['mime']) {
        'image/jpeg' => @imagecreatefromjpeg($tmp),
        'image/png'  => @imagecreatefrompng($tmp),
        'image/webp' => @imagecreatefromwebp($tmp),
        'image/gif'  => @imagecreatefromgif($tmp),
        default      => false,
    };
    if (!$src) return null;

    // Fix EXIF orientation before resizing
    if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif   = @exif_read_data($tmp);
        $orient = $exif['Orientation'] ?? 1;
        $src = match((int)$orient) {
            3 => imagerotate($src, 180, 0),
            6 => imagerotate($src, -90, 0),
            8 => imagerotate($src,  90, 0),
            default => $src,
        };
    }

    $w = imagesx($src);
    $h = imagesy($src);
    $max = 1200;

    if ($w > $max || $h > $max) {
        if ($w >= $h) { $nw = $max; $nh = (int)round($h * $max / $w); }
        else          { $nh = $max; $nw = (int)round($w * $max / $h); }
        $dst = imagecreatetruecolor($nw, $nh);
        // White background for transparent PNGs
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        $src = $dst;
    }

    $fname = bin2hex(random_bytes(8)) . '.jpg';
    $dest  = '/var/lib/noosphere/marker_photos/' . $fname;
    if (!imagejpeg($src, $dest, 82)) { imagedestroy($src); return null; }
    imagedestroy($src);
    return $fname;
}
