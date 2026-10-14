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
require_once $root . '/includes/billing_cod_day.php';
require_once $root . '/includes/payment_terms.php';
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

echo "Payment terms decide the path\n";
assert_eq('cod', bakery_payment_terms_normalize(''), 'blank terms default to COD');
assert_eq('cod', bakery_payment_terms_normalize('COD'), 'COD terms stay COD');
assert_eq('invoice', bakery_payment_terms_normalize('signature'), 'legacy signature accounts are invoice terms');
assert_eq('invoice', bakery_payment_terms_normalize('invoice'), 'invoice terms stay invoice');
assert_eq('check', bakery_payment_terms_normalize('check'), 'check terms stay check');
assert_true(bakery_payment_terms_is_cod('cod'), 'COD is the cash path');
assert_true(!bakery_payment_terms_is_cod('check'), 'check is not the cash path');
assert_true(bakery_payment_terms_is_invoice_path('invoice'), 'invoice accounts stay on the invoice path');
assert_true(bakery_payment_terms_is_invoice_path('check'), 'check accounts stay on the invoice path');
assert_true(!bakery_payment_terms_is_invoice_path('cod'), 'COD does not take the invoice path');
$checkPair = bakery_customer_payment_terms_from_post(['payment_terms' => 'check']);
assert_eq('check', $checkPair['payment_terms'], 'customer form stores check terms');
assert_eq('signature', $checkPair['payment_collection'], 'check keeps the legacy non-cash collection flag');
$codPair = bakery_customer_payment_terms_from_post(['payment_collection' => 'cod']);
assert_eq('cod', $codPair['payment_terms'], 'a COD post stays COD');
$panSql = bakery_payment_terms_pan_dulce_collection_sql($db);
assert_true(strpos($panSql, "payment_terms IN ('invoice', 'check')") !== false, 'invoice and check terms are not forced onto COD');

echo "Confirmed lines use delivered quantity and credits\n";
$confirmedOrder = [
    'status' => 'delivered',
    'delivery_confirmed_at' => '2026-08-20 15:00:00',
    'credits_taken_back' => 0,
];
accuracy_float(10.00, bakery_billing_line_chargeable_amount([
    'quantity' => 4,
    'delivered_quantity' => 2,
    'unit_price' => 5.00,
    'line_total' => 20.00,
    'is_no_charge' => 0,
], $confirmedOrder), 'a confirmed stop prices the line from delivered quantity');
accuracy_float(-6.00, bakery_billing_line_chargeable_amount([
    'quantity' => 2,
    'delivered_quantity' => 2,
    'unit_price' => 3.00,
    'line_total' => -6.00,
    'is_store_credit' => 1,
], $confirmedOrder), 'a store credit line stays negative');
$builtReasons = bakery_billing_header_mismatch_reasons($confirmedOrder, [[
    'quantity' => 4,
    'delivered_quantity' => 2,
    'unit_price' => 5.00,
    'line_total' => 20.00,
    'product_name' => 'Country loaf',
    'is_no_charge' => 0,
    'is_store_credit' => 0,
]]);
assert_true(in_array('built_before_delivery', $builtReasons, true), 'ordered quantity on a confirmed stop is flagged');
$creditReasons = bakery_billing_header_mismatch_reasons([
    'status' => 'delivered',
    'delivery_confirmed_at' => '2026-08-20 15:00:00',
    'credits_taken_back' => 2,
], [[
    'quantity' => 4,
    'delivered_quantity' => 4,
    'unit_price' => 5.00,
    'line_total' => 20.00,
    'product_name' => 'Country loaf',
    'is_store_credit' => 0,
]]);
assert_true(in_array('credit_without_line', $creditReasons, true), 'a header credit with no line is named');
assert_true(!in_array('unexplained', $creditReasons, true), 'a missing credit line is not called unexplained');
$openReasons = bakery_billing_header_mismatch_reasons([
    'status' => 'delivered',
    'delivery_confirmed_at' => '2026-08-24 15:00:00',
    'credits_taken_back' => 0,
], [[
    'quantity' => 2,
    'delivered_quantity' => 2,
    'unit_price' => 10.00,
    'line_total' => 20.00,
    'product_name' => 'Country loaf',
    'is_store_credit' => 0,
]]);
assert_eq(['unexplained'], $openReasons, 'a residual gap with no known cause is unexplained');
$missingReasons = bakery_billing_header_mismatch_reasons($confirmedOrder, [[
    'quantity' => 1,
    'delivered_quantity' => 1,
    'unit_price' => 4.00,
    'line_total' => 4.00,
    'product_name' => '',
    'product_missing' => 1,
]]);
assert_true(in_array('product_missing', $missingReasons, true), 'a line whose product is gone is flagged');

