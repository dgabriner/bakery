<?php
/**
 * Verify LOCAL_ADMIN_* code login without printing the code.
 *
 * Usage:
 *   C:\php\php.exe scripts\verify_local_login.php
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/env_loader.php';
bakery_load_env_file($root . '/.env');
$pullEnv = $root . '/.env.production.pull';
if (is_readable($pullEnv)) {
    bakery_load_env_file($pullEnv);
}
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/auth.php';

$email = $_ENV['LOCAL_ADMIN_EMAIL'] ?? getenv('LOCAL_ADMIN_EMAIL') ?: BAKERY_ADMIN_EMAIL;
$code = (string)($_ENV['LOCAL_ADMIN_CODE'] ?? getenv('LOCAL_ADMIN_CODE') ?: '');
if ($code === '') {
    $legacy = (string)($_ENV['LOCAL_ADMIN_PASSWORD'] ?? getenv('LOCAL_ADMIN_PASSWORD') ?: '');
    if (bakery_normalize_login_code($legacy) !== '') {
        $code = $legacy;
    } else {
        $code = BAKERY_ADMIN_CODE;
    }
}
$code = bakery_normalize_login_code($code);

echo 'DB=' . DB_NAME . ' host=' . DB_HOST . "\n";

$db = check_mysql_connection();
bakery_ensure_login_code_column($db);
$customers = (int)$db->query('SELECT COUNT(*) FROM customers')->fetchColumn();
$standing = (int)$db->query('SELECT COUNT(*) FROM standing_orders')->fetchColumn();
echo "customers={$customers} standing_orders={$standing}\n";

$stmt = $db->prepare(
    'SELECT u.email, u.is_active, u.login_code, r.slug AS role_slug
     FROM users u JOIN roles r ON r.id = u.role_id
     WHERE LOWER(u.email) = LOWER(?)'
);
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    fwrite(STDERR, "No user for {$email}. Run: scripts/ensure_local_admin.php\n");
    exit(1);
}
echo "user={$user['email']} role={$user['role_slug']} active={$user['is_active']} code_set=" . ($user['login_code'] ? '1' : '0') . "\n";

if ($code === '') {
    fwrite(STDERR, "LOCAL_ADMIN_CODE not set (4 digits).\n");
    exit(1);
}

if (($user['login_code'] ?? '') !== $code) {
    fwrite(STDERR, "Code in env does not match DB. Run: scripts/ensure_local_admin.php\n");
    exit(1);
}

if (!bakery_login($db, $code)) {
    fwrite(STDERR, "bakery_login failed\n");
    exit(1);
}
$cu = bakery_current_user();
echo 'LOGIN_OK role=' . ($cu['role_slug'] ?? '') . "\n";
if ($customers >= 10) {
    echo "DATA_OK looks like production pull (customers >= 10)\n";
} else {
    echo "DATA_WARN only {$customers} customers — demo fixtures? Run pull_prod_to_local.php\n";
}
exit(0);
