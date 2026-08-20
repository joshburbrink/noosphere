<?php
/*
 * radio/listen/chirp.php  -  download a CHIRP-importable channel list for
 * wherever the vehicle is.
 *
 * Served over HTTP rather than written to the card because the file is useless
 * on the head unit itself: it is for the laptop that programs the radio. Open
 * this URL from that laptop and the CSV lands in its downloads folder.
 *
 * Everything reaching the shell is cast to a number or matched against a
 * whitelist first - this endpoint is reachable from the OPEN Info Hub AP, so
 * the parameters are untrusted. The data itself is public repeater
 * information, so there is nothing here worth gating behind auth.
 */
require_once '/var/www/noosphere/shared/security.php';
sec_session_start();

const EXPORT_BIN = '/usr/local/bin/noosphere-chirp-export';
const GPS_FILE   = '/run/noosphere/parked.json';

/* Numbers only: a float cast cannot carry a shell metacharacter through. */
$lat    = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$lon    = isset($_GET['lon']) ? (float)$_GET['lon'] : null;
$count  = max(1, min(500, (int)($_GET['count'] ?? 50)));
$radius = max(1.0, min(300.0, (float)($_GET['radius'] ?? 60)));

/* Whitelist, not a filter: an unknown band name is dropped rather than passed
 * through and hoped about. */
$known = ['10m', '6m', '2m', '1.25m', '70cm', '33cm', '23cm'];
$bands = [];
foreach (explode(',', (string)($_GET['bands'] ?? '')) as $b) {
    $b = strtolower(trim($b));
    if ($b !== '' && in_array($b, $known, true)) $bands[] = $b;
}

/* Fall back to the last known position. Without one the export cannot rank by
 * distance at all, so this fails loudly instead of emitting an arbitrary
 * list. */
if ($lat === null || $lon === null) {
    $j = @json_decode((string)@file_get_contents(GPS_FILE), true);
    if (is_array($j) && isset($j['lat'], $j['lon'])) {
        $lat = (float)$j['lat'];
        $lon = (float)$j['lon'];
    }
}
if ($lat === null || $lon === null || ($lat == 0.0 && $lon == 0.0)) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(409);
    echo "No position available.\n\n";
    echo "There is no GPS fix, so nearby cannot be worked out. Add coordinates:\n";
    echo "  ?lat=39.2014&lon=-85.9214\n";
    exit;
}

$cmd = escapeshellcmd(EXPORT_BIN)
     . ' --lat ' . escapeshellarg(sprintf('%.6f', $lat))
     . ' --lon ' . escapeshellarg(sprintf('%.6f', $lon))
     . ' --count ' . escapeshellarg((string)$count)
     . ' --radius ' . escapeshellarg(sprintf('%.2f', $radius));
if ($bands) $cmd .= ' --bands ' . escapeshellarg(implode(',', $bands));

$out = [];
$rc = 0;
exec($cmd . ' 2>/dev/null', $out, $rc);

if ($rc !== 0 || !$out) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    echo "Could not build the channel list (exit $rc).\n";
    echo "If the repeater database is empty, import it first:\n";
    echo "  noosphere-repeater-import --source csv\n";
    exit;
}

/* A name with the position in it, so several exports from different places do
 * not overwrite each other in a downloads folder. */
$name = sprintf('chirp-%.3f_%.3f-%dch.csv', $lat, $lon, count($out) - 1);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');
echo implode("\n", $out), "\n";
