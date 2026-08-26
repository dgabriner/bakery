<?php
/**
 * Driver invoice must use store default pan dulce / catalog standard price
 * when order lines were saved at $0 — drivers should not be blocked.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';

// Prefer a fresh demo-fixture DB when no production snapshot is present.
$nightly = $root . '/storage/dumps/nightly';
$snapshots = glob($nightly . '/live_*.sql.gz') ?: [];
if ($snapshots) {
    bakery_reset_isolated_test_db($root);
} else {
    putenv('USE_PROD_DB=false');
    $_ENV['USE_PROD_DB'] = 'false';
    $_SERVER['USE_PROD_DB'] = 'false';
    putenv('DB_NAME=bakerysf_test');
    $_ENV['DB_NAME'] = 'bakerysf_test';
    $_SERVER['DB_NAME'] = 'bakerysf_test';
}

define('ACCESS_ALLOWED', true);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/complete_delivery.php';
require_once $root . '/includes/customer_portal.php';

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

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

$customerId = (int)$db->query(
    "SELECT id FROM customers WHERE default_pan_dulce_price IS NOT NULL AND default_pan_dulce_price > 0 ORDER BY id LIMIT 1"
)->fetchColumn();
$assert($customerId > 0, 'fixture customer with default pan dulce price');

$storePrice = (float)$db->query(
    "SELECT default_pan_dulce_price FROM customers WHERE id = {$customerId}"
)->fetchColumn();
$assert($storePrice > 0, 'store default pan dulce price is positive');

$productId = (int)$db->query(
    "SELECT p.id
     FROM products p
     JOIN dough_types dt ON dt.id = p.dough_type_id
     JOIN product_lines pl ON pl.id = dt.product_line_id
     WHERE pl.name = 'Pan Dulce'
     ORDER BY p.id LIMIT 1"
)->fetchColumn();
$assert($productId > 0, 'pan dulce product exists');

$date = '2099-12-15';
$db->prepare('DELETE doi FROM daily_order_items doi INNER JOIN daily_orders do ON do.id = doi.daily_order_id WHERE do.customer_id = ? AND do.order_date = ?')
    ->execute([$customerId, $date]);
$db->prepare('DELETE FROM daily_orders WHERE customer_id = ? AND order_date = ?')
    ->execute([$customerId, $date]);

$db->prepare("INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, 'pending', 0)")
    ->execute([$customerId, $date]);
$orderId = (int)$db->lastInsertId();
$assert($orderId > 0, 'daily order created');

// Zero-priced historical line — the failure mode drivers hit on invoice.
$db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
     VALUES (?, ?, 12, 0, 0)'
)->execute([$orderId, $productId]);

$preview = bakery_delivery_invoice($db, $orderId);
$assert(!bakery_delivery_pricing_missing($preview), 'preview does not report pricing_missing');
$assert(abs((float)$preview['average_price'] - $storePrice) < 0.005, 'preview average uses store default pan dulce price');
$assert(abs((float)$preview['items'][0]['unit_price'] - $storePrice) < 0.005, 'preview line uses store default pan dulce price');

$summary = bakery_delivery_summary($db, $orderId);
$assert($summary['pricing_missing'] === false, 'summary pricing_missing is false after repair');
$assert(abs((float)$summary['average_price'] - $storePrice) < 0.005, 'summary average uses store default');

$saved = (float)$db->query(
    "SELECT unit_price FROM daily_order_items WHERE daily_order_id = {$orderId} LIMIT 1"
)->fetchColumn();
$assert(abs($saved - $storePrice) < 0.005, 'repair persists store default onto the line');

// Confirm without a driver-entered price_per_piece.
$result = bakery_confirm_delivery($db, $orderId, 12, 0, ['amount_collected' => 12 * $storePrice]);
$assert(!empty($result['success']), 'confirm succeeds without manual price entry');
$assert(abs((float)$result['price_per_piece'] - $storePrice) < 0.005, 'confirmed price_per_piece is store default');

// Resolve helper: blank catalog + store default still prices the line.
$resolved = bakery_resolve_customer_price($db, [
    'id' => $customerId,
    'pricing_tier' => 'retail',
    'default_pan_dulce_price' => $storePrice,
], [
    'id' => $productId,
    'price' => 0,
    'product_line_name' => 'Pan Dulce',
]);
$assert(abs($resolved - $storePrice) < 0.005, 'resolve_customer_price prefers store default over zero catalog');

$resolvedMissingLine = bakery_resolve_customer_price($db, [
    'id' => $customerId,
    'pricing_tier' => 'retail',
    'default_pan_dulce_price' => $storePrice,
], [
    'id' => $productId,
    'price' => 0,
    'product_line_name' => '',
]);
$assert(abs($resolvedMissingLine - $storePrice) < 0.005, 'resolve_customer_price falls back to store default when product line missing');

// Standard-pricing store (no custom default): zero lines inherit catalog price.
$standardCustomerId = (int)$db->query(
    "SELECT id FROM customers
     WHERE default_pan_dulce_price IS NULL OR default_pan_dulce_price = '' OR default_pan_dulce_price = 0
     ORDER BY id LIMIT 1"
)->fetchColumn();
$assert($standardCustomerId > 0, 'fixture customer on standard pricing');
$catalogPrice = (float)$db->query("SELECT price FROM products WHERE id = {$productId}")->fetchColumn();
$assert($catalogPrice > 0, 'pan dulce catalog price is positive');

$stdDate = '2099-12-16';
$db->prepare('DELETE doi FROM daily_order_items doi INNER JOIN daily_orders do ON do.id = doi.daily_order_id WHERE do.customer_id = ? AND do.order_date = ?')
    ->execute([$standardCustomerId, $stdDate]);
$db->prepare('DELETE FROM daily_orders WHERE customer_id = ? AND order_date = ?')
    ->execute([$standardCustomerId, $stdDate]);
$db->prepare("INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, 'pending', 0)")
    ->execute([$standardCustomerId, $stdDate]);
$stdOrderId = (int)$db->lastInsertId();
$db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
     VALUES (?, ?, 10, 0, 0)'
)->execute([$stdOrderId, $productId]);

$stdPreview = bakery_delivery_invoice($db, $stdOrderId);
$assert(!bakery_delivery_pricing_missing($stdPreview), 'standard store preview is priced from catalog');
$assert(abs((float)$stdPreview['average_price'] - $catalogPrice) < 0.005, 'standard store uses catalog pan dulce price');

$stdResult = bakery_confirm_delivery($db, $stdOrderId, 10, 0, [
    'amount_collected' => 10 * $catalogPrice,
]);
$assert(!empty($stdResult['success']), 'standard store confirm succeeds without manual price');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
