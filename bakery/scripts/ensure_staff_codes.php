<?php
/**
 * Ensure Danny / Juan Carlos / Sergio / Laura code logins exist.
 *
 * Usage: C:\php\php.exe scripts/ensure_staff_codes.php
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

$db = check_mysql_connection();
bakery_ensure_login_code_column($db);
bakery_ensure_staff_code_users($db);
echo "OK staff code users ensured\n";
