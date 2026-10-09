<?php
/**
 * Confirm-delivery must keep an explicit no-charge $0 line, still fill a
 * price that was never set, leave a zero-quantity $0 line alone, and log
 * every catalog fill.
 *
 * Reproduces the known skip in draft PR #32 (2 x $6.50 + 2 x $0 was billed
 * $17.50). The $0 line has to be marked no-charge: a bare 0 is still "unset"
 * because unit_price defaults to 0.00.
 *
 * Local bakerysf_test only. No email, SMS, or Square.
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

require_once $root . '/complete_delivery.php';
require_once $root . '/includes/billing.php';
require_once $root . '/includes/customer_billing.php';
require_once $root . '/includes/invoice_document.php';

if (function_exists('bakery_set_locale')) {
    $GLOBALS['bakery_i18n_catalog'] = null;
    bakery_set_locale('en', false);
}

$GLOBALS['bakery_billing_mail_handler'] = static function () {
    throw new RuntimeException('zero-price confirm test must not send email');
};
$GLOBALS['bakery_square_api_handler'] = static function () {
    throw new RuntimeException('zero-price confirm test must not call Square');
};

function zpc_float(float $expected, float $actual, string $message): void
{
    assert_true(abs($expected - $actual) < 0.005, $message . " (expected={$expected} actual={$actual})");
}

function zpc_ensure_marker(PDO $db): void
{
    if (column_exists($db, 'daily_order_items', 'is_no_charge')
        && column_exists($db, 'daily_order_items', 'no_charge_reason')) {
        return;
    }
    $db->exec(
        'ALTER TABLE daily_order_items
            ADD COLUMN is_no_charge TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN no_charge_reason VARCHAR(32) NULL DEFAULT NULL'
    );
    $cache = &bakery_schema_column_cache();
    unset($cache['daily_order_items.is_no_charge'], $cache['daily_order_items.no_charge_reason']);
    assert_true(column_exists($db, 'daily_order_items', 'is_no_charge'), 'migration 088 adds is_no_charge');
}

$customerId = 0;
$productIds = [];
$orderIds = [];

try {
    zpc_ensure_marker($db);
    assert_true(bakery_order_line_no_charge_ready($db), 'no-charge marker is installed');

    $page = (string)file_get_contents($root . '/daily_orders.php');
    assert_true(strpos($page, 'setLineNoCharge') !== false, 'daily orders page can mark a line no-charge');
    assert_true(strpos($page, 'no_charge.mark') !== false, 'daily orders page uses the no-charge copy');

    $doughTypeId = (int)$db->query('SELECT id FROM dough_types ORDER BY id LIMIT 1')->fetchColumn();
    assert_true($doughTypeId > 0, 'a dough type exists');

    $stamp = 'zpc' . bin2hex(random_bytes(3));
    $db->prepare(
        'INSERT INTO customers (name, zone, email, address, phone, payment_collection, is_active)
         VALUES (?, ?, ?, ?, ?, ?, 1)'
    )->execute([
        '__' . $stamp,
        'North Zone',
        $stamp . '@example.test',
        '1 No Charge Street',
        '555-0108',
        'signature',
    ]);
    $customerId = (int)$db->lastInsertId();

    $insertProduct = $db->prepare(
        'INSERT INTO products (name, dough_type_id, price, weight_grams, description) VALUES (?, ?, ?, 40, ?)'
    );
    $insertProduct->execute([$stamp . ' priced', $doughTypeId, 6.50, 'priced line']);
    $pricedId = (int)$db->lastInsertId();
    $insertProduct->execute([$stamp . ' comp', $doughTypeId, 9.00, 'would be repriced if unmarked']);
    $compId = (int)$db->lastInsertId();
    $insertProduct->execute([$stamp . ' unset', $doughTypeId, 4.25, 'catalog price for an unset line']);
    $unsetId = (int)$db->lastInsertId();
    $insertProduct->execute([$stamp . ' nullprice', $doughTypeId, 3.00, 'catalog price for a NULL line']);
    $nullId = (int)$db->lastInsertId();
    $insertProduct->execute([$stamp . ' zeroqty', $doughTypeId, 8.00, 'zero quantity must stay $0']);
    $zeroQtyProductId = (int)$db->lastInsertId();
    $productIds = [$pricedId, $compId, $unsetId, $nullId, $zeroQtyProductId];

    $insertOrder = $db->prepare(
        "INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, 'pending', 0)"
    );
    $insertItem = $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
         VALUES (?, ?, ?, ?, ?)'
    );

    echo "Marked no-charge line\n";
    $insertOrder->execute([$customerId, '2097-11-02']);
    $freeOrderId = (int)$db->lastInsertId();
    $orderIds[] = $freeOrderId;
    $insertItem->execute([$freeOrderId, $pricedId, 2, 6.50, 13.00]);
    $insertItem->execute([$freeOrderId, $compId, 2, 6.50, 13.00]);
    $compLineId = (int)$db->query(
        'SELECT id FROM daily_order_items WHERE daily_order_id = ' . $freeOrderId . ' AND product_id = ' . $compId
    )->fetchColumn();
    $marked = bakery_daily_orders_action_set_no_charge($db, [
        'item_id' => $compLineId,
        'is_no_charge' => '1',
        'no_charge_reason' => 'sample',
    ]);
    assert_true(!empty($marked['success']), 'staff can mark a line no-charge from the order editor');
    zpc_float(0.0, (float)$marked['unit_price'], 'marking no-charge stores $0');

    $preview = bakery_delivery_invoice($db, $freeOrderId);
    assert_true(!bakery_delivery_pricing_missing($preview), 'a mixed no-charge order is not missing a price');
    zpc_float(13.0, (float)$preview['order_total'], 'preview total is 2 x $6.50 and ignores the no-charge line');

    $freeConfirmed = bakery_confirm_delivery($db, $freeOrderId, 4, 0, []);
    assert_true(!empty($freeConfirmed['success']), 'confirm succeeds without repricing the no-charge line');
    zpc_float(13.0, (float)$freeConfirmed['total'], '2 x $6.50 + 2 x $0 no-charge totals $13.00');

    $lines = $db->prepare(
        'SELECT id, product_id, quantity, unit_price, line_total, is_no_charge, no_charge_reason
         FROM daily_order_items WHERE daily_order_id = ?'
    );
    $lines->execute([$freeOrderId]);
    $byProduct = [];
    foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $byProduct[(int)$line['product_id']] = $line;
    }
    zpc_float(6.50, (float)$byProduct[$pricedId]['unit_price'], 'priced line stays $6.50');
    zpc_float(0.0, (float)$byProduct[$compId]['unit_price'], 'no-charge line stays $0.00');
    assert_eq(1, (int)$byProduct[$compId]['is_no_charge'], 'no-charge marker stays set');
    assert_eq('sample', (string)$byProduct[$compId]['no_charge_reason'], 'no-charge reason is stored');

    $repairForComp = $db->prepare(
        "SELECT metadata FROM operational_events
         WHERE daily_order_id = ? AND event_type = 'line_price_repaired'"
    );
    $repairForComp->execute([$freeOrderId]);
    $compRewritten = false;
    foreach ($repairForComp->fetchAll(PDO::FETCH_COLUMN) as $raw) {
        $meta = json_decode((string)$raw, true);
        if (is_array($meta) && (int)($meta['line_id'] ?? 0) === $compLineId && (float)($meta['new_price'] ?? 0) > 0) {
            $compRewritten = true;
        }
    }
    assert_true(!$compRewritten, 'confirm does not log a catalog fill for the no-charge line');

    $canonical = bakery_billing_load_canonical_invoice($db, $freeOrderId);
    $html = bakery_billing_invoice_document_html($canonical, ['mode' => 'staff']);
    assert_true(strpos($html, 'No charge (Sample)') !== false, 'invoice labels the no-charge line and its reason');
    assert_true(strpos($html, '$0.00') !== false, 'invoice shows the no-charge line at $0.00');
    assert_true(strpos($html, '13.00') !== false, 'invoice total is $13.00');

    $export = bakery_billing_export_rows($db, [
        'start_date' => '2097-11-02',
        'end_date' => '2097-11-02',
        'customer_id' => $customerId,
        'confirmed_only' => true,
    ]);
    $exportComp = null;
    foreach ($export as $row) {
        if ((int)$row['product_id'] === $compId) {
            $exportComp = $row;
        }
    }
    assert_true(is_array($exportComp), 'billing export includes the no-charge line');
    zpc_float(0.0, (float)$exportComp['unit_price'], 'billing export unit price is 0.00');
    zpc_float(0.0, (float)$exportComp['line_total'], 'billing export line total is 0.00');
    assert_eq(2, (int)$exportComp['quantity_ordered'], 'billing export keeps the no-charge quantity');
    assert_eq('No charge (Sample)', (string)$exportComp['no_charge'], 'billing export labels the line No charge');
    assert_true(strpos((string)$exportComp['memo'], 'No charge (Sample)') !== false, 'billing export memo carries the label for Books');

    $portalLines = bakery_portal_billing_line_export_rows($db, $customerId, '2097-11-02', '2097-11-02');
    $portalComp = null;
    foreach ($portalLines as $row) {
        if (strpos((string)$row['product'], 'comp') !== false) {
            $portalComp = $row;
        }
    }
    assert_true(is_array($portalComp), 'customer line export includes the no-charge product');
    assert_true(strpos((string)$portalComp['product'], 'No charge (Sample)') !== false, 'customer export shows the No charge label');
    zpc_float(0.0, (float)$portalComp['unit_price'], 'customer export unit price is 0.00');
    assert_eq(2, (int)$portalComp['quantity'], 'customer export keeps the quantity');

    echo "Unset price still filled\n";
    $insertOrder->execute([$customerId, '2097-11-03']);
    $unsetOrderId = (int)$db->lastInsertId();
    $orderIds[] = $unsetOrderId;
    $insertItem->execute([$unsetOrderId, $unsetId, 2, 0, 0]);
    $insertItem->execute([$unsetOrderId, $zeroQtyProductId, 0, 0, 0]);
    $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
         VALUES (?, ?, 1, NULL, 0)'
    )->execute([$unsetOrderId, $nullId]);

    $unsetLines = $db->prepare('SELECT id, product_id, unit_price FROM daily_order_items WHERE daily_order_id = ?');
    $unsetLines->execute([$unsetOrderId]);
    $unsetByProduct = [];
    foreach ($unsetLines->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $unsetByProduct[(int)$line['product_id']] = $line;
    }
    $unsetLineId = (int)$unsetByProduct[$unsetId]['id'];
    $nullLineId = (int)$unsetByProduct[$nullId]['id'];
    $zeroQtyLineId = (int)$unsetByProduct[$zeroQtyProductId]['id'];

    $unsetConfirmed = bakery_confirm_delivery($db, $unsetOrderId, 3, 0, []);
    zpc_float(11.50, (float)$unsetConfirmed['total'], 'unset $0 and NULL lines bill at catalog; zero-qty does not');

    $after = $db->prepare(
        'SELECT product_id, quantity, unit_price FROM daily_order_items WHERE daily_order_id = ?'
    );
    $after->execute([$unsetOrderId]);
    $afterByProduct = [];
    foreach ($after->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $afterByProduct[(int)$line['product_id']] = $line;
    }
    zpc_float(4.25, (float)$afterByProduct[$unsetId]['unit_price'], 'unmarked $0 is filled from the catalog');
    zpc_float(3.00, (float)$afterByProduct[$nullId]['unit_price'], 'NULL unit price is filled from the catalog');
    assert_eq(0, (int)$afterByProduct[$zeroQtyProductId]['quantity'], 'zero-quantity line is still on the order');
    zpc_float(0.0, (float)$afterByProduct[$zeroQtyProductId]['unit_price'], 'zero-quantity $0 line is not repriced');

    $logged = $db->prepare(
        "SELECT metadata FROM operational_events
         WHERE daily_order_id = ? AND event_type = 'line_price_repaired'"
    );
    $logged->execute([$unsetOrderId]);
    $seen = [];
    foreach ($logged->fetchAll(PDO::FETCH_COLUMN) as $raw) {
        $meta = json_decode((string)$raw, true);
        assert_true(is_array($meta), 'repair log metadata is JSON');
        $seen[(int)($meta['line_id'] ?? 0)] = $meta;
    }
    assert_true(isset($seen[$unsetLineId]), 'repair log includes the unmarked $0 line');
    zpc_float(0.0, (float)$seen[$unsetLineId]['old_price'], 'repair log records the old price');
    zpc_float(4.25, (float)$seen[$unsetLineId]['new_price'], 'repair log records the catalog price');
    assert_eq('customer_catalog', (string)$seen[$unsetLineId]['source'], 'repair log names the price source');
    assert_true(isset($seen[$nullLineId]), 'repair log includes the NULL price line');
    assert_true($seen[$nullLineId]['old_price'] === null, 'repair log keeps a NULL old price');
    zpc_float(3.00, (float)$seen[$nullLineId]['new_price'], 'NULL line repair log records the new price');
    assert_true(!isset($seen[$zeroQtyLineId]), 'zero-quantity line is not in the repair log');

    echo "Unmark restores a catalog price\n";
    $insertOrder->execute([$customerId, '2097-11-04']);
    $clearOrderId = (int)$db->lastInsertId();
    $orderIds[] = $clearOrderId;
    $insertItem->execute([$clearOrderId, $unsetId, 1, 0, 0]);
    $clearLineId = (int)$db->query(
        'SELECT id FROM daily_order_items WHERE daily_order_id = ' . $clearOrderId
    )->fetchColumn();
    bakery_daily_orders_action_set_no_charge($db, [
        'item_id' => $clearLineId,
        'is_no_charge' => '1',
        'no_charge_reason' => 'comp',
    ]);
    $cleared = bakery_daily_orders_action_set_no_charge($db, [
        'item_id' => $clearLineId,
        'is_no_charge' => '0',
    ]);
    assert_eq(0, (int)$cleared['is_no_charge'], 'clearing no-charge removes the marker');
    zpc_float(4.25, (float)$cleared['unit_price'], 'clearing no-charge restores the catalog price');
} finally {
    if ($orderIds !== []) {
        $in = implode(',', array_map('intval', $orderIds));
        $db->exec('DELETE FROM operational_events WHERE daily_order_id IN (' . $in . ')');
        $db->exec('DELETE FROM daily_orders WHERE id IN (' . $in . ')');
    }
    if ($productIds !== []) {
        $in = implode(',', array_map('intval', $productIds));
        $db->exec('DELETE FROM products WHERE id IN (' . $in . ')');
    }
    if ($customerId > 0) {
        $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
    }
}

echo "\n{$GLOBALS['TEST_PASS']} passed, {$GLOBALS['TEST_FAIL']} failed\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
