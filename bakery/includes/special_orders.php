<?php
/**
 * Special orders: one-off deliveries that are not pan dulce.
 *
 * A special order belongs to one customer and one delivery date. Lines are
 * either catalog products or free text, each with its own quantity and unit
 * price. They never write standing_orders or the pan dulce dated grid.
 * Saving an open order ensures a dated stop and, when the customer has a
 * standing route that day, assigns that driver the same way generation does.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/common_functions.php';
require_once __DIR__ . '/customer_order_mutations.php';
require_once __DIR__ . '/daily_orders_actions.php';
require_once __DIR__ . '/billing_header.php';
require_once __DIR__ . '/sfb_origin.php';

function bakery_special_orders_ready(PDO $db): bool
{
    return function_exists('table_exists')
        && table_exists($db, 'special_orders')
        && table_exists($db, 'special_order_lines');
}

function bakery_special_order_actor_id(): ?int
{
    if (!function_exists('bakery_current_user')) {
        return null;
    }
    $user = bakery_current_user();
    $id = (int)($user['id'] ?? 0);
    return $id > 0 ? $id : null;
}

/**
 * @return array{ok:bool, error?:string, id?:int, daily_order_id?:int}
 */
function bakery_special_order_save(PDO $db, array $input): array
{
    bakery_require_role(['administrator', 'manager']);
    if (!bakery_special_orders_ready($db)) {
        return ['ok' => false, 'error' => 'missing_schema'];
    }

    $date = trim((string)($input['delivery_date'] ?? ''));
    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        return ['ok' => false, 'error' => 'invalid_date'];
    }

    $customerId = (int)($input['customer_id'] ?? 0);
    $customer = bakery_special_order_customer_row($db, $customerId);
    if (!$customer) {
        return ['ok' => false, 'error' => 'need_customer'];
    }

    $parsed = bakery_special_order_parse_lines($db, $input['lines'] ?? []);
    if (!$parsed['ok']) {
        return ['ok' => false, 'error' => (string)$parsed['error']];
    }
    $lines = $parsed['lines'];
    if ($lines === []) {
        return ['ok' => false, 'error' => 'need_line'];
    }

    $note = trim((string)($input['note'] ?? ''));
    if (function_exists('mb_substr')) {
        $note = mb_substr($note, 0, 500);
    } else {
        $note = substr($note, 0, 500);
    }

    $orderId = (int)($input['id'] ?? 0);
    $existing = null;
    if ($orderId > 0) {
        $existing = bakery_special_order_load($db, $orderId);
        if (!$existing || (string)$existing['status'] !== 'open') {
            return ['ok' => false, 'error' => 'not_found'];
        }
    } else {
        $existing = bakery_special_order_open_for_customer_date($db, $customerId, $date);
        if ($existing) {
            $orderId = (int)$existing['id'];
        }
    }

    $linkedOrderId = (int)($existing['daily_order_id'] ?? 0);
    if ($linkedOrderId > 0 && bakery_billing_header_is_historical($db, $linkedOrderId)) {
        return ['ok' => false, 'error' => 'already_delivered'];
    }

    $actor = bakery_special_order_actor_id();
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }
    try {
        $dailyOrderId = bakery_special_order_ensure_routed_stop($db, $customer, $date);
        if ($orderId > 0) {
            $db->prepare(
                'UPDATE special_orders
                 SET customer_id = ?, delivery_date = ?, daily_order_id = ?, note = ?, updated_by = ?
                 WHERE id = ? AND status = \'open\''
            )->execute([$customerId, $date, $dailyOrderId, $note, $actor, $orderId]);
            $db->prepare('DELETE FROM special_order_lines WHERE special_order_id = ?')->execute([$orderId]);
        } else {
            $db->prepare(
                'INSERT INTO special_orders
                    (customer_id, delivery_date, daily_order_id, note, status, created_by, updated_by)
                 VALUES (?, ?, ?, ?, \'open\', ?, ?)'
            )->execute([$customerId, $date, $dailyOrderId, $note, $actor, $actor]);
            $orderId = (int)$db->lastInsertId();
        }

        $insert = $db->prepare(
            'INSERT INTO special_order_lines
                (special_order_id, line_kind, product_id, description, quantity, unit_price,
                 line_total, is_no_charge, no_charge_reason, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($lines as $index => $line) {
            $insert->execute([
                $orderId,
                $line['line_kind'],
                $line['product_id'],
                $line['description'],
                $line['quantity'],
                $line['unit_price'],
                $line['line_total'],
                $line['is_no_charge'],
                $line['no_charge_reason'],
                $index,
            ]);
        }
        bakery_customer_update_daily_total($db, $dailyOrderId);
        if ($ownsTransaction) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return ['ok' => true, 'id' => $orderId, 'daily_order_id' => $dailyOrderId];
}

/**
 * @return array{ok:bool, error?:string}
 */
function bakery_special_order_cancel(PDO $db, int $orderId): array
{
    bakery_require_role(['administrator', 'manager']);
    if (!bakery_special_orders_ready($db)) {
        return ['ok' => false, 'error' => 'missing_schema'];
    }
    $existing = bakery_special_order_load($db, $orderId);
    if (!$existing || (string)$existing['status'] !== 'open') {
        return ['ok' => false, 'error' => 'not_found'];
    }
    $dailyOrderId = (int)($existing['daily_order_id'] ?? 0);
    if ($dailyOrderId > 0 && bakery_billing_header_is_historical($db, $dailyOrderId)) {
        return ['ok' => false, 'error' => 'already_delivered'];
    }
    $actor = bakery_special_order_actor_id();
    $db->prepare(
        'UPDATE special_orders
         SET status = \'cancelled\', cancelled_by = ?, cancelled_at = CURRENT_TIMESTAMP, updated_by = ?
         WHERE id = ? AND status = \'open\''
    )->execute([$actor, $actor, $orderId]);
    if ($dailyOrderId > 0) {
        bakery_customer_update_daily_total($db, $dailyOrderId);
    }
    return ['ok' => true];
}

/**
 * @param array<int, mixed> $rawLines
 * @return array{ok:bool, error?:string, lines?:list<array<string, mixed>>}
 */
function bakery_special_order_parse_lines(PDO $db, array $rawLines): array
{
    $lines = [];
    foreach ($rawLines as $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $kind = (string)($raw['line_kind'] ?? '');
        if ($kind !== 'catalog' && $kind !== 'text') {
            $kind = '';
        }
        $productId = (int)($raw['product_id'] ?? 0);
        $description = trim((string)($raw['description'] ?? ''));
        $quantity = filter_var($raw['quantity'] ?? 0, FILTER_VALIDATE_INT);
        if ($quantity === false || (int)$quantity < 0 || (int)$quantity > 9999) {
            return ['ok' => false, 'error' => 'bad_quantity'];
        }
        $quantity = (int)$quantity;
        $priceRaw = $raw['unit_price'] ?? 0;
        if ($priceRaw === '' || $priceRaw === null) {
            $priceRaw = 0;
        }
        $price = filter_var($priceRaw, FILTER_VALIDATE_FLOAT);
        if ($price === false || (float)$price < 0 || (float)$price > 9999) {
            return ['ok' => false, 'error' => 'bad_price'];
        }
        $price = round((float)$price, 2);
        $noCharge = !empty($raw['is_no_charge']);
        $reason = null;
        if ($noCharge) {
            try {
                $reason = bakery_no_charge_normalize_reason($raw['no_charge_reason'] ?? '');
            } catch (InvalidArgumentException $e) {
                return ['ok' => false, 'error' => 'bad_reason'];
            }
            $price = 0.0;
        }
        $blank = $kind === '' && $productId <= 0 && $description === '' && $quantity === 0;
        if ($blank) {
            continue;
        }
        if ($quantity <= 0) {
            continue;
        }
        if ($kind === '') {
            $kind = $productId > 0 ? 'catalog' : 'text';
        }
        if ($kind === 'catalog') {
            if ($productId <= 0) {
                return ['ok' => false, 'error' => 'need_product'];
            }
            $product = bakery_customer_product_row($db, $productId);
            if (!$product) {
                return ['ok' => false, 'error' => 'need_product'];
            }
            if ($description === '') {
                $description = (string)$product['name'];
            }
        } else {
            $productId = 0;
            if ($description === '') {
                return ['ok' => false, 'error' => 'need_description'];
            }
        }
        if (function_exists('mb_substr')) {
            $description = mb_substr($description, 0, 200);
        } else {
            $description = substr($description, 0, 200);
        }
        $lines[] = [
            'line_kind' => $kind,
            'product_id' => $productId > 0 ? $productId : null,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $price,
            'line_total' => $noCharge ? 0.0 : round($quantity * $price, 2),
            'is_no_charge' => $noCharge ? 1 : 0,
            'no_charge_reason' => $noCharge ? $reason : null,
        ];
    }
    return ['ok' => true, 'lines' => $lines];
}

/**
 * @return array<string, mixed>|null
 */
function bakery_special_order_customer_row(PDO $db, int $customerId): ?array
{
    if ($customerId <= 0) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT c.* FROM customers c
         WHERE c.id = ? AND c.is_active = 1 '
        . bakery_sfb_ops_origin_clause('c', $db) . '
         LIMIT 1'
    );
    $stmt->execute([$customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Create the dated stop when this customer has none, and assign the standing
 * route driver when that weekday has exactly one. Existing assignments stay.
 */
function bakery_special_order_ensure_routed_stop(PDO $db, array $customer, string $date): int
{
    $customerId = (int)$customer['id'];
    $dailyOrderId = bakery_daily_order_find_or_create($db, $customerId, $date);
    if (!function_exists('table_exists') || !table_exists($db, 'daily_order_assignments')) {
        return $dailyOrderId;
    }

    $existing = $db->prepare(
        'SELECT id, driver_id FROM daily_order_assignments
         WHERE daily_order_id = ? AND delivery_date = ?
         ORDER BY id LIMIT 1'
    );
    $existing->execute([$dailyOrderId, $date]);
    $assignment = $existing->fetch(PDO::FETCH_ASSOC);
    if ($assignment) {
        $db->prepare('UPDATE daily_orders SET driver_id = ? WHERE id = ?')
            ->execute([(int)$assignment['driver_id'], $dailyOrderId]);
        return $dailyOrderId;
    }

    if (!table_exists($db, 'standing_routes')) {
        return $dailyOrderId;
    }
    $day = bakery_standing_day_from_date($date);
    $dayClause = bakery_standing_day_in_clause($day);
    $routeStmt = $db->prepare(
        "SELECT driver_id FROM standing_routes
         WHERE customer_id = ? AND day_of_week {$dayClause['sql']}
         ORDER BY id"
    );
    $routeStmt->execute(array_merge([$customerId], $dayClause['values']));
    $driverIds = [];
    foreach ($routeStmt->fetchAll(PDO::FETCH_COLUMN) as $driverId) {
        $driverId = (int)$driverId;
        if ($driverId > 0 && !in_array($driverId, $driverIds, true)) {
            $driverIds[] = $driverId;
        }
    }
    if (count($driverIds) !== 1) {
        return $dailyOrderId;
    }
    // Lines for this special order are inserted after the assignment.
    // Restoring a removed-day skip here would copy pan standing onto a
    // special-only stop. Later assignment paths see the special quantity.
    $driverId = $driverIds[0];
    $maxStmt = $db->prepare(
        'SELECT COALESCE(MAX(route_order), 0) FROM daily_order_assignments
         WHERE driver_id = ? AND delivery_date = ?'
    );
    $maxStmt->execute([$driverId, $date]);
    $routeOrder = (int)$maxStmt->fetchColumn() + 1;
    $hasNotes = function_exists('column_exists') && column_exists($db, 'daily_order_assignments', 'notes');
    if ($hasNotes) {
        $db->prepare(
            'INSERT INTO daily_order_assignments
                (daily_order_id, driver_id, delivery_date, route_order, scheduled_delivery_time, delivery_status, notes)
             VALUES (?, ?, ?, ?, NULL, \'pending\', NULL)'
        )->execute([$dailyOrderId, $driverId, $date, $routeOrder]);
    } else {
        $db->prepare(
            'INSERT INTO daily_order_assignments
                (daily_order_id, driver_id, delivery_date, route_order, scheduled_delivery_time, delivery_status)
             VALUES (?, ?, ?, ?, NULL, \'pending\')'
        )->execute([$dailyOrderId, $driverId, $date, $routeOrder]);
    }
    $db->prepare('UPDATE daily_orders SET driver_id = ? WHERE id = ?')
        ->execute([$driverId, $dailyOrderId]);
    return $dailyOrderId;
}

/**
 * @return array<string, mixed>|null
 */
function bakery_special_order_load(PDO $db, int $orderId): ?array
{
    if (!bakery_special_orders_ready($db) || $orderId <= 0) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT so.*, c.name AS customer_name
         FROM special_orders so
         JOIN customers c ON c.id = so.customer_id
         WHERE so.id = ?
         LIMIT 1'
    );
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return null;
    }
    $order['lines'] = bakery_special_order_lines($db, $orderId);
    return $order;
}

/**
 * @return array<string, mixed>|null
 */
function bakery_special_order_open_for_customer_date(PDO $db, int $customerId, string $date): ?array
{
    if (!bakery_special_orders_ready($db) || $customerId <= 0) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT id FROM special_orders
         WHERE customer_id = ? AND delivery_date = ? AND status = \'open\'
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$customerId, $date]);
    $id = (int)$stmt->fetchColumn();
    return $id > 0 ? bakery_special_order_load($db, $id) : null;
}

/**
 * @return list<array<string, mixed>>
 */
function bakery_special_order_lines(PDO $db, int $orderId): array
{
    if (!bakery_special_orders_ready($db) || $orderId <= 0) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT id, special_order_id, line_kind, product_id, description, quantity,
                delivered_quantity, unit_price, line_total, is_no_charge, no_charge_reason, sort_order
         FROM special_order_lines
         WHERE special_order_id = ?
         ORDER BY sort_order, id'
    );
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string, mixed>>
 */
function bakery_special_order_list_for_date(PDO $db, string $date): array
{
    if (!bakery_special_orders_ready($db)) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT so.id, so.customer_id, so.delivery_date, so.daily_order_id, so.note, so.status,
                c.name AS customer_name
         FROM special_orders so
         JOIN customers c ON c.id = so.customer_id
         WHERE so.delivery_date = ? AND so.status = \'open\'
         ORDER BY c.name, so.id'
    );
    $stmt->execute([$date]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($orders === []) {
        return [];
    }
    $ids = array_map(static function ($row) {
        return (int)$row['id'];
    }, $orders);
    $byOrder = bakery_special_order_lines_grouped($db, $ids);
    foreach ($orders as &$order) {
        $order['lines'] = $byOrder[(int)$order['id']] ?? [];
    }
    unset($order);
    return $orders;
}

/**
 * @param list<int> $orderIds
 * @return array<int, list<array<string, mixed>>>
 */
function bakery_special_order_lines_grouped(PDO $db, array $orderIds): array
{
    $orderIds = array_values(array_filter(array_map('intval', $orderIds)));
    if (!bakery_special_orders_ready($db) || $orderIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmt = $db->prepare(
        "SELECT id, special_order_id, line_kind, product_id, description, quantity,
                delivered_quantity, unit_price, line_total, is_no_charge, no_charge_reason, sort_order
         FROM special_order_lines
         WHERE special_order_id IN ($placeholders)
         ORDER BY special_order_id, sort_order, id"
    );
    $stmt->execute($orderIds);
    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $grouped[(int)$line['special_order_id']][] = $line;
    }
    return $grouped;
}

/**
 * @return array<int, list<array<string, mixed>>>
 */
function bakery_special_order_lines_by_daily_order(PDO $db, string $date): array
{
    if (!bakery_special_orders_ready($db)) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT so.daily_order_id, sol.id, sol.special_order_id, sol.line_kind, sol.product_id,
                sol.description, sol.quantity, sol.delivered_quantity, sol.unit_price, sol.line_total,
                sol.is_no_charge, sol.no_charge_reason, sol.sort_order
         FROM special_orders so
         JOIN special_order_lines sol ON sol.special_order_id = so.id
         WHERE so.delivery_date = ? AND so.status = \'open\' AND so.daily_order_id IS NOT NULL
           AND sol.quantity > 0
         ORDER BY so.daily_order_id, sol.sort_order, sol.id'
    );
    $stmt->execute([$date]);
    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $grouped[(int)$line['daily_order_id']][] = $line;
    }
    return $grouped;
}

/**
 * @param list<int> $dailyOrderIds
 * @return array<int, list<array<string, mixed>>>
 */
function bakery_special_order_lines_for_daily_orders(PDO $db, array $dailyOrderIds): array
{
    $dailyOrderIds = array_values(array_filter(array_map('intval', $dailyOrderIds)));
    if (!bakery_special_orders_ready($db) || $dailyOrderIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($dailyOrderIds), '?'));
    $stmt = $db->prepare(
        "SELECT so.daily_order_id, sol.id, sol.description, sol.quantity, sol.unit_price,
                sol.is_no_charge, sol.line_total
         FROM special_orders so
         JOIN special_order_lines sol ON sol.special_order_id = so.id
         WHERE so.status = 'open' AND so.daily_order_id IN ($placeholders) AND sol.quantity > 0
         ORDER BY so.daily_order_id, sol.sort_order, sol.id"
    );
    $stmt->execute($dailyOrderIds);
    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $grouped[(int)$line['daily_order_id']][] = $line;
    }
    return $grouped;
}

