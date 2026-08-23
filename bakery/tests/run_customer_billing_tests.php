<?php
/**
 * Customer portal billing — authorization and payment-label tests.
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
require_once $root . '/includes/customer_billing.php';

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

$GLOBALS['TEST_PASS'] = 0;
$GLOBALS['TEST_FAIL'] = 0;

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

// Find two customers with confirmed deliveries if possible.
$customers = $db->query(
    'SELECT DISTINCT c.id
     FROM customers c
     JOIN daily_orders do ON do.customer_id = c.id
     WHERE do.delivery_confirmed_at IS NOT NULL
     ORDER BY c.id
     LIMIT 2'
)->fetchAll(PDO::FETCH_COLUMN);

if (count($customers) < 2) {
    echo "SKIP  Need at least two customers with confirmed deliveries\n";
    exit(0);
}

$customerA = (int)$customers[0];
$customerB = (int)$customers[1];

$orderA = $db->prepare(
    'SELECT id FROM daily_orders
     WHERE customer_id = ? AND delivery_confirmed_at IS NOT NULL
     ORDER BY id DESC LIMIT 1'
);
$orderA->execute([$customerA]);
$orderAId = (int)$orderA->fetchColumn();

assert_true($orderAId > 0, 'Fixture: customer A has a confirmed order');
assert_true(
    bakery_portal_billing_verify_order($db, $customerA, $orderAId),
    'Ownership: customer A can access own order'
);
assert_true(
    !bakery_portal_billing_verify_order($db, $customerB, $orderAId),
    'IDOR: customer B cannot access customer A order'
);

$foreignInvoice = bakery_portal_billing_load_invoice($db, $customerB, $orderAId);
assert_true($foreignInvoice === null, 'IDOR: load_invoice returns null for wrong customer');

$ownInvoice = bakery_portal_billing_load_invoice($db, $customerA, $orderAId);
assert_true($ownInvoice !== null, 'Load invoice succeeds for owning customer');

$forbiddenLabels = ['Past Due', 'Unpaid', 'Balance Due', 'past due', 'unpaid', 'balance due'];
if ($ownInvoice) {
    $label = bakery_portal_billing_payment_label($ownInvoice, $ownInvoice['customer'] ?? []);
    foreach ($forbiddenLabels as $bad) {
        assert_true(
            stripos($label['label'], $bad) === false && stripos($label['detail'], $bad) === false,
            'Payment label does not contain forbidden AR term: ' . $bad
        );
    }
    assert_true(
        in_array($label['key'], ['cod_collected', 'cod_expected', 'invoice_issued', 'delivered'], true),
        'Payment label uses customer-safe key'
    );
}

$start = date('Y-m-01', strtotime('-6 months'));
$end = date('Y-m-d');
$accountA = bakery_portal_billing_account($db, $customerA, $start, $end);
foreach ($accountA['invoices'] as $inv) {
    assert_true(
        (int)($inv['customer_id'] ?? $customerA) === $customerA || !isset($inv['customer_id']),
        'Account list scoped to requested customer'
    );
    assert_true(bakery_portal_billing_invoice_visible($inv), 'Account list only includes visible invoices');
}

$summaryRows = bakery_portal_billing_summary_export_rows($db, $customerA, $start, $end);
foreach ($summaryRows as $row) {
    assert_true(!array_key_exists('customer_id', $row), 'Summary CSV excludes customer_id');
    assert_true(!array_key_exists('customer_name', $row), 'Summary CSV excludes customer_name');
    assert_true(array_key_exists('invoice_id', $row), 'Summary CSV includes invoice_id');
}

$lineRows = bakery_portal_billing_line_export_rows($db, $customerA, $start, $end);
foreach ($lineRows as $row) {
    assert_true(!array_key_exists('pricing_label', $row), 'Line CSV excludes internal pricing_label');
    assert_true(!array_key_exists('memo', $row), 'Line CSV excludes internal memo');
}

$deliveryUrl = bakery_portal_delivery_url('2024-06-15', 42);
assert_true(
    strpos($deliveryUrl, 'customer_portal_delivery.php?id=42') !== false,
    'Delivery URL uses portal delivery page with order id'
);

echo "\nPassed: {$GLOBALS['TEST_PASS']}  Failed: {$GLOBALS['TEST_FAIL']}\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
