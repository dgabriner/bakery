<?php
/**
 * Customer order power tools — characterization tests.
 *
 * Manual scenario covered:
 * - Two recurring weeks of standing orders
 * - One modified dated delivery
 * - One skipped date
 *
 * Run: php tests/run_customer_order_power_tests.php
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
require_once $root . '/includes/customer_order_mutations.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: tests must run with APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();
bakery_assert_local_test_target($db);
bakery_customer_order_ensure_schema($db);

$pass = 0;
$fail = 0;

function test_assert($cond, $msg) {
    global $pass, $fail;
    if ($cond) {
        echo "PASS  $msg\n";
        $pass++;
    } else {
        echo "FAIL  $msg\n";
        $fail++;
    }
}

// Find a portal-enabled test customer or create a minimal one.
$customerStmt = $db->query(
    "SELECT id, name, pricing_tier, default_pan_dulce_price FROM customers
     WHERE portal_enabled = 1 AND is_active = 1 LIMIT 1"
);
$customer = $customerStmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    fwrite(STDERR, "No portal-enabled customer found. Enable portal on a test customer first.\n");
    exit(1);
}

$productStmt = $db->query('SELECT id, name, price, wholesale_price FROM products LIMIT 1');
$product = $productStmt->fetch(PDO::FETCH_ASSOC);
if (!$product) {
    fwrite(STDERR, "No products in database.\n");
    exit(1);
}

$customerId = (int)$customer['id'];
$productId = (int)$product['id'];

// Use Friday (5) for standing order tests.
$friday = 5;
$today = date('Y-m-d');
$nextFriday = $today;
while ((int)date('N', strtotime($nextFriday)) !== $friday) {
    $nextFriday = date('Y-m-d', strtotime($nextFriday . ' +1 day'));
}
$secondFriday = date('Y-m-d', strtotime($nextFriday . ' +7 days'));
$thirdFriday = date('Y-m-d', strtotime($nextFriday . ' +14 days'));

echo "Customer #{$customerId}, product #{$productId}\n";
echo "Week 1 Friday: $nextFriday\n";
echo "Week 2 Friday: $secondFriday\n";
echo "Week 3 Friday: $thirdFriday\n\n";

// Setup standing order: Sourdough 20 on Friday
bakery_customer_save_standing_line($db, $customer, $productId, $friday, 20);
$standingQty = bakery_customer_standing_qty($db, $customerId, $productId, $friday);
test_assert($standingQty === 20, 'Standing Friday qty is 20');

// Modify week 2 Friday delivery only (+5)
$result = bakery_customer_save_daily_line($db, $customer, $secondFriday, $productId, 25);
test_assert($result['new_quantity'] === 25, 'Dated delivery changed to 25');
test_assert($result['regular_quantity'] === 20, 'Regular remains 20');
test_assert($result['diff_from_regular'] === 5, 'Diff is +5');

// Standing unchanged after dated change
test_assert(bakery_customer_standing_qty($db, $customerId, $productId, $friday) === 20,
    'Standing still 20 after dated change');

// Skip week 3 Friday
bakery_customer_skip_delivery($db, $customer, $thirdFriday, 'Test skip');
test_assert(bakery_customer_delivery_is_skipped($db, $customerId, $thirdFriday), 'Third Friday marked skipped');
test_assert(bakery_customer_standing_qty($db, $customerId, $productId, $friday) === 20,
    'Standing unchanged after skip');

$state = bakery_customer_delivery_state($db, $customerId, $thirdFriday);
test_assert($state['skipped'] === true && $state['editable'] === false, 'Skipped date not editable');

// Pause range covering week 1
$pauseEnd = date('Y-m-d', strtotime($nextFriday . ' +2 days'));
bakery_customer_create_pause_range($db, $customer, $nextFriday, $pauseEnd, 'Test vacation');
test_assert(bakery_customer_delivery_in_pause_range($db, $customerId, $nextFriday), 'Date in pause range');

// Locked detection uses in_production status
$orderId = bakery_customer_ensure_daily_order($db, $customer, $nextFriday);
$db->prepare("UPDATE daily_orders SET status = 'in_production' WHERE id = ?")->execute([$orderId]);
$lockedState = bakery_customer_delivery_state($db, $customerId, $nextFriday);
test_assert($lockedState['locked'] === true, 'in_production order is locked');

// Cleanup test artifacts
$db->prepare('DELETE FROM customer_delivery_skips WHERE customer_id = ? AND skip_date = ?')
    ->execute([$customerId, $thirdFriday]);
$db->prepare('DELETE FROM customer_delivery_pauses WHERE customer_id = ? AND pause_start = ?')
    ->execute([$customerId, $nextFriday]);
$db->prepare('DELETE FROM daily_order_items WHERE daily_order_id = ?')->execute([$orderId]);
$db->prepare('DELETE FROM daily_orders WHERE id = ?')->execute([$orderId]);
$db->prepare(
    'DELETE FROM daily_orders WHERE customer_id = ? AND order_date = ?'
)->execute([$customerId, $secondFriday]);

echo "\nDone: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
