<?php
/** Route Summary — photo-first day review for the Route Manager. */
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/route_summary.php';

$user = bakery_current_user();
if ($user && bakery_is_driver_route_role($user['role_slug'] ?? '')) {
    header('Location: ' . BASE_URL . 'driver.php');
    exit;
}

$today = date('Y-m-d');
$selectedDate = route_manager_parse_date($_GET['date'] ?? $today, $today);
$filter = bakery_route_summary_parse_filter($_GET['filter'] ?? 'all');
$requestedDriverId = max(0, (int)($_GET['driver_id'] ?? 0));

$driversOnDate = bakery_route_summary_drivers_for_date($db, $selectedDate);
$driverIdsOnDate = array_map(static function ($driver) {
    return (int)$driver['id'];
}, $driversOnDate);
$selectedDriverId = in_array($requestedDriverId, $driverIdsOnDate, true) ? $requestedDriverId : 0;
$loadDriverIds = $selectedDriverId > 0 ? [$selectedDriverId] : [];
$day = bakery_route_summary_load($db, $selectedDate, $loadDriverIds, $filter);
$stats = $day['stats'];

$prevDate = (new DateTimeImmutable($selectedDate))->modify('-1 day')->format('Y-m-d');
$nextDate = (new DateTimeImmutable($selectedDate))->modify('+1 day')->format('Y-m-d');
$dateObject = new DateTimeImmutable($selectedDate);
$dateLabel = function_exists('bakery_localized_date_label')
    ? bakery_localized_date_label($dateObject, true)
    : $dateObject->format('l, F j, Y');

