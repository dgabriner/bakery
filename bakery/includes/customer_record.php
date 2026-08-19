<?php
/**
 * Unified operational customer record — read-only aggregation helpers.
 *
 * Composes standing orders, dated daily orders, routes, and recent history
 * without introducing a new storage model.
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/demand_review.php';
require_once __DIR__ . '/customer_portal.php';

/**
 * Canonical weekday labels (1=Mon … 7=Sun).
 *
 * @return array<int, string>
 */
function bakery_customer_record_day_labels() {
    return [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];
}

/**
 * Short weekday labels for compact grids.
 *
 * @return array<int, string>
 */
function bakery_customer_record_day_short_labels() {
    return [
        1 => 'Mon',
        2 => 'Tue',
        3 => 'Wed',
        4 => 'Thu',
        5 => 'Fri',
        6 => 'Sat',
        7 => 'Sun',
    ];
}

/**
 * Build URL preserving customer and date context.
 */
function bakery_customer_record_url($customerId, $date = null) {
    $params = ['customer_id' => (int)$customerId];
    if ($date !== null && $date !== '') {
        $params['date'] = $date;
    }
    return 'customer_record.php?' . http_build_query($params);
}

/**
 * Escape a customer-name link to the operational hub.
 *
 * Use on high-frequency staff surfaces. Keep attributes minimal so nested
 * interactive UIs (drag handles, checkboxes) stay usable.
 *
 * @param int         $customerId
 * @param string      $name
 * @param string|null $date        Optional operating date context
 * @param string      $class       Extra CSS classes
 * @return string Safe HTML anchor
 */
function bakery_customer_record_link_html($customerId, $name, $date = null, $class = '') {
    $customerId = (int)$customerId;
    $label = htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8');
    if ($customerId <= 0) {
        return $label;
    }
    $href = htmlspecialchars(bakery_customer_record_url($customerId, $date), ENT_QUOTES, 'UTF-8');
    $classAttr = trim('customer-hub-link ' . $class);
    return '<a class="' . htmlspecialchars($classAttr, ENT_QUOTES, 'UTF-8') . '" href="' . $href . '">' . $label . '</a>';
}

/**
 * Load one customer row with resolved zone label.
 *
 * @return array|null
 */
