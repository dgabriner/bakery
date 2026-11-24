<?php
/** Route photo journey: hours and photos for who actually delivered each stop. */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/route_photo_journey.php';

$user = bakery_current_user();
$journeyAccess = bakery_route_photo_journey_access_status($user);
if ($journeyAccess === 302) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}
if ($journeyAccess !== 200) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit;
}

$today = date('Y-m-d');
$selectedDate = route_manager_parse_date($_GET['date'] ?? $today, $today);
$requestedDriverId = max(0, (int)($_GET['driver_id'] ?? 0));
$day = bakery_route_photo_journey_load($db, $selectedDate, $requestedDriverId);
$picker = $day['picker'] ?? [];
$pickerIds = array_map(static function (array $driver): int {
    return (int)$driver['id'];
}, $picker);
$selectedDriverId = in_array($requestedDriverId, $pickerIds, true) ? $requestedDriverId : 0;
if ($selectedDriverId !== $requestedDriverId) {
    $day = bakery_route_photo_journey_load($db, $selectedDate, 0);
    $picker = $day['picker'] ?? [];
    $selectedDriverId = 0;
}

$prevDate = (new DateTimeImmutable($selectedDate))->modify('-1 day')->format('Y-m-d');
$nextDate = (new DateTimeImmutable($selectedDate))->modify('+1 day')->format('Y-m-d');
$dateObject = new DateTimeImmutable($selectedDate);
$dateLabel = function_exists('bakery_localized_date_label')
    ? bakery_localized_date_label($dateObject, true)
    : $dateObject->format('l, F j, Y');

