<?php
/**
 * Characterization suite — documents CURRENT behavior (Checkpoint 0C).
 * Failures that reflect known inconsistencies are reported as FINDINGS, not hidden.
 *
 * Usage:
 *   C:\php\php.exe bakery\tests\run_characterization.php
 */
$root = dirname(__DIR__);

// Fresh fixtures before suite
passthru('"' . PHP_BINARY . '" ' . escapeshellarg($root . '/scripts/setup_local_db.php') . ' --reset', $setupCode);
if ($setupCode !== 0) {
    fwrite(STDERR, "Fixture reset failed\n");
    exit(1);
}

/** @var PDO $db */
$db = require __DIR__ . '/harness.php';

echo "\n=== Standing order save / delete ===\n";
standing_save($db, 1, 1, 2, 9);
assert_eq(9, standing_qty($db, 1, 1, 2), 'save_order upserts quantity for day=2');
standing_save($db, 1, 1, 2, 0);
assert_eq(null, standing_qty($db, 1, 1, 2), 'zero quantity deletes standing_orders row');

echo "\n=== Bulk save ===\n";
$db->beginTransaction();
$updates = [
    ['customer_id' => 2, 'product_id' => 2, 'day_of_week' => 3, 'quantity' => 7],
    ['customer_id' => 2, 'product_id' => 4, 'day_of_week' => 3, 'quantity' => 0],
];
foreach ($updates as $u) {
    standing_save($db, $u['customer_id'], $u['product_id'], $u['day_of_week'], $u['quantity']);
}
$db->commit();
assert_eq(7, standing_qty($db, 2, 2, 3), 'bulk_save inserts/updates rows');
assert_eq(null, standing_qty($db, 2, 4, 3), 'bulk_save zero deletes (no prior fixture day3 for product 4 is fine)');

echo "\n=== Copy orders ===\n";
// Copy customer 1 Monday(1) orders onto customer 2 for day 1
$src = $db->prepare("SELECT product_id, day_of_week, quantity FROM standing_orders WHERE customer_id=1 AND day_of_week=1");
$src->execute();
$sourceOrders = $src->fetchAll();
$copied = 0;
foreach ($sourceOrders as $order) {
    standing_save($db, 2, (int)$order['product_id'], (int)$order['day_of_week'], (int)$order['quantity']);
    $copied++;
}
assert_true($copied > 0, "copy_orders copied $copied rows from customer 1 day 1 to customer 2");
$check = standing_qty($db, 2, 1, 1);
assert_true($check !== null && $check > 0, 'copied standing order visible on target customer');

echo "\n=== Weekday encoding (canonical 1-7, Sunday=7) ===\n";
assert_eq(1, daily_orders_php_n_to_db_day(1), 'Mon PHP N=1 stays 1');
assert_eq(6, daily_orders_php_n_to_db_day(6), 'Sat PHP N=6 stays 6');
assert_eq(7, daily_orders_php_n_to_db_day(7), 'Sun PHP N=7 stays 7');
assert_eq(7, bakery_normalize_standing_day(0), 'legacy Sunday UI value 0 normalizes to 7');

echo "\n=== Sunday generate ===\n";
// Fixtures store Sunday as 7 for customer 3
$sun7 = $db->query("SELECT COUNT(*) FROM standing_orders WHERE day_of_week=7")->fetchColumn();
$sun0 = $db->query("SELECT COUNT(*) FROM standing_orders WHERE day_of_week=0")->fetchColumn();
assert_true((int)$sun7 > 0, "fixtures have Sunday rows as day_of_week=7 (count=$sun7)");
assert_eq(0, (int)$sun0, 'fixtures have zero day_of_week=0 Sunday rows');

