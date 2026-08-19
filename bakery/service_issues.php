<?php
/**
 * Service Issues — manager queue for customer delivery issue resolution.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/customer_delivery_issues.php';
require_once __DIR__ . '/includes/operational_exceptions.php';

bakery_require_role(['administrator', 'manager']);

$page_title = bakery_t('page.service_issues');
$statusFilter = trim((string)($_GET['status'] ?? 'open'));
$issueId = (int)($_GET['id'] ?? 0);
$customerFilterId = max(0, (int)($_GET['customer_id'] ?? 0));
$flash = trim((string)($_GET['flash'] ?? ''));
$returnTarget = bakery_ops_return_resolve($_GET['return'] ?? null, date('Y-m-d'));
$attentionLabel = $statusFilter === 'open' ? 'Showing open customer issues' : '';
if ($customerFilterId > 0) {
    $attentionLabel = ($attentionLabel !== '' ? $attentionLabel . ' · ' : '') . 'Filtered to one customer';
}

$queueFilters = [
    'status' => $statusFilter,
    'limit' => 100,
];
if ($customerFilterId > 0) {
    $queueFilters['customer_id'] = $customerFilterId;
}
$queue = bakery_delivery_issues_manager_queue($db, $queueFilters);
$filterCustomerName = '';
if ($customerFilterId > 0) {
    try {
        $nameStmt = $db->prepare('SELECT name FROM customers WHERE id = ? LIMIT 1');
        $nameStmt->execute([$customerFilterId]);
        $filterCustomerName = (string)($nameStmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        $filterCustomerName = '';
    }
}
$detail = null;
if ($issueId > 0) {
    try {
        $detail = bakery_delivery_issue_get_manager($db, $issueId);
    } catch (Throwable $e) {
        $detail = null;
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>
<style>
.service-issues { max-width: 1200px; margin: 0 auto; padding: 16px; }
.service-issues__grid { display: grid; grid-template-columns: 1fr 1.2fr; gap: 20px; }
@media (max-width: 900px) { .service-issues__grid { grid-template-columns: 1fr; } }
.issue-queue-item { display: block; padding: 12px; border: 1px solid var(--border, #ddd); border-radius: 8px; margin-bottom: 8px; text-decoration: none; color: inherit; }
.issue-queue-item.is-active { border-color: #b75c3f; background: #fdf8f6; }
.issue-queue-item:hover { background: #fafafa; }
.issue-meta { font-size: .85rem; color: #666; margin-top: 4px; }
.issue-detail dl { display: grid; grid-template-columns: 140px 1fr; gap: 8px 12px; font-size: .92rem; }
.issue-detail dt { color: #666; }
.issue-links { display: flex; flex-wrap: wrap; gap: 8px; margin: 16px 0; }
.issue-links a { font-size: .88rem; }
.flash-ok { background: #e8f5e9; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
.flash-error { background: #fde8e8; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
.credit-note { background: #fff8e6; border: 1px solid #e6d9a8; padding: 10px 14px; border-radius: 8px; font-size: .88rem; margin: 12px 0; }
</style>

<div class="service-issues">
  <?php echo bakery_ops_render_return_banner($returnTarget, $attentionLabel); ?>
  <header style="margin-bottom:20px">
    <h1><?php bakery_te('page.service_issues'); ?></h1>
    <p><?php bakery_te('issue.manager_intro'); ?></p>
  </header>

  <?php if ($flash === 'resolved'): ?>
    <div class="flash-ok"><?php bakery_te('issue.manager_resolved_flash'); ?></div>
  <?php elseif ($flash === 'error'): ?>
    <div class="flash-error"><?php bakery_te('issue.manager_error_flash'); ?></div>
  <?php endif; ?>

  <div style="margin-bottom:16px">
    <?php
      $statusQs = $customerFilterId > 0 ? '&customer_id=' . $customerFilterId : '';
    ?>
    <a href="service_issues.php?status=open<?php echo $statusQs; ?>"<?php echo $statusFilter === 'open' ? ' style="font-weight:600"' : ''; ?>><?php bakery_te('issue.filter_open'); ?></a>
    &nbsp;|&nbsp;
    <a href="service_issues.php?status=all<?php echo $statusQs; ?>"<?php echo $statusFilter === 'all' ? ' style="font-weight:600"' : ''; ?>><?php bakery_te('issue.filter_all'); ?></a>
    &nbsp;|&nbsp;
    <span><?php echo htmlspecialchars(bakery_t('issue.open_count', ['count' => bakery_delivery_issues_open_count($db)])); ?></span>
    <?php if ($customerFilterId > 0): ?>
      <div class="issue-meta" style="margin-top:8px">
        Filtered to
        <a href="customer_record.php?customer_id=<?php echo $customerFilterId; ?>">
          <?php echo htmlspecialchars($filterCustomerName !== '' ? $filterCustomerName : ('Customer #' . $customerFilterId)); ?>
        </a>
        · <a href="service_issues.php?status=<?php echo urlencode($statusFilter); ?>">Clear customer filter</a>
      </div>
    <?php endif; ?>
  </div>

  <div class="service-issues__grid">
    <div>
      <h2><?php bakery_te('issue.queue_heading'); ?></h2>
      <?php if (!$queue): ?>
        <p class="issue-meta"><?php bakery_te('issue.queue_empty'); ?></p>
      <?php else: ?>
        <?php foreach ($queue as $item): ?>
          <a href="service_issues.php?id=<?php echo (int)$item['id']; ?>&status=<?php echo urlencode($statusFilter); ?><?php echo $customerFilterId > 0 ? '&customer_id=' . $customerFilterId : ''; ?>"
             class="issue-queue-item<?php echo $detail && (int)$detail['id'] === (int)$item['id'] ? ' is-active' : ''; ?>">
            <strong><?php echo htmlspecialchars($item['customer_name']); ?></strong>
            <span class="badge"><?php echo htmlspecialchars($item['status_label']); ?></span>
            <div class="issue-meta">
              <?php echo htmlspecialchars(format_date($item['order_date'], 'M j, Y')); ?>
              · <?php echo htmlspecialchars($item['category_label']); ?>
              <?php if ($item['product_name']): ?> · <?php echo htmlspecialchars($item['product_name']); ?><?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div>
      <?php if ($detail): ?>
        <h2><?php bakery_te('issue.detail_heading'); ?> #<?php echo (int)$detail['id']; ?></h2>
        <div class="issue-detail">
          <dl>
            <dt><?php bakery_te('issue.customer'); ?></dt>
            <dd>
              <a href="<?php echo htmlspecialchars($detail['customer_url'] ?? ('customer_record.php?customer_id=' . (int)$detail['customer_id'])); ?>">
                <?php echo htmlspecialchars($detail['customer_name']); ?>
              </a>
            </dd>
            <dt><?php bakery_te('issue.delivery_date'); ?></dt>
            <dd><?php echo htmlspecialchars(format_date($detail['order_date'], 'l, M j, Y')); ?></dd>
            <dt><?php bakery_te('issue.category_label'); ?></dt>
            <dd><?php echo htmlspecialchars($detail['category_label']); ?></dd>
            <?php if ($detail['product_name']): ?>
              <dt><?php bakery_te('issue.product_label'); ?></dt>
              <dd><?php echo htmlspecialchars($detail['product_name']); ?></dd>
            <?php endif; ?>
            <?php if ($detail['ordered_quantity'] !== null): ?>
              <dt><?php bakery_te('delivery.ordered'); ?></dt>
              <dd><?php echo (int)$detail['ordered_quantity']; ?></dd>
            <?php endif; ?>
            <?php if ($detail['driver_delivered_quantity'] !== null): ?>
              <dt><?php bakery_te('issue.driver_recorded'); ?></dt>
              <dd><?php echo (int)$detail['driver_delivered_quantity']; ?></dd>
            <?php endif; ?>
            <?php if ($detail['customer_reported_quantity'] !== null): ?>
              <dt><?php bakery_te('issue.customer_reported'); ?></dt>
              <dd><?php echo (int)$detail['customer_reported_quantity']; ?></dd>
            <?php endif; ?>
            <dt><?php bakery_te('issue.description_label'); ?></dt>
            <dd><?php echo nl2br(htmlspecialchars($detail['description'])); ?></dd>
            <dt><?php bakery_te('issue.status_label'); ?></dt>
            <dd><?php echo htmlspecialchars($detail['status_label']); ?></dd>
          </dl>
        </div>

        <div class="issue-links">
          <a href="<?php echo htmlspecialchars($detail['delivery_url'] ?? '#'); ?>" target="_blank"><?php bakery_te('issue.link_delivery'); ?></a>
          <a href="<?php echo htmlspecialchars($detail['customer_url'] ?? ('customer_record.php?customer_id=' . (int)$detail['customer_id'])); ?>"><?php bakery_te('issue.link_customer'); ?></a>
          <a href="<?php echo htmlspecialchars($detail['invoice_url'] ?? '#'); ?>"><?php bakery_te('issue.link_invoice'); ?></a>
          <a href="operational_timeline.php?daily_order_id=<?php echo (int)$detail['daily_order_id']; ?>"><?php bakery_te('issue.link_timeline'); ?></a>
        </div>

        <div class="credit-note"><?php bakery_te('issue.credit_billing_note'); ?></div>

        <?php if ($detail['status'] === 'submitted'): ?>
          <form method="post" action="service_issues_api.php" style="margin-bottom:12px">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(bakery_csrf_token()); ?>">
            <input type="hidden" name="action" value="start_review">
            <input type="hidden" name="issue_id" value="<?php echo (int)$detail['id']; ?>">
            <input type="hidden" name="redirect" value="service_issues.php?id=<?php echo (int)$detail['id']; ?>">
            <button type="submit" class="btn btn-secondary"><?php bakery_te('issue.start_review'); ?></button>
          </form>
        <?php endif; ?>

        <?php if (in_array($detail['status'], ['submitted', 'under_review'], true)): ?>
          <form method="post" action="service_issues_api.php" class="issue-resolve-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(bakery_csrf_token()); ?>">
            <input type="hidden" name="action" value="resolve">
            <input type="hidden" name="issue_id" value="<?php echo (int)$detail['id']; ?>">
            <input type="hidden" name="redirect" value="service_issues.php?id=<?php echo (int)$detail['id']; ?>&flash=resolved">

            <h3><?php bakery_te('issue.resolve_heading'); ?></h3>
            <label><?php bakery_te('issue.resolution_note_label'); ?></label>
            <textarea name="resolution_note" required rows="3" style="width:100%;margin-bottom:10px"></textarea>

            <label><?php bakery_te('issue.internal_note_label'); ?></label>
            <textarea name="internal_note" rows="2" style="width:100%;margin-bottom:10px" placeholder="<?php bakery_te('issue.internal_note_placeholder'); ?>"></textarea>

            <label><?php bakery_te('issue.credit_recommendation_label'); ?></label>
            <select name="credit_recommendation" style="width:100%;margin-bottom:10px;padding:6px">
              <option value="none"<?php echo $detail['credit_recommendation'] === 'none' ? ' selected' : ''; ?>><?php bakery_te('issue.credit_none'); ?></option>
              <option value="requested"<?php echo $detail['credit_recommendation'] === 'requested' ? ' selected' : ''; ?>><?php bakery_te('issue.credit_requested'); ?></option>
              <option value="recommended"<?php echo $detail['credit_recommendation'] === 'recommended' ? ' selected' : ''; ?>><?php bakery_te('issue.credit_recommended'); ?></option>
            </select>

            <label><?php bakery_te('issue.credit_pieces_label'); ?></label>
            <input type="number" name="credit_pieces" min="0" value="<?php echo $detail['credit_pieces'] !== null ? (int)$detail['credit_pieces'] : ''; ?>" style="width:100%;margin-bottom:10px;padding:6px">

            <label><?php bakery_te('issue.resolution_status_label'); ?></label>
            <select name="status" style="width:100%;margin-bottom:12px;padding:6px">
              <option value="resolved"><?php bakery_te('issue.status_resolved'); ?></option>
              <option value="closed"><?php bakery_te('issue.status_closed'); ?></option>
            </select>

            <button type="submit" class="btn"><?php bakery_te('issue.resolve_submit'); ?></button>
          </form>
        <?php elseif (!empty($detail['resolution_note'])): ?>
          <h3><?php bakery_te('issue.resolution_heading'); ?></h3>
          <p><?php echo nl2br(htmlspecialchars($detail['resolution_note'])); ?></p>
          <?php if (!empty($detail['internal_note'])): ?>
            <p class="issue-meta"><strong><?php bakery_te('issue.internal_note_label'); ?>:</strong> <?php echo nl2br(htmlspecialchars($detail['internal_note'])); ?></p>
          <?php endif; ?>
        <?php endif; ?>
      <?php else: ?>
        <p class="issue-meta"><?php bakery_te('issue.select_from_queue'); ?></p>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
