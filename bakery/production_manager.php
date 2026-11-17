<?php
/**
 * Production Manager — bake-day board: doughs, formulas, destinations, crew.
 * Edit and commit stay on Production Center. Bakers stay on Daily Production.
 */
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/production_manager_dashboard.php';
require_once 'includes/production_day_crew.php';
require_once 'includes/baker_mix.php';
require_once 'includes/production_manager_print.php';
require_once 'includes/production_workflow_strip.php';

$selectedDate = bakery_pmd_resolve_bake_date((string)($_GET['date'] ?? ''));
$view = bakery_pmd_resolve_view((string)($_GET['view'] ?? 'batches'));
$sourceMode = bakery_pmd_resolve_source((string)($_GET['source'] ?? 'bake_list'));
$isPrint = (string)($_GET['print'] ?? '') === '1';
$expandAll = (string)($_GET['expand'] ?? '') === '1' || $isPrint;
if ($isPrint) {
    $view = 'batches';
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    bakery_production_day_handle_post($db);
}

$coverDates = bakery_pmd_bake_cover_dates($selectedDate);
$coverDate = bakery_pmd_resolve_cover((string)($_GET['cover'] ?? ''), $coverDates, $selectedDate);
$leadError = (string)($_GET['lead_error'] ?? '');

$board = null;
$week = null;
$bakeWeek = null;
$routes = null;
$supply = null;
$crew = null;
$leads = [];
$staffOptions = [];
$leadOptions = null;
$links = bakery_pmd_links($coverDate);

if ($view === 'week') {
    $week = bakery_pmd_week_orders($db, $coverDate);
    $links = $week['links'];
    try {
        $bakeWeek = bakery_pmd_bake_week($db, $selectedDate);
    } catch (Throwable $e) {
        error_log('production_manager bake week: ' . $e->getMessage());
    }
} elseif ($view === 'routes') {
    $routes = bakery_pmd_route_plan_vs_actual($db, $coverDate);
    $links = $routes['links'];
} elseif ($view === 'supply') {
    $supply = bakery_pmd_demand_vs_supply($db, $coverDate);
    $links = $supply['links'];
} else {
    $board = bakery_pmd_bake_day($db, $selectedDate, [
        'source' => $sourceMode,
        'usual_weeks' => ($expandAll && !$isPrint) ? 8 : 1,
    ]);
    $links = $board['links'];
    $view = 'batches';
    try {
        $leads = bakery_production_leads($db);
        bakery_production_apply_leads($board, $leads);
        $crew = bakery_production_crew_for_day($db, $selectedDate);
        $staffOptions = bakery_production_staff_options($db);
        if ($expandAll && !$isPrint) {
            $leadOptions = bakery_production_lead_options($db);
        }
    } catch (Throwable $e) {
        error_log('production_manager crew: ' . $e->getMessage());
    }
}

$summary = $board['summary'] ?? null;
$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

$pmdHref = static function (string $date, string $viewName = 'batches', bool $expand = false) use ($sourceMode): string {
    $q = ['date' => $date, 'view' => $viewName];
    if ($sourceMode !== 'bake_list') {
        $q['source'] = $sourceMode;
    }
    if ($expand && $viewName === 'batches') {
        $q['expand'] = '1';
    }
    return 'production_manager.php?' . http_build_query($q);
};
$printLinks = bakery_pmd_print_links($selectedDate);

$hubStages = [];
try {
    $hubStages = bakery_production_workflow_kitchen_stages($db, $coverDate);
} catch (Throwable $e) {
    error_log('production_manager workflow strip: ' . $e->getMessage());
}

$fmtSigned = static function (int $n): string {
    return ($n > 0 ? '+' : '') . number_format($n);
};

$fmtTime = static function (?string $t): string {
    if ($t === null || $t === '') {
        return '-';
    }
    $ts = strtotime($t);
    return $ts ? bakery_locale_time($ts) : htmlspecialchars($t);
};

$page_title = ($isPrint && $board)
    ? bakery_t(bakery_pmd_print_title_key($board))
    : bakery_t('page.production_manager');
