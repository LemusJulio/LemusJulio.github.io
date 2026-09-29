<?php
require_once __DIR__ . "/bootstrap.php";
if (!isset($_SESSION["user"])) {
    jsonResponse(["success" => false, "message" => "No autorizado"], 401);
}
requirePermission("use_timer", true);

$user = $_SESSION["user"];
$action = $_POST["action"] ?? "";
$activityId = $_POST["activityId"] ?? "";
$activities = loadJson("actividades.json", []);

if ($_SERVER["REQUEST_METHOD"] !== "POST" || !verifyCsrf($_POST["csrf_token"] ?? null)) {
    jsonResponse(["success" => false, "message" => "Solicitud no válida."], 403);
}
if ($action === "" || $activityId === "") {
    jsonResponse(["success" => false, "message" => "Faltan parámetros"], 400);
}

// Buscar la actividad
$activityIndex = null;
foreach ($activities as $index => $activity) {
    if ((string)$activity["id"] === (string)$activityId) {
        $activityIndex = $index;
        break;
    }
}

if ($activityIndex === null) {
    jsonResponse(["success" => false, "message" => "Actividad no encontrada"], 404);
}

$activity = &$activities[$activityIndex];
$storedElapsedSeconds = (int)($activity["elapsedSeconds"] ?? (($activity["pausedTime"] ?? 0) * 60));
$activity["deadTimeEntries"] = is_array($activity["deadTimeEntries"] ?? null)
    ? $activity["deadTimeEntries"]
    : [];
$activity["totalDeadTimeSeconds"] = (int)($activity["totalDeadTimeSeconds"] ?? 0);

// Validar que el usuario pueda modificar esta actividad
if ($activity["assignedTo"] !== $user["nombre"]) {
    jsonResponse(["success" => false, "message" => "No estás asignado a esta actividad"], 403);
}

