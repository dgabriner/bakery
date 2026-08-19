<?php
/**
 * Provision bakerysf_local + bakery_local from .env using a local MariaDB admin account.
 * CLI only. Never prints passwords. Refuses non-loopback hosts.
 *
 * Usage:
 *   C:\php\php.exe bakery/scripts/bootstrap_local_db_user.php
 *
 * Optional env (in process or .env):
 *   LOCAL_DB_ADMIN_USER=root
 *   LOCAL_DB_ADMIN_PASS=   (empty for default Scoop MariaDB)
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/env_loader.php';

$envPath = $root . DIRECTORY_SEPARATOR . '.env';
if (!is_readable($envPath)) {
    fwrite(STDERR, "Missing bakery/.env — copy from .env.example first.\n");
    exit(1);
}
bakery_load_env_file($envPath);

$host = $_ENV['DB_HOST'] ?? '';
$port = $_ENV['DB_PORT'] ?? '3306';
$name = $_ENV['DB_NAME'] ?? '';
$user = $_ENV['DB_USER'] ?? '';
$pass = $_ENV['DB_PASS'] ?? '';

$hostLower = strtolower($host);
$nameLower = strtolower($name);

if (strpos($hostLower, 'sourflour') !== false || strpos($hostLower, 'dreamhost') !== false) {
    fwrite(STDERR, "Refusing: DB_HOST looks like production.\n");
    exit(1);
}
if (!in_array($hostLower, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing: DB_HOST must be loopback.\n");
    exit(1);
}
if ($nameLower === 'bakerysf' || strpos($nameLower, '_local') === false) {
    fwrite(STDERR, "Refusing: DB_NAME must be like bakerysf_local.\n");
    exit(1);
}
if ($user === '' || $pass === '') {
    fwrite(STDERR, "Refusing: DB_USER and DB_PASS must be set in .env.\n");
    exit(1);
}

$adminUser = $_ENV['LOCAL_DB_ADMIN_USER'] ?? getenv('LOCAL_DB_ADMIN_USER') ?: 'root';
$adminPass = $_ENV['LOCAL_DB_ADMIN_PASS'] ?? getenv('LOCAL_DB_ADMIN_PASS');
if ($adminPass === false || $adminPass === null) {
    $adminPass = '';
}

try {
    $admin = new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $adminUser,
        $adminPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $safeName = str_replace('`', '``', $name);
    $admin->exec(
        "CREATE DATABASE IF NOT EXISTS `{$safeName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
    $admin->exec(
        "CREATE DATABASE IF NOT EXISTS `bakerysf_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );

    // Create/update app user for both localhost and 127.0.0.1 (MariaDB auth hosts).
    foreach (['localhost', '127.0.0.1'] as $hostSpec) {
        $quotedUser = $admin->quote($user);
        $quotedHost = $admin->quote($hostSpec);
        $quotedPass = $admin->quote($pass);
        $admin->exec("CREATE USER IF NOT EXISTS {$quotedUser}@{$quotedHost} IDENTIFIED BY {$quotedPass}");
        $admin->exec("ALTER USER {$quotedUser}@{$quotedHost} IDENTIFIED BY {$quotedPass}");
        $admin->exec("GRANT ALL PRIVILEGES ON `{$safeName}`.* TO {$quotedUser}@{$quotedHost}");
    $admin->exec("GRANT ALL PRIVILEGES ON `bakerysf_test`.* TO {$quotedUser}@{$quotedHost}");
    }
    $admin->exec('FLUSH PRIVILEGES');

    // Verify app credentials (no password output)
    $app = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $app->query('SELECT 1');

    echo "OK: database {$name} and user {$user} ready on {$host}.\n";
    echo "Next: C:\\php\\php.exe scripts\\setup_local_db.php --reset\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Bootstrap failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "If root needs a password, set LOCAL_DB_ADMIN_PASS in .env (local only).\n");
    exit(1);
}
