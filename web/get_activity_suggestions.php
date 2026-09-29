<?php
require_once __DIR__ . "/bootstrap.php";
requirePermission("view_dashboard", true);

$query = mb_strtolower(trim((string)($_GET["q"] ?? "")));
if (mb_strlen($query) < 2) {
    jsonResponse(["suggestions" => []]);
}
$catalog = loadJson("catalogo_actividades.json", []);
$matches = array_values(array_filter($catalog, static function (array $item) use ($query): bool {
    $haystack = mb_strtolower(implode(" ", [
        (string)($item["title"] ?? ""),
        (string)($item["category"] ?? ""),
        implode(" ", $item["keywords"] ?? [])
    ]));
    return mb_strpos($haystack, $query) !== false;
}));
usort($matches, static fn(array $a, array $b): int => (int)($b["usageCount"] ?? 0) <=> (int)($a["usageCount"] ?? 0));
jsonResponse(["suggestions" => array_slice($matches, 0, 5)]);
