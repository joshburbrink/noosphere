<?php
/*
 * vehicle-log/image.php  -  streams a saved plate-crop JPEG for one sighting.
 *
 * Deviates from the /incident-photos/ convention (a public nginx alias) on
 * purpose: incident photos are voluntarily submitted by the reporter, but
 * these are photos of other people's vehicles captured automatically. They
 * are gated behind alpr.view on every request rather than served as static
 * files, and looked up by sighting id (not by filename) to avoid exposing
 * the on-disk naming scheme.
 */
require_once __DIR__ . '/_init.php';
sec_session_start();
alpr_require_access();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); exit; }

$stmt = alpr_db()->prepare('SELECT image_path FROM sightings WHERE id=?');
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row || empty($row['image_path'])) { http_response_code(404); exit; }

$path = ALPR_IMG_DIR . '/' . basename($row['image_path']);
if (!is_file($path)) { http_response_code(404); exit; }

header('Content-Type: image/jpeg');
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('Cache-Control: private, max-age=3600');
readfile($path);
