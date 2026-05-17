<?php
require_once __DIR__ . '/_init.php';
sec_session_start();
if (get_setting('show_incidents','0') !== '1') { http_response_code(404); exit; }

header('Content-Type: application/json');

$db = incidents_db();
$is_admin = !empty($_SESSION['admin']);
$is_command = incidents_command_mode();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── List ────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $where = []; $params = [];
    $status = $_GET['status'] ?? '';
    $type   = $_GET['type']   ?? '';
    $with_resolved = ($_GET['with_resolved'] ?? '') === '1';
    $only_pinned   = ($_GET['only_pinned']   ?? '') === '1';

    if ($status !== '' && isset(INCIDENT_STATUSES[$status])) {
        $where[] = 'status = ?'; $params[] = $status;
    } elseif (!$with_resolved) {
        $where[] = "status != 'resolved'";
    }
    if ($type !== '' && isset(INCIDENT_TYPES[$type])) {
        $where[] = 'type = ?'; $params[] = $type;
    }
    if ($only_pinned) {
        $where[] = 'lat IS NOT NULL AND lng IS NOT NULL';
    }

    $sql = "SELECT id, submitted_at, updated_at, type, severity, title, description,
                   location_text, lat, lng, reporter_name, photo_path,
                   status, assigned_to, resolved_at, resolved_by, meta
            FROM incidents";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY submitted_at DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id']           = (int)$r['id'];
        $r['submitted_at'] = (int)$r['submitted_at'];
        $r['updated_at']   = (int)$r['updated_at'];
        $r['lat']          = $r['lat'] !== null ? (float)$r['lat'] : null;
        $r['lng']          = $r['lng'] !== null ? (float)$r['lng'] : null;
        $r['resolved_at']  = $r['resolved_at'] !== null ? (int)$r['resolved_at'] : null;
        if ($r['meta']) {
            $m = json_decode($r['meta'], true);
            $r['meta'] = is_array($m) ? $m : null;
        }
    }
    echo json_encode($rows);
    exit;
}

// ── Add ─────────────────────────────────────────────────────────────────────
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    readonly_die();

    $type  = $_POST['type']  ?? 'general';
    if (!isset(INCIDENT_TYPES[$type])) $type = 'general';
    $title = trim($_POST['title'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $loc   = trim($_POST['location_text'] ?? '');
    $rep   = trim($_POST['reporter_name'] ?? '');
    $sev   = $_POST['severity'] ?? '';
    if (!isset(INCIDENT_SEVERITIES[$sev])) $sev = null;
    if (!$sev && incidents_command_mode()) $sev = 'minor';

    $lat_raw = trim($_POST['lat'] ?? '');
    $lng_raw = trim($_POST['lng'] ?? '');
    $lat = ($lat_raw !== '' && is_numeric($lat_raw) && abs((float)$lat_raw) <= 90)  ? (float)$lat_raw : null;
    $lng = ($lng_raw !== '' && is_numeric($lng_raw) && abs((float)$lng_raw) <= 180) ? (float)$lng_raw : null;

    if ($title === '') { http_response_code(400); echo json_encode(['ok'=>false,'err'=>'title required']); exit; }

    $photo = null;
    if (!empty($_FILES['photo']['tmp_name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK
        && is_uploaded_file($_FILES['photo']['tmp_name'])) {
        $photo = incident_process_photo($_FILES['photo']['tmp_name']);
    }

    $token = bin2hex(random_bytes(16));
    $now   = time();

    $meta = null;
    if ($type === 'damage') {
        $dm = incident_collect_damage_meta($_POST);
        if ($dm) $meta = json_encode($dm, JSON_UNESCAPED_SLASHES);
        if (!$sev && !empty($dm['damage_level'])) {
            $sev = damage_level_to_severity($dm['damage_level']);
        }
    }

    $stmt = $db->prepare("INSERT INTO incidents
        (submitted_at, updated_at, type, severity, title, description, location_text,
         lat, lng, reporter_name, creator_token, photo_path, status, meta)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $now, $now, $type, $sev, $title, $desc ?: null, $loc ?: null,
        $lat, $lng, $rep ?: null, $token, $photo, 'open', $meta,
    ]);
    $id = (int)$db->lastInsertId();
    echo json_encode(['ok'=>true, 'id'=>$id, 'token'=>$token, 'photo'=>$photo]);
    exit;
}

// ── Update (admin only) ─────────────────────────────────────────────────────
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    readonly_die();
    if (!$is_admin) { http_response_code(403); echo json_encode(['ok'=>false,'err'=>'admin required']); exit; }

    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['ok'=>false,'err'=>'id required']); exit; }

    $sets = []; $params = [];
    if (isset($_POST['status'])) {
        $s = $_POST['status'];
        if (!isset(INCIDENT_STATUSES[$s])) { http_response_code(400); echo json_encode(['ok'=>false,'err'=>'bad status']); exit; }
        $sets[] = 'status = ?'; $params[] = $s;
        if ($s === 'resolved') {
            $sets[] = 'resolved_at = ?'; $params[] = time();
            $sets[] = 'resolved_by = ?'; $params[] = $_SESSION['admin_name'] ?? 'admin';
        } else {
            $sets[] = 'resolved_at = NULL';
            $sets[] = 'resolved_by = NULL';
        }
    }
    if (isset($_POST['assigned_to'])) {
        $sets[] = 'assigned_to = ?'; $params[] = trim($_POST['assigned_to']) ?: null;
    }
    if (isset($_POST['severity'])) {
        $sv = $_POST['severity'];
        if ($sv === '') { $sets[] = 'severity = NULL'; }
        elseif (isset(INCIDENT_SEVERITIES[$sv])) { $sets[] = 'severity = ?'; $params[] = $sv; }
    }
    if (!$sets) { echo json_encode(['ok'=>false,'err'=>'no changes']); exit; }

    $sets[] = 'updated_at = ?'; $params[] = time();
    $params[] = $id;
    $sql = 'UPDATE incidents SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $db->prepare($sql)->execute($params);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── Delete (admin OR creator_token) ─────────────────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    readonly_die();

    $id    = (int)($_POST['id'] ?? 0);
    $token = trim($_POST['token'] ?? '');
    if (!$id) { http_response_code(400); echo json_encode(['ok'=>false,'err'=>'id required']); exit; }

    $can = $is_admin;
    if (!$can && $token !== '') {
        $row = $db->prepare('SELECT creator_token, photo_path FROM incidents WHERE id = ?');
        $row->execute([$id]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        $can = $r && hash_equals($r['creator_token'] ?? '', $token);
        $photo = $r['photo_path'] ?? null;
    } else {
        $row = $db->prepare('SELECT photo_path FROM incidents WHERE id = ?');
        $row->execute([$id]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        $photo = $r['photo_path'] ?? null;
    }
    if (!$can) { http_response_code(403); echo json_encode(['ok'=>false,'err'=>'admin or creator token required']); exit; }

    if ($photo) @unlink(INCIDENT_PHOTO_DIR . '/' . basename($photo));
    $db->prepare('DELETE FROM incidents WHERE id = ?')->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok'=>false, 'err'=>'unknown action']);
