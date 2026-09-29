<?php
require_once __DIR__ . "/bootstrap.php";
requirePermission("view_dashboard");

$user = currentUser();
$usersData = loadJson("datos.json", ["usuarios" => []]);
$assignableUsers = array_values(array_filter(
    $usersData["usuarios"] ?? [],
    static fn(array $candidate): bool => canonicalRole($candidate["rol"] ?? null) !== ROLE_BOSS
));
$assignableNames = array_values(array_filter(array_map(
    static fn(array $candidate): string => trim((string)($candidate["nombre"] ?? "")),
    $assignableUsers
)));
$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verifyCsrf($_POST["csrf_token"] ?? null)) {
        $errors[] = "La sesión del formulario expiró. Intenta de nuevo.";
    } else {
        $title = trim((string)($_POST["title"] ?? ""));
        $description = trim((string)($_POST["description"] ?? ""));
        $assignees = $_POST["assignee"] ?? [$user["nombre"]];
        $assignees = is_array($assignees)
            ? array_values(array_unique(array_filter(array_map("trim", $assignees))))
            : [trim((string)$assignees)];
        if ($title === "") $errors[] = "El título es obligatorio.";
        if (empty($assignees)) $errors[] = "Selecciona al menos una persona.";
        foreach ($assignees as $assignee) {
            if (!in_array($assignee, $assignableNames, true)) {
                $errors[] = "Una persona seleccionada no es válida.";
                break;
            }
        }

        if (!$errors) {
            $activities = loadJson("actividades.json", []);
            $groupId = bin2hex(random_bytes(8));
            foreach ($assignees as $assignee) {
                do {
                    $id = random_int(1, 9007199254740991);
                } while (array_search($id, array_column($activities, "id"), true) !== false);
                $activities[] = [
                    "id" => $id,
                    "groupId" => $groupId,
                    "title" => $title,
                    "description" => $description,
                    "activityType" => "espontanea",
                    "priority" => "media",
                    "originalPriority" => "media",
                    "assignedTo" => $assignee,
                    "assignedBy" => $user["nombre"],
                    "assignedAt" => date("Y-m-d H:i:s"),
                    "project" => "",
                    "projectFactor" => 1,
                    "dueAt" => null,
                    "status" => "pendiente",
                    "startedAt" => null,
                    "endedAt" => null,
                    "pausedTime" => 0,
                    "elapsedSeconds" => 0,
                    "durationMinutes" => 0,
                    "totalDeadTimeSeconds" => 0,
                    "deadTimeEntries" => [],
                    "note" => ""
                ];
            }
            saveJson("actividades.json", $activities);
            $catalog = loadJson("catalogo_actividades.json", []);
            $catalogIndex = null;
            foreach ($catalog as $index => $item) {
                if (mb_strtolower((string)($item["title"] ?? "")) === mb_strtolower($title)) {
                    $catalogIndex = $index;
                    break;
                }
            }
            if ($catalogIndex === null) {
                $catalog[] = [
                    "title" => $title,
                    "category" => "Sin categoría",
                    "usageCount" => 1,
                    "lastUsedAt" => date("Y-m-d H:i:s"),
                    "keywords" => preg_split("/\s+/", mb_strtolower($title), -1, PREG_SPLIT_NO_EMPTY)
                ];
            } else {
                $catalog[$catalogIndex]["usageCount"] = (int)($catalog[$catalogIndex]["usageCount"] ?? 0) + 1;
                $catalog[$catalogIndex]["lastUsedAt"] = date("Y-m-d H:i:s");
            }
            saveJson("catalogo_actividades.json", $catalog);
            $notifications = loadJson("notificaciones.json", []);
            foreach ($assignees as $assignee) {
                $notifications[] = [
                    "id" => bin2hex(random_bytes(12)),
                    "type" => "assignment",
                    "recipient" => $assignee,
                    "message" => sprintf("Se te asignó la actividad espontánea \"%s\".", $title),
                    "activityTitle" => $title,
                    "worker" => $assignee,
                    "createdAt" => date("Y-m-d H:i:s"),
                    "createdAtUnix" => time()
                ];
            }
            $notifications[] = [
                "id" => bin2hex(random_bytes(12)),
                "type" => "spontaneous",
                "recipientRoles" => [ROLE_LEADER, ROLE_BOSS],
                "message" => sprintf("%s creó la actividad espontánea \"%s\".", $user["nombre"], $title),
                "activityTitle" => $title,
                "worker" => $user["nombre"],
                "createdAt" => date("Y-m-d H:i:s"),
                "createdAtUnix" => time()
            ];
            saveJson("notificaciones.json", array_slice($notifications, -200));
            header("Location: espontanea.php?success=1");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Actividad espontánea</title>
    <link rel="stylesheet" href="css/style.css">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, "UTF-8"); ?>">
</head>
<body>
<div class="container">
    <?php require __DIR__ . "/partials/sidebar.php"; ?>
    <main>
        <header class="page-header">
            <h1>Nueva actividad espontánea</h1>
            <p>Crea rápidamente una actividad que surgió durante la jornada.</p>
        </header>
        <?php if (isset($_GET["success"])): ?><div class="alert alert-success">Actividad creada correctamente.</div><?php endif; ?>
        <?php if ($errors): ?><div class="alert alert-error"><?php echo htmlspecialchars(implode(" ", $errors), ENT_QUOTES, "UTF-8"); ?></div><?php endif; ?>
        <form method="post" class="form-card compact-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, "UTF-8"); ?>">
            <label for="title">¿Qué actividad necesitas realizar?</label>
            <input id="title" name="title" required maxlength="160" autocomplete="off">
            <div id="activity-suggestions" class="activity-suggestions" aria-live="polite"></div>
            <label for="description">Descripción breve (opcional)</label>
            <textarea id="description" name="description" rows="3" maxlength="1000"></textarea>
            <fieldset>
                <legend>Participantes</legend>
                <?php foreach ($assignableNames as $name): ?>
                    <label class="checkbox-option">
                        <input type="checkbox" name="assignee[]" value="<?php echo htmlspecialchars($name, ENT_QUOTES, "UTF-8"); ?>" <?php echo $name === $user["nombre"] ? "checked" : ""; ?>>
                        <?php echo htmlspecialchars($name, ENT_QUOTES, "UTF-8"); ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <button type="submit" class="btn btn-primary">Crear actividad</button>
        </form>
    </main>
</div>
<script src="js/activity_suggestions.js"></script>
</body>
</html>
