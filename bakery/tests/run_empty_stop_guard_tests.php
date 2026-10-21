<?php
/**
 * Empty routed stops must not disappear.
 *
 * A dated order zeroed as "removed for this day only" stays classified as
 * changed until a route path touches it. Landing it on a route restores
 * that customer's standing lines. A plain empty order still asks first.
 * A driver cannot close an empty stop as Delivered $0, and the morning
 * board names routed zeros. Deliberate no-charge lines still confirm at $0.
 *
 * CLI / bakerysf_test only.
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

/** @var PDO $db */
$db = require __DIR__ . '/harness.php';
bakery_assert_local_test_target($db);

require_once $root . '/complete_delivery.php';
require_once $root . '/includes/driver_assignment_actions.php';
require_once $root . '/includes/daily_run.php';
require_once $root . '/includes/daily_brief.php';

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
$assert_eq = static function ($expected, $actual, string $msg) use ($assert): void {
    $ok = $expected === $actual;
    if (!$ok) {
        $msg .= ' (expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true) . ')';
    }
    $assert($ok, $msg);
};

$date = '2099-08-17';
$weekday = bakery_standing_day_from_date($date);
echo "Test date: $date weekday $weekday\n";

$_SESSION['user_id'] = 1;
$_SESSION['user_role_slug'] = 'administrator';
$_SESSION['user_email'] = 'empty-guard@example.test';
$_SESSION['user_display_name'] = 'Empty Guard';
$_SESSION['locale'] = 'en';
if (function_exists('bakery_reset_locale_cache')) {
    bakery_reset_locale_cache();
}

