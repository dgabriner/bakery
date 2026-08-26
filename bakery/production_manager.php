<?php
/**
 * Production Manager Dashboard — expandable dough / batch / piece board.
 * Edit & commit stay on Production Center; bakers stay on Daily Production.
 */
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/production_manager_dashboard.php';
require_once 'includes/production_workflow_strip.php';

$selectedDate = bakery_pmd_resolve_date((string)($_GET['date'] ?? ''));
$expandAll = (string)($_GET['expand'] ?? '') === '1';
$board = bakery_pmd_build($db, $selectedDate);
$summary = $board['summary'];

$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

$pmdHref = static function (string $date, bool $expand = false): string {
    $q = ['date' => $date];
    if ($expand) {
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
            <a class="btn btn-primary" href="<?php echo htmlspecialchars($board['links']['production_center']); ?>">
                <?php bakery_te('production_manager.link_center'); ?>
            </a>
            <a class="btn btn-outline" href="<?php echo htmlspecialchars($board['links']['production']); ?>">
                <?php bakery_te('production_manager.link_baker'); ?>
            </a>
            <a class="btn btn-outline" href="<?php echo htmlspecialchars($board['links']['ingredient_requirements']); ?>">
                <?php bakery_te('production_manager.link_ingredients'); ?>
            </a>
            <a class="btn btn-outline" href="<?php echo htmlspecialchars($board['links']['pack_list']); ?>">
                <?php bakery_te('production_manager.link_pack'); ?>
            </a>
            <a class="btn btn-outline" href="<?php echo htmlspecialchars($board['links']['product_manager_plan']); ?>">
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
        <?php if ($expandAll): ?><input type="hidden" name="expand" value="1"><?php endif; ?>
        <a class="btn btn-outline" href="<?php echo htmlspecialchars($pmdHref($prevDate, $expandAll)); ?>">
            <?php bakery_te('production_manager.prev_day'); ?>
        </a>
        <label class="pmd-date-label"><?php bakery_te('production_manager.delivery_date'); ?>
            <input type="date" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>" onchange="this.form.submit()">
        </label>
        <a class="btn btn-outline" href="<?php echo htmlspecialchars($pmdHref($nextDate, $expandAll)); ?>">
            <?php bakery_te('production_manager.next_day'); ?>
        </a>
        <span class="pmd-date-display"><?php echo htmlspecialchars($board['date_display']); ?></span>
        <a class="pmd-text-link" href="<?php echo htmlspecialchars($pmdHref($selectedDate, !$expandAll)); ?>">
            <?php bakery_te($expandAll ? 'production_manager.collapse_all' : 'production_manager.expand_all'); ?>
        </a>
    </form>

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
            <strong><?php
                $delta = (int)$summary['delta_vs_prior'];
                echo ($delta > 0 ? '+' : '') . number_format($delta);
            ?></strong>
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
                                <strong><?php
                                    $dDelta = (int)$dough['delta_vs_prior'];
                                    echo ($dDelta > 0 ? '+' : '') . number_format($dDelta);
                                ?></strong>
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
                                        <td><?php
                                            $pDelta = (int)$product['delta_vs_prior'];
                                            echo ($pDelta > 0 ? '+' : '') . number_format($pDelta);
                                        ?></td>
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
</main>
<script>
(function () {
  var root = document.getElementById('productionManagerDashboard');
  if (!root) return;
  // Keyboard-friendly: remember expand preference in session for this date.
  root.querySelectorAll('details.pmd-dough').forEach(function (el) {
    el.addEventListener('toggle', function () {
      // no-op hook for future persistence
    });
  });
})();
</script>
<?php require_once 'includes/footer.php'; ?>