$page_title = bakery_t('page.route_summary');
require_once 'includes/header.php';
require_once 'includes/nav.php';
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/route_summary.css'); ?>">
<main class="route-summary" data-date="<?php echo htmlspecialchars($selectedDate); ?>">
  <header class="rs-header">
    <div class="rs-header__copy">
      <p class="rs-eyebrow"><?php bakery_te('route_summary.eyebrow'); ?></p>
      <h1><?php bakery_te('page.route_summary'); ?></h1>
      <p class="rs-subtitle"><?php bakery_te('route_summary.subtitle'); ?></p>
    </div>
    <div class="rs-header__actions">
      <a class="sf-btn sf-btn--outline" href="<?php echo htmlspecialchars(BASE_URL . 'route_manager.php?date=' . urlencode($selectedDate)); ?>"><?php bakery_te('route_summary.board_link'); ?></a>
      <a class="sf-btn sf-btn--outline" href="<?php echo htmlspecialchars(BASE_URL . 'route_closeout.php?date=' . urlencode($selectedDate)); ?>"><?php bakery_te('route_summary.closeout_link'); ?></a>
    </div>
  </header>

  <form class="rs-toolbar" method="get" action="route_summary.php">
    <div class="rs-date-nav" role="group" aria-label="<?php echo htmlspecialchars(bakery_t('route_summary.date')); ?>">
      <a class="sf-btn sf-btn--outline sf-btn--sm" href="<?php echo htmlspecialchars(bakery_route_summary_query($prevDate, $selectedDriverId, $filter)); ?>"><?php bakery_te('route_summary.prev_day'); ?></a>
      <label class="rs-field">
        <span><?php bakery_te('route_summary.date'); ?></span>
        <input type="date" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>" onchange="this.form.submit()">
      </label>
      <a class="sf-btn sf-btn--outline sf-btn--sm" href="<?php echo htmlspecialchars(bakery_route_summary_query($nextDate, $selectedDriverId, $filter)); ?>"><?php bakery_te('route_summary.next_day'); ?></a>
      <a class="sf-btn sf-btn--quiet sf-btn--sm" href="<?php echo htmlspecialchars(bakery_route_summary_query($today, $selectedDriverId, $filter)); ?>"><?php bakery_te('route_summary.today'); ?></a>
    </div>
    <label class="rs-field">
      <span><?php bakery_te('route_summary.driver'); ?></span>
      <select name="driver_id" onchange="this.form.submit()">
        <option value="0"><?php bakery_te('route_summary.all_drivers'); ?></option>
        <?php foreach ($driversOnDate as $driver): ?>
          <option value="<?php echo (int)$driver['id']; ?>"<?php echo (int)$driver['id'] === $selectedDriverId ? ' selected' : ''; ?>>
            <?php echo htmlspecialchars((string)$driver['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if ($filter !== 'all'): ?>
      <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
    <?php endif; ?>
    <noscript><button class="sf-btn sf-btn--primary sf-btn--sm" type="submit"><?php bakery_te('route_summary.view'); ?></button></noscript>
  </form>

  <p class="rs-context"><strong><?php echo htmlspecialchars($dateLabel); ?></strong></p>

  <section class="rs-stats" aria-label="<?php echo htmlspecialchars(bakery_t('route_summary.day_totals')); ?>">
    <div><span><?php bakery_te('route_summary.stat_stops'); ?></span><strong><?php echo (int)$stats['stops']; ?></strong><small><?php echo (int)$stats['delivered']; ?> / <?php echo (int)$stats['stops']; ?> <?php bakery_te('route_summary.stat_delivered'); ?></small></div>
    <div><span><?php bakery_te('route_summary.stat_sold'); ?></span><strong><?php echo htmlspecialchars(bakery_route_summary_format_money((float)$stats['sold'])); ?></strong><small><?php echo (int)$stats['pieces']; ?> <?php bakery_te('route_summary.stat_pieces'); ?></small></div>
    <div><span><?php bakery_te('route_summary.stat_photos'); ?></span><strong><?php echo (int)$stats['photo_count']; ?></strong><small><?php echo (int)$stats['with_photos']; ?> <?php bakery_te('route_summary.stops_with_photos'); ?></small></div>
    <div<?php echo (int)$stats['missing_photos'] > 0 ? ' class="is-warn"' : ''; ?>><span><?php bakery_te('route_summary.stat_missing_photos'); ?></span><strong><?php echo (int)$stats['missing_photos']; ?></strong><small><?php bakery_te('route_summary.missing_help'); ?></small></div>
  </section>

  <nav class="rs-filters" aria-label="<?php echo htmlspecialchars(bakery_t('route_summary.filter_label')); ?>">
    <?php foreach (bakery_route_summary_filters() as $filterKey => $filterLabelKey): ?>
      <a class="rs-filter<?php echo $filter === $filterKey ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars(bakery_route_summary_query($selectedDate, $selectedDriverId, $filterKey)); ?>"<?php echo $filter === $filterKey ? ' aria-current="page"' : ''; ?>><?php bakery_te($filterLabelKey); ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$day['photos_available']): ?>
    <div class="rs-note" role="status"><?php bakery_te('route_summary.photos_unavailable'); ?></div>
  <?php endif; ?>

  <?php if (!$day['has_stops']): ?>
    <section class="rs-empty">
      <h2><?php bakery_te('route_summary.empty_title'); ?></h2>
      <p><?php bakery_te('route_summary.empty_body'); ?></p>
    </section>
  <?php elseif ((int)$day['visible_stops'] === 0): ?>
    <section class="rs-empty">
      <h2><?php bakery_te('route_summary.no_matches'); ?></h2>
      <p><?php bakery_te('route_summary.no_matches_body'); ?></p>
    </section>
  <?php else: ?>
    <?php foreach ($day['drivers'] as $driver): ?>
      <section class="rs-driver" aria-labelledby="rs-driver-<?php echo (int)$driver['id']; ?>">
        <header class="rs-driver__head">
          <div>
            <h2 id="rs-driver-<?php echo (int)$driver['id']; ?>"><?php echo htmlspecialchars((string)$driver['name']); ?></h2>
            <p><?php echo (int)$driver['stop_count']; ?> <?php bakery_te('route_summary.stops'); ?> · <?php echo htmlspecialchars(bakery_route_summary_format_money((float)$driver['sold'])); ?> · <?php echo (int)$driver['photo_count']; ?> <?php bakery_te('route_summary.photos'); ?><?php if ((int)$driver['missing_photos'] > 0): ?> · <?php echo (int)$driver['missing_photos']; ?> <?php bakery_te('route_summary.missing_short'); ?><?php endif; ?></p>
          </div>
        </header>
        <div class="rs-grid">
          <?php foreach ($driver['stops'] as $stop): ?>
            <?php
              $hero = $stop['hero_photo'];
              $status = (string)$stop['delivery_status'];
              $payment = (string)$stop['payment_collection'];
              $photoPayload = [];
              foreach ($stop['photos'] as $photo) {
                  $photoPayload[] = [
                      'url' => (string)$photo['url'],
                      'fallback_url' => (string)($photo['fallback_url'] ?? ''),
                      'photo_type' => bakery_t(bakery_route_summary_photo_type_key((string)$photo['photo_type'])),
                      'created_at' => bakery_route_summary_format_time($photo['created_at'] ?? null),
                  ];
              }
              $cardLabel = $stop['customer_name'] . ' — ' . bakery_route_summary_format_money((float)$stop['sold_amount']);
            ?>
            <article class="rs-card status-<?php echo htmlspecialchars($status); ?><?php echo $hero ? ' has-photo' : ' no-photo'; ?>" data-stop-id="<?php echo (int)$stop['daily_order_id']; ?>">
              <button type="button" class="rs-card__photo" data-photos="<?php echo htmlspecialchars(json_encode($photoPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>" data-customer="<?php echo htmlspecialchars((string)$stop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($hero ? bakery_t('route_summary.view_photos') . ': ' . $cardLabel : $cardLabel); ?>">
                <?php if ($hero): ?>
                  <img src="<?php echo htmlspecialchars((string)$hero['url']); ?>" alt="" loading="lazy" data-fallback="<?php echo htmlspecialchars((string)($hero['fallback_url'] ?? '')); ?>" onerror="if(this.dataset.fallback && !this.dataset.tried){this.dataset.tried='1';this.src=this.dataset.fallback;}">
                  <span class="rs-card__photo-meta">
                    <span>#<?php echo (int)$stop['route_order']; ?></span>
                    <span><?php bakery_te(bakery_route_summary_photo_type_key((string)$hero['photo_type'])); ?></span>
                    <?php if ((int)$stop['photo_count'] > 1): ?>
                      <span><?php echo (int)$stop['photo_count']; ?> <?php bakery_te('route_summary.photos'); ?></span>
                    <?php endif; ?>
                  </span>
                <?php else: ?>
                  <span class="rs-card__placeholder">
                    <span class="rs-card__stop-num">#<?php echo (int)$stop['route_order']; ?></span>
                    <strong><?php bakery_te('route_summary.no_photo'); ?></strong>
                    <em><?php bakery_te(bakery_route_summary_status_key($status)); ?></em>
                  </span>
                <?php endif; ?>
              </button>
              <div class="rs-card__body">
                <div class="rs-card__title">
                  <a class="rs-card__customer" href="<?php echo htmlspecialchars(BASE_URL . 'customer_record.php?customer_id=' . (int)$stop['customer_id'] . '&date=' . urlencode($selectedDate)); ?>"><?php echo htmlspecialchars((string)$stop['customer_name']); ?></a>
                  <span class="rs-status rs-status--<?php echo htmlspecialchars($status); ?>"><?php bakery_te(bakery_route_summary_status_key($status)); ?></span>
                </div>
                <p class="rs-card__sold"><?php echo htmlspecialchars(bakery_route_summary_format_money((float)$stop['sold_amount'])); ?><?php if ((int)$stop['pieces'] > 0): ?> <span>· <?php echo (int)$stop['pieces']; ?> <?php bakery_te('route_summary.stat_pieces'); ?></span><?php endif; ?></p>
                <p class="rs-card__meta">
                  <span><?php echo htmlspecialchars((string)$stop['driver_name']); ?></span>
                  <?php if ($stop['show_time'] !== ''): ?><span><?php echo htmlspecialchars($stop['show_time']); ?></span><?php endif; ?>
                  <span><?php bakery_te($payment === 'signature' ? 'route_summary.signature' : 'route_summary.cod'); ?></span>
                  <?php if ((string)$stop['zone'] !== '' && (string)$stop['zone'] !== 'No Zone'): ?><span><?php echo htmlspecialchars((string)$stop['zone']); ?></span><?php endif; ?>
                </p>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>
</main>

<div id="rsLightbox" class="rs-lightbox" hidden>
  <div class="rs-lightbox__backdrop" data-rs-close></div>
  <div class="rs-lightbox__sheet" role="dialog" aria-modal="true" aria-labelledby="rsLightboxTitle">
    <button type="button" class="rs-lightbox__close" data-rs-close aria-label="<?php echo htmlspecialchars(bakery_t('route_summary.lightbox_close')); ?>">&times;</button>
    <img id="rsLightboxImage" alt="">
    <div class="rs-lightbox__caption">
      <h3 id="rsLightboxTitle"></h3>
      <p id="rsLightboxMeta"></p>
    </div>
    <button type="button" class="rs-lightbox__nav rs-lightbox__nav--prev" id="rsLightboxPrev" aria-label="<?php echo htmlspecialchars(bakery_t('ui.previous')); ?>">‹</button>
    <button type="button" class="rs-lightbox__nav rs-lightbox__nav--next" id="rsLightboxNext" aria-label="<?php echo htmlspecialchars(bakery_t('ui.next')); ?>">›</button>
  </div>
</div>
<script src="<?php echo bakery_asset_href('includes/route_summary.js'); ?>"></script>
<?php require_once 'includes/footer.php'; ?>
