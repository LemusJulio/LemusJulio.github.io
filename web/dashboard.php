<?php
require_once __DIR__ . "/bootstrap.php";
if (!isset($_SESSION["user"])) {
    header("Location: login.php");
    exit;
}

$user = $_SESSION["user"];
$role = canonicalRole($user["rol"] ?? null);
requirePermission("view_dashboard");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Inicio de ActividadesApp con resumen de actividades y tiempo trabajado.">
    <title>Inicio - ActividadesApp</title>
    <link rel="stylesheet" href="css/style.css">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, "UTF-8"); ?>">
</head>
<body>
    <div class="container">
        <?php require __DIR__ . "/partials/sidebar.php"; ?>
        <main id="contenido-principal">
        <header class="dashboard-header">
            <div>
                <p class="eyebrow"><?php echo $role === ROLE_WORKER ? "TU JORNADA" : ($role === ROLE_LEADER ? "SUPERVISIÓN OPERATIVA" : "VISTA GENERAL"); ?></p>
                <h1><?php echo $role === ROLE_WORKER ? "Mi día" : ($role === ROLE_LEADER ? "Equipo" : "Resumen de operación"); ?></h1>
            </div>
            <div class="user-info">
                <span class="user-role">Rol: <?php echo htmlspecialchars(roleLabel($user["rol"])); ?></span>
            </div>
            <button type="button" id="theme-toggle" class="btn theme-toggle" aria-pressed="false">🌙 Modo oscuro</button>
        </header>

        <div id="dashboard-main">
            <?php if ($role === ROLE_WORKER): ?>
                <section class="activities-container worker-day-view" aria-labelledby="my-day-title">
                    <div class="section-heading">
                        <div><p class="eyebrow">TU TRABAJO</p><h2 id="my-day-title">Mis actividades</h2></div>
                    </div>
                    <div id="activities-list"><div class="activity-skeleton" aria-label="Cargando actividades"></div></div>
                    <a href="espontanea.php" class="btn btn-primary spontaneous-quick-action worker-spontaneous-action">＋ Crear actividad espontánea</a>
                </section>
            <?php elseif ($role === ROLE_LEADER): ?>
                <section class="team-overview" aria-labelledby="team-overview-title">
                    <div class="section-heading">
                        <div><p class="eyebrow">ESTADO ACTUAL</p><h2 id="team-overview-title">Avance del equipo</h2></div>
                        <div class="team-dashboard-actions">
                            <a href="actividades.php" class="btn btn-primary">＋ Asignar actividad</a>
                            <a href="espontanea.php" class="btn btn-secondary">＋ Espontánea</a>
                        </div>
                    </div>
                    <div id="role-dashboard-metrics" class="daily-board-metrics" aria-live="polite">
                        <div class="activity-skeleton" aria-label="Cargando resumen"></div>
                    </div>
                    <div class="daily-board-visuals">
                        <article class="daily-board-chart-card">
                            <h3>Actividades asignadas y espontáneas</h3>
                            <div id="team-activity-chart" class="daily-board-pie" role="img" aria-label="Distribución por tipo de actividad"></div>
                            <div id="team-activity-legend" class="daily-board-legend"></div>
                        </article>
                        <article class="daily-board-chart-card">
                            <h3>Distribución porcentual del tiempo</h3>
                            <div id="team-time-chart" class="daily-board-pie" role="img" aria-label="Porcentaje de tiempo trabajado y tiempo muerto"></div>
                            <div id="team-time-legend" class="daily-board-legend"></div>
                        </article>
                    </div>
                    <p id="role-dashboard-error" class="daily-board-error" hidden>No se pudo actualizar el estado del equipo.</p>
                </section>
                <section class="leader-activities-split" aria-label="Actividades propias y del equipo">
                    <article class="leader-activity-panel leader-own-activities" aria-labelledby="leader-own-activities-title">
                        <div class="leader-activity-heading">
                            <div>
                                <p class="eyebrow">TU TRABAJO</p>
                                <h2 id="leader-own-activities-title">Mis actividades como líder</h2>
                            </div>
                            <span class="leader-activity-count" id="leader-own-activities-count" aria-live="polite">Cargando…</span>
                        </div>
                        <div class="activities-container">
                            <div data-activities-list data-activity-scope="own" data-activity-count-target="leader-own-activities-count">
                                <div class="activity-skeleton" aria-label="Cargando tus actividades"></div>
                            </div>
                        </div>
                    </article>
                    <article class="leader-activity-panel leader-team-activities" aria-labelledby="leader-team-activities-title">
                        <div class="leader-activity-heading">
                            <div>
                                <p class="eyebrow">RESTO DEL EQUIPO</p>
                                <h2 id="leader-team-activities-title">Actividades del equipo</h2>
                            </div>
                            <span class="leader-activity-count" id="leader-team-activities-count" aria-live="polite">Cargando…</span>
                        </div>
                        <div id="team-live-activities" class="team-live-activities" aria-live="polite">
                            <div class="activity-skeleton" aria-label="Cargando actividades del equipo"></div>
                        </div>
                        <a href="actividades.php" class="text-link">Abrir gestión de actividades →</a>
                    </article>
                </section>
            <?php elseif ($role === ROLE_BOSS): ?>
                <section class="executive-overview" aria-labelledby="executive-overview-title">
                    <div class="section-heading">
                        <div><p class="eyebrow">HOY</p><h2 id="executive-overview-title">Indicadores principales</h2></div>
                    </div>
                    <div id="role-dashboard-metrics" class="daily-board-metrics" aria-live="polite">
                        <div class="activity-skeleton" aria-label="Cargando indicadores"></div>
                    </div>
                    <div class="daily-board-visuals">
                        <article class="daily-board-chart-card">
                            <h3>Actividades asignadas y espontáneas</h3>
                            <div id="executive-activity-chart" class="daily-board-pie" role="img" aria-label="Distribución por tipo de actividad"></div>
                            <div id="executive-activity-legend" class="daily-board-legend"></div>
                        </article>
                        <article class="daily-board-chart-card">
                            <h3>Tiempo trabajado y tiempo muerto</h3>
                            <div id="executive-time-chart" class="daily-board-pie" role="img" aria-label="Distribución de tiempo"></div>
                            <div id="executive-time-legend" class="daily-board-legend"></div>
                        </article>
                    </div>
                    <p id="role-dashboard-error" class="daily-board-error" hidden>No se pudieron actualizar los indicadores.</p>
                </section>
            <?php endif; ?>

            <?php if (can("view_notifications", $user)): ?>
                <section class="boss-notifications-panel" aria-labelledby="boss-notifications-title">
                    <div class="boss-notifications-header">
                        <h2 id="boss-notifications-title"><?php echo can("view_global_activities", $user) ? "Novedades del equipo" : "Mis notificaciones"; ?> <span id="boss-notifications-count" class="notification-count">0</span></h2>
                        <span class="notifications-live">Actualización automática</span>
                    </div>
                    <div id="boss-notifications-list">
                        <p class="notifications-empty">Cargando novedades...</p>
                    </div>
                </section>
            <?php endif; ?>

        </div>

        <footer class="footer">
            <p>ActividadesApp - Gestión de tiempo y tareas</p>
        </footer>
        </main>
    </div>
    <script src="js/theme.js"></script>
    <?php if (can("view_own_activities", $user)): ?><script src="js/app.js"></script><?php endif; ?>
    <script src="js/boss_notifications.js"></script>
    <?php if (in_array($role, [ROLE_LEADER, ROLE_BOSS], true)): ?><script src="js/role_dashboard.js"></script><?php endif; ?>
</body>
</html>