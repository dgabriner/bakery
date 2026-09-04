<?php
/**
 * Customer order mutation helpers — canonical owner of portal order-change semantics.
 *
 * standing_orders = recurring future pattern (My Regular Order)
 * daily_orders    = one dated commitment (This Delivery)
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * Single write path for standing_orders rows. Quantity ≤ 0 deletes the row.
 * Normalizes Sunday (0 → 7) and clears a legacy day-0 duplicate when writing day 7.
 */
function bakery_standing_order_upsert(PDO $db, int $customerId, int $productId, int $dayOfWeek, int $qty): void
{
    $customerId = (int)$customerId;
    $productId = (int)$productId;
    $dayOfWeek = function_exists('bakery_normalize_standing_day')
        ? bakery_normalize_standing_day($dayOfWeek)
        : (((int)$dayOfWeek === 0) ? 7 : (int)$dayOfWeek);
    $qty = (int)$qty;

    if ($qty > 0) {
        $stmt = $db->prepare(
            'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity = ?'
        );
        $stmt->execute([$customerId, $productId, $dayOfWeek, $qty, $qty]);
        if ($dayOfWeek === 7) {
            $db->prepare(
                'DELETE FROM standing_orders WHERE customer_id = ? AND product_id = ? AND day_of_week = 0'
            )->execute([$customerId, $productId]);
        }
        return;
    }

    if (function_exists('bakery_standing_day_in_clause')) {
        $clause = bakery_standing_day_in_clause($dayOfWeek);
        $stmt = $db->prepare(
            'DELETE FROM standing_orders WHERE customer_id = ? AND product_id = ? AND day_of_week ' . $clause['sql']
        );
        $stmt->execute(array_merge([$customerId, $productId], $clause['values']));
        return;
    }

    $stmt = $db->prepare(
        'DELETE FROM standing_orders WHERE customer_id = ? AND product_id = ? AND day_of_week = ?'
    );
    $stmt->execute([$customerId, $productId, $dayOfWeek]);
}

/**
 * Find a dated daily_orders row or create an empty pending shell.
 *
 * @param array{status?:string,created?:bool} $opts Pass created by reference via $opts['created'] after call is not supported;
 *        set $opts['track_created']=true and read $opts['was_created'] afterward.
 */
function bakery_daily_order_find_or_create(PDO $db, int $customerId, string $date, array $opts = []): int
{
    $customerId = (int)$customerId;
    $status = (string)($opts['status'] ?? 'pending');
    if ($status === '') {
        $status = 'pending';
    }

    $find = $db->prepare(
        'SELECT id FROM daily_orders WHERE customer_id = ? AND order_date = ? LIMIT 1'
    );
    $find->execute([$customerId, $date]);
    $existingId = (int)$find->fetchColumn();
    if ($existingId > 0) {
        if (!empty($opts['track_created'])) {
            // Caller may inspect via a second select; keep signature stable.
        }
        return $existingId;
    }

    $insert = $db->prepare(
        'INSERT IGNORE INTO daily_orders (customer_id, order_date, status, total_amount)
         VALUES (?, ?, ?, 0)'
    );
    $insert->execute([$customerId, $date, $status]);

    $find->execute([$customerId, $date]);
    $orderId = (int)$find->fetchColumn();
    if ($orderId <= 0) {
        throw new RuntimeException('Could not create daily order');
    }

    return $orderId;
}

/**
 * Recompute daily_orders.total_amount from line items; returns the new total.
 */
function bakery_daily_order_recompute_total(PDO $db, int $orderId): float
{
    $orderId = (int)$orderId;
    $stmt = $db->prepare(
        'UPDATE daily_orders
         SET total_amount = (
             SELECT COALESCE(SUM(line_total), 0) FROM daily_order_items WHERE daily_order_id = ?
         )
         WHERE id = ?'
    );
    $stmt->execute([$orderId, $orderId]);

    $get = $db->prepare('SELECT COALESCE(total_amount, 0) FROM daily_orders WHERE id = ? LIMIT 1');
    $get->execute([$orderId]);

    return (float)$get->fetchColumn();
}

require_once __DIR__ . '/customer_portal.php';
require_once __DIR__ . '/demand_review.php';
require_once __DIR__ . '/operational_timeline.php';
require_once __DIR__ . '/customer_notifications.php';

/** Editable daily order statuses (before production commitment). */
function bakery_customer_editable_order_statuses() {
    return ['pending', 'confirmed'];
}

/** Ensure power-tools schema exists (idempotent). */
function bakery_customer_order_ensure_schema(PDO $db) {
    static $done = false;
    if ($done) {
        return;
    }
    if (!function_exists('bakery_runtime_schema_ddl_allowed') || !bakery_runtime_schema_ddl_allowed()) {
        $done = true;
        return;
    }
    bakery_ensure_portal_schema($db);

    if (!table_exists($db, 'customer_delivery_skips')) {
        $path = dirname(__DIR__) . '/database/schema/024_customer_order_power_tools.sql';
        if (is_readable($path)) {
            $sql = file_get_contents($path);
            if ($sql !== false) {
                foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $statement) {
                    if ($statement !== '') {
                        try {
                            $db->exec($statement);
                        } catch (Throwable $e) {
                            // Idempotent — ignore duplicate table errors.
                        }
                    }
                }
            }
        }
    }
    $done = true;
}

