<?php
$currentPage = basename($_SERVER["PHP_SELF"] ?? "");
$navUser = currentUser();
$navRole = canonicalRole($navUser["rol"] ?? null);
if ($navRole === ROLE_WORKER) {
    $navigationItems = [
        ["label" => "Mi día", "href" => "dashboard.php", "permission" => "view_dashboard", "icon" => "⌂"],
        ["label" => "Mi historial", "href" => "mi_historial.php", "permission" => "view_own_activities", "icon" => "◷"],
        ["label" => "Perfil", "href" => "perfil.php", "permission" => "view_dashboard", "icon" => "♙"]
    ];
} elseif ($navRole === ROLE_LEADER) {
    $navigationItems = [
        ["label" => "Equipo", "href" => "dashboard.php", "permission" => "view_global_activities", "icon" => "▦"],
        ["label" => "Actividades", "href" => "actividades.php", "permission" => "manage_activities", "icon" => "✓"],
        ["label" => "Historial", "href" => "historial_actividades.php", "permission" => "view_global_activities", "icon" => "◷"],
        ["label" => "Perfil", "href" => "perfil.php", "permission" => "view_dashboard", "icon" => "♙"]
    ];
} else {
    $navigationItems = [
        ["label" => "Dashboard", "href" => "dashboard.php", "permission" => "view_dashboard", "icon" => "⌂"],
        ["label" => "Actividades", "href" => "actividades.php", "permission" => "manage_activities", "icon" => "✓"],
        ["label" => "Proyectos", "href" => "proyectos.php", "permission" => "manage_projects", "icon" => "◇"],
        ["label" => "Equipo", "href" => "usuarios.php", "permission" => "manage_users", "icon" => "♙"],
        ["label" => "Reportes y pagos", "href" => "reportes.php", "permission" => "view_reports", "icon" => "▤"],
        ["label" => "Historial", "href" => "historial_actividades.php", "permission" => "view_global_activities", "icon" => "◷"],
        ["label" => "Perfil", "href" => "perfil.php", "permission" => "view_dashboard", "icon" => "♙"]
    ];
}
$navigationItems = array_values(array_filter($navigationItems, static fn(array $item): bool => can($item["permission"], $navUser)));
?>
<nav class="sidebar" aria-label="Navegación principal">
    <a class="sidebar-title" href="dashboard.php">Actividades<span>App</span></a>
    <div class="sidebar-user">
        <span class="sidebar-avatar" aria-hidden="true"><?php echo htmlspecialchars(mb_strtoupper(mb_substr((string)($navUser["nombre"] ?? "U"), 0, 1)), ENT_QUOTES, "UTF-8"); ?></span>
        <span><strong><?php echo htmlspecialchars((string)($navUser["nombre"] ?? ""), ENT_QUOTES, "UTF-8"); ?></strong><small><?php echo htmlspecialchars(roleLabel($navUser["rol"] ?? null), ENT_QUOTES, "UTF-8"); ?></small></span>
    </div>
    <div class="sidebar-links" role="list">
        <?php foreach ($navigationItems as $index => $item): ?>
            <?php if (can($item["permission"])): ?>
                <a class="sidebar-link<?php echo !str_contains($item["href"], "#") && $currentPage === basename($item["href"]) ? " is-active" : ""; ?>"
                   href="<?php echo htmlspecialchars($item["href"], ENT_QUOTES, "UTF-8"); ?>"
                   aria-current="<?php echo !str_contains($item["href"], "#") && $currentPage === basename($item["href"]) ? "page" : "false"; ?>"
                   data-mobile-primary="<?php echo $index < 4 ? "true" : "false"; ?>">
                    <span class="sidebar-link-icon" aria-hidden="true"><?php echo htmlspecialchars($item["icon"], ENT_QUOTES, "UTF-8"); ?></span>
                    <?php echo htmlspecialchars($item["label"], ENT_QUOTES, "UTF-8"); ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
        <a class="sidebar-link sidebar-logout" href="logout.php" title="Cerrar sesión"><span class="sidebar-link-icon" aria-hidden="true">↪</span><span class="logout-label">Cerrar sesión</span></a>
    </div>
    <?php if (count($navigationItems) > 4): ?>
    <details class="mobile-more">
        <summary aria-label="Más opciones de navegación"><span aria-hidden="true">•••</span><span>Más</span></summary>
        <div class="mobile-more-menu">
            <?php foreach ($navigationItems as $index => $item): ?>
                <?php if ($index >= 4): ?>
                    <a class="mobile-more-link<?php echo $currentPage === basename($item["href"]) ? " is-active" : ""; ?>"
                       href="<?php echo htmlspecialchars($item["href"], ENT_QUOTES, "UTF-8"); ?>"
                       <?php echo $currentPage === basename($item["href"]) ? 'aria-current="page"' : ""; ?>>
                        <span aria-hidden="true"><?php echo htmlspecialchars($item["icon"], ENT_QUOTES, "UTF-8"); ?></span>
                        <?php echo htmlspecialchars($item["label"], ENT_QUOTES, "UTF-8"); ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
            <a class="mobile-more-link mobile-more-logout" href="logout.php"><span aria-hidden="true">↪</span>Cerrar sesión</a>
        </div>
    </details>
    <?php endif; ?>
</nav>
<script src="js/responsive_tables.js" defer></script>
