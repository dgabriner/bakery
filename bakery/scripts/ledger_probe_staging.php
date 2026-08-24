<?php
/** Read-only ledger/vault probe for bakerysoftware. Prints migration ids, vault files, and 067/068 object checks. */
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

echo "connected to database: " . $db->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo "\n-- schema_migrations entries >= 05 --\n";
$rows = $db->query("SELECT id FROM schema_migrations WHERE id >= '05' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
echo $rows ? implode("\n", $rows) : "(none)";
echo "\n\n-- vault listing (glob [0-9][0-9][0-9]_*.sql) --\n";
$files = glob('/home/bakeryOS/.sourflour-migration-source/[0-9][0-9][0-9]_*.sql') ?: [];
echo $files ? implode("\n", array_map('basename', $files)) : "(none)";
echo "\n\n-- raw scandir of vault --\n";
$all = scandir('/home/bakeryOS/.sourflour-migration-source') ?: [];
echo implode("\n", array_filter($all, static function ($n) { return $n !== '.' && $n !== '..'; }));
echo "\n";

function bakery_probe_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function bakery_probe_report(string $label, bool $ok): void
{
    echo ($ok ? 'PRESENT' : 'MISSING') . "  {$label}\n";
}

echo "\n-- education object checks (067/068) --\n";
$kindType = $db->query(
    "SELECT COLUMN_TYPE FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'sfb_offerings' AND column_name = 'kind'"
)->fetchColumn();
if ($kindType === false) {
    echo "MISSING  sfb_offerings.kind\n";
} else {
    echo "sfb_offerings.kind = {$kindType}\n";
    bakery_probe_report("sfb_offerings.kind includes 'donation'", strpos($kindType, "'donation'") !== false);
    bakery_probe_report("sfb_offerings.kind includes 'credits'", strpos($kindType, "'credits'") !== false);
}
bakery_probe_report('sfb_offerings.units', bakery_probe_column_exists($db, 'sfb_offerings', 'units'));
bakery_probe_report('sfb_offering_purchases.paid_with', bakery_probe_column_exists($db, 'sfb_offering_purchases', 'paid_with'));
bakery_probe_report('sfb_courses.required_offering_id', bakery_probe_column_exists($db, 'sfb_courses', 'required_offering_id'));

$stmt = $db->prepare(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = ?"
);
$stmt->execute(['sfb_credit_entries']);
bakery_probe_report('table sfb_credit_entries', (int)$stmt->fetchColumn() > 0);
echo "\n";
