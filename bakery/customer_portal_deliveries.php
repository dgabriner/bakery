<?php
/**
 * Customer portal — delivery history hub and today's status card.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/customer_delivery.php';

$customer = bakery_portal_customer($db);
$customerId = (int)$customer['id'];

$featured = bakery_customer_delivery_featured($db, $customerId);
$recent = bakery_customer_delivery_recent($db, $customerId, 30);

$page_title = bakery_t('delivery.hub_title');
$currentLocale = bakery_locale();
$portalActivePage = 'deliveries';
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
    .featured-card { background: linear-gradient(135deg, #fff 0%, #faf6f1 100%); border: 1px solid var(--border); border-radius: 14px; margin-bottom: 22px; padding: 20px; }
    .featured-card .date { color: var(--muted); font-size: .85rem; margin-bottom: 4px; }
    .status-badge { border-radius: 999px; display: inline-block; font-size: .78rem; font-weight: 600; letter-spacing: .02em; padding: 5px 12px; text-transform: uppercase; }
    .status-badge--confirmed { background: #eef2ff; color: #3730a3; }
    .status-badge--preparing { background: #fef3c7; color: #92400e; }
    .status-badge--out_for_delivery { background: #dbeafe; color: #1e40af; }
    .status-badge--delivered { background: #e8f5ee; color: var(--green); }
    .status-badge--skipped { background: #fde8e8; color: #9b332c; }
    .progress-note { font-size: .9rem; font-weight: 600; margin-top: 8px; }
    .history-row { align-items: center; border-bottom: 1px solid var(--border); display: flex; gap: 12px; justify-content: space-between; padding: 14px 16px; text-decoration: none; color: inherit; }
    .history-row:last-child { border-bottom: 0; }
    .history-row:hover { background: #faf6f1; }
  </style>
</head>
<body>
  <?php require __DIR__ . '/includes/portal_header.php'; ?>

  <main class="container">
    <p class="muted"><?php bakery_te('delivery.hub_welcome'); ?></p>

    <?php if ($featured): ?>
      <section class="featured-card">
        <div class="date"><?php echo htmlspecialchars($featured['date_label']); ?></div>
        <span class="status-badge status-badge--<?php echo htmlspecialchars($featured['status']['key']); ?>">
          <?php echo htmlspecialchars($featured['status']['label']); ?>
        </span>
        <p class="featured-message"><?php echo htmlspecialchars($featured['status']['message']); ?></p>
        <?php if (!empty($featured['progress'])): ?>
          <p class="progress-note"><?php echo htmlspecialchars($featured['progress']); ?></p>
        <?php endif; ?>
        <a class="btn" href="<?php echo htmlspecialchars($featured['detail_url']); ?>"><?php bakery_te('delivery.view_delivery'); ?></a>
      </section>
    <?php endif; ?>

    <section class="card">
      <h2><?php bakery_te('delivery.recent_heading'); ?></h2>
      <?php if (empty($recent)): ?>
        <p class="muted"><?php bakery_te('delivery.no_history'); ?></p>
      <?php else: ?>
        <?php foreach ($recent as $entry): ?>
          <a class="history-row" href="<?php echo htmlspecialchars($entry['detail_url']); ?>">
            <span><?php echo htmlspecialchars($entry['date_label']); ?></span>
            <span class="status-badge status-badge--<?php echo htmlspecialchars($entry['status']['key']); ?>">
              <?php echo htmlspecialchars($entry['status']['label']); ?>
            </span>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  </main>
  <?php require __DIR__ . '/includes/portal_nav.php'; ?>
</body>
</html>
