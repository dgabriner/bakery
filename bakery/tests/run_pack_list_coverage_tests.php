<?php
/**
 * Pack List regression: per-product ordered vs produced vs still-short,
 * multiple stores and drivers, a saved pack list, and zero-quantity lines.
 *
 * CLI / local bakerysf_test only. Renders pack_list.php in-process (no HTTP)
 * and deletes the far-future rows it inserts.
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

require_once $root . '/includes/pack_list.php';
require_once $root . '/includes/product_inventory.php';
require_once $root . '/includes/demand_review.php';

function pack_coverage_capture(string $date): array
{
    global $db;
    $_GET = ['date' => $date, 'view' => 'route'];
    $_REQUEST = $_GET;
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['PHP_SELF'] = '/pack_list.php';
    $previous = getcwd();
    chdir(dirname(__DIR__));
    ob_start();
    try {
        @include 'pack_list.php';
    } finally {
        $html = (string)ob_get_clean();
        if ($previous !== false) {
            chdir($previous);
        }
    }
    return [
        'html' => $html,
        'by_product' => $byProduct ?? [],
        'by_route' => $byRouteList ?? [],
        'by_customer' => $byCustomer ?? [],
        'produced' => $producedByProduct ?? [],
        'error' => $error ?? null,
    ];
}

function pack_coverage_index_products(array $byProduct): array
{
    $out = [];
    foreach ($byProduct as $row) {
        $out[(int)$row['product_id']] = $row;
    }
    return $out;
}

function pack_coverage_wipe(PDO $db, string $date, array $customerIds, array $productIds): void
{
    if (table_exists($db, 'pack_progress')) {
        $db->prepare('DELETE FROM pack_progress WHERE pack_date = ?')->execute([$date]);
    }
    if (table_exists($db, 'inventory_movements')) {
        $db->prepare('DELETE FROM inventory_movements WHERE delivery_date = ?')->execute([$date]);
    }
    if (table_exists($db, 'product_inventory_days') && $productIds !== []) {
        $in = implode(',', array_map('intval', $productIds));
        $db->exec("DELETE FROM product_inventory_days WHERE delivery_date = " . $db->quote($date) . " AND product_id IN ({$in})");
    }
    $orderIds = $db->prepare('SELECT id FROM daily_orders WHERE order_date = ?');
    $orderIds->execute([$date]);
    $ids = array_map('intval', $orderIds->fetchAll(PDO::FETCH_COLUMN));
    if ($customerIds !== []) {
        $cin = implode(',', array_map('intval', $customerIds));
        $extra = $db->query("SELECT id FROM daily_orders WHERE customer_id IN ({$cin})")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($extra as $id) {
            $ids[] = (int)$id;
        }
        $ids = array_values(array_unique($ids));
    }
    if ($ids !== []) {
        $in = implode(',', $ids);
        if (table_exists($db, 'inventory_movements') && column_exists($db, 'inventory_movements', 'daily_order_id')) {
            $db->exec("DELETE FROM inventory_movements WHERE daily_order_id IN ({$in})");
        }
        $db->exec("DELETE FROM daily_order_assignments WHERE daily_order_id IN ({$in})");
        $db->exec("DELETE FROM daily_order_items WHERE daily_order_id IN ({$in})");
        $db->exec("DELETE FROM daily_orders WHERE id IN ({$in})");
    }
    if ($customerIds !== []) {
        $cin = implode(',', array_map('intval', $customerIds));
        $db->exec("DELETE FROM customers WHERE id IN ({$cin})");
    }
    if ($productIds !== []) {
        $pin = implode(',', array_map('intval', $productIds));
        $db->exec("DELETE FROM products WHERE id IN ({$pin}) AND name LIKE '\\_\\_pack\\_%'");
    }
}

$date = '2097-08-17';
$customerIds = [];
$createdProductIds = [];

try {
    $weekday = (int)date('N', strtotime($date));
    assert_true($weekday >= 1 && $weekday <= 7, 'pack coverage date is a real weekday');

    $drivers = array_map('intval', $db->query('SELECT id FROM drivers ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
    assert_eq(2, count($drivers), 'two fixture drivers are available for pack routes');
    $productIds = array_map('intval', $db->query('SELECT id FROM products ORDER BY id LIMIT 3')->fetchAll(PDO::FETCH_COLUMN));
    $productA = (int)($productIds[0] ?? 0);
    $productB = (int)($productIds[1] ?? 0);
    $productC = (int)($productIds[2] ?? 0);
    assert_true($productA > 0 && $productB > 0 && $productC > 0, 'three fixture products are available');

    pack_coverage_wipe($db, $date, [], []);

    $insertCustomer = $db->prepare(
        'INSERT INTO customers (name, zone, email, payment_collection, is_active) VALUES (?, ?, ?, ?, 1)'
    );
    $insertCustomer->execute(['__pack_store_north', 'North Zone', 'pack-north@example.test', 'signature']);
    $storeNorth = (int)$db->lastInsertId();
    $customerIds[] = $storeNorth;
    $insertCustomer->execute(['__pack_store_south', 'South Zone', 'pack-south@example.test', 'cod']);
    $storeSouth = (int)$db->lastInsertId();
    $customerIds[] = $storeSouth;

    $insertOrder = $db->prepare(
        "INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, 'pending', 0)"
    );
    $insertItem = $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, ?, 1.00, 0)'
    );
    $insertAssign = $db->prepare(
        "INSERT INTO daily_order_assignments
         (daily_order_id, driver_id, delivery_date, route_order, delivery_status)
         VALUES (?, ?, ?, ?, 'pending')"
    );

    $insertOrder->execute([$storeNorth, $date]);
    $orderNorth = (int)$db->lastInsertId();
    $insertItem->execute([$orderNorth, $productA, 6]);
    $insertItem->execute([$orderNorth, $productB, 2]);
    $insertItem->execute([$orderNorth, $productC, 0]);
    $insertAssign->execute([$orderNorth, $drivers[0], $date, 1]);

    $insertOrder->execute([$storeSouth, $date]);
    $orderSouth = (int)$db->lastInsertId();
    $insertItem->execute([$orderSouth, $productA, 3]);
    $insertAssign->execute([$orderSouth, $drivers[1], $date, 1]);

    $lines = bakery_operating_demand_lines($db, $date);
    $ordered = [];
    $zeroSeen = false;
    foreach ($lines as $line) {
        $pid = (int)$line['product_id'];
        $qty = (int)$line['quantity'];
        if ($qty <= 0) {
            $zeroSeen = true;
        }
        $ordered[$pid] = ($ordered[$pid] ?? 0) + $qty;
    }
    assert_true(!$zeroSeen, 'operating demand lines omit zero-quantity rows');
    assert_true(($ordered[$productA] ?? 0) >= 9, 'product A ordered total includes both stores (6 + 3) plus any standing');
    assert_true(($ordered[$productB] ?? 0) >= 2, 'product B ordered total includes the north store');

    bakery_inventory_ensure_day($db, $date, $productA);
    bakery_inventory_ensure_day($db, $date, $productB);
    $db->prepare(
        'UPDATE product_inventory_days
         SET available_quantity = ?, produced_quantity = ?, loaded_quantity = ?
         WHERE delivery_date = ? AND product_id = ?'
    )->execute([1, 4, 2, $date, $productA]);
    $db->prepare(
        'UPDATE product_inventory_days
         SET available_quantity = ?, produced_quantity = ?, loaded_quantity = ?
         WHERE delivery_date = ? AND product_id = ?'
    )->execute([100000, 100000, 0, $date, $productB]);

    $countRows = bakery_pack_day_count_rows($db, $date);
    $countById = [];
    foreach ($countRows as $row) {
        $countById[(int)$row['product_id']] = $row;
    }
    assert_true(isset($countById[$productA]), 'pack count rows include product A');
    $rowA = $countById[$productA];
    $expectedShortA = max(0, (int)$ordered[$productA] - (1 + 2));
    assert_eq((int)$ordered[$productA], (int)$rowA['supposed'], 'product A supposed matches ordered demand');
    assert_eq(4, (int)$rowA['produced'], 'product A produced quantity is the inventory produced_quantity');
    assert_eq(3, (int)$rowA['covered'], 'product A covered is available plus loaded');
    assert_eq($expectedShortA, (int)$rowA['short'], 'product A still-short is ordered minus covered');
    assert_true($expectedShortA > 0, 'fixture plus the two stores leaves product A short');
    assert_eq(false, (bool)$rowA['matches'], 'a short product does not match');
    if (isset($countById[$productB])) {
        assert_eq((int)$ordered[$productB], (int)$countById[$productB]['supposed'], 'product B supposed matches ordered demand');
        assert_eq(100000, (int)$countById[$productB]['produced'], 'product B produced quantity is recorded');
        assert_eq(0, (int)$countById[$productB]['short'], 'product B is not short when covered stock meets the order');
    }

    $page = pack_coverage_capture($date);
    assert_true($page['error'] === null, 'pack list page loads the date without an error');
    $pageProducts = pack_coverage_index_products($page['by_product']);
    assert_true(isset($pageProducts[$productA]), 'pack list product view includes product A');
    assert_eq((int)$ordered[$productA], (int)$pageProducts[$productA]['total'], 'pack list product total equals ordered units');
    assert_eq($expectedShortA, (int)($pageProducts[$productA]['short'] ?? -1), 'pack list still-short matches ordered minus on-hand and loaded');
    assert_eq(4, (int)($page['produced'][$productA] ?? -1), 'pack list produced column reads produced_quantity');
    assert_true(isset($pageProducts[$productB]), 'pack list product view includes product B');
    assert_eq((int)$ordered[$productB], (int)$pageProducts[$productB]['total'], 'pack list product B total equals ordered units');

    $northLines = 0;
    $southLines = 0;
    foreach ($pageProducts[$productA]['customers'] as $customerLine) {
        if ((int)$customerLine['customer_id'] === $storeNorth) {
            $northLines++;
            assert_eq(6, (int)$customerLine['quantity'], 'north store product A line is 6, ignoring the zero-quantity duplicate');
        }
        if ((int)$customerLine['customer_id'] === $storeSouth) {
            $southLines++;
            assert_eq(3, (int)$customerLine['quantity'], 'south store product A line is 3');
        }
        assert_true((int)$customerLine['quantity'] > 0, 'pack list drops zero-quantity lines from the product');
    }
    assert_eq(1, $northLines, 'north store appears once on product A');
    assert_eq(1, $southLines, 'south store appears once on product A');

    $routes = [];
    foreach ($page['by_route'] as $route) {
        $routes[(int)$route['driver_id']] = $route;
    }
    assert_true(isset($routes[$drivers[0]]) && isset($routes[$drivers[1]]), 'pack list groups stops under both drivers');
    $customerOnRoute = static function (array $route, int $customerId): ?array {
        foreach ($route['customers'] as $customer) {
            if ((int)$customer['customer_id'] === $customerId) {
                return $customer;
            }
        }
        return null;
    };
    $northOnA = $customerOnRoute($routes[$drivers[0]], $storeNorth);
    $southOnA = $customerOnRoute($routes[$drivers[0]], $storeSouth);
    $northOnB = $customerOnRoute($routes[$drivers[1]], $storeNorth);
    $southOnB = $customerOnRoute($routes[$drivers[1]], $storeSouth);
    assert_true(is_array($northOnA), 'driver A route lists the north store');
    assert_true($southOnA === null, 'driver A route does not list the south store');
    assert_true(is_array($southOnB), 'driver B route lists the south store');
    assert_true($northOnB === null, 'driver B route does not list the north store');
    assert_eq(8, (int)$northOnA['total'], 'north store route total is 6 + 2 and excludes the zero line');
    assert_eq(3, (int)$southOnB['total'], 'south store route total is 3');
    $northProductIds = array_map(static function ($line) {
        return (int)$line['product_id'];
    }, $northOnA['products']);
    assert_true(in_array($productA, $northProductIds, true) && in_array($productB, $northProductIds, true), 'north store lists both positive products');
    assert_true(!in_array($productC, $northProductIds, true), 'north store omits the zero-quantity product');
    assert_eq(2, count($northOnA['products']), 'north store pack lines are the two positive quantities only');

    $lineKey = bakery_pack_line_key($storeNorth, $productA);
    assert_true(bakery_pack_progress_ready($db), 'pack_progress table is installed');
    bakery_pack_set_checked($db, $date, $lineKey, true, null);
    $saved = $db->prepare('SELECT COUNT(*) FROM pack_progress WHERE pack_date = ? AND line_key = ?');
    $saved->execute([$date, $lineKey]);
    assert_eq(1, (int)$saved->fetchColumn(), 'saved pack list stores the checked line');
    $savedPage = pack_coverage_capture($date);
    assert_true(
        strpos($savedPage['html'], 'data-check-key="' . $lineKey . '"') !== false
            && strpos($savedPage['html'], 'pack-line--checked') !== false,
        'saved pack list renders the line as checked'
    );
    bakery_pack_set_checked($db, $date, $lineKey, false, null);
    $saved->execute([$date, $lineKey]);
    assert_eq(0, (int)$saved->fetchColumn(), 'clearing a saved pack line removes the row');

    $marked = bakery_pack_mark_keys($db, $date, [$lineKey, bakery_pack_line_key($storeSouth, $productA), 'bad'], null);
    assert_true($marked >= 2, 'pack-all saves the remaining store lines and ignores a bad key');
    $keys = $db->prepare('SELECT line_key FROM pack_progress WHERE pack_date = ? ORDER BY line_key');
    $keys->execute([$date]);
    $found = $keys->fetchAll(PDO::FETCH_COLUMN);
    assert_true(in_array($lineKey, $found, true), 'saved pack list keeps the north line');
    assert_true(in_array(bakery_pack_line_key($storeSouth, $productA), $found, true), 'saved pack list keeps the south line');
    assert_true(!in_array('bad', $found, true), 'saved pack list rejects an invalid line key');
} catch (Throwable $e) {
    echo 'FAIL  uncaught ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $GLOBALS['TEST_FAIL']++;
} finally {
    try {
        pack_coverage_wipe($db, $date, $customerIds, $createdProductIds);
    } catch (Throwable $e) {
        fwrite(STDERR, 'pack cleanup failed: ' . $e->getMessage() . "\n");
    }
}

echo "\n{$GLOBALS['TEST_PASS']} passed, {$GLOBALS['TEST_FAIL']} failed\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
