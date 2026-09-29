<?php
require_once __DIR__ . "/bootstrap.php";
requirePermission("view_notifications", true);

$notifications = loadJson("notificaciones.json", []);
$currentUser = currentUser();
$activities = loadJson("actividades.json", []);
$activitiesChanged = false;
foreach ($activities as &$activity) {
    $dueAt = strtotime((string)($activity["dueAt"] ?? ""));
    $isOpen = in_array($activity["status"] ?? "", ["pendiente", "pausada"], true);
    if ($dueAt === false || !$isOpen) {
        continue;
    }
    $newPriority = $dueAt <= time()
        ? "urgente"
        : ($dueAt <= time() + 86400 ? "alta" : ($activity["originalPriority"] ?? $activity["priority"] ?? "media"));
    if (($activity["priority"] ?? "") !== $newPriority) {
        $activity["priority"] = $newPriority;
        $activitiesChanged = true;
    }
    if ($dueAt > time() || !empty($activity["dueNotificationSentAt"])) {
        continue;
    }
    $notifications[] = [
        "id" => bin2hex(random_bytes(12)),
        "type" => "due",
        "message" => sprintf("La actividad \"%s\" de %s venció.", $activity["title"] ?? "Sin título", $activity["assignedTo"] ?? "Sin asignar"),
        "activityTitle" => $activity["title"] ?? "Sin título",
        "worker" => $activity["assignedTo"] ?? "Sin asignar",
        "recipient" => $activity["assignedTo"] ?? "",
        "recipientRoles" => [ROLE_LEADER, ROLE_BOSS],
        "createdAt" => date("Y-m-d H:i:s"),
        "createdAtUnix" => time()
    ];
    $activity["dueNotificationSentAt"] = date("Y-m-d H:i:s");
    $activitiesChanged = true;
}
unset($activity);
if ($activitiesChanged) {
    saveJson("actividades.json", $activities);
    $notifications = array_slice($notifications, -200);
    saveJson("notificaciones.json", $notifications);
}
$since = filter_var($_GET["since"] ?? 0, FILTER_VALIDATE_INT);
$since = $since === false ? 0 : max(0, $since);

$visibleNotifications = array_values(array_filter(
    $notifications,
    static function (array $notification) use ($currentUser, $since): bool {
        if ((int)($notification["createdAtUnix"] ?? 0) < $since) {
            return false;
        }

        $role = canonicalRole($currentUser["rol"] ?? null);
        $teamEvent = in_array($notification["type"] ?? "", ["start", "pause", "resume", "stop"], true);
        if ($teamEvent) {
            return in_array($role, [ROLE_LEADER, ROLE_BOSS], true);
        }

        return empty($notification["recipient"])
            || ($notification["recipient"] ?? "") === ($currentUser["nombre"] ?? "")
            || in_array($role, $notification["recipientRoles"] ?? [], true);
    }
));

jsonResponse([
    "notifications" => array_slice($visibleNotifications, -50),
    "serverTime" => time()
]);
