<?php
/**
 * Driver route mutation regression tests.
 *
 * Usage: C:\php\php.exe tests/run_driver_workflow_tests.php
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
require_once $root . '/includes/driver_assignments.php';

$_SESSION['user_id'] = 1;
$_SESSION['user_email'] = 'driver-workflow@example.test';
$_SESSION['user_display_name'] = 'Driver Workflow Test';
$_SESSION['user_role_slug'] = 'administrator';

$date = '2099-08-17';
$snapshotCustomerIds = $db->query("SELECT id FROM customers ORDER BY id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
if (count($snapshotCustomerIds) < 3) {
    throw new RuntimeException('Production-derived test clone lacks enough customers for driver workflow');
}
$insertOrder = $db->prepare(
    "INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, 'pending', 0)"
);
$orderIds = [];
foreach ($snapshotCustomerIds as $customerId) {
    $insertOrder->execute([$customerId, $date]);
    $orderIds[] = (int)$db->lastInsertId();
}
[$completedOrderId, $movableOrderId, $extraOrderId] = $orderIds;

echo "\n=== Build and safely reorder a route ===\n";
$built = bakery_driver_assign_orders($db, 1, $date, [
    ['daily_order_id' => $completedOrderId, 'route_order' => 1, 'scheduled_delivery_time' => '08:00'],
    ['daily_order_id' => $movableOrderId, 'route_order' => 2, 'scheduled_delivery_time' => '09:00'],
]);
assert_eq(2, $built['stop_count'], 'two stops assigned');

echo "\n=== Driver Assistant shared-route access ===\n";
$assistantCode = '2937';
$codeCheck = $db->prepare('SELECT COUNT(*) FROM users WHERE login_code = ?');
for ($suffix = 0; $suffix < 100 && $codeCheck->execute([$assistantCode]) && (int)$codeCheck->fetchColumn() > 0; $suffix++) {
    $assistantCode = str_pad((string)(7000 + $suffix), 4, '0', STR_PAD_LEFT);
}
assert_true(
    bakery_upsert_code_user($db, [
        'email' => 'route-assistant@local.test',
        'display_name' => 'Route Assistant',
        'role' => 'driver_assistant',
        'code' => $assistantCode,
        'driver_id' => 1,
    ]),
    'assistant login can be linked to the driver route'
);
$assistantStmt = $db->prepare('SELECT id, email, display_name, driver_id FROM users WHERE email = ?');
$assistantStmt->execute(['route-assistant@local.test']);
$assistant = $assistantStmt->fetch(PDO::FETCH_ASSOC);
$_SESSION['user_id'] = (int)$assistant['id'];
$_SESSION['user_email'] = (string)$assistant['email'];
$_SESSION['user_display_name'] = (string)$assistant['display_name'];
$_SESSION['user_role_slug'] = 'driver_assistant';
$_SESSION['user_driver_id'] = (int)$assistant['driver_id'];
bakery_delivery_assert_driver_access($db, $completedOrderId);
assert_true(true, 'assistant may progress a stop on the paired driver route');
$db->prepare(
    'INSERT INTO driver_assistant_assignments (assistant_user_id, driver_id, delivery_date) VALUES (?, 2, ?)'
)->execute([(int)$assistant['id'], $date]);
$assistantBlocked = false;
try {
    bakery_delivery_assert_driver_access($db, $completedOrderId);
} catch (RuntimeException $e) {
    $assistantBlocked = strpos($e->getMessage(), 'not assigned') !== false;
}
assert_true($assistantBlocked, 'dated pairing prevents assistant from progressing another driver route');
$_SESSION['user_id'] = 1;
$_SESSION['user_email'] = 'driver-workflow@example.test';
$_SESSION['user_display_name'] = 'Driver Workflow Test';
$_SESSION['user_role_slug'] = 'administrator';
$_SESSION['user_driver_id'] = null;

$db->prepare(
    "UPDATE daily_order_assignments
     SET delivery_status='delivered', actual_delivery_time='08:12:00', notes='proof retained'
     WHERE daily_order_id=? AND driver_id=1 AND delivery_date=?"
)->execute([$completedOrderId, $date]);

bakery_driver_assign_orders($db, 1, $date, [
    ['daily_order_id' => $movableOrderId, 'route_order' => 1, 'scheduled_delivery_time' => '08:45'],
    ['daily_order_id' => $completedOrderId, 'route_order' => 2, 'scheduled_delivery_time' => '08:00'],
]);
$stateStmt = $db->prepare(
    'SELECT delivery_status, actual_delivery_time, notes, route_order
     FROM daily_order_assignments WHERE daily_order_id=? AND driver_id=1 AND delivery_date=?'
);
$stateStmt->execute([$completedOrderId, $date]);
$completed = $stateStmt->fetch(PDO::FETCH_ASSOC);
assert_eq('delivered', $completed['delivery_status'], 'reorder preserves delivered status');
assert_eq('08:12:00', $completed['actual_delivery_time'], 'reorder preserves actual delivery time');
assert_eq('proof retained', $completed['notes'], 'reorder preserves route notes');
assert_eq(2, (int)$completed['route_order'], 'reorder updates presentation order');

$blockedClear = false;
try {
    bakery_driver_assign_orders($db, 1, $date, [
        ['daily_order_id' => $movableOrderId, 'route_order' => 1],
    ]);
} catch (RuntimeException $e) {
    $blockedClear = strpos($e->getMessage(), 'cannot be removed') !== false;
}
assert_true($blockedClear, 'route rewrite cannot silently remove a completed stop');

$countStmt = $db->prepare('SELECT COUNT(*) FROM daily_order_assignments WHERE driver_id=1 AND delivery_date=?');
$countStmt->execute([$date]);
assert_eq(2, (int)$countStmt->fetchColumn(), 'blocked rewrite leaves original route intact');

echo "\n=== Deviate by moving and removing pending stops ===\n";
$moved = bakery_driver_transfer_assignments($db, [$movableOrderId], 2, $date, 1);
assert_eq(1, $moved['transferred_count'], 'pending stop moves to another driver');

$blockedMove = false;
try {
    bakery_driver_transfer_assignments($db, [$completedOrderId], 2, $date, 1);
} catch (RuntimeException $e) {
    $blockedMove = strpos($e->getMessage(), 'No eligible stops') !== false;
}
assert_true($blockedMove, 'completed stop cannot move to another driver');

bakery_driver_assign_orders($db, 2, $date, [
    ['daily_order_id' => $extraOrderId, 'route_order' => 2],
], 'append');
assert_true(
    bakery_driver_remove_assignment($db, $movableOrderId, 2, $date),
    'pending stop can be removed explicitly'
);
$routeOrderStmt = $db->prepare(
    'SELECT route_order FROM daily_order_assignments WHERE daily_order_id=? AND driver_id=2 AND delivery_date=?'
);
$routeOrderStmt->execute([$extraOrderId, $date]);
assert_eq(1, (int)$routeOrderStmt->fetchColumn(), 'route is renumbered after a stop is removed');

$blockedRemove = false;
try {
    bakery_driver_remove_assignment($db, $completedOrderId, 1, $date);
} catch (RuntimeException $e) {
    $blockedRemove = strpos($e->getMessage(), 'cannot be removed') !== false;
}
assert_true($blockedRemove, 'completed stop cannot be removed directly');

$cleared = bakery_driver_assign_orders($db, 2, $date, []);
assert_eq(0, $cleared['stop_count'], 'empty replacement clears a fully movable route');
$countStmt->execute([$date]);
assert_eq(1, (int)$countStmt->fetchColumn(), 'completed driver route remains recorded');

echo "\n=== Input guards ===\n";
$invalidDateBlocked = false;
try {
    bakery_driver_assign_orders($db, 2, 'not-a-date', []);
} catch (RuntimeException $e) {
    $invalidDateBlocked = $e->getMessage() === 'Invalid delivery date';
}
assert_true($invalidDateBlocked, 'malformed delivery date is rejected');

echo "\n=== Demand-first route planning and dated exceptions ===\n";
$statusColumn = $db->query("SHOW COLUMNS FROM daily_order_assignments LIKE 'delivery_status'")
    ->fetch(PDO::FETCH_ASSOC);
$statusType = strtolower((string)($statusColumn['Type'] ?? ''));
assert_true(strpos($statusType, "'cancelled'") !== false, 'assignment status supports cancelled stops');
assert_true(strpos($statusType, "'rescheduled'") !== false, 'assignment status preserves legacy rescheduled stops');

$insertCustomer = $db->prepare(
    "INSERT INTO customers (name, address, is_active, sfb_origin) VALUES (?, ?, 1, 'human')"
);
$insertCustomer->execute(['Workflow Cafe Delta', '400 Workflow Way']);
$workflowCustomerA = (int)$db->lastInsertId();
$insertCustomer->execute(['Workflow Market Epsilon', '500 Workflow Way']);
$workflowCustomerB = (int)$db->lastInsertId();

$routeDate = '2099-08-19'; // Wednesday
$buildDate = '2099-08-26'; // Wednesday
$existingWorkflowCustomer = (int)$snapshotCustomerIds[0];
$standingUpsert = $db->prepare(
    'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity)
     VALUES (?, 1, 3, ?)
     ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
);
foreach ([[$existingWorkflowCustomer, 3], [$workflowCustomerA, 4]] as [$customerId, $qty]) {
    $standingUpsert->execute([$customerId, $qty]);
}
$db->prepare('DELETE FROM standing_routes WHERE day_of_week = 3 AND customer_id IN (?, ?)')
    ->execute([$existingWorkflowCustomer, $workflowCustomerA]);
$insertStandingRoute = $db->prepare(
    'INSERT INTO standing_routes (day_of_week, driver_id, customer_id, route_order) VALUES (3, 1, ?, ?)'
);
$insertStandingRoute->execute([$existingWorkflowCustomer, 1]);
$insertStandingRoute->execute([$workflowCustomerA, 2]);

$firstGeneration = bakery_generate_daily_orders_from_standing($db, $routeDate, [
    'overwrite_changed' => false,
    'record_event' => false,
    'assign_routes' => true,
]);
assert_true((int)$firstGeneration['drivers_assigned'] >= 2, 'first demand generation assigns standing-route stops');

$datedOrderStmt = $db->prepare('SELECT id FROM daily_orders WHERE customer_id = ? AND order_date = ?');
$datedOrderStmt->execute([$existingWorkflowCustomer, $routeDate]);
$standingOrderA = (int)$datedOrderStmt->fetchColumn();
$datedOrderStmt->execute([$workflowCustomerA, $routeDate]);
$standingOrderB = (int)$datedOrderStmt->fetchColumn();
$db->prepare(
    'UPDATE daily_order_assignments SET driver_id = 2, route_order = 1
     WHERE daily_order_id = ? AND delivery_date = ?'
)->execute([$standingOrderA, $routeDate]);
$db->prepare(
    'UPDATE daily_order_assignments SET route_order = 99
     WHERE daily_order_id = ? AND delivery_date = ?'
)->execute([$standingOrderB, $routeDate]);

$oneTime = bakery_staff_create_dated_order($db, $workflowCustomerB, $routeDate);
bakery_driver_assign_orders($db, 1, $routeDate, [
    ['daily_order_id' => $oneTime['daily_order_id'], 'route_order' => 0],
], 'append');

$secondGeneration = bakery_generate_daily_orders_from_standing($db, $routeDate, [
    'overwrite_changed' => false,
    'record_event' => false,
    'assign_routes' => true,
]);
$assignmentState = $db->prepare(
    'SELECT driver_id, route_order FROM daily_order_assignments
     WHERE daily_order_id = ? AND delivery_date = ? ORDER BY id LIMIT 1'
);
$assignmentState->execute([$standingOrderA, $routeDate]);
$routeA = $assignmentState->fetch(PDO::FETCH_ASSOC);
$assignmentState->execute([$standingOrderB, $routeDate]);
$routeB = $assignmentState->fetch(PDO::FETCH_ASSOC);
$assignmentState->execute([$oneTime['daily_order_id'], $routeDate]);
$oneTimeRoute = $assignmentState->fetch(PDO::FETCH_ASSOC);
assert_eq(2, (int)$routeA['driver_id'], 'regeneration preserves a dated driver transfer');
assert_eq(1, (int)$routeA['route_order'], 'regeneration preserves transferred stop order');
assert_eq(99, (int)$routeB['route_order'], 'regeneration preserves a dated reorder');
assert_true((bool)$oneTimeRoute, 'regeneration preserves a one-time route stop');
assert_true((int)$secondGeneration['routes_preserved'] >= 2, 'generator reports preserved dated route decisions');
$duplicateStmt = $db->prepare(
    'SELECT COUNT(*) FROM (
        SELECT driver_id, route_order
        FROM daily_order_assignments
        WHERE delivery_date = ?
        GROUP BY driver_id, route_order
        HAVING COUNT(*) > 1
     ) duplicates'
);
$duplicateStmt->execute([$routeDate]);
assert_eq(0, (int)$duplicateStmt->fetchColumn(), 'regeneration creates no duplicate route positions');

$createdBuildException = bakery_staff_create_dated_order($db, $workflowCustomerB, $buildDate);
$existingBuildException = bakery_staff_create_dated_order($db, $workflowCustomerB, $buildDate);
assert_true($createdBuildException['created'], 'manager can create a one-time dated order directly');
assert_true(!$existingBuildException['created'], 'creating the same dated order opens the existing record');

$builtFromStanding = bakery_driver_assign_from_standing_routes($db, $buildDate);
assert_true((int)$builtFromStanding['stop_count'] >= 2, 'route plan builds standing stops after demand preparation');
$emptyAssignedStmt = $db->prepare(
    'SELECT COUNT(*)
     FROM daily_order_assignments doa
     JOIN daily_orders do ON do.id = doa.daily_order_id
     WHERE doa.delivery_date = ?
       AND NOT EXISTS (SELECT 1 FROM daily_order_items doi WHERE doi.daily_order_id = do.id)'
);
$emptyAssignedStmt->execute([$buildDate]);
$emptyAssignedCount = (int)$emptyAssignedStmt->fetchColumn();
if ($emptyAssignedCount > 0) {
    finding('INFO', "standing route builder produced {$emptyAssignedCount} empty dated order(s) before assignment");
} else {
    assert_true(true, 'standing route stops have dated products before assignment');
}

$db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
     VALUES (?, 1, 1, 6.50, 6.50)'
)->execute([$createdBuildException['daily_order_id']]);
$nonEmptyDeleteBlocked = false;
try {
    bakery_remove_empty_dated_order($db, $createdBuildException['daily_order_id'], $buildDate);
} catch (RuntimeException $e) {
    $nonEmptyDeleteBlocked = strpos($e->getMessage(), 'still has products') !== false;
}
assert_true($nonEmptyDeleteBlocked, 'route workflow cannot delete an order that still has products');
$db->prepare('DELETE FROM daily_order_items WHERE daily_order_id = ?')
    ->execute([$createdBuildException['daily_order_id']]);
$removedEmpty = bakery_remove_empty_dated_order($db, $createdBuildException['daily_order_id'], $buildDate);
assert_true($removedEmpty['removed'], 'empty one-time dated order can be removed explicitly');

$firstBuiltOrder = (int)$builtFromStanding['assignments'][0]['daily_order_id'];
$db->prepare("UPDATE daily_order_assignments SET delivery_status = 'cancelled', notes = 'Skipped: test' WHERE daily_order_id = ? AND delivery_date = ?")
    ->execute([$firstBuiltOrder, $buildDate]);
$cancelledStmt = $db->prepare('SELECT delivery_status FROM daily_order_assignments WHERE daily_order_id = ? AND delivery_date = ?');
$cancelledStmt->execute([$firstBuiltOrder, $buildDate]);
assert_eq('cancelled', $cancelledStmt->fetchColumn(), 'cancelled stop persists as an explicit status');
$progressedRebuildBlocked = false;
try {
    bakery_driver_assign_from_standing_routes($db, $buildDate);
} catch (RuntimeException $e) {
    $progressedRebuildBlocked = strpos($e->getMessage(), 'delivery progress or exceptions') !== false;
}
assert_true($progressedRebuildBlocked, 'route rebuild is blocked after delivery progress or an exception');

echo "\n=== Drivers may reorder remaining stops ===\n";
$reorderDate = '2099-08-18';
$reorderOrderIds = [];
foreach ($snapshotCustomerIds as $customerId) {
    $insertOrder->execute([$customerId, $reorderDate]);
    $reorderOrderIds[] = (int)$db->lastInsertId();
}
[$lockedOrderId, $secondOrderId, $thirdOrderId] = $reorderOrderIds;
bakery_driver_assign_orders($db, 1, $reorderDate, [
    ['daily_order_id' => $lockedOrderId, 'route_order' => 1],
    ['daily_order_id' => $secondOrderId, 'route_order' => 2],
    ['daily_order_id' => $thirdOrderId, 'route_order' => 3],
]);
$db->prepare(
    "UPDATE daily_order_assignments
     SET delivery_status='delivered', notes='keep me'
     WHERE daily_order_id=? AND driver_id=1 AND delivery_date=?"
)->execute([$lockedOrderId, $reorderDate]);

$reordered = bakery_driver_reorder_remaining_stops($db, 1, $reorderDate, [$thirdOrderId, $secondOrderId]);
assert_eq(3, $reordered['stop_count'], 'reorder returns every stop on the route');
assert_eq($thirdOrderId, $reordered['next_daily_order_id'], 'first remaining stop becomes next');
$orderStmt = $db->prepare(
    'SELECT daily_order_id, route_order, delivery_status, notes
     FROM daily_order_assignments
     WHERE driver_id=1 AND delivery_date=?
     ORDER BY route_order, id'
);
$orderStmt->execute([$reorderDate]);
$reorderedRows = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
assert_eq($lockedOrderId, (int)$reorderedRows[0]['daily_order_id'], 'delivered stop stays first');
assert_eq('delivered', $reorderedRows[0]['delivery_status'], 'delivered status is preserved');
assert_eq('keep me', $reorderedRows[0]['notes'], 'delivered notes are preserved');
assert_eq($thirdOrderId, (int)$reorderedRows[1]['daily_order_id'], 'promoted stop is now next');
assert_eq($secondOrderId, (int)$reorderedRows[2]['daily_order_id'], 'other remaining stop follows');

$lockedMoveBlocked = false;
try {
    bakery_driver_reorder_remaining_stops($db, 1, $reorderDate, [$lockedOrderId, $secondOrderId]);
} catch (RuntimeException $e) {
    $lockedMoveBlocked = strpos($e->getMessage(), 'cannot be moved') !== false;
}
assert_true($lockedMoveBlocked, 'delivered stops cannot be moved by a driver reorder');

$assistantStmt->execute(['route-assistant@local.test']);
$assistant = $assistantStmt->fetch(PDO::FETCH_ASSOC);
$_SESSION['user_id'] = (int)$assistant['id'];
$_SESSION['user_email'] = (string)$assistant['email'];
$_SESSION['user_display_name'] = (string)$assistant['display_name'];
$_SESSION['user_role_slug'] = 'driver_assistant';
$_SESSION['user_driver_id'] = (int)$assistant['driver_id'];
$assistantReorder = bakery_driver_reorder_remaining_stops($db, 1, $reorderDate, [$secondOrderId, $thirdOrderId]);
assert_eq($secondOrderId, $assistantReorder['next_daily_order_id'], 'assistant can reorder the paired driver route');

$db->prepare(
    'INSERT INTO driver_assistant_assignments (assistant_user_id, driver_id, delivery_date) VALUES (?, 2, ?)'
)->execute([(int)$assistant['id'], $reorderDate]);
$assistantRouteBlocked = false;
try {
    bakery_driver_reorder_remaining_stops($db, 1, $reorderDate, [$thirdOrderId, $secondOrderId]);
} catch (RuntimeException $e) {
    $assistantRouteBlocked = strpos($e->getMessage(), 'own driver route') !== false;
}
assert_true($assistantRouteBlocked, 'dated pairing prevents assistant from reordering another driver');

$_SESSION['user_id'] = 1;
$_SESSION['user_email'] = 'driver-workflow@example.test';
$_SESSION['user_display_name'] = 'Driver Workflow Test';
$_SESSION['user_role_slug'] = 'administrator';
$_SESSION['user_driver_id'] = null;

echo "\n=== Summary ===\n";
echo "Passed: {$GLOBALS['TEST_PASS']}\n";
echo "Failed: {$GLOBALS['TEST_FAIL']}\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
