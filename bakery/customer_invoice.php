<?php
/**
 * Customer invoice detail — print/PDF-friendly, historical snapshot pricing.
 */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/customer_billing.php';

$customer = bakery_portal_customer($db);
$customerId = (int)$customer['id'];
$orderId = max(0, (int)($_GET['daily_order_id'] ?? 0));
$record = isset($_GET['record']) && (string)$_GET['record'] === '1';

if ($orderId <= 0) {
    http_response_code(400);
    echo 'Invoice not specified';
    exit;
}

$invoice = bakery_portal_billing_load_invoice($db, $customerId, $orderId);
if (!$invoice) {
    http_response_code(404);
    echo 'Invoice not found';
    exit;
}

if ($record) {
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

$customerRow = $invoice['customer'] ?: $customer;
$deliveryUrl = bakery_portal_delivery_url($invoice['order_date'], $orderId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Invoice <?php echo htmlspecialchars($invoice['invoice_number']); ?></title>
  <style>
    body { font-family: Georgia, 'Times New Roman', serif; max-width: 820px; margin: 0 auto; padding: 28px; color: #1a202c; line-height: 1.45; }
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
  <div class="toolbar">
    <button type="button" class="primary" onclick="window.print()">Print / Save PDF</button>
    <a href="customer_invoice.php?daily_order_id=<?php echo $orderId; ?>&amp;record=1" onclick="setTimeout(function(){window.print();},300);return false;">Download</a>
    <a href="customer_portal_billing.php">Back to billing</a>
  </div>

  <header class="header">
    <div>
      <?php echo bakery_sour_flour_logo_img('brand-logo'); ?>
      <p class="tagline">Artisan Breads &amp; Pastries</p>
    </div>
    <div class="inv-meta">
      <h1>INVOICE</h1>
      <div><?php echo htmlspecialchars($invoice['invoice_number']); ?></div>
      <div>Invoice date: <?php echo date('F j, Y', strtotime($invoice['invoice_date'])); ?></div>
      <div>Delivery date: <?php echo date('F j, Y', strtotime($invoice['order_date'])); ?></div>
    </div>
  </header>

  <section class="bill-to">
    <h2>Bill To</h2>
    <strong><?php echo htmlspecialchars($customerRow['name']); ?></strong><br>
    <?php if (!empty($customerRow['address'])): ?>
      <?php echo nl2br(htmlspecialchars($customerRow['address'])); ?><br>
    <?php endif; ?>
    <?php if (!empty($customerRow['email'])): ?>
      <?php echo htmlspecialchars($customerRow['email']); ?><br>
    <?php endif; ?>
    <?php if (!empty($customerRow['phone'])): ?>
      <?php echo htmlspecialchars($customerRow['phone']); ?>
    <?php endif; ?>
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
    <?php foreach ($invoice['items'] as $item): ?>
      <?php
        $qty = $item['delivered_quantity'] ?? $item['quantity'];
        if ((int)$qty <= 0 && (int)$item['quantity'] <= 0) {
            continue;
        }
      ?>
      <tr>
        <td><?php echo htmlspecialchars($item['product_name']); ?></td>
        <td class="num"><?php echo (int)$item['quantity']; ?></td>
        <td class="num"><?php echo $item['delivered_quantity'] !== null ? (int)$item['delivered_quantity'] : '—'; ?></td>
        <td class="num"><?php echo $item['has_price'] ? '$' . number_format($item['unit_price'], 2) : '—'; ?></td>
        <td class="num"><?php echo $item['has_price'] ? '$' . number_format($item['line_total'], 2) : '—'; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if (!empty($invoice['has_credits']) && (int)$invoice['credits_taken_back'] > 0): ?>
    <p class="adjustment">Credits taken back: <?php echo (int)$invoice['credits_taken_back']; ?></p>
  <?php endif; ?>

  <div class="summary">
    <div class="summary-row">
      <span><?php echo htmlspecialchars($invoice['customer_payment']['label']); ?></span>
      <span></span>
    </div>
    <div class="summary-row total">
      <span>Total</span>
      <span>$<?php echo number_format($invoice['invoice_total'], 2); ?></span>
    </div>
  </div>

  <p class="note">
    Line prices are from the delivery billing snapshot at the time of confirmation — not current catalog pricing.
    <?php echo htmlspecialchars($invoice['customer_payment']['detail']); ?>
  </p>

  <p class="delivery-link">
    <?php bakery_te('portal.billing_delivery_link'); ?>:
    <a href="<?php echo htmlspecialchars($deliveryUrl); ?>"><?php echo date('F j, Y', strtotime($invoice['order_date'])); ?></a>
  </p>
</body>
</html>
