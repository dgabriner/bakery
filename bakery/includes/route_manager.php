<?php
/**
 * Shared Route Manager reads: dated assignments, cash-aware amounts, photos.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/photo_handler.php';
require_once __DIR__ . '/route_manager_cash.php';
require_once __DIR__ . '/product_inventory.php';
require_once __DIR__ . '/product_pack_yields.php';

/**
 * Parse a YYYY-MM-DD date, falling back to today (or $fallback) when invalid.
 */
function route_manager_parse_date($raw, $fallback = null): string
{
    $fallbackDate = $fallback ?: date('Y-m-d');
    $date = trim((string)$raw);
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        return $fallbackDate;
    }
    return $date;
}

/**
 * Fetch assigned deliveries for a date, optionally filtered by driver IDs.
 *
 * @param array<int, int> $driverIds
 * @return array<int, array<string, mixed>>
 */
function route_manager_fetch_deliveries(PDO $db, string $date, array $driverIds = []): array
{
    $photosAvailable = table_exists($db, 'driver_photos');
    $hasPaymentCollection = column_exists($db, 'customers', 'payment_collection');
    $hasAmountCollected = column_exists($db, 'daily_orders', 'amount_collected');
    $hasDeliveryConfirmedAt = column_exists($db, 'daily_orders', 'delivery_confirmed_at');
    $hasDeliveryOrderTotal = column_exists($db, 'daily_orders', 'delivery_order_total');
    $hasDeliveredPieces = column_exists($db, 'daily_orders', 'delivered_pieces');
    $customerPaymentSql = $hasPaymentCollection ? "COALESCE(c.payment_collection, 'cod')" : "'cod'";
    // Pan Dulce deliveries are collected as cash by default. This also makes
    // historical routes reconcile correctly even when the customer was created
    // before payment_collection existed.
    $paymentCollectionSql = "CASE WHEN EXISTS (
        SELECT 1
        FROM daily_order_items payment_doi
        INNER JOIN products payment_p ON payment_p.id = payment_doi.product_id
        INNER JOIN dough_types payment_dt ON payment_dt.id = payment_p.dough_type_id
        INNER JOIN product_lines payment_pl ON payment_pl.id = payment_dt.product_line_id
        WHERE payment_doi.daily_order_id = do.id
          AND payment_pl.name = 'Pan Dulce'
    ) THEN 'cod' ELSE {$customerPaymentSql} END";
    $amountCollectedSql = $hasAmountCollected ? 'do.amount_collected' : 'NULL';
    $deliveryConfirmedSql = $hasDeliveryConfirmedAt
        ? 'do.delivery_confirmed_at'
        : 'NULL';
    $deliveryOrderTotalSql = $hasDeliveryOrderTotal ? 'do.delivery_order_total' : 'NULL';
    $deliveredPiecesSql = $hasDeliveredPieces ? 'do.delivered_pieces' : 'NULL';
    $deliveredCondition = "doa.delivery_status = 'delivered' OR do.status IN ('delivered', 'invoiced')";
    if ($hasDeliveryConfirmedAt) {
        $deliveredCondition .= ' OR do.delivery_confirmed_at IS NOT NULL';
    }
    $deliveryStatusSql = "CASE WHEN {$deliveredCondition} THEN 'delivered' ELSE COALESCE(doa.delivery_status, 'pending') END";
    $photoCountSql = $photosAvailable
        ? "(
                SELECT COUNT(*)
                FROM driver_photos dp
                WHERE dp.driver_id = doa.driver_id
                  AND dp.customer_id = c.id
                  AND dp.delivery_date = doa.delivery_date
            )"
        : '0';

    $sql = "
        SELECT
            doa.driver_id,
            d.name AS driver_name,
            doa.route_order,
            doa.scheduled_delivery_time,
            {$deliveryStatusSql} AS delivery_status,
            doa.actual_delivery_time,
            do.id AS daily_order_id,
            do.total_amount,
            {$deliveryOrderTotalSql} AS delivery_order_total,
            {$deliveryConfirmedSql} AS delivery_confirmed_at,
            {$amountCollectedSql} AS amount_collected,
            {$deliveredPiecesSql} AS delivered_pieces,
            {$paymentCollectionSql} AS payment_collection,
            c.id AS customer_id,
            c.name AS customer_name,
            c.address,
            c.zone,
            c.phone,
            c.latitude,
            c.longitude,
            c.deliver_by,
            c.deliver_after,
            (
                SELECT COALESCE(SUM(doi.quantity), 0)
                FROM daily_order_items doi
                WHERE doi.daily_order_id = do.id
            ) AS item_count,
            (
                SELECT COALESCE(SUM(doi.line_total), 0)
                FROM daily_order_items doi
                WHERE doi.daily_order_id = do.id
            ) AS order_total_estimate,
            {$photoCountSql} AS photo_count
        FROM daily_order_assignments doa
        INNER JOIN drivers d ON doa.driver_id = d.id
        INNER JOIN daily_orders do
            ON do.id = doa.daily_order_id
            AND do.order_date = doa.delivery_date
        INNER JOIN customers c ON do.customer_id = c.id
        WHERE doa.delivery_date = ?
    ";

    $params = [$date];

    if (!empty($driverIds)) {
        $placeholders = implode(',', array_fill(0, count($driverIds), '?'));
        $sql .= " AND doa.driver_id IN ($placeholders)";
        foreach ($driverIds as $id) {
            $params[] = (int)$id;
        }
    }

    $sql .= " ORDER BY d.name, doa.route_order, c.name";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $driversData = [];
    foreach ($rows as $row) {
        $driverId = (int)$row['driver_id'];
        if (!isset($driversData[$driverId])) {
            $driversData[$driverId] = [
                'id' => $driverId,
                'name' => $row['driver_name'],
                'deliveries' => [],
            ];
        }

        $driversData[$driverId]['deliveries'][] = [
            'daily_order_id' => (int)$row['daily_order_id'],
            'customer_id' => (int)$row['customer_id'],
            'customer_name' => $row['customer_name'],
            'address' => $row['address'] ?? '',
            'zone' => $row['zone'] ?: 'No Zone',
            'phone' => $row['phone'] ?? '',
            'route_order' => (int)$row['route_order'],
            'scheduled_delivery_time' => $row['scheduled_delivery_time'],
            'actual_delivery_time' => $row['actual_delivery_time'] ?? null,
            'delivery_status' => $row['delivery_status'] ?: 'pending',
            'total_amount' => (float)$row['total_amount'],
            'delivery_order_total' => $row['delivery_order_total'] !== null ? (float)$row['delivery_order_total'] : null,
            'order_total_estimate' => (float)$row['order_total_estimate'],
            'delivery_confirmed_at' => $row['delivery_confirmed_at'] ?? null,
            'amount_collected' => $row['amount_collected'] !== null ? (float)$row['amount_collected'] : null,
            'delivered_pieces' => $row['delivered_pieces'] !== null && $row['delivered_pieces'] !== ''
                ? (int)$row['delivered_pieces']
                : null,
            // Schema default is COD; never treat a missing/blank value as signature
            // or cash totals silently drop every stop.
            'payment_collection' => in_array((string)($row['payment_collection'] ?? ''), ['cod', 'signature'], true)
                ? (string)$row['payment_collection']
                : 'cod',
            'item_count' => (int)$row['item_count'],
            'latitude' => $row['latitude'] !== null && $row['latitude'] !== '' ? (float)$row['latitude'] : null,
            'longitude' => $row['longitude'] !== null && $row['longitude'] !== '' ? (float)$row['longitude'] : null,
            'deliver_by' => $row['deliver_by'],
            'deliver_after' => $row['deliver_after'],
            'photo_count' => (int)$row['photo_count'],
        ];
    }

    foreach ($driversData as $driverId => $driverData) {
        $turnedIn = null;
        if (function_exists('bakery_cod_turnin_get')) {
            $turnedIn = bakery_cod_turnin_get($db, (int)$driverId, $date);
        } elseif (is_readable(__DIR__ . '/cod_turnins.php')) {
            require_once __DIR__ . '/cod_turnins.php';
            $turnedIn = bakery_cod_turnin_get($db, (int)$driverId, $date);
        }
        $driversData[$driverId]['cash_summary'] = route_manager_compute_cash_summary(
            $driverData['deliveries'],
            $turnedIn
        );
    }

    $manifestDriverIds = $driverIds ?: array_map('intval', array_keys($driversData));
    $manifests = bakery_inventory_pickup_manifests($db, $date, $manifestDriverIds);

    return route_manager_attach_pickup_manifests($driversData, $manifests);
}

