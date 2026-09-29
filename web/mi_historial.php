<?php
require_once __DIR__ . "/bootstrap.php";
requirePermission("view_own_activities");

$user = currentUser();
$archived = loadJson("actividades_archivadas.json", []);
$active = loadJson("actividades.json", []);
$history = array_values(array_filter(
    array_merge(
        array_filter($active, static fn(array $activity): bool => ($activity["status"] ?? "") === "completada"),
        $archived
    ),
    static fn(array $activity): bool => ($activity["assignedTo"] ?? "") === ($user["nombre"] ?? "")
));
usort($history, static fn(array $a, array $b): int => strcmp(
    (string)($b["endedAt"] ?? $b["archivedAt"] ?? ""),
    (string)($a["endedAt"] ?? $a["archivedAt"] ?? "")
));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi historial - ActividadesApp</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="container">
    <?php require __DIR__ . "/partials/sidebar.php"; ?>
    <main id="contenido-principal">
        <header class="page-header">
            <div><p class="eyebrow">TU JORNADA</p><h1>Mi historial</h1></div>
        </header>
        <section class="activities-container">
            <h2>Actividades terminadas</h2>
            <p class="form-help">Solo tú puedes consultar este historial.</p>
            <?php if (!$history): ?>
                <div class="empty-state"><span class="empty-state-icon" aria-hidden="true">◷</span><h3>Aún no hay actividades terminadas</h3><p>Tus actividades completadas aparecerán aquí.</p></div>
            <?php else: ?>
                <div class="table-scroll-container" role="region" aria-label="Mi historial de actividades" tabindex="0">
                    <table class="activities-table">
                        <thead><tr><th>Actividad</th><th>Proyecto</th><th>Tipo</th><th>Tiempo trabajado</th><th>Terminada</th></tr></thead>
                        <tbody>
                        <?php foreach ($history as $activity): ?>
                            <?php $seconds = (int)($activity["elapsedSeconds"] ?? (($activity["durationMinutes"] ?? 0) * 60)); ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)($activity["title"] ?? "Sin título"), ENT_QUOTES, "UTF-8"); ?></td>
                                <td><?php echo htmlspecialchars((string)($activity["project"] ?? "Sin proyecto"), ENT_QUOTES, "UTF-8"); ?></td>
                                <td><?php echo ($activity["activityType"] ?? "") === "espontanea" ? "Espontánea" : "Asignada"; ?></td>
                                <td><?php echo gmdate("H:i:s", max(0, $seconds)); ?></td>
                                <td><?php echo htmlspecialchars((string)($activity["endedAt"] ?? $activity["archivedAt"] ?? "—"), ENT_QUOTES, "UTF-8"); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>
<script src="js/theme.js"></script>
</body>
</html>
