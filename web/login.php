<?php
require_once __DIR__ . "/bootstrap.php";

// Si ya hay sesión activa, redirigir al dashboard
if (isset($_SESSION["user"])) {
    header("Location: dashboard.php");
    exit;
}

// Regenerar ID de sesión para prevenir fixation
$error = "";
$users = loadJson("datos.json", ["usuarios" => []]);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verifyCsrf($_POST["csrf_token"] ?? null)) {
        $error = "La sesión del formulario expiró. Intenta de nuevo.";
    }
    $nombre = trim($_POST["nombre"] ?? "");
    $pin = trim($_POST["pin"] ?? "");

    if ($error === "") {
        // Validar que el PIN tenga exactamente 4 dígitos
        if (!preg_match("/^\d{4}$/", $pin)) {
        $error = "El PIN debe tener exactamente 4 dígitos.";
        } elseif (empty($nombre) || empty($pin)) {
        $error = "Por favor, ingresa tu nombre y PIN.";
        }
    }
    if ($error === "") {
        $found = null;
        foreach ($users["usuarios"] as $u) {
            $pinMatches = isset($u["pin"]) && (
                password_verify($pin, $u["pin"]) ||
                (preg_match("/^\d{4}$/", (string)$u["pin"]) && hash_equals((string)$u["pin"], $pin))
            );
            if (($u["nombre"] ?? "") === $nombre && $pinMatches) {
                $found = $u;
                break;
            }
        }
        if ($found) {
            if (preg_match("/^\d{4}$/", (string)$found["pin"])) {
                $found["pin"] = password_hash($pin, PASSWORD_DEFAULT);
                foreach ($users["usuarios"] as &$userRecord) {
                    if (($userRecord["nombre"] ?? "") === $found["nombre"]) {
                        $userRecord = $found;
                        break;
                    }
                }
                unset($userRecord);
                saveJson("datos.json", $users);
            }
            session_regenerate_id(true);
            $_SESSION["user"] = $found;
            header("Location: dashboard.php");
            exit;
        } else {
            $error = "Nombre o PIN incorrectos.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Acceso seguro a ActividadesApp para gestionar actividades y tiempos.">
    <title>Login - ActividadesApp</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <main id="contenido-principal">
        <div class="login-toolbar">
            <button type="button" id="theme-toggle" class="btn theme-toggle" aria-pressed="false">🌙 Modo oscuro</button>
        </div>
        <div class="login-container">
            <h2>Bienvenido al Sistema</h2>

            <?php if ($error): ?>
                <div class="login-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" class="login-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, "UTF-8"); ?>">
                <div class="form-group">
                    <label for="nombre">Nombre:</label>
                    <input type="text" id="nombre" name="nombre" required placeholder="Tu nombre" autocomplete="off">
                </div>

                <div class="form-group">
                    <label for="pin">PIN (4 dígitos):</label>
                    <div class="password-input">
                        <input type="password" id="pin" name="pin" maxlength="4" required placeholder="0000" inputmode="numeric" pattern="[0-9]{4}" autocomplete="off">
                        <button type="button" class="password-reveal" data-password-target="pin" aria-label="Mostrar PIN" aria-pressed="false">
                            <svg class="password-icon password-icon-show" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path>
                                <circle cx="12" cy="12" r="2.5"></circle>
                            </svg>
                            <svg class="password-icon password-icon-hide" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="m3 3 18 18"></path>
                                <path d="M10.6 6.2A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6a17.5 17.5 0 0 1-3.1 3.8M6.2 6.2C3.8 7.8 2.5 12 2.5 12s3.5 6 9.5 6c1.2 0 2.3-.2 3.3-.7"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Entrar</button>
            </form>
        </div>
        </main>
    </div>
    <script src="js/theme.js"></script>
    <script>
        const pinInput = document.getElementById("pin");
        pinInput.addEventListener("input", function () {
            this.value = this.value.replace(/\D/g, "").slice(0, 4);
        });

        document.querySelectorAll(".password-reveal").forEach(function (button) {
            button.addEventListener("click", function () {
                const input = document.getElementById(button.dataset.passwordTarget);
                const isVisible = input.type === "text";
                input.type = isVisible ? "password" : "text";
                button.setAttribute("aria-pressed", isVisible ? "false" : "true");
                button.setAttribute("aria-label", isVisible ? "Mostrar PIN" : "Ocultar PIN");
                button.classList.toggle("is-visible", !isVisible);
            });
        });
    </script>
</body>
</html>