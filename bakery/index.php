<?php
/**
 * Operations dashboard — daily command center for bakery staff.
 *
 * Managers/admins: exception-driven operating-day command center.
 * Drivers: redirected to driver.php.
 * Bakers: quick links toward production / pack (routed away from ops metrics).
 */
define('ACCESS_ALLOWED', true);

require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/dashboard_command_center.php';
require_once 'includes/daily_order_generation.php';
require_once 'includes/demand_confirmation.php';
require_once 'includes/operational_exceptions.php';

$user = bakery_current_user();
$isDriver = $user && bakery_is_driver_route_role($user['role_slug'] ?? '');
$isBaker = $user && $user['role_slug'] === 'baker';
if ($isDriver) {
    header('Location: ' . BASE_URL . 'driver.php');
    exit;
}
$page_title = $isBaker ? bakery_t('page.index_baker') : bakery_t('page.index');
$today = date('Y-m-d');
$selectedDate = bakery_dashboard_resolve_date();
// Baker workflow targets the next calendar day (Fri → Sat production)
if ($isBaker && !isset($_GET['date'])) {
    $selectedDate = date('Y-m-d', strtotime('+1 day'));
}
$dayNames = bakery_day_names();
$weekday = bakery_standing_day_from_date($selectedDate);
$dayLabel = $dayNames[$weekday] ?? date('l', strtotime($selectedDate));
$dateDisplay = date('l, F j, Y', strtotime($selectedDate));
$isToday = ($selectedDate === $today);
$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

$dbError = null;
$commandCenter = null;
$chartData = [];
$tomorrowDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
$tomorrowReadiness = null;

try {
    try {
        bakery_fill_demand_horizon($db, $selectedDate, ['record_event' => !$isBaker]);
    } catch (Throwable $horizonEx) {
        error_log('dashboard demand horizon: ' . $horizonEx->getMessage());
    }
    if ($isBaker) {
        // Baker dashboard needs no ops metrics.
    } else {
        $commandCenter = bakery_dashboard_command_center($db, $selectedDate);
        try {
            $chartData = bakery_dashboard_orders_by_day($db, $selectedDate, 7);
        } catch (Throwable $chartEx) {
            error_log('dashboard chart: ' . $chartEx->getMessage());
            $chartData = [];
            $dbError = bakery_dashboard_safe_error_message($chartEx);
        }
        try {
            $tomorrowReadiness = bakery_demand_readiness($db, $tomorrowDate);
        } catch (Throwable $tomorrowEx) {
            error_log('dashboard tomorrow readiness: ' . $tomorrowEx->getMessage());
            $tomorrowReadiness = null;
        }
    }
} catch (Throwable $e) {
    error_log('dashboard command center: ' . $e->getMessage());
    $dbError = bakery_dashboard_safe_error_message($e);
    $commandCenter = null;
}

$chartMax = 1;
foreach ($chartData as $bar) {
    if ($bar['count'] > $chartMax) {
        $chartMax = $bar['count'];
    }
}

$stageStateLabel = static function (string $state): string {
    switch ($state) {
        case 'ok':
            return bakery_t('common.on_track');
        case 'attention':
            return bakery_t('common.needs_attention');
        case 'empty':
            return bakery_t('common.nothing_yet');
        case 'unknown':
            return bakery_t('common.unavailable');
        default:
            return ucfirst($state);
    }
};

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/dashboard.css'); ?>">

