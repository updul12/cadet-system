<?php
/** Login lockout, password policy, credential lookup. */
declare(strict_types=1);

function record_login_attempt(string $username, bool $success): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO login_attempts (username, ip_address, successful) VALUES (?, ?, ?)'
        );
        $stmt->execute([$username, client_ip(), $success ? 1 : 0]);
    } catch (Throwable $ex) {
        error_log('[LOGIN-ATTEMPT] ' . $ex->getMessage());
    }
}

function is_account_locked(string $username): int
{
    try {
        $stmt = db()->prepare(
            'SELECT failed_attempts, locked_until FROM users WHERE username = ?'
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        if (!$row) {
            return 0;
        }
        if ($row['locked_until'] !== null && strtotime((string)$row['locked_until']) > time()) {
            return strtotime((string)$row['locked_until']) - time();
        }
    } catch (Throwable $ex) {
        error_log('[LOCKOUT] ' . $ex->getMessage());
    }
    return 0;
}

function ip_throttle_seconds(): int
{
    try {
        $stmt = db()->prepare(
            "SELECT UNIX_TIMESTAMP(attempted_at) AS t
               FROM login_attempts
              WHERE ip_address = ? AND successful = 0
                AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)
              ORDER BY attempted_at ASC
              LIMIT 1 OFFSET 9"
        );
        $stmt->execute([client_ip()]);
        $tenth = $stmt->fetchColumn();
        if (!$tenth) {
            return 0;
        }
        $until = (int)$tenth + 15 * 60;
        return $until > time() ? $until - time() : 0;
    } catch (Throwable $ex) {
        error_log('[IP-THROTTLE] ' . $ex->getMessage());
        return 0;
    }
}

function clear_ip_failures(): void
{
    try {
        $stmt = db()->prepare(
            'DELETE FROM login_attempts
              WHERE ip_address = ? AND successful = 0
                AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)'
        );
        $stmt->execute([client_ip()]);
    } catch (Throwable $ex) {
        error_log('[IP-THROTTLE] ' . $ex->getMessage());
    }
}

function register_failed_login(string $username): void
{
    try {
        $stmt = db()->prepare(
            'UPDATE users
                SET failed_attempts = failed_attempts + 1,
                    locked_until = CASE
                        WHEN failed_attempts >= 5
                        THEN ?
                        ELSE locked_until
                    END
              WHERE username = ?'
        );
        $lockUntil = date('Y-m-d H:i:s', time() + 15 * 60);
        $stmt->execute([$lockUntil, $username]);
    } catch (Throwable $ex) {
        error_log('[LOCKOUT] ' . $ex->getMessage());
    }
}

function clear_login_failures(int $userId): void
{
    try {
        $stmt = db()->prepare(
            'UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?'
        );
        $stmt->execute([$userId]);
    } catch (Throwable $ex) {
        error_log('[LOCKOUT] ' . $ex->getMessage());
    }
}

function password_policy_errors(string $password): array
{
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one digit.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Password must contain at least one special character.';
    }
    return $errors;
}

function find_user_by_identifier(string $identifier): ?array
{
    $id = trim($identifier);
    if ($id === '') {
        return null;
    }
    $cols = 'id, username, password_hash, role_level, status';

    try {
        $stmt = db()->prepare("SELECT {$cols} FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }

        $compact = strtoupper((string)preg_replace('/[\s\/\-]+/', '', $id));
        if ($compact === '') {
            return null;
        }
        $stmt = db()->prepare(
            "SELECT u.id, u.username, u.password_hash, u.role_level, u.status
               FROM users u
               JOIN cadets c ON c.id = u.cadet_id
              WHERE REPLACE(REPLACE(REPLACE(UPPER(c.service_no), ' ', ''), '/', ''), '-', '') = ?
              LIMIT 1"
        );
        $stmt->execute([$compact]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $ex) {
        error_log('[LOGIN-RESOLVE] ' . $ex->getMessage());
        return null;
    }
}
