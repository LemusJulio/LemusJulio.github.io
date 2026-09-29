<?php
require_once __DIR__ . "/bootstrap.php";
requirePermission("manage_users");

$user = currentUser();
$usersData = loadJson("datos.json", ["usuarios" => []]);
$users = $usersData["usuarios"] ?? [];
$errors = [];
$success = isset($_GET["success"]);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verifyCsrf($_POST["csrf_token"] ?? null)) {
        $errors[] = "La sesión del formulario expiró. Intenta de nuevo.";
    }

    $name = trim($_POST["nombre"] ?? "");
    $pin = trim($_POST["pin"] ?? "");
    $role = $_POST["rol"] ?? "";
    $rate = filter_var($_POST["tarifa_por_hora"] ?? null, FILTER_VALIDATE_FLOAT);

    if ($name === "") {
        $errors[] = "El nombre es obligatorio.";
    }
    if (!preg_match("/^\d{4}$/", $pin)) {
        $errors[] = "El PIN debe tener exactamente 4 dígitos.";
    }
    if (!in_array($role, ["almacen", "lider"], true)) {
        $errors[] = "El rol seleccionado no es válido.";
    }
    if ($rate === false || $rate < 0) {
        $errors[] = "La tarifa por hora no es válida.";
    }
    foreach ($users as $existingUser) {
        if (strcasecmp($existingUser["nombre"] ?? "", $name) === 0) {
            $errors[] = "Ya existe un usuario con ese nombre.";
            break;
        }
    }

    if (empty($errors)) {
        $users[] = [
            "nombre" => $name,
            "pin" => password_hash($pin, PASSWORD_DEFAULT),
            "rol" => $role,
            "tarifa_por_hora" => (float)$rate
        ];
        $usersData["usuarios"] = $users;
        saveJson("datos.json", $usersData);
        header("Location: usuarios.php?success=1");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Alta y administración de usuarios de ActividadesApp.">
    <title>Usuarios - ActividadesApp</title>
    <link rel="stylesheet" href="css/style.css">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, "UTF-8"); ?>">
</head>
<body>
    <div class="container">
        <?php require __DIR__ . "/partials/sidebar.php"; ?>
        <main id="contenido-principal">
        <header class="dashboard-header">
            <h1>Gestión de usuarios</h1>
            <div class="user-info">
                <span><?php echo htmlspecialchars($user["nombre"]); ?></span>
                <span class="user-role">Rol: <?php echo htmlspecialchars(roleLabel($user["rol"])); ?></span>
            </div>
            <button type="button" id="theme-toggle" class="btn theme-toggle" aria-pressed="false">🌙 Modo oscuro</button>
        </header>

        <a href="dashboard.php" class="btn btn-back">← Volver al inicio</a>

        <?php if ($success): ?>
            <div class="alert alert-success">Usuario dado de alta correctamente.</div>
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
                <h2>Dar de alta a una persona</h2>
                <p>Registra un trabajador o líder con su acceso al sistema.</p>

            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, "UTF-8"); ?>">
                <div class="form-group">
                    <label for="nombre">Nombre *</label>
                    <input type="text" id="nombre" name="nombre" required value="<?php echo htmlspecialchars($_POST["nombre"] ?? ""); ?>">
                </div>
                <div class="form-group">
                    <label for="pin">PIN de acceso *</label>
                    <input type="password" id="pin" name="pin" maxlength="4" inputmode="numeric" pattern="\d{4}" required>
                </div>
                <div class="form-group">
                    <label for="rol">Rol *</label>
                    <select id="rol" name="rol" required>
                        <option value="">Selecciona un rol</option>
                        <option value="almacen" <?php echo (($_POST["rol"] ?? "") === "almacen") ? "selected" : ""; ?>>Trabajador</option>
                        <option value="lider" <?php echo (($_POST["rol"] ?? "") === "lider") ? "selected" : ""; ?>>Líder</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="tarifa_por_hora">Tarifa por hora *</label>
                    <input type="number" id="tarifa_por_hora" name="tarifa_por_hora" min="0" step="0.01" required value="<?php echo htmlspecialchars($_POST["tarifa_por_hora"] ?? "0"); ?>">
                </div>
                <button type="submit" class="btn btn-submit">Dar de alta</button>
            </form>
        </section>

        <section class="dashboard-shortcut">
            <h2>¿Alguien olvidó su PIN?</h2>
            <p>Restablece el acceso desde una vista separada sin mostrar contraseñas almacenadas.</p>
            <a href="recuperar_pin.php" class="btn btn-warning">Ir a recuperar PIN</a>
        </section>

        <section class="activities-list">
            <h2>Personas registradas</h2>
            <div class="table-scroll-container" role="region" aria-label="Personas registradas" tabindex="0">
            <table class="activities-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Rol</th>
                        <th>Tarifa por hora</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $registeredUser): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($registeredUser["nombre"] ?? ""); ?></td>
                            <td><?php echo htmlspecialchars(roleLabel($registeredUser["rol"] ?? "")); ?></td>
                            <td>$<?php echo number_format((float)($registeredUser["tarifa_por_hora"] ?? 0), 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </section>
        </main>
    </div>
    <script src="js/theme.js"></script>
</body>
</html>
