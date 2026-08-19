<?php

define('ACCESS_ALLOWED', true);



require_once __DIR__ . '/includes/config.php';

require_once __DIR__ . '/includes/database.php';

require_once __DIR__ . '/includes/customer_order_mutations.php';



$customer = bakery_portal_require_customer($db);

$customerId = (int)$customer['id'];

$weekStart = bakery_week_start_monday();

$nextWeekStart = date('Y-m-d', strtotime($weekStart . ' +7 days'));

$isPausedThisWeek = bakery_customer_week_is_paused($db, $customerId, $weekStart);

$isPausedNextWeek = bakery_customer_week_is_paused($db, $customerId, $nextWeekStart);

$dayLabels = bakery_standing_day_full_labels();

$activePauses = bakery_customer_active_pause_ranges($db, $customerId);



$ordersByDay = [];

foreach ($dayLabels as $dow => $label) {

    $ordersByDay[$dow] = [];

}

foreach (bakery_customer_standing_lines($db, $customerId) as $order) {

    $ordersByDay[(int)$order['day_of_week']][] = $order;

}



$productsStmt = $db->query('SELECT p.id, p.name FROM products p ORDER BY p.name');

$allProducts = $productsStmt->fetchAll();



$page_title = bakery_t('page.portal_regular_order');

$currentLocale = bakery_locale();

$portalActivePage = 'regular';

$portalCustomerName = $customer['name'];

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

    <div class="notice notice--info">

      <strong><?php bakery_te('portal.regular_order_heading'); ?></strong>

      <?php bakery_te('portal.regular_order_notice'); ?>

    </div>



    <section class="card">

      <h2 class="section-title"><?php bakery_te('portal.vacation_pause'); ?></h2>

      <p class="muted"><?php bakery_te('portal.vacation_pause_help'); ?></p>

      <form id="pause-range-form" class="inline-form">

        <label>

          <span><?php bakery_te('portal.pause_from'); ?></span>

          <input type="date" name="pause_start" required min="<?php echo date('Y-m-d'); ?>">

        </label>

        <label>

          <span><?php bakery_te('portal.pause_through'); ?></span>

          <input type="date" name="pause_end" required min="<?php echo date('Y-m-d'); ?>">

        </label>

        <button type="submit" class="btn btn-secondary"><?php bakery_te('portal.pause_deliveries'); ?></button>

      </form>

      <?php if ($activePauses): ?>

        <ul class="pause-list">

          <?php foreach ($activePauses as $pause): ?>

            <li>

              <?php echo htmlspecialchars(format_date($pause['pause_start']) . ' – ' . format_date($pause['pause_end'])); ?>

              <button type="button" class="btn-link" data-remove-pause="<?php echo (int)$pause['id']; ?>"><?php bakery_te('portal.resume_deliveries'); ?></button>

            </li>

          <?php endforeach; ?>

        </ul>

      <?php endif; ?>

    </section>



    <section class="card">

      <h2 class="section-title"><?php bakery_te('portal.delivery_pauses'); ?></h2>

      <div class="pause-row">

        <div>

          <div class="pause-label"><?php echo htmlspecialchars(bakery_t('portal.this_week', ['date' => date('M j', strtotime($weekStart))])); ?></div>

          <?php if ($isPausedThisWeek): ?>

            <span class="badge badge--paused"><?php bakery_te('portal.paused'); ?></span>

          <?php else: ?>

            <span class="badge badge--active"><?php bakery_te('portal.active'); ?></span>

          <?php endif; ?>

        </div>

        <?php if ($isPausedThisWeek): ?>

          <button type="button" class="btn btn-secondary" data-unpause="<?php echo htmlspecialchars($weekStart); ?>"><?php bakery_te('portal.resume_week'); ?></button>

        <?php else: ?>

          <button type="button" class="btn btn-secondary" data-pause="<?php echo htmlspecialchars($weekStart); ?>"><?php bakery_te('portal.pause_week'); ?></button>

        <?php endif; ?>

      </div>

      <div class="pause-row">

        <div>

          <div class="pause-label"><?php echo htmlspecialchars(bakery_t('portal.next_week', ['date' => date('M j', strtotime($nextWeekStart))])); ?></div>

          <?php if ($isPausedNextWeek): ?>

            <span class="badge badge--paused"><?php bakery_te('portal.paused'); ?></span>

          <?php else: ?>

            <span class="badge badge--active"><?php bakery_te('portal.active'); ?></span>

          <?php endif; ?>

        </div>

        <?php if ($isPausedNextWeek): ?>

          <button type="button" class="btn btn-secondary" data-unpause="<?php echo htmlspecialchars($nextWeekStart); ?>"><?php bakery_te('portal.resume_next_week'); ?></button>

        <?php else: ?>

          <button type="button" class="btn btn-secondary" data-pause="<?php echo htmlspecialchars($nextWeekStart); ?>"><?php bakery_te('portal.pause_next_week'); ?></button>

        <?php endif; ?>

      </div>

    </section>



    <?php foreach ($dayLabels as $dow => $label): ?>

      <section class="day-section" data-day="<?php echo $dow; ?>" data-empty-text="<?php echo htmlspecialchars(bakery_t('portal.no_orders_day', ['day' => $label]), ENT_QUOTES, 'UTF-8'); ?>">

        <div class="day-header"><?php echo htmlspecialchars($label); ?></div>

        <?php if (empty($ordersByDay[$dow])): ?>

          <div class="empty-day"><?php echo htmlspecialchars(bakery_t('portal.no_orders_day', ['day' => $label])); ?></div>

        <?php else: ?>

          <?php foreach ($ordersByDay[$dow] as $order): ?>

            <div class="order-row" data-product-id="<?php echo (int)$order['product_id']; ?>" data-day="<?php echo $dow; ?>">

              <span class="product-name"><?php echo htmlspecialchars($order['product_name']); ?></span>

              <div class="qty-controls">

                <button type="button" class="qty-btn" data-delta="-1" aria-label="<?php bakery_te('portal.decrease'); ?>">−</button>

                <input type="number" class="qty-input" min="0" step="1" inputmode="numeric"
                       value="<?php echo (int)$order['quantity']; ?>"
                       aria-label="<?php bakery_te('portal.quantity'); ?>">

                <button type="button" class="qty-btn" data-delta="1" aria-label="<?php bakery_te('portal.increase'); ?>">+</button>

                <button type="button" class="qty-remove" aria-label="<?php bakery_te('portal.remove_item'); ?>">×</button>

              </div>

            </div>

          <?php endforeach; ?>

        <?php endif; ?>

        <div class="add-row">

          <select class="add-product-select" data-day="<?php echo $dow; ?>" aria-label="<?php bakery_te('portal.add_product'); ?>">

            <option value=""><?php bakery_te('portal.add_product'); ?></option>

            <?php foreach ($allProducts as $p): ?>

              <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>

            <?php endforeach; ?>

          </select>

        </div>

      </section>

    <?php endforeach; ?>

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

    window.__BAKERY_I18N__ = <?php echo json_encode([

        'saved' => bakery_t('portal.saved'),

        'network_error' => bakery_t('portal.network_error'),

        'save_failed' => bakery_t('portal.save_failed'),

        'could_not_pause' => bakery_t('portal.could_not_pause'),

        'could_not_resume' => bakery_t('portal.could_not_resume'),

    ], JSON_UNESCAPED_UNICODE); ?>;

  </script>

  <script src="<?php echo bakery_asset_href('includes/portal_orders.js'); ?>"></script>

  <?php require __DIR__ . '/includes/portal_nav.php'; ?>
</body>

</html>


