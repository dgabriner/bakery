<?php
/**
 * Customer account preferences — characterization tests.
 *
 * Run: php tests/run_customer_account_tests.php
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
require_once $root . '/includes/customer_account.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: tests must run with APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();
bakery_assert_local_test_target($db);
bakery_customer_account_ensure_schema($db);

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

$customerStmt = $db->query(
    "SELECT id, name FROM customers WHERE portal_enabled = 1 AND is_active = 1 LIMIT 1"
);
$customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
if (!$customer) {
    fwrite(STDERR, "No portal-enabled customer found.\n");
    exit(1);
}

$customerId = (int)$customer['id'];
$original = bakery_customer_account_load($db, $customerId);

$testInstructions = 'TEST delivery instructions ' . uniqid();
$result = bakery_customer_account_update_section($db, $customer, 'delivery', [
    'delivery_instructions' => $testInstructions,
]);
test_assert(!empty($result['changes']), 'delivery instructions update returns changes');

$reloaded = bakery_customer_account_load($db, $customerId);
test_assert(
    ($reloaded['delivery_instructions'] ?? '') === $testInstructions,
    'delivery instructions persisted'
);

test_assert(
    bakery_driver_stop_notes(['delivery_instructions' => $testInstructions, 'order_notes' => '']) === $testInstructions,
    'driver stop notes include delivery instructions'
);

$request = bakery_customer_account_request_change(
    $db,
    $customer,
    'address',
    '123 Test Request St, Example City',
    'Unit test address change request'
);
test_assert($request['request_id'] > 0, 'address change request creates row');

// Restore original delivery instructions
bakery_customer_account_update_section($db, $customer, 'delivery', [
    'delivery_instructions' => (string)($original['delivery_instructions'] ?? ''),
]);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
