<?php
/**
 * Customer billing home — invoices, statements, and downloadable records.
 */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/customer_billing.php';

$customer = bakery_portal_customer($db);
$customerId = (int)$customer['id'];

$preset = trim((string)($_GET['preset'] ?? 'current_month'));
$customRange = isset($_GET['start_date'], $_GET['end_date']);

if ($customRange) {
    $startDate = trim((string)$_GET['start_date']);
    $endDate = trim((string)$_GET['end_date']);
    $rangeLabel = date('M j, Y', strtotime($startDate)) . ' – ' . date('M j, Y', strtotime($endDate));
} else {
    $range = bakery_portal_billing_date_preset($preset);
    $startDate = $range['start_date'];
    $endDate = $range['end_date'];
    $rangeLabel = $range['label'];
}

$searchQ = trim((string)($_GET['q'] ?? ''));

try {
    $account = bakery_portal_billing_account($db, $customerId, $startDate, $endDate, $searchQ);
} catch (Throwable $e) {
    http_response_code(500);
    echo htmlspecialchars($e->getMessage());
    exit;
}

$exportBase = 'customer_billing_export.php?start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate);
$statementUrl = 'customer_portal_statement.php?start_date=' . urlencode($startDate)
    . '&end_date=' . urlencode($endDate) . '&record=1';

