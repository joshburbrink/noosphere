<?php
/*
 * radio/listen/api.php  -  control and status for the scan-and-listen receiver.
 *
 * GET  ?act=status   -> the daemon's status.json, plus what the page needs to
 *                       render before the daemon has ever run.
 * POST                -> one small mutation of control.json, CSRF-checked.
 *
 * The daemon polls control.json ten times a second, so nothing here needs to
 * restart a service - which is why this file needs no sudo rule of its own.
 * The ONE privileged thing it does is ask the mode arbiter for the dongle, and
 * www-data is already allowed exactly `noosphere-mode set *` and nothing else.
 * Never call systemctl from here: the arbiter exists so that one place decides
 * who holds the SDR.
 */
require_once '/var/www/noosphere/shared/security.php';
sec_session_start();

const STATE_DIR = '/var/lib/noosphere/scanner';
const CONTROL   = STATE_DIR . '/control.json';
const STATUS    = STATE_DIR . '/status.json';
const RPT_DB    = STATE_DIR . '/repeaters.db';
const STATS_JSON = STATE_DIR . '/channels-stats.json';

/* The FCC ULS categories the importer derives. Must stay in step with
 * FCC_CATEGORIES in noosphere-scan-listen and uls_category() in
 * noosphere-repeater-import. Kept as a literal list, not read from the
 * database, so an empty or half-imported `channels` table cannot silently
 * remove every bank button. */
const FCC_CATEGORIES = ['public-safety', 'fire', 'ems', 'law', 'railroad',
                        'parks-dnr', 'school', 'utility', 'business'];

header('Content-Type: application/json');
header('Cache-Control: no-store');

function control_read(): array
{
    $d = is_readable(CONTROL)
        ? json_decode((string)file_get_contents(CONTROL), true) : null;
    if (!is_array($d)) $d = [];
    return $d + [
        'running'         => false,
        'banks'           => ['county', 'repeaters'],
        'park_mhz'        => null,
        'park_label'      => null,
        'squelch'         => null,
        'county_override' => null,
        'custom'          => [],
    ];
}

function control_write(array $c): bool
{
    // Atomic: the daemon reads this file constantly and a half-written
    // control file would parse as "no banks" and silently mute the scanner.
    $tmp = CONTROL . '.tmp';
    if (@file_put_contents($tmp, json_encode($c)) === false) return false;
    return @rename($tmp, CONTROL);
}

function fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

