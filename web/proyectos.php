<?php
require_once __DIR__ . "/bootstrap.php";
requirePermission("manage_projects");

$user = currentUser();
$projectsData = loadJson("proyectos.json", ["proyectos" => []]);
$projects = is_array($projectsData["proyectos"] ?? null) ? $projectsData["proyectos"] : [];
$errors = [];
$success = isset($_GET["success"]);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verifyCsrf($_POST["csrf_token"] ?? null)) {
        $errors[] = "La sesión del formulario expiró. Intenta de nuevo.";
    }

    $name = trim($_POST["nombre"] ?? "");
    $factor = filter_var($_POST["factor"] ?? null, FILTER_VALIDATE_FLOAT);
    $action = $_POST["action"] ?? "create";

    if ($action === "update_factor") {
        $projectName = trim($_POST["project_name"] ?? "");
        $projectIndex = null;
        foreach ($projects as $index => $project) {
            if ((string)($project["nombre"] ?? "") === $projectName) {
                $projectIndex = $index;
                break;
            }
        }
        if ($projectIndex === null) {
            $errors[] = "No se encontró el proyecto seleccionado.";
        }
        if ($factor === false || $factor <= 0 || $factor > 100) {
            $errors[] = "El factor debe ser mayor que 0 y no superar 100.";
        }
        if (empty($errors)) {
            $projects[$projectIndex]["factor"] = (float)$factor;
            saveJson("proyectos.json", ["proyectos" => $projects]);
            header("Location: proyectos.php?success=2");
            exit;
        }
    }

    if ($action === "create" && $name === "") {
        $errors[] = "El nombre del proyecto es obligatorio.";
    } elseif ($action === "create" && mb_strlen($name) > 120) {
        $errors[] = "El nombre del proyecto no puede superar 120 caracteres.";
    }
    if ($action === "create" && ($factor === false || $factor <= 0 || $factor > 100)) {
        $errors[] = "El factor debe ser mayor que 0 y no superar 100.";
    }

    foreach ($projects as $project) {
        if ($action !== "create") {
            break;
        }
        if (strcasecmp((string)($project["nombre"] ?? ""), $name) === 0) {
            $errors[] = "Ya existe un proyecto con ese nombre.";
            break;
        }
    }

    if ($action === "create" && empty($errors)) {
        $projects[] = [
            "nombre" => $name,
            "factor" => (float)$factor,
            "createdAt" => date("Y-m-d H:i:s"),
            "createdBy" => $user["nombre"]
        ];
        saveJson("proyectos.json", ["proyectos" => $projects]);
        header("Location: proyectos.php?success=1");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Alta y administración de proyectos de ActividadesApp.">
    <title>Proyectos - ActividadesApp</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <?php require __DIR__ . "/partials/sidebar.php"; ?>
        <main id="contenido-principal">
            <header class="dashboard-header">
                <h1>Gestión de proyectos</h1>
                <div class="user-info">
                    <span><?php echo htmlspecialchars($user["nombre"]); ?></span>
                    <span class="user-role">Rol: <?php echo htmlspecialchars(roleLabel($user["rol"])); ?></span>
                </div>
                <button type="button" id="theme-toggle" class="btn theme-toggle" aria-pressed="false">🌙 Modo oscuro</button>
            </header>

            <a href="dashboard.php" class="btn btn-back">← Volver al inicio</a>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <?php echo isset($_GET["success"]) && $_GET["success"] === "2" ? "Factor actualizado correctamente." : "Proyecto dado de alta correctamente."; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error): ?>
                        <p><?php echo htmlspecialchars($error); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <section class="create-activity-form">
                <div class="form-section-header">
                    <h2>Dar de alta un proyecto</h2>
                    <p>Los proyectos registrados aparecerán al crear o editar actividades.</p>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, "UTF-8"); ?>">
                    <div class="form-group">
                        <label for="nombre">Nombre del proyecto *</label>
                        <input type="text" id="nombre" name="nombre" maxlength="120" required value="<?php echo htmlspecialchars($_POST["nombre"] ?? ""); ?>">
                    </div>
                    <div class="form-group">
                        <label for="factor">Factor de tarifa *</label>
                        <input type="number" id="factor" name="factor" min="0.01" max="100" step="0.01" required value="<?php echo htmlspecialchars($_POST["factor"] ?? "1.00"); ?>">
                        <small class="form-help">El pago se calcula como horas × tarifa × factor.</small>
                    </div>
                    <button type="submit" class="btn btn-submit">Dar de alta</button>
                </form>
            </section>

            <section class="activities-list">
                <h2>Proyectos registrados</h2>
                <?php if (empty($projects)): ?>
                    <p>No hay proyectos registrados.</p>
                <?php else: ?>
                    <div class="table-scroll-container" role="region" aria-label="Proyectos registrados" tabindex="0">
                    <table class="activities-table">
                        <thead>
                            <tr>
                                <th>Proyecto</th>
                                <th>Factor</th>
                                <th>Fecha de alta</th>
                                <th>Registrado por</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projects as $project): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($project["nombre"] ?? ""); ?></td>
                                    <td>
                                        <form method="POST" class="inline-factor-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, "UTF-8"); ?>">
                                            <input type="hidden" name="action" value="update_factor">
                                            <input type="hidden" name="project_name" value="<?php echo htmlspecialchars($project["nombre"] ?? "", ENT_QUOTES, "UTF-8"); ?>">
                                            <input type="number" name="factor" min="0.01" max="100" step="0.01" value="<?php echo htmlspecialchars(number_format((float)($project["factor"] ?? 1), 2, ".", ""), ENT_QUOTES, "UTF-8"); ?>" aria-label="Factor de <?php echo htmlspecialchars($project["nombre"] ?? ""); ?>">
                                            <button type="submit" class="btn btn-secondary">Guardar</button>
                                        </form>
                                    </td>
                                    <td><?php echo htmlspecialchars($project["createdAt"] ?? "—"); ?></td>
                                    <td><?php echo htmlspecialchars($project["createdBy"] ?? "—"); ?></td>
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
