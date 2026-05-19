<?php
require_once __DIR__ . '/../shared/security.php';
require_once __DIR__ . '/../shared/identity.php';
sec_session_start();

header('Content-Type: application/json');
header('Cache-Control: no-store');

$u  = current_user();
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

echo json_encode([
    'signed_in' => !empty($u),
    'name'      => $u['name']     ?? '',
    'roles'     => $u['roles']    ?? [],
    'is_admin'  => $u['is_admin'] ?? false,
    'source'    => $u['source']   ?? '',
    'ip'        => $ip,
]);
