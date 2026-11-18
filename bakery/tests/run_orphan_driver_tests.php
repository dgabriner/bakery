<?php
/**
 * Orphan dated stops get their usual driver. bakerysf_test only.
 * Usage: php tests/run_orphan_driver_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
define('ACCESS_ALLOWED', true);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/daily_order_generation.php';
require_once $root . '/includes/daily_status.php';
require_once $root . '/includes/today_list.php';
require_once $root . '/includes/driver_assignments.php';
require_once $root . '/includes/standing_orders_manager_actions.php';
require_once $root . '/includes/demand_confirmation.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: tests must run with APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();
bakery_assert_local_test_target($db);
if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== 'bakerysf_test') {
    fwrite(STDERR, "Refusing: database is not bakerysf_test\n");
    exit(1);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $msg) use (&$pass, &$fail): void {
    if ($ok) {
        echo "PASS  $msg\n";
        $pass++;
        return;
    }
    echo "FAIL  $msg\n";
    $fail++;
};

$date = '2099-11-17';
$past = '2099-11-13';
$assert((int)date('N', strtotime($date)) === 2, 'fixture date is a Tuesday');
$productId = (int)$db->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$managerId = (int)$db->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'manager' ORDER BY u.id LIMIT 1")->fetchColumn();
$originSql = (function_exists('column_exists') && column_exists($db, 'customers', 'sfb_origin')) ? ', sfb_origin' : '';
$originVal = $originSql !== '' ? ", 'human'" : '';

$insertCustomer = $db->prepare('INSERT INTO customers (name, is_active' . $originSql . ') VALUES (?, 1' . $originVal . ')');
$insertDriver = $db->prepare('INSERT INTO drivers (name) VALUES (?)');
$names = [];
$customer = static function (string $name) use ($db, $insertCustomer, &$names): int {
    $insertCustomer->execute([$name]);
    $id = (int)$db->lastInsertId();
    $names[] = $id;
    return $id;
};
$insertDriver->execute(['Orphan Laura']);
$laura = (int)$db->lastInsertId();
$insertDriver->execute(['Orphan Marcos']);
$marcos = (int)$db->lastInsertId();
$insertDriver->execute(['Orphan Sergio']);
$sergio = (int)$db->lastInsertId();

$conveniencia = $customer('Orphan Conveniencia');
$canyon = $customer('Orphan American Canyon');
$priority = $customer('Orphan Priority');
$kept = $customer('Orphan Kept');
$skipped = $customer('Orphan Skipped');
$paused = $customer('Orphan Paused');
$filler = $customer('Orphan Filler');
$standingOnly = $customer('Orphan Standing Btn');

$order = $db->prepare('INSERT INTO daily_orders (customer_id, order_date, status) VALUES (?, ?, ?)');
$assign = $db->prepare(
    'INSERT INTO daily_order_assignments (daily_order_id, driver_id, delivery_date, route_order, delivery_status, actual_delivery_time)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$deliver = static function (int $customerId, string $day, int $driverId, int $position) use ($order, $assign, $db): int {
    $order->execute([$customerId, $day, 'delivered']);
    $id = (int)$db->lastInsertId();
    $assign->execute([$id, $driverId, $day, $position, 'delivered', '16:40:00']);
    return $id;
};

$deliver($conveniencia, $past, $laura, 1);
$deliver($priority, $past, $laura, 2);
$deliver($kept, $past, $laura, 3);
$deliver($skipped, $past, $laura, 4);
$deliver($paused, $past, $laura, 5);
$db->prepare('INSERT INTO standing_routes (day_of_week, driver_id, customer_id, route_order) VALUES (1, ?, ?, 1)')
    ->execute([$marcos, $priority]);
$order->execute([$canyon, $date, 'pending']);
$canyonOrder = (int)$db->lastInsertId();
$order->execute([$conveniencia, $date, 'pending']);
$convenienciaOrder = (int)$db->lastInsertId();
$order->execute([$priority, $date, 'pending']);
$priorityOrder = (int)$db->lastInsertId();
$order->execute([$kept, $date, 'pending']);
$keptOrder = (int)$db->lastInsertId();
$assign->execute([$keptOrder, $sergio, $date, 4, 'pending', null]);
$order->execute([$skipped, $date, 'pending']);
$order->execute([$paused, $date, 'pending']);
$order->execute([$filler, $date, 'pending']);
$fillerOrder = (int)$db->lastInsertId();
$assign->execute([$fillerOrder, $laura, $date, 1, 'pending', null]);
$db->prepare('INSERT INTO customer_delivery_skips (customer_id, skip_date, note) VALUES (?, ?, ?)')
    ->execute([$skipped, $date, 'skip']);
$db->prepare('INSERT INTO customer_delivery_pauses (customer_id, pause_start, pause_end) VALUES (?, ?, ?)')
    ->execute([$paused, $date, $date]);

$driverOf = static function (int $orderId, string $day = '2099-11-17') use ($db): int {
    $stmt = $db->prepare('SELECT driver_id FROM daily_order_assignments WHERE daily_order_id = ? AND delivery_date = ?');
    $stmt->execute([$orderId, $day]);
    return (int)$stmt->fetchColumn();
};
$positionOf = static function (int $orderId) use ($db): int {
    $stmt = $db->prepare('SELECT route_order FROM daily_order_assignments WHERE daily_order_id = ? AND delivery_date = ?');
    $stmt->execute([$orderId, '2099-11-17']);
    return (int)$stmt->fetchColumn();
};
$noteOf = static function (int $orderId) use ($db): string {
    $stmt = $db->prepare('SELECT notes FROM daily_order_assignments WHERE daily_order_id = ? AND delivery_date = ?');
    $stmt->execute([$orderId, '2099-11-17']);
    return (string)$stmt->fetchColumn();
};

$read = bakery_daily_status_snapshot($db, $date);
$assert($driverOf($convenienciaOrder) === 0, 'reading status does not assign a driver');

$first = bakery_assign_orphan_dated_stops($db, $date);
$assert($driverOf($convenienciaOrder) === $laura, 'a delivered history assigns Laura');
$assert($positionOf($convenienciaOrder) === 2, 'Laura is placed at the end of her route');
$assert($noteOf($convenienciaOrder) === 'Auto: usual driver (last delivered 2099-11-13)', 'the note names the last delivery');
$assert($driverOf($priorityOrder) === $marcos, 'a standing route on another day wins over the last delivery');
$assert($driverOf($keptOrder) === $sergio, 'an existing assignment is not moved');
$assert($driverOf($canyonOrder) === 0, 'a store with no history stays unassigned');
$assert($driverOf((int)$db->query('SELECT id FROM daily_orders WHERE customer_id = ' . $skipped . ' AND order_date = ' . $db->quote($date))->fetchColumn()) === 0, 'a skipped store is not assigned');
$assert($driverOf((int)$db->query('SELECT id FROM daily_orders WHERE customer_id = ' . $paused . ' AND order_date = ' . $db->quote($date))->fetchColumn()) === 0, 'a paused store is not assigned');
$needsNames = array_map(static function (array $row): string {
    return (string)$row['customer_name'];
}, bakery_orphan_needs_driver_names($db, $date));
$assert(in_array('Orphan American Canyon', $needsNames, true), 'the store with no history is on the Needs a driver list');
$assert(!in_array('Orphan Conveniencia', $needsNames, true), 'an auto-assigned store is not on the Needs a driver list');
$second = bakery_assign_orphan_dated_stops($db, $date);
$assert($second['assigned'] === [], 'running twice changes nothing');
$assert($positionOf($convenienciaOrder) === 2, 'the second run keeps the position');

$positions = $db->prepare('SELECT driver_id, route_order, COUNT(*) c FROM daily_order_assignments WHERE delivery_date = ? GROUP BY driver_id, route_order HAVING c > 1');
$positions->execute([$date]);
$assert($positions->fetch(PDO::FETCH_ASSOC) === false, 'route positions stay unique');

$_SESSION['user_id'] = $managerId;
$_SESSION['user_role_slug'] = 'manager';
$removed = false;
try {
    bakery_driver_remove_assignment($db, $convenienciaOrder, $laura, $date);
    $removed = true;
} catch (Throwable $e) {
    $assert(false, 'unassign records the removal (' . $e->getMessage() . ')');
}
if ($removed) {
    $tomb = $db->prepare('SELECT COUNT(*) FROM dated_route_removals WHERE delivery_date = ? AND customer_id = ?');
    $tomb->execute([$date, $conveniencia]);
    $assert((int)$tomb->fetchColumn() === 1, 'unassign records dated_route_removals');
    bakery_assign_orphan_dated_stops($db, $date);
    $assert($driverOf($convenienciaOrder) === 0, 'unassign then assign does not put the stop back');
}

$closedDate = '2099-11-24';
$order->execute([$conveniencia, $closedDate, 'pending']);
$closedOrder = (int)$db->lastInsertId();
$db->prepare('INSERT INTO operating_day_closeouts (operating_date, closed_at) VALUES (?, NOW())')->execute([$closedDate]);
$closedResult = bakery_assign_orphan_dated_stops($db, $closedDate);
$assert($closedResult['assigned'] === [] && $driverOf($closedOrder, $closedDate) === 0, 'a closed day is skipped');

$snap = bakery_daily_status_snapshot($db, $date);
$auto = false;
foreach ($snap['stops'] as $stop) {
    if ((int)($stop['daily_order_id'] ?? 0) === $priorityOrder && ($stop['source'] ?? '') === 'auto') {
        $auto = true;
    }
}
$assert($auto, 'daily status lists the auto stop with source auto');
$unassignedNames = array_map(static function (array $row): string {
    return (string)($row['store_name'] ?? '');
}, $snap['unassigned']['stops'] ?? []);
$assert(in_array('Orphan American Canyon', $unassignedNames, true), 'the store with no history stays in unassigned');
$assert(!in_array('Orphan Priority', $unassignedNames, true), 'confirm-path assignment is not counted as unassigned');

$items = [];
$seen = [];
bakery_today_push_routes($db, $date, 'today', $items, $seen);
$card = false;
foreach ($items as $item) {
    if (($item['kind'] ?? '') === 'needs_driver' && strpos((string)$item['sentence'], 'Needs a driver:') === 0) {
        $card = true;
    }
}
$assert($card, 'Manager Today has one Needs a driver card');

$beforeRoutes = (int)$db->query('SELECT COUNT(*) FROM standing_routes WHERE customer_id = ' . $standingOnly)->fetchColumn();
$added = bakery_standing_orders_manager_action_add_route($db, [
    'customer_id' => $standingOnly,
    'driver_id' => $laura,
    'day_of_week' => 2,
], ['id' => $managerId, 'role_slug' => 'manager']);
$assert(!empty($added['added']) && (int)$added['day_of_week'] === 2 && (int)$added['driver_id'] === $laura, 'the button writes the standing route the user chose');
$assert((int)$db->query('SELECT COUNT(*) FROM standing_routes WHERE customer_id = ' . $standingOnly)->fetchColumn() === $beforeRoutes + 1, 'the button writes only that standing route');
$assert((int)$db->query('SELECT COUNT(*) FROM standing_orders WHERE customer_id = ' . $standingOnly)->fetchColumn() === 0, 'the button does not write a standing order');

$page = (string)file_get_contents($root . '/standing_orders_manager.php');
$assignPage = (string)file_get_contents($root . '/driver_assignment.php');
$survey = (string)file_get_contents($root . '/includes/survey_route_order_render.php');
$assert(strpos($page, 'standing_orders.no_route_day') !== false && strpos($page, 'add_standing_route') !== false, 'Standing Orders Manager shows No route this day and the button');
$assert(strpos($assignPage, 'orphan-auto-chip') !== false && strpos($survey, 'orphan-auto-chip') !== false, 'Driver Assignment and Survey Center show the Auto chip');
$en = require $root . '/lang/en.php';
$es = require $root . '/lang/es.php';
foreach (['route.auto_chip', 'today.needs_driver', 'standing_orders.no_route_day', 'standing_orders.add_to_route'] as $key) {
    $assert(isset($en[$key], $es[$key]) && strpos($en[$key], "\u{2013}") === false && strpos($es[$key], "\u{2014}") === false, "lang {$key} is in en and es without dashes");
}
$assert($en['route.auto_chip'] === 'Auto' && $es['route.auto_chip'] === 'Auto', 'the chip says Auto');
$assert(strpos($en['today.needs_driver'], 'Needs a driver:') === 0, 'the card title is Needs a driver');

try {
    bakery_demand_confirmation_confirm($db, $date, $managerId);
} catch (Throwable $e) {
    // The date may still fail the full demand review. The assign already ran.
}
$assert($driverOf($priorityOrder) === $marcos, 'confirm demand leaves the auto assignment in place');

$ids = $names;
$ph = implode(',', array_fill(0, count($ids), '?'));
$orderIds = $db->prepare("SELECT id FROM daily_orders WHERE customer_id IN ($ph)");
$orderIds->execute($ids);
$foundOrders = array_map('intval', $orderIds->fetchAll(PDO::FETCH_COLUMN));
if ($foundOrders !== []) {
    $oph = implode(',', array_fill(0, count($foundOrders), '?'));
    $db->prepare("DELETE FROM daily_order_items WHERE daily_order_id IN ($oph)")->execute($foundOrders);
    $db->prepare("DELETE FROM daily_order_assignments WHERE daily_order_id IN ($oph)")->execute($foundOrders);
    $db->prepare("DELETE FROM daily_orders WHERE id IN ($oph)")->execute($foundOrders);
}
$db->prepare("DELETE FROM standing_routes WHERE customer_id IN ($ph) OR driver_id IN (?, ?, ?)")->execute([...$ids, $laura, $marcos, $sergio]);
$db->prepare("DELETE FROM standing_orders WHERE customer_id IN ($ph)")->execute($ids);
$db->prepare("DELETE FROM customer_delivery_skips WHERE customer_id IN ($ph)")->execute($ids);
$db->prepare("DELETE FROM customer_delivery_pauses WHERE customer_id IN ($ph)")->execute($ids);
if (table_exists($db, 'dated_route_removals')) {
    $db->prepare("DELETE FROM dated_route_removals WHERE customer_id IN ($ph)")->execute($ids);
}
$db->prepare('DELETE FROM operating_day_closeouts WHERE operating_date = ?')->execute([$closedDate]);
$db->prepare("DELETE FROM customers WHERE id IN ($ph)")->execute($ids);
$db->prepare('DELETE FROM drivers WHERE id IN (?, ?, ?)')->execute([$laura, $marcos, $sergio]);

echo $fail === 0
    ? "Orphan driver tests passed ({$pass})\n"
    : "Orphan driver tests finished with {$fail} failure(s), {$pass} pass(es)\n";
exit($fail === 0 ? 0 : 1);
