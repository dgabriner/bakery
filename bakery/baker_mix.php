<?php
/**
 * Baker Mix Today — simple list of starter feedings and dough batches to mix.
 * Filtered by baker product lines (Sour Flour vs Pan Dulce). Does not replace Daily Production.
 */
define('ACCESS_ALLOWED', true);

require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/daily_order_generation.php';
require_once 'includes/baker_mix.php';

bakery_require_role(['baker', 'manager', 'administrator']);

require_once 'includes/header.php';
require_once 'includes/nav.php';

$isBaker = function_exists('bakery_user_has_role') && bakery_user_has_role(['baker']);
$days = bakery_day_names();

$defaultDate = date('Y-m-d', strtotime('+1 day'));
$selectedDate = isset($_GET['date']) ? trim((string)$_GET['date']) : $defaultDate;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate) || strtotime($selectedDate) === false) {
    $selectedDate = $defaultDate;
}
$selectedDay = bakery_standing_day_from_date($selectedDate);

try {
    bakery_fill_demand_horizon($db, $selectedDate, ['record_event' => false]);
} catch (Throwable $e) {
    error_log('baker_mix demand horizon: ' . $e->getMessage());
}

$bakerProductIds = function_exists('bakery_baker_product_ids') ? bakery_baker_product_ids($db) : null;
$sheet = [
    'date' => $selectedDate,
    'bake_list' => ['committed' => false, 'items' => []],
    'batches' => [],
    'starter_feedings' => [],
    'batch_count' => 0,
    'unit_count' => 0,
];
$error = '';
try {
    $sheet = bakery_baker_mix_sheet($db, $selectedDate, $isBaker ? $bakerProductIds : null);
} catch (Throwable $e) {
    $error = $isBaker
        ? bakery_t('baker_mix.error_load')
        : bakery_t('baker_mix.error_load_ops', ['message' => $e->getMessage()]);
}

$batches = $sheet['batches'];
$starterFeedings = $sheet['starter_feedings'];
$productionHref = 'production.php?date=' . rawurlencode($selectedDate);
$packHref = 'pack_list.php?date=' . rawurlencode($selectedDate);
$hasFormula = false;
foreach ($batches as $batch) {
    if (!empty($batch['ingredients']) && (float)($batch['formula']['total_percentage'] ?? 0) > 0) {
        $hasFormula = true;
        break;
    }
}

$page_title = bakery_t('page.baker_mix');
?>

