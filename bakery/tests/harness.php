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
require_once $root . '/includes/test_target_guard.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: characterization tests must run with APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

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
 * Generate daily orders using the shared production generator.
 */
function generate_from_standing(PDO $db, $date) {
    require_once dirname(__DIR__) . '/includes/daily_order_generation.php';
    $phpDayOfWeek = (int)date('N', strtotime($date));
    $result = bakery_generate_daily_orders_from_standing($db, $date, [
        'overwrite_changed' => true,
        'record_event' => false,
        // The QA suite inserts its own dated assignments after generation.
        'assign_routes' => false,
    ]);
    return [
        'php_n' => $phpDayOfWeek,
        'db_day' => (int)$result['db_day'],
        'standing_rows' => (int)$result['standing_rows'],
        'orders_created' => (int)$result['orders_created'],
        'items_created' => (int)$result['items_created'] + (int)$result['items_updated'],
    ];
}

return $db;
