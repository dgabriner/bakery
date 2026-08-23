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

function verify_env_value(string $name, string $default = ''): string {
    $value = $_ENV[$name] ?? getenv($name);
    return ($value === false || $value === null || $value === '') ? $default : (string)$value;
}

$appEnv = strtolower(verify_env_value('APP_ENV'));
$useProdRaw = strtolower(verify_env_value('USE_PROD_DB', 'false'));
$useProd = in_array($useProdRaw, ['1', 'true', 'yes', 'on'], true);
$dbHost = strtolower(verify_env_value('DB_HOST'));
$dbName = strtolower(verify_env_value('DB_NAME'));
$mailDriver = strtolower(verify_env_value('MAIL_DRIVER'));
$mapsEnabled = strtolower(verify_env_value('MAPS_ENABLED', 'false'));

check($appEnv === 'local' || $appEnv === 'development' || $appEnv === 'dev', "APP_ENV is local-like (got: {$appEnv})");
check($mailDriver === 'log', "MAIL_DRIVER=log (got: {$mailDriver})");

if ($useProd) {
    echo "MODE USE_PROD_DB=true (local app → live production DB)\n";
    $pullPath = $root . DIRECTORY_SEPARATOR . '.env.production.pull';
    check(is_readable($pullPath), '.env.production.pull present');
    if (is_readable($pullPath)) {
        bakery_load_env_file($pullPath);
    }
    $prodHost = strtolower(verify_env_value('PROD_DB_HOST'));
    $prodName = strtolower(verify_env_value('PROD_DB_NAME'));
    $looksProd = (
        strpos($prodHost, 'sourflour') !== false ||
        strpos($prodHost, 'dreamhost') !== false ||
        $prodName === 'bakerysf'
    );
    check($looksProd, "PROD_DB_HOST/NAME look like production (host={$prodHost}, name={$prodName})");
    check(strpos($prodName, '_local') === false, 'PROD_DB_NAME is not a _local database');
    // Local DB_* in .env should still be safe for switching back
    check($dbHost === '127.0.0.1' || $dbHost === 'localhost' || $dbHost === '::1', "Stored local DB_HOST is loopback (got: {$dbHost})");
    check(strpos($dbName, '_local') !== false || strpos($dbName, 'test') !== false, "Stored local DB_NAME looks nonproduction (got: {$dbName})");
} else {
    echo "MODE USE_PROD_DB=false (local MariaDB)\n";
    check($dbHost === '127.0.0.1' || $dbHost === 'localhost' || $dbHost === '::1', "DB_HOST is loopback (got: {$dbHost})");
    check(strpos($dbHost, 'sourflour') === false, 'DB_HOST does not contain sourflour');
    check($dbName !== 'bakerysf', 'DB_NAME is not production bakerysf');
    check(strpos($dbName, '_local') !== false || strpos($dbName, 'test') !== false || strpos($dbName, 'dev') !== false, "DB_NAME looks nonproduction (got: {$dbName})");
    check($mapsEnabled === 'false' || $mapsEnabled === '0' || $mapsEnabled === 'true' || $mapsEnabled === '1', "MAPS_ENABLED is set (got: {$mapsEnabled})");
}

try {
    require_once $root . '/includes/config.php';
    check(defined('IS_LOCAL') && IS_LOCAL, 'IS_LOCAL is true after config load');
    check(defined('USE_PROD_DB') && USE_PROD_DB === $useProd, 'USE_PROD_DB constant matches .env flag');
    if ($useProd) {
        check(defined('DB_NAME') && strtolower(DB_NAME) === strtolower(verify_env_value('PROD_DB_NAME')), 'DB_NAME constant uses PROD_DB_NAME');
    } else {
        check(defined('DB_NAME') && DB_NAME === verify_env_value('DB_NAME'), 'DB_NAME constant matches env');
    }
    check(defined('MAIL_DRIVER') && MAIL_DRIVER === 'log', 'MAIL_DRIVER constant is log');
} catch (Throwable $e) {
    check(false, 'config.php loaded: ' . $e->getMessage());
}

try {
    require_once $root . '/includes/database.php';
    $db = check_mysql_connection();
    $name = $db->query('SELECT DATABASE()')->fetchColumn();
    $expected = $useProd ? verify_env_value('PROD_DB_NAME') : verify_env_value('DB_NAME');
    check($name === $expected, "Connected database is {$name}");
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    check(count($tables) > 0, 'Database has tables (' . count($tables) . ')');
} catch (Throwable $e) {
    if ($useProd) {
        echo "WARN production connection failed: " . $e->getMessage() . "\n";
        echo "      Check PROD_DB_* and DreamHost Allowable Hosts for your public IP.\n";
    } else {
        echo "WARN database connection not available yet: " . $e->getMessage() . "\n";
        echo "      Install local MySQL/MariaDB, then run scripts/setup_local_db.php\n";
        echo "      Or switch to prod: php scripts/switch_db.php prod\n";
    }
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} check(s) failed.\n");
    exit(1);
}

echo "\nAll critical checks passed (or DB pending).\n";
exit(0);
