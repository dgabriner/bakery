<?php
/**
 * Order vs assignment status: load and skip stay in agreement.
 * Usage: php tests/run_status_alignment_tests.php
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
require_once $root . '/includes/product_inventory.php';
require_once $root . '/includes/delivery_skip.php';

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

$driverId = (int)$db->query('SELECT id FROM drivers ORDER BY id LIMIT 1')->fetchColumn();
if ($driverId <= 0) {
    fwrite(STDERR, "Need at least one driver on bakerysf_test\n");
    exit(1);
}

$date = date('Y-m-d', strtotime('+47 days'));
echo "Test date: $date driver $driverId\n";

$customerIds = [];
$orderIds = [];
$cleanup = static function () use ($db, $date, &$customerIds, &$orderIds, $driverId): void {
    if ($orderIds) {
        $oph = implode(',', array_fill(0, count($orderIds), '?'));
        $db->prepare("DELETE FROM daily_order_assignments WHERE daily_order_id IN ($oph)")->execute($orderIds);
        $db->prepare("DELETE FROM daily_order_items WHERE daily_order_id IN ($oph)")->execute($orderIds);
        $db->prepare("DELETE FROM daily_orders WHERE id IN ($oph)")->execute($orderIds);
    }
    if (table_exists($db, 'driver_loads')) {
        $loadStmt = $db->prepare('SELECT id FROM driver_loads WHERE driver_id=? AND delivery_date=?');
        $loadStmt->execute([$driverId, $date]);
        $loadIds = $loadStmt->fetchAll(PDO::FETCH_COLUMN);
        if ($loadIds) {
            $lph = implode(',', array_fill(0, count($loadIds), '?'));
            if (table_exists($db, 'driver_load_items')) {
                $db->prepare("DELETE FROM driver_load_items WHERE driver_load_id IN ($lph)")->execute($loadIds);
            }
            $db->prepare("DELETE FROM driver_loads WHERE id IN ($lph)")->execute($loadIds);
        }
    }
    if (table_exists($db, 'operational_events')) {
        $db->prepare('DELETE FROM operational_events WHERE operational_date=?')->execute([$date]);
    }
    if ($customerIds) {
        $cph = implode(',', array_fill(0, count($customerIds), '?'));
        $db->prepare("DELETE FROM customers WHERE id IN ($cph)")->execute($customerIds);
    }
    $customerIds = [];
    $orderIds = [];
};

$orderStatus = static function (int $id) use ($db): string {
    $stmt = $db->prepare('SELECT status FROM daily_orders WHERE id=?');
    $stmt->execute([$id]);
    return (string)$stmt->fetchColumn();
};
$assignStatus = static function (int $id) use ($db): string {
    $stmt = $db->prepare('SELECT delivery_status FROM daily_order_assignments WHERE daily_order_id=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$id]);
    return (string)$stmt->fetchColumn();
};

$cleanup();

try {
    $insertCustomer = $db->prepare(
        "INSERT INTO customers (name, address, is_active, sfb_origin) VALUES (?, ?, 1, 'human')"
    );
    $insertCustomer->execute(['Align Cafe', '1 Status Lane']);
    $openCustomer = (int)$db->lastInsertId();
    $insertCustomer->execute(['Align Skip Market', '2 Status Lane']);
    $skipCustomer = (int)$db->lastInsertId();
    $customerIds = [$openCustomer, $skipCustomer];

    $insertOrder = $db->prepare(
        "INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, 'pending', 0)"
    );
    $insertOrder->execute([$openCustomer, $date]);
    $openOrder = (int)$db->lastInsertId();
    $insertOrder->execute([$skipCustomer, $date]);
    $skipOrder = (int)$db->lastInsertId();
    $orderIds = [$openOrder, $skipOrder];

    $insertAssign = $db->prepare(
        "INSERT INTO daily_order_assignments
         (daily_order_id, driver_id, delivery_date, route_order, scheduled_delivery_time, delivery_status)
         VALUES (?, ?, ?, ?, '08:00:00', ?)"
    );
    $insertAssign->execute([$openOrder, $driverId, $date, 1, 'pending']);
    $insertAssign->execute([$skipOrder, $driverId, $date, 2, 'cancelled']);

    bakery_inventory_mark_open_stops_out_for_delivery($db, $driverId, $date);
    $assert($orderStatus($openOrder) === 'out_for_delivery', 'load marks pending stop out_for_delivery');
    $assert($assignStatus($openOrder) === 'pending', 'load does not lock pending stop as in_transit');
    $assert($orderStatus($skipOrder) === 'pending', 'load does not resurrect a cancelled stop as out_for_delivery');

    $db->prepare("UPDATE daily_orders SET status='out_for_delivery' WHERE id=?")->execute([$skipOrder]);
    $db->prepare("UPDATE daily_order_assignments SET delivery_status='pending' WHERE daily_order_id=?")->execute([$skipOrder]);
    bakery_skip_delivery_stop($db, $skipOrder, 'Customer closed early');
    $assert($assignStatus($skipOrder) === 'cancelled', 'skip cancels the assignment');
    $assert($orderStatus($skipOrder) === 'ready', 'skip pulls the order off out_for_delivery');
    $assert($orderStatus($openOrder) === 'out_for_delivery', 'skip does not change the other stop');

    bakery_unskip_delivery_stop($db, $skipOrder);
    $assert($assignStatus($skipOrder) === 'pending', 'unskip restores assignment to pending');
    $assert($orderStatus($skipOrder) === 'ready', 'unskip without an open load does not pretend the van left');

    if (table_exists($db, 'driver_loads')) {
        $db->prepare(
            'INSERT INTO driver_loads (driver_id, delivery_date, status) VALUES (?, ?, ?)'
        )->execute([$driverId, $date, 'loaded']);
        bakery_skip_delivery_stop($db, $skipOrder, 'Closed again');
        bakery_unskip_delivery_stop($db, $skipOrder);
        $assert($orderStatus($skipOrder) === 'out_for_delivery', 'unskip after load puts the order back on the van');
        $assert($assignStatus($skipOrder) === 'pending', 'restored stop stays pending so the route can still be reordered');
    }
} catch (Throwable $e) {
    echo 'FAIL  ' . $e->getMessage() . "\n";
    $fail++;
} finally {
    $cleanup();
}

echo $fail === 0 ? "\n$pass passed, 0 failed\n" : "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