$page_title = bakery_t('page.route_photo_journey');
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/route_photo_journey.css'); ?>">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<main class="journey">
  <header class="journey-header">
    <div>
      <p class="journey-eyebrow"><?php bakery_te('route_photo_journey.eyebrow'); ?></p>
      <h1><?php bakery_te('page.route_photo_journey'); ?></h1>
      <p class="journey-date"><?php echo htmlspecialchars($dateLabel); ?>. <?php bakery_te('route_photo_journey.subtitle'); ?></p>
    </div>
  </header>

  <form class="journey-toolbar" method="get" action="route_photo_journey.php">
    <div class="journey-date-nav">
      <a class="sf-btn sf-btn--outline sf-btn--sm" href="<?php echo htmlspecialchars(bakery_route_photo_journey_query($prevDate, $selectedDriverId)); ?>"><?php bakery_te('route_photo_journey.prev_day'); ?></a>
      <label class="journey-field">
        <span><?php bakery_te('route_photo_journey.date'); ?></span>
        <input type="date" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>" onchange="this.form.submit()">
      </label>
      <a class="sf-btn sf-btn--outline sf-btn--sm" href="<?php echo htmlspecialchars(bakery_route_photo_journey_query($nextDate, $selectedDriverId)); ?>"><?php bakery_te('route_photo_journey.next_day'); ?></a>
      <a class="sf-btn sf-btn--quiet sf-btn--sm" href="<?php echo htmlspecialchars(bakery_route_photo_journey_query($today, $selectedDriverId)); ?>"><?php bakery_te('route_photo_journey.today'); ?></a>
    </div>
    <label class="journey-field">
      <span><?php bakery_te('route_photo_journey.driver'); ?></span>
      <select name="driver_id" onchange="this.form.submit()">
        <option value="0"><?php bakery_te('route_photo_journey.all_drivers'); ?></option>
        <?php foreach ($picker as $driverOption): ?>
          <option value="<?php echo (int)$driverOption['id']; ?>"<?php echo (int)$driverOption['id'] === $selectedDriverId ? ' selected' : ''; ?>>
            <?php echo htmlspecialchars((string)$driverOption['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="journey-actions">
      <noscript><button class="sf-btn sf-btn--primary sf-btn--sm" type="submit"><?php bakery_te('route_photo_journey.view'); ?></button></noscript>
      <button class="sf-btn sf-btn--outline sf-btn--sm no-print" type="button" onclick="window.print()"><?php bakery_te('route_photo_journey.print'); ?></button>
    </div>
  </form>

  <section class="journey-legend" aria-label="<?php echo htmlspecialchars(bakery_t('route_photo_journey.map_label')); ?>">
    <div class="journey-legend__chips">
      <span class="journey-swatch"><i class="is-bakery">P</i><?php bakery_te('route_photo_journey.legend_bakery'); ?></span>
      <span class="journey-swatch"><i class="is-pod">1</i><?php bakery_te('route_photo_journey.legend_pod'); ?></span>
      <span class="journey-swatch"><i class="is-arrival">1</i><?php bakery_te('route_photo_journey.legend_arrival'); ?></span>
      <span class="journey-swatch"><i class="is-arrived-open">!</i><?php bakery_te('route_photo_journey.legend_arrived_open'); ?></span>
      <span class="journey-swatch"><i class="is-pending">!</i><?php bakery_te('route_photo_journey.legend_pending'); ?></span>
    </div>
    <p><?php bakery_te('route_photo_journey.legend_help'); ?></p>
  </section>

  <?php if (($day['drivers'] ?? []) === []): ?>
    <p class="journey-empty"><?php bakery_te($selectedDriverId > 0 ? 'route_photo_journey.empty_driver' : 'route_photo_journey.empty'); ?></p>
  <?php endif; ?>

  <?php foreach ($day['drivers'] ?? [] as $driver): ?>
    <?php
      $visited = array_values(array_filter($driver['stops'], static function (array $stop): bool {
          return (string)($stop['badge'] ?? '') !== 'pending';
      }));
      $first = $visited[0] ?? null;
      $last = null;
      foreach (array_reverse($visited) as $visitedStop) {
          if ((string)($visitedStop['badge'] ?? '') !== 'arrived_open') {
              $last = $visitedStop;
              break;
          }
      }
      $mapId = 'journey-map-data-' . (int)$driver['id'];
      $pendingNames = implode(', ', $driver['pending_names'] ?? []);
    ?>
    <section class="journey-driver">
      <div class="journey-driver__head">
        <h2><?php echo htmlspecialchars((string)$driver['name']); ?></h2>
        <p class="journey-summary">
          <?php if (is_array($first)): ?>
            <span><?php bakery_te('route_photo_journey.first_stop'); ?> <strong><?php echo htmlspecialchars(bakery_route_photo_journey_format_clock((string)($first['arrival_at'] ?? ''))); ?></strong></span>
          <?php endif; ?>
          <?php if (is_array($last)): ?>
            <span><?php bakery_te('route_photo_journey.last_delivery'); ?> <strong><?php echo htmlspecialchars(bakery_route_photo_journey_format_clock((string)($last['delivered_at'] ?? ''))); ?></strong></span>
          <?php endif; ?>
          <span><?php echo htmlspecialchars(bakery_t('route_photo_journey.delivered_count', ['n' => (int)$driver['delivered_count']])); ?></span>
          <?php if ((int)$driver['pending_count'] > 0): ?>
            <span class="journey-flags"><?php echo htmlspecialchars(bakery_t('route_photo_journey.pending_names', ['count' => (int)$driver['pending_count'], 'names' => $pendingNames])); ?></span>
          <?php else: ?>
            <span><?php echo htmlspecialchars(bakery_t('route_photo_journey.pending_count', ['n' => 0])); ?></span>
          <?php endif; ?>
          <?php if (!empty($driver['no_clock_in'])): ?>
            <span class="journey-flags"><?php bakery_te('route_photo_journey.no_clock_in'); ?></span>
          <?php else: ?>
            <span><?php echo htmlspecialchars(bakery_t('route_photo_journey.clock_in', ['time' => bakery_route_photo_journey_format_clock((string)($driver['clock_in_at'] ?? ''))])); ?></span>
          <?php endif; ?>
        </p>
      </div>
      <?php if (($driver['flags']['long_gaps'] ?? []) !== [] || ($driver['flags']['short_closes'] ?? []) !== [] || ($driver['flags']['zero_dollar'] ?? []) !== []): ?>
        <p class="journey-flags">
          <?php foreach ($driver['flags']['long_gaps'] as $gap): ?>
            <span><?php echo htmlspecialchars(bakery_t('route_photo_journey.flag_long_gap', ['store' => (string)$gap['store'], 'minutes' => (int)$gap['minutes']])); ?></span>
          <?php endforeach; ?>
          <?php if (($driver['flags']['short_closes'] ?? []) !== []): ?>
            <span><?php echo htmlspecialchars(bakery_t('route_photo_journey.flag_short_close', ['stores' => implode(', ', $driver['flags']['short_closes'])])); ?></span>
          <?php endif; ?>
          <?php if (($driver['flags']['zero_dollar'] ?? []) !== []): ?>
            <span><?php echo htmlspecialchars(bakery_t('route_photo_journey.flag_zero', ['stores' => implode(', ', $driver['flags']['zero_dollar'])])); ?></span>
          <?php endif; ?>
        </p>
      <?php endif; ?>

      <div class="journey-layout">
        <div class="journey-table-wrap">
          <table class="journey-table">
            <thead>
              <tr>
                <th></th>
                <th><?php bakery_te('route_photo_journey.col_photos'); ?></th>
                <th><?php bakery_te('route_photo_journey.col_store'); ?></th>
                <th><?php bakery_te('route_photo_journey.col_arrival'); ?></th>
                <th><?php bakery_te('route_photo_journey.col_delivered'); ?></th>
                <th><?php bakery_te('route_photo_journey.col_at_stop'); ?></th>
                <th><?php bakery_te('route_photo_journey.col_travel'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($driver['stops'] as $stop): ?>
                <?php
                  $badge = (string)$stop['badge'];
                  $badgeClass = bakery_route_photo_journey_badge_class($badge);
                  $photoPayload = [];
                  foreach ($stop['photos'] as $photo) {
                      $typeKey = (string)$photo['photo_type'] === 'Before'
                          ? 'route_photo_journey.photo_arrival'
                          : 'route_photo_journey.photo_delivery';
                      $photoPayload[] = [
                          'url' => (string)($photo['url'] ?? ''),
                          'photo_type' => bakery_t($typeKey),
                          'created_at' => bakery_route_photo_journey_format_clock((string)($photo['created_at'] ?? '')),
                      ];
                  }
                  $photoJson = htmlspecialchars(
                      json_encode($photoPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
                      ENT_QUOTES,
                      'UTF-8'
                  );
                    $pieces = bakery_route_photo_journey_pieces_label($stop);
                ?>
                <tr>
                  <td>
                    <span class="journey-num <?php echo htmlspecialchars($badgeClass); ?>">
                      <?php echo $badge === 'pending' ? '!' : (int)$stop['order']; ?>
                    </span>
                  </td>
                  <td>
                    <?php if ($photoPayload !== []): ?>
                      <div class="journey-photos">
                        <?php foreach ($stop['photos'] as $photoIndex => $photo): ?>
                          <?php
                            $captionKey = (string)$photo['photo_type'] === 'Before'
                                ? 'route_photo_journey.photo_arrival'
                                : 'route_photo_journey.photo_delivery';
                          ?>
                          <button
                            type="button"
                            class="journey-photo"
                            data-journey-photo
                            data-store="<?php echo htmlspecialchars((string)$stop['customer_name']); ?>"
                            data-index="<?php echo (int)$photoIndex; ?>"
                            data-photos="<?php echo $photoJson; ?>"
                            aria-label="<?php echo htmlspecialchars(bakery_t('route_photo_journey.open_photo')); ?>"
                          >
                            <img src="<?php echo htmlspecialchars((string)$photo['url']); ?>" alt="<?php echo htmlspecialchars(bakery_t('route_photo_journey.photo_alt')); ?>">
                            <span><?php bakery_te($captionKey); ?> <?php echo htmlspecialchars(bakery_route_photo_journey_format_clock((string)$photo['created_at'])); ?></span>
                          </button>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td class="journey-store">
                    <strong><?php echo htmlspecialchars((string)$stop['customer_name']); ?></strong>
                    <span class="journey-badge <?php echo htmlspecialchars($badgeClass); ?>"><?php bakery_te(bakery_route_photo_journey_badge_key($badge)); ?></span>
                    <?php if ($pieces !== ''): ?>
                      <div class="journey-meta"><?php echo htmlspecialchars($pieces); ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="journey-time"><?php echo htmlspecialchars(bakery_route_photo_journey_format_clock((string)($stop['arrival_at'] ?? ''))); ?></td>
                  <td class="journey-time is-strong"><?php echo htmlspecialchars(bakery_route_photo_journey_format_clock((string)($stop['delivered_at'] ?? ''))); ?></td>
                  <td class="journey-time">
                    <?php if ($badge !== 'pending'): ?>
                      <?php echo htmlspecialchars(bakery_route_photo_journey_duration_label((int)$stop['stay_minutes'])); ?>
                    <?php endif; ?>
                  </td>
                  <td class="journey-time">
                    <?php echo htmlspecialchars(bakery_route_photo_journey_travel_label($stop, $driver)); ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div>
          <div class="journey-map" id="journey-map-<?php echo (int)$driver['id']; ?>" data-journey-map="<?php echo htmlspecialchars($mapId); ?>" role="img" aria-label="<?php echo htmlspecialchars(bakery_t('route_photo_journey.map_label')); ?>"></div>
          <script type="application/json" id="<?php echo htmlspecialchars($mapId); ?>"><?php echo json_encode(bakery_route_photo_journey_map_points($driver), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
        </div>
      </div>

      <?php if (($driver['other'] ?? []) !== []): ?>
        <div class="journey-other">
          <strong><?php bakery_te('route_photo_journey.other_planned'); ?></strong>
          <?php foreach ($driver['other'] as $note): ?>
            <p><?php echo htmlspecialchars(bakery_route_photo_journey_other_label($note)); ?></p>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

  <p class="journey-foot"><?php bakery_te('route_photo_journey.footer'); ?></p>
</main>

<div id="journeyLightbox" class="journey-lightbox" hidden>
  <div class="journey-lightbox__backdrop" data-journey-close></div>
  <div class="journey-lightbox__sheet" role="dialog" aria-modal="true" aria-labelledby="journeyLightboxTitle">
    <button type="button" class="journey-lightbox__close" data-journey-close aria-label="<?php echo htmlspecialchars(bakery_t('route_photo_journey.close_photo')); ?>">&times;</button>
    <img id="journeyLightboxImage" alt="">
    <div class="journey-lightbox__caption">
      <h3 id="journeyLightboxTitle"></h3>
      <p id="journeyLightboxMeta"></p>
    </div>
    <button type="button" class="journey-lightbox__nav journey-lightbox__nav--prev" id="journeyLightboxPrev" aria-label="<?php echo htmlspecialchars(bakery_t('ui.previous')); ?>">&lsaquo;</button>
    <button type="button" class="journey-lightbox__nav journey-lightbox__nav--next" id="journeyLightboxNext" aria-label="<?php echo htmlspecialchars(bakery_t('ui.next')); ?>">&rsaquo;</button>
  </div>
</div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?php echo bakery_asset_href('includes/route_photo_journey.js'); ?>"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
