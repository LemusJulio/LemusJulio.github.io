<?php
require_once __DIR__ . "/bootstrap.php";
$sessionCookie = session_get_cookie_params();
session_destroy();
setcookie(session_name(), "", [
    "expires" => time() - 42000,
    "path" => $sessionCookie["path"],
    "domain" => $sessionCookie["domain"],
    "secure" => $sessionCookie["secure"],
    "httponly" => $sessionCookie["httponly"],
    "samesite" => $sessionCookie["samesite"]
]);
header("Location: login.php");
exit;
?>