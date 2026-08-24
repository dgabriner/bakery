<?php
/**
 * Route Manager pickup manifests come from saved Driver Pickup Loads.
 *
 * Usage: php tests/run_route_manager_pickup_tests.php
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

/** @var PDO $db */
$db = require __DIR__ . '/harness.php';
bakery_assert_local_test_target($db);

require_once $root . '/includes/product_inventory.php';
require_once $root . '/includes/production_assign.php';
require_once $root . '/includes/route_manager.php';

if (!bakery_inventory_ready($db)) {
    echo "SKIP  pickup inventory tables are not installed\n";
    exit(0);
}

$date = '2099-11-04';
$driverId = (int)$db->query('SELECT id FROM drivers ORDER BY id LIMIT 1')->fetchColumn();
$product = $db->query('SELECT id, name FROM products ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if ($driverId <= 0 || !$product) {
    throw new RuntimeException('Test clone lacks a driver or product for pickup manifests');
}
$productId = (int)$product['id'];

$db->prepare('DELETE FROM inventory_movements WHERE delivery_date = ?')->execute([$date]);
$loadIds = $db->prepare('SELECT id FROM driver_loads WHERE delivery_date = ?');
$loadIds->execute([$date]);
foreach ($loadIds->fetchAll(PDO::FETCH_COLUMN) as $loadId) {
    $db->prepare('DELETE FROM driver_load_items WHERE driver_load_id = ?')->execute([(int)$loadId]);
}
$db->prepare('DELETE FROM driver_loads WHERE delivery_date = ?')->execute([$date]);
$db->prepare('DELETE FROM product_inventory_days WHERE delivery_date = ?')->execute([$date]);

bakery_inventory_save_driver_load($db, $date, $driverId, [$productId => 24], 'route manager pickup test');

$manifests = bakery_inventory_pickup_manifests($db, $date, [$driverId]);
$items = $manifests[$driverId] ?? [];
if (count($items) !== 1 || (int)$items[0]['loaded_quantity'] !== 24 || (int)$items[0]['product_id'] !== $productId) {
    fwrite(STDERR, "FAIL  pickup manifest should return the saved load quantity\n");
    exit(1);
}
echo "PASS  pickup manifest returns saved Driver Pickup Loads\n";

$zero = bakery_inventory_pickup_manifests($db, $date, [$driverId + 999999]);
if ($zero !== []) {
    fwrite(STDERR, "FAIL  other drivers must not inherit this pickup load\n");
    exit(1);
}
echo "PASS  pickup manifest is scoped to the requested driver\n";

$attached = route_manager_attach_pickup_manifests(
    [$driverId => ['id' => $driverId, 'deliveries' => []]],
    $manifests
);
if ((int)$attached[$driverId]['pickup_piece_count'] !== 24) {
    fwrite(STDERR, "FAIL  route manager attach should total loaded pieces\n");
    exit(1);
}
echo "PASS  route manager attaches pickup piece totals from the saved load\n";

$attached[$driverId]['name'] = 'Test Driver';
$grid = route_manager_pickup_grid($db, $date, $attached);
if (count($grid['drivers']) !== 1 || count($grid['rows']) !== 1) {
    fwrite(STDERR, "FAIL  pickup grid should list one driver column and one product row\n");
    exit(1);
}
if ((int)$grid['rows'][0]['cells'][0]['pieces'] !== 24 || (int)$grid['rows'][0]['total_pieces'] !== 24) {
    fwrite(STDERR, "FAIL  pickup grid cells should keep saved piece quantities\n");
    exit(1);
}
echo "PASS  pickup grid is products by driver in pieces\n";