function bakery_special_order_has_positive_qty(PDO $db, int $dailyOrderId): bool
{
    if (!bakery_special_orders_ready($db) || $dailyOrderId <= 0) {
        return false;
    }
    $stmt = $db->prepare(
        'SELECT 1
         FROM special_orders so
         JOIN special_order_lines sol ON sol.special_order_id = so.id
         WHERE so.daily_order_id = ? AND so.status = \'open\' AND sol.quantity > 0
         LIMIT 1'
    );
    $stmt->execute([$dailyOrderId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Positive special-order quantity by customer for one delivery date.
 *
 * @return array<int, int>
 */
function bakery_special_order_units_by_customer(PDO $db, string $date): array
{
    if (!bakery_special_orders_ready($db)) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT so.customer_id, COALESCE(SUM(sol.quantity), 0) AS units
         FROM special_orders so
         JOIN special_order_lines sol ON sol.special_order_id = so.id
         WHERE so.delivery_date = ? AND so.status = \'open\' AND sol.quantity > 0
         GROUP BY so.customer_id'
    );
    $stmt->execute([$date]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['customer_id']] = (int)$row['units'];
    }
    return $out;
}

function bakery_special_order_chargeable_amount(array $line, bool $useDelivered): float
{
    if ((int)($line['is_no_charge'] ?? 0) === 1) {
        return 0.0;
    }
    $deliveredRaw = $line['delivered_quantity'] ?? null;
    $hasDelivered = $deliveredRaw !== null && $deliveredRaw !== '';
    $qty = ($useDelivered && $hasDelivered) ? (int)$deliveredRaw : (int)($line['quantity'] ?? 0);
    if ($qty < 0) {
        $qty = 0;
    }
    return round($qty * (float)($line['unit_price'] ?? 0), 2);
}

function bakery_special_order_chargeable_total(PDO $db, int $dailyOrderId, bool $useDelivered): float
{
    if (!bakery_special_orders_ready($db) || $dailyOrderId <= 0) {
        return 0.0;
    }
    $stmt = $db->prepare(
        'SELECT sol.quantity, sol.delivered_quantity, sol.unit_price, sol.is_no_charge
         FROM special_order_lines sol
         JOIN special_orders so ON so.id = sol.special_order_id
         WHERE so.daily_order_id = ? AND so.status = \'open\''
    );
    $stmt->execute([$dailyOrderId]);
    $sum = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $sum += bakery_special_order_chargeable_amount($line, $useDelivered);
    }
    return round($sum, 2);
}

