<?php
define('RATE_DB', '/var/lib/noosphere/ratelimit.db');
define('REGISTRY_DB', '/var/lib/noosphere/registry.db');

function sec_session_start() {
    if (session_status() !== PHP_SESSION_NONE) return;
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);
    session_start();
    if (empty($_SESSION['_init'])) {
        session_regenerate_id(true);
        $_SESSION['_init'] = true;
    }

    // Head-unit auto-auth: grant/revoke admin from trusted-phone presence.
    // Strictly loopback-only - see shared/presence_auth.php.
    //
    // Optional module, present only on head-unit installs. Guarded with
    // is_file() so the core repo never hard-depends on a file it does not
    // ship - without this, any install lacking presence_auth.php would fatal
    // on every page load.
    if (is_file(__DIR__ . '/presence_auth.php')) {
        require_once __DIR__ . '/presence_auth.php';
        presence_auth_apply();
    }
}

function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field() {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

function csrf_verify() {
    $token = $_POST['_csrf'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        die('<p style="font-family:sans-serif;padding:2rem;color:#e94560;background:#0f0f1a;min-height:100vh;margin:0">Invalid request token. Please go back and try again.</p>');
    }
}

// For AJAX: pass token as JS var, send in POST body
function csrf_js() {
    return '<script>var CSRF_TOKEN=' . json_encode(csrf_token()) . ';</script>';
}

function _rate_db() {
    static $rdb = null;
    if (!$rdb) {
        $rdb = new PDO('sqlite:' . RATE_DB);
        $rdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $rdb->exec("CREATE TABLE IF NOT EXISTS attempts (
            key TEXT NOT NULL,
            ts  INTEGER NOT NULL
        ); CREATE INDEX IF NOT EXISTS idx ON attempts(key, ts);");
    }
    return $rdb;
}

function rate_limit($action, $max = 5, $window = 900) {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '0';
    $key = hash('sha256', $action . ':' . $ip);
    $now = time();
    $rdb = _rate_db();
    if (random_int(1, 30) === 1) {
        $rdb->prepare('DELETE FROM attempts WHERE ts < ?')->execute([$now - 86400]);
    }
    $stmt = $rdb->prepare('SELECT COUNT(*) FROM attempts WHERE key=? AND ts>?');
    $stmt->execute([$key, $now - $window]);
    if ((int)$stmt->fetchColumn() >= $max) {
        http_response_code(429);
        $mins = ceil($window / 60);
        die('<p style="font-family:sans-serif;padding:2rem;color:#e94560;background:#0f0f1a;min-height:100vh;margin:0">Too many attempts. Please wait ' . $mins . ' minutes and try again.</p>');
    }
    $rdb->prepare('INSERT INTO attempts (key, ts) VALUES (?,?)')->execute([$key, $now]);
}

function rate_reset($action) {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '0';
    $key = hash('sha256', $action . ':' . $ip);
    _rate_db()->prepare('DELETE FROM attempts WHERE key=?')->execute([$key]);
}

// Verify registry name+PIN; optionally require is_admin flag
function verify_pin($name, $pin, $require_admin = false) {
    rate_limit('pin', 5, 900);
    try {
        $rdb = new PDO('sqlite:' . REGISTRY_DB);
        $s = $rdb->prepare("SELECT * FROM registry WHERE LOWER(name)=LOWER(?) AND (entry_type='checkin' OR entry_type IS NULL OR entry_type='')");
        $s->execute([trim($name)]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row && password_verify($pin, $row['pin'])) {
            if ($require_admin && empty($row['is_admin'])) return false;
            rate_reset('pin');
            return $row;
        }
    } catch (Exception $e) {}
    return false;
}

// MIME validation  -  deny executable types regardless of extension
function check_mime_safe($tmp_path) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($tmp_path);
    $denied = [
        'text/x-php','application/x-php','application/php',
        'application/x-httpd-php','application/x-httpd-php-source',
        'text/html','application/xhtml+xml',
        'application/javascript','text/javascript',
        'application/x-sh','text/x-sh','application/x-shellscript',
        'application/x-perl','text/x-perl',
        'application/x-python','text/x-python',
    ];
    return !in_array($mime, $denied);
}

function allowed_image_mime($tmp_path) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($tmp_path);
    return in_array($mime, ['image/jpeg','image/png','image/gif','image/webp']);
}

function _ban_db() {
    static $bdb = null;
    if (!$bdb) {
        $bdb = new PDO('sqlite:' . RATE_DB);
        $bdb->exec("CREATE TABLE IF NOT EXISTS bans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            ip   TEXT,
            reason TEXT,
            banned_by TEXT,
            created_at INTEGER NOT NULL
        );");
    }
    return $bdb;
}

function is_banned($name = null) {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '0';
    $bdb = _ban_db();
    $stmt = $bdb->prepare('SELECT COUNT(*) FROM bans WHERE ip=? OR (name IS NOT NULL AND LOWER(name)=LOWER(?))');
    $stmt->execute([$ip, $name ?? '']);
    return (int)$stmt->fetchColumn() > 0;
}

function add_ban($name, $ip, $reason, $banned_by) {
    $bdb = _ban_db();
    $bdb->prepare('INSERT INTO bans (name,ip,reason,banned_by,created_at) VALUES (?,?,?,?,?)')
        ->execute([$name ?: null, $ip ?: null, $reason, $banned_by, time()]);
}

function remove_ban($id) {
    _ban_db()->prepare('DELETE FROM bans WHERE id=?')->execute([$id]);
}

function get_bans() {
    return _ban_db()->query('SELECT * FROM bans ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
}

function ban_check_or_die($name = null) {
    if (is_banned($name)) {
        http_response_code(403);
        die('<p style="font-family:sans-serif;padding:2rem;color:#e94560;background:#0f0f1a;min-height:100vh;margin:0">Your access has been restricted by an administrator.</p>');
    }
}
