<?php
declare(strict_types=1);

require_once __DIR__ . "/bootstrap.php";
requirePermission("view_global_activities", true);
$currentUser = currentUser();
$isBoss = canonicalRole($currentUser["rol"] ?? null) === ROLE_BOSS;

$today = date("Y-m-d");
$activities = loadJson("actividades.json", []);

function dashboardElapsedSeconds(array $activity): int
{
    $elapsed = (int)($activity["elapsedSeconds"] ?? (($activity["durationMinutes"] ?? 0) * 60));
    if (($activity["status"] ?? "") === "en_progreso" && !empty($activity["startedAt"])) {
        $startedAt = strtotime((string)$activity["startedAt"]);
        if ($startedAt !== false) {
            $elapsed += max(0, time() - $startedAt);
        }
    }

    return max(0, $elapsed);
}

function dashboardActivityIsForDay(array $activity, string $day): bool
{
    foreach (["assignedAt", "startedAt", "pausedAt", "endedAt", "dueAt"] as $field) {
        $value = trim((string)($activity[$field] ?? ""));
        if ($value !== "" && substr($value, 0, 10) === $day) {
            return true;
        }
    }

    // Mantener visibles las actividades que siguen ejecutándose aunque se
    // hayan asignado antes de la jornada actual.
    return in_array(($activity["status"] ?? ""), ["en_progreso", "pausada"], true);
}

$todayActivities = [];
$dayDeadTimeSeconds = 0;
foreach ($activities as $activity) {
    if (!is_array($activity) || !dashboardActivityIsForDay($activity, $today)) {
        continue;
    }
    $activity["elapsedSeconds"] = dashboardElapsedSeconds($activity);
    $deadTime = (int)($activity["totalDeadTimeSeconds"] ?? 0);
    if (($activity["status"] ?? "") === "pausada" && !empty($activity["pausedAt"])) {
        $pausedAt = strtotime((string)$activity["pausedAt"]);
        if ($pausedAt !== false) {
            $deadTime += max(0, time() - $pausedAt);
        }
    }
    $dayDeadTimeSeconds += $deadTime;
    $activity["totalDeadTimeSeconds"] = $deadTime;
    $dueTimestamp = strtotime((string)($activity["dueAt"] ?? ""));
    $activity["isOverdue"] = $dueTimestamp !== false
        && $dueTimestamp < time()
        && in_array(($activity["status"] ?? ""), ["pendiente", "pausada", "en_progreso"], true);
    if (!$isBoss) {
        unset($activity["totalDeadTimeSeconds"], $activity["deadTimeEntries"]);
    }
    $todayActivities[] = $activity;
}

$statusCounts = [
    "pendiente" => 0,
    "en_progreso" => 0,
    "pausada" => 0,
    "completada" => 0
];
$totalElapsedSeconds = 0;
$activityTypeCounts = ["asignada" => 0, "espontanea" => 0];
foreach ($todayActivities as $activity) {
    $status = (string)($activity["status"] ?? "pendiente");
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
    $totalElapsedSeconds += (int)$activity["elapsedSeconds"];
    $activityType = ($activity["activityType"] ?? "asignada") === "espontanea"
        ? "espontanea"
        : "asignada";
    $activityTypeCounts[$activityType]++;
}

$total = count($todayActivities);
$teamActivities = array_values(array_filter(
    $todayActivities,
    static fn(array $activity): bool => ($activity["assignedTo"] ?? "") !== ($currentUser["nombre"] ?? "")
));
jsonResponse([
    "date" => $today,
    "updatedAt" => date(DATE_ATOM),
    "metrics" => [
        "total" => $total,
        "pendientes" => $statusCounts["pendiente"],
        "en_progreso" => $statusCounts["en_progreso"],
        "pausadas" => $statusCounts["pausada"],
        "completadas" => $statusCounts["completada"],
        "total_elapsed_seconds" => $totalElapsedSeconds,
        "completion_percentage" => $total > 0
            ? round(($statusCounts["completada"] / $total) * 100, 1)
            : 0,
        "activity_types" => $activityTypeCounts,
        "time_distribution_percent" => ($totalElapsedSeconds + $dayDeadTimeSeconds) > 0
            ? [
                "worked" => round($totalElapsedSeconds / ($totalElapsedSeconds + $dayDeadTimeSeconds) * 100, 1),
                "dead" => round($dayDeadTimeSeconds / ($totalElapsedSeconds + $dayDeadTimeSeconds) * 100, 1)
            ]
            : ["worked" => 0, "dead" => 0],
        "total_dead_time_seconds" => $isBoss ? $dayDeadTimeSeconds : null
    ],
    "activities" => $todayActivities,
    "teamActivities" => $teamActivities
]);