$driverId = (int)$db->query('SELECT id FROM drivers WHERE archived = 0 ORDER BY id LIMIT 1')->fetchColumn();
$product = $db->query(
    'SELECT p.id, p.price
     FROM products p
     JOIN dough_types dt ON dt.id = p.dough_type_id
     JOIN product_lines pl ON pl.id = dt.product_line_id
     WHERE p.price > 0
     ORDER BY p.id
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if ($driverId <= 0 || !$product) {
    throw new RuntimeException('bakerysf_test lacks a driver or priced product');
}
$productId = (int)$product['id'];
$catalogPrice = round((float)$product['price'], 2);
$standingQty = 6;

$customerIds = [];
$insertCustomer = static function (PDO $db, string $name) use (&$customerIds): int {
    $db->prepare(
        'INSERT INTO customers (name, zone, email, address, phone, payment_collection, is_active)
         VALUES (?, ?, ?, ?, ?, ?, 1)'
    )->execute([
        $name,
        'North Zone',
        strtolower(str_replace(' ', '-', $name)) . '@example.test',
        '1 Empty Guard Street',
        '555-0100',
        'signature',
    ]);
    $id = (int)$db->lastInsertId();
    $customerIds[] = $id;
    return $id;
};

$cleanup = static function () use ($db, &$customerIds, $date): void {
    if ($customerIds === []) {
        return;
    }
    $in = implode(',', array_map('intval', $customerIds));
    $orderIds = $db->query("SELECT id FROM daily_orders WHERE customer_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
    if ($orderIds) {
        $orderIn = implode(',', array_map('intval', $orderIds));
        $db->exec("DELETE FROM daily_order_items WHERE daily_order_id IN ($orderIn)");
        $db->exec("DELETE FROM daily_order_assignments WHERE daily_order_id IN ($orderIn)");
        if (function_exists('table_exists') && table_exists($db, 'operational_events')) {
            $db->exec("DELETE FROM operational_events WHERE daily_order_id IN ($orderIn)");
        }
        $db->exec("DELETE FROM daily_orders WHERE id IN ($orderIn)");
    }
    $db->exec("DELETE FROM standing_orders WHERE customer_id IN ($in)");
    $db->exec("DELETE FROM standing_routes WHERE customer_id IN ($in)");
    if (function_exists('table_exists') && table_exists($db, 'special_orders')) {
        $specialIds = $db->query("SELECT id FROM special_orders WHERE customer_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        if ($specialIds) {
            $specialIn = implode(',', array_map('intval', $specialIds));
            $db->exec("DELETE FROM special_order_lines WHERE special_order_id IN ($specialIn)");
            $db->exec("DELETE FROM special_orders WHERE id IN ($specialIn)");
        }
    }
    if (function_exists('table_exists') && table_exists($db, 'operational_events')) {
        $db->exec("DELETE FROM operational_events WHERE customer_id IN ($in) AND operational_date = " . $db->quote($date));
    }
    $db->exec("DELETE FROM customers WHERE id IN ($in)");
};
register_shutdown_function($cleanup);

$insertOrder = static function (PDO $db, int $customerId, string $date, int $qty, float $price, bool $blockFill, bool $assign, int $driverId, bool $noCharge = false) use ($productId): int {
    $total = round($qty * $price, 2);
    $columns = 'customer_id, order_date, status, total_amount';
    $values = '?, ?, \'pending\', ?';
    $params = [$customerId, $date, $total];
    if (function_exists('column_exists') && column_exists($db, 'daily_orders', 'standing_fill_blocked')) {
        $columns .= ', standing_fill_blocked';
        $values .= ', ?';
        $params[] = $blockFill ? 1 : 0;
    }
    $db->prepare("INSERT INTO daily_orders ($columns) VALUES ($values)")->execute($params);
    $orderId = (int)$db->lastInsertId();
    $itemColumns = 'daily_order_id, product_id, quantity, unit_price, line_total';
    $itemValues = '?, ?, ?, ?, ?';
    $itemParams = [$orderId, $productId, $qty, $price, $total];
    if ($noCharge && function_exists('column_exists') && column_exists($db, 'daily_order_items', 'is_no_charge')) {
        $itemColumns .= ', is_no_charge';
        $itemValues .= ', 1';
    }
    $db->prepare("INSERT INTO daily_order_items ($itemColumns) VALUES ($itemValues)")->execute($itemParams);
    if ($assign) {
        $maxRoute = $db->prepare(
            'SELECT COALESCE(MAX(route_order), 0) FROM daily_order_assignments WHERE driver_id = ? AND delivery_date = ?'
        );
        $maxRoute->execute([$driverId, $date]);
        $nextRoute = (int)$maxRoute->fetchColumn() + 1;
        $db->prepare(
            'INSERT INTO daily_order_assignments (daily_order_id, driver_id, delivery_date, route_order, delivery_status)
             VALUES (?, ?, ?, ?, \'pending\')'
        )->execute([$orderId, $driverId, $date, $nextRoute]);
    }
    return $orderId;
};

$itemQty = static function (PDO $db, int $orderId): int {
    $stmt = $db->prepare('SELECT COALESCE(SUM(quantity), 0) FROM daily_order_items WHERE daily_order_id = ?');
    $stmt->execute([$orderId]);
    return (int)$stmt->fetchColumn();
};
$assignmentCount = static function (PDO $db, int $orderId): int {
    $stmt = $db->prepare('SELECT COUNT(*) FROM daily_order_assignments WHERE daily_order_id = ?');
    $stmt->execute([$orderId]);
    return (int)$stmt->fetchColumn();
};

$luckyId = $insertCustomer($db, 'EmptyGuard Lucky');
$neighborId = $insertCustomer($db, 'EmptyGuard Neighbor');
$shellId = $insertCustomer($db, 'EmptyGuard Shell');
$plainId = $insertCustomer($db, 'EmptyGuard Plain');
$deliverId = $insertCustomer($db, 'EmptyGuard Deliver');
$noChargeId = $insertCustomer($db, 'EmptyGuard NoCharge');
$openId = $insertCustomer($db, 'EmptyGuard Open');

foreach ([$luckyId, $neighborId] as $cid) {
    $db->prepare(
        'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, ?)'
    )->execute([$cid, $productId, $weekday, $standingQty]);
}
$db->prepare(
    'INSERT INTO standing_routes (driver_id, customer_id, day_of_week) VALUES (?, ?, ?)'
)->execute([$driverId, $shellId, $weekday]);

$luckyOrder = $insertOrder($db, $luckyId, $date, 0, 0.0, true, true, $driverId);
$neighborOrder = $insertOrder($db, $neighborId, $date, 0, 0.0, true, false, $driverId);
$shellOrder = $insertOrder($db, $shellId, $date, 0, 0.0, false, true, $driverId);
$db->prepare('DELETE FROM daily_order_items WHERE daily_order_id = ?')->execute([$shellOrder]);
$plainOrder = $insertOrder($db, $plainId, $date, 0, 0.0, false, true, $driverId);
$deliverOrder = $insertOrder($db, $deliverId, $date, 0, 0.0, true, true, $driverId);
$noChargeOrder = $insertOrder($db, $noChargeId, $date, 3, 0.0, false, true, $driverId, true);
$openOrder = $insertOrder($db, $openId, $date, 4, $catalogPrice, false, false, $driverId);

echo "\n=== Morning board flags routed zeros that are classified as changed ===\n";
$review = bakery_demand_review_build($db, $date, []);
$byName = [];
foreach ($review['customers'] as $customer) {
    $byName[(string)$customer['customer_name']] = $customer;
}
$assert(isset($byName['EmptyGuard Lucky']), 'demand review includes the routed zero order');
$assert_eq('changed', $byName['EmptyGuard Lucky']['state'] ?? '', 'removed-for-this-day stays classified as changed');
$assert(bakery_demand_review_is_routed_zero_order($byName['EmptyGuard Lucky']), 'routed zero order is the morning flag');
$assert_eq('matches', $byName['EmptyGuard Shell']['state'] ?? '', 'standing-route shell with nothing to bake stays matches');
$assert(!bakery_demand_review_is_routed_zero_order($byName['EmptyGuard Shell']), 'standing-route shell is not an empty routed stop');
$assert_eq('empty_daily', $byName['EmptyGuard Plain']['state'] ?? '', 'a true empty dated order is still empty_daily');

$run = bakery_daily_run_build($db, $date);
$blockerText = json_encode($run['blockers'] ?? []);
$assert(is_string($blockerText) && strpos($blockerText, 'EmptyGuard Lucky') !== false, 'daily run names the routed zero order');
$assert(is_string($blockerText) && strpos($blockerText, 'EmptyGuard Plain') !== false, 'daily run still names a true empty dated order');
$assert(is_string($blockerText) && strpos($blockerText, 'EmptyGuard Shell') === false, 'daily run does not flag the standing-route shell');
$routedZeroNames = $run['routed_zero']['names'] ?? [];
$assert(in_array('EmptyGuard Lucky', $routedZeroNames, true), 'morning board names the routed zero order in the red count');
$assert(!in_array('EmptyGuard Shell', $routedZeroNames, true), 'the red count skips the standing-route shell');
$assert(!in_array('EmptyGuard Neighbor', $routedZeroNames, true), 'an unassigned zero is not in the red count');
$assert((int)($run['routed_zero']['count'] ?? 0) === count($routedZeroNames), 'routed zero count matches the named stops');

$dailyRunPage = (string)file_get_contents($root . '/daily_run.php');
$assignmentPage = (string)file_get_contents($root . '/driver_assignment.php');
$ordersPage = (string)file_get_contents($root . '/daily_orders.php');
$assert(strpos($dailyRunPage, 'daily_run.routed_zero_count') !== false, 'morning board shows the routed zero count');
$assert(strpos($dailyRunPage, 'review=routed_zero') !== false || strpos($dailyRunPage, "'routed_zero'") !== false, 'morning board links to the routed zero list');
$assert(strpos($assignmentPage, 'driver_assignment.routed_zero_count') !== false, 'driver assignment shows the routed zero count');
$assert(strpos($ordersPage, 'routed_zero') !== false, 'daily orders can filter routed zeros');

$changes = bakery_daily_brief_important_changes($db, $date, $review);
$titles = array_column($changes, 'title');
$luckyTitles = array_values(array_filter($titles, static function ($title) {
    return strpos((string)$title, 'EmptyGuard Lucky') === 0;
}));
$shellTitles = array_values(array_filter($titles, static function ($title) {
    return strpos((string)$title, 'EmptyGuard Shell') === 0 && strpos((string)$title, 'Empty dated order') !== false;
}));
$assert($luckyTitles !== [] && strpos((string)$luckyTitles[0], 'Empty dated order') !== false, 'morning brief flags the routed zero order as empty');
$assert($shellTitles === [], 'morning brief does not call the standing-route shell empty');

echo "\n=== Removed-day skips restore when they land on a route ===\n";
$restored = bakery_driver_assignment_action_add_customer_to_route($db, [
    'customer_id' => $luckyId,
    'driver_id' => $driverId,
    'delivery_date' => $date,
], null);
$assert_eq(true, $restored['success'] ?? null, 'a removed-day skip is added without a prompt');
$assert(empty($restored['needs_restore']), 'a removed-day skip does not ask to restore');
$assert_eq($standingQty, $itemQty($db, $luckyOrder), 'adding the stop copies standing quantity onto that date');
$assert_eq(1, $assignmentCount($db, $luckyOrder), 'the existing stop stays assigned');
$eventStmt = $db->prepare(
    'SELECT COUNT(*) FROM operational_events
     WHERE event_type = ? AND customer_id = ? AND operational_date = ?'
);
$eventStmt->execute(['standing_order_restored', $luckyId, $date]);
$assert((int)$eventStmt->fetchColumn() >= 1, 'route restore is logged');

$neighborAdd = bakery_driver_assignment_action_add_customer_to_route($db, [
    'customer_id' => $neighborId,
    'driver_id' => $driverId,
    'delivery_date' => $date,
    'keep_empty' => '1',
], null);
$assert_eq(true, $neighborAdd['success'] ?? null, 'keep-empty still adds a removed-day skip');
$assert_eq($standingQty, $itemQty($db, $neighborOrder), 'keep-empty does not leave a removed-day skip at zero');
$assert_eq(1, $assignmentCount($db, $neighborOrder), 'keep-empty assigns the restored stop');

$plainAdd = bakery_driver_assignment_action_add_customer_to_route($db, [
    'customer_id' => $plainId,
    'driver_id' => $driverId,
    'delivery_date' => $date,
], null);
$assert(!empty($plainAdd['needs_restore']), 'a plain empty order still asks before it is added again');
$assert_eq(0, $itemQty($db, $plainOrder), 'the prompt leaves the plain empty lines at zero');

$openAdd = bakery_driver_assignment_action_add_customer_to_route($db, [
    'customer_id' => $openId,
    'driver_id' => $driverId,
    'delivery_date' => $date,
], null);
$assert_eq(true, $openAdd['success'] ?? null, 'a dated order with pieces is added without a restore prompt');
$assert(empty($openAdd['needs_restore']), 'positive dated order does not ask for restore');

$assignCustomer = $insertCustomer($db, 'EmptyGuard Assign');
$db->prepare(
    'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, ?)'
)->execute([$assignCustomer, $productId, $weekday, $standingQty]);
$assignOrder = $insertOrder($db, $assignCustomer, $date, 0, 0.0, true, false, $driverId);
$assigned = bakery_driver_assign_orders($db, $driverId, $date, [
    ['daily_order_id' => $assignOrder],
], 'append');
$assert((int)($assigned['stop_count'] ?? 0) >= 1, 'assign selected accepts the removed-day order');
$assert_eq($standingQty, $itemQty($db, $assignOrder), 'assign selected restores standing quantity');
$assert_eq(1, $assignmentCount($db, $assignOrder), 'assign selected puts the stop on the route');

$planCustomer = $insertCustomer($db, 'EmptyGuard Plan');
$db->prepare(
    'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, ?)'
)->execute([$planCustomer, $productId, $weekday, $standingQty]);
$planOrder = $insertOrder($db, $planCustomer, $date, 0, 0.0, true, false, $driverId);
bakery_driver_plan_insert_assignment($db, $driverId, $date, $planOrder, null);
$assert_eq($standingQty, $itemQty($db, $planOrder), 'prep add and survey move restore standing quantity');
$assert_eq(1, $assignmentCount($db, $planOrder), 'prep add assigns the restored stop');

$otherDriverId = (int)$db->query(
    'SELECT id FROM drivers WHERE archived = 0 AND id <> ' . (int)$driverId . ' ORDER BY id LIMIT 1'
)->fetchColumn();
if ($otherDriverId > 0) {
    $moveCustomer = $insertCustomer($db, 'EmptyGuard Move');
    $db->prepare(
        'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, ?)'
    )->execute([$moveCustomer, $productId, $weekday, $standingQty]);
    $moveOrder = $insertOrder($db, $moveCustomer, $date, 0, 0.0, true, true, $driverId);
    $moved = bakery_driver_transfer_assignments($db, [$moveOrder], $otherDriverId, $date, $driverId);
    $assert_eq(1, (int)($moved['transferred_count'] ?? 0), 'move to transfers the removed-day stop');
    $assert_eq($standingQty, $itemQty($db, $moveOrder), 'move to restores standing quantity');
} else {
    $assert(false, 'bakerysf_test has a second driver for the move-to restore');
}

require_once $root . '/includes/special_orders.php';
if (bakery_special_orders_ready($db)) {
    $specialCustomer = $insertCustomer($db, 'EmptyGuard Special');
    $db->prepare(
        'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, ?)'
    )->execute([$specialCustomer, $productId, $weekday, $standingQty]);
    $db->prepare(
        'INSERT INTO standing_routes (driver_id, customer_id, day_of_week) VALUES (?, ?, ?)'
    )->execute([$driverId, $specialCustomer, $weekday]);
    $specialOrder = $insertOrder($db, $specialCustomer, $date, 0, 0.0, true, false, $driverId);
    $savedSpecial = bakery_special_order_save($db, [
        'customer_id' => $specialCustomer,
        'delivery_date' => $date,
        'lines' => [[
            'line_kind' => 'text',
            'description' => 'Sample tray',
            'quantity' => 2,
            'unit_price' => 4.00,
        ]],
    ]);
    $assert(!empty($savedSpecial['ok']), 'a special order can attach to the removed-day stop');
    $assert_eq(0, $itemQty($db, $specialOrder), 'special-order attach does not copy pan standing');
    bakery_driver_assign_orders($db, $driverId, $date, [
        ['daily_order_id' => $specialOrder],
    ], 'append');
    $assert_eq(0, $itemQty($db, $specialOrder), 'a special line above zero blocks the standing restore');
    $specialIds = $db->query(
        'SELECT id FROM special_orders WHERE customer_id = ' . (int)$specialCustomer
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($specialIds) {
        $specialIn = implode(',', array_map('intval', $specialIds));
        $db->exec('DELETE FROM special_order_lines WHERE special_order_id IN (' . $specialIn . ')');
        $db->exec('DELETE FROM special_orders WHERE id IN (' . $specialIn . ')');
    }
}

$generation = (string)file_get_contents($root . '/includes/daily_order_generation.php');
$surveyOrder = (string)file_get_contents($root . '/includes/survey_route_order.php');
$reorder = (string)file_get_contents($root . '/includes/driver_assignments.php');
$specialSource = (string)file_get_contents($root . '/includes/special_orders.php');
$assert(substr_count($generation, 'bakery_route_stop_restore_removed_day') === 4, 'route generation restores before both assignment inserts');
$assert(strpos($surveyOrder, 'bakery_route_stop_restore_removed_day') !== false, 'survey route order restores a removed-day skip');
$assert(substr_count($reorder, 'bakery_route_stop_restore_removed_day') >= 6, 'assignment, transfer, add, prep, and reorder call the shared helper');
$assert(strpos($specialSource, 'bakery_route_stop_restore_removed_day') === false, 'special-order attach does not call the restore helper');

echo "\n=== Driver cannot close an empty stop as Delivered $0 ===\n";
$officeMessage = bakery_t('driver.empty_order_call_office');
$refused = false;
$refusedMessage = '';
try {
    bakery_confirm_delivery($db, $deliverOrder, 0, 0);
} catch (Throwable $e) {
    $refused = true;
    $refusedMessage = $e->getMessage();
}
$assert($refused, 'zero pieces on an empty order is refused');
$assert_eq($officeMessage, $refusedMessage, 'the office message is the refusal');
$statusStmt = $db->prepare(
    'SELECT do.status, doa.delivery_status
     FROM daily_orders do
     JOIN daily_order_assignments doa ON doa.daily_order_id = do.id
     WHERE do.id = ?'
);
$statusStmt->execute([$deliverOrder]);
$refusedStatus = $statusStmt->fetch(PDO::FETCH_ASSOC);
$assert_eq('pending', (string)($refusedStatus['status'] ?? ''), 'refused close leaves the order pending');
$assert_eq('pending', (string)($refusedStatus['delivery_status'] ?? ''), 'refused close leaves the stop pending');

$lineStmt = $db->prepare('SELECT id FROM daily_order_items WHERE daily_order_id = ? LIMIT 1');
$lineStmt->execute([$deliverOrder]);
$deliverLineId = (int)$lineStmt->fetchColumn();
$confirmed = bakery_confirm_delivery($db, $deliverOrder, 4, 0, [
    'line_quantities' => [$deliverLineId => 4],
    'amount_collected' => 0,
]);
$assert(!empty($confirmed['success']), 'delivered quantities on an empty order can be confirmed');
$assert((float)$confirmed['total'] > 0, 'the confirmed total uses a customer price, not $0');
$pricedStmt = $db->prepare('SELECT quantity, unit_price, line_total FROM daily_order_items WHERE id = ?');
$pricedStmt->execute([$deliverLineId]);
$pricedLine = $pricedStmt->fetch(PDO::FETCH_ASSOC);
$assert_eq(4, (int)($pricedLine['quantity'] ?? 0), 'the dated line quantity becomes what was delivered');
$assert((float)($pricedLine['unit_price'] ?? 0) > 0, 'the line is priced from the customer catalog');
$assert(abs((float)$confirmed['total'] - round(4 * (float)$pricedLine['unit_price'], 2)) < 0.02, 'header total matches the priced line');
$statusStmt->execute([$deliverOrder]);
$deliveredStatus = $statusStmt->fetch(PDO::FETCH_ASSOC);
$assert_eq('delivered', (string)($deliveredStatus['delivery_status'] ?? ''), 'a priced entry closes as delivered');

$noChargeConfirmed = bakery_confirm_delivery($db, $noChargeOrder, 3, 0, [
    'amount_collected' => 0,
]);
$assert(!empty($noChargeConfirmed['success']), 'a deliberate no-charge line still confirms');
$assert(abs((float)$noChargeConfirmed['total']) < 0.005, 'a deliberate no-charge line stays $0');
$noChargePrice = $db->prepare('SELECT unit_price, is_no_charge FROM daily_order_items WHERE daily_order_id = ?');
$noChargePrice->execute([$noChargeOrder]);
$noChargeRow = $noChargePrice->fetch(PDO::FETCH_ASSOC);
$assert_eq(1, (int)($noChargeRow['is_no_charge'] ?? 0), 'no-charge mark remains');
$assert(abs((float)($noChargeRow['unit_price'] ?? 1)) < 0.005, 'no-charge unit price stays 0');

$flagCustomer = $insertCustomer($db, 'EmptyGuard Flag');
$flagOrder = $insertOrder($db, $flagCustomer, $date, 0, 0.0, true, true, $driverId);
$flagged = bakery_flag_empty_delivery($db, $flagOrder);
$assert(!empty($flagged['flagged']), 'office flag is recorded');
$assert(empty($flagged['delivered']), 'office flag is not a delivery');
$statusStmt->execute([$flagOrder]);
$flagStatus = $statusStmt->fetch(PDO::FETCH_ASSOC);
$assert_eq('pending', (string)($flagStatus['status'] ?? ''), 'office flag does not mark the order delivered');
$assert_eq('pending', (string)($flagStatus['delivery_status'] ?? ''), 'office flag does not mark the stop delivered');
$flagIds = bakery_delivery_empty_flagged_order_ids($db, $date);
$assert(isset($flagIds[$flagOrder]), 'the route can see the office flag');
$flagEvent = $db->prepare(
    'SELECT COUNT(*) FROM operational_events WHERE event_type = ? AND daily_order_id = ?'
);
$flagEvent->execute(['delivery_empty_flagged', $flagOrder]);
$assert((int)$flagEvent->fetchColumn() >= 1, 'office flag is logged');

$driverPage = (string)file_get_contents($root . '/driver.php');
$driverJs = (string)file_get_contents($root . '/includes/driver_delivery.js');
$assert(strpos($driverPage, "status !== 'office_flag'") !== false, 'driver route skips an office-flagged stop');
$assert(strpos($driverJs, 'state.orderEmpty') !== false, 'empty orders do not use the over-delivery count gate');
$assert(strpos($driverJs, 'askForCounts = !orderEmpty') !== false, 'the freeze gate is skipped for an empty order');
$assert(strpos($driverJs, 'flag_empty_order') !== false, 'the driver can flag an empty stop for the office');

$es = include $root . '/lang/es.php';
$en = include $root . '/lang/en.php';
$assert_eq('Este pedido está vacío, llama a la oficina', $es['driver.empty_order_call_office'] ?? '', 'Spanish office message is exact');
$assert(($en['driver.empty_order_call_office'] ?? '') !== '', 'English office message exists');
foreach ([
    'driver.empty_order_call_office',
    'driver_assignment.empty_order_warning',
    'driver.empty_order_flagged',
    'daily_brief.routed_empty_detail',
] as $dashKey) {
    $assert(
        strpos((string)($en[$dashKey] ?? ''), "\u{2014}") === false
            && strpos((string)($en[$dashKey] ?? ''), "\u{2013}") === false
            && strpos((string)($es[$dashKey] ?? ''), "\u{2014}") === false
            && strpos((string)($es[$dashKey] ?? ''), "\u{2013}") === false,
        $dashKey . ' has no em dash or en dash'
    );
}

echo "\n=== Managers can read client errors and nothing else ===\n";
require_once $root . '/includes/login_history_insights.php';
require_once $root . '/includes/navigation_catalog.php';
$adminAccess = bakery_login_history_access(['id' => 1, 'role_slug' => 'administrator'], 'overview', 'GET', '');
$assert(!empty($adminAccess['allowed']) && empty($adminAccess['read_only']), 'administrator keeps full login history');
$managerRead = bakery_login_history_access(['id' => 2, 'role_slug' => 'manager'], 'client_errors', 'GET', '');
$assert(!empty($managerRead['allowed']) && !empty($managerRead['read_only']), 'manager can read client errors');
$managerOverview = bakery_login_history_access(['id' => 2, 'role_slug' => 'manager'], 'overview', 'GET', '');
$assert(empty($managerOverview['allowed']), 'manager cannot open the rest of login history');
$managerRecords = bakery_login_history_access(['id' => 2, 'role_slug' => 'manager'], 'records', 'GET', '');
$assert(empty($managerRecords['allowed']), 'manager cannot open login records');
$managerExport = bakery_login_history_access(['id' => 2, 'role_slug' => 'manager'], 'client_errors', 'GET', 'csv');
$assert(empty($managerExport['allowed']), 'manager cannot export login history');
$managerPost = bakery_login_history_access(['id' => 2, 'role_slug' => 'manager'], 'client_errors', 'POST', '');
$assert(empty($managerPost['allowed']), 'manager cannot change login history');
$driverRead = bakery_login_history_access(['id' => 3, 'role_slug' => 'driver'], 'client_errors', 'GET', '');
$assert(empty($driverRead['allowed']), 'a driver cannot open client errors');

$historyPage = (string)file_get_contents($root . '/login_history.php');
$assert(strpos($historyPage, 'bakery_login_history_access') !== false, 'the page checks login history access');
$assert(strpos($historyPage, 'empty($loginHistoryReadOnly)') !== false, 'read-only managers do not get the export link');
$assert(strpos($historyPage, 'bakery_require_role([\'administrator\'])') === false, 'managers are not rejected before the client errors check');

$hrefsFor = static function (string $role): array {
    $hrefs = [];
    foreach (bakery_navigation_groups_for_role($role) as $group) {
        foreach ($group['items'] as $item) {
            $hrefs[] = (string)($item['href'] ?? '');
        }
    }
    return $hrefs;
};
$managerHrefs = $hrefsFor('manager');
$adminHrefs = $hrefsFor('administrator');
$assert(in_array('login_history.php?view=client_errors', $managerHrefs, true), 'managers get a client errors menu item');
$assert(!in_array('login_history.php', $managerHrefs, true), 'managers do not get the full login history menu');
$assert(in_array('login_history.php', $adminHrefs, true), 'administrators still get Login History');
$historyRoles = bakery_navigation_roles_for_script('login_history.php');
$assert(in_array('administrator', $historyRoles, true) && in_array('manager', $historyRoles, true), 'the script gate allows administrators and managers');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
