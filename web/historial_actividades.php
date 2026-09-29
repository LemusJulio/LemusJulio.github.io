<?php
require_once __DIR__ . "/bootstrap.php";
requirePermission("view_global_activities");

$user = currentUser();
$archivedActivities = loadJson("actividades_archivadas.json", []);
$currentActivities = loadJson("actividades.json", []);
$history = array_values(array_merge(
    array_filter($currentActivities, fn(array $activity): bool => ($activity["status"] ?? "") === "completada"),
    $archivedActivities
));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Historial de actividades terminadas y archivadas.">
    <title>Historial de actividades - ActividadesApp</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <?php require __DIR__ . "/partials/sidebar.php"; ?>
        <main id="contenido-principal">
            <header class="dashboard-header">
                <h1>Historial de actividades</h1>
                <div class="user-info">
                    <span><?php echo htmlspecialchars($user["nombre"]); ?></span>
                    <span class="user-role">Rol: <?php echo htmlspecialchars(roleLabel($user["rol"])); ?></span>
                </div>
                <button type="button" id="theme-toggle" class="btn theme-toggle" aria-pressed="false">🌙 Modo oscuro</button>
            </header>

            <a href="actividades.php" class="btn btn-back">← Volver a actividades</a>

            <section class="activities-list">
                <h2>Actividades terminadas (<?php echo count($history); ?>)</h2>
                <?php if (empty($history)): ?>
                    <p>No hay actividades terminadas o archivadas.</p>
                <?php else: ?>
                    <div class="table-scroll-container" role="region" aria-label="Historial de actividades" tabindex="0">
                        <table class="activities-table">
                            <thead>
                                <tr>
                                    <th>Actividad</th>
                                    <th>Persona</th>
                                    <th>Proyecto</th>
                                    <th>Prioridad</th>
                                    <th>Tiempo trabajado</th>
                                    <th>Terminada el</th>
                                    <th>Archivada el</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($history as $activity): ?>
                                    <?php
                                        $seconds = (int)($activity["elapsedSeconds"] ?? (($activity["durationMinutes"] ?? 0) * 60));
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($activity["title"] ?? "Sin título"); ?></td>
                                        <td><?php echo htmlspecialchars($activity["assignedTo"] ?? "Sin asignar"); ?></td>
                                        <td><?php echo htmlspecialchars($activity["project"] ?? "Sin proyecto"); ?></td>
                                        <td><?php echo htmlspecialchars($activity["priority"] ?? "Sin prioridad"); ?></td>
                                        <td><?php echo gmdate("H:i:s", max(0, $seconds)); ?></td>
                                        <td><?php echo htmlspecialchars($activity["endedAt"] ?? "—"); ?></td>
                                        <td><?php echo htmlspecialchars($activity["archivedAt"] ?? "Aún en lista"); ?></td>
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