if (function_exists('bakery_pack_yields_ready') && bakery_pack_yields_ready($db)) {
    $prior = bakery_pack_product_yield($db, $productId);
    $saved = bakery_pack_save_count_units($db, $productId, 12, 24);
    $afterGrid = route_manager_pickup_grid($db, $date, $attached);
    $cell = $afterGrid['rows'][0]['cells'][0];
    if ((int)($saved['pieces_per_tray'] ?? 0) < 2 || (int)$cell['trays'] !== 2 || (int)$cell['tray_remainder'] !== 0) {
        fwrite(STDERR, "FAIL  saving 12 pcs/tray should show 24 pieces as 2 trays\n");
        exit(1);
    }
    if (function_exists('column_exists') && column_exists($db, 'product_pack_yields', 'pieces_per_box')) {
        if ((int)($saved['pieces_per_box'] ?? 0) !== 24 || (int)$cell['boxes'] !== 1) {
            fwrite(STDERR, "FAIL  saving 24 pcs/box should show 24 pieces as 1 box\n");
            exit(1);
        }
    }
    echo "PASS  tray and box sizes saved on the catalog update the pickup grid\n";
    if ($prior) {
        if (function_exists('column_exists') && column_exists($db, 'product_pack_yields', 'pieces_per_box')) {
            $db->prepare(
                'UPDATE product_pack_yields SET pieces_per_tray = ?, pieces_per_box = ? WHERE product_id = ?'
            )->execute([$prior['pieces_per_tray'] ?? null, $prior['pieces_per_box'] ?? null, $productId]);
        } else {
            $db->prepare('UPDATE product_pack_yields SET pieces_per_tray = ? WHERE product_id = ?')
                ->execute([$prior['pieces_per_tray'] ?? null, $productId]);
        }
    } else {
        $db->prepare('DELETE FROM product_pack_yields WHERE product_id = ?')->execute([$productId]);
    }
}

$incompleteDate = '2099-11-05';
$customerId = (int)$db->query('SELECT id FROM customers WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
if ($customerId <= 0) {
    throw new RuntimeException('Test clone lacks a customer for incomplete-load progress');
}
$db->prepare('DELETE FROM inventory_movements WHERE delivery_date = ?')->execute([$incompleteDate]);
$incLoadIds = $db->prepare('SELECT id FROM driver_loads WHERE delivery_date = ?');
$incLoadIds->execute([$incompleteDate]);
foreach ($incLoadIds->fetchAll(PDO::FETCH_COLUMN) as $loadId) {
    $db->prepare('DELETE FROM driver_load_items WHERE driver_load_id = ?')->execute([(int)$loadId]);
}
$db->prepare('DELETE FROM driver_loads WHERE delivery_date = ?')->execute([$incompleteDate]);
$db->prepare('DELETE FROM product_inventory_days WHERE delivery_date = ?')->execute([$incompleteDate]);
$oldOrders = $db->prepare('SELECT id FROM daily_orders WHERE order_date = ?');
$oldOrders->execute([$incompleteDate]);
$oldIds = array_map('intval', $oldOrders->fetchAll(PDO::FETCH_COLUMN));
if ($oldIds) {
    $in = implode(',', $oldIds);
    $db->exec("DELETE FROM daily_order_assignments WHERE daily_order_id IN ({$in})");
    $db->exec("DELETE FROM daily_order_items WHERE daily_order_id IN ({$in})");
    $db->exec("DELETE FROM daily_orders WHERE id IN ({$in})");
}

$db->prepare(
    "INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, 'pending', 0)"
)->execute([$customerId, $incompleteDate]);
$orderId = (int)$db->lastInsertId();
$db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, 12, 1, 12)'
)->execute([$orderId, $productId]);
$db->prepare(
    "INSERT INTO daily_order_assignments
     (daily_order_id, driver_id, delivery_date, route_order, scheduled_delivery_time, delivery_status)
     VALUES (?, ?, ?, 1, '08:00:00', 'pending')"
)->execute([$orderId, $driverId, $incompleteDate]);

$before = bakery_inventory_load_progress($db, $incompleteDate);
if ((int)$before['drivers_with_work'] !== 1 || count($before['incomplete']) !== 1) {
    fwrite(STDERR, "FAIL  assigned units with no pickup should be one incomplete load\n");
    exit(1);
}
if ((int)$before['incomplete'][0]['required'] !== 12 || (int)$before['incomplete'][0]['loaded'] !== 0) {
    fwrite(STDERR, "FAIL  incomplete load should show 12 required and 0 loaded\n");
    exit(1);
}
echo "PASS  assigned units with no pickup count as one incomplete load\n";

bakery_inventory_save_driver_load($db, $incompleteDate, $driverId, [$productId => 12], 'no production recorded');
$after = bakery_inventory_load_progress($db, $incompleteDate);
if ($after['incomplete'] !== []) {
    fwrite(STDERR, "FAIL  saving pickup without production should finish the incomplete load\n");
    exit(1);
}
echo "PASS  saving pickup without production finishes the incomplete load\n";

