<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/portal_command_center.php';

$customer = bakery_portal_require_customer($db);
bakery_portal_handle_sfb_bridge_post($db);
$customerId = (int)$customer['id'];

$nextDelivery = null;
$upcoming = [];
$recentDelivery = null;
$attention = [];
$homeError = '';

try {
    $homeDeliveries = bakery_portal_cmd_home_deliveries($db, $customerId);
    $nextDelivery = $homeDeliveries['next'];
    $upcoming = $homeDeliveries['upcoming'];
    $recentDelivery = bakery_portal_cmd_recent_delivery($db, $customerId);
    $attention = bakery_portal_cmd_attention_items($db, $customer, $nextDelivery);
} catch (Throwable $e) {
    error_log('customer_portal home: ' . $e->getMessage());
    $homeError = bakery_t('portal.home_error');
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

$tipStatus = trim((string)($_GET['tip'] ?? ''));

$page_title = bakery_t('page.portal_home');
$currentLocale = bakery_locale();
$portalActivePage = 'home';
$portalCustomerName = $customer['name'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8'); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?php echo htmlspecialchars($page_title); ?></title>
  <?php require __DIR__ . '/includes/portal_styles.php'; ?>
</head>
<body>
  <?php require __DIR__ . '/includes/portal_header.php'; ?>

  <main class="container">
    <?php if ($homeError !== ''): ?>
      <div class="card"><div class="card-body"><p class="delivery-card-summary"><?php echo htmlspecialchars($homeError); ?></p></div></div>
    <?php endif; ?>
    <?php if ($tipStatus === 'thanks'): ?>
      <div class="card" style="background:var(--ok-bg,#f0fdf4);border-left:4px solid var(--ok,#16a34a)">
        <div class="card-body" style="padding:14px 16px">
          <strong><?php bakery_te('portal.tip_thanks_heading'); ?></strong>
          <p style="margin:4px 0 0;font-size:.9rem;color:var(--muted)"><?php bakery_te('portal.tip_thanks_body'); ?></p>
        </div>
      </div>
    <?php elseif ($tipStatus === 'error'): ?>
      <div class="card" style="background:var(--warn-bg,#fffbeb);border-left:4px solid var(--warn,#d97706)">
        <div class="card-body" style="padding:14px 16px">
          <strong><?php bakery_te('portal.tip_error_heading'); ?></strong>
          <p style="margin:4px 0 0;font-size:.9rem;color:var(--muted)"><?php bakery_te('portal.tip_error_body'); ?></p>
        </div>
      </div>
    <?php endif; ?>
    <section class="card hero-card">
      <div class="card-body">
        <p class="hero-label"><?php bakery_te('portal.next_delivery'); ?></p>
        <?php if ($nextDelivery): ?>
          <h2 class="hero-date"><?php echo htmlspecialchars($nextDelivery['date_label']); ?></h2>
          <?php if (!empty($nextDelivery['lines'])): ?>
            <ul class="line-list">
              <?php foreach ($nextDelivery['lines'] as $line): ?>
                <li>
                  <span><?php echo htmlspecialchars($line['product_name']); ?></span>
                  <span class="line-qty"><?php echo (int)$line['quantity']; ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="delivery-card-summary"><?php bakery_te('portal.no_items_scheduled'); ?></p>
          <?php endif; ?>
          <div class="meta-row">
            <span class="badge <?php echo bakery_portal_status_badge_class($nextDelivery['status']['tone']); ?>">
              <?php echo htmlspecialchars($nextDelivery['status']['label']); ?>
            </span>
            <span><?php echo htmlspecialchars($nextDelivery['schedule_note']); ?></span>
            <?php if ((int)$nextDelivery['total_units'] > 0): ?>
              <span><?php echo htmlspecialchars(bakery_t('portal.item_count', ['count' => (int)$nextDelivery['total_units']])); ?></span>
            <?php endif; ?>
          </div>
          <?php if (!empty($nextDelivery['status_message'])): ?>
            <p class="delivery-card-summary"><?php echo htmlspecialchars($nextDelivery['status_message']); ?></p>
          <?php endif; ?>
          <?php if (!empty($nextDelivery['progress'])): ?>
            <p class="delivery-progress" style="font-weight:600;margin-top:8px"><?php echo htmlspecialchars($nextDelivery['progress']); ?></p>
          <?php endif; ?>
          <div class="btn-row">
            <?php if (!empty($nextDelivery['can_edit'])): ?>
              <a class="btn btn-block" href="customer_portal_delivery.php?date=<?php echo urlencode($nextDelivery['date']); ?>">
                <?php bakery_te('portal.review_change_delivery'); ?>
              </a>
            <?php else: ?>
              <a class="btn btn-secondary btn-block" href="customer_portal_delivery.php?date=<?php echo urlencode($nextDelivery['date']); ?>">
                <?php bakery_te('portal.view_delivery'); ?>
              </a>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <p class="empty-state"><?php bakery_te('portal.no_upcoming_delivery'); ?></p>
          <div class="btn-row">
            <a class="btn btn-secondary" href="customer_portal_regular.php"><?php bakery_te('portal.view_regular_order'); ?></a>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($attention): ?>
      <section class="card">
        <div class="card-header"><h2><?php bakery_te('portal.needs_attention'); ?></h2></div>
        <div class="card-body">
          <ul class="attention-list">
            <?php foreach ($attention as $item): ?>
              <li class="level-<?php echo htmlspecialchars($item['level']); ?>">
                <?php if (!empty($item['link'])): ?>
                  <a href="<?php echo htmlspecialchars($item['link']); ?>"><?php echo htmlspecialchars($item['message']); ?></a>
                <?php else: ?>
                  <?php echo htmlspecialchars($item['message']); ?>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($upcoming): ?>
      <section>
        <h2 class="section-title"><?php bakery_te('portal.upcoming_deliveries'); ?></h2>
        <?php foreach ($upcoming as $card): ?>
          <article class="delivery-card">
            <div class="delivery-card-top">
              <div>
                <h3 class="delivery-card-date"><?php echo htmlspecialchars($card['date_short']); ?></h3>
                <p class="delivery-card-summary">
                  <?php echo htmlspecialchars(bakery_t('portal.item_count', ['count' => (int)$card['total_units']])); ?>
                  · <?php echo htmlspecialchars($card['schedule_note']); ?>
                </p>
              </div>
              <span class="badge <?php echo bakery_portal_status_badge_class($card['status']['tone']); ?>">
                <?php echo htmlspecialchars($card['status']['label']); ?>
              </span>
            </div>
            <div class="delivery-card-actions">
              <a class="btn btn-secondary btn-block" href="customer_portal_delivery.php?date=<?php echo urlencode($card['date']); ?>">
                <?php bakery_te('portal.view_delivery'); ?>
              </a>
            </div>
          </article>
        <?php endforeach; ?>
        <a class="btn btn-secondary btn-block" href="customer_portal_calendar.php"><?php bakery_te('portal.view_all_upcoming'); ?></a>
      </section>
    <?php endif; ?>

    <?php if ($recentDelivery): ?>
      <section class="card">
        <div class="card-header"><h2><?php bakery_te('portal.recent_delivery'); ?></h2></div>
        <div class="card-body">
          <p class="delivery-card-summary">
            <?php echo htmlspecialchars($recentDelivery['date_label']); ?>
            · <?php echo htmlspecialchars($recentDelivery['status']['label']); ?>
            · <?php echo htmlspecialchars(bakery_t('portal.item_count', ['count' => (int)$recentDelivery['total_units']])); ?>
          </p>
          <a class="btn btn-secondary btn-block" href="customer_portal_history.php"><?php bakery_te('portal.view_past_deliveries'); ?></a>
        </div>
      </section>
    <?php endif; ?>

    <?php bakery_portal_sfb_bridge_card($db, $customer); ?>

    <section class="card" style="text-align:center;padding:20px 16px">
      <p style="font-size:1.1rem;font-weight:600;margin:0 0 4px"><?php bakery_te('portal.tip_heading'); ?></p>
      <p style="color:var(--muted);font-size:.88rem;margin:0 0 14px"><?php bakery_te('portal.tip_body'); ?></p>
      <form method="post" action="customer_portal_tip.php">
        <?php echo bakery_csrf_field(); ?>
        <button type="submit" class="btn" style="min-width:140px;font-size:1rem">
          <?php bakery_te('portal.tip_button'); ?>
        </button>
      </form>
    </section>

  </main>
  <?php require __DIR__ . '/includes/portal_nav.php'; ?>
</body>
</html>

