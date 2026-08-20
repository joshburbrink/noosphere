<?php
/*
 * maps/_gps.php  -  one non-blocking gpsd read, shared by maps/nav.php and
 * maps/nav-api.php.
 *
 * car/api.php and car/poi.php each carry their own copy of this logic and are
 * owned elsewhere; this is a third copy on purpose, so that nothing in the
 * routing module can change behaviour under those pages. The contract is the
 * important part, not the sharing: it returns in about a second at worst, and
 * "no fix" is a normal answer with a reason attached, never an exception and
 * never a wait.
 */

if (!function_exists('nav_gps_fix')) {

function nav_gps_fix(): array
{
    $fp = @stream_socket_client('tcp://127.0.0.1:2947', $e, $s, 0.3);
    if (!$fp) {
        return ['fix' => false, 'lat' => null, 'lon' => null, 'speed' => null,
                'track' => null, 'mode' => 0, 'why' => 'gpsd is not answering on this box'];
    }
    stream_set_timeout($fp, 1);
    fwrite($fp, "?WATCH={\"enable\":true,\"json\":true}\n");
    $out = ['fix' => false, 'lat' => null, 'lon' => null, 'speed' => null,
            'track' => null, 'mode' => 0, 'why' => 'gpsd answered but sent no position'];
    $deadline = microtime(true) + 1.2;
    while (microtime(true) < $deadline) {
        $line = fgets($fp);
        if ($line === false) break;
        $j = json_decode($line, true);
        if (!is_array($j) || ($j['class'] ?? '') !== 'TPV') continue;
        $mode = (int)($j['mode'] ?? 0);
        $ok   = $mode >= 2 && isset($j['lat'], $j['lon']);
        $out = [
            'fix'   => $ok,
            'mode'  => $mode,
            'lat'   => $ok ? (float)$j['lat'] : null,
            'lon'   => $ok ? (float)$j['lon'] : null,
            'speed' => isset($j['speed']) && is_numeric($j['speed']) ? (float)$j['speed'] : null,
            'track' => isset($j['track']) && is_numeric($j['track']) ? (float)$j['track'] : null,
            'why'   => $ok ? '' : ($mode <= 1
                        ? 'the receiver has no satellite lock yet - common indoors'
                        : 'gpsd reports mode ' . $mode),
        ];
        break;
    }
    fclose($fp);
    return $out;
}

}