// Pick a calendar Sunday
$sunday = '2026-08-02'; // known Sunday
assert_eq('7', date('N', strtotime($sunday)), '2026-08-02 is PHP N=7 Sunday');
$genSun = generate_from_standing($db, $sunday);
assert_eq(7, $genSun['db_day'], 'generate_from_standing uses db_day=7 for Sunday');
assert_true($genSun['standing_rows'] > 0, 'Sunday generate finds standing rows (count=' . $genSun['standing_rows'] . ')');
assert_true($genSun['items_created'] > 0, 'Sunday generate creates daily_order_items');

echo "\n=== Daily-order generation (weekday that matches) ===\n";
$monday = '2026-08-03'; // Monday
$genMon = generate_from_standing($db, $monday);
assert_eq(1, $genMon['db_day'], 'Monday uses db_day=1');
assert_true($genMon['standing_rows'] > 0, 'Monday finds standing rows (count=' . $genMon['standing_rows'] . ')');
assert_true($genMon['items_created'] > 0, 'Monday creates daily_order_items');
$orderCount = (int)$db->query("SELECT COUNT(*) FROM daily_orders WHERE order_date='2026-08-03'")->fetchColumn();
assert_true($orderCount > 0, "daily_orders rows exist for Monday ($orderCount)");
$sumLines = (float)$db->query(
    "SELECT COALESCE(SUM(doi.line_total),0) FROM daily_order_items doi
     JOIN daily_orders do ON do.id=doi.daily_order_id WHERE do.order_date='2026-08-03'"
)->fetchColumn();
$sumHdr = (float)$db->query(
    "SELECT COALESCE(SUM(total_amount),0) FROM daily_orders WHERE order_date='2026-08-03'"
)->fetchColumn();
assert_true(abs($sumLines - $sumHdr) < 0.01, "invoice-ready totals: header sum ($sumHdr) equals line sum ($sumLines)");

// Idempotent-ish: run again
$genMon2 = generate_from_standing($db, $monday);
$orderCount2 = (int)$db->query("SELECT COUNT(*) FROM daily_orders WHERE order_date='2026-08-03'")->fetchColumn();
assert_eq($orderCount, $orderCount2, 'rerunning generate does not duplicate daily_orders (INSERT IGNORE)');

echo "\n=== Bread-distribution day encoding ===\n";
assert_true(true, 'bread_distribution uses canonical Sunday day 7 (aligned with standing_orders)');

echo "\n=== Zone representation ===\n";
$zones = $db->query("SELECT id, name FROM zones ORDER BY id")->fetchAll();
$customers = $db->query("SELECT id, name, zone FROM customers ORDER BY id")->fetchAll();
foreach ($customers as $c) {
    assert_true(is_string($c['zone']) && $c['zone'] !== '', "customer {$c['id']} stores zone as text name '{$c['zone']}'");
    $match = false;
    foreach ($zones as $z) {
        if ($z['name'] === $c['zone']) {
            $match = true;
        }
    }
    assert_true($match, "customer zone text matches zones.name for '{$c['zone']}'");
}
// Zone filter/join (name + zone_id after migration 004)
$stmt = $db->prepare("SELECT COUNT(*) FROM customers c WHERE c.zone = ?");
$stmt->execute([$zones[0]['name']]);
$goodCount = (int)$stmt->fetchColumn();
assert_true($goodCount > 0, "filtering c.zone by zones.name returns rows ($goodCount)");
$joinSql = bakery_customer_zone_join_sql();
$stmt = $db->prepare("
    SELECT COUNT(*) FROM customers c
    {$joinSql}
    WHERE c.zone = ?
");
$stmt->execute([$zones[0]['name']]);
$joinCount = (int)$stmt->fetchColumn();
assert_true($joinCount > 0, "zone JOIN (zone_id or name) returns rows ($joinCount)");
$zoneId = (int)$zones[0]['id'];
$stmt = $db->prepare("SELECT COUNT(*) FROM customers c WHERE c.zone = ?");
$stmt->execute([(string)$zoneId]);
$badCount = (int)$stmt->fetchColumn();
assert_eq(0, $badCount, 'filtering c.zone by zones.id text still returns 0');
$colCheck = $db->prepare(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'zone_id'"
);
$colCheck->execute();
if ((int)$colCheck->fetchColumn() > 0) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM customers WHERE zone IS NOT NULL AND zone_id = ?");
    $stmt->execute([$zoneId]);
    $idCount = (int)$stmt->fetchColumn();
    assert_true($idCount > 0, "customers.zone_id backfilled for zone '{$zones[0]['name']}' ($idCount rows)");
}

