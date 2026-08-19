<?php
/**
 * Customer-facing delivery status, progress, and proof-of-delivery helpers.
 *
 * Canonical fulfillment: daily_orders + daily_order_assignments (no parallel system).
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/billing.php';

/**
 * Customer-safe lifecycle keys derived from operational state.
 *
 * @return array<string, array{label_key:string, message_key:string}>
 */
function bakery_customer_delivery_status_meta() {
    return [
        'confirmed' => [
            'label_key' => 'delivery.status_confirmed',
            'message_key' => 'delivery.msg_confirmed',
        ],
        'preparing' => [
            'label_key' => 'delivery.status_preparing',
            'message_key' => 'delivery.msg_preparing',
        ],
        'out_for_delivery' => [
            'label_key' => 'delivery.status_out',
            'message_key' => 'delivery.msg_out',
        ],
        'delivered' => [
            'label_key' => 'delivery.status_delivered',
            'message_key' => 'delivery.msg_delivered',
        ],
        'skipped' => [
            'label_key' => 'delivery.status_skipped',
            'message_key' => 'delivery.msg_skipped',
        ],
    ];
}

/**
 * Map operational order + assignment state to a customer lifecycle status.
 *
 * @param array<string, mixed> $order daily_orders row
 * @param array<string, mixed>|null $assignment daily_order_assignments row
 * @return array{key:string,label:string,message:string}
 */
function bakery_customer_delivery_derive_status(array $order, ?array $assignment) {
    $meta = bakery_customer_delivery_status_meta();
    $orderStatus = (string)($order['status'] ?? 'pending');
    $assignStatus = $assignment ? (string)($assignment['delivery_status'] ?? 'pending') : '';
    $confirmed = !empty($order['delivery_confirmed_at']);

    $build = static function ($key) use ($meta) {
        $m = $meta[$key] ?? $meta['confirmed'];
        return [
            'key' => $key,
            'label' => bakery_t($m['label_key']),
            'message' => bakery_t($m['message_key']),
        ];
    };

    if ($assignStatus === 'cancelled') {
        return $build('skipped');
    }

    if ($confirmed || $assignStatus === 'delivered'
        || in_array($orderStatus, ['delivered', 'invoiced'], true)) {
        return $build('delivered');
    }

    if ($orderStatus === 'out_for_delivery' || $assignStatus === 'in_transit') {
        return $build('out_for_delivery');
    }

    if (in_array($orderStatus, ['in_production', 'ready'], true)) {
        return $build('preparing');
    }

    return $build('confirmed');
}

/** Customer-facing status including portal skip records. */
function bakery_customer_delivery_public_status(PDO $db, array $order, ?array $assignment) {
    if (function_exists('bakery_customer_delivery_is_skipped')
        && !empty($order['customer_id']) && !empty($order['order_date'])
        && bakery_customer_delivery_is_skipped($db, (int)$order['customer_id'], (string)$order['order_date'])) {
        $meta = bakery_customer_delivery_status_meta()['skipped'];
        return [
            'key' => 'skipped',
            'label' => bakery_t($meta['label_key']),
            'message' => bakery_t($meta['message_key']),
        ];
    }
    return bakery_customer_delivery_derive_status($order, $assignment);
}

/**
 * Assert portal customer owns a daily order; returns row or throws.
 *
 * @return array<string, mixed>
 */
