<?php
/**
 * Customer notification center — in-app updates about orders, deliveries, and billing.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/customer_notifications.php';

$customer = bakery_portal_require_customer($db);
$customerId = (int)$customer['id'];

$notifications = bakery_customer_notifications_list($db, $customerId, 50, 0);
$unreadCount = bakery_customer_notifications_unread_count($db, $customerId);
$prefs = bakery_customer_notification_preferences($db, $customerId);
$emailAvailable = bakery_customer_notification_email_ready();

$page_title = bakery_t('page.portal_notifications');
$currentLocale = bakery_locale();
$portalActivePage = 'notifications';
$portalCustomerName = $customer['name'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8'); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="csrf-token" content="<?php echo htmlspecialchars(bakery_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
  <title><?php echo htmlspecialchars($page_title); ?></title>
  <?php require __DIR__ . '/includes/portal_styles.php'; ?>
</head>
<body>
  <?php require __DIR__ . '/includes/portal_header.php'; ?>

  <main class="container">
    <div class="notify-toolbar">
      <p class="page-intro"><?php bakery_te('portal.notifications_intro'); ?></p>
      <?php if ($unreadCount > 0): ?>
        <button type="button" class="btn btn-secondary btn-sm js-mark-all-read"><?php bakery_te('portal.notifications_mark_all_read'); ?></button>
      <?php endif; ?>
    </div>

    <?php if (!$notifications): ?>
      <section class="card">
        <div class="card-body">
          <p class="delivery-card-summary"><?php bakery_te('portal.notifications_empty'); ?></p>
        </div>
      </section>
    <?php else: ?>
      <ul class="notify-list" aria-label="<?php bakery_te('portal.notifications_list_label'); ?>">
        <?php foreach ($notifications as $note): ?>
          <li class="notify-item<?php echo $note['is_read'] ? ' is-read' : ' is-unread'; ?>" data-id="<?php echo (int)$note['id']; ?>">
            <?php if (!empty($note['link_url'])): ?>
              <a class="notify-item__link" href="<?php echo htmlspecialchars($note['link_url']); ?>">
            <?php else: ?>
              <div class="notify-item__link">
            <?php endif; ?>
              <div class="notify-item__head">
                <strong class="notify-item__title"><?php echo htmlspecialchars($note['title']); ?></strong>
                <time class="notify-item__time" datetime="<?php echo htmlspecialchars($note['created_at']); ?>">
                  <?php echo htmlspecialchars($note['created_at_formatted']); ?>
                </time>
              </div>
              <p class="notify-item__message"><?php echo htmlspecialchars($note['message']); ?></p>
            <?php if (!empty($note['link_url'])): ?>
              </a>
            <?php else: ?>
              </div>
            <?php endif; ?>
            <?php if (!$note['is_read']): ?>
              <button type="button" class="notify-item__read js-mark-read" data-id="<?php echo (int)$note['id']; ?>">
                <?php bakery_te('portal.notifications_mark_read'); ?>
              </button>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <section class="card notify-prefs" id="notification-preferences">
      <div class="card-body">
        <h2 class="card-title"><?php bakery_te('portal.notifications_prefs_heading'); ?></h2>
        <p class="section-note"><?php bakery_te('portal.notifications_prefs_note'); ?></p>
        <?php if (!$emailAvailable): ?>
          <p class="section-note notify-email-unavailable"><?php bakery_te('portal.notifications_email_unavailable'); ?></p>
        <?php endif; ?>
        <form class="notify-prefs-form js-notify-prefs" id="notifyPrefsForm">
          <fieldset class="notify-prefs-group">
            <legend><?php bakery_te('portal.notifications_prefs_orders'); ?></legend>
            <label class="notify-check">
              <input type="checkbox" name="order_in_app" value="1"<?php echo $prefs['order_in_app'] ? ' checked' : ''; ?>>
              <?php bakery_te('portal.notifications_in_app'); ?>
            </label>
            <?php if ($emailAvailable): ?>
              <label class="notify-check">
                <input type="checkbox" name="order_email" value="1"<?php echo $prefs['order_email'] ? ' checked' : ''; ?>>
                <?php bakery_te('portal.notifications_email'); ?>
              </label>
            <?php endif; ?>
          </fieldset>
          <fieldset class="notify-prefs-group">
            <legend><?php bakery_te('portal.notifications_prefs_delivery'); ?></legend>
            <label class="notify-check">
              <input type="checkbox" name="delivery_in_app" value="1"<?php echo $prefs['delivery_in_app'] ? ' checked' : ''; ?>>
              <?php bakery_te('portal.notifications_in_app'); ?>
            </label>
            <?php if ($emailAvailable): ?>
              <label class="notify-check">
                <input type="checkbox" name="delivery_email" value="1"<?php echo $prefs['delivery_email'] ? ' checked' : ''; ?>>
                <?php bakery_te('portal.notifications_email'); ?>
              </label>
            <?php endif; ?>
          </fieldset>
          <fieldset class="notify-prefs-group">
            <legend><?php bakery_te('portal.notifications_prefs_billing'); ?></legend>
            <label class="notify-check">
              <input type="checkbox" name="billing_in_app" value="1"<?php echo $prefs['billing_in_app'] ? ' checked' : ''; ?>>
              <?php bakery_te('portal.notifications_in_app'); ?>
            </label>
            <?php if ($emailAvailable): ?>
              <label class="notify-check">
                <input type="checkbox" name="billing_email" value="1"<?php echo $prefs['billing_email'] ? ' checked' : ''; ?>>
                <?php bakery_te('portal.notifications_email'); ?>
              </label>
            <?php endif; ?>
          </fieldset>
          <div class="section-actions">
            <button type="submit" class="btn btn-secondary"><?php bakery_te('portal.notifications_save'); ?></button>
            <span class="section-status js-prefs-status" aria-live="polite"></span>
          </div>
        </form>
      </div>
    </section>
  </main>

  <?php require __DIR__ . '/includes/portal_nav.php'; ?>

  <script>
    window.PORTAL_NOTIFY_I18N = <?php echo json_encode([
        'saved' => bakery_t('portal.saved'),
        'saveFailed' => bakery_t('portal.save_failed'),
        'networkError' => bakery_t('portal.network_error'),
    ], JSON_UNESCAPED_UNICODE); ?>;
  </script>
  <script src="<?php echo bakery_asset_href('includes/portal_notifications.js'); ?>" defer></script>
</body>
</html>
