<?php
/**
 * Pan Dulce standard quantity helpers — shared standing-order defaults.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * Pan Dulce products with configured standard quantities.
 *
 * @return array<int, array{id:int, name:string, standard_quantity:int}>
 */
function bakery_pan_dulce_standard_products(PDO $db) {
    $standardsAvailable = table_exists($db, 'pan_dulce_product_quantity_standards');
    $join = $standardsAvailable
        ? 'LEFT JOIN pan_dulce_product_quantity_standards pdqs ON pdqs.product_id = p.id'
        : '';
    $select = $standardsAvailable
        ? 'COALESCE(pdqs.standard_quantity, 12) AS standard_quantity'
        : '12 AS standard_quantity';

    $stmt = $db->query("
        SELECT p.id, p.name, {$select}
        FROM products p
        JOIN dough_types dt ON p.dough_type_id = dt.id
        JOIN product_lines pl ON dt.product_line_id = pl.id
        {$join}
        WHERE pl.name = 'Pan Dulce'
        ORDER BY p.name
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Route weekdays for a customer (1=Mon .. 7=Sun).
 *
 * @return int[]
 */
function bakery_customer_standing_route_days(PDO $db, $customerId) {
    $stmt = $db->prepare('
        SELECT DISTINCT CASE WHEN day_of_week = 0 THEN 7 ELSE day_of_week END AS day_of_week
        FROM standing_routes
        WHERE customer_id = ?
        ORDER BY day_of_week
    ');
    $stmt->execute([(int)$customerId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Apply standard Pan Dulce standing order for a customer on route days.
 *
 * Typical Pan Dulce customers with a route but no standing order get the
 * configured 1× standard quantity for every Pan Dulce product.
 *
 * @param int[]|null $routeDays Limit to these weekdays; null = all route days.
 * @return array{updated:int, products:int, days:int[]}
 */
function bakery_apply_pan_dulce_standing_standard(PDO $db, $customerId, $multiplier = 1.0, $routeDays = null) {
    $customerId = (int)$customerId;
    if ($routeDays === null) {
        $routeDays = bakery_customer_standing_route_days($db, $customerId);
    } else {
        $routeDays = array_values(array_filter(
            array_map('intval', (array)$routeDays),
            static function ($day) {
                return $day >= 1 && $day <= 7;
            }
        ));
    }

    if ($routeDays === []) {
        throw new InvalidArgumentException('Customer has no standing route days');
    }

    $products = bakery_pan_dulce_standard_products($db);
    if ($products === []) {
        throw new InvalidArgumentException('No Pan Dulce products with standard quantities configured');
    }

    $upsert = $db->prepare('
        INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
    ');

    $updated = 0;
    $multiplier = max(0.1, (float)$multiplier);
    foreach ($routeDays as $day) {
        foreach ($products as $product) {
            $standard = (int)$product['standard_quantity'];
            if ($standard <= 0) {
                continue;
            }
            $qty = max(1, (int)round($standard * $multiplier));
            $upsert->execute([$customerId, (int)$product['id'], $day, $qty]);
            $updated++;
        }
    }

    return [
        'updated' => $updated,
        'products' => count($products),
        'days' => $routeDays,
    ];
}

/**
 * Synthetic Pan Dulce demand lines when no standing or daily order exists yet.
 *
 * Used for operating demand when a routed customer has no forecast configured.
 *
 * @return array<int, array{product_id:int, product_name:string, quantity:int}>
 */
function bakery_pan_dulce_standard_demand_lines(PDO $db, $multiplier = 1.0) {
    $lines = [];
    $multiplier = max(0.1, (float)$multiplier);
    foreach (bakery_pan_dulce_standard_products($db) as $product) {
        $standard = (int)$product['standard_quantity'];
        if ($standard <= 0) {
            continue;
        }
        $qty = max(1, (int)round($standard * $multiplier));
        $lines[] = [
            'product_id' => (int)$product['id'],
            'product_name' => (string)$product['name'],
            'quantity' => $qty,
        ];
    }
    return $lines;
}

/**
 * Apply standard Pan Dulce quantities to a single dated daily order.
 *
 * @return array{updated:int, products:int, customer_id:int, order_date:string, customer_name:string}
 */
function bakery_apply_pan_dulce_daily_standard(PDO $db, $orderId, $multiplier = 1.0) {
    $orderId = (int)$orderId;

    $stmt = $db->prepare('
        SELECT do.customer_id, do.order_date, c.default_pan_dulce_price, c.name AS customer_name
        FROM daily_orders do
        JOIN customers c ON c.id = do.customer_id
        WHERE do.id = ?
    ');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new InvalidArgumentException('Daily order not found');
    }

    $products = bakery_pan_dulce_standard_products($db);
    if ($products === []) {
        throw new InvalidArgumentException('No Pan Dulce products with standard quantities configured');
    }

    $priceStmt = $db->prepare('SELECT price FROM products WHERE id = ?');
    $upsert = $db->prepare('
        INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        quantity = VALUES(quantity),
        unit_price = VALUES(unit_price),
        line_total = VALUES(quantity) * VALUES(unit_price)
    ');

    $panDulcePrice = !empty($order['default_pan_dulce_price'])
        ? (float)$order['default_pan_dulce_price']
        : null;
    $multiplier = max(0.1, (float)$multiplier);
    $updated = 0;

    foreach ($products as $product) {
        $standard = (int)$product['standard_quantity'];
        if ($standard <= 0) {
            continue;
        }

        $qty = max(1, (int)round($standard * $multiplier));
        $priceStmt->execute([(int)$product['id']]);
        $unitPrice = (float)($priceStmt->fetchColumn() ?: 0);
        if ($panDulcePrice !== null) {
            $unitPrice = $panDulcePrice;
        }

        $lineTotal = $qty * $unitPrice;
        $upsert->execute([$orderId, (int)$product['id'], $qty, $unitPrice, $lineTotal]);
        $updated++;
    }

    return [
        'updated' => $updated,
        'products' => count($products),
        'customer_id' => (int)$order['customer_id'],
        'order_date' => (string)$order['order_date'],
        'customer_name' => (string)$order['customer_name'],
    ];
}
