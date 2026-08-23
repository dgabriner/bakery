<?php
/** Regression checks for the fail-closed local test target guard. */
if (PHP_SAPI !== 'cli') { exit(1); }
define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
putenv('DB_NAME=bakerysf_test');
$_ENV['DB_NAME'] = 'bakerysf_test';
$_SERVER['DB_NAME'] = 'bakerysf_test';
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
$db = check_mysql_connection();
bakery_assert_local_test_target($db);

$source = file_get_contents($root . '/includes/test_target_guard.php');
$checks = [
    'USE_PROD_DB is rejected' => strpos($source, 'USE_PROD_DB=false') !== false,
    'actual selected database is checked' => strpos($source, 'SELECT DATABASE()') !== false,
    'loopback PDO connection status is checked' => strpos($source, 'ATTR_CONNECTION_STATUS') !== false,
    'exact disposable test database is required' => strpos($source, "['bakerysf_test']") !== false,
    'homebase writes stay off the nightly mirror' => strpos($source, 'function bakery_assert_homebase_target') !== false
        && strpos($source, "['bakerysf_stage_local', 'bakerysf_test']") !== false,
    'homebase hops from the nightly mirror onto staging' => strpos($source, 'function bakery_homebase_durable_connection') !== false
        && strpos($source, 'bakerysf_stage_local') !== false,
];
$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    $failed += $ok ? 0 : 1;
}
exit($failed === 0 ? 0 : 1);
