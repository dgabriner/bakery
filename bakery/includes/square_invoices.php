<?php
/**
 * Square Invoices for non-COD Billing Center deliveries.
 * Line amounts come from the delivery snapshot, never live catalog prices.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/square_config.php';
require_once __DIR__ . '/billing.php';

function bakery_square_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    if (!function_exists('bakery_runtime_schema_ddl_allowed') || !bakery_runtime_schema_ddl_allowed()) {
        $done = true;
        return;
    }
    if (!table_exists($db, 'daily_orders')) {
        $done = true;
        return;
    }

    if (table_exists($db, 'customers') && !column_exists($db, 'customers', 'square_customer_id')) {
        $db->exec('ALTER TABLE customers ADD COLUMN square_customer_id VARCHAR(64) NULL DEFAULT NULL');
        if (function_exists('bakery_forget_column_exists')) {
            bakery_forget_column_exists('customers', 'square_customer_id');
        }
    }

    $columns = [
        'square_invoice_id' => 'VARCHAR(64) NULL DEFAULT NULL',
        'square_order_id' => 'VARCHAR(64) NULL DEFAULT NULL',
        'square_customer_id' => 'VARCHAR(64) NULL DEFAULT NULL',
        'square_public_url' => 'VARCHAR(512) NULL DEFAULT NULL',
        'square_status' => 'VARCHAR(32) NULL DEFAULT NULL',
        'square_recipient_email' => 'VARCHAR(255) NULL DEFAULT NULL',
        'square_published_at' => 'DATETIME NULL DEFAULT NULL',
        'square_paid_at' => 'DATETIME NULL DEFAULT NULL',
        'square_last_synced_at' => 'DATETIME NULL DEFAULT NULL',
    ];
    foreach ($columns as $name => $definition) {
        if (!column_exists($db, 'daily_orders', $name)) {
            $db->exec('ALTER TABLE daily_orders ADD COLUMN `' . $name . '` ' . $definition);
            if (function_exists('bakery_forget_column_exists')) {
                bakery_forget_column_exists('daily_orders', $name);
            }
        }
    }

    if (!table_exists($db, 'square_webhook_events')) {
        $db->exec(
            'CREATE TABLE square_webhook_events (
                id INT NOT NULL AUTO_INCREMENT,
                event_id VARCHAR(80) NOT NULL,
                event_type VARCHAR(80) NULL DEFAULT NULL,
                square_invoice_id VARCHAR(64) NULL DEFAULT NULL,
                daily_order_id INT NULL DEFAULT NULL,
                processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_square_webhook_event_id (event_id),
                KEY idx_square_webhook_invoice (square_invoice_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        if (function_exists('bakery_forget_table_exists')) {
            bakery_forget_table_exists('square_webhook_events');
        }
    }

    $done = true;
}

function bakery_square_schema_ready(PDO $db): bool
{
    return table_exists($db, 'daily_orders')
        && column_exists($db, 'daily_orders', 'square_invoice_id');
}

function bakery_square_is_cod_customer(array $customer): bool
{
    return strtolower(trim((string)($customer['payment_collection'] ?? 'cod'))) === 'cod';
}

function bakery_square_normalize_status($status): string
{
    $status = strtoupper(trim((string)$status));
    if ($status === '') {
        return '';
    }
    if (in_array($status, ['PAID', 'PAYMENT_MADE'], true)) {
        return 'PAID';
    }
    if (in_array($status, ['CANCELED', 'CANCELLED', 'FAILED'], true)) {
        return 'CANCELED';
    }
    if (in_array($status, ['DRAFT', 'UNPAID', 'SCHEDULED', 'PARTIALLY_PAID', 'PAYMENT_PENDING'], true)) {
        return $status === 'SCHEDULED' ? 'UNPAID' : $status;
    }
    return $status;
}

function bakery_square_cents($dollars): int
{
    return (int)round(((float)$dollars) * 100);
}

/**
 * @return array{email:string,source:string}
 */