/**
 * Invoice and driver rows for the special lines on one dated stop.
 * Prices are the entered snapshot. A no-charge mark stays at $0.
 *
 * @return list<array<string, mixed>>
 */
function bakery_special_order_invoice_lines(PDO $db, int $dailyOrderId): array
{
    if (!bakery_special_orders_ready($db) || $dailyOrderId <= 0) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT sol.id, sol.product_id, sol.description, sol.quantity, sol.delivered_quantity,
                sol.unit_price, sol.line_total, sol.is_no_charge, sol.no_charge_reason, sol.line_kind
         FROM special_order_lines sol
         JOIN special_orders so ON so.id = sol.special_order_id
         WHERE so.daily_order_id = ? AND so.status = \'open\' AND sol.quantity > 0
         ORDER BY sol.sort_order, sol.id'
    );
    $stmt->execute([$dailyOrderId]);
    $label = function_exists('bakery_t') ? bakery_t('special_order.line_label') : 'Special order';
    if ($label === 'special_order.line_label') {
        $label = 'Special order';
    }
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $noCharge = (int)($line['is_no_charge'] ?? 0) === 1;
        $unit = $noCharge ? 0.0 : round((float)$line['unit_price'], 2);
        $qty = (int)$line['quantity'];
        $delivered = $line['delivered_quantity'];
        $billQty = ($delivered !== null && $delivered !== '') ? (int)$delivered : $qty;
        $rows[] = [
            'id' => (int)$line['id'],
            'item_id' => (int)$line['id'],
            'daily_order_id' => $dailyOrderId,
            'product_id' => (int)($line['product_id'] ?? 0),
            'product_name' => $label . ': ' . (string)$line['description'],
            'description' => (string)$line['description'],
            'product_line_name' => $label,
            'product_line' => $label,
            'quantity' => $qty,
            'delivered_quantity' => ($delivered !== null && $delivered !== '') ? (int)$delivered : null,
            'unit_price' => $unit,
            'line_total' => $noCharge ? 0.0 : round($billQty * $unit, 2),
            'is_no_charge' => $noCharge ? 1 : 0,
            'no_charge_reason' => $noCharge ? ($line['no_charge_reason'] ?? null) : null,
            'is_store_credit' => 0,
            'is_special_order' => 1,
            'delivered_quantity_estimated' => 0,
            'line_kind' => (string)$line['line_kind'],
        ];
    }
    return $rows;
}

