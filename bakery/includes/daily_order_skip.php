<?php
/**
 * Zero every line on one dated order without touching the standing order.
 *
 * The prior quantities live in operational_events metadata and in field_audit.
 * Undo restores the latest skip. A second skip, while the lines are already 0,
 * does not replace that snapshot. No extra table.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/operational_timeline.php';
require_once __DIR__ . '/customer_order_mutations.php';
require_once __DIR__ . '/sfb_origin.php';

function bakery_daily_order_skip_staff(?array $user): array
{
    if ($user === null && function_exists('bakery_current_user')) {
        $user = bakery_current_user();
    }
    $role = (string)($user['role_slug'] ?? '');
    if (!in_array($role, ['administrator', 'manager'], true)) {
        throw new RuntimeException(bakery_t('daily_orders.skip_day_permission'));
    }
    $id = (int)($user['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException(bakery_t('daily_orders.skip_day_signin'));
    }

    return [
        'id' => $id,
        'role_slug' => $role,
    ];
}

function bakery_daily_order_skip_validate_date(string $date): void
{
    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        throw new RuntimeException(bakery_t('daily_orders.skip_day_date'));
    }
}

function bakery_daily_order_skip_prepare(PDO $db): void
{
    if (function_exists('bakery_ensure_operational_events_schema')) {
        bakery_ensure_operational_events_schema($db);
    }
    if (function_exists('bakery_ensure_actor_identity_schema')) {
        bakery_ensure_actor_identity_schema($db);
    }
    if (!function_exists('bakery_operational_events_ready') || !bakery_operational_events_ready($db)) {
        throw new RuntimeException(bakery_t('daily_orders.skip_day_log'));
    }
}

/**
 * @template T
 * @param callable():T $work
 * @return T
 */
function bakery_daily_order_skip_transaction(PDO $db, callable $work)
{
    bakery_daily_order_skip_prepare($db);
    $db->beginTransaction();
    try {
        $result = $work();
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function bakery_daily_order_skip_meta($raw): ?array
{
    if (is_array($raw)) {
        return $raw;
    }
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function bakery_daily_order_skip_snapshot_has_positive(array $meta): bool
{
    foreach ($meta['lines'] ?? [] as $line) {
        if (is_array($line) && (int)($line['quantity'] ?? 0) > 0) {
            return true;
        }
    }
    return false;
}

function bakery_daily_order_skip_latest(PDO $db, int $orderId): ?array
{
    if ($orderId <= 0 || !bakery_operational_events_ready($db)) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT event_type, metadata
         FROM operational_events
         WHERE daily_order_id = ? AND event_type IN (?, ?)
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([
        $orderId,
        BAKERY_OP_DAILY_ORDER_DAY_SKIPPED,
        BAKERY_OP_DAILY_ORDER_DAY_UNSKIPPED,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (string)$row['event_type'] !== BAKERY_OP_DAILY_ORDER_DAY_SKIPPED) {
        return null;
    }
    $meta = bakery_daily_order_skip_meta($row['metadata'] ?? null);
    if ($meta === null || !isset($meta['lines']) || !is_array($meta['lines'])) {
        return null;
    }
    return $meta;
}

/**
 * @param list<int> $orderIds
 * @return array<int,true>
 */
function bakery_daily_order_skip_undo_ids(PDO $db, array $orderIds): array
{
    $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
    if ($orderIds === [] || !bakery_operational_events_ready($db)) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($orderIds), '?'));
    $sql = "SELECT e.daily_order_id, e.event_type, e.metadata
            FROM operational_events e
            INNER JOIN (
                SELECT daily_order_id, MAX(id) AS max_id
                FROM operational_events
                WHERE daily_order_id IN ($marks)
                  AND event_type IN (?, ?)
                GROUP BY daily_order_id
            ) latest ON latest.max_id = e.id";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge(
        $orderIds,
        [BAKERY_OP_DAILY_ORDER_DAY_SKIPPED, BAKERY_OP_DAILY_ORDER_DAY_UNSKIPPED]
    ));
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((string)$row['event_type'] !== BAKERY_OP_DAILY_ORDER_DAY_SKIPPED) {
            continue;
        }
        $meta = bakery_daily_order_skip_meta($row['metadata'] ?? null);
        if ($meta !== null && bakery_daily_order_skip_snapshot_has_positive($meta)) {
            $out[(int)$row['daily_order_id']] = true;
        }
    }
    return $out;
}

