<?php
/**
 * Driver route control stays on the signed-in driver's route for today.
 *
 * Usage: php tests/run_driver_route_control_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

putenv('USE_PROD_DB=false');
$_ENV['USE_PROD_DB'] = 'false';
$_SERVER['USE_PROD_DB'] = 'false';
putenv('DB_NAME=bakerysf_test');
$_ENV['DB_NAME'] = 'bakerysf_test';
$_SERVER['DB_NAME'] = 'bakerysf_test';

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';

/** @var PDO $db */
$db = require __DIR__ . '/harness.php';
bakery_assert_local_test_target($db);
require_once $root . '/includes/driver_route_control.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (function_exists('bakery_set_locale')) {
    bakery_set_locale('en', false);
}

$failures = 0;

function control_assert($expected, $actual, string $label): void
{
    global $failures;
    if ($expected === $actual) {
        echo "PASS  {$label}\n";
        return;
    }
    echo "FAIL  {$label}\n";
    fwrite(STDERR, '  expected ' . var_export($expected, true) . "\n  actual   " . var_export($actual, true) . "\n");
    $failures++;
}

function control_throws(callable $fn, string $needle, string $label): void
{
    try {
        $fn();
        control_assert(true, false, $label);
    } catch (Throwable $e) {
        control_assert(true, strpos($e->getMessage(), $needle) !== false, $label . ' (' . $e->getMessage() . ')');
    }
}

function control_login(int $driverId): void
{
    $_SESSION['user_id'] = 1;
    $_SESSION['user_email'] = 'driver-control@example.test';
    $_SESSION['user_display_name'] = 'Driver Control';
    $_SESSION['user_role_slug'] = 'driver';
    $_SESSION['user_driver_id'] = $driverId;
}

