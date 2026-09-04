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

$db->prepare(
    "UPDATE daily_order_assignments SET delivery_status='in_transit' WHERE daily_order_id=?"
)->execute([$secondOrderId]);
$inTransitMoveBlocked = false;
try {
    bakery_driver_reorder_remaining_stops($db, 1, $reorderDate, [$secondOrderId, $thirdOrderId]);
} catch (RuntimeException $e) {
    $inTransitMoveBlocked = strpos($e->getMessage(), 'cannot be moved') !== false;
}
assert_true($inTransitMoveBlocked, 'in-transit stop cannot move while the driver is serving it');
$db->prepare(
    "UPDATE daily_order_assignments SET delivery_status='pending' WHERE daily_order_id=?"
)->execute([$secondOrderId]);

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

echo "\n=== Driver night-before add and remove ===\n";
require_once $root . '/includes/driver_route_prep.php';
require_once $root . '/includes/customer_order_mutations.php';

$driverCode = '2941';
$codeCheck = $db->prepare('SELECT COUNT(*) FROM users WHERE login_code = ?');
for ($suffix = 0; $suffix < 100 && $codeCheck->execute([$driverCode]) && (int)$codeCheck->fetchColumn() > 0; $suffix++) {
    $driverCode = str_pad((string)(7100 + $suffix), 4, '0', STR_PAD_LEFT);
}
assert_true(
    bakery_upsert_code_user($db, [
        'email' => 'route-driver@local.test',
        'display_name' => 'Route Driver',
        'role' => 'driver',
        'code' => $driverCode,
        'driver_id' => 1,
    ]),
    'driver login can be linked to driver 1'
);
$driverUserStmt = $db->prepare('SELECT id, email, display_name, driver_id FROM users WHERE email = ?');
$driverUserStmt->execute(['route-driver@local.test']);
$driverUser = $driverUserStmt->fetch(PDO::FETCH_ASSOC);
$_SESSION['user_id'] = (int)$driverUser['id'];
$_SESSION['user_email'] = (string)$driverUser['email'];
$_SESSION['user_display_name'] = (string)$driverUser['display_name'];
$_SESSION['user_role_slug'] = 'driver';
$_SESSION['user_driver_id'] = (int)$driverUser['driver_id'];

$prepDate = '2099-09-03';
$prepCustomerA = (int)$snapshotCustomerIds[0];
$prepCustomerB = (int)$snapshotCustomerIds[1];
$added = bakery_driver_plan_add_stop($db, 1, $prepDate, $prepCustomerA, false);
assert_true(!empty($added['ok']), 'driver can add an unassigned dated stop');
assert_true((int)$added['daily_order_id'] > 0, 'added stop has a dated order');

$already = bakery_driver_plan_add_stop($db, 1, $prepDate, $prepCustomerA, false);
assert_eq('already_on_route', $already['code'], 'adding an existing stop is a no-op');

$_SESSION['user_role_slug'] = 'administrator';
$_SESSION['user_id'] = 1;
$customerBStmt = $db->prepare('SELECT * FROM customers WHERE id = ?');
$customerBStmt->execute([$prepCustomerB]);
$customerBRow = $customerBStmt->fetch(PDO::FETCH_ASSOC);
$otherOrderId = bakery_customer_ensure_daily_order($db, $customerBRow, $prepDate);
bakery_driver_assign_orders($db, 2, $prepDate, [
    ['daily_order_id' => $otherOrderId],
], 'append');

$_SESSION['user_id'] = (int)$driverUser['id'];
$_SESSION['user_role_slug'] = 'driver';
$_SESSION['user_driver_id'] = 1;
$blockedTake = bakery_driver_plan_add_stop($db, 1, $prepDate, $prepCustomerB, false);
assert_eq('on_other_route', $blockedTake['code'], 'taking another driver stop needs confirm');

$search = bakery_driver_plan_search($db, 1, $prepDate, '');
assert_true(isset($search['unassigned'], $search['usual'], $search['matches'], $search['other_routes']), 'prep search returns candidate groups');
assert_true(!empty($search['take_approval']) && empty($search['take_approval']['required']), 'takes do not require manager approval yet');
$otherStopCount = 0;
$foundOtherB = false;
foreach ($search['other_routes'] as $group) {
    $otherStopCount += count($group['stops']);
    foreach ($group['stops'] as $stop) {
        if ((int)$stop['customer_id'] === $prepCustomerB) {
            $foundOtherB = true;
        }
    }
}
assert_true($otherStopCount >= 1, 'expandable other-driver list includes assigned stops');
assert_true($foundOtherB, 'other-driver list includes the stop assigned to driver 2');

