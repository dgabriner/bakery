<?php
/**
 * Formulas page section for batch size, starter sub-formulas, and add-ins.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/formula_structure.php';
require_once __DIR__ . '/formula_readout.php';

/**
 * @param list<array<string, mixed>> $ingredients
 * @param list<array<string, mixed>> $products
 * @param list<array<string, mixed>> $catalogue
 * @param array<string, mixed> $structure
 * @param array<int, list<array<string, mixed>>> $partsByProduct
 */
function bakery_formula_structure_render_card(
    int $doughTypeId,
    array $ingredients,
    array $products,
    array $catalogue,
    array $structure,
    array $partsByProduct,
    bool $ready,
    ?float $mixPieces = null
): void {
    $structure = bakery_formula_normalize_structure($structure);
    echo '<section class="formula-structure" aria-labelledby="formula-structure-' . $doughTypeId . '">';
    echo '<h3 id="formula-structure-' . $doughTypeId . '">' . bakery_formula_structure_h('formula_structure.section_title') . '</h3>';
    echo '<p class="formula-structure-lead">' . bakery_formula_structure_h('formula_structure.section_lead') . '</p>';
    if (!$ready) {
        echo '<p class="formula-structure-lead">' . bakery_formula_structure_h('formula_structure.not_ready') . '</p>';
        bakery_formula_readout_render($doughTypeId, $ingredients, $structure, $products, $mixPieces);
        echo '</section>';
        return;
    }

    bakery_formula_structure_render_batch($doughTypeId, $structure, $products);
    bakery_formula_readout_render($doughTypeId, $ingredients, $structure, $products, $mixPieces);
    bakery_formula_structure_render_folded($ingredients, $structure);
    bakery_formula_structure_render_starters($doughTypeId, $ingredients, $catalogue, $structure);
    bakery_formula_structure_render_parts($doughTypeId, $products, $catalogue, $partsByProduct);
    echo '</section>';
}

function bakery_formula_structure_h(string $key): string
{
    return htmlspecialchars(bakery_t($key), ENT_QUOTES, 'UTF-8');
}

/**
 * @param array<string, mixed> $structure
 * @param list<array<string, mixed>> $products
 */
