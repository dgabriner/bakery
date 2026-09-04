<?php
/**
 * Checkpoint 0E integrity tests — zone name filtering and production guards.
 *
 * Usage:
 *   C:\php\php.exe bakery\tests\run_integrity_tests.php
 */
$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
bakery_reset_isolated_test_db($root);

/** @var PDO $db */
$db = require __DIR__ . '/harness.php';
$integrityCustomerId = (int)$db->query("SELECT id FROM customers ORDER BY id LIMIT 1")->fetchColumn();
if ($integrityCustomerId <= 0) {
    throw new RuntimeException('Production-derived test clone has no customer for integrity checks');
}

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
    standing_save($db, $integrityCustomerId, $productId, 1, 3);

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
    standing_save($db, $integrityCustomerId, $productId, 1, 2);

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
    $db->prepare("INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES ({$integrityCustomerId}, ?, 'pending', 0)")
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

echo "\n=== column_exists on underscored table names ===\n";
assert_true(
    table_exists($db, 'daily_order_assignments'),
    'daily_order_assignments table exists in fixtures'
);
assert_true(
    column_exists($db, 'daily_order_assignments', 'notes'),
    'column_exists finds notes on daily_order_assignments (underscore table name)'
);
assert_true(
    table_exists($db, 'login_audit_activity'),
    'login_audit_activity exists after 036 (underscore table name)'
);

echo "\n=== Ingredient low-stock detection ===\n";
assert_true(bakery_ingredients_inventory_ready($db), 'migration 005 inventory columns present');
assert_true(bakery_ingredients_purchasing_ready($db), 'migration 017 purchasing columns present');