<div class="ops-dashboard">
    <header class="ops-header">
        <div class="ops-header-main">
            <p class="ops-eyebrow"><?php echo $isBaker ? bakery_t('dashboard.baker_eyebrow') : bakery_t('dashboard.ops_eyebrow'); ?></p>
            <h1><?php echo $isBaker ? bakery_t('dashboard.baker_title') : bakery_t('dashboard.ops_title'); ?></h1>
            <p class="ops-date-line<?php echo $isToday ? ' is-today' : ''; ?>">
                <span class="ops-date-strong"><?php echo htmlspecialchars($dateDisplay); ?></span>
                <?php if ($isToday): ?>
                    <span class="ops-date-pill"><?php bakery_te('common.today'); ?></span>
                <?php else: ?>
                    <span class="ops-date-pill ops-date-pill-muted"><?php bakery_te('common.selected_day'); ?></span>
                <?php endif; ?>
            </p>
        </div>
        <?php if (!$isBaker): ?>
        <nav class="ops-date-nav" aria-label="<?php bakery_te('dashboard.date_nav_aria'); ?>">
            <a href="?date=<?php echo urlencode($prevDate); ?>"><?php bakery_te('common.prev'); ?></a>
            <?php if (!$isToday): ?>
                <a href="?date=<?php echo urlencode($today); ?>" class="ops-today-link"><?php bakery_te('common.today'); ?></a>
            <?php endif; ?>
            <a href="?date=<?php echo urlencode($nextDate); ?>"><?php bakery_te('common.next'); ?></a>
            <form class="ops-date-jump" method="get" action="">
                <label class="ops-sr-only" for="ops-date-input"><?php bakery_te('common.operating_date'); ?></label>
                <input id="ops-date-input" type="date" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>">
                <button type="submit"><?php bakery_te('common.go'); ?></button>
            </form>
        </nav>
        <?php endif; ?>
    </header>

    <?php if ($isBaker): ?>
        <section class="ops-section">
            <h2><?php bakery_te('common.quick_links'); ?></h2>
            <div class="ops-quick-links">
                <a class="ops-quick-link" href="<?php echo htmlspecialchars(BASE_URL); ?>production.php?date=<?php echo urlencode($selectedDate); ?>">
                    <span class="ops-quick-link-icon" aria-hidden="true">⚙️</span>
                    <span>
                        <div class="ops-quick-link-title"><?php bakery_te('dashboard.production'); ?></div>
                        <div class="ops-quick-link-desc"><?php echo htmlspecialchars(bakery_t('dashboard.production_desc', ['day' => $dayLabel])); ?></div>
                    </span>
                </a>
                <a class="ops-quick-link" href="<?php echo htmlspecialchars(BASE_URL); ?>pack_list.php?date=<?php echo urlencode($selectedDate); ?>">
                    <span class="ops-quick-link-icon" aria-hidden="true">📦</span>
                    <span>
                        <div class="ops-quick-link-title"><?php bakery_te('nav.pack_list'); ?></div>
                        <div class="ops-quick-link-desc"><?php echo htmlspecialchars(bakery_t('dashboard.pack_list_desc', ['day' => $dayLabel])); ?></div>
                    </span>
                </a>
            </div>
        </section>

    <?php else: ?>
        <?php echo bakery_ops_render_flash(bakery_ops_read_flash()); ?>
        <?php if ($dbError && !$commandCenter): ?>
            <div class="ops-alert ops-alert-danger" role="alert">
                The command center could not load this day's data. This is <strong>not</strong> a quiet zero day —
                <?php echo htmlspecialchars($dbError); ?>
            </div>
        <?php elseif ($commandCenter && !empty($commandCenter['section_errors'])): ?>
            <div class="ops-alert ops-alert-warning" role="alert">
                Some sections could not be loaded and are marked Unavailable below
                (not shown as zero).
                <?php if ($dbError): ?>
                    <?php echo htmlspecialchars($dbError); ?>
                <?php endif; ?>
            </div>
        <?php elseif ($dbError): ?>
            <div class="ops-alert ops-alert-warning" role="alert">
                Trend chart unavailable: <?php echo htmlspecialchars($dbError); ?>
            </div>
        <?php endif; ?>

        <?php if ($commandCenter): ?>
            <?php
            $exceptions = bakery_ops_enrich_exceptions($commandCenter['exceptions'], $selectedDate, 'dashboard');
            $criticalCount = 0;
            $warningCount = 0;
            foreach ($exceptions as $ex) {
                if (($ex['severity'] ?? '') === 'critical') {
                    $criticalCount++;
                } elseif (($ex['severity'] ?? '') === 'warning') {
                    $warningCount++;
                }
            }
            ?>

            <section class="ops-flow" aria-label="<?php bakery_te('dashboard.day_flow'); ?>">
                <div class="ops-flow-head">
                    <h2><?php bakery_te('dashboard.day_flow'); ?></h2>
                    <p><?php bakery_te('dashboard.day_flow_desc'); ?></p>
                    <a class="ops-brief-link" href="<?php echo htmlspecialchars(BASE_URL); ?>daily_run.php?date=<?php echo urlencode($selectedDate); ?>">
                        <?php bakery_te('nav.item.daily_run'); ?> →
                    </a>
                    <a class="ops-brief-link" href="<?php echo htmlspecialchars(BASE_URL); ?>daily_brief.php?date=<?php echo urlencode($selectedDate); ?>">
                        <?php bakery_te('brief.view_brief'); ?> →
                    </a>
                </div>
                <ol class="ops-flow-strip">
                    <?php foreach ($commandCenter['stages'] as $index => $stage): ?>
                        <li class="ops-flow-stage state-<?php echo htmlspecialchars($stage['state']); ?>">
                            <?php if ($index > 0): ?><span class="ops-flow-arrow" aria-hidden="true">→</span><?php endif; ?>
                            <a class="ops-flow-card" href="<?php echo htmlspecialchars($stage['href']); ?>">
                                <span class="ops-flow-label"><?php echo htmlspecialchars($stage['label']); ?></span>
                                <span class="ops-flow-status"><?php echo htmlspecialchars($stageStateLabel($stage['state'])); ?></span>
                                <span class="ops-flow-summary"><?php echo htmlspecialchars($stage['summary']); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>

            <?php bakery_render_demand_cadence_strip($db, $today, 'dashboard'); ?>

            <?php if ($tomorrowReadiness !== null && $tomorrowReadiness['state'] !== 'unavailable'): ?>
                <?php
                $tr = $tomorrowReadiness;
                $trState = $tr['state'];
                $trDateLabel = date('D, M j', strtotime($tr['date']));
                $trReview = [];
                if ((int)$tr['changed'] > 0) {
                    $trReview[] = '<a href="' . htmlspecialchars(bakery_ops_link_daily_orders($tr['date'], ['review' => 'changed'], 'dashboard')) . '">'
                        . (int)$tr['changed'] . ' ' . bakery_t('dashboard.tomorrow_changed') . '</a>';
                }
                if ((int)$tr['one_off'] > 0) {
                    $trReview[] = '<a href="' . htmlspecialchars(bakery_ops_link_daily_orders($tr['date'], ['review' => 'one_off'], 'dashboard')) . '">'
                        . (int)$tr['one_off'] . ' ' . bakery_t('dashboard.tomorrow_one_off') . '</a>';
                }
                if ((int)$tr['paused'] > 0) {
                    $trReview[] = '<a href="' . htmlspecialchars(bakery_ops_link_daily_orders($tr['date'], ['review' => 'paused'], 'dashboard')) . '">'
                        . (int)$tr['paused'] . ' ' . bakery_t('dashboard.tomorrow_paused') . '</a>';
                }
                $trInline = static function (string $action, string $label, string $confirm) use ($tr): string {
                    return '<form class="ops-inline-action" method="post" action="' . htmlspecialchars(BASE_URL) . 'daily_run_api.php"'
                        . ' onsubmit="return confirm(' . json_encode($confirm) . ');">'
                        . bakery_csrf_field()
                        . '<input type="hidden" name="action" value="' . htmlspecialchars($action) . '">'
                        . '<input type="hidden" name="operating_date" value="' . htmlspecialchars($tr['date']) . '">'
                        . '<input type="hidden" name="return" value="dashboard">'
                        . '<button type="submit" class="ops-inline-action-btn">' . htmlspecialchars($label) . '</button>'
                        . '</form>';
                };
                ?>
                <section class="ops-tomorrow ops-tomorrow--<?php echo htmlspecialchars($trState); ?>" aria-label="<?php bakery_te('dashboard.tomorrow_aria'); ?>">
                    <div class="ops-tomorrow-head">
                        <span class="ops-tomorrow-date"><?php bakery_te('dashboard.tomorrow_label'); ?> · <?php echo htmlspecialchars($trDateLabel); ?></span>
                        <a class="ops-brief-link" href="<?php echo htmlspecialchars(BASE_URL); ?>daily_run.php?date=<?php echo urlencode($tr['date']); ?>">
                            <?php bakery_te('dashboard.tomorrow_open_run'); ?> →
                        </a>
                    </div>
                    <div class="ops-tomorrow-body">
                        <?php if ($trState === 'no_demand'): ?>
                            <strong><?php bakery_te('dashboard.tomorrow_no_demand'); ?></strong>
                        <?php elseif ($trState === 'not_generated'): ?>
                            <strong><?php bakery_te('dashboard.tomorrow_not_generated'); ?></strong>
                            <span><?php echo htmlspecialchars(bakery_t('dashboard.tomorrow_not_generated_desc', ['count' => (int)$tr['expected_customers']])); ?></span>
                        <?php elseif ($trState === 'incomplete'): ?>
                            <strong><?php bakery_te('dashboard.tomorrow_incomplete'); ?></strong>
                            <span><?php echo htmlspecialchars(bakery_t('dashboard.tomorrow_incomplete_desc', ['count' => (int)$tr['missing_daily'] + (int)$tr['empty_daily']])); ?></span>
                        <?php elseif ($trState === 'ready_unconfirmed'): ?>
                            <strong><?php bakery_te('dashboard.tomorrow_ready'); ?></strong>
                            <span><?php echo (int)$tr['customers_with_daily']; ?> <?php bakery_te('dashboard.tomorrow_customers'); ?> · <?php echo number_format((int)$tr['daily_units']); ?> <?php bakery_te('dashboard.tomorrow_units'); ?></span>
                        <?php elseif ($trState === 'confirmed_with_changes'): ?>
                            <strong><?php bakery_te('dashboard.tomorrow_confirmed'); ?></strong>
                            <span><?php echo htmlspecialchars(bakery_t('dashboard.tomorrow_drift', ['count' => (int)$tr['changed_since']['count']])); ?></span>
                        <?php else: ?>
                            <strong><?php bakery_te('dashboard.tomorrow_confirmed'); ?></strong>
                            <span><?php echo htmlspecialchars(bakery_t('dashboard.tomorrow_confirmed_desc', [
                                'at' => !empty($tr['confirmation']['confirmed_at']) ? date('g:i A', strtotime($tr['confirmation']['confirmed_at'])) : '',
                                'customers' => (int)$tr['customers_with_daily'],
                                'units' => number_format((int)$tr['daily_units']),
                            ])); ?></span>
                        <?php endif; ?>
                        <?php if ($trReview !== []): ?>
                            <span class="ops-tomorrow-chips"><?php echo implode(' · ', $trReview); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="ops-tomorrow-actions">
                        <?php if ($trState === 'not_generated' || $trState === 'incomplete'): ?>
                            <?php echo $trInline('generate_daily_orders', bakery_t('dashboard.generate'), bakery_t('dashboard.generate_confirm')); ?>
                            <a class="ops-exception-action" href="<?php echo htmlspecialchars(bakery_ops_link_daily_orders($tr['date'], $trState === 'incomplete' ? ['review' => 'missing'] : [], 'dashboard')); ?>">
                                <?php bakery_te('dashboard.tomorrow_review'); ?>
                            </a>
                        <?php elseif ($trState === 'ready_unconfirmed' || ($trState === 'confirmed_with_changes' && $tr['confirmable'])): ?>
                            <?php echo $trInline('confirm_demand', bakery_t('dashboard.confirm_demand'), bakery_t('daily_run.confirm_demand_prompt')); ?>
                            <a class="ops-exception-action" href="<?php echo htmlspecialchars(bakery_ops_link_daily_orders($tr['date'], ['review' => 'differences'], 'dashboard')); ?>">
                                <?php bakery_te('dashboard.tomorrow_review'); ?>
                            </a>
                        <?php else: ?>
                            <a class="ops-exception-action" href="<?php echo htmlspecialchars(bakery_ops_link_daily_orders($tr['date'], [], 'dashboard')); ?>">
                                <?php bakery_te('dashboard.tomorrow_review'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="ops-exceptions" aria-label="<?php bakery_te('dashboard.attention_needed'); ?>">
                <div class="ops-exceptions-head">
                    <h2><?php bakery_te('dashboard.attention_needed'); ?></h2>
                    <?php if ($exceptions === []): ?>
                        <p class="ops-exceptions-sub"><?php echo htmlspecialchars(bakery_t('dashboard.nothing_flagged', ['date' => date('M j', strtotime($selectedDate))])); ?></p>
                    <?php else: ?>
                        <p class="ops-exceptions-sub">
                            <?php echo htmlspecialchars(bakery_t('dashboard.urgent_count', ['count' => (int)$criticalCount])); ?>
                            · <?php echo htmlspecialchars(bakery_t('dashboard.watch_count', ['count' => (int)$warningCount])); ?>
                            · <?php echo htmlspecialchars(bakery_t('dashboard.total_count', ['count' => (int)count($exceptions)])); ?>
                        </p>
                    <?php endif; ?>
                </div>

                <?php if ($exceptions === []): ?>
                    <div class="ops-exceptions-clear">
                        <strong><?php bakery_te('dashboard.day_clear'); ?></strong>
                        <span><?php bakery_te('dashboard.day_clear_desc'); ?></span>
                    </div>
                <?php else: ?>
                    <ul class="ops-exception-list">
                        <?php foreach ($exceptions as $ex): ?>
                            <?php echo bakery_ops_render_exception_html($ex, ['show_inline' => true, 'date' => $selectedDate, 'return' => 'dashboard']); ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="ops-section ops-jump-section">
                <h2><?php bakery_te('dashboard.jump_to_work'); ?></h2>
                <div class="ops-quick-links">
                    <a class="ops-quick-link" href="<?php echo htmlspecialchars($commandCenter['links']['daily_orders']); ?>">
                        <span>
                            <div class="ops-quick-link-title"><?php bakery_te('nav.item.daily_orders'); ?></div>
                            <div class="ops-quick-link-desc"><?php echo htmlspecialchars(bakery_t('dashboard.daily_orders_desc', ['day' => $dayLabel])); ?></div>
                        </span>
                    </a>
                    <a class="ops-quick-link" href="<?php echo htmlspecialchars($commandCenter['links']['production']); ?>">
                        <span>
                            <div class="ops-quick-link-title"><?php bakery_te('production.title_baker'); ?></div>
                            <div class="ops-quick-link-desc"><?php bakery_te('dashboard.production_ops_desc'); ?></div>
                        </span>
                    </a>
                    <a class="ops-quick-link" href="<?php echo htmlspecialchars($commandCenter['links']['pack']); ?>">
                        <span>
                            <div class="ops-quick-link-title"><?php bakery_te('nav.pack_list'); ?></div>
                            <div class="ops-quick-link-desc"><?php echo htmlspecialchars(bakery_t('dashboard.pack_checklist_desc', ['day' => $dayLabel])); ?></div>
                        </span>
                    </a>
                    <a class="ops-quick-link" href="<?php echo htmlspecialchars($commandCenter['links']['driver_load']); ?>">
                        <span>
                            <div class="ops-quick-link-title"><?php bakery_te('nav.item.driver_load'); ?></div>
                            <div class="ops-quick-link-desc"><?php bakery_te('dashboard.driver_load_desc'); ?></div>
                        </span>
                    </a>
                    <a class="ops-quick-link" href="<?php echo htmlspecialchars($commandCenter['links']['driver_assignment']); ?>">
                        <span>
                            <div class="ops-quick-link-title"><?php bakery_te('nav.item.driver_assignment'); ?></div>
                            <div class="ops-quick-link-desc"><?php bakery_te('dashboard.driver_assignment_desc'); ?></div>
                        </span>
                    </a>
                    <a class="ops-quick-link" href="<?php echo htmlspecialchars($commandCenter['links']['invoice']); ?>">
                        <span>
                            <div class="ops-quick-link-title"><?php bakery_te('nav.item.billing_center'); ?></div>
                            <div class="ops-quick-link-desc"><?php bakery_te('dashboard.invoice_desc'); ?></div>
                        </span>
                    </a>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($chartData)): ?>
        <section class="ops-section ops-chart-section">
            <h2><?php bakery_te('dashboard.chart_orders_7d'); ?></h2>
            <div class="ops-chart">
                <div class="ops-chart-bars" role="img" aria-label="Bar chart of daily order counts for the last seven days">
                    <?php foreach ($chartData as $bar): ?>
                        <?php
                        $heightPct = $chartMax > 0 ? max(2, round(($bar['count'] / $chartMax) * 100)) : 2;
                        $barIsSelected = !empty($bar['is_today']);
                        ?>
                        <a class="ops-chart-bar-wrap" href="?date=<?php echo urlencode($bar['date']); ?>" title="<?php echo htmlspecialchars($bar['date'] . ': ' . $bar['count'] . ' orders'); ?>">
                            <span class="ops-chart-count"><?php echo $bar['count'] > 0 ? (int)$bar['count'] : '0'; ?></span>
                            <div class="ops-chart-bar<?php echo $barIsSelected ? ' is-today' : ''; ?>" style="height: <?php echo (int)$heightPct; ?>%;"></div>
                            <span class="ops-chart-label"><?php echo htmlspecialchars($bar['label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>
