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
require_once $root . '/includes/customer_portal.php';
require_once $root . '/includes/square_config.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: tests must run with APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();
bakery_assert_local_test_target($db);
bakery_customer_account_ensure_schema($db);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

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

// One $1 tip link per click. Square is mocked; this block must not call the network.
unset($_SESSION['bakery_portal_tip_links']);

$tipCalls = [];
$GLOBALS['bakery_square_api_handler'] = static function (string $method, string $path, ?array $body = null) use (&$tipCalls): array {
    if ($method !== 'POST' || $path !== '/v2/online-checkout/payment-links') {
        throw new RuntimeException('refusing unexpected Square call ' . $method . ' ' . $path);
    }
    $tipCalls[] = $body ?? [];
    $n = count($tipCalls);
    return ['payment_link' => [
        'id' => 'PL-TIP-' . $n,
        'url' => 'https://sandbox.square.link/u/tip-' . $n,
        'order_id' => 'ORDER-TIP-' . $n,
    ]];
};

$tipReturn = 'http://localhost/customer_portal.php?tip=thanks';
$tipClick = 'tipclick' . bin2hex(random_bytes(8));
$tipFirst = bakery_portal_create_tip_checkout($customer, $tipClick, $tipReturn);
$tipReplay = bakery_portal_create_tip_checkout($customer, $tipClick, $tipReturn);
test_assert(count($tipCalls) === 1, 'same tip click creates one Square payment link');
test_assert(($tipReplay['url'] ?? '') === 'https://sandbox.square.link/u/tip-1', 'tip replay returns the original url');
test_assert(
    ($tipFirst['idempotency_key'] ?? '') === 'tip-' . $customerId . '-' . $tipClick,
    'tip idempotency key is the customer and the click'
);
test_assert(
    ($tipCalls[0]['idempotency_key'] ?? '') === ($tipFirst['idempotency_key'] ?? ''),
    'Square receives the stable tip key'
);
test_assert(
    ($tipCalls[0]['quick_pay']['price_money']['amount'] ?? 0) === 100
        && ($tipCalls[0]['quick_pay']['price_money']['currency'] ?? '') === 'USD',
    'tip link is one dollar'
);

unset($_SESSION['bakery_portal_tip_links']);
$tipAgain = bakery_portal_create_tip_checkout($customer, $tipClick, $tipReturn);
test_assert(count($tipCalls) === 2, 'a cleared tip session asks Square again');
test_assert(
    ($tipCalls[1]['idempotency_key'] ?? '') === ($tipCalls[0]['idempotency_key'] ?? '')
        && ($tipAgain['idempotency_key'] ?? '') === ($tipFirst['idempotency_key'] ?? ''),
    'a cleared tip session still sends the same key'
);

$tipOtherClick = 'tipother' . bin2hex(random_bytes(8));
$tipOther = bakery_portal_create_tip_checkout($customer, $tipOtherClick, $tipReturn);
test_assert(count($tipCalls) === 3, 'a different tip click opens its own link');
test_assert(
    ($tipOther['idempotency_key'] ?? '') === 'tip-' . $customerId . '-' . $tipOtherClick,
    'a different tip click gets its own key'
);

$tipOtherCustomer = bakery_portal_create_tip_checkout(
    ['id' => $customerId + 100000, 'name' => 'Other tip customer'],
    $tipClick,
    $tipReturn
);
test_assert(count($tipCalls) === 4, 'another customer does not reuse the tip link');
test_assert(
    ($tipOtherCustomer['idempotency_key'] ?? '') === 'tip-' . ($customerId + 100000) . '-' . $tipClick,
    'another customer gets a different tip key'
);

$tipCallsBeforeInvalid = count($tipCalls);
$tipRejected = false;
try {
    bakery_portal_create_tip_checkout($customer, 'short', $tipReturn);
} catch (InvalidArgumentException $e) {
    $tipRejected = true;
}
test_assert($tipRejected && count($tipCalls) === $tipCallsBeforeInvalid, 'an invalid tip key does not call Square');

$tipRejectedEmpty = false;
try {
    bakery_portal_create_tip_checkout($customer, '', $tipReturn);
} catch (InvalidArgumentException $e) {
    $tipRejectedEmpty = true;
}
test_assert($tipRejectedEmpty && count($tipCalls) === $tipCallsBeforeInvalid, 'a missing tip key does not call Square');

unset($_SESSION['bakery_portal_tip_links']);
$tipRetryCalls = [];
$GLOBALS['bakery_square_api_handler'] = static function (string $method, string $path, ?array $body = null) use (&$tipRetryCalls): array {
    if ($method !== 'POST' || $path !== '/v2/online-checkout/payment-links') {
        throw new RuntimeException('refusing unexpected Square call ' . $method . ' ' . $path);
    }
    $tipRetryCalls[] = (string)($body['idempotency_key'] ?? '');
    if (count($tipRetryCalls) === 1) {
        throw new RuntimeException('sandbox timeout');
    }
    return ['payment_link' => [
        'id' => 'PL-TIP-RETRY',
        'url' => 'https://sandbox.square.link/u/tip-retry',
        'order_id' => 'ORDER-TIP-RETRY',
    ]];
};
$tipRetryClick = 'tipretry' . bin2hex(random_bytes(8));
$tipRetryThrew = false;
try {
    bakery_portal_create_tip_checkout($customer, $tipRetryClick, $tipReturn);
} catch (RuntimeException $e) {
    $tipRetryThrew = $e->getMessage() === 'sandbox timeout';
}
$tipRetry = bakery_portal_create_tip_checkout($customer, $tipRetryClick, $tipReturn);
unset($GLOBALS['bakery_square_api_handler']);
test_assert($tipRetryThrew, 'a failed tip checkout surfaces the Square error');
test_assert(
    count($tipRetryCalls) === 2 && $tipRetryCalls[0] !== '' && $tipRetryCalls[0] === $tipRetryCalls[1],
    'a failed tip retry posts the same Square idempotency key'
);
test_assert(($tipRetry['url'] ?? '') === 'https://sandbox.square.link/u/tip-retry', 'tip retry receives the checkout url');
test_assert(
    $tipRetryCalls[0] === 'tip-' . $customerId . '-' . $tipRetryClick,
    'tip retry key is that same click'
);

$portalPage = (string)file_get_contents($root . '/customer_portal.php');
$tipPage = (string)file_get_contents($root . '/customer_portal_tip.php');
test_assert(strpos($portalPage, 'data-checkout-once') !== false, 'tip form locks after the first click');
test_assert(strpos($portalPage, 'bakery_sfb_checkout_key_input') !== false, 'tip form sends one checkout key');
test_assert(strpos($portalPage, 'bakery_sfb_checkout_once_script') !== false, 'portal page disables the tip button on submit');
test_assert(strpos($tipPage, 'time()') === false, 'tip checkout does not put time() in the Square key');
test_assert(strpos($tipPage, 'bakery_portal_create_tip_checkout') !== false, 'tip page uses the one-key checkout helper');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
