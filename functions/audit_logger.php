<?php
/**
 * Shared helper for writing to the audit_logs table.
 *
 * Include this AFTER db_connection.php (it needs $pdo to already exist,
 * or you can pass your own $pdo instance in).
 *
 * Usage:
 *   require_once __DIR__ . '/audit_logger.php';
 *
 *   log_audit(
 *       $pdo,
 *       $actorUserId,   // int|null — who performed the action (null = unknown/system)
 *       $actorType,     // string   — 'customer' | 'admin' | 'super_admin' | 'system' | 'guest' ...
 *       $action,        // string   — short verb, e.g. 'register', 'create', 'update', 'delete', 'login'
 *       $entityType,    // string   — what table/thing was affected, e.g. 'user', 'order', 'product'
 *       $entityId,      // int|null — the affected row's id
 *       $oldValues,     // array|null — associative array of the "before" state (null for creates)
 *       $newValues      // array|null — associative array of the "after" state (null for deletes)
 *   );
 *
 * Notes:
 *  - Never pass raw passwords or password hashes into $oldValues/$newValues.
 *  - A logging failure never throws — it's caught and written to the PHP
 *    error log instead, so it can never block the actual action (a failed
 *    audit write is bad, but it should never be the reason a registration
 *    or account save fails).
 */
/**
 * Maps an admin-side role_id (4 or 5) to the actor_type string used in
 * audit_logs, matching the $roleNames convention already used across the
 * admin pages (4 => Admin, 5 => Super Admin).
 */
function admin_actor_type(int $roleId): string
{
    return match ($roleId) {
        5       => 'super_admin',
        4       => 'admin',
        3       => 'staff',
        default => 'customer',
    };
}

function log_audit(
    PDO $pdo,
    ?int $actorUserId,
    string $actorType,
    string $action,
    string $entityType,
    ?int $entityId,
    ?array $oldValues = null,
    ?array $newValues = null
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs
                (actor_user_id, actor_type, action, entity_type, entity_id, old_values, new_values, created_at)
            VALUES
                (:actor_user_id, :actor_type, :action, :entity_type, :entity_id, :old_values, :new_values, NOW())
        ");

        $stmt->execute([
            ':actor_user_id' => $actorUserId,
            ':actor_type'    => $actorType,
            ':action'        => $action,
            ':entity_type'   => $entityType,
            ':entity_id'     => $entityId,
            ':old_values'    => $oldValues !== null ? json_encode($oldValues) : null,
            ':new_values'    => $newValues !== null ? json_encode($newValues) : null,
        ]);
    } catch (PDOException $e) {
        // Swallow the error so a logging failure never breaks the primary
        // action. Still record it somewhere so it doesn't fail silently.
        error_log('log_audit() failed: ' . $e->getMessage());
    }
}
