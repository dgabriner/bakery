<?php
/**
 * Ensure a durable local admin login code (APP_ENV=local only).
 * Reads LOCAL_ADMIN_* from env / .env / .env.production.pull.
 * Does not echo codes.
 *
 * Usage:
 *   C:\php\php.exe scripts/ensure_local_admin.php
 *   C:\php\php.exe scripts/ensure_local_admin.php --code=9741
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/env_loader.php';

// Prefer dedicated pull file for LOCAL_ADMIN_* without overriding .env DB_*
$pullEnv = $root . '/.env.production.pull';
if (is_readable($pullEnv)) {
    bakery_load_env_file($pullEnv);
}
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/auth.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: ensure_local_admin only when APP_ENV=local\n");
    exit(1);
}

$email = BAKERY_ADMIN_EMAIL;
$name = BAKERY_ADMIN_DISPLAY_NAME;
$role = 'administrator';
$code = BAKERY_ADMIN_CODE;

foreach ($argv as $arg) {
    if (strpos($arg, '--email=') === 0) {
        $email = substr($arg, strlen('--email='));
    } elseif (strpos($arg, '--name=') === 0) {
        $name = substr($arg, strlen('--name='));
    } elseif (strpos($arg, '--role=') === 0) {
        $role = substr($arg, strlen('--role='));
    } elseif (strpos($arg, '--code=') === 0) {
        $code = substr($arg, strlen('--code='));
    } elseif (strpos($arg, '--password=') === 0) {
        // Backward-compatible alias for older scripts/docs.
        $code = substr($arg, strlen('--password='));
    }
}

$email = $_ENV['LOCAL_ADMIN_EMAIL'] ?? getenv('LOCAL_ADMIN_EMAIL') ?: $email;
$name = $_ENV['LOCAL_ADMIN_NAME'] ?? getenv('LOCAL_ADMIN_NAME') ?: $name;
$role = $_ENV['LOCAL_ADMIN_ROLE'] ?? getenv('LOCAL_ADMIN_ROLE') ?: $role;

$envCode = (string)($_ENV['LOCAL_ADMIN_CODE'] ?? getenv('LOCAL_ADMIN_CODE') ?: '');
if ($envCode !== '') {
    $code = $envCode;
} else {
    // Fall back to legacy env var if someone still has a 4-digit value there.
    $legacy = (string)($_ENV['LOCAL_ADMIN_PASSWORD'] ?? getenv('LOCAL_ADMIN_PASSWORD') ?: '');
    if (bakery_normalize_login_code($legacy) !== '') {
        $code = $legacy;
    }
}

$code = bakery_normalize_login_code($code);
if ($code === '') {
    fwrite(STDERR, "Set LOCAL_ADMIN_CODE (4 digits) in .env or pass --code=\n");
    exit(1);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid admin email\n");
    exit(1);
}

function bakery_ensure_auth_schema(PDO $db, $root) {
    if (!table_exists($db, 'users') || !table_exists($db, 'roles')) {
        $path = $root . '/database/schema/002_auth.sql';
        $sql = file_get_contents($path);
        $lines = preg_split("/\r\n|\n|\r/", $sql);
        $buf = '';
        foreach ($lines as $line) {
            if (strpos(ltrim($line), '--') === 0) {
                continue;
            }
            $buf .= $line . "\n";
        }
        foreach (array_filter(array_map('trim', explode(';', $buf))) as $statement) {
            if ($statement !== '') {
                $db->exec($statement);
            }
        }
        echo "Ensured auth schema\n";
    }
}

$db = check_mysql_connection();
bakery_ensure_auth_schema($db, $root);
bakery_ensure_login_code_column($db);

if (!bakery_upsert_code_user($db, [
    'email' => $email,
    'display_name' => $name,
    'role' => $role,
    'code' => $code,
    'driver_id' => null,
])) {
    fwrite(STDERR, "Failed to upsert admin user (code may already be in use)\n");
    exit(1);
}

$check = $db->prepare('SELECT id, email, is_active, login_code FROM users WHERE email = ?');
$check->execute([$email]);
$user = $check->fetch(PDO::FETCH_ASSOC);
echo "OK local admin id={$user['id']} email={$user['email']} role={$role} active={$user['is_active']} code_set=1\n";
