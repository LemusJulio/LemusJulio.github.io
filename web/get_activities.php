<?php
require_once __DIR__ . "/bootstrap.php";
if (!isset($_SESSION["user"])) {
    jsonResponse(["error" => "No autorizado"], 401);
}

$user = $_SESSION["user"];
$activities = loadJson("actividades.json", []);
$ownActivitiesOnly = ($_GET["scope"] ?? "") === "own";

if ($ownActivitiesOnly) {
    requirePermission("view_own_activities", true);
}

foreach ($activities as &$activity) {
    $elapsedSeconds = (int)($activity["elapsedSeconds"] ?? (($activity["pausedTime"] ?? 0) * 60));
    if (($activity["status"] ?? "") === "en_progreso" && !empty($activity["startedAt"])) {
        $startedAt = new DateTime($activity["startedAt"]);
        $elapsedSeconds += max(0, time() - $startedAt->getTimestamp());
    }
    $activity["elapsedSeconds"] = $elapsedSeconds;
    $deadTime = (int)($activity["totalDeadTimeSeconds"] ?? 0);
    if (($activity["status"] ?? "") === "pausada" && !empty($activity["pausedAt"])) {
        $pausedAt = strtotime((string)$activity["pausedAt"]);
        if ($pausedAt !== false) {
            $deadTime += max(0, time() - $pausedAt);
        }
    }
    if (canonicalRole($user["rol"] ?? null) === ROLE_WORKER
        && ($activity["assignedTo"] ?? "") === ($user["nombre"] ?? "")) {
        $activity["currentDeadTimeSeconds"] = $deadTime;
    } else {
        unset($activity["currentDeadTimeSeconds"]);
    }
    if (canonicalRole($user["rol"] ?? null) !== ROLE_BOSS) {
        unset($activity["totalDeadTimeSeconds"], $activity["deadTimeEntries"]);
    }
}
unset($activity);

// Filtrar actividades según el rol del usuario
if ($ownActivitiesOnly || canonicalRole($user["rol"] ?? null) === ROLE_WORKER) {
    $filteredActivities = array_filter($activities, function($a) use ($user) {
        return ($a["assignedTo"] ?? "") === ($user["nombre"] ?? "");
    });
} else {
    // Líderes y jefes ven todas las actividades
    $filteredActivities = $activities;
}

jsonResponse(array_values($filteredActivities));
?>