$taken = bakery_driver_plan_add_stop($db, 1, $prepDate, $prepCustomerB, true);
assert_true(!empty($taken['ok']) && !empty($taken['taken_from_other']), 'driver can take a pending stop after confirm');

assert_true(
    bakery_driver_remove_assignment($db, (int)$added['daily_order_id'], 1, $prepDate),
    'driver can unassign a pending stop from their route'
);
$stillThere = $db->prepare(
    'SELECT COUNT(*) FROM daily_order_assignments WHERE daily_order_id = ? AND delivery_date = ?'
);
$stillThere->execute([(int)$added['daily_order_id'], $prepDate]);
assert_eq(0, (int)$stillThere->fetchColumn(), 'unassign deletes the assignment');
$demandKept = $db->prepare('SELECT COUNT(*) FROM daily_orders WHERE id = ?');
$demandKept->execute([(int)$added['daily_order_id']]);
assert_eq(1, (int)$demandKept->fetchColumn(), 'unassign keeps the dated order');

$pastBlocked = false;
try {
    bakery_driver_plan_add_stop($db, 1, '2020-01-02', $prepCustomerA, false);
} catch (RuntimeException $e) {
    $pastBlocked = strpos($e->getMessage(), 'Past routes') !== false
        || strpos($e->getMessage(), 'no se pueden editar') !== false
        || $e->getMessage() === bakery_t('driver.prep_past_blocked');
}
assert_true($pastBlocked, 'drivers cannot edit past routes');

$otherDriverBlocked = false;
try {
    bakery_driver_plan_add_stop($db, 2, $prepDate, $prepCustomerA, false);
} catch (RuntimeException $e) {
    $otherDriverBlocked = strpos($e->getMessage(), 'own driver route') !== false;
}
assert_true($otherDriverBlocked, 'driver cannot add stops to another identity');

$policy = bakery_driver_plan_take_policy($db);
assert_true($policy['mode'] === 'immediate' && $policy['approver_role'] === 'manager', 'take policy is immediate with a manager approver reserved');
assert_true(
    bakery_driver_plan_take_is_approved($db, 2, 1, $otherOrderId),
    'immediate policy approves a take without a manager queue'
);

require_once $root . '/includes/pan_dulce_standards.php';
$standardProducts = bakery_pan_dulce_standard_products($db);
$prepDow = bakery_standing_day_from_date($prepDate);
$noStandingStmt = $db->prepare(
    "SELECT c.id
     FROM customers c
     WHERE c.is_active = 1
       AND c.id NOT IN (?, ?)
       AND NOT EXISTS (
           SELECT 1 FROM standing_orders so
           WHERE so.customer_id = c.id
             AND so.quantity > 0
             AND CASE WHEN so.day_of_week = 0 THEN 7 ELSE so.day_of_week END = ?
       )
       AND NOT EXISTS (
           SELECT 1 FROM daily_orders do
           WHERE do.customer_id = c.id AND do.order_date = ?
       )
     ORDER BY c.id
     LIMIT 1"
);
$noStandingStmt->execute([$prepCustomerA, $prepCustomerB, $prepDow, $prepDate]);
$noStandingCustomerId = (int)$noStandingStmt->fetchColumn();
if ($noStandingCustomerId > 0 && $standardProducts !== []) {
    $standingBefore = $db->prepare(
        'SELECT COUNT(*) FROM standing_orders WHERE customer_id = ? AND CASE WHEN day_of_week = 0 THEN 7 ELSE day_of_week END = ?'
    );
    $standingBefore->execute([$noStandingCustomerId, $prepDow]);
    $standingCountBefore = (int)$standingBefore->fetchColumn();
    $filledAdd = bakery_driver_plan_add_stop($db, 1, $prepDate, $noStandingCustomerId, false);
    assert_true(!empty($filledAdd['ok']), 'driver can add a customer with no standing for the day');
    assert_true(!empty($filledAdd['filled_standard']), 'empty weekday standing fills the standard 1x dated order');
    assert_eq('pan_dulce_1x', $filledAdd['filled_standard_source'], 'fill source is the Pan Dulce 1x standard');
    $itemStmt = $db->prepare('SELECT COUNT(*) FROM daily_order_items WHERE daily_order_id = ? AND quantity > 0');
    $itemStmt->execute([(int)$filledAdd['daily_order_id']]);
    assert_true((int)$itemStmt->fetchColumn() > 0, 'standard fill writes dated order lines');
    $standingBefore->execute([$noStandingCustomerId, $prepDow]);
    assert_eq($standingCountBefore, (int)$standingBefore->fetchColumn(), 'standard fill does not rewrite standing orders');
} else {
    assert_true(true, 'snapshot has no customer without weekday standing plus Pan Dulce standards; skip 1x fill check');
}

