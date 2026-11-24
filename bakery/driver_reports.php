<?php
/** Read-only driver reports. Managers and administrators see every driver. */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/driver_reports.php';

$user = bakery_current_user();
$reportAccess = bakery_driver_reports_access_status($user);
if ($reportAccess === 302) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}
if ($reportAccess !== 200) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit;
}

$today = date('Y-m-d');
$tab = bakery_driver_reports_parse_tab((string)($_GET['tab'] ?? 'summary'));
$fallbackDate = bakery_driver_reports_default_date($tab, $today);
$selectedDate = route_manager_parse_date($_GET['date'] ?? $fallbackDate, $fallbackDate);

switch ($tab) {
    case 'summary':
        $day = bakery_driver_reports_load_summary($db, $selectedDate);
        break;
    case 'sheet':
        $day = bakery_driver_reports_load_sheet($db, $selectedDate);
        break;
    case 'plan':
        $day = bakery_driver_reports_load_plan($db, $selectedDate);
        break;
    default:
        throw new LogicException('Unhandled driver reports tab');
}
$selectedDate = (string)($day['date'] ?? $selectedDate);

$tabs = [
    'summary' => 'driver_reports.tab_summary',
    'sheet' => 'driver_reports.tab_sheet',
    'plan' => 'driver_reports.tab_plan',
];

$page_title = bakery_t('page.driver_reports');
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';

