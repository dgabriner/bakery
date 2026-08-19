<?php
/**
 * Point this PHP process at bakerysf_test so demo-fixture resets cannot
 * wipe the bakerysf_local production mirror.
 */
putenv('USE_PROD_DB=false');
$_ENV['USE_PROD_DB'] = 'false';
$_SERVER['USE_PROD_DB'] = 'false';
putenv('DB_NAME=bakerysf_test');
$_ENV['DB_NAME'] = 'bakerysf_test';
$_SERVER['DB_NAME'] = 'bakerysf_test';

function bakery_reset_isolated_test_db($root) {
    $cmd = '"' . PHP_BINARY . '" ' . escapeshellarg($root . '/scripts/setup_local_db.php')
        . ' --reset --force-reset --database=bakerysf_test';
    passthru($cmd, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Isolated test database reset failed\n");
        exit(1);
    }
}
