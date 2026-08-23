<?php
/** Static safety contracts for the production-derived local snapshot workflow. */
define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
$snapshot = file_get_contents($root . '/scripts/snapshot_production.php');
$refresh = file_get_contents($root . '/scripts/refresh_local_from_snapshot.php');
$fail = 0;
$assert = function ($ok, $label) use (&$fail) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) $fail++;
};

$assert(strpos($snapshot, 'prod_db_mysqldump') !== false, 'snapshot uses shared dump helper');
$dumpHelper = (string)file_get_contents($root . '/scripts/prod_db_cli.php');
$assert(strpos($dumpHelper, 'prod_db_cli_supports_option') !== false, 'dump helper detects client-version option support');
$assert(strpos($dumpHelper, 'prod_db_cli_supports_option($mysqldump, \'--ssl-verify-server-cert\')') !== false, 'new SSL flag is conditional for older hosted clients');
$assert(strpos($snapshot, 'snapshot_validate_sql') !== false, 'snapshot validates SQL markers');
$assert(strpos($snapshot, 'gzopen') !== false, 'snapshot writes compressed SQL');
$assert(strpos($snapshot, 'PROD_DB_') === false, 'snapshot does not print production credential values');
$assert(strpos($refresh, "['bakerysf_local', 'bakerysf_stage_local', 'bakerysf_test']") !== false, 'refresh allow-list is explicit');
$assert(strpos($refresh, 'bakerysf_refresh_local') !== false, 'refresh imports into a temporary database first');
$assert(strpos($refresh, 'Checkpoint restored') !== false, 'refresh restores a failed target');
$assert(strpos($refresh, 'DROP DATABASE IF EXISTS') !== false, 'refresh drop is limited to validated local names');
echo $fail . ' failed, ' . (8 - $fail) . ' passed' . PHP_EOL;
exit($fail === 0 ? 0 : 1);
