<?php
/**
 * Database bootstrap — PDO singleton.
 * Credentials come from .env (preferred) or server environment vars.
 * Defaults match local XAMPP so no .env is needed on dev machines.
 */
declare(strict_types=1);

/* Errors are logged, never shown in the browser. */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/* Private error log outside the web root when possible; fallback inside storage/. */
$__logDir = dirname(__DIR__) . '/storage/logs';
if (!is_dir($__logDir)) {
    @mkdir($__logDir, 0755, true);
}
if (is_dir($__logDir) && is_writable($__logDir)) {
    ini_set('error_log', $__logDir . '/php_errors.log');
}

/**
 * Load key=value pairs from .env into getenv() without overriding
 * real server/environment variables (cPanel, Docker, systemd win).
 */
function load_env_file(string $path): void
{
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}
load_env_file(dirname(__DIR__) . '/.env');

function env(string $key, string $default = ''): string
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

final class Database
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = env('DB_HOST', '127.0.0.1');
        $name = env('DB_NAME', 'cadet_db');
        $user = env('DB_USER', 'root');
        $pass = env('DB_PASS', '');
        $port = (int)env('DB_PORT', '3306');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $ex) {
            error_log('[DB] connection failed: ' . $ex->getMessage());
            /* Render the standalone 500 page — no DB, no session. */
            if (!headers_sent()) {
                http_response_code(500);
                require dirname(__DIR__) . '/error/500.php';
                exit;
            }
            exit('Service temporarily unavailable.');
        }

        return self::$pdo;
    }
}

/** Shorthand: active PDO connection. */
function db(): PDO
{
    return Database::conn();
}
