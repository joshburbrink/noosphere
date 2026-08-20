<?php
/*
 * maps/nav-api.php  -  the only bridge between the browser and the on-board
 * routing engine.
 *
 * GraphHopper listens on 127.0.0.1:8991 and NOTHING ELSE. The AP subnet
 * (192.168.4.0/24) is an open network, so the engine is never bound where a
 * guest could reach it; this PHP endpoint is the single front door and it
 * only ever forwards a from/to pair. Everything here is offline: the graph
 * was built from the Indiana OSM extract and no request leaves the box.
 *
 * Three operations, all GET, all JSON:
 *
 *   ?op=fix                       current gpsd position, or a first-class
 *                                 "no fix" answer - never a hang
 *   ?op=route&flat=&flon=&tlat=&tlon=
 *                                 car route, GeoJSON line + turn list
 *   ?op=find&q=kroger&lat=&lon=   offline place lookup so a driver with no
 *                                 GPS fix can still name a starting point
 *
 * Every failure mode returns ok:false with a `code` the UI can turn into a
 * sentence. A spinner that never resolves is the one outcome this file
 * exists to prevent, so every path has a timeout and every timeout has a
 * message.
 */

require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();

const GH_BASE   = 'http://127.0.0.1:8991';
const GH_TMO    = 12;          // seconds; a CH query is milliseconds, this is
                               // only long enough to cover a cold JIT
const POI_DB    = '/var/lib/noosphere/poi/poi.db';
const M_PER_MI  = 1609.344;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $a): never { echo json_encode($a); exit; }
function fail(string $code, string $msg): never { out(['ok' => false, 'code' => $code, 'error' => $msg]); }

function num(string $k): ?float
{
    if (!isset($_GET[$k]) || $_GET[$k] === '') return null;
    if (!is_scalar($_GET[$k])) return null;
    $v = filter_var((string)$_GET[$k], FILTER_VALIDATE_FLOAT);
    return $v === false ? null : (float)$v;
}

require_once '/var/www/noosphere/maps/_gps.php';

/* ---------- engine ------------------------------------------------------- */

