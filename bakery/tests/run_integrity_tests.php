<?php
/**
 * Checkpoint 0E integrity tests — zone name filtering and production guards.
 *
 * Usage:
 *   C:\php\php.exe bakery\tests\run_integrity_tests.php
 */
$root = dirname(__DIR__);

passthru('"' . PHP_BINARY . '" ' . escapeshellarg($root . '/scripts/setup_local_db.php') . ' --reset --force-reset', $setupCode);
if ($setupCode !== 0) {
    fwrite(STDERR, "Fixture reset failed\n");
    exit(1);
}

/** @var PDO $db */
$db = require __DIR__ . '/harness.php';

echo "\n=== Zone filter by name (bread_distribution shape) ===\n";
$zones = $db->query("SELECT id, name FROM zones ORDER BY id")->fetchAll();
$zoneName = $zones[0]['name'];

$stmt = $db->prepare("SELECT COUNT(*) FROM customers c WHERE c.zone = ?");
$stmt->execute([$zoneName]);
$filterCount = (int)$stmt->fetchColumn();
assert_true($filterCount > 0, "WHERE c.zone = zones.name returns rows for '$zoneName' ($filterCount)");

$stmt = $db->prepare("
    SELECT c.id, c.name, c.zone, c.zone_id, z.name AS zone_name
    FROM customers c
    " . bakery_customer_zone_join_sql() . "
    WHERE c.zone = ?
");
$stmt->execute([$zoneName]);
$rows = $stmt->fetchAll();
assert_true(count($rows) === $filterCount, 'name-based JOIN row count matches filter count');
foreach ($rows as $row) {
    assert_eq($zoneName, $row['zone'], "customer {$row['id']} zone text matches filter");
    assert_eq($zoneName, $row['zone_name'], "customer {$row['id']} JOIN resolves zone_name");
}

$stmt = $db->prepare("SELECT COUNT(*) FROM customers c WHERE c.zone = ?");
$stmt->execute([(string)$zones[0]['id']]);
$idFilterCount = (int)$stmt->fetchColumn();
assert_eq(0, $idFilterCount, 'filtering by zones.id as text still returns 0');

$colCheck = $db->prepare(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'zone_id'"
);
$colCheck->execute();
if ((int)$colCheck->fetchColumn() > 0) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM customers WHERE zone_id = ?");
    $stmt->execute([(int)$zones[0]['id']]);
    $zoneIdCount = (int)$stmt->fetchColumn();
    assert_true($zoneIdCount > 0, "zone_id backfill populated for '$zoneName' ($zoneIdCount rows)");
}

