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

require_once __DIR__ . '/payment_terms.php';

function bakery_billing_money_same($left, $right): bool
{
    return abs(round((float)$left, 2) - round((float)$right, 2)) < 0.005;
}

/**
 * Booked chargeable amount for one line. No-charge is $0 even when a stale
 * line_total is still stored. Catalog prices on the row are ignored.
 */
function bakery_billing_line_chargeable_amount(array $line, ?array $order = null): float
{
    if ((int)($line['is_no_charge'] ?? 0) === 1) {
        return 0.0;
    }
    if ((int)($line['is_store_credit'] ?? 0) === 1) {
        return round((float)($line['line_total'] ?? 0), 2);
    }
    $confirmed = $order !== null && bakery_billing_order_is_confirmed($order);
    $deliveredRaw = $line['delivered_quantity'] ?? null;
    $hasDelivered = $deliveredRaw !== null && $deliveredRaw !== '';
    if ($confirmed && $hasDelivered) {
        return round((float)($line['unit_price'] ?? 0) * (int)$deliveredRaw, 2);
    }
    return round((float)($line['line_total'] ?? 0), 2);
}

function bakery_billing_order_is_confirmed(array $order): bool
{
    if (!empty($order['delivery_confirmed_at'])) {
        return true;
    }
    return in_array((string)($order['status'] ?? ''), ['delivered', 'invoiced'], true);
}

/**
 * @param array<int, array<string, mixed>> $lines
 */
/**
 * @param array<int, array<string, mixed>> $lines
 */
function bakery_billing_header_from_lines(array $lines, ?array $order = null): float
{
    $sum = 0.0;
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $sum += bakery_billing_line_chargeable_amount($line, $order);
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
    $lineHeader = bakery_billing_header_from_lines($lines, $order);
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

/**
 * Why a stored header and its lines differ. Does not write the invoice.
 *
 * @param array<int, array<string, mixed>> $lines
 * @return list<string>
 */
function bakery_billing_header_mismatch_reasons(array $order, array $lines): array
{
    $reasons = [];
    $credits = (int)($order['credits_taken_back'] ?? 0);
    $hasCreditLine = false;
    $builtBefore = false;
    $productMissing = false;
    $confirmed = bakery_billing_order_is_confirmed($order);
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        if ((int)($line['is_store_credit'] ?? 0) === 1) {
            $hasCreditLine = true;
        }
        if (!empty($line['product_missing']) || trim((string)($line['product_name'] ?? '')) === '') {
            $productMissing = true;
        }
        if (!$confirmed || (int)($line['is_no_charge'] ?? 0) === 1 || (int)($line['is_store_credit'] ?? 0) === 1) {
            continue;
        }
        $deliveredRaw = $line['delivered_quantity'] ?? null;
        $hasDelivered = $deliveredRaw !== null && $deliveredRaw !== '';
        $ordered = (int)($line['quantity'] ?? 0);
        $booked = round((float)($line['line_total'] ?? 0), 2);
        $orderedAmount = round((float)($line['unit_price'] ?? 0) * $ordered, 2);
        if (!$hasDelivered || ((int)$deliveredRaw !== $ordered && bakery_billing_money_same($booked, $orderedAmount))) {
            $builtBefore = true;
        }
    }
    if ($credits > 0 && !$hasCreditLine) {
        $reasons[] = 'credit_without_line';
    }
    if ($builtBefore) {
        $reasons[] = 'built_before_delivery';
    }
    if ($productMissing) {
        $reasons[] = 'product_missing';
    }
    if ($reasons === []) {
        $reasons[] = 'unexplained';
    }
    return $reasons;
}

/**
 * Cash the driver should collect for one COD stop.
 * A historical credit with no line keeps the stored header.
 * A store-credit line uses the line sum. Invoice, check, and unpaid Square stay at zero.
 *
 * @param array<int, array<string, mixed>> $lines
 */
function bakery_billing_cod_expected_amount(array $order, array $lines): float
{
    $terms = $order['payment_terms'] ?? (($order['payment_collection'] ?? 'cod') === 'cod' ? 'cod' : 'invoice');
    if (!bakery_payment_terms_is_cod($terms)) {
        return 0.0;
    }
    $override = trim((string)($order['cod_override_reason'] ?? $order['cod_square_override_reason'] ?? ''));
    if (!empty($order['square_prepaid']) && $override === '') {
        return 0.0;
    }
    $hasCreditLine = false;
    foreach ($lines as $line) {
        if (is_array($line) && (int)($line['is_store_credit'] ?? 0) === 1) {
            $hasCreditLine = true;
            break;
        }
    }
    if ($hasCreditLine) {
        return bakery_billing_header_from_lines($lines, $order);
    }
    return bakery_billing_stored_header_amount($order);
}

function bakery_billing_store_credit_product_id(PDO $db): int
{
    $stmt = $db->query("SELECT id FROM products WHERE name = 'Store credit' LIMIT 1");
    $id = $stmt ? (int)$stmt->fetchColumn() : 0;
    if ($id > 0) {
        return $id;
    }
    $db->prepare(
        "INSERT INTO products (name, price, description) VALUES ('Store credit', 0.00, ?)"
    )->execute(['Negative line for credits taken back at the door']);
    return (int)$db->lastInsertId();
}

/**
 * Write the credit line for the stop being confirmed, then set that stop's header
 * from its lines. Older orders are not passed here.
 */
function bakery_billing_apply_confirm_credit_and_header(PDO $db, int $orderId, int $credits, float $pricePerPiece): float
{
    if ($orderId <= 0 || !function_exists('column_exists') || !column_exists($db, 'daily_order_items', 'is_store_credit')) {
        return round(0, 2);
    }
    $db->prepare('DELETE FROM daily_order_items WHERE daily_order_id = ? AND is_store_credit = 1')
        ->execute([$orderId]);
    if ($credits > 0 && $pricePerPiece > 0) {
        $productId = bakery_billing_store_credit_product_id($db);
        $lineTotal = round(-1 * $credits * $pricePerPiece, 2);
        $db->prepare(
            'INSERT INTO daily_order_items
                (daily_order_id, product_id, quantity, delivered_quantity, unit_price, line_total, is_store_credit)
             VALUES (?, ?, ?, NULL, ?, ?, 1)'
        )->execute([$orderId, $productId, $credits, round($pricePerPiece, 2), $lineTotal]);
    }
    $stmt = $db->prepare(
        'SELECT quantity, delivered_quantity, unit_price, line_total, is_no_charge, is_store_credit
         FROM daily_order_items WHERE daily_order_id = ?'
    );
    $stmt->execute([$orderId]);
    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $sum = bakery_billing_header_from_lines($lines, [
        'status' => 'delivered',
        'delivery_confirmed_at' => date('Y-m-d H:i:s'),
    ]);
    $db->prepare('UPDATE daily_orders SET total_amount = ?, delivery_order_total = ? WHERE id = ?')
        ->execute([$sum, $sum, $orderId]);
    return $sum;
}
