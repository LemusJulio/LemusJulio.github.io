<?php
require_once __DIR__ . "/bootstrap.php";
requirePermission("view_dashboard");
$user = currentUser();
$role = canonicalRole($user["rol"] ?? null);
$roleDescriptions = [
    ROLE_WORKER => "Consulta y registra el tiempo de tus actividades.",
    ROLE_LEADER => "Supervisa el avance del equipo y organiza actividades.",
    ROLE_BOSS => "Administra proyectos, personal, reportes y aprobaciones."
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil - ActividadesApp</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="container">
    <?php require __DIR__ . "/partials/sidebar.php"; ?>
    <main id="contenido-principal">
        <header class="page-header"><div><p class="eyebrow">CUENTA</p><h1>Perfil</h1></div></header>
        <section class="profile-card">
            <span class="profile-avatar" aria-hidden="true"><?php echo htmlspecialchars(mb_strtoupper(mb_substr((string)$user["nombre"], 0, 1)), ENT_QUOTES, "UTF-8"); ?></span>
            <div><p class="eyebrow">USUARIO</p><h2><?php echo htmlspecialchars((string)$user["nombre"], ENT_QUOTES, "UTF-8"); ?></h2><p><?php echo htmlspecialchars(roleLabel($user["rol"] ?? null), ENT_QUOTES, "UTF-8"); ?></p><p class="form-help"><?php echo htmlspecialchars($roleDescriptions[$role] ?? "", ENT_QUOTES, "UTF-8"); ?></p></div>
            <a class="btn btn-danger" href="logout.php">Cerrar sesión</a>
        </section>
    </main>
</div>
<script src="js/theme.js"></script>
</body>
</html>
