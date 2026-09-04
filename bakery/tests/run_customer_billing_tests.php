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
require_once $root . '/includes/square_invoices.php';

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

// ── Money visibility phase 1: computed read-first balances + AR aging ────────
require_once $root . '/includes/billing_aging.php';

$agingSource = (string)@file_get_contents($root . '/includes/billing_aging.php');
assert_true($agingSource !== '', 'Aging source readable');
assert_true(
    strpos($agingSource, "column_exists(\$db, 'daily_orders', 'square_status')") !== false,
    'Aging source guards square_status behind a column_exists check'
);
assert_true(strpos($agingSource, "= 'PAID'") !== false, 'Settlement test is Square status PAID');
assert_true(
    strpos($agingSource, 'INSERT INTO') === false
        && strpos($agingSource, 'UPDATE ') === false
        && strpos($agingSource, 'DELETE FROM') === false,
    'Aging helpers are pure reads (no INSERT/UPDATE/DELETE)'
);
assert_true(
    strpos($agingSource, "status IN ('delivered', 'invoiced')") !== false,
    'Balances count delivered and invoiced statuses only'
);
assert_true(strpos($agingSource, '0.005') !== false, 'Cent tolerance 0.005 is pinned in aging source');

$agingOrderIds = [];
$agingCustomerIds = [];
try {
    bakery_square_ensure_schema($db);
    $squareColReady = column_exists($db, 'daily_orders', 'square_status');

    $custInsert = static function (PDO $db, $name) use (&$agingCustomerIds) {
        $db->prepare('INSERT INTO customers (name, zone, payment_collection, is_active) VALUES (?, ?, ?, 1)')
            ->execute([$name, 'Test Zone', 'cod']);
        $id = (int)$db->lastInsertId();
        $agingCustomerIds[] = $id;
        return $id;
    };
    $orderInsert = static function (
        PDO $db,
        $customerId,
        $orderDate,
        $status,
        $total,
        $collected,
        $squareStatus,
        $confirmed = true
    ) use (&$agingOrderIds) {
        $cols = 'customer_id, order_date, status, total_amount, delivery_order_total, delivery_pricing_label';
        $marks = '?, ?, ?, ?, ?, ?';
        $params = [$customerId, $orderDate, $status, $total, $total, 'aging test snapshot'];
        if ($confirmed) {
            $cols .= ', delivered_pieces, delivery_confirmed_at';
            $marks .= ', ?, ?';
            $params[] = 12;
            $params[] = $orderDate . ' 09:00:00';
        }
        if ($collected !== null && column_exists($db, 'daily_orders', 'amount_collected')) {
            $cols .= ', amount_collected';
            $marks .= ', ?';
            $params[] = $collected;
        }
        if ($squareStatus !== null && column_exists($db, 'daily_orders', 'square_status')) {
            $cols .= ', square_status';
            $marks .= ', ?';
            $params[] = $squareStatus;
        }
        $db->prepare("INSERT INTO daily_orders ($cols) VALUES ($marks)")->execute($params);
        $orderId = (int)$db->lastInsertId();
        $agingOrderIds[] = $orderId;
        return $orderId;
    };

    $customerA = $custInsert($db, '__aging_a_' . uniqid());
    $customerB = $custInsert($db, '__aging_b_' . uniqid());
    $customerC = $custInsert($db, '__aging_c_' . uniqid());

    $oldDate = (new DateTimeImmutable('-45 days'))->format('Y-m-d');
    $newDate = (new DateTimeImmutable('-10 days'))->format('Y-m-d');

    // Customer A: partial COD collection + fresh uncollected delivery.
    $ordA1 = $orderInsert($db, $customerA, $oldDate, 'delivered', 100.00, 40.00, null);
    $ordA2 = $orderInsert($db, $customerA, $newDate, 'delivered', 50.00, null, null);

    // Customer B: Square-settled / tolerance edge / invoiced-but-unpaid.
    $bOld2 = (new DateTimeImmutable('-44 days'))->format('Y-m-d');
    $bOld3 = (new DateTimeImmutable('-43 days'))->format('Y-m-d');
    $bOld4 = (new DateTimeImmutable('-42 days'))->format('Y-m-d');
    $ordB1 = $orderInsert($db, $customerB, $oldDate, 'delivered', 75.00, null, 'PAID');
    $ordB2 = $orderInsert($db, $customerB, $bOld2, 'delivered', 33.33, 33.32, null);
    $ordB3 = $orderInsert($db, $customerB, $bOld3, 'delivered', 10.00, 9.996, null);
    $ordB4 = $orderInsert($db, $customerB, $bOld4, 'invoiced', 20.00, null, 'UNPAID');

    // Customer C: only an unconfirmed order — never counted.
    $orderInsert($db, $customerC, $oldDate, 'ready', 500.00, null, null, false);

    $loadOrder = static function (PDO $db, $orderId) {
        $stmt = $db->prepare('SELECT * FROM daily_orders WHERE id = ?');
        $stmt->execute([$orderId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    };

    assert_true(abs(bakery_billing_order_outstanding($loadOrder($db, $ordA1)) - 60.00) < 0.001, 'Per-order outstanding subtracts COD collected');
    assert_true(abs(bakery_billing_order_outstanding($loadOrder($db, $ordA2)) - 50.00) < 0.001, 'Null amount_collected counts full snapshot total');

    $settleA1 = bakery_billing_settlement_row($loadOrder($db, $ordA1));
    assert_true(
        abs((float)$settleA1['snapshot_total'] - (float)$loadOrder($db, $ordA1)['delivery_order_total']) < 0.001
            && abs((float)$settleA1['cod_collected'] - 40.00) < 0.001
            && abs((float)$settleA1['open_balance'] - 60.00) < 0.001,
        'Settlement row exposes snapshot · COD · open balance from existing fields'
    );
    assert_true(bakery_billing_square_status_failed('CANCELED'), 'Canceled Square counts as failed for filters');
    assert_true(!bakery_billing_square_status_failed('UNPAID'), 'Unpaid Square is not a failed filter');

    if ($squareColReady) {
        assert_true(bakery_billing_order_outstanding($loadOrder($db, $ordB1)) === 0.0, 'Square PAID delivery settles fully');
        assert_true(abs(bakery_billing_order_outstanding($loadOrder($db, $ordB4)) - 20.00) < 0.001, 'Invoiced-but-unpaid stays outstanding');
        assert_true(bakery_billing_order_outstanding(['delivery_order_total' => 75.00], true) === 0.0, 'Explicit settled override zeroes remainder');
    } else {
        echo "SKIP  square_status column missing; PAID-exclusion covered by source contract\n";
    }

    assert_true(abs(bakery_billing_order_outstanding($loadOrder($db, $ordB2)) - 0.01) < 0.0005, 'One-cent remainder survives cent tolerance');
    assert_true(bakery_billing_order_outstanding($loadOrder($db, $ordB3)) <= bakery_billing_outstanding_tolerance(), 'Sub-cent remainder treated as settled');

    $balances = bakery_billing_customer_balances($db);
    $rowA = $balances[$customerA] ?? null;
    $rowB = $balances[$customerB] ?? null;

    assert_true(is_array($rowA), 'Customer A appears in balances aggregation');
    if (is_array($rowA)) {
        assert_true(abs((float)$rowA['outstanding_total'] - 110.00) < 0.001, 'Customer A outstanding totals 60 + 50 = 110.00');
        assert_true((int)$rowA['outstanding_count'] === 2, 'Customer A has two outstanding deliveries');
        assert_true((int)$rowA['settled_count'] === 0, 'Customer A has no settled deliveries');
        assert_true(
            $rowA['oldest_outstanding_date'] === $oldDate,
            'Oldest outstanding date is the oldest unpaid confirmation'
        );
        assert_true(
            (int)$rowA['oldest_days'] >= 44 && (int)$rowA['oldest_days'] <= 46,
            'Oldest days computed from oldest confirmation (~45)'
        );
    }

    assert_true(is_array($rowB), 'Customer B appears in balances aggregation');
    if (is_array($rowB)) {
        assert_true(abs((float)$rowB['outstanding_total'] - 20.01) < 0.001, 'Customer B outstanding is 0.01 + 20.00 (Square-settled excluded)');
        assert_true((int)$rowB['outstanding_count'] === 2, 'Customer B has two outstanding deliveries');
        assert_true((int)$rowB['settled_count'] === 2, 'Customer B settled_count covers Square-PAID plus sub-cent rows');
    }

    assert_true(!isset($balances[$customerC]), 'Unconfirmed-only customer never appears in balances');

    $balanceC = bakery_billing_customer_balance($db, $customerC);
    assert_true((float)$balanceC['outstanding_total'] === 0.0 && (int)$balanceC['outstanding_count'] === 0, 'Scoped balance returns zeros for clean customer');
    assert_true(trim((string)$balanceC['customer_name']) !== '', 'Scoped balance still resolves customer name when clean');
    assert_true((int)$balanceC['settled_count'] === 0, 'Unconfirmed order does not count as settled context either');

    $balanceMissing = bakery_billing_customer_balance($db, 999999999);
    assert_true(
        (float)$balanceMissing['outstanding_total'] === 0.0
            && (int)$balanceMissing['outstanding_count'] === 0
            && $balanceMissing['customer_name'] === '',
        'Unknown customer gets null-safe zero balance'
    );
} catch (Throwable $e) {
    assert_true(false, 'Aging fixture: ' . $e->getMessage());
} finally {
    if ($agingOrderIds) {
        $in = implode(',', array_map('intval', $agingOrderIds));
        $db->exec('DELETE FROM daily_order_items WHERE daily_order_id IN (' . $in . ')');
        $db->exec('DELETE FROM daily_orders WHERE id IN (' . $in . ')');
    }
    if ($agingCustomerIds) {
        $inC = implode(',', array_map('intval', $agingCustomerIds));
        $db->exec('DELETE FROM daily_orders WHERE customer_id IN (' . $inC . ')');
        $db->exec('DELETE FROM customers WHERE id IN (' . $inC . ')');
    }
}

// ── Aging i18n parity (en + es) ──────────────────────────────────────────────
$agingEn = include $root . '/lang/en.php';
$agingEs = include $root . '/lang/es.php';
$agingKeys = [
    'billing.balances_aria',
    'billing.balances_chip_all',
    'billing.balances_chip_outstanding',
    'billing.balances_chip_aging30',
    'billing.balances_chip_unpaid14',
    'billing.balances_chip_cod_turnin',
    'billing.balances_chip_square_failed',
    'billing.settlement_row',
    'billing.balances_summary',
    'billing.balances_none',
    'hub.balance_label',
    'hub.balance_due',
    'hub.balance_current',
];
foreach ($agingKeys as $agingKey) {
    assert_true(isset($agingEn[$agingKey]) && trim((string)$agingEn[$agingKey]) !== '', "en key exists: {$agingKey}");
    assert_true(
        isset($agingEs[$agingKey]) && trim((string)$agingEs[$agingKey]) !== '' && $agingEs[$agingKey] !== $agingEn[$agingKey],
        "es key exists and is a genuine translation: {$agingKey}"
    );
}
assert_true(
    isset($agingEn['billing.balances_summary'])
        && strpos((string)$agingEn['billing.balances_summary'], ':n') !== false
        && strpos((string)$agingEn['billing.balances_summary'], ':total') !== false
        && strpos((string)$agingEn['billing.balances_summary'], ':m') !== false,
    'Summary key carries :n/:total/:m params'
);
assert_true(
    isset($agingEn['hub.balance_due'], $agingEs['hub.balance_due'])
        && strpos((string)$agingEn['hub.balance_due'], ':total') !== false
        && strpos((string)$agingEn['hub.balance_due'], ':days') !== false
        && strpos((string)$agingEs['hub.balance_due'], ':total') !== false
        && strpos((string)$agingEs['hub.balance_due'], ':days') !== false,
    'Hub balance-due key carries :total/:days params in both languages'
);
$panelSrc = (string)file_get_contents($root . '/includes/billing_panel_invoices.php');
assert_true(strpos($panelSrc, 'bakery_billing_settlement_row') !== false, 'Billing Center list renders settlement rows');
assert_true(
    strpos($panelSrc, 'unpaid14') !== false
        && strpos($panelSrc, 'cod_not_turned_in') !== false
        && strpos($panelSrc, 'square_failed') !== false,
    'Settlement filters are wired'
);

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