accuracy_float(10.00, bakery_billing_cod_expected_amount([
    'payment_terms' => 'cod',
    'total_amount' => 10.00,
    'delivery_order_total' => 10.00,
    'credits_taken_back' => 2,
], [[
    'line_total' => 20.00,
    'is_store_credit' => 0,
]]), 'historical COD expected cash stays the stored header when the credit has no line');
accuracy_float(14.00, bakery_billing_cod_expected_amount([
    'payment_terms' => 'cod',
    'status' => 'delivered',
    'delivery_confirmed_at' => '2026-08-20 15:00:00',
    'delivery_order_total' => 20.00,
], [
    [
        'quantity' => 4,
        'delivered_quantity' => 4,
        'unit_price' => 5.00,
        'line_total' => 20.00,
        'is_store_credit' => 0,
    ],
    [
        'line_total' => -6.00,
        'unit_price' => 3.00,
        'is_store_credit' => 1,
    ],
]), 'a credit line reduces the COD cash the driver should collect');
accuracy_float(0.00, bakery_billing_cod_expected_amount([
    'payment_terms' => 'invoice',
    'delivery_order_total' => 20.00,
], [['line_total' => 20.00]]), 'invoice terms do not create driver cash');
accuracy_float(0.00, bakery_billing_cod_expected_amount([
    'payment_terms' => 'check',
    'delivery_order_total' => 20.00,
], [['line_total' => 20.00]]), 'check terms do not create driver cash');
accuracy_float(0.00, bakery_billing_cod_expected_amount([
    'payment_terms' => 'cod',
    'delivery_order_total' => 20.00,
    'square_prepaid' => true,
], [['line_total' => 20.00]]), 'Square prepaid stays out of COD expected cash');

echo "COD day, invoice path, and lines that must stay visible\n";
$suffix = substr(uniqid('', true), -8);
$codDate = '2019-03-03';
$db->prepare('INSERT INTO drivers (name) VALUES (?)')->execute(['Accuracy Driver ' . $suffix]);
$driverId = (int)$db->lastInsertId();
$driverName = 'Accuracy Driver ' . $suffix;
assert_true($driverId > 0, 'a driver exists for the COD day');
$termsReady = function_exists('column_exists') && column_exists($db, 'customers', 'payment_terms');
assert_true($termsReady, 'customers.payment_terms exists');

$insertCustomer = function (string $name, string $terms) use ($db, $suffix): int {
    $email = strtolower(str_replace(' ', '-', $name)) . '-' . $suffix . '@sourflour.test';
    $collection = $terms === 'cod' ? 'cod' : 'signature';
    $db->prepare(
        'INSERT INTO customers (name, zone, email, address, phone, payment_collection, payment_terms, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
    )->execute([$name . ' ' . $suffix, 'North Zone', $email, '1 Accuracy Street', '555-0199', $collection, $terms]);
    return (int)$db->lastInsertId();
};

$codCustomer = $insertCustomer('Harbor COD', 'cod');
$prepaidCustomer = $insertCustomer('Prepaid COD', 'cod');
$invoiceCustomer = $insertCustomer('Mission Invoice', 'invoice');
$builtCustomer = $insertCustomer('Built Before', 'invoice');
$checkCustomer = $insertCustomer('Check Counter', 'check');
$goldenCustomer = $insertCustomer('Golden Gate', 'invoice');
$convenCustomer = $insertCustomer('Conveniencie', 'invoice');

$insertOrder = function (int $customerId, float $header, string $status = 'delivered') use ($db, $codDate, $driverId): int {
    $db->prepare(
        'INSERT INTO daily_orders (customer_id, order_date, status, total_amount, delivery_order_total, delivery_confirmed_at, driver_id, credits_taken_back)
         VALUES (?, ?, ?, ?, ?, NOW(), ?, 0)'
    )->execute([$customerId, $codDate, $status, $header, $header, $driverId]);
    return (int)$db->lastInsertId();
};

$creditOrder = $insertOrder($codCustomer, 10.00);
$db->prepare(
    'UPDATE daily_orders SET credits_taken_back = 2, amount_collected = 10.00 WHERE id = ?'
)->execute([$creditOrder]);
$db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, delivered_quantity, unit_price, line_total, is_no_charge)
     VALUES (?, ?, 4, 4, 5.00, 20.00, 0)'
)->execute([$creditOrder, $pricedId]);
$creditBefore = $db->prepare('SELECT total_amount, delivery_order_total FROM daily_orders WHERE id = ?');
$creditBefore->execute([$creditOrder]);
$creditBeforeRow = $creditBefore->fetch(PDO::FETCH_ASSOC);

