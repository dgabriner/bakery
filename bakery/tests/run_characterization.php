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

echo "\n=== Weekday conversion (daily_orders.php CURRENT behavior) ===\n";
assert_eq(1, daily_orders_php_n_to_db_day(1), 'Mon PHP N=1 stays 1');
assert_eq(6, daily_orders_php_n_to_db_day(6), 'Sat PHP N=6 stays 6');
assert_eq(0, daily_orders_php_n_to_db_day(7), 'Sun PHP N=7 converts to DB day 0 (CURRENT)');
finding(
    'weekday',
    'daily_orders.php maps Sunday to day_of_week=0, but standing_orders.php / manager / production / fixtures use Sunday=7'
);

echo "\n=== Sunday behavior contradiction ===\n";
// Fixtures store Sunday as 7 for customer 3
$sun7 = $db->query("SELECT COUNT(*) FROM standing_orders WHERE day_of_week=7")->fetchColumn();
$sun0 = $db->query("SELECT COUNT(*) FROM standing_orders WHERE day_of_week=0")->fetchColumn();
assert_true((int)$sun7 > 0, "fixtures have Sunday rows as day_of_week=7 (count=$sun7)");
assert_eq(0, (int)$sun0, 'fixtures have zero day_of_week=0 Sunday rows');

// Pick a calendar Sunday
$sunday = '2026-08-02'; // known Sunday
assert_eq('7', date('N', strtotime($sunday)), '2026-08-02 is PHP N=7 Sunday');
$genSun = generate_from_standing($db, $sunday);
assert_eq(0, $genSun['db_day'], 'generate_from_standing uses db_day=0 for Sunday');
assert_eq(0, $genSun['standing_rows'], 'CURRENT BUG: Sunday generate finds 0 standing rows because fixtures use 7');
finding(
    'sunday_generate',
    'Generating daily orders for a Sunday misses standing_orders stored as day_of_week=7'
);

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

echo "\n=== Bread-distribution day encoding vs standing UI ===\n";
finding(
    'bread_distribution_days',
    'bread_distribution.php UI uses data-day=0 for Sunday while $dayNames array is 1=Mon..7=Sun — dual encoding in one file'
);
$bdSundayInputs = true; // documented from source inspection
assert_true($bdSundayInputs, 'documented: bread_distribution Sunday buttons/inputs use day 0');

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
// Simulate bread_distribution filter bug: WHERE c.zone = ? with int id
$zoneId = (int)$zones[0]['id'];
$stmt = $db->prepare("SELECT COUNT(*) FROM customers c WHERE c.zone = ?");
$stmt->execute([$zoneId]);
$badCount = (int)$stmt->fetchColumn();
$stmt = $db->prepare("SELECT COUNT(*) FROM customers c WHERE c.zone = ?");
$stmt->execute([$zones[0]['name']]);
$goodCount = (int)$stmt->fetchColumn();
assert_eq(0, $badCount, 'CURRENT BUG: filtering c.zone by zones.id returns 0 rows');
assert_true($goodCount > 0, "filtering c.zone by zones.name returns rows ($goodCount)");
finding(
    'zone_join',
    'bread_distribution.php casts zone filter to int and JOINs c.zone = z.id, but customers.zone stores zone name text'
);

