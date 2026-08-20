<?php
/*
 * radio/survey/api.php  -  the new-signal detector's status, its detections,
 * and the one action that matters: go and listen to one.
 *
 * This is a SEPARATE file from radio/listen/api.php on purpose. That file owns
 * the scan-and-listen receiver's control.json and is being edited elsewhere;
 * this one owns the sweeper. The only place they touch is `listen`, which
 * writes the same control.json - through the same atomic write, and setting
 * only the park fields.
 *
 * THE HARDWARE FACT THIS FILE EXISTS TO EXPRESS: there is one tuner. It can
 * sweep or it can listen. It cannot do both. So "listen to this detection" is
 * necessarily "stop sweeping", and the honest thing is to do it in one tap and
 * say so, rather than to pretend the sweep is still running.
 */
require_once '/var/www/noosphere/shared/security.php';
sec_session_start();

const RF_DIR   = '/var/lib/noosphere/rfscan';
const RF_DB    = RF_DIR . '/rfscan.db';
const RF_STAT  = RF_DIR . '/status.json';
const SC_CTRL  = '/var/lib/noosphere/scanner/control.json';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function rf_db(): ?PDO
{
    if (!is_readable(RF_DB)) return null;
    try {
        return new PDO('sqlite:' . RF_DB, null, null,
                       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable $e) {
        return null;                 // never yet run is a normal state
    }
}

function current_mode(): string
{
    $j = @json_decode((string)@file_get_contents('/var/lib/noosphere/mode.json'), true);
    return (is_array($j) && !empty($j['mode'])) ? (string)$j['mode'] : 'unknown';
}

/* ------------------------------------------------------------------ GET */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $st  = is_readable(RF_STAT)
        ? json_decode((string)file_get_contents(RF_STAT), true) : null;
    $age = is_readable(RF_STAT) ? time() - filemtime(RF_STAT) : null;

    $dets = [];
    $audit = ['raised' => 0, 'suppressed' => 0];
    $db = rf_db();
    if ($db) {
        try {
            /* COLLAPSED PER FREQUENCY, not one row per event. A transmitter
             * that keys up nine times in ten minutes produced nine rows and
             * filled the whole screen with itself, which is unreadable at a
             * glance and actively dangerous to scroll while driving. One row
             * per frequency, carrying how many times and how long ago, says
             * strictly more in a quarter of the space.
             *
             * Rounded to 3 dp - 1 kHz - so the same carrier drifting across
             * one 10.6 kHz FFT bin does not split into two rows. That is the
             * same problem the alert cooldown key has, and the same answer.  */
            $q = $db->query(
                'SELECT MAX(id) id, MIN(first_ts) first_ts, MAX(last_ts) last_ts,'
                . ' ROUND(mhz,3) mhz, MAX(peak_over) peak_over, SUM(sweeps) sweeps,'
                . ' COUNT(*) hits,'
                . ' MAX(COALESCE(ident,\'\')) ident, MAX(COALESCE(ident_src,\'\')) ident_src,'
                . ' MAX(lat) lat, MAX(lon) lon, MAX(alerted) alerted'
                . ' FROM detections GROUP BY ROUND(mhz,3)'
                . ' ORDER BY last_ts DESC LIMIT 24');
            $dets = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
            /* Both halves of the ledger. A detector you cannot audit is one
             * you cannot tune, so the page shows how many candidates were
             * thrown away next to how many were raised. */
            $since = time() - 86400;
            foreach ($db->query(
                'SELECT result, COUNT(*) c FROM audit WHERE ts >= ' . $since
                . ' GROUP BY result') as $r) {
                $audit[$r['result']] = (int)$r['c'];
            }
        } catch (Throwable $e) { /* empty database is a normal state */ }
    }

    /* The durable summary the daemon rewrites next to its database. Passed
     * straight through so anything aggregating this box - the stats dashboard
     * being built - can read one endpoint instead of learning the schema. */
    $stats = is_readable(RF_DIR . '/stats.json')
        ? json_decode((string)file_get_contents(RF_DIR . '/stats.json'), true)
        : null;

    echo json_encode([
        'ok'         => true,
        'stats'      => $stats,
        'status'     => $st,
        'status_age' => $age,
        'stale'      => ($age === null || $age > 30),
        'sdr_mode'   => current_mode(),
        'detections' => $dets,
        'audit_24h'  => $audit,
    ]);
    exit;
}

/* ----------------------------------------------------------------- POST */

csrf_verify();
$act = (string)($_POST['act'] ?? '');

switch ($act) {

    case 'sweep':
        /* Take the dongle for sweeping. The arbiter stops weather, APRS,
         * ADS-B, the waterfall and the scan receiver first - which is exactly
         * the point of asking it rather than calling systemctl. */
        $out = []; $rc = 0;
        exec('sudo -n /usr/local/bin/noosphere-mode set survey 2>&1', $out, $rc);
        if ($rc !== 0) fail('Mode switch failed: ' . implode(' ', $out), 500);
        break;

    case 'listen':
        /* ONE TAP. Park the scan receiver on the detected frequency and hand
         * it the dongle. Sweeping stops here, unavoidably. */
        $mhz = (float)($_POST['mhz'] ?? 0);
        if ($mhz < 24 || $mhz > 1300) fail('That frequency is outside the receiver range.');
        $label = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 40);

        $c = is_readable(SC_CTRL)
            ? json_decode((string)file_get_contents(SC_CTRL), true) : null;
        if (!is_array($c)) $c = [];
        $c['park_mhz']   = round($mhz, 6);
        $c['park_label'] = $label !== '' ? $label : sprintf('New signal %.4f', $mhz);
        $c['running']    = true;
        /* Atomic, for the same reason listen/api.php is: the receiver polls
         * this file ten times a second and half a file parses as no banks. */
        $tmp = SC_CTRL . '.tmp';
        if (@file_put_contents($tmp, json_encode($c)) === false || !@rename($tmp, SC_CTRL)) {
            fail('Could not write the receiver control file.', 500);
        }
        $out = []; $rc = 0;
        exec('sudo -n /usr/local/bin/noosphere-mode set listen 2>&1', $out, $rc);
        if ($rc !== 0) fail('Mode switch failed: ' . implode(' ', $out), 500);
        break;

    case 'drive':
        /* Give the dongle back to NOAA weather - the default for driving. */
        $out = []; $rc = 0;
        exec('sudo -n /usr/local/bin/noosphere-mode set drive 2>&1', $out, $rc);
        if ($rc !== 0) fail('Mode switch failed: ' . implode(' ', $out), 500);
        break;

    case 'forget':
        /* Clear the detection list without touching the learned baseline.
         * Two different things: the list is history, the baseline is what the
         * band normally looks like and is expensive to relearn. */
        $db = rf_db();
        if ($db) {
            try { $db->exec('DELETE FROM detections'); }
            catch (Throwable $e) { fail('Could not clear detections.', 500); }
        }
        break;

    default:
        fail('Unknown action.');
}

echo json_encode(['ok' => true, 'sdr_mode' => current_mode()]);
