<?php
/**
 * Customer portal command center — read/write helpers scoped to session customer.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/customer_record.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/customer_order_mutations.php';
require_once __DIR__ . '/customer_delivery.php';

/**
 * Load a daily order only if it belongs to the authenticated customer.
 *
 * @return array|null
 */
function bakery_portal_cmd_load_owned_daily_order(PDO $db, $customerId, $dailyOrderId) {
    $stmt = $db->prepare(
        'SELECT do.id, do.customer_id, do.order_date, do.status, do.total_amount,
                do.delivery_confirmed_at, do.delivery_order_total
         FROM daily_orders do
         WHERE do.id = ? AND do.customer_id = ?
         LIMIT 1'
    );
    $stmt->execute([(int)$dailyOrderId, (int)$customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Verify a delivery date belongs to this customer (by order id or date lookup).
 */
function bakery_portal_cmd_assert_delivery_date(PDO $db, $customerId, $date) {
    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid delivery date');
    }

    $dayOfWeek = bakery_standing_day_from_date($date);
    $standingRoutes = bakery_customer_record_standing_routes($db, $customerId);
    $standingByDay = bakery_customer_record_standing_schedule($db, $customerId);
    $weekStart = bakery_week_start_monday($date);
    $paused = bakery_customer_week_is_paused($db, $customerId, $weekStart)
        || bakery_customer_delivery_in_pause_range($db, $customerId, $date);
    $customerSkipped = bakery_customer_delivery_is_skipped($db, $customerId, $date);

    $context = bakery_customer_record_date_context(
        $db,
        $customerId,
        $date,
        $dayOfWeek,
        $standingRoutes
    );
    $context['paused'] = $paused || $customerSkipped;
    $context['week_paused'] = bakery_customer_week_is_paused($db, $customerId, $weekStart);
    $context['range_paused'] = bakery_customer_delivery_in_pause_range($db, $customerId, $date);
    $context['customer_skipped'] = $customerSkipped;
    $context['week_start'] = $weekStart;
    $context['day_of_week'] = $dayOfWeek;

    $hasStanding = ($standingByDay[$dayOfWeek]['total_units'] ?? 0) > 0;
    $hasDaily = !empty($context['daily_order_id']);

    if (!$hasStanding && !$hasDaily) {
        throw new RuntimeException('No delivery scheduled for this date');
    }

    return $context;
}

/**
 * Customer-readable delivery status derived from stored operational data.
 *
 * @return array{key:string,label:string,tone:string}
 */
function bakery_portal_cmd_customer_delivery_status(array $context) {
    if (!empty($context['paused']) && empty($context['daily_order_id'])) {
        return ['key' => 'skipped', 'label' => 'Skipped', 'tone' => 'muted'];
    }

    $assignment = $context['dated_route']['assignment_status'] ?? null;
    $orderStatus = (string)($context['status'] ?? '');

    if ($assignment === 'cancelled') {
        return ['key' => 'skipped', 'label' => 'Skipped', 'tone' => 'muted'];
    }
    if ($assignment === 'failed') {
        return ['key' => 'failed', 'label' => 'Delivery issue', 'tone' => 'danger'];
    }
    if ($assignment === 'delivered' || $orderStatus === 'delivered' || $orderStatus === 'invoiced') {
        return ['key' => 'delivered', 'label' => 'Delivered', 'tone' => 'ok'];
    }
    if ($assignment === 'in_transit' || $orderStatus === 'out_for_delivery') {
        return ['key' => 'out_for_delivery', 'label' => 'Out for delivery', 'tone' => 'info'];
    }
    if (in_array($orderStatus, ['in_production', 'ready'], true)) {
        return ['key' => 'preparing', 'label' => 'Preparing', 'tone' => 'warn'];
    }
    if ($orderStatus === 'confirmed') {
        return ['key' => 'confirmed', 'label' => 'Confirmed', 'tone' => 'ok'];
    }

    $state = (string)($context['state'] ?? '');
    if ($state === 'changed') {
        return ['key' => 'modified', 'label' => 'Modified', 'tone' => 'warn'];
    }
    if ($state === 'paused') {
        return ['key' => 'skipped', 'label' => 'Skipped', 'tone' => 'muted'];
    }

    return ['key' => 'upcoming', 'label' => 'Upcoming', 'tone' => 'info'];
}

/**
 * Customer-facing status explanation (honest, non-operational).
 */
function bakery_portal_cmd_delivery_status_message($statusKey) {
    $map = [
        'confirmed' => 'delivery.msg_confirmed',
        'preparing' => 'delivery.msg_preparing',
        'out_for_delivery' => 'delivery.msg_out',
        'delivered' => 'delivery.msg_delivered',
        'skipped' => 'delivery.msg_skipped',
        'failed' => 'delivery.msg_failed',
        'modified' => 'delivery.msg_modified',
        'upcoming' => 'delivery.msg_upcoming',
    ];
    $key = $map[$statusKey] ?? 'delivery.msg_upcoming';
    return bakery_t($key);
}

/**
 * Whether the customer may change quantities for this dated delivery.
 */
function bakery_portal_cmd_delivery_can_edit($date, array $context) {
    if ($date < date('Y-m-d')) {
        return false;
    }
    if (!empty($context['paused']) || !empty($context['customer_skipped'])
        || !empty($context['range_paused'])) {
        return false;
    }

    $assignment = $context['dated_route']['assignment_status'] ?? null;
    $orderStatus = (string)($context['status'] ?? '');

    if (in_array($assignment, ['delivered', 'cancelled', 'failed'], true)) {
        return false;
    }
    if (in_array($orderStatus, ['in_production', 'ready', 'out_for_delivery', 'delivered', 'invoiced'], true)) {
        return false;
    }

    return true;
}

/**
 * Human label for standing vs daily comparison state.
 */
function bakery_portal_cmd_schedule_note(array $context) {
    $state = (string)($context['state'] ?? '');
    $map = [
        'matches' => 'Matches your regular order',
        'changed' => 'Modified from your regular order',
        'missing_daily' => 'Based on your regular order',
        'one_off' => 'Custom delivery (not from regular schedule)',
        'empty_daily' => 'Delivery scheduled with no items yet',
        'paused' => 'Delivery paused this week',
    ];
    return $map[$state] ?? bakery_customer_record_state_label($state);
}

/**
 * Lines to display for a delivery card (daily when committed, else standing forecast).
 *
 * @return array<int, array{product_id:int,product_name:string,quantity:int,delivered_quantity:int|null}>
 */
function bakery_portal_cmd_delivery_display_lines(array $context) {
    $lines = [];
    if (!empty($context['daily_lines'])) {
        foreach ($context['daily_lines'] as $line) {
            if ((int)($line['daily_qty'] ?? 0) <= 0) {
                continue;
            }
            $lines[] = [
                'product_id' => (int)$line['product_id'],
                'product_name' => $line['product_name'],
                'quantity' => (int)$line['daily_qty'],
                'delivered_quantity' => $line['delivered_quantity'] !== null
                    ? (int)$line['delivered_quantity']
                    : null,
            ];
        }
        return $lines;
    }

    foreach ($context['standing_lines'] ?? [] as $line) {
        if ((int)($line['standing_qty'] ?? 0) <= 0) {
            continue;
        }
        $lines[] = [
            'product_id' => (int)$line['product_id'],
            'product_name' => $line['product_name'],
            'quantity' => (int)$line['standing_qty'],
            'delivered_quantity' => null,
        ];
    }
    return $lines;
}

/**
 * Total units for display on cards.
 */
function bakery_portal_cmd_delivery_total_units(array $context) {
    $lines = bakery_portal_cmd_delivery_display_lines($context);
    $total = 0;
    foreach ($lines as $line) {
        $total += (int)$line['quantity'];
    }
    return $total;
}

/**
 * Build one delivery card payload for home/calendar views.
 *
 * @return array
 */
function bakery_portal_cmd_build_delivery_card(PDO $db, $customerId, $date, array $context) {
    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    $status = bakery_portal_cmd_customer_delivery_status($context);
    $lines = bakery_portal_cmd_delivery_display_lines($context);
    $totalUnits = bakery_portal_cmd_delivery_total_units($context);

    $progress = null;
    if ($status['key'] === 'out_for_delivery' && !empty($context['daily_order_id'])) {
        try {
            $orderRow = bakery_customer_delivery_assert_ownership(
                $db,
                (int)$customerId,
                (int)$context['daily_order_id']
            );
            $driverId = (int)($orderRow['assignment_driver_id'] ?? 0);
            $routeOrder = (int)($orderRow['route_order'] ?? 0);
            $deliveryDate = (string)($orderRow['assignment_delivery_date'] ?? $date);
            if ($driverId > 0 && $routeOrder > 0) {
                $ahead = bakery_customer_delivery_stops_ahead($db, $driverId, $deliveryDate, $routeOrder);
                if ($ahead !== null && $ahead > 0) {
                    $progress = bakery_t('delivery.stops_ahead', ['count' => $ahead]);
                } elseif ($ahead === 0) {
                    $progress = bakery_t('delivery.next_stop');
                }
            }
        } catch (Throwable $e) {
            // Progress is optional — omit when route data is incomplete.
        }
    }

    return [
        'date' => $date,
        'date_label' => $dateObject ? $dateObject->format('l, F j') : $date,
        'date_short' => $dateObject ? $dateObject->format('D M j') : $date,
        'day_of_week' => (int)($context['day_of_week'] ?? bakery_standing_day_from_date($date)),
        'daily_order_id' => $context['daily_order_id'] ?? null,
        'status' => $status,
        'status_message' => bakery_portal_cmd_delivery_status_message($status['key']),
        'progress' => $progress,
        'schedule_state' => $context['state'] ?? null,
        'schedule_note' => bakery_portal_cmd_schedule_note($context),
        'lines' => $lines,
        'total_units' => $totalUnits,
        'can_edit' => bakery_portal_cmd_delivery_can_edit($date, $context),
        'paused' => !empty($context['paused']),
        'delivery_confirmed_at' => $context['delivery_confirmed_at'] ?? null,
        'invoice_number' => !empty($context['daily_order_id'])
            ? bakery_billing_invoice_number((int)$context['daily_order_id'], $date)
            : null,
        'has_proof' => !empty($context['daily_order_id'])
            ? bakery_portal_cmd_delivery_has_proof(
                $db,
                (int)$context['daily_order_id'],
                (int)$customerId,
                $date
            )
            : false,
    ];
}

/**
 * Upcoming delivery dates for one customer.
 *
 * @return array<int, array>
 */
function bakery_portal_cmd_prefetch_dated_routes(PDO $db, array $orderIds, $startDate, $endDate) {
    $orderIds = array_values(array_filter(array_map('intval', $orderIds)));
    if ($orderIds === []) {
        return [];
    }

    $assignmentHasNotes = column_exists($db, 'daily_order_assignments', 'notes');
    $assignmentNotesSelect = $assignmentHasNotes ? 'doa.notes,' : 'NULL AS notes,';
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

    $stmt = $db->prepare("
        SELECT doa.daily_order_id, doa.delivery_date, doa.driver_id, doa.route_order,
               doa.delivery_status, doa.scheduled_delivery_time, doa.actual_delivery_time,
               {$assignmentNotesSelect}
               d.name AS driver_name
        FROM daily_order_assignments doa
        LEFT JOIN drivers d ON d.id = doa.driver_id
        WHERE doa.daily_order_id IN ({$placeholders})
          AND doa.delivery_date BETWEEN ? AND ?
        ORDER BY doa.daily_order_id, doa.route_order, doa.id
    ");
    $stmt->execute(array_merge($orderIds, [(string)$startDate, (string)$endDate]));

    $routes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (int)$row['daily_order_id'] . '|' . (string)$row['delivery_date'];
        if (isset($routes[$key])) {
            continue;
        }
        $routes[$key] = [
            'driver_id' => $row['driver_id'] !== null ? (int)$row['driver_id'] : null,
            'driver_name' => $row['driver_name'] ?: null,
            'route_order' => $row['route_order'] !== null ? (int)$row['route_order'] : null,
            'assignment_status' => $row['delivery_status'] ?? null,
            'scheduled_delivery_time' => $row['scheduled_delivery_time'] ?? null,
            'actual_delivery_time' => $row['actual_delivery_time'] ?? null,
            'notes' => $row['notes'] ?? null,
        ];
    }
    return $routes;
}

function bakery_portal_cmd_schedule_deliveries(PDO $db, $customerId, $daysAhead = 28, $limit = 12) {
    static $cache = [];
    $cacheKey = (int)$customerId . ':' . (int)$daysAhead . ':' . (int)$limit;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $customerId = (int)$customerId;
    $standingByDay = bakery_customer_record_standing_schedule($db, $customerId);
    $standingRoutes = bakery_customer_record_standing_routes($db, $customerId);
    $pausedWeeks = bakery_customer_paused_week_starts($db, $customerId);
    $today = date('Y-m-d');
    $endDate = date('Y-m-d', strtotime($today . ' +' . (int)$daysAhead . ' days'));
    $cards = [];

    $dailyByDate = [];
    $orderIds = [];
    $dailyStmt = $db->prepare(
        'SELECT id, order_date, status, total_amount, notes, delivery_confirmed_at,
                delivery_order_total, delivered_pieces, credits_taken_back
         FROM daily_orders
         WHERE customer_id = ? AND order_date BETWEEN ? AND ?'
    );
    $dailyStmt->execute([$customerId, $today, $endDate]);
    foreach ($dailyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dailyByDate[(string)$row['order_date']] = $row;
        $orderIds[] = (int)$row['id'];
    }

    $itemsByOrderId = bakery_portal_cmd_history_items_by_order($db, $orderIds);
    $routesByOrderDate = bakery_portal_cmd_prefetch_dated_routes($db, $orderIds, $today, $endDate);

    for ($offset = 0; $offset <= $daysAhead; $offset++) {
        $date = date('Y-m-d', strtotime($today . ' +' . $offset . ' days'));
        $dow = bakery_standing_day_from_date($date);
        $hasStanding = ($standingByDay[$dow]['total_units'] ?? 0) > 0;
        $dailyOrder = $dailyByDate[$date] ?? null;
        $hasDaily = $dailyOrder !== null;

        if (!$hasStanding && !$hasDaily) {
            continue;
        }

        $weekStart = bakery_week_start_monday($date);
        $paused = !empty($pausedWeeks[(string)$weekStart]);

        try {
            $routeKey = $dailyOrder ? ((int)$dailyOrder['id'] . '|' . $date) : '';
            $context = bakery_customer_record_date_context(
                $db,
                $customerId,
                $date,
                $dow,
                $standingRoutes,
                [
                    'standing_by_day' => $standingByDay,
                    'daily_order' => $dailyOrder,
                    'daily_items' => $dailyOrder ? ($itemsByOrderId[(int)$dailyOrder['id']] ?? []) : [],
                    'dated_route' => $routeKey !== '' ? ($routesByOrderDate[$routeKey] ?? null) : null,
                ]
            );
        } catch (Throwable $e) {
            error_log('portal_cmd_schedule_deliveries: ' . $e->getMessage());
            continue;
        }

        $context['paused'] = $paused;
        $context['week_start'] = $weekStart;
        $context['day_of_week'] = $dow;

        $cards[] = bakery_portal_cmd_build_delivery_card($db, $customerId, $date, $context);

        if (count($cards) >= $limit) {
            break;
        }
    }

    $cache[$cacheKey] = $cards;
    return $cards;
}

/**
 * First meaningful upcoming delivery (skips paused-without-order when possible).
 *
 * @return array|null
 */
function bakery_portal_cmd_next_delivery(PDO $db, $customerId) {
    $home = bakery_portal_cmd_home_deliveries($db, $customerId);
    return $home['next'];
}

/**
 * Home page schedule — one batched pass for next + upcoming cards.
 *
 * @return array{next: ?array, upcoming: array<int, array>}
 */
function bakery_portal_cmd_home_deliveries(PDO $db, $customerId) {
    static $cache = [];
    $customerId = (int)$customerId;
    if (isset($cache[$customerId])) {
        return $cache[$customerId];
    }

    $cards = bakery_portal_cmd_schedule_deliveries($db, $customerId, 42, 20);
    $next = null;
    foreach ($cards as $card) {
        if ($card['paused'] && empty($card['daily_order_id'])) {
            continue;
        }
        $next = $card;
        break;
    }
    if ($next === null && $cards !== []) {
        $next = $cards[0];
    }

    $upcoming = [];
    foreach ($cards as $card) {
        if ($next && $card['date'] === $next['date']) {
            continue;
        }
        $upcoming[] = $card;
        if (count($upcoming) >= 6) {
            break;
        }
    }

    $cache[$customerId] = ['next' => $next, 'upcoming' => $upcoming];
    return $cache[$customerId];
}

/**
 * Most recent past delivery summary.
 *
 * @return array|null
 */
function bakery_portal_cmd_recent_delivery(PDO $db, $customerId) {
    $stmt = $db->prepare(
        'SELECT do.id, do.order_date, do.status, do.delivery_confirmed_at
         FROM daily_orders do
         WHERE do.customer_id = ? AND do.order_date < ?
         ORDER BY do.order_date DESC, do.id DESC
         LIMIT 1'
    );
    $stmt->execute([(int)$customerId, date('Y-m-d')]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $standingRoutes = bakery_customer_record_standing_routes($db, $customerId);
    $dow = bakery_standing_day_from_date($row['order_date']);
    try {
        $context = bakery_customer_record_date_context(
            $db,
            $customerId,
            $row['order_date'],
            $dow,
            $standingRoutes
        );
        return bakery_portal_cmd_build_delivery_card($db, $customerId, $row['order_date'], $context);
    } catch (Throwable $e) {
        error_log('portal_cmd_recent_delivery: ' . $e->getMessage());
        return null;
    }
}

/**
 * Items that may need customer attention on the home page.
 *
 * @return array<int, array{level:string,message:string,link?:string}>
 */
function bakery_portal_cmd_attention_items(PDO $db, array $customer, array $nextDelivery = null) {
    $items = [];
    $customerId = (int)$customer['id'];

    if (trim((string)($customer['address'] ?? '')) === '') {
        $items[] = [
            'level' => 'warn',
            'message' => bakery_t('portal.attention_missing_address'),
        ];
    }

    $weekStart = bakery_week_start_monday();
    if (bakery_customer_week_is_paused($db, $customerId, $weekStart)) {
        $items[] = [
            'level' => 'info',
            'message' => bakery_t('portal.attention_paused_week'),
            'link' => 'customer_portal_regular.php',
        ];
    }

    if ($nextDelivery && !empty($nextDelivery['can_edit']) && ($nextDelivery['schedule_state'] ?? '') === 'missing_daily') {
        $items[] = [
            'level' => 'info',
            'message' => bakery_t('portal.attention_review_next'),
            'link' => 'customer_portal_delivery.php?date=' . urlencode($nextDelivery['date']),
        ];
    }

    return $items;
}

/**
 * Map customer delivery status keys to portal badge tones.
 */
function bakery_portal_cmd_history_status_tone($statusKey) {
    $map = [
        'delivered' => 'ok',
        'confirmed' => 'ok',
        'preparing' => 'warn',
        'out_for_delivery' => 'info',
        'skipped' => 'muted',
        'failed' => 'danger',
        'modified' => 'warn',
        'upcoming' => 'info',
    ];
    return $map[$statusKey] ?? 'muted';
}

/**
 * Build one history card from a daily_orders row and optional line items.
 *
 * @param array<int, array<string, mixed>> $itemsByOrderId
 */
function bakery_portal_cmd_history_row_card(
    PDO $db,
    $customerId,
    array $row,
    array $itemsByOrderId = []
) {
    $orderId = (int)$row['id'];
    $orderDate = (string)$row['order_date'];
    $assignment = ['delivery_status' => $row['assignment_delivery_status'] ?? null];
    $orderRow = [
        'id' => $orderId,
        'customer_id' => (int)$customerId,
        'order_date' => $orderDate,
        'status' => $row['status'] ?? null,
        'delivery_confirmed_at' => $row['delivery_confirmed_at'] ?? null,
    ];
    $publicStatus = bakery_customer_delivery_public_status($db, $orderRow, $assignment);

    $lines = [];
    foreach ($itemsByOrderId[$orderId] ?? [] as $item) {
        if ((int)($item['quantity'] ?? 0) <= 0) {
            continue;
        }
        $lines[] = [
            'product_id' => (int)$item['product_id'],
            'product_name' => (string)$item['product_name'],
            'quantity' => (int)$item['quantity'],
            'delivered_quantity' => array_key_exists('delivered_quantity', $item)
                && $item['delivered_quantity'] !== null
                ? (int)$item['delivered_quantity']
                : null,
        ];
    }

    $orderedUnits = (int)($row['ordered_units'] ?? 0);
    $deliveredUnits = (int)($row['delivered_units'] ?? 0);
    $dateObject = DateTime::createFromFormat('!Y-m-d', $orderDate);

    return [
        'date' => $orderDate,
        'date_label' => $dateObject ? $dateObject->format('l, F j') : $orderDate,
        'daily_order_id' => $orderId,
        'status' => [
            'key' => $publicStatus['key'],
            'label' => $publicStatus['label'],
            'tone' => bakery_portal_cmd_history_status_tone($publicStatus['key']),
        ],
        'lines' => $lines,
        'ordered_units' => $orderedUnits,
        'delivered_units' => $deliveredUnits,
        'variance' => $deliveredUnits - $orderedUnits,
        'delivery_confirmed_at' => $row['delivery_confirmed_at'] ?? null,
        'invoice_number' => bakery_billing_invoice_number($orderId, $orderDate),
        'has_proof' => bakery_portal_cmd_delivery_has_proof($db, $orderId, (int)$customerId, $orderDate),
        'display_amount' => isset($row['delivery_order_total']) && $row['delivery_order_total'] !== null
            ? (float)$row['delivery_order_total']
            : (float)($row['total_amount'] ?? 0),
    ];
}

/**
 * Load line items for a set of daily order ids.
 *
 * @param array<int, int> $orderIds
 * @return array<int, array<int, array<string, mixed>>>
 */
function bakery_portal_cmd_history_items_by_order(PDO $db, array $orderIds) {
    $orderIds = array_values(array_filter(array_map('intval', $orderIds)));
    if (!$orderIds) {
        return [];
    }

    $hasDeliveredQty = column_exists($db, 'daily_order_items', 'delivered_quantity');
    $deliveredSelect = $hasDeliveredQty ? 'doi.delivered_quantity' : 'NULL AS delivered_quantity';
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

    $stmt = $db->prepare(
        "SELECT doi.daily_order_id, doi.product_id, doi.quantity, {$deliveredSelect},
                p.name AS product_name
         FROM daily_order_items doi
         JOIN products p ON p.id = doi.product_id
         WHERE doi.daily_order_id IN ({$placeholders})
         ORDER BY doi.daily_order_id, p.name"
    );
    $stmt->execute($orderIds);

    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $orderId = (int)$item['daily_order_id'];
        $grouped[$orderId][] = $item;
    }
    return $grouped;
}

/**
 * Search delivery history for the authenticated customer.
 *
 * @param array{start_date?:string,end_date?:string,product_id?:int,q?:string,limit?:int,offset?:int} $filters
 * @return array{rows:array,total:int,start_date:string,end_date:string,error?:string}
 */
function bakery_portal_cmd_history_search(PDO $db, $customerId, array $filters = []) {
    $startDate = trim((string)($filters['start_date'] ?? ''));
    $endDate = trim((string)($filters['end_date'] ?? ''));
    $productId = (int)($filters['product_id'] ?? 0);
    $query = trim((string)($filters['q'] ?? ''));
    $limit = max(1, min(100, (int)($filters['limit'] ?? 30)));
    $offset = max(0, (int)($filters['offset'] ?? 0));

    if ($startDate === '') {
        if ($productId > 0) {
            try {
                $minDateStmt = $db->prepare(
                    'SELECT MIN(do.order_date)
                     FROM daily_orders do
                     JOIN daily_order_items doi ON doi.daily_order_id = do.id
                     WHERE do.customer_id = ? AND doi.product_id = ? AND doi.quantity > 0'
                );
                $minDateStmt->execute([(int)$customerId, $productId]);
                $startDate = (string)($minDateStmt->fetchColumn() ?: date('Y-m-d', strtotime('-90 days')));
            } catch (Throwable $e) {
                $startDate = date('Y-m-d', strtotime('-90 days'));
            }
        } else {
            $startDate = date('Y-m-d', strtotime('-90 days'));
        }
    }
    if ($endDate === '') {
        $endDate = date('Y-m-d', strtotime('-1 day'));
    }

    $where = ['do.customer_id = ?', 'do.order_date BETWEEN ? AND ?'];
    $params = [(int)$customerId, $startDate, $endDate];

    if ($productId > 0) {
        $where[] = 'EXISTS (
            SELECT 1 FROM daily_order_items doi
            WHERE doi.daily_order_id = do.id AND doi.product_id = ?
        )';
        $params[] = $productId;
    }

    if ($query !== '') {
        $where[] = 'EXISTS (
            SELECT 1 FROM daily_order_items doi
            JOIN products p ON p.id = doi.product_id
            WHERE doi.daily_order_id = do.id AND p.name LIKE ?
        )';
        $params[] = '%' . $query . '%';
    }

    $whereSql = implode(' AND ', $where);
    $extraCols = '';
    if (column_exists($db, 'daily_orders', 'delivery_confirmed_at')) {
        $extraCols .= ', do.delivery_confirmed_at';
    }
    if (column_exists($db, 'daily_orders', 'delivery_order_total')) {
        $extraCols .= ', do.delivery_order_total';
    }

    $deliveredUnitsSql = column_exists($db, 'daily_order_items', 'delivered_quantity')
        ? 'COALESCE(SUM(COALESCE(doi.delivered_quantity, doi.quantity)), 0)'
        : 'COALESCE(SUM(doi.quantity), 0)';

    try {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM daily_orders do WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT do.id, do.customer_id, do.order_date, do.status, do.total_amount{$extraCols},
                   doa.delivery_status AS assignment_delivery_status,
                   (
                       SELECT COALESCE(SUM(doi.quantity), 0)
                       FROM daily_order_items doi WHERE doi.daily_order_id = do.id
                   ) AS ordered_units,
                   (
                       SELECT {$deliveredUnitsSql}
                       FROM daily_order_items doi WHERE doi.daily_order_id = do.id
                   ) AS delivered_units
            FROM daily_orders do
            LEFT JOIN daily_order_assignments doa ON doa.daily_order_id = do.id
            WHERE {$whereSql}
            ORDER BY do.order_date DESC, do.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $orderRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('portal_cmd_history_search: ' . $e->getMessage());
        return [
            'rows' => [],
            'total' => 0,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'error' => bakery_t('portal.history_load_error'),
        ];
    }

    $itemsByOrder = bakery_portal_cmd_history_items_by_order(
        $db,
        array_column($orderRows, 'id')
    );

    $rows = [];
    foreach ($orderRows as $row) {
        try {
            $rows[] = bakery_portal_cmd_history_row_card($db, $customerId, $row, $itemsByOrder);
        } catch (Throwable $e) {
            error_log('portal_cmd_history_search row: ' . $e->getMessage());
        }
    }

    return [
        'rows' => $rows,
        'total' => $total,
        'start_date' => $startDate,
        'end_date' => $endDate,
    ];
}

