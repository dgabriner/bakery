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

    if (!in_array($mode, ['replace', 'append', 'reorder'], true)) {
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
    if ($mode === 'reorder' && $normalized === []) {
        throw new RuntimeException('No assignments provided');
    }

    bakery_dated_route_removals_ready($db);
    bakery_dated_route_removal_log_ready($db);

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
        foreach ($normalized as $assignment) {
            bakery_route_stop_restore_removed_day($db, (int)$assignment['daily_order_id']);
        }
        $insertStmt = $db->prepare('
            INSERT INTO daily_order_assignments (
                daily_order_id, driver_id, delivery_date, route_order,
                scheduled_delivery_time, delivery_status
            ) VALUES (?, ?, ?, ?, ?, \'pending\')
        ');

        $targetExistingStmt = $db->prepare('
            SELECT id, daily_order_id, delivery_status, route_order, scheduled_delivery_time
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

        $movedFromOther = $otherAssignments ?? [];

        $updateLegacyDriverStmt = $db->prepare('UPDATE daily_orders SET driver_id = ? WHERE id = ?');
        $removedOrderIds = [];

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
        } elseif ($mode === 'reorder') {
            $omitted = [];
            foreach ($targetExistingByOrder as $orderId => $row) {
                if (!isset($submittedLookup[$orderId])) {
                    $omitted[] = $row;
                }
            }
            usort($omitted, static function (array $a, array $b): int {
                $routeCompare = ((int)($a['route_order'] ?? 0)) <=> ((int)($b['route_order'] ?? 0));
                return $routeCompare !== 0 ? $routeCompare : ((int)$a['id'] <=> (int)$b['id']);
            });

            $db->prepare('
                UPDATE daily_order_assignments
                SET route_order = -id
                WHERE driver_id = ? AND delivery_date = ?
            ')->execute([$driverId, $deliveryDate]);

            $updateExistingStmt = $db->prepare('
                UPDATE daily_order_assignments
                SET route_order = ?, scheduled_delivery_time = ?
                WHERE id = ?
            ');
            $updateOrderOnlyStmt = $db->prepare('
                UPDATE daily_order_assignments
                SET route_order = ?
                WHERE id = ?
            ');
            $position = 1;
            foreach ($normalized as $assignment) {
                $dailyOrderId = (int)$assignment['daily_order_id'];
                if (isset($targetExistingByOrder[$dailyOrderId])) {
                    $updateExistingStmt->execute([
                        $position,
                        $assignment['scheduled_delivery_time'],
                        (int)$targetExistingByOrder[$dailyOrderId]['id'],
                    ]);
                } else {
                    $insertStmt->execute([
                        $dailyOrderId,
                        $driverId,
                        $deliveryDate,
                        $position,
                        $assignment['scheduled_delivery_time'],
                    ]);
                }
                $updateLegacyDriverStmt->execute([$driverId, $dailyOrderId]);
                $position++;
            }
            foreach ($omitted as $row) {
                $updateOrderOnlyStmt->execute([$position, (int)$row['id']]);
                $position++;
            }
            $stopCount = $position - 1;
        } else {
            // Free the positive route positions before applying a reorder.
            // Migration 047 enforces one position per driver/date, so swaps
            // must be written in two phases inside this transaction.
            $db->prepare('
                UPDATE daily_order_assignments
                SET route_order = -id
                WHERE driver_id = ? AND delivery_date = ?
            ')->execute([$driverId, $deliveryDate]);

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

        $movedOrderIds = array_map(
            static fn(array $row): int => (int)$row['daily_order_id'],
            $movedFromOther
        );
        $customerByOrder = bakery_dated_route_customers_for_orders($db, array_merge(
            array_map(static fn(array $assignment): int => (int)$assignment['daily_order_id'], $normalized),
            $removedOrderIds,
            $movedOrderIds
        ));
        foreach ($movedFromOther as $row) {
            bakery_dated_route_log_removal(
                $db,
                $deliveryDate,
                $customerByOrder[(int)$row['daily_order_id']] ?? 0,
                (int)$row['driver_id'],
                (int)$row['daily_order_id'],
                'moved_to_other_driver',
                'driver_assign_orders.move',
                false
            );
        }
        foreach ($removedOrderIds as $orderId) {
            bakery_dated_route_log_removal(
                $db,
                $deliveryDate,
                $customerByOrder[(int)$orderId] ?? 0,
                $driverId,
                (int)$orderId,
                'saved_route_omitted',
                'driver_assign_orders.replace',
                true
            );
        }
        foreach ($normalized as $assignment) {
            bakery_dated_route_removal_clear(
                $db,
                $deliveryDate,
                $customerByOrder[(int)$assignment['daily_order_id']] ?? 0
            );
        }

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
            ($mode === 'append' ? 'Added stops to ' : ($mode === 'reorder' ? 'Reordered route for ' : 'Assigned route for ')) . $driverName
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
    bakery_dated_route_removals_ready($db);
    bakery_dated_route_removal_log_ready($db);

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
        $customerStmt = $db->prepare('SELECT customer_id FROM daily_orders WHERE id = ?');
        $customerStmt->execute([$dailyOrderId]);
        bakery_dated_route_log_removal(
            $db,
            $deliveryDate,
            (int)$customerStmt->fetchColumn(),
            $driverId,
            $dailyOrderId,
            'driver_removed',
            'driver_remove_assignment',
            true
        );
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
 * Store label for a standing stop that is missing from a dated route.
 *
 * @param array{customer_name?:string, customer_id?:int, route_order?:int|null} $stop
 */
function bakery_driver_assignment_missing_store_label(array $stop): string
{
    $name = trim((string)($stop['customer_name'] ?? ''));
    if ($name === '') {
        $name = '#' . (int)($stop['customer_id'] ?? 0);
    }
    $order = (int)($stop['route_order'] ?? 0);
    if ($order > 0) {
        return $name . ' (#' . $order . ')';
    }
    return $name;
}

/**
 * Button label such as "Restore 3 missing stops: Amigos (#9), Plaza (#11)".
 *
 * @param list<string> $storeLabels
 */
function bakery_driver_assignment_restore_label(int $count, array $storeLabels): string
{
    $stores = implode(', ', array_values(array_filter(
        array_map(static fn($label): string => trim((string)$label), $storeLabels),
        static fn(string $label): bool => $label !== ''
    )));
    if ($count === 1) {
        return bakery_t('driver_assignment.restore_missing_one', ['stores' => $stores]);
    }
    return bakery_t('driver_assignment.restore_missing_many', [
        'count' => $count,
        'stores' => $stores,
    ]);
}

/**
 * Confirm sentence that names every stop a Restore action will add back.
 *
 * @param list<string> $storeLabels
 */
function bakery_route_restore_confirm_message(array $storeLabels): string
{
    $stores = implode(', ', $storeLabels);
    if (count($storeLabels) === 1) {
        return bakery_t('driver_assignment.restore_confirm_one', ['stores' => $stores]);
    }
    return bakery_t('driver_assignment.restore_confirm_many', ['stores' => $stores]);
}

/**
 * Result sentence after Restore adds the named stops back.
 *
 * @param list<string> $storeLabels
 */
function bakery_route_restore_done_message(array $storeLabels, string $driverName): string
{
    $stores = implode(', ', $storeLabels);
    if (count($storeLabels) === 1) {
        return bakery_t('driver_assignment.restore_done_one', [
            'stores' => $stores,
            'driver' => $driverName,
        ]);
    }
    return bakery_t('driver_assignment.restore_done_many', [
        'stores' => $stores,
        'driver' => $driverName,
        'count' => count($storeLabels),
    ]);
}

/**
 * Standing stops that are not on that driver's dated route.
 *
 * @return list<array{
 *   driver_id:int,
 *   driver_name:string,
 *   stops:list<array{customer_id:int,customer_name:string,route_order:int,label:string}>,
 *   labels:list<string>,
 *   label:string,
 *   confirm:string
 * }>
 */
function bakery_route_missing_standing_by_driver(PDO $db, string $date): array
{
    if (!table_exists($db, 'standing_routes') || !table_exists($db, 'customers') || !table_exists($db, 'drivers')) {
        return [];
    }
    $date = bakery_driver_validate_delivery_date($date);
    $dayOfWeek = (int)date('N', strtotime($date));
    $stmt = $db->prepare(
        'SELECT sr.customer_id, sr.driver_id, sr.route_order, c.name AS customer_name, d.name AS driver_name
         FROM standing_routes sr
         JOIN customers c ON c.id = sr.customer_id
         ' . bakery_sfb_ops_origin_clause('c', $db) . '
         JOIN drivers d ON d.id = sr.driver_id
         WHERE CASE WHEN sr.day_of_week = 0 THEN 7 ELSE sr.day_of_week END = ?
         ORDER BY d.name, COALESCE(sr.route_order, 2147483647), c.name'
    );
    $stmt->execute([$dayOfWeek]);
    $standing = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $assignedDriverByCustomer = [];
    if (table_exists($db, 'daily_orders') && table_exists($db, 'daily_order_assignments')) {
        $assignedStmt = $db->prepare(
            'SELECT do.customer_id, doa.driver_id
             FROM daily_orders do
             JOIN daily_order_assignments doa
               ON doa.daily_order_id = do.id AND doa.delivery_date = do.order_date
             WHERE do.order_date = ?'
        );
        $assignedStmt->execute([$date]);
        foreach ($assignedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $assignedDriverByCustomer[(int)$row['customer_id']] = (int)$row['driver_id'];
        }
    }

    $groups = [];
    foreach ($standing as $route) {
        $driverId = (int)$route['driver_id'];
        $customerId = (int)$route['customer_id'];
        if ($driverId <= 0 || $customerId <= 0) {
            continue;
        }
        if (($assignedDriverByCustomer[$customerId] ?? 0) === $driverId) {
            continue;
        }
        if (!isset($groups[$driverId])) {
            $groups[$driverId] = [
                'driver_id' => $driverId,
                'driver_name' => (string)($route['driver_name'] ?? ''),
                'stops' => [],
                'labels' => [],
            ];
        }
        $label = bakery_driver_assignment_missing_store_label($route);
        $groups[$driverId]['stops'][] = [
            'customer_id' => $customerId,
            'customer_name' => (string)($route['customer_name'] ?? ''),
            'route_order' => (int)($route['route_order'] ?? 0),
            'label' => $label,
        ];
        $groups[$driverId]['labels'][] = $label;
    }

    $result = [];
    foreach ($groups as $group) {
        $labels = $group['labels'];
        $group['label'] = bakery_driver_assignment_restore_label(count($labels), $labels);
        $group['confirm'] = bakery_route_restore_confirm_message($labels);
        $result[] = $group;
    }
    return $result;
}

/**
 * Submit buttons whose label and confirm both name the stores.
 *
 * @param list<array{driver_id:int,label:string,confirm:string}> $groups
 * @param array<string,scalar> $hidden
 */
function bakery_route_restore_render_forms(array $groups, array $hidden, string $buttonClass = 'route-restore-btn'): void
{
    foreach ($groups as $group) {
        $confirm = (string)($group['confirm'] ?? '');
        $label = (string)($group['label'] ?? '');
        if ($confirm === '' || $label === '') {
            continue;
        }
        echo '<form method="post" class="route-restore-form" data-confirm="'
            . htmlspecialchars($confirm, ENT_QUOTES, 'UTF-8')
            . '" onsubmit="return confirm(this.getAttribute(\'data-confirm\'));">';
        if (function_exists('bakery_csrf_field')) {
            echo bakery_csrf_field();
        }
        foreach ($hidden as $name => $value) {
            echo '<input type="hidden" name="' . htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8')
                . '" value="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '">';
        }
        echo '<input type="hidden" name="driver_id" value="' . (int)$group['driver_id'] . '">';
        echo '<button type="submit" class="' . htmlspecialchars($buttonClass, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button>';
        echo '</form>';
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
    $dayClause = ((int)$dayOfWeek === 7)
        ? 'sr.day_of_week IN (0, 7)'
        : 'sr.day_of_week = ?';
    $dayParams = ((int)$dayOfWeek === 7) ? [] : [(int)$dayOfWeek];
    $stmt = $db->prepare(
        'SELECT sr.customer_id, sr.driver_id, c.name AS customer_name, sr.route_order
         FROM standing_routes sr
         JOIN customers c ON c.id = sr.customer_id AND c.is_active = 1
         ' . bakery_sfb_ops_origin_clause('c', $db) . '
         WHERE ' . $dayClause . '
         ORDER BY sr.driver_id, COALESCE(sr.route_order, 2147483647), c.name'
    );
    $stmt->execute($dayParams);
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

    bakery_dated_route_removals_ready($db);
    bakery_dated_route_removal_log_ready($db);
    $db->beginTransaction();
    try {
        bakery_dated_route_removal_clear_date($db, $deliveryDate);
        $customerIds = array_map(static fn(array $route): int => (int)$route['customer_id'], $standingRoutes);
        $orderIdByCustomer = bakery_daily_orders_map_for_customers($db, $deliveryDate, $customerIds);

        $assignments = [];
        $routeOrderByDriver = [];

        foreach ($standingRoutes as $route) {
            $customerId = (int)$route['customer_id'];
            $dailyOrderId = (int)($orderIdByCustomer[$customerId] ?? 0);
            if ($dailyOrderId <= 0) {
                throw new RuntimeException('Could not create daily order for customer ' . $customerId);
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

        $priorStmt = $db->prepare(
            'SELECT doa.daily_order_id, doa.driver_id, do.customer_id
             FROM daily_order_assignments doa
             JOIN daily_orders do ON do.id = doa.daily_order_id
             WHERE doa.delivery_date = ?'
        );
        $priorStmt->execute([$deliveryDate]);
        $priorStops = $priorStmt->fetchAll(PDO::FETCH_ASSOC);
        $newDriverByCustomer = [];
        $customerByOrder = [];
        foreach ($orderIdByCustomer as $customerId => $dailyOrderId) {
            $customerByOrder[(int)$dailyOrderId] = (int)$customerId;
        }
        foreach ($assignments as $assignment) {
            $customerId = $customerByOrder[(int)$assignment['daily_order_id']] ?? 0;
            if ($customerId > 0) {
                $newDriverByCustomer[$customerId] = (int)$assignment['driver_id'];
            }
        }
        foreach ($priorStops as $priorStop) {
            $customerId = (int)$priorStop['customer_id'];
            $priorDriverId = (int)$priorStop['driver_id'];
            if (!isset($newDriverByCustomer[$customerId])) {
                bakery_dated_route_log_removal(
                    $db,
                    $deliveryDate,
                    $customerId,
                    $priorDriverId,
                    (int)$priorStop['daily_order_id'],
                    'standing_rebuild_omitted',
                    'driver_assign_from_standing',
                    false
                );
            } elseif ($newDriverByCustomer[$customerId] !== $priorDriverId) {
                bakery_dated_route_log_removal(
                    $db,
                    $deliveryDate,
                    $customerId,
                    $priorDriverId,
                    (int)$priorStop['daily_order_id'],
                    'moved_to_other_driver',
                    'driver_assign_from_standing',
                    false
                );
            }
        }

        $db->prepare('DELETE FROM daily_order_assignments WHERE delivery_date = ?')->execute([$deliveryDate]);
        $db->prepare('UPDATE daily_orders SET driver_id = NULL WHERE order_date = ?')->execute([$deliveryDate]);

        $insertAssignment = $db->prepare('
            INSERT INTO daily_order_assignments (
                daily_order_id, driver_id, delivery_date, route_order,
                scheduled_delivery_time, delivery_status
            ) VALUES (?, ?, ?, ?, ?, \'pending\')
        ');
        $updateDriver = $db->prepare('UPDATE daily_orders SET driver_id = ? WHERE id = ?');
        foreach ($assignments as $assignment) {
            bakery_route_stop_restore_removed_day($db, (int)$assignment['daily_order_id']);
            $insertAssignment->execute([
                $assignment['daily_order_id'],
                $assignment['driver_id'],
                $deliveryDate,
                $assignment['route_order'],
                $assignment['scheduled_delivery_time'],
            ]);
            $updateDriver->execute([$assignment['driver_id'], $assignment['daily_order_id']]);
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

    bakery_dated_route_removal_log_ready($db);
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
        $transferCustomers = bakery_dated_route_customers_for_orders(
            $db,
            array_map(static fn(array $assignment): int => (int)$assignment['daily_order_id'], $toTransfer)
        );
        foreach ($toTransfer as $assignment) {
            $orderId = (int)$assignment['daily_order_id'];
            $sourceDriverIds[(int)$assignment['driver_id']] = true;
            bakery_dated_route_log_removal(
                $db,
                $deliveryDate,
                $transferCustomers[$orderId] ?? 0,
                (int)$assignment['driver_id'],
                $orderId,
                'moved_to_other_driver',
                'driver_transfer_assignments',
                false
            );

            $deleteStmt->execute([$orderId, $deliveryDate]);
            bakery_route_stop_restore_removed_day($db, $orderId);
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

    // A stop at the door is already operationally in progress and must not
    // jump elsewhere in the route while the driver is serving it.
    $lockedStatuses = ['delivered', 'cancelled', 'in_transit'];
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
        foreach ($rows as $row) {
            bakery_route_stop_restore_removed_day($db, (int)$row['daily_order_id']);
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
                throw new RuntimeException('Completed, skipped, or in-transit stops cannot be moved');
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
function bakery_dated_order_has_no_positive_quantity(PDO $db, int $dailyOrderId): bool
{
    if ($dailyOrderId <= 0) {
        return false;
    }
    $stmt = $db->prepare(
        'SELECT COALESCE(SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END), 0)
         FROM daily_order_items
         WHERE daily_order_id = ?'
    );
    $stmt->execute([$dailyOrderId]);
    if ((int)$stmt->fetchColumn() > 0) {
        return false;
    }
    if (!function_exists('bakery_special_order_has_positive_qty')) {
        $specialPath = __DIR__ . '/special_orders.php';
        if (is_file($specialPath)) {
            require_once $specialPath;
        }
    }
    if (function_exists('bakery_special_order_has_positive_qty')
        && bakery_special_order_has_positive_qty($db, $dailyOrderId)) {
        return false;
    }
    return true;
}

/**
 * A dated order zeroed as "removed for this day only": the fill block is on,
 * every pan line is 0, and the standing order for that weekday still has pieces.
 * A special-order line with quantity above 0 is not this skip.
 */
function bakery_route_stop_is_removed_day_skip(PDO $db, int $dailyOrderId): bool
{
    if ($dailyOrderId <= 0) {
        return false;
    }
    if (!function_exists('bakery_special_order_has_positive_qty')) {
        $specialPath = __DIR__ . '/special_orders.php';
        if (is_file($specialPath)) {
            require_once $specialPath;
        }
    }
    if (function_exists('bakery_special_order_has_positive_qty')
        && bakery_special_order_has_positive_qty($db, $dailyOrderId)) {
        return false;
    }
    if (!bakery_dated_order_has_no_positive_quantity($db, $dailyOrderId)) {
        return false;
    }
    if (!function_exists('column_exists') || !column_exists($db, 'daily_orders', 'standing_fill_blocked')) {
        return false;
    }
    $stmt = $db->prepare(
        'SELECT customer_id, order_date, standing_fill_blocked FROM daily_orders WHERE id = ?'
    );
    $stmt->execute([$dailyOrderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || (int)($order['standing_fill_blocked'] ?? 0) !== 1) {
        return false;
    }
    $date = (string)$order['order_date'];
    $customerId = (int)$order['customer_id'];
    if ($customerId <= 0 || !function_exists('bakery_standing_day_from_date')) {
        return false;
    }
    $dayClause = bakery_standing_day_in_clause(bakery_standing_day_from_date($date));
    $stand = $db->prepare(
        "SELECT COALESCE(SUM(quantity), 0)
         FROM standing_orders
         WHERE customer_id = ? AND day_of_week {$dayClause['sql']} AND quantity > 0"
    );
    $stand->execute(array_merge([$customerId], $dayClause['values']));
    return (int)$stand->fetchColumn() > 0;
}

/**
 * When a removed-for-this-day skip is about to sit on a route, copy that
 * weekday's standing lines back onto the dated order. One rule for every
 * assignment path. Special-order quantity is left alone.
 *
 * @return bool True when standing lines were copied.
 */
function bakery_route_stop_restore_removed_day(PDO $db, int $dailyOrderId): bool
{
    if (!bakery_route_stop_is_removed_day_skip($db, $dailyOrderId)) {
        return false;
    }
    if (!function_exists('bakery_resolve_customer_price')) {
        require_once __DIR__ . '/customer_portal.php';
    }
    if (!function_exists('bakery_daily_order_recompute_total')) {
        require_once __DIR__ . '/customer_order_mutations.php';
    }

    $orderStmt = $db->prepare(
        'SELECT do.customer_id, do.order_date, c.pricing_tier, c.default_pan_dulce_price
         FROM daily_orders do
         JOIN customers c ON c.id = do.customer_id
         WHERE do.id = ?'
    );
    $orderStmt->execute([$dailyOrderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return false;
    }
    $customerId = (int)$order['customer_id'];
    $date = (string)$order['order_date'];
    $dayClause = bakery_standing_day_in_clause(bakery_standing_day_from_date($date));
    $lines = $db->prepare(
        "SELECT so.product_id, so.quantity, COALESCE(p.price, 0) AS price,
                p.wholesale_price, pl.name AS product_line_name
         FROM standing_orders so
         JOIN products p ON p.id = so.product_id
         JOIN dough_types dt ON dt.id = p.dough_type_id
         JOIN product_lines pl ON pl.id = dt.product_line_id
         WHERE so.customer_id = ? AND so.day_of_week {$dayClause['sql']} AND so.quantity > 0"
    );
    $lines->execute(array_merge([$customerId], $dayClause['values']));
    $standingLines = $lines->fetchAll(PDO::FETCH_ASSOC);
    if ($standingLines === []) {
        return false;
    }

    $customer = [
        'id' => $customerId,
        'pricing_tier' => $order['pricing_tier'] ?? 'retail',
        'default_pan_dulce_price' => $order['default_pan_dulce_price'] ?? null,
    ];
    $upsert = $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            quantity = VALUES(quantity),
            unit_price = VALUES(unit_price),
            line_total = VALUES(line_total)'
    );
    $wrote = 0;
    foreach ($standingLines as $line) {
        $qty = (int)$line['quantity'];
        if ($qty <= 0) {
            continue;
        }
        $unit = bakery_resolve_customer_price($db, $customer, [
            'id' => (int)$line['product_id'],
            'price' => (float)$line['price'],
            'wholesale_price' => $line['wholesale_price'] ?? null,
            'product_line_name' => (string)($line['product_line_name'] ?? ''),
        ]);
        $unit = round((float)$unit, 2);
        $upsert->execute([$dailyOrderId, (int)$line['product_id'], $qty, $unit, round($qty * $unit, 2)]);
        $wrote++;
    }
    if ($wrote === 0) {
        return false;
    }
    if (function_exists('bakery_daily_order_set_standing_fill_blocked')) {
        bakery_daily_order_set_standing_fill_blocked($db, $dailyOrderId, false);
    }
    if (function_exists('bakery_daily_order_recompute_total')) {
        bakery_daily_order_recompute_total($db, $dailyOrderId);
    }
    if (function_exists('bakery_record_operational_event')) {
        $eventType = defined('BAKERY_OP_STANDING_ORDER_RESTORED')
            ? BAKERY_OP_STANDING_ORDER_RESTORED
            : 'standing_order_restored';
        bakery_record_operational_event(
            $db,
            $eventType,
            'Restored standing order onto the dated order',
            [
                'operational_date' => $date,
                'customer_id' => $customerId,
                'daily_order_id' => $dailyOrderId,
                'metadata' => ['source' => 'route_stop_restore'],
            ]
        );
    }
    return true;
}

/**
 * Put this customer's standing order back onto one dated order and log it.
 * Other customers on the same date are left alone.
 */
function bakery_driver_restore_standing_for_dated_order(
    PDO $db,
    int $customerId,
    string $deliveryDate,
    int $dailyOrderId
): void {
    require_once __DIR__ . '/daily_order_generation.php';
    bakery_generate_daily_orders_from_standing($db, $deliveryDate, [
        'overwrite_changed' => true,
        'assign_routes' => false,
        'record_event' => false,
        'only_customer_id' => $customerId,
    ]);
    if (!function_exists('bakery_record_operational_event')) {
        return;
    }
    $eventType = defined('BAKERY_OP_STANDING_ORDER_RESTORED')
        ? BAKERY_OP_STANDING_ORDER_RESTORED
        : 'standing_order_restored';
    bakery_record_operational_event(
        $db,
        $eventType,
        'Restored standing order onto the dated order',
        [
            'operational_date' => $deliveryDate,
            'customer_id' => $customerId,
            'daily_order_id' => $dailyOrderId,
            'metadata' => ['source' => 'route_add_restore'],
        ]
    );
}

function bakery_driver_add_customer_to_route(
    PDO $db,
    int $customerId,
    int $driverId,
    string $deliveryDate,
    bool $saveStandingRoute = false,
    array $standingOrderLines = [],
    bool $applyPanDulceStandard = false,
    array $options = []
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

    require_once __DIR__ . '/customer_order_mutations.php';
    $guardOrder = bakery_customer_daily_order_row($db, $customerId, $deliveryDate);
    $guardIsRemovedDay = $guardOrder
        && bakery_route_stop_is_removed_day_skip($db, (int)$guardOrder['id']);
    if ($guardOrder
        && !$guardIsRemovedDay
        && bakery_dated_order_has_no_positive_quantity($db, (int)$guardOrder['id'])
    ) {
        $restoreStanding = !empty($options['restore_standing']);
        $keepEmpty = !empty($options['keep_empty']);
        if (!$restoreStanding && !$keepEmpty) {
            $warning = function_exists('bakery_t')
                ? bakery_t('driver_assignment.empty_order_warning', ['customer' => (string)$customer['name']])
                : (string)$customer['name'] . ' has an empty dated order.';
            return [
                'needs_restore' => true,
                'daily_order_id' => (int)$guardOrder['id'],
                'customer_name' => (string)$customer['name'],
                'message' => $warning,
                'standing_route_saved' => false,
                'standing_orders_updated' => 0,
            ];
        }
        if ($restoreStanding) {
            bakery_driver_restore_standing_for_dated_order(
                $db,
                $customerId,
                $deliveryDate,
                (int)$guardOrder['id']
            );
        }
    }

    bakery_dated_route_removal_log_ready($db);
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
            foreach ($standingOrderLines as $line) {
                $productId = (int)($line['product_id'] ?? 0);
                $quantity = (int)($line['quantity'] ?? 0);
                if ($productId <= 0 || $quantity <= 0) {
                    continue;
                }
                bakery_standing_order_upsert($db, $customerId, $productId, $dayOfWeek, $quantity);
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

        $priorAssignStmt = $db->prepare(
            'SELECT driver_id FROM daily_order_assignments WHERE daily_order_id = ? AND delivery_date = ?'
        );
        $priorAssignStmt->execute([$dailyOrderId, $deliveryDate]);
        foreach ($priorAssignStmt->fetchAll(PDO::FETCH_COLUMN) as $priorDriverId) {
            if ((int)$priorDriverId !== $driverId) {
                bakery_dated_route_log_removal(
                    $db,
                    $deliveryDate,
                    $customerId,
                    (int)$priorDriverId,
                    $dailyOrderId,
                    'moved_to_other_driver',
                    'driver_add_customer_to_route',
                    false
                );
            }
        }

        $db->prepare('DELETE FROM daily_order_assignments WHERE daily_order_id = ? AND delivery_date = ?')
            ->execute([$dailyOrderId, $deliveryDate]);

        $insertAssignment = $db->prepare('
            INSERT INTO daily_order_assignments (
                daily_order_id, driver_id, delivery_date, route_order,
                scheduled_delivery_time, delivery_status
            ) VALUES (?, ?, ?, ?, NULL, \'pending\')
        ');
        bakery_route_stop_restore_removed_day($db, $dailyOrderId);
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
 * Take-from-another-driver policy.
 *
 * Takes apply immediately today. When a manager approval queue ships, set
 * required=true and let bakery_driver_plan_take_is_approved() read that queue.
 *
 * @return array{required:bool,mode:string,approver_role:string}
 */
function bakery_driver_plan_take_policy(?PDO $db = null): array
{
    unset($db);
    return [
        'required' => false,
        'mode' => 'immediate',
        'approver_role' => 'manager',
    ];
}

/**
 * Whether this driver may pull another driver's stop now.
 * Immediate while take policy required=false. Later: approved request row.
 */
function bakery_driver_plan_take_is_approved(
    PDO $db,
    int $fromDriverId,
    int $toDriverId,
    int $dailyOrderId
): bool {
    $policy = bakery_driver_plan_take_policy($db);
    if (empty($policy['required'])) {
        return true;
    }
    unset($fromDriverId, $toDriverId, $dailyOrderId);
    return false;
}

/**
 * Drivers (and managers in driver mode) may add one dated stop to their own
 * route. This never rewrites standing routes. If the customer has no standing
 * for that weekday and the dated order is empty, it fills the standard 1×
 * Pan Dulce mix on the dated order only.
 *
 * @return array{
 *     ok:bool,
 *     code:string,
 *     message:string,
 *     customer_id:int,
 *     customer_name:string,
 *     daily_order_id:int,
 *     other_driver_name:string,
 *     taken_from_other:bool,
 *     filled_standard:bool,
 *     filled_standard_source:string,
 *     take_approval:array{required:bool,mode:string,approver_role:string}
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
    $takeApproval = bakery_driver_plan_take_policy($db);

    $empty = [
        'ok' => false,
        'code' => '',
        'message' => '',
        'customer_id' => $customerId,
        'customer_name' => $customerName,
        'daily_order_id' => 0,
        'other_driver_name' => '',
        'taken_from_other' => false,
        'filled_standard' => false,
        'filled_standard_source' => 'none',
        'take_approval' => $takeApproval,
    ];

    require_once __DIR__ . '/customer_order_mutations.php';

    $filled = ['filled' => false, 'source' => 'none', 'item_count' => 0];
    $dailyOrderId = 0;

    bakery_dated_route_removal_log_ready($db);
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
            if (!bakery_driver_plan_take_is_approved(
                $db,
                $assignedDriverId,
                $driverId,
                (int)$existing['daily_order_id']
            )) {
                $db->rollBack();
                $empty['code'] = 'take_needs_approval';
                $empty['other_driver_name'] = (string)($existing['driver_name'] ?: ('Driver #' . $assignedDriverId));
                $empty['daily_order_id'] = (int)$existing['daily_order_id'];
                $empty['message'] = bakery_driver_plan_text(
                    'driver.prep_take_needs_approval',
                    [],
                    'A manager has to approve moving this stop.'
                );
                return $empty;
            }

            $sourceDriverId = $assignedDriverId;
            $scheduled = $existing['scheduled_delivery_time'] ?? null;
            $dailyOrderId = bakery_customer_ensure_daily_order($db, $customer, $deliveryDate);
            $filled = bakery_customer_fill_empty_dated_order_from_standard($db, $dailyOrderId, $deliveryDate, $customer);
            bakery_dated_route_log_removal(
                $db,
                $deliveryDate,
                $customerId,
                $sourceDriverId,
                $dailyOrderId,
                'moved_to_other_driver',
                'driver_plan_add_stop',
                false
            );
            $db->prepare('DELETE FROM daily_order_assignments WHERE id = ?')->execute([(int)$existing['id']]);
            bakery_driver_plan_insert_assignment($db, $driverId, $deliveryDate, $dailyOrderId, $scheduled);
            bakery_driver_renumber_route_orders($db, [$sourceDriverId, $driverId], $deliveryDate);
            $db->commit();

            bakery_driver_plan_record_add(
                $db,
                $driverId,
                $deliveryDate,
                $customerId,
                $customerName,
                (string)($driverRow['name'] ?? ''),
                true,
                ['filled_standard_source' => $filled['source']]
            );

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
                'filled_standard' => !empty($filled['filled']),
                'filled_standard_source' => (string)$filled['source'],
                'take_approval' => $takeApproval,
            ];
        }

        $dailyOrderId = bakery_customer_ensure_daily_order($db, $customer, $deliveryDate);
        $filled = bakery_customer_fill_empty_dated_order_from_standard($db, $dailyOrderId, $deliveryDate, $customer);
        bakery_driver_plan_insert_assignment($db, $driverId, $deliveryDate, $dailyOrderId, null);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    bakery_driver_plan_record_add(
        $db,
        $driverId,
        $deliveryDate,
        $customerId,
        $customerName,
        (string)($driverRow['name'] ?? ''),
        false,
        ['filled_standard_source' => $filled['source']]
    );

    $addedMessage = !empty($filled['filled'])
        ? bakery_driver_plan_text(
            'driver.prep_added_standard',
            ['name' => $customerName],
            'Added :name with the standard 1× order'
        )
        : bakery_driver_plan_text(
            'driver.prep_added',
            ['name' => $customerName],
            'Added :name'
        );

    return [
        'ok' => true,
        'code' => 'added',
        'message' => $addedMessage,
        'customer_id' => $customerId,
        'customer_name' => $customerName,
        'daily_order_id' => $dailyOrderId,
        'other_driver_name' => '',
        'taken_from_other' => false,
        'filled_standard' => !empty($filled['filled']),
        'filled_standard_source' => (string)$filled['source'],
        'take_approval' => $takeApproval,
    ];
}

function bakery_driver_plan_insert_assignment(
    PDO $db,
    int $driverId,
    string $deliveryDate,
    int $dailyOrderId,
    $scheduledDeliveryTime
): void {
    bakery_route_stop_restore_removed_day($db, $dailyOrderId);
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
    bool $takenFromOther,
    array $extra = []
): void {
    if (!function_exists('bakery_record_operational_event') || !defined('BAKERY_OP_DRIVER_ROUTE_ASSIGNED')) {
        return;
    }
    $verb = $takenFromOther ? 'Moved' : 'Added';
    $policy = bakery_driver_plan_take_policy($db);
    bakery_record_operational_event(
        $db,
        BAKERY_OP_DRIVER_ROUTE_ASSIGNED,
        $verb . ' ' . $customerName . ' onto ' . ($driverName !== '' ? $driverName : ('Driver #' . $driverId))
            . ' on ' . date('M j, Y', strtotime($deliveryDate)),
        [
            'operational_date' => $deliveryDate,
            'driver_id' => $driverId,
            'customer_id' => $customerId,
            'metadata' => array_merge(
                [
                    'source' => 'driver_route_prep',
                    'taken_from_other' => $takenFromOther,
                    'take_approval' => $policy,
                ],
                $extra
            ),
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
 * Dated orders with no driver for this delivery date.
 * Survey Center and Driver Assignment both use this list.
 *
 * @return list<array{id:int,name:string,zone:string,address:string,daily_order_id:int,pieces:int}>
 */
function bakery_unassigned_for_date(PDO $db, string $date): array
{
    $date = bakery_driver_validate_delivery_date($date);
    if (!function_exists('table_exists') || !table_exists($db, 'daily_orders') || !table_exists($db, 'customers')) {
        return [];
    }
    $origin = function_exists('bakery_sfb_ops_origin_clause')
        ? bakery_sfb_ops_origin_clause('c', $db)
        : '';
    $pieces = '0';
    if (table_exists($db, 'daily_order_items')) {
        $pieces = '(SELECT COALESCE(SUM(doi.quantity), 0) FROM daily_order_items doi WHERE doi.daily_order_id = do.id)';
    }
    $assignmentGuard = '';
    if (table_exists($db, 'daily_order_assignments')) {
        $assignmentGuard = ' AND NOT EXISTS (
            SELECT 1 FROM daily_order_assignments doa
            WHERE doa.daily_order_id = do.id
              AND doa.delivery_date = do.order_date
              AND doa.driver_id <> 0
        )';
    }
    $sql = "SELECT c.id AS id, c.name AS name, COALESCE(c.zone, '') AS zone,
                   COALESCE(c.address, '') AS address, do.id AS daily_order_id,
                   {$pieces} AS pieces
            FROM daily_orders do
            JOIN customers c ON c.id = do.customer_id
            {$origin}
            WHERE do.order_date = ?
            {$assignmentGuard}
            ORDER BY c.name";
    $stmt = $db->prepare($sql);
    $stmt->execute([$date]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int)$row['id'];
        if ($id <= 0) {
            continue;
        }
        $rows[] = [
            'id' => $id,
            'name' => (string)$row['name'],
            'zone' => (string)($row['zone'] ?? ''),
            'address' => (string)($row['address'] ?? ''),
            'daily_order_id' => (int)$row['daily_order_id'],
            'pieces' => (int)($row['pieces'] ?? 0),
        ];
    }
    return $rows;
}

/**
 * Count unassigned daily orders for a date.
 */
function bakery_driver_unassigned_count(PDO $db, string $date): int
{
    return count(bakery_unassigned_for_date($db, $date));
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
