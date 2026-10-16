<?php
/**
 * Dated route removals, morning-loss alerts, standing-driver gaps, and $0 orders.
 *
 * Usage: php tests/run_route_integrity_tests.php
 * Targets bakerysf_test on loopback only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
$db = require $root . '/tests/harness.php';
require_once $root . '/includes/driver_assignments.php';
require_once $root . '/includes/daily_order_generation.php';
require_once $root . '/includes/demand_review.php';
require_once $root . '/includes/survey_store_verify.php';
require_once $root . '/includes/dashboard_command_center.php';
require_once $root . '/includes/daily_run.php';

$_SESSION['user_id'] = 1;
$_SESSION['user_email'] = 'route-integrity@example.test';
$_SESSION['user_display_name'] = 'Route Integrity';
$_SESSION['user_role_slug'] = 'administrator';

$prefix = 'RI ' . bin2hex(random_bytes(3));
$productId = (int)$db->query(
    'SELECT so.product_id
     FROM standing_orders so
     JOIN products p ON p.id = so.product_id
     LIMIT 1'
)->fetchColumn();
assert_true($productId > 0, 'fixture product exists for route integrity');

$originSql = '';
if (function_exists('column_exists') && column_exists($db, 'customers', 'sfb_origin')) {
    $originSql = ', sfb_origin';
}
$insertCustomer = $db->prepare(
    'INSERT INTO customers (name, address, is_active' . $originSql . ') VALUES (?, ?, 1' . ($originSql !== '' ? ", 'human'" : '') . ')'
);
$insertDriver = $db->prepare('INSERT INTO drivers (name) VALUES (?)');
$insertDriver->execute([$prefix . ' Driver']);
$driverId = (int)$db->lastInsertId();
$insertDriver->execute([$prefix . ' Other']);
$otherDriverId = (int)$db->lastInsertId();

$insertCustomer->execute([$prefix . ' Keep', '1 Integrity Way']);
$keepId = (int)$db->lastInsertId();
$insertCustomer->execute([$prefix . ' Drop', '2 Integrity Way']);
$dropId = (int)$db->lastInsertId();
$insertCustomer->execute([$prefix . ' Optimize', '3 Integrity Way']);
$optimizeId = (int)$db->lastInsertId();
$insertCustomer->execute([$prefix . ' Friday', '4 Integrity Way']);
$fridayCustomerId = (int)$db->lastInsertId();
$insertCustomer->execute([$prefix . ' Zero', '5 Integrity Way']);
$zeroId = (int)$db->lastInsertId();
$insertCustomer->execute([$prefix . ' Shell', '6 Integrity Way']);
$shellId = (int)$db->lastInsertId();

$customerIds = [$keepId, $dropId, $optimizeId, $fridayCustomerId, $zeroId, $shellId];
register_shutdown_function(static function () use ($db, $customerIds, $driverId, $otherDriverId): void {
    $in = implode(',', array_map('intval', $customerIds));
    if ($in === '') {
        return;
    }
    try {
        foreach ([
            'dated_route_removal_log',
            'dated_route_removals',
            'dated_route_morning_checks',
        ] as $table) {
            if (function_exists('table_exists') && table_exists($db, $table)) {
                $db->exec("DELETE FROM {$table} WHERE delivery_date >= '2099-11-01' AND delivery_date < '2100-01-01'");
            }
        }
        $db->exec(
            "DELETE doa FROM daily_order_assignments doa
             JOIN daily_orders do ON do.id = doa.daily_order_id
             WHERE do.order_date >= '2099-11-01' AND do.order_date < '2100-01-01'"
        );
        $db->exec(
            "DELETE doi FROM daily_order_items doi
             JOIN daily_orders do ON do.id = doi.daily_order_id
             WHERE do.order_date >= '2099-11-01' AND do.order_date < '2100-01-01'"
        );
        $db->exec("DELETE FROM daily_orders WHERE order_date >= '2099-11-01' AND order_date < '2100-01-01'");
        $db->exec(
            "DELETE doa FROM daily_order_assignments doa
             JOIN daily_orders do ON do.id = doa.daily_order_id
             WHERE do.customer_id IN ($in)"
        );
        $db->exec(
            "DELETE doi FROM daily_order_items doi
             JOIN daily_orders do ON do.id = doi.daily_order_id
             WHERE do.customer_id IN ($in)"
        );
        $db->exec("DELETE FROM daily_orders WHERE customer_id IN ($in)");
        $db->exec("DELETE FROM standing_routes WHERE customer_id IN ($in)");
        $db->exec("DELETE FROM standing_orders WHERE customer_id IN ($in)");
        $db->exec("DELETE FROM customers WHERE id IN ($in)");
        $db->exec('DELETE FROM drivers WHERE id IN (' . (int)$driverId . ',' . (int)$otherDriverId . ')');
    } catch (Throwable $e) {
        fwrite(STDERR, 'route integrity cleanup: ' . $e->getMessage() . "\n");
    }
});

$friday = new DateTimeImmutable('2099-11-01');
while ($friday->format('N') !== '5') {
    $friday = $friday->modify('+1 day');
}
$fridayDate = $friday->format('Y-m-d');
$weekday = bakery_standing_day_from_date($fridayDate);
$routeDate = $friday->modify('+7 days')->format('Y-m-d');

function route_integrity_order(PDO $db, int $customerId, string $date, float $total = 12.5): int
{
    $stmt = $db->prepare(
        'INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, \'pending\', ?)'
    );
    $stmt->execute([$customerId, $date, $total]);
    return (int)$db->lastInsertId();
}

function route_integrity_assigned_customers(PDO $db, int $driverId, string $date): array
{
    $stmt = $db->prepare(
        'SELECT do.customer_id
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         WHERE doa.driver_id = ? AND doa.delivery_date = ?
         ORDER BY doa.route_order, doa.id'
    );
    $stmt->execute([$driverId, $date]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

echo "\n=== Optimize reorder does not drop omitted stops ===\n";
$keepOrder = route_integrity_order($db, $keepId, $routeDate);
$dropOrder = route_integrity_order($db, $dropId, $routeDate);
$optimizeOrder = route_integrity_order($db, $optimizeId, $routeDate);
bakery_driver_assign_orders($db, $driverId, $routeDate, [
    ['daily_order_id' => $keepOrder, 'route_order' => 1],
    ['daily_order_id' => $dropOrder, 'route_order' => 2],
    ['daily_order_id' => $optimizeOrder, 'route_order' => 3],
]);
$reordered = bakery_driver_assign_orders($db, $driverId, $routeDate, [
    ['daily_order_id' => $optimizeOrder, 'route_order' => 1, 'scheduled_delivery_time' => '07:10'],
    ['daily_order_id' => $keepOrder, 'route_order' => 2, 'scheduled_delivery_time' => '07:40'],
], 'reorder');
assert_eq('reorder', $reordered['mode'], 'optimize save uses reorder mode');
assert_eq(
    [$optimizeId, $keepId, $dropId],
    route_integrity_assigned_customers($db, $driverId, $routeDate),
    'reorder keeps the stop Google did not return'
);
$reorderLogs = $db->prepare(
    'SELECT COUNT(*) FROM dated_route_removal_log WHERE delivery_date = ? AND reason = ?'
);
$reorderLogs->execute([$routeDate, 'saved_route_omitted']);
assert_eq(0, (int)$reorderLogs->fetchColumn(), 'reorder does not log a removal');

echo "\n=== Replace logs the omitted stop and blocks the next fill ===\n";
bakery_dated_route_morning_capture($db, $routeDate);
$morningBefore = $db->prepare('SELECT COUNT(*) FROM dated_route_morning_checks WHERE delivery_date = ?');
$morningBefore->execute([$routeDate]);
$morningCount = (int)$morningBefore->fetchColumn();
assert_true($morningCount >= 3, 'morning check records the route');
bakery_driver_assign_orders($db, $driverId, $routeDate, [
    ['daily_order_id' => $keepOrder, 'route_order' => 1],
    ['daily_order_id' => $optimizeOrder, 'route_order' => 2],
], 'replace');
$logStmt = $db->prepare(
    'SELECT customer_id, removed_by_user_id, reason, code_path
     FROM dated_route_removal_log
     WHERE delivery_date = ? AND customer_id = ?
     ORDER BY id DESC LIMIT 1'
);
$logStmt->execute([$routeDate, $dropId]);
$logRow = $logStmt->fetch(PDO::FETCH_ASSOC);
assert_eq('saved_route_omitted', (string)($logRow['reason'] ?? ''), 'replace records why the stop left');
assert_eq('driver_assign_orders.replace', (string)($logRow['code_path'] ?? ''), 'replace records the code path');
assert_eq(1, (int)($logRow['removed_by_user_id'] ?? 0), 'replace records who removed the stop');
$tombStmt = $db->prepare('SELECT COUNT(*) FROM dated_route_removals WHERE delivery_date = ? AND customer_id = ?');
$tombStmt->execute([$routeDate, $dropId]);
assert_eq(1, (int)$tombStmt->fetchColumn(), 'replace writes a refill tombstone');

$db->prepare(
    'INSERT INTO standing_routes (day_of_week, driver_id, customer_id, route_order) VALUES (?, ?, ?, 1)'
)->execute([$weekday, $driverId, $dropId]);
standing_save($db, $dropId, $productId, $weekday, 2);
bakery_generate_daily_orders_from_standing($db, $routeDate, ['record_event' => false]);
assert_true(
    !in_array($dropId, route_integrity_assigned_customers($db, $driverId, $routeDate), true),
    'next fill does not copy a logged removal back'
);
bakery_dated_route_morning_capture($db, $routeDate);
$morningBefore->execute([$routeDate]);
assert_eq($morningCount, (int)$morningBefore->fetchColumn(), 'a later capture does not shrink the morning baseline');
$losses = bakery_dated_route_morning_losses($db, $routeDate);
$lossNames = array_column($losses, 'customer_name');
assert_true(in_array($prefix . ' Drop', $lossNames, true), 'Daily Run losses name the store that left');
$lossCountBefore = (int)$db->query('SELECT COUNT(*) FROM dated_route_morning_checks')->fetchColumn();
bakery_dated_route_morning_losses($db, $routeDate);
$lossCountAfter = (int)$db->query('SELECT COUNT(*) FROM dated_route_morning_checks')->fetchColumn();
assert_eq($lossCountBefore, $lossCountAfter, 'reading losses does not write');

$run = bakery_daily_run_build($db, $routeDate);
$lossTypes = [];
foreach ($run['blockers'] as $blocker) {
    $lossTypes[] = (string)($blocker['type'] ?? '');
}
assert_true(in_array('route_lost_after_morning', $lossTypes, true), 'Daily Run alerts when a route loses stops');
$dispatchState = '';
foreach ($run['stages'] as $stage) {
    if (($stage['key'] ?? '') === 'dispatch') {
        $dispatchState = (string)$stage['ui_state'];
    }
}
assert_eq('needs_attention', $dispatchState, 'dispatch needs attention after a morning loss');

echo "\n=== Survey off logs and tombstones only the dropped store ===\n";
$surveyDate = $friday->modify('+14 days')->format('Y-m-d');
$surveyKeep = route_integrity_order($db, $keepId, $surveyDate);
$surveyDrop = route_integrity_order($db, $dropId, $surveyDate);
bakery_driver_assign_orders($db, $driverId, $surveyDate, [
    ['daily_order_id' => $surveyKeep, 'route_order' => 1],
    ['daily_order_id' => $surveyDrop, 'route_order' => 2],
]);
$untouched = bakery_survey_store_verify_apply_routes($db, $surveyDate, [[
    'driver_id' => $driverId,
    'on' => [['id' => $keepId]],
    'dropped' => [],
]]);
assert_eq(0, (int)$untouched['removed'], 'an empty dropped list does not wipe the route');
assert_eq(
    [$keepId, $dropId],
    route_integrity_assigned_customers($db, $driverId, $surveyDate),
    'stops stay when the survey did not turn them off'
);
$turnedOff = bakery_survey_store_verify_apply_routes($db, $surveyDate, [[
    'driver_id' => $driverId,
    'on' => [['id' => $keepId]],
    'dropped' => [['id' => $dropId, 'name' => $prefix . ' Drop']],
]]);
assert_eq(1, (int)$turnedOff['removed'], 'survey removes the store that was turned off');
$surveyLog = $db->prepare(
    'SELECT reason, code_path FROM dated_route_removal_log
     WHERE delivery_date = ? AND customer_id = ? ORDER BY id DESC LIMIT 1'
);
$surveyLog->execute([$surveyDate, $dropId]);
$surveyRow = $surveyLog->fetch(PDO::FETCH_ASSOC);
assert_eq('survey_turned_off', (string)($surveyRow['reason'] ?? ''), 'survey removal records why');
assert_eq('survey_store_verify_unassign', (string)($surveyRow['code_path'] ?? ''), 'survey removal records the code path');
$tombStmt->execute([$surveyDate, $dropId]);
assert_eq(1, (int)$tombStmt->fetchColumn(), 'survey off writes a refill tombstone');

echo "\n=== Standing order with no standing driver is its own Act now item ===\n";
standing_save($db, $fridayCustomerId, $productId, $weekday, 3);
$center = bakery_dashboard_command_center($db, $fridayDate);
$named = null;
$generic = null;
foreach ($center['exceptions'] as $exception) {
    if (($exception['type'] ?? '') === 'standing_order_no_driver') {
        $named = $exception;
    }
    if (($exception['type'] ?? '') === 'delivery_unassigned') {
        $generic = $exception;
    }
}
assert_true(is_array($named), 'Act now includes standing orders with no standing driver');
assert_true(
    strpos((string)($named['detail'] ?? ''), $prefix . ' Friday') !== false,
    'the standing-driver item names the store'
);
assert_true(
    strpos((string)($named['href'] ?? ''), 'standing_routes.php') !== false,
    'the standing-driver item opens standing routes'
);
assert_true(empty($named['inline_action']), 'the standing-driver item does not build routes from standing');
if (is_array($generic)) {
    assert_true(
        ($generic['type'] ?? '') !== ($named['type'] ?? ''),
        'the standing-driver item is separate from stops that need a driver'
    );
}

echo "\n=== Empty and $0.00 dated orders are empty_daily ===\n";
assert_eq('matches', bakery_demand_review_classify_customer([
    'has_daily' => true,
    'has_standing' => false,
    'on_standing_route' => true,
    'line_map' => [],
    'standing_fill_blocked' => 0,
]), 'a standing-route visit with nothing to bake stays off the empty list');
assert_eq('empty_daily', bakery_demand_review_classify_customer([
    'has_daily' => true,
    'has_standing' => false,
    'on_standing_route' => false,
    'line_map' => [],
    'standing_fill_blocked' => 0,
]), 'a dated shell that is not a standing-route visit is empty');
assert_eq('empty_daily', bakery_demand_review_classify_customer([
    'has_daily' => true,
    'has_standing' => true,
    'line_map' => [
        1 => [
            'standing_qty' => 2,
            'daily_qty' => 2,
            'unit_price' => 0,
            'line_total' => 0,
        ],
    ],
    'standing_fill_blocked' => 0,
]), 'a positive quantity priced at $0.00 is empty');
assert_eq('changed', bakery_demand_review_classify_customer([
    'has_daily' => true,
    'has_standing' => true,
    'line_map' => [
        1 => [
            'standing_qty' => 4,
            'daily_qty' => 0,
            'unit_price' => 0,
            'line_total' => 0,
        ],
    ],
    'standing_fill_blocked' => 1,
]), 'an intentional emptied order stays changed');
assert_eq('matches', bakery_demand_review_classify_customer([
    'has_daily' => true,
    'has_standing' => true,
    'line_map' => [
        1 => [
            'standing_qty' => 2,
            'daily_qty' => 2,
            'unit_price' => 4,
            'line_total' => 8,
        ],
    ],
    'standing_fill_blocked' => 0,
]), 'a priced match stays matches');

$zeroDate = $friday->modify('+21 days')->format('Y-m-d');
$zeroOrder = route_integrity_order($db, $zeroId, $zeroDate, 0);
$db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
     VALUES (?, ?, 2, 0, 0)'
)->execute([$zeroOrder, $productId]);
standing_save($db, $zeroId, $productId, bakery_standing_day_from_date($zeroDate), 2);
$db->prepare(
    'INSERT INTO standing_routes (day_of_week, driver_id, customer_id, route_order) VALUES (?, ?, ?, 1)'
)->execute([bakery_standing_day_from_date($zeroDate), $driverId, $zeroId]);
$shellOrder = route_integrity_order($db, $shellId, $zeroDate, 0);
$db->prepare(
    'INSERT INTO standing_routes (day_of_week, driver_id, customer_id, route_order) VALUES (?, ?, ?, 2)'
)->execute([bakery_standing_day_from_date($zeroDate), $driverId, $shellId]);
$review = bakery_demand_review_build($db, $zeroDate, []);
$states = [];
foreach ($review['customers'] as $customer) {
    $states[(int)$customer['customer_id']] = (string)$customer['state'];
}
assert_eq('empty_daily', $states[$zeroId] ?? '', 'demand review counts a $0.00 order as empty');
assert_eq('matches', $states[$shellId] ?? '', 'a standing-route visit with no lines is not an empty order');

echo "\n" . $GLOBALS['TEST_PASS'] . " passed, " . $GLOBALS['TEST_FAIL'] . " failed\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
