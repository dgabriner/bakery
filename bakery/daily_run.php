<?php
/**
 * Daily Run — manager guided runbook for one operating date.
 */
define('ACCESS_ALLOWED', true);

require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/daily_order_generation.php';
require_once 'includes/daily_run.php';
require_once 'includes/driver_assignments.php';
require_once 'includes/operational_exceptions.php';
require_once 'includes/driver_day_sms.php';

$user = bakery_current_user();
if ($user && bakery_is_driver_route_role($user['role_slug'] ?? '')) {
    header('Location: ' . BASE_URL . 'driver.php');
    exit;
}
if ($user && $user['role_slug'] === 'baker') {
    header('Location: ' . BASE_URL . 'production.php');
    exit;
}

$page_title = bakery_t('page.daily_run');
$today = date('Y-m-d');
$selectedDate = bakery_dashboard_resolve_date();
$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
$isToday = ($selectedDate === $today);
$dateDisplay = date('l, F j, Y', strtotime($selectedDate));

$flash = null;
$flashType = 'success';
if (isset($_GET['flash']) && isset($_GET['msg'])) {
    $flashType = ($_GET['flash'] ?? '') === 'error' ? 'danger' : 'success';
    $flash = (string)$_GET['msg'];
}
$error = null;
$dailyRun = null;

bakery_try_build_from_standing_post($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        bakery_require_csrf();
        $postDate = bakery_dashboard_resolve_date((string)($_POST['operating_date'] ?? $selectedDate));
        if ($action === 'close_day') {
            $note = trim((string)($_POST['manager_note'] ?? ''));
            bakery_daily_run_close_day($db, $postDate, (int)($user['id'] ?? 0) ?: null, $note);
            $flash = bakery_t('daily_run.closed_success', ['date' => date('M j, Y', strtotime($postDate))]);
            $selectedDate = $postDate;
        } elseif ($action === 'reopen_day') {
            bakery_daily_run_reopen_day($db, $postDate, (int)($user['id'] ?? 0) ?: null);
            $flash = bakery_t('daily_run.reopened_success', ['date' => date('M j, Y', strtotime($postDate))]);
            $selectedDate = $postDate;
        } elseif ($action === 'restore_standing_stops') {
            require_once __DIR__ . '/includes/driver_assignment_actions.php';
            $restored = bakery_driver_assignment_action_sync_driver_route($db, [
                'driver_id' => (int)($_POST['driver_id'] ?? 0),
                'delivery_date' => $postDate,
            ]);
            $flash = (string)($restored['message'] ?? '');
            $selectedDate = $postDate;
        }
    } catch (Throwable $e) {
        $flashType = 'danger';
        $flash = $e->getMessage();
    }
}

try {
    $dailyRun = bakery_daily_run_build($db, $selectedDate);
} catch (Throwable $e) {
    error_log('daily_run page: ' . $e->getMessage());
    $error = bakery_dashboard_safe_error_message($e);
}

$driverSmsBlocks = [];
$closeoutSplits = [];
if (is_array($dailyRun)) {
    try {
        $driverSmsBlocks = bakery_driver_day_sms_blocks($db, $selectedDate);
    } catch (Throwable $e) {
        error_log('daily_run driver sms: ' . $e->getMessage());
    }
    try {
        require_once 'includes/route_summary.php';
        $closeoutSplits = bakery_route_closeout_driver_splits($db, $selectedDate);
    } catch (Throwable $e) {
        error_log('daily_run closeout split: ' . $e->getMessage());
    }
}

$uiStateLabel = static function (string $state): string {
    $map = [
        'complete' => bakery_t('daily_run.state.complete'),
        'ready' => bakery_t('daily_run.state.ready'),
        'in_progress' => bakery_t('daily_run.state.in_progress'),
        'needs_attention' => bakery_t('daily_run.state.needs_attention'),
        'not_started' => bakery_t('daily_run.state.not_started'),
        'empty' => bakery_t('daily_run.state.empty'),
        'unavailable' => bakery_t('daily_run.state.unavailable'),
    ];
    return $map[$state] ?? ucfirst(str_replace('_', ' ', $state));
};

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/dashboard.css'); ?>">
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/daily_run.css'); ?>">

