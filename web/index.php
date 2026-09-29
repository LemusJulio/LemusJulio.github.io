<?php
require_once __DIR__ . "/bootstrap.php";
if (isset($_SESSION["user"])) {
    header("Location: dashboard.php");
    exit;
} else {
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redirección - ActividadesApp</title>
</head>
<body>
    <p>Redirigiendo...</p>
    <meta http-equiv="refresh" content="0;url=<?php echo isset($_SESSION["user"]) ? "dashboard.php" : "login.php"; ?>">
</body>
</html>