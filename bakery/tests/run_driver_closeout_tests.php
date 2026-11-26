<?php
/**
 * Driver-mode route closeout.
 *
 * Summary counts and the open-stop confirm flag. Inventory posting stays in
 * bakery_inventory_reconcile_driver_load.
 *
 * Usage: php tests/run_driver_closeout_tests.php
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

require_once $root . '/includes/product_inventory.php';
require_once $root . '/includes/exception_desk.php';
require_once $root . '/includes/driver_closeout.php';

$failures = 0;

function closeout_assert($expected, $actual, string $label): void
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

function closeout_assert_true(bool $condition, string $label): void
{
    closeout_assert(true, $condition, $label);
}

$english = require $root . '/lang/en.php';
$spanish = require $root . '/lang/es.php';
$needed = [
    'driver.close_route',
    'driver.closeout_title',
    'driver.closeout_delivered',
    'driver.closeout_problems',
    'driver.closeout_pieces',
    'driver.closeout_credits',
    'driver.closeout_returns',
    'driver.closeout_cash',
    'driver.closeout_open',
    'driver.closeout_open_warn',
    'driver.closeout_confirm_open',
    'driver.closeout_reopen_manager',
    'driver.closeout_own_route',
];
foreach ($needed as $key) {
    closeout_assert_true(isset($english[$key], $spanish[$key]) && $english[$key] !== '' && $spanish[$key] !== '' && $english[$key] !== $spanish[$key], "{$key} is in English and Spanish");
}
closeout_assert('Close out route', $english['driver.close_route'], 'English action label');
closeout_assert('Cerrar ruta', $spanish['driver.close_route'], 'Spanish action label');

$source = (string)file_get_contents($root . '/route_closeout.php')
    . (string)file_get_contents($root . '/includes/product_inventory.php')
    . (string)file_get_contents($root . '/includes/driver_closeout.php');
closeout_assert_true(strpos($source, 'bool $allowOpenStops = false') !== false, 'open-stop bypass is an explicit flag on the existing reconcile function');
closeout_assert_true(strpos($source, "bakery_t('driver.closeout_own_route')") !== false, 'a driver cannot close another driver route');
closeout_assert_true(strpos($source, 'bakery_driver_route_control_reopen') !== false, 'drivers reopen through the same-day control guard');
closeout_assert_true(
    strpos($source, '$isDriverOnly && (string)($_POST[\'confirm_open_stops\'] ?? \'\') === \'1\'') !== false,
    'only a driver confirm sets the open-stop flag'
);

$date = '2099-11-25';
$drivers = $db->query('SELECT id FROM drivers ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
$customers = $db->query('SELECT id FROM customers ORDER BY id LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);
$productId = (int)$db->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
if (count($drivers) < 2 || count($customers) < 3 || $productId <= 0) {
    fwrite(STDERR, "FAIL  test database needs two drivers, three customers, and a product\n");
    exit(1);
}
$driverA = (int)$drivers[0];
$driverB = (int)$drivers[1];

function closeout_wipe(PDO $db, string $date): void
{
    $db->prepare('DELETE FROM delivery_recovery_cases WHERE delivery_date = ?')->execute([$date]);
    $orderIds = $db->prepare('SELECT id FROM daily_orders WHERE order_date = ?');
    $orderIds->execute([$date]);
    $ids = array_map('intval', $orderIds->fetchAll(PDO::FETCH_COLUMN));
    if ($ids !== []) {
        $in = implode(',', $ids);
        $db->exec("DELETE FROM inventory_movements WHERE daily_order_id IN ({$in})");
        $db->exec("DELETE FROM daily_order_assignments WHERE daily_order_id IN ({$in})");
        $db->exec("DELETE FROM daily_order_items WHERE daily_order_id IN ({$in})");
        $db->exec("DELETE FROM daily_orders WHERE id IN ({$in})");
    }
    $db->prepare('DELETE FROM inventory_movements WHERE delivery_date = ?')->execute([$date]);
    $loadIds = $db->prepare('SELECT id FROM driver_loads WHERE delivery_date = ?');
    $loadIds->execute([$date]);
    foreach ($loadIds->fetchAll(PDO::FETCH_COLUMN) as $loadId) {
        $db->prepare('DELETE FROM driver_load_items WHERE driver_load_id = ?')->execute([(int)$loadId]);
    }
    $db->prepare('DELETE FROM driver_loads WHERE delivery_date = ?')->execute([$date]);
    $db->prepare('DELETE FROM product_inventory_days WHERE delivery_date = ?')->execute([$date]);
}

function closeout_order(PDO $db, int $customerId, string $date, string $orderStatus, int $productId, int $pieces, ?float $cash): int
{
    $db->prepare(
        'INSERT INTO daily_orders (customer_id, order_date, status, total_amount, delivered_pieces, amount_collected, credits_taken_back)
         VALUES (?, ?, ?, 0, ?, ?, 0)'
    )->execute([$customerId, $date, $orderStatus, $pieces, $cash]);
    $orderId = (int)$db->lastInsertId();
    $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, delivered_quantity, unit_price, line_total)
         VALUES (?, ?, ?, ?, 0, 0)'
    )->execute([$orderId, $productId, $pieces, $pieces]);
    return $orderId;
}

function closeout_assign(PDO $db, int $orderId, int $driverId, string $date, string $status, int $routeOrder): int
{
    $db->prepare(
        'INSERT INTO daily_order_assignments (daily_order_id, driver_id, delivery_date, route_order, delivery_status)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$orderId, $driverId, $date, $routeOrder, $status]);
    return (int)$db->lastInsertId();
}

closeout_wipe($db, $date);

try {
    bakery_inventory_record_production($db, $date, $productId, 9, 'driver closeout test');
    bakery_inventory_save_driver_load($db, $date, $driverA, [$productId => 6], 'driver closeout test');
    bakery_inventory_save_driver_load($db, $date, $driverB, [$productId => 3], 'driver closeout test other driver');

    $deliveredId = closeout_order($db, (int)$customers[0], $date, 'delivered', $productId, 4, 12.50);
    closeout_assign($db, $deliveredId, $driverA, $date, 'delivered', 1);
    $openId = closeout_order($db, (int)$customers[1], $date, 'pending', $productId, 0, null);
    closeout_assign($db, $openId, $driverA, $date, 'pending', 2);
    $failedId = closeout_order($db, (int)$customers[2], $date, 'pending', $productId, 0, null);
    $failedAssignment = closeout_assign($db, $failedId, $driverA, $date, 'failed', 3);
    $db->prepare(
        "INSERT INTO delivery_recovery_cases
         (failed_assignment_id, active_assignment_id, daily_order_id, delivery_date, original_driver_id,
          failure_reason, manager_note, workflow_state, customer_communication_status, billing_handoff)
         VALUES (?, ?, ?, ?, ?, 'access_issue', 'Gate locked', 'open', 'not_needed', 'not_needed')"
    )->execute([$failedAssignment, $failedAssignment, $failedId, $date, $driverA]);

    if (function_exists('bakery_set_locale')) {
        bakery_set_locale('en', false);
    }
    $summary = bakery_driver_closeout_summary($db, $date, $driverA);
    closeout_assert(1, $summary['delivered_stops'], 'one stop delivered');
    closeout_assert(1, $summary['open_stops'], 'one stop still open');
    closeout_assert(4, $summary['pieces_delivered'], 'pieces delivered come from the delivered stop');
    closeout_assert(12.5, $summary['cash_collected'], 'cash collected is the COD recorded on the stop');
    closeout_assert(2, $summary['returns'], 'leftover bread is the return');
    closeout_assert(true, $summary['has_load'], 'pickup load is present');
    closeout_assert(false, $summary['is_closed'], 'route is not closed yet');
    closeout_assert(1, count($summary['problem_stops']), 'failed stop is listed');
    closeout_assert(true, strpos($summary['problem_stops'][0]['reason'], "Can't get in") !== false, 'problem reason uses the existing recovery label');
    closeout_assert(true, strpos($summary['problem_stops'][0]['reason'], 'Gate locked') !== false, 'problem note is included');

    $blocked = false;
    try {
        bakery_inventory_reconcile_driver_load($db, $date, $driverA, [
            $productId => ['returned' => 2, 'wasted' => 0],
        ], 'driver closeout without confirm');
    } catch (RuntimeException $e) {
        $blocked = strpos($e->getMessage(), 'still pending') !== false;
    }
    closeout_assert(true, $blocked, 'closeout without confirm still refuses open stops');

    bakery_inventory_reconcile_driver_load($db, $date, $driverA, [
        $productId => ['returned' => 2, 'wasted' => 0],
    ], 'driver closeout confirmed', true);
    $closed = bakery_driver_closeout_summary($db, $date, $driverA);
    closeout_assert(true, $closed['is_closed'], 'confirmed closeout uses the existing reconcile path');
    closeout_assert(1, $closed['open_stops'], 'confirming closeout does not mark the open stop delivered');

    $other = $db->prepare('SELECT status FROM driver_loads WHERE driver_id = ? AND delivery_date = ?');
    $other->execute([$driverB, $date]);
    closeout_assert('loaded', (string)$other->fetchColumn(), 'another driver route stays open');
} finally {
    closeout_wipe($db, $date);
}

if ($failures > 0) {
    echo "{$failures} failed\n";
    exit(1);
}
echo "driver closeout checks passed\n";
exit(0);
