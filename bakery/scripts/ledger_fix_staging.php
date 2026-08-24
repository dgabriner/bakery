<?php
/** Scoped repair: drop orphaned ledger rows for the four education migrations, then re-show state.
 *  Bootstrap mirrors run_migrations.php --mode=hosted-stage (no web config; direct env + connection). */
define('ACCESS_ALLOWED', true);
$root = '/home/bakeryOS/staging.sourflour.org';
require_once $root . '/includes/env_loader.php';
bakery_clear_env_keys(['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'APP_ENV', 'USE_PROD_DB']);
bakery_load_env_file($root . '/.env', true);
putenv('APP_ENV=staging');
$_ENV['APP_ENV'] = 'staging';
$_SERVER['APP_ENV'] = 'staging';
putenv('USE_PROD_DB=false');
$_ENV['USE_PROD_DB'] = 'false';
$_SERVER['USE_PROD_DB'] = 'false';
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
$db = check_mysql_connection();
bakery_assert_dreamhost_staging_target($db);

$ids = [
    '062_bread_education',
    '063_bread_education_learning',
    '064_bread_education_invites',
    '066_bread_education_payments',
];

$connected = (string)$db->query('SELECT DATABASE()')->fetchColumn();
echo "connected to database: " . $connected . "\n";
if ($connected !== 'bakerysoftware') {
    echo "REFUSING: expected bakerysoftware\n";
    exit(1);
}

echo "\n-- ledger rows BEFORE --\n";
$marks = implode(',', array_fill(0, count($ids), '?'));
$stmt = $db->prepare("SELECT id FROM schema_migrations WHERE id IN ($marks) ORDER BY id");
$stmt->execute($ids);
$before = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo $before ? implode("\n", $before) : '(none)';

if ($before) {
    $del = $db->prepare("DELETE FROM schema_migrations WHERE id IN ($marks)");
    $del->execute($ids);
    echo "\n\nDeleted rows: " . $del->rowCount() . "\n";
} else {
    echo "\n\nNothing to delete - ledger already clean.\n";
}

echo "\n-- education tables AFTER --\n";
$tables = $db->query(
    "SELECT table_name FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND (table_name LIKE 'sfb_courses%' OR table_name LIKE 'sfb_lesson%'
            OR table_name LIKE 'sfb_offering%' OR table_name LIKE 'sfb_invites%')
     ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);
echo $tables ? implode("\n", $tables) : "(none)";
echo "\n";
