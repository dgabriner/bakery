<?php
/**
 * Emergency local admin code reset (APP_ENV=local only).
 * Usage: C:\php\php.exe bakery/scripts/reset_local_admin.php [4-digit-code]
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/auth.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: admin reset only when APP_ENV=local\n");
    exit(1);
}

$newCode = bakery_normalize_login_code($argv[1] ?? BAKERY_ADMIN_CODE);
if ($newCode === '') {
    fwrite(STDERR, "Code must be exactly 4 digits\n");
    exit(1);
}

$db = check_mysql_connection();
if (!table_exists($db, 'users')) {
    fwrite(STDERR, "users table missing — run scripts/seed_local_users.php first\n");
    exit(1);
}

bakery_ensure_login_code_column($db);

if (!bakery_upsert_code_user($db, [
    'email' => BAKERY_ADMIN_EMAIL,
    'display_name' => BAKERY_ADMIN_DISPLAY_NAME,
    'role' => 'administrator',
    'code' => $newCode,
    'driver_id' => null,
])) {
    fwrite(STDERR, "Failed to reset admin code (collision?)\n");
    exit(1);
}

echo "Reset " . BAKERY_ADMIN_EMAIL . " login code (value not echoed).\n";
