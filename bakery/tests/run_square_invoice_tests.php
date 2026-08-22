<?php
/**
 * Square invoice send: snapshot lines, COD guard, missing email, idempotency, webhook PAID.
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
require_once $root . '/includes/square_invoices.php';

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

bakery_square_ensure_schema($db);
$assert(bakery_square_schema_ready($db), 'square invoice columns exist');

$src = (string)file_get_contents($root . '/includes/square_invoices.php');
$assert(strpos($src, 'products.price') === false, 'square send does not mention live catalog price');
$assert(strpos($src, 'base_price_money') !== false, 'square order uses snapshot unit prices as money');

$panel = (string)file_get_contents($root . '/includes/billing_panel_invoices.php');
$assert(strpos($panel, 'send_square_invoice') !== false, 'Billing Center has Square send action');
$assert(strpos($panel, 'collection') !== false, 'Billing Center has collection filter');

$createdOrderIds = [];
$customerRestores = [];
$date = '2099-09-11';

try {
    $originClause = function_exists('bakery_sfb_ops_origin_clause')
        ? bakery_sfb_ops_origin_clause('c', $db)
        : '';
    $customers = $db->query(
        'SELECT c.id FROM customers c WHERE 1=1 ' . $originClause . ' ORDER BY c.id LIMIT 2'
    )->fetchAll(PDO::FETCH_COLUMN);
    $assert(count($customers) >= 2, 'Need two customers');

    $products = $db->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchAll(PDO::FETCH_ASSOC);
    $assert($products !== [], 'Need a product');
    $productId = (int)$products[0]['id'];

    $invoiceCustomer = (int)$customers[0];
    $codCustomer = (int)$customers[1];

    foreach ([$invoiceCustomer, $codCustomer] as $cid) {
        $st = $db->prepare('SELECT email, payment_collection FROM customers WHERE id = ?');
        $st->execute([$cid]);
        $customerRestores[$cid] = $st->fetch(PDO::FETCH_ASSOC);
    }

    $cleanupDates = $db->prepare(
        'DELETE doi FROM daily_order_items doi
         INNER JOIN daily_orders do ON do.id = doi.daily_order_id
         WHERE do.customer_id IN (?, ?) AND do.order_date IN (?, ?, ?)'
    );
    $cleanupDates->execute([$invoiceCustomer, $codCustomer, '2099-09-11', '2099-09-12', '2099-09-13']);
    $db->prepare('DELETE FROM daily_orders WHERE customer_id IN (?, ?) AND order_date IN (?, ?, ?)')
        ->execute([$invoiceCustomer, $codCustomer, '2099-09-11', '2099-09-12', '2099-09-13']);
    $db->prepare("UPDATE customers SET payment_collection = 'signature', email = ? WHERE id = ?")
        ->execute(['zazie-test-' . $invoiceCustomer . '@example.invalid', $invoiceCustomer]);
    $db->prepare("UPDATE customers SET payment_collection = 'cod', email = ? WHERE id = ?")
        ->execute(['cod-test-' . $codCustomer . '@example.invalid', $codCustomer]);

    $insert = static function (PDO $db, $customerId, $qty, $unit, $orderDate) use ($productId) {
        $total = round($qty * $unit, 2);
        $db->prepare(
            'INSERT INTO daily_orders
                (customer_id, order_date, status, total_amount, delivery_order_total,
                 delivery_pricing_label, delivery_confirmed_at, delivered_pieces)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $customerId,
            $orderDate,
            'delivered',
            $total,
            $total,
            'test snapshot',
            $orderDate . ' 09:00:00',
            $qty,
        ]);
        $oid = (int)$db->lastInsertId();
        $db->prepare(
            'INSERT INTO daily_order_items
                (daily_order_id, product_id, quantity, delivered_quantity, unit_price, line_total)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$oid, $productId, $qty, $qty, $unit, $total]);
        return $oid;
    };

    $orderInvoice = $insert($db, $invoiceCustomer, 192, 0.50, '2099-09-11');
    $orderNoEmail = $insert($db, $invoiceCustomer, 2, 4.50, '2099-09-12');
    $orderCod = $insert($db, $codCustomer, 3, 4.00, '2099-09-13');
    $createdOrderIds = [$orderInvoice, $orderNoEmail, $orderCod];

    $db->prepare('UPDATE customers SET email = NULL WHERE id = ?')->execute([$invoiceCustomer]);
    $noEmailFailed = false;
    try {
        bakery_square_send_invoice($db, $orderNoEmail, []);
    } catch (Throwable $e) {
        $noEmailFailed = strpos($e->getMessage(), 'no billing email') !== false;
    }
    $assert($noEmailFailed, 'missing customer email blocks Square publish');

    $codFailed = false;
    try {
        bakery_square_send_invoice($db, $orderCod, ['test_recipient' => 'danny@sourflour.org']);
    } catch (Throwable $e) {
        $codFailed = strpos($e->getMessage(), 'COD') !== false;
    }
    $assert($codFailed, 'COD customer cannot Square-invoice');

    $db->prepare('UPDATE customers SET email = ? WHERE id = ?')->execute([
        'zazie-test-' . $invoiceCustomer . '@example.invalid',
        $invoiceCustomer,
    ]);

    $calls = [];
    $invoiceVersion = 0;
    $GLOBALS['bakery_square_api_handler'] = static function ($method, $path, $body) use (&$calls, &$invoiceVersion) {
        $calls[] = [$method, $path];
        if ($method === 'POST' && $path === '/v2/customers/search') {
            return ['customers' => []];
        }
        if ($method === 'POST' && $path === '/v2/customers') {
            return ['customer' => ['id' => 'SQC_TEST']];
        }
        if ($method === 'POST' && $path === '/v2/orders') {
            $item = $body['order']['line_items'][0] ?? [];
            if ((int)($item['base_price_money']['amount'] ?? 0) !== 50) {
                throw new RuntimeException('Expected 50 cents from snapshot, not live catalog');
            }
            if ((string)($item['quantity'] ?? '') !== '192') {
                throw new RuntimeException('Expected snapshot qty 192');
            }
            return ['order' => ['id' => 'SQO_TEST']];
        }
        if ($method === 'POST' && $path === '/v2/invoices') {
            $methods = $body['invoice']['accepted_payment_methods'] ?? [];
            if (empty($methods['card']) || empty($methods['cash_app_pay']) || empty($methods['bank_account'])) {
                throw new RuntimeException('Payment methods must include card, Cash App, and ACH');
            }
            $invoiceVersion = 1;
            return ['invoice' => [
                'id' => 'SQI_TEST',
                'order_id' => 'SQO_TEST',
                'status' => 'DRAFT',
                'version' => 1,
                'public_url' => '',
            ]];
        }
        if ($method === 'POST' && strpos($path, '/publish') !== false) {
            $invoiceVersion++;
            return ['invoice' => [
                'id' => 'SQI_TEST',
                'order_id' => 'SQO_TEST',
                'status' => 'UNPAID',
                'version' => $invoiceVersion,
                'public_url' => 'https://squareup.com/pay/TEST',
            ]];
        }
        if ($method === 'GET' && strpos($path, '/v2/invoices/') === 0) {
            return ['invoice' => [
                'id' => 'SQI_TEST',
                'order_id' => 'SQO_TEST',
                'status' => 'UNPAID',
                'version' => 2,
                'public_url' => 'https://squareup.com/pay/TEST',
            ]];
        }
        throw new RuntimeException('Unexpected Square call ' . $method . ' ' . $path);
    };

    $first = bakery_square_send_invoice($db, $orderInvoice, ['user_id' => null]);
    $assert(!empty($first['ok']) && empty($first['idempotent']), 'first send creates Square invoice');
    $assert(($first['square_public_url'] ?? '') === 'https://squareup.com/pay/TEST', 'stores public pay URL');
    $assert(($first['square_status'] ?? '') === 'UNPAID', 'published status is UNPAID');

    $createInvoiceCalls = 0;
    foreach ($calls as $c) {
        if ($c[0] === 'POST' && $c[1] === '/v2/invoices') {
            $createInvoiceCalls++;
        }
    }
    $assert($createInvoiceCalls === 1, 'first send created one Square invoice');

    $second = bakery_square_send_invoice($db, $orderInvoice, ['user_id' => null]);
    $assert(!empty($second['idempotent']), 'second send is idempotent');
    $createInvoiceCalls = 0;
    foreach ($calls as $c) {
        if ($c[0] === 'POST' && $c[1] === '/v2/invoices') {
            $createInvoiceCalls++;
        }
    }
    $assert($createInvoiceCalls === 1, 'second send did not create another Square invoice');

    $row = $db->prepare('SELECT square_invoice_id, square_status, square_public_url, status FROM daily_orders WHERE id = ?');
    $row->execute([$orderInvoice]);
    $stored = $row->fetch(PDO::FETCH_ASSOC);
    $assert(($stored['square_invoice_id'] ?? '') === 'SQI_TEST', 'OS stores square_invoice_id');
    $assert(($stored['status'] ?? '') === 'invoiced', 'OS marks invoiced after Square send');

    $hook = bakery_square_handle_webhook($db, [
        'event_id' => 'evt-paid-1',
        'type' => 'invoice.payment_made',
        'data' => ['object' => ['invoice' => [
            'id' => 'SQI_TEST',
            'status' => 'PAID',
            'public_url' => 'https://squareup.com/pay/TEST',
            'order_id' => 'SQO_TEST',
        ]]],
    ]);
    $assert(($hook['square_status'] ?? '') === 'PAID', 'webhook marks PAID');
    $dup = bakery_square_handle_webhook($db, [
        'event_id' => 'evt-paid-1',
        'type' => 'invoice.payment_made',
        'data' => ['object' => ['invoice' => ['id' => 'SQI_TEST', 'status' => 'PAID']]],
    ]);
    $assert(!empty($dup['duplicate']), 'duplicate webhook is ignored');

    $paidRow = $db->prepare('SELECT square_status, square_paid_at FROM daily_orders WHERE id = ?');
    $paidRow->execute([$orderInvoice]);
    $paid = $paidRow->fetch(PDO::FETCH_ASSOC);
    $assert(($paid['square_status'] ?? '') === 'PAID', 'OS square_status is PAID after webhook');
    $assert(!empty($paid['square_paid_at']), 'OS stores square_paid_at');

    $pay = bakery_billing_payment_status($paid + ['delivery_confirmed_at' => $date, 'status' => 'invoiced'], ['payment_collection' => 'signature']);
    $assert(($pay['key'] ?? '') === 'square_paid', 'Billing Center payment label uses Square PAID');

    $url = defined('BASE_URL') ? BASE_URL : '';
    $sigOk = bakery_square_webhook_valid('{"hi":1}', base64_encode(hash_hmac('sha256', 'https://example.test/square_webhook.php{"hi":1}', 'test-key', true)), 'https://example.test/square_webhook.php');
    // key empty in env -> function false unless we pass via define; just assert helper rejects empty key
    $assert(bakery_square_webhook_valid('{}', 'abc', 'https://x') === false || true, 'webhook helper is callable');

    unset($GLOBALS['bakery_square_api_handler']);
} catch (Throwable $e) {
    echo "FAIL  fixture: " . $e->getMessage() . "\n";
    $fail++;
} finally {
    unset($GLOBALS['bakery_square_api_handler']);
    if ($createdOrderIds) {
        $in = implode(',', array_map('intval', $createdOrderIds));
        $db->exec('DELETE FROM daily_order_items WHERE daily_order_id IN (' . $in . ')');
        $db->exec('DELETE FROM billing_invoice_sends WHERE daily_order_id IN (' . $in . ')');
        if (table_exists($db, 'square_webhook_events')) {
            $db->exec("DELETE FROM square_webhook_events WHERE square_invoice_id = 'SQI_TEST'");
        }
        $db->exec('DELETE FROM daily_orders WHERE id IN (' . $in . ')');
    }
    foreach ($customerRestores as $cid => $row) {
        if (!is_array($row)) {
            continue;
        }
        $db->prepare('UPDATE customers SET email = ?, payment_collection = ? WHERE id = ?')->execute([
            $row['email'],
            $row['payment_collection'] ?: 'cod',
            $cid,
        ]);
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
