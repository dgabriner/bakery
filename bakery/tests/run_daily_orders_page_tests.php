<?php
/**
 * Characterization: Daily Orders page helpers (generate / one-time / edit qty).
 *
 * §4 invariants asserted by name:
 *   - Dated beats standing, per customer
 *   - Re-generation preserves dated edits unless overwrite_changed
 *   - Inactive customers never generate
 *
 * CLI / local bakerysf_test only. Cleans up synthetic customers/dates.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/daily_order_generation.php';
require_once $root . '/includes/customer_order_mutations.php';
require_once $root . '/includes/demand_review.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: tests must run with APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $msg) use (&$pass, &$fail): void {
    if ($ok) {
        echo "PASS  $msg\n";
        $pass++;
    } else {
        echo "FAIL  $msg\n";
        $fail++;
    }
};

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['user_id'] = 1;
$_SESSION['user_email'] = 'daily-orders-char@example.test';
$_SESSION['user_display_name'] = 'Daily Orders Characterization';
$_SESSION['user_role_slug'] = 'administrator';

$date = date('Y-m-d', strtotime('+47 days'));
$weekday = bakery_standing_day_from_date($date);
echo "Test date: $date (standing day $weekday)\n";
echo "INVARIANT  Dated beats standing, per customer\n";
echo "INVARIANT  Re-generation preserves dated edits unless overwrite_changed\n";
echo "INVARIANT  Inactive customers never generate\n";

$productId = (int)$db->query(
    "SELECT p.id
     FROM products p
     JOIN dough_types dt ON dt.id = p.dough_type_id
     JOIN product_lines pl ON pl.id = dt.product_line_id
     ORDER BY p.id
     LIMIT 1"
)->fetchColumn();
if ($productId <= 0) {
    fwrite(STDERR, "Need a catalog product with dough/line on bakerysf_test\n");
    exit(1);
}

$customerIds = [];
$cleanup = static function () use ($db, $date, &$customerIds): void {
    if ($customerIds === []) {
        return;
    }
    $ph = implode(',', array_fill(0, count($customerIds), '?'));
    $orderIds = $db->prepare("SELECT id FROM daily_orders WHERE customer_id IN ($ph) AND order_date = ?");
    $orderIds->execute(array_merge($customerIds, [$date]));
    $ids = array_map('intval', $orderIds->fetchAll(PDO::FETCH_COLUMN));
    if ($ids) {
        $oph = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("DELETE FROM daily_order_items WHERE daily_order_id IN ($oph)")->execute($ids);
        if (table_exists($db, 'daily_order_assignments')) {
            $db->prepare("DELETE FROM daily_order_assignments WHERE daily_order_id IN ($oph)")->execute($ids);
        }
        $db->prepare("DELETE FROM daily_orders WHERE id IN ($oph)")->execute($ids);
    }
    $db->prepare("DELETE FROM standing_orders WHERE customer_id IN ($ph)")->execute($customerIds);
    if (table_exists($db, 'standing_routes')) {
        $db->prepare("DELETE FROM standing_routes WHERE customer_id IN ($ph)")->execute($customerIds);
    }
    if (function_exists('bakery_operational_events_ready') && bakery_operational_events_ready($db)) {
        $db->prepare('DELETE FROM operational_events WHERE operational_date = ?')->execute([$date]);
    }
    $db->prepare("DELETE FROM customers WHERE id IN ($ph)")->execute($customerIds);
    $customerIds = [];
};

$cleanup();

$insertCustomer = $db->prepare(
    "INSERT INTO customers (name, address, is_active, sfb_origin) VALUES (?, ?, ?, 'human')"
);
$insertCustomer->execute(['Char Daily Active Cafe', '1 Char Lane', 1]);
$activeId = (int)$db->lastInsertId();
$customerIds[] = $activeId;
$insertCustomer->execute(['Char Daily Inactive Cafe', '2 Char Lane', 0]);
$inactiveId = (int)$db->lastInsertId();
$customerIds[] = $inactiveId;

$standing = $db->prepare(
    'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, ?)'
);
$standing->execute([$activeId, $productId, $weekday, 6]);
$standing->execute([$inactiveId, $productId, $weekday, 9]);

echo "\n=== Generate single date; inactive excluded ===\n";
$gen = bakery_generate_daily_orders_from_standing($db, $date, [
    'overwrite_changed' => false,
    'record_event' => false,
    'assign_routes' => false,
]);
$assert((int)($gen['orders_created'] ?? 0) >= 1, 'generate created at least one dated order');

$activeOrder = $db->prepare(
    'SELECT id FROM daily_orders WHERE customer_id = ? AND order_date = ? LIMIT 1'
);
$activeOrder->execute([$activeId, $date]);
$activeOrderId = (int)$activeOrder->fetchColumn();
$assert($activeOrderId > 0, 'active customer received a dated order (§4 dated beats standing, per customer)');

$inactiveOrder = $db->prepare(
    'SELECT COUNT(*) FROM daily_orders WHERE customer_id = ? AND order_date = ?'
);
$inactiveOrder->execute([$inactiveId, $date]);
$assert((int)$inactiveOrder->fetchColumn() === 0, 'inactive customer excluded from generation');

$itemQty = $db->prepare(
    'SELECT quantity FROM daily_order_items WHERE daily_order_id = ? AND product_id = ? LIMIT 1'
);
$itemQty->execute([$activeOrderId, $productId]);
$assert((int)$itemQty->fetchColumn() === 6, 'generated quantity matches standing template');

echo "\n=== Edit quantity recomputes total; re-generate preserves edit ===\n";
$edited = bakery_demand_set_dated_quantity($db, $activeId, $date, $productId, 23);
$assert((int)($edited['quantity'] ?? 0) === 23, 'dated quantity edit via demand helper');
$totalStmt = $db->prepare('SELECT total_amount FROM daily_orders WHERE id = ?');
$totalStmt->execute([$activeOrderId]);
$totalAfterEdit = (float)$totalStmt->fetchColumn();
$lineStmt = $db->prepare(
    'SELECT COALESCE(SUM(line_total), 0) FROM daily_order_items WHERE daily_order_id = ?'
);
$lineStmt->execute([$activeOrderId]);
$lineSum = (float)$lineStmt->fetchColumn();
$assert(abs($totalAfterEdit - $lineSum) < 0.001, 'edit quantity recomputes order total_amount from lines');

$regen = bakery_generate_daily_orders_from_standing($db, $date, [
    'overwrite_changed' => false,
    'record_event' => false,
    'assign_routes' => false,
]);
$itemQty->execute([$activeOrderId, $productId]);
$kept = (int)$itemQty->fetchColumn();
$assert($kept === 23, 're-generation preserves dated edits unless overwrite_changed (§4)');
$assert((int)($regen['items_preserved'] ?? 0) >= 1 || $kept === 23, 'generator reported preserve or quantity still edited');

echo "\n=== Create one-time dated order ===\n";
$insertCustomer->execute(['Char Daily One-Time Cafe', '3 Char Lane', 1]);
$oneTimeId = (int)$db->lastInsertId();
$customerIds[] = $oneTimeId;

$created = bakery_staff_create_dated_order($db, $oneTimeId, $date);
$assert(!empty($created['created']), 'staff create opens a one-time dated order');
$assert((int)($created['daily_order_id'] ?? 0) > 0, 'one-time order has an id');
$reopen = bakery_staff_create_dated_order($db, $oneTimeId, $date);
$assert(empty($reopen['created']), 'second create reopens existing dated order');
$assert((int)$reopen['daily_order_id'] === (int)$created['daily_order_id'], 'reopen returns the same daily_order_id');

$cleanup();

echo "\n=== daily_orders page characterization: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
