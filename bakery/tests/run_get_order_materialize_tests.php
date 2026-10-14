<?php
/**
 * Page views must not materialize dated orders.
 * An order a manager emptied stays empty across generate, ensure, and horizon fill.
 *
 * CLI / bakerysf_test only.
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
require_once $root . '/includes/daily_orders_actions.php';
require_once $root . '/includes/i18n.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: tests must run with APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();
bakery_assert_local_test_target($db);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['user_id'] = 1;
$_SESSION['user_role_slug'] = 'administrator';

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

echo "\n=== GET pages do not materialize orders ===\n";
$pageFiles = [
    'index.php',
    'daily_run.php',
    'daily_orders.php',
    'production.php',
    'baker_mix.php',
    'driver.php',
    'driver_assignment.php',
    'survey.php',
];
foreach ($pageFiles as $rel) {
    $src = (string)file_get_contents($root . '/' . $rel);
    $assert(
        strpos($src, 'bakery_fill_demand_horizon(') === false,
        $rel . ' does not fill the demand horizon on view'
    );
    $assert(
        strpos($src, 'bakery_ensure_daily_orders_for_date(') === false,
        $rel . ' does not ensure dated orders on view'
    );
    $assert(
        strpos($src, 'bakery_generate_daily_orders_from_standing(') === false,
        $rel . ' does not generate standing orders inline on view'
    );
}

$surveySrc = (string)file_get_contents($root . '/survey.php');
$assert(
    strpos($surveySrc, 'bakery_customer_ensure_daily_order(') === false,
    'survey.php does not create a dated order while rendering'
);

$cronSrc = (string)file_get_contents($root . '/scripts/demand_scheduler.php');
$assert(
    strpos($cronSrc, 'bakery_ensure_daily_orders_horizon(') !== false,
    'demand scheduler cron still materializes the horizon'
);

$ordersSrc = (string)file_get_contents($root . '/daily_orders.php');
$assert(
    strpos($ordersSrc, 'csrf_token') !== false && strpos($ordersSrc, 'bakery_require_role') !== false,
    'Daily Orders generation POST checks CSRF and manager role'
);
$assert(
    strpos($ordersSrc, "action=generate_from_standing") !== false
        && strpos($ordersSrc, 'csrf_token') !== false,
    'Daily Orders generate request sends a CSRF token'
);

foreach (['daily_run.php', 'index.php', 'production.php', 'baker_mix.php', 'driver_assignment.php'] as $rel) {
    $src = (string)file_get_contents($root . '/' . $rel);
    $assert(
        strpos($src, 'build_from_standing') !== false,
        $rel . ' offers an explicit Build from standing action'
    );
}

$en = require $root . '/lang/en.php';
$es = require $root . '/lang/es.php';
$assert(($en['ux.daily_orders.generate_standing'] ?? '') === 'Build from standing', 'English button says Build from standing');
$assert(($es['ux.daily_orders.generate_standing'] ?? '') !== '' && ($es['ux.daily_orders.generate_standing'] ?? '') !== ($en['ux.daily_orders.generate_standing'] ?? ''), 'Spanish button is translated');
$assert(!empty($en['orders.built_from_standing']) && !empty($es['orders.built_from_standing']), 'build result copy exists in EN and ES');
$assert(
    strpos($ordersSrc, 'ux.daily_orders.removed_one_day') !== false
        && strpos($ordersSrc, 'ux.daily_orders.undo_removal') !== false,
    'Daily Orders explains a one-day removal and offers undo'
);
$assert(
    ($en['ux.daily_orders.removed_one_day'] ?? '') === 'Removed for this day only. Standing order is unchanged.',
    'English removal copy says this day only'
);
$assert(
    ($es['ux.daily_orders.removed_one_day'] ?? '') !== ''
        && ($es['ux.daily_orders.removed_one_day'] ?? '') !== ($en['ux.daily_orders.removed_one_day'] ?? ''),
    'Spanish removal copy is translated'
);
$assert(
    ($en['ux.daily_orders.undo_removal'] ?? '') === 'Undo removal'
        && ($es['ux.daily_orders.undo_removal'] ?? '') !== ''
        && ($es['ux.daily_orders.undo_removal'] ?? '') !== ($en['ux.daily_orders.undo_removal'] ?? ''),
    'Undo removal is labeled in English and Spanish'
);

echo "\n=== Emptied dated order stays empty ===\n";

$assert(
    function_exists('column_exists') && column_exists($db, 'daily_orders', 'standing_fill_blocked'),
    'migration adds daily_orders.standing_fill_blocked'
);

$date = date('Y-m-d', strtotime('+63 days'));
$weekday = bakery_standing_day_from_date($date);
$productId = (int)$db->query(
    "SELECT p.id
     FROM products p
     JOIN dough_types dt ON dt.id = p.dough_type_id
     JOIN product_lines pl ON pl.id = dt.product_line_id
     ORDER BY p.id
     LIMIT 1"
)->fetchColumn();

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
    $db->prepare("DELETE FROM customers WHERE id IN ($ph)")->execute($customerIds);
    $customerIds = [];
};

if ($productId <= 0) {
    echo "FAIL  need a catalog product\n";
    exit(1);
}

$leftover = $db->prepare("SELECT id FROM customers WHERE name IN (?, ?)");
$leftover->execute(['Get Must Not Create Cafe', 'Get Must Not Create Neighbor']);
foreach ($leftover->fetchAll(PDO::FETCH_COLUMN) as $leftoverId) {
    $customerIds[] = (int)$leftoverId;
}
$cleanup();

$insertCustomer = $db->prepare(
    "INSERT INTO customers (name, address, is_active, sfb_origin) VALUES (?, ?, 1, 'human')"
);
$insertCustomer->execute(['Get Must Not Create Cafe', '9 Tombstone Lane']);
$customerId = (int)$db->lastInsertId();
$customerIds[] = $customerId;
$insertCustomer->execute(['Get Must Not Create Neighbor', '10 Tombstone Lane']);
$neighborId = (int)$db->lastInsertId();
$customerIds[] = $neighborId;

$db->prepare(
    'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, ?)'
)->execute([$customerId, $productId, $weekday, 4]);
$db->prepare(
    'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, ?)'
)->execute([$neighborId, $productId, $weekday, 2]);

try {
$before = (int)$db->query(
    'SELECT COUNT(*) FROM daily_orders WHERE order_date = ' . $db->quote($date)
        . ' AND customer_id IN (' . $customerId . ',' . $neighborId . ')'
)->fetchColumn();
bakery_demand_review_build($db, $date, []);
$afterReview = (int)$db->query(
    'SELECT COUNT(*) FROM daily_orders WHERE order_date = ' . $db->quote($date)
        . ' AND customer_id IN (' . $customerId . ',' . $neighborId . ')'
)->fetchColumn();
$assert($before === 0 && $afterReview === $before, 'demand review read does not create dated orders');

$gen = bakery_generate_daily_orders_from_standing($db, $date, [
    'overwrite_changed' => false,
    'record_event' => false,
    'assign_routes' => false,
]);
$assert((int)($gen['orders_created'] ?? 0) >= 1, 'explicit generate still creates a dated order');

$orderId = (int)$db->query(
    'SELECT id FROM daily_orders WHERE customer_id = ' . $customerId . ' AND order_date = ' . $db->quote($date)
)->fetchColumn();
$itemId = (int)$db->query(
    'SELECT id FROM daily_order_items WHERE daily_order_id = ' . $orderId . ' AND product_id = ' . $productId
)->fetchColumn();
$assert($orderId > 0 && $itemId > 0, 'generated order has the standing line');

$neighborItemId = (int)$db->query(
    'SELECT doi.id FROM daily_order_items doi
     JOIN daily_orders do ON do.id = doi.daily_order_id
     WHERE do.customer_id = ' . $neighborId . ' AND do.order_date = ' . $db->quote($date) . ' AND doi.product_id = ' . $productId
)->fetchColumn();
bakery_daily_orders_action_update_quantity($db, [
    'item_id' => $neighborItemId,
    'quantity' => 9,
]);

bakery_daily_orders_action_update_quantity($db, [
    'item_id' => $itemId,
    'quantity' => 0,
]);
$zeroQty = $db->query('SELECT quantity FROM daily_order_items WHERE id = ' . $itemId)->fetchColumn();
$assert($zeroQty !== false && (int)$zeroQty === 0, 'remove keeps a dated override of quantity 0');
$left = (int)$db->query(
    'SELECT COUNT(*) FROM daily_order_items WHERE daily_order_id = ' . $orderId . ' AND quantity > 0'
)->fetchColumn();
$blocked = 0;
if (column_exists($db, 'daily_orders', 'standing_fill_blocked')) {
    $blocked = (int)$db->query(
        'SELECT standing_fill_blocked FROM daily_orders WHERE id = ' . $orderId
    )->fetchColumn();
}
$assert($left === 0 && $blocked === 1, 'removing the only line tombstones the dated order');

$regen = bakery_generate_daily_orders_from_standing($db, $date, [
    'overwrite_changed' => false,
    'record_event' => false,
    'assign_routes' => false,
]);
$refilled = (int)$db->query(
    'SELECT COUNT(*) FROM daily_order_items WHERE daily_order_id = ' . $orderId . ' AND quantity > 0'
)->fetchColumn();
$assert($refilled === 0, 'generate does not copy standing back onto an emptied order');
$assert((int)($regen['items_created'] ?? 0) === 0 || $refilled === 0, 'emptied order contributes no new lines');

$ensured = bakery_ensure_daily_orders_for_date($db, $date, [
    'record_event' => false,
    'assign_routes' => false,
]);
$afterEnsure = (int)$db->query(
    'SELECT COUNT(*) FROM daily_order_items WHERE daily_order_id = ' . $orderId . ' AND quantity > 0'
)->fetchColumn();
$assert($afterEnsure === 0, 'ensure does not rebuild an emptied order');
$neighborItems = (int)$db->query(
    'SELECT COUNT(*) FROM daily_order_items doi
     JOIN daily_orders do ON do.id = doi.daily_order_id
     WHERE do.customer_id = ' . $neighborId . ' AND do.order_date = ' . $db->quote($date) . ' AND doi.quantity > 0'
)->fetchColumn();
$assert($neighborItems >= 1, 'a neighbor who was never emptied still has dated lines');
$neighborQty = (int)$db->query(
    'SELECT doi.quantity FROM daily_order_items doi
     JOIN daily_orders do ON do.id = doi.daily_order_id
     WHERE do.customer_id = ' . $neighborId . ' AND do.order_date = ' . $db->quote($date) . ' AND doi.product_id = ' . $productId
)->fetchColumn();
$assert($neighborQty === 9, 'removing one order does not re-sync another order on the same date');
$demandLines = bakery_operating_demand_customers_for_product($db, $date, $productId);
$removedDemand = null;
$neighborDemand = null;
foreach ($demandLines as $demandLine) {
    if ((int)$demandLine['id'] === $customerId) {
        $removedDemand = (int)$demandLine['quantity'];
    }
    if ((int)$demandLine['id'] === $neighborId) {
        $neighborDemand = (int)$demandLine['quantity'];
    }
}
$assert($removedDemand === null, 'a one-day removal is not baked as standing demand');
$assert($neighborDemand === 9, 'the other order still contributes its dated quantity');

if (is_array($ensured) && !empty($ensured['ran'])) {
    $again = bakery_ensure_daily_orders_for_date($db, $date, [
        'record_event' => false,
        'assign_routes' => false,
    ]);
    $assert(empty($again['ran']), 'ensure does not keep retrying a tombstoned empty order');
} else {
    $assert(empty($ensured['ran']), 'ensure skips when the only gap is a tombstoned empty order');
}

$overwrite = bakery_generate_daily_orders_from_standing($db, $date, [
    'overwrite_changed' => true,
    'record_event' => false,
    'assign_routes' => false,
]);
$restored = (int)$db->query(
    'SELECT quantity FROM daily_order_items WHERE daily_order_id = ' . $orderId . ' AND product_id = ' . $productId
)->fetchColumn();
$blockedAfter = (int)$db->query(
    'SELECT standing_fill_blocked FROM daily_orders WHERE id = ' . $orderId
)->fetchColumn();
$assert($restored === 4 && $blockedAfter === 0, 'overwrite generate is the explicit way to refill an emptied order');
$assert((int)($overwrite['items_created'] ?? 0) + (int)($overwrite['items_updated'] ?? 0) >= 1, 'overwrite writes the standing line');

bakery_daily_orders_action_update_quantity($db, [
    'item_id' => $itemId,
    'quantity' => 0,
]);
bakery_daily_orders_action_update_quantity($db, [
    'item_id' => $itemId,
    'quantity' => 4,
]);
$undone = (int)$db->query('SELECT quantity FROM daily_order_items WHERE id = ' . $itemId)->fetchColumn();
$assert($undone === 4, 'undo writes the standing quantity back onto the dated line');

$visitZero = bakery_demand_set_dated_quantity($db, $customerId, $date, $productId, 0);
$visitQty = $db->query('SELECT quantity FROM daily_order_items WHERE id = ' . (int)$visitZero['item_id'])->fetchColumn();
$assert($visitQty !== false && (int)$visitQty === 0, 'setting a dated quantity to 0 keeps the line');
bakery_generate_daily_orders_from_standing($db, $date, [
    'overwrite_changed' => false,
    'record_event' => false,
    'assign_routes' => false,
]);
$afterVisitSync = (int)$db->query(
    'SELECT quantity FROM daily_order_items WHERE daily_order_id = ' . $orderId . ' AND product_id = ' . $productId
)->fetchColumn();
$assert($afterVisitSync === 0, 'standing sync keeps a visit quantity of 0');
} finally {
    $cleanup();
}

echo "\n=== get order materialize: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