$drivers = $db->query('SELECT id FROM drivers ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
$productId = (int)$db->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
if (count($drivers) < 2 || $productId <= 0) {
    fwrite(STDERR, "FAIL  need two drivers and a product\n");
    exit(1);
}
$driverA = (int)$drivers[0];
$driverB = (int)$drivers[1];
$today = date('Y-m-d');
$yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');

$stale = $db->query("SELECT id FROM customers WHERE name LIKE 'Control Customer %'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($stale as $staleId) {
    $orders = $db->prepare('SELECT id FROM daily_orders WHERE customer_id = ?');
    $orders->execute([(int)$staleId]);
    foreach ($orders->fetchAll(PDO::FETCH_COLUMN) as $staleOrder) {
        $db->prepare('DELETE FROM daily_order_assignments WHERE daily_order_id = ?')->execute([(int)$staleOrder]);
        $db->prepare('DELETE FROM daily_order_items WHERE daily_order_id = ?')->execute([(int)$staleOrder]);
        $db->prepare('DELETE FROM daily_orders WHERE id = ?')->execute([(int)$staleOrder]);
    }
    $db->prepare('DELETE FROM customers WHERE id = ?')->execute([(int)$staleId]);
}

$hasOrigin = function_exists('column_exists') && column_exists($db, 'customers', 'sfb_origin');
$insertCustomer = $hasOrigin
    ? $db->prepare('INSERT INTO customers (name, zone, is_active, sfb_origin) VALUES (?, ?, 1, ?)')
    : $db->prepare('INSERT INTO customers (name, zone, is_active) VALUES (?, ?, 1)');
$customerIds = [];
for ($i = 1; $i <= 3; $i++) {
    if ($hasOrigin) {
        $insertCustomer->execute(['Control Customer ' . $i, 'Test', 'human']);
    } else {
        $insertCustomer->execute(['Control Customer ' . $i, 'Test']);
    }
    $customerIds[] = (int)$db->lastInsertId();
}

$createdOrders = [];
$routeOrders = [];
$makeOrder = static function (PDO $db, int $customerId, string $date, int $driverId, string $status, int $productId, int $qty) use (&$createdOrders, &$routeOrders): int {
    $db->prepare(
        'INSERT INTO daily_orders (customer_id, order_date, status, total_amount, credits_taken_back)
         VALUES (?, ?, ?, 0, 0)'
    )->execute([$customerId, $date, $status === 'delivered' ? 'delivered' : 'pending']);
    $orderId = (int)$db->lastInsertId();
    $createdOrders[] = $orderId;
    $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
         VALUES (?, ?, ?, 1, ?)'
    )->execute([$orderId, $productId, $qty, $qty]);
    $routeKey = $driverId . '|' . $date;
    $routeOrders[$routeKey] = ($routeOrders[$routeKey] ?? 80) + 1;
    $db->prepare(
        'INSERT INTO daily_order_assignments (daily_order_id, driver_id, delivery_date, route_order, delivery_status)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$orderId, $driverId, $date, $routeOrders[$routeKey], $status]);
    return $orderId;
};

try {
$pendingA = $makeOrder($db, $customerIds[0], $today, $driverA, 'pending', $productId, 2);
$cancelledA = $makeOrder($db, $customerIds[1], $today, $driverA, 'cancelled', $productId, 1);
$cancelledYesterday = $makeOrder($db, $customerIds[2], $yesterday, $driverA, 'cancelled', $productId, 1);
$cancelledB = $makeOrder($db, $customerIds[0], $yesterday, $driverB, 'cancelled', $productId, 1);
$deliveredA = $makeOrder($db, $customerIds[2], $today, $driverA, 'delivered', $productId, 1);

control_login($driverA);

control_throws(
    static function () use ($db, $pendingA): void {
        bakery_driver_route_control_unskip($db, $pendingA);
    },
    'restore',
    'undo skip rejects a stop that is not skipped'
);
control_throws(
    static function () use ($db, $cancelledYesterday): void {
        bakery_driver_route_control_unskip($db, $cancelledYesterday);
    },
    'today',
    'undo skip rejects another date'
);
control_login($driverB);
control_throws(
    static function () use ($db, $cancelledA): void {
        bakery_driver_route_control_unskip($db, $cancelledA);
    },
    'own route',
    'undo skip rejects another driver route'
);
control_login($driverA);
bakery_driver_route_control_unskip($db, $cancelledA);
$statusStmt = $db->prepare('SELECT delivery_status FROM daily_order_assignments WHERE daily_order_id = ? ORDER BY id DESC LIMIT 1');
$statusStmt->execute([$cancelledA]);
control_assert('pending', (string)$statusStmt->fetchColumn(), 'undo skip restores today\'s cancelled stop');

control_throws(
    static function () use ($db): void {
        bakery_driver_route_control_reopen($db, '2099-01-02', '1');
    },
    'today',
    'reopen rejects another date'
);
control_throws(
    static function () use ($db, $today): void {
        bakery_driver_route_control_reopen($db, $today, '');
    },
    'Confirm',
    'reopen requires confirm'
);

control_throws(
    static function () use ($db, $pendingA): void {
        bakery_driver_route_control_save_quantities($db, $pendingA, [1 => '']);
    },
    'whole number',
    'quantity edit rejects an empty value'
);
control_throws(
    static function () use ($db, $pendingA, $productId): void {
        bakery_driver_route_control_save_quantities($db, $pendingA, [$productId => '-1']);
    },
    'whole number',
    'quantity edit rejects a negative number'
);
control_throws(
    static function () use ($db, $deliveredA, $productId): void {
        bakery_driver_route_control_save_quantities($db, $deliveredA, [$productId => '3']);
    },
    'delivered',
    'quantity edit rejects a delivered stop'
);
bakery_driver_route_control_save_quantities($db, $pendingA, [$productId => '4']);
$qtyStmt = $db->prepare('SELECT quantity FROM daily_order_items WHERE daily_order_id = ? AND product_id = ?');
$qtyStmt->execute([$pendingA, $productId]);
control_assert(4, (int)$qtyStmt->fetchColumn(), 'quantity edit saves a whole number on today\'s stop');

control_throws(
    static function () use ($db, $deliveredA, $driverB): void {
        bakery_driver_route_control_move($db, $deliveredA, $driverB);
    },
    'Delivered',
    'move rejects a delivered stop'
);
control_login($driverB);
control_throws(
    static function () use ($db, $pendingA, $driverA): void {
        bakery_driver_route_control_move($db, $pendingA, $driverA);
    },
    'own route',
    'move rejects another driver route'
);
control_login($driverA);
bakery_driver_route_control_move($db, $pendingA, $driverB);
$statusStmt->execute([$pendingA]);
$moved = $db->prepare('SELECT driver_id FROM daily_order_assignments WHERE daily_order_id = ? ORDER BY id DESC LIMIT 1');
$moved->execute([$pendingA]);
control_assert($driverB, (int)$moved->fetchColumn(), 'move puts the stop on the other driver for the same date');

control_throws(
    static function () use ($db): void {
        bakery_driver_route_control_turnin($db, '2099-01-03', '5');
    },
    'today',
    'cash turn-in rejects another date'
);
control_throws(
    static function () use ($db, $today): void {
        bakery_driver_route_control_save_load($db, '2099-01-04', [1 => '1']);
    },
    'today',
    'pickup load edit rejects another date'
);
control_throws(
    static function () use ($db, $today): void {
        bakery_driver_route_control_turnin($db, $today, '');
    },
    'amount',
    'cash turn-in rejects an empty amount'
);

$event = $db->prepare(
    "SELECT metadata FROM operational_events
     WHERE event_type = 'driver_stop_moved' AND daily_order_id = ?
     ORDER BY id DESC LIMIT 1"
);
$event->execute([$pendingA]);
$metadata = json_decode((string)$event->fetchColumn(), true);
control_assert(true, is_array($metadata) && isset($metadata['before'], $metadata['after']), 'move writes before and after for the office');

} finally {
foreach ($createdOrders as $orderId) {
    $db->prepare('DELETE FROM daily_order_assignments WHERE daily_order_id = ?')->execute([$orderId]);
    $db->prepare('DELETE FROM daily_order_items WHERE daily_order_id = ?')->execute([$orderId]);
    $db->prepare('DELETE FROM daily_orders WHERE id = ?')->execute([$orderId]);
}
if ($createdOrders !== [] && table_exists($db, 'operational_events')) {
    $db->prepare("DELETE FROM operational_events WHERE event_type IN ('driver_stop_moved', 'driver_stop_quantity_changed', 'delivery_unskipped') AND daily_order_id IN (" . implode(',', array_map('intval', $createdOrders)) . ")")->execute();
}
foreach ($customerIds as $customerId) {
    $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
}
}

$source = (string)file_get_contents($root . '/includes/driver_route_control.php')
    . (string)file_get_contents($root . '/includes/customer_order_mutations.php');
control_assert(true, strpos($source, 'bakery_customer_notify_daily_changed') === false || strpos((string)file_get_contents($root . '/includes/driver_route_control.php'), 'bakery_customer_notify') === false, 'driver quantity edits do not call customer notify');
control_assert(true, strpos((string)file_get_contents($root . '/includes/driver_route.js'), "name === 'control'") !== false, 'Back closes the driver control sheet');

if ($failures > 0) {
    echo "{$failures} failed\n";
    exit(1);
}
echo "driver route control checks passed\n";
exit(0);
