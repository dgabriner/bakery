<?php
/** Administrator-only sign-in, session, navigation, and usage history. */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/navigation_catalog.php';
require_once __DIR__ . '/includes/login_history_insights.php';

bakery_require_role(['administrator']);
bakery_ensure_login_audit_schema($db);

$filters = bakery_login_history_parse_filters($_GET);
$ready = bakery_login_history_ready($db);
$data = bakery_login_history_load($db, $filters, $ready);
$options = $data['options'];
$summary = $data['summary'];
$view = $filters['view'];
$investigation = $data['investigation'];
$dayNames = function_exists('bakery_day_names') ? bakery_day_names(true) : [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$comparison = $data['comparison'];

if ($filters['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="login-history-' . $filters['today'] . '.csv"');
    $stream = fopen('php://output', 'w');
    if ($stream) {
        fputcsv($stream, bakery_login_history_csv_headers());
        foreach ($data['records']['rows'] ?? [] as $exportRow) {
            fputcsv($stream, bakery_login_history_csv_row($exportRow));
        }
        fclose($stream);
    }
    exit;
}

$page_title = bakery_t('page.login_history');
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';

function bakery_login_history_tab(array $filters, string $view, string $label): void
{
    $active = $filters['view'] === $view ? ' is-active' : '';
    echo '<a class="' . trim($active) . '" href="' . htmlspecialchars(bakery_login_history_url(['view' => $view])) . '">'
        . htmlspecialchars($label) . '</a>';
}

function bakery_login_history_render_session(array $row): void
{
    $isSuccess = !empty($row['is_success']);
    $isLive = !empty($row['is_live']);
    $clientMetadata = !empty($row['client_metadata']) && is_array(json_decode((string)$row['client_metadata'], true))
        ? json_decode((string)$row['client_metadata'], true)
        : [];
    $location = ($row['gps_latitude'] !== null && $row['gps_longitude'] !== null)
        ? ((float)$row['gps_latitude'] . ', ' . (float)$row['gps_longitude'])
        : bakery_t('login_history.location_not_shared');
    $mapUrl = ($row['gps_latitude'] !== null && $row['gps_longitude'] !== null)
        ? 'https://www.google.com/maps?q=' . rawurlencode($row['gps_latitude'] . ',' . $row['gps_longitude'])
        : '';
    $subject = (($row['auth_type'] ?? '') === 'customer' && !empty($row['customer_id']))
        ? ['user_id' => 0, 'customer_id' => (int)$row['customer_id'], 'subject' => 'c-' . (int)$row['customer_id']]
        : (!empty($row['user_id']) ? ['user_id' => (int)$row['user_id'], 'customer_id' => 0, 'subject' => 's-' . (int)$row['user_id']] : null);
    ?>
    <article class="login-history-row">
      <div>
        <span class="login-history-label"><?php bakery_te('login_history.user'); ?></span>
        <h2><?php echo htmlspecialchars((string)$row['display_name']); ?></h2>
        <p><?php echo htmlspecialchars((string)$row['role_label']); ?> · <?php echo htmlspecialchars($row['auth_type'] === 'customer' ? bakery_t('login_history.customer_portal') : bakery_t('login_history.staff_app')); ?></p>
        <?php if (!empty($row['staff_email'])): ?><p><?php echo htmlspecialchars((string)$row['staff_email']); ?></p><?php endif; ?>
        <p><span class="login-history-pill<?php echo $isSuccess ? '' : ' failure'; ?>"><?php echo htmlspecialchars($isSuccess ? bakery_t('login_history.successful') : bakery_t('login_history.failed')); ?></span></p>
        <?php if ($subject): ?>
          <a class="login-history-investigate" href="<?php echo htmlspecialchars(bakery_login_history_url($subject)); ?>#investigation"><?php bakery_te('login_history.investigate'); ?></a>
        <?php endif; ?>
      </div>
      <div>
        <span class="login-history-label"><?php bakery_te('login_history.session_label'); ?></span>
        <p><strong><?php echo htmlspecialchars(bakery_login_history_when((string)$row['login_at'])); ?></strong></p>
        <p><?php echo $isSuccess
            ? htmlspecialchars(bakery_t('login_history.time_recorded') . ': ' . bakery_login_history_duration($row['duration_seconds']))
            : htmlspecialchars($row['failure_reason'] ?: bakery_t('login_history.invalid_credentials')); ?></p>
        <?php if (!empty($row['page_label'])): ?><p><?php bakery_te('login_history.on_page'); ?>: <?php echo htmlspecialchars((string)$row['page_label']); ?></p><?php endif; ?>
        <?php if (!empty($row['credential_method'])): ?>
          <p><?php echo htmlspecialchars(bakery_login_history_credential_label($row['credential_method'])); ?><?php if (!empty($row['credential_suffix'])): ?> · <?php bakery_te('login_history.ends'); ?> <?php echo htmlspecialchars('••' . $row['credential_suffix']); ?><?php endif; ?></p>
        <?php endif; ?>
        <?php if ($isLive): ?>
          <p><span class="login-history-pill active"><?php bakery_te('login_history.open_session'); ?> · <?php echo htmlspecialchars(bakery_login_history_ago((string)$row['last_seen_at'])); ?></span></p>
        <?php elseif ($isSuccess && empty($row['logout_at'])): ?>
          <p><?php bakery_te('login_history.inactive'); ?> · <?php bakery_te('login_history.last_seen'); ?> <?php echo htmlspecialchars(bakery_login_history_ago((string)($row['last_seen_at'] ?: $row['login_at']))); ?></p>
        <?php elseif (!empty($row['logout_at'])): ?>
          <p><?php bakery_te('login_history.ended'); ?> <?php echo htmlspecialchars(date('g:i:s A', strtotime((string)$row['logout_at']))); ?></p>
        <?php endif; ?>
      </div>
      <div>
        <span class="login-history-label"><?php bakery_te('login_history.device_network'); ?></span>
        <p><strong><?php echo htmlspecialchars($row['device_type'] ?: bakery_t('login_history.unknown_device')); ?></strong></p>
        <p><?php echo htmlspecialchars(($row['browser'] ?: bakery_t('login_history.unknown_browser')) . ' · ' . ($row['operating_system'] ?: bakery_t('login_history.unknown_os'))); ?></p>
        <p><?php bakery_te('login_history.ip'); ?>: <?php echo htmlspecialchars($row['ip_address'] ?: bakery_t('common.unavailable')); ?></p>
        <?php if (!empty($clientMetadata['screen'])): ?><p><?php bakery_te('login_history.screen'); ?>: <?php echo htmlspecialchars((string)$clientMetadata['screen']); ?></p><?php endif; ?>
        <?php if (!empty($row['user_agent'])): ?><details><summary><?php bakery_te('login_history.browser_signature'); ?></summary><p><code><?php echo htmlspecialchars((string)$row['user_agent']); ?></code></p></details><?php endif; ?>
      </div>
      <div>
        <span class="login-history-label"><?php bakery_te('login_history.location'); ?></span>
        <p><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$row['location_status']))); ?></p>
        <?php if ($mapUrl): ?>
          <p><a class="login-history-map" href="<?php echo htmlspecialchars($mapUrl); ?>" target="_blank" rel="noopener"><?php bakery_te('login_history.view_gps'); ?>: <?php echo htmlspecialchars($location); ?></a></p>
          <p><?php bakery_te('login_history.accuracy'); ?>: <?php echo htmlspecialchars((string)$row['gps_accuracy_m']); ?> m</p>
        <?php endif; ?>
      </div>
    </article>
    <?php
}