$prepaidOrder = $insertOrder($prepaidCustomer, 8.00);
$db->prepare('UPDATE daily_orders SET square_invoice_id = ?, square_status = ? WHERE id = ?')
    ->execute(['sq_cod_day_prepaid', 'PAID', $prepaidOrder]);
$db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, delivered_quantity, unit_price, line_total)
     VALUES (?, ?, 1, 1, 8.00, 8.00)'
)->execute([$prepaidOrder, $pricedId]);

$invoiceOrder = $insertOrder($invoiceCustomer, 30.00);
$db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, delivered_quantity, unit_price, line_total)
     VALUES (?, ?, 3, 3, 10.00, 30.00)'
)->execute([$invoiceOrder, $pricedId]);
$checkOrder = $insertOrder($checkCustomer, 12.00);
$db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, delivered_quantity, unit_price, line_total)
     VALUES (?, ?, 2, 2, 6.00, 12.00)'
)->execute([$checkOrder, $pricedId]);

$goldenOrder = $insertOrder($goldenCustomer, 2.06);
$db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, delivered_quantity, unit_price, line_total)
     VALUES (?, ?, 2, 2, 10.00, 20.00)'
)->execute([$goldenOrder, $pricedId]);
$convenOrder = $insertOrder($convenCustomer, 2.06);
$db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, delivered_quantity, unit_price, line_total)
     VALUES (?, ?, 2, 2, 10.00, 20.00)'
)->execute([$convenOrder, $pricedId]);

$builtOrder = $insertOrder($builtCustomer, 20.00);
$db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, delivered_quantity, unit_price, line_total)
     VALUES (?, ?, 4, 2, 5.00, 20.00)'
)->execute([$builtOrder, $pricedId]);

$day = bakery_billing_cod_driver_day($db, $codDate);
$dayRow = null;
foreach ($day as $row) {
    if ((int)$row['driver_id'] === $driverId) {
        $dayRow = $row;
        break;
    }
}
assert_true(is_array($dayRow), 'COD day lists the driver who has cash stops');
assert_eq($driverName, (string)$dayRow['driver_name'], 'COD day names the driver');
accuracy_float(10.00, (float)$dayRow['expected'], 'COD day expected cash uses the stored header when the credit has no line');
accuracy_float(10.00, (float)$dayRow['collected'], 'COD day collected cash is what the driver recorded');
assert_true((int)$dayRow['stop_count'] >= 1, 'COD day counts the cash stops');

$invoiceIds = array_map(static function ($row) {
    return (int)$row['id'];
}, bakery_billing_query_orders($db, [
    'start_date' => $codDate,
    'end_date' => $codDate,
    'collection' => 'invoice',
    'status' => 'all',
]));
assert_true(in_array($invoiceOrder, $invoiceIds, true), 'invoice terms stay on the invoice list');
assert_true(in_array($checkOrder, $invoiceIds, true), 'check terms stay on the invoice list');
assert_true(!in_array($creditOrder, $invoiceIds, true), 'COD stops are not invoices');

$reportAfter = bakery_billing_header_mismatch_rows($db);
$byId = [];
foreach ($reportAfter as $row) {
    $byId[(int)$row['id']] = $row;
}
assert_true(isset($byId[$goldenOrder]), 'Golden Gate stays on the mismatch report');
assert_true(isset($byId[$convenOrder]), 'Conveniencie stays on the mismatch report');
accuracy_float(17.94, abs((float)$byId[$goldenOrder]['line_header_amount'] - (float)$byId[$goldenOrder]['stored_header_amount']), 'Golden Gate difference is 17.94');
accuracy_float(17.94, abs((float)$byId[$convenOrder]['line_header_amount'] - (float)$byId[$convenOrder]['stored_header_amount']), 'Conveniencie difference is 17.94');
assert_true(in_array('unexplained', $byId[$goldenOrder]['reasons'], true), 'Golden Gate gap is unexplained');
assert_true(in_array('unexplained', $byId[$convenOrder]['reasons'], true), 'Conveniencie gap is unexplained');
assert_true(in_array('credit_without_line', $byId[$creditOrder]['reasons'], true), 'the historical credit order is named and not rewritten');
assert_true(in_array('built_before_delivery', $byId[$builtOrder]['reasons'], true), 'the ordered-quantity invoice is flagged');
$creditBefore->execute([$creditOrder]);
$creditAfterRow = $creditBefore->fetch(PDO::FETCH_ASSOC);
accuracy_float((float)$creditBeforeRow['total_amount'], (float)$creditAfterRow['total_amount'], 'the report does not rewrite a historical credit header');
accuracy_float((float)$creditBeforeRow['delivery_order_total'], (float)$creditAfterRow['delivery_order_total'], 'the report does not rewrite a historical credit snapshot');
$goldenHeader = $db->prepare('SELECT total_amount, delivery_order_total FROM daily_orders WHERE id = ?');
$goldenHeader->execute([$goldenOrder]);
$goldenHeaderRow = $goldenHeader->fetch(PDO::FETCH_ASSOC);
accuracy_float(2.06, (float)$goldenHeaderRow['total_amount'], 'Golden Gate stored header stays booked');
accuracy_float(2.06, (float)$goldenHeaderRow['delivery_order_total'], 'Golden Gate snapshot stays booked');