$page_title = bakery_t('page.portal_billing');
$currentLocale = bakery_locale();
$portalActivePage = 'billing';
$portalCustomerName = $customer['name'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8'); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($page_title); ?></title>
  <?php require __DIR__ . '/includes/portal_styles.php'; ?>
  <style>
    .page-intro { margin-bottom: 18px; }
    .btn-teal { background: #0f766e; color: #fff; }
    .note { color: var(--muted); font-size: .82rem; line-height: 1.45; margin: 0 0 12px; }
    .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
    .empty { color: var(--muted); font-size: .9rem; padding: 12px 0; }
    .card > h2 { padding: 16px 16px 0; }
  </style>
</head>
<body>
  <?php require __DIR__ . '/includes/portal_header.php'; ?>

  <main class="container container--wide">
    <p class="page-intro"><?php bakery_te('portal.billing_intro'); ?></p>

    <section class="card">
      <h2><?php bakery_te('portal.billing_period'); ?></h2>
      <div class="preset-links">
        <a href="?preset=current_month"<?php echo !$customRange && $preset === 'current_month' ? ' class="active"' : ''; ?>><?php bakery_te('portal.billing_current_month'); ?></a>
        <a href="?preset=prev_month"<?php echo !$customRange && $preset === 'prev_month' ? ' class="active"' : ''; ?>><?php bakery_te('portal.billing_prev_month'); ?></a>
      </div>
      <form method="get" class="filter-row">
        <label><?php bakery_te('portal.billing_from'); ?>
          <input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>" required>
        </label>
        <label><?php bakery_te('portal.billing_through'); ?>
          <input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>" required>
        </label>
        <label><?php bakery_te('portal.billing_search'); ?>
          <input type="search" name="q" value="<?php echo htmlspecialchars($searchQ); ?>" placeholder="<?php bakery_te('portal.billing_search_ph'); ?>">
        </label>
        <button type="submit" class="btn"><?php bakery_te('portal.billing_apply'); ?></button>
      </form>
      <p class="note"><?php echo htmlspecialchars($rangeLabel); ?> · <?php echo htmlspecialchars($account['payment_note']); ?></p>

      <div class="metrics">
        <div class="metric"><strong><?php echo number_format($account['invoice_count']); ?></strong><span><?php bakery_te('portal.billing_invoices'); ?></span></div>
        <div class="metric"><strong>$<?php echo number_format($account['period_total'], 2); ?></strong><span><?php bakery_te('portal.billing_period_total'); ?></span></div>
      </div>
    </section>

    <section class="card">
      <h2><?php bakery_te('portal.billing_invoices_heading'); ?></h2>
      <?php if (!$account['invoices']): ?>
        <p class="empty"><?php bakery_te('portal.billing_no_invoices'); ?></p>
      <?php else: ?>
        <div class="table-scroll">
        <table>
          <thead>
            <tr>
              <th><?php bakery_te('portal.billing_delivery_date'); ?></th>
              <th><?php bakery_te('portal.billing_invoice_number'); ?></th>
              <th><?php bakery_te('portal.billing_invoice_date'); ?></th>
              <th class="num"><?php bakery_te('portal.billing_amount'); ?></th>
              <th><?php bakery_te('portal.billing_status'); ?></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($account['invoices'] as $inv): ?>
            <tr>
              <td><?php echo date('M j, Y', strtotime($inv['order_date'])); ?></td>
              <td><?php echo htmlspecialchars($inv['invoice_number']); ?></td>
              <td><?php echo date('M j, Y', strtotime($inv['invoice_date'])); ?></td>
              <td class="num">$<?php echo number_format($inv['display_total'], 2); ?></td>
              <td title="<?php echo htmlspecialchars($inv['customer_payment']['detail']); ?>"><?php echo htmlspecialchars($inv['customer_payment']['label']); ?></td>
              <td><a class="btn btn-secondary" style="padding:6px 10px;font-size:.78rem" href="customer_invoice.php?daily_order_id=<?php echo (int)$inv['id']; ?>"><?php bakery_te('portal.billing_view'); ?></a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2><?php bakery_te('portal.billing_statements_heading'); ?></h2>
      <p class="note"><?php bakery_te('portal.billing_statements_note'); ?></p>
      <div class="actions">
        <a class="btn btn-teal" target="_blank" rel="noopener" href="<?php echo htmlspecialchars($statementUrl); ?>"><?php bakery_te('portal.billing_generate_statement'); ?></a>
      </div>
      <?php if ($account['statements']): ?>
        <table style="margin-top:14px">
          <thead>
            <tr>
              <th><?php bakery_te('portal.billing_statement_date'); ?></th>
              <th><?php bakery_te('portal.billing_period'); ?></th>
              <th class="num"><?php bakery_te('portal.billing_amount'); ?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($account['statements'] as $st): ?>
            <tr>
              <td><?php echo date('M j, Y', strtotime($st['statement_date'])); ?></td>
              <td><?php echo htmlspecialchars($st['period_start'] . ' – ' . $st['period_end']); ?></td>
              <td class="num">$<?php echo number_format((float)$st['total_amount'], 2); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2><?php bakery_te('portal.billing_downloads_heading'); ?></h2>
      <p class="note"><?php bakery_te('portal.billing_downloads_note'); ?></p>
      <div class="download-grid">
        <div class="download-item">
          <strong><?php bakery_te('portal.billing_download_summary'); ?></strong>
          <p><?php bakery_te('portal.billing_download_summary_desc'); ?></p>
          <a class="btn btn-secondary" href="<?php echo htmlspecialchars($exportBase . '&format=summary'); ?>"><?php bakery_te('portal.billing_download_csv'); ?></a>
        </div>
        <div class="download-item">
          <strong><?php bakery_te('portal.billing_download_lines'); ?></strong>
          <p><?php bakery_te('portal.billing_download_lines_desc'); ?></p>
          <a class="btn btn-secondary" href="<?php echo htmlspecialchars($exportBase . '&format=lines'); ?>"><?php bakery_te('portal.billing_download_csv'); ?></a>
        </div>
        <div class="download-item">
          <strong><?php bakery_te('portal.billing_download_bundle'); ?></strong>
          <p><?php bakery_te('portal.billing_download_bundle_desc'); ?></p>
          <div class="actions">
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars($exportBase . '&format=summary'); ?>"><?php bakery_te('portal.billing_invoice_csv'); ?></a>
            <a class="btn btn-teal" target="_blank" rel="noopener" href="<?php echo htmlspecialchars($statementUrl); ?>"><?php bakery_te('portal.billing_statement_pdf'); ?></a>
          </div>
        </div>
      </div>
    </section>
  </main>
  <?php require __DIR__ . '/includes/portal_nav.php'; ?>
</body>
</html>
