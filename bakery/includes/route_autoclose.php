<?php
/**
 * Finish past routes whose stops are all terminal, and record an implicit
 * pickup when nobody saved one.
 *
 * Writers: nightly scheduler, the self-heal POST, and an explicit Finish POST.
 * A GET must not call bakery_route_autoclose_past or bakery_route_finish_nothing_left.
 *
 * An implicit load does not touch product_inventory_days or inventory_movements.
 * A manager load is never replaced. Reopen of an implicit load does not reverse stock.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/product_inventory.php';
require_once __DIR__ . '/operational_timeline.php';

/**
 * @return list<string>
 */
function bakery_route_autoclose_terminal_statuses(): array
{
    return ['delivered', 'failed', 'cancelled'];
}

function bakery_route_autoclose_today(?DateTimeImmutable $now = null): string
{
    $zone = new DateTimeZone('America/Los_Angeles');
    $now = $now ?? new DateTimeImmutable('now', $zone);
    return $now->setTimezone($zone)->format('Y-m-d');
}

function bakery_route_autoclose_has_source_columns(PDO $db): bool
{
    return function_exists('column_exists')
        && column_exists($db, 'driver_loads', 'load_source')
        && column_exists($db, 'driver_loads', 'closeout_source');
}

function bakery_route_autoclose_day_is_closed(PDO $db, string $date): bool
{
    if (!function_exists('table_exists') || !table_exists($db, 'operating_day_closeouts')) {
        return false;
    }
    $stmt = $db->prepare(
        'SELECT closed_at, reopened_at FROM operating_day_closeouts WHERE operating_date = ? LIMIT 1'
    );
    $stmt->execute([$date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    return !empty($row['closed_at']) && empty($row['reopened_at']);
}

/**
 * Gross delivered units (net delivered plus door credits) so loaded = delivered + credits
 * when returns and waste are 0.
 *
 * @return array<int, int>
 */
function bakery_route_autoclose_gross_delivered(PDO $db, string $date, int $driverId): array
{
    $net = bakery_inventory_driver_delivered_by_product($db, $date, $driverId);
    $credits = bakery_inventory_driver_credits_by_product($db, $date, $driverId);
    $out = [];
    foreach (array_unique(array_merge(array_keys($net), array_keys($credits))) as $productId) {
        $productId = (int)$productId;
        $qty = (int)($net[$productId] ?? 0) + (int)($credits[$productId] ?? 0);
        if ($productId > 0 && $qty > 0) {
            $out[$productId] = $qty;
        }
    }
    return $out;
}

/**
 * @return array<int, int>
 */
function bakery_route_autoclose_loaded_map(PDO $db, int $loadId): array
{
    $stmt = $db->prepare(
        'SELECT product_id, loaded_quantity, returned_quantity, wasted_quantity
         FROM driver_load_items WHERE driver_load_id = ?'
    );
    $stmt->execute([$loadId]);
    $loaded = [];
    $returns = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $loaded[(int)$row['product_id']] = (int)$row['loaded_quantity'];
        $returns += (int)$row['returned_quantity'] + (int)$row['wasted_quantity'];
    }
    $loaded['_returns'] = $returns;
    return $loaded;
}

/**
 * @param array<int, int> $quantities
 */
function bakery_route_autoclose_replace_items(PDO $db, int $loadId, array $quantities): void
{
    $db->prepare('DELETE FROM driver_load_items WHERE driver_load_id = ?')->execute([$loadId]);
    $hasWaste = function_exists('column_exists') && column_exists($db, 'driver_load_items', 'wasted_quantity');
    $sql = $hasWaste
        ? 'INSERT INTO driver_load_items (driver_load_id, product_id, loaded_quantity, returned_quantity, wasted_quantity) VALUES (?, ?, ?, 0, 0)'
        : 'INSERT INTO driver_load_items (driver_load_id, product_id, loaded_quantity, returned_quantity) VALUES (?, ?, ?, 0)';
    $insert = $db->prepare($sql);
    foreach ($quantities as $productId => $qty) {
        $productId = (int)$productId;
        $qty = (int)$qty;
        if ($productId <= 0 || $qty <= 0) {
            continue;
        }
        $insert->execute([$loadId, $productId, $qty]);
    }
}

function bakery_route_autoclose_insert_load(PDO $db, string $date, int $driverId, string $notes): int
{
    if (bakery_route_autoclose_has_source_columns($db)) {
        $stmt = $db->prepare(
            'INSERT INTO driver_loads (driver_id, delivery_date, status, notes, created_by_user_id, load_source)
             VALUES (?, ?, \'loaded\', ?, NULL, \'implicit\')'
        );
        $stmt->execute([$driverId, $date, $notes]);
    } else {
        $stmt = $db->prepare(
            'INSERT INTO driver_loads (driver_id, delivery_date, status, notes, created_by_user_id)
             VALUES (?, ?, \'loaded\', ?, NULL)'
        );
        $stmt->execute([$driverId, $date, $notes . ' load_source=implicit']);
    }
    return (int)$db->lastInsertId();
}

function bakery_route_autoclose_mark_reconciled(PDO $db, int $loadId, string $closeoutSource, ?string $notes): void
{
    $hasAt = function_exists('column_exists') && column_exists($db, 'driver_loads', 'reconciled_at');
    $hasSource = bakery_route_autoclose_has_source_columns($db);
    if ($hasAt && $hasSource) {
        $db->prepare(
            'UPDATE driver_loads
             SET status = \'reconciled\', closeout_source = ?, notes = COALESCE(?, notes),
                 reconciled_at = CURRENT_TIMESTAMP, reconciled_by_user_id = NULL
             WHERE id = ?'
        )->execute([$closeoutSource, $notes, $loadId]);
        return;
    }
    if ($hasAt) {
        $db->prepare(
            'UPDATE driver_loads
             SET status = \'reconciled\', notes = COALESCE(?, notes),
                 reconciled_at = CURRENT_TIMESTAMP, reconciled_by_user_id = NULL
             WHERE id = ?'
        )->execute([$notes, $loadId]);
        return;
    }
    $db->prepare(
        'UPDATE driver_loads SET status = \'reconciled\', notes = COALESCE(?, notes) WHERE id = ?'
    )->execute([$notes, $loadId]);
}

function bakery_route_autoclose_set_closeout_source(PDO $db, int $loadId, string $closeoutSource): void
{
    if (!bakery_route_autoclose_has_source_columns($db)) {
        return;
    }
    $db->prepare('UPDATE driver_loads SET closeout_source = ? WHERE id = ?')
        ->execute([$closeoutSource, $loadId]);
}

/**
 * @param array<string, int|string> $metadata
 */
function bakery_route_autoclose_event(PDO $db, string $date, int $driverId, string $summary, array $metadata): void
{
    if (!function_exists('bakery_record_operational_event')) {
        return;
    }
    $eventType = defined('BAKERY_OP_DRIVER_ROUTE_CLOSED')
        ? BAKERY_OP_DRIVER_ROUTE_CLOSED
        : 'driver_route_closed';
    bakery_record_operational_event($db, $eventType, $summary, [
        'operational_date' => $date,
        'driver_id' => $driverId,
        'metadata' => $metadata,
    ]);
}

/**
 * @return array{id:int,status:string,load_source:string}|null
 */
function bakery_route_autoclose_load_row(PDO $db, string $date, int $driverId): ?array
{
    $source = bakery_route_autoclose_has_source_columns($db) ? ', load_source, closeout_source' : '';
    $stmt = $db->prepare(
        'SELECT id, status' . $source . ' FROM driver_loads WHERE driver_id = ? AND delivery_date = ? LIMIT 1'
    );
    $stmt->execute([$driverId, $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $row['id'] = (int)$row['id'];
    $row['status'] = (string)$row['status'];
    $row['load_source'] = (string)($row['load_source'] ?? 'manager');
    $row['closeout_source'] = (string)($row['closeout_source'] ?? '');
    return $row;
}

/**
 * Record delivered units as the pickup. Does not reserve finished goods.
 *
 * @return int load id
 */
function bakery_route_implicit_load_record(PDO $db, string $date, int $driverId, array $quantities, string $notes): int
{
    $existing = bakery_route_autoclose_load_row($db, $date, $driverId);
    if ($existing) {
        throw new RuntimeException('A pickup load already exists for this driver on this date.');
    }
    $own = !$db->inTransaction();
    if ($own) {
        $db->beginTransaction();
    }
    try {
        $loadId = bakery_route_autoclose_insert_load($db, $date, $driverId, $notes);
        bakery_route_autoclose_replace_items($db, $loadId, $quantities);
        if ($own) {
            $db->commit();
        }
        return $loadId;
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * @param array<int, int> $left
 * @param array<int, int> $right
 */
function bakery_route_autoclose_maps_equal(array $left, array $right): bool
{
    ksort($left);
    ksort($right);
    return $left === $right;
}

/**
 * Close one driver/date when every stop is terminal.
 *
 * @return string closed|left_open|unchanged|skipped_closed_day
 */
function bakery_route_autoclose_one(PDO $db, string $date, int $driverId, string $closeoutSource): string
{
    if (bakery_route_autoclose_day_is_closed($db, $date)) {
        return 'skipped_closed_day';
    }
    $statuses = bakery_route_autoclose_terminal_statuses();
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $openStmt = $db->prepare(
        "SELECT COUNT(*) FROM daily_order_assignments
         WHERE driver_id = ? AND delivery_date = ?
           AND delivery_status NOT IN ($placeholders)"
    );
    $openStmt->execute(array_merge([$driverId, $date], $statuses));
    if ((int)$openStmt->fetchColumn() > 0) {
        return 'left_open';
    }
    $countStmt = $db->prepare(
        'SELECT COUNT(*) FROM daily_order_assignments WHERE driver_id = ? AND delivery_date = ?'
    );
    $countStmt->execute([$driverId, $date]);
    if ((int)$countStmt->fetchColumn() <= 0) {
        return 'unchanged';
    }

    $load = bakery_route_autoclose_load_row($db, $date, $driverId);
    if ($load && $load['status'] === 'reconciled') {
        return 'unchanged';
    }

    $gross = bakery_route_autoclose_gross_delivered($db, $date, $driverId);
    $implicitNote = 'Implicit load. Finished goods were not reserved.';

    if (!$load) {
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            $loadId = bakery_route_autoclose_insert_load($db, $date, $driverId, $implicitNote);
            bakery_route_autoclose_replace_items($db, $loadId, $gross);
            bakery_route_autoclose_mark_reconciled($db, $loadId, $closeoutSource, null);
            bakery_route_autoclose_event($db, $date, $driverId, 'Closed route for driver #' . $driverId, [
                'closeout_source' => $closeoutSource,
                'load_source' => 'implicit',
                'returned_units' => 0,
                'wasted_units' => 0,
                'stock_reserved' => false,
            ]);
            if ($own) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return 'closed';
    }

    if ($load['load_source'] === 'implicit') {
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            bakery_route_autoclose_replace_items($db, $load['id'], $gross);
            bakery_route_autoclose_mark_reconciled($db, $load['id'], $closeoutSource, null);
            bakery_route_autoclose_event($db, $date, $driverId, 'Closed route for driver #' . $driverId, [
                'closeout_source' => $closeoutSource,
                'load_source' => 'implicit',
                'stock_reserved' => false,
            ]);
            if ($own) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return 'closed';
    }

    $loaded = bakery_route_autoclose_loaded_map($db, $load['id']);
    $returns = (int)($loaded['_returns'] ?? 0);
    unset($loaded['_returns']);
    if ($returns > 0 || !bakery_route_autoclose_maps_equal($loaded, $gross)) {
        return 'left_open';
    }

    bakery_inventory_reconcile_driver_load($db, $date, $driverId, [], null);
    bakery_route_autoclose_set_closeout_source($db, $load['id'], $closeoutSource);
    return 'closed';
}

/**
 * Past routes only. $onlyDate limits the scan to one delivery date (tests).
 * A date that is today or later is ignored.
 *
 * @return array{closed:int,implicit:int,left_open:int,skipped_closed_day:int,unchanged:int}
 */
function bakery_route_autoclose_past(PDO $db, ?DateTimeImmutable $now = null, ?string $onlyDate = null): array
{
    $counts = [
        'closed' => 0,
        'implicit' => 0,
        'left_open' => 0,
        'skipped_closed_day' => 0,
        'unchanged' => 0,
    ];
    if (!bakery_inventory_ready($db)) {
        return $counts;
    }
    $today = bakery_route_autoclose_today($now);
    if ($onlyDate !== null) {
        $onlyDate = bakery_inventory_validate_date($onlyDate);
        if ($onlyDate >= $today) {
            return $counts;
        }
    }
    $statuses = bakery_route_autoclose_terminal_statuses();
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $sql = "SELECT doa.driver_id, doa.delivery_date,
                   SUM(CASE WHEN doa.delivery_status IN ($placeholders) THEN 0 ELSE 1 END) AS open_stops
            FROM daily_order_assignments doa
            LEFT JOIN driver_loads dl
              ON dl.driver_id = doa.driver_id AND dl.delivery_date = doa.delivery_date
            WHERE doa.delivery_date < ?
              AND (dl.id IS NULL OR dl.status <> 'reconciled')";
    $params = array_merge($statuses, [$today]);
    if ($onlyDate !== null) {
        $sql .= ' AND doa.delivery_date = ?';
        $params[] = $onlyDate;
    }
    $sql .= ' GROUP BY doa.driver_id, doa.delivery_date';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $pairs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($pairs as $pair) {
        $date = (string)$pair['delivery_date'];
        $driverId = (int)$pair['driver_id'];
        if ($driverId <= 0) {
            continue;
        }
        if ((int)$pair['open_stops'] > 0) {
            if (bakery_route_autoclose_day_is_closed($db, $date)) {
                $counts['skipped_closed_day']++;
            } else {
                $counts['left_open']++;
            }
            continue;
        }
        $before = bakery_route_autoclose_load_row($db, $date, $driverId);
        try {
            $result = bakery_route_autoclose_one($db, $date, $driverId, 'auto');
        } catch (Throwable $e) {
            error_log('route autoclose ' . $date . ' driver ' . $driverId . ': ' . $e->getMessage());
            $counts['left_open']++;
            continue;
        }
        if (!isset($counts[$result])) {
            $counts['left_open']++;
            continue;
        }
        $counts[$result]++;
        if ($result === 'closed' && (!$before || $before['load_source'] === 'implicit')) {
            $counts['implicit']++;
        }
    }
    return $counts;
}

/**
 * Driver Finish: nothing left in the car. Idempotent when the route is already closed.
 * Allowed on any date. Does not run from a GET.
 *
 * @return array{ok:bool,idempotent:bool,closeout_source:string,load_source:string}
 */
function bakery_route_finish_nothing_left(PDO $db, string $date, int $driverId): array
{
    $date = bakery_inventory_validate_date($date);
    if ($driverId <= 0) {
        throw new InvalidArgumentException('Choose a driver.');
    }
    $load = bakery_route_autoclose_load_row($db, $date, $driverId);
    if ($load && $load['status'] === 'reconciled') {
        return [
            'ok' => true,
            'idempotent' => true,
            'closeout_source' => $load['closeout_source'],
            'load_source' => $load['load_source'],
        ];
    }
    $result = bakery_route_autoclose_one($db, $date, $driverId, 'driver');
    if ($result === 'left_open') {
        $statuses = bakery_route_autoclose_terminal_statuses();
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $openStmt = $db->prepare(
            "SELECT COUNT(*) FROM daily_order_assignments
             WHERE driver_id = ? AND delivery_date = ?
               AND delivery_status NOT IN ($placeholders)"
        );
        $openStmt->execute(array_merge([$driverId, $date], $statuses));
        if ((int)$openStmt->fetchColumn() > 0) {
            throw new RuntimeException('Finish or resolve open stops before closing this route.');
        }
        throw new RuntimeException('Could not close this route. Enter returns and waste on Route Closeout.');
    }
    if ($result === 'skipped_closed_day') {
        throw new RuntimeException('This day is already closed.');
    }
    $after = bakery_route_autoclose_load_row($db, $date, $driverId);
    if (!$after || $after['status'] !== 'reconciled') {
        throw new RuntimeException('Could not close this route. Enter returns and waste on Route Closeout.');
    }
    return [
        'ok' => true,
        'idempotent' => false,
        'closeout_source' => $after['closeout_source'] !== '' ? $after['closeout_source'] : 'driver',
        'load_source' => $after['load_source'],
    ];
}

/**
 * Read-only counts for the driver Finish screen.
 *
 * @return array{stop_count:int,all_terminal:bool,loaded_units:int,delivered_units:int,reconciled:bool,load_source:string,closeout_source:string}
 */
function bakery_route_finish_snapshot(PDO $db, string $date, int $driverId): array
{
    $empty = [
        'stop_count' => 0,
        'all_terminal' => false,
        'loaded_units' => 0,
        'delivered_units' => 0,
        'reconciled' => false,
        'load_source' => '',
        'closeout_source' => '',
    ];
    if ($driverId <= 0 || !bakery_inventory_ready($db)) {
        return $empty;
    }
    try {
        $date = bakery_inventory_validate_date($date);
    } catch (Throwable $e) {
        return $empty;
    }
    $statuses = bakery_route_autoclose_terminal_statuses();
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS stop_count,
                SUM(CASE WHEN delivery_status IN ($placeholders) THEN 0 ELSE 1 END) AS open_stops
         FROM daily_order_assignments
         WHERE driver_id = ? AND delivery_date = ?"
    );
    $stmt->execute(array_merge($statuses, [$driverId, $date]));
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stopCount = (int)($row['stop_count'] ?? 0);
    $gross = bakery_route_autoclose_gross_delivered($db, $date, $driverId);
    $delivered = 0;
    foreach ($gross as $qty) {
        $delivered += (int)$qty;
    }
    $load = bakery_route_autoclose_load_row($db, $date, $driverId);
    $loadedUnits = 0;
    if ($load) {
        $sum = $db->prepare('SELECT COALESCE(SUM(loaded_quantity), 0) FROM driver_load_items WHERE driver_load_id = ?');
        $sum->execute([$load['id']]);
        $loadedUnits = (int)$sum->fetchColumn();
    }
    return [
        'stop_count' => $stopCount,
        'all_terminal' => $stopCount > 0 && (int)($row['open_stops'] ?? 0) === 0,
        'loaded_units' => $loadedUnits,
        'delivered_units' => $delivered,
        'reconciled' => $load !== null && $load['status'] === 'reconciled',
        'load_source' => $load['load_source'] ?? '',
        'closeout_source' => $load['closeout_source'] ?? '',
    ];
}
