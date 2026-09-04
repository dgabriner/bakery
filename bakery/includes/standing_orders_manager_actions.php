<?php
/**
 * Standing Orders Manager action handlers — pure functions for page and API dispatch.
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_standing_orders_manager_action_save_order(PDO $db, array $input, ?array $user = null): array
{
    $customerId = (int)($input['customer_id'] ?? 0);
    $productId = (int)($input['product_id'] ?? 0);
    $dayOfWeek = (int)($input['day_of_week'] ?? 0);
    $quantity = (int)($input['quantity'] ?? 0);

    if (!bakery_sfb_ops_customer_allowed($db, $customerId)) {
        throw new RuntimeException('Synthetic SF Bakers cannot have standing orders');
    }

    if ($quantity > 0) {
        $stmt = $db->prepare('
            INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = ?
        ');
        $stmt->execute([$customerId, $productId, $dayOfWeek, $quantity, $quantity]);
    } else {
        $stmt = $db->prepare('DELETE FROM standing_orders WHERE customer_id = ? AND product_id = ? AND day_of_week = ?');
        $stmt->execute([$customerId, $productId, $dayOfWeek]);
    }

    return ['success' => true];
}

function bakery_standing_orders_manager_action_bulk_save(PDO $db, array $input, ?array $user = null): array
{
    $updates = json_decode((string)($input['updates'] ?? ''), true);
    if (!is_array($updates)) {
        throw new InvalidArgumentException('Invalid standing order updates');
    }

    $db->beginTransaction();
    try {
        $upsert = $db->prepare('
            INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = ?
        ');
        $delete = $db->prepare('DELETE FROM standing_orders WHERE customer_id = ? AND product_id = ? AND day_of_week = ?');

        foreach ($updates as $update) {
            $customerId = (int)$update['customer_id'];
            $productId = (int)$update['product_id'];
            $dayOfWeek = (int)$update['day_of_week'];
            $quantity = (int)$update['quantity'];

            if ($quantity > 0) {
                $upsert->execute([$customerId, $productId, $dayOfWeek, $quantity, $quantity]);
            } else {
                $delete->execute([$customerId, $productId, $dayOfWeek]);
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return ['success' => true, 'updated' => count($updates)];
}

function bakery_standing_orders_manager_action_load_customer_orders(PDO $db, array $input, ?array $user = null): array
{
    $customerId = (int)($input['customer_id'] ?? 0);

    $orders = $db->prepare('
        SELECT
            so.product_id,
            CASE WHEN so.day_of_week = 0 THEN 7 ELSE so.day_of_week END AS day_of_week,
            so.quantity,
            p.name as product_name,
            p.price
        FROM standing_orders so
        JOIN products p ON so.product_id = p.id
        WHERE so.customer_id = ?
        ORDER BY so.day_of_week, p.name
    ');
    $orders->execute([$customerId]);
    $orderData = $orders->fetchAll();

    $formattedOrders = [];
    foreach ($orderData as $order) {
        if (!isset($formattedOrders[$order['product_id']])) {
            $formattedOrders[$order['product_id']] = [];
        }
        $formattedOrders[$order['product_id']][$order['day_of_week']] = [
            'quantity' => $order['quantity'],
            'product_name' => $order['product_name'],
            'price' => $order['price'],
        ];
    }

    return ['success' => true, 'orders' => $formattedOrders];
}

function bakery_standing_orders_manager_action_check_performance(PDO $db, array $input, ?array $user = null): array
{
    $startTime = microtime(true);

    $indexChecks = [
        'standing_orders' => "SHOW INDEX FROM standing_orders WHERE Key_name = 'unique_order'",
        'standing_routes' => "SHOW INDEX FROM standing_routes WHERE Key_name = 'customer_id'",
        'products' => "SHOW INDEX FROM products WHERE Key_name = 'products_ibfk_1'",
        'dough_types' => "SHOW INDEX FROM dough_types WHERE Key_name = 'idx_dough_types_product_line'",
    ];

    $missingIndexes = [];
    foreach ($indexChecks as $table => $checkSql) {
        $result = $db->query($checkSql);
        if ($result->rowCount() === 0) {
            $missingIndexes[] = $table;
        }
    }

    $loadTime = number_format((microtime(true) - $startTime) * 1000, 2);

    return [
        'success' => true,
        'load_time' => $loadTime,
        'missing_indexes' => $missingIndexes,
        'recommendation' => count($missingIndexes) > 0
            ? 'Database indexes missing - contact administrator'
            : 'Database optimized',
    ];
}

function bakery_standing_orders_manager_action_diagnostic_routes(PDO $db, array $input, ?array $user = null): array
{
    $diagnostics = [];

    $routesQuery = $db->query('
        SELECT
            COUNT(*) as total_routes,
            COUNT(DISTINCT customer_id) as customers_with_routes,
            COUNT(DISTINCT day_of_week) as unique_days,
            GROUP_CONCAT(DISTINCT day_of_week ORDER BY day_of_week) as all_days
        FROM standing_routes
    ');
    $diagnostics['standing_routes'] = $routesQuery->fetch();

    $customerQuery = $db->query('
        SELECT
            c.id,
            c.name,
            GROUP_CONCAT(DISTINCT sr.day_of_week ORDER BY sr.day_of_week) as route_days,
            COUNT(DISTINCT sr.day_of_week) as route_count
        FROM customers c
        LEFT JOIN standing_routes sr ON c.id = sr.customer_id
        GROUP BY c.id, c.name
        HAVING route_count > 0
        LIMIT 10
    ');
    $diagnostics['sample_customers_with_routes'] = $customerQuery->fetchAll();

    $tableCheck = $db->query('SELECT COUNT(*) as count FROM standing_routes')->fetch();
    $diagnostics['routes_table_count'] = $tableCheck['count'];

    return [
        'success' => true,
        'diagnostics' => $diagnostics,
    ];
}

function bakery_standing_orders_manager_action_apply_pan_dulce_standard(PDO $db, array $input, ?array $user = null): array
{
    $customerId = (int)($input['customer_id'] ?? 0);
    $multiplier = isset($input['multiplier']) ? (float)$input['multiplier'] : 1.0;
    $dayOfWeek = isset($input['day_of_week']) && $input['day_of_week'] !== ''
        ? (int)$input['day_of_week']
        : null;
    $routeDays = $dayOfWeek !== null ? [$dayOfWeek] : null;

    $db->beginTransaction();
    try {
        $result = bakery_apply_pan_dulce_standing_standard($db, $customerId, $multiplier, $routeDays);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return ['success' => true] + $result;
}

function bakery_standing_orders_manager_action_copy_orders(PDO $db, array $input, ?array $user = null): array
{
    $sourceCustomerId = (int)($input['source_customer_id'] ?? 0);
    $targetCustomerId = (int)($input['target_customer_id'] ?? 0);
    $selectedDays = json_decode((string)($input['selected_days'] ?? ''), true);
    if (!is_array($selectedDays)) {
        throw new InvalidArgumentException('Invalid selected days');
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare('
            SELECT product_id, day_of_week, quantity
            FROM standing_orders
            WHERE customer_id = ? AND day_of_week IN (' . implode(',', array_fill(0, count($selectedDays), '?')) . ')
        ');
        $stmt->execute(array_merge([$sourceCustomerId], $selectedDays));
        $sourceOrders = $stmt->fetchAll();

        $copiedCount = 0;
        foreach ($sourceOrders as $order) {
            $stmt = $db->prepare('
                INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = ?
            ');
            $stmt->execute([
                $targetCustomerId,
                $order['product_id'],
                $order['day_of_week'],
                $order['quantity'],
                $order['quantity'],
            ]);
            $copiedCount++;
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return ['success' => true, 'copied' => $copiedCount];
}

function bakery_standing_orders_manager_dispatch(PDO $db, array $input, ?array $user = null): array
{
    $action = (string)($input['action'] ?? '');
    switch ($action) {
        case 'save_order':
            return bakery_standing_orders_manager_action_save_order($db, $input, $user);
        case 'bulk_save':
            return bakery_standing_orders_manager_action_bulk_save($db, $input, $user);
        case 'load_customer_orders':
            return bakery_standing_orders_manager_action_load_customer_orders($db, $input, $user);
        case 'check_performance':
            return bakery_standing_orders_manager_action_check_performance($db, $input, $user);
        case 'diagnostic_routes':
            return bakery_standing_orders_manager_action_diagnostic_routes($db, $input, $user);
        case 'apply_pan_dulce_standard':
            return bakery_standing_orders_manager_action_apply_pan_dulce_standard($db, $input, $user);
        case 'copy_orders':
            return bakery_standing_orders_manager_action_copy_orders($db, $input, $user);
        default:
            throw new Exception('Invalid action');
    }
}