function bakery_standing_day_full_labels() {
    if (function_exists('bakery_standing_day_full_labels_localized')) {
        return bakery_standing_day_full_labels_localized();
    }
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

function bakery_portal_actor_options() {
    return [
        'actor_user_id' => null,
        'actor_role' => 'customer_portal',
    ];
}

function bakery_portal_record_event(PDO $db, $eventType, $summary, $customerId, array $options = []) {
    bakery_customer_order_ensure_schema($db);
    return bakery_record_operational_event($db, $eventType, $summary, array_merge(
        bakery_portal_actor_options(),
        ['customer_id' => (int)$customerId],
        $options
    ));
}

function bakery_customer_delivery_is_skipped(PDO $db, $customerId, $date) {
    bakery_customer_order_ensure_schema($db);
    if (!table_exists($db, 'customer_delivery_skips')) {
        return false;
    }
    $stmt = $db->prepare(
        'SELECT 1 FROM customer_delivery_skips WHERE customer_id = ? AND skip_date = ? LIMIT 1'
    );
    $stmt->execute([(int)$customerId, $date]);
    return (bool)$stmt->fetchColumn();
}

function bakery_customer_delivery_in_pause_range(PDO $db, $customerId, $date) {
    bakery_customer_order_ensure_schema($db);
    if (!table_exists($db, 'customer_delivery_pauses')) {
        return false;
    }
    $stmt = $db->prepare(
        'SELECT 1 FROM customer_delivery_pauses
         WHERE customer_id = ? AND pause_start <= ? AND pause_end >= ?
         LIMIT 1'
    );
    $stmt->execute([(int)$customerId, $date, $date]);
    return (bool)$stmt->fetchColumn();
}

/** True when deliveries should not occur (week pause, date-range pause, or explicit skip). */
function bakery_customer_delivery_is_paused(PDO $db, $customerId, $date) {
    $weekStart = bakery_week_start_monday($date);
    if (bakery_customer_week_is_paused($db, $customerId, $weekStart)) {
        return true;
    }
    if (bakery_customer_delivery_in_pause_range($db, $customerId, $date)) {
        return true;
    }
    if (bakery_customer_delivery_is_skipped($db, $customerId, $date)) {
        return true;
    }
    return false;
}

function bakery_customer_standing_weekdays(PDO $db, $customerId) {
    $stmt = $db->prepare(
        'SELECT DISTINCT CASE WHEN day_of_week = 0 THEN 7 ELSE day_of_week END AS day_of_week
         FROM standing_orders
         WHERE customer_id = ? AND quantity > 0
         ORDER BY day_of_week'
    );
    $stmt->execute([(int)$customerId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function bakery_customer_standing_lines(PDO $db, $customerId, $dayOfWeek = null) {
    $sql = 'SELECT so.product_id,
                   CASE WHEN so.day_of_week = 0 THEN 7 ELSE so.day_of_week END AS day_of_week,
                   so.quantity, p.name AS product_name
            FROM standing_orders so
            JOIN products p ON p.id = so.product_id
            WHERE so.customer_id = ?';
    $params = [(int)$customerId];
    if ($dayOfWeek !== null) {
        $clause = bakery_standing_day_in_clause((int)$dayOfWeek);
        $sql .= ' AND so.day_of_week ' . $clause['sql'];
        $params = array_merge($params, $clause['values']);
    }
    $sql .= ' ORDER BY day_of_week, p.name';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function bakery_customer_standing_qty(PDO $db, $customerId, $productId, $dayOfWeek) {
    $clause = bakery_standing_day_in_clause((int)$dayOfWeek);
    $stmt = $db->prepare(
        'SELECT quantity FROM standing_orders
         WHERE customer_id = ? AND product_id = ? AND day_of_week ' . $clause['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([(int)$customerId, (int)$productId], $clause['values']));
    $val = $stmt->fetchColumn();
    return $val === false ? 0 : (int)$val;
}

function bakery_customer_daily_order_row(PDO $db, $customerId, $date) {
    $stmt = $db->prepare(
        'SELECT do.id, do.customer_id, do.order_date, do.status, do.total_amount, do.notes,
                (
                    SELECT doa.delivery_status
                    FROM daily_order_assignments doa
                    WHERE doa.daily_order_id = do.id AND doa.delivery_date = do.order_date
                    ORDER BY doa.id LIMIT 1
                ) AS assignment_status
         FROM daily_orders do
         WHERE do.customer_id = ? AND do.order_date = ?
         LIMIT 1'
    );
    $stmt->execute([(int)$customerId, $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bakery_customer_daily_items(PDO $db, $dailyOrderId) {
    $stmt = $db->prepare(
        'SELECT doi.id AS item_id, doi.product_id, doi.quantity, doi.unit_price, doi.line_total,
                p.name AS product_name
         FROM daily_order_items doi
         JOIN products p ON p.id = doi.product_id
         WHERE doi.daily_order_id = ?
         ORDER BY p.name'
    );
    $stmt->execute([(int)$dailyOrderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function bakery_customer_order_is_locked($orderStatus, $assignmentStatus = null) {
    return bakery_demand_review_is_advanced_status($orderStatus, $assignmentStatus);
}

/**
 * @return array{state:string,editable:bool,locked:bool,skipped:bool,paused:bool,daily_order_id:?int,status:?string}
 */
function bakery_customer_delivery_state(PDO $db, $customerId, $date) {
    bakery_customer_order_ensure_schema($db);

    $skipped = bakery_customer_delivery_is_skipped($db, $customerId, $date);
    $weekPaused = bakery_customer_week_is_paused($db, $customerId, bakery_week_start_monday($date));
    $rangePaused = bakery_customer_delivery_in_pause_range($db, $customerId, $date);
    $paused = $weekPaused || $rangePaused;

    $daily = bakery_customer_daily_order_row($db, $customerId, $date);
    $locked = false;
    if ($daily) {
        $locked = bakery_customer_order_is_locked($daily['status'], $daily['assignment_status'] ?? null);
    }

    if ($skipped) {
        $state = 'skipped';
    } elseif ($paused) {
        $state = 'paused';
    } elseif ($daily && $locked) {
        $state = 'locked';
    } elseif ($daily) {
        $state = 'editable';
    } else {
        $state = 'forecast';
    }

    return [
        'state' => $state,
        'editable' => !$locked && !$skipped && !$paused,
        'locked' => $locked,
        'skipped' => $skipped,
        'paused' => $paused,
        'week_paused' => $weekPaused,
        'range_paused' => $rangePaused,
        'daily_order_id' => $daily ? (int)$daily['id'] : null,
        'status' => $daily['status'] ?? null,
        'assignment_status' => $daily['assignment_status'] ?? null,
    ];
}

function bakery_customer_update_daily_total(PDO $db, $dailyOrderId) {
    bakery_daily_order_recompute_total($db, (int)$dailyOrderId);
}

function bakery_customer_product_row(PDO $db, $productId) {
    $stmt = $db->prepare(
        'SELECT p.id, p.name, p.price, p.wholesale_price, pl.name AS product_line_name
         FROM products p
         LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
         LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
         WHERE p.id = ?
         LIMIT 1'
    );
    $stmt->execute([(int)$productId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function bakery_customer_ensure_daily_order(PDO $db, array $customer, $date) {
    $customerId = (int)$customer['id'];
    $existing = bakery_customer_daily_order_row($db, $customerId, $date);
    if ($existing) {
        return (int)$existing['id'];
    }

    $dailyOrderId = bakery_daily_order_find_or_create($db, $customerId, (string)$date);

    $dayOfWeek = bakery_standing_day_from_date($date);
    $standingLines = bakery_customer_standing_lines($db, $customerId, $dayOfWeek);
    foreach ($standingLines as $line) {
        $qty = (int)$line['quantity'];
        if ($qty <= 0) {
            continue;
        }
        $product = bakery_customer_product_row($db, (int)$line['product_id']);
        if (!$product) {
            continue;
        }
        $unitPrice = bakery_resolve_customer_price($db, $customer, $product);
        $lineTotal = round($qty * $unitPrice, 2);
        $ins = $db->prepare(
            'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
             VALUES (?, ?, ?, ?, ?)'
        );
        $ins->execute([$dailyOrderId, (int)$line['product_id'], $qty, $unitPrice, $lineTotal]);
    }
    bakery_customer_update_daily_total($db, $dailyOrderId);
    return $dailyOrderId;
}

/**
 * True when the customer has a positive standing quantity for that weekday.
 */
function bakery_customer_weekday_has_standing(PDO $db, int $customerId, string $date): bool
{
    $dayOfWeek = bakery_standing_day_from_date($date);
    foreach (bakery_customer_standing_lines($db, $customerId, $dayOfWeek) as $line) {
        if ((int)($line['quantity'] ?? 0) > 0) {
            return true;
        }
    }
    return false;
}

/**
 * If this weekday has no standing demand and the dated order is empty, fill
 * the dated order with the standard 1× Pan Dulce mix. Never writes standing.
 *
 * @param array<string,mixed>|null $customer
 * @return array{filled:bool,source:string,item_count:int}
 */
function bakery_customer_fill_empty_dated_order_from_standard(
    PDO $db,
    int $dailyOrderId,
    string $date,
    ?array $customer = null
): array {
    $result = [
        'filled' => false,
        'source' => 'none',
        'item_count' => 0,
    ];
    if ($dailyOrderId <= 0) {
        return $result;
    }

    $customerId = (int)($customer['id'] ?? 0);
    if ($customerId <= 0) {
        $idStmt = $db->prepare('SELECT customer_id FROM daily_orders WHERE id = ? LIMIT 1');
        $idStmt->execute([$dailyOrderId]);
        $customerId = (int)$idStmt->fetchColumn();
    }
    if ($customerId <= 0) {
        return $result;
    }

    $itemCount = 0;
    foreach (bakery_customer_daily_items($db, $dailyOrderId) as $item) {
        if ((int)($item['quantity'] ?? 0) > 0) {
            $itemCount++;
        }
    }
    if ($itemCount > 0) {
        $result['source'] = 'existing';
        $result['item_count'] = $itemCount;
        return $result;
    }
    if (bakery_customer_weekday_has_standing($db, $customerId, $date)) {
        $result['source'] = 'standing';
        return $result;
    }

    require_once __DIR__ . '/pan_dulce_standards.php';
    try {
        $applied = bakery_apply_pan_dulce_daily_standard($db, $dailyOrderId, 1.0);
        bakery_customer_update_daily_total($db, $dailyOrderId);
        $updated = (int)($applied['updated'] ?? 0);
        $result['filled'] = $updated > 0;
        $result['source'] = $result['filled'] ? 'pan_dulce_1x' : 'none';
        $result['item_count'] = $updated;
    } catch (Throwable $e) {
        $result['source'] = 'none';
    }

    return $result;
}

/**
 * Create the dated order shell a manager will edit for one specific date.
 * Existing dated demand wins; this never replaces or clears an order.
 *
 * @return array{daily_order_id:int,customer_id:int,customer_name:string,created:bool,item_count:int}
 */
function bakery_staff_create_dated_order(PDO $db, int $customerId, string $date): array {
    bakery_require_role(['administrator', 'manager']);

    $dateObj = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid delivery date');
    }
    if ($date < date('Y-m-d')) {
        throw new InvalidArgumentException('Cannot create a dated order in the past');
    }
    if ($customerId <= 0) {
        throw new InvalidArgumentException('Invalid customer');
    }

    $stmt = $db->prepare(
        'SELECT c.* FROM customers c
         WHERE c.id = ? AND c.is_active = 1 '
        . bakery_sfb_ops_origin_clause('c', $db) . '
         LIMIT 1'
    );
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        throw new RuntimeException('Customer not found or inactive');
    }

    $existing = bakery_customer_daily_order_row($db, $customerId, $date);
    $created = !$existing;
    if ($existing) {
        $dailyOrderId = (int)$existing['id'];
    } else {
        $db->beginTransaction();
        try {
            $dailyOrderId = bakery_customer_ensure_daily_order($db, $customer, $date);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    $countStmt = $db->prepare('SELECT COUNT(*) FROM daily_order_items WHERE daily_order_id = ?');
    $countStmt->execute([$dailyOrderId]);
    $itemCount = (int)$countStmt->fetchColumn();

    if ($created) {
        bakery_record_operational_event(
            $db,
            BAKERY_OP_DAILY_ORDER_GENERATED,
            'Created one-time dated order for ' . $customer['name'] . ' on ' . date('M j, Y', strtotime($date)),
            [
                'operational_date' => $date,
                'customer_id' => $customerId,
                'daily_order_id' => $dailyOrderId,
                'metadata' => [
                    'source' => 'staff_one_time_dated_order',
                    'standing_items_copied' => $itemCount,
                ],
            ]
        );
    }

    return [
        'daily_order_id' => $dailyOrderId,
        'customer_id' => $customerId,
        'customer_name' => (string)$customer['name'],
        'created' => $created,
        'item_count' => $itemCount,
    ];
}

/**
 * Delete only an empty dated order shell. Product demand must be removed in
 * Daily Orders first, so a route action can never erase saleable demand.
 *
 * @return array{removed:bool,requires_confirmation:bool,status:string,customer_name:string,message:string}
 */
function bakery_remove_empty_dated_order(
    PDO $db,
    int $dailyOrderId,
    string $date,
    bool $confirmedDelivered = false
): array {
    bakery_require_role(['administrator', 'manager']);

    $dateObj = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid order date');
    }
    if ($dailyOrderId <= 0) {
        throw new InvalidArgumentException('Invalid daily order ID');
    }

    $stmt = $db->prepare(
        'SELECT do.id, do.customer_id, do.status, c.name AS customer_name
         FROM daily_orders do
         JOIN customers c ON c.id = do.customer_id
         WHERE do.id = ? AND do.order_date = ?'
    );
    $stmt->execute([$dailyOrderId, $date]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new RuntimeException('Daily order not found for this date');
    }

    $countStmt = $db->prepare('SELECT COUNT(*) FROM daily_order_items WHERE daily_order_id = ?');
    $countStmt->execute([$dailyOrderId]);
    if ((int)$countStmt->fetchColumn() > 0) {
        throw new RuntimeException(
            bakery_t('daily_orders.error_nonempty_delete')
        );
    }

    $status = (string)$order['status'];
    if (in_array($status, ['delivered', 'invoiced'], true) && !$confirmedDelivered) {
        return [
            'removed' => false,
            'requires_confirmation' => true,
            'status' => $status,
            'customer_name' => (string)$order['customer_name'],
            'message' => '',
        ];
    }

    bakery_ensure_operational_events_schema($db);
    $db->beginTransaction();
    try {
        if (table_exists($db, 'driver_photos')) {
            $photoStmt = $db->prepare('DELETE FROM driver_photos WHERE delivery_date = ? AND customer_id = ?');
            $photoStmt->execute([$date, (int)$order['customer_id']]);
        }
        // Record while the FK target still exists. ON DELETE SET NULL keeps
        // the audit event after the empty order shell is removed.
        bakery_record_operational_event(
            $db,
            BAKERY_OP_DAILY_ORDER_CLEARED,
            'Removed empty dated order for ' . $order['customer_name'] . ' on ' . date('l, F j, Y', strtotime($date)),
            [
                'operational_date' => $date,
                'customer_id' => (int)$order['customer_id'],
                'daily_order_id' => $dailyOrderId,
            ]
        );
        $deleteStmt = $db->prepare('DELETE FROM daily_orders WHERE id = ?');
        $deleteStmt->execute([$dailyOrderId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return [
        'removed' => true,
        'requires_confirmation' => false,
        'status' => $status,
        'customer_name' => (string)$order['customer_name'],
        'message' => 'Removed empty dated order for ' . $order['customer_name'],
    ];
}

/**
 * Build delivery comparison lines: regular vs this delivery.
 *
 * @return array<int,array{product_id:int,product_name:string,regular_qty:int,delivery_qty:int,diff:int,item_id:?int}>
 */
function bakery_customer_delivery_comparison(PDO $db, $customerId, $date, array $customer) {
    $dayOfWeek = bakery_standing_day_from_date($date);
    $standing = bakery_customer_standing_lines($db, $customerId, $dayOfWeek);
    $standingByProduct = [];
    foreach ($standing as $line) {
        $standingByProduct[(int)$line['product_id']] = $line;
    }

    $daily = bakery_customer_daily_order_row($db, $customerId, $date);
    $dailyByProduct = [];
    if ($daily) {
        foreach (bakery_customer_daily_items($db, (int)$daily['id']) as $item) {
            $dailyByProduct[(int)$item['product_id']] = $item;
        }
    }

    $productIds = array_unique(array_merge(array_keys($standingByProduct), array_keys($dailyByProduct)));
    sort($productIds);

    $lines = [];
    foreach ($productIds as $pid) {
        $regularQty = isset($standingByProduct[$pid]) ? (int)$standingByProduct[$pid]['quantity'] : 0;
        $deliveryQty = isset($dailyByProduct[$pid]) ? (int)$dailyByProduct[$pid]['quantity'] : $regularQty;
        if (!$daily && $regularQty > 0) {
            $deliveryQty = $regularQty;
        }
        $name = $standingByProduct[$pid]['product_name']
            ?? ($dailyByProduct[$pid]['product_name'] ?? 'Product #' . $pid);
        $lines[] = [
            'product_id' => $pid,
            'product_name' => $name,
            'regular_qty' => $regularQty,
            'delivery_qty' => $deliveryQty,
            'diff' => $deliveryQty - $regularQty,
            'item_id' => isset($dailyByProduct[$pid]) ? (int)$dailyByProduct[$pid]['item_id'] : null,
        ];
    }
    return $lines;
}

/**
 * Upcoming delivery dates for a customer based on standing weekdays.
 *
 * @return array<int,array{date:string,day_of_week:int,day_label:string,state:array,has_standing:bool}>
 */
function bakery_customer_upcoming_deliveries(PDO $db, $customerId, $weeksAhead = 3) {
    bakery_customer_order_ensure_schema($db);
    $weekdays = bakery_customer_standing_weekdays($db, $customerId);
    if (!$weekdays) {
        return [];
    }

    $fullLabels = bakery_standing_day_full_labels();
    $today = date('Y-m-d');
    $endDate = date('Y-m-d', strtotime('+' . ((int)$weeksAhead * 7) . ' days'));
    $results = [];

    $cursor = strtotime($today);
    $endTs = strtotime($endDate);
    while ($cursor <= $endTs) {
        $date = date('Y-m-d', $cursor);
        $dow = (int)date('N', $cursor);
        if (in_array($dow, $weekdays, true)) {
            if ($date >= $today) {
                $state = bakery_customer_delivery_state($db, $customerId, $date);
                $results[] = [
                    'date' => $date,
                    'day_of_week' => $dow,
                    'day_label' => $fullLabels[$dow] ?? date('l', $cursor),
                    'state' => $state,
                    'has_standing' => true,
                ];
            }
        }
        $cursor = strtotime('+1 day', $cursor);
    }
    return $results;
}

function bakery_customer_save_standing_line(PDO $db, array $customer, $productId, $dayOfWeek, $quantity) {
    bakery_customer_order_ensure_schema($db);
    $customerId = (int)$customer['id'];
    $productId = (int)$productId;
    $dayOfWeek = bakery_normalize_standing_day((int)$dayOfWeek);
    $quantity = max(0, (int)$quantity);

    if ($productId <= 0 || $dayOfWeek < 1 || $dayOfWeek > 7) {
        throw new InvalidArgumentException('Invalid standing order data');
    }
    if (!bakery_customer_product_row($db, $productId)) {
        throw new InvalidArgumentException('Unknown product');
    }

    $oldQty = bakery_customer_standing_qty($db, $customerId, $productId, $dayOfWeek);
    $product = bakery_customer_product_row($db, $productId);
    $fullLabels = bakery_standing_day_full_labels();

    bakery_standing_order_upsert($db, $customerId, $productId, $dayOfWeek, $quantity);

    if ($oldQty !== $quantity) {
        $dayLabel = $fullLabels[$dayOfWeek] ?? 'Day ' . $dayOfWeek;
        bakery_portal_record_event($db, BAKERY_OP_PORTAL_STANDING_CHANGED,
            $customer['name'] . ' changed regular ' . $dayLabel . ' ' . $product['name'] . ': ' . $oldQty . ' → ' . $quantity,
            $customerId,
            [
                'product_id' => $productId,
                'metadata' => [
                    'day_of_week' => $dayOfWeek,
                    'product_name' => $product['name'],
                    'old_quantity' => $oldQty,
                    'new_quantity' => $quantity,
                    'scope' => 'regular',
                ],
            ]
        );
        bakery_customer_notify_standing_changed(
            $db, $customer, $dayLabel, $product['name'], $oldQty, $quantity, $dayOfWeek, $productId
        );
    }

    return [
        'old_quantity' => $oldQty,
        'new_quantity' => $quantity,
        'product_name' => $product['name'],
        'day_of_week' => $dayOfWeek,
        'day_label' => $fullLabels[$dayOfWeek] ?? '',
    ];
}

function bakery_customer_save_daily_line(PDO $db, array $customer, $date, $productId, $quantity) {
    bakery_customer_order_ensure_schema($db);
    $customerId = (int)$customer['id'];
    $productId = (int)$productId;
    $quantity = max(0, (int)$quantity);

    $dateObj = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid delivery date');
    }
    if ($date < date('Y-m-d')) {
        throw new InvalidArgumentException('Cannot change past deliveries');
    }
    if ($productId <= 0 || !bakery_customer_product_row($db, $productId)) {
        throw new InvalidArgumentException('Unknown product');
    }

    $state = bakery_customer_delivery_state($db, $customerId, $date);
    if ($state['skipped']) {
        throw new InvalidArgumentException('This delivery is skipped');
    }
    if ($state['paused']) {
        throw new InvalidArgumentException('Deliveries are paused for this date');
    }
    if ($state['locked']) {
        throw new InvalidArgumentException('This delivery is locked — use Request a Change');
    }

    $dayOfWeek = bakery_standing_day_from_date($date);
    $regularQty = bakery_customer_standing_qty($db, $customerId, $productId, $dayOfWeek);
    $product = bakery_customer_product_row($db, $productId);

    $dailyOrderId = bakery_customer_ensure_daily_order($db, $customer, $date);
    $stmt = $db->prepare(
        'SELECT id, quantity FROM daily_order_items WHERE daily_order_id = ? AND product_id = ? LIMIT 1'
    );
    $stmt->execute([$dailyOrderId, $productId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    $oldQty = $existing ? (int)$existing['quantity'] : $regularQty;

    if ($quantity > 0) {
        $unitPrice = bakery_resolve_customer_price($db, $customer, $product);
        $lineTotal = round($quantity * $unitPrice, 2);
        if ($existing) {
            $upd = $db->prepare(
                'UPDATE daily_order_items SET quantity = ?, unit_price = ?, line_total = ? WHERE id = ?'
            );
            $upd->execute([$quantity, $unitPrice, $lineTotal, (int)$existing['id']]);
        } else {
            $ins = $db->prepare(
                'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $ins->execute([$dailyOrderId, $productId, $quantity, $unitPrice, $lineTotal]);
        }
    } elseif ($existing) {
        $del = $db->prepare('DELETE FROM daily_order_items WHERE id = ?');
        $del->execute([(int)$existing['id']]);
    }
    bakery_customer_update_daily_total($db, $dailyOrderId);

    if ($oldQty !== $quantity) {
        bakery_portal_record_event($db, BAKERY_OP_PORTAL_DAILY_CHANGED,
            $customer['name'] . ' changed delivery ' . $date . ' ' . $product['name'] . ': ' . $oldQty . ' → ' . $quantity,
            $customerId,
            [
                'operational_date' => $date,
                'daily_order_id' => $dailyOrderId,
                'product_id' => $productId,
                'metadata' => [
                    'product_name' => $product['name'],
                    'regular_quantity' => $regularQty,
                    'old_quantity' => $oldQty,
                    'new_quantity' => $quantity,
                    'scope' => 'delivery',
                ],
            ]
        );
        bakery_customer_notify_daily_changed(
            $db, $customer, $date, $product['name'], $oldQty, $quantity, $dailyOrderId, $productId
        );
    }

    return [
        'daily_order_id' => $dailyOrderId,
        'old_quantity' => $oldQty,
        'new_quantity' => $quantity,
        'regular_quantity' => $regularQty,
        'product_name' => $product['name'],
        'diff_from_regular' => $quantity - $regularQty,
    ];
}

function bakery_customer_skip_delivery(PDO $db, array $customer, $date, $note = '') {
    bakery_customer_order_ensure_schema($db);
    $customerId = (int)$customer['id'];

    $dateObj = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid delivery date');
    }
    if ($date < date('Y-m-d')) {
        throw new InvalidArgumentException('Cannot skip past deliveries');
    }

    $state = bakery_customer_delivery_state($db, $customerId, $date);
    if ($state['locked']) {
        throw new InvalidArgumentException('This delivery is locked — use Request a Change');
    }

    $note = trim((string)$note);
    $stmt = $db->prepare(
        'INSERT INTO customer_delivery_skips (customer_id, skip_date, note)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE note = VALUES(note)'
    );
    $stmt->execute([$customerId, $date, $note !== '' ? $note : null]);

    $daily = bakery_customer_daily_order_row($db, $customerId, $date);
    if ($daily && !$state['locked']) {
        $db->prepare('DELETE FROM daily_order_items WHERE daily_order_id = ?')->execute([(int)$daily['id']]);
        $db->prepare(
            'UPDATE daily_orders SET total_amount = 0, notes = ? WHERE id = ?'
        )->execute(['Customer skipped delivery', (int)$daily['id']]);
    }

    bakery_portal_record_event($db, BAKERY_OP_PORTAL_DELIVERY_SKIPPED,
        $customer['name'] . ' skipped delivery ' . $date,
        $customerId,
        [
            'operational_date' => $date,
            'daily_order_id' => $daily ? (int)$daily['id'] : null,
            'metadata' => ['note' => $note !== '' ? $note : null],
        ]
    );
    bakery_customer_notify_delivery_skipped(
        $db, $customer, $date, $daily ? (int)$daily['id'] : null
    );

    return ['date' => $date, 'skipped' => true];
}

function bakery_customer_unskip_delivery(PDO $db, array $customer, $date) {
    bakery_customer_order_ensure_schema($db);
    $customerId = (int)$customer['id'];

    $stmt = $db->prepare(
        'DELETE FROM customer_delivery_skips WHERE customer_id = ? AND skip_date = ?'
    );
    $stmt->execute([$customerId, $date]);

    bakery_portal_record_event($db, BAKERY_OP_PORTAL_DELIVERY_UNSKIPPED,
        $customer['name'] . ' restored delivery ' . $date,
        $customerId,
        ['operational_date' => $date]
    );

    return ['date' => $date, 'skipped' => false];
}

function bakery_customer_create_pause_range(PDO $db, array $customer, $pauseStart, $pauseEnd, $note = '') {
    bakery_customer_order_ensure_schema($db);
    $customerId = (int)$customer['id'];

    $startObj = DateTime::createFromFormat('!Y-m-d', $pauseStart);
    $endObj = DateTime::createFromFormat('!Y-m-d', $pauseEnd);
    if (!$startObj || $startObj->format('Y-m-d') !== $pauseStart
        || !$endObj || $endObj->format('Y-m-d') !== $pauseEnd) {
        throw new InvalidArgumentException('Invalid pause dates');
    }
    if ($pauseEnd < $pauseStart) {
        throw new InvalidArgumentException('Pause end must be on or after start');
    }
    if ($pauseEnd < date('Y-m-d')) {
        throw new InvalidArgumentException('Cannot pause dates in the past');
    }

    $note = trim((string)$note);
    $stmt = $db->prepare(
        'INSERT INTO customer_delivery_pauses (customer_id, pause_start, pause_end, note)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$customerId, $pauseStart, $pauseEnd, $note !== '' ? $note : null]);
    $pauseId = (int)$db->lastInsertId();

    bakery_portal_record_event($db, BAKERY_OP_PORTAL_PAUSE_CREATED,
        $customer['name'] . ' paused deliveries ' . $pauseStart . ' through ' . $pauseEnd,
        $customerId,
        [
            'metadata' => [
                'pause_id' => $pauseId,
                'pause_start' => $pauseStart,
                'pause_end' => $pauseEnd,
                'note' => $note !== '' ? $note : null,
            ],
        ]
    );
    bakery_customer_notify_pause_scheduled($db, $customer, $pauseStart, $pauseEnd, $pauseId);

    return ['pause_id' => $pauseId, 'pause_start' => $pauseStart, 'pause_end' => $pauseEnd];
}

function bakery_customer_remove_pause_range(PDO $db, array $customer, $pauseId) {
    bakery_customer_order_ensure_schema($db);
    $customerId = (int)$customer['id'];
    $pauseId = (int)$pauseId;

    $stmt = $db->prepare(
        'SELECT pause_start, pause_end FROM customer_delivery_pauses WHERE id = ? AND customer_id = ? LIMIT 1'
    );
    $stmt->execute([$pauseId, $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new InvalidArgumentException('Pause not found');
    }

    $db->prepare('DELETE FROM customer_delivery_pauses WHERE id = ? AND customer_id = ?')
        ->execute([$pauseId, $customerId]);

    bakery_portal_record_event($db, BAKERY_OP_PORTAL_PAUSE_REMOVED,
        $customer['name'] . ' resumed deliveries (removed pause ' . $row['pause_start'] . ' – ' . $row['pause_end'] . ')',
        $customerId,
        [
            'metadata' => [
                'pause_id' => $pauseId,
                'pause_start' => $row['pause_start'],
                'pause_end' => $row['pause_end'],
            ],
        ]
    );

    return ['pause_id' => $pauseId, 'removed' => true];
}

function bakery_customer_active_pause_ranges(PDO $db, $customerId) {
    bakery_customer_order_ensure_schema($db);
    if (!table_exists($db, 'customer_delivery_pauses')) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT id, pause_start, pause_end, note, created_at
         FROM customer_delivery_pauses
         WHERE customer_id = ? AND pause_end >= CURDATE()
         ORDER BY pause_start'
    );
    $stmt->execute([(int)$customerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function bakery_customer_request_change(PDO $db, array $customer, $date, $message, array $details = []) {
    bakery_customer_order_ensure_schema($db);
    $customerId = (int)$customer['id'];
    $message = trim((string)$message);
    if ($message === '') {
        throw new InvalidArgumentException('Please describe the change you need');
    }

    $dateObj = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid delivery date');
    }

    $daily = bakery_customer_daily_order_row($db, $customerId, $date);
    $details['requested_at'] = date('c');
    if ($daily) {
        $details['order_status'] = $daily['status'];
    }

    $stmt = $db->prepare(
        'INSERT INTO customer_change_requests
         (customer_id, order_date, daily_order_id, request_type, message, status, metadata)
         VALUES (?, ?, ?, \'delivery_change\', ?, \'pending\', ?)'
    );
    $stmt->execute([
        $customerId,
        $date,
        $daily ? (int)$daily['id'] : null,
        $message,
        json_encode($details, JSON_UNESCAPED_UNICODE),
    ]);
    $requestId = (int)$db->lastInsertId();

    bakery_portal_record_event($db, BAKERY_OP_PORTAL_CHANGE_REQUESTED,
        $customer['name'] . ' requested a change for delivery ' . $date,
        $customerId,
        [
            'operational_date' => $date,
            'daily_order_id' => $daily ? (int)$daily['id'] : null,
            'metadata' => [
                'request_id' => $requestId,
                'message' => $message,
            ],
        ]
    );
    bakery_customer_notify_change_requested($db, $customer, $date, $requestId);

    return ['request_id' => $requestId, 'date' => $date];
}

function bakery_customer_format_confirmation(array $result, $scope = 'standing') {
    if ($scope === 'standing') {
        return [
            'title' => bakery_t('portal.confirm_regular_changed', [
                'day' => $result['day_label'] ?? '',
            ]),
            'lines' => [
                bakery_t('portal.confirm_qty_change', [
                    'product' => $result['product_name'] ?? '',
                    'old' => $result['old_quantity'] ?? 0,
                    'new' => $result['new_quantity'] ?? 0,
                ]),
            ],
            'unchanged' => bakery_t('portal.confirm_regular_unchanged'),
        ];
    }

    $dateFormatted = format_date($result['date'] ?? '');
    return [
        'title' => bakery_t('portal.confirm_delivery_changed', ['date' => $dateFormatted]),
        'lines' => [
            bakery_t('portal.confirm_qty_change', [
                'product' => $result['product_name'] ?? '',
                'old' => $result['old_quantity'] ?? 0,
                'new' => $result['new_quantity'] ?? 0,
            ]),
            bakery_t('portal.confirm_regular_remains', [
                'product' => $result['product_name'] ?? '',
                'qty' => $result['regular_quantity'] ?? 0,
            ]),
        ],
        'unchanged' => bakery_t('portal.confirm_regular_schedule_unchanged'),
    ];
}

/**
 * Soft-close / reopen a wholesale client. Sole write path for customers.is_active.
 *
 * Deactivation retires eligible future dated demand. Reactivation does not
 * revive retired rows; standing generation recreates future demand.
 *
 * @return array{customer_id:int,is_active:bool,retired:array}
 */
function bakery_customer_apply_active_status(PDO $db, int $customerId, bool $isActive, string $reason = ''): array
{
    if ($customerId <= 0) {
        throw new InvalidArgumentException('A valid customer is required.');
    }

    $stmt = $db->prepare(
        'SELECT id, name, is_active FROM customers WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        throw new RuntimeException('Customer not found.');
    }

    $reason = trim($reason);
    $update = $db->prepare(
        'UPDATE customers
         SET is_active = ?, inactive_at = ?, inactive_reason = ?
         WHERE id = ?'
    );
    $update->execute([
        $isActive ? 1 : 0,
        $isActive ? null : date('Y-m-d H:i:s'),
        $isActive ? null : ($reason !== '' ? $reason : null),
        $customerId,
    ]);

    $retired = [
        'orders_retired' => 0,
        'orders_protected' => 0,
        'assignments_removed' => 0,
        'daily_order_ids' => [],
    ];
    if (!$isActive) {
        $retired = bakery_customer_retire_inactive_future_demand(
            $db,
            $customerId,
            (string)$customer['name']
        );
    }

    return [
        'customer_id' => $customerId,
        'is_active' => $isActive,
        'retired' => $retired,
    ];
}

/**
 * Stop unstarted future operational demand for a client that is no longer active.
 * Keeps the dated order shell. Does not rewrite past or in-progress work.
 *
 * @return array{orders_retired:int,orders_protected:int,assignments_removed:int,daily_order_ids:list<int>}
 */
function bakery_customer_retire_inactive_future_demand(PDO $db, int $customerId, string $customerName = ''): array
{
    $today = date('Y-m-d');
    $result = [
        'orders_retired' => 0,
        'orders_protected' => 0,
        'assignments_removed' => 0,
        'daily_order_ids' => [],
    ];
    if ($customerId <= 0) {
        return $result;
    }

    $stmt = $db->prepare(
        'SELECT do.id, do.order_date, do.status, do.notes,
                (
                    SELECT doa.delivery_status
                    FROM daily_order_assignments doa
                    WHERE doa.daily_order_id = do.id AND doa.delivery_date = do.order_date
                    ORDER BY doa.id LIMIT 1
                ) AS assignment_status
         FROM daily_orders do
         WHERE do.customer_id = ?
           AND do.order_date >= ?
         ORDER BY do.order_date, do.id'
    );
    $stmt->execute([$customerId, $today]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($orders === []) {
        return $result;
    }

    $deleteItems = $db->prepare('DELETE FROM daily_order_items WHERE daily_order_id = ?');
    $updateOrder = $db->prepare(
        'UPDATE daily_orders SET total_amount = 0, notes = ? WHERE id = ?'
    );
    $deletePendingAssignments = $db->prepare(
        "DELETE FROM daily_order_assignments
         WHERE daily_order_id = ?
           AND delivery_date = ?
           AND COALESCE(delivery_status, 'pending') IN ('pending', 'cancelled')"
    );

    foreach ($orders as $order) {
        $dailyOrderId = (int)$order['id'];
        $orderDate = (string)$order['order_date'];
        if (bakery_demand_review_is_advanced_status(
            $order['status'] ?? null,
            $order['assignment_status'] ?? null
        )) {
            $result['orders_protected']++;
            continue;
        }

        $deleteItems->execute([$dailyOrderId]);
        $itemsRemoved = $deleteItems->rowCount();
        $deletePendingAssignments->execute([$dailyOrderId, $orderDate]);
        $assignmentsRemoved = $deletePendingAssignments->rowCount();
        $result['assignments_removed'] += $assignmentsRemoved;

        $existingNotes = trim((string)($order['notes'] ?? ''));
        $inactiveNote = 'Customer inactive';
        $notes = $existingNotes === '' || strpos($existingNotes, $inactiveNote) === false
            ? trim($existingNotes . ($existingNotes !== '' ? "\n" : '') . $inactiveNote)
            : $existingNotes;
        $updateOrder->execute([$notes, $dailyOrderId]);

        if ($itemsRemoved > 0 || $assignmentsRemoved > 0 || strpos($existingNotes, $inactiveNote) === false) {
            $result['orders_retired']++;
            $result['daily_order_ids'][] = $dailyOrderId;
        }
    }

    if ($result['orders_retired'] > 0 || $result['orders_protected'] > 0) {
        $label = $customerName !== '' ? $customerName : ('customer #' . $customerId);
        bakery_record_operational_event(
            $db,
            BAKERY_OP_DAILY_ORDER_CLEARED,
            $label . ' made inactive; retired ' . $result['orders_retired']
                . ' future dated order(s)'
                . ($result['orders_protected'] > 0
                    ? (', protected ' . $result['orders_protected'])
                    : ''),
            [
                'customer_id' => $customerId,
                'operational_date' => $today,
                'metadata' => [
                    'reason' => 'customer_inactive',
                    'daily_order_ids' => $result['daily_order_ids'],
                    'orders_retired' => $result['orders_retired'],
                    'orders_protected' => $result['orders_protected'],
                    'assignments_removed' => $result['assignments_removed'],
                ],
            ]
        );
    }

    return $result;
}
