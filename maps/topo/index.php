<?php
header("Content-Type: application/json");
$files = glob(__DIR__ . "/*.pdf");
$out = [];
foreach ($files as $f) {
    $name = basename($f, ".pdf");
    $display = ucwords(str_replace("_", " ", $name));
    $out[] = ["name" => $display, "url" => "/maps/topo/" . basename($f)];
}
usort($out, fn($a,$b) => strcmp($a["name"], $b["name"]));
echo json_encode($out);
