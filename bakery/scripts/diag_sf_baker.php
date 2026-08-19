<?php
define('ACCESS_ALLOWED', true);
require dirname(__DIR__) . '/includes/config.php';
require dirname(__DIR__) . '/includes/database.php';
$db = check_mysql_connection();
require dirname(__DIR__) . '/includes/sf_baker.php';

echo 'DB: ' . DB_NAME . '@' . DB_HOST . "\n";
echo 'USE_PROD_DB: ' . (defined('USE_PROD_DB') && USE_PROD_DB ? 'true' : 'false') . "\n";
echo 'runtime DDL allowed: ' . (bakery_runtime_schema_ddl_allowed() ? 'yes' : 'no') . "\n";
echo 'sf_baker_enabled column: ' . (column_exists($db, 'customers', 'sf_baker_enabled') ? 'yes' : 'NO') . "\n";
echo 'sfb_batches: ' . (table_exists($db, 'sfb_batches') ? 'yes' : 'NO') . "\n";
echo 'sfb_formulas: ' . (table_exists($db, 'sfb_formulas') ? 'yes' : 'NO') . "\n";
echo 'sfb_batch_formula_snapshots: ' . (table_exists($db, 'sfb_batch_formula_snapshots') ? 'yes' : 'NO') . "\n";
echo 'sfb_batch_formula_snapshot_lines: ' . (table_exists($db, 'sfb_batch_formula_snapshot_lines') ? 'yes' : 'NO') . "\n";
echo 'sfb_batch_messages: ' . (table_exists($db, 'sfb_batch_messages') ? 'yes' : 'NO') . "\n";
echo 'tables_ready (032): ' . (bakery_sfb_tables_ready($db) ? 'yes' : 'NO') . "\n";
echo 'formula_snapshots_ready (033): ' . (bakery_sfb_formula_snapshots_ready($db) ? 'yes' : 'NO') . "\n";
echo 'discussion_ready (034): ' . (bakery_sfb_discussion_ready($db) ? 'yes' : 'NO') . "\n";
$adminReady = bakery_sfb_tables_ready($db)
    && bakery_sfb_formula_snapshots_ready($db)
    && bakery_sfb_discussion_ready($db);
echo 'admin engagement ready: ' . ($adminReady ? 'yes' : 'NO — run: php scripts/run_migrations.php') . "\n";
