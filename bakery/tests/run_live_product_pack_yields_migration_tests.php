<?php
/**
 * Proves the hosted-safe 053 product-pack-yield migration on bakerysf_test.
 * It creates only additive tables/reference rows, then restores the normal
 * 052 reference fixture for any later test in the same process sequence.
 */
if (PHP_SAPI !== 'cli') { exit(1); }

define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/schema_sql.php';
require_once $root . '/includes/product_pack_yields.php';
require_once $root . '/includes/hosted_migration_approval.php';

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

$fail = 0;
$assert = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . PHP_EOL;
    if (!$ok) { $fail++; }
};

$liveMigration = $root . '/database/schema/054_live_product_pack_yields_mysql_compat.sql';
$stagingMigration = $root . '/database/schema/052_product_pack_yields.sql';
[$safe, $message] = bakery_hosted_migration_sql_safe((string)file_get_contents($liveMigration));
$assert($safe, '054 is accepted by the hosted additive migration gate: ' . $message);
$compatibilitySql = (string)file_get_contents($liveMigration);
$assert(strpos($compatibilitySql, 'notes TEXT NULL DEFAULT NULL') === false, '054 avoids legacy-MySQL TEXT defaults');

try {
    // These are disposable reference tables. Disable FK checks only for this
    // isolated test so a previous fixture cannot mask a missing CREATE TABLE.
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    $db->exec('DROP TABLE IF EXISTS product_aliases, product_pack_yields, dough_type_pack_yields');
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    bakery_run_sql_file($db, $liveMigration);

    $assert(bakery_pack_yields_ready($db), '054 creates all product-pack-yield tables');
    $aliases = (int)$db->query('SELECT COUNT(*) FROM product_aliases')->fetchColumn();
    $productRows = (int)$db->query('SELECT COUNT(*) FROM product_pack_yields')->fetchColumn();
    $doughRows = (int)$db->query('SELECT COUNT(*) FROM dough_type_pack_yields')->fetchColumn();
    $assert($aliases >= 40, '054 inserts the product aliases without updating existing rows');
    $assert($productRows >= 7, '054 inserts the product pack-yield references');
    $assert($doughRows >= 2, '054 inserts the dough pack-yield references');
} finally {
    // Leave the normal Staging-friendly fixture available for suites that run
    // after this one. This stays inside the disposable bakerysf_test database.
    bakery_run_sql_file($db, $stagingMigration);
}

echo $fail . " failed\n";
exit($fail === 0 ? 0 : 1);