function gh_get(string $path): array
{
    $ch = curl_init(GH_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => GH_TMO,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($body === false) return ['http' => 0, 'json' => null, 'curl' => $cerr];
    return ['http' => $code, 'json' => json_decode($body, true), 'curl' => ''];
}

/*
 * GraphHopper sign codes -> a glyph and a plain word. The engine already
 * writes the sentence ("Turn right onto County Road 200 S"); this is only the
 * icon beside it, so an unknown sign degrades to a dot rather than an error.
 */
function sign_glyph(int $s): string
{
    return match ($s) {
        -98, -8, 8 => "\u{21B6}",       // u-turn
        -3         => "\u{2196}",       // sharp left
        -2         => "\u{2190}",       // left
        -1, -7     => "\u{2196}",       // slight/keep left
        0          => "\u{2191}",       // continue
        1, 7       => "\u{2197}",       // slight/keep right
        2          => "\u{2192}",       // right
        3          => "\u{2197}",       // sharp right
        4, 5       => "\u{25C9}",       // arrive / via
        6          => "\u{27F3}",       // roundabout
        default    => "\u{2022}",
    };
}

/* ---------- dispatch ----------------------------------------------------- */

$op = isset($_GET['op']) && is_string($_GET['op']) ? $_GET['op'] : '';

if ($op === 'fix') {
    $g = nav_gps_fix();
    out([
        'ok'    => true,
        'fix'   => (bool)$g['fix'],
        'lat'   => $g['lat']   ?? null,
        'lon'   => $g['lon']   ?? null,
        'speed' => $g['speed'] ?? null,
        'track' => $g['track'] ?? null,
        'why'   => $g['why']   ?? '',
    ]);
}

if ($op === 'find') {
    // Offline origin picker. Bounded, prefix-matched, ranked by distance from
    // whatever reference point the caller has (the region centre when there is
    // no fix at all).
    $qs = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
    if (mb_strlen($qs) < 2) fail('bad_input', 'Type at least two characters.');
    if (mb_strlen($qs) > 60) $qs = mb_substr($qs, 0, 60);
    if (!is_readable(POI_DB)) fail('no_index', 'No offline place index on this box.');

    $rlat = num('lat') ?? (float)get_setting('region_lat', '39.2');
    $rlon = num('lon') ?? (float)get_setting('region_lng', '-85.92');

    $toks = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($qs), -1, PREG_SPLIT_NO_EMPTY);
    $parts = [];
    foreach (array_slice($toks, 0, 6) as $t) {
        if (mb_strlen($t) < 2) continue;
        $parts[] = '"' . $t . '"*';
    }
    if (!$parts) fail('bad_input', 'Nothing searchable in that.');

    try {
        $db = new PDO('sqlite:' . POI_DB, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA query_only=1');
        $st = $db->prepare(
            'SELECT p.name, p.brand, p.operator, p.category, p.lat, p.lon,
                    p.addr_housenumber, p.addr_street, p.addr_city
               FROM poi_fts f JOIN poi p ON p.id = f.rowid
              WHERE poi_fts MATCH :m LIMIT 1500');
        $st->execute([':m' => implode(' ', $parts)]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        fail('no_index', 'Place index could not be read.');
    }

    $hits = [];
    foreach ($rows as $r) {
        $dlat = deg2rad((float)$r['lat'] - $rlat);
        $dlon = deg2rad((float)$r['lon'] - $rlon) * cos(deg2rad($rlat));
        $d = 6371000.0 * sqrt($dlat * $dlat + $dlon * $dlon);   // equirect. is
        $name = '';                                             // plenty for ranking
        foreach (['name', 'brand', 'operator'] as $k) {
            if (!empty($r[$k])) { $name = (string)$r[$k]; break; }
        }
        if ($name === '') $name = ucfirst(str_replace('_', ' ', (string)$r['category']));
        $sub = trim(trim((string)($r['addr_housenumber'] ?? '')) . ' ' .
                    trim((string)($r['addr_street'] ?? '')));
        if (!empty($r['addr_city'])) $sub = ($sub !== '' ? $sub . ', ' : '') . $r['addr_city'];
        $hits[] = ['name' => $name, 'sub' => $sub, 'lat' => (float)$r['lat'],
                   'lon' => (float)$r['lon'], 'dist_m' => $d];
    }
    usort($hits, fn($a, $b) => $a['dist_m'] <=> $b['dist_m']);
    out(['ok' => true, 'results' => array_slice($hits, 0, 12), 'total' => count($hits)]);
}

if ($op === 'route') {
    $tlat = num('tlat'); $tlon = num('tlon');
    if ($tlat === null || $tlon === null) fail('bad_input', 'No destination given.');

    $flat = num('flat'); $flon = num('flon');
    $src  = 'given';
    if ($flat === null || $flon === null) {
        // No explicit origin: this is the "route me from where I am" case.
        $g = nav_gps_fix();
        if (empty($g['fix'])) {
            fail('no_fix', 'No GPS fix - ' . ($g['why'] ?: 'gpsd has no position') . '.');
        }
        $flat = (float)$g['lat']; $flon = (float)$g['lon']; $src = 'gps';
    }
    foreach ([[$flat, $flon], [$tlat, $tlon]] as [$a, $b]) {
        if ($a < -90 || $a > 90 || $b < -180 || $b > 180) fail('bad_input', 'Coordinates out of range.');
    }

    $qs = http_build_query([
        'point'          => sprintf('%.6f,%.6f', $flat, $flon),
        'profile'        => 'car',
        'points_encoded' => 'false',
        'instructions'   => 'true',
        'locale'         => 'en',
        'elevation'      => 'false',
    ]) . '&' . http_build_query(['point' => sprintf('%.6f,%.6f', $tlat, $tlon)]);

    $r = gh_get('/route?' . $qs);

    if ($r['http'] === 0) {
        fail('no_engine',
             'The routing engine is not running. It lives on the CAR-DATA card; '
             . 'if that card is missing the rest of the head unit still works.');
    }
    if (!is_array($r['json'])) fail('engine_error', 'The routing engine returned something unreadable.');

    if ($r['http'] !== 200 || empty($r['json']['paths'][0])) {
        $why = $r['json']['message'] ?? 'No road route between those two points.';
        // GraphHopper says "Connection between locations not found" for a point
        // off the graph - across a state line, or in the middle of a field.
        $code = stripos((string)$why, 'not found') !== false || stripos((string)$why, 'connection') !== false
              ? 'no_route' : 'engine_error';
        fail($code, (string)$why);
    }

    $p = $r['json']['paths'][0];
    $steps = [];
    foreach (($p['instructions'] ?? []) as $ins) {
        $steps[] = [
            'text'       => (string)($ins['text'] ?? ''),
            'street'     => (string)($ins['street_name'] ?? ''),
            'distance_m' => (float)($ins['distance'] ?? 0),
            'time_s'     => (float)($ins['time'] ?? 0) / 1000.0,
            'glyph'      => sign_glyph((int)($ins['sign'] ?? 0)),
            'i'          => (int)(($ins['interval'][0] ?? 0)),
        ];
    }

    out([
        'ok'         => true,
        'distance_m' => (float)($p['distance'] ?? 0),
        'distance_mi'=> round((float)($p['distance'] ?? 0) / M_PER_MI, 1),
        'time_s'     => (float)($p['time'] ?? 0) / 1000.0,
        'geometry'   => $p['points'] ?? null,        // GeoJSON LineString
        'bbox'       => $p['bbox'] ?? null,          // [minLon,minLat,maxLon,maxLat]
        'steps'      => $steps,
        'origin'     => ['lat' => $flat, 'lon' => $flon, 'source' => $src],
        'dest'       => ['lat' => $tlat, 'lon' => $tlon],
    ]);
}

if ($op === 'status') {
    $r = gh_get('/health');
    out(['ok' => true, 'engine' => $r['http'] === 200 ? 'up' : 'down', 'http' => $r['http']]);
}

fail('bad_input', 'Unknown operation.');
