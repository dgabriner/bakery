<?php
/**
 * Route Manager pickup manifests come from saved Driver Pickup Loads.
 *
 * Usage: php tests/run_route_manager_pickup_tests.php
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

require_once $root . '/includes/product_inventory.php';
require_once $root . '/includes/route_manager.php';

if (!bakery_inventory_ready($db)) {
    echo "SKIP  pickup inventory tables are not installed\n";
    exit(0);
}

$date = '2099-11-04';
$driverId = (int)$db->query('SELECT id FROM drivers ORDER BY id LIMIT 1')->fetchColumn();
$product = $db->query('SELECT id, name FROM products ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if ($driverId <= 0 || !$product) {
    throw new RuntimeException('Test clone lacks a driver or product for pickup manifests');
}
$productId = (int)$product['id'];

$db->prepare('DELETE FROM inventory_movements WHERE delivery_date = ?')->execute([$date]);
$loadIds = $db->prepare('SELECT id FROM driver_loads WHERE delivery_date = ?');
$loadIds->execute([$date]);
foreach ($loadIds->fetchAll(PDO::FETCH_COLUMN) as $loadId) {
    $db->prepare('DELETE FROM driver_load_items WHERE driver_load_id = ?')->execute([(int)$loadId]);
}
$db->prepare('DELETE FROM driver_loads WHERE delivery_date = ?')->execute([$date]);
$db->prepare('DELETE FROM product_inventory_days WHERE delivery_date = ?')->execute([$date]);

bakery_inventory_save_driver_load($db, $date, $driverId, [$productId => 24], 'route manager pickup test');

$manifests = bakery_inventory_pickup_manifests($db, $date, [$driverId]);
$items = $manifests[$driverId] ?? [];
if (count($items) !== 1 || (int)$items[0]['loaded_quantity'] !== 24 || (int)$items[0]['product_id'] !== $productId) {
    fwrite(STDERR, "FAIL  pickup manifest should return the saved load quantity\n");
    exit(1);
}
echo "PASS  pickup manifest returns saved Driver Pickup Loads\n";

$zero = bakery_inventory_pickup_manifests($db, $date, [$driverId + 999999]);
if ($zero !== []) {
    fwrite(STDERR, "FAIL  other drivers must not inherit this pickup load\n");
    exit(1);
}
echo "PASS  pickup manifest is scoped to the requested driver\n";

$attached = route_manager_attach_pickup_manifests(
    [$driverId => ['id' => $driverId, 'deliveries' => []]],
    $manifests
);
if ((int)$attached[$driverId]['pickup_piece_count'] !== 24) {
    fwrite(STDERR, "FAIL  route manager attach should total loaded pieces\n");
    exit(1);
}
echo "PASS  route manager attaches pickup piece totals from the saved load\n";

$incompleteDate = '2099-11-05';
$customerId = (int)$db->query('SELECT id FROM customers WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
if ($customerId <= 0) {
    throw new RuntimeException('Test clone lacks a customer for incomplete-load progress');
}
$db->prepare('DELETE FROM inventory_movements WHERE delivery_date = ?')->execute([$incompleteDate]);
$incLoadIds = $db->prepare('SELECT id FROM driver_loads WHERE delivery_date = ?');
$incLoadIds->execute([$incompleteDate]);
foreach ($incLoadIds->fetchAll(PDO::FETCH_COLUMN) as $loadId) {
    $db->prepare('DELETE FROM driver_load_items WHERE driver_load_id = ?')->execute([(int)$loadId]);
}
$db->prepare('DELETE FROM driver_loads WHERE delivery_date = ?')->execute([$incompleteDate]);
$db->prepare('DELETE FROM product_inventory_days WHERE delivery_date = ?')->execute([$incompleteDate]);
$oldOrders = $db->prepare('SELECT id FROM daily_orders WHERE order_date = ?');
$oldOrders->execute([$incompleteDate]);
$oldIds = array_map('intval', $oldOrders->fetchAll(PDO::FETCH_COLUMN));
if ($oldIds) {
    $in = implode(',', $oldIds);
    $db->exec("DELETE FROM daily_order_assignments WHERE daily_order_id IN ({$in})");
    $db->exec("DELETE FROM daily_order_items WHERE daily_order_id IN ({$in})");
    $db->exec("DELETE FROM daily_orders WHERE id IN ({$in})");
}

$db->prepare(
    "INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, 'pending', 0)"
)->execute([$customerId, $incompleteDate]);
$orderId = (int)$db->lastInsertId();
$db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, 12, 1, 12)'
)->execute([$orderId, $productId]);
$db->prepare(
    "INSERT INTO daily_order_assignments
     (daily_order_id, driver_id, delivery_date, route_order, scheduled_delivery_time, delivery_status)
     VALUES (?, ?, ?, 1, '08:00:00', 'pending')"
)->execute([$orderId, $driverId, $incompleteDate]);

$before = bakery_inventory_load_progress($db, $incompleteDate);
if ((int)$before['drivers_with_work'] !== 1 || count($before['incomplete']) !== 1) {
    fwrite(STDERR, "FAIL  assigned units with no pickup should be one incomplete load\n");
    exit(1);
}
if ((int)$before['incomplete'][0]['required'] !== 12 || (int)$before['incomplete'][0]['loaded'] !== 0) {
    fwrite(STDERR, "FAIL  incomplete load should show 12 required and 0 loaded\n");
    exit(1);
}
echo "PASS  assigned units with no pickup count as one incomplete load\n";

bakery_inventory_save_driver_load($db, $incompleteDate, $driverId, [$productId => 12], 'no production recorded');
$after = bakery_inventory_load_progress($db, $incompleteDate);
if ($after['incomplete'] !== []) {
    fwrite(STDERR, "FAIL  saving pickup without production should finish the incomplete load\n");
    exit(1);
}
echo "PASS  saving pickup without production finishes the incomplete load\n";

$src = (string)file_get_contents($root . '/driver_load.php');
if (strpos($src, 'data-load-finish-hint') === false || strpos($src, 'driver_load.today_still_open') === false) {
    fwrite(STDERR, "FAIL  Driver Pickup Loads must name the stuck load and warn when today is a different day\n");
    exit(1);
}
echo "PASS  Driver Pickup Loads explains how to finish an incomplete load\n";

$db->prepare('DELETE FROM inventory_movements WHERE delivery_date = ?')->execute([$incompleteDate]);
$incLoadIds->execute([$incompleteDate]);
foreach ($incLoadIds->fetchAll(PDO::FETCH_COLUMN) as $loadId) {
    $db->prepare('DELETE FROM driver_load_items WHERE driver_load_id = ?')->execute([(int)$loadId]);
}
$db->prepare('DELETE FROM driver_loads WHERE delivery_date = ?')->execute([$incompleteDate]);
$db->prepare('DELETE FROM product_inventory_days WHERE delivery_date = ?')->execute([$incompleteDate]);
$db->prepare('DELETE FROM daily_order_assignments WHERE daily_order_id = ?')->execute([$orderId]);
$db->prepare('DELETE FROM daily_order_items WHERE daily_order_id = ?')->execute([$orderId]);
$db->prepare('DELETE FROM daily_orders WHERE id = ?')->execute([$orderId]);

$db->prepare('DELETE FROM inventory_movements WHERE delivery_date = ?')->execute([$date]);
$loadIds->execute([$date]);
foreach ($loadIds->fetchAll(PDO::FETCH_COLUMN) as $loadId) {
    $db->prepare('DELETE FROM driver_load_items WHERE driver_load_id = ?')->execute([(int)$loadId]);
}
$db->prepare('DELETE FROM driver_loads WHERE delivery_date = ?')->execute([$date]);
$db->prepare('DELETE FROM product_inventory_days WHERE delivery_date = ?')->execute([$date]);

echo "All Route Manager pickup checks passed\n";
exit(0);
