<?php
/**
 * Customer billing CSV exports — scoped to authenticated portal customer.
 */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/customer_billing.php';

$customer = bakery_portal_customer($db);
$customerId = (int)$customer['id'];

$startDate = trim((string)($_GET['start_date'] ?? date('Y-m-01')));
$endDate = trim((string)($_GET['end_date'] ?? date('Y-m-d')));
$format = trim((string)($_GET['format'] ?? 'summary'));

if (!in_array($format, ['summary', 'lines'], true)) {
    http_response_code(400);
    echo 'Invalid format';
    exit;
}

if ($format === 'lines') {
    $headers = ['invoice_id', 'delivery_date', 'product', 'quantity', 'unit_price', 'line_amount'];
    $rows = bakery_portal_billing_line_export_rows($db, $customerId, $startDate, $endDate);
    $filename = 'sourflour-invoice-lines-' . $startDate . '_to_' . $endDate . '.csv';
    $eventDetail = 'line-item CSV';
} else {
    $headers = ['invoice_id', 'invoice_date', 'delivery_date', 'amount'];
    $rows = bakery_portal_billing_summary_export_rows($db, $customerId, $startDate, $endDate);
    $filename = 'sourflour-invoices-' . $startDate . '_to_' . $endDate . '.csv';
    $eventDetail = 'invoice summary CSV';
}

$recordKey = 'portal_csv_' . $customerId . '_' . $format . '_' . $startDate . '_' . $endDate;
if (empty($_SESSION[$recordKey])) {
    bakery_portal_billing_log_event($db, $customerId, 'portal_billing_export_downloaded', 'Customer downloaded ' . $eventDetail . ' for ' . $startDate . ' – ' . $endDate, [
        'period_start' => $startDate,
        'period_end' => $endDate,
        'format' => $format,
        'row_count' => count($rows),
    ]);
    $_SESSION[$recordKey] = time();
}

$content = bakery_portal_billing_csv_content($headers, $rows);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo $content;
