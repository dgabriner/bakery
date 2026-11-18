<?php
/**
 * Baker day for Danny's admin profile.
 * Juan's Mix, Bake, and Pack screens are separate and are not rendered here.
 */
define('ACCESS_ALLOWED', true);

require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/baker_day.php';
require_once 'includes/kitchen_segments.php';
require_once 'includes/admin_workspace_mode.php';

$craftUser = function_exists('bakery_current_user') ? bakery_current_user() : null;
if (!bakery_admin_craft_preview_allowed($craftUser)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo function_exists('bakery_t') ? bakery_t('baker_day.forbidden') : "Forbidden\n";
    exit;
}
bakery_admin_craft_preview_set('baker');

$stage = (string)($_GET['stage'] ?? $_POST['stage'] ?? 'mix');
if (!in_array($stage, ['mix', 'shape', 'pack'], true)) {
    $stage = 'mix';
}
$selectedDate = bakery_staff_selected_date($_GET['date'] ?? ($_POST['date'] ?? null), bakery_baker_home_delivery_date());
$noticeCode = (string)($_GET['notice'] ?? '');
$error = '';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $postDate = bakery_staff_selected_date($_POST['date'] ?? null, $selectedDate);
    $postStage = (string)($_POST['stage'] ?? $stage);
    if (!in_array($postStage, ['mix', 'shape', 'pack'], true)) {
        $postStage = 'mix';
    }
    $userId = (int)($craftUser['id'] ?? 0);
    $userId = $userId > 0 ? $userId : null;
    try {
        if ($action === 'set_batches') {
            $step = (string)($_POST['step'] ?? '');
            if ($step === '0.5' || $step === '-0.5') {
                $batches = bakery_baker_day_step_batches((float)($_POST['current_batches'] ?? 0), (float)$step);
            } else {
                $batches = bakery_baker_day_normalize_batches((float)($_POST['batches'] ?? 0));
            }
            bakery_baker_day_apply_batches($db, $postDate, (int)($_POST['dough_type_id'] ?? 0), $batches, $userId);
            $noticeCode = 'saved';
        } elseif ($action === 'set_shape') {
            bakery_baker_day_apply_shape(
                $db,
                $postDate,
                (int)($_POST['product_id'] ?? 0),
                (int)($_POST['quantity'] ?? 0),
                $userId
            );
            $noticeCode = 'saved';
        } elseif ($action === 'add_dough') {
            bakery_baker_day_add_dough($db, $postDate, (int)($_POST['dough_type_id'] ?? 0), $userId);
            $noticeCode = 'saved';
        } elseif ($action === 'publish') {
            bakery_baker_day_publish($db, $postDate, $userId);
            $noticeCode = 'published';
        } else {
            $noticeCode = '';
        }
        if ($noticeCode !== '') {
            header('Location: ' . BASE_URL . 'baker_day.php?' . http_build_query([
                'date' => $postDate,
                'stage' => $postStage,
                'notice' => $noticeCode,
            ]));
            exit;
        }
    } catch (Throwable $e) {
        $selectedDate = $postDate;
        $stage = $postStage;
        $error = $e instanceof InvalidArgumentException || $e instanceof RuntimeException
            ? $e->getMessage()
            : bakery_t('baker_day.error_save');
        $noticeCode = '';
    }
}

$view = bakery_baker_day_view($db, $selectedDate);
$batches = $view['batches'];
$status = $view['status'];
$working = $view['working'];
$produced = $view['produced'];
$page_title = bakery_t('baker_day.title');

$publishLabel = (int)$status['delta'] === 0
    ? bakery_t('baker_day.publish')
    : bakery_t('baker_day.publish_delta', [
        'delta' => ((int)$status['delta'] > 0 ? '+' : '') . (int)$status['delta'],
    ]);
$canPublish = !($status['committed'] && $status['matches']);

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(function_exists('bakery_asset_href') ? bakery_asset_href('css/baker_day.css') : (BASE_URL . 'css/baker_day.css'), ENT_QUOTES, 'UTF-8'); ?>">

