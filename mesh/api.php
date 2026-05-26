<?php
require_once __DIR__ . '/_init.php';
header('Content-Type: application/json');

if (!mesh_db_ready()) {
    echo json_encode(['ok'=>false,'error'=>'mesh daemon not yet running']); exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = mesh_db();

if ($action === 'recv') {
    $since   = (int)($_GET['since'] ?? 0);
    $channel = isset($_GET['channel']) ? (int)$_GET['channel'] : null;
    $limit   = max(1, min(200, (int)($_GET['limit'] ?? 80)));
    $sql = "SELECT id, direction, from_id, from_short, to_id, channel, body, rssi, snr, received_at
            FROM mesh_messages WHERE id > :since";
    $params = [':since' => $since];
    if ($channel !== null) { $sql .= " AND channel = :ch"; $params[':ch'] = $channel; }
    $sql .= " ORDER BY id ASC LIMIT " . $limit;
    $s = $db->prepare($sql);
    foreach ($params as $k=>$v) $s->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $s->execute();
    echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action === 'nodes') {
    $rows = $db->query("SELECT * FROM mesh_nodes ORDER BY is_self DESC, COALESCE(last_heard, updated_at) DESC")
               ->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows);
    exit;
}

if ($action === 'send') {
    // csrf_verify() die()s with HTML on failure, so do an inline JSON-friendly check.
    $tok = $_POST['_csrf'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $tok)) {
        echo json_encode(['ok'=>false,'error'=>'csrf']); exit;
    }
    if (!can('mesh.send'))  { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
    $body = trim($_POST['body'] ?? '');
    if ($body === '') { echo json_encode(['ok'=>false,'error'=>'empty']); exit; }
    if (mb_strlen($body) > 200) $body = mb_substr($body, 0, 200);
    $channel = (int)($_POST['channel'] ?? 0);
    $to_id   = trim($_POST['to_id'] ?? '') ?: null;
    $u = current_user();
    $queued_by = $u['name'] ?? ($_SESSION['admin'] ? 'admin' : 'anon');
    $s = $db->prepare(
        "INSERT INTO mesh_outbox (to_id, channel, body, queued_by, queued_at)
         VALUES (:to, :ch, :body, :by, :at)"
    );
    $s->execute([
        ':to' => $to_id, ':ch' => $channel, ':body' => $body,
        ':by' => $queued_by, ':at' => time(),
    ]);
    echo json_encode(['ok'=>true,'id'=>(int)$db->lastInsertId()]);
    exit;
}

if ($action === 'status') {
    $msg_count = (int)$db->query("SELECT COUNT(*) FROM mesh_messages")->fetchColumn();
    $node_count = (int)$db->query("SELECT COUNT(*) FROM mesh_nodes")->fetchColumn();
    $pending = (int)$db->query("SELECT COUNT(*) FROM mesh_outbox WHERE sent_at IS NULL")->fetchColumn();
    $last = $db->query("SELECT value FROM mesh_state WHERE key='last_connect'")->fetchColumn();
    $self = $db->query("SELECT node_id, long_name, short_name FROM mesh_nodes WHERE is_self=1")->fetch(PDO::FETCH_ASSOC);
    echo json_encode([
        'ok' => true,
        'msg_count' => $msg_count,
        'node_count' => $node_count,
        'pending' => $pending,
        'last_connect' => $last ? (int)$last : null,
        'self' => $self ?: null,
    ]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'unknown action']);
