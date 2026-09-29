<?php
require_once __DIR__ . "/bootstrap.php";
if (!isset($_SESSION["user"])) {
    jsonResponse(["error" => "No autorizado"], 401);
}
requirePermission("use_timer", true);

$activities = loadJson("actividades.json", []);
$user = $_SESSION["user"];

// Buscar actividad activa para este usuario
$activeActivity = null;
foreach ($activities as $activity) {
    if ($activity["assignedTo"] === $user["nombre"] &&
        in_array($activity["status"], ["en_progreso", "pausada"], true)) {
        $activeActivity = $activity;
        break;
    }
}

if ($activeActivity) {
    $elapsedSeconds = (int)($activeActivity["elapsedSeconds"] ?? (($activeActivity["pausedTime"] ?? 0) * 60));
    if ($activeActivity["status"] === "en_progreso" && !empty($activeActivity["startedAt"])) {
        $elapsedSeconds += max(0, time() - (new DateTime($activeActivity["startedAt"]))->getTimestamp());
    }
    jsonResponse([
        "active" => true,
        "activityId" => $activeActivity["id"],
        "running" => $activeActivity["status"] === "en_progreso",
        "elapsedSeconds" => $elapsedSeconds
    ]);
} else {
    jsonResponse(["active" => false]);
}
?>