<div class="bd-screen">
    <header class="bd-header">
        <h1 class="bd-title"><?php bakery_te('baker_day.title'); ?></h1>
        <p class="bd-lead"><?php bakery_te('baker_day.lead'); ?></p>
        <form method="get" action="baker_day.php" class="bd-date">
            <input type="hidden" name="stage" value="<?php echo htmlspecialchars($stage, ENT_QUOTES, 'UTF-8'); ?>">
            <label for="baker-day-date"><?php bakery_te('baker_mix.bake_for_delivery'); ?></label>
            <input id="baker-day-date" type="date" name="date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>" onchange="this.form.submit()">
        </form>
        <nav class="bd-stages" aria-label="<?php bakery_te('nav.baker_day_aria'); ?>">
            <?php foreach (['mix' => 'baker_day.stage_mix', 'shape' => 'baker_day.stage_shape', 'pack' => 'baker_day.stage_pack'] as $key => $labelKey): ?>
                <?php $href = 'baker_day.php?' . http_build_query(['date' => $selectedDate, 'stage' => $key]); ?>
                <a class="bd-stages__link<?php echo $stage === $key ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $stage === $key ? ' aria-current="page"' : ''; ?>><?php bakery_te($labelKey); ?></a>
            <?php endforeach; ?>
        </nav>
    </header>

    <?php if ($error !== ''): ?>
        <p class="bd-alert" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php elseif ($noticeCode === 'published'): ?>
        <p class="bd-note" role="status"><?php bakery_te('baker_day.published'); ?></p>
    <?php elseif ($noticeCode === 'saved'): ?>
        <p class="bd-note" role="status"><?php bakery_te('baker_day.saved'); ?></p>
    <?php endif; ?>

    <p class="bd-status">
        <?php if ($status['matches'] && $status['committed']): ?>
            <?php bakery_te('baker_day.status_match_committed'); ?>
        <?php elseif ($status['matches']): ?>
            <?php echo htmlspecialchars(bakery_t('baker_day.status_match_open', ['count' => number_format((int)$status['juan_pieces'])]), ENT_QUOTES, 'UTF-8'); ?>
        <?php else: ?>
            <?php echo htmlspecialchars(bakery_t('baker_day.status_draft', [
                'draft' => number_format((int)$status['draft_pieces']),
                'juan' => number_format((int)$status['juan_pieces']),
            ]), ENT_QUOTES, 'UTF-8'); ?>
        <?php endif; ?>
    </p>

    <?php if ($stage === 'mix'): ?>
        <p class="bd-stage-lead"><?php bakery_te('baker_day.mix_lead'); ?></p>
        <?php if (!empty($view['starter_feedings']) || $view['pull'] !== []): ?>
            <?php
            $formulaDefaultUnit = bakery_formula_default_unit_mode(true);
            $formulaUnitModes = bakery_formula_unit_modes(true);
            ?>
            <div class="formula-unit-bar formula-unit-bar--baker" data-formula-units data-unit-mode="<?php echo htmlspecialchars($formulaDefaultUnit, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="formula-unit-switch" role="radiogroup" aria-label="<?php bakery_te('formula.show_mix_as'); ?>">
                    <?php foreach ($formulaUnitModes as $unitMode): ?>
                        <button type="button" class="formula-unit-btn<?php echo $unitMode === $formulaDefaultUnit ? ' is-active' : ''; ?>" data-unit="<?php echo htmlspecialchars($unitMode, ENT_QUOTES, 'UTF-8'); ?>" aria-checked="<?php echo $unitMode === $formulaDefaultUnit ? 'true' : 'false'; ?>">
                            <?php echo htmlspecialchars(bakery_t('formula.units.' . $unitMode), ENT_QUOTES, 'UTF-8'); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($view['starter_feedings'])): ?>
        <details class="bd-card" open>
            <summary><?php bakery_te('baker_day.starter_title'); ?></summary>
            <p class="bd-card__lead"><?php bakery_te('baker_day.starter_lead'); ?></p>
            <?php $feedings = $view['starter_feedings']; ?>
                <div class="bd-feeds">
                    <?php if (isset($feedings['seed_starter'])): ?>
                        <section class="bd-feed">
                            <h2><?php bakery_te('production.total_seed'); ?></h2>
                            <ul>
                                <li><span><?php bakery_te('production.mother_starter'); ?></span><strong><?php echo number_format((float)$feedings['seed_starter']['mother_starter'], 0); ?>g</strong></li>
                                <li><span><?php bakery_te('production.flour'); ?></span><strong><?php echo number_format((float)$feedings['seed_starter']['flour'], 0); ?>g</strong></li>
                                <li><span><?php bakery_te('production.water'); ?></span><strong><?php echo number_format((float)$feedings['seed_starter']['water'], 0); ?>g</strong></li>
                            </ul>
                        </section>
                    <?php endif; ?>
                    <?php foreach (['starter' => 'production.total_starter', 'starter_liquido' => 'production.total_liquido'] as $feedKey => $titleKey): ?>
                        <?php if (!isset($feedings[$feedKey])) { continue; } ?>
                        <section class="bd-feed">
                            <h2><?php bakery_te($titleKey); ?></h2>
                            <ul>
                                <?php if (isset($feedings[$feedKey]['seed_starter'])): ?>
                                    <li><span><?php bakery_te('production.seed_starter'); ?></span><strong><?php echo number_format((float)$feedings[$feedKey]['seed_starter'], 0); ?>g</strong></li>
                                <?php endif; ?>
                                <li><span><?php bakery_te('production.flour'); ?></span><strong><?php echo number_format((float)$feedings[$feedKey]['flour'], 0); ?>g</strong></li>
                                <li><span><?php bakery_te('production.water'); ?></span><strong><?php echo number_format((float)$feedings[$feedKey]['water'], 0); ?>g</strong></li>
                                <li class="bd-feed__total"><span><?php bakery_te($titleKey); ?></span><strong><?php echo number_format((float)$feedings[$feedKey]['total_needed'], 0); ?>g</strong></li>
                            </ul>
                        </section>
                    <?php endforeach; ?>
                </div>
        </details>
        <?php endif; ?>

        <details class="bd-card" open>
            <summary><?php bakery_te('baker_day.pull_title'); ?></summary>
            <p class="bd-card__lead"><?php bakery_te('baker_day.pull_lead'); ?></p>
            <?php if ($view['pull'] === []): ?>
                <p class="bd-empty"><?php bakery_te('baker_day.pull_empty'); ?></p>
            <?php else: ?>
                <ul class="bm-formula" data-formula-units data-unit-mode="<?php echo htmlspecialchars(bakery_formula_default_unit_mode(true), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php foreach ($view['pull'] as $row): ?>
                        <?php bakery_baker_mix_echo_amount_row($row['name'], $row['unit'], (float)$row['grams'], null); ?>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </details>

        <?php if ($batches === []): ?>
            <p class="bd-empty"><?php bakery_te('baker_day.empty'); ?></p>
        <?php endif; ?>
        <?php foreach ($batches as $batch): ?>
            <?php
            $doughId = (int)($batch['dough_type_id'] ?? 0);
            $batchCount = (float)($batch['displayed_batches'] ?? 0);
            $batchLabel = bakery_baker_day_format_batches($batchCount);
            $formId = 'bd-batch-' . $doughId;
            ?>
            <article class="bd-batch">
                <div class="bd-batch__row">
                    <h2><?php echo htmlspecialchars((string)$batch['dough_type_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <?php if ($doughId > 0): ?>
                        <div class="bd-stepper">
                            <button class="bd-stepper__btn" type="submit" form="<?php echo htmlspecialchars($formId, ENT_QUOTES, 'UTF-8'); ?>" name="step" value="-0.5" aria-label="<?php bakery_te('baker_day.minus'); ?>">−</button>
                            <form id="<?php echo htmlspecialchars($formId, ENT_QUOTES, 'UTF-8'); ?>" method="post" action="baker_day.php">
                                <?php echo bakery_csrf_field(); ?>
                                <input type="hidden" name="action" value="set_batches">
                                <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="stage" value="mix">
                                <input type="hidden" name="dough_type_id" value="<?php echo $doughId; ?>">
                                <input type="hidden" name="current_batches" value="<?php echo htmlspecialchars($batchLabel, ENT_QUOTES, 'UTF-8'); ?>">
                                <button class="bd-sr" type="submit" name="step" value="set"><?php bakery_te('baker_day.set'); ?></button>
                                <label class="bd-sr" for="<?php echo htmlspecialchars($formId, ENT_QUOTES, 'UTF-8'); ?>-n"><?php echo htmlspecialchars((string)$batch['dough_type_name'], ENT_QUOTES, 'UTF-8'); ?></label>
                                <input id="<?php echo htmlspecialchars($formId, ENT_QUOTES, 'UTF-8'); ?>-n" class="bd-stepper__input" type="number" name="batches" min="0" max="40" step="0.5" inputmode="decimal" value="<?php echo htmlspecialchars($batchLabel, ENT_QUOTES, 'UTF-8'); ?>">
                            </form>
                            <button class="bd-stepper__btn" type="submit" form="<?php echo htmlspecialchars($formId, ENT_QUOTES, 'UTF-8'); ?>" name="step" value="0.5" aria-label="<?php bakery_te('baker_day.plus'); ?>">+</button>
                        </div>
                    <?php else: ?>
                        <span class="bd-batch__count"><?php echo htmlspecialchars($batchLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                    <p class="bd-batch__meta"><?php echo htmlspecialchars(bakery_t('baker_day.row_meta', [
                        'batches' => $batchLabel,
                        'pieces' => number_format((int)$batch['planned_units']),
                    ]), ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <details>
                    <summary><?php bakery_te('baker_day.ingredients'); ?></summary>
                    <?php
                    $totalPct = (float)($batch['formula']['total_percentage'] ?? 0);
                    $totalWeight = (float)($batch['total_weight_grams'] ?? 0);
                    $hasFormula = !empty($batch['ingredients']) && $totalPct > 0 && $totalWeight > 0;
                    if ($hasFormula) {
                        bakery_baker_mix_echo_formula(
                            $batch['ingredients'],
                            (float)($batch['formula_dough_grams'] ?? $totalWeight) / ($totalPct / 100),
                            (float)($batch['formula_dough_grams'] ?? $totalWeight),
                            true,
                            $batch['formula_structure'] ?? null,
                            $batch['add_in_rows'] ?? []
                        );
                    } else {
                        echo '<p class="bd-empty">' . htmlspecialchars(bakery_t('baker_day.no_formula'), ENT_QUOTES, 'UTF-8') . '</p>';
                    }
                    ?>
                </details>
            </article>
        <?php endforeach; ?>
    <?php elseif ($stage === 'shape'): ?>
        <p class="bd-stage-lead"><?php bakery_te('baker_day.shape_lead'); ?></p>
        <?php if ($batches === []): ?>
            <p class="bd-empty"><?php bakery_te('baker_day.empty'); ?></p>
        <?php endif; ?>
        <?php foreach ($batches as $batch): ?>
            <article class="bd-batch">
                <div class="bd-batch__row">
                    <h2><?php echo htmlspecialchars((string)$batch['dough_type_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <p class="bd-batch__meta"><?php echo htmlspecialchars(bakery_t('baker_day.row_meta', [
                        'batches' => bakery_baker_day_format_batches((float)($batch['displayed_batches'] ?? 0)),
                        'pieces' => number_format((int)$batch['planned_units']),
                    ]), ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <details>
                    <summary><?php bakery_te('baker_day.shapes'); ?></summary>
                    <ul class="bd-shapes">
                        <?php foreach ($batch['products'] as $product): ?>
                            <?php
                            $productId = (int)$product['product_id'];
                            $planned = (int)$product['planned_quantity'];
                            $made = (int)($produced[$productId] ?? 0);
                            ?>
                            <li>
                                <form method="post" action="baker_day.php" class="bd-shape">
                                    <?php echo bakery_csrf_field(); ?>
                                    <input type="hidden" name="action" value="set_shape">
                                    <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="stage" value="shape">
                                    <input type="hidden" name="product_id" value="<?php echo $productId; ?>">
                                    <label for="shape-<?php echo $productId; ?>"><?php echo htmlspecialchars((string)$product['product_name'], ENT_QUOTES, 'UTF-8'); ?></label>
                                    <input id="shape-<?php echo $productId; ?>" type="number" name="quantity" min="0" step="1" inputmode="numeric" value="<?php echo $planned; ?>">
                                    <button type="submit"><?php bakery_te('baker_day.set'); ?></button>
                                </form>
                                <?php if ($made > 0): ?>
                                    <p class="bd-made"><?php echo htmlspecialchars(bakery_t('baker_day.made_left', [
                                        'made' => number_format($made),
                                        'left' => number_format(max(0, $planned - $made)),
                                    ]), ENT_QUOTES, 'UTF-8'); ?></p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            </article>
        <?php endforeach; ?>
    <?php else: ?>
        <p class="bd-stage-lead"><?php bakery_te('baker_day.pack_lead'); ?></p>
        <details class="bd-card" open>
            <summary><?php bakery_te('baker_day.capp_counter'); ?></summary>
            <p class="bd-card__lead"><?php bakery_te('baker_day.capp_counter_lead'); ?></p>
            <?php if ($view['counter'] === []): ?>
                <p class="bd-empty"><?php bakery_te('baker_day.capp_counter_empty'); ?></p>
            <?php else: ?>
                <ul class="bd-counter">
                    <?php foreach ($view['counter'] as $slip): ?>
                        <li>
                            <strong><?php echo htmlspecialchars($slip['customer_name'] !== '' ? $slip['customer_name'] : bakery_t('baker_day.pile_capp'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <span><?php echo htmlspecialchars($slip['order_text'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </details>
        <?php
        $packById = [];
        foreach ($view['pack'] as $product) {
            $packById[(int)$product['product_id']] = $product;
        }
        $packIds = array_values(array_unique(array_merge(array_keys($packById), array_map('intval', array_keys($working)))));
        $packRows = [];
        foreach ($packIds as $productId) {
            $productId = (int)$productId;
            $shaped = (int)($working[$productId] ?? 0);
            $packed = $packById[$productId] ?? null;
            $ordered = $packed ? (int)$packed['ordered'] : 0;
            if ($shaped <= 0 && $ordered <= 0) {
                continue;
            }
            $name = $packed['product_name'] ?? '';
            if ($name === '') {
                foreach ($batches as $batch) {
                    foreach ($batch['products'] as $product) {
                        if ((int)$product['product_id'] === $productId) {
                            $name = (string)$product['product_name'];
                            break 2;
                        }
                    }
                }
            }
            $packRows[] = [
                'product_id' => $productId,
                'product_name' => $name !== '' ? $name : ('#' . $productId),
                'shaped' => $shaped,
                'ordered' => $ordered,
                'piles' => $packed['piles'] ?? [],
            ];
        }
        usort($packRows, static function (array $a, array $b): int {
            return strcasecmp($a['product_name'], $b['product_name']);
        });
        ?>
        <?php if ($packRows === []): ?>
            <p class="bd-empty"><?php bakery_te('baker_day.pack_summary_empty'); ?></p>
        <?php endif; ?>
        <?php foreach ($packRows as $row): ?>
            <?php
            $summaryParts = [];
            foreach ($row['piles'] as $pile) {
                $summaryParts[] = bakery_baker_day_pile_label($pile) . ' ' . number_format((int)$pile['quantity']);
            }
            $summary = $summaryParts !== [] ? implode(' · ', $summaryParts) : bakery_t('baker_day.pack_summary_empty');
            ?>
            <article class="bd-batch">
                <div class="bd-batch__row">
                    <h2><?php echo htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <p class="bd-batch__meta"><?php echo htmlspecialchars($summary, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php if ((int)$row['shaped'] !== (int)$row['ordered']): ?>
                        <p class="bd-gap"><?php echo htmlspecialchars(bakery_t('baker_day.gap', [
                            'bake' => number_format((int)$row['shaped']),
                            'orders' => number_format((int)$row['ordered']),
                        ]), ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                </div>
                <details>
                    <summary><?php bakery_te('baker_day.stops'); ?></summary>
                    <?php if ($row['piles'] === []): ?>
                        <p class="bd-empty"><?php bakery_te('baker_day.pack_summary_empty'); ?></p>
                    <?php else: ?>
                        <?php foreach ($row['piles'] as $pile): ?>
                            <section class="bd-pile">
                                <h3><?php echo htmlspecialchars(bakery_baker_day_pile_label($pile) . ' · ' . number_format((int)$pile['quantity']), ENT_QUOTES, 'UTF-8'); ?></h3>
                                <ul>
                                    <?php foreach ($pile['stops'] as $stop): ?>
                                        <li>
                                            <span><?php echo htmlspecialchars((string)$stop['customer_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php if ((string)($pile['kind'] ?? '') !== 'driver' && trim((string)($stop['driver_name'] ?? '')) !== ''): ?>
                                                <em><?php echo htmlspecialchars(bakery_t('baker_day.carried_by', ['driver' => (string)$stop['driver_name']]), ENT_QUOTES, 'UTF-8'); ?></em>
                                            <?php endif; ?>
                                            <strong><?php echo number_format((int)$stop['quantity']); ?></strong>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </section>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </details>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($stage !== 'pack' && $view['addable'] !== []): ?>
        <form method="post" action="baker_day.php" class="bd-add">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="add_dough">
            <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="stage" value="<?php echo htmlspecialchars($stage, ENT_QUOTES, 'UTF-8'); ?>">
            <label for="bd-add-dough"><?php bakery_te('baker_day.add_dough'); ?></label>
            <select id="bd-add-dough" name="dough_type_id" required>
                <?php foreach ($view['addable'] as $dough): ?>
                    <option value="<?php echo (int)$dough['id']; ?>"><?php echo htmlspecialchars($dough['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit"><?php bakery_te('baker_day.add'); ?></button>
        </form>
    <?php endif; ?>

    <form method="post" action="baker_day.php" class="bd-publish">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="action" value="publish">
        <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="stage" value="<?php echo htmlspecialchars($stage, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit"<?php echo $canPublish ? '' : ' disabled'; ?>><?php echo htmlspecialchars($publishLabel, ENT_QUOTES, 'UTF-8'); ?></button>
    </form>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var storageKey = 'bakery.formulaUnitMode';
    var modes = ['g', 'lb', 'gal'];
    function applyFormulaUnitMode(mode) {
        if (modes.indexOf(mode) === -1) mode = 'g';
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
