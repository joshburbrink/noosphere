<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
require_once '/var/www/noosphere/shared/identity.php';
require_once '/var/www/noosphere/shared/capabilities.php';
sec_session_start();

if (get_setting('show_mesh','0') !== '1') { http_response_code(404); exit; }

function mesh_db(): PDO {
    static $db = null;
    if ($db) return $db;
    $db = new PDO('sqlite:/var/lib/noosphere/mesh.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA busy_timeout=2000");
    return $db;
}

// Tables created by the daemon - if the daemon hasn't run yet, these may not
// exist. mesh_db_ready() lets the UI degrade gracefully.
function mesh_db_ready(): bool {
    try {
        $r = mesh_db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='mesh_messages'")->fetch();
        return (bool)$r;
    } catch (Exception $e) { return false; }
}