function bakery_square_resolve_recipient(array $customer, $overrideEmail = ''): array
{
    $override = trim((string)$overrideEmail);
    if ($override !== '') {
        if (!filter_var($override, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Test recipient is not a valid email.');
        }
        return ['email' => $override, 'source' => 'test_override'];
    }
    $email = bakery_billing_customer_billing_email($customer);
    if ($email === '') {
        throw new RuntimeException('Customer has no billing email. Add one on the customer record, or use the test recipient field.');
    }
    return ['email' => $email, 'source' => 'customer'];
}

/**
 * @return array<int, array<string, mixed>>
 */
function bakery_square_order_line_items(array $invoice): array
{
    $lines = [];
    foreach ($invoice['items'] ?? [] as $item) {
        $qty = $item['delivered_quantity'] !== null && $item['delivered_quantity'] !== ''
            ? (int)$item['delivered_quantity']
            : (int)($item['quantity'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $unit = (float)($item['unit_price'] ?? 0);
        $lines[] = [
            'name' => (string)($item['product_name'] ?? $item['product'] ?? 'Item'),
            'quantity' => (string)$qty,
            'base_price_money' => [
                'amount' => bakery_square_cents($unit),
                'currency' => 'USD',
            ],
        ];
    }
    if ($lines === []) {
        throw new RuntimeException('Invoice has no snapshot line items to send to Square.');
    }
    return $lines;
}

function bakery_square_load_order_row(PDO $db, $orderId): array
{
    bakery_square_ensure_schema($db);
    $orderId = (int)$orderId;
    $sqCols = bakery_square_schema_ready($db)
        ? ', do.square_invoice_id, do.square_order_id, do.square_customer_id, do.square_public_url,
           do.square_status, do.square_recipient_email, do.square_published_at, do.square_paid_at,
           do.square_last_synced_at'
        : '';
    $custSq = column_exists($db, 'customers', 'square_customer_id')
        ? ', c.square_customer_id AS customer_square_customer_id'
        : ', NULL AS customer_square_customer_id';
    $stmt = $db->prepare(
        'SELECT do.id, do.customer_id, do.order_date, do.status, do.delivery_confirmed_at,
                do.total_amount, do.delivery_order_total' . $sqCols . ',
                c.name AS customer_name, c.email AS customer_email, c.payment_collection,
                c.phone AS customer_phone' . $custSq . '
         FROM daily_orders do
         JOIN customers c ON c.id = do.customer_id
         WHERE do.id = ?
         LIMIT 1'
    );
    $stmt->execute([$orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Order not found or delivery not confirmed');
    }
    return $row;
}

function bakery_square_persist(PDO $db, $orderId, array $fields): void
{
    bakery_square_ensure_schema($db);
    $allowed = [
        'square_invoice_id', 'square_order_id', 'square_customer_id', 'square_public_url',
        'square_status', 'square_recipient_email', 'square_published_at', 'square_paid_at',
        'square_last_synced_at',
    ];
    $sets = [];
    $values = [];
    foreach ($allowed as $col) {
        if (array_key_exists($col, $fields) && column_exists($db, 'daily_orders', $col)) {
            $sets[] = '`' . $col . '` = ?';
            $values[] = $fields[$col];
        }
    }
    if ($sets === []) {
        return;
    }
    $values[] = (int)$orderId;
    $db->prepare('UPDATE daily_orders SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($values);
}

function bakery_square_ensure_customer(array $orderRow, $email, $testOverride = false): string
{
    $existing = trim((string)($orderRow['square_customer_id'] ?? $orderRow['customer_square_customer_id'] ?? ''));
    if ($existing !== '' && !$testOverride) {
        return $existing;
    }

    $search = square_api_request('POST', '/v2/customers/search', [
        'query' => [
            'filter' => [
                'email_address' => ['exact' => $email],
            ],
        ],
    ]);
    $found = $search['customers'][0]['id'] ?? '';
    if ($found !== '') {
        return (string)$found;
    }

    $osCustomerId = (int)$orderRow['customer_id'];
    $payload = [
        'given_name' => $testOverride
            ? ('TEST ' . (string)$orderRow['customer_name'])
            : (string)$orderRow['customer_name'],
        'email_address' => $email,
        'reference_id' => $testOverride
            ? ('os-customer-' . $osCustomerId . '-test')
            : ('os-customer-' . $osCustomerId),
        'note' => 'Sour Flour OS customer_id=' . $osCustomerId,
    ];
    $phone = trim((string)($orderRow['customer_phone'] ?? ''));
    if ($phone !== '') {
        $payload['phone_number'] = $phone;
    }
    $created = square_api_request('POST', '/v2/customers', $payload);
    $id = (string)($created['customer']['id'] ?? '');
    if ($id === '') {
        throw new RuntimeException('Square did not return a customer id.');
    }
    return $id;
}

function bakery_square_apply_invoice_payload(PDO $db, $orderId, array $invoice, array $extra = []): array
{
    $status = bakery_square_normalize_status($invoice['status'] ?? '');
    $publicUrl = (string)($invoice['public_url'] ?? $invoice['publicUrl'] ?? '');
    $now = date('Y-m-d H:i:s');
    $fields = array_merge([
        'square_invoice_id' => (string)($invoice['id'] ?? ''),
        'square_order_id' => (string)($invoice['order_id'] ?? ''),
        'square_public_url' => $publicUrl !== '' ? $publicUrl : ($extra['square_public_url'] ?? null),
        'square_status' => $status !== '' ? $status : null,
        'square_last_synced_at' => $now,
    ], $extra);

    if ($status === 'PAID' && empty($extra['keep_paid_at'])) {
        $fields['square_paid_at'] = $extra['square_paid_at'] ?? $now;
    }

    bakery_square_persist($db, $orderId, $fields);

    if ($status === 'PAID') {
        $st = $db->prepare('SELECT status, delivery_confirmed_at FROM daily_orders WHERE id = ?');
        $st->execute([(int)$orderId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && (string)$row['status'] !== 'invoiced' && !empty($row['delivery_confirmed_at'])) {
            bakery_billing_mark_invoiced($db, (int)$orderId, $extra['user_id'] ?? null);
        }
    }

    return $fields;
}

/**
 * Create or reuse a Square invoice for a confirmed non-COD delivery.
 *
 * @param array{draft_only?:bool,test_recipient?:string,user_id?:int|null} $opts
 * @return array<string, mixed>
 */
function bakery_square_send_invoice(PDO $db, $orderId, array $opts = []): array
{
    if (!square_is_configured() && !isset($GLOBALS['bakery_square_api_handler'])) {
        throw new RuntimeException('Square is not configured. Set SQUARE_ACCESS_TOKEN and SQUARE_LOCATION_ID.');
    }

    $orderId = (int)$orderId;
    $draftOnly = !empty($opts['draft_only']);
    $testRecipient = (string)($opts['test_recipient'] ?? '');
    $userId = isset($opts['user_id']) ? $opts['user_id'] : null;

    $row = bakery_square_load_order_row($db, $orderId);
    if (empty($row['delivery_confirmed_at'])) {
        throw new RuntimeException('Order not found or delivery not confirmed');
    }
    if (bakery_square_is_cod_customer($row)) {
        throw new RuntimeException('This customer is COD cash at delivery. Use Route Manager, not Square.');
    }

    $existingId = trim((string)($row['square_invoice_id'] ?? ''));
    if ($existingId !== '') {
        $got = square_api_request('GET', '/v2/invoices/' . rawurlencode($existingId));
        $invoice = $got['invoice'] ?? [];
        $status = bakery_square_normalize_status($invoice['status'] ?? $row['square_status'] ?? '');
        if (!$draftOnly && $status === 'DRAFT' && isset($invoice['version'])) {
            $published = square_api_request('POST', '/v2/invoices/' . rawurlencode($existingId) . '/publish', [
                'version' => (int)$invoice['version'],
                'idempotency_key' => 'os-publish-' . $orderId,
            ]);
            $invoice = $published['invoice'] ?? $invoice;
            $status = bakery_square_normalize_status($invoice['status'] ?? 'UNPAID');
            bakery_square_apply_invoice_payload($db, $orderId, $invoice, [
                'square_published_at' => date('Y-m-d H:i:s'),
                'user_id' => $userId,
            ]);
        } else {
            bakery_square_apply_invoice_payload($db, $orderId, $invoice, ['user_id' => $userId]);
        }
        return [
            'ok' => true,
            'idempotent' => true,
            'daily_order_id' => $orderId,
            'square_invoice_id' => $existingId,
            'square_public_url' => (string)($invoice['public_url'] ?? $row['square_public_url'] ?? ''),
            'square_status' => bakery_square_normalize_status($invoice['status'] ?? $status),
        ];
    }

    $invoiceDoc = bakery_billing_load_canonical_invoice($db, $orderId);
    $customer = $invoiceDoc['customer'] ?? [];
    $customer['payment_collection'] = $row['payment_collection'] ?? ($customer['payment_collection'] ?? 'signature');
    $recipient = bakery_square_resolve_recipient($customer, $testRecipient);
    $squareCustomerId = bakery_square_ensure_customer($row, $recipient['email'], $recipient['source'] === 'test_override');

    if ($recipient['source'] !== 'test_override' && column_exists($db, 'customers', 'square_customer_id')) {
        $db->prepare('UPDATE customers SET square_customer_id = ? WHERE id = ?')->execute([
            $squareCustomerId,
            (int)$row['customer_id'],
        ]);
    }

    $invoiceNumber = bakery_billing_invoice_number($orderId, $row['order_date']);
    $lineItems = bakery_square_order_line_items($invoiceDoc);

    $orderResp = square_api_request('POST', '/v2/orders', [
        'idempotency_key' => 'os-order-' . $orderId,
        'order' => [
            'location_id' => SQUARE_LOCATION_ID !== '' ? SQUARE_LOCATION_ID : 'test-location',
            'customer_id' => $squareCustomerId,
            'reference_id' => $invoiceNumber,
            'line_items' => $lineItems,
        ],
    ]);
    $squareOrderId = (string)($orderResp['order']['id'] ?? '');
    if ($squareOrderId === '') {
        throw new RuntimeException('Square did not return an order id.');
    }

    $due = date('Y-m-d', strtotime((string)$row['order_date'] . ' +14 days') ?: time());
    $invoiceResp = square_api_request('POST', '/v2/invoices', [
        'idempotency_key' => 'os-invoice-' . $orderId,
        'invoice' => [
            'location_id' => SQUARE_LOCATION_ID !== '' ? SQUARE_LOCATION_ID : 'test-location',
            'order_id' => $squareOrderId,
            'primary_recipient' => ['customer_id' => $squareCustomerId],
            'delivery_method' => 'EMAIL',
            'invoice_number' => $invoiceNumber,
            'title' => $invoiceNumber . ' ' . (string)$row['customer_name'],
            'description' => 'Sour Flour delivery invoice. Amounts are from the delivery snapshot.',
            'payment_requests' => [[
                'request_type' => 'BALANCE',
                'due_date' => $due,
            ]],
            'accepted_payment_methods' => [
                'card' => true,
                'square_gift_card' => false,
                'bank_account' => true,
                'buy_now_pay_later' => false,
                'cash_app_pay' => true,
            ],
        ],
    ]);
    $invoice = $invoiceResp['invoice'] ?? [];
    $squareInvoiceId = (string)($invoice['id'] ?? '');
    if ($squareInvoiceId === '') {
        throw new RuntimeException('Square did not return an invoice id.');
    }

    $status = bakery_square_normalize_status($invoice['status'] ?? 'DRAFT');
    $publishedAt = null;
    if (!$draftOnly) {
        $published = square_api_request('POST', '/v2/invoices/' . rawurlencode($squareInvoiceId) . '/publish', [
            'version' => (int)($invoice['version'] ?? 0),
            'idempotency_key' => 'os-publish-' . $orderId,
        ]);
        $invoice = $published['invoice'] ?? $invoice;
        $status = bakery_square_normalize_status($invoice['status'] ?? 'UNPAID');
        $publishedAt = date('Y-m-d H:i:s');
    }

    if ((string)$row['status'] !== 'invoiced') {
        bakery_billing_mark_invoiced($db, $orderId, $userId);
    }

    bakery_square_apply_invoice_payload($db, $orderId, $invoice, [
        'square_customer_id' => $squareCustomerId,
        'square_recipient_email' => $recipient['email'],
        'square_published_at' => $publishedAt,
        'user_id' => $userId,
    ]);

    if (function_exists('log_user_action')) {
        log_user_action($db, 'square_invoice_sent', 'daily_order', $orderId, json_encode([
            'square_invoice_id' => $squareInvoiceId,
            'square_status' => $status,
            'recipient' => $recipient['email'],
            'draft_only' => $draftOnly,
            'invoice_number' => $invoiceNumber,
        ]), $userId);
    }

    return [
        'ok' => true,
        'idempotent' => false,
        'daily_order_id' => $orderId,
        'invoice_number' => $invoiceNumber,
        'square_invoice_id' => $squareInvoiceId,
        'square_order_id' => $squareOrderId,
        'square_public_url' => (string)($invoice['public_url'] ?? ''),
        'square_status' => $status,
        'recipient' => $recipient['email'],
        'draft_only' => $draftOnly,
    ];
}

function bakery_square_refresh_invoice(PDO $db, $orderId): array
{
    $row = bakery_square_load_order_row($db, $orderId);
    $id = trim((string)($row['square_invoice_id'] ?? ''));
    if ($id === '') {
        throw new RuntimeException('This delivery has no Square invoice yet.');
    }
    $got = square_api_request('GET', '/v2/invoices/' . rawurlencode($id));
    $invoice = $got['invoice'] ?? [];
    bakery_square_apply_invoice_payload($db, $orderId, $invoice);
    return [
        'ok' => true,
        'square_invoice_id' => $id,
        'square_status' => bakery_square_normalize_status($invoice['status'] ?? ''),
        'square_public_url' => (string)($invoice['public_url'] ?? $row['square_public_url'] ?? ''),
    ];
}

/**
 * Payment truth is signature-checked or refused. Without a signature key the
 * endpoint must answer 503 and process nothing — never fall open.
 */
function bakery_square_webhook_configured(): bool
{
    return defined('SQUARE_WEBHOOK_SIGNATURE_KEY') && (string)SQUARE_WEBHOOK_SIGNATURE_KEY !== '';
}

function bakery_square_webhook_valid($body, $signatureHeader, $notificationUrl): bool
{
    $key = defined('SQUARE_WEBHOOK_SIGNATURE_KEY') ? (string)SQUARE_WEBHOOK_SIGNATURE_KEY : '';
    if ($key === '' || $signatureHeader === '' || $notificationUrl === '') {
        return false;
    }
    $payload = $notificationUrl . $body;
    $sig = (string)$signatureHeader;
    $sha256 = base64_encode(hash_hmac('sha256', $payload, $key, true));
    $sha1 = base64_encode(hash_hmac('sha1', $payload, $key, true));
    return hash_equals($sha256, $sig) || hash_equals($sha1, $sig);
}

function bakery_square_handle_webhook(PDO $db, array $payload): array
{
    bakery_square_ensure_schema($db);
    $eventId = (string)($payload['event_id'] ?? $payload['eventId'] ?? '');
    $type = (string)($payload['type'] ?? '');
    $invoice = $payload['data']['object']['invoice'] ?? $payload['data']['object'] ?? [];
    if (!isset($invoice['id']) && isset($payload['data']['id'])) {
        $got = square_api_request('GET', '/v2/invoices/' . rawurlencode((string)$payload['data']['id']));
        $invoice = $got['invoice'] ?? [];
    }
    $squareInvoiceId = (string)($invoice['id'] ?? '');

    if ($eventId !== '' && table_exists($db, 'square_webhook_events')) {
        try {
            $db->prepare(
                'INSERT INTO square_webhook_events (event_id, event_type, square_invoice_id)
                 VALUES (?, ?, ?)'
            )->execute([$eventId, $type, $squareInvoiceId !== '' ? $squareInvoiceId : null]);
        } catch (PDOException $e) {
            return ['ok' => true, 'duplicate' => true, 'event_id' => $eventId];
        }
    }

    if ($squareInvoiceId === '' || !column_exists($db, 'daily_orders', 'square_invoice_id')) {
        return ['ok' => true, 'ignored' => true];
    }

    $find = $db->prepare('SELECT id FROM daily_orders WHERE square_invoice_id = ? LIMIT 1');
    $find->execute([$squareInvoiceId]);
    $orderId = (int)$find->fetchColumn();
    if ($orderId <= 0) {
        return ['ok' => true, 'unmatched' => true, 'square_invoice_id' => $squareInvoiceId];
    }

    bakery_square_apply_invoice_payload($db, $orderId, $invoice);
    if (table_exists($db, 'square_webhook_events') && $eventId !== '') {
        $db->prepare('UPDATE square_webhook_events SET daily_order_id = ? WHERE event_id = ?')
            ->execute([$orderId, $eventId]);
    }

    return [
        'ok' => true,
        'daily_order_id' => $orderId,
        'square_invoice_id' => $squareInvoiceId,
        'square_status' => bakery_square_normalize_status($invoice['status'] ?? ''),
        'event_type' => $type,
    ];
}
