<?php
/**
 * Canonical invoice send — snapshot totals, bulk confirmed-only, MAIL_DRIVER=log,
 * legacy generator quarantine, mark-invoiced still refuses unconfirmed.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';

define('ACCESS_ALLOWED', true);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/billing.php';
require_once $root . '/includes/invoice_document.php';

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $msg) use (&$pass, &$fail): void {
    if ($ok) {
        echo "PASS  $msg\n";
        $pass++;
        return;
    }
    echo "FAIL  $msg\n";
    $fail++;
};

$assert(defined('MAIL_DRIVER') && MAIL_DRIVER === 'log', 'MAIL_DRIVER=log (never SMTP a real customer)');
$assert(!bakery_billing_email_ready(), 'email_ready is false in log mode');
$assert(bakery_billing_is_fixture_noise(['order_date' => '2099-09-11']), '2099 dates are treated as test noise');
$assert(!bakery_billing_is_fixture_noise(['order_date' => '2026-08-12', 'customer_email' => 'mario@zaziesf.com']), 'real August deliveries are not test noise');
$assert(bakery_billing_work_queue([
    'needs_attention' => false,
    'is_cod' => false,
    'square_status' => '',
    'delivery_confirmed_at' => '2026-08-12 09:00:00',
    'category' => 'ready',
]) === 'to_send', 'ready non-COD without Square is To send');
$assert(bakery_billing_work_queue([
    'needs_attention' => false,
    'is_cod' => false,
    'square_status' => 'UNPAID',
    'square_invoice_id' => 'sq',
    'delivery_confirmed_at' => '2026-08-12 09:00:00',
    'category' => 'already_invoiced',
]) === 'waiting', 'Square UNPAID is Waiting on pay');

$billingSrc = (string)file_get_contents($root . '/includes/billing.php');
$assert(strpos($billingSrc, 'INVOICE_TEST_RECIPIENT') === false, 'billing send path does not use INVOICE_TEST_RECIPIENT');
$assert(strpos($billingSrc, 'EmailUtils') === false, 'billing send path does not call EmailUtils');

foreach (['generate_invoice.php', 'generate_invoice_simple.php', 'simple_invoice.php'] as $legacyFile) {
    $src = (string)file_get_contents($root . '/' . $legacyFile);
    $assert(strpos($src, 'p.price as unit_price') === false, $legacyFile . ' does not join live products.price');
    $assert(
        strpos($src, 'bakery_billing_legacy_generator_emit_quarantine') !== false,
        $legacyFile . ' quarantines to Billing Center'
    );
}

$ordersSrc = (string)file_get_contents($root . '/orders.php');
$assert(strpos($ordersSrc, 'simple_invoice.php') === false, 'orders.php no longer opens simple_invoice.php');
$assert(strpos($ordersSrc, 'generate_invoice_simple.php') === false, 'orders.php no longer emails via generate_invoice_simple.php');

$hubSrc = (string)file_get_contents($root . '/customer_record.php');
$assert(strpos($hubSrc, 'simple_invoice.php') === false, 'customer_record.php no longer links simple_invoice.php');
$assert(strpos($hubSrc, 'customer_invoice.php') !== false, 'customer_record.php links the canonical invoice');

$redirect = bakery_billing_legacy_generator_redirect([
    'customer_id' => 12,
    'start_date' => '2026-08-01',
    'end_date' => '2026-08-07',
]);
$assert(strpos($redirect, 'billing_center.php') === 0, 'legacy redirect targets Billing Center');
$assert(strpos($redirect, 'customer_id=12') !== false, 'legacy redirect preserves customer');

bakery_billing_ensure_invoice_send_schema($db);
$assert(bakery_billing_invoice_send_schema_ready($db), 'invoice send schema is ready');

$dateConfirmed = '2099-08-21';
$dateUnconfirmed = '2099-08-22';
$createdOrderIds = [];
$productRestores = [];
$customerRestores = [];

try {
    $originClause = function_exists('bakery_sfb_ops_origin_clause')
        ? bakery_sfb_ops_origin_clause('c', $db)
        : '';
    $customers = $db->query(
        'SELECT c.id FROM customers c WHERE 1=1 ' . $originClause . ' ORDER BY c.id LIMIT 3'
    )->fetchAll(PDO::FETCH_COLUMN);
    $assert(count($customers) >= 2, 'Need at least two wholesale customers');

    $products = $db->query('SELECT id, price FROM products ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($products) >= 1, 'Need at least one product');

    $customerA = (int)$customers[0];
    $customerB = (int)$customers[1];
    $product = $products[0];
    $productId = (int)$product['id'];
    $originalPrice = $product['price'];

    foreach ([$customerA, $customerB] as $cid) {
        $emailStmt = $db->prepare('SELECT email FROM customers WHERE id = ?');
        $emailStmt->execute([$cid]);
        $customerRestores[$cid] = $emailStmt->fetchColumn();
        $db->prepare('UPDATE customers SET email = ? WHERE id = ?')->execute([
            'invoice-send-' . $cid . '@example.invalid',
            $cid,
        ]);
    }

    $db->prepare('DELETE FROM daily_orders WHERE customer_id IN (?, ?) AND order_date IN (?, ?)')
        ->execute([$customerA, $customerB, $dateConfirmed, $dateUnconfirmed]);

    $insertOrder = static function (PDO $db, $customerId, $date, $status, $confirmed, $qty, $unitPrice) {
        $total = round($qty * $unitPrice, 2);
        $db->prepare(
            'INSERT INTO daily_orders
                (customer_id, order_date, status, total_amount, delivery_order_total,
                 delivery_pricing_label, delivery_confirmed_at, delivered_pieces)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $customerId,
            $date,
            $status,
            $total,
            $confirmed ? $total : null,
            $confirmed ? 'test snapshot' : null,
            $confirmed ? $date . ' 09:00:00' : null,
            $confirmed ? $qty : null,
        ]);
        $orderId = (int)$db->lastInsertId();
        $db->prepare(
            'INSERT INTO daily_order_items
                (daily_order_id, product_id, quantity, delivered_quantity, unit_price, line_total)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $orderId,
            $GLOBALS['invoice_send_product_id'],
            $qty,
            $confirmed ? $qty : null,
            $unitPrice,
            $total,
        ]);
        return $orderId;
    };

    $GLOBALS['invoice_send_product_id'] = $productId;
    $orderA = $insertOrder($db, $customerA, $dateConfirmed, 'delivered', true, 2, 4.25);
    $orderB = $insertOrder($db, $customerB, $dateConfirmed, 'delivered', true, 3, 4.00);
    $orderOpen = $insertOrder($db, $customerA, $dateUnconfirmed, 'pending', false, 2, 4.25);
    $createdOrderIds = [$orderA, $orderB, $orderOpen];
    $assert($orderA > 0 && $orderB > 0 && $orderOpen > 0, 'Inserted confirmed and unconfirmed fixtures');

    $unconfirmedFailed = false;
    try {
        bakery_billing_mark_invoiced($db, $orderOpen, null);
    } catch (Throwable $e) {
        $unconfirmedFailed = strpos($e->getMessage(), 'delivery not confirmed') !== false;
    }
    $assert($unconfirmedFailed, 'bakery_billing_mark_invoiced still refuses unconfirmed deliveries');
    $openStatus = $db->query('SELECT status FROM daily_orders WHERE id = ' . (int)$orderOpen)->fetchColumn();
    $assert($openStatus === 'pending', 'unconfirmed order status unchanged');

    $productRestores[$productId] = $originalPrice;
    $db->prepare('UPDATE products SET price = 999.99 WHERE id = ?')->execute([$productId]);

    $invoiceA = bakery_billing_load_canonical_invoice($db, $orderA);
    $assert((float)$invoiceA['invoice_total'] === 8.50, 'canonical invoice total uses snapshot 8.50');
    $linePrice = (float)($invoiceA['items'][0]['unit_price'] ?? 0);
    $assert($linePrice === 4.25, 'line unit_price is the frozen snapshot, not products.price');
    $html = bakery_billing_invoice_document_html($invoiceA, ['mode' => 'email']);
    $assert(strpos($html, '8.50') !== false, 'rendered document shows snapshot total 8.50');
    $assert(strpos($html, '4.25') !== false, 'rendered document shows snapshot unit price 4.25');
    $assert(strpos($html, '999.99') === false, 'rendered document does not show live catalog 999.99');

    $GLOBALS['bakery_billing_smtp_attempted'] = false;
    $sendA = bakery_billing_send_invoice($db, $orderA, null);
    $assert(!empty($sendA['ok']), 'send succeeds in MAIL_DRIVER=log');
    $assert(($sendA['channel'] ?? '') === 'log', 'log-mode channel is log');
    $assert(($sendA['amount'] ?? 0) == 8.50, 'send records snapshot amount 8.50');
    $assert(($sendA['recipient'] ?? '') === 'invoice-send-' . $customerA . '@example.invalid', 'send uses customer billing email');
    $assert(empty($sendA['smtp_attempted']) && empty($GLOBALS['bakery_billing_smtp_attempted']), 'log-mode does not call SMTP');
    $assert(!empty($sendA['marked_invoiced']), 'send marks invoiced when not already invoiced');
    $statusA = $db->query('SELECT status FROM daily_orders WHERE id = ' . (int)$orderA)->fetchColumn();
    $assert($statusA === 'invoiced', 'confirmed send marks daily_orders.status invoiced');

    $mailLog = $root . '/logs/mail.log';
    $logContents = is_readable($mailLog) ? (string)file_get_contents($mailLog) : '';
    $assert(
        strpos($logContents, (string)$sendA['invoice_number']) !== false
            && strpos($logContents, 'invoice-send-' . $customerA . '@example.invalid') !== false,
        'mail.log records canonical send without a test inbox override'
    );

    $outbox = $db->prepare(
        'SELECT channel, status, amount, sent_to_email FROM billing_invoice_sends WHERE daily_order_id = ? ORDER BY id DESC LIMIT 1'
    );
    $outbox->execute([$orderA]);
    $outboxRow = $outbox->fetch(PDO::FETCH_ASSOC);
    $assert($outboxRow && $outboxRow['channel'] === 'log', 'outbox row persisted for log send');
    $assert((float)$outboxRow['amount'] === 8.50, 'outbox amount is snapshot total');

    $sendUnconfirmedFailed = false;
    try {
        bakery_billing_send_invoice($db, $orderOpen, null);
    } catch (Throwable $e) {
        $sendUnconfirmedFailed = strpos($e->getMessage(), 'delivery not confirmed') !== false;
    }
    $assert($sendUnconfirmedFailed, 'single send refuses unconfirmed deliveries');

    $batch = bakery_billing_send_invoices($db, [$orderA, $orderB, $orderOpen], null);
    $assert($batch['sent'] === 2, 'bulk send includes selected confirmed orders (including an allowed re-send)');
    $assert($batch['skipped'] === 1, 'bulk send skips the unconfirmed order');
    $statusB = $db->query('SELECT status FROM daily_orders WHERE id = ' . (int)$orderB)->fetchColumn();
    $assert($statusB === 'invoiced', 'bulk send marks the confirmed order invoiced');
    $openAfter = $db->query('SELECT status FROM daily_orders WHERE id = ' . (int)$orderOpen)->fetchColumn();
    $assert($openAfter === 'pending', 'bulk send does not invoice unconfirmed orders');

    $resend = bakery_billing_send_invoice($db, $orderA, null);
    $assert(!empty($resend['ok']), 're-send is allowed');
    $sendCount = (int)$db->query(
        'SELECT COUNT(*) FROM billing_invoice_sends WHERE daily_order_id = ' . (int)$orderA
    )->fetchColumn();
    $assert($sendCount >= 2, 're-send writes another outbox row');

    // ---- Mission 35: outbox pattern — mail failure never fakes a sent row -------
    $stampBefore = $db->query('SELECT invoice_sent_at FROM daily_orders WHERE id = ' . (int)$orderA)->fetchColumn();
    $GLOBALS['bakery_billing_mail_handler'] = static function () {
        throw new RuntimeException('SMTP connect() failed (test)');
    };
    $failedSend = bakery_billing_send_invoice($db, $orderA, null);
    unset($GLOBALS['bakery_billing_mail_handler']);
    $assert(empty($failedSend['ok']) && ($failedSend['reason'] ?? '') === 'mail_failed', 'SMTP failure returns ok=false reason=mail_failed instead of throwing');
    $failedRow = $db->query('SELECT status, channel, failure_reason FROM billing_invoice_sends WHERE daily_order_id = ' . (int)$orderA . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $assert($failedRow && $failedRow['status'] === 'failed', 'failed attempt leaves an honest failed outbox row');
    $assert($failedRow && strpos((string)$failedRow['failure_reason'], 'SMTP connect') !== false, 'failed row records the reason');
    $stampAfter = $db->query('SELECT invoice_sent_at FROM daily_orders WHERE id = ' . (int)$orderA)->fetchColumn();
    $assert((string)$stampAfter === (string)$stampBefore, 'failed attempt does not stamp daily_orders.invoice_sent_at');
    $statusAfterFail = $db->query('SELECT status FROM daily_orders WHERE id = ' . (int)$orderA)->fetchColumn();
    $assert($statusAfterFail === 'invoiced', 'order stays invoiced after a failed email (resend allowed)');
    $failedMap = bakery_billing_failed_sends($db, [$orderA, $orderB]);
    $assert(isset($failedMap[$orderA]) && !isset($failedMap[$orderB]), 'failed-send lookup names only the order whose latest send failed');
    $queuedLeft = (int)$db->query("SELECT COUNT(*) FROM billing_invoice_sends WHERE status = 'queued' AND daily_order_id IN (" . (int)$orderA . ',' . (int)$orderB . ')')->fetchColumn();
    $assert($queuedLeft === 0, 'no outbox row is left queued after attempts complete');
    $GLOBALS['bakery_billing_mail_handler'] = static function () { return 'smtp'; };
    $recovered = bakery_billing_send_invoice($db, $orderA, null);
    unset($GLOBALS['bakery_billing_mail_handler']);
    $assert(!empty($recovered['ok']) && ($recovered['status'] ?? '') === 'sent', 'resend after fixing mail records sent');
    $assert(bakery_billing_failed_sends($db, [$orderA]) === [], 'successful resend clears the failed chip');
    $batchFail = null;
    $GLOBALS['bakery_billing_mail_handler'] = static function () { throw new RuntimeException('boom'); };
    $batchFail = bakery_billing_send_invoices($db, [$orderA], null);
    unset($GLOBALS['bakery_billing_mail_handler']);
    $assert(($batchFail['failed'] ?? 0) === 1 && $batchFail['sent'] === 0, 'bulk send counts mail failures separately from skips');
} catch (Throwable $e) {
    echo 'FAIL  fixture/runtime: ' . $e->getMessage() . "\n";
    $fail++;
} finally {
    if ($createdOrderIds) {
        $placeholders = implode(',', array_fill(0, count($createdOrderIds), '?'));
        if (table_exists($db, 'billing_invoice_sends')) {
            $db->prepare('DELETE FROM billing_invoice_sends WHERE daily_order_id IN (' . $placeholders . ')')
                ->execute($createdOrderIds);
        }
        $db->prepare('DELETE FROM daily_orders WHERE id IN (' . $placeholders . ')')->execute($createdOrderIds);
    }
    foreach ($productRestores as $pid => $price) {
        $db->prepare('UPDATE products SET price = ? WHERE id = ?')->execute([$price, $pid]);
    }
    foreach ($customerRestores as $cid => $email) {
        $db->prepare('UPDATE customers SET email = ? WHERE id = ?')->execute([$email === false ? null : $email, $cid]);
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
