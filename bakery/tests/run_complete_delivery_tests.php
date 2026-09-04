<?php
/**
 * Characterization: complete_delivery confirm transaction (assignment + lines + snapshot).
 *
 * §4 invariants asserted by name:
 *   - Delivery confirmation creates the billable record (one transaction)
 *   - Door credits post return movements once; re-confirm deltas
 *   - Billable = delivered − credits_taken_back
 *
 * Credit FG math detail lives in run_credit_return_tests — this suite focuses on
 * the confirm transaction writing assignment + order + line + snapshot together.
 *
 * CLI / local bakerysf_test only. Cleans up the synthetic future date.
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
require_once $root . '/complete_delivery.php';

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
$assert_float = static function (float $expected, float $actual, string $msg) use ($assert): void {
    $assert(abs($expected - $actual) < 0.005, $msg . " (expected=$expected actual=$actual)");
};

$date = '2099-11-18';
echo "Test date: $date\n";
echo "INVARIANT  Delivery confirmation creates the billable record (one transaction)\n";
echo "INVARIANT  Door credits post return movements once; re-confirm deltas\n";
echo "INVARIANT  Billable = delivered − credits_taken_back\n";

$driverId = (int)$db->query('SELECT id FROM drivers ORDER BY id LIMIT 1')->fetchColumn();
$customerId = (int)$db->query('SELECT id FROM customers WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$product = $db->query(
    'SELECT id, price FROM products WHERE price > 0 ORDER BY id LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if ($driverId <= 0 || $customerId <= 0 || !$product) {
    throw new RuntimeException('bakerysf_test lacks driver/customer/product for delivery confirm');
}
$productId = (int)$product['id'];
$price = round((float)$product['price'], 2);

$wipe = static function (PDO $db, string $date): void {
    $orderIds = $db->prepare('SELECT id FROM daily_orders WHERE order_date = ?');
    $orderIds->execute([$date]);
    $ids = array_map('intval', $orderIds->fetchAll(PDO::FETCH_COLUMN));
    if ($ids) {
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
};

$creditReturnSum = static function (PDO $db, string $date, int $orderId): int {
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(quantity_delta), 0)
         FROM inventory_movements
         WHERE delivery_date = ?
           AND movement_type = 'return'
           AND notes = ?"
    );
    $stmt->execute([$date, bakery_inventory_credit_return_note($orderId)]);
    return (int)$stmt->fetchColumn();
};

$wipe($db, $date);

echo "\n=== Confirm writes assignment + order + lines + snapshot together ===\n";
bakery_inventory_record_production($db, $date, $productId, 12, 'char complete_delivery bake');
bakery_inventory_save_driver_load($db, $date, $driverId, [$productId => 12], 'char complete_delivery load');

$db->prepare(
    "INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, 'out_for_delivery', ?)"
)->execute([$customerId, $date, round(12 * $price, 2)]);
$orderId = (int)$db->lastInsertId();
$db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
     VALUES (?, ?, 12, ?, ?)'
)->execute([$orderId, $productId, $price, round(12 * $price, 2)]);
$db->prepare(
    "INSERT INTO daily_order_assignments
     (daily_order_id, driver_id, delivery_date, route_order, scheduled_delivery_time, delivery_status)
     VALUES (?, ?, ?, 1, '08:00:00', 'pending')"
)->execute([$orderId, $driverId, $date]);

$confirmed = bakery_confirm_delivery($db, $orderId, 12, 3, [
    'amount_collected' => 0.00,
    'price_per_piece' => $price,
]);

$assert_eq(3, (int)$confirmed['credits_taken_back'], 'confirm stores credits_taken_back');
$assert_eq(9, (int)$confirmed['billable_pieces'], 'billable = delivered − credits_taken_back (§4)');
$assert_float(round(9 * $price, 2), (float)$confirmed['total'], 'snapshot total uses billable pieces');

$header = $db->prepare(
    'SELECT status, delivered_pieces, credits_taken_back, total_amount, delivery_confirmed_at
     FROM daily_orders WHERE id = ?'
);
$header->execute([$orderId]);
$headerRow = $header->fetch(PDO::FETCH_ASSOC);
$assert_eq('delivered', (string)$headerRow['status'], 'order status becomes delivered in the confirm transaction');
$assert_eq(12, (int)$headerRow['delivered_pieces'], 'header delivered_pieces snapshot written');
$assert_eq(3, (int)$headerRow['credits_taken_back'], 'header credits_taken_back snapshot written');
$assert($headerRow['delivery_confirmed_at'] !== null && $headerRow['delivery_confirmed_at'] !== '',
    'delivery_confirmed_at stamped');

$assignment = $db->prepare(
    'SELECT delivery_status FROM daily_order_assignments WHERE daily_order_id = ? LIMIT 1'
);
$assignment->execute([$orderId]);
$assert_eq('delivered', (string)$assignment->fetchColumn(),
    'assignment delivery_status becomes delivered in the same transaction (§4 confirm creates billable record)');

$line = $db->prepare(
    'SELECT delivered_quantity FROM daily_order_items WHERE daily_order_id = ? AND product_id = ?'
);
$line->execute([$orderId, $productId]);
$lineDelivered = $line->fetchColumn();
$assert($lineDelivered !== false && (int)$lineDelivered === 12,
    'line delivered_quantity written in the confirm transaction');

$assert_eq(3, $creditReturnSum($db, $date, $orderId), 'door credits post return movements once on confirm');

echo "\n=== Re-confirm deltas (does not double-return; can change credits) ===\n";
$replay = bakery_confirm_delivery($db, $orderId, 12, 3, [
    'amount_collected' => 0.00,
    'price_per_piece' => $price,
]);
$assert_eq(9, (int)$replay['billable_pieces'], 'identical re-confirm keeps billable math');
$assert_eq(3, $creditReturnSum($db, $date, $orderId), 'identical re-confirm does not double-return credits');

$delta = bakery_confirm_delivery($db, $orderId, 12, 5, [
    'amount_collected' => 0.00,
    'price_per_piece' => $price,
]);
$assert_eq(5, (int)$delta['credits_taken_back'], 're-confirm can raise credits_taken_back');
$assert_eq(7, (int)$delta['billable_pieces'], 're-confirm deltas billable pieces');
$assert_eq(5, $creditReturnSum($db, $date, $orderId),
    're-confirm deltas return movements to the new credit total (not additive double)');

$header->execute([$orderId]);
$headerRow = $header->fetch(PDO::FETCH_ASSOC);
$assert_eq(5, (int)$headerRow['credits_taken_back'], 'header snapshot updated on re-confirm delta');
// Re-confirm multiplies new billable by the post-confirm invoice average
// (frozen delivery_order_total / ordered), not the original catalog unit price.
$assert_float((float)$delta['total'], (float)$headerRow['total_amount'],
    'header snapshot total equals what the re-confirm transaction wrote');
$assert((float)$headerRow['total_amount'] > 0, 're-confirm delta still writes a positive frozen total');

$wipe($db, $date);

echo "\n=== complete_delivery characterization: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
