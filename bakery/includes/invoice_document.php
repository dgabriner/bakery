<?php
/**
 * Canonical per-delivery invoice HTML — the same document the portal shows.
 * Amounts come from the delivery snapshot, never live products.price.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/brand.php';

/**
 * Render the portal invoice document.
 *
 * @param array<string, mixed> $invoice From bakery_billing_load_canonical_invoice / portal loader
 * @param array{mode?:string} $options portal|staff|email
 */
function bakery_billing_invoice_document_html(array $invoice, array $options = []) {
    $mode = (string)($options['mode'] ?? 'portal');
    if (!in_array($mode, ['portal', 'staff', 'email'], true)) {
        $mode = 'portal';
    }

    $orderId = (int)($invoice['id'] ?? 0);
    $invoiceNumber = (string)($invoice['invoice_number'] ?? '');
    $customerRow = $invoice['customer'] ?? [];
    $items = $invoice['items'] ?? [];
    $invoiceTotal = isset($invoice['invoice_total'])
        ? (float)$invoice['invoice_total']
        : (float)($invoice['billable_amount'] ?? $invoice['display_amount'] ?? 0);
    $payment = $invoice['customer_payment'] ?? [
        'label' => '',
        'detail' => '',
    ];
    $orderDate = (string)($invoice['order_date'] ?? '');
    $invoiceDate = (string)($invoice['invoice_date'] ?? $orderDate);
    $deliveryUrl = function_exists('bakery_portal_delivery_url')
        ? bakery_portal_delivery_url($orderDate, $orderId)
        : ('customer_portal_delivery.php?id=' . $orderId);

    $esc = static function ($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };

    $toolbar = '';
    if ($mode === 'portal') {
        $toolbar = '<div class="toolbar">'
            . '<button type="button" class="primary" onclick="window.print()">Print / Save PDF</button>'
            . '<a href="customer_invoice.php?daily_order_id=' . $orderId . '&amp;record=1" onclick="setTimeout(function(){window.print();},300);return false;">Download</a>'
            . '<a href="customer_portal_billing.php">Back to billing</a>'
            . '</div>';
    } elseif ($mode === 'staff') {
        $toolbar = '<div class="toolbar">'
            . '<button type="button" class="primary" onclick="window.print()">Print / Save PDF</button>'
            . '<a href="billing_center.php?panel=invoices&amp;invoice_id=' . $orderId . '">'
            . $esc(function_exists('bakery_t') ? bakery_t('billing.back_to_billing') : 'Back to Billing Center')
            . '</a>'
            . '</div>';
    }

    $itemRows = '';
    foreach ($items as $item) {
        $qty = $item['delivered_quantity'] ?? $item['quantity'];
        if ((int)$qty <= 0 && (int)$item['quantity'] <= 0) {
            continue;
        }
        $deliveredCell = $item['delivered_quantity'] !== null ? (string)(int)$item['delivered_quantity'] : '—';
        $priceCell = !empty($item['has_price']) ? '$' . number_format((float)$item['unit_price'], 2) : '—';
        $totalCell = !empty($item['has_price']) ? '$' . number_format((float)$item['line_total'], 2) : '—';
        $itemRows .= '<tr>'
            . '<td>' . $esc($item['product_name'] ?? '') . '</td>'
            . '<td class="num">' . (int)$item['quantity'] . '</td>'
            . '<td class="num">' . $esc($deliveredCell) . '</td>'
            . '<td class="num">' . $esc($priceCell) . '</td>'
            . '<td class="num">' . $esc($totalCell) . '</td>'
            . '</tr>';
    }

    $creditsHtml = '';
    if (!empty($invoice['has_credits']) && (int)($invoice['credits_taken_back'] ?? 0) > 0) {
        $creditsHtml = '<p class="adjustment">Credits taken back: ' . (int)$invoice['credits_taken_back'] . '</p>';
    }

    $snapshotNote = 'Line prices are from the delivery billing snapshot at the time of confirmation — not current catalog pricing.';
    if (function_exists('bakery_t')) {
        $snapshotNote = bakery_t('billing.snapshot_note');
    }

    $deliveryLinkLabel = function_exists('bakery_t')
        ? bakery_t('portal.billing_delivery_link')
        : 'Delivery';

    $billAddress = '';
    if (!empty($customerRow['address'])) {
        $billAddress = nl2br($esc($customerRow['address'])) . '<br>';
    }
    $billEmail = '';
    if (!empty($customerRow['email'])) {
        $billEmail = $esc($customerRow['email']) . '<br>';
    }
    $billPhone = '';
    if (!empty($customerRow['phone'])) {
        $billPhone = $esc($customerRow['phone']);
    }

    $logo = function_exists('bakery_sour_flour_logo_img')
        ? bakery_sour_flour_logo_img('brand-logo')
        : '';

    return '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Invoice ' . $esc($invoiceNumber) . '</title>
  <style>
    body { font-family: Georgia, \'Times New Roman\', serif; max-width: 820px; margin: 0 auto; padding: 28px; color: #1a202c; line-height: 1.45; }
    .toolbar { position: fixed; top: 16px; right: 16px; display: flex; flex-wrap: wrap; gap: 8px; font-family: system-ui, sans-serif; z-index: 10; }
    .toolbar button, .toolbar a { padding: 8px 14px; border: 1px solid #cbd5e0; border-radius: 8px; background: #fff; text-decoration: none; color: #2d3748; font-size: 14px; cursor: pointer; }
    .toolbar .primary { background: #0f766e; color: #fff; border-color: #0f766e; }
    .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #0f766e; padding-bottom: 20px; margin-bottom: 24px; gap: 16px; }
    .brand-logo { display: block; height: auto; margin: 0 0 8px; max-width: 220px; mix-blend-mode: multiply; width: min(52vw, 220px); }
    .tagline { color: #718096; font-style: italic; margin: 4px 0 0; font-size: 14px; }
    .inv-meta { text-align: right; font-size: 14px; }
    .inv-meta h1 { margin: 0 0 8px; font-size: 22px; letter-spacing: 0.06em; }
    .bill-to { margin-bottom: 24px; }
    .bill-to h2 { font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: #718096; margin: 0 0 6px; }
    table { width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 14px; }
    th, td { border-bottom: 1px solid #e2e8f0; padding: 10px 8px; text-align: left; }
    th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #718096; border-bottom-width: 2px; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .summary { margin-top: 16px; padding-top: 12px; border-top: 2px solid #0f766e; }
    .summary-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 15px; }
    .summary-row.total { font-size: 18px; font-weight: bold; }
    .note { margin-top: 28px; padding-top: 16px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #718096; font-family: system-ui, sans-serif; }
    .delivery-link { margin-top: 12px; font-family: system-ui, sans-serif; font-size: 13px; }
    .delivery-link a { color: #0f766e; }
    .adjustment { color: #92400e; font-size: 13px; margin-top: 8px; }
    @media print { .toolbar { display: none; } body { padding: 0; } }
  </style>
</head>
<body>
  ' . $toolbar . '

  <header class="header">
    <div>
      ' . $logo . '
      <p class="tagline">Artisan Breads &amp; Pastries</p>
    </div>
    <div class="inv-meta">
      <h1>INVOICE</h1>
      <div>' . $esc($invoiceNumber) . '</div>
      <div>Invoice date: ' . $esc(date('F j, Y', strtotime($invoiceDate))) . '</div>
      <div>Delivery date: ' . $esc(date('F j, Y', strtotime($orderDate))) . '</div>
    </div>
  </header>

  <section class="bill-to">
    <h2>Bill To</h2>
    <strong>' . $esc($customerRow['name'] ?? '') . '</strong><br>
    ' . $billAddress . $billEmail . $billPhone . '
  </section>

  <table>
    <thead>
      <tr>
        <th>Item</th>
        <th class="num">Ordered</th>
        <th class="num">Delivered</th>
        <th class="num">Unit price</th>
        <th class="num">Line amount</th>
      </tr>
    </thead>
    <tbody>
    ' . $itemRows . '
    </tbody>
  </table>

  ' . $creditsHtml . '

  <div class="summary">
    <div class="summary-row">
      <span>' . $esc($payment['label'] ?? '') . '</span>
      <span></span>
    </div>
    <div class="summary-row total">
      <span>Total</span>
      <span>$' . number_format($invoiceTotal, 2) . '</span>
    </div>
  </div>

  <p class="note">
    ' . $esc($snapshotNote) . '
    ' . $esc($payment['detail'] ?? '') . '
  </p>

  <p class="delivery-link">
    ' . $esc($deliveryLinkLabel) . ':
    <a href="' . $esc($deliveryUrl) . '">' . $esc(date('F j, Y', strtotime($orderDate))) . '</a>
  </p>
</body>
</html>';
}
