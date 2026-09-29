<?php
require_once __DIR__ . "/bootstrap.php";
if (!isset($_SESSION["user"])) {
    header("Location: login.php");
    exit;
}

$user = $_SESSION["user"];
requirePermission("view_reports");

$users = loadJson("datos.json", ["usuarios" => []]);
$activities = loadJson("actividades.json", []);
$projectsData = loadJson("proyectos.json", ["proyectos" => []]);
$projectFactors = [];
foreach (($projectsData["proyectos"] ?? []) as $project) {
    $projectName = trim((string)($project["nombre"] ?? ""));
    if ($projectName !== "") {
        $projectFactors[$projectName] = max(0.01, (float)($project["factor"] ?? 1));
    }
}
$reviewErrors = [];
$reviewSuccess = isset($_GET["review_success"]);

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "review_activity") {
    if (!verifyCsrf($_POST["csrf_token"] ?? null)) {
        $reviewErrors[] = "La sesión del formulario expiró. Intenta de nuevo.";
    }

    $activityId = trim((string)($_POST["activity_id"] ?? ""));
    $reviewStatus = $_POST["review_status"] ?? "";
    $correctedMinutes = filter_var($_POST["corrected_minutes"] ?? null, FILTER_VALIDATE_FLOAT);
    $activityIndex = null;

    foreach ($activities as $index => $activity) {
        if ((string)($activity["id"] ?? "") === $activityId) {
            $activityIndex = $index;
            break;
        }
    }

    if ($activityIndex === null) {
        $reviewErrors[] = "No se encontró la actividad seleccionada.";
    }
    if ($activityIndex !== null && ($activities[$activityIndex]["status"] ?? "") !== "completada") {
        $reviewErrors[] = "Solo se pueden revisar actividades terminadas.";
    }
    if ($activityIndex !== null && ($activities[$activityIndex]["reviewStatus"] ?? "pendiente") === "aprobada") {
        $reviewErrors[] = "Una actividad aprobada está bloqueada y no puede modificarse.";
    }
    if (!in_array($reviewStatus, ["pendiente", "aprobada", "rechazada"], true)) {
        $reviewErrors[] = "El estado de revisión no es válido.";
    }
    if ($correctedMinutes === false || $correctedMinutes < 0 || $correctedMinutes > 1440) {
        $reviewErrors[] = "El tiempo corregido debe estar entre 0 y 1,440 minutos.";
    }

    if (empty($reviewErrors)) {
        $activities[$activityIndex]["elapsedSeconds"] = (int)round($correctedMinutes * 60);
        $activities[$activityIndex]["durationMinutes"] = (int)floor($correctedMinutes);
        $activities[$activityIndex]["reviewStatus"] = $reviewStatus;
        $activities[$activityIndex]["reviewedAt"] = date("Y-m-d H:i:s");
        $activities[$activityIndex]["reviewedBy"] = $user["nombre"];
        $activities[$activityIndex]["reviewNote"] = trim($_POST["review_note"] ?? "");
        saveJson("actividades.json", $activities);
        $recipient = trim((string)($activities[$activityIndex]["assignedTo"] ?? ""));
        if ($recipient !== "") {
            $notifications = loadJson("notificaciones.json", []);
            $statusText = $reviewStatus === "aprobada" ? "aprobada" : "rechazada";
            $note = trim($_POST["review_note"] ?? "");
            $notifications[] = [
                "id" => bin2hex(random_bytes(12)),
                "type" => "review",
                "recipient" => $recipient,
                "message" => sprintf(
                    "Tu actividad \"%s\" fue %s%s.",
                    $activities[$activityIndex]["title"] ?? "Sin título",
                    $statusText,
                    $note !== "" ? ": " . $note : ""
                ),
                "activityTitle" => $activities[$activityIndex]["title"] ?? "Sin título",
                "worker" => $recipient,
                "createdAt" => date("Y-m-d H:i:s"),
                "createdAtUnix" => time()
            ];
            saveJson("notificaciones.json", array_slice($notifications, -200));
        }
        header("Location: reportes.php?review_success=1");
        exit;
    }
}