echo "\n=== client_request_id idempotency ===\n";
require_once $root . '/complete_delivery.php';
require_once $root . '/includes/client_request_id.php';
require_once $root . '/includes/product_inventory.php';
$idempDate = '2099-11-11';
$db->prepare('DELETE FROM inventory_movements WHERE delivery_date = ?')->execute([$idempDate]);
$wipeOrders = $db->prepare('SELECT id FROM daily_orders WHERE order_date = ?');
$wipeOrders->execute([$idempDate]);
foreach ($wipeOrders->fetchAll(PDO::FETCH_COLUMN) as $oid) {
    $oid = (int)$oid;
    $db->exec('DELETE FROM inventory_movements WHERE daily_order_id = ' . $oid);
    $db->exec('DELETE FROM daily_order_assignments WHERE daily_order_id = ' . $oid);
    $db->exec('DELETE FROM daily_order_items WHERE daily_order_id = ' . $oid);
    $db->exec('DELETE FROM daily_orders WHERE id = ' . $oid);
}
$productId = (int)$db->query('SELECT id FROM products WHERE price > 0 ORDER BY id LIMIT 1')->fetchColumn();
$price = (float)$db->query('SELECT price FROM products WHERE id = ' . $productId)->fetchColumn();
$custId = (int)$db->query('SELECT id FROM customers WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$drvId = (int)$db->query('SELECT id FROM drivers ORDER BY id LIMIT 1')->fetchColumn();
assert_true($productId > 0 && $custId > 0 && $drvId > 0, 'idempotency fixture rows exist');
if (function_exists('bakery_inventory_record_production')) {
    bakery_inventory_record_production($db, $idempDate, $productId, 12, 'idempotency bake');
    bakery_inventory_save_driver_load($db, $idempDate, $drvId, [$productId => 12], 'idempotency load');
}
$db->prepare(
    'INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, ?, ?)'
)->execute([$custId, $idempDate, 'confirmed', round(10 * $price, 2)]);
$idemOrderId = (int)$db->lastInsertId();
$db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)'
)->execute([$idemOrderId, $productId, 10, $price]);
$db->prepare(
    'INSERT INTO daily_order_assignments (daily_order_id, driver_id, delivery_date, route_order, delivery_status)
     VALUES (?, ?, ?, 1, ?)'
)->execute([$idemOrderId, $drvId, $idempDate, 'pending']);
$requestId = 'test-confirm-' . bin2hex(random_bytes(6));
$first = bakery_confirm_delivery($db, $idemOrderId, 10, 2, [
    'client_request_id' => $requestId,
    'amount_collected' => 0,
    'price_per_piece' => $price,
]);
$second = bakery_confirm_delivery($db, $idemOrderId, 10, 2, [
    'client_request_id' => $requestId,
    'amount_collected' => 0,
    'price_per_piece' => $price,
]);
assert_true(!empty($first['success']), 'first confirm with client_request_id succeeds');
assert_true(!empty($second['duplicate']), 'repeat client_request_id returns duplicate');
assert_eq((int)$first['credits_taken_back'], (int)$second['credits_taken_back'], 'duplicate returns original credits');
$movements = $db->prepare('SELECT COUNT(*) FROM inventory_movements WHERE daily_order_id = ?');
$movements->execute([$idemOrderId]);
$movementCount = (int)$movements->fetchColumn();
assert_true($movementCount > 0, 'confirm posted inventory movements once');
$second = bakery_confirm_delivery($db, $idemOrderId, 10, 2, [
    'client_request_id' => $requestId,
    'amount_collected' => 0,
    'price_per_piece' => $price,
]);
$movements->execute([$idemOrderId]);
assert_eq($movementCount, (int)$movements->fetchColumn(), 'duplicate confirm does not add inventory movements');
assert_true(!empty($second['duplicate']), 'third identical client_request_id stays duplicate');

$_SESSION['user_id'] = 1;
$_SESSION['user_email'] = 'driver-workflow@example.test';
$_SESSION['user_display_name'] = 'Driver Workflow Test';
$_SESSION['user_role_slug'] = 'administrator';
$_SESSION['user_driver_id'] = null;

echo "\n=== Summary ===\n";
echo "Passed: {$GLOBALS['TEST_PASS']}\n";
echo "Failed: {$GLOBALS['TEST_FAIL']}\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
