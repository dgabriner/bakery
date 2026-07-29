<?php
/**
 * Emergency local admin password reset (APP_ENV=local only).
 * Usage: C:\php\php.exe bakery/scripts/reset_local_admin.php [new-password]
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: admin reset only when APP_ENV=local\n");
    exit(1);
}

$newPassword = $argv[1] ?? 'LocalAdmin!234';
if (strlen($newPassword) < 10) {
    fwrite(STDERR, "Password must be at least 10 characters\n");
    exit(1);
}

$db = check_mysql_connection();
if (!table_exists($db, 'users')) {
    fwrite(STDERR, "users table missing — run scripts/seed_local_users.php first\n");
    exit(1);
}

$role = $db->query("SELECT id FROM roles WHERE slug='administrator'")->fetchColumn();
if (!$role) {
    fwrite(STDERR, "administrator role missing\n");
    exit(1);
}

$hash = password_hash($newPassword, PASSWORD_DEFAULT);
$stmt = $db->prepare(
    "INSERT INTO users (email, password_hash, display_name, role_id, is_active)
     VALUES ('admin@local.test', ?, 'Local Admin', ?, 1)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role_id = VALUES(role_id), is_active = 1"
);
$stmt->execute([$hash, $role]);

echo "Reset admin@local.test password (value not echoed). Use the password you supplied or the documented local default.\n";
