<?php
/**
 * Minimal CLI test harness for local bakerysf_local (Checkpoint 0C).
 * No PHPUnit / Composer required.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: characterization tests must run with APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();

$GLOBALS['TEST_PASS'] = 0;
$GLOBALS['TEST_FAIL'] = 0;
$GLOBALS['TEST_FINDINGS'] = [];

function assert_true($condition, $message) {
    if ($condition) {
        echo "PASS  $message\n";
        $GLOBALS['TEST_PASS']++;
        return true;
    }
    echo "FAIL  $message\n";
    $GLOBALS['TEST_FAIL']++;
    return false;
}

function assert_eq($expected, $actual, $message) {
    $ok = $expected === $actual;
    if (!$ok) {
        $message .= ' (expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true) . ')';
    }
    return assert_true($ok, $message);
}

function finding($severity, $detail) {
    $GLOBALS['TEST_FINDINGS'][] = ['severity' => $severity, 'detail' => $detail];
    echo "NOTE  [$severity] $detail\n";
}

/**
 * Canonical standing day from PHP date('N'): 1=Mon .. 7=Sun.
 */
function daily_orders_php_n_to_db_day($phpN) {
    return (int)$phpN;
}

function standing_save(PDO $db, $customerId, $productId, $dayOfWeek, $quantity) {
    if ($quantity > 0) {
        $stmt = $db->prepare("
            INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = ?
        ");
        $stmt->execute([$customerId, $productId, $dayOfWeek, $quantity, $quantity]);
    } else {
        $stmt = $db->prepare(
            "DELETE FROM standing_orders WHERE customer_id = ? AND product_id = ? AND day_of_week = ?"
        );
        $stmt->execute([$customerId, $productId, $dayOfWeek]);
    }
}

function standing_qty(PDO $db, $customerId, $productId, $dayOfWeek) {
    $stmt = $db->prepare(
        "SELECT quantity FROM standing_orders WHERE customer_id=? AND product_id=? AND day_of_week=?"
    );
    $stmt->execute([$customerId, $productId, $dayOfWeek]);
    $row = $stmt->fetch();
    return $row ? (int)$row['quantity'] : null;
}

/**
 * Generate daily orders using CURRENT daily_orders.php day conversion + join rules.
 */
function generate_from_standing(PDO $db, $date) {
    $phpDayOfWeek = (int)date('N', strtotime($date));
    $dbDayOfWeek = daily_orders_php_n_to_db_day($phpDayOfWeek);
    $dayClause = bakery_standing_day_in_clause($dbDayOfWeek);

    $stmt = $db->prepare("
        SELECT so.customer_id, so.product_id, so.quantity,
               COALESCE(p.price, 0) as price,
               c.default_pan_dulce_price,
               pl.name as product_line_name
        FROM standing_orders so
        JOIN customers c ON so.customer_id = c.id
        JOIN products p ON so.product_id = p.id
        JOIN dough_types dt ON p.dough_type_id = dt.id
        JOIN product_lines pl ON dt.product_line_id = pl.id
        WHERE so.day_of_week {$dayClause['sql']}
        ORDER BY so.customer_id, so.product_id
    ");
    $stmt->execute($dayClause['values']);
    $standingOrders = $stmt->fetchAll();

    $ordersCreated = 0;
    $itemsCreated = 0;
    $db->beginTransaction();
    try {
        $customerOrders = [];
        foreach ($standingOrders as $order) {
            $customerOrders[$order['customer_id']][] = $order;
        }
        foreach ($customerOrders as $customerId => $orders) {
            $ins = $db->prepare(
                "INSERT IGNORE INTO daily_orders (customer_id, order_date, status, total_amount)
                 VALUES (?, ?, 'pending', 0)"
            );
            $ins->execute([$customerId, $date]);
            if ($ins->rowCount() > 0) {
                $ordersCreated++;
            }
            $oidStmt = $db->prepare(
                "SELECT id FROM daily_orders WHERE customer_id=? AND order_date=?"
            );
            $oidStmt->execute([$customerId, $date]);
            $dailyOrderId = (int)$oidStmt->fetchColumn();

            foreach ($orders as $order) {
                $unit = (float)$order['price'];
                if (($order['product_line_name'] ?? '') === 'Pan Dulce' && $order['default_pan_dulce_price'] !== null) {
                    $unit = (float)$order['default_pan_dulce_price'];
                }
                $line = $unit * (int)$order['quantity'];
                $item = $db->prepare("
                    INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantity = VALUES(quantity),
                        unit_price = VALUES(unit_price), line_total = VALUES(line_total)
                ");
                $item->execute([$dailyOrderId, $order['product_id'], $order['quantity'], $unit, $line]);
                $itemsCreated++;
            }
            $sum = $db->prepare(
                "SELECT COALESCE(SUM(line_total),0) FROM daily_order_items WHERE daily_order_id=?"
            );
            $sum->execute([$dailyOrderId]);
            $tot = $db->prepare("UPDATE daily_orders SET total_amount=? WHERE id=?");
            $tot->execute([$sum->fetchColumn(), $dailyOrderId]);
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

    return [
        'php_n' => $phpDayOfWeek,
        'db_day' => $dbDayOfWeek,
        'standing_rows' => count($standingOrders),
        'orders_created' => $ordersCreated,
        'items_created' => $itemsCreated,
    ];
}

return $db;