function bakery_login_history_render_delta(?array $delta): void
{
    if (!$delta || ($delta['direction'] ?? 'flat') === 'flat') {
        return;
    }
    $dir = $delta['direction'] === 'up' ? 'is-up' : 'is-down';
    $sign = $delta['direction'] === 'up' ? '+' : '−';
    echo '<em class="login-history-delta ' . $dir . '">' . htmlspecialchars($sign . (int)$delta['pct'] . '%') . '</em>';
}

function bakery_login_history_render_heatmap(array $heat, array $dayNames, string $titleKey, string $emptyKey = 'login_history.heatmap_empty'): void
{
    if (empty($heat['max'])) {
        echo '<div class="login-history-empty">' . htmlspecialchars(bakery_t($emptyKey)) . '</div>';
        return;
    }
    echo '<div class="login-history-heat" role="img" aria-label="' . htmlspecialchars(bakery_t($titleKey)) . '">';
    for ($d = 0; $d < 7; $d++) {
        echo '<div class="login-history-heat__lab">' . htmlspecialchars($dayNames[$d + 1] ?? '') . '</div>';
        for ($h = 0; $h < 24; $h++) {
            $n = (int)($heat['grid'][$d][$h] ?? 0);
            $alpha = $n ? 0.12 + 0.88 * bakery_login_history_intensity($n, (int)$heat['max']) : 0;
            $style = $n ? 'background: rgba(36,116,106,' . $alpha . ');' : '';
            echo '<span class="login-history-heat__cell" data-n="' . $n . '" title="' . htmlspecialchars(($dayNames[$d + 1] ?? '') . ' ' . bakery_login_history_format_hour($h) . ' · ' . $n) . '" style="' . $style . '"></span>';
        }
    }
    echo '</div><div class="login-history-heat-hours"><span></span>';
    for ($h = 0; $h < 24; $h++) {
        echo '<span>' . ($h % 3 === 0 ? (int)$h : '') . '</span>';
    }
    echo '</div>';
}

function bakery_login_history_render_daily_chart(array $days, int $maxS, int $maxP, int $maxF): void
{
    echo '<div class="login-history-chart">';
    foreach ($days as $dayRow) {
        $sH = max((int)$dayRow['signins'] > 0 ? 4 : 0, (int)round(132 * (int)$dayRow['signins'] / max(1, $maxS)));
        $pH = max((int)$dayRow['pages'] > 0 ? 4 : 0, (int)round(132 * (int)$dayRow['pages'] / max(1, $maxP)));
        $fH = $maxF > 0 ? max((int)$dayRow['failures'] > 0 ? 4 : 0, (int)round(132 * (int)$dayRow['failures'] / $maxF)) : 0;
        $title = $dayRow['day'] . ': ' . (int)$dayRow['signins'] . ' / ' . (int)$dayRow['pages'];
        if (!empty($dayRow['failures'])) {
            $title .= ' / ' . (int)$dayRow['failures'] . ' failed';
        }
        echo '<div class="login-history-chart__day" title="' . htmlspecialchars($title) . '">';
        echo '<div class="login-history-chart__pair">';
        echo '<span class="login-history-chart__bar" style="height: ' . $sH . 'px"></span>';
        echo '<span class="login-history-chart__bar is-pages" style="height: ' . $pH . 'px"></span>';
        if ($maxF > 0) {
            echo '<span class="login-history-chart__bar is-fail" style="height: ' . $fH . 'px"></span>';
        }
        echo '</div><small>' . htmlspecialchars(date('M j', strtotime($dayRow['day']))) . '</small></div>';
    }
    echo '</div>';
}

function bakery_login_history_render_page_rank(array $pageRow, string $view = 'usage'): void
{
    $href = bakery_login_history_url(['module' => $pageRow['module_key'], 'view' => $view]);
    $screen = bakery_login_history_screen_href((string)$pageRow['module_key']);
    $dwell = (int)($pageRow['avg_dwell'] ?? 0);
    echo '<article class="login-history-rank__row">';
    echo '<a href="' . htmlspecialchars($href) . '">';
    echo '<div><strong>' . htmlspecialchars((string)$pageRow['label']) . '</strong>';
    echo '<small>' . htmlspecialchars((string)$pageRow['area_label']) . ' · ' . (int)$pageRow['people'] . ' ' . htmlspecialchars(bakery_t('login_history.people_count'));
    if ($dwell > 0) {
        echo ' · ' . htmlspecialchars(bakery_t('login_history.dwell') . ' ' . bakery_login_history_duration($dwell));
    }
    echo '</small></div><b>' . (int)$pageRow['visits'] . '</b></a>';
    if ($screen !== '') {
        echo '<a class="login-history-open" href="' . htmlspecialchars($screen) . '">' . htmlspecialchars(bakery_t('login_history.open_screen')) . '</a>';
    }
    echo '</article>';
}