function bakery_formula_structure_render_batch(int $doughTypeId, array $structure, array $products): void
{
    $mode = (string) ($structure['batch_size_mode'] ?? '');
    $grams = $structure['standard_batch_dough_grams'];
    $pieces = $structure['standard_batch_pieces'];
    $multiplier = $structure['batch_multiplier'];
    $loss = $structure['dough_loss_grams'];
    $sampleWeight = null;
    foreach ($products as $product) {
        if (!empty($product['weight_grams'])) {
            $sampleWeight = (float) $product['weight_grams'];
            break;
        }
    }
    $reference = bakery_formula_batch_reference($structure, $sampleWeight);
    $share = bakery_formula_loss_share_grams($structure, $sampleWeight);

    echo '<form method="POST" class="formula-structure-form">';
    echo bakery_csrf_field();
    echo '<input type="hidden" name="action" value="save_formula_batch">';
    echo '<input type="hidden" name="dough_type_id" value="' . $doughTypeId . '">';
    echo '<h4>' . bakery_formula_structure_h('formula_structure.batch_title') . '</h4>';
    echo '<div class="formula-structure-grid">';
    bakery_formula_structure_select(
        'batch_size_mode',
        bakery_t('formula_structure.batch_mode'),
        [
            '' => bakery_t('formula_structure.batch_mode_blank'),
            'grams' => bakery_t('formula_structure.batch_mode_grams'),
            'pieces' => bakery_t('formula_structure.batch_mode_pieces'),
        ],
        $mode,
        (string) $doughTypeId
    );
    bakery_formula_structure_number('standard_batch_dough_grams', bakery_t('formula_structure.batch_grams'), $grams, '0.001', '');
    bakery_formula_structure_number(
        'pieces_per_batch',
        bakery_t('formula_structure.pieces_per_batch'),
        $structure['pieces_per_batch'] ?? null,
        '0.001',
        ''
    );
    bakery_formula_structure_number('standard_batch_pieces', bakery_t('formula_structure.batch_pieces'), $pieces, '0.001', '');
    bakery_formula_structure_number(
        'batch_multiplier',
        bakery_t('formula_structure.batch_multiplier'),
        $multiplier,
        '0.001',
        ''
    );
    bakery_formula_structure_number(
        'dough_loss_grams',
        bakery_t('formula_structure.dough_loss'),
        $loss,
        '0.1',
        (string) bakery_formula_default_dough_loss_grams()
    );
    echo '</div>';
    echo '<p class="formula-structure-hint">' . bakery_formula_structure_h('formula_structure.pieces_per_batch_hint') . '</p>';
    echo '<p class="formula-structure-hint">' . bakery_formula_structure_h('formula_structure.batch_multiplier_hint') . '</p>';
    echo '<p class="formula-structure-hint">' . bakery_formula_structure_h('formula_structure.dough_loss_hint') . '</p>';
    if ($reference['configured']) {
        $effective = $reference['mode'] === 'pieces' && $reference['pieces'] !== null
            ? number_format((float) $reference['pieces'], 3) . ' ' . bakery_t('formula_structure.batch_mode_pieces')
            : number_format((float) $reference['grams'], 0) . ' g';
        echo '<p class="formula-structure-hint"><strong>' . bakery_formula_structure_h('formula_structure.effective_batch') . '</strong> '
            . htmlspecialchars($effective, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    if ($share !== null) {
        echo '<p class="formula-structure-hint"><strong>' . bakery_formula_structure_h('formula_structure.loss_share') . '</strong> '
            . htmlspecialchars(number_format($share, 2) . ' g', ENT_QUOTES, 'UTF-8') . '</p>';
    }
    echo '<button type="submit">' . bakery_formula_structure_h('formula_structure.save_batch') . '</button>';
    echo '</form>';
}

/**
 * @param array<string, string> $options
 */
function bakery_formula_structure_select(string $name, string $label, array $options, string $selected, string $idSuffix): void
{
    $id = $name . '-' . $idSuffix;
    echo '<label class="formula-structure-field" for="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">';
    echo '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    echo '<select id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '">';
    foreach ($options as $value => $text) {
        $isSelected = (string) $value === $selected ? ' selected' : '';
        echo '<option value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"' . $isSelected . '>'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    echo '</select></label>';
}

function bakery_formula_structure_number(string $name, string $label, $value, string $step, string $placeholder): void
{
    $shown = $value === null ? '' : (string) $value;
    echo '<label class="formula-structure-field">';
    echo '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    echo '<input type="number" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" step="'
        . htmlspecialchars($step, ENT_QUOTES, 'UTF-8') . '" min="0" value="'
        . htmlspecialchars($shown, ENT_QUOTES, 'UTF-8') . '" placeholder="'
        . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '">';
    echo '</label>';
}

/**
 * @param list<array<string, mixed>> $ingredients
 * @param array<string, mixed> $structure
 */
function bakery_formula_structure_render_folded(array $ingredients, array $structure): void
{
    if ($ingredients === []) {
        return;
    }
    $lines = [];
    $hasLink = false;
    foreach ($ingredients as $ingredient) {
        $line = [
            'ingredient_id' => (int) ($ingredient['ingredient_id'] ?? 0),
            'ingredient_name' => (string) ($ingredient['ingredient_name'] ?? ''),
            'unit' => (string) ($ingredient['unit'] ?? ''),
            'percentage' => (float) ($ingredient['percentage'] ?? 0),
        ];
        if (!empty($ingredient['subformula']) && is_array($ingredient['subformula'])) {
            $line['subformula'] = $ingredient['subformula'];
            if (!empty($ingredient['subformula']['lines'])) {
                $hasLink = true;
            }
        }
        $lines[] = $line;
    }
    $totalPct = 0.0;
    foreach ($lines as $line) {
        $totalPct += $line['percentage'];
    }
    $hasStarter = $structure['starters'] !== [];
    if (!$hasStarter && !$hasLink) {
        return;
    }
    $fold = bakery_formula_fold_formula($lines, $structure['starters'], $totalPct > 0 ? $totalPct : 0.0);
    echo '<div class="formula-structure-folded">';
    echo '<h4>' . bakery_formula_structure_h('formula_structure.folded_title') . '</h4>';
    if (empty($fold['folded'])) {
        echo '<p class="formula-structure-hint">' . bakery_formula_structure_h('formula_structure.not_foldable') . '</p>';
        echo '</div>';
        return;
    }
    echo '<p class="formula-structure-hint">' . bakery_formula_structure_h('formula_structure.folded_lead') . '</p>';
    echo '<p class="formula-structure-hint"><strong>' . bakery_formula_structure_h('formula_structure.total_flour') . '</strong> '
        . htmlspecialchars(number_format((float) $fold['total_flour_grams'], 1), ENT_QUOTES, 'UTF-8') . ' · 100%</p>';
    $waterPercent = (float) $fold['total_flour_grams'] > 0
        ? ((float) $fold['total_water_grams'] / (float) $fold['total_flour_grams']) * 100
        : 0.0;
    echo '<p class="formula-structure-hint"><strong>' . bakery_formula_structure_h('formula_structure.total_water') . '</strong> '
        . htmlspecialchars(number_format((float) $fold['total_water_grams'], 1), ENT_QUOTES, 'UTF-8')
        . ' · ' . htmlspecialchars(number_format($waterPercent, 1), ENT_QUOTES, 'UTF-8') . '%</p>';
    echo '<p class="formula-structure-hint"><strong>' . bakery_formula_structure_h('formula_structure.true_hydration') . '</strong> '
        . htmlspecialchars(number_format($waterPercent, 1), ENT_QUOTES, 'UTF-8') . '%</p>';
    echo '<ul class="formula-structure-lines">';
    foreach ($fold['ingredients'] as $row) {
        $percent = $row['folded_bakers_percent'];
        echo '<li><span>' . htmlspecialchars((string) $row['ingredient_name'], ENT_QUOTES, 'UTF-8') . '</span><strong>'
            . htmlspecialchars($percent === null ? '' : number_format((float) $percent, 1) . '%', ENT_QUOTES, 'UTF-8')
            . '</strong></li>';
    }
    foreach ($fold['prep'] as $prep) {
        echo '<li class="is-prep"><span>' . htmlspecialchars((string) $prep['ingredient_name'], ENT_QUOTES, 'UTF-8')
            . ' - ' . bakery_formula_structure_h('formula_structure.starter_prep') . '</span><strong>'
            . htmlspecialchars(bakery_t((string) $prep['label_key']), ENT_QUOTES, 'UTF-8') . '</strong></li>';
    }
    echo '</ul></div>';
}

/**
 * @param list<array<string, mixed>> $ingredients
 * @param list<array<string, mixed>> $catalogue
 * @param array<string, mixed> $structure
 */
function bakery_formula_structure_render_starters(int $doughTypeId, array $ingredients, array $catalogue, array $structure): void
{
    echo '<div class="formula-structure-block">';
    echo '<h4>' . bakery_formula_structure_h('formula_structure.starter_title') . '</h4>';
    echo '<p class="formula-structure-hint">' . bakery_formula_structure_h('formula_structure.starter_lead') . '</p>';
    foreach ($structure['starters'] as $starter) {
        $replaces = (int) ($starter['replaces_ingredient_id'] ?? 0);
        $replacesName = bakery_formula_structure_ingredient_name($ingredients, $catalogue, $replaces);
        echo '<article class="formula-structure-saved">';
        echo '<strong>' . htmlspecialchars((string) ($starter['name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</strong>';
        echo '<p>' . bakery_formula_structure_h('formula_structure.starter_replaces') . ': '
            . htmlspecialchars($replacesName, ENT_QUOTES, 'UTF-8');
        if ($starter['hydration_percent'] !== null && $starter['hydration_percent'] !== '') {
            echo ' · ' . bakery_formula_structure_h('formula_structure.hydration') . ' '
                . htmlspecialchars((string) $starter['hydration_percent'], ENT_QUOTES, 'UTF-8');
        }
        echo '</p><ul>';
        foreach ($starter['lines'] ?? [] as $line) {
            echo '<li>' . htmlspecialchars((string) ($line['ingredient_name'] ?? ''), ENT_QUOTES, 'UTF-8')
                . ' · ' . htmlspecialchars(bakery_t('formula_structure.role_' . ($line['line_role'] ?? 'other')), ENT_QUOTES, 'UTF-8');
            if ($line['percentage'] !== null && $line['percentage'] !== '') {
                echo ' · ' . htmlspecialchars((string) $line['percentage'], ENT_QUOTES, 'UTF-8') . '%';
            }
            echo '</li>';
        }
        echo '</ul>';
        echo '<form method="POST">';
        echo bakery_csrf_field();
        echo '<input type="hidden" name="action" value="delete_formula_starter">';
        echo '<input type="hidden" name="dough_type_id" value="' . $doughTypeId . '">';
        echo '<input type="hidden" name="subformula_id" value="' . (int) ($starter['id'] ?? 0) . '">';
        echo '<button type="submit" class="formula-remove-button">' . bakery_formula_structure_h('formula_structure.remove_starter') . '</button>';
        echo '</form></article>';
    }

    if ($ingredients === []) {
        echo '<p class="formula-structure-hint">' . bakery_formula_structure_h('formula_structure.starter_needs_formula') . '</p>';
        echo '</div>';
        return;
    }

    echo '<form method="POST" class="formula-structure-form">';
    echo bakery_csrf_field();
    echo '<input type="hidden" name="action" value="save_formula_starter">';
    echo '<input type="hidden" name="dough_type_id" value="' . $doughTypeId . '">';
    echo '<div class="formula-structure-grid">';
    echo '<label class="formula-structure-field"><span>' . bakery_formula_structure_h('formula_structure.starter_name') . '</span>';
    echo '<input type="text" name="name" maxlength="100" required></label>';
    $replaceOptions = ['' => bakery_t('formula_structure.starter_replaces_blank')];
    foreach ($ingredients as $ingredient) {
        $replaceOptions[(string) (int) $ingredient['ingredient_id']] = (string) ($ingredient['ingredient_name'] ?? '');
    }
    bakery_formula_structure_select('replaces_ingredient_id', bakery_t('formula_structure.starter_replaces'), $replaceOptions, '', (string) $doughTypeId);
    bakery_formula_structure_number('hydration_percent', bakery_t('formula_structure.hydration'), null, '0.001', '');
    echo '</div>';
    echo '<p class="formula-structure-hint">' . bakery_formula_structure_h('formula_structure.line_hint') . '</p>';
    for ($i = 0; $i < 4; $i++) {
        bakery_formula_structure_line_fields($catalogue, true);
    }
    echo '<button type="submit">' . bakery_formula_structure_h('formula_structure.save_starter') . '</button>';
    echo '</form></div>';
}

/**
 * @param list<array<string, mixed>> $products
 * @param list<array<string, mixed>> $catalogue
 * @param array<int, list<array<string, mixed>>> $partsByProduct
 */
function bakery_formula_structure_render_parts(int $doughTypeId, array $products, array $catalogue, array $partsByProduct): void
{
    echo '<div class="formula-structure-block">';
    echo '<h4>' . bakery_formula_structure_h('formula_structure.topping_title') . '</h4>';
    echo '<p class="formula-structure-hint">' . bakery_formula_structure_h('formula_structure.topping_lead') . '</p>';
    if ($products === []) {
        echo '<p class="formula-structure-hint">' . bakery_formula_structure_h('formula_structure.no_products') . '</p>';
        echo '</div>';
        return;
    }
    foreach ($products as $product) {
        $productId = (int) $product['id'];
        foreach ($partsByProduct[$productId] ?? [] as $part) {
            echo '<article class="formula-structure-saved">';
            echo '<strong>' . htmlspecialchars((string) ($part['name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</strong>';
            echo '<p>' . htmlspecialchars((string) ($product['name'] ?? ''), ENT_QUOTES, 'UTF-8')
                . ' · ' . htmlspecialchars(bakery_t($part['kind'] === 'filling' ? 'formula_structure.kind_filling' : 'formula_structure.kind_topping'), ENT_QUOTES, 'UTF-8')
                . '</p><ul>';
            foreach ($part['lines'] ?? [] as $line) {
                echo '<li>' . htmlspecialchars((string) ($line['ingredient_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                if ($line['grams_per_piece'] !== null && $line['grams_per_piece'] !== '') {
                    echo ' · ' . htmlspecialchars((string) $line['grams_per_piece'], ENT_QUOTES, 'UTF-8') . ' g';
                }
                echo '</li>';
            }
            echo '</ul><form method="POST">';
            echo bakery_csrf_field();
            echo '<input type="hidden" name="action" value="delete_formula_part">';
            echo '<input type="hidden" name="dough_type_id" value="' . $doughTypeId . '">';
            echo '<input type="hidden" name="subformula_id" value="' . (int) ($part['id'] ?? 0) . '">';
            echo '<button type="submit" class="formula-remove-button">' . bakery_formula_structure_h('formula_structure.remove_part') . '</button>';
            echo '</form></article>';
        }
    }

    echo '<form method="POST" class="formula-structure-form">';
    echo bakery_csrf_field();
    echo '<input type="hidden" name="action" value="save_formula_part">';
    echo '<input type="hidden" name="dough_type_id" value="' . $doughTypeId . '">';
    echo '<div class="formula-structure-grid">';
    $productOptions = ['' => bakery_t('formula_structure.product')];
    foreach ($products as $product) {
        $productOptions[(string) (int) $product['id']] = (string) $product['name'];
    }
    bakery_formula_structure_select('product_id', bakery_t('formula_structure.product'), $productOptions, '', 'product-' . $doughTypeId);
    bakery_formula_structure_select('kind', bakery_t('formula_structure.part_kind'), [
        'topping' => bakery_t('formula_structure.kind_topping'),
        'filling' => bakery_t('formula_structure.kind_filling'),
    ], 'topping', 'kind-' . $doughTypeId);
    echo '<label class="formula-structure-field"><span>' . bakery_formula_structure_h('formula_structure.part_name') . '</span>';
    echo '<input type="text" name="name" maxlength="100" required></label>';
    echo '</div>';
    echo '<p class="formula-structure-hint">' . bakery_formula_structure_h('formula_structure.line_hint') . '</p>';
    for ($i = 0; $i < 3; $i++) {
        bakery_formula_structure_line_fields($catalogue, false);
    }
    echo '<button type="submit">' . bakery_formula_structure_h('formula_structure.save_part') . '</button>';
    echo '</form></div>';
}

/**
 * @param list<array<string, mixed>> $catalogue
 */
function bakery_formula_structure_line_fields(array $catalogue, bool $starter): void
{
    echo '<div class="formula-structure-line">';
    echo '<label><span>' . bakery_formula_structure_h('formula_structure.starter_line_ingredient') . '</span>';
    echo '<select name="line_ingredient_id[]"><option value="">' . bakery_formula_structure_h('formula_structure.choose_ingredient') . '</option>';
    foreach ($catalogue as $ingredient) {
        echo '<option value="' . (int) $ingredient['id'] . '">'
            . htmlspecialchars((string) ($ingredient['name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</option>';
    }
    echo '</select></label>';
    if ($starter) {
        echo '<label><span>' . bakery_formula_structure_h('formula_structure.starter_line_role') . '</span>';
        echo '<select name="line_role[]">';
        echo '<option value="flour">' . bakery_formula_structure_h('formula_structure.role_flour') . '</option>';
        echo '<option value="water">' . bakery_formula_structure_h('formula_structure.role_water') . '</option>';
        echo '<option value="other">' . bakery_formula_structure_h('formula_structure.role_other') . '</option>';
        echo '</select></label>';
        echo '<label><span>' . bakery_formula_structure_h('formula_structure.starter_line_percent') . '</span>';
        echo '<input type="number" name="line_percentage[]" step="0.001" min="0" placeholder=""></label>';
    } else {
        echo '<label><span>' . bakery_formula_structure_h('formula_structure.grams_per_piece') . '</span>';
        echo '<input type="number" name="line_grams_per_piece[]" step="0.001" min="0" placeholder=""></label>';
    }
    echo '</div>';
}

/**
 * @param list<array<string, mixed>> $ingredients
 * @param list<array<string, mixed>> $catalogue
 */
function bakery_formula_readout_h(string $key, array $params = []): string
{
    return htmlspecialchars(bakery_t($key, $params), ENT_QUOTES, 'UTF-8');
}

function bakery_formula_readout_num(float $number, int $decimals): string
{
    return number_format($number, $decimals);
}

function bakery_formula_readout_pieces_value(float $pieces): string
{
    $text = rtrim(rtrim(number_format($pieces, 3, '.', ''), '0'), '.');
    return $text === '' ? '0' : $text;
}

/**
 * @param list<array<string, mixed>> $lines
 * @return list<array<string, mixed>>
 */
function bakery_formula_readout_lines_of(array $lines, string $kind): array
{
    $matched = [];
    foreach ($lines as $line) {
        if (($line['kind'] ?? '') === $kind) {
            $matched[] = $line;
        }
    }
    return $matched;
}

/**
 * @param array<string, mixed>|null $metric
 */
function bakery_formula_readout_metric(string $labelKey, ?array $metric, string $field): void
{
    echo '<p><strong>' . bakery_formula_readout_h($labelKey) . '</strong> ';
    if ($metric === null || empty($metric['enough']) || $metric[$field] === null) {
        echo bakery_formula_readout_h('formula_readout.not_enough');
    } else {
        echo htmlspecialchars(bakery_formula_readout_num((float) $metric[$field], 1) . '%', ENT_QUOTES, 'UTF-8');
    }
    echo '</p>';
}

/**
 * Read-only hydration, preferment, target mix, and implied pieces.
 * The mix form is GET. It does not save.
 *
 * @param list<array<string, mixed>> $lines
 * @param array<string, mixed> $structure
 * @param list<array<string, mixed>> $products
 */
function bakery_formula_readout_render(
    int $doughTypeId,
    array $lines,
    array $structure,
    array $products,
    ?float $mixPieces
): void {
    $weighted = [];
    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }
        $weight = bakery_formula_normalize_optional_number($product['weight_grams'] ?? null);
        if ($weight === null || $weight <= 0) {
            continue;
        }
        $product['weight_grams'] = $weight;
        $weighted[] = $product;
    }

    $sampleWeight = $weighted === [] ? null : (float) $weighted[0]['weight_grams'];
    $sample = bakery_formula_readout($lines, $structure, [
        'piece_weight_grams' => $sampleWeight,
        'pieces' => $mixPieces,
    ]);
    $formPieces = $mixPieces !== null ? $mixPieces : $sample['implied']['entered_pieces'];

    echo '<div class="formula-readout">';
    echo '<h4 id="formula-readout-' . $doughTypeId . '">' . bakery_formula_readout_h('formula_readout.title') . '</h4>';
    echo '<p class="formula-structure-hint">' . bakery_formula_readout_h('formula_readout.lead') . '</p>';
    bakery_formula_readout_metric('formula_readout.hydration', $sample['hydration'], 'percent');
    bakery_formula_readout_metric('formula_readout.pff', $sample['pff'], 'percent');
    echo '<p class="formula-structure-hint">' . bakery_formula_readout_h('formula_readout.pff_hint') . '</p>';
    bakery_formula_readout_metric('formula_readout.preferment_share', $sample['preferment_share'], 'percent');
    if (!empty($sample['starter_incomplete'])) {
        echo '<p class="formula-structure-hint">' . bakery_formula_readout_h('formula_readout.starter_not_folded') . '</p>';
    }
    if (!empty($sample['loss_unset'])) {
        echo '<p class="formula-structure-hint">' . bakery_formula_readout_h('formula_readout.loss_unset') . '</p>';
    }

    echo '<form method="get" action="formulas.php#formula-' . $doughTypeId . '" class="formula-readout-form">';
    echo '<fieldset>';
    echo '<legend>' . bakery_formula_readout_h('formula_readout.mix_title') . '</legend>';
    echo '<input type="hidden" name="dough_type" value="' . $doughTypeId . '">';
    echo '<label class="formula-structure-field" for="mix-pieces-' . $doughTypeId . '">';
    echo '<span>' . bakery_formula_readout_h('formula_readout.mix_pieces') . '</span>';
    echo '<input id="mix-pieces-' . $doughTypeId . '" type="number" name="mix_pieces" inputmode="decimal" min="0" step="any"';
    if ($formPieces !== null) {
        echo ' value="' . htmlspecialchars(bakery_formula_readout_pieces_value((float) $formPieces), ENT_QUOTES, 'UTF-8') . '"';
    }
    echo '>';
    echo '</label>';
    echo '<button type="submit">' . bakery_formula_readout_h('formula_readout.mix_show') . '</button>';
    echo '<p class="formula-structure-hint">' . bakery_formula_readout_h('formula_readout.mix_note') . '</p>';
    echo '</fieldset></form>';

    if ($weighted === []) {
        echo '<p><strong>' . bakery_formula_readout_h('formula_readout.mix_title') . '</strong> ';
        echo bakery_formula_readout_h('formula_readout.not_enough') . '</p>';
        echo '<p><strong>' . bakery_formula_readout_h('formula_readout.implied_title') . '</strong> ';
        echo bakery_formula_readout_h('formula_readout.not_enough') . '</p>';
        echo '</div>';
        return;
    }

    foreach ($weighted as $product) {
        $readout = bakery_formula_readout($lines, $structure, [
            'piece_weight_grams' => (float) $product['weight_grams'],
            'pieces' => $mixPieces,
        ]);
        $name = htmlspecialchars((string) ($product['name'] ?? ''), ENT_QUOTES, 'UTF-8');
        echo '<article class="formula-readout-product">';
        echo '<h5>' . $name . '</h5>';
        echo '<p><strong>' . bakery_formula_readout_h('formula_readout.piece_weight') . '</strong> ';
        echo htmlspecialchars(bakery_formula_readout_num((float) $product['weight_grams'], 1) . ' g', ENT_QUOTES, 'UTF-8') . '</p>';
        bakery_formula_readout_mix_block($readout);
        bakery_formula_readout_implied_block($readout);
        echo '</article>';
    }
    echo '</div>';
}

/**
 * @param array<string, mixed> $readout
 */
function bakery_formula_readout_mix_block(array $readout): void
{
    $mix = $readout['mix'];
    echo '<h5>' . bakery_formula_readout_h('formula_readout.mix_title') . '</h5>';
    if (empty($mix['dough_enough']) || $mix['dough_grams'] === null) {
        echo '<p>' . bakery_formula_readout_h('formula_readout.not_enough') . '</p>';
        return;
    }
    echo '<p><strong>' . bakery_formula_readout_h('formula_readout.dough_total') . '</strong> ';
    echo htmlspecialchars(bakery_formula_readout_num((float) $mix['dough_grams'], 1) . ' g', ENT_QUOTES, 'UTF-8');
    echo ' <span class="formula-structure-hint">('
        . htmlspecialchars(bakery_formula_readout_num((float) $mix['pieces'], 1), ENT_QUOTES, 'UTF-8')
        . ' × '
        . htmlspecialchars(bakery_formula_readout_num((float) $mix['piece_weight_grams'], 1), ENT_QUOTES, 'UTF-8')
        . ' g + '
        . htmlspecialchars(bakery_formula_readout_num((float) $readout['loss_grams'], 1), ENT_QUOTES, 'UTF-8')
        . ' g ' . bakery_formula_readout_h('formula_readout.loss')
        . ')</span></p>';
    if (empty($mix['enough'])) {
        echo '<p>' . bakery_formula_readout_h('formula_readout.not_enough') . '</p>';
        return;
    }
    bakery_formula_readout_sum_row('formula_readout.direct_flour', $mix['lines'], 'flour', (float) $mix['direct_flour_grams']);
    bakery_formula_readout_sum_row('formula_readout.direct_water', $mix['lines'], 'water', (float) $mix['direct_water_grams']);
    bakery_formula_readout_sum_row('formula_readout.starter', $mix['lines'], 'starter', (float) $mix['starter_grams']);
    bakery_formula_readout_sum_row('formula_readout.salt', $mix['lines'], 'salt', (float) $mix['salt_grams']);
    foreach ($mix['lines'] as $line) {
        $kind = (string) ($line['kind'] ?? '');
        if ($kind === 'flour' || $kind === 'water' || $kind === 'starter' || $kind === 'salt') {
            continue;
        }
        echo '<p><strong>' . htmlspecialchars((string) $line['name'], ENT_QUOTES, 'UTF-8') . '</strong> ';
        echo htmlspecialchars(bakery_formula_readout_num((float) $line['grams'], 1) . ' g', ENT_QUOTES, 'UTF-8') . '</p>';
    }
}

/**
 * @param list<array<string, mixed>> $lines
 */
function bakery_formula_readout_sum_row(string $labelKey, array $lines, string $kind, float $grams): void
{
    $matched = bakery_formula_readout_lines_of($lines, $kind);
    if ($matched === [] && $grams <= 0) {
        return;
    }
    $label = bakery_formula_readout_h($labelKey);
    if (count($matched) === 1 && $matched[0]['name'] !== '') {
        $label .= ' · ' . htmlspecialchars((string) $matched[0]['name'], ENT_QUOTES, 'UTF-8');
    }
    echo '<p><strong>' . $label . '</strong> ';
    echo htmlspecialchars(bakery_formula_readout_num($grams, 1) . ' g', ENT_QUOTES, 'UTF-8') . '</p>';
    if (count($matched) > 1) {
        echo '<ul class="formula-structure-lines">';
        foreach ($matched as $line) {
            echo '<li><span>' . htmlspecialchars((string) $line['name'], ENT_QUOTES, 'UTF-8') . '</span><strong>'
                . htmlspecialchars(bakery_formula_readout_num((float) $line['grams'], 1) . ' g', ENT_QUOTES, 'UTF-8')
                . '</strong></li>';
        }
        echo '</ul>';
    }
}

/**
 * @param array<string, mixed> $readout
 */
function bakery_formula_readout_implied_block(array $readout): void
{
    $implied = $readout['implied'];
    $class = !empty($implied['flag']) ? ' class="formula-readout-flag"' : '';
    echo '<h5>' . bakery_formula_readout_h('formula_readout.implied_title') . '</h5>';
    echo '<p' . $class . '>';
    echo '<strong>' . bakery_formula_readout_h('formula_readout.implied_pieces') . '</strong> ';
    if (empty($implied['enough']) || $implied['pieces'] === null) {
        echo bakery_formula_readout_h('formula_readout.not_enough');
    } else {
        echo htmlspecialchars(bakery_formula_readout_num((float) $implied['pieces'], 1), ENT_QUOTES, 'UTF-8');
    }
    echo ' · <strong>' . bakery_formula_readout_h('formula_readout.entered_pieces') . '</strong> ';
    if ($implied['entered_pieces'] === null) {
        echo bakery_formula_readout_h('formula_readout.not_enough');
    } else {
        echo htmlspecialchars(bakery_formula_readout_num((float) $implied['entered_pieces'], 1), ENT_QUOTES, 'UTF-8');
    }
    if (!empty($implied['flag'])) {
        echo ' <span>' . bakery_formula_readout_h('formula_readout.flag') . '</span>';
    }
    echo '</p>';
    if (!empty($implied['enough']) && $implied['total_flour_grams'] !== null && $implied['total_bakers_percent'] !== null) {
        $flourGrams = (float) $implied['total_flour_grams'];
        echo '<p class="formula-structure-hint"><strong>' . bakery_formula_readout_h('formula_readout.total_flour') . '</strong> ';
        echo htmlspecialchars(
            bakery_formula_readout_num($flourGrams, 1) . ' g · ' . bakery_format_formula_lb($flourGrams),
            ENT_QUOTES,
            'UTF-8'
        );
        echo ' · <strong>' . bakery_formula_readout_h('formula_readout.bakers_percent') . '</strong> ';
        echo htmlspecialchars(bakery_formula_readout_num((float) $implied['total_bakers_percent'], 1) . '%', ENT_QUOTES, 'UTF-8');
        echo '</p>';
    }
}

/**
 * @param list<array<string, mixed>> $ingredients
 * @param list<array<string, mixed>> $catalogue
 */
function bakery_formula_structure_ingredient_name(array $ingredients, array $catalogue, int $ingredientId): string
{
    foreach ($ingredients as $ingredient) {
        if ((int) ($ingredient['ingredient_id'] ?? 0) === $ingredientId) {
            return (string) ($ingredient['ingredient_name'] ?? '');
        }
    }
    foreach ($catalogue as $ingredient) {
        if ((int) ($ingredient['id'] ?? 0) === $ingredientId) {
            return (string) ($ingredient['name'] ?? '');
        }
    }
    return '';
}