$src = (string)file_get_contents($root . '/driver_load.php');
if (strpos($src, 'data-load-finish-hint') === false || strpos($src, 'driver_load.today_still_open') === false) {
    fwrite(STDERR, "FAIL  Driver Pickup Loads must name the stuck load and warn when today is a different day\n");
    exit(1);
}
echo "PASS  Driver Pickup Loads explains how to finish an incomplete load\n";

$db->prepare('DELETE FROM inventory_movements WHERE delivery_date = ?')->execute([$incompleteDate]);
$incLoadIds->execute([$incompleteDate]);
foreach ($incLoadIds->fetchAll(PDO::FETCH_COLUMN) as $loadId) {
    $db->prepare('DELETE FROM driver_load_items WHERE driver_load_id = ?')->execute([(int)$loadId]);
}
$db->prepare('DELETE FROM driver_loads WHERE delivery_date = ?')->execute([$incompleteDate]);
$db->prepare('DELETE FROM product_inventory_days WHERE delivery_date = ?')->execute([$incompleteDate]);
$db->prepare('DELETE FROM daily_order_assignments WHERE daily_order_id = ?')->execute([$orderId]);
$db->prepare('DELETE FROM daily_order_items WHERE daily_order_id = ?')->execute([$orderId]);
$db->prepare('DELETE FROM daily_orders WHERE id = ?')->execute([$orderId]);

$rebalanceDate = '2099-11-06';
$driverRows = $db->query('SELECT id FROM drivers ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
$customerRows = $db->query('SELECT id FROM customers WHERE is_active = 1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
if (count($driverRows) < 2 || count($customerRows) < 2) {
    throw new RuntimeException('Test clone needs two drivers and two customers for pickup rebalance');
}
$lauraId = (int)$driverRows[0];
$marcosId = (int)$driverRows[1];
$storeA = (int)$customerRows[0];
$storeB = (int)$customerRows[1];

$cleanupRebalance = static function (PDO $db, string $date): void {
    $db->prepare('DELETE FROM inventory_movements WHERE delivery_date = ?')->execute([$date]);
    $loadIds = $db->prepare('SELECT id FROM driver_loads WHERE delivery_date = ?');
    $loadIds->execute([$date]);
    foreach ($loadIds->fetchAll(PDO::FETCH_COLUMN) as $loadId) {
        $db->prepare('DELETE FROM driver_load_items WHERE driver_load_id = ?')->execute([(int)$loadId]);
    }
    $db->prepare('DELETE FROM driver_loads WHERE delivery_date = ?')->execute([$date]);
    $db->prepare('DELETE FROM product_inventory_days WHERE delivery_date = ?')->execute([$date]);
    $oldOrders = $db->prepare('SELECT id FROM daily_orders WHERE order_date = ?');
    $oldOrders->execute([$date]);
    $oldIds = array_map('intval', $oldOrders->fetchAll(PDO::FETCH_COLUMN));
    if ($oldIds) {
        $in = implode(',', $oldIds);
        $db->exec("DELETE FROM daily_order_assignments WHERE daily_order_id IN ({$in})");
        $db->exec("DELETE FROM daily_order_items WHERE daily_order_id IN ({$in})");
        $db->exec("DELETE FROM daily_orders WHERE id IN ({$in})");
    }
};
$cleanupRebalance($db, $rebalanceDate);

$insertStop = static function (PDO $db, int $customerId, int $driverId, int $productId, string $date, int $qty): int {
    $db->prepare(
        "INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, 'pending', 0)"
    )->execute([$customerId, $date]);
    $oid = (int)$db->lastInsertId();
    $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, ?, 1, ?)'
    )->execute([$oid, $productId, $qty, $qty]);
    $db->prepare(
        "INSERT INTO daily_order_assignments
         (daily_order_id, driver_id, delivery_date, route_order, scheduled_delivery_time, delivery_status)
         VALUES (?, ?, ?, 1, '08:00:00', 'pending')"
    )->execute([$oid, $driverId, $date]);
    return $oid;
};
$orderA = $insertStop($db, $storeA, $lauraId, $productId, $rebalanceDate, 10);
$orderB = $insertStop($db, $storeB, $marcosId, $productId, $rebalanceDate, 6);
bakery_inventory_save_driver_load($db, $rebalanceDate, $lauraId, [$productId => 10], 'rebalance laura');
bakery_inventory_save_driver_load($db, $rebalanceDate, $marcosId, [$productId => 6], 'rebalance marcos');

