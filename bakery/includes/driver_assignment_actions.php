<?php
/**
 * Driver Assignment action handlers — pure functions for page and API dispatch.
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_driver_assignment_action_assign_orders(PDO $db, array $input, ?array $user = null): array
{
    $driverId = (int)($input['driver_id'] ?? 0);
    $deliveryDate = (string)($input['delivery_date'] ?? '');
    $assignments = json_decode($input['assignments'] ?? '[]', true);
    // replace = full route rewrite (edit/optimize/reorder)
    // append = add selected orders without clearing existing ones
    $mode = strtolower(trim((string)($input['mode'] ?? 'replace')));
    if (!in_array($mode, ['replace', 'append'], true)) {
        $mode = 'replace';
    }

    if (!is_array($assignments)) {
        throw new Exception('Invalid assignments');
    }

    $result = bakery_driver_assign_orders($db, $driverId, $deliveryDate, $assignments, $mode);

    return [
        'success' => true,
        'message' => $result['mode'] === 'append'
            ? ($result['stop_count'] > 0
                ? $result['stop_count'] . ' stop' . ($result['stop_count'] === 1 ? '' : 's') . ' added to ' . $result['driver_name']
                : 'Those stops are already on ' . $result['driver_name'] . '\'s route')
            : ($result['stop_count'] > 0
                ? 'Route saved for ' . $result['driver_name']
                : 'Route cleared for ' . $result['driver_name']),
        'mode' => $result['mode'],
        'stop_count' => $result['stop_count'],
    ];
}

function bakery_driver_assignment_action_get_optimized_route(PDO $db, array $input, ?array $user = null): array
{
    $driverId = (int)$input['driver_id'];
    $deliveryDate = $input['delivery_date'];

    $stmt = $db->prepare("
        SELECT 
            do.id as daily_order_id,
            do.customer_id,
            c.name as customer_name,
            c.address,
            c.deliver_by,
            c.deliver_after,
            COALESCE(c.delivery_time, 20) as delivery_time,
            c.latitude,
            c.longitude
        FROM daily_orders do
        JOIN customers c ON do.customer_id = c.id
        " . bakery_sfb_ops_origin_clause('c', $db) . "
        WHERE do.order_date = ?
        AND do.id IN (
            SELECT daily_order_id 
            FROM daily_order_assignments 
            WHERE driver_id = ? AND delivery_date = ?
        )
        ORDER BY c.name
    ");
    $stmt->execute([$deliveryDate, $driverId, $deliveryDate]);
    $orders = $stmt->fetchAll();

    return ['success' => true, 'orders' => $orders];
}

function bakery_driver_assignment_action_update_delivery_time(PDO $db, array $input, ?array $user = null): array
{
    $customerId = (int)$input['customer_id'];
    $deliveryTime = (int)$input['delivery_time'];

    if ($deliveryTime >= 1 && $deliveryTime <= 120) {
        $stmt = $db->prepare('UPDATE customers SET delivery_time = ? WHERE id = ?');
        $success = $stmt->execute([$deliveryTime, $customerId]);

        return ['success' => $success];
    }

    return ['success' => false, 'error' => 'Invalid delivery time'];
}

function bakery_driver_assignment_action_remove_assignment(PDO $db, array $input, ?array $user = null): array
{
    $dailyOrderId = (int)($input['daily_order_id'] ?? 0);
    $driverId = (int)($input['driver_id'] ?? 0);
    $deliveryDate = (string)($input['delivery_date'] ?? '');

    bakery_driver_remove_assignment($db, $dailyOrderId, $driverId, $deliveryDate);

    return ['success' => true, 'message' => 'Stop unassigned; the dated order and quantities were kept'];
}

function bakery_driver_assignment_action_transfer_assignments(PDO $db, array $input, ?array $user = null): array
{
    $toDriverId = (int)$input['to_driver_id'];
    $deliveryDate = $input['delivery_date'];
    $fromDriverId = isset($input['from_driver_id']) && $input['from_driver_id'] !== ''
        ? (int)$input['from_driver_id']
        : null;
    $dailyOrderIds = json_decode($input['daily_order_ids'] ?? '[]', true);
    if (!is_array($dailyOrderIds)) {
        throw new Exception('Invalid daily_order_ids');
    }

    $result = bakery_driver_transfer_assignments(
        $db,
        $dailyOrderIds,
        $toDriverId,
        $deliveryDate,
        $fromDriverId
    );

    $message = $result['transferred_count'] . ' stop'
        . ($result['transferred_count'] === 1 ? '' : 's')
        . ' moved to ' . $result['to_driver_name'];
    if (!empty($result['skipped'])) {
        $message .= ' (' . count($result['skipped']) . ' skipped)';
    }

    return [
        'success' => true,
        'message' => $message,
        'transferred_count' => $result['transferred_count'],
        'skipped' => $result['skipped'],
    ];
}

function bakery_driver_assignment_action_save_as_standing_route(PDO $db, array $input, ?array $user = null): array
{
    $deliveryDate = $input['delivery_date'];
    $dayOfWeek = date('N', strtotime($deliveryDate));
    $stmt = $db->prepare(" 
        SELECT doa.driver_id, do.customer_id, doa.route_order
        FROM daily_order_assignments doa
        JOIN daily_orders do ON do.id = doa.daily_order_id
        WHERE doa.delivery_date = ? AND do.order_date = ?
        ORDER BY doa.driver_id, doa.route_order, do.customer_id
    ");
    $stmt->execute([$deliveryDate, $deliveryDate]);
    $currentRoute = $stmt->fetchAll();
    if (empty($currentRoute)) {
        throw new Exception('There are no dated route assignments to save for this day.');
    }

    $db->beginTransaction();
    try {
        if ($dayOfWeek === 7) {
            $db->prepare('DELETE FROM standing_routes WHERE day_of_week IN (0, 7)')->execute();
        } else {
            $db->prepare('DELETE FROM standing_routes WHERE day_of_week = ?')->execute([$dayOfWeek]);
        }

        $insertRoute = $db->prepare(" 
            INSERT INTO standing_routes (day_of_week, driver_id, customer_id, route_order)
            VALUES (?, ?, ?, ?)
        ");
        $nextOrderByDriver = [];
        foreach ($currentRoute as $stop) {
            $driverId = (int)$stop['driver_id'];
            $nextOrderByDriver[$driverId] = ($nextOrderByDriver[$driverId] ?? 0) + 1;
            $insertRoute->execute([
                $dayOfWeek,
                $driverId,
                (int)$stop['customer_id'],
                $nextOrderByDriver[$driverId],
            ]);
        }

        $db->commit();

        return [
            'success' => true,
            'message' => 'Saved ' . count($currentRoute) . ' stops as the recurring ' . date('l', strtotime($deliveryDate)) . ' route.',
        ];
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

function bakery_driver_assignment_action_sync_driver_route(PDO $db, array $input, ?array $user = null): array
{
    $driverId = (int)$input['driver_id'];
    $deliveryDate = $input['delivery_date'];
    $driverRow = bakery_get_driver_by_id($db, $driverId);
    if (!$driverRow) {
        throw new Exception("Driver ID $driverId does not exist in the drivers table");
    }
    if ((int)($driverRow['archived'] ?? 0) === 1) {
        throw new Exception('Cannot modify routes for an archived driver. Restore the driver first.');
    }

    $dayOfWeek = date('N', strtotime($deliveryDate));
    $stmt = $db->prepare(" 
        SELECT sr.customer_id, c.name AS customer_name, sr.route_order
        FROM standing_routes sr
        JOIN customers c ON c.id = sr.customer_id
        " . bakery_sfb_ops_origin_clause('c', $db) . "
        WHERE sr.driver_id = ?
          AND CASE WHEN sr.day_of_week = 0 THEN 7 ELSE sr.day_of_week END = ?
        ORDER BY COALESCE(sr.route_order, 2147483647), c.name
    ");
    $stmt->execute([$driverId, $dayOfWeek]);
    $routeStops = $stmt->fetchAll();
    if (empty($routeStops)) {
        throw new Exception('This driver has no standing-route stops for ' . date('l', strtotime($deliveryDate)) . '.');
    }

    bakery_generate_daily_orders_from_standing($db, $deliveryDate, [
        'overwrite_changed' => false,
        'record_event' => true,
        'assign_routes' => false,
    ]);

    $findOrder = $db->prepare('SELECT id FROM daily_orders WHERE customer_id = ? AND order_date = ?');
    $createOrder = $db->prepare(
        "INSERT IGNORE INTO daily_orders (customer_id, order_date, status, total_amount)
         VALUES (?, ?, 'pending', 0)"
    );
    $assignments = [];
    foreach ($routeStops as $index => $stop) {
        $findOrder->execute([$stop['customer_id'], $deliveryDate]);
        $dailyOrderId = (int)$findOrder->fetchColumn();
        if ($dailyOrderId <= 0) {
            $createOrder->execute([$stop['customer_id'], $deliveryDate]);
            $findOrder->execute([$stop['customer_id'], $deliveryDate]);
            $dailyOrderId = (int)$findOrder->fetchColumn();
        }
        $assignments[] = [
            'daily_order_id' => $dailyOrderId,
            'route_order' => $index + 1,
        ];
    }

    $result = bakery_driver_assign_orders($db, $driverId, $deliveryDate, $assignments, 'append');

    return [
        'success' => true,
        'message' => 'Restored ' . $result['stop_count'] . ' missing standing-route stop'
            . ($result['stop_count'] === 1 ? '' : 's') . ' for ' . $driverRow['name'] . '.',
    ];
}

function bakery_driver_assignment_action_create_orders_and_assign(PDO $db, array $input, ?array $user = null): array
{
    $deliveryDate = $input['delivery_date'];
    $result = bakery_driver_assign_from_standing_routes($db, $deliveryDate);

    return [
        'success' => true,
        'message' => bakery_t('driver_assignment.build_success', [
            'count' => $result['stop_count'],
            'date' => date('l, F j, Y', strtotime($deliveryDate)),
        ]),
        'assignments' => $result['assignments'],
        'demand' => $result['demand'],
    ];
}

function bakery_driver_assignment_action_save_standing_route(PDO $db, array $input, ?array $user = null): array
{
    $customerId = (int)$input['customer_id'];
    $dayOfWeek = bakery_normalize_standing_day((int)$input['day_of_week']);
    $driverId = (int)$input['driver_id'];

    if ($customerId <= 0) {
        throw new Exception('Invalid customer ID');
    }
    if ($dayOfWeek < 1 || $dayOfWeek > 7) {
        throw new Exception('Invalid day of week');
    }

    $dayClause = $dayOfWeek === 7 ? 'IN (0, 7)' : '= ?';
    $stmt = $db->prepare("DELETE FROM standing_routes WHERE customer_id = ? AND day_of_week $dayClause");
    $stmt->execute($dayOfWeek === 7 ? [$customerId] : [$customerId, $dayOfWeek]);

    if ($driverId > 0) {
        $driverRow = bakery_get_driver_by_id($db, $driverId);
        if (!$driverRow) {
            throw new Exception('Invalid driver selected');
        }
        if ((int)($driverRow['archived'] ?? 0) === 1) {
            throw new Exception('Cannot assign an archived driver to a standing route');
        }
        if ($dayOfWeek === 7) {
            $db->prepare('DELETE FROM standing_routes WHERE customer_id = ? AND day_of_week = 0')
                ->execute([$customerId]);
        }
        $stmt = $db->prepare('INSERT INTO standing_routes (driver_id, customer_id, day_of_week) VALUES (?, ?, ?)');
        $stmt->execute([$driverId, $customerId, $dayOfWeek]);
    }

    return ['success' => true];
}

function bakery_driver_assignment_action_add_customer_to_route(PDO $db, array $input, ?array $user = null): array
{
    $customerId = (int)$input['customer_id'];
    $driverId = (int)$input['driver_id'];
    $deliveryDate = $input['delivery_date'] ?? '';
    // A customer added from the dated route is a one-time stop unless
    // the dispatcher explicitly chooses to make it recurring.
    $saveStandingRoute = ($input['save_standing_route'] ?? '0') === '1';
    $applyPanDulce = $saveStandingRoute && ($input['apply_pan_dulce'] ?? '0') === '1';
    $standingOrderLines = json_decode($input['standing_order_lines'] ?? '[]', true);
    if (!is_array($standingOrderLines)) {
        $standingOrderLines = [];
    }
    if (!$saveStandingRoute) {
        $standingOrderLines = [];
    }

    $result = bakery_driver_add_customer_to_route(
        $db,
        $customerId,
        $driverId,
        $deliveryDate,
        $saveStandingRoute,
        $standingOrderLines,
        $applyPanDulce
    );

    return [
        'success' => true,
        'message' => $result['message'],
        'daily_order_id' => $result['daily_order_id'],
    ];
}

function bakery_driver_assignment_action_remove_daily_order(PDO $db, array $input, ?array $user = null): array
{
    $dailyOrderId = (int)$input['daily_order_id'];
    $deliveryDate = $input['delivery_date'] ?? '';
    $confirmed = ($input['confirm_delivered'] ?? '0') === '1';
    $result = bakery_remove_empty_dated_order($db, $dailyOrderId, $deliveryDate, $confirmed);
    if ($result['requires_confirmation']) {
        return [
            'success' => false,
            'requires_delivered_confirmation' => true,
            'status' => $result['status'],
        ];
    }

    return ['success' => true, 'message' => $result['message']];
}

function bakery_driver_assignment_dispatch(PDO $db, array $input, ?array $user = null): array
{
    $action = (string)($input['action'] ?? '');
    switch ($action) {
        case 'assign_orders':
            return bakery_driver_assignment_action_assign_orders($db, $input, $user);
        case 'get_optimized_route':
            return bakery_driver_assignment_action_get_optimized_route($db, $input, $user);
        case 'update_delivery_time':
            return bakery_driver_assignment_action_update_delivery_time($db, $input, $user);
        case 'remove_assignment':
            return bakery_driver_assignment_action_remove_assignment($db, $input, $user);
        case 'transfer_assignments':
            return bakery_driver_assignment_action_transfer_assignments($db, $input, $user);
        case 'save_as_standing_route':
            return bakery_driver_assignment_action_save_as_standing_route($db, $input, $user);
        case 'sync_driver_route':
            return bakery_driver_assignment_action_sync_driver_route($db, $input, $user);
        case 'create_orders_and_assign':
            return bakery_driver_assignment_action_create_orders_and_assign($db, $input, $user);
        case 'save_standing_route':
            return bakery_driver_assignment_action_save_standing_route($db, $input, $user);
        case 'add_customer_to_route':
            return bakery_driver_assignment_action_add_customer_to_route($db, $input, $user);
        case 'remove_daily_order':
            return bakery_driver_assignment_action_remove_daily_order($db, $input, $user);
        default:
            throw new Exception('Invalid action');
    }
}