/**
 * @param array<int, array<int, array<string, mixed>>> $itemsByOrder
 * @param list<int> $orderIds
 */
function bakery_special_order_append_billing_items(PDO $db, array &$itemsByOrder, array $orderIds): void
{
    if (!bakery_special_orders_ready($db) || $orderIds === []) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $ids = array_values(array_map('intval', $orderIds));
    $stmt = $db->prepare(
        "SELECT so.daily_order_id
         FROM special_orders so
         WHERE so.status = 'open' AND so.daily_order_id IN ($placeholders)"
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $dailyOrderId) {
        $dailyOrderId = (int)$dailyOrderId;
        foreach (bakery_special_order_invoice_lines($db, $dailyOrderId) as $line) {
            $itemsByOrder[$dailyOrderId][] = $line;
        }
    }
}

/**
 * Record delivered quantities and add their snapshot prices to the delivery header.
 * Omitted lines are recorded as delivered in full. No-charge lines stay $0.
 * This does not call Square.
 *
 * @param array<int|string, int|string>|null $posted
 */
function bakery_special_order_book_delivery(PDO $db, int $dailyOrderId, ?array $posted = null): float
{
    if (!bakery_special_orders_ready($db) || $dailyOrderId <= 0) {
        return 0.0;
    }
    $stmt = $db->prepare(
        'SELECT sol.id, sol.quantity, sol.unit_price, sol.is_no_charge
         FROM special_order_lines sol
         JOIN special_orders so ON so.id = sol.special_order_id
         WHERE so.daily_order_id = ? AND so.status = \'open\''
    );
    $stmt->execute([$dailyOrderId]);
    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($lines === []) {
        return 0.0;
    }
    $update = $db->prepare(
        'UPDATE special_order_lines
         SET delivered_quantity = ?, line_total = ?
         WHERE id = ?'
    );
    foreach ($lines as $line) {
        $id = (int)$line['id'];
        $ordered = max(0, (int)$line['quantity']);
        if (is_array($posted) && (array_key_exists($id, $posted) || array_key_exists((string)$id, $posted))) {
            $raw = $posted[$id] ?? $posted[(string)$id];
            $parsed = filter_var($raw, FILTER_VALIDATE_INT);
            if ($parsed === false || (int)$parsed < 0) {
                throw new InvalidArgumentException('Enter whole numbers for special order quantities');
            }
            $delivered = (int)$parsed;
        } else {
            $delivered = $ordered;
        }
        $noCharge = (int)($line['is_no_charge'] ?? 0) === 1;
        $unit = $noCharge ? 0.0 : round((float)$line['unit_price'], 2);
        $update->execute([$delivered, round($delivered * $unit, 2), $id]);
    }

    $special = bakery_special_order_chargeable_total($db, $dailyOrderId, true);
    $header = $db->prepare('SELECT total_amount, delivery_order_total FROM daily_orders WHERE id = ?');
    $header->execute([$dailyOrderId]);
    $row = $header->fetch(PDO::FETCH_ASSOC) ?: [];
    $base = $row['delivery_order_total'] !== null && $row['delivery_order_total'] !== ''
        ? (float)$row['delivery_order_total']
        : (float)($row['total_amount'] ?? 0);
    $total = round($base + $special, 2);
    $db->prepare('UPDATE daily_orders SET total_amount = ?, delivery_order_total = ? WHERE id = ?')
        ->execute([$total, $total, $dailyOrderId]);
    return $total;
}