function activityElapsedSeconds(array $activity): int
{
    $elapsed = (int)($activity["elapsedSeconds"] ?? (($activity["durationMinutes"] ?? 0) * 60));
    if (($activity["status"] ?? "") === "en_progreso" && !empty($activity["startedAt"])) {
        $startedAt = strtotime($activity["startedAt"]);
        if ($startedAt !== false) {
            $elapsed += max(0, time() - $startedAt);
        }
    }

    return max(0, $elapsed);
}

function formatReportDuration(int $seconds): string
{
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $remainingSeconds = $seconds % 60;

    return sprintf("%02d:%02d:%02d", $hours, $minutes, $remainingSeconds);
}

function reviewStatusLabel(string $status): string
{
    return match ($status) {
        "aprobada" => "Aprobada",
        "rechazada" => "Rechazada",
        default => "Pendiente de revisión"
    };
}

function projectFactor(string $project, array $projectFactors): float
{
    return $projectFactors[$project] ?? 1.0;
}

// Crear mapeo insensible a mayúsculas/minúsculas
// JSON de usuarios usa nombres cortos, pero actividades.json usa nombres completos (ej. "Pedro Almacén")
// Se almacenan tanto versiones exactas como en minúsculas para facilitar la búsqueda
$userMap = [];
$userMapLower = [];  // Para búsqueda insensible a mayúsculas/minúsculas
foreach ($users["usuarios"] as $u) {
    $userMap[$u["nombre"]] = $u;                          // nombre corto → usuario
    $userMap[$u["nombre"] . " " . $u["rol"]] = $u;        // nombre completo exacto → usuario
    $userMapLower[strtolower($u["nombre"])] = $u;         // nombre corto en minúsculas → usuario
    $userMapLower[strtolower($u["nombre"] . " " . $u["rol"])] = $u; // nombre completo en minúsculas → usuario
}

// Inicializar reportes usando los nombres únicos de actividades
// Se busca el nombre corto del usuario correspondiente a cada actividad
$personReports = [];
foreach ($activities as $activity) {
    $assignedTo = $activity["assignedTo"];
    $userData = null;
    $reportName = $assignedTo;
    
    // Intentar encontrar el usuario por nombre exacto o insensible a mayúsculas/minúsculas
    if (isset($userMap[$assignedTo])) {
        $userData = $userMap[$assignedTo];
    } elseif (isset($userMapLower[strtolower($assignedTo)])) {
        $userData = $userMapLower[strtolower($assignedTo)];
    }
    
    if ($userData) {
        $reportName = $userData["nombre"];  // Usar nombre corto
    }
    
    $tarifa = $userData ? $userData["tarifa_por_hora"] : 15.0;
    
    if (!isset($personReports[$reportName])) {
        $personReports[$reportName] = [
            "horas" => 0,
            "tarifa" => $tarifa,
            "costo" => 0
        ];
    }
}

// Agregar las horas y costos de cada actividad a la persona correspondiente
foreach ($activities as $activity) {
    $assignedTo = $activity["assignedTo"];
    $seconds = activityElapsedSeconds($activity);
    if ($seconds <= 0) continue;
    $minutes = $seconds / 60;
    
    $userData = null;
    // Buscar usuario por nombre exacto o insensible a mayúsculas/minúsculas
    if (isset($userMap[$assignedTo])) {
        $userData = $userMap[$assignedTo];
    } elseif (isset($userMapLower[strtolower($assignedTo)])) {
        $userData = $userMapLower[strtolower($assignedTo)];
    }
    
    if ($userData) {
        $reportName = $userData["nombre"];
    } else {
        $reportName = $assignedTo;  // Si no se encuentra, usar el nombre tal como está
    }
    
    if (isset($personReports[$reportName])) {
        $personReports[$reportName]["horas"] += $minutes;
        $factor = (float)($activity["projectFactor"] ?? projectFactor((string)($activity["project"] ?? ""), $projectFactors));
        $personReports[$reportName]["costo"] += ($minutes / 60) * $personReports[$reportName]["tarifa"] * $factor;
    }
}