/* ------------------------------------------------------------------ GET */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $st = is_readable(STATUS)
        ? json_decode((string)file_get_contents(STATUS), true) : null;
    $age = is_readable(STATUS) ? time() - filemtime(STATUS) : null;

    $mode = 'unknown';
    if (is_readable('/var/lib/noosphere/mode.json')) {
        $j = json_decode((string)@file_get_contents('/var/lib/noosphere/mode.json'), true);
        if (is_array($j) && !empty($j['mode'])) $mode = $j['mode'];
    }

    // Counted here rather than in the daemon so the page can explain an empty
    // repeater bank ("none imported") differently from a real one ("none
    // within 25 miles").
    $rpt = ['total' => 0, 'with_pos' => 0, 'counties' => 0,
            'channels' => 0, 'channels_listenable' => 0, 'uls_date' => null,
            'by_category' => []];
    if (is_readable(RPT_DB)) {
        try {
            $db = new PDO('sqlite:' . RPT_DB, null, null,
                          [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $rpt['total']    = (int)$db->query('SELECT COUNT(*) FROM repeaters')->fetchColumn();
            $rpt['with_pos'] = (int)$db->query('SELECT COUNT(*) FROM repeaters WHERE lat IS NOT NULL')->fetchColumn();
            $rpt['counties'] = (int)$db->query('SELECT COUNT(*) FROM counties')->fetchColumn();
            /* Separate try: the `channels` table only exists once the FCC
             * import has been run at least once, and a rig without it must
             * still show its repeater counts rather than erroring out. */
            try {
                $rpt['channels'] = (int)$db->query('SELECT COUNT(*) FROM channels')->fetchColumn();
                $rpt['channels_listenable'] = (int)$db->query('SELECT COUNT(*) FROM channels WHERE listenable=1')->fetchColumn();
                $rpt['uls_date'] = $db->query("SELECT data_date FROM import_runs WHERE source='fcc' AND ok=1 ORDER BY started DESC LIMIT 1")->fetchColumn() ?: null;
                /* Statewide per-category totals. These are what the bank
                 * buttons show BEFORE the receiver is running and a county
                 * has been resolved - without them every button reads "none
                 * licensed here", which looks exactly like a failed import
                 * rather than "we do not know where you are yet". */
                foreach ($db->query('SELECT category, SUM(listenable), COUNT(*) FROM channels GROUP BY category') as $r) {
                    $rpt['by_category'][$r[0]] = ['listenable' => (int)$r[1],
                                                  'licensed'   => (int)$r[2]];
                }
            } catch (Throwable $e) { /* not imported yet */ }
        } catch (Throwable $e) { /* empty database is a normal state */ }
    }

    echo json_encode([
        'ok'         => true,
        'status'     => $st,
        'status_age' => $age,
        'stale'      => ($age === null || $age > 10),
        'control'    => control_read(),
        'sdr_mode'   => $mode,
        'library'    => $rpt,
    ]);
    exit;
}

/* ----------------------------------------------------------------- POST */

csrf_verify();
$act = (string)($_POST['act'] ?? '');
$c   = control_read();

switch ($act) {
    case 'start':
        // Ask the arbiter for the dongle. It stops NOAA weather, APRS, ADS-B
        // and the waterfall first, so this is also what keeps two receivers
        // from fighting over one 0bda:2838.
        $c['running'] = true;
        if (!control_write($c)) fail('Could not write the control file.', 500);
        $out = [];
        $rc = 0;
        exec('sudo -n /usr/local/bin/noosphere-mode set listen 2>&1', $out, $rc);
        if ($rc !== 0) fail('Mode switch failed: ' . implode(' ', $out), 500);
        break;

    case 'stop':
        $c['running'] = false;
        $c['park_mhz'] = null;
        $c['park_label'] = null;
        if (!control_write($c)) fail('Could not write the control file.', 500);
        break;

    case 'drive':
        // Hand the dongle back to NOAA weather - the default for driving.
        $c['running'] = false;
        control_write($c);
        $out = [];
        $rc = 0;
        exec('sudo -n /usr/local/bin/noosphere-mode set drive 2>&1', $out, $rc);
        if ($rc !== 0) fail('Mode switch failed: ' . implode(' ', $out), 500);
        break;

    case 'banks':
        $want = (array)($_POST['banks'] ?? []);
        /* Group ids come from the data file, so adding a group there needs
         * no change here - but only ids that really exist are accepted, so a
         * stale button cannot mute the scanner by selecting nothing. */
        $allowed = ['county', 'repeaters', 'nwr'];
        $sx = @json_decode((string)@file_get_contents('/etc/noosphere/channels-simplex.json'), true);
        foreach (($sx['groups'] ?? []) as $g) {
            if (!empty($g['id'])) $allowed[] = 'simplex:' . $g['id'];
        }
        /* FCC ULS categories, same selection shape as the simplex groups.
         * Whitelisted from a constant rather than from whatever the POST
         * carried: the category also reaches a SQL IN() clause in the daemon,
         * and this is the boundary where that stops being user input. */
        foreach (FCC_CATEGORIES as $cat) $allowed[] = 'fcc:' . $cat;
        $ok = array_values(array_intersect($allowed, $want));
        $c['banks'] = $ok;
        $c['park_mhz'] = null;
        if (!control_write($c)) fail('Could not write the control file.', 500);
        break;

    case 'park':
        $mhz = (float)($_POST['mhz'] ?? 0);
        // 24-1300 MHz is the dongle's own range; anything else is a bad tap or
        // a bad import and must not reach rtl_fm.
        if ($mhz < 24 || $mhz > 1300) fail('That frequency is outside the receiver range.');
        $c['park_mhz']   = round($mhz, 6);
        $c['park_label'] = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 40) ?: null;
        $c['running']    = true;
        if (!control_write($c)) fail('Could not write the control file.', 500);
        break;

    case 'unpark':
        $c['park_mhz'] = null;
        $c['park_label'] = null;
        if (!control_write($c)) fail('Could not write the control file.', 500);
        break;

    case 'squelch':
        $v = (int)($_POST['value'] ?? 0);
        // Measured on this dongle at gain 49.6: below 1 there is no squelch at
        // all (and rtl_fm then refuses to scan), above ~18 nothing ever opens.
        if ($v < 1 || $v > 18) fail('Squelch must be between 1 and 18.');
        $c['squelch'] = $v;
        if (!control_write($c)) fail('Could not write the control file.', 500);
        break;

    case 'county':
        $slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($_POST['slug'] ?? '')));
        $c['county_override'] = $slug !== '' ? $slug : null;
        if (!control_write($c)) fail('Could not write the control file.', 500);
        break;

    default:
        fail('Unknown action.');
}

echo json_encode(['ok' => true, 'control' => $c]);