$driversData = [
    $lauraId => ['id' => $lauraId, 'name' => 'Laura', 'pickup_manifest' => []],
    $marcosId => ['id' => $marcosId, 'name' => 'Marcos', 'pickup_manifest' => []],
];
$driversData = route_manager_attach_pickup_manifests(
    $driversData,
    bakery_inventory_pickup_manifests($db, $rebalanceDate, [$lauraId, $marcosId])
);
$grid = route_manager_pickup_grid($db, $rebalanceDate, $driversData);
$productRow = $grid['rows'][0] ?? null;
$lauraCell = null;
$marcosCell = null;
foreach ((array)($productRow['cells'] ?? []) as $cell) {
    if ((int)$cell['driver_id'] === $lauraId) {
        $lauraCell = $cell;
    }
    if ((int)$cell['driver_id'] === $marcosId) {
        $marcosCell = $cell;
    }
}
if (!$lauraCell || (int)$lauraCell['required'] !== 10 || count($lauraCell['stores']) !== 1) {
    fwrite(STDERR, "FAIL  pickup grid should list Laura’s store quantities\n");
    exit(1);
}
echo "PASS  pickup grid opens store lines under each driver cell\n";

route_manager_pickup_set_store($db, $rebalanceDate, $productId, $storeA, 8);
$qtyA = (int)$db->query('SELECT quantity FROM daily_order_items WHERE daily_order_id = ' . $orderA)->fetchColumn();
if ($qtyA !== 8) {
    fwrite(STDERR, "FAIL  store quantity edit should keep a dated line at 8\n");
    exit(1);
}
echo "PASS  store quantity can be edited from the pickup board helpers\n";

$moved = route_manager_pickup_shift($db, $rebalanceDate, $productId, $lauraId, $marcosId, 4, 'existing');
if ((int)$moved['moved'] !== 4) {
    fwrite(STDERR, "FAIL  shift should move 4 unlocked pieces to the other driver\n");
    exit(1);
}
$qtyA = (int)$db->query('SELECT quantity FROM daily_order_items WHERE daily_order_id = ' . $orderA)->fetchColumn();
$qtyB = (int)$db->query('SELECT quantity FROM daily_order_items WHERE daily_order_id = ' . $orderB)->fetchColumn();
if ($qtyA !== 4 || $qtyB !== 10) {
    fwrite(STDERR, "FAIL  after a 4-piece shift Laura should have 4 and Marcos 10, got {$qtyA}/{$qtyB}\n");
    exit(1);
}
$all = route_manager_pickup_shift($db, $rebalanceDate, $productId, $lauraId, $marcosId, 0, 'existing');
if ((int)$all['moved'] !== 4) {
    fwrite(STDERR, "FAIL  quantity 0 should move the remaining unlocked pieces\n");
    exit(1);
}
$qtyA = (int)$db->query('SELECT quantity FROM daily_order_items WHERE daily_order_id = ' . $orderA)->fetchColumn();
$qtyB = (int)$db->query('SELECT quantity FROM daily_order_items WHERE daily_order_id = ' . $orderB)->fetchColumn();
if ($qtyA !== 0 || $qtyB !== 14) {
    fwrite(STDERR, "FAIL  full item shift should leave Laura at 0 and Marcos at 14, got {$qtyA}/{$qtyB}\n");
    exit(1);
}
echo "PASS  pieces can move between drivers and auto-spread onto their stores\n";

route_manager_pickup_set_driver_total($db, $rebalanceDate, $productId, $marcosId, 12, 'existing');
$qtyB = (int)$db->query('SELECT quantity FROM daily_order_items WHERE daily_order_id = ' . $orderB)->fetchColumn();
if ($qtyB !== 12) {
    fwrite(STDERR, "FAIL  setting a driver total should share onto that driver’s stores\n");
    exit(1);
}
echo "PASS  a driver total auto-assigns onto that driver’s stores\n";