require_once 'includes/header.php';
if (!$isPrint) {
    require_once 'includes/nav.php';
}
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/production_manager.css'); ?>">
<?php if ($isPrint && $board): ?>
<main class="pmd-print-sheet" id="productionManagerPrint">
    <div class="pmd-print-toolbar no-print">
        <button type="button" class="btn btn-primary" onclick="window.print()"><?php bakery_te('production_manager.print_now'); ?></button>
        <a class="btn btn-outline" href="<?php echo htmlspecialchars($pmdHref($selectedDate, 'batches', true)); ?>"><?php bakery_te('production_manager.print_back'); ?></a>
    </div>
    <header class="pmd-print-head">
        <div>
            <p class="pmd-print-kicker"><?php bakery_te('production_manager.print_kicker'); ?></p>
            <h1><?php bakery_te(bakery_pmd_print_title_key($board)); ?></h1>
            <p class="pmd-print-sub"><?php echo htmlspecialchars((string)($board['quantity_date_display'] ?? $board['date_display'])); ?></p>
            <?php if (!empty($board['date_mismatch'])): ?>
                <p class="pmd-note"><?php echo htmlspecialchars(bakery_t('production_manager.print_date_mismatch', [
                    'quantity_date' => (string)($board['quantity_date_display'] ?? ''),
                    'selected_date' => (string)($board['date_display'] ?? ''),
                ])); ?></p>
            <?php endif; ?>
        </div>
        <div class="pmd-print-meta">
            <p><strong><?php bakery_te('production_manager.print_covers'); ?></strong> <?php echo htmlspecialchars((string)($board['cover_label'] ?? '') !== '' ? (string)$board['cover_label'] : bakery_pmd_print_route_label((string)($board['quantity_date'] ?? $selectedDate))); ?></p>
            <p><strong><?php bakery_te('production_manager.print_source'); ?></strong> <?php echo htmlspecialchars(bakery_pmd_print_source_label($board)); ?></p>
            <p><strong><?php bakery_te('production_manager.metric_pieces'); ?></strong> <?php echo number_format((int)($board['summary']['pieces'] ?? 0)); ?>
                · <strong><?php bakery_te('production_manager.metric_doughs'); ?></strong> <?php echo number_format((int)($board['summary']['dough_types'] ?? 0)); ?>
                · <strong><?php bakery_te('production_manager.metric_products'); ?></strong> <?php echo number_format((int)($board['summary']['products'] ?? 0)); ?></p>
        </div>
    </header>
    <?php if ($board['doughs'] === []): ?>
        <p class="pmd-empty"><?php bakery_te('production_manager.empty'); ?></p>
    <?php else: ?>
        <table class="pmd-print-table">
            <thead>
                <tr>
                    <th><?php bakery_te('production_manager.print_col_dough'); ?></th>
                    <th><?php bakery_te('production_manager.col_batch'); ?></th>
                    <th class="num"><?php bakery_te('production_manager.col_pieces'); ?></th>
                    <th><?php bakery_te('production_manager.print_col_skus'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($board['doughs'] as $dough): ?>
                <tr class="pmd-print-dough">
                    <td>
                        <strong><?php echo htmlspecialchars($dough['dough_type_name']); ?></strong>
                        <?php if ($dough['product_line_name'] !== ''): ?>
                            <span class="pmd-print-muted"><?php echo htmlspecialchars($dough['product_line_name']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($dough['batch']['label'] !== '' ? $dough['batch']['label'] : '-'); ?></td>
                    <td class="num"><strong><?php echo number_format((int)$dough['pieces']); ?></strong></td>
                    <td class="pmd-print-muted"><?php echo (int)$dough['product_count']; ?></td>
                </tr>
                <?php foreach ($dough['products'] as $product): ?>
                    <?php if ((int)$product['bake_quantity'] <= 0) { continue; } ?>
                    <tr class="pmd-print-sku">
                        <td><?php echo htmlspecialchars($product['name']); ?></td>
                        <td><?php echo htmlspecialchars($product['batch_label'] !== '' ? $product['batch_label'] : '-'); ?></td>
                        <td class="num"><?php echo number_format((int)$product['bake_quantity']); ?></td>
                        <td></td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php if (is_array($crew) && ($crew['people'] ?? []) !== []): ?>
        <p class="pmd-print-sub">
            <?php bakery_te('production_manager.crew_title'); ?>:
            <?php
            $printCrew = [];
            foreach ($crew['people'] as $person) {
                $bit = (string)$person['display_name'];
                if (!empty($person['hours_label'])) {
                    $bit .= ' ' . $person['hours_label'];
                }
                $printCrew[] = $bit;
            }
            echo htmlspecialchars(implode(' · ', $printCrew));
            ?>
        </p>
    <?php endif; ?>
    <footer class="pmd-print-foot">
        <p><?php bakery_te(bakery_pmd_print_footer_key($board)); ?></p>
        <p><?php echo htmlspecialchars(bakery_t('production_manager.print_letter', [
            'date' => bakery_format_user_stamp(time(), 'n/j/Y g:i A'),
        ])); ?></p>
    </footer>
</main>
</body>
</html>
<?php return; endif; ?>
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
            <a class="btn btn-outline" href="<?php echo htmlspecialchars($printLinks['bake_sheet']); ?>">
                <?php bakery_te('production_manager.print_sheet_link'); ?>
            </a>
            <a class="btn btn-outline" href="<?php echo htmlspecialchars($printLinks['draft']); ?>">
                <?php bakery_te('production_manager.print_draft_link'); ?>
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
        <label class="pmd-date-label"><?php bakery_te('production_manager.bake_day'); ?>
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

    <?php if (($view === 'routes' || $view === 'supply') && $coverDates === []): ?>
        <p class="pmd-note"><?php bakery_te('production_manager.no_cover'); ?></p>
    <?php elseif (($view === 'routes' || $view === 'supply') && count($coverDates) > 1): ?>
        <nav class="pmd-covers" aria-label="<?php bakery_te('production_manager.cover_for'); ?>">
            <?php foreach ($coverDates as $chipDate): ?>
                <a class="pmd-tab<?php echo $chipDate === $coverDate ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($pmdHref($selectedDate, $view, false) . '&cover=' . rawurlencode($chipDate)); ?>">
                    <?php echo htmlspecialchars(date('D n/j', strtotime($chipDate))); ?>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php if ($view === 'batches' && $board): ?>
    <?php if (!empty($board['cover_label'])): ?>
        <p class="pmd-view-lead"><?php echo htmlspecialchars((string)$board['cover_label']); ?></p>
    <?php endif; ?>
    <?php foreach ($board['families'] ?? [] as $familyRow): ?>
        <?php if (!empty($familyRow['active'])) { continue; } ?>
        <p class="pmd-quiet"><?php bakery_te($familyRow['family'] === 'sour_flour' ? 'production_manager.family_quiet_sour_flour' : 'production_manager.family_quiet_daily'); ?></p>
    <?php endforeach; ?>

    <section class="pmd-crew" aria-label="<?php bakery_te('production_manager.crew_aria'); ?>">
        <div class="pmd-crew__head">
            <h2><?php bakery_te('production_manager.crew_title'); ?></h2>
        </div>
        <?php if (!is_array($crew) || ($crew['people'] ?? []) === []): ?>
            <p class="pmd-muted"><?php bakery_te('production_manager.crew_empty'); ?></p>
        <?php else: ?>
            <ul class="pmd-crew__list">
                <?php foreach ($crew['people'] as $person): ?>
                    <li class="pmd-crew__person<?php echo !empty($person['punched']) ? ' is-in' : ''; ?>">
                        <strong><?php echo htmlspecialchars((string)$person['display_name']); ?></strong>
                        <span class="pmd-muted">
                            <?php
                            if (!empty($person['extra'])) {
                                bakery_te('production_manager.crew_extra');
                            } elseif (!empty($person['punched'])) {
                                bakery_te('production_manager.crew_in');
                            } else {
                                bakery_te('production_manager.crew_not_in');
                            }
                            ?>
                        </span>
                        <?php if (!empty($person['clock_in_at'])): ?>
                            <span><?php echo htmlspecialchars(bakery_time_clock_format_time((string)$person['clock_in_at'])); ?>
                                <?php echo bakery_locale_range_word(); ?>
                                <?php echo $person['clock_out_at']
                                    ? htmlspecialchars(bakery_time_clock_format_time((string)$person['clock_out_at']))
                                    : htmlspecialchars(bakery_t('time_clock.still_in')); ?>
                                · <?php echo htmlspecialchars((string)$person['hours_label']); ?>
                            </span>
                        <?php endif; ?>
                        <?php foreach ($person['flags'] ?? [] as $flag): ?>
                            <span class="pmd-pill pmd-pill--warn"><?php echo htmlspecialchars(bakery_t('time_clock.flag_' . $flag)); ?></span>
                        <?php endforeach; ?>
                        <form method="post" action="production_manager.php" class="pmd-crew__form">
                            <?php echo bakery_csrf_field(); ?>
                            <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>">
                            <input type="hidden" name="expand" value="<?php echo $expandAll ? '1' : '0'; ?>">
                            <input type="hidden" name="user_id" value="<?php echo (int)$person['user_id']; ?>">
                            <?php if (!empty($person['pin'])): ?>
                                <input type="hidden" name="action" value="crew_clear">
                                <button type="submit" class="btn btn-outline btn-sm"><?php bakery_te('production_manager.crew_clear'); ?></button>
                            <?php else: ?>
                                <input type="hidden" name="action" value="crew_remove">
                                <button type="submit" class="btn btn-outline btn-sm"><?php bakery_te('production_manager.crew_remove'); ?></button>
                            <?php endif; ?>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($staffOptions !== []): ?>
            <form method="post" action="production_manager.php" class="pmd-crew__add">
                <?php echo bakery_csrf_field(); ?>
                <input type="hidden" name="action" value="crew_add">
                <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>">
                <input type="hidden" name="expand" value="<?php echo $expandAll ? '1' : '0'; ?>">
                <label><?php bakery_te('production_manager.crew_person'); ?>
                    <select name="user_id" required>
                        <?php foreach ($staffOptions as $staff): ?>
                            <option value="<?php echo (int)$staff['id']; ?>"><?php echo htmlspecialchars($staff['display_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="btn btn-outline btn-sm"><?php bakery_te('production_manager.crew_add'); ?></button>
            </form>
        <?php endif; ?>
    </section>

    <?php if ($expandAll && !$isPrint && is_array($leadOptions)): ?>
        <details class="pmd-leads" open>
            <summary><?php bakery_te('production_manager.leads_title'); ?></summary>
            <p class="pmd-muted"><?php bakery_te('production_manager.leads_help'); ?></p>
            <?php if ($leadError === 'scope'): ?>
                <p class="pmd-note"><?php bakery_te('production_manager.leads_need_scope'); ?></p>
            <?php endif; ?>
            <?php if ($leads === []): ?>
                <p class="pmd-muted"><?php bakery_te('production_manager.leads_none'); ?></p>
            <?php else: ?>
                <ul class="pmd-leads__list">
                    <?php foreach ($leads as $lead): ?>
                        <li>
                            <strong><?php echo htmlspecialchars((string)$lead['display_name']); ?></strong>
                            <span class="pmd-muted">
                                <?php
                                $bits = array_filter([
                                    (string)($lead['product_line_name'] ?? ''),
                                    (string)($lead['dough_type_name'] ?? ''),
                                    (string)($lead['customer_name'] ?? ''),
                                ]);
                                echo htmlspecialchars(implode(' · ', $bits));
                                ?>
                            </span>
                            <form method="post" action="production_manager.php">
                                <?php echo bakery_csrf_field(); ?>
                                <input type="hidden" name="action" value="lead_delete">
                                <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>">
                                <input type="hidden" name="lead_id" value="<?php echo (int)$lead['id']; ?>">
                                <button type="submit" class="btn btn-outline btn-sm"><?php bakery_te('production_manager.leads_delete'); ?></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <form method="post" action="production_manager.php" class="pmd-leads__add">
                <?php echo bakery_csrf_field(); ?>
                <input type="hidden" name="action" value="lead_add">
                <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>">
                <label><?php bakery_te('production_manager.leads_user'); ?>
                    <select name="user_id" required>
                        <?php foreach ($leadOptions['staff'] as $staff): ?>
                            <option value="<?php echo (int)$staff['id']; ?>"><?php echo htmlspecialchars($staff['display_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><?php bakery_te('production_manager.leads_line'); ?>
                    <select name="product_line_id">
                        <option value=""><?php bakery_te('production_manager.leads_any'); ?></option>
                        <?php foreach ($leadOptions['lines'] as $line): ?>
                            <option value="<?php echo (int)$line['id']; ?>"><?php echo htmlspecialchars($line['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><?php bakery_te('production_manager.leads_dough'); ?>
                    <select name="dough_type_id">
                        <option value=""><?php bakery_te('production_manager.leads_any'); ?></option>
                        <?php foreach ($leadOptions['doughs'] as $doughOption): ?>
                            <option value="<?php echo (int)$doughOption['id']; ?>">
                                <?php echo htmlspecialchars(trim($doughOption['line_name'] . ' ' . $doughOption['name'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><?php bakery_te('production_manager.leads_customer'); ?>
                    <select name="customer_id">
                        <option value=""><?php bakery_te('production_manager.leads_any'); ?></option>
                        <?php foreach ($leadOptions['customers'] as $customerOption): ?>
                            <option value="<?php echo (int)$customerOption['id']; ?>"><?php echo htmlspecialchars($customerOption['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="btn btn-outline"><?php bakery_te('production_manager.leads_save'); ?></button>
            </form>
        </details>
    <?php endif; ?>

    <section class="pmd-status-row" aria-label="<?php bakery_te('production_manager.status_aria'); ?>">
        <?php if (($board['bake_source'] ?? '') === 'mixed'): ?>
            <span class="pmd-pill pmd-pill--warn"><?php bakery_te('production_manager.partly_committed'); ?></span>
        <?php elseif ($board['committed']): ?>
            <span class="pmd-pill pmd-pill--ok"><?php bakery_te('production_manager.committed'); ?></span>
        <?php else: ?>
            <span class="pmd-pill pmd-pill--warn"><?php bakery_te('production_manager.not_committed'); ?></span>
        <?php endif; ?>
        <span class="pmd-pill pmd-pill--muted">
            <?php
            if (($board['bake_source'] ?? '') === 'last_entered') {
                echo htmlspecialchars(bakery_pmd_print_source_label($board));
            } elseif (($board['bake_source'] ?? '') === 'committed_plan') {
                bakery_te('production_manager.source_committed');
            } elseif (($board['bake_source'] ?? '') === 'mixed') {
                bakery_te('production_manager.source_mixed');
            } else {
                bakery_te('production_manager.source_rhythm');
            }
            ?>
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
    <?php if (($board['bake_source'] ?? '') === 'demand'): ?>
        <p class="pmd-note"><?php bakery_te('production_manager.rhythm_note'); ?></p>
    <?php elseif (($board['bake_source'] ?? '') === 'mixed'): ?>
        <p class="pmd-note"><?php bakery_te('production_manager.mixed_note'); ?></p>
    <?php endif; ?>

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
            <strong><?php echo htmlspecialchars($summary['dough_weight']['label'] !== '' ? number_format($summary['dough_weight']['lb'], 1) . ' lb' : '-'); ?></strong>
        </div>
        <div class="pmd-metric<?php echo ((int)$summary['delta_vs_prior'] !== 0) ? ' is-info' : ''; ?>">
            <span><?php bakery_te('production_manager.metric_vs_prior'); ?></span>
            <strong><?php echo htmlspecialchars($fmtSigned((int)$summary['delta_vs_prior'])); ?></strong>
        </div>
    </section>

    <?php if ($board['doughs'] === []): ?>
        <?php if (!empty($board['any_family_active'])): ?>
        <div class="pmd-empty"><?php bakery_te('production_manager.empty'); ?></div>
        <?php endif; ?>
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
                            <?php if (!empty($dough['leads'])): ?>
                                <span class="pmd-lead"><?php echo htmlspecialchars(implode(', ', $dough['leads'])); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($dough['cover_dates'])): ?>
                                <span class="pmd-muted"><?php echo htmlspecialchars(bakery_pmd_format_cover_dates($dough['cover_dates'])); ?></span>
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
                                <strong><?php echo htmlspecialchars($batch['label'] !== '' ? $batch['label'] : '-'); ?></strong>
                            </div>
                            <div class="pmd-stat">
                                <span><?php bakery_te('production_manager.col_dough_weight'); ?></span>
                                <strong><?php echo htmlspecialchars($weight['label'] !== '' ? number_format($weight['lb'], 1) . ' lb' : '-'); ?></strong>
                            </div>
                            <div class="pmd-stat">
                                <span><?php bakery_te('production_manager.last_week'); ?></span>
                                <strong><?php echo number_format((int)($dough['usual_last'] ?? $dough['prior_pieces'] ?? 0)); ?></strong>
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
                        <?php if (isset($dough['usual_median']) && $dough['usual_median'] !== null): ?>
                            <p class="pmd-standard"><?php echo htmlspecialchars(bakery_t('production_manager.usual_median', [
                                'count' => number_format((int)$dough['usual_median']),
                            ])); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($dough['formula']['ingredients']) && function_exists('bakery_baker_mix_echo_formula')): ?>
                            <div class="pmd-formula">
                                <?php bakery_baker_mix_echo_formula(
                                    $dough['formula']['ingredients'],
                                    (float)$dough['formula']['total_flour_grams'],
                                    (float)$dough['formula']['total_dough_grams'],
                                    false,
                                    is_array($dough['formula']['structure'] ?? null) ? $dough['formula']['structure'] : null,
                                    is_array($dough['formula']['add_in_rows'] ?? null) ? $dough['formula']['add_in_rows'] : []
                                ); ?>
                            </div>
                        <?php elseif ((int)$dough['pieces'] > 0): ?>
                            <p class="pmd-muted"><?php bakery_te('production_manager.formula_missing'); ?></p>
                        <?php endif; ?>
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
                                                echo '-';
                                            }
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($product['batch_label'] !== '' ? $product['batch_label'] : '-'); ?></td>
                                        <td><?php echo number_format((int)$product['demand_quantity']); ?></td>
                                        <td><?php echo $product['planned_quantity'] > 0 ? number_format((int)$product['planned_quantity']) : '-'; ?></td>
                                        <td><?php echo $board['inventory_ready'] ? number_format((int)$product['produced_quantity']) : '-'; ?></td>
                                        <td><?php echo $board['inventory_ready'] ? number_format((int)$product['left']) : '-'; ?></td>
                                        <td class="pmd-muted">
                                            <?php echo $product['weight_grams'] > 0 ? number_format((int)$product['weight_grams']) . ' g' : '-'; ?>
                                        </td>
                                        <td class="pmd-muted">
                                            <?php echo $product['dough_weight']['label'] !== ''
                                                ? htmlspecialchars(number_format($product['dough_weight']['lb'], 1) . ' lb')
                                                : '-'; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($fmtSigned((int)$product['delta_vs_prior'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php foreach ($dough['products'] as $product): ?>
                            <?php $where = $product['destinations'] ?? []; ?>
                            <details class="pmd-where"<?php echo $expandAll ? ' open' : ''; ?>>
                                <summary><?php echo htmlspecialchars(bakery_t('production_manager.where_goes')); ?> — <?php echo htmlspecialchars((string)$product['name']); ?></summary>
                                <?php if ($where === []): ?>
                                    <p class="pmd-muted"><?php bakery_te('production_manager.where_empty'); ?></p>
                                <?php else: ?>
                                    <table class="pmd-table">
                                        <thead>
                                            <tr>
                                                <th><?php bakery_te('production_manager.col_when'); ?></th>
                                                <th><?php bakery_te('production_manager.col_customer'); ?></th>
                                                <th><?php bakery_te('production_manager.col_pieces'); ?></th>
                                                <th><?php bakery_te('production_manager.col_source'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($where as $dest): ?>
                                            <?php
                                            $sourceKey = [
                                                'daily' => 'production_manager.dest_source_daily',
                                                'standing' => 'production_manager.dest_source_standing',
                                                'pan_dulce_standard' => 'production_manager.dest_source_pan_dulce_standard',
                                                'mixed' => 'production_manager.dest_source_mixed',
                                            ][(string)$dest['source']] ?? '';
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars(date('D n/j', strtotime((string)$dest['date']))); ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars((string)$dest['customer_name']); ?></strong>
                                                    <?php if (!empty($dest['leads'])): ?>
                                                        <div class="pmd-lead"><?php echo htmlspecialchars(implode(', ', $dest['leads'])); ?></div>
                                                    <?php endif; ?>
                                                    <?php if (($dest['zone'] ?? '') !== ''): ?>
                                                        <div class="pmd-muted"><?php echo htmlspecialchars((string)$dest['zone']); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo number_format((int)$dest['quantity']); ?></td>
                                                <td><?php echo $sourceKey !== '' ? htmlspecialchars(bakery_t($sourceKey)) : htmlspecialchars((string)$dest['source']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </details>
                        <?php endforeach; ?>

                        <div class="pmd-dough__actions">
                            <a class="btn btn-outline btn-sm" href="<?php echo htmlspecialchars($dough['links']['production_center'] ?? $board['links']['production_center']); ?>">
                                <?php bakery_te('production_manager.edit_plan'); ?>
                            </a>
                            <a class="btn btn-outline btn-sm" href="<?php echo htmlspecialchars($dough['links']['production'] ?? $board['links']['production']); ?>">
                                <?php bakery_te('production_manager.open_baker'); ?>
                            </a>
                            <a class="btn btn-outline btn-sm" href="<?php echo htmlspecialchars($dough['links']['pack_list'] ?? $board['links']['pack_list']); ?>">
                                <?php bakery_te('production_manager.link_pack'); ?>
                            </a>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php elseif ($view === 'week' && $week): ?>
        <?php if (is_array($bakeWeek) && ($bakeWeek['days'] ?? []) !== []): ?>
            <p class="pmd-view-lead"><?php bakery_te('production_manager.week_bake_lead'); ?></p>
            <div class="pmd-table-wrap">
                <table class="pmd-table" aria-label="<?php bakery_te('production_manager.week_bake_aria'); ?>">
                    <thead>
                        <tr>
                            <th><?php bakery_te('production_manager.week_col_day'); ?></th>
                            <th><?php bakery_te('production_manager.week_col_pan_dulce'); ?></th>
                            <th><?php bakery_te('production_manager.week_col_sour_flour'); ?></th>
                            <th><?php bakery_te('production_manager.week_col_crew'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($bakeWeek['days'] as $bakeDayRow): ?>
                        <?php
                        $familyPieces = $bakeDayRow['by_family'] ?? [];
                        $familyActive = $bakeDayRow['family_active'] ?? [];
                        $fmtFamily = static function (string $family) use ($familyPieces, $familyActive): string {
                            if (empty($familyActive[$family])) {
                                return '—';
                            }
                            return number_format((int)($familyPieces[$family] ?? 0));
                        };
                        $crewBits = [];
                        foreach ($bakeDayRow['crew'] ?? [] as $person) {
                            $bit = (string)$person['display_name'];
                            if (!empty($person['hours_label'])) {
                                $bit .= ' ' . $person['hours_label'];
                            } elseif (empty($person['punched'])) {
                                $bit .= ' · ' . bakery_t('production_manager.crew_not_in');
                            }
                            $crewBits[] = $bit;
                        }
                        ?>
                        <tr class="<?php echo !empty($bakeDayRow['is_selected']) ? 'is-selected' : ''; ?>">
                            <td><a href="<?php echo htmlspecialchars($pmdHref($bakeDayRow['date'], 'batches')); ?>"><?php echo htmlspecialchars((string)$bakeDayRow['label']); ?></a></td>
                            <td><?php echo htmlspecialchars($fmtFamily('daily')); ?></td>
                            <td><?php echo htmlspecialchars($fmtFamily('sour_flour')); ?></td>
                            <td><?php echo $crewBits === [] ? '—' : htmlspecialchars(implode(', ', $crewBits)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php $ws = $week['summary']; ?>
        <?php $weekUnavailable = (($week['load_state'] ?? 'ready') !== 'ready'); ?>
        <p class="pmd-view-lead"><?php bakery_te('production_manager.week_lead'); ?></p>
        <?php if ($weekUnavailable): ?>
            <p class="pmd-note" role="alert"><?php bakery_te('production_manager.week_unavailable'); ?></p>
            <p class="pmd-muted">
                <a href="<?php echo htmlspecialchars($links['daily_orders']); ?>"><?php bakery_te('production_manager.link_daily_orders'); ?></a>
            </p>
        <?php else: ?>
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
                <strong><?php echo $ws['vs_typical'] === null ? '-' : htmlspecialchars($fmtSigned((int)$ws['vs_typical'])); ?></strong>
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
        <?php endif; ?>

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
 <span class="pmd-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['actual_driver_name']): ?>
                                    <?php echo htmlspecialchars($row['actual_driver_name']); ?>
                                    <?php if ($row['actual_route_order'] !== null): ?>
                                        <span class="pmd-muted">#<?php echo (int)$row['actual_route_order']; ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
 <span class="pmd-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $row['delivery_status'] ? htmlspecialchars($row['delivery_status']) : '-'; ?></td>
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
                            <td><?php echo $supply['inventory_ready'] ? number_format((int)$row['produced']) : '-'; ?></td>
                            <td><?php echo $supply['inventory_ready'] ? number_format((int)$row['on_hand']) : '-'; ?></td>
                            <td><?php echo $supply['inventory_ready'] ? number_format((int)$row['loaded']) : '-'; ?></td>
                            <td>
                                <?php if ((int)$row['demand'] <= 0): ?>
 <span class="pmd-muted">-</span>
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
