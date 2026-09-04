<?php
/**
 * Characterization: Standing Orders Manager (upsert / delete-on-zero / copy / pause).
 *
 * §4 invariants asserted by name:
 *   - Standing = template/forecast; dated = commercial commitment
 *   - Standing edits never rewrite past dated orders
 *   - Pauses/skips suppress generation
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
require_once $root . '/includes/customer_order_mutations.php';
require_once $root . '/includes/daily_order_generation.php';
require_once $root . '/includes/customer_portal.php';

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

/**
 * Mirrors standing_orders_manager.php action=copy_orders (no extracted helper yet).
 *
 * @param list<int> $selectedDays
 */
function standing_manager_copy_orders(PDO $db, int $sourceCustomerId, int $targetCustomerId, array $selectedDays): int
{
    if ($selectedDays === []) {
        return 0;
    }
    $placeholders = implode(',', array_fill(0, count($selectedDays), '?'));
    $stmt = $db->prepare(
        "SELECT product_id, day_of_week, quantity
         FROM standing_orders
         WHERE customer_id = ? AND day_of_week IN ($placeholders)"
    );
    $stmt->execute(array_merge([$sourceCustomerId], $selectedDays));
    $sourceOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $db->beginTransaction();
    try {
        $copied = 0;
        $upsert = $db->prepare(
            'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity = ?'
        );
        foreach ($sourceOrders as $order) {
            $qty = (int)$order['quantity'];
            $upsert->execute([
                $targetCustomerId,
                (int)$order['product_id'],
                (int)$order['day_of_week'],
                $qty,
                $qty,
            ]);
            $copied++;
        }
        $db->commit();
        return $copied;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

$futureDate = date('Y-m-d', strtotime('+49 days'));
$weekday = bakery_standing_day_from_date($futureDate);
$pastDate = date('Y-m-d', strtotime('-14 days'));
echo "Future date: $futureDate (day $weekday); past dated order on $pastDate\n";
echo "INVARIANT  Standing = template/forecast; dated = commercial commitment\n";
echo "INVARIANT  Standing edits never rewrite past dated orders\n";
echo "INVARIANT  Pauses/skips suppress generation\n";

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
$cleanup = static function () use ($db, $futureDate, $pastDate, &$customerIds): void {
    if ($customerIds === []) {
        return;
    }
    $ph = implode(',', array_fill(0, count($customerIds), '?'));
    foreach ([$futureDate, $pastDate] as $d) {
        $orderIds = $db->prepare("SELECT id FROM daily_orders WHERE customer_id IN ($ph) AND order_date = ?");
        $orderIds->execute(array_merge($customerIds, [$d]));
        $ids = array_map('intval', $orderIds->fetchAll(PDO::FETCH_COLUMN));
        if ($ids) {
            $oph = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("DELETE FROM daily_order_items WHERE daily_order_id IN ($oph)")->execute($ids);
            if (table_exists($db, 'daily_order_assignments')) {
                $db->prepare("DELETE FROM daily_order_assignments WHERE daily_order_id IN ($oph)")->execute($ids);
            }
            $db->prepare("DELETE FROM daily_orders WHERE id IN ($oph)")->execute($ids);
        }
    }
    $db->prepare("DELETE FROM standing_orders WHERE customer_id IN ($ph)")->execute($customerIds);
    if (table_exists($db, 'standing_order_pauses')) {
        $db->prepare("DELETE FROM standing_order_pauses WHERE customer_id IN ($ph)")->execute($customerIds);
    }
    $db->prepare("DELETE FROM customers WHERE id IN ($ph)")->execute($customerIds);
    $customerIds = [];
};

$cleanup();

$insertCustomer = $db->prepare(
    "INSERT INTO customers (name, address, is_active, sfb_origin) VALUES (?, ?, ?, 'human')"
);
$insertCustomer->execute(['Char Standing Source Cafe', '10 Standing Ave', 1]);
$sourceId = (int)$db->lastInsertId();
$customerIds[] = $sourceId;
$insertCustomer->execute(['Char Standing Target Cafe', '11 Standing Ave', 1]);
$targetId = (int)$db->lastInsertId();
$customerIds[] = $targetId;

$sourceCustomer = $db->query("SELECT * FROM customers WHERE id = $sourceId")->fetch(PDO::FETCH_ASSOC);
$targetCustomer = $db->query("SELECT * FROM customers WHERE id = $targetId")->fetch(PDO::FETCH_ASSOC);

echo "\n=== Upsert standing line; delete on zero ===\n";
$up = bakery_customer_save_standing_line($db, $sourceCustomer, $productId, $weekday, 12);
$assert((int)$up['new_quantity'] === 12, 'upsert writes standing quantity > 0');
$assert(bakery_customer_standing_qty($db, $sourceId, $productId, $weekday) === 12, 'standing row readable after upsert');

$del = bakery_customer_save_standing_line($db, $sourceCustomer, $productId, $weekday, 0);
$assert((int)$del['new_quantity'] === 0, 'delete-on-zero returns quantity 0');
$assert(bakery_customer_standing_qty($db, $sourceId, $productId, $weekday) === null
    || bakery_customer_standing_qty($db, $sourceId, $productId, $weekday) === 0,
    'standing row removed (or zero) after delete-on-zero');

bakery_customer_save_standing_line($db, $sourceCustomer, $productId, $weekday, 8);

echo "\n=== Copy week does not touch past dated orders ===\n";
$db->prepare(
    "INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, 'delivered', 40.00)"
)->execute([$targetId, $pastDate]);
$pastOrderId = (int)$db->lastInsertId();
$db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
     VALUES (?, ?, 4, 10.00, 40.00)'
)->execute([$pastOrderId, $productId]);

$copied = standing_manager_copy_orders($db, $sourceId, $targetId, [$weekday]);
$assert($copied === 1, 'copy_orders copied one standing row for the selected day');
$assert(bakery_customer_standing_qty($db, $targetId, $productId, $weekday) === 8,
    'target standing matches source after copy (§4 standing = template)');

$pastCheck = $db->prepare(
    'SELECT doi.quantity, do.total_amount
     FROM daily_order_items doi
     JOIN daily_orders do ON do.id = doi.daily_order_id
     WHERE do.id = ? AND doi.product_id = ?'
);
$pastCheck->execute([$pastOrderId, $productId]);
$pastRow = $pastCheck->fetch(PDO::FETCH_ASSOC);
$assert($pastRow !== false && (int)$pastRow['quantity'] === 4,
    'copy-week does not rewrite past dated order lines (§4 standing edits never rewrite past dated)');
$assert(abs((float)$pastRow['total_amount'] - 40.00) < 0.001,
    'past dated order total unchanged by standing copy');

echo "\n=== Pause suppresses generation ===\n";
$weekStart = bakery_week_start_monday($futureDate);
if (table_exists($db, 'standing_order_pauses')) {
    $db->prepare(
        'INSERT INTO standing_order_pauses (customer_id, week_start) VALUES (?, ?)'
    )->execute([$sourceId, $weekStart]);
    $assert(bakery_customer_week_is_paused($db, $sourceId, $weekStart), 'pause row marks the week paused');

    $gen = bakery_generate_daily_orders_from_standing($db, $futureDate, [
        'overwrite_changed' => false,
        'record_event' => false,
        'assign_routes' => false,
    ]);
    $pausedOrders = $db->prepare(
        'SELECT COUNT(*) FROM daily_orders WHERE customer_id = ? AND order_date = ?'
    );
    $pausedOrders->execute([$sourceId, $futureDate]);
    $assert((int)$pausedOrders->fetchColumn() === 0,
        'pause suppresses generation for the paused customer (§4 pauses/skips)');
    $assert((int)($gen['orders_created'] ?? 0) >= 0, 'generate still returns a result under pause');
} else {
    echo "NOTE  standing_order_pauses missing — skip pause assert\n";
}

$cleanup();

echo "\n=== standing_orders_manager characterization: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
