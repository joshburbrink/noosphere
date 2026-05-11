<?php
define('ANALYTICS_DB', '/var/lib/noosphere/analytics.db');

function _adb() {
    static $db = null;
    if ($db) return $db;
    $db = new PDO('sqlite:' . ANALYTICS_DB);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec("
        CREATE TABLE IF NOT EXISTS sessions (
            sid TEXT PRIMARY KEY,
            first_seen INTEGER NOT NULL,
            last_seen INTEGER NOT NULL,
            hit_count INTEGER DEFAULT 1,
            registered INTEGER DEFAULT 0,
            reg_name TEXT
        );
        CREATE TABLE IF NOT EXISTS hits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sid TEXT NOT NULL,
            module TEXT NOT NULL,
            ts INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS hits_ts     ON hits(ts);
        CREATE INDEX IF NOT EXISTS hits_module ON hits(module);
        CREATE INDEX IF NOT EXISTS hits_sid    ON hits(sid);
    ");
    return $db;
}

function track_visit($module) {
    try {
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        $db  = _adb();
        $sid = hash('sha256', session_id());
        $now = time();
        $db->prepare('INSERT INTO sessions (sid,first_seen,last_seen,hit_count)
            VALUES (?,?,?,1)
            ON CONFLICT(sid) DO UPDATE SET last_seen=excluded.last_seen, hit_count=hit_count+1')
           ->execute([$sid, $now, $now]);
        $db->prepare('INSERT INTO hits (sid,module,ts) VALUES (?,?,?)')
           ->execute([$sid, $module, $now]);
    } catch (Exception $e) {}
}

function mark_registered($name) {
    try {
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        $sid = hash('sha256', session_id());
        _adb()->prepare('UPDATE sessions SET registered=1, reg_name=? WHERE sid=?')
              ->execute([$name, $sid]);
    } catch (Exception $e) {}
}

function analytics_stats() {
    try {
        $db      = _adb();
        $day_ago = time() - 86400;
        $week_ago = time() - 7 * 86400;

        $total      = (int)$db->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
        $registered = (int)$db->query('SELECT COUNT(*) FROM sessions WHERE registered=1')->fetchColumn();
        $today      = (int)$db->query("SELECT COUNT(DISTINCT sid) FROM hits WHERE ts >= " . strtotime('today'))->fetchColumn();
        $total_hits = (int)$db->query('SELECT COUNT(*) FROM hits')->fetchColumn();

        // Average session duration (seconds) for sessions with more than one hit
        $avg_dur_row = $db->query('SELECT AVG(last_seen - first_seen) FROM sessions WHERE hit_count > 1')->fetchColumn();
        $avg_duration = $avg_dur_row ? (int)$avg_dur_row : 0;

        // Module hit counts
        $mod_rows = $db->query('SELECT module, COUNT(*) as cnt FROM hits GROUP BY module ORDER BY cnt DESC')->fetchAll(PDO::FETCH_ASSOC);
        $modules = [];
        $max_hits = 0;
        foreach ($mod_rows as $r) {
            $modules[$r['module']] = (int)$r['cnt'];
            if ((int)$r['cnt'] > $max_hits) $max_hits = (int)$r['cnt'];
        }

        // Peak hour (last 7 days)
        $hour_rows = $db->query("SELECT CAST(strftime('%H', ts, 'unixepoch', 'localtime') AS INTEGER) as hr, COUNT(*) as cnt
            FROM hits WHERE ts >= $week_ago GROUP BY hr ORDER BY hr")->fetchAll(PDO::FETCH_ASSOC);
        $hours = array_fill(0, 24, 0);
        $max_hour = 0;
        foreach ($hour_rows as $r) {
            $hours[(int)$r['hr']] = (int)$r['cnt'];
            if ((int)$r['cnt'] > $max_hour) $max_hour = (int)$r['cnt'];
        }

        // Daily unique visitors last 7 days
        $day_rows = $db->query("SELECT date(ts,'unixepoch','localtime') as d, COUNT(DISTINCT sid) as cnt
            FROM hits WHERE ts >= $week_ago GROUP BY d ORDER BY d")->fetchAll(PDO::FETCH_ASSOC);
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $days[date('Y-m-d', strtotime("-$i days"))] = 0;
        }
        foreach ($day_rows as $r) {
            if (isset($days[$r['d']])) $days[$r['d']] = (int)$r['cnt'];
        }
        $max_day = max($days) ?: 1;

        return compact('total','registered','today','total_hits','avg_duration','modules','max_hits','hours','max_hour','days','max_day');
    } catch (Exception $e) {
        return null;
    }
}
