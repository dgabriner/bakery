<?php
/**
 * Skip a dated order for one day: one write, standing order unchanged, undo from the log.
 *
 * Database: bakerysf_test only. Never Staging or Live.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';

/** @var PDO $db */
$db = require __DIR__ . '/harness.php';
bakery_assert_local_test_target($db);

require_once $root . '/includes/auth.php';
require_once $root . '/includes/customer_order_mutations.php';
require_once $root . '/includes/daily_order_generation.php';
require_once $root . '/includes/daily_orders_actions.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

echo "=== Daily order skip page contract ===\n";
$pageSrc = (string)file_get_contents($root . '/daily_orders.php');
$skipPath = $root . '/includes/daily_order_skip.php';
$skipSrc = is_file($skipPath) ? (string)file_get_contents($skipPath) : '';
$actionsSrc = (string)file_get_contents($root . '/includes/daily_orders_actions.php');
$jsStart = strpos($pageSrc, 'function skipDatedDay');
$jsEnd = strpos($pageSrc, 'function showCreateDatedOrderModal');
$jsBody = ($jsStart !== false && $jsEnd !== false && $jsEnd > $jsStart)
    ? substr($pageSrc, $jsStart, $jsEnd - $jsStart)
    : '';
assert_true($jsBody !== '', 'skipDatedDay is on the daily orders page');
assert_true(strpos($jsBody, 'location.reload') === false, 'skip uses one request and does not reload the page');
assert_true(strpos($jsBody, 'csrf_token') !== false, 'skip POST sends the CSRF token');
assert_true(strpos($jsBody, 'skip_dated_day') !== false, 'per-order skip action is posted');
assert_true(strpos($jsBody, 'undo_skip_dated_day') !== false, 'undo skip action is posted');
assert_true(strpos($jsBody, 'skip_unassigned_stops') !== false, 'bulk unassigned skip action is posted');
assert_true(strpos($pageSrc, 'daily_orders.skip_day') !== false, 'per-order button uses the translation key');
assert_true(strpos($pageSrc, 'daily_orders.skip_unassigned') !== false, 'bulk button uses the translation key');
assert_true(strpos($actionsSrc, 'skip_dated_day') !== false, 'dispatch handles skip_dated_day');
assert_true(strpos($skipSrc, 'beginTransaction') !== false, 'skip runs in a transaction');
assert_true(strpos($skipSrc, 'standing_orders') === false, 'skip does not write standing orders');
assert_true(strpos($skipSrc, 'bakery_audit_daily_line_diff') !== false, 'skip writes the field audit');

$en = include $root . '/lang/en.php';
$es = include $root . '/lang/es.php';
foreach ([
    'daily_orders.skip_day',
    'daily_orders.skip_day_help',
    'daily_orders.skip_day_done',
    'daily_orders.skip_day_undo',
    'daily_orders.skip_unassigned',
    'daily_orders.skip_unassigned_help',
    'daily_orders.skip_unassigned_line',
    'daily_orders.skip_unassigned_confirm',
] as $key) {
    $enText = (string)($en[$key] ?? '');
    $esText = (string)($es[$key] ?? '');
    assert_true($enText !== '' && $esText !== '' && $enText !== $esText, 'translated key ' . $key);
    assert_true($enText !== '' && strpos($enText, "\u{2013}") === false && strpos($enText, "\u{2014}") === false, 'English ' . $key . ' has no dash');
    assert_true($esText !== '' && strpos($esText, "\u{2013}") === false && strpos($esText, "\u{2014}") === false, 'Spanish ' . $key . ' has no dash');
}

$stamp = 'skp' . bin2hex(random_bytes(3));
$date = '2099-03-16';
$otherDate = '2099-03-17';
$weekday = bakery_standing_day_from_date($date);
$customerIds = [];
$orderIds = [];
$userIds = [];
$driverIds = [];

