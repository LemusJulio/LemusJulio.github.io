<?php
require_once __DIR__ . "/bootstrap.php";
require_once __DIR__ . "/activity_recommendations.php";
if (!isset($_SESSION["user"])) {
    header("Location: login.php");
    exit;
}

$user = $_SESSION["user"];
requirePermission("view_global_activities");

// Cargar usuarios con validación
if (!file_exists("data")) {
    mkdir("data", 0755, true);
}

if (!file_exists("data/datos.json")) {
    file_put_contents(
        "data/datos.json",
        json_encode(["usuarios" => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

$users = loadJson("datos.json", ["usuarios" => []]);
if (!isset($users["usuarios"]) || !is_array($users["usuarios"])) {
    $users["usuarios"] = [];
}

$activities = loadJson("actividades.json", []);
$projectsData = loadJson("proyectos.json", ["proyectos" => []]);
$projects = is_array($projectsData["proyectos"] ?? null) ? $projectsData["proyectos"] : [];
$projectNames = array_values(array_filter(array_map(
    static fn(array $project): string => trim((string)($project["nombre"] ?? "")),
    $projects
)));
$projectFactors = [];
foreach ($projects as $project) {
    $projectName = trim((string)($project["nombre"] ?? ""));
    if ($projectName !== "") {
        $projectFactors[$projectName] = max(0.01, (float)($project["factor"] ?? 1));
    }
}

function normalizeDueAt(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === "") {
        return null;
    }
    $date = DateTime::createFromFormat("Y-m-d", $value)
        ?: DateTime::createFromFormat("Y-m-d\TH:i", $value);
    return $date ? $date->format("Y-m-d 23:59:59") : null;
}
$errors = [];
$archiveSuccess = false;

// Solo director y líder pueden crear o editar actividades
$canCreateOrEdit = can("manage_activities", $user);
$assignableUsers = array_filter($users["usuarios"], function($u) {
    return canonicalRole($u["rol"] ?? null) !== ROLE_BOSS;
});

// Archivar actividades completadas para despejar la lista sin perder historial.
if ($_SERVER["REQUEST_METHOD"] === "POST"
    && $canCreateOrEdit
    && ($_POST["action"] ?? "") === "archive_completed") {
    if (!verifyCsrf($_POST["csrf_token"] ?? null)) {
        $errors[] = "La sesión del formulario expiró. Intenta de nuevo.";
    } else {
        $completedToArchive = array_values(array_filter($activities, function($activity) {
            return ($activity["status"] ?? "") === "completada";
        }));

        if (empty($completedToArchive)) {
            $errors[] = "No hay actividades completadas para archivar.";
        } else {
            $archivedActivities = loadJson("actividades_archivadas.json", []);
            foreach ($completedToArchive as &$archivedActivity) {
                $archivedActivity["archivedAt"] = date("Y-m-d H:i:s");
                $archivedActivity["archivedBy"] = $user["nombre"];
            }
            unset($archivedActivity);

            $activities = array_values(array_filter($activities, function($activity) {
                return ($activity["status"] ?? "") !== "completada";
            }));
            saveJson("actividades_archivadas.json", array_merge($archivedActivities, $completedToArchive));
            saveJson("actividades.json", $activities);
            header("Location: actividades.php?archived=" . count($completedToArchive));
            exit;
        }
    }
}

// Procesar creación de actividad
if ($_SERVER["REQUEST_METHOD"] === "POST" && $canCreateOrEdit && !verifyCsrf($_POST["csrf_token"] ?? null)) {
    $errors[] = "La sesión del formulario expiró. Intenta de nuevo.";
}
if ($_SERVER["REQUEST_METHOD"] === "POST"
    && $canCreateOrEdit
    && ($_POST["action"] ?? "") === ""
    && empty($_POST["edit_id"])
    && verifyCsrf($_POST["csrf_token"] ?? null)) {
    $title = trim($_POST["title"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $priority = $_POST["priority"] ?? "media";
    $assignees = $_POST["assignee"] ?? [];
    $assignees = is_array($assignees) ? array_values(array_unique(array_map("trim", $assignees))) : [trim((string)$assignees)];
    $project = $_POST["project"] ?? "";
    $projectFactor = $projectFactors[$project] ?? 1.0;
    $dueAtInput = $_POST["due_at"] ?? "";
    $dueAt = normalizeDueAt($dueAtInput);

    $errors = [];
    if (empty($title)) $errors[] = "El título es obligatorio.";
    if (empty($assignees) || $assignees === [""]) $errors[] = "Debes asignar la actividad al menos a una persona.";
    if (!in_array($priority, ["baja", "media", "alta", "urgente"], true)) $errors[] = "La prioridad no es válida.";
    if ($project !== "" && !in_array($project, $projectNames, true)) $errors[] = "El proyecto seleccionado no es válido.";
    if ($dueAtInput !== "" && $dueAt === null) $errors[] = "La fecha límite no es válida.";
    $assignableNames = array_column($assignableUsers, "nombre");
    foreach ($assignees as $assignee) {
        if (!in_array($assignee, $assignableNames, true)) {
            $errors[] = "Uno de los usuarios asignados no es válido.";
            break;
        }
    }

    if (empty($errors)) {
        $groupId = bin2hex(random_bytes(8));
        foreach ($assignees as $assignee) {
            do {
                $newId = random_int(1, 9007199254740991);
            } while (array_search($newId, array_column($activities, "id"), true) !== false);

            $activities[] = [
                "id" => $newId,
                "groupId" => $groupId,
                "title" => $title,
                "description" => $description,
                "activityType" => "asignada",
                "priority" => $priority,
                "originalPriority" => $priority,
                "assignedTo" => $assignee,
                "assignedBy" => $user["nombre"],
                "assignedAt" => date("Y-m-d H:i:s"),
                "project" => $project,
                "projectFactor" => $projectFactor,
                "dueAt" => $dueAt,
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
        $notifications = loadJson("notificaciones.json", []);
        foreach ($assignees as $assignee) {
            $notifications[] = [
                "id" => bin2hex(random_bytes(12)),
                "type" => "assignment",
                "recipient" => $assignee,
                "message" => sprintf("Se te asignó la actividad \"%s\".", $title),
                "activityTitle" => $title,
                "worker" => $assignee,
                "createdAt" => date("Y-m-d H:i:s"),
                "createdAtUnix" => time()
            ];
        }
        saveJson("notificaciones.json", array_slice($notifications, -200));
        header("Location: actividades.php?success=1");
        exit;
    }
}

// Procesar edición de actividad
if ($_SERVER["REQUEST_METHOD"] === "POST" && $canCreateOrEdit && !empty($_POST["edit_id"]) && verifyCsrf($_POST["csrf_token"] ?? null)) {
    $editId = $_POST["edit_id"];
    $editIndex = null;
    foreach ($activities as $index => $activity) {
        if ((string)$activity["id"] === (string)$editId) {
            $editIndex = $index;
            break;
        }
    }

    if ($editIndex !== null) {
        $title = trim($_POST["title"] ?? "");
        $description = trim($_POST["description"] ?? "");
        $priority = $_POST["priority"] ?? "media";
        $assignees = $_POST["assignee"] ?? [];
        $assignees = is_array($assignees)
            ? array_values(array_unique(array_map("trim", $assignees)))
            : [trim((string)$assignees)];
        $assignee = count($assignees) === 1 ? $assignees[0] : "";
        $project = $_POST["project"] ?? "";
        $dueAtInput = $_POST["due_at"] ?? "";
        $dueAt = normalizeDueAt($dueAtInput);

        $errors = [];
        if (empty($title)) $errors[] = "El título es obligatorio.";
        if ($assignee === "") $errors[] = "Al editar, selecciona exactamente una persona.";
        if (!in_array($priority, ["baja", "media", "alta", "urgente"], true)) $errors[] = "La prioridad no es válida.";
        if ($project !== "" && !in_array($project, $projectNames, true)) $errors[] = "El proyecto seleccionado no es válido.";
        if ($dueAtInput !== "" && $dueAt === null) $errors[] = "La fecha límite no es válida.";
        if (!in_array($assignee, array_column($assignableUsers, "nombre"), true)) $errors[] = "El usuario asignado no es válido.";

        if (empty($errors)) {
            $activities[$editIndex]["title"] = $title;
            $activities[$editIndex]["description"] = $description;
            $activities[$editIndex]["priority"] = $priority;
            $activities[$editIndex]["originalPriority"] = $activities[$editIndex]["originalPriority"] ?? $priority;
            $activities[$editIndex]["assignedTo"] = $assignee;
            $activities[$editIndex]["project"] = $project;
            $activities[$editIndex]["projectFactor"] = $activities[$editIndex]["projectFactor"] ?? ($projectFactors[$project] ?? 1.0);
            $activities[$editIndex]["dueAt"] = $dueAt;
            saveJson("actividades.json", $activities);
            header("Location: actividades.php?success=2");
            exit;
        }
    }
}

$activityRecommendations = $canCreateOrEdit
    ? buildActivityRecommendations(
        $activities,
        loadJson("actividades_archivadas.json", []),
        (string)($user["nombre"] ?? "")
    )
    : [];

// Filtrar actividades según el rol
if (canonicalRole($user["rol"] ?? null) === ROLE_WORKER) {
    $activities = array_filter($activities, function($a) use ($user) {
        return $a["assignedTo"] === $user["nombre"];
    });
} elseif (canonicalRole($user["rol"] ?? null) === ROLE_LEADER) {
    // Los líderes ven actividades asignadas a su equipo (excluyendo director)
    $activities = array_filter($activities, function($a) use ($user, $users) {
        $userNames = array_column($users["usuarios"], "nombre");
        return in_array($a["assignedTo"], $userNames);
    });
}

$activeActivities = array_filter($activities, function($activity) {
    return ($activity["status"] ?? "") !== "completada";
});
$completedActivities = array_filter($activities, function($activity) {
    return ($activity["status"] ?? "") === "completada";
});
$archivedCount = filter_var($_GET["archived"] ?? null, FILTER_VALIDATE_INT);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Gestión, asignación y seguimiento de actividades del equipo.">
    <title>Actividades - ActividadesApp</title>
    <link rel="stylesheet" href="css/style.css">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, "UTF-8"); ?>">
</head>
<body>
    <div class="container">
        <?php require __DIR__ . "/partials/sidebar.php"; ?>
        <main id="contenido-principal">
        <header class="dashboard-header">
            <h1>Gestión de Actividades</h1>
            <div class="user-info">
                <span><?php echo htmlspecialchars($user["nombre"]); ?></span>
                <span class="user-role">Rol: <?php echo htmlspecialchars(roleLabel($user["rol"])); ?></span>
            </div>
            <button type="button" id="theme-toggle" class="btn theme-toggle" aria-pressed="false">🌙 Modo oscuro</button>
        </header>

        <a href="dashboard.php" class="btn btn-back">← Volver al inicio</a>

        <?php if (($_GET["success"] ?? "") === "1"): ?>
            <div class="alert alert-success">Actividad creada correctamente.</div>
        <?php elseif (($_GET["success"] ?? "") === "2"): ?>
            <div class="alert alert-success">Cambios guardados correctamente.</div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <p><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="activities-content">
            <?php if ($canCreateOrEdit): ?>
                <?php
                    $formAssignees = $_POST["assignee"] ?? [];
                    $formAssignees = is_array($formAssignees) ? $formAssignees : [$formAssignees];
                    $formEditId = trim((string)($_POST["edit_id"] ?? ""));
                ?>
                <details class="action-panel unified-activity-panel" id="activity-form-panel" <?php echo $formEditId !== "" || $errors ? "open" : ""; ?>>
                    <summary id="activity-form-summary"><?php echo $formEditId !== "" ? "Editar actividad" : "Crear o editar actividad"; ?></summary>
                    <div class="create-activity-form">
                        <div class="form-section-header">
                            <h2 id="activity-form-title"><?php echo $formEditId !== "" ? "Editar actividad" : "Crear actividad"; ?></h2>
                            <p id="activity-form-description">Completa los datos. Para editar, selecciona una actividad de la lista y este mismo formulario se rellenará.</p>
                        </div>
                        <section class="activity-recommendations" aria-labelledby="activity-recommendations-title" <?php echo $formEditId !== "" ? "hidden" : ""; ?>>
                            <div class="activity-recommendations-heading">
                                <div>
                                    <span class="recommendation-spark" aria-hidden="true">✦</span>
                                    <h3 id="activity-recommendations-title">Sugerencias para ti</h3>
                                </div>
                                <span>Basadas en tu historial</span>
                            </div>
                            <?php if ($activityRecommendations): ?>
                                <div class="activity-recommendations-list">
                                    <?php foreach ($activityRecommendations as $index => $recommendation): ?>
                                        <article class="activity-recommendation-card">
                                            <div class="activity-recommendation-copy">
                                                <h4><?php echo htmlspecialchars($recommendation["title"], ENT_QUOTES, "UTF-8"); ?></h4>
                                                <p><?php echo htmlspecialchars($recommendation["reason"], ENT_QUOTES, "UTF-8"); ?></p>
                                            </div>
                                            <button type="button" class="btn btn-secondary use-activity-recommendation" data-recommendation-index="<?php echo $index; ?>">Usar sugerencia</button>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                                <script type="application/json" id="activity-recommendations-data"><?php
                                    echo json_encode($activityRecommendations, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
                                ?></script>
                            <?php else: ?>
                                <p class="activity-recommendations-empty">Aún no hay suficientes actividades repetidas para sugerir. Las recomendaciones aparecerán conforme reutilices actividades.</p>
                            <?php endif; ?>
                        </section>
                        <form method="POST" action="actividades.php" id="activityForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, "UTF-8"); ?>">
                            <input type="hidden" name="edit_id" id="edit_activity_id" value="<?php echo htmlspecialchars($formEditId, ENT_QUOTES, "UTF-8"); ?>">
                            <div class="form-group">
                                <label for="title">Título *</label>
                                <input type="text" id="title" name="title" required maxlength="160" value="<?php echo htmlspecialchars($_POST["title"] ?? "", ENT_QUOTES, "UTF-8"); ?>">
                                <button type="button" id="mic-title" class="btn btn-mic" disabled>🎤 Hablar título</button>
                            </div>
                            <div class="form-group">
                                <label for="description">Descripción</label>
                                <textarea id="description" name="description" rows="3" maxlength="1000"><?php echo htmlspecialchars($_POST["description"] ?? "", ENT_QUOTES, "UTF-8"); ?></textarea>
                                <button type="button" id="mic-description" class="btn btn-mic" disabled>🎤 Hablar descripción</button>
                            </div>
                            <p id="voice-input-status" class="voice-input-status" role="status" aria-live="polite"></p>
                            <div class="form-group">
                                <label for="priority">Prioridad</label>
                                <?php $formPriority = $_POST["priority"] ?? "media"; ?>
                                <select id="priority" name="priority">
                                    <?php foreach (["baja" => "Baja", "media" => "Media", "alta" => "Alta", "urgente" => "Urgente"] as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo $formPriority === $value ? "selected" : ""; ?>><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="assignee">Asignar a *</label>
                                <div class="assignee-picker" id="assignee-picker">
                                    <div class="assignee-add-row">
                                        <select id="assignee" class="assignee-native-select" tabindex="-1" aria-hidden="true">
                                            <option value="">Selecciona una persona</option>
                                            <?php foreach ($assignableUsers as $u): ?>
                                                <option value="<?php echo htmlspecialchars($u["nombre"], ENT_QUOTES, "UTF-8"); ?>" data-role="<?php echo htmlspecialchars(roleLabel($u["rol"] ?? null), ENT_QUOTES, "UTF-8"); ?>">
                                                    <?php echo htmlspecialchars($u["nombre"], ENT_QUOTES, "UTF-8"); ?> (<?php echo htmlspecialchars(roleLabel($u["rol"] ?? null), ENT_QUOTES, "UTF-8"); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="assignee-select" id="assignee-select">
                                            <button type="button" id="assignee-trigger" class="assignee-trigger" aria-haspopup="listbox" aria-expanded="false" aria-controls="assignee-options">
                                                <span class="assignee-trigger-icon" aria-hidden="true">♙</span>
                                                <span class="assignee-trigger-label">Selecciona una persona</span>
                                                <span class="assignee-trigger-chevron" aria-hidden="true">⌄</span>
                                            </button>
                                            <div id="assignee-options" class="assignee-options" role="listbox" aria-label="Personas disponibles" hidden>
                                                <?php foreach ($assignableUsers as $u): ?>
                                                    <button type="button" class="assignee-option" role="option" aria-selected="false" data-value="<?php echo htmlspecialchars($u["nombre"], ENT_QUOTES, "UTF-8"); ?>" data-label="<?php echo htmlspecialchars($u["nombre"], ENT_QUOTES, "UTF-8"); ?>">
                                                        <span class="assignee-option-avatar" aria-hidden="true"><?php echo htmlspecialchars(mb_strtoupper(mb_substr((string)$u["nombre"], 0, 1)), ENT_QUOTES, "UTF-8"); ?></span>
                                                        <span class="assignee-option-copy">
                                                            <strong><?php echo htmlspecialchars($u["nombre"], ENT_QUOTES, "UTF-8"); ?></strong>
                                                            <small><?php echo htmlspecialchars(roleLabel($u["rol"] ?? null), ENT_QUOTES, "UTF-8"); ?></small>
                                                        </span>
                                                        <span class="assignee-option-check" aria-hidden="true">✓</span>
                                                    </button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <button type="button" class="btn btn-secondary" id="add-assignee">Agregar</button>
                                    </div>
                                    <div id="selected-assignees" class="selected-assignees" aria-live="polite">
                                        <?php foreach ($formAssignees as $selectedAssignee): ?>
                                            <?php if (trim((string)$selectedAssignee) === "") continue; ?>
                                            <div class="selected-assignee" data-name="<?php echo htmlspecialchars((string)$selectedAssignee, ENT_QUOTES, "UTF-8"); ?>">
                                                <span><?php echo htmlspecialchars((string)$selectedAssignee, ENT_QUOTES, "UTF-8"); ?></span>
                                                <input type="hidden" name="assignee[]" value="<?php echo htmlspecialchars((string)$selectedAssignee, ENT_QUOTES, "UTF-8"); ?>">
                                                <button type="button" class="remove-assignee" aria-label="Quitar <?php echo htmlspecialchars((string)$selectedAssignee, ENT_QUOTES, "UTF-8"); ?>">×</button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <small class="form-help" id="assignee-help">Puedes agregar varias personas; cada quien tendrá su cronómetro y pago.</small>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="project">Proyecto</label>
                                <select id="project" name="project">
                                    <option value="">Sin proyecto</option>
                                    <?php foreach ($projectNames as $projectName): ?>
                                        <option value="<?php echo htmlspecialchars($projectName, ENT_QUOTES, "UTF-8"); ?>" <?php echo (($_POST["project"] ?? "") === $projectName) ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars($projectName, ENT_QUOTES, "UTF-8"); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="due_at">Fecha límite (opcional)</label>
                                <input type="date" id="due_at" name="due_at" value="<?php echo htmlspecialchars($_POST["due_at"] ?? "", ENT_QUOTES, "UTF-8"); ?>">
                            </div>
                            <div class="form-actions">
                                <button type="submit" id="activity-form-submit" class="btn btn-submit"><?php echo $formEditId !== "" ? "Guardar cambios" : "Crear actividad"; ?></button>
                                <button type="button" id="activity-form-cancel" class="btn btn-secondary" hidden>Cancelar edición</button>
                            </div>
                        </form>
                    </div>
                </details>

<script>
const assigneeSelect = document.getElementById('assignee');
const addAssigneeButton = document.getElementById('add-assignee');
const selectedAssignees = document.getElementById('selected-assignees');
const assigneeSelectContainer = document.getElementById('assignee-select');
const assigneeTrigger = document.getElementById('assignee-trigger');
const assigneeOptions = document.getElementById('assignee-options');
const assigneeTriggerLabel = assigneeTrigger?.querySelector('.assignee-trigger-label');

function syncAssigneeOptions() {
    if (!assigneeOptions) return;
    const selectedNames = new Set(
        Array.from(selectedAssignees.querySelectorAll('.selected-assignee'))
            .map(function (item) { return item.dataset.name; })
    );
    assigneeOptions.querySelectorAll('.assignee-option').forEach(function (option) {
        const isSelected = selectedNames.has(option.dataset.value);
        option.disabled = isSelected;
        option.setAttribute('aria-selected', String(assigneeSelect.value === option.dataset.value));
        option.classList.toggle('is-assigned', isSelected);
    });
}

function closeAssigneeOptions(returnFocus) {
    if (!assigneeOptions || !assigneeTrigger) return;
    assigneeOptions.hidden = true;
    assigneeTrigger.setAttribute('aria-expanded', 'false');
    if (returnFocus) assigneeTrigger.focus();
}

function openAssigneeOptions() {
    if (!assigneeOptions || !assigneeTrigger) return;
    syncAssigneeOptions();
    assigneeOptions.hidden = false;
    assigneeTrigger.setAttribute('aria-expanded', 'true');
    const firstAvailable = assigneeOptions.querySelector('.assignee-option:not(:disabled)');
    (firstAvailable || assigneeOptions.querySelector('.assignee-option'))?.focus();
}

function setAssigneeChoice(option) {
    if (!option || option.disabled) return;
    assigneeSelect.value = option.dataset.value;
    assigneeTriggerLabel.textContent = option.dataset.label;
    syncAssigneeOptions();
    closeAssigneeOptions(true);
}

function addAssignee(name) {
    if (activityForm.elements.edit_id.value
        && selectedAssignees.querySelectorAll('.selected-assignee').length >= 1) {
        return;
    }
    const alreadySelected = Array.from(selectedAssignees.querySelectorAll('.selected-assignee'))
        .some(function (item) {
            return item.dataset.name === name;
        });
    if (!name || alreadySelected) {
        return;
    }

    const item = document.createElement('div');
    item.className = 'selected-assignee';
    item.dataset.name = name;

    const label = document.createElement('span');
    label.textContent = name;

    const hiddenInput = document.createElement('input');
    hiddenInput.type = 'hidden';
    hiddenInput.name = 'assignee[]';
    hiddenInput.value = name;

    const removeButton = document.createElement('button');
    removeButton.type = 'button';
    removeButton.className = 'remove-assignee';
    removeButton.setAttribute('aria-label', 'Quitar ' + name);
    removeButton.textContent = '×';
    removeButton.addEventListener('click', function () {
        item.remove();
        syncAssigneeOptions();
    });

    item.append(label, hiddenInput, removeButton);
    selectedAssignees.appendChild(item);
    assigneeSelect.value = '';
    assigneeTriggerLabel.textContent = 'Selecciona una persona';
    syncAssigneeOptions();
}

if (assigneeSelect && addAssigneeButton && selectedAssignees) {
    assigneeTrigger.addEventListener('click', function () {
        if (assigneeOptions.hidden) openAssigneeOptions();
        else closeAssigneeOptions(false);
    });

    assigneeTrigger.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openAssigneeOptions();
        }
    });

    assigneeOptions.querySelectorAll('.assignee-option').forEach(function (option) {
        option.addEventListener('click', function () {
            setAssigneeChoice(option);
        });
        option.addEventListener('keydown', function (event) {
            const availableOptions = Array.from(assigneeOptions.querySelectorAll('.assignee-option:not(:disabled)'));
            const currentIndex = availableOptions.indexOf(option);
            let nextIndex = currentIndex;
            if (event.key === 'ArrowDown') nextIndex = Math.min(currentIndex + 1, availableOptions.length - 1);
            else if (event.key === 'ArrowUp') nextIndex = Math.max(currentIndex - 1, 0);
            else if (event.key === 'Home') nextIndex = 0;
            else if (event.key === 'End') nextIndex = availableOptions.length - 1;
            else if (event.key === 'Escape') {
                event.preventDefault();
                closeAssigneeOptions(true);
                return;
            } else if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                setAssigneeChoice(option);
                return;
            } else {
                return;
            }
            event.preventDefault();
            availableOptions[nextIndex]?.focus();
        });
    });

    document.addEventListener('pointerdown', function (event) {
        if (!assigneeSelectContainer.contains(event.target)) closeAssigneeOptions(false);
    });

    addAssigneeButton.addEventListener('click', function () {
        addAssignee(assigneeSelect.value);
    });

    selectedAssignees.querySelectorAll('.selected-assignee').forEach(function (item) {
        item.querySelector('.remove-assignee').addEventListener('click', function () {
            item.remove();
            syncAssigneeOptions();
        });
    });
    syncAssigneeOptions();
}

const activitiesForEdit = <?php echo json_encode(array_values($activities), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const activityForm = document.getElementById('activityForm');
const activityFormPanel = document.getElementById('activity-form-panel');
const activityFormTitle = document.getElementById('activity-form-title');
const activityFormSummary = document.getElementById('activity-form-summary');
const activityFormSubmit = document.getElementById('activity-form-submit');
const activityFormCancel = document.getElementById('activity-form-cancel');
const assigneeHelp = document.getElementById('assignee-help');
const activityRecommendationsSection = document.querySelector('.activity-recommendations');
const activityRecommendations = JSON.parse(document.getElementById('activity-recommendations-data')?.textContent || '[]');

function clearSelectedAssignees() {
    selectedAssignees.replaceChildren();
    assigneeSelect.value = '';
    assigneeTriggerLabel.textContent = 'Selecciona una persona';
    syncAssigneeOptions();
}

document.querySelectorAll('.use-activity-recommendation').forEach(function (button) {
    button.addEventListener('click', function () {
        const recommendation = activityRecommendations[Number(button.dataset.recommendationIndex)];
        if (!recommendation || !activityForm) return;

        activityForm.elements.edit_id.value = '';
        activityForm.elements.title.value = recommendation.title || '';
        activityForm.elements.description.value = recommendation.description || '';
        activityForm.elements.priority.value = recommendation.priority || 'media';
        activityForm.elements.project.value = recommendation.project || '';
        activityForm.elements.due_at.value = '';
        clearSelectedAssignees();
        if (recommendation.assignee && Array.from(assigneeSelect.options).some(function (option) {
            return option.value === recommendation.assignee;
        })) {
            addAssignee(recommendation.assignee);
        }
        setActivityFormMode(false);
        activityFormPanel.open = true;
        activityForm.elements.title.focus();
        activityForm.elements.title.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
});

function setActivityFormMode(editing) {
    const assigneeItems = selectedAssignees.querySelectorAll('.selected-assignee');
    if (editing && assigneeItems.length > 1) {
        Array.from(assigneeItems).slice(1).forEach(function (item) {
            item.remove();
        });
    }
    activityFormTitle.textContent = editing ? 'Editar actividad' : 'Crear actividad';
    activityFormSummary.textContent = editing ? 'Editar actividad' : 'Crear o editar actividad';
    activityFormSubmit.textContent = editing ? 'Guardar cambios' : 'Crear actividad';
    activityFormCancel.hidden = !editing;
    if (activityRecommendationsSection) activityRecommendationsSection.hidden = editing;
    assigneeHelp.textContent = editing
        ? 'Al editar se conserva una persona asignada. Para asignar a varias personas, crea la actividad como nueva.'
        : 'Puedes agregar varias personas; cada quien tendrá su cronómetro y pago.';
    activityFormPanel.open = true;
}

function populateEditForm(activityId) {
    const activity = activitiesForEdit.find(function (item) {
        return String(item.id) === String(activityId);
    });
    if (!activity) return;

    activityForm.elements.edit_id.value = activity.id;
    activityForm.elements.title.value = activity.title || '';
    activityForm.elements.description.value = activity.description || '';
    activityForm.elements.priority.value = activity.priority || 'media';
    activityForm.elements.project.value = activity.project || '';
    activityForm.elements.due_at.value = activity.dueAt ? activity.dueAt.slice(0, 10) : '';
    clearSelectedAssignees();
    addAssignee(activity.assignedTo || '');
    setActivityFormMode(true);
    activityFormTitle.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function cancelEdit() {
    activityForm.reset();
    activityForm.elements.edit_id.value = '';
    clearSelectedAssignees();
    setActivityFormMode(false);
    activityFormPanel.open = false;
}

activityFormCancel.addEventListener('click', cancelEdit);
document.addEventListener('click', function (event) {
    const button = event.target.closest('[data-edit-id]');
    if (button) {
        populateEditForm(button.dataset.editId);
    }
});
if (activityForm.elements.edit_id.value) {
    setActivityFormMode(true);
}
</script>

            <?php endif; ?>

            <div class="activities-list">
                <h2>Lista de Actividades</h2>
                <?php if ($canCreateOrEdit): ?>
                    <a href="historial_actividades.php" class="btn btn-secondary activity-history-link">Ver historial de terminadas</a>
                <?php endif; ?>
                <?php if ($archivedCount !== false && $archivedCount > 0): ?>
                    <div class="alert alert-success">
                        Se archivaron <?php echo $archivedCount; ?> actividades completadas. El historial se conserva.
                    </div>
                <?php endif; ?>
                <?php if ($canCreateOrEdit && !empty($completedActivities)): ?>
                    <section class="archive-activities-panel">
                        <div>
                            <h3>Depurar actividades realizadas</h3>
                            <p>Retira las actividades completadas de esta lista. Se conservarán en el archivo histórico.</p>
                        </div>
                        <form method="POST" onsubmit="return confirm('¿Archivar todas las actividades completadas? El historial se conservará en el archivo histórico.');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, "UTF-8"); ?>">
                            <input type="hidden" name="action" value="archive_completed">
                            <button type="submit" class="btn btn-danger">Archivar completadas</button>
                        </form>
                    </section>
                <?php endif; ?>
                <?php if (empty($activeActivities)): ?>
                    <p>No hay actividades pendientes o en progreso.</p>
                <?php else: ?>
                    <div class="table-scroll-container" role="region" aria-label="Lista de actividades" tabindex="0">
                    <table class="activities-table">
                        <thead>
                            <tr>
                                <th>Título</th>
                                <th>Estado</th>
                                <th>Prioridad</th>
                                <th>Asignado a</th>
                                <th>Asignada el</th>
                                <th>Proyecto</th>
                                <th>Fecha límite</th>
                                <th>Tiempo trabajado</th>
                                <th>Terminada el</th>
                                <?php if ($canCreateOrEdit): ?>
                                    <th>Editar</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeActivities as $act): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($act["title"] ?? ""); ?></td>
                                    <?php
                                        $activityStatus = (string)($act["status"] ?? "pendiente");
                                        $statusLabels = ["pendiente" => "Pendiente", "en_progreso" => "En progreso", "pausada" => "Pausada", "completada" => "Completada"];
                                    ?>
                                    <td><span class="activity-status status-<?php echo htmlspecialchars($activityStatus, ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($statusLabels[$activityStatus] ?? $activityStatus, ENT_QUOTES, "UTF-8"); ?></span></td>
                                    <td><?php echo htmlspecialchars($act["priority"] ?? "media"); ?></td>
                                    <td><?php echo htmlspecialchars($act["assignedTo"] ?? "-"); ?></td>
                                    <td><?php echo htmlspecialchars($act["assignedAt"] ?? $act["startedAt"] ?? "-"); ?></td>
                                    <td><?php echo htmlspecialchars($act["project"] ?? "-"); ?></td>
                                    <td><?php echo htmlspecialchars($act["dueAt"] ?? "Sin límite"); ?></td>
                                    <td class="live-time-cell" data-activity-id="<?php echo htmlspecialchars((string)$act["id"], ENT_QUOTES, "UTF-8"); ?>" data-status="<?php echo htmlspecialchars($act["status"] ?? "pendiente", ENT_QUOTES, "UTF-8"); ?>" data-elapsed-seconds="<?php echo (int)($act["elapsedSeconds"] ?? (($act["durationMinutes"] ?? 0) * 60)); ?>">
                                        <span class="live-elapsed"><?php echo ($act["elapsedSeconds"] ?? 0) > 0 ? gmdate("H:i:s", (int)$act["elapsedSeconds"]) : "-"; ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($act["endedAt"] ?? "-"); ?></td>
                                     <?php if ($canCreateOrEdit): ?>
                                         <td>
                                             <button type="button" class="btn btn-warning" data-edit-id="<?php echo htmlspecialchars((string)$act["id"], ENT_QUOTES, "UTF-8"); ?>">Editar</button>
                                         </td>
                                     <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
                <?php if (!empty($completedActivities)): ?>
                    <details class="completed-activities">
                        <summary>Actividades realizadas (<?php echo count($completedActivities); ?>)</summary>
                        <div class="table-scroll-container" role="region" aria-label="Actividades realizadas" tabindex="0">
                        <table class="activities-table">
                            <thead>
                                <tr>
                                    <th>Título</th>
                                    <th>Estado</th>
                                    <th>Asignado a</th>
                                    <th>Asignada el</th>
                                    <th>Proyecto</th>
                                    <th>Tiempo trabajado</th>
                                    <th>Terminada el</th>
                                    <?php if ($canCreateOrEdit): ?><th>Editar</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($completedActivities as $act): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($act["title"] ?? ""); ?></td>
                                        <td><span class="activity-status status-completada">Completada</span></td>
                                        <td><?php echo htmlspecialchars($act["assignedTo"] ?? "-"); ?></td>
                                        <td><?php echo htmlspecialchars($act["assignedAt"] ?? $act["startedAt"] ?? "-"); ?></td>
                                        <td><?php echo htmlspecialchars($act["project"] ?? "-"); ?></td>
                                        <td class="live-time-cell" data-activity-id="<?php echo htmlspecialchars((string)$act["id"], ENT_QUOTES, "UTF-8"); ?>" data-status="completada" data-elapsed-seconds="<?php echo (int)($act["elapsedSeconds"] ?? (($act["durationMinutes"] ?? 0) * 60)); ?>">
                                            <span class="live-elapsed"><?php echo ($act["elapsedSeconds"] ?? 0) > 0 ? gmdate("H:i:s", (int)$act["elapsedSeconds"]) : "-"; ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($act["endedAt"] ?? "-"); ?></td>
                                        <?php if ($canCreateOrEdit): ?>
                                            <td><button type="button" class="btn btn-warning" data-edit-id="<?php echo htmlspecialchars((string)$act["id"], ENT_QUOTES, "UTF-8"); ?>">Editar</button></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </details>
                <?php endif; ?>
            </div>
        </div>

        <footer class="footer">
            <p>ActividadesApp - Gestión de tiempo y tareas</p>
        </footer>
        </main>
    </div>
    <script src="js/theme.js"></script>
    <script src="js/live_activities.js"></script>
    <?php if ($canCreateOrEdit): ?><script src="js/voice_input.js"></script><?php endif; ?>
</body>
</html>