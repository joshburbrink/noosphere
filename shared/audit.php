<?php
// Audit log — append-only record of admin actions.
// Call log_audit() from any admin action handler.

define('AUDIT_DB', '/var/lib/noosphere/audit.db');

function _audit_db(): SQLite3 {
    static $db = null;
    if ($db) return $db;
    $db = new SQLite3(AUDIT_DB);
    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec("CREATE TABLE IF NOT EXISTS audit (
        id       INTEGER PRIMARY KEY AUTOINCREMENT,
        ts       INTEGER NOT NULL,
        actor    TEXT NOT NULL DEFAULT 'unknown',
        ip       TEXT,
        action   TEXT NOT NULL,
        detail   TEXT,
        severity TEXT NOT NULL DEFAULT 'info'
    )");
    return $db;
}

function log_audit(string $action, string $detail = '', string $severity = 'info'): void {
    try {
        $db    = _audit_db();
        $actor = $_SESSION['admin_name'] ?? 'unknown';
        $ip    = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $s = $db->prepare("INSERT INTO audit (ts,actor,ip,action,detail,severity) VALUES (?,?,?,?,?,?)");
        $s->bindValue(1, time(),    SQLITE3_INTEGER);
        $s->bindValue(2, $actor,    SQLITE3_TEXT);
        $s->bindValue(3, $ip,       SQLITE3_TEXT);
        $s->bindValue(4, $action,   SQLITE3_TEXT);
        $s->bindValue(5, $detail ?: null, $detail ? SQLITE3_TEXT : SQLITE3_NULL);
        $s->bindValue(6, $severity, SQLITE3_TEXT);
        $s->execute();
    } catch (Throwable $e) {
        // Never let audit logging crash the admin panel
    }
}

function get_audit_log(int $limit = 200): array {
    try {
        $db  = _audit_db();
        $res = $db->query("SELECT * FROM audit ORDER BY ts DESC LIMIT $limit");
        $rows = [];
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}