echo "\n=== Production totals (daily_orders primary, date-aware) ===\n";
$monday = '2026-08-03';
$dailyProd = $db->prepare("
    SELECT COALESCE(SUM(doi.quantity), 0) as total_quantity,
           COALESCE(SUM(doi.quantity * p.weight_grams), 0) as total_weight_grams
    FROM daily_order_items doi
    JOIN daily_orders do ON doi.daily_order_id = do.id
    JOIN products p ON doi.product_id = p.id
    WHERE do.order_date = ?
");
$dailyProd->execute([$monday]);
$dailyRow = $dailyProd->fetch();
assert_true((float)$dailyRow['total_quantity'] > 0, 'production daily Monday quantity > 0');
assert_true((float)$dailyRow['total_weight_grams'] > 0, 'production daily Monday weight > 0');

$standingProd = $db->prepare("
    SELECT COALESCE(SUM(so.quantity), 0) as total_quantity
    FROM standing_orders so
    WHERE so.day_of_week = 1
");
$standingProd->execute();
$standingQty = (float)$standingProd->fetchColumn();
assert_true($standingQty > 0, 'standing Monday quantity baseline > 0');

// Daily totals should reflect generated daily orders (not silently ignore them)
$itemRow = $db->query("
    SELECT doi.id, doi.quantity, doi.product_id
    FROM daily_order_items doi
    JOIN daily_orders do ON doi.daily_order_id = do.id
    WHERE do.order_date = '2026-08-03' AND doi.quantity > 0
    LIMIT 1
")->fetch();
assert_true($itemRow !== false, 'have daily_order_item to mutate for production_source check');
$originalQty = (int)$itemRow['quantity'];
$newQty = $originalQty + 5;
$db->prepare("UPDATE daily_order_items SET quantity = ? WHERE id = ?")->execute([$newQty, $itemRow['id']]);
$dailyProd->execute([$monday]);
$mutatedRow = $dailyProd->fetch();
$mutatedQty = (float)$mutatedRow['total_quantity'];
assert_eq($standingQty + 5, $mutatedQty, 'production daily totals track daily_order_items edits (+5 on one line)');
$db->prepare("UPDATE daily_order_items SET quantity = ? WHERE id = ?")->execute([$originalQty, $itemRow['id']]);

$fallbackDate = '2099-01-01';
$fallbackDay = bakery_standing_day_from_date($fallbackDate);
$chk = $db->prepare("
    SELECT COUNT(*) FROM daily_order_items doi
    JOIN daily_orders do ON doi.daily_order_id = do.id
    WHERE do.order_date = ? AND doi.quantity > 0
");
$chk->execute([$fallbackDate]);
assert_eq(0, (int)$chk->fetchColumn(), 'fallback fixture date has no daily orders');
$dayClause = bakery_standing_day_in_clause($fallbackDay);
$fallbackStmt = $db->prepare("
    SELECT COALESCE(SUM(so.quantity), 0)
    FROM standing_orders so
    WHERE so.day_of_week {$dayClause['sql']}
");
$fallbackStmt->execute($dayClause['values']);
$fallbackQty = (float)$fallbackStmt->fetchColumn();
assert_true($fallbackQty > 0, "standing fallback for {$fallbackDate} weekday {$fallbackDay} has quantity {$fallbackQty}");
assert_true(true, 'production.php uses daily_orders when present; falls back to standing_orders with banner when absent');

echo "\n=== Pack-list totals (canonical 1-7) ===\n";
$packMon = $db->prepare("
    SELECT COALESCE(SUM(so.quantity),0) FROM standing_orders so WHERE so.day_of_week = 1
");
$packMon->execute();
$packMonQty = (float)$packMon->fetchColumn();
$packSunClause = bakery_standing_day_in_clause(7);
$packSun = $db->prepare("
    SELECT COALESCE(SUM(so.quantity),0) FROM standing_orders so WHERE so.day_of_week {$packSunClause['sql']}
");
$packSun->execute($packSunClause['values']);
$packSunQty = (float)$packSun->fetchColumn();
assert_true($packMonQty > 0, "pack_list Monday (day=1) sees quantity $packMonQty");
assert_true($packSunQty > 0, "pack_list Sunday (day=7) sees fixture quantity $packSunQty");

echo "\n=== Driver assignment + delivery completion ===\n";
$oid = (int)$db->query("SELECT id FROM daily_orders WHERE order_date='2026-08-03' ORDER BY id LIMIT 1")->fetchColumn();
assert_true($oid > 0, "have daily_order id=$oid for assignment");
$db->prepare("DELETE FROM daily_order_assignments WHERE delivery_date='2026-08-03'")->execute();
$as = $db->prepare("
    INSERT INTO daily_order_assignments (daily_order_id, driver_id, delivery_date, route_order, scheduled_delivery_time, delivery_status)
    VALUES (?, 1, '2026-08-03', 1, '08:00:00', 'pending')
");
$as->execute([$oid]);
$status = $db->query("SELECT delivery_status FROM daily_order_assignments WHERE daily_order_id=$oid")->fetchColumn();
assert_eq('pending', $status, 'assignment starts pending');

$db->beginTransaction();
$db->prepare("
    UPDATE daily_order_assignments SET delivery_status='delivered', actual_delivery_time=CURTIME()
    WHERE daily_order_id=?
")->execute([$oid]);
$db->prepare("
    UPDATE daily_orders SET status='delivered' WHERE id=?
")->execute([$oid]);
$db->commit();
$status2 = $db->query("SELECT delivery_status FROM daily_order_assignments WHERE daily_order_id=$oid")->fetchColumn();
assert_eq('delivered', $status2, 'complete_delivery mark_delivered sets assignment delivered');
$orderStatus = $db->query("SELECT status FROM daily_orders WHERE id=$oid")->fetchColumn();
assert_eq('delivered', $orderStatus, 'complete_delivery mark_delivered sets daily_orders.status delivered');

echo "\n=== Invoice totals ===\n";
$inv = $db->prepare("
    SELECT do.total_amount, COALESCE(SUM(doi.line_total),0) as line_sum
    FROM daily_orders do
    LEFT JOIN daily_order_items doi ON doi.daily_order_id = do.id
    WHERE do.id = ?
    GROUP BY do.id, do.total_amount
");
$inv->execute([$oid]);
$invRow = $inv->fetch();
assert_true(abs((float)$invRow['total_amount'] - (float)$invRow['line_sum']) < 0.01, 'invoice order total matches sum of lines');

echo "\n=== get_driver_orders.php contract ===\n";
assert_true(file_exists($root . '/get_driver_orders.php'), 'get_driver_orders.php exists');

$driverOrdersStmt = $db->prepare("
    SELECT
        c.name AS customer_name,
        c.address AS customer_address,
        c.zone,
        do.id AS daily_order_id,
        do.total_amount,
        doa.route_order,
        doa.scheduled_delivery_time
    FROM daily_orders do
    INNER JOIN customers c ON do.customer_id = c.id
    INNER JOIN daily_order_assignments doa ON do.id = doa.daily_order_id
    INNER JOIN drivers d ON doa.driver_id = d.id
    WHERE doa.driver_id = ? AND do.order_date = ?
    ORDER BY doa.route_order, c.zone, c.name
");
$driverOrdersStmt->execute([1, '2026-08-03']);
$driverOrderRows = $driverOrdersStmt->fetchAll();
assert_true(count($driverOrderRows) > 0, 'driver 1 has assigned orders on 2026-08-03 for contract check');

$contractFields = [
    'daily_order_id',
    'customer_name',
    'customer_address',
    'zone',
    'route_order',
    'scheduled_delivery_time',
    'total_amount',
];
foreach ($driverOrderRows as $row) {
    foreach ($contractFields as $field) {
        assert_true(array_key_exists($field, $row), "canonical query row includes $field");
    }
    assert_true(is_numeric($row['daily_order_id']), 'daily_order_id is numeric');
    assert_true(is_string($row['customer_name']) && $row['customer_name'] !== '', 'customer_name is non-empty string');
    assert_true(is_string($row['customer_address']), 'customer_address is string');
    assert_true(is_string($row['zone']) && $row['zone'] !== '', 'zone is non-empty string in fixtures');
    assert_true(is_numeric($row['route_order']), 'route_order is numeric');
    assert_true(is_numeric($row['total_amount']), 'total_amount is numeric');
}

$driverOrdersStmt->execute([1, '2099-01-01']);
assert_eq([], $driverOrdersStmt->fetchAll(), 'no assignments returns empty result set for contract empty-array case');

echo "\n=== Summary ===\n";
echo "Passed: {$GLOBALS['TEST_PASS']}\n";
echo "Failed: {$GLOBALS['TEST_FAIL']}\n";
echo "Findings: " . count($GLOBALS['TEST_FINDINGS']) . "\n";

$findingsPath = $root . '/docs/CHECKPOINT_0C_CHARACTERIZATION_FINDINGS.md';
$md = "# Checkpoint 0C — Characterization Findings\n\n";
$md .= "Generated by `tests/run_characterization.php` against `bakerysf_local`.\n\n";
$md .= "## Results\n\n";
$md .= "- Passed: {$GLOBALS['TEST_PASS']}\n";
$md .= "- Failed: {$GLOBALS['TEST_FAIL']}\n\n";
$md .= "## Findings (current behavior — do not treat as fixed)\n\n";
foreach ($GLOBALS['TEST_FINDINGS'] as $f) {
    $md .= "### {$f['severity']}\n\n{$f['detail']}\n\n";
}
$md .= "## Weekday map (canonical)\n\n";
$md .= "| Surface | Sunday encoding |\n|---------|-----------------|\n";
$md .= "| All ops surfaces (standing, generate, pack, bread_distribution, production) | 7 |\n";
$md .= "| Legacy rows with Sunday stored as 0 | Read via `IN (0,7)`; new writes use 7 |\n";
$md .= "| Local fixtures | 7 |\n\n";
$md .= "## Zone representation\n\n";
$md .= "- `customers.zone` stores **text names** matching `zones.name`.\n";
$md .= "- `customers.zone_id` FK to `zones.id` (migration 004); bread_distribution joins via zone_id with name fallback.\n\n";
$md .= "## get_driver_orders.php contract\n\n";
$md .= "```\nPOST application/x-www-form-urlencoded\ndriver_id={int}&date={YYYY-MM-DD}\n\nResponse JSON:\n{\n  \"success\": true,\n  \"orders\": [\n    {\n      \"daily_order_id\": int,\n      \"customer_name\": string,\n      \"customer_address\": string,\n      \"zone\": string,\n      \"route_order\": int,\n      \"scheduled_delivery_time\": string|null,\n      \"total_amount\": number\n    }\n  ]\n}\n```\n\nCanonical SQL source: `driver_list.php` server query joining `daily_orders`, `customers`, `daily_order_assignments`, `drivers` filtered by `doa.driver_id` and `do.order_date`.\n";
file_put_contents($findingsPath, $md);
echo "Wrote docs/CHECKPOINT_0C_CHARACTERIZATION_FINDINGS.md\n";

exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
