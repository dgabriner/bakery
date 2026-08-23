<?php
/**
 * Product Manager Plan Center — standards, standing, cover demand, and FG stock.
 * Read-mostly. Bake commit stays on Production Center.
 */
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/product_manager_plan.php';
require_once 'includes/production_workflow_strip.php';

$deliveryDate = bakery_product_manager_plan_resolve_date((string)($_GET['date'] ?? ''));
$familyFilter = bakery_product_manager_plan_normalize_family((string)($_GET['family'] ?? 'daily'));
$attentionOnly = (string)($_GET['attention'] ?? '') === '1';

$board = bakery_product_manager_plan_board($db, $deliveryDate, $familyFilter);
$rows = $board['rows'];
if ($attentionOnly) {
    $rows = array_values(array_filter($rows, static fn($r) => !empty($r['attention'])));
}

$prevDate = date('Y-m-d', strtotime($deliveryDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($deliveryDate . ' +1 day'));

$pmpHref = static function (string $date, string $family, bool $attention, bool $all = false): string {
    $q = ['date' => $date, 'family' => $family];
    if ($attention) {
        $q['attention'] = '1';
    }
    if ($all) {
        $q['show_all'] = '1';
    }
    return 'product_manager_plan.php?' . http_build_query($q);
};

$dayLabel = static function (string $date) {
    $dt = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dt) {
        return $date;
    }
    $names = function_exists('bakery_day_names') ? bakery_day_names(true) : [];
    $dow = (int)$dt->format('N');
    $day = $names[$dow] ?? $dt->format('D');
    $md = function_exists('bakery_localized_month_day') ? bakery_localized_month_day($dt) : $dt->format('M j');
    return trim($day . ' ' . $md);
};

$hubStages = [];
try {
    $hubStages = bakery_production_workflow_kitchen_stages($db, $deliveryDate);
} catch (Throwable $e) {
    error_log('product_manager_plan workflow strip: ' . $e->getMessage());
}

