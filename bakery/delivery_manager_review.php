<?php
/** Read-only delivery review. Managers and administrators only. */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/delivery_manager_review.php';

$user = bakery_current_user();
$reviewAccess = bakery_delivery_manager_review_access_status($user);
if ($reviewAccess === 302) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}
if ($reviewAccess !== 200) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit;
}

$today = (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
$range = bakery_delivery_manager_review_parse_range(
    $today,
    trim((string)($_GET['from'] ?? '')),
    trim((string)($_GET['to'] ?? ''))
);
$view = bakery_delivery_manager_review_parse_view((string)($_GET['view'] ?? 'drivers'));
$filter = bakery_delivery_manager_review_parse_filter((string)($_GET['filter'] ?? 'all'));
$query = bakery_delivery_manager_review_parse_query((string)($_GET['q'] ?? ''));
$driverId = bakery_delivery_manager_review_positive_id($_GET['driver'] ?? 0);
$customerId = bakery_delivery_manager_review_positive_id($_GET['customer'] ?? 0);
$report = bakery_delivery_manager_review_load($db, (string)$range['from'], (string)$range['to']);
$from = (string)$report['from'];
$to = (string)$report['to'];
$totals = $report['totals'];
$deliveredTotal = (int)$totals['delivered'];
$afterTotal = (int)$totals['with_after'];
$photoPercent = $deliveredTotal > 0 ? (int)round(($afterTotal / $deliveredTotal) * 100) : 0;

$selectedDriver = null;
foreach ($report['drivers'] as $driver) {
    if ((int)$driver['driver_id'] === $driverId) {
        $selectedDriver = $driver;
        break;
    }
}
$selectedCustomer = null;
foreach ($report['customers'] as $customer) {
    if ((int)$customer['customer_id'] === $customerId) {
        $selectedCustomer = $customer;
        break;
    }
}

$searchedCustomers = [];
foreach ($report['customers'] as $customer) {
    if (bakery_delivery_manager_review_customer_search_match($customer, $query)) {
        $searchedCustomers[] = $customer;
    }
}
$filterCounts = bakery_delivery_manager_review_filter_counts($searchedCustomers);
$visibleCustomers = [];
foreach ($searchedCustomers as $customer) {
    if (bakery_delivery_manager_review_customer_matches($customer, $filter)) {
        $visibleCustomers[] = $customer;
    }
}
$needs = [];
foreach ($report['needs'] as $need) {
    if ($selectedDriver !== null && (int)$need['driver_id'] !== (int)$selectedDriver['driver_id']) {
        continue;
    }
    $needs[] = $need;
}

$context = [
    'view' => $view,
    'from' => $from,
    'to' => $to,
    'driver' => $driverId,
    'customer' => $customerId,
    'filter' => $filter,
    'q' => $query,
];
$base = defined('BASE_URL') ? (string)BASE_URL : '';

$page_title = bakery_t('page.delivery_manager_review');
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';

$h = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};
$clockLabel = static function ($value, bool $ready): string {
    if ($value === null) {
        return bakery_delivery_manager_review_yes_no(null, $ready);
    }
    return bakery_delivery_manager_review_yes_no((bool)$value, $ready);
};
$timeLabel = static function ($value) use ($h): string {
    $clock = bakery_route_photo_journey_format_clock($value === null ? null : (string)$value);
    if ($clock === '') {
        return $h(bakery_t('delivery_review.not_recorded'));
    }
    return $h($clock);
};
$photoCell = static function (string $url, bool $hasAfter) use ($h): string {
    if ($url === '' || strpos($url, 'driver_photo.php') === false || strpos($url, 'uploads/driver_photos') !== false) {
        return $h(bakery_t('delivery_review.no_photo'));
    }
    $img = '<img class="delivery-review__thumb" src="' . $h($url) . '" alt="' . $h(bakery_t('delivery_review.photo_alt')) . '">';
    if (!$hasAfter) {
        return $img . '<span class="delivery-review__photo-note">' . $h(bakery_t('delivery_review.no_photo')) . '</span>';
    }
    return $img;
};
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/delivery_manager_review.css'); ?>">
<main class="delivery-review">
  <header class="delivery-review__header">
    <h1><?php bakery_te('page.delivery_manager_review'); ?></h1>
    <p><?php bakery_te('delivery_review.subtitle'); ?></p>
    <p class="delivery-review__note"><?php bakery_te('delivery_review.legend'); ?></p>
  </header>

  <form class="delivery-review__range" method="get" action="delivery_manager_review.php">
    <input type="hidden" name="view" value="<?php echo $h($view); ?>">
    <?php if ($view === 'drivers' && $driverId > 0): ?>
      <input type="hidden" name="driver" value="<?php echo $driverId; ?>">
    <?php endif; ?>
    <?php if ($view === 'customers' && $customerId > 0): ?>
      <input type="hidden" name="customer" value="<?php echo $customerId; ?>">
    <?php endif; ?>
    <?php if ($view === 'customers' && $filter !== 'all'): ?>
      <input type="hidden" name="filter" value="<?php echo $h($filter); ?>">
    <?php endif; ?>
    <label>
      <span><?php bakery_te('delivery_review.from'); ?></span>
      <input type="date" name="from" value="<?php echo $h($from); ?>" required>
    </label>
    <label>
      <span><?php bakery_te('delivery_review.to'); ?></span>
      <input type="date" name="to" value="<?php echo $h($to); ?>" required>
    </label>
    <?php if ($view === 'customers'): ?>
      <label>
        <span><?php bakery_te('delivery_review.search'); ?></span>
        <input type="search" name="q" value="<?php echo $h($query); ?>" placeholder="<?php echo $h(bakery_t('delivery_review.search_placeholder')); ?>">
      </label>
    <?php endif; ?>
    <button class="sf-btn sf-btn--primary sf-btn--sm" type="submit"><?php bakery_te('delivery_review.show'); ?></button>
  </form>
  <?php if (!empty($range['clamped'])): ?>
    <p class="delivery-review__note"><?php echo $h(bakery_t('delivery_review.range_clamped', ['days' => BAKERY_ROUTE_HEALTH_MAX_DAYS])); ?></p>
  <?php endif; ?>

  <nav class="delivery-review__tabs" aria-label="<?php echo $h(bakery_t('delivery_review.tabs_label')); ?>">
    <?php
      $driverTab = bakery_delivery_manager_review_href(['view' => 'drivers', 'from' => $from, 'to' => $to, 'driver' => $driverId]);
      $customerTab = bakery_delivery_manager_review_href(['view' => 'customers', 'from' => $from, 'to' => $to, 'customer' => $customerId, 'filter' => $filter, 'q' => $query]);
    ?>
    <a class="delivery-review__tab<?php echo $view === 'drivers' ? ' is-active' : ''; ?>" href="<?php echo $h($driverTab); ?>"<?php echo $view === 'drivers' ? ' aria-current="page"' : ''; ?>><?php bakery_te('delivery_review.tab_drivers'); ?></a>
    <a class="delivery-review__tab<?php echo $view === 'customers' ? ' is-active' : ''; ?>" href="<?php echo $h($customerTab); ?>"<?php echo $view === 'customers' ? ' aria-current="page"' : ''; ?>><?php bakery_te('delivery_review.tab_customers'); ?></a>
  </nav>

  <ul class="delivery-review__stats">
    <li><strong><?php echo (int)$totals['routed']; ?></strong><span><?php bakery_te('delivery_review.stat_stops'); ?></span></li>
    <li><strong><?php echo $deliveredTotal; ?></strong><span><?php bakery_te('delivery_review.stat_delivered'); ?></span></li>
    <li><strong><?php echo (int)$totals['open']; ?></strong><span><?php bakery_te('delivery_review.stat_open'); ?></span></li>
    <li><strong><?php echo (int)$totals['failed']; ?></strong><span><?php bakery_te('delivery_review.stat_failed'); ?></span></li>
    <li><strong><?php echo (int)$totals['cancelled']; ?></strong><span><?php bakery_te('delivery_review.stat_cancelled'); ?></span></li>
    <li><strong><?php echo $afterTotal; ?></strong><span><?php bakery_te('delivery_review.stat_after'); ?></span></li>
  </ul>
  <p>
    <?php if ($deliveredTotal === 0): ?>
      <?php bakery_te('delivery_review.photo_rate_none'); ?>
    <?php else: ?>
      <?php echo $h(bakery_t('delivery_review.photo_rate', ['after' => $afterTotal, 'delivered' => $deliveredTotal, 'percent' => $photoPercent])); ?>
    <?php endif; ?>
  </p>
  <?php if (empty($report['clock_ready'])): ?>
    <p class="delivery-review__note"><?php bakery_te('delivery_review.clock_missing'); ?></p>
  <?php endif; ?>
  <?php if (empty($report['close_ready'])): ?>
    <p class="delivery-review__note"><?php bakery_te('delivery_review.close_missing'); ?></p>
  <?php endif; ?>
  <p>
    <?php if ((int)$totals['short_customers'] > 0): ?>
      <a href="<?php echo $h(bakery_delivery_manager_review_href(['view' => 'customers', 'from' => $from, 'to' => $to, 'filter' => 'short', 'q' => $query])); ?>"><?php echo $h(bakery_t('delivery_review.bridge_short', ['count' => (int)$totals['short_customers']])); ?></a>
    <?php else: ?>
      <?php bakery_te('delivery_review.bridge_short_none'); ?>
    <?php endif; ?>
  </p>
  <p>
    <?php if ((int)$totals['open'] + (int)$totals['failed'] > 0): ?>
      <a href="<?php echo $h(bakery_delivery_manager_review_href(['view' => 'drivers', 'from' => $from, 'to' => $to, 'driver' => $driverId])); ?>#delivery-review-needs"><?php echo $h(bakery_t('delivery_review.bridge_open', ['open' => (int)$totals['open'], 'failed' => (int)$totals['failed']])); ?></a>
    <?php else: ?>
      <?php bakery_te('delivery_review.bridge_open_none'); ?>
    <?php endif; ?>
  </p>

  <?php if ($view === 'drivers'): ?>
    <section class="delivery-review__section" aria-labelledby="delivery-review-drivers">
      <h2 id="delivery-review-drivers"><?php bakery_te('delivery_review.drivers_heading'); ?></h2>
      <?php if ($driverId > 0 && $selectedDriver === null): ?>
        <p class="delivery-review__note"><?php bakery_te('delivery_review.not_in_window'); ?></p>
      <?php endif; ?>
      <?php if ($report['drivers'] === []): ?>
        <p class="delivery-review__empty"><?php bakery_te('delivery_review.drivers_empty'); ?></p>
      <?php else: ?>
        <div class="delivery-review__cards">
          <?php foreach ($report['drivers'] as $driver): ?>
            <?php
              $cardHref = bakery_delivery_manager_review_href([
                  'view' => 'drivers',
                  'from' => $from,
                  'to' => $to,
                  'driver' => (int)$driver['driver_id'],
              ]);
              $current = $selectedDriver !== null && (int)$driver['driver_id'] === (int)$selectedDriver['driver_id'];
              $attention = (int)$driver['attention_score'] > 0;
            ?>
            <a class="delivery-review__card<?php echo $current ? ' is-current' : ''; ?><?php echo $attention ? ' is-attention' : ''; ?>" href="<?php echo $h($cardHref); ?>"<?php echo $current ? ' aria-current="true"' : ''; ?>>
              <h3><?php echo $h((string)$driver['driver_name']); ?></h3>
              <p><?php echo $h(bakery_t('delivery_review.driver_counts', [
                  'standing' => (int)$driver['standing_stops'],
                  'routed' => (int)$driver['stops'],
                  'delivered' => (int)$driver['delivered'],
              ])); ?></p>
              <p><?php echo $h(bakery_t('delivery_review.days_worked', ['count' => (int)$driver['days_worked']])); ?></p>
              <?php if ((int)$driver['quiet_days'] > 0): ?>
                <p><?php echo $h(bakery_t('delivery_review.quiet_days', ['count' => (int)$driver['quiet_days']])); ?></p>
              <?php endif; ?>
              <?php if (!empty($report['clock_ready'])): ?>
                <p><?php echo $h(bakery_t('delivery_review.clock_days', ['done' => (int)$driver['clocked_days'], 'days' => (int)$driver['listed_days']])); ?></p>
              <?php endif; ?>
              <?php if (!empty($report['close_ready'])): ?>
                <p><?php echo $h(bakery_t('delivery_review.close_days', ['done' => (int)$driver['closed_days'], 'days' => (int)$driver['listed_days']])); ?></p>
              <?php endif; ?>
              <ul>
                <?php foreach (bakery_delivery_manager_review_driver_attention($driver) as $line): ?>
                  <li><?php echo $h(bakery_t((string)$line['key'], $line['params'])); ?></li>
                <?php endforeach; ?>
              </ul>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <?php if ($selectedDriver !== null): ?>
      <section class="delivery-review__section" aria-labelledby="delivery-review-days">
        <h2 id="delivery-review-days"><?php echo $h((string)$selectedDriver['driver_name']); ?></h2>
        <p><a href="<?php echo $h(bakery_delivery_manager_review_href(['view' => 'drivers', 'from' => $from, 'to' => $to])); ?>"><?php bakery_te('delivery_review.all_drivers'); ?></a></p>
        <h3><?php bakery_te('delivery_review.day_title'); ?></h3>
        <div class="sf-table-wrap">
          <table class="sf-table sf-table--stack-sm">
            <thead>
              <tr>
                <th><?php bakery_te('delivery_review.col_date'); ?></th>
                <th><?php bakery_te('delivery_review.col_standing'); ?></th>
                <th><?php bakery_te('delivery_review.col_routed'); ?></th>
                <th><?php bakery_te('delivery_review.col_delivered'); ?></th>
                <th><?php bakery_te('delivery_review.col_open'); ?></th>
                <th><?php bakery_te('delivery_review.col_failed'); ?></th>
                <th><?php bakery_te('delivery_review.col_cancelled'); ?></th>
                <th><?php bakery_te('delivery_review.col_after'); ?></th>
                <th><?php bakery_te('delivery_review.col_arrival'); ?></th>
                <th><?php bakery_te('delivery_review.col_close'); ?></th>
                <th><?php bakery_te('delivery_review.col_clock'); ?></th>
                <th><?php bakery_te('delivery_review.col_route_closed'); ?></th>
                <th><?php bakery_te('delivery_review.col_links'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($selectedDriver['day_rows'] as $day): ?>
                <?php
                  $dayDate = (string)$day['date'];
                  $sheet = $base . 'driver_reports.php?' . http_build_query(['tab' => 'sheet', 'date' => $dayDate]);
                  $photos = $base . 'route_photo_journey.php?' . http_build_query(['date' => $dayDate, 'driver_id' => (int)$selectedDriver['driver_id']]);
                  $assign = $base . 'driver_assignment.php?' . http_build_query(['date' => $dayDate]);
                  $shortDay = (int)$day['standing_stops'] > (int)$day['stops'];
                ?>
                <tr class="<?php echo $shortDay ? 'is-problem' : ''; ?>">
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_date')); ?>"><?php echo $h(bakery_delivery_manager_review_display_date($dayDate)); ?></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_standing')); ?>"><?php echo (int)$day['standing_stops']; ?></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_routed')); ?>"><?php echo (int)$day['stops']; ?></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_delivered')); ?>"><?php echo (int)$day['delivered']; ?></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_open')); ?>"><?php echo (int)$day['open']; ?></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_failed')); ?>"><?php echo (int)$day['failed']; ?></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_cancelled')); ?>"><?php echo (int)$day['cancelled']; ?></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_after')); ?>"><?php echo (int)$day['delivered_with_after']; ?></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_arrival')); ?>"><?php echo $timeLabel($day['first_arrival']); ?></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_close')); ?>"><?php echo $timeLabel($day['last_close']); ?></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_clock')); ?>"><?php echo $h($clockLabel($day['clocked_in'], !empty($report['clock_ready']))); ?></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_route_closed')); ?>"><?php echo $h($clockLabel($day['route_closed'], !empty($report['close_ready']))); ?></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_links')); ?>">
                    <a href="<?php echo $h($sheet); ?>"><?php bakery_te('delivery_review.open_sheet'); ?></a>
                    <a href="<?php echo $h($photos); ?>"><?php bakery_te('delivery_review.open_photos'); ?></a>
                    <a href="<?php echo $h($assign); ?>"><?php bakery_te('delivery_review.open_assign'); ?></a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php foreach ($selectedDriver['day_rows'] as $day): ?>
          <?php if (($day['bulk_stores'] ?? []) === []) { continue; } ?>
          <p class="delivery-review__note"><?php echo $h(bakery_t('delivery_review.bulk_line', [
              'date' => bakery_delivery_manager_review_display_date((string)$day['date']),
              'stores' => implode(', ', $day['bulk_stores']),
          ])); ?></p>
        <?php endforeach; ?>
      </section>

      <section class="delivery-review__section" aria-labelledby="delivery-review-stops">
        <h2 id="delivery-review-stops"><?php bakery_te('delivery_review.stops_title'); ?></h2>
        <?php if ($selectedDriver['stop_rows'] === []): ?>
          <p class="delivery-review__empty"><?php bakery_te('delivery_review.stops_empty'); ?></p>
        <?php else: ?>
          <div class="sf-table-wrap">
            <table class="sf-table sf-table--stack-sm">
              <thead>
                <tr>
                  <th><?php bakery_te('delivery_review.col_date'); ?></th>
                  <th><?php bakery_te('delivery_review.col_store'); ?></th>
                  <th><?php bakery_te('delivery_review.col_status'); ?></th>
                  <th><?php bakery_te('delivery_review.col_arrival'); ?></th>
                  <th><?php bakery_te('delivery_review.col_close'); ?></th>
                  <th><?php bakery_te('delivery_review.col_photo'); ?></th>
                  <th><?php bakery_te('delivery_review.col_note'); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($selectedDriver['stop_rows'] as $stop): ?>
                  <?php
                    $problem = (string)$stop['status'] === 'failed'
                        || (string)$stop['status'] === 'open'
                        || ((string)$stop['status'] === 'delivered' && empty($stop['has_after']));
                    $storeHref = bakery_delivery_manager_review_href([
                        'view' => 'customers',
                        'from' => $from,
                        'to' => $to,
                        'customer' => (int)$stop['customer_id'],
                    ]);
                  ?>
                  <tr class="<?php echo $problem ? 'is-problem' : ''; ?>">
                    <td data-label="<?php echo $h(bakery_t('delivery_review.col_date')); ?>"><?php echo $h(bakery_delivery_manager_review_display_date((string)$stop['date'])); ?></td>
                    <td data-label="<?php echo $h(bakery_t('delivery_review.col_store')); ?>"><a href="<?php echo $h($storeHref); ?>"><?php echo $h((string)$stop['customer_name']); ?></a></td>
                    <td data-label="<?php echo $h(bakery_t('delivery_review.col_status')); ?>"><?php echo $h(bakery_t(bakery_delivery_manager_review_status_key((string)$stop['status']))); ?></td>
                    <td data-label="<?php echo $h(bakery_t('delivery_review.col_arrival')); ?>"><?php echo $timeLabel($stop['arrival_at']); ?></td>
                    <td data-label="<?php echo $h(bakery_t('delivery_review.col_close')); ?>"><?php echo $timeLabel($stop['closed_at']); ?></td>
                    <td data-label="<?php echo $h(bakery_t('delivery_review.col_photo')); ?>"><?php echo $photoCell((string)$stop['photo_url'], !empty($stop['has_after'])); ?></td>
                    <td data-label="<?php echo $h(bakery_t('delivery_review.col_note')); ?>"><?php echo $h((string)$stop['note'] !== '' ? (string)$stop['note'] : bakery_t('delivery_review.no_note')); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <section class="delivery-review__section" id="delivery-review-needs" aria-labelledby="delivery-review-needs-title">
      <h2 id="delivery-review-needs-title"><?php bakery_te('delivery_review.needs_title'); ?></h2>
      <?php if ($needs === []): ?>
        <p class="delivery-review__empty"><?php bakery_te('delivery_review.needs_empty'); ?></p>
      <?php else: ?>
        <p><?php echo $h(bakery_t('delivery_review.needs_intro', ['count' => count($needs)])); ?></p>
        <div class="sf-table-wrap">
          <table class="sf-table sf-table--stack-sm">
            <thead>
              <tr>
                <th><?php bakery_te('delivery_review.col_date'); ?></th>
                <th><?php bakery_te('delivery_review.col_driver'); ?></th>
                <th><?php bakery_te('delivery_review.col_store'); ?></th>
                <th><?php bakery_te('delivery_review.col_what'); ?></th>
                <th><?php bakery_te('delivery_review.col_photo'); ?></th>
                <th><?php bakery_te('delivery_review.col_note'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($needs as $need): ?>
                <?php
                  $storeHref = bakery_delivery_manager_review_href([
                      'view' => 'customers',
                      'from' => $from,
                      'to' => $to,
                      'customer' => (int)$need['customer_id'],
                  ]);
                  $driverHref = bakery_delivery_manager_review_href([
                      'view' => 'drivers',
                      'from' => $from,
                      'to' => $to,
                      'driver' => (int)$need['driver_id'],
                  ]);
                  $photoUrl = (string)$need['photo_url'];
                  $safePhoto = $photoUrl !== '' && strpos($photoUrl, 'driver_photo.php') !== false && strpos($photoUrl, 'uploads/driver_photos') === false;
                ?>
                <tr class="is-problem">
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_date')); ?>"><?php echo $h(bakery_delivery_manager_review_display_date((string)$need['date'])); ?></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_driver')); ?>"><a href="<?php echo $h($driverHref); ?>"><?php echo $h((string)$need['driver_name']); ?></a></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_store')); ?>"><a href="<?php echo $h($storeHref); ?>"><?php echo $h((string)$need['customer_name']); ?></a></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_what')); ?>"><?php echo $h(bakery_t(bakery_delivery_manager_review_why_key((string)$need['reason']))); ?></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_photo')); ?>">
                    <?php if ($safePhoto): ?>
                      <a href="<?php echo $h($photoUrl); ?>"><?php bakery_te('delivery_review.photo_link'); ?></a>
                    <?php else: ?>
                      <?php bakery_te('delivery_review.no_photo'); ?>
                    <?php endif; ?>
                  </td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_note')); ?>"><?php echo $h((string)$need['note'] !== '' ? (string)$need['note'] : bakery_t('delivery_review.no_note')); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  <?php else: ?>
    <section class="delivery-review__section" aria-labelledby="delivery-review-stores">
      <h2 id="delivery-review-stores"><?php bakery_te('delivery_review.tab_customers'); ?></h2>
      <p><?php bakery_te('delivery_review.customers_intro'); ?></p>
      <nav class="delivery-review__chips" aria-label="<?php echo $h(bakery_t('delivery_review.filters_label')); ?>">
        <?php
          $chips = [
              'all' => 'delivery_review.filter_all',
              'short' => 'delivery_review.filter_short',
              'quiet' => 'delivery_review.filter_quiet',
              'missed' => 'delivery_review.filter_missed',
              'off_driver' => 'delivery_review.filter_off_driver',
              'no_standing' => 'delivery_review.filter_no_standing',
              'extra' => 'delivery_review.filter_extra',
          ];
        ?>
        <?php foreach ($chips as $chip => $labelKey): ?>
          <?php
            $chipHref = bakery_delivery_manager_review_href([
                'view' => 'customers',
                'from' => $from,
                'to' => $to,
                'filter' => $chip,
                'q' => $query,
                'customer' => $customerId,
            ]);
            $chipOn = $filter === $chip;
          ?>
          <a class="delivery-review__chip<?php echo $chipOn ? ' is-active' : ''; ?>" href="<?php echo $h($chipHref); ?>"<?php echo $chipOn ? ' aria-current="true"' : ''; ?>><?php echo $h(bakery_t($labelKey, ['count' => (int)$filterCounts[$chip]])); ?></a>
        <?php endforeach; ?>
      </nav>

      <?php if ($customerId > 0 && $selectedCustomer === null): ?>
        <p class="delivery-review__note"><?php bakery_te('delivery_review.not_in_window'); ?></p>
      <?php endif; ?>

      <?php if ($selectedCustomer !== null): ?>
        <?php
          $reading = $selectedCustomer['reading'];
          $record = $base . 'customer_record.php?' . http_build_query(['customer_id' => (int)$selectedCustomer['customer_id']]);
        ?>
        <article class="delivery-review__store" id="store-<?php echo (int)$selectedCustomer['customer_id']; ?>">
          <h3><?php echo $h((string)$selectedCustomer['name']); ?><?php if (empty($selectedCustomer['active'])): ?> <span class="delivery-review__flag"><?php bakery_te('delivery_review.inactive'); ?></span><?php endif; ?></h3>
          <p><?php echo $h((string)$selectedCustomer['zone'] !== '' ? (string)$selectedCustomer['zone'] : bakery_t('delivery_review.no_zone')); ?></p>
          <p><?php echo $h(bakery_delivery_manager_review_pattern_label($selectedCustomer['pattern'])); ?></p>
          <p class="delivery-review__reading"><?php echo $h(bakery_t((string)$reading['key'], $reading['params'])); ?></p>
          <?php if ((int)$selectedCustomer['off_count'] > 0): ?>
            <p><?php echo $h(bakery_t('delivery_review.reading_off_driver', ['count' => (int)$selectedCustomer['off_count']])); ?></p>
          <?php endif; ?>
          <?php if ((int)$selectedCustomer['missing_after'] > 0): ?>
            <p><?php echo $h(bakery_t('delivery_review.reading_missing_after', ['count' => (int)$selectedCustomer['missing_after']])); ?></p>
          <?php endif; ?>
          <p>
            <a href="<?php echo $h(bakery_delivery_manager_review_href(['view' => 'customers', 'from' => $from, 'to' => $to, 'filter' => $filter, 'q' => $query])); ?>"><?php bakery_te('delivery_review.all_customers'); ?></a>
            <a href="<?php echo $h($record); ?>"><?php bakery_te('delivery_review.open_record'); ?></a>
            <a href="<?php echo $h($base . 'standing_routes.php'); ?>"><?php bakery_te('delivery_review.open_standing'); ?></a>
          </p>
          <h3><?php bakery_te('delivery_review.visit_title'); ?></h3>
          <div class="sf-table-wrap">
            <table class="sf-table sf-table--stack-sm">
              <thead>
                <tr>
                  <th><?php bakery_te('delivery_review.col_date'); ?></th>
                  <th><?php bakery_te('delivery_review.col_standing_driver'); ?></th>
                  <th><?php bakery_te('delivery_review.col_actual_driver'); ?></th>
                  <th><?php bakery_te('delivery_review.col_status'); ?></th>
                  <th><?php bakery_te('delivery_review.col_photo'); ?></th>
                  <th><?php bakery_te('delivery_review.col_note'); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($selectedCustomer['visits'] as $visit): ?>
                  <?php
                    $standingNames = [];
                    $standingDriverId = 0;
                    foreach ($visit['standing_drivers'] as $standingDriver) {
                        $standingNames[] = (string)$standingDriver['driver_name'];
                        if ($standingDriverId === 0) {
                            $standingDriverId = (int)$standingDriver['driver_id'];
                        }
                    }
                    $actualId = (int)$visit['actual_driver_id'];
                    $actualHref = $actualId > 0
                        ? bakery_delivery_manager_review_href(['view' => 'drivers', 'from' => $from, 'to' => $to, 'driver' => $actualId])
                        : '';
                    $standingHref = $standingDriverId > 0
                        ? bakery_delivery_manager_review_href(['view' => 'drivers', 'from' => $from, 'to' => $to, 'driver' => $standingDriverId])
                        : '';
                    $marks = [];
                    if (!empty($visit['off_driver'])) {
                        $marks[] = bakery_t('delivery_review.off_driver_mark');
                    }
                    if (!empty($visit['extra'])) {
                        $marks[] = bakery_t('delivery_review.extra_mark');
                    }
                    $statusText = bakery_t(bakery_delivery_manager_review_status_key((string)$visit['status']));
                    if ($marks !== []) {
                        $statusText .= ' (' . implode(', ', $marks) . ')';
                    }
                    $problem = (string)$visit['status'] === 'not_routed'
                        || (string)$visit['status'] === 'open'
                        || (string)$visit['status'] === 'failed'
                        || !empty($visit['off_driver'])
                        || ((string)$visit['status'] === 'delivered' && empty($visit['has_after']));
                  ?>
                  <tr class="<?php echo $problem ? 'is-problem' : ''; ?>">
                    <td data-label="<?php echo $h(bakery_t('delivery_review.col_date')); ?>"><?php echo $h(bakery_delivery_manager_review_display_date((string)$visit['date'])); ?></td>
                    <td data-label="<?php echo $h(bakery_t('delivery_review.col_standing_driver')); ?>">
                      <?php if ($standingHref !== ''): ?>
                        <a href="<?php echo $h($standingHref); ?>"><?php echo $h(implode(', ', $standingNames)); ?></a>
                      <?php else: ?>
                        <?php bakery_te('delivery_review.no_standing_pattern'); ?>
                      <?php endif; ?>
                    </td>
                    <td data-label="<?php echo $h(bakery_t('delivery_review.col_actual_driver')); ?>">
                      <?php if ($actualHref !== ''): ?>
                        <a href="<?php echo $h($actualHref); ?>"><?php echo $h((string)$visit['actual_driver_name']); ?></a>
                      <?php else: ?>
                        <?php bakery_te('delivery_review.nobody'); ?>
                      <?php endif; ?>
                    </td>
                    <td data-label="<?php echo $h(bakery_t('delivery_review.col_status')); ?>"><?php echo $h($statusText); ?></td>
                    <td data-label="<?php echo $h(bakery_t('delivery_review.col_photo')); ?>"><?php echo $photoCell((string)$visit['photo_url'], !empty($visit['has_after'])); ?></td>
                    <td data-label="<?php echo $h(bakery_t('delivery_review.col_note')); ?>"><?php echo $h((string)$visit['note'] !== '' ? (string)$visit['note'] : bakery_t('delivery_review.no_note')); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </article>
      <?php endif; ?>

      <?php if ($report['customers'] === []): ?>
        <p class="delivery-review__empty"><?php bakery_te('delivery_review.customers_empty'); ?></p>
      <?php elseif ($visibleCustomers === []): ?>
        <p class="delivery-review__empty"><?php bakery_te('delivery_review.filter_empty'); ?></p>
      <?php else: ?>
        <div class="sf-table-wrap">
          <table class="sf-table sf-table--stack-sm">
            <thead>
              <tr>
                <th><?php bakery_te('delivery_review.col_customer'); ?></th>
                <th><?php bakery_te('delivery_review.col_zone'); ?></th>
                <th><?php bakery_te('delivery_review.col_pattern'); ?></th>
                <th><?php bakery_te('delivery_review.col_expected'); ?></th>
                <th><?php bakery_te('delivery_review.col_routed'); ?></th>
                <th><?php bakery_te('delivery_review.col_delivered'); ?></th>
                <th><?php bakery_te('delivery_review.col_open'); ?></th>
                <th><?php bakery_te('delivery_review.col_failed'); ?></th>
                <th><?php bakery_te('delivery_review.col_cancelled'); ?></th>
                <th><?php bakery_te('delivery_review.col_last'); ?></th>
                <th><?php bakery_te('delivery_review.col_where'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($visibleCustomers as $customer): ?>
                <?php
                  $rowHref = bakery_delivery_manager_review_href([
                      'view' => 'customers',
                      'from' => $from,
                      'to' => $to,
                      'filter' => $filter,
                      'q' => $query,
                      'customer' => (int)$customer['customer_id'],
                  ]);
                  $current = $selectedCustomer !== null && (int)$customer['customer_id'] === (int)$selectedCustomer['customer_id'];
                  $reading = $customer['reading'];
                  $where = bakery_t((string)$reading['key'], $reading['params']);
                  if ((int)$customer['off_count'] > 0) {
                      $where .= ' ' . bakery_t('delivery_review.reading_off_driver', ['count' => (int)$customer['off_count']]);
                  }
                  $rowClass = '';
                  if (!empty($customer['flags']['quiet']) || !empty($customer['flags']['missed']) || !empty($customer['flags']['short'])) {
                      $rowClass = 'is-problem';
                  }
                  if ($current) {
                      $rowClass = trim($rowClass . ' is-current');
                  }
                  $last = (string)($customer['last_delivered'] ?? '');
                ?>
                <tr class="<?php echo $h($rowClass); ?>">
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_customer')); ?>">
                    <a href="<?php echo $h($rowHref); ?>"><?php echo $h((string)$customer['name']); ?></a>
                    <?php if (empty($customer['active'])): ?>
                      <span class="delivery-review__flag"><?php bakery_te('delivery_review.inactive'); ?></span>
                    <?php endif; ?>
                  </td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_zone')); ?>"><?php echo $h((string)$customer['zone'] !== '' ? (string)$customer['zone'] : bakery_t('delivery_review.no_zone')); ?></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_pattern')); ?>"><?php echo $h(bakery_delivery_manager_review_pattern_label($customer['pattern'])); ?></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_expected')); ?>"><?php echo (int)$customer['expected']; ?></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_routed')); ?>"><?php echo (int)$customer['routed']; ?></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_delivered')); ?>"><?php echo (int)$customer['delivered']; ?></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_open')); ?>"><?php echo (int)$customer['open']; ?></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_failed')); ?>"><?php echo (int)$customer['failed']; ?></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_cancelled')); ?>"><?php echo (int)$customer['cancelled']; ?></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_last')); ?>"><?php echo $h($last !== '' ? bakery_delivery_manager_review_display_date($last) : bakery_t('delivery_review.no_last')); ?></td>
                  <td data-label="<?php echo $h(bakery_t('delivery_review.col_where')); ?>"><?php echo $h($where); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
