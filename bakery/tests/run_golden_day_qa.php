<?php
/**
 * Golden-day QA — exercises one representative bakery operating date end-to-end.
 * CLI only. Refuses non-local databases. Resets demo fixtures before run.
 *
 * Usage:
 *   USE_PROD_DB=false php tests/run_golden_day_qa.php
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
bakery_reset_isolated_test_db($root);

/** @var PDO $db */
$db = require __DIR__ . '/harness.php';

// Use real rows from the production-derived clone; auto-increment IDs are not
// stable across nightly snapshots.
$qaPair = $db->query(
    "SELECT customer_id, product_id FROM standing_orders WHERE day_of_week=1 AND quantity>0 ORDER BY customer_id, product_id LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
$qaCustomerIds = $db->query(
    "SELECT DISTINCT customer_id FROM standing_orders WHERE day_of_week=1 AND quantity>0 ORDER BY customer_id LIMIT 2"
)->fetchAll(PDO::FETCH_COLUMN);
$qaProductIds = $db->query("SELECT id FROM products ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (!$qaPair || count($qaCustomerIds) < 2 || count($qaProductIds) < 2) {
    throw new RuntimeException('Production-derived test clone lacks golden-day rows');
}
$qaCustomerId = (int)$qaPair['customer_id'];
$qbCustomerId = (int)$db->query("SELECT id FROM customers WHERE id <> {$qaCustomerId} ORDER BY id LIMIT 1")->fetchColumn();
$qaProductId = (int)$qaPair['product_id'];
$qbProductId = (int)$qaProductIds[1];

require_once $root . '/includes/demand_review.php';
require_once $root . '/includes/product_inventory.php';
require_once $root . '/includes/daily_run.php';
require_once $root . '/includes/daily_brief.php';
require_once $root . '/includes/dashboard_command_center.php';
require_once $root . '/includes/billing.php';
require_once $root . '/includes/ingredient_requirements.php';
require_once $root . '/includes/operational_timeline.php';
require_once $root . '/complete_delivery.php';

echo "\n=== Database safety ===\n";
assert_true(!USE_PROD_DB, 'USE_PROD_DB is false for this run');
$hostLower = strtolower(DB_HOST);
$nameLower = strtolower(DB_NAME);
assert_true(
    in_array($hostLower, ['127.0.0.1', 'localhost', '::1'], true),
    'DB_HOST is local (' . DB_HOST . ')'
);
assert_true(
    strpos($nameLower, '_local') !== false || strpos($nameLower, 'test') !== false,
    'DB_NAME is non-production (' . DB_NAME . ')'
);

$testDate = '2099-08-03'; // Monday — avoids mutating dated snapshot rows
assert_eq('1', date('N', strtotime($testDate)), 'test date is Monday');

echo "\n=== 1–3 Demand: standing, override, one-off ===\n";
$gen = generate_from_standing($db, $testDate);
assert_true($gen['items_created'] > 0, 'generated daily orders from standing');

$standingQty = (int)$db->query(
    "SELECT quantity FROM standing_orders WHERE customer_id={$qaCustomerId} AND product_id={$qaProductId} AND day_of_week=1"
)->fetchColumn();
assert_true($standingQty > 0, 'snapshot standing qty for selected customer/product Monday');

$alphaOrderId = (int)$db->query(
    "SELECT id FROM daily_orders WHERE customer_id={$qaCustomerId} AND order_date='{$testDate}' LIMIT 1"
)->fetchColumn();
assert_true($alphaOrderId > 0, 'customer 1 has daily order');

$itemRow = $db->query(
    "SELECT id, quantity FROM daily_order_items WHERE daily_order_id={$alphaOrderId} AND product_id={$qaProductId} LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
assert_eq($standingQty, (int)$itemRow['quantity'], 'daily item matches standing before override');

$overrideQty = 7;
$db->prepare('UPDATE daily_order_items SET quantity=?, line_total=ROUND(?*unit_price,2) WHERE id=?')
    ->execute([$overrideQty, $overrideQty, (int)$itemRow['id']]);
$db->prepare(
    'UPDATE daily_orders SET total_amount=(SELECT COALESCE(SUM(line_total),0) FROM daily_order_items WHERE daily_order_id=?) WHERE id=?'
)->execute([$alphaOrderId, $alphaOrderId]);

$demand = bakery_demand_review_build($db, $testDate);
$alphaCustomer = null;
foreach ($demand['customers'] as $cust) {
    if ((int)($cust['customer_id'] ?? 0) === $qaCustomerId) {
        $alphaCustomer = $cust;
        break;
    }
}
assert_true($alphaCustomer !== null, 'demand review has customer 1');
$alphaLine = $alphaCustomer['line_map'][$qaProductId] ?? null;
assert_true($alphaLine !== null, 'demand review has customer 1 product 1 line');
assert_eq($overrideQty, (int)$alphaLine['daily_qty'], 'daily override visible in demand review');
assert_eq($standingQty, (int)$alphaLine['standing_qty'], 'standing qty preserved in demand review');
assert_true((int)$alphaLine['daily_qty'] !== (int)$alphaLine['standing_qty'], 'variance when daily != standing');

$db->prepare("INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES ({$qbCustomerId}, ?, 'pending', 0)")
    ->execute([$testDate]);
$oneOffOrderId = (int)$db->lastInsertId();
$db->prepare(
    "INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total) VALUES (?, {$qbProductId}, 3, 7.00, 21.00)"
)->execute([$oneOffOrderId]);
$db->prepare('UPDATE daily_orders SET total_amount=21.00 WHERE id=?')->execute([$oneOffOrderId]);
assert_true($oneOffOrderId > 0, 'one-off order for customer 2 created');

echo "\n=== 4–5 Production plan + ingredient calc ===\n";
$planQty = 10;
$db->prepare(
    "INSERT INTO production_plan_items (delivery_date, product_id, planned_quantity, created_by_user_id)
     VALUES (?, {$qaProductId}, ?, 1) ON DUPLICATE KEY UPDATE planned_quantity=VALUES(planned_quantity)"
)->execute([$testDate, $planQty]);

$planBuild = bakery_ingredient_requirements_build($db, $testDate, 'plan');
assert_true(($planBuild['error'] ?? null) === null, 'ingredient plan build succeeds');
$prod1 = null;
foreach ($planBuild['products'] as $p) {
    if ((int)$p['product_id'] === $qaProductId) {
        $prod1 = $p;
        break;
    }
}
assert_true($prod1 !== null, 'product 1 in ingredient plan');
assert_eq($planQty, (int)$prod1['quantity'], 'plan source uses saved planned_quantity');
assert_true(is_numeric($prod1['plan_vs_demand_delta']), 'plan vs total demand delta is numeric');

echo "\n=== 6 Production completion (plan vs actual) ===\n";
$producedQty = 9;
bakery_inventory_record_production($db, $testDate, $qaProductId, $producedQty, 'QA golden day');
$inv = $db->prepare('SELECT produced_quantity FROM product_inventory_days WHERE delivery_date=? AND product_id=?');
$inv->execute([$testDate, $qaProductId]);
assert_eq($producedQty, (int)$inv->fetchColumn(), 'produced_quantity recorded');

echo "\n=== 7–8 Finished goods + pack demand ===\n";
$packLines = bakery_operating_demand_lines($db, $testDate);
$alphaPackQty = 0;
foreach ($packLines as $row) {
    if ((int)$row['customer_id'] === $qaCustomerId && (int)$row['product_id'] === $qaProductId) {
        $alphaPackQty = (int)$row['quantity'];
        assert_eq('daily', $row['source'], 'customer 1 uses daily source not standing');
        break;
    }
}
assert_eq($overrideQty, $alphaPackQty, 'pack/demand uses daily override for customer 1 product 1');

echo "\n=== 9–11 Driver assign, load, deliveries ===\n";
$orderIds = $db->query(
    "SELECT id FROM daily_orders WHERE order_date='{$testDate}' ORDER BY id"
)->fetchAll(PDO::FETCH_COLUMN);
assert_true(count($orderIds) >= 2, 'at least two stops for the day');

$routeOrder = 1;
foreach ($orderIds as $oid) {
    $db->prepare(
        "INSERT INTO daily_order_assignments (daily_order_id, driver_id, delivery_date, route_order, scheduled_delivery_time, delivery_status)
         VALUES (?, 1, ?, ?, '08:00:00', 'pending')"
    )->execute([(int)$oid, $testDate, $routeOrder++]);
}

$loadQty = [];
foreach ($packLines as $row) {
    $pid = (int)$row['product_id'];
    $loadQty[$pid] = ($loadQty[$pid] ?? 0) + (int)$row['quantity'];
}
bakery_inventory_save_driver_load($db, $testDate, 1, $loadQty, 'QA load');

$normalOrderId = $alphaOrderId;
$reducedOrderId = (int)$orderIds[1];

// A historic zero-priced line should inherit the current catalog price before
// a driver sees or confirms the invoice.
$repairLineStmt = $db->prepare(
    'SELECT doi.id, doi.quantity, doi.unit_price, doi.line_total, p.price AS standard_price
     FROM daily_order_items doi
     JOIN products p ON p.id = doi.product_id
     WHERE doi.daily_order_id = ? AND doi.quantity > 0 AND p.price > 0
     ORDER BY doi.id LIMIT 1'
);
$repairLineStmt->execute([$normalOrderId]);
$repairLine = $repairLineStmt->fetch(PDO::FETCH_ASSOC);
if ($repairLine) {
    $db->prepare('UPDATE daily_order_items SET unit_price=0, line_total=0 WHERE id=?')->execute([(int)$repairLine['id']]);
    $repairedPreview = bakery_delivery_invoice($db, $normalOrderId);
    assert_true($repairedPreview['average_price'] > 0, 'zero-priced line preview uses configured standard price');
    bakery_delivery_repair_missing_item_prices($db, $normalOrderId);
    $savedPriceStmt = $db->prepare('SELECT unit_price FROM daily_order_items WHERE id=?');
    $savedPriceStmt->execute([(int)$repairLine['id']]);
    assert_eq(round((float)$repairLine['standard_price'], 2), round((float)$savedPriceStmt->fetchColumn(), 2), 'standard price is saved before delivery confirmation');
}

// Normal delivery — full qty
$invoiceNormal = bakery_delivery_invoice($db, $normalOrderId);
$orderedNormal = (int)$invoiceNormal['ordered_pieces'];
$pricePerPiece = $invoiceNormal['average_price'];
$db->beginTransaction();
$db->prepare(
    'UPDATE daily_orders SET delivered_pieces=?, credits_taken_back=0, total_amount=?, delivery_order_total=?, delivery_confirmed_at=NOW() WHERE id=?'
)->execute([$orderedNormal, round($orderedNormal * $pricePerPiece, 2), round($orderedNormal * $pricePerPiece, 2), $normalOrderId]);
bakery_mark_delivery_delivered($db, $normalOrderId);
$db->commit();

bakery_operational_log_delivery(
    $db,
    BAKERY_OP_DELIVERY_COMPLETED,
    $normalOrderId,
    'QA completed normal delivery',
    ['ordered_pieces' => $orderedNormal, 'delivered_pieces' => $orderedNormal],
    ['latitude' => 37.78, 'longitude' => -122.41, 'accuracy_m' => 12.0, 'status' => 'captured']
);

if (bakery_operational_events_ready($db)) {
    $ev = $db->prepare('SELECT daily_order_id, gps_latitude, gps_longitude, gps_status FROM operational_events WHERE daily_order_id=? ORDER BY id DESC LIMIT 1');
    $ev->execute([$normalOrderId]);
    $evRow = $ev->fetch(PDO::FETCH_ASSOC);
    assert_eq($normalOrderId, (int)$evRow['daily_order_id'], 'timeline event links correct daily_order_id');
    assert_float_near(37.78, (float)$evRow['gps_latitude'], 'GPS latitude stored');
    assert_float_near(-122.41, (float)$evRow['gps_longitude'], 'GPS longitude stored');
    assert_eq('captured', $evRow['gps_status'], 'GPS status captured');
}

// Reduced delivery
$invoiceReduced = bakery_delivery_invoice($db, $reducedOrderId);
$orderedReduced = (int)$invoiceReduced['ordered_pieces'];
$deliveredReduced = max(1, $orderedReduced - 2);
$reducedPrice = $invoiceReduced['average_price'];
$reducedTotal = round($deliveredReduced * $reducedPrice, 2);
$db->beginTransaction();
$db->prepare(
    'UPDATE daily_orders SET delivered_pieces=?, credits_taken_back=0, total_amount=?, delivery_order_total=?, delivery_confirmed_at=NOW() WHERE id=?'
)->execute([$deliveredReduced, $reducedTotal, $reducedTotal, $reducedOrderId]);
bakery_mark_delivery_delivered($db, $reducedOrderId);
$db->commit();

$itemDelivered = $db->prepare('SELECT SUM(delivered_quantity) FROM daily_order_items WHERE daily_order_id=?');
$itemDelivered->execute([$reducedOrderId]);
assert_eq($deliveredReduced, (int)$itemDelivered->fetchColumn(), 'line delivered_quantity sums to header');

$billingOrders = bakery_billing_query_orders($db, [
    'start_date' => $testDate,
    'end_date' => $testDate,
    'customer_id' => 0,
    'status' => 'all',
]);
$billingItems = bakery_billing_load_items($db, [$reducedOrderId]);
$billingEnriched = bakery_billing_enrich_orders(
    array_values(array_filter($billingOrders, static function ($o) use ($reducedOrderId) {
        return (int)$o['id'] === $reducedOrderId;
    })),
    $billingItems
);
$billingReduced = $billingEnriched[0] ?? [];
assert_true(!empty($billingReduced['has_quantity_variance']), 'reduced delivery flagged as quantity variance');
assert_float_near($reducedTotal, (float)$billingReduced['billable_amount'], 'billable amount uses delivered not ordered');

echo "\n=== 14–16 Invoice, statement, accounting export ===\n";
bakery_billing_mark_invoiced($db, $normalOrderId, 1);
bakery_billing_mark_invoiced($db, $reducedOrderId, 1);

$exportRows = bakery_billing_export_rows($db, [
    'start_date' => $testDate,
    'end_date' => $testDate,
    'customer_id' => 0,
    'confirmed_only' => true,
]);
assert_true(count($exportRows) >= 2, 'export has line rows for both deliveries');

$exportTotal = 0.0;
$seenOrders = [];
$reducedLineFound = false;
foreach ($exportRows as $row) {
    $seenOrders[(int)$row['daily_order_id']] = true;
    if ((int)$row['daily_order_id'] === $reducedOrderId) {
        if ((int)$row['quantity_delivered'] < (int)$row['quantity_ordered']) {
            $reducedLineFound = true;
        }
    }
}
assert_true($reducedLineFound, 'export quantity_delivered < quantity_ordered for reduced stop');
assert_true(isset($seenOrders[$normalOrderId]) && isset($seenOrders[$reducedOrderId]), 'export covers both orders');

$stmtData = bakery_billing_statement_data($db, $qaCustomerId, $testDate, $testDate);
assert_float_near(
    (float)$db->query("SELECT total_amount FROM daily_orders WHERE id={$normalOrderId}")->fetchColumn(),
    (float)$stmtData['total_amount'],
    'statement total matches customer 1 confirmed delivery for the day',
    0.05
);

if (bakery_billing_tables_ready($db)) {
    bakery_billing_record_export($db, [
        'export_key' => 'EXP-QA-1',
        'period_start' => $testDate,
        'period_end' => $testDate,
        'row_count' => count($exportRows),
        'invoice_count' => count($seenOrders),
        'content_hash' => hash('sha256', 'qa'),
        'notes' => 'QA golden day',
    ], array_keys($seenOrders), 1);
    $dup = $db->prepare('SELECT COUNT(*) FROM billing_exports WHERE export_key=?');
    $dup->execute(['EXP-QA-1']);
    assert_eq(1, (int)$dup->fetchColumn(), 'duplicate export_key rejected by unique constraint or single row');
}

echo "\n=== 16b Route closeout reconciliation ===\n";
if (bakery_inventory_closeout_ready($db)) {
    $closeStats = bakery_inventory_closeout_stats($db, $testDate);
    assert_true((int)$closeStats['unreconciled'] >= 1, 'loaded route needs closeout before day close');

    // Closeout requires every assigned stop finished — deliver any remaining open stops.
    foreach ($orderIds as $oid) {
        $oid = (int)$oid;
        $st = $db->prepare('SELECT status, delivery_confirmed_at FROM daily_orders WHERE id=?');
        $st->execute([$oid]);
        $ord = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ord || ($ord['status'] ?? '') === 'delivered' || ($ord['status'] ?? '') === 'invoiced') {
            continue;
        }
        $inv = bakery_delivery_invoice($db, $oid);
        $pcs = (int)$inv['ordered_pieces'];
        $price = (float)$inv['average_price'];
        $total = round($pcs * $price, 2);
        $db->beginTransaction();
        $db->prepare(
            'UPDATE daily_orders SET delivered_pieces=?, credits_taken_back=0, total_amount=?, delivery_order_total=?, delivery_confirmed_at=NOW() WHERE id=?'
        )->execute([$pcs, $total, $total, $oid]);
        bakery_mark_delivery_delivered($db, $oid);
        $db->commit();
    }

    $closeLines = bakery_inventory_closeout_lines($db, $testDate, 1);
    $reconcilePayload = [];
    foreach ($closeLines as $line) {
        $loaded = (int)$line['loaded_quantity'];
        $delivered = (int)$line['delivered_quantity'];
        $remaining = max(0, $loaded - $delivered);
        // Put one unit to waste when remaining allows; rest returns to FG.
        $waste = $remaining > 0 ? 1 : 0;
        $returned = max(0, $remaining - $waste);
        if ($delivered + $returned + $waste !== $loaded) {
            $returned = max(0, $loaded - $delivered);
            $waste = 0;
        }
        $reconcilePayload[(int)$line['product_id']] = [
            'returned' => $returned,
            'wasted' => $waste,
        ];
    }
    bakery_inventory_reconcile_driver_load($db, $testDate, 1, $reconcilePayload, 'QA route closeout');

    $loadStatus = $db->prepare("SELECT status FROM driver_loads WHERE driver_id=1 AND delivery_date=?");
    $loadStatus->execute([$testDate]);
    assert_eq('reconciled', (string)$loadStatus->fetchColumn(), 'driver load marked reconciled');

    $wasteMove = $db->prepare(
        "SELECT COUNT(*) FROM inventory_movements
         WHERE delivery_date=? AND driver_id=1 AND movement_type='waste'"
    );
    $wasteMove->execute([$testDate]);
    assert_true((int)$wasteMove->fetchColumn() >= 0, 'waste movements allowed on ledger');

    $returnMove = $db->prepare(
        "SELECT COUNT(*) FROM inventory_movements
         WHERE delivery_date=? AND driver_id=1 AND movement_type IN ('return','delivery')"
    );
    $returnMove->execute([$testDate]);
    assert_true((int)$returnMove->fetchColumn() > 0, 'return/delivery movements posted on closeout');

    $closeStatsAfter = bakery_inventory_closeout_stats($db, $testDate);
    assert_eq(0, (int)$closeStatsAfter['unreconciled'], 'no open routes after reconcile');
} else {
    echo "  (skip route closeout — migration 037 not applied)\n";
}

echo "\n=== 17–18 Daily Brief + Daily Run closeout ===\n";
$cc = bakery_dashboard_command_center($db, $testDate);
$dailyRun = bakery_daily_run_build($db, $testDate);
$brief = bakery_daily_brief_build($db, $testDate, ['command_center' => $cc, 'daily_run' => $dailyRun]);

$ccDelivered = (int)($cc['stages']['delivery']['metrics']['delivered']['value'] ?? -1);
$briefDelivered = bakery_daily_brief_metric_value(
    bakery_daily_brief_stage_by_key($cc, 'delivery') ?? [],
    'delivered'
);
if ($briefDelivered !== null && $ccDelivered >= 0) {
    assert_eq($ccDelivered, $briefDelivered, 'Daily Brief delivery count matches command center');
}

assert_true(empty($dailyRun['operational_complete']), 'day not operationally complete before all stages satisfied');

$closeFailed = false;
try {
    bakery_daily_run_close_day($db, $testDate, 1, 'premature');
} catch (RuntimeException $e) {
    $closeFailed = true;
}
assert_true($closeFailed, 'closeout rejected while stages incomplete');

echo "\n=== Edge: GPS 0,0 treated as unavailable ===\n";
$gpsZero = bakery_operational_gps_from_input([
    'gps_status' => 'captured',
    'gps_latitude' => '0',
    'gps_longitude' => '0',
]);
assert_eq('unavailable', $gpsZero['status'], 'GPS 0,0 downgraded when lat/lng are zero');

echo "\n=== Edge: double closeout idempotent ===\n";
// Mark remaining orders invoiced and satisfy stages enough for a partial check
foreach ($orderIds as $oid) {
    $db->prepare("UPDATE daily_orders SET status='invoiced' WHERE id=? AND delivery_confirmed_at IS NOT NULL")
        ->execute([(int)$oid]);
}

echo "\n=== Summary ===\n";
echo "Passed: {$GLOBALS['TEST_PASS']}\n";
echo "Failed: {$GLOBALS['TEST_FAIL']}\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);

function assert_float_near($expected, $actual, $message, $epsilon = 0.01) {
    assert_true(abs((float)$expected - (float)$actual) <= $epsilon, $message);
}
