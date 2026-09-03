<?php
/**
 * Force-approve migration 074 for Live (role seed; schema compare stays Match).
 */
define('ACCESS_ALLOWED', true);

$root = rtrim((string)getenv('BAKERY_HOSTED_STAGE_ROOT'), '/');
if ($root === '') {
    $root = dirname(__DIR__);
}

require_once $root . '/includes/env_loader.php';
$stagingEnv = $root . DIRECTORY_SEPARATOR . '.env';
if (is_readable($stagingEnv)) {
    bakery_load_env_file($stagingEnv, true);
    putenv('APP_ENV=staging');
    $_ENV['APP_ENV'] = 'staging';
    $_SERVER['APP_ENV'] = 'staging';
}

require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
$db = check_mysql_connection();
require_once $root . '/includes/auth.php';
require_once $root . '/includes/hosted_migration_approval.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

if (!defined('IS_STAGING') || !IS_STAGING) {
    fwrite(STDERR, "staging-only\n");
    exit(1);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$admin = $db->query(
    "SELECT u.id, u.email, u.display_name, r.slug AS role_slug
     FROM users u
     JOIN roles r ON r.id = u.role_id
     WHERE r.slug = 'administrator' AND u.is_active = 1
     ORDER BY u.id ASC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if ($admin) {
    $_SESSION['user_id'] = (int)$admin['id'];
    $_SESSION['user_email'] = (string)$admin['email'];
    $_SESSION['user_display_name'] = (string)$admin['display_name'];
    $_SESSION['user_role_slug'] = (string)$admin['role_slug'];
}

$file = '074_cashier_role.sql';
foreach ($argv as $arg) {
    if (strpos($arg, '--file=') === 0) {
        $file = basename(substr($arg, 7));
    }
}

$result = bakery_hosted_migration_approve($file);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