// Calcular costos por proyecto
$projectReports = [];
foreach ($activities as $activity) {
    $project = $activity["project"] ?? "Sin proyecto";
    if (!isset($projectReports[$project])) {
        $projectReports[$project] = ["horas" => 0, "costo" => 0, "factor" => projectFactor($project, $projectFactors)];
    }
    $minutes = activityElapsedSeconds($activity) / 60;
    $projectReports[$project]["horas"] += $minutes;

    // Buscar tarifa del usuario asignado usando el mapeo unificado
    $assignedTo = $activity["assignedTo"];
    $userFound = null;
    if (isset($userMap[$assignedTo])) {
        $userFound = $userMap[$assignedTo];
    } elseif (isset($userMapLower[strtolower($assignedTo)])) {
        $userFound = $userMapLower[strtolower($assignedTo)];
    }
    if ($userFound) {
        $factor = (float)($activity["projectFactor"] ?? $projectReports[$project]["factor"]);
        $projectReports[$project]["costo"] += ($minutes / 60) * $userFound["tarifa_por_hora"] * $factor;
    }
}
// Exportar a CSV
if (isset($_GET["export"])) {
    $filename = "reporte_" . date("Y-m-d_H-i-s") . ".csv";
    header("Content-Type: text/csv");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    $output = fopen("php://output", "w");

    // Encabezado
    fputcsv($output, ["Reporte de Actividades - " . date("Y-m-d H:i:s")]);
    fputcsv($output, [""]);
    fputcsv($output, ["=== Horas por Persona ==="]);
    fputcsv($output, ["Persona", "Tiempo trabajado", "Tarifa/hora", "Costo estimado"]);

    foreach ($personReports as $name => $data) {
        fputcsv($output, [
            $name,
            formatReportDuration((int)round($data["horas"] * 60)),
            $data["tarifa"],
            number_format($data["costo"], 2)
        ]);
    }

    fputcsv($output, [""]);
    fputcsv($output, ["=== Costos por Proyecto ==="]);
    fputcsv($output, ["Proyecto", "Tiempo trabajado", "Costo estimado"]);

    foreach ($projectReports as $project => $data) {
        fputcsv($output, [
            $project,
            formatReportDuration((int)round($data["horas"] * 60)),
            number_format($data["costo"], 2)
        ]);
    }

    fputcsv($output, [""]);
    fputcsv($output, ["=== Detalle de Actividades ==="]);
    fputcsv($output, ["Actividad", "Persona asignada", "Proyecto", "Prioridad", "Estado", "Revisión", "Asignada", "Inicio", "Terminada", "Tiempo", "Costo estimado"]);
    foreach ($activities as $activity) {
        $assignedTo = $activity["assignedTo"] ?? "Sin asignar";
        $userData = $userMap[$assignedTo] ?? $userMapLower[strtolower($assignedTo)] ?? null;
        $seconds = activityElapsedSeconds($activity);
        $rate = $userData ? (float)$userData["tarifa_por_hora"] : 15.0;
        $factor = (float)($activity["projectFactor"] ?? projectFactor((string)($activity["project"] ?? ""), $projectFactors));
        fputcsv($output, [
            $activity["title"] ?? "Sin título",
            $assignedTo,
            $activity["project"] ?? "Sin proyecto",
            $activity["priority"] ?? "Sin prioridad",
            $activity["status"] ?? "Sin estado",
            reviewStatusLabel($activity["reviewStatus"] ?? "pendiente"),
            $activity["assignedAt"] ?? "",
            $activity["startedAt"] ?? "",
            $activity["endedAt"] ?? "",
            formatReportDuration($seconds),
            number_format(($seconds / 3600) * $rate * $factor, 2)
        ]);
    }

    fclose($output);
    exit;
}

