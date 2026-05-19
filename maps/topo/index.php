<?php
require_once '/var/www/noosphere/shared/region.php';
header("Content-Type: application/json");
$dir = region_path('topo');
$url = region_url('topo');
$files = glob($dir . '/*.pdf') ?: [];
$out = [];
foreach ($files as $f) {
    $name = basename($f, ".pdf");
    $display = ucwords(str_replace("_", " ", $name));
    $out[] = ["name" => $display, "url" => $url . '/' . basename($f)];
}
usort($out, fn($a,$b) => strcmp($a["name"], $b["name"]));
echo json_encode($out);