$freshCustomer = $insertCustomer('Fresh Confirm', 'cod');
$db->prepare(
    "INSERT INTO daily_orders (customer_id, order_date, status, total_amount, driver_id) VALUES (?, ?, 'pending', 0, ?)"
)->execute([$freshCustomer, $codDate, $driverId]);
$freshOrder = (int)$db->lastInsertId();
$db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
     VALUES (?, ?, 4, 5.00, 20.00)'
)->execute([$freshOrder, $pricedId]);
$confirmed = bakery_confirm_delivery($db, $freshOrder, 4, 1, ['amount_collected' => 15.00]);
assert_true(!empty($confirmed['success']), 'a new COD stop confirms');
$creditLine = $db->prepare(
    'SELECT line_total, is_store_credit FROM daily_order_items WHERE daily_order_id = ? AND is_store_credit = 1'
);
$creditLine->execute([$freshOrder]);
$creditLineRow = $creditLine->fetch(PDO::FETCH_ASSOC);
assert_true(is_array($creditLineRow), 'confirm writes a store credit line');
accuracy_float(-5.00, (float)$creditLineRow['line_total'], 'the new credit line is negative');
$freshHeader = $db->prepare('SELECT total_amount, delivery_order_total FROM daily_orders WHERE id = ?');
$freshHeader->execute([$freshOrder]);
$freshHeaderRow = $freshHeader->fetch(PDO::FETCH_ASSOC);
accuracy_float(15.00, (float)$freshHeaderRow['total_amount'], 'the new header matches the lines including the credit');
accuracy_float(15.00, (float)$freshHeaderRow['delivery_order_total'], 'the new snapshot matches the lines including the credit');
$historicalCreditLines = $db->prepare(
    'SELECT COUNT(*) FROM daily_order_items WHERE daily_order_id = ? AND is_store_credit = 1'
);
$historicalCreditLines->execute([$creditOrder]);
assert_eq(0, (int)$historicalCreditLines->fetchColumn(), 'confirming a new stop does not add a credit line to an older order');

$db->prepare('INSERT INTO products (name, price, description) VALUES (?, 4.00, ?)')
    ->execute(['__accuracy_removed_' . $suffix, 'deleted for the billing export']);
$goneProduct = (int)$db->lastInsertId();
$exportCustomer = $insertCustomer('Export Shelf', 'invoice');
$exportOrder = $insertOrder($exportCustomer, 4.00);
$db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, delivered_quantity, unit_price, line_total)
     VALUES (?, ?, 1, 1, 4.00, 4.00)'
)->execute([$exportOrder, $goneProduct]);
$db->exec('SET FOREIGN_KEY_CHECKS=0');
$db->prepare('DELETE FROM products WHERE id = ?')->execute([$goneProduct]);
$db->exec('SET FOREIGN_KEY_CHECKS=1');
$exportRows = bakery_billing_export_rows($db, [
    'start_date' => $codDate,
    'end_date' => $codDate,
    'collection' => 'invoice',
    'confirmed_only' => true,
]);
$exportHit = false;
foreach ($exportRows as $exportRow) {
    if ((int)$exportRow['daily_order_id'] === $exportOrder && (int)$exportRow['product_id'] === $goneProduct) {
        $exportHit = true;
        accuracy_float(4.00, (float)$exportRow['line_total'], 'export keeps the amount for a deleted product');
        assert_true(trim((string)$exportRow['product_name']) !== '', 'export still names a deleted product line');
    }
}
assert_true($exportHit, 'billing export includes a line whose product was deleted');

$center = (string)file_get_contents($root . '/billing_center.php');
assert_true(strpos($center, "?? 'cod'") !== false, 'Billing Center opens on the COD day');
assert_true(strpos($center, 'billing_panel_cod_day.php') !== false, 'Billing Center renders the COD day panel');
$customersPage = (string)file_get_contents($root . '/customers.php');
assert_true(strpos($customersPage, 'payment_terms') !== false, 'customer form stores payment terms');
$loadItems = (string)file_get_contents($root . '/includes/billing.php');
assert_true(strpos($loadItems, 'LEFT JOIN products') !== false, 'billing lines keep rows when the product is gone');

echo "\n{$GLOBALS['TEST_PASS']} passed, {$GLOBALS['TEST_FAIL']} failed\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
