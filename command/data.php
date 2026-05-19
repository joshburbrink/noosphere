<?php
/*
 * /command/data.php — JSON snapshot for the Incident Command dashboard (#76).
 * Polled every ~30s by /command/index.php. All queries are read-only.
 */
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
require_once '/var/www/noosphere/shared/identity.php';
sec_session_start();

if (get_setting('show_incidents_command', '0') !== '1') { http_response_code(404); exit; }
if (!can('command.view')) { http_response_code(403); exit; }

header('Content-Type: application/json');
header('Cache-Control: no-store');

function _q(string $dbpath, string $sql): array {
    try {
        $db = new SQLite3($dbpath, SQLITE3_OPEN_READONLY);
        $res = $db->query($sql);
        $rows = [];
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
        return $rows;
    } catch (Throwable $e) { return []; }
}

$show_incidents = get_setting('show_incidents', '0') === '1';
$show_runners   = get_setting('show_runners',   '1') === '1';
$show_weather   = get_setting('show_weather',   '1') === '1';
$show_registry  = get_setting('show_registry',  '1') === '1';
$show_supplies  = get_setting('show_supplies',  '0') === '1';
$show_radio     = get_setting('show_radio',     '1') === '1';
$shelter_mode   = get_setting('registry_shelter', '0') === '1';
$shelter_cap    = (int)get_setting('shelter_capacity', '0');

$out = ['ts' => time()];

// ── Active incidents ────────────────────────────────────────────────────────
if ($show_incidents) {
    $rows = _q('/var/lib/noosphere/incidents.db',
        "SELECT id, type, severity, title, location_text, lat, lng, status, assigned_to,
                submitted_at, updated_at
         FROM incidents WHERE status != 'resolved'
         ORDER BY CASE severity WHEN 'critical' THEN 0 WHEN 'major' THEN 1 WHEN 'minor' THEN 2 ELSE 3 END,
                  submitted_at DESC LIMIT 50");
    $out['incidents'] = ['enabled' => true, 'rows' => $rows, 'count' => count($rows)];
} else {
    $out['incidents'] = ['enabled' => false];
}

// ── Runner board ────────────────────────────────────────────────────────────
if ($show_runners) {
    $rows = _q('/var/lib/noosphere/runners.db',
        "SELECT id, name, destination, departed_at, expected_at, notes
         FROM runners WHERE status='out' ORDER BY departed_at ASC LIMIT 30");
    $now = time();
    foreach ($rows as &$r) {
        $r['overdue'] = ($r['expected_at'] && $r['expected_at'] < $now) ? 1 : 0;
    }
    $out['runners'] = ['enabled' => true, 'rows' => $rows, 'count' => count($rows)];
} else {
    $out['runners'] = ['enabled' => false];
}

// ── NWR active alert + latest weather observation ──────────────────────────
if ($show_weather) {
    $alert = null;
    try {
        $db = new SQLite3('/var/lib/noosphere/weather/alerts.db', SQLITE3_OPEN_READONLY);
        $alert = $db->querySingle(
            "SELECT event, headline, severity, received_at, expires_at, matched_local
             FROM alerts WHERE expires_at > " . time() . "
             ORDER BY received_at DESC LIMIT 1", true) ?: null;
    } catch (Throwable $e) {}

    $obs = null;
    try {
        $db = new SQLite3('/var/lib/noosphere/weather.db', SQLITE3_OPEN_READONLY);
        $obs = $db->querySingle("SELECT * FROM weather_log ORDER BY logged_at DESC LIMIT 1", true) ?: null;
    } catch (Throwable $e) {}

    $out['weather'] = ['enabled' => true, 'alert' => $alert, 'obs' => $obs];
} else {
    $out['weather'] = ['enabled' => false];
}

// ── Registry headcount / shelter occupancy ─────────────────────────────────
if ($show_registry) {
    $total = $shelter = 0;
    try {
        $db = new SQLite3('/var/lib/noosphere/registry.db', SQLITE3_OPEN_READONLY);
        $total = (int)$db->querySingle(
            "SELECT COUNT(*) FROM registry WHERE entry_type='checkin' OR entry_type IS NULL OR entry_type=''");
        if ($shelter_mode) {
            try {
                $shelter = (int)$db->querySingle("SELECT COUNT(*) FROM registry WHERE bunk IS NOT NULL AND bunk != ''");
            } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {}

    $out['registry'] = [
        'enabled'  => true,
        'total'    => $total,
        'shelter'  => $shelter,
        'capacity' => $shelter_mode ? $shelter_cap : 0,
    ];
} else {
    $out['registry'] = ['enabled' => false];
}

// ── Supplies — only red/yellow ─────────────────────────────────────────────
if ($show_supplies) {
    $items = _q('/var/lib/noosphere/inventory.db',
        "SELECT name, category, quantity, unit, low_threshold, consumption_per_day FROM items");
    $alerts = [];
    foreach ($items as $i) {
        $cpd = (float)$i['consumption_per_day'];
        $qty = (float)$i['quantity'];
        $low = (float)$i['low_threshold'];
        $days = $cpd > 0 ? $qty / $cpd : null;
        $status = 'green';
        if ($days !== null) {
            if      ($days < 1) $status = 'red';
            elseif  ($days < 3) $status = 'yellow';
            else                $status = 'green';
        } elseif ($low > 0) {
            if      ($qty <= $low)       $status = 'red';
            elseif  ($qty <= $low * 1.5) $status = 'yellow';
        }
        if ($status !== 'green') {
            $alerts[] = [
                'name'   => $i['name'],
                'qty'    => $qty,
                'unit'   => $i['unit'],
                'days'   => $days !== null ? round($days, 1) : null,
                'status' => $status,
            ];
        }
    }
    // Sort: red before yellow, then by days ascending
    usort($alerts, function($a, $b) {
        if ($a['status'] !== $b['status']) return $a['status'] === 'red' ? -1 : 1;
        $ad = $a['days'] ?? 999; $bd = $b['days'] ?? 999;
        return $ad <=> $bd;
    });
    $out['supplies'] = ['enabled' => true, 'rows' => array_slice($alerts, 0, 15), 'count' => count($alerts)];
} else {
    $out['supplies'] = ['enabled' => false];
}

// ── Radio / SDR status ─────────────────────────────────────────────────────
if ($show_radio) {
    $mode = get_setting('radio_mode', 'off');
    $signal = null;
    $sigfile = '/var/lib/noosphere/weather/signal.json';
    if (is_readable($sigfile)) {
        $j = json_decode(@file_get_contents($sigfile), true);
        if (is_array($j) && isset($j['dbfs'])) $signal = (float)$j['dbfs'];
    }
    $out['radio'] = ['enabled' => true, 'mode' => $mode, 'signal_dbfs' => $signal];
} else {
    $out['radio'] = ['enabled' => false];
}

echo json_encode($out);
