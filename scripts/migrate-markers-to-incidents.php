<?php
// Migrate /maps/markers (map_markers.db) into /incidents/.
//
// Idempotent: each row gets meta.legacy_marker_id; re-runs skip imported.
// Photos copy from /var/lib/noosphere/marker_photos/ to incident_photos/.
// creator_token preserved so anon users keep delete rights.
//
// Type mapping:
//   pin      -> general
//   search   -> missing
//   camp     -> resource  (title prefixed "Camp: ")
//   hazard   -> hazard
//   medical  -> medical
//   resource -> resource
//   blocked  -> hazard    (title prefixed "Road blocked: ")
//
// Usage: php /var/www/noosphere/scripts/migrate-markers-to-incidents.php

require_once __DIR__ . '/../incidents/_init.php';

$MARKERS_DB     = '/var/lib/noosphere/map_markers.db';
$MARKERS_PHOTOS = '/var/lib/noosphere/marker_photos';

if (!file_exists($MARKERS_DB)) {
    fwrite(STDERR, "No map_markers.db at $MARKERS_DB  -  nothing to migrate.\n");
    exit(0);
}

$inc = incidents_db();
$mk  = new PDO('sqlite:' . $MARKERS_DB);
$mk->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (!is_dir(INCIDENT_PHOTO_DIR)) mkdir(INCIDENT_PHOTO_DIR, 0750, true);

$migrated = [];
$rs = $inc->query("SELECT meta FROM incidents WHERE meta IS NOT NULL");
foreach ($rs as $row) {
    $m = json_decode($row['meta'], true);
    if (is_array($m) && isset($m['legacy_marker_id'])) {
        $migrated[(int)$m['legacy_marker_id']] = true;
    }
}
fwrite(STDOUT, "Already migrated: " . count($migrated) . " marker rows.\n");

$TYPE_MAP = [
    'pin'      => ['type' => 'general',  'title_prefix' => ''],
    'search'   => ['type' => 'missing',  'title_prefix' => ''],
    'camp'     => ['type' => 'resource', 'title_prefix' => 'Camp: '],
    'hazard'   => ['type' => 'hazard',   'title_prefix' => ''],
    'medical'  => ['type' => 'medical',  'title_prefix' => ''],
    'resource' => ['type' => 'resource', 'title_prefix' => ''],
    'blocked'  => ['type' => 'hazard',   'title_prefix' => 'Road blocked: '],
];

$rows = $mk->query("SELECT * FROM markers ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
fwrite(STDOUT, "Source marker rows: " . count($rows) . "\n");

$ins = $inc->prepare("INSERT INTO incidents
    (submitted_at, updated_at, type, severity, title, description, location_text,
     lat, lng, reporter_name, creator_token, photo_path, status, meta)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

$new_count = 0; $photo_count = 0;

foreach ($rows as $r) {
    $legacy_id = (int)$r['id'];
    if (isset($migrated[$legacy_id])) continue;

    $mt = $r['mtype'] ?? 'pin';
    $cfg = $TYPE_MAP[$mt] ?? $TYPE_MAP['pin'];
    $title = $cfg['title_prefix'] . trim((string)($r['title'] ?? ''));
    if ($title === '') $title = 'Map marker #' . $legacy_id;

    $new_photo = null;
    if (!empty($r['photo'])) {
        $src = $MARKERS_PHOTOS . '/' . basename($r['photo']);
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
                $new_photo = null;
            }
        }
    }

    $meta = ['legacy_marker_id' => $legacy_id, 'legacy_mtype' => $mt];

    $created = (int)($r['created_at'] ?? time());
    $ins->execute([
        $created, $created,
        $cfg['type'], null,
        $title,
        !empty($r['note']) ? $r['note'] : null,
        null,
        ($r['lat'] !== null && $r['lat'] !== '') ? (float)$r['lat'] : null,
        ($r['lng'] !== null && $r['lng'] !== '') ? (float)$r['lng'] : null,
        !empty($r['created_by']) ? $r['created_by'] : null,
        $r['creator_token'] ?? bin2hex(random_bytes(16)),
        $new_photo,
        'open',
        json_encode($meta, JSON_UNESCAPED_SLASHES),
    ]);
    $new_count++;
}

fwrite(STDOUT, "Migrated $new_count new marker rows ($photo_count photos copied).\n");
fwrite(STDOUT, "Done.\n");
