<?php
/**
 * Logout — CSRF-protected POST, full session teardown.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth_check.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect(portal_base_path() . '/login.php');
}

csrf_check();

audit_log('logout');

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'] ?? 'Lax',
    ]);
}
session_destroy();

/* Fresh session so the login page can show a flash message. */
session_start();
session_regenerate_id(true);
flash('success', 'You have been signed out securely.');
redirect(portal_base_path() . '/login.php');