/**
 * @return list<array<string, mixed>>
 */
function bakery_special_order_customer_choices(PDO $db): array
{
    $origin = function_exists('bakery_sfb_ops_origin_clause') ? bakery_sfb_ops_origin_clause('c', $db) : '';
    $stmt = $db->query(
        'SELECT c.id, c.name FROM customers c
         WHERE c.is_active = 1 ' . $origin . '
         ORDER BY c.name'
    );
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

/**
 * @return list<array<string, mixed>>
 */
function bakery_special_order_product_choices(PDO $db): array
{
    $stmt = $db->query('SELECT id, name, price FROM products ORDER BY name');
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function bakery_special_order_stop_summary_html(array $lines): string
{
    $visible = [];
    foreach ($lines as $line) {
        if (!is_array($line) || (int)($line['quantity'] ?? 0) <= 0) {
            continue;
        }
        $visible[] = $line;
    }
    if ($visible === []) {
        return '';
    }
    $label = function_exists('bakery_t') ? bakery_t('special_order.badge') : 'Special order';
    if ($label === 'special_order.badge') {
        $label = 'Special order';
    }
    $html = '<div class="special-order-stop"><div class="special-order-stop__label">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>';
    foreach ($visible as $line) {
        $name = (string)($line['description'] ?? '');
        $qty = (int)$line['quantity'];
        $price = (int)($line['is_no_charge'] ?? 0) === 1 ? 0.0 : (float)($line['unit_price'] ?? 0);
        $html .= '<div class="special-order-stop__line">'
            . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
            . ' × ' . $qty
            . ' · $' . number_format($price, 2)
            . '</div>';
    }
    $html .= '</div>';
    return $html;
}

function bakery_special_order_plain_summary(array $lines): string
{
    $parts = [];
    foreach ($lines as $line) {
        if (!is_array($line) || (int)($line['quantity'] ?? 0) <= 0) {
            continue;
        }
        $price = (int)($line['is_no_charge'] ?? 0) === 1 ? 0.0 : (float)($line['unit_price'] ?? 0);
        $parts[] = (string)($line['description'] ?? '')
            . ' x' . (int)$line['quantity']
            . ' $' . number_format($price, 2);
    }
    return implode('; ', $parts);
}
