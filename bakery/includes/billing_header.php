<?php
/**
 * Invoice header math for Billing Center.
 *
 * The stored header is daily_orders.delivery_order_total when a delivery
 * snapshot exists, otherwise daily_orders.total_amount. The line header is
 * the sum of booked daily_order_items.line_total. No-charge lines (088)
 * contribute $0. Live products.price is never read: open PR #58 reprices
 * only open future lines and leaves invoiced history booked.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_billing_money_same($left, $right): bool
{
    return abs(round((float)$left, 2) - round((float)$right, 2)) < 0.005;
}

/**
 * Booked chargeable amount for one line. No-charge is $0 even when a stale
 * line_total is still stored. Catalog prices on the row are ignored.
 */
function bakery_billing_line_chargeable_amount(array $line): float
{
    if ((int)($line['is_no_charge'] ?? 0) === 1) {
        return 0.0;
    }
    return round((float)($line['line_total'] ?? 0), 2);
}

/**
 * @param array<int, array<string, mixed>> $lines
 */
function bakery_billing_header_from_lines(array $lines): float
{
    $sum = 0.0;
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $sum += bakery_billing_line_chargeable_amount($line);
    }
    return round($sum, 2);
}

function bakery_billing_stored_header_amount(array $order): float
{
    if (isset($order['delivery_order_total']) && $order['delivery_order_total'] !== null && $order['delivery_order_total'] !== '') {
        return round((float)$order['delivery_order_total'], 2);
    }
    return round((float)($order['total_amount'] ?? 0), 2);
}

/**
 * @param array<int, array<string, mixed>> $lines
 * @return array{stored_header_amount:float,line_header_amount:float,header_mismatch:bool}
 */
function bakery_billing_header_compare(array $order, array $lines): array
{
    $stored = bakery_billing_stored_header_amount($order);
    $lineHeader = bakery_billing_header_from_lines($lines);
    return [
        'stored_header_amount' => $stored,
        'line_header_amount' => $lineHeader,
        'header_mismatch' => !bakery_billing_money_same($stored, $lineHeader),
    ];
}

function bakery_billing_header_mismatch_flag(array $order): string
{
    if (empty($order['header_mismatch'])) {
        return '';
    }
    $lines = number_format((float)($order['line_header_amount'] ?? 0), 2);
    $header = number_format((float)($order['stored_header_amount'] ?? 0), 2);
    if (function_exists('bakery_t')) {
        return bakery_t('billing.header_mismatch_flag', [
            'lines' => $lines,
            'header' => $header,
        ]);
    }
    return 'Lines $' . $lines . ', header $' . $header;
}

/**
 * SQL expression for one daily_order_items row. No-charge contributes 0.
 */
function bakery_billing_chargeable_line_sum_sql(PDO $db): string
{
    $ready = function_exists('column_exists') && column_exists($db, 'daily_order_items', 'is_no_charge');
    if (!$ready) {
        return 'line_total';
    }
    return 'CASE WHEN COALESCE(is_no_charge, 0) = 1 THEN 0 ELSE line_total END';
}

/**
 * Confirmed, invoiced, Square-invoiced, or already-sent orders keep the
 * header that was booked. Open orders may still recompute from lines.
 */
function bakery_billing_header_is_historical(PDO $db, int $orderId): bool
{
    if ($orderId <= 0) {
        return false;
    }
    $squareSql = function_exists('column_exists') && column_exists($db, 'daily_orders', 'square_invoice_id')
        ? 'square_invoice_id'
        : 'NULL';
    $confirmedSql = function_exists('column_exists') && column_exists($db, 'daily_orders', 'delivery_confirmed_at')
        ? 'delivery_confirmed_at'
        : 'NULL';
    $stmt = $db->prepare(
        "SELECT status, {$confirmedSql} AS delivery_confirmed_at, {$squareSql} AS square_invoice_id
         FROM daily_orders WHERE id = ?"
    );
    $stmt->execute([$orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    if ((string)($row['status'] ?? '') === 'invoiced') {
        return true;
    }
    if (!empty($row['delivery_confirmed_at'])) {
        return true;
    }
    if (trim((string)($row['square_invoice_id'] ?? '')) !== '') {
        return true;
    }
    if (function_exists('table_exists') && table_exists($db, 'billing_invoice_sends')) {
        $sends = $db->prepare('SELECT COUNT(*) FROM billing_invoice_sends WHERE daily_order_id = ?');
        $sends->execute([$orderId]);
        if ((int)$sends->fetchColumn() > 0) {
            return true;
        }
    }
    return false;
}