/**
 * Attach saved pickup-load lines to Route Manager driver groups.
 *
 * @param array<int, array<string, mixed>> $driversData
 * @param array<int, array<int, array<string, mixed>>> $manifestsByDriver
 * @return array<int, array<string, mixed>>
 */
function route_manager_attach_pickup_manifests(array $driversData, array $manifestsByDriver): array
{
    foreach ($driversData as $driverId => $driverData) {
        $items = $manifestsByDriver[(int)$driverId] ?? [];
        $pieceCount = 0;
        foreach ($items as $item) {
            $pieceCount += (int)($item['loaded_quantity'] ?? 0);
        }
        $driversData[$driverId]['pickup_manifest'] = $items;
        $driversData[$driverId]['pickup_sku_count'] = count($items);
        $driversData[$driverId]['pickup_piece_count'] = $pieceCount;
    }
    return $driversData;
}

/**
 * Product rows × driver columns for the Route Manager pickup board.
 *
 * Loaded pieces stay the saved van load. Store lists are dated assignments.
 *
 * @param array<int, array<string, mixed>> $driversData
 * @return array{drivers:list<array{id:int,name:string}>,rows:list<array<string,mixed>>}
 */
function route_manager_pickup_grid(PDO $db, string $date, array $driversData): array
{
    $drivers = [];
    $driverIds = [];
    foreach ($driversData as $driverId => $driver) {
        $id = (int)$driverId;
        $drivers[] = [
            'id' => $id,
            'name' => (string)($driver['name'] ?? 'Driver'),
        ];
        if ($id > 0) {
            $driverIds[] = $id;
        }
    }
    usort($drivers, static function ($a, $b) {
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });

    $byProduct = [];
    foreach ($driversData as $driverId => $driver) {
        foreach ((array)($driver['pickup_manifest'] ?? []) as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            if (!isset($byProduct[$productId])) {
                $byProduct[$productId] = [
                    'product_id' => $productId,
                    'name' => (string)($item['name'] ?? ''),
                    'qty' => [],
                ];
            }
            $byProduct[$productId]['qty'][(int)$driverId] = (int)($item['loaded_quantity'] ?? 0);
        }
    }

    $assigned = route_manager_pickup_assigned_lines($db, $date, $driverIds);
    foreach ($assigned as $productId => $product) {
        if (!isset($byProduct[$productId])) {
            $byProduct[$productId] = [
                'product_id' => $productId,
                'name' => (string)$product['name'],
                'qty' => [],
            ];
        } elseif ($byProduct[$productId]['name'] === '') {
            $byProduct[$productId]['name'] = (string)$product['name'];
        }
    }

    uasort($byProduct, static function ($a, $b) {
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });

    $routeStores = route_manager_pickup_route_stores_for_products(
        $db,
        $date,
        $driverIds,
        array_map('intval', array_keys($byProduct))
    );

    $rows = [];
    foreach ($byProduct as $product) {
        $productId = (int)$product['product_id'];
        $hints = route_manager_pickup_demand_hints($db, $date, $productId);
        $total = 0;
        $requiredTotal = 0;
        $cells = [];
        foreach ($drivers as $driver) {
            $pieces = (int)($product['qty'][$driver['id']] ?? 0);
            $stores = $routeStores[$productId][$driver['id']] ?? ($assigned[$productId]['by_driver'][$driver['id']] ?? []);
            $stores = route_manager_pickup_apply_demand_hints($stores, $hints);
            $required = 0;
            foreach ($stores as $store) {
                $required += (int)($store['quantity'] ?? 0);
            }
            $total += $pieces;
            $requiredTotal += $required;
            $break = function_exists('bakery_pack_count_breakdown')
                ? bakery_pack_count_breakdown($db, $productId, $pieces)
                : [
                    'pieces' => $pieces,
                    'trays' => 0,
                    'tray_remainder' => $pieces,
                    'boxes' => 0,
                    'box_remainder' => $pieces,
                ];
            $cells[] = [
                'driver_id' => $driver['id'],
                'pieces' => $pieces,
                'required' => $required,
                'stores' => $stores,
                'trays' => (int)($break['trays'] ?? 0),
                'tray_remainder' => (int)($break['tray_remainder'] ?? $pieces),
                'boxes' => (int)($break['boxes'] ?? 0),
                'box_remainder' => (int)($break['box_remainder'] ?? $pieces),
            ];
        }
        $rates = function_exists('bakery_pack_count_breakdown')
            ? bakery_pack_count_breakdown($db, $productId, 0)
            : ['pieces_per_tray' => null, 'pieces_per_box' => null];
        $rows[] = [
            'product_id' => $productId,
            'name' => $product['name'],
            'product_line_id' => 0,
            'product_line_name' => '',
            'pieces_per_tray' => $rates['pieces_per_tray'] ?? null,
            'pieces_per_box' => $rates['pieces_per_box'] ?? null,
            'total_pieces' => $total,
            'total_required' => $requiredTotal,
            'cells' => $cells,
        ];
    }

    $rows = route_manager_pickup_attach_product_lines($db, $rows);

    return [
        'drivers' => $drivers,
        'rows' => $rows,
        'families' => route_manager_pickup_families($rows),
        'store_view' => route_manager_pickup_store_view($drivers, $rows),
    ];
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function route_manager_pickup_attach_product_lines(PDO $db, array $rows): array
{
    $ids = [];
    foreach ($rows as $row) {
        $id = (int)($row['product_id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    $ids = array_values(array_unique($ids));
    if ($ids === [] || !function_exists('table_exists') || !table_exists($db, 'products')) {
        return $rows;
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $sql = 'SELECT p.id, COALESCE(pl.id, 0) AS product_line_id, COALESCE(pl.name, \'\') AS product_line_name
            FROM products p
            LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
            LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
            WHERE p.id IN (' . $ph . ')';
    $stmt = $db->prepare($sql);
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int)$row['id']] = [
            'product_line_id' => (int)$row['product_line_id'],
            'product_line_name' => (string)$row['product_line_name'],
        ];
    }
    foreach ($rows as $i => $row) {
        $meta = $map[(int)$row['product_id']] ?? ['product_line_id' => 0, 'product_line_name' => ''];
        $rows[$i]['product_line_id'] = $meta['product_line_id'];
        $rows[$i]['product_line_name'] = $meta['product_line_name'];
    }
    return $rows;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function route_manager_pickup_families(array $rows): array
{
    $families = [];
    foreach ($rows as $row) {
        $name = trim((string)($row['product_line_name'] ?? ''));
        if ($name === '') {
            $name = 'Other';
        }
        if (!isset($families[$name])) {
            $families[$name] = [
                'name' => $name,
                'product_ids' => [],
                'total_pieces' => 0,
                'total_required' => 0,
            ];
        }
        $families[$name]['product_ids'][] = (int)$row['product_id'];
        $families[$name]['total_pieces'] += (int)($row['total_pieces'] ?? 0);
        $families[$name]['total_required'] += (int)($row['total_required'] ?? 0);
    }
    $out = array_values($families);
    usort($out, static function ($a, $b) {
        if ($a['name'] === 'Pan Dulce') {
            return -1;
        }
        if ($b['name'] === 'Pan Dulce') {
            return 1;
        }
        return strcasecmp($a['name'], $b['name']);
    });
    return $out;
}

/**
 * Store rows × driver columns, with per-SKU detail inside each cell.
 *
 * @param list<array{id:int,name:string}> $drivers
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function route_manager_pickup_store_view(array $drivers, array $rows): array
{
    $byStore = [];
    foreach ($rows as $row) {
        $productId = (int)($row['product_id'] ?? 0);
        $productName = (string)($row['name'] ?? '');
        $lineName = (string)($row['product_line_name'] ?? '');
        foreach ((array)($row['cells'] ?? []) as $cell) {
            $driverId = (int)($cell['driver_id'] ?? 0);
            foreach ((array)($cell['stores'] ?? []) as $store) {
                $customerId = (int)($store['customer_id'] ?? 0);
                if ($customerId <= 0 || $driverId <= 0) {
                    continue;
                }
                $qty = (int)($store['quantity'] ?? 0);
                $expected = (int)($store['expected_qty'] ?? 0);
                $standing = (int)($store['standing_qty'] ?? 0);
                if ($qty <= 0 && $expected <= 0 && $standing <= 0) {
                    continue;
                }
                if (!isset($byStore[$customerId])) {
                    $byStore[$customerId] = [
                        'customer_id' => $customerId,
                        'name' => (string)($store['name'] ?? ''),
                        'by_driver' => [],
                    ];
                }
                if (!isset($byStore[$customerId]['by_driver'][$driverId])) {
                    $byStore[$customerId]['by_driver'][$driverId] = [
                        'driver_id' => $driverId,
                        'required' => 0,
                        'expected' => 0,
                        'products' => [],
                    ];
                }
                $byStore[$customerId]['by_driver'][$driverId]['required'] += $qty;
                $byStore[$customerId]['by_driver'][$driverId]['expected'] += $expected;
                $byStore[$customerId]['by_driver'][$driverId]['products'][] = [
                    'product_id' => $productId,
                    'name' => $productName,
                    'product_line_name' => $lineName,
                    'quantity' => $qty,
                    'expected_qty' => $expected,
                    'standing_qty' => $standing,
                    'locked' => !empty($store['locked']),
                ];
            }
        }
    }
    $out = [];
    foreach ($byStore as $store) {
        $cells = [];
        $totalRequired = 0;
        $totalExpected = 0;
        foreach ($drivers as $driver) {
            $cell = $store['by_driver'][(int)$driver['id']] ?? [
                'driver_id' => (int)$driver['id'],
                'required' => 0,
                'expected' => 0,
                'products' => [],
            ];
            $totalRequired += (int)$cell['required'];
            $totalExpected += (int)$cell['expected'];
            $cells[] = $cell;
        }
        $out[] = [
            'customer_id' => (int)$store['customer_id'],
            'name' => (string)$store['name'],
            'total_required' => $totalRequired,
            'total_expected' => $totalExpected,
            'cells' => $cells,
        ];
    }
    usort($out, static function ($a, $b) {
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });
    return $out;
}

/**
 * Dated store lines for assigned stops, keyed by product then driver.
 *
 * @param list<int> $driverIds
 * @return array<int, array{name:string,by_driver:array<int,list<array<string,mixed>>>}>
 */
function route_manager_pickup_assigned_lines(PDO $db, string $date, array $driverIds): array
{
    if ($driverIds === [] || !function_exists('table_exists') || !table_exists($db, 'daily_order_items')) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($driverIds), '?'));
    $sql = "SELECT doa.driver_id, do.id AS daily_order_id, do.status AS order_status,
                   doa.delivery_status, c.id AS customer_id, c.name AS customer_name,
                   doi.product_id, p.name AS product_name, doi.quantity
            FROM daily_order_assignments doa
            INNER JOIN daily_orders do ON do.id = doa.daily_order_id AND do.order_date = doa.delivery_date
            INNER JOIN customers c ON c.id = do.customer_id
            INNER JOIN daily_order_items doi ON doi.daily_order_id = do.id
            INNER JOIN products p ON p.id = doi.product_id
            WHERE doa.delivery_date = ?
              AND doa.driver_id IN ($placeholders)
              AND doi.quantity > 0
            ORDER BY c.name, p.name";
    $params = array_merge([$date], array_map('intval', $driverIds));
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $productId = (int)$row['product_id'];
        $driverId = (int)$row['driver_id'];
        if (!isset($out[$productId])) {
            $out[$productId] = [
                'name' => (string)$row['product_name'],
                'by_driver' => [],
            ];
        }
        $locked = route_manager_pickup_store_locked($row['order_status'] ?? null, $row['delivery_status'] ?? null);
        $out[$productId]['by_driver'][$driverId][] = [
            'customer_id' => (int)$row['customer_id'],
            'name' => (string)$row['customer_name'],
            'daily_order_id' => (int)$row['daily_order_id'],
            'quantity' => (int)$row['quantity'],
            'locked' => $locked,
        ];
    }
    return $out;
}