function bakery_customer_delivery_assert_ownership(PDO $db, int $customerId, int $dailyOrderId) {
    if ($customerId <= 0 || $dailyOrderId <= 0) {
        throw new InvalidArgumentException('Invalid delivery reference');
    }

    $stmt = $db->prepare(
        'SELECT do.*,
                doa.id AS assignment_id,
                doa.driver_id AS assignment_driver_id,
                doa.delivery_date AS assignment_delivery_date,
                doa.route_order,
                doa.scheduled_delivery_time,
                doa.actual_delivery_time,
                doa.estimated_delivery_time,
                doa.delivery_status AS assignment_delivery_status,
                d.name AS driver_name
         FROM daily_orders do
         LEFT JOIN daily_order_assignments doa ON doa.daily_order_id = do.id
         LEFT JOIN drivers d ON d.id = doa.driver_id
         WHERE do.id = ? AND do.customer_id = ?
         ORDER BY doa.id DESC
         LIMIT 1'
    );
    $stmt->execute([$dailyOrderId, $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Delivery not found');
    }
    return $row;
}

/**
 * Fetch daily order by customer + date.
 *
 * @return array<string, mixed>|null
 */
function bakery_customer_delivery_by_date(PDO $db, int $customerId, string $date) {
    if ($customerId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }

    $stmt = $db->prepare(
        'SELECT do.*,
                doa.id AS assignment_id,
                doa.driver_id AS assignment_driver_id,
                doa.delivery_date AS assignment_delivery_date,
                doa.route_order,
                doa.scheduled_delivery_time,
                doa.actual_delivery_time,
                doa.estimated_delivery_time,
                doa.delivery_status AS assignment_delivery_status,
                d.name AS driver_name
         FROM daily_orders do
         LEFT JOIN daily_order_assignments doa ON doa.daily_order_id = do.id
         LEFT JOIN drivers d ON d.id = doa.driver_id
         WHERE do.customer_id = ? AND do.order_date = ?
         ORDER BY doa.id DESC
         LIMIT 1'
    );
    $stmt->execute([$customerId, $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Line items for a daily order with ordered/delivered quantities.
 *
 * @return array<int, array<string, mixed>>
 */
function bakery_customer_delivery_items(PDO $db, int $dailyOrderId) {
    $stmt = $db->prepare(
        'SELECT doi.id, doi.product_id, doi.quantity, doi.delivered_quantity,
                doi.unit_price, doi.line_total, p.name AS product_name
         FROM daily_order_items doi
         JOIN products p ON p.id = doi.product_id
         WHERE doi.daily_order_id = ?
         ORDER BY p.name'
    );
    $stmt->execute([$dailyOrderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Count incomplete stops ahead on the same driver route (conservative progress).
 */
function bakery_customer_delivery_stops_ahead(PDO $db, int $driverId, string $deliveryDate, int $routeOrder): ?int {
    if ($driverId <= 0 || $routeOrder <= 0) {
        return null;
    }

    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM daily_order_assignments doa
         WHERE doa.driver_id = ? AND doa.delivery_date = ?
           AND doa.route_order < ?
           AND doa.delivery_status NOT IN (\'delivered\', \'cancelled\')'
    );
    $stmt->execute([$driverId, $deliveryDate, $routeOrder]);
    $count = (int)$stmt->fetchColumn();
    return $count >= 0 ? $count : null;
}

/**
 * Customer-safe GPS summary — no precise coordinates exposed.
 *
 * @param array<string, mixed>|null $gpsEvent operational_events row or photo row
 */
function bakery_customer_delivery_gps_summary(?array $gpsEvent): ?string {
    if (!$gpsEvent) {
        return null;
    }

    $status = strtolower(trim((string)($gpsEvent['gps_status'] ?? '')));
    if ($status === 'captured') {
        return bakery_t('delivery.location_confirmed');
    }

    $lat = $gpsEvent['gps_latitude'] ?? $gpsEvent['latitude'] ?? null;
    $lng = $gpsEvent['gps_longitude'] ?? $gpsEvent['longitude'] ?? null;
    if ($lat !== null && $lng !== null && $lat !== '' && $lng !== '') {
        return bakery_t('delivery.location_confirmed');
    }

    return null;
}

/**
 * Delivery completion GPS from operational_events (customer-owned order only).
 *
 * @return array<string, mixed>|null
 */
function bakery_customer_delivery_completion_gps(PDO $db, int $dailyOrderId, int $customerId) {
    if (!table_exists($db, 'operational_events')) {
        return null;
    }

    $stmt = $db->prepare(
        "SELECT gps_latitude, gps_longitude, gps_accuracy_m, gps_status, occurred_at
         FROM operational_events
         WHERE daily_order_id = ? AND customer_id = ?
           AND event_type IN ('delivery_completed', 'delivery_marked')
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([$dailyOrderId, $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Photos for a delivery, scoped to authenticated customer.
 *
 * @return array<int, array<string, mixed>>
 */
function bakery_customer_delivery_photos(PDO $db, int $customerId, int $dailyOrderId, string $orderDate) {
    if (!table_exists($db, 'driver_photos')) {
        return [];
    }

    $stmt = $db->prepare(
        'SELECT id, photo_type, filename, file_path, mime_type, created_at,
                latitude, longitude
         FROM driver_photos
         WHERE customer_id = ? AND delivery_date = ?
         ORDER BY created_at DESC'
    );
    $stmt->execute([$customerId, $orderDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $photos = [];
    foreach ($rows as $row) {
        $photos[] = [
            'id' => (int)$row['id'],
            'photo_type' => (string)($row['photo_type'] ?? 'After'),
            'created_at' => (string)($row['created_at'] ?? ''),
            'url' => BASE_URL . 'customer_portal_delivery_photo.php?id=' . (int)$row['id'],
            'has_location' => bakery_customer_delivery_gps_summary($row) !== null,
        ];
    }
    return $photos;
}

/**
 * Verify customer owns a delivery photo.
 *
 * @return array<string, mixed>|null
 */
function bakery_customer_delivery_photo_for_customer(PDO $db, int $customerId, int $photoId) {
    if (!table_exists($db, 'driver_photos') || $customerId <= 0 || $photoId <= 0) {
        return null;
    }

    $stmt = $db->prepare(
        'SELECT id, customer_id, delivery_date, file_path, mime_type, photo_type
         FROM driver_photos
         WHERE id = ? AND customer_id = ?
         LIMIT 1'
    );
    $stmt->execute([$photoId, $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Build a compact status card payload for a delivery row.
 *
 * @param array<string, mixed> $order Row from assert_ownership / by_date
 * @return array<string, mixed>
 */
function bakery_customer_delivery_card(PDO $db, array $order) {
    $assignment = [
        'delivery_status' => $order['assignment_delivery_status'] ?? null,
        'route_order' => $order['route_order'] ?? null,
        'driver_id' => $order['assignment_driver_id'] ?? null,
        'delivery_date' => $order['assignment_delivery_date'] ?? $order['order_date'],
    ];

    $status = bakery_customer_delivery_public_status($db, $order, $assignment);
    $dailyOrderId = (int)$order['id'];
    $orderDate = (string)$order['order_date'];

    $progress = null;
    if ($status['key'] === 'out_for_delivery') {
        $driverId = (int)($order['assignment_driver_id'] ?? 0);
        $routeOrder = (int)($order['route_order'] ?? 0);
        $deliveryDate = (string)($order['assignment_delivery_date'] ?? $orderDate);
        if ($driverId > 0 && $routeOrder > 0) {
            $ahead = bakery_customer_delivery_stops_ahead($db, $driverId, $deliveryDate, $routeOrder);
            if ($ahead !== null && $ahead > 0) {
                $progress = bakery_t('delivery.stops_ahead', ['count' => $ahead]);
            } elseif ($ahead === 0) {
                $progress = bakery_t('delivery.next_stop');
            }
        }
    }

    return [
        'daily_order_id' => $dailyOrderId,
        'order_date' => $orderDate,
        'date_label' => date('l M j', strtotime($orderDate)),
        'status' => $status,
        'progress' => $progress,
        'detail_url' => BASE_URL . 'customer_portal_delivery.php?date=' . urlencode($orderDate),
    ];
}

/**
 * Full delivery detail + proof payload for customer portal.
 *
 * @return array<string, mixed>
 */
function bakery_customer_delivery_detail(PDO $db, int $customerId, int $dailyOrderId) {
    $order = bakery_customer_delivery_assert_ownership($db, $customerId, $dailyOrderId);
    $items = bakery_customer_delivery_items($db, $dailyOrderId);

    $billingOrder = [
        'status' => $order['status'],
        'delivery_confirmed_at' => $order['delivery_confirmed_at'] ?? null,
        'delivered_pieces' => $order['delivered_pieces'] ?? null,
        'credits_taken_back' => $order['credits_taken_back'] ?? 0,
        'delivery_order_total' => $order['delivery_order_total'] ?? null,
        'delivery_pricing_label' => $order['delivery_pricing_label'] ?? '',
        'assignment_delivery_status' => $order['assignment_delivery_status'] ?? '',
    ];
    $classification = bakery_billing_classify_order($billingOrder, $items);

    $assignment = [
        'delivery_status' => $order['assignment_delivery_status'] ?? null,
    ];
    $status = bakery_customer_delivery_public_status($db, $order, $assignment);

    $driverId = (int)($order['assignment_driver_id'] ?? 0);
    $routeOrder = (int)($order['route_order'] ?? 0);
    $deliveryDate = (string)($order['assignment_delivery_date'] ?? $order['order_date']);
    $progress = null;
    if ($status['key'] === 'out_for_delivery' && $driverId > 0 && $routeOrder > 0) {
        $ahead = bakery_customer_delivery_stops_ahead($db, $driverId, $deliveryDate, $routeOrder);
        if ($ahead !== null && $ahead > 0) {
            $progress = bakery_t('delivery.stops_ahead', ['count' => $ahead]);
        } elseif ($ahead === 0) {
            $progress = bakery_t('delivery.next_stop');
        }
    }

    $deliveredAt = null;
    $deliveredTimeLabel = null;
    if (!empty($order['delivery_confirmed_at'])) {
        $deliveredAt = (string)$order['delivery_confirmed_at'];
        $deliveredTimeLabel = date('g:i A', strtotime($deliveredAt));
    } elseif (!empty($order['actual_delivery_time'])) {
        $deliveredTimeLabel = date('g:i A', strtotime((string)$order['actual_delivery_time']));
    }

    $gpsEvent = bakery_customer_delivery_completion_gps($db, $dailyOrderId, $customerId);
    $locationSummary = bakery_customer_delivery_gps_summary($gpsEvent);
    if ($locationSummary === null && $status['key'] === 'delivered') {
        $photos = bakery_customer_delivery_photos($db, $customerId, $dailyOrderId, (string)$order['order_date']);
        foreach ($photos as $photo) {
            if (!empty($photo['has_location'])) {
                $locationSummary = bakery_t('delivery.location_confirmed');
                break;
            }
        }
    }

    $photos = bakery_customer_delivery_photos($db, $customerId, $dailyOrderId, (string)$order['order_date']);

    $invoiceNumber = null;
    if (!empty($order['delivery_confirmed_at'])) {
        $invoiceNumber = bakery_billing_invoice_number($dailyOrderId, (string)$order['order_date']);
    }

    $lineItems = [];
    foreach ($classification['items'] as $item) {
        $lineItems[] = [
            'product_id' => (int)$item['product_id'],
            'product_name' => $item['product_name'],
            'ordered' => (int)$item['quantity'],
            'delivered' => $item['delivered_quantity'] !== null ? (int)$item['delivered_quantity'] : null,
            'variance' => $item['variance'],
            'has_variance' => $item['variance'] !== null && $item['variance'] !== 0,
        ];
    }

    return [
        'daily_order_id' => $dailyOrderId,
        'order_date' => (string)$order['order_date'],
        'date_label' => date('l, F j, Y', strtotime((string)$order['order_date'])),
        'status' => $status,
        'progress' => $progress,
        'delivered_at' => $deliveredAt,
        'delivered_time_label' => $deliveredTimeLabel,
        'driver_name' => !empty($order['driver_name']) ? (string)$order['driver_name'] : null,
        'items' => $lineItems,
        'has_quantity_variance' => !empty($classification['has_quantity_variance']),
        'invoice_number' => $invoiceNumber,
        'location_summary' => $locationSummary,
        'photos' => $photos,
        'has_photos' => count($photos) > 0,
    ];
}

/**
 * Recent deliveries for customer history index (Agent 1 can link to detail_url).
 *
 * @return array<int, array<string, mixed>>
 */
function bakery_customer_delivery_recent(PDO $db, int $customerId, int $limit = 20) {
    $limit = max(1, min(50, $limit));

    $stmt = $db->prepare(
        'SELECT do.id, do.order_date, do.status, do.delivery_confirmed_at,
                doa.delivery_status AS assignment_delivery_status
         FROM daily_orders do
         LEFT JOIN daily_order_assignments doa ON doa.daily_order_id = do.id
         WHERE do.customer_id = ?
         ORDER BY do.order_date DESC, do.id DESC
         LIMIT ' . (int)$limit
    );
    $stmt->execute([$customerId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($rows as $row) {
        $assignment = ['delivery_status' => $row['assignment_delivery_status'] ?? null];
        $status = bakery_customer_delivery_public_status($db, $row, $assignment);
        $results[] = [
            'daily_order_id' => (int)$row['id'],
            'order_date' => (string)$row['order_date'],
            'date_label' => date('M j, Y', strtotime((string)$row['order_date'])),
            'status' => $status,
            'detail_url' => BASE_URL . 'customer_portal_delivery.php?date=' . urlencode((string)$row['order_date']),
        ];
    }
    return $results;
}

/**
 * Today's or next upcoming active delivery for the status card on portal home.
 *
 * @return array<string, mixed>|null
 */
function bakery_customer_delivery_featured(PDO $db, int $customerId) {
    $today = date('Y-m-d');

    $todayOrder = bakery_customer_delivery_by_date($db, $customerId, $today);
    if ($todayOrder) {
        return bakery_customer_delivery_card($db, $todayOrder);
    }

    $stmt = $db->prepare(
        'SELECT do.id
         FROM daily_orders do
         LEFT JOIN daily_order_assignments doa ON doa.daily_order_id = do.id
         WHERE do.customer_id = ? AND do.order_date > ?
           AND (doa.delivery_status IS NULL OR doa.delivery_status <> \'cancelled\')
         ORDER BY do.order_date ASC
         LIMIT 1'
    );
    $stmt->execute([$customerId, $today]);
    $nextId = (int)$stmt->fetchColumn();
    if ($nextId > 0) {
        $order = bakery_customer_delivery_assert_ownership($db, $customerId, $nextId);
        return bakery_customer_delivery_card($db, $order);
    }

    return null;
}