$page_title = bakery_t('page.product_manager_plan');
require_once 'includes/header.php';
require_once 'includes/nav.php';
$summary = $board['summary'];
?>
<main class="pmp-center container">
    <div class="pmp-heading">
        <div>
            <p class="pmp-eyebrow"><?= htmlspecialchars(bakery_t('product_manager_plan.eyebrow')) ?></p>
            <h1><?= htmlspecialchars(bakery_t('product_manager_plan.title')) ?></h1>
            <p><?= bakery_t('product_manager_plan.lead') ?></p>
        </div>
        <div class="pmp-heading-actions">
            <a class="btn btn-primary" href="production_center.php?date=<?= urlencode($deliveryDate) ?>"><?= htmlspecialchars(bakery_t('product_manager_plan.link_production_center')) ?></a>
            <a class="btn btn-outline" href="pan_dulce_quantities.php"><?= htmlspecialchars(bakery_t('product_manager_plan.link_standards')) ?></a>
            <a class="btn btn-outline" href="daily_orders.php?date=<?= urlencode($deliveryDate) ?>"><?= htmlspecialchars(bakery_t('product_manager_plan.link_orders')) ?></a>
            <?php if ($board['inventory_ready']): ?>
                <a class="btn btn-outline" href="inventory.php?date=<?= urlencode($deliveryDate) ?>"><?= htmlspecialchars(bakery_t('product_manager_plan.link_inventory')) ?></a>
            <?php endif; ?>
            <a class="btn btn-outline" href="standing_orders_manager.php"><?= htmlspecialchars(bakery_t('product_manager_plan.link_standing')) ?></a>
        </div>
    </div>

    <?php
    echo bakery_production_workflow_strip_css();
    echo bakery_production_workflow_strip_html($hubStages, [
        'current' => 'production_plan',
        'title' => bakery_t('production_workflow.title'),
        'lead' => bakery_t('product_manager_plan.workflow_lead'),
    ]);
    ?>

    <?php if (!$board['inventory_ready']): ?>
        <div class="pmp-notice warning"><?= htmlspecialchars(bakery_t('product_manager_plan.warn_no_inventory')) ?></div>
    <?php endif; ?>

    <form method="get" class="pmp-filters" action="product_manager_plan.php">
        <?php if ($attentionOnly): ?><input type="hidden" name="attention" value="1"><?php endif; ?>
        <a class="btn btn-outline" href="<?= htmlspecialchars($pmpHref($prevDate, $familyFilter, $attentionOnly)) ?>"><?= htmlspecialchars(bakery_t('product_manager_plan.prev_day')) ?></a>
        <label><?= htmlspecialchars(bakery_t('product_manager_plan.delivery_date')) ?>
            <input type="date" name="date" value="<?= htmlspecialchars($deliveryDate) ?>" onchange="this.form.submit()">
        </label>
        <a class="btn btn-outline" href="<?= htmlspecialchars($pmpHref($nextDate, $familyFilter, $attentionOnly)) ?>"><?= htmlspecialchars(bakery_t('product_manager_plan.next_day')) ?></a>
        <label><?= htmlspecialchars(bakery_t('product_manager_plan.family')) ?>
            <select name="family" onchange="this.form.submit()">
                <option value="daily"<?= $familyFilter === BAKERY_PRODUCTION_CADENCE_DAILY ? ' selected' : '' ?>><?= htmlspecialchars(bakery_t('product_manager_plan.family_daily')) ?></option>
                <option value="sour_flour"<?= $familyFilter === BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR ? ' selected' : '' ?>><?= htmlspecialchars(bakery_t('product_manager_plan.family_sf')) ?></option>
                <option value="all"<?= $familyFilter === 'all' ? ' selected' : '' ?>><?= htmlspecialchars(bakery_t('product_manager_plan.family_all')) ?></option>
            </select>
        </label>
        <a class="pmp-text-link" href="<?= htmlspecialchars($pmpHref($deliveryDate, $familyFilter, !$attentionOnly)) ?>">
            <?= htmlspecialchars($attentionOnly ? bakery_t('product_manager_plan.show_all_rows') : bakery_t('product_manager_plan.attention_only')) ?>
        </a>
    </form>

    <section class="pmp-context" aria-label="<?= htmlspecialchars(bakery_t('product_manager_plan.context_aria')) ?>">
        <div class="pmp-context-card">
            <span class="pmp-context-label"><?= htmlspecialchars(bakery_t('product_manager_plan.bake_day')) ?></span>
            <strong><?= htmlspecialchars($dayLabel($board['bake_date'])) ?></strong>
            <span class="pmp-muted"><?= htmlspecialchars($board['bake_date']) ?></span>
        </div>
        <div class="pmp-context-card pmp-context-card--wide">
            <span class="pmp-context-label"><?= htmlspecialchars(bakery_t('product_manager_plan.cover_window')) ?></span>
            <div class="pmp-cover-chips">
                <?php foreach ($board['cover_dates'] as $cd): ?>
                    <a class="pmp-chip<?= $cd === $deliveryDate ? ' is-current' : '' ?>"
                       href="<?= htmlspecialchars($pmpHref($cd, $familyFilter, $attentionOnly)) ?>">
                        <?= htmlspecialchars($dayLabel($cd)) ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <p class="pmp-muted"><?= htmlspecialchars(bakery_t('product_manager_plan.cover_hint')) ?></p>
        </div>
    </section>

    <section class="pmp-summary" aria-label="<?= htmlspecialchars(bakery_t('product_manager_plan.summary_aria')) ?>">
        <div class="pmp-metric"><span><?= htmlspecialchars(bakery_t('product_manager_plan.metric_focus_demand')) ?></span><strong><?= number_format((int)$summary['focus_demand']) ?></strong></div>
        <div class="pmp-metric"><span><?= htmlspecialchars(bakery_t('product_manager_plan.metric_cover_demand')) ?></span><strong><?= number_format((int)$summary['cover_demand']) ?></strong></div>
        <div class="pmp-metric"><span><?= htmlspecialchars(bakery_t('product_manager_plan.metric_standing')) ?></span><strong><?= number_format((int)$summary['standing']) ?></strong></div>
        <div class="pmp-metric"><span><?= htmlspecialchars(bakery_t('product_manager_plan.metric_on_hand')) ?></span><strong><?= number_format((int)$summary['on_hand']) ?></strong></div>
        <div class="pmp-metric"><span><?= htmlspecialchars(bakery_t('product_manager_plan.metric_make')) ?></span><strong><?= number_format((int)$summary['make_need']) ?></strong></div>
        <div class="pmp-metric<?= (int)$summary['shortfall'] > 0 ? ' is-danger' : '' ?>"><span><?= htmlspecialchars(bakery_t('product_manager_plan.metric_shortfall')) ?></span><strong><?= number_format((int)$summary['shortfall']) ?></strong></div>
        <div class="pmp-metric"><span><?= htmlspecialchars(bakery_t('product_manager_plan.metric_attention')) ?></span><strong><?= number_format((int)$summary['attention']) ?></strong></div>
    </section>

    <div class="pmp-table-wrap">
        <table class="items-table pmp-table">
            <thead>
                <tr>
                    <th><?= htmlspecialchars(bakery_t('product_manager_plan.col_product')) ?></th>
                    <th><?= htmlspecialchars(bakery_t('product_manager_plan.col_standard')) ?></th>
                    <th><?= htmlspecialchars(bakery_t('product_manager_plan.col_standing')) ?></th>
                    <th><?= htmlspecialchars(bakery_t('product_manager_plan.col_focus_demand')) ?></th>
                    <th><?= htmlspecialchars(bakery_t('product_manager_plan.col_cover_demand')) ?></th>
                    <?php foreach ($board['cover_dates'] as $cd): ?>
                        <th class="pmp-day-col"><?= htmlspecialchars($dayLabel($cd)) ?></th>
                    <?php endforeach; ?>
                    <th><?= htmlspecialchars(bakery_t('product_manager_plan.col_on_hand')) ?></th>
                    <th><?= htmlspecialchars(bakery_t('product_manager_plan.col_planned')) ?></th>
                    <th><?= htmlspecialchars(bakery_t('product_manager_plan.col_make')) ?></th>
                    <th><?= htmlspecialchars(bakery_t('product_manager_plan.col_hint')) ?></th>
                    <th><?= htmlspecialchars(bakery_t('product_manager_plan.col_status')) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php
            $lastDough = null;
            foreach ($rows as $row):
                if ($lastDough !== $row['dough_type_name']):
                    $lastDough = $row['dough_type_name'];
                    $colspan = 11 + count($board['cover_dates']);
            ?>
                <tr class="pmp-dough-row"><th colspan="<?= (int)$colspan ?>"><?= htmlspecialchars($lastDough !== '' ? $lastDough : bakery_t('product_manager_plan.no_dough')) ?></th></tr>
            <?php endif; ?>
                <tr class="<?= !empty($row['attention']) ? 'pmp-row--attention' : '' ?>">
                    <td>
                        <strong><?= htmlspecialchars($row['name']) ?></strong>
                        <?php if ($row['product_line_name'] !== ''): ?>
                            <div class="pmp-muted"><?= htmlspecialchars($row['product_line_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= $row['standard_quantity'] > 0 ? number_format($row['standard_quantity']) : '—' ?></td>
                    <td><?= $row['standing_quantity'] > 0 ? number_format($row['standing_quantity']) : '—' ?></td>
                    <td><strong><?= number_format($row['focus_demand']) ?></strong></td>
                    <td><?= number_format($row['cover_demand']) ?></td>
                    <?php foreach ($board['cover_dates'] as $cd): ?>
                        <td class="pmp-day-col"><?= number_format((int)($row['demand_by_date'][$cd] ?? 0)) ?></td>
                    <?php endforeach; ?>
                    <td><?= $board['inventory_ready'] ? number_format($row['on_hand']) : '—' ?></td>
                    <td><?= $row['has_plan'] ? number_format($row['planned']) : '—' ?></td>
                    <td><strong><?= number_format($row['make_need']) ?></strong></td>
                    <td class="pmp-muted">
                        <?php if ($row['gallons_hint'] !== null): ?>
                            ≈ <?= htmlspecialchars((string)$row['gallons_hint']) ?> gal
                        <?php elseif ($row['trays_hint'] !== null): ?>
                            ≈ <?= htmlspecialchars((string)$row['trays_hint']) ?> trays
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php foreach ($row['statuses'] as $st): ?>
                            <span class="pmp-status pmp-status--<?= htmlspecialchars($st['tone']) ?>"><?= htmlspecialchars($st['label']) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <a class="btn btn-outline btn-sm" href="production_center.php?date=<?= urlencode($deliveryDate) ?>"><?= htmlspecialchars(bakery_t('product_manager_plan.open_day_plan')) ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="<?= 11 + count($board['cover_dates']) ?>"><?= htmlspecialchars(bakery_t('product_manager_plan.empty')) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<style>
.pmp-center{max-width:1280px;margin:0 auto;padding:20px 16px 48px}
.pmp-heading{display:flex;flex-wrap:wrap;gap:16px;justify-content:space-between;align-items:flex-start;margin-bottom:1rem}
.pmp-eyebrow{margin:0;text-transform:uppercase;letter-spacing:.04em;font-size:.75rem;color:#6d7771}
.pmp-heading h1{margin:.15rem 0 .35rem;font-size:1.65rem}
.pmp-heading p{margin:0;max-width:42rem;color:#3d4742}
.pmp-heading-actions{display:flex;flex-wrap:wrap;gap:8px}
.pmp-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:end;margin:1rem 0}
.pmp-filters label{display:flex;flex-direction:column;gap:4px;font-size:.85rem}
.pmp-text-link{align-self:center;font-size:.9rem}
.pmp-context{display:grid;grid-template-columns:minmax(140px,220px) 1fr;gap:12px;margin:1rem 0 1.25rem}
.pmp-context-card{background:#f7f3ee;border:1px solid #e5ddd3;border-radius:10px;padding:12px 14px}
.pmp-context-label{display:block;font-size:.75rem;text-transform:uppercase;letter-spacing:.03em;color:#6d7771;margin-bottom:4px}
.pmp-cover-chips{display:flex;flex-wrap:wrap;gap:6px;margin:.35rem 0}
.pmp-chip{display:inline-block;padding:4px 10px;border-radius:999px;background:#fff;border:1px solid #d7cfc4;text-decoration:none;color:#2c342f;font-size:.85rem}
.pmp-chip.is-current{background:#5d3b2d;color:#fff;border-color:#5d3b2d}
.pmp-muted{color:#6d7771;font-size:.85rem}
.pmp-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:1.25rem}
.pmp-metric{background:#fff;border:1px solid #e5ddd3;border-radius:10px;padding:10px 12px}
.pmp-metric span{display:block;font-size:.75rem;color:#6d7771}
.pmp-metric strong{font-size:1.25rem}
.pmp-metric.is-danger{border-color:#c45c4a;background:#fff5f3}
.pmp-table-wrap{overflow-x:auto}
.pmp-dough-row th{background:#f4ebe5;color:#5d3b2d;text-align:left;padding:8px 10px}
.pmp-row--attention{background:#fffaf3}
.pmp-status{display:inline-block;margin:1px 2px;padding:2px 7px;border-radius:999px;font-size:.75rem;background:#eee}
.pmp-status--ok{background:#e5f3ea;color:#1f5a34}
.pmp-status--warn{background:#fff0d6;color:#7a4d00}
.pmp-status--danger{background:#fde4e0;color:#8a2a1b}
.pmp-status--muted{background:#eceff1;color:#546e7a}
.pmp-notice{padding:10px 12px;border-radius:8px;margin:0 0 1rem}
.pmp-notice.warning{background:#fff6e5;border:1px solid #e6c98a}
.btn-sm{padding:4px 8px;font-size:.8rem}
@media(max-width:800px){.pmp-context{grid-template-columns:1fr}.pmp-day-col{display:none}}
</style>
<?php require_once 'includes/footer.php'; ?>