$conserved = route_manager_pickup_apply_plan($db, $rebalanceDate, $productId, [
    ['customer_id' => $storeA, 'quantity' => 4],
    ['customer_id' => $storeB, 'quantity' => 8],
]);
if ((int)$conserved['unlocked_pieces'] !== 12) {
    fwrite(STDERR, "FAIL  conserved plan should keep 12 unlocked pieces\n");
    exit(1);
}
$qtyA = (int)$db->query('SELECT quantity FROM daily_order_items WHERE daily_order_id = ' . $orderA)->fetchColumn();
$qtyB = (int)$db->query('SELECT quantity FROM daily_order_items WHERE daily_order_id = ' . $orderB)->fetchColumn();
if ($qtyA !== 4 || $qtyB !== 8) {
    fwrite(STDERR, "FAIL  conserved plan should land 4 / 8, got {$qtyA}/{$qtyB}\n");
    exit(1);
}
$rejected = false;
try {
    route_manager_pickup_apply_plan($db, $rebalanceDate, $productId, [
        ['customer_id' => $storeA, 'quantity' => 3],
        ['customer_id' => $storeB, 'quantity' => 8],
    ]);
} catch (Throwable $e) {
    $rejected = true;
}
if (!$rejected) {
    fwrite(STDERR, "FAIL  a plan that changes the piece total must be rejected\n");
    exit(1);
}
echo "PASS  pickup plans keep a fixed piece total and reject leaks\n";

$srcManager = (string)file_get_contents($root . '/route_manager.php');
if (strpos($srcManager, 'pickup-sheet-root') === false || strpos($srcManager, 'apply_plan') === false) {
    fwrite(STDERR, "FAIL  Route Manager must open an on-screen pickup sheet that saves a conserved plan\n");
    exit(1);
}
echo "PASS  Route Manager pickup sheet is an on-screen conserved move\n";

$hinted = route_manager_pickup_apply_demand_hints(
    [['customer_id' => 9, 'quantity' => 10]],
    [9 => ['standing_qty' => 6, 'expected_qty' => 8, 'source' => 'daily']]
);
if ((int)$hinted[0]['standing_qty'] !== 6 || (int)$hinted[0]['expected_qty'] !== 8 || $hinted[0]['source'] !== 'daily') {
    fwrite(STDERR, "FAIL  pickup stores should carry standing vs today’s supposed quantity\n");
    exit(1);
}
echo "PASS  pickup stores carry standing and today’s supposed quantity\n";

if (strpos($srcManager, 'pickup-slider') === false || strpos($srcManager, 'pickup-chunk') === false) {
    fwrite(STDERR, "FAIL  pickup sheet must move by slider and chunk, not only ±1\n");
    exit(1);
}
echo "PASS  pickup sheet moves by slider and chunk size\n";

$allocStores = [
    ['customer_id' => 1, 'name' => 'Big Cafe', 'quantity' => 14, 'expected_qty' => 10, 'standing_qty' => 10, 'locked' => false, 'driver_id' => 1],
    ['customer_id' => 2, 'name' => 'Mid Market', 'quantity' => 2, 'expected_qty' => 6, 'standing_qty' => 6, 'locked' => false, 'driver_id' => 1],
    ['customer_id' => 3, 'name' => 'Tiny Corner', 'quantity' => 1, 'expected_qty' => 4, 'standing_qty' => 4, 'locked' => false, 'driver_id' => 2],
];
$balanced = route_manager_pickup_allocate_unlocked($allocStores, 'supposed');
$next = array_map(static function ($row) {
    return (int)$row['next'];
}, $balanced);
if ($next !== [9, 5, 3] || array_sum($next) !== 17) {
    fwrite(STDERR, 'FAIL  supposed rebalance should land 9/5/3, got ' . implode('/', $next) . "\n");
    exit(1);
}
$little = route_manager_pickup_allocate_unlocked($allocStores, 'little_shop');
$littleNext = array_map(static function ($row) {
    return (int)$row['next'];
}, $little);
if ($littleNext !== [8, 5, 4] || array_sum($littleNext) !== 17) {
    fwrite(STDERR, 'FAIL  little-shop extras should land 8/5/4, got ' . implode('/', $littleNext) . "\n");
    exit(1);
}
$vans = route_manager_pickup_allocate_unlocked($allocStores, 'by_van');
$vanA = (int)$vans[0]['next'] + (int)$vans[1]['next'];
$vanB = (int)$vans[2]['next'];
if ($vanA + $vanB !== 17 || $vanA < $vanB) {
    fwrite(STDERR, "FAIL  by-van should keep most pieces on the heavier van, got {$vanA}/{$vanB}\n");
    exit(1);
}
echo "PASS  pickup allocate methods keep the pool and track supposed / little shops / vans\n";

