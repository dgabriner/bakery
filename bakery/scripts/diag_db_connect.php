<?php
/**
 * Print real DB connection status (CLI). Does not print passwords.
 */
define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';

echo 'APP_ENV=' . APP_ENV . PHP_EOL;
echo 'USE_PROD_DB=' . (defined('USE_PROD_DB') && USE_PROD_DB ? 'true' : 'false') . PHP_EOL;
echo 'DB_HOST=' . DB_HOST . PHP_EOL;
echo 'DB_PORT=' . DB_PORT . PHP_EOL;
echo 'DB_NAME=' . DB_NAME . PHP_EOL;
echo 'DB_USER=' . DB_USER . PHP_EOL;

try {
    $db = check_mysql_connection();
    $name = $db->query('SELECT DATABASE()')->fetchColumn();
    $ver = $db->query('SELECT VERSION()')->fetchColumn();
    echo "CONNECT_OK database={$name} version={$ver}\n";
    exit(0);
} catch (Throwable $e) {
    echo 'CONNECT_FAIL: ' . $e->getMessage() . PHP_EOL;
    echo "Hints:\n";
    echo "  - DreamHost panel → MySQL → Allowable Hosts: add your public IP\n";
    echo "  - Confirm PROD_DB_PASS in .env.production.pull\n";
    echo "  - php scripts/switch_db.php  (confirm mode)\n";
    exit(1);
}
