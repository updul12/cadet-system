<?php
/** Output escaping, CSRF, safe redirects, session bootstrap. */
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $base = rtrim($dir, '/');
    /* Portal lives at /portal — strip it and any module subfolder. */
    if (preg_match('#^(.*)/portal(?:/.*)?$#', $base, $m)) {
        $base = $m[1] ?: '';
    }
    return $base;
}

function portal_base_path(): string
{
    return app_base_path() . '/portal';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals(csrf_token(), $sent)) {
        header('HTTP/1.1 419 Authentication Timeout', true, 419);
        exit('Invalid or expired form token. Please go back and try again.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flashes'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $all = $_SESSION['flashes'] ?? [];
    unset($_SESSION['flashes']);
    return is_array($all) ? $all : [];
}

function redirect(string $path): never
{
    $blocked = str_contains($path, "\r")
        || str_contains($path, "\n")
        || str_starts_with($path, '//')
        || preg_match('#^[a-z][a-z0-9+.-]*:#i', $path);

    if ($blocked || !preg_match('#^(?:[A-Za-z0-9_./?=&% \+-]|\.\./)+$#', $path)) {
        $path = portal_base_path() . '/login.php';
    }
    header('Location: ' . $path, true, 303);
    exit;
}

function bootstrap_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', $secure ? '1' : '0');

    session_name('CADETSESS');
    session_start([
        'cookie_lifetime' => 0,
        'cookie_path'     => '/',
        'cookie_secure'   => $secure,
        'cookie_httponly' => true,
        'use_strict_mode' => true,
    ]);

    $idleLimit = 1800;
    $now = time();
    if (isset($_SESSION['last_activity']) && ($now - (int)$_SESSION['last_activity']) > $idleLimit) {
        $_SESSION = [];
        session_destroy();
        session_start();
        flash('warning', 'Your session expired due to inactivity. Please sign in again.');
        header('Location: ' . portal_base_path() . '/login.php', true, 303);
        exit;
    }
    $_SESSION['last_activity'] = $now;
}
