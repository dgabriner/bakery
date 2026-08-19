<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/customer_order_mutations.php';

$customer = bakery_portal_customer($db);
$customerId = (int)$customer['id'];

$date = trim((string)($_GET['date'] ?? ''));
$dateObj = DateTime::createFromFormat('!Y-m-d', $date);
if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
    header('Location: ' . BASE_URL . 'customer_portal_calendar.php');
    exit;
}

$dayOfWeek = bakery_standing_day_from_date($date);
$fullLabels = bakery_standing_day_full_labels();
$dayLabel = $fullLabels[$dayOfWeek] ?? $dateObj->format('l');
$state = bakery_customer_delivery_state($db, $customerId, $date);
$lines = bakery_customer_delivery_comparison($db, $customerId, $date, $customer);
$hasStanding = !empty(bakery_customer_standing_lines($db, $customerId, $dayOfWeek));

$productsStmt = $db->query('SELECT p.id, p.name FROM products p ORDER BY p.name');
$allProducts = $productsStmt->fetchAll();

$page_title = bakery_t('page.portal_this_delivery', ['date' => format_date($date)]);
$currentLocale = bakery_locale();
$portalActivePage = 'upcoming';
$portalCustomerName = $customer['name'];
$dateFormatted = format_date($date, 'l, F j, Y');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8'); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?php echo htmlspecialchars(bakery_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
  <title><?php echo htmlspecialchars($page_title); ?></title>
  <?php require __DIR__ . '/includes/portal_styles.php'; ?>
</head>
<body>
  <?php require __DIR__ . '/includes/portal_header.php'; ?>

  <main class="container">
    <p><a href="customer_portal_calendar.php">&larr; <?php bakery_te('portal.back_to_upcoming'); ?></a></p>

    <div class="notice notice--info">
      <strong><?php echo htmlspecialchars(bakery_t('portal.this_delivery_only', ['date' => $dateFormatted])); ?></strong>
      <?php bakery_te('portal.this_delivery_notice'); ?>
    </div>

    <?php if ($state['locked']): ?>
      <div class="notice notice--locked">
        <?php bakery_te('portal.delivery_locked_notice'); ?>
      </div>
    <?php elseif ($state['skipped']): ?>
      <div class="notice notice--warn">
        <?php bakery_te('portal.delivery_skipped_notice'); ?>
      </div>
    <?php elseif ($state['paused']): ?>
      <div class="notice notice--warn">
        <?php bakery_te('portal.delivery_paused_notice'); ?>
      </div>
    <?php endif; ?>

    <section class="delivery-card">
      <h2><?php echo htmlspecialchars($dateFormatted); ?></h2>

      <?php if (!$hasStanding && !$lines): ?>
        <p class="muted"><?php bakery_te('portal.no_standing_for_day', ['day' => $dayLabel]); ?></p>
      <?php else: ?>
        <div class="comparison-labels">
          <span><?php bakery_te('portal.product'); ?></span>
          <span><?php bakery_te('portal.regular'); ?></span>
          <span><?php bakery_te('portal.this_delivery_col'); ?></span>
          <span><?php bakery_te('portal.difference'); ?></span>
        </div>
        <?php foreach ($lines as $line): ?>
          <div class="comparison-row" data-product-id="<?php echo (int)$line['product_id']; ?>">
            <span class="product-name"><?php echo htmlspecialchars($line['product_name']); ?></span>
            <span class="col-regular"><?php echo (int)$line['regular_qty']; ?></span>
            <span class="col-delivery">
              <?php if ($state['editable']): ?>
                <div class="qty-controls">
                  <button type="button" class="qty-btn" data-delta="-1" aria-label="<?php bakery_te('portal.decrease'); ?>">−</button>
                  <span class="qty-value"><?php echo (int)$line['delivery_qty']; ?></span>
                  <button type="button" class="qty-btn" data-delta="1" aria-label="<?php bakery_te('portal.increase'); ?>">+</button>
                </div>
              <?php else: ?>
                <?php echo (int)$line['delivery_qty']; ?>
              <?php endif; ?>
            </span>
            <span class="col-diff diff<?php echo $line['diff'] === 0 ? ' diff--zero' : ''; ?>">
              <?php
                if ($line['diff'] > 0) echo '+' . $line['diff'];
                elseif ($line['diff'] < 0) echo (string)$line['diff'];
                else echo '—';
              ?>
            </span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($state['editable']): ?>
        <div class="add-row">
          <label class="muted"><?php bakery_te('portal.add_one_time'); ?></label>
          <select id="add-daily-product" class="add-product-select">
            <option value=""><?php bakery_te('portal.add_product'); ?></option>
            <?php foreach ($allProducts as $p): ?>
              <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="actions-row">
          <?php if ($state['skipped']): ?>
            <button type="button" class="btn btn-secondary" id="btn-unskip"><?php bakery_te('portal.restore_delivery'); ?></button>
          <?php else: ?>
            <button type="button" class="btn btn-secondary" id="btn-skip"><?php bakery_te('portal.skip_this_delivery'); ?></button>
          <?php endif; ?>
        </div>
      <?php elseif ($state['locked']): ?>
        <form id="request-change-form" class="request-form" style="margin-top:16px">
          <label>
            <span class="muted"><?php bakery_te('portal.request_change_label'); ?></span>
            <textarea name="message" required placeholder="<?php echo htmlspecialchars(bakery_t('portal.request_change_placeholder')); ?>"></textarea>
          </label>
          <button type="submit" class="btn"><?php bakery_te('portal.request_change_submit'); ?></button>
        </form>
      <?php endif; ?>
    </section>

    <p class="muted">
      <?php bakery_te('portal.regular_reminder'); ?>
      <a href="customer_portal.php"><?php bakery_te('portal.regular_order_heading'); ?></a>
    </p>
  </main>

  <div class="confirm-panel" id="confirm-panel" hidden>
    <div class="confirm-panel__inner">
      <h3 id="confirm-title"></h3>
      <ul id="confirm-lines"></ul>
      <p class="muted" id="confirm-unchanged"></p>
      <button type="button" class="btn" id="confirm-dismiss"><?php bakery_te('portal.got_it'); ?></button>
    </div>
  </div>
  <div class="toast" id="toast" role="status"></div>

  <script>
    window.__BAKERY_DELIVERY__ = {
      date: <?php echo json_encode($date); ?>,
      editable: <?php echo $state['editable'] ? 'true' : 'false'; ?>,
      locked: <?php echo $state['locked'] ? 'true' : 'false'; ?>
    };
    window.__BAKERY_I18N__ = <?php echo json_encode([
        'saved' => bakery_t('portal.saved'),
        'network_error' => bakery_t('portal.network_error'),
        'save_failed' => bakery_t('portal.save_failed'),
    ], JSON_UNESCAPED_UNICODE); ?>;
  </script>
  <script src="<?php echo bakery_asset_href('includes/portal_delivery.js'); ?>"></script>
  <?php require __DIR__ . '/includes/portal_nav.php'; ?>
</body>
</html>