<div class="ops-dashboard daily-run">
    <header class="ops-header">
        <div class="ops-header-main">
            <p class="ops-eyebrow"><?php bakery_te('daily_run.eyebrow'); ?></p>
            <h1><?php bakery_te('daily_run.title'); ?></h1>
            <p class="ops-date-line<?php echo $isToday ? ' is-today' : ''; ?>">
                <span class="ops-date-strong"><?php echo htmlspecialchars($dateDisplay); ?></span>
                <?php if ($isToday): ?>
                    <span class="ops-date-pill"><?php bakery_te('common.today'); ?></span>
                <?php else: ?>
                    <span class="ops-date-pill ops-date-pill-muted"><?php bakery_te('common.selected_day'); ?></span>
                <?php endif; ?>
                <?php if ($dailyRun && !empty($dailyRun['is_closed'])): ?>
                    <span class="dr-closed-pill"><?php bakery_te('daily_run.closed_pill'); ?></span>
                <?php elseif ($dailyRun && !empty($dailyRun['stale_closeout'])): ?>
                    <span class="dr-stale-pill"><?php bakery_te('daily_run.stale_pill'); ?></span>
                <?php endif; ?>
            </p>
            <?php if (is_array($dailyRun) && is_array($dailyRun['demand_scheduler'] ?? null)): ?>
                <?php bakery_render_demand_scheduler_status($dailyRun['demand_scheduler']); ?>
            <?php endif; ?>
        </div>
        <div class="dr-header-links no-print">
            <?php echo bakery_build_from_standing_button($selectedDate, 'ops-inline-action-btn'); ?>
            <a class="ops-brief-link" href="<?php echo htmlspecialchars(BASE_URL); ?>daily_brief.php?date=<?php echo urlencode($selectedDate); ?>">
                <?php bakery_te('brief.view_brief'); ?> →
            </a>
        </div>
        <nav class="ops-date-nav" aria-label="<?php bakery_te('dashboard.date_nav_aria'); ?>">
            <a href="?date=<?php echo urlencode($prevDate); ?>"><?php bakery_te('common.prev'); ?></a>
            <?php if (!$isToday): ?>
                <a href="?date=<?php echo urlencode($today); ?>" class="ops-today-link"><?php bakery_te('common.today'); ?></a>
            <?php endif; ?>
            <a href="?date=<?php echo urlencode($nextDate); ?>"><?php bakery_te('common.next'); ?></a>
            <form class="ops-date-jump" method="get" action="">
                <label class="ops-sr-only" for="dr-date-input"><?php bakery_te('common.operating_date'); ?></label>
                <input id="dr-date-input" type="date" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>">
                <button type="submit"><?php bakery_te('common.go'); ?></button>
            </form>
        </nav>
    </header>

    <?php if ($flash): ?>
        <div class="ops-alert ops-alert-<?php echo $flashType === 'danger' ? 'danger' : 'success'; ?>" role="alert">
            <?php echo htmlspecialchars($flash); ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="ops-alert ops-alert-danger" role="alert">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php elseif ($dailyRun): ?>
        <?php if (!empty($dailyRun['section_errors'])): ?>
            <div class="ops-alert ops-alert-warning" role="alert">
                <?php bakery_te('daily_run.partial_data'); ?>
            </div>
        <?php endif; ?>

        <?php
        $routeRestoreGroups = [];
        if (function_exists('bakery_route_missing_standing_by_driver')) {
            $routeRestoreGroups = bakery_route_missing_standing_by_driver($db, $selectedDate);
        }
        ?>
        <?php if ($routeRestoreGroups): ?>
            <section class="dr-restore-standing" aria-label="<?php echo htmlspecialchars(bakery_t('daily_run.restore_standing_title')); ?>">
                <h2><?php echo htmlspecialchars(bakery_t('daily_run.restore_standing_title')); ?></h2>
                <p><?php bakery_te('daily_run.restore_standing_help'); ?></p>
                <?php bakery_route_restore_render_forms($routeRestoreGroups, [
                    'action' => 'restore_standing_stops',
                    'operating_date' => $selectedDate,
                ], 'dr-btn dr-btn-secondary route-restore-btn'); ?>
            </section>
        <?php endif; ?>

        <?php if (!empty($dailyRun['route_losses'])): ?>
            <?php
            $routeLossGroups = [];
            foreach ($dailyRun['route_losses'] as $routeLoss) {
                $routeLossDriverId = (int)($routeLoss['driver_id'] ?? 0);
                if (!isset($routeLossGroups[$routeLossDriverId])) {
                    $routeLossDriverName = trim((string)($routeLoss['driver_name'] ?? ''));
                    $routeLossGroups[$routeLossDriverId] = [
                        'name' => $routeLossDriverName !== '' ? $routeLossDriverName : ('#' . $routeLossDriverId),
                        'stores' => [],
                    ];
                }
                $routeLossGroups[$routeLossDriverId]['stores'][] = (string)($routeLoss['customer_name'] ?? '');
            }
            ?>
            <section class="dr-route-losses" aria-label="<?php echo htmlspecialchars(bakery_t('daily_run.route_lost_title', ['count' => count($dailyRun['route_losses'])])); ?>">
                <h2><?php echo htmlspecialchars(bakery_t('daily_run.route_lost_title', ['count' => count($dailyRun['route_losses'])])); ?></h2>
                <p><?php bakery_te('daily_run.route_lost_help'); ?></p>
                <ul>
                    <?php foreach ($routeLossGroups as $routeLossGroup): ?>
                        <li><?php echo htmlspecialchars(bakery_t('daily_run.route_lost_driver', [
                            'driver' => $routeLossGroup['name'],
                            'count' => count($routeLossGroup['stores']),
                            'stores' => implode(', ', $routeLossGroup['stores']),
                        ])); ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if (!empty($dailyRun['unrouted_standing_orders'])): ?>
            <section class="dr-unrouted-standing" aria-label="<?php echo htmlspecialchars(bakery_t('daily_run.unrouted_standing_title', ['count' => count($dailyRun['unrouted_standing_orders'])])); ?>">
                <h2><?php echo htmlspecialchars(bakery_t('daily_run.unrouted_standing_title', ['count' => count($dailyRun['unrouted_standing_orders'])])); ?></h2>
                <p><?php bakery_te('daily_run.unrouted_standing_help'); ?></p>
                <ul>
                    <?php foreach ($dailyRun['unrouted_standing_orders'] as $unrouted): ?>
                        <li>
                            <strong><?php echo htmlspecialchars((string)$unrouted['customer_name']); ?></strong>
                            <span><?php echo htmlspecialchars((string)$unrouted['summary']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <a href="<?php echo htmlspecialchars(BASE_URL); ?>standing_routes.php"><?php bakery_te('daily_run.unrouted_standing_open'); ?></a>
            </section>
        <?php endif; ?>

        <?php bakery_render_demand_cadence_strip($db, $today, 'daily_run'); ?>

        <section class="dr-hero" aria-label="<?php bakery_te('daily_run.progress_aria'); ?>">
            <div class="dr-progress-ring">
                <span class="dr-progress-count"><?php echo (int)$dailyRun['progress']['complete']; ?></span>
                <span class="dr-progress-of">/ <?php echo (int)$dailyRun['progress']['total']; ?></span>
            </div>
            <div class="dr-hero-body">
                <h2><?php echo htmlspecialchars($dailyRun['progress']['label']); ?></h2>
                <?php if ($dailyRun['next_action']): ?>
                    <p class="dr-next-prompt"><?php bakery_te('daily_run.what_next'); ?></p>
                    <a class="dr-next-action" href="<?php echo htmlspecialchars($dailyRun['next_action']['href']); ?>">
                        <span class="dr-next-step"><?php echo htmlspecialchars(bakery_t('daily_run.step_n', ['n' => (int)$dailyRun['next_action']['step']])); ?></span>
                        <strong><?php echo htmlspecialchars($dailyRun['next_action']['label']); ?></strong>
                        <span class="dr-next-cta"><?php echo htmlspecialchars($dailyRun['next_action']['action_label']); ?> →</span>
                    </a>
                <?php else: ?>
                    <p class="dr-next-prompt"><?php bakery_te('daily_run.all_clear'); ?></p>
                <?php endif; ?>
                <?php if ($dailyRun['later_in_progress']): ?>
                    <p class="dr-parallel-note">
                        <?php echo htmlspecialchars(bakery_t('daily_run.parallel_work', [
                            'stage' => $dailyRun['later_in_progress']['label'],
                        ])); ?>
                    </p>
                <?php endif; ?>
            </div>
            <div class="dr-hero-links">
                <a class="ops-quick-link dr-hub-link" href="<?php echo htmlspecialchars($dailyRun['links']['index']); ?>">
                    <?php bakery_te('nav.item.index'); ?>
                </a>
            </div>
        </section>

        <?php if ($driverSmsBlocks !== []): ?>
        <section class="dr-driver-sms" aria-label="<?php echo htmlspecialchars(bakery_t('daily_run.driver_sms_title')); ?>">
            <h2><?php bakery_te('daily_run.driver_sms_title'); ?></h2>
            <p class="dr-driver-sms-help"><?php bakery_te('daily_run.copy_driver_sms_help'); ?></p>
            <?php foreach ($driverSmsBlocks as $smsBlock): ?>
                <div class="dr-driver-sms-card">
                    <h3><?php echo htmlspecialchars((string)$smsBlock['driver_name']); ?></h3>
                    <textarea class="dr-driver-sms-body" readonly rows="8"><?php echo htmlspecialchars((string)$smsBlock['body']); ?></textarea>
                    <button type="button" class="dr-btn dr-btn-secondary dr-driver-sms-copy" data-copied="<?php echo htmlspecialchars(bakery_t('daily_run.copy_driver_sms_copied')); ?>">
                        <?php bakery_te('daily_run.copy_driver_sms'); ?>
                    </button>
                </div>
            <?php endforeach; ?>
        </section>
        <script>
        (function () {
          document.querySelectorAll('.dr-driver-sms-copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
              var card = btn.closest('.dr-driver-sms-card');
              var area = card ? card.querySelector('.dr-driver-sms-body') : null;
              var text = area ? area.value : '';
              if (!text || !navigator.clipboard || !navigator.clipboard.writeText) {
                return;
              }
              var label = btn.textContent;
              navigator.clipboard.writeText(text).then(function () {
                btn.textContent = btn.getAttribute('data-copied') || label;
                setTimeout(function () { btn.textContent = label; }, 1200);
              });
            });
          });
        })();
        </script>
        <?php endif; ?>

        <ol class="dr-stage-list" aria-label="<?php bakery_te('daily_run.stages_aria'); ?>">
            <?php foreach ($dailyRun['stages'] as $stage): ?>
                <?php
                $isCloseout = ($stage['key'] === 'closeout');
                $stateClass = 'dr-state-' . preg_replace('/[^a-z_]/', '', $stage['ui_state']);
                $isQuiet = in_array($stage['ui_state'], ['complete', 'empty'], true);
                ?>
                <li class="dr-stage <?php echo $stateClass; ?><?php echo $isQuiet ? ' is-quiet' : ''; ?><?php echo $isCloseout ? ' is-closeout' : ''; ?>"
                    id="<?php echo $isCloseout ? 'closeout' : htmlspecialchars($stage['key']); ?>">
                    <div class="dr-stage-head">
                        <span class="dr-stage-num"><?php echo (int)$stage['step']; ?></span>
                        <div class="dr-stage-title-wrap">
                            <h3><?php echo htmlspecialchars($stage['label']); ?></h3>
                            <span class="dr-stage-state"><?php echo htmlspecialchars($uiStateLabel($stage['ui_state'])); ?></span>
                        </div>
                        <?php if (!$isCloseout): ?>
                            <a class="dr-stage-action" href="<?php echo htmlspecialchars($stage['href']); ?>">
                                <?php echo htmlspecialchars($stage['action_label']); ?>
                            </a>
                        <?php endif; ?>
                    </div>

                    <p class="dr-stage-summary"><?php echo htmlspecialchars($stage['summary']); ?></p>

                    <?php if (!empty($stage['metrics'])): ?>
                        <dl class="dr-metrics">
                            <?php foreach ($stage['metrics'] as $metric): ?>
                                <div class="dr-metric">
                                    <dt><?php echo htmlspecialchars($metric['label']); ?></dt>
                                    <dd><?php echo htmlspecialchars((string)$metric['value']); ?></dd>
                                </div>
                            <?php endforeach; ?>
                        </dl>
                    <?php endif; ?>

                    <?php
                    $stageBlockers = array_filter($dailyRun['blockers'], static function ($b) use ($stage) {
                        return ($b['stage'] ?? '') === $stage['key']
                            || ($stage['key'] === 'dispatch' && in_array($b['stage'] ?? $b['category'] ?? '', ['load', 'delivery'], true))
                            || ($stage['key'] === 'deliver' && ($b['category'] ?? '') === 'delivery')
                            || ($stage['key'] === 'invoice' && ($b['category'] ?? '') === 'invoice');
                    });
                    ?>
                    <?php if ($stageBlockers !== []): ?>
                        <ul class="dr-stage-blockers">
                            <?php foreach ($stageBlockers as $blocker): ?>
                                <?php echo bakery_ops_render_exception_html($blocker, ['show_inline' => true, 'date' => $selectedDate, 'return' => 'daily_run']); ?>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($stage['key'] === 'confirm_demand' && !empty($stage['confirmation']['available'])): ?>
                        <?php
                        $conf = $stage['confirmation'];
                        $confRow = $conf['confirmation'] ?? null;
                        $confChanges = (int)($conf['changed_since']['count'] ?? 0);
                        ?>
                        <div class="dr-demand-confirm">
                            <?php if ($confRow !== null): ?>
                                <p class="dr-demand-confirm-status is-confirmed">
                                    <?php echo htmlspecialchars(bakery_t('daily_run.demand_confirmed_line', [
                                        'at' => bakery_format_user_stamp(strtotime($confRow['confirmed_at']), 'M j, Y g:i A'),
                                        'customers' => (int)$confRow['customers_count'],
                                        'units' => number_format((int)$confRow['units_count']),
                                    ])); ?>
                                </p>
                                <?php if ($confChanges > 0): ?>
                                    <p class="dr-demand-confirm-drift">
                                        <?php echo htmlspecialchars(bakery_t('daily_run.demand_changed_since', ['count' => $confChanges])); ?>
                                        <a href="<?php echo htmlspecialchars(bakery_ops_link_daily_orders($selectedDate, ['review' => 'differences'], 'daily_run')); ?>">
                                            <?php bakery_te('daily_run.review_changes'); ?>
                                        </a>
                                    </p>
                                <?php endif; ?>
                            <?php elseif (!empty($conf['confirmable'])): ?>
                                <p class="dr-demand-confirm-status"><?php bakery_te('daily_run.demand_unconfirmed'); ?></p>
                            <?php else: ?>
                                <p class="dr-demand-confirm-status is-muted"><?php bakery_te('daily_run.demand_not_confirmable'); ?></p>
                            <?php endif; ?>

                            <?php if (!empty($conf['confirmable']) && ($confRow === null || $confChanges > 0)): ?>
                                <form method="post" action="<?php echo htmlspecialchars(BASE_URL); ?>daily_run_api.php" class="dr-demand-confirm-form"
                                      onsubmit="return confirm(<?php echo json_encode(bakery_t('daily_run.confirm_demand_prompt')); ?>);">
                                    <?php echo bakery_csrf_field(); ?>
                                    <input type="hidden" name="action" value="confirm_demand">
                                    <input type="hidden" name="operating_date" value="<?php echo htmlspecialchars($selectedDate); ?>">
                                    <input type="hidden" name="return" value="daily_run">
                                    <button type="submit" class="dr-btn dr-btn-primary">
                                        <?php bakery_te($confRow === null ? 'daily_run.confirm_demand' : 'daily_run.confirm_demand_again'); ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($stage['key'] === 'production_plan' && !empty($stage['commit']['available'])): ?>
                        <?php
                        $planConf = $stage['commit'];
                        $planRow = $planConf['commit'] ?? null;
                        $planChanges = (int)($planConf['changed_since']['count'] ?? 0);
                        ?>
                        <div class="dr-demand-confirm">
                            <?php if ($planRow !== null): ?>
                                <p class="dr-demand-confirm-status is-confirmed">
                                    <?php echo htmlspecialchars(bakery_t('daily_run.plan_committed_line', [
                                        'at' => bakery_format_user_stamp(strtotime($planRow['committed_at']), 'M j, Y g:i A'),
                                        'products' => (int)$planRow['products_count'],
                                        'units' => number_format((int)$planRow['units_count']),
                                    ])); ?>
                                </p>
                                <?php if ($planChanges > 0): ?>
                                    <p class="dr-demand-confirm-drift">
                                        <?php echo htmlspecialchars(bakery_t('daily_run.plan_changed_since', ['count' => $planChanges])); ?>
                                        <a href="<?php echo htmlspecialchars(bakery_ops_link_production_center($dailyRun['week_start'], ['attention' => '1', 'date' => $selectedDate], 'daily_run')); ?>">
                                            <?php bakery_te('daily_run.review_plan'); ?>
                                        </a>
                                    </p>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="dr-demand-confirm-status"><?php bakery_te('daily_run.plan_uncommitted'); ?></p>
                            <?php endif; ?>

                            <?php if ($planRow === null || $planChanges > 0): ?>
                                <form method="post" action="<?php echo htmlspecialchars(BASE_URL); ?>daily_run_api.php" class="dr-demand-confirm-form"
                                      onsubmit="return confirm(<?php echo json_encode(bakery_t($planRow === null ? 'daily_run.commit_plan_prompt' : 'daily_run.commit_plan_again_prompt')); ?>);">
                                    <?php echo bakery_csrf_field(); ?>
                                    <input type="hidden" name="action" value="commit_production_plan">
                                    <input type="hidden" name="operating_date" value="<?php echo htmlspecialchars($selectedDate); ?>">
                                    <input type="hidden" name="return" value="daily_run">
                                    <button type="submit" class="dr-btn dr-btn-primary">
                                        <?php bakery_te($planRow === null ? 'daily_run.commit_plan' : 'daily_run.commit_plan_again'); ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($isCloseout): ?>
                        <?php if ($closeoutSplits !== []): ?>
                        <section class="dr-closeout-split" aria-label="<?php echo htmlspecialchars(bakery_t('daily_run.closeout_split_title')); ?>">
                            <h4><?php bakery_te('daily_run.closeout_split_title'); ?></h4>
                            <p class="dr-closeout-split-help"><?php bakery_te('daily_run.closeout_split_help'); ?></p>
                            <?php foreach ($closeoutSplits as $split): ?>
                                <?php $splitCounts = $split['counts']; ?>
                                <article class="dr-closeout-split-card">
                                    <h5><?php echo htmlspecialchars((string)$split['driver_name']); ?></h5>
                                    <ul>
                                        <li><?php echo htmlspecialchars(bakery_t('daily_run.closeout_after')); ?>: <?php echo (int)$splitCounts['after']; ?></li>
                                        <li><?php echo htmlspecialchars(bakery_t('daily_run.closeout_arrival')); ?>: <?php echo (int)$splitCounts['arrival_only']; ?></li>
                                        <li><?php echo htmlspecialchars(bakery_t('daily_run.closeout_none')); ?>: <?php echo (int)$splitCounts['no_photo']; ?></li>
                                        <li><?php echo htmlspecialchars(bakery_t('daily_run.closeout_open')); ?>: <?php echo (int)$splitCounts['open']; ?></li>
                                    </ul>
                                    <textarea class="dr-closeout-sms-body" readonly rows="6"><?php echo htmlspecialchars((string)$split['body']); ?></textarea>
                                    <button type="button" class="dr-btn dr-btn-secondary dr-closeout-sms-copy" data-copied="<?php echo htmlspecialchars(bakery_t('daily_run.closeout_copied')); ?>">
                                        <?php bakery_te('daily_run.closeout_copy'); ?>
                                    </button>
                                </article>
                            <?php endforeach; ?>
                        </section>
                        <script>
                        (function () {
                          document.querySelectorAll('.dr-closeout-sms-copy').forEach(function (btn) {
                            btn.addEventListener('click', function () {
                              var card = btn.closest('.dr-closeout-split-card');
                              var area = card ? card.querySelector('.dr-closeout-sms-body') : null;
                              var text = area ? area.value : '';
                              if (!text || !navigator.clipboard || !navigator.clipboard.writeText) {
                                return;
                              }
                              var label = btn.textContent;
                              navigator.clipboard.writeText(text).then(function () {
                                btn.textContent = btn.getAttribute('data-copied') || label;
                                setTimeout(function () { btn.textContent = label; }, 1200);
                              });
                            });
                          });
                        })();
                        </script>
                        <?php endif; ?>
                        <div class="dr-closeout-panel">
                            <?php if (!empty($dailyRun['is_closed'])): ?>
                                <div class="dr-closeout-status dr-closeout-status--closed">
                                    <strong><?php bakery_te('daily_run.day_closed'); ?></strong>
                                    <?php if (!empty($dailyRun['closeout']['closed_at'])): ?>
                                        <span><?php echo htmlspecialchars(bakery_format_user_stamp(strtotime($dailyRun['closeout']['closed_at']), 'M j, Y g:i A')); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($dailyRun['closeout']['manager_note'])): ?>
                                        <p class="dr-closeout-note"><?php echo htmlspecialchars($dailyRun['closeout']['manager_note']); ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($dailyRun['stale_closeout'])): ?>
                                    <div class="ops-alert ops-alert-warning">
                                        <?php bakery_te('daily_run.stale_warning'); ?>
                                    </div>
                                <?php endif; ?>
                                <form method="post" class="dr-closeout-form">
                                    <?php echo bakery_csrf_field(); ?>
                                    <input type="hidden" name="action" value="reopen_day">
                                    <input type="hidden" name="operating_date" value="<?php echo htmlspecialchars($selectedDate); ?>">
                                    <button type="submit" class="dr-btn dr-btn-secondary"><?php bakery_te('daily_run.reopen'); ?></button>
                                </form>
                            <?php else: ?>
                                <?php if (!$dailyRun['operational_complete']): ?>
                                    <div class="dr-closeout-blockers">
                                        <strong><?php bakery_te('daily_run.cannot_close'); ?></strong>
                                        <ul>
                                            <?php foreach ($dailyRun['close_blockers'] as $cb): ?>
                                                <li>
                                                    <?php echo htmlspecialchars($cb['title']); ?>.
                                                    <?php if (!empty($cb['href'])): ?>
                                                        <a href="<?php echo htmlspecialchars($cb['href']); ?>"><?php echo htmlspecialchars($cb['action'] ?? bakery_t('dashboard.open')); ?></a>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php else: ?>
                                    <p class="dr-closeout-ready"><?php bakery_te('daily_run.ready_to_close'); ?></p>
                                <?php endif; ?>

                                <?php if (!$dailyRun['closeout_ready']): ?>
                                    <div class="ops-alert ops-alert-warning">
                                        <?php bakery_te('daily_run.closeout_not_installed'); ?>
                                    </div>
                                <?php else: ?>
                                    <form method="post" class="dr-closeout-form">
                                        <?php echo bakery_csrf_field(); ?>
                                        <input type="hidden" name="action" value="close_day">
                                        <input type="hidden" name="operating_date" value="<?php echo htmlspecialchars($selectedDate); ?>">
                                        <label for="manager_note"><?php bakery_te('daily_run.manager_note'); ?></label>
                                        <textarea id="manager_note" name="manager_note" rows="2" placeholder="<?php bakery_te('daily_run.manager_note_ph'); ?>"></textarea>
                                        <button type="submit" class="dr-btn dr-btn-primary"
                                            <?php echo $dailyRun['operational_complete'] ? '' : 'disabled'; ?>>
                                            <?php bakery_te('daily_run.close_day'); ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>

        <?php if ($dailyRun['blockers'] !== []): ?>
            <section class="ops-exceptions" aria-label="<?php bakery_te('dashboard.attention_needed'); ?>">
                <div class="ops-exceptions-head">
                    <h2><?php bakery_te('daily_run.all_blockers'); ?></h2>
                </div>
                <ul class="ops-exception-list">
                    <?php foreach ($dailyRun['blockers'] as $ex): ?>
                        <?php echo bakery_ops_render_exception_html($ex, ['show_inline' => true, 'date' => $selectedDate, 'return' => 'daily_run']); ?>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>
