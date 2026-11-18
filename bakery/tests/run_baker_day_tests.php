<?php
/**
 * Danny's baker day: batch scaling, pack piles, and publish.
 * Juan's mix page is not involved. Database: bakerysf_test only.
 *
 * Usage: php tests/run_baker_day_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
/** @var PDO $db */
$db = require __DIR__ . '/harness.php';

require_once $root . '/includes/i18n.php';
require_once $root . '/includes/baker_day.php';
require_once $root . '/includes/admin_workspace_mode.php';

$_SESSION['user_id'] = 1;
$_SESSION['user_role_slug'] = 'administrator';
$_SESSION['user_display_name'] = 'Danny';

assert_true(bakery_admin_craft_preview_allowed(['role_slug' => 'administrator', 'display_name' => 'Danny']), 'Danny’s admin profile can open the baker day');
assert_true(bakery_admin_craft_preview_allowed(['role_slug' => 'administrator', 'display_name' => 'Danny Gabriner']), 'a longer Danny name still matches');
assert_true(!bakery_admin_craft_preview_allowed(['role_slug' => 'administrator', 'display_name' => 'Pat']), 'another administrator does not get the preview');
assert_true(!bakery_admin_craft_preview_allowed(['role_slug' => 'baker', 'display_name' => 'Danny']), 'a baker named Danny does not get the preview');
assert_true(!bakery_admin_craft_preview_allowed(null), 'signed out does not get the preview');

$scaled = bakery_baker_day_scale_quantities([1 => 10, 2 => 4, 3 => 2], 2.0, 1.0, 16.0);
assert_eq([1 => 20, 2 => 8, 3 => 4], $scaled, 'doubling a batch doubles each shape');

$remainder = bakery_baker_day_scale_quantities([1 => 10, 2 => 1], 1.0, 1.0, 12.0);
assert_eq([1 => 11, 2 => 1], $remainder, 'a leftover piece stays on the largest shape');

$half = bakery_baker_day_scale_quantities([1 => 6, 2 => 4], 0.5, 1.0, 10.0);
assert_eq([1 => 3, 2 => 2], $half, 'a half batch keeps the same split');

$cleared = bakery_baker_day_scale_quantities([1 => 6, 2 => 4], 0.0, 1.0, 10.0);
assert_eq([1 => 0, 2 => 0], $cleared, 'zero batches clears every shape');

assert_eq(2.0, bakery_baker_day_step_batches(1.5, 0.5), 'plus steps up by half a batch');
assert_eq(1.0, bakery_baker_day_step_batches(1.5, -0.5), 'minus steps down by half a batch');
assert_eq(0.0, bakery_baker_day_step_batches(0.5, -0.5), 'minus does not go below zero');

$lines = [
    ['customer_id' => 1, 'customer_name' => "Civic Center Farmer's Market", 'product_id' => 9, 'product_name' => 'Country', 'quantity' => 10],
    ['customer_id' => 2, 'customer_name' => 'Corner Store', 'product_id' => 9, 'product_name' => 'Country', 'quantity' => 6],
    ['customer_id' => 3, 'customer_name' => 'Capp', 'product_id' => 9, 'product_name' => 'Country', 'quantity' => 4],
    ['customer_id' => 4, 'customer_name' => "Noe Valley Farmer's Market", 'product_id' => 9, 'product_name' => 'Country', 'quantity' => 5],
    ['customer_id' => 5, 'customer_name' => 'Walk-in', 'product_id' => 9, 'product_name' => 'Country', 'quantity' => 2],
];
$routes = [
    1 => ['driver_id' => 4, 'driver_name' => 'Ana'],
    2 => ['driver_id' => 4, 'driver_name' => 'Ana'],
    3 => ['driver_id' => 5, 'driver_name' => 'Luis'],
    4 => ['driver_id' => 5, 'driver_name' => 'Luis'],
];
$packed = bakery_baker_day_pack_products($lines, $routes);
assert_eq(1, count($packed), 'one product stays one pack row');
assert_eq(27, $packed[0]['ordered'], 'pile quantities add up to the order');
$byKind = [];
foreach ($packed[0]['piles'] as $pile) {
    $byKind[$pile['kind']] = ($byKind[$pile['kind']] ?? 0) + (int)$pile['quantity'];
}
assert_eq(6, $byKind['driver'] ?? 0, 'the driver pile keeps only the route stops');
assert_eq(10, $byKind['civic_center'] ?? 0, 'Civic Center is its own pile');
assert_eq(5, $byKind['noe_valley'] ?? 0, 'Noe Valley is its own pile');
assert_eq(4, $byKind['capp'] ?? 0, 'Capp is its own pile');
assert_eq(2, $byKind['unassigned'] ?? 0, 'a stop with no driver is unassigned');
$civic = null;
foreach ($packed[0]['piles'] as $pile) {
    if ($pile['kind'] === 'civic_center') {
        $civic = $pile;
    }
}
assert_eq('Ana', $civic['stops'][0]['driver_name'] ?? '', 'the market pile still names the driver who carries it');

