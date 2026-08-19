<?php
/**
 * Customer portal statement — print/PDF-friendly, scoped to authenticated customer.
 */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/customer_billing.php';

$customer = bakery_portal_customer($db);
$customerId = (int)$customer['id'];

$startDate = trim((string)($_GET['start_date'] ?? date('Y-m-01')));
$endDate = trim((string)($_GET['end_date'] ?? date('Y-m-d')));
$statementDate = trim((string)($_GET['statement_date'] ?? date('Y-m-d')));
$record = isset($_GET['record']) && (string)$_GET['record'] === '1';

try {
    $statement = bakery_billing_statement_data($db, $customerId, $startDate, $endDate, $statementDate);
} catch (Throwable $e) {
    http_response_code(404);
    echo htmlspecialchars($e->getMessage());
    exit;
}

// Apply customer-safe payment labels on statement lines.
foreach ($statement['invoices'] as &$line) {
    $line['customer_payment'] = bakery_portal_billing_payment_label([
        'status' => $line['status'],
        'delivery_confirmed_at' => $line['invoice_date'],
        'amount_collected' => null,
    ], $statement['customer']);
}
unset($line);

if ($record) {
    $recordKey = 'portal_stmt_' . $customerId . '_' . $startDate . '_' . $endDate;
    if (empty($_SESSION[$recordKey])) {
        if (bakery_billing_tables_ready($db)) {
            bakery_billing_record_statement($db, [
                'customer_id' => $customerId,
                'period_start' => $startDate,
                'period_end' => $endDate,
                'statement_date' => $statementDate,
                'invoice_count' => $statement['invoice_count'],
                'total_amount' => $statement['total_amount'],
                'sent_at' => null,
                'sent_by_user_id' => null,
                'sent_to_email' => null,
            ], null);
        }
        bakery_portal_billing_log_event($db, $customerId, 'portal_statement_generated', 'Customer generated statement for ' . $startDate . ' – ' . $endDate, [
            'period_start' => $startDate,
            'period_end' => $endDate,
            'invoice_count' => $statement['invoice_count'],
            'total_amount' => $statement['total_amount'],
        ]);
        $_SESSION[$recordKey] = time();
    }
}

$customerRow = $statement['customer'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Statement — <?php echo htmlspecialchars($customerRow['name']); ?></title>
  <style>
    body { font-family: Georgia, 'Times New Roman', serif; max-width: 820px; margin: 0 auto; padding: 28px; color: #1a202c; line-height: 1.45; }
    .toolbar { position: fixed; top: 16px; right: 16px; display: flex; gap: 8px; font-family: system-ui, sans-serif; }
    .toolbar button, .toolbar a { padding: 8px 14px; border: 1px solid #cbd5e0; border-radius: 8px; background: #fff; text-decoration: none; color: #2d3748; font-size: 14px; cursor: pointer; }
    .toolbar .primary { background: #0f766e; color: #fff; border-color: #0f766e; }
    .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #0f766e; padding-bottom: 20px; margin-bottom: 24px; }
    .brand-logo { display: block; height: auto; margin: 0 0 8px; max-width: 220px; mix-blend-mode: multiply; width: min(52vw, 220px); }
    .tagline { color: #718096; font-style: italic; margin: 4px 0 0; font-size: 14px; }
    .stmt-meta { text-align: right; font-size: 14px; }
    .stmt-meta h1 { margin: 0 0 8px; font-size: 22px; letter-spacing: 0.06em; }
    .bill-to { margin-bottom: 24px; }
    .bill-to h2 { font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: #718096; margin: 0 0 6px; }
    table { width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 14px; }
    th, td { border-bottom: 1px solid #e2e8f0; padding: 10px 8px; text-align: left; }
    th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #718096; border-bottom-width: 2px; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    tfoot td { font-weight: bold; border-top: 2px solid #0f766e; font-size: 16px; }
    .note { margin-top: 28px; padding-top: 16px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #718096; font-family: system-ui, sans-serif; }
    @media print { .toolbar { display: none; } body { padding: 0; } }
  </style>
</head>
<body>
  <div class="toolbar">
    <button type="button" class="primary" onclick="window.print()">Print / Save PDF</button>
    <a href="customer_portal_billing.php">Back to billing</a>
  </div>

  <header class="header">
    <div>
      <?php echo bakery_sour_flour_logo_img('brand-logo'); ?>
      <p class="tagline"><?php echo htmlspecialchars($statement['company']['tagline']); ?></p>
    </div>
    <div class="stmt-meta">
      <h1>STATEMENT</h1>
      <div>Statement date: <?php echo date('F j, Y', strtotime($statementDate)); ?></div>
      <div>Period: <?php echo date('M j, Y', strtotime($startDate)); ?> – <?php echo date('M j, Y', strtotime($endDate)); ?></div>
    </div>
  </header>

  <section class="bill-to">
    <h2>Account</h2>
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

  <?php if (!$statement['invoices']): ?>
    <p>No confirmed delivery invoices in this period.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Invoice #</th>
          <th>Delivery date</th>
          <th>Invoice date</th>
          <th class="num">Amount</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($statement['invoices'] as $line): ?>
        <tr>
          <td>
            <a href="customer_invoice.php?daily_order_id=<?php echo (int)$line['daily_order_id']; ?>" style="color:#0f766e">
              <?php echo htmlspecialchars($line['invoice_number']); ?>
            </a>
          </td>
          <td><?php echo date('M j, Y', strtotime($line['order_date'])); ?></td>
          <td><?php echo date('M j, Y', strtotime($line['invoice_date'])); ?></td>
          <td class="num">$<?php echo number_format($line['amount'], 2); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3">Period total (confirmed deliveries)</td>
          <td class="num">$<?php echo number_format($statement['total_amount'], 2); ?></td>
        </tr>
      </tfoot>
    </table>
  <?php endif; ?>

  <p class="note">
    <?php echo htmlspecialchars($statement['balance_note']); ?>
    Payment status in external accounting is not shown here.
  </p>
</body>
</html>