function bakery_login_history_render_person_card(array $personRow, bool $compact = false): void
{
    ?>
    <article class="login-history-person">
      <div>
        <strong><span class="login-history-dot<?php echo !empty($personRow['is_live']) ? ' is-live' : ''; ?>"></span><?php echo htmlspecialchars((string)$personRow['display_name']); ?></strong>
        <small><?php echo htmlspecialchars((string)$personRow['role_name']); ?></small>
        <a href="<?php echo htmlspecialchars(bakery_login_history_url(['subject' => $personRow['subject'], 'user_id' => (int)$personRow['user_id'], 'customer_id' => (int)$personRow['customer_id']])); ?>#investigation"><?php bakery_te('login_history.investigate'); ?></a>
      </div>
      <div>
        <small><?php bakery_te('login_history.top_page'); ?></small>
        <strong><?php echo htmlspecialchars((string)($personRow['top_page_label'] ?: bakery_t('login_history.unknown_page'))); ?></strong>
        <?php if (!$compact): ?><small><?php bakery_te('login_history.last_seen'); ?> <?php echo htmlspecialchars(bakery_login_history_ago((string)$personRow['last_seen_at'])); ?></small><?php endif; ?>
      </div>
      <div>
        <small><?php echo $compact ? bakery_t('login_history.last_seen') : bakery_t('login_history.sessions'); ?></small>
        <strong><?php echo $compact ? htmlspecialchars(bakery_login_history_ago((string)$personRow['last_seen_at'])) : (int)$personRow['sessions']; ?></strong>
        <small><?php echo (int)$personRow['sessions']; ?> · <?php echo (int)$personRow['pages']; ?><?php if (!$compact): ?> · <?php echo (int)$personRow['failed']; ?><?php endif; ?></small>
      </div>
    </article>
    <?php
}
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/login_history.css'); ?>">
<main class="login-history-page">
  <header class="login-history-hero">
    <p class="login-history-kicker"><?php bakery_te('login_history.kicker'); ?></p>
    <h1><?php bakery_te('page.login_history'); ?></h1>
    <p class="login-history-lead"><?php bakery_te('login_history.lead'); ?></p>
  </header>

  <?php if (!empty($data['briefing'])): ?>
    <section class="login-history-briefing" aria-label="<?php echo htmlspecialchars(bakery_t('login_history.briefing')); ?>">
      <p class="login-history-briefing__kicker"><?php bakery_te('login_history.briefing'); ?></p>
      <ul>
        <?php foreach ($data['briefing'] as $line): ?>
          <li><?php echo htmlspecialchars((string)$line); ?></li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <nav class="login-history-ranges" aria-label="<?php echo htmlspecialchars(bakery_t('login_history.range_label')); ?>">
    <?php foreach (['today' => 'login_history.range_today', '7d' => 'login_history.range_7d', '14d' => 'login_history.range_14d', '30d' => 'login_history.range_30d', 'all' => 'login_history.range_all'] as $range => $labelKey): ?>
      <a class="<?php echo $filters['range'] === $range ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars(bakery_login_history_url(['range' => $range, 'from' => '', 'until' => '', 'page' => 1])); ?>"><?php bakery_te($labelKey); ?></a>
    <?php endforeach; ?>
  </nav>

  <form class="login-history-filters" method="get">
    <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
    <div class="login-history-filters__primary">
      <label><?php bakery_te('login_history.from'); ?> <input type="date" name="from" value="<?php echo htmlspecialchars($filters['from']); ?>"></label>
      <label><?php bakery_te('login_history.through'); ?> <input type="date" name="until" value="<?php echo htmlspecialchars($filters['until']); ?>"></label>
      <label><?php bakery_te('login_history.person'); ?>
        <select name="subject" onchange="this.form.submit()">
          <option value=""><?php bakery_te('login_history.person_all'); ?></option>
          <?php if ($options['users']): ?>
          <optgroup label="<?php echo htmlspecialchars(bakery_t('login_history.person_staff')); ?>">
            <?php foreach ($options['users'] as $u): ?>
              <option value="s-<?php echo (int)$u['id']; ?>"<?php echo $filters['subject'] === 's-' . (int)$u['id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars($u['display_name'] . ' · ' . $u['role_name']); ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
          <?php if ($options['customers']): ?>
          <optgroup label="<?php echo htmlspecialchars(bakery_t('login_history.person_customers')); ?>">
            <?php foreach ($options['customers'] as $c): ?>
              <option value="c-<?php echo (int)$c['id']; ?>"<?php echo $filters['subject'] === 'c-' . (int)$c['id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars((string)$c['name']); ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
        </select>
      </label>
      <button type="submit"><?php bakery_te('login_history.apply'); ?></button>
      <?php if (bakery_login_history_has_filters($filters)): ?><a href="<?php echo htmlspecialchars(BASE_URL); ?>login_history.php"><?php bakery_te('login_history.clear'); ?></a><?php endif; ?>
    </div>
    <details class="login-history-filters__more"<?php echo bakery_login_history_has_filters($filters) && ($filters['role'] || $filters['auth_type'] || $filters['session'] || $filters['device'] || $filters['area'] || $filters['module'] || $filters['q']) ? ' open' : ''; ?>>
      <summary><?php bakery_te('login_history.more_filters'); ?></summary>
      <div class="login-history-filters__grid">
        <label><?php bakery_te('login_history.role'); ?>
          <select name="role">
            <option value=""><?php bakery_te('login_history.role_all'); ?></option>
            <?php foreach ($options['roles'] as $role): ?>
              <option value="<?php echo htmlspecialchars((string)$role['slug']); ?>"<?php echo $filters['role'] === $role['slug'] ? ' selected' : ''; ?>><?php echo htmlspecialchars((string)$role['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><?php bakery_te('login_history.auth_type'); ?>
          <select name="auth_type">
            <option value=""><?php bakery_te('login_history.auth_all'); ?></option>
            <option value="staff"<?php echo $filters['auth_type'] === 'staff' ? ' selected' : ''; ?>><?php bakery_te('login_history.auth_staff'); ?></option>
            <option value="customer"<?php echo $filters['auth_type'] === 'customer' ? ' selected' : ''; ?>><?php bakery_te('login_history.auth_customer'); ?></option>
          </select>
        </label>
        <label><?php bakery_te('login_history.session'); ?>
          <select name="session">
            <option value=""><?php bakery_te('login_history.session_all'); ?></option>
            <option value="live"<?php echo $filters['session'] === 'live' ? ' selected' : ''; ?>><?php bakery_te('login_history.session_live'); ?></option>
            <option value="idle"<?php echo $filters['session'] === 'idle' ? ' selected' : ''; ?>><?php bakery_te('login_history.session_idle'); ?></option>
            <option value="ended"<?php echo $filters['session'] === 'ended' ? ' selected' : ''; ?>><?php bakery_te('login_history.session_ended'); ?></option>
            <option value="failed"<?php echo $filters['session'] === 'failed' ? ' selected' : ''; ?>><?php bakery_te('login_history.session_failed'); ?></option>
          </select>
        </label>
        <label><?php bakery_te('login_history.device'); ?>
          <select name="device">
            <option value=""><?php bakery_te('login_history.device_all'); ?></option>
            <option value="Desktop"<?php echo $filters['device'] === 'Desktop' ? ' selected' : ''; ?>><?php bakery_te('login_history.device_desktop'); ?></option>
            <option value="Mobile"<?php echo $filters['device'] === 'Mobile' ? ' selected' : ''; ?>><?php bakery_te('login_history.device_mobile'); ?></option>
            <option value="Tablet"<?php echo $filters['device'] === 'Tablet' ? ' selected' : ''; ?>><?php bakery_te('login_history.device_tablet'); ?></option>
          </select>
        </label>
        <label><?php bakery_te('login_history.area'); ?>
          <select name="area">
            <option value=""><?php bakery_te('login_history.area_all'); ?></option>
            <?php foreach (bakery_login_history_areas() as $areaKey => $areaLabel): ?>
              <option value="<?php echo htmlspecialchars($areaKey); ?>"<?php echo $filters['area'] === $areaKey ? ' selected' : ''; ?>><?php echo htmlspecialchars($areaLabel); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><?php bakery_te('login_history.page'); ?>
          <select name="module">
            <option value=""><?php bakery_te('login_history.page_all'); ?></option>
            <?php foreach ($options['modules'] as $module): ?>
              <option value="<?php echo htmlspecialchars((string)$module['module_key']); ?>"<?php echo $filters['module'] === $module['module_key'] ? ' selected' : ''; ?>><?php echo htmlspecialchars((string)$module['label']); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><?php bakery_te('login_history.search'); ?> <input type="search" name="q" value="<?php echo htmlspecialchars($filters['q']); ?>" placeholder="<?php echo htmlspecialchars(bakery_t('login_history.search_ph')); ?>"></label>
      </div>
    </details>
  </form>

  <?php if (!empty($data['chips'])): ?>
    <div class="login-history-chips" aria-label="<?php echo htmlspecialchars(bakery_t('login_history.filter_chips')); ?>">
      <?php foreach ($data['chips'] as $chip): ?>
        <a href="<?php echo htmlspecialchars((string)$chip['url']); ?>"><?php echo htmlspecialchars((string)$chip['label']); ?> <span aria-hidden="true">×</span><span class="login-history-sr"><?php bakery_te('login_history.remove_filter'); ?></span></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$ready['audit']): ?>
    <div class="login-history-note" role="alert"><strong><?php bakery_te('login_history.schema_audit'); ?></strong></div>
  <?php elseif (!$ready['context']): ?>
    <div class="login-history-note" role="alert"><strong><?php bakery_te('login_history.schema_context'); ?></strong></div>
  <?php elseif (!$ready['activity']): ?>
    <div class="login-history-note" role="alert"><strong><?php bakery_te('login_history.schema_activity'); ?></strong></div>
  <?php endif; ?>

  <nav class="login-history-tabs" aria-label="<?php echo htmlspecialchars(bakery_t('login_history.views')); ?>">
    <?php
    bakery_login_history_tab($filters, 'overview', bakery_t('login_history.view_overview'));
    bakery_login_history_tab($filters, 'time', bakery_t('login_history.view_time'));
    bakery_login_history_tab($filters, 'usage', bakery_t('login_history.view_usage'));
    bakery_login_history_tab($filters, 'live', bakery_t('login_history.view_live'));
    bakery_login_history_tab($filters, 'records', bakery_t('login_history.view_records'));
    ?>
  </nav>

  <section class="login-history-summary" aria-label="<?php echo htmlspecialchars(bakery_t('login_history.summary')); ?>">
    <div class="login-history-stat"><strong><?php echo (int)$summary['signins']; ?><?php bakery_login_history_render_delta($comparison['signins'] ?? null); ?></strong><span><?php bakery_te('login_history.stat_signins'); ?></span></div>
    <div class="login-history-stat"><strong><?php echo (int)$summary['active']; ?></strong><span><?php bakery_te('login_history.stat_active'); ?></span></div>
    <div class="login-history-stat"><strong><?php echo (int)$summary['users']; ?><?php bakery_login_history_render_delta($comparison['users'] ?? null); ?></strong><span><?php bakery_te('login_history.stat_users'); ?></span></div>
    <div class="login-history-stat"><strong><?php echo htmlspecialchars(bakery_login_history_duration($summary['avg_seconds'])); ?></strong><span><?php bakery_te('login_history.stat_avg'); ?></span></div>
    <div class="login-history-stat"><strong><?php echo (int)$summary['pages']; ?><?php bakery_login_history_render_delta($comparison['pages'] ?? null); ?></strong><span><?php bakery_te('login_history.stat_pages'); ?></span></div>
    <div class="login-history-stat"><strong><?php echo (int)$summary['actions']; ?><?php bakery_login_history_render_delta($comparison['actions'] ?? null); ?></strong><span><?php bakery_te('login_history.stat_actions'); ?></span></div>
  </section>
  <?php if (!empty($comparison['window']['days'])): ?>
    <p class="login-history-compare"><?php bakery_te('login_history.vs_previous', ['n' => (int)$comparison['window']['days']]); ?></p>
  <?php endif; ?>

  <?php if (!empty($investigation['person'])): $person = $investigation['person']; ?>
  <section class="investigation" id="investigation" aria-labelledby="investigation-title">
    <div class="investigation-header">
      <div>
        <div class="investigation-kicker"><?php bakery_te('login_history.investigation'); ?></div>
        <h2 id="investigation-title"><?php echo htmlspecialchars((string)$person['display_name']); ?></h2>
        <p class="investigation-person"><?php echo htmlspecialchars((string)$person['role_name']); ?><?php if (!empty($person['email'])): ?> · <?php echo htmlspecialchars((string)$person['email']); ?><?php endif; ?><?php if ($investigation['last_active']): ?> · <?php bakery_te('login_history.last_seen'); ?> <?php echo htmlspecialchars(bakery_login_history_ago((string)$investigation['last_active'])); ?><?php endif; ?></p>
      </div>
      <nav class="investigation-nav" aria-label="<?php echo htmlspecialchars(bakery_t('login_history.investigation')); ?>">
        <a href="#activity-stream"><?php bakery_te('login_history.activity_stream'); ?></a>
        <a href="<?php echo htmlspecialchars(bakery_login_history_url(['view' => 'records'])); ?>#login-records"><?php bakery_te('login_history.login_records'); ?></a>
        <a href="<?php echo htmlspecialchars(bakery_login_history_url(['user_id' => 0, 'customer_id' => 0, 'subject' => ''])); ?>"><?php bakery_te('login_history.change_person'); ?></a>
      </nav>
    </div>
    <div class="investigation-stats" aria-label="<?php echo htmlspecialchars(bakery_t('login_history.summary')); ?>">
      <div class="investigation-stat"><strong><?php echo (int)$investigation['sessions']; ?></strong><span><?php bakery_te('login_history.stat_sessions'); ?></span></div>
      <div class="investigation-stat"><strong><?php echo (int)$investigation['pages']; ?></strong><span><?php bakery_te('login_history.stat_pages'); ?></span></div>
      <div class="investigation-stat"><strong><?php echo (int)$investigation['actions']; ?></strong><span><?php bakery_te('login_history.stat_actions'); ?></span></div>
      <div class="investigation-stat"><strong><?php echo (int)$investigation['unique_pages']; ?></strong><span><?php bakery_te('login_history.unique_pages'); ?></span></div>
      <div class="investigation-stat"><strong><?php echo (int)$investigation['failed']; ?></strong><span><?php bakery_te('login_history.stat_failed'); ?></span></div>
      <div class="investigation-stat"><strong><?php echo (int)($investigation['timeline_total'] ?? count($investigation['timeline'])); ?></strong><span><?php bakery_te('login_history.stat_events'); ?></span></div>
    </div>
    <?php if (!empty($investigation['top_pages'])): ?>
    <div class="login-history-rank" style="margin-top: .4rem;">
      <?php foreach ($investigation['top_pages'] as $pageRow): ?>
        <a href="<?php echo htmlspecialchars(bakery_login_history_url(['module' => $pageRow['module_key'], 'view' => 'usage'])); ?>">
          <div><strong><?php echo htmlspecialchars((string)$pageRow['label']); ?></strong><small><?php echo htmlspecialchars((string)$pageRow['module_key']); ?></small></div>
          <b><?php echo (int)$pageRow['visits']; ?></b>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="timeline-header" id="activity-stream">
      <div><h3><?php bakery_te('login_history.activity_stream'); ?></h3><p><?php bakery_te('login_history.timeline_help'); ?></p></div>
      <div class="timeline-filters" role="group" aria-label="<?php echo htmlspecialchars(bakery_t('login_history.filter_activity')); ?>">
        <button class="timeline-filter is-active" type="button" data-timeline-filter="all"><?php bakery_te('login_history.filter_all'); ?></button>
        <button class="timeline-filter" type="button" data-timeline-filter="session"><?php bakery_te('login_history.filter_session'); ?></button>
        <button class="timeline-filter" type="button" data-timeline-filter="navigation"><?php bakery_te('login_history.filter_pages'); ?></button>
        <button class="timeline-filter" type="button" data-timeline-filter="action"><?php bakery_te('login_history.filter_actions'); ?></button>
      </div>
    </div>
    <?php $timelineGroups = $investigation['timeline_groups'] ?: bakery_login_history_group_timeline($investigation['timeline']); ?>
    <?php if ($timelineGroups): ?>
      <div class="history-timeline" aria-live="polite">
        <?php foreach ($timelineGroups as $dayKey => $events):
            $dayLabel = $dayKey !== '' ? bakery_login_history_when($dayKey . ' 12:00:00', 'date') : bakery_t('login_history.day_group');
        ?>
          <section class="history-day" data-day="<?php echo htmlspecialchars((string)$dayKey); ?>">
            <h4 class="history-day__title"><?php echo htmlspecialchars($dayLabel); ?></h4>
            <?php foreach ($events as $event): ?>
              <article class="history-event" data-kind="<?php echo htmlspecialchars((string)$event['kind']); ?>">
                <time class="history-event-time" datetime="<?php echo htmlspecialchars(date('c', (int)$event['timestamp'])); ?>"><?php echo htmlspecialchars(date('g:i:s A', (int)$event['timestamp'])); ?></time>
                <h4><?php echo htmlspecialchars((string)$event['title']); ?></h4>
                <?php if ($event['detail']): ?><p><?php echo htmlspecialchars((string)$event['detail']); ?></p><?php endif; ?>
                <?php if ($event['path']): ?>
                  <?php $eventScreen = bakery_login_history_screen_href((string)$event['path']); ?>
                  <?php if ($eventScreen !== ''): ?>
                    <a class="login-history-open" href="<?php echo htmlspecialchars($eventScreen); ?>"><?php echo htmlspecialchars((string)$event['path']); ?></a>
                  <?php else: ?>
                    <code><?php echo htmlspecialchars((string)$event['path']); ?></code>
                  <?php endif; ?>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </section>
        <?php endforeach; ?>
      </div>
      <?php if ((int)($investigation['timeline_total'] ?? 0) > count($investigation['timeline'])): ?>
        <p class="login-history-panel__lead"><?php bakery_te('login_history.more_events'); ?></p>
      <?php endif; ?>
    <?php else: ?>
      <div class="timeline-empty"><?php bakery_te('login_history.no_activity'); ?></div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($view === 'overview'): ?>
    <?php if (!empty($data['roles'])): ?>
    <section class="login-history-panel">
      <div class="login-history-panel__head">
        <div>
          <h2><?php bakery_te('login_history.roles_title'); ?></h2>
          <p class="login-history-panel__lead"><?php bakery_te('login_history.roles_lead'); ?></p>
        </div>
        <a href="<?php echo htmlspecialchars(bakery_login_history_url(['view' => 'live'])); ?>"><?php bakery_te('login_history.see_all'); ?></a>
      </div>
      <div class="login-history-roles">
        <?php foreach ($data['roles'] as $group): ?>
          <article class="login-history-role">
            <header>
              <h3><?php echo htmlspecialchars((string)$group['label']); ?></h3>
              <small><?php echo count($group['live']); ?> / <?php echo (int)$group['total']; ?></small>
            </header>
            <?php if ($group['live']): ?>
              <p class="login-history-role__lab"><?php bakery_te('login_history.roles_live'); ?></p>
              <ul>
                <?php foreach ($group['live'] as $member): ?>
                  <li>
                    <a href="<?php echo htmlspecialchars(bakery_login_history_url(['subject' => $member['subject'], 'user_id' => (int)$member['id']])); ?>#investigation"><span class="login-history-dot is-live"></span><?php echo htmlspecialchars((string)$member['display_name']); ?></a>
                    <small><?php echo htmlspecialchars((string)$member['page_label']); ?> · <?php echo htmlspecialchars(bakery_login_history_ago((string)$member['last_seen_at'])); ?></small>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <?php if ($group['today']): ?>
              <p class="login-history-role__lab"><?php bakery_te('login_history.roles_today'); ?></p>
              <ul class="is-quiet">
                <?php foreach ($group['today'] as $member): ?>
                  <li>
                    <a href="<?php echo htmlspecialchars(bakery_login_history_url(['subject' => $member['subject'], 'user_id' => (int)$member['id']])); ?>#investigation"><?php echo htmlspecialchars((string)$member['display_name']); ?></a>
                    <small><?php echo htmlspecialchars(bakery_login_history_ago((string)$member['last_seen_at'])); ?></small>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <?php if ($group['quiet']): ?>
              <p class="login-history-role__lab"><?php bakery_te('login_history.roles_quiet'); ?></p>
              <ul class="is-quiet">
                <?php foreach ($group['quiet'] as $member): ?>
                  <li>
                    <a href="<?php echo htmlspecialchars(bakery_login_history_url(['subject' => $member['subject'], 'user_id' => (int)$member['id']])); ?>#investigation"><?php echo htmlspecialchars((string)$member['display_name']); ?></a>
                    <small><?php echo $member['last_seen_at'] ? htmlspecialchars(bakery_login_history_ago((string)$member['last_seen_at'])) : htmlspecialchars(bakery_t('login_history.never_seen')); ?></small>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php elseif (!$group['quiet']): ?>
              <p class="login-history-empty is-inline"><?php bakery_te('login_history.roles_ok'); ?></p>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($data['failures'])): ?>
    <section class="login-history-panel login-history-panel--watch">
      <div class="login-history-panel__head">
        <div>
          <h2><?php bakery_te('login_history.failures_title'); ?></h2>
          <p class="login-history-panel__lead"><?php bakery_te('login_history.failures_lead'); ?></p>
        </div>
        <a href="<?php echo htmlspecialchars(bakery_login_history_url(['view' => 'records', 'session' => 'failed'])); ?>"><?php bakery_te('login_history.see_all'); ?></a>
      </div>
      <div class="login-history-watch">
        <?php foreach ($data['failures'] as $failRow): ?>
          <article>
            <strong><?php echo htmlspecialchars((string)$failRow['principal']); ?></strong>
            <small><?php echo htmlspecialchars((string)($failRow['ip_address'] ?: bakery_t('common.unavailable'))); ?> · <?php echo htmlspecialchars((string)$failRow['auth_type']); ?></small>
            <b><?php echo (int)$failRow['n']; ?></b>
            <small><?php echo htmlspecialchars(bakery_login_history_ago((string)$failRow['last_at'])); ?></small>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <div class="login-history-grid">
      <section class="login-history-panel">
        <div class="login-history-panel__head">
          <div>
            <h2><?php bakery_te('login_history.live_title'); ?></h2>
            <p class="login-history-panel__lead"><?php bakery_te('login_history.live_lead'); ?></p>
          </div>
          <a href="<?php echo htmlspecialchars(bakery_login_history_url(['view' => 'live'])); ?>"><?php bakery_te('login_history.see_all'); ?></a>
        </div>
        <?php if ($data['live']): ?>
          <div class="login-history-presence">
            <?php foreach ($data['live'] as $row): ?>
              <article>
                <h3><span class="login-history-dot is-live"></span><?php echo htmlspecialchars((string)$row['display_name']); ?></h3>
                <small><?php echo htmlspecialchars((string)$row['role_label']); ?> · <?php echo htmlspecialchars($row['page_label'] ?: bakery_t('login_history.unknown_page')); ?></small>
                <small><?php echo htmlspecialchars(bakery_login_history_ago((string)$row['last_seen_at'])); ?> · <?php echo htmlspecialchars(bakery_login_history_duration($row['duration_seconds'])); ?></small>
              </article>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="login-history-empty"><?php bakery_te('login_history.no_live'); ?></div>
        <?php endif; ?>
      </section>
      <section class="login-history-panel">
        <div class="login-history-panel__head">
          <div>
            <h2><?php bakery_te('login_history.pages_title'); ?></h2>
            <p class="login-history-panel__lead"><?php bakery_te('login_history.pages_lead'); ?></p>
          </div>
          <a href="<?php echo htmlspecialchars(bakery_login_history_url(['view' => 'usage'])); ?>"><?php bakery_te('login_history.see_all'); ?></a>
        </div>
        <?php if ($data['pages']): ?>
          <div class="login-history-rank">
            <?php foreach ($data['pages'] as $pageRow) { bakery_login_history_render_page_rank($pageRow); } ?>
          </div>
        <?php else: ?>
          <div class="login-history-empty"><?php bakery_te('login_history.no_pages'); ?></div>
        <?php endif; ?>
      </section>
    </div>

    <?php
    $overviewDays = array_slice($data['daily']['rows'] ?? [], -14);
    $overviewMaxS = 1;
    $overviewMaxP = 1;
    $overviewMaxF = 0;
    foreach ($overviewDays as $dayRow) {
        $overviewMaxS = max($overviewMaxS, (int)$dayRow['signins']);
        $overviewMaxP = max($overviewMaxP, (int)$dayRow['pages']);
        $overviewMaxF = max($overviewMaxF, (int)($dayRow['failures'] ?? 0));
    }
    ?>
    <section class="login-history-panel">
      <div class="login-history-panel__head">
        <div>
          <h2><?php bakery_te('login_history.daily_title'); ?></h2>
          <p class="login-history-panel__lead"><?php bakery_te('login_history.daily_lead'); ?></p>
        </div>
        <a href="<?php echo htmlspecialchars(bakery_login_history_url(['view' => 'time'])); ?>"><?php bakery_te('login_history.see_all'); ?></a>
      </div>
      <?php if ($overviewDays && ($overviewMaxS > 1 || $overviewMaxP > 1 || $overviewMaxF > 0 || array_sum(array_map(static function ($row) { return (int)$row['signins'] + (int)$row['pages'] + (int)($row['failures'] ?? 0); }, $overviewDays)) > 0)): ?>
        <?php bakery_login_history_render_daily_chart($overviewDays, $overviewMaxS, $overviewMaxP, $overviewMaxF); ?>
        <div class="login-history-legend">
          <span><i></i><?php bakery_te('login_history.chart_signins'); ?></span>
          <span><i class="is-pages"></i><?php bakery_te('login_history.chart_pages'); ?></span>
          <?php if ($overviewMaxF > 0): ?><span><i class="is-fail"></i><?php bakery_te('login_history.chart_failures'); ?></span><?php endif; ?>
        </div>
      <?php else: ?>
        <div class="login-history-empty"><?php bakery_te('login_history.no_chart'); ?></div>
      <?php endif; ?>
    </section>

    <section class="login-history-panel">
      <div class="login-history-panel__head">
        <div>
          <h2><?php bakery_te('login_history.people_title'); ?></h2>
          <p class="login-history-panel__lead"><?php bakery_te('login_history.people_lead'); ?></p>
        </div>
        <a href="<?php echo htmlspecialchars(bakery_login_history_url(['view' => 'usage'])); ?>"><?php bakery_te('login_history.see_all'); ?></a>
      </div>
      <?php if ($data['people']): ?>
        <div class="login-history-people">
          <?php foreach ($data['people'] as $personRow) { bakery_login_history_render_person_card($personRow, true); } ?>
        </div>
      <?php else: ?>
        <div class="login-history-empty"><?php bakery_te('login_history.no_people'); ?></div>
      <?php endif; ?>
    </section>

    <section class="login-history-list" id="login-records" aria-label="<?php echo htmlspecialchars(bakery_t('login_history.login_records')); ?>">
      <div class="login-history-panel__head">
        <h2><?php bakery_te('login_history.recent_records'); ?></h2>
        <a href="<?php echo htmlspecialchars(bakery_login_history_url(['view' => 'records'])); ?>"><?php bakery_te('login_history.see_all'); ?></a>
      </div>
      <?php if (!$data['recent']): ?><div class="login-history-empty"><?php echo $ready['audit'] ? bakery_t('login_history.no_records') : bakery_t('login_history.no_storage'); ?></div><?php endif; ?>
      <?php foreach ($data['recent'] as $row) { bakery_login_history_render_session($row); } ?>
    </section>

  <?php elseif ($view === 'time'):
      $daily = $data['daily'];
      $workHeat = $data['work_heatmap'];
      $heat = $data['heatmap'];
      $maxS = max(1, (int)$daily['max_signins']);
      $maxP = max(1, (int)$daily['max_pages']);
      $maxF = (int)$daily['max_failures'];
  ?>
    <section class="login-history-panel">
      <h2><?php bakery_te('login_history.daily_title'); ?></h2>
      <p class="login-history-panel__lead"><?php bakery_te('login_history.daily_lead'); ?></p>
      <?php if ($daily['rows'] && ((int)$daily['max_signins'] > 0 || (int)$daily['max_pages'] > 0 || $maxF > 0)): ?>
        <?php bakery_login_history_render_daily_chart($daily['rows'], $maxS, $maxP, $maxF); ?>
        <div class="login-history-legend">
          <span><i></i><?php bakery_te('login_history.chart_signins'); ?></span>
          <span><i class="is-pages"></i><?php bakery_te('login_history.chart_pages'); ?></span>
          <?php if ($maxF > 0): ?><span><i class="is-fail"></i><?php bakery_te('login_history.chart_failures'); ?></span><?php endif; ?>
        </div>
      <?php else: ?>
        <div class="login-history-empty"><?php bakery_te('login_history.no_chart'); ?></div>
      <?php endif; ?>
    </section>
    <section class="login-history-panel">
      <h2><?php bakery_te('login_history.work_heat_title'); ?></h2>
      <p class="login-history-panel__lead"><?php bakery_te('login_history.work_heat_lead'); ?></p>
      <?php bakery_login_history_render_heatmap($workHeat, $dayNames, 'login_history.work_heat_title', 'login_history.work_heat_empty'); ?>
    </section>
    <div class="login-history-grid">
      <section class="login-history-panel">
        <h2><?php bakery_te('login_history.heatmap_title'); ?></h2>
        <p class="login-history-panel__lead"><?php bakery_te('login_history.heatmap_lead'); ?></p>
        <?php bakery_login_history_render_heatmap($heat, $dayNames, 'login_history.heatmap_title'); ?>
      </section>
      <section class="login-history-panel">
        <h2><?php bakery_te('login_history.weekday_title'); ?></h2>
        <p class="login-history-panel__lead"><?php bakery_te('login_history.weekday_lead'); ?></p>
        <ul class="login-history-weekdays">
          <?php for ($d = 0; $d < 7; $d++):
              $n = (int)($workHeat['weekday'][$d] ?? $heat['weekday'][$d] ?? 0);
              $weekdayMax = (int)($workHeat['weekday_max'] ?? $heat['weekday_max'] ?? 0);
              $pct = $weekdayMax > 0 ? (int)round(100 * $n / $weekdayMax) : 0;
          ?>
            <li>
              <span><?php echo htmlspecialchars($dayNames[$d + 1] ?? ''); ?></span>
              <div class="login-history-meter"><span style="width: <?php echo $pct; ?>%"></span></div>
              <strong><?php echo $n; ?></strong>
            </li>
          <?php endfor; ?>
        </ul>
        <h3 style="margin: 1.1rem 0 .45rem;"><?php bakery_te('login_history.hour_title'); ?></h3>
        <div class="login-history-hours">
          <?php
          $hourly = $workHeat['hourly'] ?? $heat['hourly'] ?? [];
          $hourlyMax = (int)($workHeat['hourly_max'] ?? $heat['hourly_max'] ?? 0);
          for ($h = 0; $h < 24; $h++):
              $n = (int)($hourly[$h] ?? 0);
              $pct = $hourlyMax > 0 ? max($n ? 8 : 0, (int)round(100 * $n / $hourlyMax)) : 0;
          ?>
            <span title="<?php echo htmlspecialchars(bakery_login_history_format_hour($h) . ' · ' . $n); ?>" style="height: <?php echo $pct; ?>%"></span>
          <?php endfor; ?>
        </div>
        <div class="login-history-hours-labels"><?php for ($h = 0; $h < 24; $h++): ?><span><?php echo $h % 3 === 0 ? $h : ''; ?></span><?php endfor; ?></div>
      </section>
    </div>

  <?php elseif ($view === 'usage'): ?>
    <?php if (!empty($data['workflows'])): ?>
    <section class="login-history-panel">
      <h2><?php bakery_te('login_history.known_flows_title'); ?></h2>
      <p class="login-history-panel__lead"><?php bakery_te('login_history.known_flows_lead'); ?></p>
      <ul class="login-history-flow">
        <?php foreach ($data['workflows'] as $flow): ?>
          <li>
            <span class="login-history-chip is-flow"><?php echo htmlspecialchars((string)$flow['label']); ?></span>
            <a class="login-history-chip" href="<?php echo htmlspecialchars(bakery_login_history_url(['module' => $flow['from_key']])); ?>"><?php echo htmlspecialchars((string)$flow['from_label']); ?></a>
            <span class="login-history-arrow">→</span>
            <a class="login-history-chip" href="<?php echo htmlspecialchars(bakery_login_history_url(['module' => $flow['to_key']])); ?>"><?php echo htmlspecialchars((string)$flow['to_label']); ?></a>
            <span class="login-history-chip is-count"><?php echo (int)$flow['n']; ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
    <div class="login-history-grid">
      <section class="login-history-panel">
        <h2><?php bakery_te('login_history.pages_title'); ?></h2>
        <p class="login-history-panel__lead"><?php bakery_te('login_history.pages_lead'); ?></p>
        <?php if ($data['pages']): ?>
          <div class="login-history-rank">
            <?php foreach ($data['pages'] as $pageRow) { bakery_login_history_render_page_rank($pageRow, 'usage'); } ?>
          </div>
        <?php else: ?>
          <div class="login-history-empty"><?php bakery_te('login_history.no_pages'); ?></div>
        <?php endif; ?>
      </section>
      <section class="login-history-panel">
        <h2><?php bakery_te('login_history.areas_title'); ?></h2>
        <p class="login-history-panel__lead"><?php bakery_te('login_history.areas_lead'); ?></p>
        <?php if ($data['areas']): ?>
          <ul class="login-history-weekdays">
            <?php
            $areaMax = 1;
            foreach ($data['areas'] as $areaRow) { $areaMax = max($areaMax, (int)$areaRow['visits']); }
            foreach ($data['areas'] as $areaRow):
                $pct = (int)round(100 * (int)$areaRow['visits'] / $areaMax);
            ?>
              <li>
                <span><?php echo htmlspecialchars((string)$areaRow['label']); ?></span>
                <div class="login-history-meter"><span style="width: <?php echo $pct; ?>%"></span></div>
                <strong><?php echo (int)$areaRow['visits']; ?></strong>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="login-history-empty"><?php bakery_te('login_history.no_pages'); ?></div>
        <?php endif; ?>
        <?php if ($data['devices']): ?>
          <h3 style="margin: 1.1rem 0 .45rem;"><?php bakery_te('login_history.devices_title'); ?></h3>
          <ul class="login-history-weekdays">
            <?php
            $deviceMax = 1;
            foreach ($data['devices'] as $deviceRow) { $deviceMax = max($deviceMax, (int)$deviceRow['n']); }
            foreach ($data['devices'] as $deviceRow):
                $pct = (int)round(100 * (int)$deviceRow['n'] / $deviceMax);
            ?>
              <li>
                <span><?php echo htmlspecialchars((string)$deviceRow['device_type']); ?></span>
                <div class="login-history-meter"><span style="width: <?php echo $pct; ?>%"></span></div>
                <strong><?php echo (int)$deviceRow['n']; ?></strong>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    </div>
    <section class="login-history-panel">
      <h2><?php bakery_te('login_history.people_title'); ?></h2>
      <p class="login-history-panel__lead"><?php bakery_te('login_history.people_lead'); ?></p>
      <?php if ($data['people']): ?>
        <div class="login-history-people">
          <?php foreach ($data['people'] as $personRow) { bakery_login_history_render_person_card($personRow, false); } ?>
        </div>
      <?php else: ?>
        <div class="login-history-empty"><?php bakery_te('login_history.no_people'); ?></div>
      <?php endif; ?>
    </section>
    <div class="login-history-grid">
      <section class="login-history-panel">
        <h2><?php bakery_te('login_history.workflows_title'); ?></h2>
        <p class="login-history-panel__lead"><?php bakery_te('login_history.workflows_lead'); ?></p>
        <?php if ($data['transitions']): ?>
          <ul class="login-history-flow">
            <?php foreach ($data['transitions'] as $step): ?>
              <li>
                <a class="login-history-chip" href="<?php echo htmlspecialchars(bakery_login_history_url(['module' => $step['from_key']])); ?>"><?php echo htmlspecialchars((string)$step['from_label']); ?></a>
                <span class="login-history-arrow">→</span>
                <a class="login-history-chip" href="<?php echo htmlspecialchars(bakery_login_history_url(['module' => $step['to_key']])); ?>"><?php echo htmlspecialchars((string)$step['to_label']); ?></a>
                <span class="login-history-chip is-count"><?php echo (int)$step['n']; ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="login-history-empty"><?php bakery_te('login_history.no_transitions'); ?></div>
        <?php endif; ?>
      </section>
      <section class="login-history-panel">
        <h2><?php bakery_te('login_history.actions_title'); ?></h2>
        <p class="login-history-panel__lead"><?php bakery_te('login_history.actions_lead'); ?></p>
        <?php if ($data['actions']): ?>
          <div class="login-history-rank">
            <?php foreach ($data['actions'] as $actionRow): ?>
              <article>
                <div><strong><?php echo htmlspecialchars((string)$actionRow['label']); ?></strong><small><?php echo htmlspecialchars(bakery_login_history_when((string)$actionRow['last_at'])); ?></small></div>
                <b><?php echo (int)$actionRow['n']; ?></b>
              </article>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="login-history-empty"><?php bakery_te('login_history.no_actions'); ?></div>
        <?php endif; ?>
      </section>
    </div>
    <section class="login-history-panel">
      <h2><?php bakery_te('login_history.paths_title'); ?></h2>
      <p class="login-history-panel__lead"><?php bakery_te('login_history.paths_lead'); ?></p>
      <?php if ($data['session_paths']): ?>
        <div class="login-history-presence">
          <?php foreach ($data['session_paths'] as $pathRow): ?>
            <div class="login-history-path">
              <p><strong><?php echo htmlspecialchars((string)$pathRow['display_name']); ?></strong> · <?php echo htmlspecialchars(bakery_login_history_when((string)$pathRow['login_at'])); ?></p>
              <ol>
                <?php foreach ($pathRow['labels'] as $label): ?>
                  <li class="login-history-chip"><?php echo htmlspecialchars((string)$label); ?></li>
                <?php endforeach; ?>
              </ol>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="login-history-empty"><?php bakery_te('login_history.no_paths'); ?></div>
      <?php endif; ?>
    </section>

  <?php elseif ($view === 'live'): ?>
    <?php if (!empty($data['roles'])): ?>
    <section class="login-history-panel">
      <h2><?php bakery_te('login_history.roles_title'); ?></h2>
      <p class="login-history-panel__lead"><?php bakery_te('login_history.roles_lead'); ?></p>
      <div class="login-history-roles">
        <?php foreach ($data['roles'] as $group): ?>
          <article class="login-history-role">
            <header>
              <h3><?php echo htmlspecialchars((string)$group['label']); ?></h3>
              <small><?php echo count($group['live']); ?> / <?php echo (int)$group['total']; ?></small>
            </header>
            <?php if ($group['live']): ?>
              <ul>
                <?php foreach ($group['live'] as $member): ?>
                  <li>
                    <a href="<?php echo htmlspecialchars(bakery_login_history_url(['subject' => $member['subject'], 'user_id' => (int)$member['id']])); ?>#investigation"><span class="login-history-dot is-live"></span><?php echo htmlspecialchars((string)$member['display_name']); ?></a>
                    <small><?php echo htmlspecialchars((string)$member['page_label']); ?></small>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <p class="login-history-empty is-inline"><?php bakery_te('login_history.no_live'); ?></p>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
    <section class="login-history-panel">
      <h2><?php bakery_te('login_history.live_title'); ?></h2>
      <p class="login-history-panel__lead"><?php bakery_te('login_history.live_lead'); ?></p>
      <?php if ($data['live']): ?>
        <div class="login-history-list">
          <?php foreach ($data['live'] as $row) { bakery_login_history_render_session($row); } ?>
        </div>
      <?php else: ?>
        <div class="login-history-empty"><?php bakery_te('login_history.no_live'); ?></div>
      <?php endif; ?>
    </section>
    <section class="login-history-panel">
      <h2><?php bakery_te('login_history.idle_title'); ?></h2>
      <p class="login-history-panel__lead"><?php bakery_te('login_history.idle_lead'); ?></p>
      <?php if ($data['idle']): ?>
        <div class="login-history-list">
          <?php foreach ($data['idle'] as $row) { bakery_login_history_render_session($row); } ?>
        </div>
      <?php else: ?>
        <div class="login-history-empty"><?php bakery_te('login_history.no_idle'); ?></div>
      <?php endif; ?>
    </section>

  <?php else:
      $records = $data['records'];
  ?>
    <section class="login-history-list" id="login-records" aria-label="<?php echo htmlspecialchars(bakery_t('login_history.login_records')); ?>">
      <div class="login-history-panel__head">
        <h2><?php bakery_te('login_history.login_records'); ?></h2>
        <a href="<?php echo htmlspecialchars(bakery_login_history_url(['export' => 'csv'])); ?>"><?php bakery_te('login_history.export_csv'); ?></a>
      </div>
      <?php if (!$records['rows']): ?><div class="login-history-empty"><?php echo $ready['audit'] ? bakery_t('login_history.no_records') : bakery_t('login_history.no_storage'); ?></div><?php endif; ?>
      <?php foreach ($records['rows'] as $row) { bakery_login_history_render_session($row); } ?>
    </section>
    <?php if (($records['last_page'] ?? 1) > 1): ?>
      <div class="login-history-pager">
        <span><?php echo htmlspecialchars(bakery_t('login_history.page_n_of', ['page' => (int)$records['page'], 'last' => (int)$records['last_page'], 'total' => (int)$records['total']])); ?></span>
        <span>
          <?php if ($records['page'] > 1): ?><a href="<?php echo htmlspecialchars(bakery_login_history_url(['page' => $records['page'] - 1])); ?>"><?php bakery_te('login_history.previous'); ?></a><?php endif; ?>
          <?php if ($records['page'] < $records['last_page']): ?><?php echo $records['page'] > 1 ? ' · ' : ''; ?><a href="<?php echo htmlspecialchars(bakery_login_history_url(['page' => $records['page'] + 1])); ?>"><?php bakery_te('login_history.next'); ?></a><?php endif; ?>
        </span>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <p class="login-history-privacy"><?php bakery_te('login_history.privacy_footer'); ?></p>
</main>
<script>
document.querySelectorAll('[data-timeline-filter]').forEach(function (button) {
  button.addEventListener('click', function () {
    var filter = button.getAttribute('data-timeline-filter');
    document.querySelectorAll('[data-timeline-filter]').forEach(function (item) { item.classList.toggle('is-active', item === button); });
    document.querySelectorAll('.history-day').forEach(function (day) {
      var visible = false;
      day.querySelectorAll('.history-event').forEach(function (event) {
        var hidden = filter !== 'all' && event.getAttribute('data-kind') !== filter;
        event.hidden = hidden;
        if (!hidden) visible = true;
      });
      day.hidden = !visible;
    });
    document.querySelectorAll('.history-timeline > .history-event').forEach(function (event) {
      event.hidden = filter !== 'all' && event.getAttribute('data-kind') !== filter;
    });
  });
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
