<?php
/** Read-only route health. Managers and administrators only. */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/route_health.php';

$user = bakery_current_user();
$healthAccess = bakery_route_health_access_status($user);
if ($healthAccess === 302) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}
if ($healthAccess !== 200) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit;
}

$today = (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
$fromRaw = trim((string)($_GET['from'] ?? ''));
$toRaw = trim((string)($_GET['to'] ?? ''));
if ($fromRaw === '' && $toRaw === '' && trim((string)($_GET['date'] ?? '')) !== '') {
    $fromRaw = trim((string)$_GET['date']);
    $toRaw = $fromRaw;
}
$range = bakery_route_health_parse_range($today, $fromRaw, $toRaw);
$report = bakery_route_health_load($db, $range['from'], $range['to']);
$sellDate = bakery_next_sell_date($today);
$tomorrow = bakery_route_health_tomorrow($db, $sellDate);
$stopTotal = (int)$report['stop_total'];
$deliveredTotal = (int)$report['delivered_total'];
$deliveredPercent = $stopTotal > 0 ? (int)round(($deliveredTotal / $stopTotal) * 100) : 0;

$page_title = bakery_t('page.route_health');
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';

$h = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};
$yesNo = static function (?bool $value, bool $ready) use ($h): string {
    return $h(bakery_route_health_yes_no($value, $ready));
};
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/route_health.css'); ?>">
<main class="route-health">
  <header class="route-health__header">
    <h1><?php bakery_te('page.route_health'); ?></h1>
    <p><?php bakery_te('route_health.subtitle'); ?></p>
  </header>

  <form class="route-health__range" method="get" action="route_health.php">
    <label>
      <span><?php bakery_te('route_health.from'); ?></span>
      <input type="date" name="from" value="<?php echo $h((string)$report['from']); ?>" required>
    </label>
    <label>
      <span><?php bakery_te('route_health.to'); ?></span>
      <input type="date" name="to" value="<?php echo $h((string)$report['to']); ?>" required>
    </label>
    <button class="sf-btn sf-btn--primary sf-btn--sm" type="submit"><?php bakery_te('route_health.view'); ?></button>
  </form>
  <?php if (!empty($range['clamped'])): ?>
    <p class="route-health__note"><?php echo $h(bakery_t('route_health.range_clamped', ['days' => BAKERY_ROUTE_HEALTH_MAX_DAYS])); ?></p>
  <?php endif; ?>

  <section class="route-health__section" aria-labelledby="route-health-tomorrow">
    <h2 id="route-health-tomorrow"><?php bakery_te('route_health.tomorrow_title'); ?></h2>
    <p><?php echo $h(bakery_t('route_health.tomorrow_intro', ['date' => bakery_route_health_display_date((string)$tomorrow['date'])])); ?></p>
    <?php
      $overnight = $tomorrow['overnight'];
      $overnightClass = !empty($overnight['missed']) ? ' route-health__banner is-missed' : ' route-health__banner';
    ?>
    <p class="<?php echo trim($overnightClass); ?>"><?php echo $h(bakery_t((string)$overnight['message_key'], $overnight['message_params'] ?? [])); ?></p>
    <p class="route-health__note"><?php bakery_te('route_health.build_help'); ?></p>
    <p><a class="sf-btn sf-btn--outline sf-btn--sm" href="<?php echo $h((defined('BASE_URL') ? BASE_URL : '') . 'daily_orders.php?date=' . rawurlencode((string)$tomorrow['date'])); ?>"><?php bakery_te('route_health.build_standing'); ?></a></p>
    <?php if ($tomorrow['drivers'] === []): ?>
      <p class="route-health__empty"><?php bakery_te('route_health.tomorrow_empty'); ?></p>
    <?php else: ?>
      <div class="sf-table-wrap">
        <table class="sf-table sf-table--stack-sm route-health__table">
          <thead>
            <tr>
              <th><?php bakery_te('route_health.col_driver'); ?></th>
              <th><?php bakery_te('route_health.col_dated'); ?></th>
              <th><?php bakery_te('route_health.col_standing'); ?></th>
              <th><?php bakery_te('route_health.col_gap'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tomorrow['drivers'] as $driver): ?>
              <tr class="<?php echo !empty($driver['short']) ? 'is-short' : ''; ?>">
                <td data-label="<?php echo $h(bakery_t('route_health.col_driver')); ?>"><?php echo $h((string)$driver['driver_name']); ?></td>
                <td data-label="<?php echo $h(bakery_t('route_health.col_dated')); ?>"><?php echo (int)$driver['dated']; ?></td>
                <td data-label="<?php echo $h(bakery_t('route_health.col_standing')); ?>"><?php echo (int)$driver['standing']; ?></td>
                <td data-label="<?php echo $h(bakery_t('route_health.col_gap')); ?>"><?php echo $h(bakery_t(!empty($driver['short']) ? 'route_health.short' : 'route_health.ok')); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="route-health__section" aria-labelledby="route-health-days">
    <h2 id="route-health-days"><?php bakery_te('route_health.day_title'); ?></h2>
    <p><?php echo $h(bakery_t('route_health.totals', ['delivered' => $deliveredTotal, 'stops' => $stopTotal, 'percent' => $deliveredPercent])); ?></p>
    <p class="route-health__note"><?php bakery_te('route_health.zero_note'); ?></p>
    <?php if (empty($report['clock_ready'])): ?>
      <p class="route-health__note"><?php bakery_te('route_health.clock_missing'); ?></p>
    <?php endif; ?>
    <?php if (empty($report['close_ready'])): ?>
      <p class="route-health__note"><?php bakery_te('route_health.close_missing'); ?></p>
    <?php endif; ?>
    <?php if ($report['days'] === []): ?>
      <p class="route-health__empty"><?php bakery_te('route_health.day_empty'); ?></p>
    <?php else: ?>
      <div class="sf-table-wrap">
        <table class="sf-table sf-table--stack-sm route-health__table">
          <thead>
            <tr>
              <th><?php bakery_te('route_health.col_date'); ?></th>
              <th><?php bakery_te('route_health.col_driver'); ?></th>
              <th><?php bakery_te('route_health.col_stops'); ?></th>
              <th><?php bakery_te('route_health.col_delivered'); ?></th>
              <th><?php bakery_te('route_health.col_open'); ?></th>
              <th><?php bakery_te('route_health.col_after'); ?></th>
              <th><?php bakery_te('route_health.col_closed'); ?></th>
              <th><?php bakery_te('route_health.col_clock'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($report['days'] as $day): ?>
              <tr>
                <td data-label="<?php echo $h(bakery_t('route_health.col_date')); ?>"><a href="<?php echo $h((defined('BASE_URL') ? BASE_URL : '') . 'route_photo_journey.php?date=' . rawurlencode((string)$day['date'])); ?>"><?php echo $h(bakery_route_health_display_date((string)$day['date'])); ?></a></td>
                <td data-label="<?php echo $h(bakery_t('route_health.col_driver')); ?>"><?php echo $h((string)$day['driver_name']); ?></td>
                <td data-label="<?php echo $h(bakery_t('route_health.col_stops')); ?>"><?php echo (int)$day['stops']; ?></td>
                <td data-label="<?php echo $h(bakery_t('route_health.col_delivered')); ?>"><?php echo (int)$day['delivered']; ?></td>
                <td data-label="<?php echo $h(bakery_t('route_health.col_open')); ?>"><?php echo (int)$day['open']; ?></td>
                <td data-label="<?php echo $h(bakery_t('route_health.col_after')); ?>"><?php echo (int)$day['delivered_with_after']; ?></td>
                <td data-label="<?php echo $h(bakery_t('route_health.col_closed')); ?>"><?php echo $yesNo(isset($day['route_closed']) ? (bool)$day['route_closed'] : null, !empty($report['close_ready'])); ?></td>
                <td data-label="<?php echo $h(bakery_t('route_health.col_clock')); ?>"><?php echo $yesNo(isset($day['clocked_in']) ? (bool)$day['clocked_in'] : null, !empty($report['clock_ready'])); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="route-health__section" aria-labelledby="route-health-repeat">
    <h2 id="route-health-repeat"><?php bakery_te('route_health.repeat_title'); ?></h2>
    <p><?php echo $h(bakery_t('route_health.repeat_intro', ['count' => BAKERY_ROUTE_HEALTH_REPEAT_SKIP_DAYS])); ?></p>
    <?php if ($report['repeat_skips'] === []): ?>
      <p class="route-health__empty"><?php bakery_te('route_health.repeat_empty'); ?></p>
    <?php else: ?>
      <div class="sf-table-wrap">
        <table class="sf-table sf-table--stack-sm route-health__table">
          <thead>
            <tr>
              <th><?php bakery_te('route_health.col_store'); ?></th>
              <th><?php bakery_te('route_health.col_drivers'); ?></th>
              <th><?php bakery_te('route_health.col_times'); ?></th>
              <th><?php bakery_te('route_health.col_dates'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($report['repeat_skips'] as $skip): ?>
              <tr class="is-short">
                <td data-label="<?php echo $h(bakery_t('route_health.col_store')); ?>"><?php echo $h((string)$skip['customer_name']); ?></td>
                <td data-label="<?php echo $h(bakery_t('route_health.col_drivers')); ?>"><?php echo $h(implode(', ', $skip['drivers'])); ?></td>
                <td data-label="<?php echo $h(bakery_t('route_health.col_times')); ?>"><?php echo (int)$skip['times']; ?></td>
                <td data-label="<?php echo $h(bakery_t('route_health.col_dates')); ?>"><?php echo $h(implode(', ', array_map('bakery_route_health_display_date', $skip['dates']))); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="route-health__section" aria-labelledby="route-health-proof">
    <h2 id="route-health-proof"><?php bakery_te('route_health.proof_title'); ?></h2>
    <h3><?php bakery_te('route_health.no_after_title'); ?></h3>
    <?php if ($report['missing_after'] === []): ?>
      <p class="route-health__empty"><?php bakery_te('route_health.no_after_empty'); ?></p>
    <?php else: ?>
      <div class="sf-table-wrap">
        <table class="sf-table sf-table--stack-sm route-health__table">
          <thead>
            <tr>
              <th><?php bakery_te('route_health.col_date'); ?></th>
              <th><?php bakery_te('route_health.col_driver'); ?></th>
              <th><?php bakery_te('route_health.col_store'); ?></th>
              <th><?php bakery_te('route_health.photo_link'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($report['missing_after'] as $row): ?>
              <tr>
                <td data-label="<?php echo $h(bakery_t('route_health.col_date')); ?>"><?php echo $h(bakery_route_health_display_date((string)$row['date'])); ?></td>
                <td data-label="<?php echo $h(bakery_t('route_health.col_driver')); ?>"><?php echo $h((string)$row['driver_name']); ?></td>
                <td data-label="<?php echo $h(bakery_t('route_health.col_store')); ?>"><?php echo $h((string)$row['customer_name']); ?></td>
                <td data-label="<?php echo $h(bakery_t('route_health.photo_link')); ?>">
                  <?php if ((string)$row['photo_url'] !== ''): ?>
                    <a href="<?php echo $h((string)$row['photo_url']); ?>"><?php bakery_te('route_health.photo_link'); ?></a>
                  <?php else: ?>
                    <?php bakery_te('route_health.no'); ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <h3><?php bakery_te('route_health.bulk_title'); ?></h3>
    <p><?php echo $h(bakery_t('route_health.bulk_intro', [
        'count' => BAKERY_ROUTE_STOP_BULK_STAMP_MIN_STOPS,
        'minutes' => BAKERY_ROUTE_STOP_BULK_STAMP_MINUTES,
    ])); ?></p>
    <?php if ($report['bulk_stamps'] === []): ?>
      <p class="route-health__empty"><?php bakery_te('route_health.bulk_empty'); ?></p>
    <?php else: ?>
      <div class="sf-table-wrap">
        <table class="sf-table sf-table--stack-sm route-health__table">
          <thead>
            <tr>
              <th><?php bakery_te('route_health.col_date'); ?></th>
              <th><?php bakery_te('route_health.col_driver'); ?></th>
              <th><?php bakery_te('route_health.col_store'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($report['bulk_stamps'] as $row): ?>
              <tr class="is-short">
                <td data-label="<?php echo $h(bakery_t('route_health.col_date')); ?>"><?php echo $h(bakery_route_health_display_date((string)$row['date'])); ?></td>
                <td data-label="<?php echo $h(bakery_t('route_health.col_driver')); ?>"><?php echo $h((string)$row['driver_name']); ?></td>
                <td data-label="<?php echo $h(bakery_t('route_health.col_store')); ?>"><?php echo $h(implode(', ', $row['stores'])); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
