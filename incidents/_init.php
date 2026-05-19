<?php
// Shared init for /incidents/ module — DB schema, constants, helpers.
// Loaded by index.php, api.php, export.php.

require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
require_once '/var/www/noosphere/shared/identity.php';

function incidents_db(): PDO {
    static $db = null;
    if ($db) return $db;
    $db = new PDO('sqlite:/var/lib/noosphere/incidents.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec("CREATE TABLE IF NOT EXISTS incidents (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        submitted_at    INTEGER NOT NULL,
        updated_at      INTEGER NOT NULL,
        type            TEXT NOT NULL DEFAULT 'general',
        severity        TEXT,
        title           TEXT NOT NULL,
        description     TEXT,
        location_text   TEXT,
        lat             REAL,
        lng             REAL,
        reporter_name   TEXT,
        creator_token   TEXT,
        photo_path      TEXT,
        status          TEXT NOT NULL DEFAULT 'open',
        assigned_to     TEXT,
        resolved_at     INTEGER,
        resolved_by     TEXT,
        meta            TEXT
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_incidents_status ON incidents(status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_incidents_type ON incidents(type)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_incidents_submitted ON incidents(submitted_at DESC)");
    return $db;
}

const INCIDENT_TYPES = [
    'damage'   => 'Damage',
    'medical'  => 'Medical',
    'hazard'   => 'Hazard',
    'missing'  => 'Missing Person',
    'resource' => 'Resource',
    'general'  => 'General / Observation',
];

const INCIDENT_SEVERITIES = [
    'critical' => 'Critical',
    'serious'  => 'Serious',
    'minor'    => 'Minor',
    'info'     => 'Informational',
];

const INCIDENT_STATUSES = [
    'open'         => 'Open',
    'acknowledged' => 'Acknowledged',
    'resolved'     => 'Resolved',
];

const INCIDENT_TYPE_COLORS = [
    'damage'   => '#e67e22',
    'medical'  => '#e94560',
    'hazard'   => '#f39c12',
    'missing'  => '#9b59b6',
    'resource' => '#2ecc71',
    'general'  => '#7aa7d9',
];

const INCIDENT_SEVERITY_COLORS = [
    'critical' => '#e94560',
    'serious'  => '#e67e22',
    'minor'    => '#f39c12',
    'info'     => '#7aa7d9',
];

const INCIDENT_PHOTO_DIR = '/var/lib/noosphere/incident_photos';

// Damage-specific (used when type=damage; stored in meta JSON)
const DAMAGE_STRUCTURE_TYPES = [
    'residence'      => 'Residence',
    'commercial'     => 'Commercial',
    'agricultural'   => 'Agricultural',
    'infrastructure' => 'Infrastructure',
    'other'          => 'Other',
];
const DAMAGE_LEVELS = [
    'none'         => 'None / Undamaged',
    'minor'        => 'Minor',
    'major'        => 'Major',
    'destroyed'    => 'Destroyed',
    'inaccessible' => 'Inaccessible',
];
const DAMAGE_ACCOUNTED = ['yes'=>'Yes','no'=>'No','unknown'=>'Unknown'];
const DAMAGE_UTILITIES = ['electric'=>'Electric','gas'=>'Gas','water'=>'Water'];

function damage_level_to_severity(?string $lvl): ?string {
    return match($lvl) {
        'destroyed'    => 'critical',
        'major'        => 'serious',
        'inaccessible' => 'serious',
        'minor'        => 'minor',
        'none'         => 'info',
        default        => null,
    };
}

function incident_collect_damage_meta(array $post): array {
    $meta = [];
    $st = $post['damage_structure_type'] ?? '';
    if (isset(DAMAGE_STRUCTURE_TYPES[$st])) $meta['structure_type'] = $st;
    $dl = $post['damage_level'] ?? '';
    if (isset(DAMAGE_LEVELS[$dl])) $meta['damage_level'] = $dl;
    $ac = $post['damage_accounted'] ?? '';
    if (isset(DAMAGE_ACCOUNTED[$ac])) $meta['occupants_accounted'] = $ac;
    if (strlen(trim((string)($post['damage_occupant_count'] ?? '')))) {
        $meta['occupant_count'] = (int)$post['damage_occupant_count'];
    }
    $utils = array_values(array_intersect(array_keys(DAMAGE_UTILITIES), (array)($post['damage_utilities'] ?? [])));
    if ($utils) $meta['utilities_affected'] = $utils;
    $hz = trim((string)($post['damage_hazards'] ?? ''));
    if ($hz !== '') $meta['hazards'] = $hz;
    return $meta;
}

function incidents_command_mode(): bool {
    return get_setting('show_incidents_command', '0') === '1';
}

function incident_marker_color(array $r): string {
    if (incidents_command_mode() && !empty($r['severity']) && isset(INCIDENT_SEVERITY_COLORS[$r['severity']])) {
        return INCIDENT_SEVERITY_COLORS[$r['severity']];
    }
    return INCIDENT_TYPE_COLORS[$r['type']] ?? '#7aa7d9';
}

function incident_process_photo(string $tmp): ?string {
    if (!is_dir(INCIDENT_PHOTO_DIR)) @mkdir(INCIDENT_PHOTO_DIR, 0750, true);
    $info = @getimagesize($tmp);
    if (!$info) return null;
    $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
    if (!in_array($info['mime'], $allowed)) return null;

    $src = match($info['mime']) {
        'image/jpeg' => @imagecreatefromjpeg($tmp),
        'image/png'  => @imagecreatefrompng($tmp),
        'image/webp' => @imagecreatefromwebp($tmp),
        'image/gif'  => @imagecreatefromgif($tmp),
        default      => false,
    };
    if (!$src) return null;

    if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmp);
        $orient = $exif['Orientation'] ?? 1;
        $src = match((int)$orient) {
            3 => imagerotate($src, 180, 0),
            6 => imagerotate($src, -90, 0),
            8 => imagerotate($src,  90, 0),
            default => $src,
        };
    }

    $w = imagesx($src); $h = imagesy($src); $max = 1600;
    if ($w > $max || $h > $max) {
        if ($w >= $h) { $nw = $max; $nh = (int)round($h * $max / $w); }
        else          { $nh = $max; $nw = (int)round($w * $max / $h); }
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        $src = $dst;
    }

    $fname = bin2hex(random_bytes(8)) . '.jpg';
    $dest = INCIDENT_PHOTO_DIR . '/' . $fname;
    if (!imagejpeg($src, $dest, 82)) { imagedestroy($src); return null; }
    imagedestroy($src);
    return $fname;
}
