<?php
/**
 * Ensure a durable local admin login (APP_ENV=local only).
 * Reads LOCAL_ADMIN_* from env / .env / .env.production.pull.
 * Does not echo passwords.
 *
 * Usage:
 *   C:\php\php.exe scripts/ensure_local_admin.php
 *   C:\php\php.exe scripts/ensure_local_admin.php --password=Secret
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

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: ensure_local_admin only when APP_ENV=local\n");
    exit(1);
}

$email = 'danny@sourflour.org';
$name = 'Danny';
$role = 'administrator';
$password = '';

foreach ($argv as $arg) {
    if (strpos($arg, '--email=') === 0) {
        $email = substr($arg, strlen('--email='));
    } elseif (strpos($arg, '--name=') === 0) {
        $name = substr($arg, strlen('--name='));
    } elseif (strpos($arg, '--role=') === 0) {
        $role = substr($arg, strlen('--role='));
    } elseif (strpos($arg, '--password=') === 0) {
        $password = substr($arg, strlen('--password='));
    }
}

$email = $_ENV['LOCAL_ADMIN_EMAIL'] ?? getenv('LOCAL_ADMIN_EMAIL') ?: $email;
$name = $_ENV['LOCAL_ADMIN_NAME'] ?? getenv('LOCAL_ADMIN_NAME') ?: $name;
$role = $_ENV['LOCAL_ADMIN_ROLE'] ?? getenv('LOCAL_ADMIN_ROLE') ?: $role;
if ($password === '') {
    $password = (string)($_ENV['LOCAL_ADMIN_PASSWORD'] ?? getenv('LOCAL_ADMIN_PASSWORD') ?: '');
}

if ($password === '' || strlen($password) < 8) {
    fwrite(STDERR, "Set LOCAL_ADMIN_PASSWORD in .env or .env.production.pull, or pass --password=\n");
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

$roleStmt = $db->prepare('SELECT id FROM roles WHERE slug = ?');
$roleStmt->execute([$role]);
$roleId = $roleStmt->fetchColumn();
if (!$roleId) {
    fwrite(STDERR, "Missing role: {$role}\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $db->prepare(
    "INSERT INTO users (email, password_hash, display_name, role_id, is_active)
     VALUES (?, ?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE
       password_hash = VALUES(password_hash),
       display_name = VALUES(display_name),
       role_id = VALUES(role_id),
       is_active = 1"
);
$stmt->execute([$email, $hash, $name, $roleId]);

$check = $db->prepare('SELECT id, email, is_active FROM users WHERE email = ?');
$check->execute([$email]);
$user = $check->fetch(PDO::FETCH_ASSOC);
echo "OK local admin id={$user['id']} email={$user['email']} role={$role} active={$user['is_active']}\n";