// Procesar la acción
switch ($action) {
    case "start":
        if ($activity["status"] !== "pendiente" && $activity["status"] !== "pausada") {
            jsonResponse(["success" => false, "message" => "No se puede iniciar esta actividad"], 409);
        }
        foreach ($activities as $otherActivity) {
            if ($otherActivity["assignedTo"] === $user["nombre"]
                && $otherActivity["id"] != $activity["id"]
                && $otherActivity["status"] === "en_progreso") {
                jsonResponse(["success" => false, "message" => "Ya tienes otra actividad en progreso."], 409);
            }
        }

        $activity["status"] = "en_progreso";
        $activity["startedAt"] = date("Y-m-d H:i:s");
        $activity["elapsedSeconds"] = $storedElapsedSeconds;
        $activity["pausedTime"] = intdiv($storedElapsedSeconds, 60);
        $activity["endedAt"] = null;
        $activity["pausedAt"] = null;
        break;

    case "pause":
        if ($activity["status"] !== "en_progreso") {
            jsonResponse(["success" => false, "message" => "No se puede pausar esta actividad"], 409);
        }

        $activity["status"] = "pausada";
        $activity["endedAt"] = date("Y-m-d H:i:s");

        // Acumular segundos para no perder el tiempo entre pausas.
        $start = new DateTime($activity["startedAt"]);
        $end = new DateTime();
        $durationSeconds = max(0, $end->getTimestamp() - $start->getTimestamp());
        $storedElapsedSeconds += $durationSeconds;
        $activity["elapsedSeconds"] = $storedElapsedSeconds;
        $activity["pausedTime"] = intdiv($storedElapsedSeconds, 60);
        $activity["pausedAt"] = date("Y-m-d H:i:s");
        $reason = trim((string)($_POST["pauseReason"] ?? ""));
        $note = trim((string)($_POST["pauseNote"] ?? ""));
        if ($reason === "" || ($reason === "Otra razón" && $note === "")) {
            jsonResponse([
                "success" => false,
                "message" => $reason === ""
                    ? "Debes indicar el motivo de la pausa."
                    : "Describe el motivo seleccionado como otra razón."
            ], 422);
        }
        $activity["deadTimeEntries"][] = [
            "reason" => $reason,
            "note" => $note,
            "startedAt" => date("Y-m-d H:i:s"),
            "endedAt" => null,
            "durationSeconds" => 0
        ];
        break;

    case "resume":
        if ($activity["status"] !== "pausada") {
            jsonResponse(["success" => false, "message" => "No se puede reanudar esta actividad"], 409);
        }

        $activity["status"] = "en_progreso";
        $activity["startedAt"] = date("Y-m-d H:i:s");
        $activity["elapsedSeconds"] = $storedElapsedSeconds;
        $activity["pausedTime"] = intdiv($storedElapsedSeconds, 60);
        $activity["pausedAt"] = null;
        $lastEntryIndex = count($activity["deadTimeEntries"]) - 1;
        if ($lastEntryIndex >= 0 && empty($activity["deadTimeEntries"][$lastEntryIndex]["endedAt"])) {
            $deadStart = strtotime((string)$activity["deadTimeEntries"][$lastEntryIndex]["startedAt"]);
            $deadDuration = max(0, time() - ($deadStart ?: time()));
            $activity["deadTimeEntries"][$lastEntryIndex]["endedAt"] = date("Y-m-d H:i:s");
            $activity["deadTimeEntries"][$lastEntryIndex]["durationSeconds"] = $deadDuration;
            $activity["totalDeadTimeSeconds"] += $deadDuration;
        }
        $activity["endedAt"] = null;
        break;

    case "stop":
        if ($activity["status"] !== "en_progreso" && $activity["status"] !== "pausada") {
            jsonResponse(["success" => false, "message" => "No se puede terminar esta actividad"], 409);
        }

        $wasPaused = $activity["status"] === "pausada";
        $activity["status"] = "completada";
        $activity["endedAt"] = date("Y-m-d H:i:s");

        // Calcular duración total en segundos y conservar pausas anteriores.
        $durationSeconds = 0;
        if (!$wasPaused && $activity["startedAt"] !== null) {
            $start = new DateTime($activity["startedAt"]);
            $end = new DateTime();
            $durationSeconds = max(0, $end->getTimestamp() - $start->getTimestamp());
        }
        $storedElapsedSeconds += $durationSeconds;
        $activity["elapsedSeconds"] = $storedElapsedSeconds;
        $activity["pausedTime"] = intdiv($storedElapsedSeconds, 60);
        $activity["durationMinutes"] = round($storedElapsedSeconds / 60, 2);
        $lastEntryIndex = count($activity["deadTimeEntries"]) - 1;
        if ($wasPaused && $lastEntryIndex >= 0 && empty($activity["deadTimeEntries"][$lastEntryIndex]["endedAt"])) {
            $deadStart = strtotime((string)$activity["deadTimeEntries"][$lastEntryIndex]["startedAt"]);
            $deadDuration = max(0, time() - ($deadStart ?: time()));
            $activity["deadTimeEntries"][$lastEntryIndex]["endedAt"] = date("Y-m-d H:i:s");
            $activity["deadTimeEntries"][$lastEntryIndex]["durationSeconds"] = $deadDuration;
            $activity["totalDeadTimeSeconds"] += $deadDuration;
        }
        break;

    default:
        jsonResponse(["success" => false, "message" => "Acción no válida"], 400);
}

// Guardar cambios
saveJson("actividades.json", $activities);

$notificationAction = match ($action) {
    "start", "resume" => "inició",
    "pause" => "pausó",
    "stop" => "terminó",
    default => null
};
if ($notificationAction !== null) {
    $notifications = loadJson("notificaciones.json", []);
    $notifications[] = [
        "id" => bin2hex(random_bytes(12)),
        "type" => $action,
        "message" => sprintf(
            "%s %s la actividad \"%s\".",
            $user["nombre"],
            $notificationAction,
            $activity["title"] ?? "Sin título"
        ),
        "activityTitle" => $activity["title"] ?? "Sin título",
        "worker" => $user["nombre"],
        "recipientRoles" => [ROLE_LEADER, ROLE_BOSS],
        "createdAt" => date("Y-m-d H:i:s"),
        "createdAtUnix" => time()
    ];
    $notifications = array_slice($notifications, -200);
    saveJson("notificaciones.json", $notifications);
}

jsonResponse([
    "success" => true,
    "pausedTime" => $activity["pausedTime"] ?? 0,
    "elapsedSeconds" => $activity["elapsedSeconds"] ?? 0
]);
?>