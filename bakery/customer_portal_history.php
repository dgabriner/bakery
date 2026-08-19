<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/portal_command_center.php';

$customer = bakery_portal_require_customer($db);
$customerId = (int)$customer['id'];

$startDate = trim((string)($_GET['start_date'] ?? ''));
$endDate = trim((string)($_GET['end_date'] ?? ''));
$productId = (int)($_GET['product_id'] ?? 0);
$query = trim((string)($_GET['q'] ?? ''));

$nextEditable = bakery_portal_cmd_next_delivery($db, $customerId);
$reorderTargetDate = $nextEditable['date'] ?? date('Y-m-d', strtotime('+7 days'));

$history = bakery_portal_cmd_history_search($db, $customerId, [
    'start_date' => $startDate,
    'end_date' => $endDate,
    'product_id' => $productId,
    'q' => $query,
    'limit' => 40,
]);
$startDate = $history['start_date'];
$endDate = $history['end_date'];
$historyError = $history['error'] ?? '';
$productOptions = bakery_portal_cmd_history_product_options($db, $customerId);

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

$page_title = bakery_t('page.portal_history');
$currentLocale = bakery_locale();
$portalActivePage = 'history';
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

  <main class="container container-wide">
    <h1 class="section-title"><?php bakery_te('portal.order_history'); ?></h1>
    <p class="delivery-card-summary"><?php bakery_te('portal.history_intro'); ?></p>

    <form class="filters" method="get" action="customer_portal_history.php">
      <div>
        <label for="start_date"><?php bakery_te('portal.filter_from'); ?></label>
        <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
      </div>
      <div>
        <label for="end_date"><?php bakery_te('portal.filter_to'); ?></label>
        <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
      </div>
      <div>
        <label for="product_id"><?php bakery_te('portal.filter_product'); ?></label>
        <select id="product_id" name="product_id">
          <option value=""><?php bakery_te('portal.all_products'); ?></option>
          <?php foreach ($productOptions as $product): ?>
            <option value="<?php echo (int)$product['id']; ?>"<?php echo $productId === (int)$product['id'] ? ' selected' : ''; ?>>
              <?php echo htmlspecialchars($product['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="q"><?php bakery_te('portal.filter_search'); ?></label>
        <input type="search" id="q" name="q" value="<?php echo htmlspecialchars($query); ?>" placeholder="<?php echo htmlspecialchars(bakery_t('portal.search_placeholder')); ?>">
      </div>
      <div style="align-self:end;">
        <button type="submit" class="btn"><?php bakery_te('portal.apply_filters'); ?></button>
      </div>
    </form>

    <?php if ($historyError !== ''): ?>
      <p class="empty-state"><?php echo htmlspecialchars($historyError); ?></p>
    <?php elseif (!$history['rows']): ?>
      <p class="empty-state"><?php bakery_te('portal.no_history'); ?></p>
    <?php else: ?>
      <p class="delivery-card-summary">
        <?php echo htmlspecialchars(bakery_t('portal.history_showing', [
            'shown' => count($history['rows']),
            'total' => (int)$history['total'],
        ])); ?>
      </p>
      <?php foreach ($history['rows'] as $row): ?>
        <article class="history-row">
          <div class="history-row-head">
            <div>
              <h2 class="delivery-card-date"><?php echo htmlspecialchars($row['date_label']); ?></h2>
              <div class="history-meta">
                <span><?php echo htmlspecialchars(bakery_t('portal.ordered_units', ['count' => (int)$row['ordered_units']])); ?></span>
                <span><?php echo htmlspecialchars(bakery_t('portal.delivered_units', ['count' => (int)$row['delivered_units']])); ?></span>
                <?php if ((int)$row['variance'] !== 0): ?>
                  <span><?php echo htmlspecialchars(bakery_t('portal.variance_units', ['count' => (int)$row['variance']])); ?></span>
                <?php endif; ?>
              </div>
            </div>
            <span class="badge <?php echo bakery_portal_status_badge_class($row['status']['tone']); ?>">
              <?php echo htmlspecialchars($row['status']['label']); ?>
            </span>
          </div>
          <?php if (!empty($row['lines'])): ?>
            <ul class="line-list">
              <?php foreach (array_slice($row['lines'], 0, 5) as $line): ?>
                <li>
                  <span><?php echo htmlspecialchars($line['product_name']); ?></span>
                  <span class="line-qty">
                    <?php
                      $ordered = (int)$line['quantity'];
                      $delivered = $line['delivered_quantity'];
                      echo $delivered !== null ? (int)$delivered . ' / ' . $ordered : (string)$ordered;
                    ?>
                  </span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <div class="delivery-card-actions">
            <a class="btn btn-secondary" href="customer_portal_delivery.php?id=<?php echo (int)$row['daily_order_id']; ?>">
              <?php bakery_te('portal.view_delivery'); ?>
            </a>
            <?php if (!empty($row['has_proof'])): ?>
              <a class="btn btn-secondary" href="customer_portal_delivery.php?id=<?php echo (int)$row['daily_order_id']; ?>#proof">
                <?php bakery_te('portal.view_proof'); ?>
              </a>
            <?php endif; ?>
            <?php if (!empty($row['invoice_number']) && !empty($row['delivery_confirmed_at'])): ?>
              <a class="btn btn-secondary" href="customer_invoice.php?daily_order_id=<?php echo (int)$row['daily_order_id']; ?>">
                <?php echo htmlspecialchars($row['invoice_number']); ?>
              </a>
            <?php endif; ?>
            <a class="btn btn-secondary" href="customer_portal_delivery.php?date=<?php echo urlencode($reorderTargetDate); ?>&amp;reorder_from=<?php echo (int)$row['daily_order_id']; ?>">
              <?php bakery_te('portal.reorder'); ?>
            </a>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>
  <?php require __DIR__ . '/includes/portal_nav.php'; ?>
</body>
</html>
