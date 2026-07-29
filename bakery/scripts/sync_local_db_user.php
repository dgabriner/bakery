<?php
/**
 * Align local MariaDB bakery_local account with bakery/.env (APP_ENV=local only).
 * Does not print secret values.
 *
 * Usage: C:\php\php.exe bakery/scripts/sync_local_db_user.php
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/env_loader.php';
bakery_load_env_file($root . '/.env');

$appEnv = strtolower((string)($_ENV['APP_ENV'] ?? ''));
if (!in_array($appEnv, ['local', 'development', 'dev'], true)) {
    fwrite(STDERR, "Refusing: APP_ENV must be local\n");
    exit(1);
}

$host = $_ENV['DB_HOST'] ?? '';
$name = $_ENV['DB_NAME'] ?? '';
$user = $_ENV['DB_USER'] ?? '';
$pass = $_ENV['DB_PASS'] ?? '';

if ($host === '' || $name === '' || $user === '') {
    fwrite(STDERR, "Missing DB_HOST/DB_NAME/DB_USER in .env\n");
    exit(1);
}
if (strpos(strtolower($host), 'sourflour') !== false || strtolower($name) === 'bakerysf') {
    fwrite(STDERR, "Refusing production-looking target\n");
    exit(1);
}

try {
    $rootDb = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot connect as local root (blank password expected for Scoop MariaDB)\n");
    exit(1);
}

$dbIdent = '`' . str_replace('`', '``', $name) . '`';
$rootDb->exec("CREATE DATABASE IF NOT EXISTS {$dbIdent} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$hosts = array_unique([$host, '127.0.0.1', 'localhost']);
foreach ($hosts as $h) {
    if ($h === '' || strpos($h, "'") !== false) {
        continue;
    }
    // Drop/recreate to avoid plugin/password mismatch without echoing secrets
    try {
        $rootDb->exec("DROP USER IF EXISTS '{$user}'@'{$h}'");
    } catch (Throwable $e) {
        // ignore
    }
    $stmt = $rootDb->prepare("CREATE USER ?@? IDENTIFIED BY ?");
    // MariaDB may not allow bound identifiers — use quote for password only
    $passLit = $rootDb->quote($pass);
    $rootDb->exec("CREATE USER '{$user}'@'{$h}' IDENTIFIED BY {$passLit}");
    $rootDb->exec("GRANT ALL PRIVILEGES ON {$dbIdent}.* TO '{$user}'@'{$h}'");
    $rootDb->exec("GRANT CREATE, DROP ON *.* TO '{$user}'@'{$h}'");
}

$rootDb->exec('FLUSH PRIVILEGES');

// Verify app credentials
$verify = new PDO(
    "mysql:host={$host};dbname={$name};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$verify->query('SELECT 1');

echo "Synced local DB user to .env and verified connection (secrets not printed).\n";