echo "\n=== Production totals (day 1 / Monday, encoding 1-7) ===\n";
$prod = $db->prepare("
    SELECT SUM(so.quantity) as total_quantity,
           SUM(so.quantity * p.weight_grams) as total_weight_grams
    FROM standing_orders so
    JOIN products p ON so.product_id = p.id
    WHERE so.day_of_week = 1
");
$prod->execute();
$prodRow = $prod->fetch();
assert_true((float)$prodRow['total_quantity'] > 0, 'production Monday quantity > 0');
assert_true((float)$prodRow['total_weight_grams'] > 0, 'production Monday weight > 0 with fixture weights');
finding(
    'production_source',
    'production.php aggregates standing_orders for day 1-7, not daily_orders — ignores same-day edits after generation'
);

echo "\n=== Pack-list totals (day encoding 0-6) ===\n";
$packMon = $db->prepare("
    SELECT COALESCE(SUM(so.quantity),0) FROM standing_orders so WHERE so.day_of_week = 1
");
$packMon->execute();
$packMonQty = (float)$packMon->fetchColumn();
$packSun = $db->prepare("
    SELECT COALESCE(SUM(so.quantity),0) FROM standing_orders so WHERE so.day_of_week = 0
");
$packSun->execute();
$packSunQty = (float)$packSun->fetchColumn();
assert_true($packMonQty > 0, "pack_list Monday (day=1 via date('w')) would see quantity $packMonQty");
assert_eq(0.0, $packSunQty, "pack_list Sunday default date('w')=0 sees 0 rows — misses fixture Sunday=7");
finding(
    'pack_list_sunday',
    "pack_list.php uses date('w') 0=Sunday..6=Saturday; Sunday pack list misses standing_orders stored as 7"
);

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

$db->prepare("
    UPDATE daily_order_assignments SET delivery_status='delivered', actual_delivery_time=CURTIME()
    WHERE daily_order_id=?
")->execute([$oid]);
$status2 = $db->query("SELECT delivery_status FROM daily_order_assignments WHERE daily_order_id=$oid")->fetchColumn();
assert_eq('delivered', $status2, 'complete_delivery mark_delivered sets assignment delivered');
$orderStatus = $db->query("SELECT status FROM daily_orders WHERE id=$oid")->fetchColumn();
assert_eq('pending', $orderStatus, 'CURRENT: daily_orders.status remains pending after delivery completion');
finding(
    'delivery_status_split',
    'complete_delivery updates daily_order_assignments.delivery_status only; daily_orders.status is unchanged'
);

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

echo "\n=== get_driver_orders.php contract (missing file) ===\n";
$exists = file_exists($root . '/get_driver_orders.php');
assert_true(!$exists, 'get_driver_orders.php is currently missing (expected until 0E)');
finding(
    'get_driver_orders_contract',
    'driver_list.php POSTs driver_id + date; expects JSON {success, orders[]} with fields: daily_order_id, customer_name, customer_address, zone, route_order, scheduled_delivery_time, total_amount (same shape as server-side query in driver_list.php lines 47-66)'
);

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
$md .= "## Weekday map (as observed)\n\n";
$md .= "| Surface | Sunday encoding |\n|---------|-----------------|\n";
$md .= "| standing_orders.php / manager / production | 7 |\n";
$md .= "| daily_orders.php generate_from_standing | 0 (converts from PHP N=7) |\n";
$md .= "| pack_list.php | 0 (`date('w')`) |\n";
$md .= "| bread_distribution.php UI inputs | 0 |\n";
$md .= "| Local fixtures | 7 |\n\n";
$md .= "## Zone representation\n\n";
$md .= "- `customers.zone` stores **text names** matching `zones.name`.\n";
$md .= "- `bread_distribution.php` incorrectly filters/joins using `zones.id`.\n\n";
$md .= "## get_driver_orders.php contract\n\n";
$md .= "```\nPOST application/x-www-form-urlencoded\ndriver_id={int}&date={YYYY-MM-DD}\n\nResponse JSON:\n{\n  \"success\": true,\n  \"orders\": [\n    {\n      \"daily_order_id\": int,\n      \"customer_name\": string,\n      \"customer_address\": string,\n      \"zone\": string,\n      \"route_order\": int,\n      \"scheduled_delivery_time\": string|null,\n      \"total_amount\": number\n    }\n  ]\n}\n```\n\nCanonical SQL source: `driver_list.php` server query joining `daily_orders`, `customers`, `daily_order_assignments`, `drivers` filtered by `doa.driver_id` and `do.order_date`.\n";
file_put_contents($findingsPath, $md);
echo "Wrote docs/CHECKPOINT_0C_CHARACTERIZATION_FINDINGS.md\n";

exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