$cleanup = static function () use ($db, &$customerIds, &$orderIds, &$userIds, &$driverIds): void {
    if ($orderIds !== []) {
        $in = implode(',', array_map('intval', $orderIds));
        if (table_exists($db, 'operational_events')) {
            $db->exec("DELETE FROM operational_events WHERE daily_order_id IN ($in)");
        }
        if (table_exists($db, 'field_audit')) {
            $lineIds = $db->query("SELECT id FROM daily_order_items WHERE daily_order_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
            if ($lineIds !== []) {
                $lin = implode(',', array_map('intval', $lineIds));
                $db->exec("DELETE FROM field_audit WHERE entity_type = 'daily_order_item' AND entity_id IN ($lin)");
            }
        }
        $db->exec("DELETE FROM daily_orders WHERE id IN ($in)");
    }
    if ($customerIds !== []) {
        $in = implode(',', array_map('intval', $customerIds));
        $db->exec("DELETE FROM standing_orders WHERE customer_id IN ($in)");
        $db->exec("DELETE FROM customers WHERE id IN ($in)");
    }
    if ($driverIds !== []) {
        $in = implode(',', array_map('intval', $driverIds));
        $db->exec("DELETE FROM drivers WHERE id IN ($in)");
    }
    if ($userIds !== []) {
        $in = implode(',', array_map('intval', $userIds));
        if (table_exists($db, 'operational_events')) {
            $db->exec("DELETE FROM operational_events WHERE actor_user_id IN ($in)");
        }
        $db->exec("DELETE FROM users WHERE id IN ($in)");
    }
};

try {
    echo "=== Staff and catalog ===\n";
    $adminRole = (int)$db->query("SELECT id FROM roles WHERE slug = 'administrator' LIMIT 1")->fetchColumn();
    assert_true($adminRole > 0, 'administrator role exists');
    $codeStmt = $db->prepare('SELECT id FROM users WHERE login_code = ? LIMIT 1');
    $code = '0000';
    for ($try = 0; $try < 40; $try++) {
        $code = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $codeStmt->execute([$code]);
        if (!$codeStmt->fetchColumn()) {
            break;
        }
    }
    $insertUser = $db->prepare(
        'INSERT INTO users (email, password_hash, login_code, display_name, role_id, is_active) VALUES (?, ?, ?, ?, ?, 1)'
    );
    $insertUser->execute([$stamp . '@example.test', 'x', $code, $stamp . ' admin', $adminRole]);
    $adminId = (int)$db->lastInsertId();
    $userIds[] = $adminId;
    $staff = ['id' => $adminId, 'role_slug' => 'administrator'];
    $_SESSION['user_id'] = $adminId;
    $_SESSION['user_email'] = $stamp . '@example.test';
    $_SESSION['user_display_name'] = $stamp . ' admin';
    $_SESSION['user_role_slug'] = 'administrator';

    $products = $db->query('SELECT id, price FROM products ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_ASSOC);
    assert_true(count($products) === 2, 'bakerysf_test has two catalog products');
    $productA = (int)$products[0]['id'];
    $productB = (int)$products[1]['id'];

    $insertCustomer = $db->prepare(
        "INSERT INTO customers (name, address, is_active, sfb_origin) VALUES (?, ?, 1, 'human')"
    );
    $insertCustomer->execute([$stamp . ' Alpha', '1 Skip Lane']);
    $alphaId = (int)$db->lastInsertId();
    $customerIds[] = $alphaId;
    $insertCustomer->execute([$stamp . ' Bravo', '2 Skip Lane']);
    $bravoId = (int)$db->lastInsertId();
    $customerIds[] = $bravoId;
    $insertCustomer->execute([$stamp . ' Charlie', '3 Skip Lane']);
    $charlieId = (int)$db->lastInsertId();
    $customerIds[] = $charlieId;
    $insertCustomer->execute([$stamp . ' Delta', '4 Skip Lane']);
    $deltaId = (int)$db->lastInsertId();
    $customerIds[] = $deltaId;
    $insertCustomer->execute([$stamp . ' Echo', '5 Skip Lane']);
    $echoId = (int)$db->lastInsertId();
    $customerIds[] = $echoId;

    $standing = $db->prepare(
        'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, ?)'
    );
    $standing->execute([$alphaId, $productA, $weekday, 11]);
    $standing->execute([$alphaId, $productB, $weekday, 13]);

    $insertDriver = $db->prepare('INSERT INTO drivers (name) VALUES (?)');
    $insertDriver->execute([$stamp . ' driver']);
    $driverId = (int)$db->lastInsertId();
    $driverIds[] = $driverId;
    $insertDriver->execute([$stamp . ' other driver']);
    $otherDriverId = (int)$db->lastInsertId();
    $driverIds[] = $otherDriverId;

    $insertOrder = $db->prepare(
        'INSERT INTO daily_orders (customer_id, order_date, status, driver_id, total_amount) VALUES (?, ?, ?, ?, ?)'
    );
    $insertOrder->execute([$alphaId, $date, 'pending', null, 20]);
    $alphaOrder = (int)$db->lastInsertId();
    $orderIds[] = $alphaOrder;
    $insertOrder->execute([$alphaId, $otherDate, 'pending', null, 10]);
    $alphaOther = (int)$db->lastInsertId();
    $orderIds[] = $alphaOther;
    $insertOrder->execute([$bravoId, $date, 'pending', null, 18]);
    $bravoOrder = (int)$db->lastInsertId();
    $orderIds[] = $bravoOrder;
    $insertOrder->execute([$charlieId, $date, 'pending', $otherDriverId, 6]);
    $charlieOrder = (int)$db->lastInsertId();
    $orderIds[] = $charlieOrder;
    $insertOrder->execute([$deltaId, $date, 'pending', null, 16]);
    $deltaOrder = (int)$db->lastInsertId();
    $orderIds[] = $deltaOrder;
    $insertOrder->execute([$echoId, $date, 'invoiced', null, 20]);
    $echoOrder = (int)$db->lastInsertId();
    $orderIds[] = $echoOrder;

    $insertItem = $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)'
    );
    $insertItem->execute([$alphaOrder, $productA, 4, 2.50, 10]);
    $insertItem->execute([$alphaOrder, $productB, 7, 2.00, 14]);
    $insertItem->execute([$alphaOther, $productA, 5, 2.00, 10]);
    $insertItem->execute([$bravoOrder, $productA, 9, 2.00, 18]);
    $insertItem->execute([$charlieOrder, $productA, 3, 2.00, 6]);
    $insertItem->execute([$deltaOrder, $productA, 8, 2.00, 16]);
    $insertItem->execute([$echoOrder, $productA, 4, 5.00, 20]);

    $assign = $db->prepare(
        'INSERT INTO daily_order_assignments (daily_order_id, driver_id, delivery_date, route_order, delivery_status)
         VALUES (?, ?, ?, ?, ?)'
    );
    $assign->execute([$bravoOrder, $driverId, $date, 1, 'pending']);
    $assign->execute([$deltaOrder, $driverId, $date, 2, 'cancelled']);

    $qtyOf = static function (int $orderId, int $productId) use ($db): int {
        $stmt = $db->prepare('SELECT quantity FROM daily_order_items WHERE daily_order_id = ? AND product_id = ?');
        $stmt->execute([$orderId, $productId]);
        return (int)$stmt->fetchColumn();
    };
    $standingQty = static function (int $customerId, int $productId) use ($db, $weekday): int {
        $stmt = $db->prepare(
            'SELECT quantity FROM standing_orders WHERE customer_id = ? AND product_id = ? AND day_of_week = ?'
        );
        $stmt->execute([$customerId, $productId, $weekday]);
        return (int)$stmt->fetchColumn();
    };

    echo "=== Permission and date guard ===\n";
    $denied = false;
    try {
        bakery_daily_order_skip($db, $alphaOrder, $date, ['id' => $adminId, 'role_slug' => 'driver']);
    } catch (RuntimeException $e) {
        $denied = true;
    }
    assert_true($denied, 'a driver cannot skip a dated order');
    assert_eq(4, $qtyOf($alphaOrder, $productA), 'denied skip leaves the dated quantity');

    $wrongDate = false;
    try {
        bakery_daily_order_skip($db, $alphaOrder, $otherDate, $staff);
    } catch (RuntimeException $e) {
        $wrongDate = true;
    }
    assert_true($wrongDate, 'skip refuses an order that is not for the posted date');
    assert_eq(4, $qtyOf($alphaOrder, $productA), 'wrong date does not change this date');
    assert_eq(5, $qtyOf($alphaOther, $productA), 'wrong date does not change the other date');

    echo "=== One order, one transaction ===\n";
    $skipped = bakery_daily_orders_action_skip_dated_day($db, [
        'order_id' => $alphaOrder,
        'date' => $date,
    ], $staff);
    assert_true(!empty($skipped['success']), 'skip action succeeds');
    assert_eq(0, $qtyOf($alphaOrder, $productA), 'first line is 0');
    assert_eq(0, $qtyOf($alphaOrder, $productB), 'second line is 0');
    assert_eq(2, (int)($skipped['lines_changed'] ?? 0), 'both lines were changed in one result');
    assert_eq(5, $qtyOf($alphaOther, $productA), 'the same customer on another date is untouched');
    assert_eq(9, $qtyOf($bravoOrder, $productA), 'another customer on this date is untouched');
    assert_eq(11, $standingQty($alphaId, $productA), 'standing quantity for the first product is untouched');
    assert_eq(13, $standingQty($alphaId, $productB), 'standing quantity for the second product is untouched');
    $lineCount = (int)$db->query('SELECT COUNT(*) FROM daily_order_items WHERE daily_order_id = ' . $alphaOrder)->fetchColumn();
    assert_eq(2, $lineCount, 'zeroed lines stay on the dated order');
    $total = (float)$db->query('SELECT total_amount FROM daily_orders WHERE id = ' . $alphaOrder)->fetchColumn();
    assert_true(abs($total) < 0.001, 'order total is recomputed to 0');
    if (column_exists($db, 'daily_orders', 'standing_fill_blocked')) {
        $blocked = (int)$db->query('SELECT standing_fill_blocked FROM daily_orders WHERE id = ' . $alphaOrder)->fetchColumn();
        assert_eq(1, $blocked, 'standing fill is blocked so generation cannot refill this date');
    }

    $lineA = (int)$db->query(
        'SELECT id FROM daily_order_items WHERE daily_order_id = ' . $alphaOrder . ' AND product_id = ' . $productA
    )->fetchColumn();
    $audit = $db->prepare(
        "SELECT actor_user_id, action, old_value, new_value
         FROM field_audit
         WHERE entity_type = 'daily_order_item' AND entity_id = ? AND field = 'quantity'
         ORDER BY id DESC LIMIT 1"
    );
    $audit->execute([$lineA]);
    $auditRow = $audit->fetch(PDO::FETCH_ASSOC);
    assert_true(is_array($auditRow), 'the quantity change is in the field audit');
    assert_eq($adminId, (int)($auditRow['actor_user_id'] ?? 0), 'the audit row names the staff user');
    assert_eq('4', (string)($auditRow['old_value'] ?? ''), 'the audit row keeps the prior quantity');
    assert_eq('0', (string)($auditRow['new_value'] ?? ''), 'the audit row records 0');

    $event = $db->prepare(
        'SELECT actor_user_id, event_type, operational_date, customer_id, metadata
         FROM operational_events WHERE daily_order_id = ? ORDER BY id DESC LIMIT 1'
    );
    $event->execute([$alphaOrder]);
    $eventRow = $event->fetch(PDO::FETCH_ASSOC);
    assert_true(is_array($eventRow), 'the skip is in the operational log');
    assert_eq($adminId, (int)$eventRow['actor_user_id'], 'the operational log names the staff user');
    assert_eq('daily_order_day_skipped', (string)$eventRow['event_type'], 'the log uses the skip event');
    assert_eq($date, (string)$eventRow['operational_date'], 'the log is for this date');
    assert_eq($alphaId, (int)$eventRow['customer_id'], 'the log is for this customer');
    $meta = json_decode((string)$eventRow['metadata'], true);
    assert_true(is_array($meta) && isset($meta['lines']), 'the log stores the prior lines');
    $logged = [];
    foreach ($meta['lines'] as $line) {
        $logged[(int)$line['product_id']] = (int)$line['quantity'];
    }
    assert_eq(4, $logged[$productA] ?? -1, 'the log stores the first prior quantity');
    assert_eq(7, $logged[$productB] ?? -1, 'the log stores the second prior quantity');

    $again = bakery_daily_order_skip($db, $alphaOrder, $date, $staff);
    assert_true(!empty($again['already_zero']), 'a second skip does not invent a new snapshot');
    $eventCount = (int)$db->query(
        "SELECT COUNT(*) FROM operational_events
         WHERE daily_order_id = $alphaOrder AND event_type = 'daily_order_day_skipped'"
    )->fetchColumn();
    assert_eq(1, $eventCount, 'the second skip keeps the original prior quantities');

    echo "=== Generation does not refill, undo restores ===\n";
    bakery_generate_daily_orders_from_standing($db, $date, [
        'overwrite_changed' => false,
        'record_event' => false,
        'assign_routes' => false,
    ]);
    assert_eq(0, $qtyOf($alphaOrder, $productA), 'generation leaves the skipped date at 0');
    assert_eq(11, $standingQty($alphaId, $productA), 'generation does not change the standing order');

    $restored = bakery_daily_orders_action_undo_skip_dated_day($db, [
        'order_id' => $alphaOrder,
        'date' => $date,
    ], $staff);
    assert_true(!empty($restored['success']), 'undo succeeds');
    assert_eq(4, $qtyOf($alphaOrder, $productA), 'undo restores the first quantity');
    assert_eq(7, $qtyOf($alphaOrder, $productB), 'undo restores the second quantity');
    assert_eq(11, $standingQty($alphaId, $productA), 'undo does not change the standing order');
    assert_eq(5, $qtyOf($alphaOther, $productA), 'undo does not change the other date');
    if (column_exists($db, 'daily_orders', 'standing_fill_blocked')) {
        $blocked = (int)$db->query('SELECT standing_fill_blocked FROM daily_orders WHERE id = ' . $alphaOrder)->fetchColumn();
        assert_eq(0, $blocked, 'undo restores the previous standing-fill flag');
    }
    $undoEvent = (int)$db->query(
        "SELECT COUNT(*) FROM operational_events
         WHERE daily_order_id = $alphaOrder AND event_type = 'daily_order_day_unskipped'"
    )->fetchColumn();
    assert_eq(1, $undoEvent, 'undo is logged');

    echo "=== Unassigned stops ===\n";
    $preview = bakery_daily_order_unassigned_stops($db, $date);
    $previewIds = array_map(static fn(array $row): int => (int)$row['daily_order_id'], $preview);
    assert_true(in_array($alphaOrder, $previewIds, true), 'the open order is unassigned');
    assert_true(in_array($deltaOrder, $previewIds, true), 'a cancelled assignment is unassigned');
    assert_true(in_array($echoOrder, $previewIds, true), 'an invoiced order with no driver is listed');
    assert_true(!in_array($bravoOrder, $previewIds, true), 'a pending assignment is not unassigned');
    assert_true(!in_array($charlieOrder, $previewIds, true), 'a legacy driver_id is not unassigned');
    assert_true(!in_array($alphaOther, $previewIds, true), 'another date is not in this list');
    $deltaRow = null;
    foreach ($preview as $row) {
        if ((int)$row['daily_order_id'] === $deltaOrder) {
            $deltaRow = $row;
        }
    }
    assert_eq(1, (int)($deltaRow['line_count'] ?? 0), 'the confirm list counts lines');
    assert_eq(1, (int)($deltaRow['positive_lines'] ?? 0), 'the confirm list counts lines with a quantity');

    $rolled = false;
    try {
        bakery_daily_order_skip_unassigned($db, $date, [$alphaOrder, 99999999], $staff);
    } catch (RuntimeException $e) {
        $rolled = true;
    }
    assert_true($rolled, 'a missing order fails the bulk skip');
    assert_eq(4, $qtyOf($alphaOrder, $productA), 'the failed bulk skip rolls back');
    assert_eq(8, $qtyOf($deltaOrder, $productA), 'the failed bulk skip does not zero other stops');

    $bulk = bakery_daily_orders_action_skip_unassigned($db, [
        'date' => $date,
        'order_ids' => $alphaOrder . ',' . $deltaOrder . ',' . $bravoOrder,
    ], $staff);
    assert_true(!empty($bulk['success']), 'bulk skip succeeds');
    assert_eq(0, $qtyOf($alphaOrder, $productA), 'bulk skip zeros the open order');
    assert_eq(0, $qtyOf($deltaOrder, $productA), 'bulk skip zeros the cancelled stop');
    assert_eq(9, $qtyOf($bravoOrder, $productA), 'bulk skip leaves a stop that has a driver');
    assert_eq(3, $qtyOf($charlieOrder, $productA), 'bulk skip leaves the legacy driver');
    assert_eq(11, $standingQty($alphaId, $productA), 'bulk skip leaves the standing order');
    $leftIds = array_map(static fn(array $row): int => (int)$row['daily_order_id'], $bulk['left_assigned'] ?? []);
    assert_true(in_array($bravoOrder, $leftIds, true), 'the result names the stop that gained a driver');

    echo "=== Invoiced header stays, lines still zero ===\n";
    $echoSkip = bakery_daily_order_skip($db, $echoOrder, $date, $staff);
    assert_true(!empty($echoSkip['success']), 'an invoiced dated order can be zeroed');
    assert_eq(0, $qtyOf($echoOrder, $productA), 'invoiced lines go to 0');
    $echoTotal = (float)$db->query('SELECT total_amount FROM daily_orders WHERE id = ' . $echoOrder)->fetchColumn();
    assert_true(abs($echoTotal - 20.0) < 0.001, 'an invoiced header total is not rewritten');

    $cleanup();
} catch (Throwable $e) {
    $cleanup();
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}

echo "\n=== daily order skip: {$GLOBALS['TEST_PASS']} passed, {$GLOBALS['TEST_FAIL']} failed ===\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
