<?php
/** Audit trail + client IP helpers. */
declare(strict_types=1);

function audit_log(string $action, ?string $entity = null, ?int $entityId = null, ?string $detail = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO audit_log (user_id, username, action, entity, entity_id, detail, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $_SESSION['username'] ?? null,
            $action,
            $entity,
            $entityId,
            $detail !== null ? mb_substr($detail, 0, 500) : null,
            client_ip(),
        ]);
    } catch (Throwable $ex) {
        error_log('[AUDIT] ' . $ex->getMessage());
    }
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (str_starts_with($ip, '::ffff:')) {
        $ip = substr($ip, 7);
    }
    return substr($ip, 0, 45);
}
