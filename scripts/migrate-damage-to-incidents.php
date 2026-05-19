<?php
// Migrate /damage/ records into /incidents/.
//
// Idempotent: each incoming damage row gets meta.legacy_damage_id; re-runs
// skip rows already imported. Photos are copied into the incidents photo
// dir under new filenames. Settings: copies show_damage -> show_incidents
// for any preset value that is currently '1' (unless show_incidents is set).
//
// Usage:  php /var/www/noosphere/scripts/migrate-damage-to-incidents.php
//
// Run as root (needs write access to /var/lib/noosphere/incidents.db and
// to /var/lib/noosphere/incident_photos/)  -  the systemd-managed nginx/php
// runs as www-data so make sure perms allow www-data to keep using the
// resulting files (script chowns photos to www-data:www-data).

require_once __DIR__ . '/../incidents/_init.php';

$DAMAGE_DB     = '/var/lib/noosphere/damage.db';
$DAMAGE_PHOTOS = '/var/lib/noosphere/damage_photos';
$SETTINGS_DB   = '/var/lib/noosphere/settings.db';

if (!file_exists($DAMAGE_DB)) {
    fwrite(STDERR, "No damage.db at $DAMAGE_DB  -  nothing to migrate.\n");
    exit(0);
}

$inc = incidents_db();
$dmg = new PDO('sqlite:' . $DAMAGE_DB);
$dmg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Ensure photo dir exists
if (!is_dir(INCIDENT_PHOTO_DIR)) mkdir(INCIDENT_PHOTO_DIR, 0750, true);

// Collect already-migrated legacy IDs
$migrated = [];
$rs = $inc->query("SELECT meta FROM incidents WHERE type = 'damage' AND meta IS NOT NULL");
foreach ($rs as $row) {
    $m = json_decode($row['meta'], true);
    if (is_array($m) && isset($m['legacy_damage_id'])) {
        $migrated[(int)$m['legacy_damage_id']] = true;
    }
}
fwrite(STDOUT, "Already migrated: " . count($migrated) . " damage rows.\n");

// Pull every damage row
$rows = $dmg->query("SELECT * FROM reports ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
fwrite(STDOUT, "Source damage rows: " . count($rows) . "\n");

$ins = $inc->prepare("INSERT INTO incidents
    (submitted_at, updated_at, type, severity, title, description, location_text,
     lat, lng, reporter_name, creator_token, photo_path, status, meta)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

$new_count = 0; $photo_count = 0;

foreach ($rows as $r) {
    $legacy_id = (int)$r['id'];
    if (isset($migrated[$legacy_id])) continue;

    $level = $r['damage_level'] ?? null;
    $severity = damage_level_to_severity($level);

    $address = trim((string)($r['address'] ?? ''));
    $title = $address !== ''
        ? mb_strimwidth($address, 0, 100, '…')
        : ('Damage report #' . $legacy_id);
    if ($level && isset(DAMAGE_LEVELS[$level])) {
        $title = DAMAGE_LEVELS[$level] . ': ' . $title;
    }

    $meta = [
        'legacy_damage_id' => $legacy_id,
    ];
    if ($level && isset(DAMAGE_LEVELS[$level]))                 $meta['damage_level']        = $level;
    if (!empty($r['structure_type']) && isset(DAMAGE_STRUCTURE_TYPES[$r['structure_type']]))
        $meta['structure_type']      = $r['structure_type'];
    if (!empty($r['occupants_accounted']) && isset(DAMAGE_ACCOUNTED[$r['occupants_accounted']]))
        $meta['occupants_accounted'] = $r['occupants_accounted'];
    if (isset($r['occupant_count']) && $r['occupant_count'] !== null && $r['occupant_count'] !== '')
        $meta['occupant_count']      = (int)$r['occupant_count'];
    if (!empty($r['utilities_affected'])) {
        $u = array_values(array_filter(explode(',', $r['utilities_affected'])));
        if ($u) $meta['utilities_affected'] = $u;
    }
    if (!empty($r['hazards'])) $meta['hazards'] = $r['hazards'];

    // Copy photo
    $new_photo = null;
    if (!empty($r['photo_path'])) {
        $src = $DAMAGE_PHOTOS . '/' . basename($r['photo_path']);
        if (is_file($src)) {
            $ext = pathinfo($src, PATHINFO_EXTENSION) ?: 'jpg';
            $new_photo = bin2hex(random_bytes(8)) . '.' . $ext;
            $dst = INCIDENT_PHOTO_DIR . '/' . $new_photo;
            if (copy($src, $dst)) {
                @chown($dst, 'www-data');
                @chgrp($dst, 'www-data');
                @chmod($dst, 0640);
                $photo_count++;
            } else {
                fwrite(STDERR, "  could not copy photo for legacy id $legacy_id\n");
                $new_photo = null;
            }
        }
    }

    $submitted = (int)($r['submitted_at'] ?? time());
    $ins->execute([
        $submitted,
        $submitted,
        'damage',
        $severity,
        $title,
        !empty($r['notes']) ? $r['notes'] : null,
        $address !== '' ? $address : null,
        ($r['lat'] !== null && $r['lat'] !== '') ? (float)$r['lat'] : null,
        ($r['lng'] !== null && $r['lng'] !== '') ? (float)$r['lng'] : null,
        !empty($r['reporter_name']) ? $r['reporter_name'] : null,
        bin2hex(random_bytes(16)),
        $new_photo,
        'open',
        json_encode($meta, JSON_UNESCAPED_SLASHES),
    ]);
    $new_count++;
}

fwrite(STDOUT, "Migrated $new_count new damage rows ($photo_count photos copied).\n");

// ── Settings migration ──────────────────────────────────────────────────────
if (file_exists($SETTINGS_DB)) {
    $sdb = new PDO('sqlite:' . $SETTINGS_DB);
    $sdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $cur_inc = $sdb->query("SELECT value FROM settings WHERE key='show_incidents'")->fetchColumn();
    $cur_dmg = $sdb->query("SELECT value FROM settings WHERE key='show_damage'")->fetchColumn();

    if ($cur_dmg === '1' && $cur_inc !== '1') {
        $sdb->prepare("INSERT INTO settings (key,value) VALUES ('show_incidents','1')
                       ON CONFLICT(key) DO UPDATE SET value='1'")->execute();
        fwrite(STDOUT, "Settings: show_damage=1 found; set show_incidents=1.\n");
    } else {
        fwrite(STDOUT, "Settings: no migration needed (show_damage=" . var_export($cur_dmg, true)
            . ", show_incidents=" . var_export($cur_inc, true) . ").\n");
    }

    // Always disable show_damage now that /damage/ redirects
    $sdb->prepare("INSERT INTO settings (key,value) VALUES ('show_damage','0')
                   ON CONFLICT(key) DO UPDATE SET value='0'")->execute();
    fwrite(STDOUT, "Settings: show_damage forced to 0 (the old module is now a redirect).\n");
} else {
    fwrite(STDERR, "No settings.db at $SETTINGS_DB  -  skipped settings migration.\n");
}

fwrite(STDOUT, "Done.\n");
