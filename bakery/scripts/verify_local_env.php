<?php
/**
 * Verify local environment safety rails (CLI only).
 * Does not print secret values.
 *
 * Usage: C:\php\php.exe bakery/scripts/verify_local_env.php
 */
define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
require_once $root . '/includes/env_loader.php';

$envPath = $root . DIRECTORY_SEPARATOR . '.env';
if (!is_readable($envPath)) {
    fwrite(STDERR, "FAIL: bakery/.env not found. Copy .env.example to .env first.\n");
    exit(1);
}

bakery_load_env_file($envPath);

$failures = 0;
function check($ok, $msg) {
    global $failures;
    if ($ok) {
        echo "OK   $msg\n";
    } else {
        echo "FAIL $msg\n";
        $failures++;
    }
}

$appEnv = strtolower((string)($_ENV['APP_ENV'] ?? ''));
$dbHost = strtolower((string)($_ENV['DB_HOST'] ?? ''));
$dbName = strtolower((string)($_ENV['DB_NAME'] ?? ''));
$mailDriver = strtolower((string)($_ENV['MAIL_DRIVER'] ?? ''));
$mapsEnabled = strtolower((string)($_ENV['MAPS_ENABLED'] ?? 'false'));

check($appEnv === 'local' || $appEnv === 'development' || $appEnv === 'dev', "APP_ENV is local-like (got: {$appEnv})");
check($dbHost === '127.0.0.1' || $dbHost === 'localhost' || $dbHost === '::1', "DB_HOST is loopback (got: {$dbHost})");
check(strpos($dbHost, 'sourflour') === false, 'DB_HOST does not contain sourflour');
check($dbName !== 'bakerysf', 'DB_NAME is not production bakerysf');
check(strpos($dbName, '_local') !== false || strpos($dbName, 'test') !== false || strpos($dbName, 'dev') !== false, "DB_NAME looks nonproduction (got: {$dbName})");
check($mailDriver === 'log', "MAIL_DRIVER=log (got: {$mailDriver})");
check($mapsEnabled === 'false' || $mapsEnabled === '0', "MAPS_ENABLED is false for local");

// Config bootstrap should succeed without connecting if we only load defines —
// full config connects safety checks only.
try {
    require_once $root . '/includes/config.php';
    check(defined('IS_LOCAL') && IS_LOCAL, 'IS_LOCAL is true after config load');
    check(defined('DB_NAME') && DB_NAME === ($_ENV['DB_NAME'] ?? ''), 'DB_NAME constant matches env');
    check(defined('MAIL_DRIVER') && MAIL_DRIVER === 'log', 'MAIL_DRIVER constant is log');
} catch (Throwable $e) {
    check(false, 'config.php loaded: ' . $e->getMessage());
}

// Refuse production-looking connection attempt
try {
    require_once $root . '/includes/database.php';
    $db = check_mysql_connection();
    $name = $db->query('SELECT DATABASE()')->fetchColumn();
    check($name === ($_ENV['DB_NAME'] ?? null), "Connected database is {$name}");
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    check(count($tables) > 0, 'Local database has tables (' . count($tables) . ')');
} catch (Throwable $e) {
    echo "WARN database connection not available yet: " . $e->getMessage() . "\n";
    echo "      Install local MySQL/MariaDB, then run scripts/setup_local_db.php\n";
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} check(s) failed.\n");
    exit(1);
}

echo "\nAll critical local safety checks passed (or DB pending install).\n";
exit(0);
