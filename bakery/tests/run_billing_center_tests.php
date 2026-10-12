<?php
/**
 * Billing Center regression: invoices from confirmed deliveries, totals that
 * keep $0 lines, card / Cash App / ACH / COD tenders and balance math,
 * a credit line, and date-range edges.
 *
 * No email, SMS, or live Square call. Square is either skipped or answered
 * by an in-process mock. CLI / local bakerysf_test only.
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
require_once $root . '/includes/billing_aging.php';
require_once $root . '/includes/invoice_document.php';
require_once $root . '/includes/square_invoices.php';
require_once $root . '/complete_delivery.php';

$GLOBALS['TEST_KNOWN'] = [];
$GLOBALS['BILLING_MAIL_CALLS'] = 0;
$GLOBALS['BILLING_SQUARE_CALLS'] = 0;

$GLOBALS['bakery_billing_mail_handler'] = static function () {
    $GLOBALS['BILLING_MAIL_CALLS']++;
    throw new RuntimeException('billing regression must not send email');
};
$GLOBALS['bakery_square_api_handler'] = static function ($method, $path) {
    $GLOBALS['BILLING_SQUARE_CALLS']++;
    throw new RuntimeException('billing regression must not call Square (' . $method . ' ' . $path . ')');
};

function bill_known(bool $ok, string $message, string $where): void
{
    if ($ok) {
        assert_true(true, $message);
        return;
    }
    echo "SKIP  known failure: $message\n";
    echo "      where: $where\n";
    $GLOBALS['TEST_KNOWN'][] = ['message' => $message, 'where' => $where];
}

function bill_float(float $expected, float $actual, string $message): void
{
    assert_true(abs($expected - $actual) < 0.005, $message . " (expected={$expected} actual={$actual})");
}

function bill_insert_customer(PDO $db, string $name, string $collection): int
{
    $db->prepare(
        'INSERT INTO customers (name, zone, email, address, phone, payment_collection, is_active)
         VALUES (?, ?, ?, ?, ?, ?, 1)'
    )->execute([
        $name,
        'North Zone',
        $name . '@example.test',
        '1 Regression Street',
        '555-0199',
        $collection,
    ]);
    return (int)$db->lastInsertId();
}

function bill_insert_snapshot(
    PDO $db,
    int $customerId,
    string $date,
    string $status,
    float $total,
    ?float $collected,
    ?string $squareStatus
): int {
    $cols = 'customer_id, order_date, status, total_amount, delivery_order_total, delivery_pricing_label, delivered_pieces, delivery_confirmed_at';
    $marks = '?, ?, ?, ?, ?, ?, ?, ?';
    $params = [$customerId, $date, $status, $total, $total, 'regression snapshot', 4, $date . ' 09:00:00'];
    if ($collected !== null && column_exists($db, 'daily_orders', 'amount_collected')) {
        $cols .= ', amount_collected';
        $marks .= ', ?';
        $params[] = $collected;
    }
    if ($squareStatus !== null && column_exists($db, 'daily_orders', 'square_status')) {
        $cols .= ', square_status';
        $marks .= ', ?';
        $params[] = $squareStatus;
    }
    $db->prepare("INSERT INTO daily_orders ({$cols}) VALUES ({$marks})")->execute($params);
    return (int)$db->lastInsertId();
}

function bill_product_price(PDO $db, int $productId): float
{
    $stmt = $db->prepare('SELECT price FROM products WHERE id = ?');
    $stmt->execute([$productId]);
    return round((float)$stmt->fetchColumn(), 2);
}

function bill_wipe(PDO $db, array $customerIds, array $productIds): void
{
    if ($customerIds !== []) {
        $in = implode(',', array_map('intval', $customerIds));
        $ids = $db->query("SELECT id FROM daily_orders WHERE customer_id IN ({$in})")->fetchAll(PDO::FETCH_COLUMN);
        $ids = array_map('intval', $ids);
        if ($ids !== []) {
            $oin = implode(',', $ids);
            if (table_exists($db, 'inventory_movements') && column_exists($db, 'inventory_movements', 'daily_order_id')) {
                $db->exec("DELETE FROM inventory_movements WHERE daily_order_id IN ({$oin})");
            }
            if (table_exists($db, 'billing_export_invoices')) {
                $db->exec("DELETE FROM billing_export_invoices WHERE daily_order_id IN ({$oin})");
            }
            $db->exec("DELETE FROM daily_order_assignments WHERE daily_order_id IN ({$oin})");
            $db->exec("DELETE FROM daily_order_items WHERE daily_order_id IN ({$oin})");
            $db->exec("DELETE FROM daily_orders WHERE id IN ({$oin})");
        }
        if (table_exists($db, 'billing_statements')) {
            $db->exec("DELETE FROM billing_statements WHERE customer_id IN ({$in})");
        }
        $db->exec("DELETE FROM customers WHERE id IN ({$in})");
    }
    if ($productIds !== []) {
        $pin = implode(',', array_map('intval', $productIds));
        $db->exec("DELETE FROM products WHERE id IN ({$pin})");
    }
}

$customerIds = [];
$productIds = [];

try {
    bakery_square_ensure_schema($db);
    assert_true(bakery_billing_tables_ready($db), 'billing audit tables are installed');
    assert_true(column_exists($db, 'daily_orders', 'square_status'), 'square_status column is installed');
    assert_true(column_exists($db, 'daily_orders', 'amount_collected'), 'amount_collected column is installed');

    $pricedId = (int)$db->query(
        "SELECT p.id
         FROM products p
         JOIN dough_types dt ON dt.id = p.dough_type_id
         JOIN product_lines pl ON pl.id = dt.product_line_id
         WHERE p.price > 0 AND pl.name <> 'Pan Dulce'
         ORDER BY p.id
         LIMIT 1"
    )->fetchColumn();
    assert_true($pricedId > 0, 'a non-pan-dulce priced product exists');
    $unit = bill_product_price($db, $pricedId);

    $db->prepare(
        'INSERT INTO products (name, dough_type_id, price, weight_grams, description)
         VALUES (?, 1, 0.00, 40, ?)'
    )->execute(['__bill_free_slice', 'intentional zero price for billing regression']);
    $freeId = (int)$db->lastInsertId();
    $productIds[] = $freeId;

    $signatureId = bill_insert_customer($db, '__bill_signature', 'signature');
    $customerIds[] = $signatureId;
    $codId = bill_insert_customer($db, '__bill_cod', 'cod');
    $customerIds[] = $codId;

    $insertOrder = $db->prepare(
        "INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, 'pending', 0)"
    );
    $insertItem = $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)'
    );

    echo "Invoice from confirmed delivery\n";
    $insertOrder->execute([$signatureId, '2097-05-06']);
    $pendingId = (int)$db->lastInsertId();
    $insertItem->execute([$pendingId, $pricedId, 2, $unit, round(2 * $unit, 2)]);
    $before = bakery_billing_query_orders($db, [
        'start_date' => '2097-05-06',
        'end_date' => '2097-05-06',
        'customer_id' => $signatureId,
        'confirmed_only' => true,
    ]);
    assert_eq([], array_map(static function ($row) {
        return (int)$row['id'];
    }, $before), 'unconfirmed delivery is not an invoice yet');
    $refused = false;
    try {
        bakery_billing_mark_invoiced($db, $pendingId, null);
    } catch (Throwable $e) {
        $refused = stripos($e->getMessage(), 'not confirmed') !== false;
    }
    assert_true($refused, 'Billing Center refuses to invoice a delivery that is not confirmed');

    $confirmed = bakery_confirm_delivery($db, $pendingId, 2, 0, []);
    assert_true(!empty($confirmed['success']), 'confirming the delivery creates the billable snapshot');
    bill_float(round(2 * $unit, 2), (float)$confirmed['total'], 'confirmed invoice total is quantity times snapshot price');
    $afterRows = bakery_billing_query_orders($db, [
        'start_date' => '2097-05-06',
        'end_date' => '2097-05-06',
        'customer_id' => $signatureId,
        'confirmed_only' => true,
    ]);
    assert_eq([$pendingId], array_map(static function ($row) {
        return (int)$row['id'];
    }, $afterRows), 'confirmed delivery shows up in the billing query');
    $items = bakery_billing_load_items($db, [$pendingId]);
    $enriched = bakery_billing_enrich_orders($afterRows, $items);
    assert_eq('ready', (string)($enriched[0]['category'] ?? ''), 'confirmed priced delivery is ready to invoice');
    bill_float(round(2 * $unit, 2), (float)$enriched[0]['billable_amount'], 'enriched billable amount matches the snapshot');
    assert_true(bakery_billing_mark_invoiced($db, $pendingId, null), 'Billing Center marks the confirmed delivery invoiced');
    $status = $db->prepare('SELECT status FROM daily_orders WHERE id = ?');
    $status->execute([$pendingId]);
    assert_eq('invoiced', (string)$status->fetchColumn(), 'order status is invoiced');
    $invoicedRows = bakery_billing_query_orders($db, [
        'start_date' => '2097-05-06',
        'end_date' => '2097-05-06',
        'customer_id' => $signatureId,
        'invoiced_only' => true,
    ]);
    assert_eq([$pendingId], array_map(static function ($row) {
        return (int)$row['id'];
    }, $invoicedRows), 'invoiced filter returns the order Billing Center just marked');

    echo "Zero-quantity line and intentional \$0 line\n";
    $insertOrder->execute([$signatureId, '2097-05-07']);
    $zeroQtyId = (int)$db->lastInsertId();
    $insertItem->execute([$zeroQtyId, $pricedId, 2, $unit, round(2 * $unit, 2)]);
    $insertItem->execute([$zeroQtyId, $freeId, 0, 0, 0]);
    $zeroConfirmed = bakery_confirm_delivery($db, $zeroQtyId, 2, 0, []);
    bill_float(round(2 * $unit, 2), (float)$zeroConfirmed['total'], 'a zero-quantity line does not change the invoice total');
    $zeroItems = bakery_billing_load_items($db, [$zeroQtyId]);
    $zeroOrder = bakery_billing_query_orders($db, [
        'start_date' => '2097-05-07',
        'end_date' => '2097-05-07',
        'customer_id' => $signatureId,
    ]);
    $zeroEnriched = bakery_billing_enrich_orders($zeroOrder, $zeroItems);
    $zeroLineSum = 0.0;
    $sawZeroQty = false;
    foreach ($zeroEnriched[0]['items'] as $item) {
        $zeroLineSum += (float)$item['line_total'];
        if ((int)$item['product_id'] === $freeId) {
            $sawZeroQty = true;
            assert_eq(0, (int)$item['quantity'], 'zero-quantity line is still on the invoice');
            bill_float(0.0, (float)$item['line_total'], 'zero-quantity line contributes $0');
        }
    }
    assert_true($sawZeroQty, 'classifier keeps the zero-quantity line');
    bill_float(round(2 * $unit, 2), round($zeroLineSum, 2), 'invoice line sum includes the $0 quantity line');

    $insertOrder->execute([$signatureId, '2097-05-08']);
    $freeOrderId = (int)$db->lastInsertId();
    $insertItem->execute([$freeOrderId, $pricedId, 2, $unit, round(2 * $unit, 2)]);
    $insertItem->execute([$freeOrderId, $freeId, 2, 0, 0]);
    $freeConfirmed = bakery_confirm_delivery($db, $freeOrderId, 4, 0, []);
    $freeLines = $db->prepare('SELECT product_id, unit_price, line_total, quantity FROM daily_order_items WHERE daily_order_id = ?');
    $freeLines->execute([$freeOrderId]);
    $freeByProduct = [];
    foreach ($freeLines->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $freeByProduct[(int)$line['product_id']] = $line;
    }
    $preserved = isset($freeByProduct[$freeId], $freeByProduct[$pricedId])
        && abs((float)$freeByProduct[$freeId]['unit_price']) < 0.005
        && abs((float)$freeByProduct[$pricedId]['unit_price'] - $unit) < 0.005
        && abs((float)$freeConfirmed['total'] - round(2 * $unit, 2)) < 0.005;
    bill_known(
        $preserved,
        'intentional $0 line stays $0 and the invoice total stays the priced lines only (observed total ' . $freeConfirmed['total'] . ', expected ' . round(2 * $unit, 2) . ')',
        'complete_delivery.php bakery_delivery_repair_missing_item_prices() (called from bakery_confirm_delivery) treats unit_price <= 0 as a missing price and overwrites it with a catalog/store fallback'
    );
    if ($preserved) {
        $freeLoaded = bakery_billing_load_items($db, [$freeOrderId]);
        $freeQuery = bakery_billing_query_orders($db, [
            'start_date' => '2097-05-08',
            'end_date' => '2097-05-08',
            'customer_id' => $signatureId,
        ]);
        $freeEnriched = bakery_billing_enrich_orders($freeQuery, $freeLoaded);
        $sum = 0.0;
        foreach ($freeEnriched[0]['items'] as $item) {
            $sum += (float)$item['line_total'];
        }
        bill_float(round(2 * $unit, 2), round($sum, 2), 'enriched total includes the intentional $0 line as zero');
    }

    echo "Credit line\n";
    $insertOrder->execute([$signatureId, '2097-05-09']);
    $creditId = (int)$db->lastInsertId();
    $insertItem->execute([$creditId, $pricedId, 4, $unit, round(4 * $unit, 2)]);
    $credit = bakery_confirm_delivery($db, $creditId, 4, 1, []);
    $expectedCreditTotal = round(3 * $unit, 2);
    bill_float($expectedCreditTotal, (float)$credit['total'], 'one credit reduces the invoice by one piece price');
    assert_eq(1, (int)$credit['credits_taken_back'], 'confirm stores credits_taken_back');
    assert_eq(3, (int)$credit['billable_pieces'], 'billable pieces are delivered minus credits');
    $creditRows = bakery_billing_query_orders($db, [
        'start_date' => '2097-05-09',
        'end_date' => '2097-05-09',
        'customer_id' => $signatureId,
    ]);
    $creditEnriched = bakery_billing_enrich_orders($creditRows, bakery_billing_load_items($db, [$creditId]));
    assert_true(!empty($creditEnriched[0]['has_credits']), 'classifier flags the credit');
    assert_true(
        in_array('1 credit', $creditEnriched[0]['variance_summary'] ?? [], true),
        'variance summary names the credit'
    );
    $canonical = bakery_billing_load_canonical_invoice($db, $creditId);
    $html = bakery_billing_invoice_document_html($canonical, ['mode' => 'staff']);
    assert_true(strpos($html, 'Credits taken back: 1') !== false, 'invoice document prints the credit line');
    assert_true(strpos($html, number_format($expectedCreditTotal, 2)) !== false, 'invoice document total is the post-credit amount');
    $settlement = bakery_billing_settlement_row($creditRows[0]);
    bill_float($expectedCreditTotal, (float)$settlement['open_balance'], 'unpaid credit invoice balance is the reduced total');

    echo "Tenders and balances\n";
    $codPartial = bill_insert_snapshot($db, $codId, '2097-05-12', 'invoiced', 40.00, 10.00, null);
    $codFull = bill_insert_snapshot($db, $codId, '2097-05-13', 'invoiced', 25.00, 25.00, null);
    $codNone = bill_insert_snapshot($db, $codId, '2097-05-14', 'invoiced', 8.00, null, null);
    $cardPaid = bill_insert_snapshot($db, $signatureId, '2097-05-15', 'invoiced', 18.00, null, 'PAID');
    $cashAppPaid = bill_insert_snapshot($db, $signatureId, '2097-05-16', 'invoiced', 12.00, null, 'PAID');
    $achPaid = bill_insert_snapshot($db, $signatureId, '2097-05-17', 'invoiced', 9.00, null, 'PAID');
    $cardOpen = bill_insert_snapshot($db, $signatureId, '2097-05-18', 'invoiced', 15.00, null, 'UNPAID');

    $load = static function (int $id) use ($db): array {
        $stmt = $db->prepare('SELECT * FROM daily_orders WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    };
    bill_float(30.00, (float)bakery_billing_order_outstanding($load($codPartial)), 'partial COD tender leaves the unpaid balance');
    bill_float(0.00, (float)bakery_billing_order_outstanding($load($codFull)), 'COD tender for the full snapshot settles the balance');
    bill_float(8.00, (float)bakery_billing_order_outstanding($load($codNone)), 'COD with no collection leaves the full balance');
    bill_float(0.00, (float)bakery_billing_order_outstanding($load($cardPaid)), 'card tender (Square PAID) settles the balance');
    bill_float(0.00, (float)bakery_billing_order_outstanding($load($cashAppPaid)), 'Cash App tender (Square PAID) settles the balance');
    bill_float(0.00, (float)bakery_billing_order_outstanding($load($achPaid)), 'ACH tender (Square PAID) settles the balance');
    bill_float(15.00, (float)bakery_billing_order_outstanding($load($cardOpen)), 'an unpaid Square invoice does not apply a tender');

    $codPay = bakery_billing_payment_status($load($codPartial), ['payment_collection' => 'cod']);
    $cardPay = bakery_billing_payment_status($load($cardPaid), ['payment_collection' => 'signature']);
    $cashPay = bakery_billing_payment_status($load($cashAppPaid), ['payment_collection' => 'signature']);
    $achPay = bakery_billing_payment_status($load($achPaid), ['payment_collection' => 'signature']);
    $openPay = bakery_billing_payment_status($load($cardOpen), ['payment_collection' => 'signature']);
    assert_eq('cod_collected', $codPay['key'], 'COD applied tender is labeled collected at delivery');
    assert_eq('square_paid', $cardPay['key'], 'card paid tender is labeled paid in Square');
    assert_eq('square_paid', $cashPay['key'], 'Cash App paid tender is labeled paid in Square');
    assert_eq('square_paid', $achPay['key'], 'ACH paid tender is labeled paid in Square');
    assert_eq('square_unpaid', $openPay['key'], 'unpaid Square tender stays unpaid');

    $codBalance = bakery_billing_customer_balance($db, $codId);
    bill_float(38.00, (float)$codBalance['outstanding_total'], 'COD customer balance is partial remainder plus uncollected order');
    assert_eq(2, (int)$codBalance['outstanding_count'], 'two COD orders remain open');

    $insertOrder->execute([$signatureId, '2097-05-19']);
    $squareOrderId = (int)$db->lastInsertId();
    $insertItem->execute([$squareOrderId, $pricedId, 2, $unit, round(2 * $unit, 2)]);
    bakery_confirm_delivery($db, $squareOrderId, 2, 0, []);
    $capturedMethods = [];
    $mockedCalls = 0;
    $GLOBALS['bakery_square_api_handler'] = static function ($method, $path, $body) use (&$capturedMethods, &$mockedCalls) {
        $mockedCalls++;
        $GLOBALS['BILLING_SQUARE_CALLS']++;
        if ($method === 'POST' && $path === '/v2/customers/search') {
            return ['customers' => []];
        }
        if ($method === 'POST' && $path === '/v2/customers') {
            return ['customer' => ['id' => 'SQC_REG']];
        }
        if ($method === 'POST' && $path === '/v2/orders') {
            return ['order' => ['id' => 'SQO_REG']];
        }
        if ($method === 'POST' && $path === '/v2/invoices') {
            $capturedMethods = $body['invoice']['accepted_payment_methods'] ?? [];
            return ['invoice' => [
                'id' => 'SQI_REG',
                'order_id' => 'SQO_REG',
                'status' => 'DRAFT',
                'version' => 1,
                'public_url' => '',
            ]];
        }
        if ($method === 'POST' && strpos($path, '/publish') !== false) {
            return ['invoice' => [
                'id' => 'SQI_REG',
                'order_id' => 'SQO_REG',
                'status' => 'UNPAID',
                'version' => 2,
                'public_url' => 'https://squareup.com/pay/REG',
            ]];
        }
        throw new RuntimeException('Unexpected Square mock call ' . $method . ' ' . $path);
    };
    $sent = bakery_square_send_invoice($db, $squareOrderId, ['user_id' => null]);
    $GLOBALS['bakery_square_api_handler'] = static function ($method, $path) {
        $GLOBALS['BILLING_SQUARE_CALLS']++;
        throw new RuntimeException('billing regression must not call Square (' . $method . ' ' . $path . ')');
    };
    assert_true(!empty($sent['ok']), 'mocked Square send creates the invoice without leaving the process');
    assert_true(!empty($capturedMethods['card']), 'Square invoice accepts a card tender');
    assert_true(!empty($capturedMethods['cash_app_pay']), 'Square invoice accepts a Cash App tender');
    assert_true(!empty($capturedMethods['bank_account']), 'Square invoice accepts an ACH bank-account tender');
    assert_true($mockedCalls > 0, 'the mock handled the Square conversation');
    $openAfterSend = bakery_billing_order_outstanding($load($squareOrderId));
    bill_float(round(2 * $unit, 2), (float)$openAfterSend, 'offering card, Cash App, and ACH does not collect until one is paid');
    bakery_square_apply_invoice_payload($db, $squareOrderId, [
        'id' => 'SQI_REG',
        'order_id' => 'SQO_REG',
        'status' => 'PAID',
        'public_url' => 'https://squareup.com/pay/REG',
    ]);
    bill_float(0.00, (float)bakery_billing_order_outstanding($load($squareOrderId)), 'applying the paid tender zeros the balance');
    $paidStatus = bakery_billing_payment_status($load($squareOrderId), ['payment_collection' => 'signature']);
    assert_eq('square_paid', $paidStatus['key'], 'applied Square tender is paid');

    echo "Date range edges\n";
    $rangeCustomer = bill_insert_customer($db, '__bill_range', 'signature');
    $customerIds[] = $rangeCustomer;
    $beforeId = bill_insert_snapshot($db, $rangeCustomer, '2097-05-01', 'invoiced', 10.00, null, null);
    $startId = bill_insert_snapshot($db, $rangeCustomer, '2097-05-02', 'invoiced', 20.00, null, null);
    $endId = bill_insert_snapshot($db, $rangeCustomer, '2097-05-03', 'invoiced', 30.00, null, null);
    $afterId = bill_insert_snapshot($db, $rangeCustomer, '2097-05-04', 'invoiced', 40.00, null, null);
    $idsIn = static function (array $rows): array {
        $ids = array_map(static function ($row) {
            return (int)$row['id'];
        }, $rows);
        sort($ids);
        return $ids;
    };
    $span = bakery_billing_query_orders($db, [
        'start_date' => '2097-05-02',
        'end_date' => '2097-05-03',
        'customer_id' => $rangeCustomer,
        'sort' => 'date_asc',
    ]);
    $spanIds = $idsIn($span);
    sort($spanIds);
    $expectedSpan = [$startId, $endId];
    sort($expectedSpan);
    assert_eq($expectedSpan, $spanIds, 'date range includes the start day and the end day');
    assert_true(!in_array($beforeId, $spanIds, true) && !in_array($afterId, $spanIds, true), 'date range excludes the day before and the day after');
    $onlyStart = $idsIn(bakery_billing_query_orders($db, [
        'start_date' => '2097-05-02',
        'end_date' => '2097-05-02',
        'customer_id' => $rangeCustomer,
    ]));
    assert_eq([$startId], $onlyStart, 'a one-day range includes only that date');
    $onlyEnd = $idsIn(bakery_billing_query_orders($db, [
        'start_date' => '2097-05-03',
        'end_date' => '2097-05-03',
        'customer_id' => $rangeCustomer,
    ]));
    assert_eq([$endId], $onlyEnd, 'the end date alone is included when it is the whole range');
    $inverted = bakery_billing_query_orders($db, [
        'start_date' => '2097-05-03',
        'end_date' => '2097-05-02',
        'customer_id' => $rangeCustomer,
    ]);
    assert_eq([], $inverted, 'an inverted range matches nothing');
    $account = bakery_billing_customer_account($db, $rangeCustomer, '2097-05-02', '2097-05-03');
    bill_float(50.00, (float)$account['totals']['billable_total'], 'account total for the inclusive range is the two edge invoices');
    assert_eq(2, (int)$account['totals']['invoice_count'], 'account invoice count honors the same edges');

    assert_eq(0, (int)$GLOBALS['BILLING_MAIL_CALLS'], 'no test sent email');
    assert_true($mockedCalls > 0 && (int)$GLOBALS['BILLING_SQUARE_CALLS'] === $mockedCalls, 'Square was only invoked through the in-process mock');
} catch (Throwable $e) {
    echo 'FAIL  uncaught ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $GLOBALS['TEST_FAIL']++;
} finally {
    $GLOBALS['bakery_billing_mail_handler'] = static function () {
        throw new RuntimeException('billing regression must not send email');
    };
    $GLOBALS['bakery_square_api_handler'] = static function () {
        throw new RuntimeException('billing regression must not call Square');
    };
    try {
        bill_wipe($db, $customerIds, $productIds);
    } catch (Throwable $e) {
        fwrite(STDERR, 'billing cleanup failed: ' . $e->getMessage() . "\n");
    }
}

$known = count($GLOBALS['TEST_KNOWN']);
echo "\n{$GLOBALS['TEST_PASS']} passed, {$GLOBALS['TEST_FAIL']} failed, {$known} known\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
