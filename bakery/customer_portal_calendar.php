<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/portal_command_center.php';

$customer = bakery_portal_require_customer($db);
$customerId = (int)$customer['id'];
$upcoming = [];
try {
    $upcoming = bakery_portal_cmd_schedule_deliveries($db, $customerId, 42, 20);
} catch (Throwable $e) {
    error_log('customer_portal_calendar: ' . $e->getMessage());
}

function bakery_portal_status_badge_class($tone) {
    $map = [
        'ok' => 'badge-ok',
        'info' => 'badge-info',
        'warn' => 'badge-warn',
        'muted' => 'badge-muted',
        'danger' => 'badge-danger',
    ];
    return $map[$tone] ?? 'badge-muted';
}

$page_title = bakery_t('page.portal_calendar');
$currentLocale = bakery_locale();
$portalActivePage = 'calendar';
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
    <h1 class="section-title"><?php bakery_te('portal.upcoming_schedule'); ?></h1>
    <p class="delivery-card-summary"><?php bakery_te('portal.calendar_intro'); ?></p>

    <?php if (!$upcoming): ?>
      <p class="empty-state"><?php bakery_te('portal.no_upcoming_delivery'); ?></p>
    <?php else: ?>
      <?php foreach ($upcoming as $card): ?>
        <article class="delivery-card">
          <div class="delivery-card-top">
            <div>
              <h2 class="delivery-card-date"><?php echo htmlspecialchars($card['date_label']); ?></h2>
              <p class="delivery-card-summary">
                <?php echo htmlspecialchars(bakery_t('portal.item_count', ['count' => (int)$card['total_units']])); ?>
              </p>
              <p class="delivery-card-summary"><?php echo htmlspecialchars($card['schedule_note']); ?></p>
              <?php if (!empty($card['can_edit'])): ?>
                <p class="delivery-card-summary"><?php bakery_te('portal.changes_allowed'); ?></p>
              <?php else: ?>
                <p class="delivery-card-summary"><?php bakery_te('portal.changes_locked'); ?></p>
              <?php endif; ?>
            </div>
            <span class="badge <?php echo bakery_portal_status_badge_class($card['status']['tone']); ?>">
              <?php echo htmlspecialchars($card['status']['label']); ?>
            </span>
          </div>
          <?php if (!empty($card['lines'])): ?>
            <ul class="line-list">
              <?php foreach (array_slice($card['lines'], 0, 4) as $line): ?>
                <li>
                  <span><?php echo htmlspecialchars($line['product_name']); ?></span>
                  <span class="line-qty"><?php echo (int)$line['quantity']; ?></span>
                </li>
              <?php endforeach; ?>
              <?php if (count($card['lines']) > 4): ?>
                <li><span class="delivery-card-summary"><?php echo htmlspecialchars(bakery_t('portal.more_items', ['count' => count($card['lines']) - 4])); ?></span></li>
              <?php endif; ?>
            </ul>
          <?php endif; ?>
          <div class="delivery-card-actions">
            <a class="btn btn-secondary btn-block" href="<?php echo !empty($card['can_edit'])
                ? 'customer_upcoming_edit.php?date=' . urlencode($card['date'])
                : 'customer_portal_delivery.php?date=' . urlencode($card['date']); ?>">
              <?php echo htmlspecialchars(!empty($card['can_edit']) ? bakery_t('portal.review_change_delivery') : bakery_t('portal.view_delivery')); ?>
            </a>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>
  <?php require __DIR__ . '/includes/portal_nav.php'; ?>
</body>
</html>