if (strpos($srcManager, 'data-method="supposed"') === false || strpos($srcManager, 'little_shop') === false) {
    fwrite(STDERR, "FAIL  pickup sheet must expose one-click supposed balance and little-shop extras\n");
    exit(1);
}
echo "PASS  pickup sheet exposes one-click rebalance methods\n";

$familyRows = [
    [
        'product_id' => 11,
        'name' => 'Concha',
        'product_line_name' => 'Pan Dulce',
        'total_pieces' => 10,
        'total_required' => 10,
        'cells' => [[
            'driver_id' => $driverId,
            'stores' => [[
                'customer_id' => 7,
                'name' => 'Cafe One',
                'quantity' => 6,
                'expected_qty' => 5,
                'standing_qty' => 4,
            ]],
        ]],
    ],
    [
        'product_id' => 12,
        'name' => 'Cuerno',
        'product_line_name' => 'Pan Dulce',
        'total_pieces' => 8,
        'total_required' => 8,
        'cells' => [[
            'driver_id' => $driverId,
            'stores' => [[
                'customer_id' => 7,
                'name' => 'Cafe One',
                'quantity' => 8,
                'expected_qty' => 8,
                'standing_qty' => 8,
            ]],
        ]],
    ],
    [
        'product_id' => 13,
        'name' => 'Baguette',
        'product_line_name' => 'Bread',
        'total_pieces' => 4,
        'total_required' => 4,
        'cells' => [[
            'driver_id' => $driverId,
            'stores' => [[
                'customer_id' => 7,
                'name' => 'Cafe One',
                'quantity' => 4,
                'expected_qty' => 4,
                'standing_qty' => 4,
            ]],
        ]],
    ],
    [
        'product_id' => 14,
        'name' => 'Ghost pan',
        'product_line_name' => 'Pan Dulce',
        'total_pieces' => 0,
        'total_required' => 0,
        'cells' => [[
            'driver_id' => $driverId,
            'stores' => [[
                'customer_id' => 7,
                'name' => 'Cafe One',
                'quantity' => 0,
                'expected_qty' => 0,
                'standing_qty' => 0,
            ]],
        ]],
    ],
];
$families = route_manager_pickup_families($familyRows);
if (($families[0]['name'] ?? '') !== 'Pan Dulce' || count($families[0]['product_ids']) !== 3) {
    fwrite(STDERR, "FAIL  Pan Dulce family should lead and include both SKUs\n");
    exit(1);
}
$storeView = route_manager_pickup_store_view([['id' => $driverId, 'name' => 'Test Driver']], $familyRows);
if (count($storeView) !== 1 || (int)$storeView[0]['total_required'] !== 18) {
    fwrite(STDERR, "FAIL  store view should total pieces across SKUs for the shop\n");
    exit(1);
}
if (count($storeView[0]['cells'][0]['products']) !== 3) {
    fwrite(STDERR, "FAIL  store cell should list each live SKU and skip empty route cartesian rows\n");
    exit(1);
}
echo "PASS  pickup families and store-per-driver view keep SKUs separate\n";

$srcInc = file_get_contents($root . '/includes/route_manager.php');
if (strpos($srcInc, 'function route_manager_pickup_allocate_many') === false
    || strpos($srcManager, 'allocate-scope') === false
    || strpos($srcManager, 'sku-bump') === false) {
    fwrite(STDERR, "FAIL  family allocate and store/product view must be wired\n");
    exit(1);
}
echo "PASS  family-wide allocate and store view are wired on Route Manager\n";

$cleanupRebalance($db, $rebalanceDate);

$db->prepare('DELETE FROM inventory_movements WHERE delivery_date = ?')->execute([$date]);
$loadIds->execute([$date]);
foreach ($loadIds->fetchAll(PDO::FETCH_COLUMN) as $loadId) {
    $db->prepare('DELETE FROM driver_load_items WHERE driver_load_id = ?')->execute([(int)$loadId]);
}
$db->prepare('DELETE FROM driver_loads WHERE delivery_date = ?')->execute([$date]);
$db->prepare('DELETE FROM product_inventory_days WHERE delivery_date = ?')->execute([$date]);

echo "All Route Manager pickup checks passed\n";
exit(0);
