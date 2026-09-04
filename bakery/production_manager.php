<?php
/**
 * Production Manager Dashboard — expandable dough / batch / piece board,
 * plus week order sense, route plan vs actual, and demand vs supply.
 * Edit & commit stay on Production Center; bakers stay on Daily Production.
 */
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/production_manager_dashboard.php';
require_once 'includes/production_workflow_strip.php';

$selectedDate = bakery_pmd_resolve_date((string)($_GET['date'] ?? ''));
$view = bakery_pmd_resolve_view((string)($_GET['view'] ?? 'batches'));
$expandAll = (string)($_GET['expand'] ?? '') === '1';

$board = null;
$week = null;
$routes = null;
$supply = null;
$links = bakery_pmd_links($selectedDate);

if ($view === 'week') {
    $week = bakery_pmd_week_orders($db, $selectedDate);
    $links = $week['links'];
} elseif ($view === 'routes') {
    $routes = bakery_pmd_route_plan_vs_actual($db, $selectedDate);
    $links = $routes['links'];
} elseif ($view === 'supply') {
    $supply = bakery_pmd_demand_vs_supply($db, $selectedDate);
    $links = $supply['links'];
} else {
    $board = bakery_pmd_build($db, $selectedDate);
    $links = $board['links'];
    $view = 'batches';
}

$summary = $board['summary'] ?? null;
$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

$pmdHref = static function (string $date, string $viewName = 'batches', bool $expand = false): string {
    $q = ['date' => $date, 'view' => $viewName];
    if ($expand && $viewName === 'batches') {
        $q['expand'] = '1';
    }
    return 'production_manager.php?' . http_build_query($q);
};

$hubStages = [];
try {
    $hubStages = bakery_production_workflow_kitchen_stages($db, $selectedDate);
} catch (Throwable $e) {
    error_log('production_manager workflow strip: ' . $e->getMessage());
}

$fmtSigned = static function (int $n): string {
    return ($n > 0 ? '+' : '') . number_format($n);
};

$fmtTime = static function (?string $t): string {
    if ($t === null || $t === '') {
        return '—';
    }
    $ts = strtotime($t);
    return $ts ? date('g:i A', $ts) : htmlspecialchars($t);
};

