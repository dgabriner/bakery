<?php
/**
 * Customer invoice detail — print/PDF-friendly, historical snapshot pricing.
 * Portal customers and Billing Center staff share this document.
 */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/customer_billing.php';
require_once __DIR__ . '/includes/invoice_document.php';

$orderId = max(0, (int)($_GET['daily_order_id'] ?? 0));
$record = isset($_GET['record']) && (string)$_GET['record'] === '1';

if ($orderId <= 0) {
    http_response_code(400);
    echo 'Invoice not specified';
    exit;
}

$staffUser = function_exists('bakery_current_user') ? bakery_current_user() : null;
$isStaff = $staffUser && function_exists('bakery_user_has_role')
    && bakery_user_has_role(['administrator', 'manager']);
$portalCustomer = function_exists('bakery_portal_customer') ? bakery_portal_customer($db) : null;

if ($portalCustomer) {
    $invoice = bakery_portal_billing_load_invoice($db, (int)$portalCustomer['id'], $orderId);
    $mode = 'portal';
} elseif ($isStaff) {
    try {
        $invoice = bakery_billing_load_canonical_invoice($db, $orderId);
    } catch (Throwable $e) {
        $invoice = null;
    }
    $mode = 'staff';
} else {
    $invoice = null;
    $mode = 'portal';
}

if (!$invoice) {
    http_response_code(404);
    echo 'Invoice not found';
    exit;
}

if ($record && $mode === 'portal' && $portalCustomer) {
    $customerId = (int)$portalCustomer['id'];
    $recordKey = 'portal_inv_dl_' . $customerId . '_' . $orderId;
    if (empty($_SESSION[$recordKey])) {
        bakery_portal_billing_log_event($db, $customerId, 'portal_invoice_downloaded', 'Customer downloaded invoice ' . $invoice['invoice_number'], [
            'daily_order_id' => $orderId,
            'invoice_ref' => $invoice['invoice_number'],
            'period_start' => $invoice['order_date'],
            'period_end' => $invoice['order_date'],
        ]);
        $_SESSION[$recordKey] = time();
    }
}

echo bakery_billing_invoice_document_html($invoice, ['mode' => $mode]);