/**
 * Every assigned stop for the given products, including zero-quantity lines so pieces can land there.
 *
 * @param list<int> $driverIds
 * @param list<int> $productIds
 * @return array<int, array<int, list<array<string,mixed>>>>
 */
function route_manager_pickup_route_stores_for_products(PDO $db, string $date, array $driverIds, array $productIds): array
{
    $driverIds = array_values(array_filter(array_map('intval', $driverIds)));
    $productIds = array_values(array_filter(array_map('intval', $productIds)));
    if ($driverIds === [] || $productIds === [] || !function_exists('table_exists') || !table_exists($db, 'daily_order_assignments')) {
        return [];
    }
    $driverPh = implode(',', array_fill(0, count($driverIds), '?'));
    $productPh = implode(',', array_fill(0, count($productIds), '?'));
    $sql = "SELECT doa.driver_id, do.id AS daily_order_id, do.status AS order_status,
                   doa.delivery_status, c.id AS customer_id, c.name AS customer_name,
                   p.id AS product_id, COALESCE(doi.quantity, 0) AS quantity
            FROM daily_order_assignments doa
            INNER JOIN daily_orders do ON do.id = doa.daily_order_id AND do.order_date = doa.delivery_date
            INNER JOIN customers c ON c.id = do.customer_id
            INNER JOIN products p ON p.id IN ($productPh)
            LEFT JOIN daily_order_items doi ON doi.daily_order_id = do.id AND doi.product_id = p.id
            WHERE doa.delivery_date = ?
              AND doa.driver_id IN ($driverPh)
            ORDER BY c.name, p.name";
    $params = array_merge($productIds, [$date], $driverIds);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $productId = (int)$row['product_id'];
        $driverId = (int)$row['driver_id'];
        $out[$productId][$driverId][] = [
            'customer_id' => (int)$row['customer_id'],
            'name' => (string)$row['customer_name'],
            'daily_order_id' => (int)$row['daily_order_id'],
            'quantity' => (int)$row['quantity'],
            'locked' => route_manager_pickup_store_locked($row['order_status'] ?? null, $row['delivery_status'] ?? null),
        ];
    }
    return $out;
}

