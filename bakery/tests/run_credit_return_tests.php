<?php
/**
 * Door credits as finished-goods returns.
 *
 * Usage: php tests/run_credit_return_tests.php
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

$date = '2099-09-22';
$driverId = (int)$db->query('SELECT id FROM drivers ORDER BY id LIMIT 1')->fetchColumn();
$customerId = (int)$db->query('SELECT id FROM customers WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$products = $db->query(
    'SELECT id, price FROM products WHERE price > 0 ORDER BY id LIMIT 2'
)->fetchAll(PDO::FETCH_ASSOC);
if ($driverId <= 0 || $customerId <= 0 || count($products) < 2) {
    throw new RuntimeException('Production-derived test clone lacks driver/customer/products for credit returns');
}
$productA = (int)$products[0]['id'];
$productB = (int)$products[1]['id'];
$priceA = round((float)$products[0]['price'], 2);
$priceB = round((float)$products[1]['price'], 2);

function credit_wipe_date(PDO $db, string $date): void
{
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
}

function credit_available(PDO $db, string $date, int $productId): int
{
    $stmt = $db->prepare(
        'SELECT available_quantity FROM product_inventory_days WHERE delivery_date = ? AND product_id = ?'
    );
    $stmt->execute([$date, $productId]);
    $val = $stmt->fetchColumn();
    return $val === false ? 0 : (int)$val;
}

function credit_loaded(PDO $db, string $date, int $productId): int
{
    $stmt = $db->prepare(
        'SELECT loaded_quantity FROM product_inventory_days WHERE delivery_date = ? AND product_id = ?'
    );
    $stmt->execute([$date, $productId]);
    $val = $stmt->fetchColumn();
    return $val === false ? 0 : (int)$val;
}

function credit_return_sum(PDO $db, string $date, int $orderId): int
{
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(quantity_delta), 0)
         FROM inventory_movements
         WHERE delivery_date = ?
           AND movement_type = 'return'
           AND notes = ?"
    );
    $stmt->execute([$date, bakery_inventory_credit_return_note($orderId)]);
    return (int)$stmt->fetchColumn();
}

function credit_closeout_return_sum(PDO $db, string $date, int $driverId): int
{
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(quantity_delta), 0)
         FROM inventory_movements
         WHERE delivery_date = ?
           AND driver_id = ?
           AND movement_type = 'return'
           AND (notes IS NULL OR notes NOT LIKE '%credit taken back%')"
    );
    $stmt->execute([$date, $driverId]);
    return (int)$stmt->fetchColumn();
}

function credit_make_order(PDO $db, int $customerId, string $date, array $lines): int
{
    $total = 0.0;
    foreach ($lines as $line) {
        $total += round((int)$line['qty'] * (float)$line['price'], 2);
    }
    $ins = $db->prepare(
        "INSERT INTO daily_orders (customer_id, order_date, status, total_amount)
         VALUES (?, ?, 'pending', ?)"
    );
    $ins->execute([$customerId, $date, $total]);
    $orderId = (int)$db->lastInsertId();
    $item = $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
         VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($lines as $line) {
        $qty = (int)$line['qty'];
        $price = round((float)$line['price'], 2);
        $item->execute([$orderId, (int)$line['product_id'], $qty, $price, round($qty * $price, 2)]);
    }
    return $orderId;
}

function credit_assign(PDO $db, int $orderId, int $driverId, string $date, int $routeOrder = 1): void
{
    $db->prepare(
        "INSERT INTO daily_order_assignments
         (daily_order_id, driver_id, delivery_date, route_order, scheduled_delivery_time, delivery_status)
         VALUES (?, ?, ?, ?, '08:00:00', 'pending')"
    )->execute([$orderId, $driverId, $date, $routeOrder]);
}

function credit_stock_and_load(PDO $db, string $date, int $driverId, array $quantities): void
{
    foreach ($quantities as $productId => $qty) {
        bakery_inventory_record_production($db, $date, (int)$productId, (int)$qty, 'credit-return test bake');
    }
    bakery_inventory_save_driver_load($db, $date, $driverId, $quantities, 'credit-return test load');
}

function credit_confirm(PDO $db, int $orderId, int $delivered, int $credits, float $cash): array
{
    return bakery_confirm_delivery($db, $orderId, $delivered, $credits, [
        'amount_collected' => $cash,
        'price_per_piece' => 1.00,
    ]);
}

credit_wipe_date($db, $date);

echo "\n=== Confirm with credits posts FG returns ===\n";
credit_stock_and_load($db, $date, $driverId, [$productA => 10]);
$orderCredit = credit_make_order($db, $customerId, $date, [
    ['product_id' => $productA, 'qty' => 10, 'price' => $priceA],
]);
credit_assign($db, $orderCredit, $driverId, $date, 1);
$availableAfterLoad = credit_available($db, $date, $productA);
assert_eq(0, $availableAfterLoad, 'load reserves all produced units');
$confirmed = credit_confirm($db, $orderCredit, 10, 2, 0.00);
assert_eq(2, (int)$confirmed['credits_taken_back'], 'confirm stores credits_taken_back');
assert_eq(8, (int)$confirmed['billable_pieces'], 'billable is delivered minus credits');
assert_float_near(round(8 * $priceA, 2), (float)$confirmed['total'], 'snapshot total uses billable pieces');
assert_eq(2, credit_return_sum($db, $date, $orderCredit), 'return movements total credited pieces');
assert_eq(2, credit_available($db, $date, $productA), 'available_quantity increases by credits');
assert_eq(8, credit_loaded($db, $date, $productA), 'credited pieces leave loaded custody');

$header = $db->prepare('SELECT delivered_pieces, credits_taken_back, total_amount FROM daily_orders WHERE id = ?');
$header->execute([$orderCredit]);
$headerRow = $header->fetch(PDO::FETCH_ASSOC);
assert_eq(10, (int)$headerRow['delivered_pieces'], 'header delivered_pieces unchanged by stock posting');
assert_eq(2, (int)$headerRow['credits_taken_back'], 'header credits_taken_back stored');
assert_float_near(round(8 * $priceA, 2), (float)$headerRow['total_amount'], 'frozen snapshot is delivered minus credits');

echo "\n=== Replay confirm does not double-return ===\n";
$replay = credit_confirm($db, $orderCredit, 10, 2, 0.00);
assert_eq(8, (int)$replay['billable_pieces'], 'replay keeps billable math');
assert_eq(2, credit_return_sum($db, $date, $orderCredit), 'replay does not add a second return of N');
assert_eq(2, credit_available($db, $date, $productA), 'replay does not double available_quantity');

echo "\n=== Credits = 0 posts no return ===\n";
credit_wipe_date($db, $date);
credit_stock_and_load($db, $date, $driverId, [$productA => 10]);
$orderZero = credit_make_order($db, $customerId, $date, [
    ['product_id' => $productA, 'qty' => 10, 'price' => $priceA],
]);
credit_assign($db, $orderZero, $driverId, $date, 1);
$zero = credit_confirm($db, $orderZero, 10, 0, 0.00);
assert_eq(0, (int)$zero['credits_taken_back'], 'zero-credit confirm stores 0');
assert_eq(10, (int)$zero['billable_pieces'], 'zero-credit billable equals delivered');
assert_eq(0, credit_return_sum($db, $date, $orderZero), 'zero credits post no credit-return movement');
assert_eq(0, credit_available($db, $date, $productA), 'zero credits leave available at post-load amount');

echo "\n=== Closeout does not return the same credited units ===\n";
credit_wipe_date($db, $date);
credit_stock_and_load($db, $date, $driverId, [$productA => 12]);
$orderClose = credit_make_order($db, $customerId, $date, [
    ['product_id' => $productA, 'qty' => 10, 'price' => $priceA],
]);
credit_assign($db, $orderClose, $driverId, $date, 1);
credit_confirm($db, $orderClose, 10, 2, 0.00);
assert_eq(2, credit_available($db, $date, $productA), 'credits already in FG before closeout');
assert_eq(10, credit_loaded($db, $date, $productA), 'loaded after credit return is original minus credits');

$closeLines = bakery_inventory_closeout_lines($db, $date, $driverId);
$lineA = null;
foreach ($closeLines as $line) {
    if ((int)$line['product_id'] === $productA) {
        $lineA = $line;
        break;
    }
}
assert_true($lineA !== null, 'closeout line exists for credited product');
assert_eq(8, (int)$lineA['delivered_quantity'], 'closeout delivered is net of door credits');
assert_eq(2, (int)$lineA['credits_quantity'], 'closeout shows door credits already returned at confirm');
assert_eq(2, (int)$lineA['suggested_returned'], 'van leftover is loaded minus net delivered minus credits');

$doubleCountBlocked = false;
try {
    bakery_inventory_reconcile_driver_load($db, $date, $driverId, [
        $productA => ['returned' => 4, 'wasted' => 0],
    ], 'QA would double-count credits');
} catch (RuntimeException $e) {
    $doubleCountBlocked = strpos($e->getMessage(), 'must balance') !== false;
}
assert_true($doubleCountBlocked, 'closeout rejects leftover that includes already-returned credits');

$reconcilePayload = [
    $productA => [
        'returned' => (int)$lineA['suggested_returned'],
        'wasted' => 0,
    ],
];
bakery_inventory_reconcile_driver_load($db, $date, $driverId, $reconcilePayload, 'QA leftover only');
assert_eq(2, credit_return_sum($db, $date, $orderClose), 'confirm credit returns stay at N after closeout');
assert_eq(2, credit_closeout_return_sum($db, $date, $driverId), 'closeout returns only van leftover, not the credited N');
assert_eq(4, credit_available($db, $date, $productA), 'available is credits plus leftover, not double-counted credits');
assert_eq(0, credit_loaded($db, $date, $productA), 'route loaded custody is cleared');

echo "\n=== Multi-product allocation follows id ASC ===\n";
credit_wipe_date($db, $date);
credit_stock_and_load($db, $date, $driverId, [$productA => 5, $productB => 3]);
$orderMixed = credit_make_order($db, $customerId, $date, [
    ['product_id' => $productA, 'qty' => 5, 'price' => $priceA],
    ['product_id' => $productB, 'qty' => 3, 'price' => $priceB],
]);
credit_assign($db, $orderMixed, $driverId, $date, 1);
$itemIds = $db->prepare(
    'SELECT id, product_id FROM daily_order_items WHERE daily_order_id = ? ORDER BY id ASC'
);
$itemIds->execute([$orderMixed]);
$mixedLines = $itemIds->fetchAll(PDO::FETCH_ASSOC);
assert_eq($productA, (int)$mixedLines[0]['product_id'], 'first line is product A (stable insert order)');
assert_eq($productB, (int)$mixedLines[1]['product_id'], 'second line is product B');

$mixed = credit_confirm($db, $orderMixed, 8, 3, 0.00);
assert_eq(5, (int)$mixed['billable_pieces'], 'mixed-order billable is 8 delivered minus 3 credits');
$alloc = bakery_inventory_allocate_delivery_credits($db, $orderMixed, 3);
assert_eq(0, (int)$alloc['remaining'], 'all header credits allocated to lines');
assert_eq(3, (int)$alloc['lines'][0]['credit_quantity'], 'first line (id ASC) takes credits up to delivered qty');
assert_eq(0, (int)$alloc['lines'][1]['credit_quantity'], 'later line is untouched when the first line can absorb the credits');
assert_eq(3, (int)($alloc['by_product'][$productA] ?? 0), 'product A receives the three credited units');
assert_eq(0, (int)($alloc['by_product'][$productB] ?? 0), 'product B receives none under the documented rule');
assert_eq(3, credit_available($db, $date, $productA), 'FG return lands on the allocated product');
assert_eq(0, credit_available($db, $date, $productB), 'unallocated mixed-order SKU is not credited');

$overflowAlloc = bakery_inventory_allocate_delivery_credits($db, $orderMixed, 6);
assert_eq(5, (int)$overflowAlloc['lines'][0]['credit_quantity'], 'first line cannot take more than its delivered pieces');
assert_eq(1, (int)$overflowAlloc['lines'][1]['credit_quantity'], 'remainder continues to the next line in id order');

credit_wipe_date($db, $date);

echo "\n=== Summary ===\n";
echo "Passed: {$GLOBALS['TEST_PASS']}\n";
echo "Failed: {$GLOBALS['TEST_FAIL']}\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);

function assert_float_near($expected, $actual, $message, $epsilon = 0.01) {
    assert_true(abs((float)$expected - (float)$actual) <= $epsilon, $message);
}