echo "\n=== Production missing-weight detection ===\n";
$db->beginTransaction();
try {
    $db->exec("INSERT INTO products (name, dough_type_id, price, weight_grams) VALUES ('Integrity Test No Weight', 1, 1.00, NULL)");
    $productId = (int)$db->lastInsertId();
    standing_save($db, 1, $productId, 1, 3);

    $stmt = $db->prepare("
        SELECT p.name, p.weight_grams, SUM(so.quantity) AS total_quantity
        FROM standing_orders so
        JOIN products p ON so.product_id = p.id
        WHERE so.day_of_week = 1 AND so.product_id = ?
        GROUP BY p.id, p.name, p.weight_grams
    ");
    $stmt->execute([$productId]);
    $row = $stmt->fetch();
    assert_true((int)$row['total_quantity'] > 0, 'fixture standing order exists for no-weight product');
    assert_true($row['weight_grams'] === null || (int)$row['weight_grams'] <= 0, 'product has missing weight');

    $wouldWarn = (int)$row['total_quantity'] > 0
        && ($row['weight_grams'] === null || (int)$row['weight_grams'] <= 0);
    assert_true($wouldWarn, 'production guard would flag missing weight_grams');

    $db->rollBack();
} catch (Exception $e) {
    $db->rollBack();
    throw $e;
}

echo "\n=== Production missing-formula detection ===\n";
$db->beginTransaction();
try {
    $db->exec("INSERT INTO dough_types (name, description, product_line_id) VALUES ('Integrity Test Dough', 'No formula', 1)");
    $doughTypeId = (int)$db->lastInsertId();
    $db->exec("INSERT INTO products (name, dough_type_id, price, weight_grams) VALUES ('Integrity Test Product', $doughTypeId, 2.00, 500)");
    $productId = (int)$db->lastInsertId();
    standing_save($db, 1, $productId, 1, 2);

    $stmt = $db->prepare("
        SELECT dt.name AS dough_type_name, SUM(so.quantity) AS total_quantity
        FROM standing_orders so
        JOIN products p ON so.product_id = p.id
        LEFT JOIN dough_types dt ON p.dough_type_id = dt.id
        WHERE so.day_of_week = 1 AND p.dough_type_id = ?
        GROUP BY dt.name
    ");
    $stmt->execute([$doughTypeId]);
    $row = $stmt->fetch();

    $formulaStmt = $db->prepare("SELECT COALESCE(SUM(percentage), 0) FROM formula_ingredients WHERE dough_type_id = ?");
    $formulaStmt->execute([$doughTypeId]);
    $totalPct = (float)$formulaStmt->fetchColumn();

    assert_true((int)$row['total_quantity'] > 0, 'standing order exists for dough type without formula');
    assert_eq(0.0, $totalPct, 'dough type has no formula ingredients');
    assert_true($totalPct <= 0, 'production guard would flag missing formula');

    $db->rollBack();
} catch (Exception $e) {
    $db->rollBack();
    throw $e;
}

echo "\n=== Production missing-weight detection (daily_orders path) ===\n";
$db->beginTransaction();
try {
    $db->exec("INSERT INTO products (name, dough_type_id, price, weight_grams) VALUES ('Integrity Daily No Weight', 1, 1.00, NULL)");
    $productId = (int)$db->lastInsertId();
    $orderDate = '2099-06-01';
    $db->prepare("INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (1, ?, 'pending', 0)")
        ->execute([$orderDate]);
    $dailyOrderId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, 4, 1.00, 4.00)")
        ->execute([$dailyOrderId, $productId]);

    $stmt = $db->prepare("
        SELECT p.name, p.weight_grams, SUM(doi.quantity) AS total_quantity
        FROM daily_order_items doi
        JOIN daily_orders do ON doi.daily_order_id = do.id
        JOIN products p ON doi.product_id = p.id
        WHERE do.order_date = ? AND doi.product_id = ?
        GROUP BY p.id, p.name, p.weight_grams
    ");
    $stmt->execute([$orderDate, $productId]);
    $row = $stmt->fetch();
    assert_true((int)$row['total_quantity'] > 0, 'daily order item exists for no-weight product');
    assert_true($row['weight_grams'] === null || (int)$row['weight_grams'] <= 0, 'daily product has missing weight');

    $wouldWarn = (int)$row['total_quantity'] > 0
        && ($row['weight_grams'] === null || (int)$row['weight_grams'] <= 0);
    assert_true($wouldWarn, 'production daily path would flag missing weight_grams');

    $db->rollBack();
} catch (Exception $e) {
    $db->rollBack();
    throw $e;
}

echo "\n=== Ingredient low-stock detection ===\n";
assert_true(bakery_ingredients_inventory_ready($db), 'migration 005 inventory columns present');

$db->beginTransaction();
try {
    $db->exec(
        "INSERT INTO ingredients (name, unit, quantity_on_hand, reorder_level, supplier_name)
         VALUES ('Integrity Test Low Stock', 'kg', 5.000, 10.000, 'Test Supplier')"
    );
    $lowId = (int)$db->lastInsertId();

    $db->exec(
        "INSERT INTO ingredients (name, unit, quantity_on_hand, reorder_level)
         VALUES ('Integrity Test OK Stock', 'kg', 50.000, 10.000)"
    );
    $okId = (int)$db->lastInsertId();

    $lowStock = bakery_low_stock_ingredients($db);
    $lowIds = array_map('intval', array_column($lowStock, 'id'));
    assert_true(in_array($lowId, $lowIds, true), 'fixture low-stock ingredient detected');
    assert_true(!in_array($okId, $lowIds, true), 'well-stocked fixture ingredient excluded');

    $fixture = null;
    foreach ($lowStock as $row) {
        if ((int)$row['id'] === $lowId) {
            $fixture = $row;
            break;
        }
    }
    assert_true($fixture !== null, 'low-stock helper returns fixture row');
    assert_eq('Integrity Test Low Stock', $fixture['name'], 'low-stock row name matches fixture');
    assert_true(bakery_ingredient_is_low_stock($fixture), 'bakery_ingredient_is_low_stock true for fixture');

    $db->rollBack();
} catch (Exception $e) {
    $db->rollBack();
    throw $e;
}

echo "\n=== Summary ===\n";
echo "Passed: {$GLOBALS['TEST_PASS']}\n";
echo "Failed: {$GLOBALS['TEST_FAIL']}\n";

exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
