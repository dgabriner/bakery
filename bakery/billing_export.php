<?php
/**
 * Deterministic accounting CSV export for QuickBooks-ready handoff.
 */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/billing.php';

bakery_require_role(['administrator', 'manager']);

$startDate = trim((string)($_GET['start_date'] ?? date('Y-m-01')));
$endDate = trim((string)($_GET['end_date'] ?? date('Y-m-d')));
$customerId = max(0, (int)($_GET['customer_id'] ?? 0));
$confirmedOnly = !isset($_GET['include_unconfirmed']) || (string)$_GET['include_unconfirmed'] !== '1';
$recordExport = !isset($_GET['record']) || (string)$_GET['record'] !== '0';

$filters = [
    'start_date' => $startDate,
    'end_date' => $endDate,
    'customer_id' => $customerId,
    'status' => 'all',
    'confirmed_only' => $confirmedOnly,
    'sort' => 'date_asc',
];

$rows = bakery_billing_export_rows($db, $filters);

$headers = [
    'invoice_id',
    'daily_order_id',
    'customer_id',
    'customer_name',
    'invoice_date',
    'delivery_date',
    'product_id',
    'product_name',
    'quantity_ordered',
    'quantity_delivered',
    'unit_price',
    'line_total',
    'invoice_total',
    'credits_taken_back',
    'pricing_label',
    'status',
    'memo',
];

$csvLines = [];
$csvLines[] = implode(',', $headers);

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
    $csvLines[] = implode(',', $fields);
}

$content = implode("\r\n", $csvLines) . "\r\n";
$contentHash = hash('sha256', $content);
$exportKey = 'EXP-' . date('Ymd-His') . '-' . substr($contentHash, 0, 8);

$user = bakery_current_user();
$userId = isset($user['id']) ? (int)$user['id'] : null;

if ($recordExport && bakery_billing_tables_ready($db) && $rows) {
    $orderIds = array_values(array_unique(array_map(static function ($r) {
        return (int)$r['daily_order_id'];
    }, $rows)));
    try {
        bakery_billing_record_export($db, [
            'export_key' => $exportKey,
            'period_start' => $startDate,
            'period_end' => $endDate,
            'row_count' => count($rows),
            'invoice_count' => count($orderIds),
            'content_hash' => $contentHash,
            'notes' => 'CSV accounting export',
        ], $orderIds, $userId);
    } catch (Throwable $e) {
        error_log('billing_export record: ' . $e->getMessage());
    }
}

$filename = 'sourflour-accounting-' . $startDate . '_to_' . $endDate . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo $content;
