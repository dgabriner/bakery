<?php
/**
 * Standing route store analysis — decide + save.
 * Usage: php tests/run_standing_route_analysis_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

putenv('USE_PROD_DB=false');
$_ENV['USE_PROD_DB'] = 'false';
$_SERVER['USE_PROD_DB'] = 'false';

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
bakery_reset_isolated_test_db($root);

/** @var PDO $db */
$db = require __DIR__ . '/harness.php';
require_once $root . '/includes/standing_route_analysis.php';

$_SESSION['user_id'] = 1;
$_SESSION['user_email'] = 'standing-route-analysis@example.test';
$_SESSION['user_display_name'] = 'Standing Route Analysis';
$_SESSION['user_role_slug'] = 'administrator';

$names = ['marcos' => 4, 'sergio' => 1, 'laura' => 2, 'marisol' => 6, 'juan' => 7];

echo "\n=== Decide: usual driver vs standing ===\n";
$sergioUsual = [['driver_id' => 1, 'name' => 'Sergio', 'visits' => 3]];
$add = bakery_standing_route_decide(null, $sergioUsual, 5, 'Daly City/San Mateo', true, 7, $names);
assert_eq('add', $add['status'], 'Friday Daly leftover with usual Sergio is add');
assert_eq(1, $add['suggest_driver_id'], 'suggest Sergio');
assert_true($add['applyable'], 'add is applyable');

$match = bakery_standing_route_decide(1, $sergioUsual, 5, 'Daly City/San Mateo', true, 7, $names);
assert_eq('match', $match['status'], 'standing Sergio matches usual');
assert_true(!$match['applyable'], 'match is not applyable');

$change = bakery_standing_route_decide(4, $sergioUsual, 5, 'Daly City/San Mateo', true, 7, $names);
assert_eq('change', $change['status'], 'standing Marcos vs usual Sergio is change');
assert_eq(1, $change['suggest_driver_id'], 'change still suggests Sergio');

echo "\n=== Decide: fuzzy Mission and Juan fill ===\n";
$split = bakery_standing_route_decide(null, [
    ['driver_id' => 2, 'name' => 'Laura', 'visits' => 2],
    ['driver_id' => 4, 'name' => 'Marcos', 'visits' => 2],
], 2, 'Mission', true, 7, $names);
assert_eq('ask', $split['status'], 'Tuesday Mission split is ask');
assert_true(!$split['applyable'], 'do not auto-lock Tuesday Mission');

$fill = bakery_standing_route_decide(null, [
    ['driver_id' => 7, 'name' => 'Juan', 'visits' => 4],
], 2, 'Mission', true, 7, $names);
assert_eq('fill', $fill['status'], 'Juan-usual Tuesday is fill not standing');
assert_true(!$fill['applyable'], 'Juan is not written to standing');

echo "\n=== Decide: zone fallback ===\n";
$sun = bakery_standing_route_decide(null, [], 7, 'Centro', true, 7, $names);
assert_eq('add', $sun['status'], 'Sunday Centro leftover falls to Laura');
assert_eq(2, $sun['suggest_driver_id'], 'suggest Laura');
assert_eq('standing_routes.reason_zone', $sun['reason_key'], 'zone fallback reason');

$mon = bakery_standing_route_decide(null, [], 1, 'East Bay', true, 7, $names);
assert_eq(4, $mon['suggest_driver_id'], 'Monday East Bay is Marcos not Sergio');

echo "\n=== Save standing route ===\n";
$stamp = 'SRAnal ' . substr(bin2hex(random_bytes(4)), 0, 8);
$db->prepare('INSERT INTO customers (name, zone, is_active) VALUES (?, ?, 1)')->execute([$stamp, 'Centro']);
$customerId = (int)$db->lastInsertId();
assert_true($customerId > 0, 'test customer inserted');

$driverId = (int)$db->query("SELECT id FROM drivers ORDER BY id LIMIT 1")->fetchColumn();
assert_true($driverId > 0, 'fixture has a driver');

bakery_standing_route_save($db, $customerId, $driverId, 1);
$stmt = $db->prepare('SELECT driver_id FROM standing_routes WHERE customer_id = ? AND day_of_week = 1');
$stmt->execute([$customerId]);
assert_eq($driverId, (int)$stmt->fetchColumn(), 'Monday standing saved');

bakery_standing_route_save($db, $customerId, 0, 1);
$stmt->execute([$customerId]);
assert_eq(false, $stmt->fetchColumn(), 'clearing driver removes standing row');

$src = (string)file_get_contents($root . '/standing_routes.php');
assert_true(strpos(ltrim($src), '<?php') === 0, 'standing_routes.php starts with PHP so POST JSON is clean');
assert_true(strpos($src, "bakery_asset_href('css/standing_routes.css')") !== false, 'page still links standing_routes.css');
assert_true(strpos($src, 'view=stores') !== false, 'Standing Routes has By store view');
assert_true(strpos($src, 'bakery_standing_route_store_analysis') !== false, 'page uses analysis helper');
assert_true(strpos($src, 'standing_routes.php?view=stores') !== false, 'store tab stays on standing_routes.php');

echo "\n=== Zone helpers ===\n";
assert_eq('daly', bakery_standing_zone_key('Daly City/San Mateo'), 'Daly zone key');
assert_true(bakery_standing_zone_is_fuzzy(2, 'mission'), 'Tue Mission fuzzy');
assert_true(bakery_standing_zone_is_fuzzy(6, 'mission'), 'Sat Mission fuzzy');
assert_true(!bakery_standing_zone_is_fuzzy(4, 'mission'), 'Thu Mission is not fuzzy');

$db->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);

echo "\n{$GLOBALS['TEST_PASS']} passed, {$GLOBALS['TEST_FAIL']} failed\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
