<?php
/**
 * Integrated Materials / Ingredient Planner.
 *
 * Orders → Production Plan → Required Batches → Ingredient Requirements → Stock → Purchase hints
 */
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/product_inventory.php';
require_once 'includes/ingredient_requirements.php';
require_once 'includes/operational_exceptions.php';

$page_title = bakery_t('page.ingredient_requirements');

$days = [
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
    7 => 'Sunday',
];

$selectedDate = bakery_ingredient_requirements_resolve_date(
    isset($_GET['date']) ? (string)$_GET['date'] : null
);
$selectedSource = bakery_ingredient_requirements_resolve_source(
    isset($_GET['source']) ? (string)$_GET['source'] : null
);
$sources = bakery_ingredient_requirements_sources();
$exportCsv = isset($_GET['export']) && (string)$_GET['export'] === 'csv';
$attentionExceptions = (string)($_GET['attention'] ?? '') === 'exceptions';
$returnTarget = bakery_ops_return_resolve($_GET['return'] ?? null, $selectedDate);
$attentionLabel = $attentionExceptions ? 'Showing configuration exceptions' : '';

$plan = bakery_ingredient_requirements_build($db, $selectedDate, $selectedSource);

if ($exportCsv && $plan['error'] === null) {
    $filename = 'ingredient-planner-' . $selectedDate . '-' . $selectedSource . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, [
        'Ingredient',
        'Required (g)',
        'Required (kg)',
        'Demand (g)',
        'On Hand',
        'Shortage',
        'Suggested purchase',
        'Quantity source',
        'Planning date',
    ]);
    foreach ($plan['ingredients'] as $row) {
        fputcsv($out, [
            $row['ingredient_name'],
            number_format((float)$row['required_grams'], 3, '.', ''),
            number_format((float)$row['required_grams'] / 1000, 6, '.', ''),
            $row['demand_grams'] !== null ? number_format((float)$row['demand_grams'], 3, '.', '') : '',
            $row['on_hand_display'] ?? '',
            $row['shortage_display'] ?? '',
            $row['suggested_purchase'] ?? '',
            $plan['source_label'],
            $selectedDate,
        ]);
    }
    fclose($out);
    exit;
}

require_once 'includes/header.php';
require_once 'includes/nav.php';

$weekdayLabel = $days[(int)$plan['weekday']] ?? ('Day ' . $plan['weekday']);
$queryBase = [
    'date' => $selectedDate,
    'source' => $selectedSource,
];
$pcWeek = date('Y-m-d', strtotime('monday this week', strtotime($selectedDate)));
?>

