<?php
/**
 * Customer portal billing — self-service invoices, statements, and exports.
 *
 * Reuses canonical billing helpers from includes/billing.php.
 * All queries are scoped to the authenticated portal customer.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/billing.php';

/**
 * Customer-safe payment label — never implies external AR state we do not track.
 *
 * @return array{key:string,label:string,detail:string}
 */
function bakery_portal_billing_payment_label(array $order, array $customer = []) {
    $status = bakery_billing_payment_status($order, $customer);
    switch ($status['key']) {
        case 'cod_collected':
            return [
                'key' => 'cod_collected',
                'label' => 'Collected at delivery',
                'detail' => 'Cash was recorded when this delivery was confirmed.',
            ];
        case 'cod_expected':
            return [
                'key' => 'cod_expected',
                'label' => 'Delivered (COD)',
                'detail' => 'Payment is collected at delivery. No external payment status is tracked here.',
            ];
        case 'billing_complete':
        case 'payment_unknown':
            return [
                'key' => 'invoice_issued',
                'label' => 'Invoice issued',
                'detail' => 'Payment status is managed externally.',
            ];
        default:
            return [
                'key' => 'delivered',
                'label' => 'Delivered',
                'detail' => '',
            ];
    }
}

/**
 * Whether an invoice row is safe to show on the customer portal.
 */
function bakery_portal_billing_invoice_visible(array $invoice) {
    if (empty($invoice['delivery_confirmed_at'])) {
        return false;
    }
    if (!empty($invoice['pricing_issue'])) {
        return false;
    }
    $amount = !empty($invoice['amount_is_billable'])
        ? (float)$invoice['billable_amount']
        : (float)($invoice['display_amount'] ?? 0);
    return $amount > 0;
}

/**
 * Verify that a daily order belongs to the portal customer and is customer-visible.
 */
