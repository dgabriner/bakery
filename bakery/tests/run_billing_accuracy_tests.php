<?php
/**
 * Billing header vs lines, and COD blocked when Square already has the stop.
 * Local bakerysf_test only. No mail, SMS, or live Square call.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';

/** @var PDO $db */
$db = require __DIR__ . '/harness.php';
bakery_assert_local_test_target($db);

require_once $root . '/includes/billing.php';
require_once $root . '/includes/billing_header.php';
require_once $root . '/includes/cod_square_guard.php';
require_once $root . '/includes/route_manager_cash.php';
require_once $root . '/complete_delivery.php';

function accuracy_float(float $expected, float $actual, string $message): void
{
    assert_true(abs($expected - $actual) < 0.005, $message . " (expected={$expected} actual={$actual})");
}

echo "Header from booked lines\n";
$lines = [
    ['line_total' => 10.50, 'unit_price' => 10.50, 'quantity' => 1, 'is_no_charge' => 0, 'price' => 99.00],
    ['line_total' => 4.00, 'unit_price' => 4.00, 'quantity' => 1, 'is_no_charge' => 1, 'price' => 4.00],
    ['line_total' => 1.25, 'unit_price' => 1.25, 'quantity' => 1, 'catalog_price' => 8.00],
];
accuracy_float(11.75, bakery_billing_header_from_lines($lines), 'no-charge line is $0 and catalog price is ignored');

$stored = bakery_billing_stored_header_amount([
    'total_amount' => 40.00,
    'delivery_order_total' => 30.00,
]);
accuracy_float(30.00, $stored, 'confirmed snapshot is the stored header when it is set');
$openStored = bakery_billing_stored_header_amount([
    'total_amount' => 12.00,
    'delivery_order_total' => null,
]);
accuracy_float(12.00, $openStored, 'open order header is total_amount');

$mismatch = bakery_billing_header_compare(
    ['total_amount' => 69.11, 'delivery_order_total' => 69.11],
    [
        ['line_total' => 70.00, 'is_no_charge' => 0],
        ['line_total' => 6.19, 'is_no_charge' => 1],
    ]
);
assert_true(!empty($mismatch['header_mismatch']), 'stored header that differs from chargeable lines is a mismatch');
accuracy_float(69.11, (float)$mismatch['stored_header_amount'], 'mismatch keeps the stored header');
accuracy_float(70.00, (float)$mismatch['line_header_amount'], 'mismatch line total drops the no-charge line');

$match = bakery_billing_header_compare(
    ['total_amount' => 11.75, 'delivery_order_total' => 11.75],
    $lines
);
assert_true(empty($match['header_mismatch']), 'header that equals chargeable lines is not a mismatch');

$flag = bakery_billing_header_mismatch_flag([
    'header_mismatch' => true,
    'stored_header_amount' => 69.11,
    'line_header_amount' => 76.19,
]);
assert_true(strpos($flag, '69.11') !== false && strpos($flag, '76.19') !== false, 'invoice list flag shows both amounts');
assert_eq('', bakery_billing_header_mismatch_flag(['header_mismatch' => false]), 'matching invoice has no flag text');

echo "Square prepaid blocks COD cash\n";
assert_true(bakery_stop_square_prepaid(['square_invoice_id' => 'inv_123']), 'square_invoice_id marks the stop prepaid');
assert_true(bakery_stop_square_prepaid(['square_invoice_id' => '', 'square_status' => 'PAID']), 'a paid Square tender marks the stop prepaid');
assert_true(bakery_stop_square_prepaid(['square_paid_at' => '2026-10-01 08:00:00']), 'square_paid_at marks the stop prepaid');
assert_true(!bakery_stop_square_prepaid(['square_invoice_id' => '', 'square_status' => 'UNPAID']), 'an unpaid stop with no Square id is not prepaid');
assert_true(bakery_cod_cash_blocked(['square_invoice_id' => 'inv_123']), 'prepaid stop blocks COD cash');
assert_true(!bakery_cod_cash_blocked([
    'square_invoice_id' => 'inv_123',
    'cod_square_override_reason' => 'Customer paid cash after the Square link failed',
]), 'a logged override unblocks COD cash');

