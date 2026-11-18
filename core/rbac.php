<?php
/** RBAC gates — current user, role/module checks, permission predicates. */
declare(strict_types=1);

function current_user(): ?array
{
    static $cache = false;
    static $user = null;
    if ($cache) {
        return $user;
    }
    $cache = true;

    $id = $_SESSION['user_id'] ?? null;
    if (!is_int($id) && !ctype_digit((string)$id)) {
        return null;
    }

    try {
        $stmt = db()->prepare(
            'SELECT id, cadet_id, username, photo, role_level, status, last_login
               FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([(int)$id]);
        $row = $stmt->fetch();
    } catch (Throwable $ex) {
        error_log('[AUTH] ' . $ex->getMessage());
        return null;
    }

    if (!$row) {
        unset($_SESSION['user_id'], $_SESSION['username']);
        return null;
    }
    if ($row['status'] !== 'Active') {
        audit_log('session_killed_suspended', 'users', (int)$row['id'], $row['username']);
        $_SESSION = [];
        session_destroy();
        session_start();
        flash('danger', 'This account has been suspended.');
        header('Location: ' . portal_base_path() . '/login.php', true, 303);
        exit;
    }

    $user = $row;
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function requireLogin(): array
{
    $u = current_user();
    if ($u === null) {
        flash('warning', 'Please sign in to continue.');
        redirect(portal_base_path() . '/login.php');
    }
    return $u;
}

function requireRole(array $roles): array
{
    $u = requireLogin();
    if (!in_array($u['role_level'], $roles, true)) {
        audit_log('access_denied', null, null, 'role=' . $u['role_level'] . ' needs ' . implode(',', $roles));
        http_response_code(403);
        $allowed = e(implode(', ', $roles));
        $role = e($u['role_level']);
        exit('<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>403 · Access denied</title>'
            . '<link rel="stylesheet" href="' . e(app_base_path()) . '/assets/vendor/bootstrap.min.css">'
            . '<link rel="stylesheet" href="' . e(app_base_path()) . '/assets/vendor/bootstrap-icons.css">'
            . '</head><body class="bg-light"><div class="container" style="max-width:560px">'
            . '<div class="card shadow-sm border-danger mt-5"><div class="card-body text-center p-4">'
            . '<h1 class="text-danger display-6"><i class="bi bi-shield-x"></i> 403</h1>'
            . '<p class="text-muted">Your role (<strong>' . $role . '</strong>) is not permitted. '
            . 'Required: <strong>' . $allowed . '</strong>.</p>'
            . '<a class="btn btn-outline-secondary" href="javascript:history.back()">Go back</a>'
            . '</div></div></div></body></html>');
    }
    return $u;
}

function allowed_modules(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $u = current_user();
    if ($u === null) {
        return $cache = [];
    }
    if ($u['role_level'] === 'Admin') {
        return $cache = module_keys();
    }
    try {
        $stmt = db()->prepare('SELECT module_key FROM user_modules WHERE user_id = ?');
        $stmt->execute([(int)$u['id']]);
        $keys = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $ex) {
        error_log('[MODULES] ' . $ex->getMessage());
        $keys = [];
    }
    return $cache = array_values(array_intersect($keys, module_keys()));
}

function has_module(string $key): bool
{
    return in_array($key, allowed_modules(), true);
}

function requireModule(string $key): void
{
    $u = requireLogin();
    if (has_module($key)) {
        return;
    }
    audit_log('access_denied', 'users', (int)$u['id'], 'module=' . $key . ' not assigned');
    http_response_code(403);
    $label = e(module_label($key));
    $role  = e($u['role_level']);
    $base  = e(app_base_path());
    exit('<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>403 · Module not assigned</title>'
        . '<link rel="stylesheet" href="' . $base . '/assets/vendor/bootstrap.min.css">'
        . '<link rel="stylesheet" href="' . $base . '/assets/vendor/bootstrap-icons.css">'
        . '</head><body class="bg-light"><div class="container" style="max-width:560px">'
        . '<div class="card shadow-sm border-danger mt-5"><div class="card-body text-center p-4">'
        . '<h1 class="text-danger display-6"><i class="bi bi-shield-x"></i> 403</h1>'
        . '<p class="text-muted">The <strong>' . $label . '</strong> module is not assigned to your '
        . 'account (<strong>' . $role . '</strong>).<br>Ask an administrator to grant it.</p>'
        . '<a class="btn btn-outline-secondary" href="javascript:history.back()">Go back</a>'
        . '</div></div></div></body></html>');
}

function canEdit(): bool
{
    $u = current_user();
    return $u !== null && in_array($u['role_level'], ['Admin', 'Officer'], true);
}

function canDelete(): bool
{
    $u = current_user();
    return $u !== null && $u['role_level'] === 'Admin';
}

function isAdmin(): bool
{
    $u = current_user();
    return $u !== null && $u['role_level'] === 'Admin';
}