$h = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/driver_reports.css'); ?>">
<main class="driver-reports">
  <header class="driver-reports__header">
    <h1><?php bakery_te('page.driver_reports'); ?></h1>
    <p><?php bakery_te('driver_reports.subtitle'); ?></p>
    <p class="driver-reports__note"><?php bakery_te('driver_reports.all_drivers'); ?></p>
  </header>

  <nav class="driver-reports__tabs" aria-label="<?php echo $h(bakery_t('driver_reports.tabs_label')); ?>">
    <?php foreach ($tabs as $candidate => $labelKey): ?>
      <?php
        $tabDate = bakery_driver_reports_tab_date($candidate, $tab, $selectedDate, $today);
        $active = $candidate === $tab;
      ?>
      <a class="driver-reports__tab<?php echo $active ? ' is-active' : ''; ?>" href="<?php echo $h(bakery_driver_reports_query($candidate, $tabDate)); ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>><?php bakery_te($labelKey); ?></a>
    <?php endforeach; ?>
  </nav>

  <form class="driver-reports__date" method="get" action="driver_reports.php">
    <input type="hidden" name="tab" value="<?php echo $h($tab); ?>">
    <label>
      <span><?php bakery_te('driver_reports.date'); ?></span>
      <input type="date" name="date" value="<?php echo $h($selectedDate); ?>" required>
    </label>
    <button class="sf-btn sf-btn--primary sf-btn--sm" type="submit"><?php bakery_te('driver_reports.view'); ?></button>
  </form>

  <?php if ($tab === 'plan'): ?>
    <?php if (($day['drivers'] ?? []) === []): ?>
      <p class="driver-reports__empty"><?php bakery_te('driver_reports.plan_none'); ?></p>
    <?php endif; ?>
    <?php foreach ($day['drivers'] ?? [] as $driver): ?>
      <section class="driver-reports__driver">
        <h2><?php echo $h((string)$driver['name']); ?></h2>
        <p class="driver-reports__kicker"><?php bakery_te('driver_reports.plan_with_delivery'); ?></p>
        <p><?php echo $h(bakery_t('driver_reports.plan_stops', ['count' => (int)$driver['stop_count']])); ?></p>
        <p><?php echo $h(bakery_t('driver_reports.plan_range', [
            'min' => (int)$driver['minutes_min'],
            'max' => (int)$driver['minutes_max'],
        ])); ?></p>
        <?php if (!empty($driver['overloaded'])): ?>
          <p class="driver-reports__flag"><?php bakery_te('driver_reports.plan_overload'); ?></p>
        <?php endif; ?>
        <ol class="driver-reports__stops">
          <?php foreach ($driver['stops'] as $stop): ?>
            <li><?php echo $h(bakery_driver_reports_plan_stop_label($stop)); ?></li>
          <?php endforeach; ?>
        </ol>
      </section>
    <?php endforeach; ?>

    <section class="driver-reports__driver">
      <h2><?php bakery_te('driver_reports.plan_no_van'); ?></h2>
      <?php if (($day['no_van'] ?? []) === []): ?>
        <p class="driver-reports__empty"><?php bakery_te('driver_reports.plan_no_van_empty'); ?></p>
      <?php else: ?>
        <ul class="driver-reports__stops">
          <?php foreach ($day['no_van'] as $stop): ?>
            <li><?php echo $h(bakery_driver_reports_plan_stop_label($stop)); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="driver-reports__driver">
      <h2><?php bakery_te('driver_reports.plan_faltan'); ?></h2>
      <?php if (($day['faltan'] ?? []) === []): ?>
        <p class="driver-reports__empty"><?php bakery_te('driver_reports.plan_faltan_empty'); ?></p>
      <?php else: ?>
        <ul class="driver-reports__stops">
          <?php foreach ($day['faltan'] as $stop): ?>
            <li><?php echo $h((string)($stop['name'] ?? '')); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  <?php elseif (($day['drivers'] ?? []) === []): ?>
    <p class="driver-reports__empty"><?php bakery_te('driver_reports.empty'); ?></p>
  <?php endif; ?>

  <?php if ($tab === 'summary'): ?>
    <?php foreach ($day['drivers'] ?? [] as $driver): ?>
      <section class="driver-reports__driver">
        <h2><?php echo $h((string)$driver['name']); ?></h2>
        <h3><?php bakery_te('driver_reports.flags_label'); ?></h3>
        <?php $flagLines = bakery_driver_reports_flag_lines($driver['flags'] ?? []); ?>
        <?php if ($flagLines === []): ?>
          <p class="driver-reports__empty"><?php bakery_te('driver_reports.no_flags'); ?></p>
        <?php else: ?>
          <ul class="driver-reports__flags">
            <?php foreach ($flagLines as $line): ?>
              <li><?php echo $h($line); ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <ol class="driver-reports__stops">
          <?php foreach ($driver['stops'] as $stop): ?>
            <li class="driver-reports__stop">
              <p class="driver-reports__stop-name"><?php echo $h(bakery_driver_reports_stop_label($stop)); ?></p>
              <p class="driver-reports__mark"><?php bakery_te(bakery_driver_reports_mark_key((string)($stop['mark'] ?? 'no_info'))); ?></p>
            </li>
          <?php endforeach; ?>
        </ol>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($tab === 'sheet'): ?>
    <?php foreach ($day['drivers'] ?? [] as $driver): ?>
      <section class="driver-reports__driver">
        <h2><?php echo $h((string)$driver['name']); ?></h2>
        <?php foreach ($driver['stops'] as $stop): ?>
          <?php
            $thumb = $stop['thumbnail'] ?? null;
            $src = is_array($thumb) ? (string)($thumb['url'] ?? '') : '';
            $safePhoto = $src !== '' && strpos($src, 'driver_photo.php') !== false && strpos($src, 'uploads/driver_photos') === false;
          ?>
          <article class="driver-reports__sheet-stop">
            <h3><?php echo $h(bakery_driver_reports_stop_label($stop)); ?></h3>
            <dl>
              <div>
                <dt><?php bakery_te('driver_reports.col_arrival'); ?></dt>
                <dd><?php echo $h(bakery_route_photo_journey_format_clock($stop['arrival_at'] ?? null)); ?></dd>
              </div>
              <div>
                <dt><?php bakery_te('driver_reports.col_departure'); ?></dt>
                <dd><?php echo $h(bakery_route_photo_journey_format_clock($stop['departure_at'] ?? null)); ?></dd>
              </div>
              <div>
                <dt><?php bakery_te('driver_reports.col_at_stop'); ?></dt>
                <dd><?php echo $h(bakery_driver_reports_minutes_label($stop['minutes_at_stop'] ?? null)); ?></dd>
              </div>
              <div>
                <dt><?php bakery_te('driver_reports.col_since'); ?></dt>
                <dd><?php echo $h(bakery_driver_reports_minutes_label($stop['minutes_since_previous'] ?? null)); ?></dd>
              </div>
              <div>
                <dt><?php bakery_te('driver_reports.col_photo'); ?></dt>
                <dd>
                  <?php if ($safePhoto): ?>
                    <img class="driver-reports__thumb" src="<?php echo $h($src); ?>" alt="<?php echo $h(bakery_t('driver_reports.photo_alt')); ?>">
                  <?php else: ?>
                    <?php bakery_te('driver_reports.no_photo'); ?>
                  <?php endif; ?>
                </dd>
              </div>
            </dl>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