$cash = route_manager_compute_cash_summary([
    ['payment_collection' => 'cod', 'delivery_status' => 'pending', 'total_amount' => 18.00, 'square_prepaid' => true],
    ['payment_collection' => 'cod', 'delivery_status' => 'pending', 'total_amount' => 7.00],
    [
        'payment_collection' => 'cod',
        'delivery_status' => 'pending',
        'total_amount' => 5.00,
        'square_prepaid' => true,
        'cod_override_reason' => 'Manager collected at the door',
    ],
]);
assert_eq(2, (int)$cash['cod_stop_count'], 'prepaid stop is left out of COD cash expected');
accuracy_float(12.00, (float)$cash['expected_remaining'], 'prepaid stop is not in the COD cash remaining');
assert_eq(1, (int)$cash['prepaid_stop_count'], 'prepaid stops are counted for the route');

echo "Report lists mismatches and does not rewrite them\n";
$pricedId = (int)$db->query(
    "SELECT p.id FROM products p WHERE p.price > 0 ORDER BY p.id LIMIT 1"
)->fetchColumn();
assert_true($pricedId > 0, 'a priced product exists');
$unit = (float)$db->query('SELECT price FROM products WHERE id = ' . $pricedId)->fetchColumn();
$secondId = (int)$db->query(
    'SELECT id FROM products WHERE id <> ' . $pricedId . ' ORDER BY id LIMIT 1'
)->fetchColumn();
if ($secondId <= 0) {
    $doughId = (int)$db->query('SELECT dough_type_id FROM products WHERE id = ' . $pricedId)->fetchColumn();
    $db->prepare(
        'INSERT INTO products (name, dough_type_id, price, weight_grams, description)
         VALUES (?, ?, 1.00, 40, ?)'
    )->execute(['__accuracy_second', $doughId > 0 ? $doughId : 1, 'second line for header mismatch']);
    $secondId = (int)$db->lastInsertId();
}
assert_true($secondId > 0 && $secondId !== $pricedId, 'a second product exists for the no-charge line');

$db->prepare(
    'INSERT INTO customers (name, zone, email, address, phone, payment_collection, is_active)
     VALUES (?, ?, ?, ?, ?, ?, 1)'
)->execute([
    '__accuracy_cod_' . substr(uniqid('', true), -8),
    'North Zone',
    'header-mismatch-' . substr(uniqid('', true), -8) . '@sourflour.test',
    '1 Accuracy Street',
    '555-0101',
    'cod',
]);
$customerId = (int)$db->lastInsertId();
$today = date('Y-m-d');

$db->prepare(
    "INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, 'pending', 0)"
)->execute([$customerId, $today]);
$orderId = (int)$db->lastInsertId();
$db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total, is_no_charge)
     VALUES (?, ?, 2, ?, ?, 0)'
)->execute([$orderId, $pricedId, $unit, round(2 * $unit, 2)]);
$db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total, is_no_charge, no_charge_reason)
     VALUES (?, ?, 1, 3.00, 3.00, 1, ?)'
)->execute([$orderId, $secondId, 'sample']);

$recomputed = bakery_daily_order_recompute_total($db, $orderId);
accuracy_float(round(2 * $unit, 2), $recomputed, 'open header recomputes from chargeable lines only');

$bookedHeader = round((2 * $unit) + 5.00, 2);
$db->prepare(
    'UPDATE daily_orders
     SET total_amount = ?, delivery_order_total = ?, delivery_confirmed_at = NOW(), status = ?
     WHERE id = ?'
)->execute([$bookedHeader, $bookedHeader, 'delivered', $orderId]);
$afterGuard = bakery_daily_order_recompute_total($db, $orderId);
accuracy_float($bookedHeader, $afterGuard, 'recompute does not rewrite a confirmed invoice header');

$before = $db->prepare('SELECT total_amount, delivery_order_total FROM daily_orders WHERE id = ?');
$before->execute([$orderId]);
$beforeRow = $before->fetch(PDO::FETCH_ASSOC);