function bakery_portal_billing_verify_order(PDO $db, $customerId, $orderId) {
    $customerId = (int)$customerId;
    $orderId = (int)$orderId;
    if ($customerId <= 0 || $orderId <= 0) {
        return false;
    }
    $stmt = $db->prepare(
        'SELECT do.id FROM daily_orders do
         WHERE do.id = ? AND do.customer_id = ? AND do.delivery_confirmed_at IS NOT NULL
         LIMIT 1'
    );
    $stmt->execute([$orderId, $customerId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Load one customer-visible invoice with line items and historical snapshot pricing.
 *
 * @return array|null
 */
function bakery_portal_billing_load_invoice(PDO $db, $customerId, $orderId) {
    if (!bakery_portal_billing_verify_order($db, $customerId, $orderId)) {
        return null;
    }

    $orders = bakery_billing_query_orders($db, [
        'start_date' => '2000-01-01',
        'end_date' => '2099-12-31',
        'customer_id' => (int)$customerId,
        'status' => 'all',
    ]);
    $order = null;
    foreach ($orders as $row) {
        if ((int)$row['id'] === (int)$orderId) {
            $order = $row;
            break;
        }
    }
    if (!$order) {
        return null;
    }

    $itemsByOrder = bakery_billing_load_items($db, [(int)$orderId]);
    $enriched = bakery_billing_enrich_orders([$order], $itemsByOrder);
    $invoice = $enriched[0] ?? null;
    if (!$invoice || !bakery_portal_billing_invoice_visible($invoice)) {
        return null;
    }

    $customer = bakery_customer_record_load_customer($db, (int)$customerId);
    $invoice['customer'] = $customer;
    $invoice['customer_payment'] = bakery_portal_billing_payment_label($invoice, $customer ?: []);
    $invoice['invoice_total'] = $invoice['amount_is_billable']
        ? round((float)$invoice['billable_amount'], 2)
        : round((float)$invoice['display_amount'], 2);

    return $invoice;
}

/**
 * Customer billing account summary for a date range (portal-safe filtering).
 *
 * @return array<string, mixed>
 */
function bakery_portal_billing_account(PDO $db, $customerId, $startDate, $endDate, $searchQ = '') {
    $account = bakery_billing_customer_account($db, (int)$customerId, $startDate, $endDate);
    $customer = $account['customer'];

    $visible = array_values(array_filter($account['invoices'], 'bakery_portal_billing_invoice_visible'));
    if ($searchQ !== '') {
        $visible = bakery_billing_filter_search($visible, $searchQ);
    }

    foreach ($visible as &$inv) {
        $inv['customer_payment'] = bakery_portal_billing_payment_label($inv, $customer);
        $inv['display_total'] = $inv['amount_is_billable']
            ? round((float)$inv['billable_amount'], 2)
            : round((float)$inv['display_amount'], 2);
    }
    unset($inv);

    $periodTotal = 0.0;
    foreach ($visible as $inv) {
        $periodTotal += (float)$inv['display_total'];
    }

    return [
        'customer' => $customer,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'invoices' => $visible,
        'period_total' => round($periodTotal, 2),
        'invoice_count' => count($visible),
        'statements' => $account['statements'],
        'payment_note' => 'Amounts reflect confirmed delivery billing snapshots. '
            . 'Payment status in accounting systems is not tracked in this portal.',
    ];
}

/**
 * Resolve preset date ranges for customer billing filters.
 *
 * @return array{start_date:string,end_date:string,label:string}
 */
function bakery_portal_billing_date_preset($preset) {
    $today = date('Y-m-d');
    switch ($preset) {
        case 'prev_month':
            $start = date('Y-m-01', strtotime('first day of previous month'));
            $end = date('Y-m-t', strtotime('last day of previous month'));
            return ['start_date' => $start, 'end_date' => $end, 'label' => 'Previous month'];
        case 'current_month':
        default:
            return [
                'start_date' => date('Y-m-01'),
                'end_date' => $today,
                'label' => 'Current month',
            ];
    }
}

/**
 * Customer-safe invoice summary CSV rows.
 *
 * @return array<int, array<string, scalar|null>>
 */
function bakery_portal_billing_summary_export_rows(PDO $db, $customerId, $startDate, $endDate) {
    $account = bakery_portal_billing_account($db, $customerId, $startDate, $endDate);
    $rows = [];
    foreach ($account['invoices'] as $inv) {
        $rows[] = [
            'invoice_id' => $inv['invoice_number'],
            'invoice_date' => $inv['invoice_date'],
            'delivery_date' => $inv['order_date'],
            'amount' => $inv['display_total'],
        ];
    }
    return $rows;
}

/**
 * Customer-safe line-item CSV rows (historical snapshot pricing only).
 *
 * @return array<int, array<string, scalar|null>>
 */
function bakery_portal_billing_line_export_rows(PDO $db, $customerId, $startDate, $endDate) {
    $account = bakery_portal_billing_account($db, $customerId, $startDate, $endDate);
    $rows = [];
    foreach ($account['invoices'] as $inv) {
        foreach ($inv['items'] as $line) {
            $qty = $line['delivered_quantity'] ?? $line['quantity'];
            if ((int)$qty <= 0) {
                continue;
            }
            $unitPrice = (float)$line['unit_price'];
            $lineTotal = $line['delivered_quantity'] !== null
                ? round($unitPrice * (int)$line['delivered_quantity'], 2)
                : (float)$line['line_total'];
            if ($unitPrice <= 0 && $lineTotal <= 0) {
                continue;
            }
            $rows[] = [
                'invoice_id' => $inv['invoice_number'],
                'delivery_date' => $inv['order_date'],
                'product' => $line['product_name'],
                'quantity' => (int)$qty,
                'unit_price' => $unitPrice,
                'line_amount' => $lineTotal,
            ];
        }
    }
    return $rows;
}

/**
 * Encode rows as CSV string.
 *
 * @param array<int, string> $headers
 * @param array<int, array<string, scalar|null>> $rows
 */
function bakery_portal_billing_csv_content(array $headers, array $rows) {
    $lines = [implode(',', $headers)];
    foreach ($rows as $row) {
        $fields = [];
        foreach ($headers as $h) {
            $val = $row[$h] ?? '';
            if ($val === null) {
                $fields[] = '';
                continue;
            }
            $str = (string)$val;
            if (strpos($str, ',') !== false || strpos($str, '"') !== false || strpos($str, "\n") !== false) {
                $str = '"' . str_replace('"', '""', $str) . '"';
            }
            $fields[] = $str;
        }
        $lines[] = implode(',', $fields);
    }
    return implode("\r\n", $lines) . "\r\n";
}

/**
 * Stable delivery detail URL for Agent 4 integration.
 */
function bakery_portal_delivery_url($orderDate, $dailyOrderId = null) {
    if ($dailyOrderId !== null && (int)$dailyOrderId > 0) {
        return 'customer_portal_delivery.php?id=' . (int)$dailyOrderId;
    }
    return 'customer_portal_delivery.php?date=' . urlencode((string)$orderDate);
}

/**
 * Log meaningful customer billing self-service events (not page views).
 */
function bakery_portal_billing_log_event(PDO $db, $customerId, $eventType, $summary, array $meta = []) {
    if (!function_exists('bakery_record_operational_event')) {
        return;
    }
    bakery_record_operational_event($db, $eventType, $summary, [
        'customer_id' => (int)$customerId,
        'daily_order_id' => isset($meta['daily_order_id']) ? (int)$meta['daily_order_id'] : null,
        'invoice_ref' => $meta['invoice_ref'] ?? null,
        'actor_role' => 'customer_portal',
        'metadata' => $meta,
    ]);
}