/**
 * Whether proof-of-delivery photos exist for an order.
 */
function bakery_portal_cmd_delivery_has_proof(PDO $db, $dailyOrderId, $customerId = null, $orderDate = null) {
    if (!function_exists('table_exists') || !table_exists($db, 'driver_photos')) {
        return false;
    }
    if (function_exists('bakery_delivery_has_photo')) {
        try {
            return bakery_delivery_has_photo($db, (int)$dailyOrderId);
        } catch (Throwable $e) {
            error_log('portal_cmd_delivery_has_proof: ' . $e->getMessage());
        }
    }

    if ($customerId === null || $orderDate === null) {
        $stmt = $db->prepare(
            'SELECT customer_id, order_date FROM daily_orders WHERE id = ? LIMIT 1'
        );
        $stmt->execute([(int)$dailyOrderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $customerId = (int)$row['customer_id'];
        $orderDate = (string)$row['order_date'];
    }

    $stmt = $db->prepare(
        'SELECT 1 FROM driver_photos
         WHERE customer_id = ? AND delivery_date = ?
         LIMIT 1'
    );
    $stmt->execute([(int)$customerId, $orderDate]);
    return (bool)$stmt->fetchColumn();
}

/**
 * List delivery proof photos scoped to customer ownership.
 *
 * @return array<int, array>
 */
function bakery_portal_cmd_delivery_photos(PDO $db, $customerId, $dailyOrderId) {
    $order = bakery_portal_cmd_load_owned_daily_order($db, $customerId, $dailyOrderId);
    if (!$order || !table_exists($db, 'driver_photos')) {
        return [];
    }

    return bakery_customer_delivery_photos(
        $db,
        (int)$customerId,
        (int)$dailyOrderId,
        (string)$order['order_date']
    );
}

/**
 * Save one dated delivery line for the authenticated customer.
 */
function bakery_portal_cmd_save_daily_item(PDO $db, array $customer, $date, $productId, $quantity) {
    bakery_customer_save_daily_line($db, $customer, $date, $productId, $quantity);
    return bakery_portal_cmd_assert_delivery_date($db, (int)$customer['id'], $date);
}

/**
 * Use a previous delivery as the starting point for a future dated order.
 *
 * @return array Updated target delivery context
 */
function bakery_portal_cmd_apply_reorder(PDO $db, array $customer, $sourceOrderId, $targetDate) {
    $customerId = (int)$customer['id'];
    $source = bakery_portal_cmd_load_owned_daily_order($db, $customerId, $sourceOrderId);
    if (!$source) {
        throw new RuntimeException('Source delivery not found');
    }

    $targetContext = bakery_portal_cmd_assert_delivery_date($db, $customerId, $targetDate);
    if (!bakery_portal_cmd_delivery_can_edit($targetDate, $targetContext)) {
        throw new RuntimeException('Changes are no longer allowed for the target delivery');
    }

    $itemsStmt = $db->prepare(
        'SELECT product_id, quantity FROM daily_order_items
         WHERE daily_order_id = ? AND quantity > 0'
    );
    $itemsStmt->execute([(int)$sourceOrderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) {
        throw new RuntimeException('The selected delivery has no items to reuse');
    }

    $orderId = bakery_customer_ensure_daily_order($db, $customer, $targetDate);

    $productIds = array_map('intval', array_column($items, 'product_id'));
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $validStmt = $db->prepare("SELECT id FROM products WHERE id IN ($placeholders)");
    $validStmt->execute($productIds);
    $validIds = array_map('intval', $validStmt->fetchAll(PDO::FETCH_COLUMN));

    foreach ($items as $item) {
        $productId = (int)$item['product_id'];
        if (!in_array($productId, $validIds, true)) {
            continue;
        }
        bakery_customer_save_daily_line(
            $db,
            $customer,
            $targetDate,
            $productId,
            (int)$item['quantity']
        );
    }

    return bakery_portal_cmd_assert_delivery_date($db, $customerId, $targetDate);
}

/**
 * Products the customer has ordered before (for history filter).
 *
 * @return array<int, array{id:int,name:string}>
 */
function bakery_portal_cmd_history_product_options(PDO $db, $customerId) {
    $stmt = $db->prepare(
        'SELECT DISTINCT p.id, p.name
         FROM daily_order_items doi
         JOIN daily_orders do ON do.id = doi.daily_order_id
         JOIN products p ON p.id = doi.product_id
         WHERE do.customer_id = ?
         ORDER BY p.name'
    );
    $stmt->execute([(int)$customerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
