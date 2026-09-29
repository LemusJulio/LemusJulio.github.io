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

    $resetUserName = trim($_POST["reset_user"] ?? "");
    $newPin = trim($_POST["new_pin"] ?? "");
    $resetIndex = null;

    if (!preg_match("/^\d{4}$/", $newPin)) {
        $errors[] = "El nuevo PIN debe tener exactamente 4 dígitos.";
    }

    foreach ($users as $index => $registeredUser) {
        if (($registeredUser["nombre"] ?? "") === $resetUserName) {
            $resetIndex = $index;
            break;
        }
    }

    if ($resetIndex === null) {
        $errors[] = "No se encontró la persona seleccionada.";
    }

    if (empty($errors)) {
        $users[$resetIndex]["pin"] = password_hash($newPin, PASSWORD_DEFAULT);
        $usersData["usuarios"] = $users;
        saveJson("datos.json", $usersData);
        header("Location: recuperar_pin.php?success=1");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Restablecimiento seguro del PIN de acceso de los usuarios.">
    <title>Recuperar PIN - ActividadesApp</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <?php require __DIR__ . "/partials/sidebar.php"; ?>
        <main id="contenido-principal">
        <header class="dashboard-header">
            <h1>Recuperar PIN</h1>
            <div class="user-info">
                <span><?php echo htmlspecialchars($user["nombre"]); ?></span>
                <span class="user-role">Rol: <?php echo htmlspecialchars(roleLabel($user["rol"])); ?></span>
            </div>
            <button type="button" id="theme-toggle" class="btn theme-toggle" aria-pressed="false">🌙 Modo oscuro</button>
        </header>

        <a href="usuarios.php" class="btn btn-back">← Volver a gestión de usuarios</a>

        <?php if ($success): ?>
            <div class="alert alert-success">El PIN fue restablecido correctamente. Entrégalo directamente al usuario.</div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <p><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="edit-form">
            <div class="form-section-header">
                <h2>Restablecer acceso</h2>
                <p>El PIN anterior no se puede visualizar. Define uno nuevo y comunícaselo directamente a la persona.</p>

            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, "UTF-8"); ?>">
                <div class="form-group">
                    <label for="reset_user">Persona *</label>
                    <select id="reset_user" name="reset_user" required>
                        <option value="">Selecciona una persona</option>
                        <?php foreach ($users as $registeredUser): ?>
                            <option value="<?php echo htmlspecialchars($registeredUser["nombre"], ENT_QUOTES, "UTF-8"); ?>">
                                <?php echo htmlspecialchars($registeredUser["nombre"]); ?> (<?php echo htmlspecialchars(roleLabel($registeredUser["rol"] ?? "")); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="new_pin">Nuevo PIN *</label>
                    <input type="password" id="new_pin" name="new_pin" maxlength="4" inputmode="numeric" pattern="\d{4}" required>
                    <label class="password-toggle">
                        <input type="checkbox" id="show-new-pin">
                        Mostrar PIN mientras lo capturas
                    </label>
                </div>
                <button type="submit" class="btn btn-warning">Restablecer PIN</button>
            </form>
        </section>
        </main>
    </div>
    <script src="js/theme.js"></script>
    <script>
        document.getElementById("show-new-pin").addEventListener("change", function () {
            document.getElementById("new_pin").type = this.checked ? "text" : "password";
        });
    </script>
</body>
</html>