$completedActivities = 0;
$inProgressActivities = 0;
$pendingActivities = 0;
foreach ($activities as $activity) {
    $status = $activity["status"] ?? "";
    if ($status === "completada") {
        $completedActivities++;
    } elseif ($status === "en_progreso" || $status === "pausada") {
        $inProgressActivities++;
    } else {
        $pendingActivities++;
    }

}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Reportes de actividades, horas trabajadas, tarifas y pagos estimados.">
    <title>Reportes - ActividadesApp</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .reports-container {
            margin-top: 20px;
        }
        .report-summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin: 20px 0 28px;
        }
        .report-summary-card {
            background: var(--surface-color);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            padding: 16px;
        }
        .report-summary-card strong {
            display: block;
            color: var(--text-light);
            font-size: .85rem;
            margin-bottom: 6px;
        }
        .report-summary-card span {
            font-size: 1.35rem;
            font-weight: 700;
        }
        .report-table-wrapper {
            overflow-x: auto;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
        }
        .reporte-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-bottom: 20px;
            min-width: 720px;
            background: var(--surface-color);
        }
        .reporte-table th, .reporte-table td {
            padding: 14px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }
        .reporte-table th {
            background: linear-gradient(110deg, var(--primary-color), #4338ca);
            color: #ffffff;
            font-size: .78rem;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .reporte-table tbody tr:nth-child(even) {
            background: rgba(148, 163, 184, .045);
        }
        .reporte-table tbody tr:last-child td {
            border-bottom: 0;
        }
        .reporte-table td {
            color: var(--text-color);
            font-size: .9rem;
        }
        .reporte-table tr:hover {
            background-color: rgba(34, 211, 238, .09);
        }
        .report-detail-table {
            min-width: 1250px;
        }
        .report-detail-table td,
        .report-detail-table th {
            white-space: nowrap;
        }
        .report-detail-table td:first-child {
            white-space: normal;
            min-width: 190px;
        }
        .report-status {
            display: inline-flex;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: .8rem;
            font-weight: 600;
        }
        .report-status-completada { background: #dcfce7; color: #166534; }
        .report-status-en_progreso { background: #fee2e2; color: #991b1b; }
        .report-status-pausada { background: #fef3c7; color: #92400e; }
        .report-status-pendiente { background: var(--surface-muted); color: var(--text-color); }
        .review-pending { background: #e0e7ff; color: #3730a3; }
        .review-approved { background: #dcfce7; color: #166534; }
        .review-rejected { background: #fee2e2; color: #991b1b; }
        .review-help {
            color: var(--text-light);
            font-size: .82rem;
            margin: -10px 0 12px;
        }
        .review-form {
            display: grid;
            grid-template-columns: 1.2fr .8fr 1fr auto;
            gap: 8px;
            align-items: end;
            min-width: 920px;
        }
        .review-form .form-group {
            margin: 0;
        }
        .review-form label {
            display: block;
            color: var(--text-light);
            font-size: .75rem;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .review-form input,
        .review-form select {
            width: 100%;
            padding: 8px 9px;
            border: 1px solid var(--border-color);
            border-radius: 7px;
            background: var(--surface-color);
            color: var(--text-color);
        }
        .export-actions {
            margin: 20px 0;
            display: flex;
            gap: 10px;
        }
        .btn-export {
            background-color: var(--success-color);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-print {
            background-color: var(--secondary-color);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        @media (max-width: 800px) {
            .report-summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php require __DIR__ . "/partials/sidebar.php"; ?>
        <main id="contenido-principal">
        <header class="dashboard-header">
            <h1>Reportes</h1>
            <div class="user-info">
                <span><?php echo htmlspecialchars($user["nombre"]); ?></span>
                <span class="user-role">Rol: <?php echo htmlspecialchars(roleLabel($user["rol"])); ?></span>
            </div>
            <button type="button" id="theme-toggle" class="btn theme-toggle" aria-pressed="false">🌙 Modo oscuro</button>
        </header>

        <a href="dashboard.php" class="btn btn-back">← Volver al inicio</a>

        <div class="export-actions">
            <a href="?export=1" class="btn-export">📥 Exportar a CSV</a>
            <button onclick="window.print()" class="btn-print">🖨️ Imprimir</button>
        </div>

        <div class="reports-container">
            <div class="report-summary" aria-label="Resumen general de reportes">
                <div class="report-summary-card">
                    <strong>Actividades completadas</strong>
                    <span><?php echo $completedActivities; ?></span>
                </div>
                <div class="report-summary-card">
                    <strong>En proceso / pausadas</strong>
                    <span><?php echo $inProgressActivities; ?></span>
                </div>
                <div class="report-summary-card">
                    <strong>Actividades pendientes</strong>
                    <span><?php echo $pendingActivities; ?></span>
                </div>
            </div>

            <h2>Horas y Costos por Persona</h2>
            <p class="review-help">El tiempo trabajado se muestra como horas, minutos y segundos. La tarifa se aplica sobre ese tiempo para calcular el pago estimado.</p>
            <div class="report-table-wrapper" role="region" aria-label="Reporte por persona" tabindex="0">
            <table class="reporte-table">
                <thead>
                    <tr>
                        <th>Persona</th>
                        <th>Tiempo trabajado</th>
                        <th>Tarifa/hora</th>
                        <th>Costo estimado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($personReports as $name => $data): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($name); ?></td>
                            <td><?php echo formatReportDuration((int)round($data["horas"] * 60)); ?></td>
                            <td>$<?php echo number_format($data["tarifa"], 2); ?></td>
                            <td>$<?php echo number_format($data["costo"], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <h2>Revisión y pago por actividad</h2>
            <p class="review-help">Revisa cada registro, corrige los minutos cuando sea necesario y aprueba únicamente el tiempo validado para el pago.</p>
            <div class="report-table-wrapper" role="region" aria-label="Revisión y pago por actividad" tabindex="0">
            <table class="reporte-table report-detail-table">
                <thead>
                    <tr>
                        <th>Actividad</th>
                        <th>Persona</th>
                        <th>Estado</th>
                        <th>Revisión</th>
                        <th>Minutos registrados</th>
                        <th>Tarifa/hora</th>
                        <th>Pago estimado</th>
                        <th>Acción de revisión</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activities as $activity): ?>
                        <?php
                            $assignedTo = $activity["assignedTo"] ?? "Sin asignar";
                            $userData = $userMap[$assignedTo] ?? $userMapLower[strtolower($assignedTo)] ?? null;
                            $seconds = activityElapsedSeconds($activity);
                            $rate = $userData ? (float)$userData["tarifa_por_hora"] : 15.0;
                            $factor = (float)($activity["projectFactor"] ?? projectFactor((string)($activity["project"] ?? ""), $projectFactors));
                            $reviewStatus = $activity["reviewStatus"] ?? "pendiente";
                            $reviewClass = $reviewStatus === "aprobada" ? "review-approved" : ($reviewStatus === "rechazada" ? "review-rejected" : "review-pending");
                            $activityId = (string)($activity["id"] ?? "");
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($activity["title"] ?? "Sin título"); ?></td>
                            <td><?php echo htmlspecialchars($assignedTo); ?></td>
                            <td><?php echo htmlspecialchars($activity["status"] ?? "Sin estado"); ?></td>
                            <td><span class="report-status <?php echo $reviewClass; ?>"><?php echo htmlspecialchars(reviewStatusLabel($reviewStatus)); ?></span></td>
                            <td><?php echo number_format($seconds / 60, 2); ?></td>
                            <td>$<?php echo number_format($rate, 2); ?></td>
                            <td>$<?php echo number_format(($seconds / 3600) * $rate * $factor, 2); ?></td>
                            <td>
                                <form method="POST" class="review-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, "UTF-8"); ?>">
                                    <input type="hidden" name="action" value="review_activity">
                                    <input type="hidden" name="activity_id" value="<?php echo htmlspecialchars($activityId, ENT_QUOTES, "UTF-8"); ?>">
                                    <div class="form-group">
                                        <label for="minutes-<?php echo htmlspecialchars($activityId, ENT_QUOTES, "UTF-8"); ?>">Minutos corregidos</label>
                                        <input id="minutes-<?php echo htmlspecialchars($activityId, ENT_QUOTES, "UTF-8"); ?>" type="number" name="corrected_minutes" min="0" max="1440" step="0.01" value="<?php echo htmlspecialchars(number_format($seconds / 60, 2, ".", ""), ENT_QUOTES, "UTF-8"); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="review-<?php echo htmlspecialchars($activityId, ENT_QUOTES, "UTF-8"); ?>">Decisión</label>
                                        <select id="review-<?php echo htmlspecialchars($activityId, ENT_QUOTES, "UTF-8"); ?>" name="review_status">
                                            <?php foreach (["pendiente" => "Pendiente", "aprobada" => "Aprobar", "rechazada" => "Rechazar"] as $value => $label): ?>
                                                <option value="<?php echo $value; ?>" <?php echo $reviewStatus === $value ? "selected" : ""; ?>><?php echo $label; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="note-<?php echo htmlspecialchars($activityId, ENT_QUOTES, "UTF-8"); ?>">Nota</label>
                                        <input id="note-<?php echo htmlspecialchars($activityId, ENT_QUOTES, "UTF-8"); ?>" type="text" name="review_note" maxlength="180" value="<?php echo htmlspecialchars($activity["reviewNote"] ?? "", ENT_QUOTES, "UTF-8"); ?>" placeholder="Motivo opcional">
                                    </div>
                                    <button type="submit" class="btn btn-primary">Guardar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <h2>Costos por Proyecto</h2>
            <div class="report-table-wrapper" role="region" aria-label="Reporte por proyecto" tabindex="0">
            <table class="reporte-table">
                <thead>
                    <tr>
                        <th>Proyecto</th>
                        <th>Tiempo trabajado</th>
                        <th>Costo estimado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($projectReports as $project => $data): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($project); ?></td>
                            <td><?php echo formatReportDuration((int)round($data["horas"] * 60)); ?></td>
                            <td>$<?php echo number_format($data["costo"], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <h2>Detalle de actividades</h2>
            <div class="report-table-wrapper" role="region" aria-label="Detalle de actividades" tabindex="0">
            <table class="reporte-table report-detail-table">
                <thead>
                    <tr>
                        <th>Actividad</th>
                        <th>Persona asignada</th>
                        <th>Proyecto</th>
                        <th>Prioridad</th>
                        <th>Estado</th>
                        <th>Asignada</th>
                        <th>Inicio</th>
                        <th>Terminada</th>
                        <th>Tiempo trabajado</th>
                        <th>Costo estimado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activities as $activity): ?>
                        <?php
                            $assignedTo = $activity["assignedTo"] ?? "Sin asignar";
                            $userData = $userMap[$assignedTo] ?? $userMapLower[strtolower($assignedTo)] ?? null;
                            $seconds = activityElapsedSeconds($activity);
                            $rate = $userData ? (float)$userData["tarifa_por_hora"] : 15.0;
                            $factor = (float)($activity["projectFactor"] ?? projectFactor((string)($activity["project"] ?? ""), $projectFactors));
                            $status = $activity["status"] ?? "sin_estado";
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($activity["title"] ?? "Sin título"); ?></strong>
                                <?php if (!empty($activity["description"])): ?>
                                    <br><small><?php echo htmlspecialchars($activity["description"]); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($assignedTo); ?></td>
                            <td><?php echo htmlspecialchars($activity["project"] ?? "Sin proyecto"); ?></td>
                            <td><?php echo htmlspecialchars($activity["priority"] ?? "Sin prioridad"); ?></td>
                            <td><span class="report-status report-status-<?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars($status); ?></span></td>
                            <td><?php echo htmlspecialchars($activity["assignedAt"] ?? "—"); ?></td>
                            <td><?php echo htmlspecialchars($activity["startedAt"] ?? "—"); ?></td>
                            <td><?php echo htmlspecialchars($activity["endedAt"] ?? "—"); ?></td>
                            <td><strong><?php echo formatReportDuration($seconds); ?></strong></td>
                            <td>$<?php echo number_format(($seconds / 3600) * $rate * $factor, 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

        <footer class="footer">
            <p>ActividadesApp - Gestión de tiempo y tareas</p>
        </footer>
        </main>
    </div>
    <script src="js/theme.js"></script>
</body>
</html>