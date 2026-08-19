<?php
/**
 * Canonical driver assignment mutations — shared by Driver Assignment and Daily Run.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/operational_timeline.php';
require_once __DIR__ . '/sfb_origin.php';
require_once __DIR__ . '/daily_order_generation.php';

/**
 * Reject malformed delivery dates before they reach a query or strtotime().
 */
function bakery_driver_validate_delivery_date(string $deliveryDate): string
{
    $date = DateTime::createFromFormat('!Y-m-d', $deliveryDate);
    if (!$date || $date->format('Y-m-d') !== $deliveryDate) {
        throw new RuntimeException('Invalid delivery date');
    }
    return $deliveryDate;
}

/**
 * Assign one or more orders to a driver (append or replace mode).
 *
 * @param list<array{daily_order_id:int, route_order?:int, scheduled_delivery_time?:?string}> $assignments
 * @return array{stop_count:int, mode:string, driver_name:string}
 */
function bakery_driver_assign_orders(
    PDO $db,
    int $driverId,
    string $deliveryDate,
    array $assignments,
    string $mode = 'replace'
): array {
    bakery_require_role(['administrator', 'manager']);
    $deliveryDate = bakery_driver_validate_delivery_date($deliveryDate);

    if (!in_array($mode, ['replace', 'append'], true)) {
        $mode = 'replace';
    }
    if ($mode === 'append' && $assignments === []) {
        throw new RuntimeException('No assignments provided');
    }

    $driverRow = bakery_get_driver_by_id($db, $driverId);
    if (!$driverRow) {
        throw new RuntimeException("Driver ID {$driverId} does not exist");
    }
    if ((int)($driverRow['archived'] ?? 0) === 1) {
        throw new RuntimeException('Cannot assign orders to an archived driver');
    }

    $normalized = [];
    $seenOrderIds = [];
    foreach ($assignments as $assignment) {
        $dailyOrderId = (int)($assignment['daily_order_id'] ?? 0);
        if ($dailyOrderId <= 0) {
            continue;
        }
        if (isset($seenOrderIds[$dailyOrderId])) {
            throw new RuntimeException("Order {$dailyOrderId} was submitted more than once");
        }
        $seenOrderIds[$dailyOrderId] = true;
        $routeOrder = (int)($assignment['route_order'] ?? 0);
        $normalized[] = [
            'daily_order_id' => $dailyOrderId,
            'route_order' => $routeOrder > 0 ? $routeOrder : count($normalized) + 1,
            'scheduled_delivery_time' => ($assignment['scheduled_delivery_time'] ?? null) ?: null,
            'input_order' => count($normalized),
        ];
    }

    if ($mode === 'replace' && count($normalized) > 1) {
        usort($normalized, static function (array $a, array $b): int {
            $routeCompare = $a['route_order'] <=> $b['route_order'];
            return $routeCompare !== 0 ? $routeCompare : ($a['input_order'] <=> $b['input_order']);
        });
        foreach ($normalized as $index => &$assignment) {
            $assignment['route_order'] = $index + 1;
        }
        unset($assignment);
    }

    if ($mode === 'append' && $normalized === []) {
        throw new RuntimeException('No valid orders to assign');
    }

    if ($normalized !== []) {
        $orderIds = array_column($normalized, 'daily_order_id');
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $orderStmt = $db->prepare("SELECT id, order_date FROM daily_orders WHERE id IN ($placeholders)");
        $orderStmt->execute($orderIds);
        $validOrderIds = [];
        foreach ($orderStmt->fetchAll(PDO::FETCH_ASSOC) as $orderRow) {
            if ((string)$orderRow['order_date'] !== $deliveryDate) {
                throw new RuntimeException('Every assigned order must belong to the selected delivery date');
            }
            $validOrderIds[(int)$orderRow['id']] = true;
        }
        if (count($validOrderIds) !== count($normalized)) {
            throw new RuntimeException('One or more daily orders do not exist');
        }
    }

    $db->beginTransaction();
    try {
        $insertStmt = $db->prepare('
            INSERT INTO daily_order_assignments (
                daily_order_id, driver_id, delivery_date, route_order,
                scheduled_delivery_time, delivery_status
            ) VALUES (?, ?, ?, ?, ?, \'pending\')
        ');

        $targetExistingStmt = $db->prepare('
            SELECT id, daily_order_id, delivery_status
            FROM daily_order_assignments
            WHERE driver_id = ? AND delivery_date = ?
            FOR UPDATE
        ');
        $targetExistingStmt->execute([$driverId, $deliveryDate]);
        $targetExisting = $targetExistingStmt->fetchAll(PDO::FETCH_ASSOC);
        $targetExistingByOrder = [];
        foreach ($targetExisting as $row) {
            $targetExistingByOrder[(int)$row['daily_order_id']] = $row;
        }

        $submittedIds = array_column($normalized, 'daily_order_id');
        $submittedLookup = array_fill_keys($submittedIds, true);
        $lockedStatuses = ['delivered', 'in_transit'];
        if ($mode === 'replace') {
            foreach ($targetExistingByOrder as $orderId => $row) {
                if (!isset($submittedLookup[$orderId]) && in_array((string)$row['delivery_status'], $lockedStatuses, true)) {
                    throw new RuntimeException('Completed or in-transit stops cannot be removed from a route');
                }
            }
        }

        $sourceDriverIds = [];
        if ($submittedIds !== []) {
            $placeholders = implode(',', array_fill(0, count($submittedIds), '?'));
            $otherStmt = $db->prepare("
                SELECT id, daily_order_id, driver_id, delivery_status
                FROM daily_order_assignments
                WHERE daily_order_id IN ($placeholders) AND delivery_date = ? AND driver_id <> ?
                FOR UPDATE
            ");
            $otherStmt->execute([...$submittedIds, $deliveryDate, $driverId]);
            $otherAssignments = $otherStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($otherAssignments as $row) {
                if (in_array((string)$row['delivery_status'], $lockedStatuses, true)) {
                    throw new RuntimeException('Completed or in-transit stops must stay with their current driver');
                }
                $sourceDriverIds[(int)$row['driver_id']] = true;
            }
            if ($otherAssignments !== []) {
                $otherIds = array_map('intval', array_column($otherAssignments, 'id'));
                $deletePlaceholders = implode(',', array_fill(0, count($otherIds), '?'));
                $db->prepare("DELETE FROM daily_order_assignments WHERE id IN ($deletePlaceholders)")
                    ->execute($otherIds);
            }
        }

        $updateLegacyDriverStmt = $db->prepare('UPDATE daily_orders SET driver_id = ? WHERE id = ?');

        if ($mode === 'append') {
            $maxStmt = $db->prepare('
                SELECT COALESCE(MAX(route_order), 0)
                FROM daily_order_assignments
                WHERE driver_id = ? AND delivery_date = ?
            ');
            $maxStmt->execute([$driverId, $deliveryDate]);
            $nextRouteOrder = (int)$maxStmt->fetchColumn() + 1;

            $added = 0;
            foreach ($normalized as $assignment) {
                $dailyOrderId = (int)$assignment['daily_order_id'];
                if (isset($targetExistingByOrder[$dailyOrderId])) {
                    continue;
                }
                $insertStmt->execute([
                    $dailyOrderId,
                    $driverId,
                    $deliveryDate,
                    $nextRouteOrder,
                    $assignment['scheduled_delivery_time'] ?? null,
                ]);
                $updateLegacyDriverStmt->execute([$driverId, $dailyOrderId]);
                $nextRouteOrder++;
                $added++;
            }
            $stopCount = $added;
        } else {
            // Free the positive route positions before applying a reorder.
            // Migration 047 enforces one position per driver/date, so swaps
            // must be written in two phases inside this transaction.
            $db->prepare('
                UPDATE daily_order_assignments
                SET route_order = -id
                WHERE driver_id = ? AND delivery_date = ?
            ')->execute([$driverId, $deliveryDate]);

            $removedOrderIds = [];
            foreach ($targetExistingByOrder as $orderId => $row) {
                if (!isset($submittedLookup[$orderId])) {
                    $removedOrderIds[] = $orderId;
                    $db->prepare('DELETE FROM daily_order_assignments WHERE id = ?')->execute([(int)$row['id']]);
                }
            }

            $stopCount = 0;
            $updateExistingStmt = $db->prepare('
                UPDATE daily_order_assignments
                SET route_order = ?, scheduled_delivery_time = ?
                WHERE id = ?
            ');
            foreach ($normalized as $assignment) {
                $dailyOrderId = (int)$assignment['daily_order_id'];
                if (isset($targetExistingByOrder[$dailyOrderId])) {
                    $updateExistingStmt->execute([
                        (int)$assignment['route_order'],
                        $assignment['scheduled_delivery_time'],
                        (int)$targetExistingByOrder[$dailyOrderId]['id'],
                    ]);
                } else {
                    $insertStmt->execute([
                        $dailyOrderId,
                        $driverId,
                        $deliveryDate,
                        (int)$assignment['route_order'],
                        $assignment['scheduled_delivery_time'],
                    ]);
                }
                $updateLegacyDriverStmt->execute([$driverId, $dailyOrderId]);
                $stopCount++;
            }

            if ($removedOrderIds !== []) {
                $clearLegacyStmt = $db->prepare('
                    UPDATE daily_orders do
                    SET do.driver_id = NULL
                    WHERE do.id = ?
                      AND NOT EXISTS (
                          SELECT 1 FROM daily_order_assignments doa
                          WHERE doa.daily_order_id = do.id AND doa.delivery_date = ?
                      )
                ');
                foreach ($removedOrderIds as $orderId) {
                    $clearLegacyStmt->execute([$orderId, $deliveryDate]);
                }
            }
        }

        bakery_driver_renumber_route_orders($db, array_keys($sourceDriverIds), $deliveryDate);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    $driverName = $driverRow['name'] ?? ('Driver #' . $driverId);
    if (function_exists('bakery_record_operational_event') && defined('BAKERY_OP_DRIVER_ROUTE_ASSIGNED')) {
        bakery_record_operational_event(
            $db,
            BAKERY_OP_DRIVER_ROUTE_ASSIGNED,
            ($mode === 'append' ? 'Added stops to ' : 'Assigned route for ') . $driverName
                . ' on ' . date('M j, Y', strtotime($deliveryDate)),
            [
                'operational_date' => $deliveryDate,
                'driver_id' => $driverId,
                'metadata' => [
                    'mode' => $mode,
                    'stop_count' => $stopCount,
                    'source' => 'canonical_assign',
                ],
            ]
        );
    }

    return [
        'stop_count' => $stopCount,
        'mode' => $mode,
        'driver_name' => $driverName,
    ];
}

/**
 * Remove one movable stop from a dated route and close the numbering gap.
 */
function bakery_driver_remove_assignment(
    PDO $db,
    int $dailyOrderId,
    int $driverId,
    string $deliveryDate
): bool {
    $deliveryDate = bakery_driver_assert_route_plan_edit($db, $driverId, $deliveryDate);
    if ($dailyOrderId <= 0 || $driverId <= 0) {
        throw new RuntimeException('Invalid route stop');
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare('
            SELECT id, delivery_status
            FROM daily_order_assignments
            WHERE daily_order_id = ? AND driver_id = ? AND delivery_date = ?
            FOR UPDATE
        ');
        $stmt->execute([$dailyOrderId, $driverId, $deliveryDate]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$assignment) {
            throw new RuntimeException('Route stop not found');
        }
        if (in_array((string)$assignment['delivery_status'], ['delivered', 'in_transit'], true)) {
            throw new RuntimeException('Completed or in-transit stops cannot be removed');
        }

        $db->prepare('DELETE FROM daily_order_assignments WHERE id = ?')->execute([(int)$assignment['id']]);
        bakery_driver_renumber_route_orders($db, [$driverId], $deliveryDate);
        $db->prepare('
            UPDATE daily_orders do
            SET do.driver_id = NULL
            WHERE do.id = ?
              AND NOT EXISTS (
                  SELECT 1 FROM daily_order_assignments doa
                  WHERE doa.daily_order_id = do.id AND doa.delivery_date = ?
              )
        ')->execute([$dailyOrderId, $deliveryDate]);
        $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Build dated route from standing routes — ensures daily orders exist and assigns drivers.
 *
 * @return array{stop_count:int, assignments:list<array<string,mixed>>}
 */
function bakery_driver_assign_from_standing_routes(PDO $db, string $deliveryDate): array
{
    bakery_require_role(['administrator', 'manager']);
    $deliveryDate = bakery_driver_validate_delivery_date($deliveryDate);

    if (!table_exists($db, 'standing_routes')) {
        throw new RuntimeException('Standing routes are not configured');
    }

    $progressStmt = $db->prepare(
        "SELECT COUNT(*)
         FROM daily_order_assignments
         WHERE delivery_date = ?
           AND COALESCE(delivery_status, 'pending') <> 'pending'"
    );
    $progressStmt->execute([$deliveryDate]);
    if ((int)$progressStmt->fetchColumn() > 0) {
        throw new RuntimeException(
            bakery_t('driver_assignment.error_progressed_route')
        );
    }

    $dayOfWeek = date('N', strtotime($deliveryDate));
    $stmt = $db->prepare(
        'SELECT sr.customer_id, sr.driver_id, c.name AS customer_name, sr.route_order
         FROM standing_routes sr
         JOIN customers c ON c.id = sr.customer_id AND c.is_active = 1
         ' . bakery_sfb_ops_origin_clause('c', $db) . '
         WHERE CASE WHEN sr.day_of_week = 0 THEN 7 ELSE sr.day_of_week END = ?
         ORDER BY sr.driver_id, COALESCE(sr.route_order, 2147483647), c.name'
    );
    $stmt->execute([$dayOfWeek]);
    $standingRoutes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($standingRoutes === []) {
        throw new RuntimeException(
            'No standing-route stops are configured for ' . date('l', strtotime($deliveryDate)) . '.'
        );
    }

    // Demand is the source of truth for what is being delivered. Prepare it
    // before route construction, without assigning routes from the generator.
    // This also fills orders left empty by older route-first behavior while
    // preserving every dated quantity edit.
    $demandResult = bakery_generate_daily_orders_from_standing($db, $deliveryDate, [
        'overwrite_changed' => false,
        'record_event' => true,
        'assign_routes' => false,
    ]);

    $db->beginTransaction();
    try {
        $assignments = [];
        $routeOrderByDriver = [];

        foreach ($standingRoutes as $route) {
            $customerId = (int)$route['customer_id'];
            $orderStmt = $db->prepare('SELECT id FROM daily_orders WHERE customer_id = ? AND order_date = ?');
            $orderStmt->execute([$customerId, $deliveryDate]);
            $existingOrder = $orderStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingOrder) {
                $dailyOrderId = (int)$existingOrder['id'];
            } else {
                $ins = $db->prepare('INSERT INTO daily_orders (customer_id, order_date, total_amount) VALUES (?, ?, 0)');
                $ins->execute([$customerId, $deliveryDate]);
                $dailyOrderId = (int)$db->lastInsertId();
            }

            $assignedDriverId = (int)$route['driver_id'];
            $routeOrderByDriver[$assignedDriverId] = ($routeOrderByDriver[$assignedDriverId] ?? 0) + 1;
            $assignments[] = [
                'daily_order_id' => $dailyOrderId,
                'driver_id' => $assignedDriverId,
                'route_order' => $routeOrderByDriver[$assignedDriverId],
                'scheduled_delivery_time' => null,
            ];
        }

        $db->prepare('DELETE FROM daily_order_assignments WHERE delivery_date = ?')->execute([$deliveryDate]);
        $db->prepare('UPDATE daily_orders SET driver_id = NULL WHERE order_date = ?')->execute([$deliveryDate]);

        $insertAssignment = $db->prepare('
            INSERT INTO daily_order_assignments (
                daily_order_id, driver_id, delivery_date, route_order,
                scheduled_delivery_time, delivery_status
            ) VALUES (?, ?, ?, ?, ?, \'pending\')
        ');
        foreach ($assignments as $assignment) {
            $insertAssignment->execute([
                $assignment['daily_order_id'],
                $assignment['driver_id'],
                $deliveryDate,
                $assignment['route_order'],
                $assignment['scheduled_delivery_time'],
            ]);
            $db->prepare('UPDATE daily_orders SET driver_id = ? WHERE id = ?')
                ->execute([$assignment['driver_id'], $assignment['daily_order_id']]);
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    if (function_exists('bakery_record_operational_event') && defined('BAKERY_OP_DRIVER_ROUTE_ASSIGNED')) {
        bakery_record_operational_event(
            $db,
            BAKERY_OP_DRIVER_ROUTE_ASSIGNED,
            'Built ' . count($assignments) . ' route stops from standing route for '
                . date('M j, Y', strtotime($deliveryDate)),
            [
                'operational_date' => $deliveryDate,
                'metadata' => [
                    'source' => 'standing_route_sync',
                    'stop_count' => count($assignments),
                ],
            ]
        );
    }

    return [
        'stop_count' => count($assignments),
        'assignments' => $assignments,
        'demand' => $demandResult,
    ];
}

/**
 * Move dated stops from their current driver(s) to another driver.
 *
 * @param list<int> $dailyOrderIds
 * @param int|null $fromDriverId When set, every stop must currently belong to this driver.
 * @return array{transferred_count:int, to_driver_name:string, skipped:list<array{daily_order_id:int, reason:string}>}
 */
function bakery_driver_transfer_assignments(
    PDO $db,
    array $dailyOrderIds,
    int $toDriverId,
    string $deliveryDate,
    ?int $fromDriverId = null
): array {
    bakery_require_role(['administrator', 'manager']);

    $dailyOrderIds = array_values(array_unique(array_filter(
        array_map('intval', $dailyOrderIds),
        static fn(int $id): bool => $id > 0
    )));
    if ($dailyOrderIds === []) {
        throw new RuntimeException('No orders selected to transfer');
    }

    $toDriverRow = bakery_get_driver_by_id($db, $toDriverId);
    if (!$toDriverRow) {
        throw new RuntimeException("Driver ID {$toDriverId} does not exist");
    }
    if ((int)($toDriverRow['archived'] ?? 0) === 1) {
        throw new RuntimeException('Cannot transfer stops to an archived driver');
    }

    $placeholders = implode(',', array_fill(0, count($dailyOrderIds), '?'));
    $fetchStmt = $db->prepare("
        SELECT doa.daily_order_id, doa.driver_id, doa.delivery_status, doa.scheduled_delivery_time
        FROM daily_order_assignments doa
        WHERE doa.daily_order_id IN ($placeholders)
          AND doa.delivery_date = ?
    ");
    $fetchStmt->execute([...$dailyOrderIds, $deliveryDate]);
    $existing = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

    $existingByOrderId = [];
    foreach ($existing as $row) {
        $existingByOrderId[(int)$row['daily_order_id']] = $row;
    }

    $blockedStatuses = ['delivered', 'in_transit'];
    $toTransfer = [];
    $skipped = [];
    foreach ($dailyOrderIds as $orderId) {
        $assignment = $existingByOrderId[$orderId] ?? null;
        if (!$assignment) {
            $skipped[] = ['daily_order_id' => $orderId, 'reason' => 'not_assigned'];
            continue;
        }
        $currentDriverId = (int)$assignment['driver_id'];
        if ($fromDriverId !== null && $currentDriverId !== $fromDriverId) {
            $skipped[] = ['daily_order_id' => $orderId, 'reason' => 'wrong_source_driver'];
            continue;
        }
        if ($currentDriverId === $toDriverId) {
            $skipped[] = ['daily_order_id' => $orderId, 'reason' => 'already_on_target_driver'];
            continue;
        }
        if (in_array((string)$assignment['delivery_status'], $blockedStatuses, true)) {
            $skipped[] = [
                'daily_order_id' => $orderId,
                'reason' => 'status_' . $assignment['delivery_status'],
            ];
            continue;
        }
        $toTransfer[] = $assignment;
    }

    if ($toTransfer === []) {
        throw new RuntimeException('No eligible stops to transfer');
    }

    $db->beginTransaction();
    try {
        $deleteStmt = $db->prepare('
            DELETE FROM daily_order_assignments
            WHERE daily_order_id = ? AND delivery_date = ?
        ');
        $insertStmt = $db->prepare('
            INSERT INTO daily_order_assignments (
                daily_order_id, driver_id, delivery_date, route_order,
                scheduled_delivery_time, delivery_status
            ) VALUES (?, ?, ?, ?, ?, \'pending\')
        ');
        $updateLegacyDriverStmt = $db->prepare('UPDATE daily_orders SET driver_id = ? WHERE id = ?');

        $maxStmt = $db->prepare('
            SELECT COALESCE(MAX(route_order), 0)
            FROM daily_order_assignments
            WHERE driver_id = ? AND delivery_date = ?
        ');
        $maxStmt->execute([$toDriverId, $deliveryDate]);
        $nextRouteOrder = (int)$maxStmt->fetchColumn() + 1;

        $sourceDriverIds = [];
        foreach ($toTransfer as $assignment) {
            $orderId = (int)$assignment['daily_order_id'];
            $sourceDriverIds[(int)$assignment['driver_id']] = true;

            $deleteStmt->execute([$orderId, $deliveryDate]);
            $insertStmt->execute([
                $orderId,
                $toDriverId,
                $deliveryDate,
                $nextRouteOrder,
                $assignment['scheduled_delivery_time'] ?? null,
            ]);
            $updateLegacyDriverStmt->execute([$toDriverId, $orderId]);
            $nextRouteOrder++;
        }

        bakery_driver_renumber_route_orders($db, array_keys($sourceDriverIds), $deliveryDate);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    $toDriverName = $toDriverRow['name'] ?? ('Driver #' . $toDriverId);
    $transferredCount = count($toTransfer);
    if (function_exists('bakery_record_operational_event') && defined('BAKERY_OP_DRIVER_ROUTE_ASSIGNED')) {
        bakery_record_operational_event(
            $db,
            BAKERY_OP_DRIVER_ROUTE_ASSIGNED,
            'Transferred ' . $transferredCount . ' stop' . ($transferredCount === 1 ? '' : 's')
                . ' to ' . $toDriverName . ' on ' . date('M j, Y', strtotime($deliveryDate)),
            [
                'operational_date' => $deliveryDate,
                'driver_id' => $toDriverId,
                'metadata' => [
                    'source' => 'transfer',
                    'from_driver_id' => $fromDriverId,
                    'to_driver_id' => $toDriverId,
                    'stop_count' => $transferredCount,
                    'skipped_count' => count($skipped),
                ],
            ]
        );
    }

    return [
        'transferred_count' => $transferredCount,
        'to_driver_name' => $toDriverName,
        'skipped' => $skipped,
    ];
}

/**
 * Renumber route_order sequentially for one or more drivers on a date.
 *
 * @param list<int> $driverIds
 */
function bakery_driver_renumber_route_orders(PDO $db, array $driverIds, string $deliveryDate): void
{
    $selectStmt = $db->prepare('
        SELECT id FROM daily_order_assignments
        WHERE driver_id = ? AND delivery_date = ?
        ORDER BY route_order, id
    ');
    $updateStmt = $db->prepare('
        UPDATE daily_order_assignments SET route_order = ? WHERE id = ?
    ');

    foreach ($driverIds as $driverId) {
        $driverId = (int)$driverId;
        if ($driverId <= 0) {
            continue;
        }
        $selectStmt->execute([$driverId, $deliveryDate]);
        $assignmentIds = $selectStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($assignmentIds as $index => $assignmentId) {
            $updateStmt->execute([$index + 1, (int)$assignmentId]);
        }
    }
}

/**
 * Reorder remaining stops on one driver's dated route.
 *
 * Drivers and Driver Assistants may only reorder their own route identity.
 * Delivered and skipped stops stay locked at the front of the numbered list.
 * This never adds, removes, or transfers stops.
 *
 * @param list<int> $orderedDailyOrderIds Remaining stops in the desired visit order.
 *        Missing remaining IDs are appended in their current relative order.
 * @return array{
 *     stop_count:int,
 *     next_daily_order_id:int,
 *     stops:list<array{daily_order_id:int, route_order:int, delivery_status:string}>
 * }
 */
function bakery_driver_reorder_remaining_stops(
    PDO $db,
    int $driverId,
    string $deliveryDate,
    array $orderedDailyOrderIds
): array {
    bakery_require_role(['administrator', 'manager', 'driver', 'driver_assistant']);
    $deliveryDate = bakery_driver_validate_delivery_date($deliveryDate);
    if ($driverId <= 0) {
        throw new RuntimeException('Invalid driver_id');
    }
    bakery_assert_driver_identity($db, $driverId, $deliveryDate);

    $orderedDailyOrderIds = array_values(array_unique(array_filter(
        array_map('intval', $orderedDailyOrderIds),
        static fn(int $id): bool => $id > 0
    )));

    $lockedStatuses = ['delivered', 'cancelled'];
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('
            SELECT id, daily_order_id, route_order, delivery_status
            FROM daily_order_assignments
            WHERE driver_id = ? AND delivery_date = ?
            ORDER BY route_order, id
            FOR UPDATE
        ');
        $stmt->execute([$driverId, $deliveryDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            throw new RuntimeException('No stops on this route');
        }

        $locked = [];
        $movable = [];
        $movableByOrderId = [];
        foreach ($rows as $row) {
            $status = (string)($row['delivery_status'] ?? 'pending');
            if (in_array($status, $lockedStatuses, true)) {
                $locked[] = $row;
                continue;
            }
            $movable[] = $row;
            $movableByOrderId[(int)$row['daily_order_id']] = $row;
        }
        if ($movable === []) {
            throw new RuntimeException('No remaining stops to reorder');
        }

        foreach ($orderedDailyOrderIds as $orderId) {
            if (!isset($movableByOrderId[$orderId])) {
                throw new RuntimeException('Completed or skipped stops cannot be moved');
            }
        }

        $used = [];
        $newMovable = [];
        foreach ($orderedDailyOrderIds as $orderId) {
            if (isset($used[$orderId])) {
                continue;
            }
            $newMovable[] = $movableByOrderId[$orderId];
            $used[$orderId] = true;
        }
        foreach ($movable as $row) {
            $orderId = (int)$row['daily_order_id'];
            if (!isset($used[$orderId])) {
                $newMovable[] = $row;
            }
        }

        $db->prepare('
            UPDATE daily_order_assignments
            SET route_order = -id
            WHERE driver_id = ? AND delivery_date = ?
        ')->execute([$driverId, $deliveryDate]);

        $updateStmt = $db->prepare('UPDATE daily_order_assignments SET route_order = ? WHERE id = ?');
        $routeOrder = 1;
        $resultStops = [];
        $nextDailyOrderId = 0;
        foreach (array_merge($locked, $newMovable) as $row) {
            $updateStmt->execute([$routeOrder, (int)$row['id']]);
            $status = (string)($row['delivery_status'] ?? 'pending');
            $resultStops[] = [
                'daily_order_id' => (int)$row['daily_order_id'],
                'route_order' => $routeOrder,
                'delivery_status' => $status,
            ];
            if ($nextDailyOrderId === 0 && !in_array($status, $lockedStatuses, true)) {
                $nextDailyOrderId = (int)$row['daily_order_id'];
            }
            $routeOrder++;
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    $driverRow = bakery_get_driver_by_id($db, $driverId);
    $driverName = $driverRow['name'] ?? ('Driver #' . $driverId);
    if (function_exists('bakery_record_operational_event') && defined('BAKERY_OP_DRIVER_ROUTE_ASSIGNED')) {
        bakery_record_operational_event(
            $db,
            BAKERY_OP_DRIVER_ROUTE_ASSIGNED,
            'Reordered remaining stops for ' . $driverName . ' on ' . date('M j, Y', strtotime($deliveryDate)),
            [
                'operational_date' => $deliveryDate,
                'driver_id' => $driverId,
                'metadata' => [
                    'source' => 'driver_reorder',
                    'stop_count' => count($resultStops),
                    'next_daily_order_id' => $nextDailyOrderId,
                ],
            ]
        );
    }

    return [
        'stop_count' => count($resultStops),
        'next_daily_order_id' => $nextDailyOrderId,
        'stops' => $resultStops,
    ];
}

/**
 * Add a customer to a driver's dated route, creating daily orders and optional standing data.
 *
 * @param list<array{product_id:int, quantity:int}> $standingOrderLines
 * @return array{
 *     daily_order_id:int,
 *     customer_name:string,
 *     message:string,
 *     standing_route_saved:bool,
 *     standing_orders_updated:int
 * }
 */
function bakery_driver_add_customer_to_route(
    PDO $db,
    int $customerId,
    int $driverId,
    string $deliveryDate,
    bool $saveStandingRoute = false,
    array $standingOrderLines = [],
    bool $applyPanDulceStandard = false
): array {
    bakery_require_role(['administrator', 'manager']);

    $dateObject = DateTime::createFromFormat('!Y-m-d', $deliveryDate);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $deliveryDate) {
        throw new RuntimeException('Invalid delivery date');
    }
    if ($customerId <= 0) {
        throw new RuntimeException('Invalid customer ID');
    }

    $driverRow = bakery_get_driver_by_id($db, $driverId);
    if (!$driverRow) {
        throw new RuntimeException("Driver ID {$driverId} does not exist");
    }
    if ((int)($driverRow['archived'] ?? 0) === 1) {
        throw new RuntimeException('Cannot assign stops to an archived driver');
    }

    $custStmt = $db->prepare('SELECT * FROM customers WHERE id = ? AND is_active = 1');
    $custStmt->execute([$customerId]);
    $customer = $custStmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        throw new RuntimeException('Customer not found or inactive');
    }

    $dayOfWeek = (int)date('N', strtotime($deliveryDate));
    $standingOrdersUpdated = 0;

    $db->beginTransaction();
    try {
        if ($saveStandingRoute) {
            $dayClause = $dayOfWeek === 7 ? 'IN (0, 7)' : '= ?';
            $deleteRoute = $db->prepare("DELETE FROM standing_routes WHERE customer_id = ? AND day_of_week {$dayClause}");
            $deleteRoute->execute($dayOfWeek === 7 ? [$customerId] : [$customerId, $dayOfWeek]);
            if ($dayOfWeek === 7) {
                $db->prepare('DELETE FROM standing_routes WHERE customer_id = ? AND day_of_week = 0')
                    ->execute([$customerId]);
            }
            $insertRoute = $db->prepare(
                'INSERT INTO standing_routes (driver_id, customer_id, day_of_week) VALUES (?, ?, ?)'
            );
            $insertRoute->execute([$driverId, $customerId, $dayOfWeek]);
        }

        if ($applyPanDulceStandard) {
            require_once __DIR__ . '/pan_dulce_standards.php';
            $routeDays = $saveStandingRoute ? [$dayOfWeek] : bakery_customer_standing_route_days($db, $customerId);
            if ($routeDays === [] && $saveStandingRoute) {
                $routeDays = [$dayOfWeek];
            }
            if ($routeDays === []) {
                throw new RuntimeException(
                    'Save standing route first, or assign a standing route before applying Pan Dulce standard'
                );
            }
            $panResult = bakery_apply_pan_dulce_standing_standard($db, $customerId, 1.0, $routeDays);
            $standingOrdersUpdated += (int)($panResult['updated'] ?? 0);
        }

        if ($standingOrderLines !== []) {
            $upsertStanding = $db->prepare('
                INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
            ');
            foreach ($standingOrderLines as $line) {
                $productId = (int)($line['product_id'] ?? 0);
                $quantity = (int)($line['quantity'] ?? 0);
                if ($productId <= 0 || $quantity <= 0) {
                    continue;
                }
                $upsertStanding->execute([$customerId, $productId, $dayOfWeek, $quantity]);
                $standingOrdersUpdated++;
            }
        }

        require_once __DIR__ . '/customer_order_mutations.php';
        $existingOrder = bakery_customer_daily_order_row($db, $customerId, $deliveryDate);
        $hadExistingOrder = (bool)$existingOrder;

        if ($existingOrder) {
            $dailyOrderId = (int)$existingOrder['id'];
        } else {
            $dailyOrderId = bakery_customer_ensure_daily_order($db, $customer, $deliveryDate);
        }

        if ($hadExistingOrder && $standingOrdersUpdated > 0) {
            $items = bakery_customer_daily_items($db, $dailyOrderId);
            if ($items === []) {
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
            }
        }

        $maxStmt = $db->prepare('
            SELECT COALESCE(MAX(route_order), 0)
            FROM daily_order_assignments
            WHERE driver_id = ? AND delivery_date = ?
        ');
        $maxStmt->execute([$driverId, $deliveryDate]);
        $nextRouteOrder = (int)$maxStmt->fetchColumn() + 1;

        $db->prepare('DELETE FROM daily_order_assignments WHERE daily_order_id = ? AND delivery_date = ?')
            ->execute([$dailyOrderId, $deliveryDate]);

        $insertAssignment = $db->prepare('
            INSERT INTO daily_order_assignments (
                daily_order_id, driver_id, delivery_date, route_order,
                scheduled_delivery_time, delivery_status
            ) VALUES (?, ?, ?, ?, NULL, \'pending\')
        ');
        $insertAssignment->execute([$dailyOrderId, $driverId, $deliveryDate, $nextRouteOrder]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    $driverName = $driverRow['name'] ?? ('Driver #' . $driverId);
    $message = $customer['name'] . ' added to ' . $driverName . '\'s route';
    if ($saveStandingRoute) {
        $message .= ' and saved to the ' . date('l', strtotime($deliveryDate)) . ' standing route';
    }
    if ($standingOrdersUpdated > 0) {
        $message .= ' with standing order updated';
    }

    if (function_exists('bakery_record_operational_event') && defined('BAKERY_OP_DRIVER_ROUTE_ASSIGNED')) {
        bakery_record_operational_event(
            $db,
            BAKERY_OP_DRIVER_ROUTE_ASSIGNED,
            'Added ' . $customer['name'] . ' to ' . $driverName . ' on ' . date('M j, Y', strtotime($deliveryDate)),
            [
                'operational_date' => $deliveryDate,
                'driver_id' => $driverId,
                'customer_id' => $customerId,
                'metadata' => [
                    'source' => 'add_customer_to_route',
                    'standing_route_saved' => $saveStandingRoute,
                    'standing_orders_updated' => $standingOrdersUpdated,
                ],
            ]
        );
    }

    return [
        'daily_order_id' => $dailyOrderId,
        'customer_name' => (string)$customer['name'],
        'message' => $message,
        'standing_route_saved' => $saveStandingRoute,
        'standing_orders_updated' => $standingOrdersUpdated,
    ];
}

/**
 * Drivers (and managers in driver mode) may add one dated stop to their own
 * route. This never rewrites standing routes or Pan Dulce standards.
 *
 * @return array{
 *     ok:bool,
 *     code:string,
 *     message:string,
 *     customer_id:int,
 *     customer_name:string,
 *     daily_order_id:int,
 *     other_driver_name:string,
 *     taken_from_other:bool
 * }
 */
function bakery_driver_plan_add_stop(
    PDO $db,
    int $driverId,
    string $deliveryDate,
    int $customerId,
    bool $takeFromOther = false
): array {
    $deliveryDate = bakery_driver_assert_route_plan_edit($db, $driverId, $deliveryDate);
    if ($customerId <= 0) {
        throw new RuntimeException(
            bakery_driver_plan_text('driver.prep_customer_missing', [], 'Customer not found or inactive')
        );
    }

    $driverRow = bakery_get_driver_by_id($db, $driverId);
    if (!$driverRow) {
        throw new RuntimeException("Driver ID {$driverId} does not exist");
    }
    if ((int)($driverRow['archived'] ?? 0) === 1) {
        throw new RuntimeException('Cannot assign stops to an archived driver');
    }

    $custStmt = $db->prepare(
        'SELECT c.* FROM customers c
         WHERE c.id = ? AND c.is_active = 1 '
        . bakery_sfb_ops_origin_clause('c', $db) . '
         LIMIT 1'
    );
    $custStmt->execute([$customerId]);
    $customer = $custStmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        throw new RuntimeException(
            bakery_driver_plan_text('driver.prep_customer_missing', [], 'Customer not found or inactive')
        );
    }
    $customerName = (string)$customer['name'];

    $empty = [
        'ok' => false,
        'code' => '',
        'message' => '',
        'customer_id' => $customerId,
        'customer_name' => $customerName,
        'daily_order_id' => 0,
        'other_driver_name' => '',
        'taken_from_other' => false,
    ];

    require_once __DIR__ . '/customer_order_mutations.php';

    $db->beginTransaction();
    try {
        $existingStmt = $db->prepare(
            'SELECT doa.id, doa.daily_order_id, doa.driver_id, doa.delivery_status, doa.scheduled_delivery_time, d.name AS driver_name
             FROM daily_order_assignments doa
             LEFT JOIN drivers d ON d.id = doa.driver_id
             WHERE doa.delivery_date = ?
               AND doa.daily_order_id IN (
                   SELECT id FROM daily_orders WHERE customer_id = ? AND order_date = ?
               )
             FOR UPDATE'
        );
        $existingStmt->execute([$deliveryDate, $customerId, $deliveryDate]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $assignedDriverId = (int)$existing['driver_id'];
            $status = (string)($existing['delivery_status'] ?? 'pending');
            if ($assignedDriverId === $driverId) {
                $db->rollBack();
                $empty['code'] = 'already_on_route';
                $empty['daily_order_id'] = (int)$existing['daily_order_id'];
                $empty['message'] = bakery_driver_plan_text(
                    'driver.prep_already',
                    ['name' => $customerName],
                    ':name is already on your route'
                );
                return $empty;
            }
            if (in_array($status, ['delivered', 'in_transit'], true)) {
                throw new RuntimeException(
                    bakery_driver_plan_text('driver.prep_locked', [], 'Completed or in-transit stops cannot be moved')
                );
            }
            if (!$takeFromOther) {
                $db->rollBack();
                $empty['code'] = 'on_other_route';
                $empty['other_driver_name'] = (string)($existing['driver_name'] ?: ('Driver #' . $assignedDriverId));
                $empty['message'] = bakery_driver_plan_text(
                    'driver.prep_on_other',
                    ['name' => $customerName, 'driver' => $empty['other_driver_name']],
                    ':name is on :driver\'s route'
                );
                return $empty;
            }

            $sourceDriverId = $assignedDriverId;
            $scheduled = $existing['scheduled_delivery_time'] ?? null;
            $dailyOrderId = bakery_customer_ensure_daily_order($db, $customer, $deliveryDate);
            $db->prepare('DELETE FROM daily_order_assignments WHERE id = ?')->execute([(int)$existing['id']]);
            bakery_driver_plan_insert_assignment($db, $driverId, $deliveryDate, $dailyOrderId, $scheduled);
            bakery_driver_renumber_route_orders($db, [$sourceDriverId, $driverId], $deliveryDate);
            $db->commit();

            bakery_driver_plan_record_add($db, $driverId, $deliveryDate, $customerId, $customerName, (string)($driverRow['name'] ?? ''), true);

            return [
                'ok' => true,
                'code' => 'taken',
                'message' => bakery_driver_plan_text(
                    'driver.prep_taken',
                    ['name' => $customerName],
                    'Moved :name onto your route'
                ),
                'customer_id' => $customerId,
                'customer_name' => $customerName,
                'daily_order_id' => $dailyOrderId,
                'other_driver_name' => (string)($existing['driver_name'] ?? ''),
                'taken_from_other' => true,
            ];
        }

        $dailyOrderId = bakery_customer_ensure_daily_order($db, $customer, $deliveryDate);
        bakery_driver_plan_insert_assignment($db, $driverId, $deliveryDate, $dailyOrderId, null);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    bakery_driver_plan_record_add($db, $driverId, $deliveryDate, $customerId, $customerName, (string)($driverRow['name'] ?? ''), false);

    return [
        'ok' => true,
        'code' => 'added',
        'message' => bakery_driver_plan_text(
            'driver.prep_added',
            ['name' => $customerName],
            'Added :name'
        ),
        'customer_id' => $customerId,
        'customer_name' => $customerName,
        'daily_order_id' => $dailyOrderId,
        'other_driver_name' => '',
        'taken_from_other' => false,
    ];
}

function bakery_driver_plan_insert_assignment(
    PDO $db,
    int $driverId,
    string $deliveryDate,
    int $dailyOrderId,
    $scheduledDeliveryTime
): void {
    $maxStmt = $db->prepare(
        'SELECT COALESCE(MAX(route_order), 0)
         FROM daily_order_assignments
         WHERE driver_id = ? AND delivery_date = ?'
    );
    $maxStmt->execute([$driverId, $deliveryDate]);
    $nextRouteOrder = (int)$maxStmt->fetchColumn() + 1;

    $insert = $db->prepare(
        'INSERT INTO daily_order_assignments (
            daily_order_id, driver_id, delivery_date, route_order,
            scheduled_delivery_time, delivery_status
        ) VALUES (?, ?, ?, ?, ?, \'pending\')'
    );
    $insert->execute([
        $dailyOrderId,
        $driverId,
        $deliveryDate,
        $nextRouteOrder,
        $scheduledDeliveryTime ?: null,
    ]);
    $db->prepare('UPDATE daily_orders SET driver_id = ? WHERE id = ?')->execute([$driverId, $dailyOrderId]);
}

function bakery_driver_plan_record_add(
    PDO $db,
    int $driverId,
    string $deliveryDate,
    int $customerId,
    string $customerName,
    string $driverName,
    bool $takenFromOther
): void {
    if (!function_exists('bakery_record_operational_event') || !defined('BAKERY_OP_DRIVER_ROUTE_ASSIGNED')) {
        return;
    }
    $verb = $takenFromOther ? 'Moved' : 'Added';
    bakery_record_operational_event(
        $db,
        BAKERY_OP_DRIVER_ROUTE_ASSIGNED,
        $verb . ' ' . $customerName . ' onto ' . ($driverName !== '' ? $driverName : ('Driver #' . $driverId))
            . ' on ' . date('M j, Y', strtotime($deliveryDate)),
        [
            'operational_date' => $deliveryDate,
            'driver_id' => $driverId,
            'customer_id' => $customerId,
            'metadata' => [
                'source' => 'driver_route_prep',
                'taken_from_other' => $takenFromOther,
            ],
        ]
    );
}

function bakery_driver_plan_text(string $key, array $params = [], string $fallback = ''): string
{
    if (function_exists('bakery_t')) {
        $text = bakery_t($key, $params);
        if ($text !== $key) {
            return $text;
        }
    }
    foreach ($params as $name => $value) {
        $fallback = str_replace(':' . $name, (string)$value, $fallback);
    }
    return $fallback !== '' ? $fallback : $key;
}

/**
 * Count unassigned daily orders for a date.
 */
function bakery_driver_unassigned_count(PDO $db, string $date): int
{
    if (!table_exists($db, 'daily_orders') || !table_exists($db, 'daily_order_assignments')) {
        return 0;
    }
    $stmt = $db->prepare('
        SELECT COUNT(*)
        FROM daily_orders do
        WHERE do.order_date = ?
          AND NOT EXISTS (
              SELECT 1 FROM daily_order_assignments doa
              WHERE doa.daily_order_id = do.id AND doa.delivery_date = ?
          )
    ');
    $stmt->execute([$date, $date]);
    return (int)$stmt->fetchColumn();
}

/**
 * Drivers and assistants may edit their own today/future route.
 * Managers may still edit any driver's dated assignments.
 */
function bakery_driver_assert_route_plan_edit(PDO $db, int $driverId, string $deliveryDate): string
{
    bakery_require_role(['administrator', 'manager', 'driver', 'driver_assistant']);
    $deliveryDate = bakery_driver_validate_delivery_date($deliveryDate);
    bakery_assert_driver_identity($db, $driverId, $deliveryDate);
    if (!bakery_user_has_role(['administrator', 'manager']) && $deliveryDate < date('Y-m-d')) {
        throw new RuntimeException(
            bakery_driver_plan_text('driver.prep_past_blocked', [], 'Past routes cannot be edited')
        );
    }
    return $deliveryDate;
}

/**
 * Drivers may only act on data for their linked/selected driver identity.
 * Managers and administrators may access any driver.
 */
function bakery_assert_driver_identity(PDO $db, int $requestedDriverId, string $date): void
{
    if ($requestedDriverId <= 0) {
        throw new RuntimeException('Invalid driver_id');
    }
    if (bakery_user_has_role(['administrator', 'manager'])) {
        return;
    }
    if (!bakery_user_has_role(bakery_driver_route_roles())) {
        throw new RuntimeException('Insufficient permissions');
    }
    $allowedDriverId = bakery_route_worker_driver_id($db, bakery_current_user(), $date);
    if ($allowedDriverId <= 0) {
        throw new RuntimeException('Select your driver identity before continuing');
    }
    if ((int)$requestedDriverId !== (int)$allowedDriverId) {
        throw new RuntimeException('You may only access your own driver route');
    }
}

/**
 * Drivers may only act on stops assigned to their selected driver identity.
 */
function bakery_delivery_assert_driver_access(PDO $db, int $dailyOrderId): void
{
    if (!function_exists('bakery_current_user')) {
        return;
    }
    $user = bakery_current_user();
    if (!$user) {
        throw new RuntimeException('Authentication required');
    }
    $role = (string)($user['role_slug'] ?? '');
    if (in_array($role, ['administrator', 'manager'], true)) {
        return;
    }
    if (!bakery_is_driver_route_role($role)) {
        throw new RuntimeException('Insufficient permissions');
    }
    $stmt = $db->prepare(
        "SELECT doa.driver_id
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         WHERE doa.daily_order_id = ? AND doa.delivery_date = do.order_date
         ORDER BY doa.id DESC
         LIMIT 1"
    );
    $stmt->execute([$dailyOrderId]);
    $assigned = $stmt->fetchColumn();
    if ($assigned === false) {
        $legacy = $db->prepare('SELECT driver_id FROM daily_orders WHERE id = ?');
        $legacy->execute([$dailyOrderId]);
        $assigned = $legacy->fetchColumn();
    }
    $dateStmt = $db->prepare('SELECT order_date FROM daily_orders WHERE id = ? LIMIT 1');
    $dateStmt->execute([$dailyOrderId]);
    $orderDate = (string)$dateStmt->fetchColumn();
    $driverId = bakery_route_worker_driver_id($db, $user, $orderDate);
    if ($driverId <= 0 || $assigned === false || (int)$assigned !== $driverId) {
        throw new RuntimeException('This stop is not assigned to your driver route');
    }
}
