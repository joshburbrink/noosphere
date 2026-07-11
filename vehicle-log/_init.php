<?php
/*
 * vehicle-log/_init.php  -  shared init for the ALPR vehicle-log module (Phase 3).
 *
 * This module is a read/manage UI layered on top of the standalone Python
 * capture daemon (capture_daemon.py, not part of this repo) that writes to
 * /var/lib/noosphere-alpr/sightings.db. This module does not create or alter
 * that schema  -  it only reads existing rows and, with alpr.manage, deletes
 * them / starts-stops the daemon via the admin panel.
 */

require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
require_once '/var/www/noosphere/shared/identity.php';

const ALPR_DB_PATH = '/var/lib/noosphere-alpr/sightings.db';
const ALPR_IMG_DIR  = '/var/lib/noosphere-alpr/images';

function alpr_db(): PDO {
    static $db = null;
    if ($db) return $db;
    $db = new PDO('sqlite:' . ALPR_DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}

// Gate the whole module behind the opt-in settings toggle + capability,
// same pattern as /command/'s show_incidents_command + command.view gate.
function alpr_require_access(): void {
    if (get_setting('show_alpr', '0') !== '1') { http_response_code(404); exit; }
    if (!can('alpr.view')) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '/vehicle-log/');
        header("Location: /registry/login.php?next=$next");
        exit;
    }
}
