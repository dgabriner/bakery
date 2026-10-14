<?php
/**
 * Actor identity for timeline rows, login activity, and bot staff accounts.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/** Staff accounts that act as software, not a person on the floor. */
function bakery_known_bot_display_names(): array
{
    return ['New Bot', 'Cursor Agent'];
}

function bakery_actor_type_for_action(?array $user, ?string $role, ?string $eventType): string
{
    $name = trim((string)($user['display_name'] ?? ''));
    if (!empty($user['is_bot']) || ($name !== '' && in_array($name, bakery_known_bot_display_names(), true))) {
        return 'bot';
    }
    $role = strtolower(trim((string)($role ?? ($user['role_slug'] ?? ''))));
    $eventType = (string)$eventType;
    $userId = (int)($user['id'] ?? 0);
    if ($role === 'customer') {
        return 'customer';
    }
    if ($userId <= 0 && strpos($eventType, 'portal_') === 0) {
        return 'customer';
    }
    if ($userId <= 0) {
        return 'system';
    }
    return 'human';
}

function bakery_actor_user_with_bot_flag(PDO $db, ?array $user): ?array
{
    if (!$user) {
        return null;
    }
    if (array_key_exists('is_bot', $user)) {
        return $user;
    }
    $id = (int)($user['id'] ?? 0);
    if ($id <= 0 || !function_exists('column_exists') || !column_exists($db, 'users', 'is_bot')) {
        $user['is_bot'] = in_array(trim((string)($user['display_name'] ?? '')), bakery_known_bot_display_names(), true) ? 1 : 0;
        return $user;
    }
    $stmt = $db->prepare('SELECT is_bot, display_name FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $user['is_bot'] = (int)$row['is_bot'];
        if (trim((string)($user['display_name'] ?? '')) === '') {
            $user['display_name'] = (string)$row['display_name'];
        }
    }
    return $user;
}

function bakery_ensure_actor_identity_schema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    if (function_exists('bakery_runtime_schema_ddl_allowed') && !bakery_runtime_schema_ddl_allowed()) {
        $done = true;
        return;
    }
    try {
        if (function_exists('table_exists') && table_exists($db, 'users')
            && function_exists('column_exists') && !column_exists($db, 'users', 'is_bot')) {
            $db->exec('ALTER TABLE users ADD COLUMN is_bot TINYINT(1) NOT NULL DEFAULT 0');
            if (function_exists('bakery_forget_column_exists')) {
                bakery_forget_column_exists('users', 'is_bot');
            }
            $db->exec("UPDATE users SET is_bot = 1 WHERE display_name IN ('New Bot', 'Cursor Agent')");
        }
        if (function_exists('table_exists') && table_exists($db, 'operational_events')
            && function_exists('column_exists') && !column_exists($db, 'operational_events', 'actor_type')) {
            $db->exec('ALTER TABLE operational_events ADD COLUMN actor_type VARCHAR(16) NULL DEFAULT NULL');
            if (function_exists('bakery_forget_column_exists')) {
                bakery_forget_column_exists('operational_events', 'actor_type');
            }
        }
        if (function_exists('table_exists') && table_exists($db, 'login_audit')
            && function_exists('column_exists') && !column_exists($db, 'login_audit', 'actor_type')) {
            $db->exec('ALTER TABLE login_audit ADD COLUMN actor_type VARCHAR(16) NULL DEFAULT NULL');
            if (function_exists('bakery_forget_column_exists')) {
                bakery_forget_column_exists('login_audit', 'actor_type');
            }
        }
        if (function_exists('table_exists') && table_exists($db, 'login_audit_activity')
            && function_exists('column_exists') && !column_exists($db, 'login_audit_activity', 'actor_type')) {
            $db->exec('ALTER TABLE login_audit_activity ADD COLUMN actor_type VARCHAR(16) NULL DEFAULT NULL');
            if (function_exists('bakery_forget_column_exists')) {
                bakery_forget_column_exists('login_audit_activity', 'actor_type');
            }
        }
        if (function_exists('table_exists') && table_exists($db, 'users') && !table_exists($db, 'admin_signin_alerts')) {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS admin_signin_alerts (
                    id BIGINT NOT NULL AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    login_audit_id BIGINT NULL DEFAULT NULL,
                    ip_address VARCHAR(45) NULL DEFAULT NULL,
                    user_agent VARCHAR(1000) NULL DEFAULT NULL,
                    device_label VARCHAR(160) NULL DEFAULT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    dismissed_at DATETIME NULL DEFAULT NULL,
                    dismissed_by_user_id INT NULL DEFAULT NULL,
                    PRIMARY KEY (id),
                    KEY idx_admin_signin_alerts_open (dismissed_at, created_at),
                    KEY idx_admin_signin_alerts_user (user_id, created_at)
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            if (function_exists('bakery_forget_table_exists')) {
                bakery_forget_table_exists('admin_signin_alerts');
            }
        }
    } catch (Throwable $e) {
        error_log('Actor identity schema error: ' . $e->getMessage());
    }
    $done = true;
}