/**
 * @return list<array<string,mixed>>
 */
function bakery_daily_order_unassigned_stops(PDO $db, string $date): array
{
    bakery_daily_order_skip_validate_date($date);
    $origin = function_exists('bakery_sfb_ops_origin_clause')
        ? bakery_sfb_ops_origin_clause('c', $db)
        : '';
    $assignmentSql = '';
    if (function_exists('table_exists') && table_exists($db, 'daily_order_assignments')) {
        $assignmentSql = " AND NOT EXISTS (
                SELECT 1 FROM daily_order_assignments doa
                WHERE doa.daily_order_id = do.id
                  AND doa.delivery_date = do.order_date
                  AND doa.delivery_status <> 'cancelled'
            )";
    }
    $sql = "SELECT do.id AS daily_order_id, do.customer_id, c.name AS customer_name, do.status AS status,
                   COUNT(doi.id) AS line_count,
                   COALESCE(SUM(CASE WHEN doi.quantity > 0 THEN 1 ELSE 0 END), 0) AS positive_lines
            FROM daily_orders do
            JOIN customers c ON c.id = do.customer_id
            LEFT JOIN daily_order_items doi ON doi.daily_order_id = do.id
            WHERE do.order_date = ?
              AND (do.driver_id IS NULL OR do.driver_id = 0)
              {$assignmentSql}
              {$origin}
            GROUP BY do.id, do.customer_id, c.name, do.status
            HAVING positive_lines > 0
            ORDER BY c.name, do.id";
    $stmt = $db->prepare($sql);
    $stmt->execute([$date]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            'daily_order_id' => (int)$row['daily_order_id'],
            'customer_id' => (int)$row['customer_id'],
            'customer_name' => (string)$row['customer_name'],
            'status' => (string)$row['status'],
            'line_count' => (int)$row['line_count'],
            'positive_lines' => (int)$row['positive_lines'],
        ];
    }
    return $rows;
}

/**
 * @return array<string,mixed>|null
 */
