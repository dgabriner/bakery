<?php
/**
 * Product Manager Plan Center board helper.
 *
 * CLI / local bakerysf_test only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/product_manager_plan.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: tests must run with APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $msg) use (&$pass, &$fail): void {
    if ($ok) {
        echo "PASS  $msg\n";
        $pass++;
    } else {
        echo "FAIL  $msg\n";
        $fail++;
    }
};

$assert(bakery_product_manager_plan_resolve_date('2026-08-22') === '2026-08-22', 'resolve valid date');
$assert(bakery_product_manager_plan_resolve_date('nope', '2026-08-20') === '2026-08-20', 'resolve falls back');
$assert(bakery_product_manager_plan_normalize_family('daily') === BAKERY_PRODUCTION_CADENCE_DAILY, 'family daily');
$assert(bakery_product_manager_plan_normalize_family('sf') === BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR, 'family sf');
$assert(bakery_product_manager_plan_normalize_family('') === 'all', 'family empty → all');

// Friday bake covers Sat–Mon for daily family.
$board = bakery_product_manager_plan_board($db, '2026-08-22', BAKERY_PRODUCTION_CADENCE_DAILY);
$assert($board['delivery_date'] === '2026-08-22', 'board delivery date');
$assert($board['bake_date'] === '2026-08-21', 'Sat delivery baked Friday');
$assert($board['cover_dates'] === ['2026-08-22', '2026-08-23', '2026-08-24'], 'Fri cover Sat-Sun-Mon');
$assert(isset($board['summary']['focus_demand']), 'summary present');
$assert(is_array($board['rows']), 'rows array');

// Row shape when products exist
if ($board['rows'] !== []) {
    $row = $board['rows'][0];
    $assert(isset($row['standard_quantity'], $row['standing_quantity'], $row['focus_demand'], $row['cover_demand'], $row['make_need']), 'row fields');
    $assert(isset($row['demand_by_date']['2026-08-22']), 'per-date demand');
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