/**
 * Standing template and today's operating demand (dated beats standing) for one SKU.
 *
 * @return array<int, array{standing_qty:int,expected_qty:int,source:string}>
 */
function route_manager_pickup_demand_hints(PDO $db, string $date, int $productId): array
{
    if ($productId <= 0) {
        return [];
    }
    require_once __DIR__ . '/demand_review.php';
    $hints = [];
    if (function_exists('bakery_operating_demand_customers_for_product')) {
        foreach (bakery_operating_demand_customers_for_product($db, $date, $productId) as $row) {
            $customerId = (int)($row['id'] ?? 0);
            if ($customerId <= 0) {
                continue;
            }
            $hints[$customerId] = [
                'standing_qty' => 0,
                'expected_qty' => (int)($row['quantity'] ?? 0),
                'source' => (string)($row['source'] ?? 'standing'),
            ];
        }
    }
    if (!function_exists('bakery_standing_day_from_date') || !function_exists('table_exists') || !table_exists($db, 'standing_orders')) {
        return $hints;
    }
    $weekday = bakery_standing_day_from_date($date);
    $clause = function_exists('bakery_standing_day_in_clause')
        ? bakery_standing_day_in_clause((int)$weekday)
        : ['sql' => '= ?', 'values' => [(int)$weekday]];
    $stmt = $db->prepare(
        'SELECT customer_id, quantity FROM standing_orders
         WHERE product_id = ? AND day_of_week ' . $clause['sql']
    );
    $stmt->execute(array_merge([$productId], $clause['values']));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $customerId = (int)$row['customer_id'];
        if (!isset($hints[$customerId])) {
            $hints[$customerId] = [
                'standing_qty' => 0,
                'expected_qty' => 0,
                'source' => 'standing',
            ];
        }
        $hints[$customerId]['standing_qty'] = (int)$row['quantity'];
    }
    return $hints;
}

/**
 * @param list<array<string,mixed>> $stores
 * @param array<int, array{standing_qty:int,expected_qty:int,source:string}> $hints
 * @return list<array<string,mixed>>
 */
function route_manager_pickup_apply_demand_hints(array $stores, array $hints): array
{
    foreach ($stores as $i => $store) {
        $hint = $hints[(int)($store['customer_id'] ?? 0)] ?? [
            'standing_qty' => 0,
            'expected_qty' => 0,
            'source' => 'none',
        ];
        $stores[$i]['standing_qty'] = (int)$hint['standing_qty'];
        $stores[$i]['expected_qty'] = (int)$hint['expected_qty'];
        $stores[$i]['source'] = (string)$hint['source'];
    }
    return $stores;
}

function route_manager_pickup_store_locked(?string $orderStatus, ?string $assignmentStatus): bool
{
    return in_array((string)$assignmentStatus, ['in_transit', 'delivered', 'failed', 'cancelled'], true);
}

/**
 * All assigned stops for a driver on a date, with this product's dated quantity (0 if none).
 *
 * @return list<array<string,mixed>>
 */
function route_manager_pickup_driver_stops(PDO $db, string $date, int $driverId, int $productId): array
{
    $stmt = $db->prepare(
        "SELECT doa.driver_id, do.id AS daily_order_id, do.status AS order_status,
                doa.delivery_status, c.id AS customer_id, c.name AS customer_name,
                COALESCE(doi.quantity, 0) AS quantity
         FROM daily_order_assignments doa
         INNER JOIN daily_orders do ON do.id = doa.daily_order_id AND do.order_date = doa.delivery_date
         INNER JOIN customers c ON c.id = do.customer_id
         LEFT JOIN daily_order_items doi ON doi.daily_order_id = do.id AND doi.product_id = ?
         WHERE doa.delivery_date = ? AND doa.driver_id = ?
         ORDER BY c.name"
    );
    $stmt->execute([$productId, $date, $driverId]);
    $stores = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $stores[] = [
            'customer_id' => (int)$row['customer_id'],
            'name' => (string)$row['customer_name'],
            'daily_order_id' => (int)$row['daily_order_id'],
            'quantity' => (int)$row['quantity'],
            'locked' => route_manager_pickup_store_locked($row['order_status'] ?? null, $row['delivery_status'] ?? null),
        ];
    }
    return $stores;
}

/**
 * Largest-remainder share of $newTotal across unlocked stores.
 *
 * @param list<array<string,mixed>> $stores
 * @return list<array<string,mixed>>
 */
