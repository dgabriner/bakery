<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/customer_delivery_issues.php';

$customer = bakery_portal_require_customer($db);
$customerId = (int)$customer['id'];
$issueId = (int)($_GET['id'] ?? 0);
$error = '';
$issue = null;

try {
    if ($issueId <= 0) {
        throw new InvalidArgumentException(bakery_t('issue.not_found'));
    }
    $row = bakery_delivery_issue_assert_ownership($db, $customerId, $issueId);
    $issue = bakery_delivery_issue_format_row($row);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$page_title = bakery_t('issue.view_heading');
$currentLocale = bakery_locale();
$portalActivePage = 'delivery';
$portalCustomerName = $customer['name'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8'); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($page_title); ?></title>
  <?php require __DIR__ . '/includes/portal_styles.php'; ?>
</head>
<body>
  <?php require __DIR__ . '/includes/portal_header.php'; ?>

  <main class="container">
    <?php if ($error): ?>
      <p class="empty-state"><?php echo htmlspecialchars($error); ?></p>
      <a class="btn btn-secondary" href="customer_portal.php"><?php bakery_te('portal.back_home'); ?></a>
    <?php else: ?>
      <p><a href="customer_portal_delivery.php?date=<?php echo urlencode($issue['order_date']); ?>" class="delivery-card-summary"><?php bakery_te('issue.back_to_delivery'); ?></a></p>

      <section class="card hero-card">
        <div class="card-body">
          <p class="hero-label"><?php bakery_te('issue.report_heading'); ?> #<?php echo (int)$issue['id']; ?></p>
          <h1 class="hero-date"><?php echo htmlspecialchars(format_date($issue['order_date'], 'l, M j, Y')); ?></h1>
          <div class="meta-row">
            <span class="badge badge-<?php echo htmlspecialchars($issue['status_tone']); ?>"><?php echo htmlspecialchars($issue['status_label']); ?></span>
            <span><?php echo htmlspecialchars($issue['category_label']); ?></span>
          </div>
        </div>
      </section>

      <section class="card">
        <div class="card-header"><h2><?php bakery_te('issue.details_heading'); ?></h2></div>
        <div class="card-body">
          <?php if ($issue['product_name']): ?>
            <p><strong><?php bakery_te('issue.product_label'); ?>:</strong> <?php echo htmlspecialchars($issue['product_name']); ?></p>
          <?php endif; ?>
          <?php if ($issue['ordered_quantity'] !== null): ?>
            <p><strong><?php bakery_te('delivery.ordered'); ?>:</strong> <?php echo (int)$issue['ordered_quantity']; ?></p>
          <?php endif; ?>
          <?php if ($issue['driver_delivered_quantity'] !== null): ?>
            <p><strong><?php bakery_te('issue.driver_recorded'); ?>:</strong> <?php echo (int)$issue['driver_delivered_quantity']; ?></p>
          <?php endif; ?>
          <?php if ($issue['customer_reported_quantity'] !== null): ?>
            <p><strong><?php bakery_te('issue.customer_reported'); ?>:</strong> <?php echo (int)$issue['customer_reported_quantity']; ?></p>
          <?php endif; ?>
          <p><strong><?php bakery_te('issue.description_label'); ?>:</strong></p>
          <p><?php echo nl2br(htmlspecialchars($issue['description'])); ?></p>
          <p class="muted" style="margin-top:12px;font-size:.85rem"><?php bakery_te('issue.submitted_at'); ?>: <?php echo htmlspecialchars($issue['created_at_label']); ?></p>
        </div>
      </section>

      <?php if (in_array($issue['status'], ['resolved', 'closed'], true) && !empty($issue['resolution_note'])): ?>
        <section class="card">
          <div class="card-header"><h2><?php bakery_te('issue.resolution_heading'); ?></h2></div>
          <div class="card-body">
            <p><?php echo nl2br(htmlspecialchars($issue['resolution_note'])); ?></p>
            <?php if ($issue['resolved_at_label']): ?>
              <p class="muted" style="margin-top:12px;font-size:.85rem"><?php echo htmlspecialchars($issue['resolved_at_label']); ?></p>
            <?php endif; ?>
            <?php if ($issue['credit_recommendation'] !== 'none' && $issue['credit_pieces']): ?>
              <p class="notice notice--info" style="margin-top:12px"><?php echo htmlspecialchars(bakery_t('issue.credit_pending_note', ['pieces' => (int)$issue['credit_pieces']])); ?></p>
            <?php endif; ?>
          </div>
        </section>
      <?php elseif (in_array($issue['status'], ['submitted', 'under_review'], true)): ?>
        <section class="card">
          <div class="card-body">
            <p class="delivery-card-summary"><?php bakery_te('issue.pending_message'); ?></p>
          </div>
        </section>
      <?php endif; ?>
    <?php endif; ?>
  </main>

  <?php require __DIR__ . '/includes/portal_nav.php'; ?>
</body>
</html>