<div class="bm-screen">
    <header class="bm-header">
        <div class="bm-header__top">
            <h1 class="bm-title"><?php bakery_te('baker_mix.title'); ?></h1>
            <div class="bm-header__links">
                <a class="bm-link" href="<?php echo htmlspecialchars($productionHref, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('baker_mix.open_production'); ?></a>
                <a class="bm-link" href="<?php echo htmlspecialchars($packHref, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('nav.pack_list'); ?></a>
            </div>
        </div>
        <p class="bm-lead"><?php bakery_te('baker_mix.lead'); ?></p>
        <form method="get" action="baker_mix.php" class="bm-date-form">
            <label class="bm-date-label" for="date"><?php bakery_te('baker_mix.bake_for_delivery'); ?></label>
            <input type="date" name="date" id="date" class="bm-date-input"
                   value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>"
                   onchange="this.form.submit()">
            <p class="bm-date-context">
                <strong><?php echo htmlspecialchars($days[$selectedDay], ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(date('M j, Y', strtotime($selectedDate)), ENT_QUOTES, 'UTF-8'); ?></strong>
                <?php if ($sheet['batch_count'] > 0): ?>
                    <span class="bm-chip"><?php echo htmlspecialchars(bakery_t('baker_mix.summary', [
                        'batches' => (int)$sheet['batch_count'],
                        'units' => number_format((int)$sheet['unit_count']),
                    ]), ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </p>
        </form>
    </header>

    <?php if ($error !== ''): ?>
        <div class="bm-alert bm-alert--error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php elseif (empty($batches)): ?>
        <div class="bm-empty">
            <p><?php bakery_te('baker_mix.empty'); ?></p>
            <a class="bm-btn" href="<?php echo htmlspecialchars($productionHref, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('baker_mix.open_production'); ?></a>
        </div>
    <?php else: ?>
        <?php if ($hasFormula): ?>
            <?php
            $formulaDefaultUnit = bakery_formula_default_unit_mode(true);
            $formulaUnitModes = bakery_formula_unit_modes(true);
            ?>
            <div class="formula-unit-bar formula-unit-bar--baker"
                 data-formula-units
                 data-unit-mode="<?php echo htmlspecialchars($formulaDefaultUnit, ENT_QUOTES, 'UTF-8'); ?>"
                 data-baker-units="1">
                <div class="formula-unit-bar-row">
                    <span class="formula-unit-label sf-sr-only" id="bm-formula-unit-label"><?php echo htmlspecialchars(bakery_t('formula.show_mix_as'), ENT_QUOTES, 'UTF-8'); ?></span>
                    <div class="formula-unit-switch" role="radiogroup" aria-labelledby="bm-formula-unit-label">
                        <?php foreach ($formulaUnitModes as $unitMode): ?>
                            <button type="button"
                                    role="radio"
                                    class="formula-unit-btn<?php echo $unitMode === $formulaDefaultUnit ? ' is-active' : ''; ?>"
                                    data-unit="<?php echo htmlspecialchars($unitMode, ENT_QUOTES, 'UTF-8'); ?>"
                                    aria-checked="<?php echo $unitMode === $formulaDefaultUnit ? 'true' : 'false'; ?>"
                                    aria-label="<?php echo htmlspecialchars(bakery_t('formula.units.' . $unitMode . '_aria'), ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars(bakery_t('formula.units.' . $unitMode), ENT_QUOTES, 'UTF-8'); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($starterFeedings)): ?>
            <section class="bm-starter" aria-labelledby="bm-starter-title">
                <h2 id="bm-starter-title" class="bm-section-title"><?php bakery_te('baker_mix.starter_title'); ?></h2>
                <p class="bm-section-lead"><?php bakery_te('baker_mix.starter_lead'); ?></p>
                <div class="bm-starter__body">
                    <?php if (isset($starterFeedings['seed_starter'])): ?>
                        <article class="bm-feed">
                            <h3><?php bakery_te('production.feed_seed'); ?></h3>
                            <ul class="bm-feed__list">
                                <li><span><?php bakery_te('production.mother_starter'); ?></span><strong><?php echo number_format($starterFeedings['seed_starter']['mother_starter'], 0); ?>g</strong></li>
                                <li><span><?php bakery_te('production.flour'); ?></span><strong><?php echo number_format($starterFeedings['seed_starter']['flour'], 0); ?>g</strong></li>
                                <li><span><?php bakery_te('production.water'); ?></span><strong><?php echo number_format($starterFeedings['seed_starter']['water'], 0); ?>g</strong></li>
                                <li class="bm-feed__total"><span><?php bakery_te('production.total_seed'); ?></span><strong><?php echo number_format($starterFeedings['seed_starter']['total_needed'], 0); ?>g</strong></li>
                            </ul>
                        </article>
                    <?php endif; ?>
                    <?php if (isset($starterFeedings['starter'])): ?>
                        <article class="bm-feed">
                            <h3><?php bakery_te('production.feed_regular'); ?></h3>
                            <ul class="bm-feed__list">
                                <li><span><?php bakery_te('production.seed_starter'); ?></span><strong><?php echo number_format($starterFeedings['starter']['seed_starter'], 0); ?>g</strong></li>
                                <li><span><?php bakery_te('production.flour'); ?></span><strong><?php echo number_format($starterFeedings['starter']['flour'], 0); ?>g</strong></li>
                                <li><span><?php bakery_te('production.water'); ?></span><strong><?php echo number_format($starterFeedings['starter']['water'], 0); ?>g</strong></li>
                                <li class="bm-feed__total"><span><?php bakery_te('production.total_starter'); ?></span><strong><?php echo number_format($starterFeedings['starter']['total_needed'], 0); ?>g</strong></li>
                            </ul>
                        </article>
                    <?php endif; ?>
                    <?php if (isset($starterFeedings['starter_liquido'])): ?>
                        <article class="bm-feed">
                            <h3><?php bakery_te('production.feed_liquido'); ?></h3>
                            <ul class="bm-feed__list">
                                <li><span><?php bakery_te('production.seed_starter'); ?></span><strong><?php echo number_format($starterFeedings['starter_liquido']['seed_starter'], 0); ?>g</strong></li>
                                <li><span><?php bakery_te('production.flour'); ?></span><strong><?php echo number_format($starterFeedings['starter_liquido']['flour'], 0); ?>g</strong></li>
                                <li><span><?php bakery_te('production.water'); ?></span><strong><?php echo number_format($starterFeedings['starter_liquido']['water'], 0); ?>g</strong></li>
                                <li class="bm-feed__total"><span><?php bakery_te('production.total_liquido'); ?></span><strong><?php echo number_format($starterFeedings['starter_liquido']['total_needed'], 0); ?>g</strong></li>
                            </ul>
                        </article>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="bm-batches" aria-labelledby="bm-batches-title">
            <h2 id="bm-batches-title" class="bm-section-title"><?php bakery_te('baker_mix.batches_title'); ?></h2>
            <p class="bm-section-lead"><?php bakery_te('baker_mix.batches_lead'); ?></p>

            <?php foreach ($batches as $batch):
                $totalPct = (float)($batch['formula']['total_percentage'] ?? 0);
                $totalWeight = (float)$batch['total_weight_grams'];
                $hasBatchFormula = !empty($batch['ingredients']) && $totalPct > 0 && $totalWeight > 0;
                $lineName = trim((string)($batch['product_line_name'] ?? ''));
            ?>
                <article class="bm-batch">
                    <header class="bm-batch__header">
                        <h3 class="bm-batch__title"><?php echo htmlspecialchars((string)$batch['dough_type_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                        <?php if ($lineName !== ''): ?>
                            <span class="bm-batch__line"><?php echo htmlspecialchars($lineName, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                        <p class="bm-batch__meta"><?php echo htmlspecialchars(bakery_t('baker_mix.batch_meta', [
                            'units' => number_format((int)$batch['planned_units']),
                            'grams' => number_format((int)$batch['total_weight_grams']),
                        ]), ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php if (!empty($batch['pan_dulce_hint'])): ?>
                            <p class="bm-batch__hint">
                                <strong><?php bakery_te('production.batch_left'); ?></strong>
                                <?php echo htmlspecialchars(bakery_t('production.batch_left_values', [
                                    'gallons' => rtrim(rtrim(number_format((float)$batch['pan_dulce_hint']['gallons'], 2), '0'), '.'),
                                    'trays' => rtrim(rtrim(number_format((float)$batch['pan_dulce_hint']['trays'], 1), '0'), '.'),
                                    'pieces' => number_format((int)$batch['pan_dulce_hint']['pieces']),
                                ]), ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                        <?php endif; ?>
                    </header>

                    <?php if ($hasBatchFormula):
                        $flour = $totalWeight / ($totalPct / 100);
                        bakery_baker_mix_echo_formula($batch['ingredients'], (float)$flour, $totalWeight, true);
                    else: ?>
                        <p class="bm-batch__empty"><?php bakery_te('baker_mix.no_formula'); ?></p>
                    <?php endif; ?>

                    <?php if (!empty($batch['products'])): ?>
                        <ul class="bm-products" aria-label="<?php echo htmlspecialchars(bakery_t('baker_mix.products_from_mix'), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php foreach ($batch['products'] as $product): ?>
                                <li>
                                    <span><?php echo htmlspecialchars((string)$product['product_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <strong><?php echo number_format((int)$product['planned_quantity']); ?></strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>

        <p class="bm-footer-note"><?php bakery_te('baker_mix.record_hint'); ?>
            <a href="<?php echo htmlspecialchars($productionHref, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('baker_mix.open_production'); ?></a>
        </p>
    <?php endif; ?>
</div>

<style>
.bm-screen { max-width: 720px; margin: 0 auto; padding: 12px 14px 40px; }
.bm-header { background: linear-gradient(160deg, #1a4a45 0%, #2d6a4f 100%); color: #fff; border-radius: 16px; padding: 18px 16px 16px; margin-bottom: 16px; }
.bm-header__top { display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; }
.bm-title { margin: 0; font-size: 1.55rem; line-height: 1.2; }
.bm-lead { margin: 0 0 14px; opacity: .92; font-size: .95rem; line-height: 1.4; }
.bm-header__links { display: flex; flex-wrap: wrap; gap: 8px; }
.bm-link { background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.28); border-radius: 999px; color: #fff; font-weight: 700; min-height: 44px; padding: 10px 14px; text-decoration: none; display: inline-flex; align-items: center; }
.bm-date-form { display: grid; gap: 8px; }
.bm-date-label { font-size: .9rem; opacity: .9; }
.bm-date-input { width: 100%; max-width: 280px; min-height: 48px; padding: 10px 12px; border: 1px solid rgba(255,255,255,.35); border-radius: 10px; background: #fff; color: #173f3c; font-size: 16px; }
.bm-date-context { margin: 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; font-size: .95rem; }
.bm-chip { background: rgba(255,255,255,.14); border-radius: 999px; padding: 4px 10px; font-size: .82rem; font-weight: 700; }
.bm-alert { border-radius: 10px; padding: 14px 16px; margin-bottom: 14px; }
.bm-alert--error { background: #fdecec; border: 1px solid #e7a1a1; color: #7a1f1f; }
.bm-empty { text-align: center; padding: 32px 16px; background: #f8faf9; border: 1px dashed #c8d8d0; border-radius: 12px; }
.bm-btn { display: inline-flex; align-items: center; min-height: 44px; padding: 10px 16px; border-radius: 10px; background: #1f6637; color: #fff; font-weight: 700; text-decoration: none; }
.bm-section-title { margin: 0 0 4px; font-size: 1.2rem; color: #173f3c; }
.bm-section-lead { margin: 0 0 12px; color: #4b6351; font-size: .92rem; }
.bm-starter { margin-bottom: 20px; }
.bm-starter__body { display: grid; gap: 12px; }
.bm-feed { background: #f7fbf8; border: 1px solid #cfe8db; border-radius: 12px; padding: 14px; }
.bm-feed h3 { margin: 0 0 10px; font-size: 1rem; color: #0e5a43; }
.bm-feed__list { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
.bm-feed__list li { display: flex; justify-content: space-between; gap: 10px; padding: 8px 10px; background: #fff; border: 1px solid #e3ece7; border-radius: 8px; }
.bm-feed__total { background: #e8f7ec !important; border-color: #8bc99a !important; font-weight: 700; }
.bm-batches { display: grid; gap: 4px; }
.bm-batch { background: #fff; border: 1px solid #dbe7df; border-radius: 14px; padding: 14px; margin-bottom: 12px; box-shadow: 0 2px 8px rgba(31,42,36,.05); }
.bm-batch__header { margin-bottom: 10px; }
.bm-batch__title { margin: 0; font-size: 1.2rem; color: #173f3c; }
.bm-batch__line { display: inline-block; margin-top: 4px; font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #1f6637; background: #e7f3ec; border-radius: 999px; padding: 3px 9px; }
.bm-batch__meta { margin: 8px 0 0; color: #607068; font-size: .92rem; }
.bm-batch__hint { margin: 10px 0 0; padding: 10px 12px; border-radius: 10px; background: #e7f3ec; color: #174f36; font-size: .95rem; }
.bm-batch__hint strong { display: block; margin-bottom: 2px; }
.bm-batch__empty { margin: 0; color: #607068; }
.bm-formula { list-style: none; margin: 0 0 12px; padding: 0; display: grid; gap: 6px; }
.bm-formula li { display: flex; justify-content: space-between; gap: 10px; padding: 8px 10px; background: #f8faf9; border: 1px solid #e3ece7; border-radius: 8px; }
.bm-formula__total { background: #e8f7ec !important; border-color: #8bc99a !important; font-weight: 700; }
.bm-products { list-style: none; margin: 0; padding: 0; display: grid; gap: 4px; border-top: 1px solid #e4eee8; padding-top: 10px; }
.bm-products li { display: flex; justify-content: space-between; gap: 10px; font-size: .92rem; color: #42545a; }
.bm-footer-note { margin: 8px 0 0; color: #4b6351; font-size: .92rem; line-height: 1.45; }
.bm-footer-note a { font-weight: 700; color: #1f6637; }
.formula-unit-bar { margin: 0 0 14px; }
.formula-unit-bar-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.formula-unit-bar--baker .formula-unit-bar-row { gap: 0; }
.formula-unit-switch {
    display: flex; flex: 0 0 auto; border: 1px solid #8db59a; border-radius: 10px;
    overflow: hidden; background: #f3fbf5;
}
.formula-unit-btn {
    flex: 0 0 auto; min-height: 36px; min-width: 40px; padding: 0 12px; border: 0;
    border-right: 1px solid #8db59a; background: transparent; color: #1f6637;
    font-size: .9rem; font-weight: 700; cursor: pointer; touch-action: manipulation;
}
.formula-unit-btn:last-child { border-right: 0; }
.formula-unit-btn.is-active,
.formula-unit-btn[aria-checked="true"] { background: #1f6b35; color: #fff; }
.ingredient-amount { display: flex; flex-wrap: wrap; justify-content: flex-end; align-items: baseline; gap: 0; text-align: right; }
[data-unit-mode="g"] .qty-lb,
[data-unit-mode="g"] .qty-gal,
[data-unit-mode="g"] .qty-sep { display: none; }
[data-unit-mode="lb"] .qty-g,
[data-unit-mode="lb"] .qty-gal,
[data-unit-mode="lb"] .qty-sep { display: none; }
[data-unit-mode="gal"] .qty-g,
[data-unit-mode="gal"] .qty-sep { display: none; }
[data-unit-mode="gal"] .qty-gal { display: inline; }
[data-unit-mode="gal"] li:not(.is-liquid) .qty-gal { display: none; }
[data-unit-mode="gal"] li:not(.is-liquid) .qty-lb { display: inline; }
.sf-sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); border: 0; }
@media (min-width: 640px) {
    .bm-starter__body { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var storageKey = 'bakery.formulaUnitMode';
    var modes = ['g', 'lb', 'gal'];
    var fallback = 'g';
    function applyFormulaUnitMode(mode) {
        if (modes.indexOf(mode) === -1) mode = fallback;
        document.querySelectorAll('[data-formula-units]').forEach(function (el) {
            el.setAttribute('data-unit-mode', mode);
        });
        document.querySelectorAll('.formula-unit-btn').forEach(function (btn) {
            var on = btn.getAttribute('data-unit') === mode;
            btn.classList.toggle('is-active', on);
            btn.setAttribute('aria-checked', on ? 'true' : 'false');
        });
        try { localStorage.setItem(storageKey, mode); } catch (err) {}
    }
    var saved = null;
    try { saved = localStorage.getItem(storageKey); } catch (err) {}
    if (saved) applyFormulaUnitMode(saved);
    document.querySelectorAll('.formula-unit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            applyFormulaUnitMode(btn.getAttribute('data-unit'));
        });
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