$db->beginTransaction();
try {
    $db->exec(
        "INSERT INTO ingredients (name, unit, quantity_on_hand, reorder_level, supplier_name, package_size, unit_cost)
         VALUES ('Integrity Test Low Stock', 'kg', 5.000, 10.000, 'Test Supplier', 25.000, 42.50)"
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
    assert_eq('25 kg', bakery_ingredient_package_label(['package_size' => 25, 'unit' => 'kg']), 'package label uses size and unit');

    $db->rollBack();
} catch (Exception $e) {
    $db->rollBack();
    throw $e;
}

echo "\n=== Zones catalog single source of truth (invariant 4.14) ===\n";
if (!function_exists('bakery_zones_catalog')) {
    require_once $root . '/includes/zones_catalog.php';
}
assert_true(function_exists('bakery_zones_catalog'), 'bakery_zones_catalog helper loads function-safe');
assert_true(function_exists('bakery_zones_catalog_ready'), 'bakery_zones_catalog_ready helper loads function-safe');
assert_true(function_exists('bakery_zones_legacy_list'), 'bakery_zones_legacy_list helper loads function-safe');
assert_true(function_exists('bakery_zones_legacy_rows'), 'bakery_zones_legacy_rows helper loads function-safe');
assert_true(function_exists('bakery_zone_color'), 'bakery_zone_color helper loads function-safe');
assert_true(function_exists('bakery_zone_display_cycle'), 'bakery_zone_display_cycle helper loads function-safe');
assert_true(function_exists('bakery_zone_route_color'), 'bakery_zone_route_color helper loads function-safe');

$zonePageContract = [
    'driver.php' => ['bakery_zones_catalog(', 'bakery_zone_display_cycle(', 'bakery_zone_route_color('],
    'driver_overview.php' => ['bakery_zones_catalog(', 'bakery_zone_display_cycle(', 'bakery_zone_route_color('],
    'driver_list.php' => ['bakery_zones_catalog(', 'bakery_zone_display_cycle(', 'bakery_zone_route_color('],
    'customers.php' => ['bakery_zones_catalog('],
    'customer_schedule.php' => ['bakery_zones_catalog('],
];
foreach ($zonePageContract as $zonePage => $requiredCalls) {
    $pageSource = @file_get_contents($root . '/' . $zonePage);
    if (!assert_true(is_string($pageSource) && $pageSource !== '', "$zonePage readable for zone source contract")) {
        continue;
    }
    assert_true(
        strpos($pageSource, 'includes/zones_catalog.php') !== false,
        "$zonePage requires includes/zones_catalog.php"
    );
    foreach ($requiredCalls as $requiredCall) {
        assert_true(strpos($pageSource, $requiredCall) !== false, "$zonePage calls $requiredCall");
    }
    assert_true(
        strpos($pageSource, "'#6610f2'") === false,
        "$zonePage no longer declares a local 15-color zone palette"
    );
    assert_true(
        strpos($pageSource, "'Ruta Sour Flour'") === false,
        "$zonePage no longer declares a local hardcoded zone-name list"
    );
}
$mapSource = @file_get_contents($root . '/map.php');
if (assert_true(is_string($mapSource) && $mapSource !== '', 'map.php readable for zone source contract')) {
    assert_true(
        strpos($mapSource, 'SELECT name, color FROM zones ORDER BY name') !== false,
        'map.php retains its guarded table-first zones query'
    );
}

assert_eq(
    ['Centro', 'Mission', 'Ruta Sour Flour', 'Daly City/San Mateo', 'North Bay', 'East Bay'],
    bakery_zones_legacy_list(),
    'legacy fallback equals the documented six zones in historical order'
);
assert_eq(
    ['#007bff', '#dc3545', '#28a745', '#fd7e14', '#6f42c1', '#20c997'],
    array_column(bakery_zones_legacy_rows(), 'color'),
    'legacy fallback colors match the zones.php seed values'
);

$catalogTableNames = $db->query("SELECT TRIM(name) FROM zones ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$catalogRows = bakery_zones_catalog($db);
assert_true(count($catalogRows) > 0, 'zones catalog returns rows from the populated zones table');
assert_eq(
    array_map('strval', $catalogTableNames),
    array_column($catalogRows, 'name'),
    'catalog names and order match SELECT name FROM zones ORDER BY name'
);
$catalogShapeOk = true;
foreach ($catalogRows as $catalogRow) {
    if (!isset($catalogRow['name'], $catalogRow['color'])
        || trim((string)$catalogRow['name']) === ''
        || preg_match('/^#[0-9a-f]{6}$/', (string)$catalogRow['color']) !== 1) {
        $catalogShapeOk = false;
        break;
    }
}
assert_true($catalogShapeOk, 'every catalog row carries a name plus validated hex color');
assert_true(bakery_zones_catalog_ready($db), 'catalog ready flag true while zones table holds rows');

$db->exec("CREATE TEMPORARY TABLE zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    color VARCHAR(7) DEFAULT '#007bff',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
try {
    $emptyTableCatalog = bakery_zones_catalog($db);
    assert_eq(
        bakery_zones_legacy_rows(),
        $emptyTableCatalog,
        'empty zones table falls back to the canonical legacy rows'
    );
    assert_true(!bakery_zones_catalog_ready($db), 'catalog ready flag false while zones table is empty');

    assert_eq('#dc3545', bakery_zone_color($emptyTableCatalog, 'mission'), 'zone color lookup is case-insensitive');
    assert_eq('#dc3545', bakery_zone_color($emptyTableCatalog, '  Mission  '), 'zone color lookup trims whitespace');
    assert_eq('', bakery_zone_color($emptyTableCatalog, 'Atlantis'), 'unknown zone yields the empty default');
    assert_eq('#123abc', bakery_zone_color($emptyTableCatalog, 'atlantis', '#123abc'), 'unknown zone honors caller-supplied default');

    $stmt = $db->prepare("INSERT INTO zones (name, description, color) VALUES (?, ?, ?)");
    $stmt->execute(['Harbor Fog', 'integrity temp fixture', '#FFAA01']);
    $stmt->execute(['Atlantis', 'integrity temp fixture', '#GGHH']);

    $fixtureCatalog = bakery_zones_catalog($db);
    assert_eq(
        ['Atlantis', 'Harbor Fog'],
        array_column($fixtureCatalog, 'name'),
        'non-empty zones table wins over legacy fallback, ordered by name'
    );
    assert_eq(
        '#ffaa01',
        bakery_zone_color($fixtureCatalog, 'harbor fog'),
        'table colors normalize to lowercase #rrggbb'
    );
    assert_eq(
        '#6c757d',
        bakery_zone_color($fixtureCatalog, 'ATLANTIS'),
        'invalid table colors degrade to the neutral presentation gray'
    );

    $zoneCycle = bakery_zone_display_cycle();
    assert_eq('#007bff', bakery_zone_route_color([], 'Mission', $zoneCycle, 0), 'legacy-named zones keep encounter-order tints');
    assert_eq('#28a745', bakery_zone_route_color([], 'East Bay', $zoneCycle, 1), 'second-seen legacy zone keeps its cycle tint');
    assert_eq('#ffaa01', bakery_zone_route_color($fixtureCatalog, 'Harbor Fog', $zoneCycle, 0), 'post-migration zones light up with their table color');
    assert_eq('#ffc107', bakery_zone_route_color([], 'No Zone', $zoneCycle, 6), 'untracked zones keep cycling as before');
    assert_eq('#007bff', bakery_zone_route_color([], 'Overflow Zone', $zoneCycle, 15), 'tint cycle wraps around past its length');
} finally {
    $db->exec("DROP TEMPORARY TABLE IF EXISTS zones");
}
assert_true(bakery_zones_catalog_ready($db), 'real zones table visible again after temporary fixture dropped');

echo "\n=== One mutation path: standing_orders INSERT only in customer_order_mutations ===\n";
$standingInsertFiles = [];
foreach (array_merge(
    glob($root . '/*.php') ?: [],
    glob($root . '/includes/*.php') ?: []
) as $phpFile) {
    $rel = str_replace('\\', '/', substr($phpFile, strlen($root) + 1));
    if ($rel === 'includes/customer_order_mutations.php') {
        continue;
    }
    $src = (string)file_get_contents($phpFile);
    if (preg_match('/INSERT\s+INTO\s+standing_orders\b/i', $src)) {
        $standingInsertFiles[] = $rel;
    }
}
assert_true(
    $standingInsertFiles === [],
    'no root/includes INSERT INTO standing_orders outside customer_order_mutations.php (' . implode(', ', $standingInsertFiles) . ')'
);

echo "\n=== Summary ===\n";
echo "Passed: {$GLOBALS['TEST_PASS']}\n";
echo "Failed: {$GLOBALS['TEST_FAIL']}\n";

exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