function route_manager_pickup_share(array $stores, int $newTotal): array
{
    $newTotal = max(0, $newTotal);
    $unlocked = [];
    $lockedQty = 0;
    foreach ($stores as $i => $store) {
        if (!empty($store['locked'])) {
            $lockedQty += (int)$store['quantity'];
            $stores[$i]['next'] = (int)$store['quantity'];
        } else {
            $unlocked[] = $i;
        }
    }
    $pool = max(0, $newTotal - $lockedQty);
    if ($unlocked === []) {
        if ($newTotal !== $lockedQty) {
            throw new RuntimeException('Every store on this route is already on the van and cannot be edited.');
        }
        return $stores;
    }
    $shareRows = [];
    foreach ($unlocked as $i) {
        $shareRows[] = ['quantity' => (int)$stores[$i]['quantity']];
    }
    $weight = 0;
    foreach ($shareRows as $row) {
        $weight += (int)$row['quantity'];
    }
    if ($weight <= 0) {
        $n = count($unlocked);
        $base = intdiv($pool, $n);
        $rem = $pool % $n;
        foreach ($unlocked as $j => $i) {
            $stores[$i]['next'] = $base + ($j < $rem ? 1 : 0);
        }
        return $stores;
    }
    require_once __DIR__ . '/production_assign.php';
    $recommended = bakery_production_assign_recommend($shareRows, $pool);
    foreach ($unlocked as $j => $i) {
        $stores[$i]['next'] = (int)($recommended[$j]['recommended'] ?? 0);
    }
    return $stores;
}

function route_manager_pickup_write_stores(PDO $db, string $date, int $productId, array $stores): void
{
    $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
    $userId = isset($user['id']) ? (int)$user['id'] : null;
    foreach ($stores as $store) {
        if (!empty($store['locked'])) {
            continue;
        }
        $next = (int)($store['next'] ?? $store['quantity'] ?? 0);
        if ($next === (int)($store['quantity'] ?? 0)) {
            continue;
        }
        route_manager_pickup_save_demand(
            $db,
            $date,
            $productId,
            (int)$store['customer_id'],
            $next,
            $userId
        );
    }
}

function route_manager_pickup_sync_load(PDO $db, string $date, int $driverId, int $productId): void
{
    if ($driverId <= 0 || $productId <= 0 || !function_exists('bakery_inventory_ready') || !bakery_inventory_ready($db)) {
        return;
    }
    $exists = $db->prepare('SELECT id FROM driver_loads WHERE driver_id = ? AND delivery_date = ? LIMIT 1');
    $exists->execute([$driverId, $date]);
    if (!$exists->fetchColumn()) {
        return;
    }
    $sum = $db->prepare(
        'SELECT COALESCE(SUM(doi.quantity), 0)
         FROM daily_order_assignments doa
         INNER JOIN daily_orders do ON do.id = doa.daily_order_id AND do.order_date = doa.delivery_date
         INNER JOIN daily_order_items doi ON doi.daily_order_id = do.id
         WHERE doa.delivery_date = ? AND doa.driver_id = ? AND doi.product_id = ?'
    );
    $sum->execute([$date, $driverId, $productId]);
    bakery_inventory_save_driver_load($db, $date, $driverId, [$productId => (int)$sum->fetchColumn()], 'Route Manager pickup rebalance');
}

