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
    $nightly = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dumps' . DIRECTORY_SEPARATOR . 'nightly';
    $snapshots = glob($nightly . DIRECTORY_SEPARATOR . 'live_*.sql.gz') ?: [];
    rsort($snapshots, SORT_STRING);
    if (!$snapshots) {
        // Cloud / Linux agents have no production snapshot. Fall back to the
        // sanitized schema + demo fixtures — still strictly bakerysf_test.
        fwrite(STDERR, "NOTE  No production snapshot under storage/dumps/nightly; resetting bakerysf_test from schema + fixtures.\n");
        $cmd = '"' . PHP_BINARY . '" ' . escapeshellarg($root . '/scripts/setup_local_db.php')
            . ' --reset --force-reset --database=bakerysf_test';
        passthru($cmd, $code);
        if ($code !== 0) {
            fwrite(STDERR, "Isolated test database fixture reset failed\n");
            exit(1);
        }
        return;
    }
    $cmd = '"' . PHP_BINARY . '" ' . escapeshellarg($root . '/scripts/refresh_local_from_snapshot.php')
        . ' --snapshot=' . escapeshellarg($snapshots[0]) . ' --target=bakerysf_test';
    passthru($cmd, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Isolated test database reset failed\n");
        exit(1);
    }
}