$report = bakery_billing_header_mismatch_rows($db);
$found = null;
foreach ($report as $row) {
    if ((int)$row['id'] === $orderId) {
        $found = $row;
        break;
    }
}
assert_true(is_array($found), 'mismatch report lists the confirmed invoice');
accuracy_float($bookedHeader, (float)$found['stored_header_amount'], 'report stored header is the booked snapshot');
accuracy_float(round(2 * $unit, 2), (float)$found['line_header_amount'], 'report line total ignores the no-charge line');

$after = $db->prepare('SELECT total_amount, delivery_order_total FROM daily_orders WHERE id = ?');
$after->execute([$orderId]);
$afterRow = $after->fetch(PDO::FETCH_ASSOC);
accuracy_float((float)$beforeRow['total_amount'], (float)$afterRow['total_amount'], 'report does not change total_amount');
accuracy_float((float)$beforeRow['delivery_order_total'], (float)$afterRow['delivery_order_total'], 'report does not change delivery_order_total');

$db->prepare('UPDATE daily_orders SET square_invoice_id = ? WHERE id = ?')->execute(['sq_accuracy_1', $orderId]);
$threw = false;
try {
    bakery_confirm_delivery($db, $orderId, 2, 0, ['amount_collected' => 12.00]);
} catch (Throwable $e) {
    $threw = stripos($e->getMessage(), 'prepaid') !== false || stripos($e->getMessage(), 'Square') !== false;
}
assert_true($threw, 'COD cash is refused when the stop has a Square invoice');

$driverThrew = false;
try {
    bakery_cod_record_square_override($db, $orderId, 'Driver tried', ['id' => 1, 'role_slug' => 'driver']);
} catch (Throwable $e) {
    $driverThrew = stripos($e->getMessage(), 'manager') !== false;
}
assert_true($driverThrew, 'a driver cannot override the Square prepaid block');
$reasonStmt = $db->prepare('SELECT cod_square_override_reason FROM daily_orders WHERE id = ?');
$reasonStmt->execute([$orderId]);
$storedReason = $reasonStmt->fetchColumn();
assert_true($storedReason === null || $storedReason === false || $storedReason === '', 'failed override does not store a reason');

bakery_cod_record_square_override($db, $orderId, 'Square link was voided at the door', [
    'id' => 0,
    'role_slug' => 'manager',
]);
$reasonStmt->execute([$orderId]);
assert_eq('Square link was voided at the door', (string)$reasonStmt->fetchColumn(), 'manager override stores the reason');
$logged = $db->prepare(
    "SELECT COUNT(*) FROM operational_events
     WHERE daily_order_id = ? AND event_type LIKE '%cod_square_override%'"
);
$logged->execute([$orderId]);
assert_true((int)$logged->fetchColumn() > 0, 'manager override is written to the operational log');

$stillHeader = $db->prepare('SELECT total_amount, delivery_order_total FROM daily_orders WHERE id = ?');
$stillHeader->execute([$orderId]);
$stillRow = $stillHeader->fetch(PDO::FETCH_ASSOC);
accuracy_float($bookedHeader, (float)$stillRow['total_amount'], 'override does not rewrite the invoice header');
accuracy_float($bookedHeader, (float)$stillRow['delivery_order_total'], 'override does not rewrite the delivery snapshot');

$panel = (string)file_get_contents($root . '/includes/billing_panel_invoices.php');
assert_true(strpos($panel, 'bakery_billing_header_mismatch_flag') !== false, 'invoice list renders the mismatch flag');
$reportPanel = (string)file_get_contents($root . '/includes/billing_panel_header_mismatch.php');
assert_true(strpos($reportPanel, 'bakery_billing_header_mismatch_rows') !== false, 'manager report reads the mismatch list');
assert_true(stripos($reportPanel, 'UPDATE ') === false, 'manager report file does not write invoices');

$driverJs = (string)file_get_contents($root . '/includes/driver_delivery.js');
assert_true(strpos($driverJs, 'square_prepaid') !== false, 'driver COD entry knows about Square prepaid');
$routeJs = (string)file_get_contents($root . '/includes/route_manager.js');
assert_true(strpos($routeJs, 'square_prepaid') !== false, 'Route Manager shows Square prepaid stops');

echo "\n{$GLOBALS['TEST_PASS']} passed, {$GLOBALS['TEST_FAIL']} failed\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