function route_manager_pickup_save_demand(
    PDO $db,
    string $date,
    int $productId,
    int $customerId,
    int $quantity,
    ?int $userId
): void {
    require_once __DIR__ . '/production_assign.php';
    $dateObj = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid delivery date');
    }
    if ($date < date('Y-m-d')) {
        throw new InvalidArgumentException('Cannot change past deliveries');
    }
    $quantity = max(0, $quantity);
    if ($productId <= 0 || $customerId <= 0) {
        throw new InvalidArgumentException('Unknown store or product.');
    }
    $customer = bakery_production_assign_customer_row($db, $customerId);
    $product = bakery_customer_product_row($db, $productId);
    if (!$customer || !$product) {
        throw new InvalidArgumentException('Unknown store or product.');
    }
    $state = bakery_customer_delivery_state($db, $customerId, $date);
    if (!empty($state['skipped'])) {
        throw new InvalidArgumentException('This delivery is skipped.');
    }
    if (!empty($state['paused'])) {
        throw new InvalidArgumentException('Deliveries are paused for this date.');
    }
    if (route_manager_pickup_store_locked($state['status'] ?? null, $state['assignment_status'] ?? null)) {
        throw new InvalidArgumentException('This stop is already on the van and cannot be edited here.');
    }
    $dailyOrderId = bakery_customer_ensure_daily_order($db, $customer, $date);
    $stmt = $db->prepare(
        'SELECT id, quantity FROM daily_order_items WHERE daily_order_id = ? AND product_id = ? LIMIT 1'
    );
    $stmt->execute([$dailyOrderId, $productId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    $oldQty = $existing ? (int)$existing['quantity'] : 0;
    $unitPrice = bakery_resolve_customer_price($db, $customer, $product);
    $lineTotal = round($quantity * $unitPrice, 2);
    if ($existing) {
        $upd = $db->prepare(
            'UPDATE daily_order_items SET quantity = ?, line_total = ? * unit_price WHERE id = ?'
        );
        $upd->execute([$quantity, $quantity, (int)$existing['id']]);
    } else {
        $ins = $db->prepare(
            'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
             VALUES (?, ?, ?, ?, ?)'
        );
        $ins->execute([$dailyOrderId, $productId, $quantity, $unitPrice, $lineTotal]);
    }
    bakery_customer_update_daily_total($db, $dailyOrderId);
    if ($oldQty !== $quantity && function_exists('bakery_record_operational_event')) {
        bakery_record_operational_event(
            $db,
            BAKERY_OP_DAILY_ORDER_QUANTITY_CHANGED,
            'Route Manager pickup rebalance ' . $product['name'] . ' for ' . $customer['name'] . ': ' . $oldQty . ' → ' . $quantity,
            [
                'operational_date' => $date,
                'customer_id' => $customerId,
                'daily_order_id' => $dailyOrderId,
                'product_id' => $productId,
                'actor_user_id' => $userId,
                'actor_role' => 'staff',
                'metadata' => [
                    'product_name' => $product['name'],
                    'old_quantity' => $oldQty,
                    'new_quantity' => $quantity,
                    'source' => 'route_manager_pickup',
                ],
            ]
        );
    }
}

function route_manager_pickup_set_store(PDO $db, string $date, int $productId, int $customerId, int $quantity): array
{
    $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
    route_manager_pickup_save_demand(
        $db,
        $date,
        $productId,
        $customerId,
        max(0, $quantity),
        isset($user['id']) ? (int)$user['id'] : null
    );
    $driverId = route_manager_pickup_driver_for_customer($db, $date, $customerId);
    if ($driverId > 0) {
        route_manager_pickup_sync_load($db, $date, $driverId, $productId);
    }
    return ['ok' => true, 'driver_id' => $driverId];
}

function route_manager_pickup_driver_for_customer(PDO $db, string $date, int $customerId): int
{
    $stmt = $db->prepare(
        'SELECT doa.driver_id
         FROM daily_order_assignments doa
         INNER JOIN daily_orders do ON do.id = doa.daily_order_id
         WHERE doa.delivery_date = ? AND do.customer_id = ?
         ORDER BY doa.id DESC LIMIT 1'
    );
    $stmt->execute([$date, $customerId]);
    return (int)$stmt->fetchColumn();
}

function route_manager_pickup_set_driver_total(
    PDO $db,
    string $date,
    int $productId,
    int $driverId,
    int $quantity,
    string $assign = 'existing'
): array {
    $stores = route_manager_pickup_target_stores($db, $date, $driverId, $productId, $assign);
    if ($stores === []) {
        throw new RuntimeException('This driver has no stores on the route to receive this product.');
    }
    $stores = route_manager_pickup_share($stores, max(0, $quantity));
    route_manager_pickup_write_stores($db, $date, $productId, $stores);
    route_manager_pickup_sync_load($db, $date, $driverId, $productId);
    return ['ok' => true, 'driver_id' => $driverId, 'quantity' => max(0, $quantity)];
}

function route_manager_pickup_target_stores(PDO $db, string $date, int $driverId, int $productId, string $assign): array
{
    $all = route_manager_pickup_driver_stops($db, $date, $driverId, $productId);
    if ($assign === 'all_stops') {
        return $all;
    }
    $existing = [];
    foreach ($all as $store) {
        if ((int)$store['quantity'] > 0) {
            $existing[] = $store;
        }
    }
    return $existing !== [] ? $existing : $all;
}

/**
 * @param array<string, mixed> $post
 * @return array<string, mixed>
 */
function route_manager_pickup_rebalance(PDO $db, array $post): array
{
    $date = trim((string)($post['date'] ?? ''));
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid date format; use YYYY-MM-DD');
    }
    $productId = (int)($post['product_id'] ?? 0);
    $op = (string)($post['op'] ?? '');
    $productIds = [];
    $decodedIds = json_decode((string)($post['product_ids'] ?? '[]'), true);
    if (is_array($decodedIds)) {
        foreach ($decodedIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $productIds[] = $id;
            }
        }
    }
    if ($productId > 0 && $productIds === []) {
        $productIds = [$productId];
    }
    if ($op === 'allocate') {
        return route_manager_pickup_allocate_many(
            $db,
            $date,
            $productIds,
            (string)($post['method'] ?? 'supposed'),
            (int)($post['tray_size'] ?? 0)
        );
    }
    if ($productId <= 0) {
        throw new InvalidArgumentException('Choose a product.');
    }
    $assign = (string)($post['assign'] ?? 'existing');
    if ($assign !== 'all_stops') {
        $assign = 'existing';
    }
    if ($op === 'set_store') {
        return route_manager_pickup_set_store(
            $db,
            $date,
            $productId,
            (int)($post['customer_id'] ?? 0),
            (int)($post['quantity'] ?? 0)
        );
    }
    if ($op === 'set_driver') {
        return route_manager_pickup_set_driver_total(
            $db,
            $date,
            $productId,
            (int)($post['driver_id'] ?? 0),
            (int)($post['quantity'] ?? 0),
            $assign
        );
    }
    if ($op === 'shift') {
        return route_manager_pickup_shift(
            $db,
            $date,
            $productId,
            (int)($post['from_driver_id'] ?? 0),
            (int)($post['to_driver_id'] ?? 0),
            (int)($post['quantity'] ?? 0),
            $assign
        );
    }
    if ($op === 'apply_plan') {
        $decoded = json_decode((string)($post['lines'] ?? '[]'), true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Store quantities are required.');
        }
        return route_manager_pickup_apply_plan($db, $date, $productId, $decoded);
    }
    throw new InvalidArgumentException('Unknown pickup change.');
}

/**
 * Rewrite one product's dated store lines. Unlocked piece total must stay the same.
 *
 * @param list<array<string,mixed>> $lines
 * @return array<string, mixed>
 */