function bakery_daily_order_skip_lock(PDO $db, int $orderId, string $date): ?array
{
    $fillReady = function_exists('bakery_daily_order_standing_fill_column_ready')
        && bakery_daily_order_standing_fill_column_ready($db);
    $fillSelect = $fillReady ? 'do.standing_fill_blocked' : '0 AS standing_fill_blocked';
    $stmt = $db->prepare(
        "SELECT do.id, do.customer_id, do.order_date, do.status, do.driver_id, do.total_amount,
                c.name AS customer_name, {$fillSelect}
         FROM daily_orders do
         JOIN customers c ON c.id = do.customer_id
         WHERE do.id = ? AND do.order_date = ?
         FOR UPDATE"
    );
    $stmt->execute([$orderId, $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $lines = $db->prepare(
        'SELECT id, product_id, quantity, unit_price, line_total
         FROM daily_order_items
         WHERE daily_order_id = ?
         ORDER BY id
         FOR UPDATE'
    );
    $lines->execute([$orderId]);
    $row['lines'] = $lines->fetchAll(PDO::FETCH_ASSOC);
    $row['fill_ready'] = $fillReady;
    return $row;
}

function bakery_daily_order_is_unassigned(PDO $db, array $locked, string $date): bool
{
    if ((int)($locked['driver_id'] ?? 0) > 0) {
        return false;
    }
    if (!function_exists('table_exists') || !table_exists($db, 'daily_order_assignments')) {
        return true;
    }
    $stmt = $db->prepare(
        "SELECT 1 FROM daily_order_assignments
         WHERE daily_order_id = ? AND delivery_date = ?
           AND delivery_status <> 'cancelled'
         LIMIT 1"
    );
    $stmt->execute([(int)$locked['id'], $date]);
    return !$stmt->fetchColumn();
}

/**
 * @param list<array<string,mixed>> $lines
 * @return list<array{id:int,product_id:int,quantity:int,line_total:float}>
 */
function bakery_daily_order_skip_public_lines(array $lines, ?int $forceQuantity = null): array
{
    $out = [];
    foreach ($lines as $line) {
        $quantity = $forceQuantity === null ? (int)$line['quantity'] : $forceQuantity;
        $out[] = [
            'id' => (int)$line['id'],
            'product_id' => (int)$line['product_id'],
            'quantity' => $quantity,
            'line_total' => round($quantity * (float)$line['unit_price'], 2),
        ];
    }
    return $out;
}

/**
 * @param array<string,mixed> $locked
 * @param array{id:int,role_slug:string} $staff
 * @return array<string,mixed>
 */
function bakery_daily_order_skip_write(PDO $db, array $locked, array $staff): array
{
    $orderId = (int)$locked['id'];
    $lines = $locked['lines'];
    if ($lines === []) {
        throw new RuntimeException(bakery_t('daily_orders.skip_day_none'));
    }
    $changed = 0;
    foreach ($lines as $line) {
        if ((int)$line['quantity'] !== 0) {
            $changed++;
        }
    }
    $fillReady = !empty($locked['fill_ready']);
    $previousFlag = (int)($locked['standing_fill_blocked'] ?? 0) === 1 ? 1 : 0;
    if ($changed === 0) {
        if (function_exists('bakery_daily_order_set_standing_fill_blocked')) {
            bakery_daily_order_set_standing_fill_blocked($db, $orderId, true, $fillReady);
        }
        $total = bakery_daily_order_recompute_total($db, $orderId);
        $meta = bakery_daily_order_skip_latest($db, $orderId);
        return [
            'success' => true,
            'daily_order_id' => $orderId,
            'customer_id' => (int)$locked['customer_id'],
            'customer_name' => (string)$locked['customer_name'],
            'order_date' => (string)$locked['order_date'],
            'lines' => bakery_daily_order_skip_public_lines($lines, 0),
            'lines_changed' => 0,
            'total_amount' => $total,
            'can_undo' => $meta !== null && bakery_daily_order_skip_snapshot_has_positive($meta),
            'already_zero' => true,
        ];
    }

    $db->prepare('UPDATE daily_order_items SET quantity = 0, line_total = 0 WHERE daily_order_id = ?')
        ->execute([$orderId]);
    foreach ($lines as $line) {
        if ((int)$line['quantity'] === 0) {
            continue;
        }
        bakery_audit_daily_line_diff(
            $db,
            [
                'id' => (int)$line['id'],
                'quantity' => (int)$line['quantity'],
                'unit_price' => $line['unit_price'],
            ],
            [
                'id' => (int)$line['id'],
                'quantity' => 0,
                'unit_price' => $line['unit_price'],
            ]
        );
    }
    if (function_exists('bakery_daily_order_set_standing_fill_blocked')) {
        bakery_daily_order_set_standing_fill_blocked($db, $orderId, true, $fillReady);
    }
    $total = bakery_daily_order_recompute_total($db, $orderId);
    $snapshot = [];
    foreach ($lines as $line) {
        $snapshot[] = [
            'id' => (int)$line['id'],
            'product_id' => (int)$line['product_id'],
            'quantity' => (int)$line['quantity'],
        ];
    }
    $summary = 'Skipped dated order for ' . $locked['customer_name'] . ' on ' . $locked['order_date']
        . '. ' . $changed . ' lines set to 0.';
    $eventId = bakery_record_operational_event($db, BAKERY_OP_DAILY_ORDER_DAY_SKIPPED, $summary, [
        'operational_date' => (string)$locked['order_date'],
        'customer_id' => (int)$locked['customer_id'],
        'daily_order_id' => $orderId,
        'actor_user_id' => $staff['id'],
        'actor_role' => $staff['role_slug'],
        'metadata' => [
            'source' => 'daily_orders_skip',
            'standing_fill_blocked' => $previousFlag,
            'lines' => $snapshot,
        ],
    ]);
    if (!$eventId) {
        throw new RuntimeException(bakery_t('daily_orders.skip_day_log'));
    }

    return [
        'success' => true,
        'daily_order_id' => $orderId,
        'customer_id' => (int)$locked['customer_id'],
        'customer_name' => (string)$locked['customer_name'],
        'order_date' => (string)$locked['order_date'],
        'lines' => bakery_daily_order_skip_public_lines($lines, 0),
        'lines_changed' => $changed,
        'total_amount' => $total,
        'can_undo' => true,
        'already_zero' => false,
    ];
}

/**
 * @param array<string,mixed> $locked
 * @param array{id:int,role_slug:string} $staff
 * @return array<string,mixed>
 */
function bakery_daily_order_undo_skip_write(PDO $db, array $locked, array $staff): array
{
    $orderId = (int)$locked['id'];
    $meta = bakery_daily_order_skip_latest($db, $orderId);
    if ($meta === null || !bakery_daily_order_skip_snapshot_has_positive($meta)) {
        throw new RuntimeException(bakery_t('daily_orders.skip_day_undo_missing'));
    }
    $current = [];
    foreach ($locked['lines'] as $line) {
        $current[(int)$line['id']] = $line;
    }
    $update = $db->prepare(
        'UPDATE daily_order_items SET quantity = ?, line_total = ? WHERE id = ? AND daily_order_id = ?'
    );
    $changed = 0;
    foreach ($meta['lines'] as $snap) {
        if (!is_array($snap)) {
            continue;
        }
        $lineId = (int)($snap['id'] ?? 0);
        if (!isset($current[$lineId])) {
            continue;
        }
        $line = $current[$lineId];
        $quantity = max(0, (int)($snap['quantity'] ?? 0));
        $lineTotal = round($quantity * (float)$line['unit_price'], 2);
        if ((int)$line['quantity'] !== $quantity) {
            $changed++;
            bakery_audit_daily_line_diff(
                $db,
                [
                    'id' => $lineId,
                    'quantity' => (int)$line['quantity'],
                    'unit_price' => $line['unit_price'],
                ],
                [
                    'id' => $lineId,
                    'quantity' => $quantity,
                    'unit_price' => $line['unit_price'],
                ]
            );
        }
        $update->execute([$quantity, $lineTotal, $lineId, $orderId]);
        $current[$lineId]['quantity'] = $quantity;
        $current[$lineId]['line_total'] = $lineTotal;
    }
    $restoreBlocked = (int)($meta['standing_fill_blocked'] ?? 0) === 1;
    if (function_exists('bakery_daily_order_set_standing_fill_blocked')) {
        bakery_daily_order_set_standing_fill_blocked($db, $orderId, $restoreBlocked, !empty($locked['fill_ready']));
    }
    $total = bakery_daily_order_recompute_total($db, $orderId);
    $summary = 'Restored dated order for ' . $locked['customer_name'] . ' on ' . $locked['order_date'] . '.';
    $eventId = bakery_record_operational_event($db, BAKERY_OP_DAILY_ORDER_DAY_UNSKIPPED, $summary, [
        'operational_date' => (string)$locked['order_date'],
        'customer_id' => (int)$locked['customer_id'],
        'daily_order_id' => $orderId,
        'actor_user_id' => $staff['id'],
        'actor_role' => $staff['role_slug'],
        'metadata' => [
            'source' => 'daily_orders_skip',
            'lines_restored' => $changed,
        ],
    ]);
    if (!$eventId) {
        throw new RuntimeException(bakery_t('daily_orders.skip_day_log'));
    }

    return [
        'success' => true,
        'daily_order_id' => $orderId,
        'customer_id' => (int)$locked['customer_id'],
        'customer_name' => (string)$locked['customer_name'],
        'order_date' => (string)$locked['order_date'],
        'lines' => bakery_daily_order_skip_public_lines(array_values($current)),
        'lines_changed' => $changed,
        'total_amount' => $total,
        'can_undo' => false,
        'already_zero' => false,
    ];
}

/**
 * @return array<string,mixed>
 */
function bakery_daily_order_skip(PDO $db, int $orderId, string $date, ?array $user = null): array
{
    $staff = bakery_daily_order_skip_staff($user);
    bakery_daily_order_skip_validate_date($date);
    if ($orderId <= 0) {
        throw new RuntimeException(bakery_t('daily_orders.skip_day_date'));
    }
    return bakery_daily_order_skip_transaction($db, function () use ($db, $orderId, $date, $staff) {
        $locked = bakery_daily_order_skip_lock($db, $orderId, $date);
        if ($locked === null) {
            throw new RuntimeException(bakery_t('daily_orders.skip_day_date'));
        }
        return bakery_daily_order_skip_write($db, $locked, $staff);
    });
}

/**
 * @return array<string,mixed>
 */
function bakery_daily_order_undo_skip(PDO $db, int $orderId, string $date, ?array $user = null): array
{
    $staff = bakery_daily_order_skip_staff($user);
    bakery_daily_order_skip_validate_date($date);
    if ($orderId <= 0) {
        throw new RuntimeException(bakery_t('daily_orders.skip_day_date'));
    }
    return bakery_daily_order_skip_transaction($db, function () use ($db, $orderId, $date, $staff) {
        $locked = bakery_daily_order_skip_lock($db, $orderId, $date);
        if ($locked === null) {
            throw new RuntimeException(bakery_t('daily_orders.skip_day_date'));
        }
        return bakery_daily_order_undo_skip_write($db, $locked, $staff);
    });
}

/**
 * @param list<int|string> $orderIds
 * @return array<string,mixed>
 */
function bakery_daily_order_skip_unassigned(PDO $db, string $date, array $orderIds, ?array $user = null): array
{
    $staff = bakery_daily_order_skip_staff($user);
    bakery_daily_order_skip_validate_date($date);
    $ids = [];
    foreach ($orderIds as $orderId) {
        $orderId = (int)$orderId;
        if ($orderId > 0) {
            $ids[$orderId] = $orderId;
        }
    }
    $ids = array_values($ids);
    sort($ids);

    return bakery_daily_order_skip_transaction($db, function () use ($db, $date, $ids, $staff) {
        $targets = $ids;
        if ($targets === []) {
            foreach (bakery_daily_order_unassigned_stops($db, $date) as $row) {
                $targets[] = (int)$row['daily_order_id'];
            }
        }
        $skipped = [];
        $left = [];
        foreach ($targets as $orderId) {
            $locked = bakery_daily_order_skip_lock($db, $orderId, $date);
            if ($locked === null) {
                throw new RuntimeException(bakery_t('daily_orders.skip_unassigned_missing'));
            }
            if (!bakery_daily_order_is_unassigned($db, $locked, $date)) {
                $left[] = [
                    'daily_order_id' => $orderId,
                    'customer_name' => (string)$locked['customer_name'],
                ];
                continue;
            }
            $skipped[] = bakery_daily_order_skip_write($db, $locked, $staff);
        }
        return [
            'success' => true,
            'date' => $date,
            'skipped' => $skipped,
            'skipped_count' => count($skipped),
            'left_assigned' => $left,
        ];
    });
}

/**
 * @param mixed $raw
 * @return list<int>
 */
function bakery_daily_order_skip_id_list($raw): array
{
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $parts = preg_split('/\s*,\s*/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            $parts = [];
        }
    }
    $ids = [];
    foreach ($parts as $part) {
        $id = (int)$part;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    $ids = array_values($ids);
    sort($ids);
    return $ids;
}