$page_title = bakery_t('page.production_manager');
require_once 'includes/header.php';
require_once 'includes/nav.php';
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/production_manager.css'); ?>">
<main class="pmd container" id="productionManagerDashboard">
    <header class="pmd-heading">
        <div>
            <p class="pmd-eyebrow"><?php bakery_te('production_manager.eyebrow'); ?></p>
            <h1><?php bakery_te('production_manager.title'); ?></h1>
            <p class="pmd-lead"><?php bakery_te('production_manager.lead'); ?></p>
        </div>
        <div class="pmd-heading-actions">
            <a class="btn btn-primary" href="<?php echo htmlspecialchars($links['production_center']); ?>">
                <?php bakery_te('production_manager.link_center'); ?>
            </a>
            <a class="btn btn-outline" href="<?php echo htmlspecialchars($links['production']); ?>">
                <?php bakery_te('production_manager.link_baker'); ?>
            </a>
            <a class="btn btn-outline" href="<?php echo htmlspecialchars($links['ingredient_requirements']); ?>">
                <?php bakery_te('production_manager.link_ingredients'); ?>
            </a>
            <a class="btn btn-outline" href="<?php echo htmlspecialchars($links['pack_list']); ?>">
                <?php bakery_te('production_manager.link_pack'); ?>
            </a>
            <a class="btn btn-outline" href="<?php echo htmlspecialchars($links['product_manager_plan']); ?>">
                <?php bakery_te('production_manager.link_pmp'); ?>
            </a>
        </div>
    </header>

    <?php
    echo bakery_production_workflow_strip_css();
    echo bakery_production_workflow_strip_html($hubStages, [
        'current' => 'produce',
        'title' => bakery_t('production_workflow.title'),
        'lead' => bakery_t('production_manager.workflow_lead'),
    ]);
    ?>

    <form method="get" class="pmd-filters" action="production_manager.php">
        <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
        <?php if ($expandAll && $view === 'batches'): ?><input type="hidden" name="expand" value="1"><?php endif; ?>
        <a class="btn btn-outline" href="<?php echo htmlspecialchars($pmdHref($prevDate, $view, $expandAll)); ?>">
            <?php bakery_te('production_manager.prev_day'); ?>
        </a>
        <label class="pmd-date-label"><?php bakery_te('production_manager.delivery_date'); ?>
            <input type="date" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>" onchange="this.form.submit()">
        </label>
        <a class="btn btn-outline" href="<?php echo htmlspecialchars($pmdHref($nextDate, $view, $expandAll)); ?>">
            <?php bakery_te('production_manager.next_day'); ?>
        </a>
        <span class="pmd-date-display"><?php echo htmlspecialchars(date('l, F j, Y', strtotime($selectedDate))); ?></span>
        <?php if ($view === 'batches'): ?>
            <a class="pmd-text-link" href="<?php echo htmlspecialchars($pmdHref($selectedDate, 'batches', !$expandAll)); ?>">
                <?php bakery_te($expandAll ? 'production_manager.collapse_all' : 'production_manager.expand_all'); ?>
            </a>
        <?php endif; ?>
    </form>

    <nav class="pmd-tabs" aria-label="<?php bakery_te('production_manager.tabs_aria'); ?>">
        <a class="pmd-tab<?php echo $view === 'batches' ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($pmdHref($selectedDate, 'batches', $expandAll)); ?>">
            <?php bakery_te('production_manager.tab_batches'); ?>
        </a>
        <a class="pmd-tab<?php echo $view === 'week' ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($pmdHref($selectedDate, 'week')); ?>">
            <?php bakery_te('production_manager.tab_week'); ?>
        </a>
        <a class="pmd-tab<?php echo $view === 'routes' ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($pmdHref($selectedDate, 'routes')); ?>">
            <?php bakery_te('production_manager.tab_routes'); ?>
        </a>
        <a class="pmd-tab<?php echo $view === 'supply' ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($pmdHref($selectedDate, 'supply')); ?>">
            <?php bakery_te('production_manager.tab_supply'); ?>
        </a>
    </nav>

    <?php if ($view === 'batches' && $board): ?>
    <section class="pmd-status-row" aria-label="<?php bakery_te('production_manager.status_aria'); ?>">
        <?php if ($board['committed']): ?>
            <span class="pmd-pill pmd-pill--ok"><?php bakery_te('production_manager.committed'); ?></span>
        <?php else: ?>
            <span class="pmd-pill pmd-pill--warn"><?php bakery_te('production_manager.not_committed'); ?></span>
        <?php endif; ?>
        <span class="pmd-pill pmd-pill--muted">
            <?php echo htmlspecialchars(
                $board['bake_source'] === 'committed_plan'
                    ? bakery_t('production_manager.source_committed')
                    : bakery_t('production_manager.source_demand')
            ); ?>
        </span>
        <?php if (!empty($board['changed_since']['count'])): ?>
            <span class="pmd-pill pmd-pill--danger">
                <?php echo htmlspecialchars(bakery_t('production_manager.drift', [
                    'count' => (int)$board['changed_since']['count'],
                ])); ?>
            </span>
        <?php endif; ?>
        <span class="pmd-muted">
            <?php echo htmlspecialchars(bakery_t('production_manager.prior_week', [
                'date' => $board['prior_date'],
            ])); ?>
        </span>
    </section>

    <section class="pmd-summary" aria-label="<?php bakery_te('production_manager.summary_aria'); ?>">
        <div class="pmd-metric">
            <span><?php bakery_te('production_manager.metric_doughs'); ?></span>
            <strong><?php echo number_format((int)$summary['dough_types']); ?></strong>
        </div>
        <div class="pmd-metric">
            <span><?php bakery_te('production_manager.metric_products'); ?></span>
            <strong><?php echo number_format((int)$summary['products']); ?></strong>
        </div>
        <div class="pmd-metric">
            <span><?php bakery_te('production_manager.metric_pieces'); ?></span>
            <strong><?php echo number_format((int)$summary['pieces']); ?></strong>
        </div>
        <div class="pmd-metric">
            <span><?php bakery_te('production_manager.metric_demand'); ?></span>
            <strong><?php echo number_format((int)$summary['demand_pieces']); ?></strong>
        </div>
        <div class="pmd-metric">
            <span><?php bakery_te('production_manager.metric_planned'); ?></span>
            <strong><?php echo number_format((int)$summary['planned_pieces']); ?></strong>
        </div>
        <div class="pmd-metric">
            <span><?php bakery_te('production_manager.metric_made'); ?></span>
            <strong><?php echo number_format((int)$summary['produced_pieces']); ?></strong>
        </div>
        <div class="pmd-metric">
            <span><?php bakery_te('production_manager.metric_dough_weight'); ?></span>
            <strong><?php echo htmlspecialchars($summary['dough_weight']['label'] !== '' ? number_format($summary['dough_weight']['lb'], 1) . ' lb' : '—'); ?></strong>
        </div>
        <div class="pmd-metric<?php echo ((int)$summary['delta_vs_prior'] !== 0) ? ' is-info' : ''; ?>">
            <span><?php bakery_te('production_manager.metric_vs_prior'); ?></span>
            <strong><?php echo htmlspecialchars($fmtSigned((int)$summary['delta_vs_prior'])); ?></strong>
        </div>
    </section>

    <?php if ($board['doughs'] === []): ?>
        <div class="pmd-empty"><?php bakery_te('production_manager.empty'); ?></div>
    <?php else: ?>
        <section class="pmd-board" aria-label="<?php bakery_te('production_manager.board_aria'); ?>">
            <?php foreach ($board['doughs'] as $dough): ?>
                <?php
                $open = $expandAll ? ' open' : '';
                $batch = $dough['batch'];
                $standard = $dough['standard_batches'];
                $weight = $dough['dough_weight'];
                ?>
                <details class="pmd-dough"<?php echo $open; ?>>
                    <summary class="pmd-dough__summary">
                        <div class="pmd-dough__identity">
                            <strong class="pmd-dough__name"><?php echo htmlspecialchars($dough['dough_type_name']); ?></strong>
                            <?php if ($dough['product_line_name'] !== ''): ?>
                                <span class="pmd-muted"><?php echo htmlspecialchars($dough['product_line_name']); ?></span>
                            <?php endif; ?>
                            <span class="pmd-dough__sku-count">
                                <?php echo htmlspecialchars(bakery_t('production_manager.sku_count', [
                                    'count' => (int)$dough['product_count'],
                                ])); ?>
                            </span>
                        </div>
                        <div class="pmd-dough__stats">
                            <div class="pmd-stat">
                                <span><?php bakery_te('production_manager.col_pieces'); ?></span>
                                <strong><?php echo number_format((int)$dough['pieces']); ?></strong>
                            </div>
                            <div class="pmd-stat pmd-stat--wide">
                                <span><?php bakery_te('production_manager.col_batch'); ?></span>
                                <strong><?php echo htmlspecialchars($batch['label'] !== '' ? $batch['label'] : '—'); ?></strong>
                            </div>
                            <div class="pmd-stat">
                                <span><?php bakery_te('production_manager.col_dough_weight'); ?></span>
                                <strong><?php echo htmlspecialchars($weight['label'] !== '' ? number_format($weight['lb'], 1) . ' lb' : '—'); ?></strong>
                            </div>
                            <div class="pmd-stat">
                                <span><?php bakery_te('production_manager.col_vs_prior'); ?></span>
                                <strong><?php echo htmlspecialchars($fmtSigned((int)$dough['delta_vs_prior'])); ?></strong>
                            </div>
                        </div>
                        <div class="pmd-dough__status">
                            <?php foreach ($dough['statuses'] as $st): ?>
                                <span class="pmd-status pmd-status--<?php echo htmlspecialchars($st['tone']); ?>">
                                    <?php echo htmlspecialchars($st['label']); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </summary>

                    <div class="pmd-dough__body">
                        <?php if ($standard['label'] !== ''): ?>
                            <p class="pmd-standard">
                                <span><?php bakery_te('production_manager.standard_batches'); ?></span>
                                <?php echo htmlspecialchars($standard['label']); ?>
                            </p>
                        <?php endif; ?>

                        <div class="pmd-table-wrap">
                            <table class="pmd-table">
                                <thead>
                                    <tr>
                                        <th><?php bakery_te('production_manager.col_product'); ?></th>
                                        <th><?php bakery_te('production_manager.col_pieces'); ?></th>
                                        <th><?php bakery_te('production_manager.col_batch_size'); ?></th>
                                        <th><?php bakery_te('production_manager.col_batch'); ?></th>
                                        <th><?php bakery_te('production_manager.col_demand'); ?></th>
                                        <th><?php bakery_te('production_manager.col_planned'); ?></th>
                                        <th><?php bakery_te('production_manager.col_made'); ?></th>
                                        <th><?php bakery_te('production_manager.col_left'); ?></th>
                                        <th><?php bakery_te('production_manager.col_piece_weight'); ?></th>
                                        <th><?php bakery_te('production_manager.col_dough_weight'); ?></th>
                                        <th><?php bakery_te('production_manager.col_vs_prior'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($dough['products'] as $product): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                            <?php if (!empty($product['pack']['label']) && $product['pack']['label'] !== $product['batch_label']): ?>
                                                <div class="pmd-muted"><?php echo htmlspecialchars($product['pack']['label']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?php echo number_format((int)$product['bake_quantity']); ?></strong></td>
                                        <td class="pmd-muted">
                                            <?php
                                            if ($product['batch_unit'] !== 'piece' && $product['pcs_per_batch_unit'] > 1) {
                                                echo htmlspecialchars(
                                                    number_format((float)$product['pcs_per_batch_unit'])
                                                    . ' / '
                                                    . $product['batch_unit']
                                                );
                                            } else {
                                                echo '—';
                                            }
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($product['batch_label'] !== '' ? $product['batch_label'] : '—'); ?></td>
                                        <td><?php echo number_format((int)$product['demand_quantity']); ?></td>
                                        <td><?php echo $product['planned_quantity'] > 0 ? number_format((int)$product['planned_quantity']) : '—'; ?></td>
                                        <td><?php echo $board['inventory_ready'] ? number_format((int)$product['produced_quantity']) : '—'; ?></td>
                                        <td><?php echo $board['inventory_ready'] ? number_format((int)$product['left']) : '—'; ?></td>
                                        <td class="pmd-muted">
                                            <?php echo $product['weight_grams'] > 0 ? number_format((int)$product['weight_grams']) . ' g' : '—'; ?>
                                        </td>
                                        <td class="pmd-muted">
                                            <?php echo $product['dough_weight']['label'] !== ''
                                                ? htmlspecialchars(number_format($product['dough_weight']['lb'], 1) . ' lb')
                                                : '—'; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($fmtSigned((int)$product['delta_vs_prior'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="pmd-dough__actions">
                            <a class="btn btn-outline btn-sm" href="<?php echo htmlspecialchars($board['links']['production_center']); ?>">
                                <?php bakery_te('production_manager.edit_plan'); ?>
                            </a>
                            <a class="btn btn-outline btn-sm" href="<?php echo htmlspecialchars($board['links']['production']); ?>">
                                <?php bakery_te('production_manager.open_baker'); ?>
                            </a>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php elseif ($view === 'week' && $week): ?>
        <?php $ws = $week['summary']; ?>
        <p class="pmd-view-lead"><?php bakery_te('production_manager.week_lead'); ?></p>
        <?php if (!empty($week['incomplete'])): ?>
            <p class="pmd-note"><?php bakery_te('production_manager.week_incomplete'); ?></p>
        <?php endif; ?>

        <section class="pmd-summary" aria-label="<?php bakery_te('production_manager.week_summary_aria'); ?>">
            <div class="pmd-metric">
                <span><?php bakery_te('production_manager.week_metric_selected'); ?></span>
                <strong><?php echo number_format((int)$ws['selected_pieces']); ?></strong>
            </div>
            <div class="pmd-metric">
                <span><?php bakery_te('production_manager.week_metric_customers'); ?></span>
                <strong><?php echo number_format((int)$ws['selected_customers']); ?></strong>
            </div>
            <div class="pmd-metric">
                <span><?php bakery_te('production_manager.week_metric_week_total'); ?></span>
                <strong><?php echo number_format((int)$ws['week_pieces']); ?></strong>
            </div>
            <div class="pmd-metric">
                <span><?php bakery_te('production_manager.week_metric_avg'); ?></span>
                <strong><?php echo number_format((int)$ws['week_avg_active']); ?></strong>
            </div>
            <div class="pmd-metric">
                <span><?php echo htmlspecialchars(bakery_t('production_manager.week_metric_typical', [
                    'day' => $week['weekday_label'],
                ])); ?></span>
                <strong><?php echo number_format((int)$ws['typical_weekday_avg']); ?></strong>
            </div>
            <div class="pmd-metric<?php echo ($ws['vs_typical'] !== null && (int)$ws['vs_typical'] !== 0) ? ' is-info' : ''; ?>">
                <span><?php bakery_te('production_manager.week_metric_vs_typical'); ?></span>
                <strong><?php echo $ws['vs_typical'] === null ? '—' : htmlspecialchars($fmtSigned((int)$ws['vs_typical'])); ?></strong>
            </div>
        </section>

        <section class="pmd-week-strip" aria-label="<?php bakery_te('production_manager.week_strip_aria'); ?>">
            <?php
            $maxPieces = 1;
            foreach ($week['days'] as $dayRow) {
                $maxPieces = max($maxPieces, (int)$dayRow['pieces']);
            }
            foreach ($week['days'] as $dayRow):
                $pct = (int)round(((int)$dayRow['pieces'] / $maxPieces) * 100);
                $cls = 'pmd-week-day';
                if (!empty($dayRow['is_selected'])) {
                    $cls .= ' is-selected';
                }
                if (empty($dayRow['has_orders'])) {
                    $cls .= ' is-empty';
                }
            ?>
                <a class="<?php echo $cls; ?>" href="<?php echo htmlspecialchars($pmdHref($dayRow['date'], 'week')); ?>">
                    <span class="pmd-week-day__label"><?php echo htmlspecialchars($dayRow['label']); ?></span>
                    <span class="pmd-week-day__bar" style="--pmd-week-pct: <?php echo $pct; ?>%"></span>
                    <strong class="pmd-week-day__pieces"><?php echo number_format((int)$dayRow['pieces']); ?></strong>
                    <span class="pmd-week-day__customers"><?php echo number_format((int)$dayRow['customers']); ?> cust</span>
                </a>
            <?php endforeach; ?>
        </section>

        <div class="pmd-table-wrap">
            <table class="pmd-table">
                <thead>
                    <tr>
                        <th><?php bakery_te('production_manager.col_product'); ?></th>
                        <th><?php bakery_te('production_manager.col_pieces'); ?></th>
                        <th><?php bakery_te('production_manager.week_col_customers'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($week['top_products'] === []): ?>
                    <tr><td colspan="3" class="pmd-muted"><?php bakery_te('production_manager.week_no_products'); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($week['top_products'] as $tp): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($tp['name']); ?></strong></td>
                            <td><?php echo number_format((int)$tp['pieces']); ?></td>
                            <td><?php echo number_format((int)$tp['customers']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="pmd-muted">
            <a href="<?php echo htmlspecialchars($links['daily_orders']); ?>"><?php bakery_te('production_manager.link_daily_orders'); ?></a>
        </p>

    <?php elseif ($view === 'routes' && $routes): ?>
        <?php $rs = $routes['summary']; ?>
        <p class="pmd-view-lead"><?php echo htmlspecialchars(bakery_t('production_manager.routes_lead', [
            'day' => $routes['weekday_label'],
        ])); ?></p>
        <?php if (!empty($routes['incomplete'])): ?>
            <p class="pmd-note"><?php bakery_te('production_manager.routes_incomplete'); ?></p>
        <?php endif; ?>

        <section class="pmd-summary" aria-label="<?php bakery_te('production_manager.routes_summary_aria'); ?>">
            <div class="pmd-metric">
                <span><?php bakery_te('production_manager.routes_metric_plan'); ?></span>
                <strong><?php echo number_format((int)$rs['planned_stops']); ?></strong>
            </div>
            <div class="pmd-metric">
                <span><?php bakery_te('production_manager.routes_metric_actual'); ?></span>
                <strong><?php echo number_format((int)$rs['actual_stops']); ?></strong>
            </div>
            <div class="pmd-metric">
                <span><?php bakery_te('production_manager.routes_metric_match'); ?></span>
                <strong><?php echo number_format((int)$rs['matched']); ?></strong>
            </div>
            <div class="pmd-metric<?php echo ((int)$rs['reassigned'] > 0) ? ' is-info' : ''; ?>">
                <span><?php bakery_te('production_manager.routes_metric_reassigned'); ?></span>
                <strong><?php echo number_format((int)$rs['reassigned']); ?></strong>
            </div>
            <div class="pmd-metric<?php echo ((int)$rs['plan_only'] > 0) ? ' is-warn' : ''; ?>">
                <span><?php bakery_te('production_manager.routes_metric_plan_only'); ?></span>
                <strong><?php echo number_format((int)$rs['plan_only']); ?></strong>
            </div>
            <div class="pmd-metric">
                <span><?php bakery_te('production_manager.routes_metric_delivered'); ?></span>
                <strong><?php echo number_format((int)$rs['delivered']); ?></strong>
            </div>
        </section>

        <div class="pmd-table-wrap">
            <table class="pmd-table">
                <thead>
                    <tr>
                        <th><?php bakery_te('production_manager.routes_col_customer'); ?></th>
                        <th><?php bakery_te('production_manager.routes_col_plan'); ?></th>
                        <th><?php bakery_te('production_manager.routes_col_actual'); ?></th>
                        <th><?php bakery_te('production_manager.routes_col_status'); ?></th>
                        <th><?php bakery_te('production_manager.routes_col_scheduled'); ?></th>
                        <th><?php bakery_te('production_manager.routes_col_actual_time'); ?></th>
                        <th><?php bakery_te('production_manager.routes_col_align'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($routes['rows'] === []): ?>
                    <tr><td colspan="7" class="pmd-muted"><?php bakery_te('production_manager.routes_empty'); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($routes['rows'] as $row): ?>
                        <?php
                        $align = (string)$row['alignment'];
                        $alignTone = [
                            'match' => 'ok',
                            'reassigned' => 'info',
                            'plan_only' => 'warn',
                            'actual_only' => 'danger',
                        ][$align] ?? 'muted';
                        $alignLabel = bakery_t('production_manager.routes_align_' . $align);
                        ?>
                        <tr class="pmd-row--<?php echo htmlspecialchars($alignTone); ?>">
                            <td>
                                <strong><?php echo htmlspecialchars($row['customer_name']); ?></strong>
                                <?php if ($row['zone'] !== ''): ?>
                                    <div class="pmd-muted"><?php echo htmlspecialchars($row['zone']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['plan_driver_name']): ?>
                                    <?php echo htmlspecialchars($row['plan_driver_name']); ?>
                                    <?php if ($row['plan_route_order'] !== null): ?>
                                        <span class="pmd-muted">#<?php echo (int)$row['plan_route_order']; ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="pmd-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['actual_driver_name']): ?>
                                    <?php echo htmlspecialchars($row['actual_driver_name']); ?>
                                    <?php if ($row['actual_route_order'] !== null): ?>
                                        <span class="pmd-muted">#<?php echo (int)$row['actual_route_order']; ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="pmd-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $row['delivery_status'] ? htmlspecialchars($row['delivery_status']) : '—'; ?></td>
                            <td><?php echo $fmtTime($row['scheduled_delivery_time'] ?? null); ?></td>
                            <td><?php echo $fmtTime($row['actual_delivery_time'] ?? null); ?></td>
                            <td><span class="pmd-status pmd-status--<?php echo htmlspecialchars($alignTone); ?>"><?php echo htmlspecialchars($alignLabel); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="pmd-muted">
            <a href="<?php echo htmlspecialchars($links['route_manager']); ?>"><?php bakery_te('production_manager.link_route_manager'); ?></a>
            ·
            <a href="<?php echo htmlspecialchars($links['standing_routes']); ?>"><?php bakery_te('production_manager.link_standing_routes'); ?></a>
        </p>

    <?php elseif ($view === 'supply' && $supply): ?>
        <?php $ss = $supply['summary']; ?>
        <p class="pmd-view-lead"><?php bakery_te('production_manager.supply_lead'); ?></p>
        <?php if (!empty($supply['incomplete'])): ?>
            <p class="pmd-note"><?php bakery_te('production_manager.supply_incomplete'); ?></p>
        <?php endif; ?>

        <section class="pmd-status-row" aria-label="<?php bakery_te('production_manager.status_aria'); ?>">
            <?php if ($supply['committed']): ?>
                <span class="pmd-pill pmd-pill--ok"><?php bakery_te('production_manager.committed'); ?></span>
            <?php else: ?>
                <span class="pmd-pill pmd-pill--warn"><?php bakery_te('production_manager.not_committed'); ?></span>
            <?php endif; ?>
            <?php if (!$supply['inventory_ready']): ?>
                <span class="pmd-pill pmd-pill--muted"><?php bakery_te('production_manager.supply_no_inventory'); ?></span>
            <?php endif; ?>
        </section>

        <section class="pmd-summary" aria-label="<?php bakery_te('production_manager.supply_summary_aria'); ?>">
            <div class="pmd-metric">
                <span><?php bakery_te('production_manager.metric_demand'); ?></span>
                <strong><?php echo number_format((int)$ss['demand']); ?></strong>
            </div>
            <div class="pmd-metric">
                <span><?php bakery_te('production_manager.metric_pieces'); ?></span>
                <strong><?php echo number_format((int)$ss['bake']); ?></strong>
            </div>
            <div class="pmd-metric">
                <span><?php bakery_te('production_manager.metric_made'); ?></span>
                <strong><?php echo number_format((int)$ss['produced']); ?></strong>
            </div>
            <div class="pmd-metric">
                <span><?php bakery_te('production_manager.supply_metric_on_hand'); ?></span>
                <strong><?php echo number_format((int)$ss['on_hand']); ?></strong>
            </div>
            <div class="pmd-metric<?php echo ((int)$ss['short_skus'] > 0) ? ' is-warn' : ''; ?>">
                <span><?php bakery_te('production_manager.supply_metric_short'); ?></span>
                <strong><?php echo number_format((int)$ss['short_skus']); ?></strong>
            </div>
            <div class="pmd-metric">
                <span><?php bakery_te('production_manager.supply_metric_net_gap'); ?></span>
                <strong><?php echo htmlspecialchars($fmtSigned((int)$ss['net_gap'])); ?></strong>
            </div>
        </section>

        <div class="pmd-table-wrap">
            <table class="pmd-table">
                <thead>
                    <tr>
                        <th><?php bakery_te('production_manager.col_product'); ?></th>
                        <th><?php bakery_te('production_manager.col_demand'); ?></th>
                        <th><?php bakery_te('production_manager.metric_pieces'); ?></th>
                        <th><?php bakery_te('production_manager.col_made'); ?></th>
                        <th><?php bakery_te('production_manager.supply_col_on_hand'); ?></th>
                        <th><?php bakery_te('production_manager.supply_col_loaded'); ?></th>
                        <th><?php bakery_te('production_manager.supply_col_gap'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($supply['rows'] === []): ?>
                    <tr><td colspan="7" class="pmd-muted"><?php bakery_te('production_manager.supply_empty'); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($supply['rows'] as $row): ?>
                        <tr class="pmd-row--<?php echo htmlspecialchars($row['tone']); ?>">
                            <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                            <td><?php echo number_format((int)$row['demand']); ?></td>
                            <td><?php echo number_format((int)$row['bake']); ?></td>
                            <td><?php echo $supply['inventory_ready'] ? number_format((int)$row['produced']) : '—'; ?></td>
                            <td><?php echo $supply['inventory_ready'] ? number_format((int)$row['on_hand']) : '—'; ?></td>
                            <td><?php echo $supply['inventory_ready'] ? number_format((int)$row['loaded']) : '—'; ?></td>
                            <td>
                                <?php if ((int)$row['demand'] <= 0): ?>
                                    <span class="pmd-muted">—</span>
                                <?php else: ?>
                                    <span class="pmd-status pmd-status--<?php echo htmlspecialchars($row['tone'] === 'short' ? 'danger' : ($row['tone'] === 'extra' ? 'info' : 'ok')); ?>">
                                        <?php echo htmlspecialchars($fmtSigned((int)$row['gap'])); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="pmd-muted">
            <a href="<?php echo htmlspecialchars($links['production_center']); ?>"><?php bakery_te('production_manager.edit_plan'); ?></a>
            ·
            <a href="<?php echo htmlspecialchars($links['inventory']); ?>"><?php bakery_te('production_manager.link_inventory'); ?></a>
        </p>
    <?php endif; ?>
</main>
<?php require_once 'includes/footer.php'; ?>