function route_manager_pickup_apply_plan(PDO $db, string $date, int $productId, array $lines): array
{
    $driverIds = [];
    $idStmt = $db->prepare(
        'SELECT DISTINCT driver_id FROM daily_order_assignments WHERE delivery_date = ? AND driver_id > 0'
    );
    $idStmt->execute([$date]);
    foreach ($idStmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $driverIds[] = (int)$id;
    }
    $map = route_manager_pickup_route_stores_for_products($db, $date, $driverIds, [$productId]);
    $current = [];
    foreach (($map[$productId] ?? []) as $driverId => $stores) {
        foreach ($stores as $store) {
            $customerId = (int)$store['customer_id'];
            $current[$customerId] = $store + ['driver_id' => (int)$driverId];
        }
    }
    $wanted = [];
    foreach ($lines as $line) {
        $customerId = (int)($line['customer_id'] ?? 0);
        if ($customerId <= 0 || !isset($current[$customerId])) {
            throw new InvalidArgumentException('Every store in the plan must already be on a route that day.');
        }
        $wanted[$customerId] = max(0, (int)($line['quantity'] ?? 0));
    }
    foreach ($current as $customerId => $store) {
        if (!array_key_exists($customerId, $wanted)) {
            $wanted[$customerId] = (int)$store['quantity'];
        }
        if (!empty($store['locked']) && $wanted[$customerId] !== (int)$store['quantity']) {
            throw new RuntimeException('A stop already on the van cannot change from this board.');
        }
    }
    $oldUnlocked = 0;
    $newUnlocked = 0;
    foreach ($current as $customerId => $store) {
        if (!empty($store['locked'])) {
            continue;
        }
        $oldUnlocked += (int)$store['quantity'];
        $newUnlocked += (int)$wanted[$customerId];
    }
    if ($oldUnlocked !== $newUnlocked) {
        throw new RuntimeException(
            'Keep the same number of pieces. Take from one store or driver, then place them on another.'
        );
    }
    $grouped = [];
    $touchedDrivers = [];
    foreach ($current as $customerId => $store) {
        $driverId = (int)$store['driver_id'];
        $next = (int)$wanted[$customerId];
        if ($next !== (int)$store['quantity']) {
            $touchedDrivers[$driverId] = $driverId;
        }
        $store['next'] = $next;
        $grouped[$driverId][] = $store;
    }
    $own = !$db->inTransaction();
    if ($own) {
        $db->beginTransaction();
    }
    try {
        foreach ($grouped as $stores) {
            route_manager_pickup_write_stores($db, $date, $productId, $stores);
        }
        if ($own) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    foreach ($touchedDrivers as $driverId) {
        route_manager_pickup_sync_load($db, $date, $driverId, $productId);
    }
    return [
        'ok' => true,
        'product_id' => $productId,
        'unlocked_pieces' => $newUnlocked,
        'changed_drivers' => array_values($touchedDrivers),
    ];
}

/**
 * @return array<int, array<string,mixed>>
 */
function route_manager_pickup_current_product_stores(PDO $db, string $date, int $productId): array
{
    $driverIds = [];
    $idStmt = $db->prepare(
        'SELECT DISTINCT driver_id FROM daily_order_assignments WHERE delivery_date = ? AND driver_id > 0'
    );
    $idStmt->execute([$date]);
    foreach ($idStmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $driverIds[] = (int)$id;
    }
    $map = route_manager_pickup_route_stores_for_products($db, $date, $driverIds, [$productId]);
    $hints = route_manager_pickup_demand_hints($db, $date, $productId);
    $current = [];
    foreach (($map[$productId] ?? []) as $driverId => $stores) {
        $stores = route_manager_pickup_apply_demand_hints($stores, $hints);
        foreach ($stores as $store) {
            $customerId = (int)$store['customer_id'];
            $current[$customerId] = $store + ['driver_id' => (int)$driverId];
        }
    }
    return $current;
}

function route_manager_pickup_store_weight(array $store, string $prefer = 'supposed'): int
{
    $expected = (int)($store['expected_qty'] ?? $store['expected'] ?? 0);
    $standing = (int)($store['standing_qty'] ?? $store['standing'] ?? 0);
    if ($prefer === 'standing') {
        return max(0, $standing);
    }
    return $expected > 0 ? $expected : max(0, $standing);
}

/**
 * @param list<array<string,mixed>> $stores
 * @param list<int> $indexes
 */
function route_manager_pickup_set_next_by_weights(array &$stores, array $indexes, int $pool, string $remainder = 'largest'): void
{
    $n = count($indexes);
    $pool = max(0, $pool);
    if ($n === 0) {
        return;
    }
    if ($pool === 0) {
        foreach ($indexes as $i) {
            $stores[$i]['next'] = 0;
        }
        return;
    }
    $sum = 0;
    foreach ($indexes as $i) {
        $sum += max(0, (int)($stores[$i]['weight'] ?? 0));
    }
    if ($sum <= 0) {
        $base = intdiv($pool, $n);
        $rem = $pool % $n;
        foreach ($indexes as $j => $i) {
            $stores[$i]['next'] = $base + ($j < $rem ? 1 : 0);
        }
        return;
    }
    $used = 0;
    $remainders = [];
    foreach ($indexes as $j => $i) {
        $weight = max(0, (int)($stores[$i]['weight'] ?? 0));
        $raw = ($weight / $sum) * $pool;
        $floor = (int)floor($raw);
        $stores[$i]['next'] = $floor;
        $used += $floor;
        $remainders[] = [
            'i' => $i,
            'frac' => $raw - $floor,
            'w' => $weight,
            'name' => (string)($stores[$i]['name'] ?? ''),
        ];
    }
    $left = $pool - $used;
    if ($remainder === 'little') {
        usort($remainders, static function ($a, $b) {
            if ($a['w'] !== $b['w']) {
                return $a['w'] <=> $b['w'];
            }
            return strcasecmp($a['name'], $b['name']);
        });
    } else {
        usort($remainders, static function ($a, $b) {
            if ($a['frac'] === $b['frac']) {
                return $b['w'] <=> $a['w'];
            }
            return $b['frac'] <=> $a['frac'];
        });
    }
    foreach ($remainders as $row) {
        if ($left <= 0) {
            break;
        }
        $stores[$row['i']]['next']++;
        $left--;
    }
}

/**
 * Conserved reallocation of unlocked stores. Locked rows keep their quantity.
 *
 * @param list<array<string,mixed>> $stores
 * @return list<array<string,mixed>>
 */
function route_manager_pickup_allocate_unlocked(array $stores, string $method, int $traySize = 0): array
{
    $allowed = ['supposed', 'standing', 'by_van', 'little_shop', 'trays'];
    if (!in_array($method, $allowed, true)) {
        $method = 'supposed';
    }
    $prefer = $method === 'standing' ? 'standing' : 'supposed';
    $unlocked = [];
    $pool = 0;
    foreach ($stores as $i => $store) {
        if (!empty($store['locked'])) {
            $stores[$i]['next'] = (int)$store['quantity'];
            continue;
        }
        $stores[$i]['weight'] = route_manager_pickup_store_weight($store, $prefer);
        $unlocked[] = $i;
        $pool += (int)$store['quantity'];
    }
    if ($unlocked === []) {
        return $stores;
    }
    if ($method === 'by_van') {
        $byDriver = [];
        foreach ($unlocked as $i) {
            $byDriver[(int)($stores[$i]['driver_id'] ?? 0)][] = $i;
        }
        $vanStores = [];
        $vanIdx = [];
        foreach ($byDriver as $driverId => $idxs) {
            $weight = 0;
            foreach ($idxs as $i) {
                $weight += (int)$stores[$i]['weight'];
            }
            $vanIdx[] = $idxs;
            $vanStores[] = [
                'weight' => $weight,
                'name' => (string)$driverId,
            ];
        }
        $vanKeys = array_keys($vanStores);
        route_manager_pickup_set_next_by_weights($vanStores, $vanKeys, $pool, 'largest');
        foreach ($vanIdx as $j => $idxs) {
            route_manager_pickup_set_next_by_weights($stores, $idxs, (int)$vanStores[$j]['next'], 'largest');
        }
        return $stores;
    }
    $remainder = $method === 'little_shop' ? 'little' : 'largest';
    route_manager_pickup_set_next_by_weights($stores, $unlocked, $pool, $remainder);
    if ($method === 'trays' && $traySize > 1) {
        $spare = 0;
        foreach ($unlocked as $i) {
            $qty = (int)$stores[$i]['next'];
            $rounded = intdiv($qty, $traySize) * $traySize;
            $spare += $qty - $rounded;
            $stores[$i]['next'] = $rounded;
        }
        $fullTrays = intdiv($spare, $traySize);
        $bits = $spare % $traySize;
        usort($unlocked, static function ($a, $b) use ($stores) {
            return ((int)$stores[$b]['weight']) <=> ((int)$stores[$a]['weight']);
        });
        foreach ($unlocked as $i) {
            if ($fullTrays <= 0) {
                break;
            }
            $stores[$i]['next'] += $traySize;
            $fullTrays--;
        }
        usort($unlocked, static function ($a, $b) use ($stores) {
            $wa = (int)$stores[$a]['weight'];
            $wb = (int)$stores[$b]['weight'];
            if ($wa !== $wb) {
                return $wa <=> $wb;
            }
            return strcasecmp((string)($stores[$a]['name'] ?? ''), (string)($stores[$b]['name'] ?? ''));
        });
        foreach ($unlocked as $i) {
            if ($bits <= 0) {
                break;
            }
            $stores[$i]['next']++;
            $bits--;
        }
    }
    return $stores;
}

function route_manager_pickup_allocate_and_save(
    PDO $db,
    string $date,
    int $productId,
    string $method,
    int $traySize = 0
): array {
    $current = route_manager_pickup_current_product_stores($db, $date, $productId);
    if ($current === []) {
        throw new RuntimeException('No stores on route to rebalance.');
    }
    $list = array_values($current);
    $list = route_manager_pickup_allocate_unlocked($list, $method, $traySize);
    $lines = [];
    foreach ($list as $store) {
        $lines[] = [
            'customer_id' => (int)$store['customer_id'],
            'quantity' => (int)($store['next'] ?? $store['quantity'] ?? 0),
        ];
    }
    $result = route_manager_pickup_apply_plan($db, $date, $productId, $lines);
    $result['method'] = $method;
    return $result;
}

/**
 * Run the same conserved method on every SKU. Each product keeps its own piece pool.
 *
 * @param list<int> $productIds
 * @return array<string, mixed>
 */
function route_manager_pickup_allocate_many(
    PDO $db,
    string $date,
    array $productIds,
    string $method,
    int $traySize = 0
): array {
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
    if ($productIds === []) {
        throw new InvalidArgumentException('Choose a product or a family.');
    }
    $results = [];
    $own = !$db->inTransaction();
    if ($own) {
        $db->beginTransaction();
    }
    try {
        foreach ($productIds as $productId) {
            $perTray = $traySize;
            if ($method === 'trays' && $perTray <= 1 && function_exists('bakery_pack_count_breakdown')) {
                $break = bakery_pack_count_breakdown($db, $productId, 0);
                $perTray = (int)($break['pieces_per_tray'] ?? 0);
            }
            $current = route_manager_pickup_current_product_stores($db, $date, $productId);
            if ($current === []) {
                continue;
            }
            $results[] = route_manager_pickup_allocate_and_save($db, $date, $productId, $method, $perTray);
        }
        if ($own) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    return [
        'ok' => true,
        'method' => $method,
        'product_ids' => $productIds,
        'products' => $results,
    ];
}

function route_manager_pickup_shift(
    PDO $db,
    string $date,
    int $productId,
    int $fromDriverId,
    int $toDriverId,
    int $quantity,
    string $assign = 'existing'
): array {
    if ($fromDriverId === $toDriverId) {
        throw new RuntimeException('Choose a different driver to receive this product.');
    }
    $fromStores = route_manager_pickup_target_stores($db, $date, $fromDriverId, $productId, 'existing');
    $toStores = route_manager_pickup_target_stores($db, $date, $toDriverId, $productId, $assign);
    if ($toStores === []) {
        throw new RuntimeException('The receiving driver has no stores on this date. Assign a stop first, or spread across all of their stops.');
    }
    $fromUnlocked = 0;
    $fromTotal = 0;
    foreach ($fromStores as $store) {
        $fromTotal += (int)$store['quantity'];
        if (empty($store['locked'])) {
            $fromUnlocked += (int)$store['quantity'];
        }
    }
    $toTotal = 0;
    foreach ($toStores as $store) {
        $toTotal += (int)$store['quantity'];
    }
    $move = $quantity <= 0 ? $fromUnlocked : min($quantity, $fromUnlocked);
    if ($move <= 0) {
        throw new RuntimeException('Nothing unlocked is left to move from this driver.');
    }
    $fromStores = route_manager_pickup_share($fromStores, $fromTotal - $move);
    $toStores = route_manager_pickup_share($toStores, $toTotal + $move);
    $own = !$db->inTransaction();
    if ($own) {
        $db->beginTransaction();
    }
    try {
        route_manager_pickup_write_stores($db, $date, $productId, $fromStores);
        route_manager_pickup_write_stores($db, $date, $productId, $toStores);
        if ($own) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    route_manager_pickup_sync_load($db, $date, $fromDriverId, $productId);
    route_manager_pickup_sync_load($db, $date, $toDriverId, $productId);
    return [
        'ok' => true,
        'moved' => $move,
        'from_driver_id' => $fromDriverId,
        'to_driver_id' => $toDriverId,
    ];
}

/**
 * Normalize a driver_photos row into a display payload with URLs.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function route_manager_normalize_photo(PhotoHandler $photoHandler, array $row): array
{
    $urls = $photoHandler->getPhotoUrlWithFallback((string)($row['file_path'] ?? ''));
    return [
        'id' => (int)($row['id'] ?? 0),
        'driver_id' => (int)($row['driver_id'] ?? 0),
        'customer_id' => (int)($row['customer_id'] ?? 0),
        'photo_type' => $row['photo_type'] ?? 'Photo',
        'notes' => $row['notes'] ?? '',
        'created_at' => $row['created_at'] ?? '',
        'url' => $urls['primary'],
        'fallback_url' => $urls['fallback'],
        'customer_name' => $row['customer_name'] ?? '',
        'customer_address' => $row['customer_address'] ?? '',
    ];
}

/**
 * Fetch delivery photos for a driver/customer/date with display URLs.
 *
 * @return array<int, array<string, mixed>>
 */
function route_manager_fetch_photos(PDO $db, int $driverId, int $customerId, string $date): array
{
    if (!table_exists($db, 'driver_photos')) {
        return [];
    }

    $photoHandler = new PhotoHandler();
    $rows = $photoHandler->getPhotos($db, $driverId, $date, $customerId);
    $photos = [];
    foreach ($rows as $row) {
        $photos[] = route_manager_normalize_photo($photoHandler, $row);
    }
    return $photos;
}

/**
 * Fetch all delivery photos for a date, keyed by "driverId:customerId".
 *
 * @param array<int, int> $driverIds
 * @return array<string, array<int, array<string, mixed>>>
 */
function route_manager_fetch_photos_for_date(PDO $db, string $date, array $driverIds = []): array
{
    if (!table_exists($db, 'driver_photos')) {
        return [];
    }

    $sql = "
        SELECT p.*, c.name AS customer_name, c.address AS customer_address
        FROM driver_photos p
        INNER JOIN customers c ON c.id = p.customer_id
        WHERE p.delivery_date = ?
    ";
    $params = [$date];
    if (!empty($driverIds)) {
        $placeholders = implode(',', array_fill(0, count($driverIds), '?'));
        $sql .= " AND p.driver_id IN ($placeholders)";
        foreach ($driverIds as $id) {
            $params[] = (int)$id;
        }
    }
    $sql .= ' ORDER BY p.created_at ASC, p.id ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $photoHandler = new PhotoHandler();
    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (int)$row['driver_id'] . ':' . (int)$row['customer_id'];
        $grouped[$key][] = route_manager_normalize_photo($photoHandler, $row);
    }
    return $grouped;
}