foreach (['baker_mix.php', 'production.php', 'pack_list.php'] as $page) {
    $source = (string)file_get_contents($root . '/' . $page);
    assert_true(strpos($source, 'baker_day') === false, $page . ' does not mention the new baker day');
}
$navSource = (string)file_get_contents($root . '/includes/nav.php');
assert_true(strpos($navSource, "elseif (\$navRole === 'baker')") !== false, 'Juan’s baker nav branch is still there');
assert_true(strpos($navSource, 'production.php?date=') !== false, 'Juan’s nav still opens Daily Production');

$manifest = (string)file_get_contents($root . '/scripts/deploy_manifest.ps1');
assert_true(strpos($manifest, "'baker_day.php'") !== false, 'baker_day.php is on the deploy root list');

$date = '2099-11-17';
$productIds = $db->query('SELECT id FROM products ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
$productA = (int)($productIds[0] ?? 0);
$productB = (int)($productIds[1] ?? 0);
assert_true($productA > 0 && $productB > 0 && $productA !== $productB, 'two products exist for the publish check');

$cleanup = static function (PDO $db, string $date): void {
    if (table_exists($db, 'production_plan_commit_items')) {
        $db->prepare('DELETE FROM production_plan_commit_items WHERE delivery_date = ?')->execute([$date]);
    }
    if (table_exists($db, 'production_plan_commits')) {
        $db->prepare('DELETE FROM production_plan_commits WHERE delivery_date = ?')->execute([$date]);
    }
    if (table_exists($db, 'production_plan_items')) {
        $db->prepare('DELETE FROM production_plan_items WHERE delivery_date = ?')->execute([$date]);
    }
    if (function_exists('bakery_operational_events_ready') && bakery_operational_events_ready($db)) {
        $db->prepare('DELETE FROM operational_events WHERE operational_date = ?')->execute([$date]);
    }
};

try {
    $cleanup($db, $date);
    bakery_production_plan_commits_ensure($db);
    bakery_baker_day_save_products($db, $date, [$productA => 10, $productB => 6], null);
    bakery_production_plan_commit($db, $date, null);
    bakery_baker_day_save_products($db, $date, [$productA => 20], null);
    $working = bakery_baker_day_working_quantities($db, $date);
    assert_eq(20, (int)($working[$productA] ?? 0), 'the draft replaces only the edited product');
    assert_eq(6, (int)($working[$productB] ?? 0), 'the other product stays on the published bake');
    bakery_baker_day_publish($db, $date, null);
    $committed = bakery_production_plan_committed_quantities($db, $date);
    assert_eq(20, (int)($committed[$productA] ?? 0), 'publish updates the edited product on the bake Juan reads');
    assert_eq(6, (int)($committed[$productB] ?? 0), 'publish keeps the product that was not edited');
} finally {
    $cleanup($db, $date);
}

echo "\n{$GLOBALS['TEST_PASS']} passed, {$GLOBALS['TEST_FAIL']} failed\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
