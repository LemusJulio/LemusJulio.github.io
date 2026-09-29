<?php
declare(strict_types=1);

$isHttps = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off");
session_set_cookie_params([
    "httponly" => true,
    "secure" => $isHttps,
    "samesite" => "Lax"
]);
session_start();

const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . "data";

const ROLE_WORKER = "trabajador";
const ROLE_LEADER = "lider";
const ROLE_BOSS = "jefe";

function canonicalRole(?string $role): string
{
    return match ($role) {
        "almacen", "trabajador" => ROLE_WORKER,
        "director", "jefe" => ROLE_BOSS,
        "lider" => ROLE_LEADER,
        default => ""
    };
}

function roleLabel(?string $role): string
{
    return match (canonicalRole($role)) {
        ROLE_WORKER => "Trabajador",
        ROLE_LEADER => "Líder",
        ROLE_BOSS => "Jefe",
        default => "Sin rol"
    };
}

function can(string $permission, ?array $user = null): bool
{
    $user ??= currentUser();
    $role = canonicalRole($user["rol"] ?? null);
    $permissions = [
        "view_dashboard" => [ROLE_WORKER, ROLE_LEADER, ROLE_BOSS],
        "use_timer" => [ROLE_WORKER, ROLE_LEADER],
        "view_own_activities" => [ROLE_WORKER, ROLE_LEADER],
        "manage_activities" => [ROLE_LEADER, ROLE_BOSS],
        "view_global_activities" => [ROLE_LEADER, ROLE_BOSS],
        "view_reports" => [ROLE_BOSS],
        "manage_users" => [ROLE_BOSS],
        "manage_projects" => [ROLE_BOSS],
        "view_notifications" => [ROLE_WORKER, ROLE_LEADER, ROLE_BOSS]
    ];

    return in_array($role, $permissions[$permission] ?? [], true);
}

function requirePermission(string $permission, bool $json = false): void
{
    if (currentUser() === null) {
        if ($json) {
            jsonResponse(["error" => "No autorizado"], 401);
        }
        header("Location: login.php");
        exit;
    }

    if (!can($permission)) {
        if ($json) {
            jsonResponse(["error" => "Permisos insuficientes"], 403);
        }
        http_response_code(403);
        exit("Acceso prohibido");
    }
}

function loadJson(string $filename, array $default = []): array
{
    $path = DATA_DIR . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
        return $default;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("No se pudo leer {$filename}.");
    }

    $data = json_decode($contents, true);
    if (!is_array($data)) {
        throw new RuntimeException("El archivo {$filename} no contiene JSON válido.");
    }

    return $data;
}

function saveJson(string $filename, array $data): void
{
    $path = DATA_DIR . DIRECTORY_SEPARATOR . $filename;
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException("No se pudo guardar {$filename}.");
    }
}

function csrfToken(): string
{
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    return $_SESSION["csrf_token"];
}

function verifyCsrf(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION["csrf_token"])
        && hash_equals($_SESSION["csrf_token"], $token);
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function currentUser(): ?array
{
    return isset($_SESSION["user"]) && is_array($_SESSION["user"])
        ? $_SESSION["user"]
        : null;
}
