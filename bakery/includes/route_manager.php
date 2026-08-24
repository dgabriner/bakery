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
        $driversData[$driverId]['cash_summary'] = route_manager_compute_cash_summary($driverData['deliveries']);
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