function bakery_customer_record_load_customer(PDO $db, $customerId) {
    $zoneJoin = bakery_customer_zone_join_sql();
    $stmt = $db->prepare("
        SELECT c.*,
               COALESCE(z.name, NULLIF(c.zone, ''), 'No Zone') AS zone_label,
               z.color AS zone_color
        FROM customers c
        {$zoneJoin}
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->execute([(int)$customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Build the full operational snapshot for one customer on one date.
 *
 * @return array
 */
function bakery_customer_record_build(PDO $db, $customerId, $date) {
    $customerId = (int)$customerId;
    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid order date');
    }

    $customer = bakery_customer_record_load_customer($db, $customerId);
    if (!$customer) {
        throw new RuntimeException('Customer not found');
    }

    $dayOfWeek = bakery_standing_day_from_date($date);
    $dayName = $dateObject->format('l');
    $weekStart = bakery_week_start_monday($date);
    $dayLabels = bakery_customer_record_day_labels();

    $standingByDay = bakery_customer_record_standing_schedule($db, $customerId);
    $standingRoutes = bakery_customer_record_standing_routes($db, $customerId);
    foreach ($standingRoutes as $day => $route) {
        if (isset($standingByDay[$day])) {
            $standingByDay[$day]['driver_id'] = $route['driver_id'];
            $standingByDay[$day]['driver_name'] = $route['driver_name'];
            $standingByDay[$day]['route_order'] = $route['route_order'];
        }
    }

    $dateContext = bakery_customer_record_date_context($db, $customerId, $date, $dayOfWeek, $standingRoutes);
    $dateContext['week_start'] = $weekStart;
    $dateContext['paused'] = bakery_customer_week_is_paused($db, $customerId, $weekStart);

    $deliveryDays = [];
    foreach ($standingByDay as $day => $info) {
        if ($info['total_units'] > 0 || !empty($info['driver_name'])) {
            $deliveryDays[] = $dayLabels[$day] ?? (string)$day;
        }
    }

    $recentOrders = bakery_customer_record_recent_orders($db, $customerId, 12);
    $hints = bakery_customer_record_hints(
        $customer,
        $date,
        $dateContext,
        $standingByDay,
        $standingRoutes,
        $recentOrders
    );

    return [
        'customer' => $customer,
        'date' => $date,
        'day_of_week' => $dayOfWeek,
        'day_name' => $dayName,
        'week_start' => $weekStart,
        'zone_label' => $customer['zone_label'] ?? ($customer['zone'] ?: 'No Zone'),
        'standing_by_day' => $standingByDay,
        'standing_routes' => $standingRoutes,
        'delivery_days' => $deliveryDays,
        'date_context' => $dateContext,
        'recent_orders' => $recentOrders,
        'hints' => $hints,
    ];
}

/**
 * Standing order quantities grouped by weekday.
 *
 * @return array<int, array>
 */
function bakery_customer_record_standing_schedule(PDO $db, $customerId) {
    $byDay = [];
    foreach (array_keys(bakery_customer_record_day_labels()) as $day) {
        $byDay[$day] = [
            'items' => [],
            'total_units' => 0,
            'driver_id' => null,
            'driver_name' => null,
            'route_order' => null,
        ];
    }

    $stmt = $db->prepare("
        SELECT so.day_of_week, so.product_id, so.quantity, p.name AS product_name
        FROM standing_orders so
        JOIN products p ON p.id = so.product_id
        WHERE so.customer_id = ? AND so.quantity > 0
        ORDER BY so.day_of_week, p.name
    ");
    $stmt->execute([(int)$customerId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $day = bakery_normalize_standing_day((int)$row['day_of_week']);
        if (!isset($byDay[$day])) {
            continue;
        }
        $qty = (int)$row['quantity'];
        $byDay[$day]['items'][] = [
            'product_id' => (int)$row['product_id'],
            'product_name' => $row['product_name'],
            'quantity' => $qty,
        ];
        $byDay[$day]['total_units'] += $qty;
    }

    return $byDay;
}

/**
 * Recurring route/driver assignments by weekday.
 *
 * @return array<int, array>
 */
function bakery_customer_record_standing_routes(PDO $db, $customerId) {
    $routes = [];
    $stmt = $db->prepare("
        SELECT sr.day_of_week, sr.driver_id, sr.route_order, d.name AS driver_name
        FROM standing_routes sr
        LEFT JOIN drivers d ON d.id = sr.driver_id
        WHERE sr.customer_id = ?
        ORDER BY sr.day_of_week
    ");
    $stmt->execute([(int)$customerId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $day = bakery_normalize_standing_day((int)$row['day_of_week']);
        $routes[$day] = [
            'driver_id' => $row['driver_id'] !== null ? (int)$row['driver_id'] : null,
            'driver_name' => $row['driver_name'] ?: null,
            'route_order' => $row['route_order'] !== null ? (int)$row['route_order'] : null,
        ];
    }
    return $routes;
}

/**
 * Compare standing expectation vs dated daily order for one date.
 *
 * Optional $opts keys for portal batching (skip per-date queries):
 *   standing_by_day — output of bakery_customer_record_standing_schedule()
 *   daily_order     — daily_orders row for this date, or null
 *   daily_items     — line items for daily_order['id']
 *   dated_route     — preloaded assignment row, or null to skip lookup
 *
 * @return array
 */
function bakery_customer_record_date_context(PDO $db, $customerId, $date, $dayOfWeek, array $standingRoutes, array $opts = []) {
    if (isset($opts['standing_by_day']) && is_array($opts['standing_by_day'])) {
        $standingRows = [];
        $dayInfo = $opts['standing_by_day'][$dayOfWeek]['items'] ?? [];
        foreach ($dayInfo as $item) {
            $standingRows[] = [
                'product_id' => (int)$item['product_id'],
                'quantity' => (int)$item['quantity'],
                'product_name' => (string)$item['product_name'],
            ];
        }
    } else {
        $dayClause = bakery_standing_day_in_clause($dayOfWeek);

        $standingStmt = $db->prepare("
            SELECT so.product_id, so.quantity, p.name AS product_name
            FROM standing_orders so
            JOIN products p ON p.id = so.product_id
            WHERE so.customer_id = ?
              AND so.day_of_week {$dayClause['sql']}
              AND so.quantity > 0
            ORDER BY p.name
        ");
        $standingParams = array_merge([(int)$customerId], $dayClause['values']);
        $standingStmt->execute($standingParams);
        $standingRows = $standingStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (array_key_exists('daily_order', $opts)) {
        $dailyRows = [];
        $dailyOrder = $opts['daily_order'];
        if (is_array($dailyOrder)) {
            $items = $opts['daily_items'] ?? [];
            if ($items === []) {
                $dailyRows[] = [
                    'daily_order_id' => (int)$dailyOrder['id'],
                    'status' => $dailyOrder['status'] ?? null,
                    'total_amount' => $dailyOrder['total_amount'] ?? 0,
                    'notes' => $dailyOrder['notes'] ?? null,
                    'delivery_confirmed_at' => $dailyOrder['delivery_confirmed_at'] ?? null,
                    'delivery_order_total' => $dailyOrder['delivery_order_total'] ?? null,
                    'delivered_pieces' => $dailyOrder['delivered_pieces'] ?? null,
                    'credits_taken_back' => $dailyOrder['credits_taken_back'] ?? null,
                    'item_id' => null,
                    'product_id' => null,
                    'quantity' => null,
                    'delivered_quantity' => null,
                    'unit_price' => null,
                    'product_name' => null,
                ];
            } else {
                foreach ($items as $item) {
                    $dailyRows[] = [
                        'daily_order_id' => (int)$dailyOrder['id'],
                        'status' => $dailyOrder['status'] ?? null,
                        'total_amount' => $dailyOrder['total_amount'] ?? 0,
                        'notes' => $dailyOrder['notes'] ?? null,
                        'delivery_confirmed_at' => $dailyOrder['delivery_confirmed_at'] ?? null,
                        'delivery_order_total' => $dailyOrder['delivery_order_total'] ?? null,
                        'delivered_pieces' => $dailyOrder['delivered_pieces'] ?? null,
                        'credits_taken_back' => $dailyOrder['credits_taken_back'] ?? null,
                        'item_id' => (int)($item['item_id'] ?? $item['id'] ?? 0),
                        'product_id' => $item['product_id'] ?? null,
                        'quantity' => $item['quantity'] ?? null,
                        'delivered_quantity' => $item['delivered_quantity'] ?? null,
                        'unit_price' => $item['unit_price'] ?? null,
                        'product_name' => $item['product_name'] ?? null,
                    ];
                }
            }
        }
    } else {
        $dailyStmt = $db->prepare("
            SELECT do.id AS daily_order_id, do.status, do.total_amount, do.notes,
                   do.delivery_confirmed_at, do.delivery_order_total,
                   do.delivered_pieces, do.credits_taken_back,
                   doi.id AS item_id, doi.product_id, doi.quantity,
                   doi.delivered_quantity, doi.unit_price,
                   p.name AS product_name
            FROM daily_orders do
            LEFT JOIN daily_order_items doi ON doi.daily_order_id = do.id
            LEFT JOIN products p ON p.id = doi.product_id
            WHERE do.customer_id = ? AND do.order_date = ?
            ORDER BY p.name
        ");
        $dailyStmt->execute([(int)$customerId, $date]);
        $dailyRows = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $dailyOrderId = null;
    $status = null;
    $totalAmount = 0.0;
    $orderNotes = null;
    $deliveryConfirmedAt = null;
    $deliveryOrderTotal = null;
    $lineMap = [];

    foreach ($standingRows as $row) {
        $pid = (int)$row['product_id'];
        $lineMap[$pid] = [
            'product_id' => $pid,
            'product_name' => $row['product_name'],
            'standing_qty' => (int)$row['quantity'],
            'daily_qty' => null,
            'delivered_quantity' => null,
            'item_id' => null,
            'unit_price' => null,
        ];
    }

    foreach ($dailyRows as $row) {
        if ($dailyOrderId === null) {
            $dailyOrderId = (int)$row['daily_order_id'];
            $status = $row['status'];
            $totalAmount = (float)$row['total_amount'];
            $orderNotes = $row['notes'];
            $deliveryConfirmedAt = $row['delivery_confirmed_at'];
            $deliveryOrderTotal = $row['delivery_order_total'] !== null
                ? (float)$row['delivery_order_total']
                : null;
        }
        if ($row['product_id'] === null) {
            continue;
        }
        $pid = (int)$row['product_id'];
        if (!isset($lineMap[$pid])) {
            $lineMap[$pid] = [
                'product_id' => $pid,
                'product_name' => $row['product_name'],
                'standing_qty' => null,
                'daily_qty' => null,
                'delivered_quantity' => null,
                'item_id' => null,
                'unit_price' => null,
            ];
        }
        $lineMap[$pid]['daily_qty'] = (int)$row['quantity'];
        $lineMap[$pid]['delivered_quantity'] = $row['delivered_quantity'];
        $lineMap[$pid]['item_id'] = (int)$row['item_id'];
        $lineMap[$pid]['unit_price'] = $row['unit_price'];
        $lineMap[$pid]['product_name'] = $row['product_name'];
    }

    $standingRoute = $standingRoutes[$dayOfWeek] ?? null;
    if (array_key_exists('dated_route', $opts)) {
        $datedRoute = $opts['dated_route'];
    } else {
        $datedRoute = bakery_customer_record_dated_route($db, $dailyOrderId, $date);
    }

    $reviewCustomer = [
        'has_standing' => count($standingRows) > 0,
        'has_daily' => $dailyOrderId !== null,
        'paused' => false,
        'line_map' => $lineMap,
        'status' => $status,
        'assignment_status' => $datedRoute['assignment_status'] ?? null,
    ];
    $state = bakery_demand_review_classify_customer($reviewCustomer);
    $diffLines = bakery_demand_review_diff_lines($lineMap);

    $standingUnits = 0;
    $dailyUnits = 0;
    foreach ($lineMap as $line) {
        $standingUnits += (int)($line['standing_qty'] ?? 0);
        $dailyUnits += (int)($line['daily_qty'] ?? 0);
    }

    return [
        'daily_order_id' => $dailyOrderId,
        'status' => $status,
        'total_amount' => $totalAmount,
        'delivery_order_total' => $deliveryOrderTotal,
        'delivery_confirmed_at' => $deliveryConfirmedAt,
        'order_notes' => $orderNotes,
        'standing_lines' => array_values(array_filter($lineMap, function ($line) {
            return $line['standing_qty'] !== null && (int)$line['standing_qty'] > 0;
        })),
        'daily_lines' => array_values(array_filter($lineMap, function ($line) {
            return $line['daily_qty'] !== null;
        })),
        'line_map' => array_values($lineMap),
        'diff_lines' => $diffLines,
        'state' => $state,
        'standing_units' => $standingUnits,
        'daily_units' => $dailyUnits,
        'standing_route' => $standingRoute,
        'dated_route' => $datedRoute,
    ];
}

/**
 * Dated driver/route assignment for one daily order.
 *
 * @return array|null
 */
function bakery_customer_record_dated_route(PDO $db, $dailyOrderId, $date) {
    if (!$dailyOrderId) {
        return null;
    }

    $assignmentHasNotes = column_exists($db, 'daily_order_assignments', 'notes');
    $assignmentNotesSelect = $assignmentHasNotes ? 'doa.notes,' : 'NULL AS notes,';

    $stmt = $db->prepare("
        SELECT doa.driver_id, doa.route_order, doa.delivery_status,
               doa.scheduled_delivery_time, doa.actual_delivery_time, {$assignmentNotesSelect}
               d.name AS driver_name
        FROM daily_order_assignments doa
        LEFT JOIN drivers d ON d.id = doa.driver_id
        WHERE doa.daily_order_id = ? AND doa.delivery_date = ?
        ORDER BY doa.route_order, doa.id
        LIMIT 1
    ");
    $stmt->execute([(int)$dailyOrderId, $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $legacy = $db->prepare("
            SELECT do.driver_id, d.name AS driver_name
            FROM daily_orders do
            LEFT JOIN drivers d ON d.id = do.driver_id
            WHERE do.id = ?
            LIMIT 1
        ");
        $legacy->execute([(int)$dailyOrderId]);
        $legacyRow = $legacy->fetch(PDO::FETCH_ASSOC);
        if (!$legacyRow || !$legacyRow['driver_id']) {
            return null;
        }
        return [
            'driver_id' => (int)$legacyRow['driver_id'],
            'driver_name' => $legacyRow['driver_name'],
            'route_order' => null,
            'assignment_status' => null,
            'scheduled_delivery_time' => null,
            'actual_delivery_time' => null,
            'notes' => null,
            'source' => 'legacy_daily_order',
        ];
    }

    return [
        'driver_id' => $row['driver_id'] !== null ? (int)$row['driver_id'] : null,
        'driver_name' => $row['driver_name'] ?: null,
        'route_order' => $row['route_order'] !== null ? (int)$row['route_order'] : null,
        'assignment_status' => $row['delivery_status'],
        'scheduled_delivery_time' => $row['scheduled_delivery_time'],
        'actual_delivery_time' => $row['actual_delivery_time'],
        'notes' => $row['notes'],
        'source' => 'daily_assignment',
    ];
}

/**
 * Recent dated orders with ordered vs delivered totals.
 *
 * @return array<int, array>
 */
function bakery_customer_record_recent_orders(PDO $db, $customerId, $limit = 12) {
    $stmt = $db->prepare("
        SELECT do.id, do.order_date, do.status, do.total_amount,
               do.delivery_confirmed_at, do.delivery_order_total,
               do.delivered_pieces, do.credits_taken_back,
               (
                   SELECT COALESCE(SUM(doi.quantity), 0)
                   FROM daily_order_items doi
                   WHERE doi.daily_order_id = do.id
               ) AS ordered_units,
               (
                   SELECT COALESCE(SUM(COALESCE(doi.delivered_quantity, doi.quantity)), 0)
                   FROM daily_order_items doi
                   WHERE doi.daily_order_id = do.id
               ) AS delivered_units
        FROM daily_orders do
        WHERE do.customer_id = ?
        ORDER BY do.order_date DESC, do.id DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, (int)$customerId, PDO::PARAM_INT);
    $stmt->bindValue(2, (int)$limit, PDO::PARAM_INT);
    $stmt->execute();

    $orders = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ordered = (int)$row['ordered_units'];
        $delivered = (int)$row['delivered_units'];
        $orders[] = [
            'id' => (int)$row['id'],
            'order_date' => $row['order_date'],
            'status' => $row['status'],
            'total_amount' => (float)$row['total_amount'],
            'delivery_order_total' => $row['delivery_order_total'] !== null
                ? (float)$row['delivery_order_total']
                : null,
            'delivery_confirmed_at' => $row['delivery_confirmed_at'],
            'delivered_pieces' => $row['delivered_pieces'] !== null ? (int)$row['delivered_pieces'] : null,
            'credits_taken_back' => $row['credits_taken_back'] !== null ? (int)$row['credits_taken_back'] : null,
            'ordered_units' => $ordered,
            'delivered_units' => $delivered,
            'variance' => $delivered - $ordered,
            'invoice_number' => 'INV-' . date('Ymd', strtotime($row['order_date']))
                . '-' . str_pad((string)$row['id'], 5, '0', STR_PAD_LEFT),
            'display_amount' => $row['delivery_order_total'] !== null
                ? (float)$row['delivery_order_total']
                : (float)$row['total_amount'],
        ];
    }

    return $orders;
}

/**
 * Human-readable state label for demand comparison.
 */
function bakery_customer_record_state_label($state) {
    $labels = [
        'matches' => 'Matches standing',
        'changed' => 'Differs from standing',
        'missing_daily' => 'No daily order yet',
        'one_off' => 'One-off daily order',
        'empty_daily' => 'Daily order empty',
        'paused' => 'Standing paused this week',
    ];
    return $labels[$state] ?? ucfirst(str_replace('_', ' ', (string)$state));
}

/**
 * CSS class suffix for demand state badges.
 */
function bakery_customer_record_state_class($state) {
    $classes = [
        'matches' => 'state-ok',
        'changed' => 'state-warn',
        'missing_daily' => 'state-alert',
        'one_off' => 'state-info',
        'empty_daily' => 'state-warn',
        'paused' => 'state-muted',
    ];
    return $classes[$state] ?? 'state-muted';
}

/**
 * Detect safe operational inconsistency hints.
 *
 * @return array<int, array{level:string, message:string}>
 */
function bakery_customer_record_hints(
    array $customer,
    $date,
    array $dateContext,
    array $standingByDay,
    array $standingRoutes,
    array $recentOrders
) {
    $hints = [];
    $zoneText = trim((string)($customer['zone'] ?? ''));
    $zoneId = isset($customer['zone_id']) ? (int)$customer['zone_id'] : 0;
    if ($zoneText === '' && $zoneId <= 0) {
        $hints[] = [
            'level' => 'warn',
            'message' => 'No delivery zone assigned — routing and daily order filters may be incomplete.',
        ];
    }

    $address = trim((string)($customer['address'] ?? ''));
    $phone = trim((string)($customer['phone'] ?? ''));
    if ($address === '' || $phone === '') {
        $missing = [];
        if ($address === '') {
            $missing[] = 'address';
        }
        if ($phone === '') {
            $missing[] = 'phone';
        }
        $hints[] = [
            'level' => 'warn',
            'message' => 'Missing driver contact info: ' . implode(' and ', $missing) . '.',
        ];
    }

    if (!(bool)($customer['is_active'] ?? 1)) {
        $reason = trim((string)($customer['inactive_reason'] ?? ''));
        $hints[] = [
            'level' => 'info',
            'message' => 'Customer is inactive'
                . ($reason !== '' ? ' (' . $reason . ')' : '')
                . '. Standing data is shown for reference.',
        ];
    }

    if (!empty($dateContext['paused'])) {
        $hints[] = [
            'level' => 'info',
            'message' => 'Standing orders are paused for the week of '
                . date('M j', strtotime($dateContext['week_start'] ?? $date)) . '.',
        ];
    }

    $dayOfWeek = bakery_standing_day_from_date($date);
    $hasStandingToday = ($standingByDay[$dayOfWeek]['total_units'] ?? 0) > 0;
    $isFutureOrToday = $date >= date('Y-m-d');

    if ($hasStandingToday && empty($dateContext['daily_order_id']) && $isFutureOrToday && empty($dateContext['paused'])) {
        $hints[] = [
            'level' => 'alert',
            'message' => 'Standing demand exists for this weekday but no daily order is scheduled for '
                . date('M j, Y', strtotime($date)) . '.',
        ];
    }

    if (!empty($dateContext['daily_order_id']) && $isFutureOrToday) {
        $standingRoute = $dateContext['standing_route'] ?? null;
        $datedRoute = $dateContext['dated_route'] ?? null;
        if ($standingRoute && !empty($standingRoute['driver_id']) && (!$datedRoute || empty($datedRoute['driver_id']))) {
            $hints[] = [
                'level' => 'warn',
                'message' => 'Daily order exists but no driver is assigned for '
                    . date('M j, Y', strtotime($date))
                    . ' (recurring route expects '
                    . ($standingRoute['driver_name'] ?: 'a driver')
                    . ').',
            ];
        }
    }

    if (($dateContext['state'] ?? '') === 'changed' && !empty($dateContext['diff_lines'])) {
        $hints[] = [
            'level' => 'warn',
            'message' => 'Selected date quantities differ from the normal standing schedule.',
        ];
    }

    $varianceCount = 0;
    foreach ($recentOrders as $order) {
        if ($order['delivery_confirmed_at'] && $order['variance'] !== 0) {
            $varianceCount++;
        }
    }
    if ($varianceCount >= 2) {
        $hints[] = [
            'level' => 'warn',
            'message' => "Delivered quantities differed from ordered on {$varianceCount} of the most recent confirmed deliveries.",
        ];
    }

    $deliveryDays = [];
    foreach ($standingByDay as $day => $info) {
        if ($info['total_units'] > 0) {
            $deliveryDays[] = $day;
        }
    }
    foreach ($deliveryDays as $day) {
        if (empty($standingRoutes[$day]['driver_id'])) {
            $labels = bakery_customer_record_day_short_labels();
            $hints[] = [
                'level' => 'warn',
                'message' => 'Standing orders on '
                    . ($labels[$day] ?? 'weekday')
                    . ' but no recurring route/driver assigned.',
            ];
            break;
        }
    }

    return $hints;
}