<div class="ipl-page">
    <?php echo bakery_ops_render_return_banner($returnTarget, $attentionLabel); ?>
    <header class="ipl-header">
        <div>
            <p class="ipl-eyebrow">Production · materials planning</p>
            <h1>Ingredient Planner</h1>
            <p class="ipl-lead">
                Given what customers ordered and what you decided to produce, see required batches,
                aggregated ingredient needs, reported stock, and likely stocking gaps — traceable back to each product and formula.
            </p>
        </div>
        <div class="ipl-actions no-print">
            <a class="btn btn-outline" href="production_center.php?week=<?php echo urlencode($pcWeek); ?>#day-<?php echo urlencode($selectedDate); ?>">Production Center</a>
            <a class="btn btn-outline" href="production.php?date=<?php echo urlencode($selectedDate); ?>">Daily Production</a>
            <a class="btn btn-outline" href="ingredients.php">Ingredients</a>
            <?php if ($plan['error'] === null && !empty($plan['ingredients'])): ?>
                <a class="btn btn-primary" href="?<?php echo htmlspecialchars(http_build_query(array_merge($queryBase, ['export' => 'csv'])), ENT_QUOTES, 'UTF-8'); ?>">Export CSV</a>
            <?php endif; ?>
            <button type="button" class="btn btn-outline" onclick="window.print()">Print</button>
        </div>
    </header>

    <form class="ipl-controls no-print" method="get" action="ingredient_requirements.php">
        <label>
            <span>Production / delivery date</span>
            <input type="date" name="date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>" required>
        </label>
        <fieldset class="ipl-source-fieldset">
            <legend>Quantity source</legend>
            <?php foreach ($sources as $key => $meta): ?>
                <label class="ipl-source-option">
                    <input type="radio" name="source" value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"
                        <?php echo $selectedSource === $key ? 'checked' : ''; ?>>
                    <span>
                        <strong><?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        <small><?php echo htmlspecialchars($meta['description'], ENT_QUOTES, 'UTF-8'); ?></small>
                    </span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <button type="submit" class="btn btn-primary">Calculate</button>
    </form>

    <section class="ipl-context" aria-label="Planning context">
        <div>
            <span>Date</span>
            <strong><?php echo htmlspecialchars(date('l, M j, Y', strtotime($selectedDate)), ENT_QUOTES, 'UTF-8'); ?></strong>
            <small><?php echo htmlspecialchars($weekdayLabel, ENT_QUOTES, 'UTF-8'); ?></small>
        </div>
        <div>
            <span>Quantity source</span>
            <strong class="ipl-source-badge ipl-source-badge--<?php echo htmlspecialchars($selectedSource, ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($plan['source_label'], ENT_QUOTES, 'UTF-8'); ?>
            </strong>
            <small><?php echo htmlspecialchars($plan['source_detail'], ENT_QUOTES, 'UTF-8'); ?></small>
        </div>
        <div>
            <span>Demand mode</span>
            <strong><?php echo $plan['demand_mode'] === 'daily_orders' ? 'Committed daily orders' : 'Standing forecast'; ?></strong>
            <small>Never switched silently — see quantity source above.</small>
        </div>
        <?php if ($selectedSource === 'plan' && empty($plan['error'])): ?>
        <div>
            <span>Plan vs demand</span>
            <strong>
                <?php
                $delta = (int)$plan['comparison']['delta_units'];
                echo ($delta > 0 ? '+' : '') . number_format($delta) . ' units';
                ?>
            </strong>
            <small>
                Plan <?php echo number_format((int)$plan['comparison']['plan_units']); ?> ·
                Demand <?php echo number_format((int)$plan['comparison']['demand_units']); ?>
            </small>
        </div>
        <?php endif; ?>
        <div>
            <span>Ingredient stock</span>
            <strong><?php echo $plan['on_hand_trustworthy'] ? 'Comparable for some rows' : 'Limited comparison'; ?></strong>
            <small><?php echo htmlspecialchars($plan['on_hand_note'], ENT_QUOTES, 'UTF-8'); ?></small>
        </div>
    </section>

    <?php if ($plan['error'] !== null): ?>
        <div class="ipl-notice ipl-notice--error" role="alert">
            <?php echo htmlspecialchars($plan['error'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php else: ?>

        <?php foreach ($plan['notes'] as $note): ?>
            <div class="ipl-notice ipl-notice--info"><?php echo htmlspecialchars($note, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endforeach; ?>

        <section class="ipl-summary-metrics" aria-label="Totals">
            <div>
                <span>Products</span>
                <strong><?php echo number_format((int)$plan['totals']['products']); ?></strong>
            </div>
            <div>
                <span>Finished units</span>
                <strong><?php echo number_format((int)$plan['totals']['units']); ?></strong>
            </div>
            <div>
                <span>Total dough</span>
                <strong><?php echo htmlspecialchars(bakery_ingredient_requirements_format_grams((float)$plan['totals']['dough_grams']), ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div>
                <span>Ingredients</span>
                <strong><?php echo number_format((int)$plan['totals']['ingredients']); ?></strong>
            </div>
            <div class="<?php echo (int)$plan['totals']['exceptions'] > 0 ? 'needs-attention' : ''; ?>">
                <span>Config errors</span>
                <strong><?php echo number_format((int)$plan['totals']['exceptions']); ?></strong>
            </div>
        </section>

        <?php if (!empty($plan['production_rows'])): ?>
        <section class="ipl-section" id="production">
            <header class="ipl-section__header">
                <h2>Committed production &amp; batches</h2>
                <p>Per-product plan quantities exploded through formula yield (weight_grams). Fractional batches are kept; suggested whole batches are rounded up for planning reference only.</p>
            </header>
            <div class="ipl-table-wrap">
                <table class="ipl-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Planned</th>
                            <th>Demand</th>
                            <th>Δ plan−demand</th>
                            <th>Weight</th>
                            <th>Dough</th>
                            <th>Reference yield</th>
                            <th>Theoretical batches</th>
                            <th>Suggested whole</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($plan['production_rows'] as $row): ?>
                        <?php
                        $batches = $row['batches'] ?? [];
                        $fixHref = null;
                        if (!$row['explodable']) {
                            if (empty($row['dough_type_id'])) {
                                $fixHref = 'dough_types.php';
                            } elseif (empty($row['weight_grams'])) {
                                $fixHref = 'dough_types.php';
                            } else {
                                $fixHref = 'formulas.php?dough_type=' . (int)$row['dough_type_id'];
                            }
                        }
                        ?>
                        <tr class="<?php echo $row['explodable'] ? '' : 'ipl-row-error'; ?>">
                            <td>
                                <strong><?php echo htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <?php if (!empty($row['dough_type_name'])): ?>
                                    <small><?php echo htmlspecialchars($row['dough_type_name'], ENT_QUOTES, 'UTF-8'); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo number_format((int)$row['planned_quantity']); ?></strong></td>
                            <td><?php echo number_format((int)$row['demand_quantity']); ?></td>
                            <td class="<?php echo (int)$row['plan_vs_demand_delta'] !== 0 ? 'ipl-delta' : ''; ?>">
                                <?php
                                $d = (int)$row['plan_vs_demand_delta'];
                                echo ($d > 0 ? '+' : '') . number_format($d);
                                ?>
                            </td>
                            <td><?php echo $row['weight_grams'] ? number_format((int)$row['weight_grams']) . ' g' : '—'; ?></td>
                            <td><?php echo $row['dough_grams'] !== null ? htmlspecialchars(bakery_ingredient_requirements_format_grams((float)$row['dough_grams']), ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                            <td>
                                <?php if (!empty($batches['batch_reference_configured']) && !empty($batches['reference_yield_units'])): ?>
                                    <?php echo rtrim(rtrim(number_format((float)$batches['reference_yield_units'], 2), '0'), '.'); ?> units/batch
                                <?php else: ?>
                                    <span class="ipl-muted">Not configured</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($batches['theoretical_product_batches'])): ?>
                                    <?php echo rtrim(rtrim(number_format((float)$batches['theoretical_product_batches'], 3), '0'), '.'); ?>
                                <?php elseif ($row['dough_grams'] !== null): ?>
                                    <span class="ipl-muted" title="Continuous dough scale"><?php echo htmlspecialchars(bakery_ingredient_requirements_format_grams((float)$row['dough_grams']), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($batches['suggested_whole_product_batches'])): ?>
                                    <?php echo number_format((int)$batches['suggested_whole_product_batches']); ?>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['explodable']): ?>
                                    <span class="ipl-ok">OK</span>
                                <?php elseif ($fixHref): ?>
                                    <a href="<?php echo htmlspecialchars($fixHref, ENT_QUOTES, 'UTF-8'); ?>">Fix config →</a>
                                <?php else: ?>
                                    <span class="ipl-warn">Error</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <section class="ipl-section" id="summary">
            <header class="ipl-section__header">
                <h2>Ingredient requirements</h2>
                <p>Required totals from the selected quantity source. Shortage shown only when on-hand unit converts to grams.</p>
            </header>
            <?php if (empty($plan['ingredients'])): ?>
                <p class="ipl-empty">No ingredient requirements for this date and source. Check configuration exceptions or choose another quantity source.</p>
            <?php else: ?>
                <div class="ipl-table-wrap">
                    <table class="ipl-table">
                        <thead>
                            <tr>
                                <th>Ingredient</th>
                                <th>Required</th>
                                <?php if ($selectedSource !== 'demand'): ?><th>Demand</th><?php endif; ?>
                                <th>Reported on hand</th>
                                <th>Shortage / surplus</th>
                                <th>Suggested purchase</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($plan['ingredients'] as $row): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['ingredient_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <a class="ipl-drill no-print" href="#contrib-<?php echo (int)$row['ingredient_id']; ?>">Sources ↓</a>
                                    <?php if (!empty($row['stock_note']) && empty($row['stock_trustworthy'])): ?>
                                        <small class="ipl-unit-warn"><?php echo htmlspecialchars($row['stock_note'], ENT_QUOTES, 'UTF-8'); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo number_format((float)$row['required_grams'], 1); ?> g</strong>
                                    <small><?php echo number_format((float)$row['required_grams'] / 1000, 3); ?> kg</small>
                                </td>
                                <?php if ($selectedSource !== 'demand'): ?>
                                <td>
                                    <?php if ($row['demand_grams'] !== null): ?>
                                        <?php echo number_format((float)$row['demand_grams'], 1); ?> g
                                        <?php if ($row['plan_vs_demand_grams'] !== null && abs((float)$row['plan_vs_demand_grams']) >= 0.1): ?>
                                            <small class="ipl-delta"><?php echo ((float)$row['plan_vs_demand_grams'] > 0 ? '+' : '') . number_format((float)$row['plan_vs_demand_grams'], 1); ?> vs demand</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="ipl-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <?php if ($row['on_hand_display'] !== null): ?>
                                        <?php echo htmlspecialchars($row['on_hand_display'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php else: ?>
                                        <span class="ipl-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['stock_trustworthy'])): ?>
                                        <?php if ((float)$row['shortage_grams'] > 0): ?>
                                            <span class="ipl-short">−<?php echo htmlspecialchars($row['shortage_display'] ?? bakery_ingredient_requirements_format_grams((float)$row['shortage_grams']), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php elseif ((float)$row['surplus_grams'] > 0): ?>
                                            <span class="ipl-surplus">+<?php echo htmlspecialchars(bakery_ingredient_requirements_format_grams((float)$row['surplus_grams']), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php else: ?>
                                            <span class="ipl-ok">Covered</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="ipl-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['suggested_purchase'])): ?>
                                        <?php echo htmlspecialchars($row['suggested_purchase'], ENT_QUOTES, 'UTF-8'); ?>
                                        <?php if ($plan['purchasing_ready']): ?>
                                            <small><a href="ingredients.php">Update in Ingredients →</a></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="ipl-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <?php if (!empty($plan['purchase_suggestions'])): ?>
        <section class="ipl-section" id="purchase">
            <header class="ipl-section__header">
                <h2>Suggested purchase need</h2>
                <p>Recommendations only — nothing is ordered automatically. Update ingredient counts in Ingredients after purchasing.</p>
            </header>
            <div class="ipl-table-wrap">
                <table class="ipl-table">
                    <thead>
                        <tr>
                            <th>Ingredient</th>
                            <th>Required</th>
                            <th>On hand</th>
                            <th>Shortage</th>
                            <th>Suggested quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($plan['purchase_suggestions'] as $row): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($row['ingredient_name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                            <td><?php echo number_format((float)$row['required_grams'], 1); ?> g</td>
                            <td><?php echo htmlspecialchars($row['on_hand_display'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="ipl-short"><?php echo htmlspecialchars($row['shortage_display'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($row['suggested_purchase'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <section class="ipl-section" id="detail">
            <header class="ipl-section__header">
                <h2>Traceability</h2>
                <p>Drill down from ingredient totals to contributing products, and dough-type mix totals.</p>
            </header>

            <?php if (!empty($plan['dough_types'])): ?>
                <div class="ipl-dough-blocks">
                    <?php foreach ($plan['dough_types'] as $dough): ?>
                        <details class="ipl-dough">
                            <summary>
                                <strong><?php echo htmlspecialchars($dough['dough_type_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span>
                                    <?php echo number_format((int)$dough['units']); ?> units ·
                                    <?php echo htmlspecialchars(bakery_ingredient_requirements_format_grams((float)$dough['dough_grams']), ENT_QUOTES, 'UTF-8'); ?> dough
                                    <?php if (!empty($dough['theoretical_dough_batches'])): ?>
                                        · <?php echo rtrim(rtrim(number_format((float)$dough['theoretical_dough_batches'], 3), '0'), '.'); ?> batches
                                        (suggest <?php echo (int)$dough['suggested_whole_dough_batches']; ?> whole)
                                    <?php endif; ?>
                                </span>
                            </summary>
                            <ul class="ipl-product-list">
                                <?php foreach ($dough['products'] as $p): ?>
                                    <li>
                                        <span><?php echo htmlspecialchars($p['product_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span>
                                            <?php echo number_format((int)$p['quantity']); ?> planned ·
                                            <?php echo htmlspecialchars(bakery_ingredient_requirements_format_grams((float)$p['dough_grams']), ENT_QUOTES, 'UTF-8'); ?>
                                            <?php if (!empty($p['batches']['theoretical_product_batches'])): ?>
                                                · <?php echo rtrim(rtrim(number_format((float)$p['batches']['theoretical_product_batches'], 3), '0'), '.'); ?> batches
                                            <?php endif; ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <p class="ipl-dough-links no-print">
                                <a href="formulas.php?dough_type=<?php echo (int)$dough['dough_type_id']; ?>">Edit formula</a>
                                · <a href="dough_types.php">Set batch reference</a>
                            </p>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($plan['ingredients'])): ?>
                <?php foreach ($plan['ingredients'] as $ing): ?>
                    <details class="ipl-contrib" id="contrib-<?php echo (int)$ing['ingredient_id']; ?>">
                        <summary>
                            <strong><?php echo htmlspecialchars($ing['ingredient_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            <span><?php echo number_format((float)$ing['required_grams'], 1); ?> g required</span>
                        </summary>
                        <ul class="ipl-product-list">
                            <?php foreach ($ing['contributors'] as $c): ?>
                                <li>
                                    <span><?php echo htmlspecialchars($c['product_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span>
                                        <?php echo number_format((int)$c['finished_units']); ?> units →
                                        <?php echo number_format((float)$c['required_grams'], 1); ?> g
                                        @ <?php echo rtrim(rtrim(number_format((float)$c['formula_percentage'], 2), '0'), '.'); ?>%
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <section class="ipl-section" id="exceptions">
            <header class="ipl-section__header">
                <h2>Configuration exceptions</h2>
                <p>Products and dough types that could not be fully exploded. Nothing with planned production disappears silently.</p>
            </header>
            <?php
            $errors = array_filter($plan['exceptions'], static fn($ex) => ($ex['severity'] ?? 'error') === 'error');
            $infos = array_filter($plan['exceptions'], static fn($ex) => ($ex['severity'] ?? '') !== 'error');
            ?>
            <?php if (empty($plan['exceptions'])): ?>
                <p class="ipl-empty ipl-empty--ok">No configuration gaps for products included in this calculation.</p>
            <?php else: ?>
                <?php if ($errors): ?>
                    <h3 class="ipl-subhead">Errors</h3>
                    <ul class="ipl-exceptions ipl-exceptions--error">
                        <?php foreach ($errors as $ex): ?>
                            <li>
                                <code><?php echo htmlspecialchars($ex['code'], ENT_QUOTES, 'UTF-8'); ?></code>
                                <span><?php echo htmlspecialchars($ex['message'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($infos): ?>
                    <h3 class="ipl-subhead">Informational</h3>
                    <ul class="ipl-exceptions ipl-exceptions--info">
                        <?php foreach ($infos as $ex): ?>
                            <li>
                                <code><?php echo htmlspecialchars($ex['code'], ENT_QUOTES, 'UTF-8'); ?></code>
                                <span><?php echo htmlspecialchars($ex['message'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="ipl-section ipl-method" id="method">
            <header class="ipl-section__header">
                <h2>How this is calculated</h2>
            </header>
            <ul>
                <li><?php echo htmlspecialchars($plan['yield_note'], ENT_QUOTES, 'UTF-8'); ?></li>
                <li><?php echo htmlspecialchars($plan['batch_note'], ENT_QUOTES, 'UTF-8'); ?></li>
                <li><?php echo htmlspecialchars($plan['unit_note'], ENT_QUOTES, 'UTF-8'); ?></li>
                <li><?php echo htmlspecialchars($plan['stock_formula_note'], ENT_QUOTES, 'UTF-8'); ?></li>
                <li>Read-only: does not deduct stock, create POs, or alter production plans.</li>
            </ul>
        </section>
    <?php endif; ?>
</div>

<style>
.ipl-page { max-width: 1180px; margin: 0 auto 40px; padding: 8px 12px 32px; color: #1c2b24; }
.ipl-header { display: flex; flex-wrap: wrap; gap: 16px; justify-content: space-between; align-items: flex-start; margin-bottom: 18px; }
.ipl-eyebrow { margin: 0 0 4px; text-transform: uppercase; letter-spacing: .06em; font-size: .75rem; color: #5a7264; font-weight: 700; }
.ipl-header h1 { margin: 0 0 8px; font-size: 1.75rem; color: #173f3c; }
.ipl-lead { margin: 0; max-width: 68ch; color: #4b6351; line-height: 1.45; }
.ipl-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.ipl-controls { display: grid; gap: 14px; padding: 16px; margin-bottom: 16px; background: #f4faf6; border: 1px solid #d5e5db; border-radius: 12px; }
.ipl-controls label > span { display: block; font-weight: 700; margin-bottom: 6px; font-size: .9rem; }
.ipl-controls input[type="date"] { min-height: 42px; padding: 8px 10px; border: 1px solid #8db59a; border-radius: 8px; font-size: 1rem; }
.ipl-source-fieldset { border: 1px solid #cfe0d5; border-radius: 10px; padding: 10px 12px; margin: 0; }
.ipl-source-fieldset legend { padding: 0 6px; font-weight: 700; }
.ipl-source-option { display: grid; grid-template-columns: auto 1fr; gap: 10px; align-items: start; padding: 8px 4px; cursor: pointer; }
.ipl-source-option small { display: block; color: #5a7264; margin-top: 2px; line-height: 1.35; }
.ipl-context { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 16px; }
.ipl-context > div { background: #fff; border: 1px solid #dbe7df; border-radius: 10px; padding: 12px 14px; }
.ipl-context span { display: block; font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; color: #607068; font-weight: 700; }
.ipl-context strong { display: block; margin-top: 4px; font-size: 1.05rem; color: #173f3c; }
.ipl-context small { display: block; margin-top: 4px; color: #5a7264; line-height: 1.35; }
.ipl-source-badge--plan { color: #1f4f7a; }
.ipl-source-badge--demand { color: #1f5f32; }
.ipl-source-badge--to_produce { color: #8a4d00; }
.ipl-summary-metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; margin-bottom: 20px; }
.ipl-summary-metrics > div { background: #fff; border: 1px solid #dbe7df; border-radius: 10px; padding: 12px; }
.ipl-summary-metrics span { display: block; font-size: .78rem; color: #607068; text-transform: uppercase; letter-spacing: .03em; }
.ipl-summary-metrics strong { display: block; margin-top: 4px; font-size: 1.35rem; color: #173f3c; }
.ipl-summary-metrics .needs-attention { border-color: #efc98d; background: #fffaf2; }
.ipl-section { margin-bottom: 28px; }
.ipl-section__header h2 { margin: 0 0 6px; font-size: 1.25rem; color: #173f3c; }
.ipl-section__header p { margin: 0 0 12px; color: #4b6351; }
.ipl-subhead { font-size: 1rem; margin: 12px 0 8px; color: #173f3c; }
.ipl-table-wrap { overflow-x: auto; border: 1px solid #dbe7df; border-radius: 10px; background: #fff; }
.ipl-table { width: 100%; border-collapse: collapse; font-size: .95rem; }
.ipl-table th, .ipl-table td { padding: 10px 12px; border-bottom: 1px solid #e4eee8; text-align: left; vertical-align: top; }
.ipl-table th { background: #f4faf6; font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; color: #42545a; }
.ipl-table td small { display: block; color: #607068; margin-top: 2px; }
.ipl-table .ipl-muted, .ipl-muted { color: #8a9a90; }
.ipl-row-error { background: #fff8f8; }
.ipl-delta { color: #8a4d00; font-weight: 600; }
.ipl-short { color: #b72c2c; font-weight: 700; }
.ipl-surplus { color: #1f6b35; font-weight: 700; }
.ipl-ok { color: #1f6b35; font-weight: 700; }
.ipl-warn { color: #8a4d00; font-weight: 700; }
.ipl-unit-warn { display: block; color: #8a4d00; font-size: .8rem; margin-top: 4px; }
.ipl-drill { font-size: .78rem; margin-left: 8px; }
.ipl-dough-blocks, .ipl-contrib { margin-bottom: 10px; }
.ipl-dough, .ipl-contrib { border: 1px solid #dbe7df; border-radius: 10px; background: #fff; padding: 0 12px; }
.ipl-dough summary, .ipl-contrib summary { cursor: pointer; padding: 12px 4px; display: flex; flex-wrap: wrap; gap: 8px 16px; justify-content: space-between; }
.ipl-dough summary span, .ipl-contrib summary span { color: #5a7264; font-size: .9rem; }
.ipl-product-list { list-style: none; margin: 0 0 12px; padding: 0; display: grid; gap: 6px; }
.ipl-product-list li { display: flex; justify-content: space-between; gap: 12px; padding: 8px 10px; background: #f8fbf9; border-radius: 8px; }
.ipl-dough-links { margin: 0 0 12px; font-size: .88rem; }
.ipl-exceptions { list-style: none; margin: 0 0 12px; padding: 0; display: grid; gap: 8px; }
.ipl-exceptions li { display: grid; grid-template-columns: minmax(120px, auto) 1fr; gap: 10px; padding: 10px 12px; border-radius: 8px; }
.ipl-exceptions--error li { background: #fff5f5; border: 1px solid #efc2c2; }
.ipl-exceptions--info li { background: #fffaf2; border: 1px solid #efc98d; }
.ipl-exceptions code { font-size: .78rem; font-weight: 700; }
.ipl-exceptions--error code { color: #9f2727; }
.ipl-exceptions--info code { color: #8a4d00; }
.ipl-notice { padding: 12px 14px; border-radius: 10px; margin-bottom: 12px; line-height: 1.4; }
.ipl-notice--error { background: #fdecec; border: 1px solid #e7a1a1; color: #7a1f1f; }
.ipl-notice--info { background: #eef5fb; border: 1px solid #b7cde3; color: #1f4f7a; }
.ipl-empty { padding: 16px; background: #f8faf9; border: 1px dashed #c8d8d0; border-radius: 10px; color: #4b6351; }
.ipl-empty--ok { background: #f3fbf5; border-color: #b8d9c2; color: #1f5f32; }
.ipl-method ul { margin: 0; padding-left: 1.2rem; color: #4b6351; line-height: 1.5; }
.ipl-method li { margin-bottom: 8px; }
@media print {
    .no-print, .navbar, nav, .main-nav, header.site-header { display: none !important; }
    .ipl-page { max-width: none; padding: 0; }
}
@media (max-width: 700px) {
    .ipl-exceptions li, .ipl-product-list li { grid-template-columns: 1fr; flex-direction: column; }
}
</style>

<?php if ($attentionExceptions): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('exceptions');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
});
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
