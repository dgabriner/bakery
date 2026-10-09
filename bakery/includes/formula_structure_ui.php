<?php
/**
 * Formulas page section for batch size, starter sub-formulas, and add-ins.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/formula_structure.php';

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
    bool $ready
): void {
    $structure = bakery_formula_normalize_structure($structure);
    echo '<section class="formula-structure" aria-labelledby="formula-structure-' . $doughTypeId . '">';
    echo '<h3 id="formula-structure-' . $doughTypeId . '">' . bakery_formula_structure_h('formula_structure.section_title') . '</h3>';
    echo '<p class="formula-structure-lead">' . bakery_formula_structure_h('formula_structure.section_lead') . '</p>';
    if (!$ready) {
        echo '<p class="formula-structure-lead">' . bakery_formula_structure_h('formula_structure.not_ready') . '</p>';
        echo '</section>';
        return;
    }

    bakery_formula_structure_render_batch($doughTypeId, $structure, $products);
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
    foreach ($ingredients as $ingredient) {
        $lines[] = [
            'ingredient_id' => (int) ($ingredient['ingredient_id'] ?? 0),
            'ingredient_name' => (string) ($ingredient['ingredient_name'] ?? ''),
            'unit' => (string) ($ingredient['unit'] ?? ''),
            'percentage' => (float) ($ingredient['percentage'] ?? 0),
        ];
    }
    $totalPct = 0.0;
    foreach ($lines as $line) {
        $totalPct += $line['percentage'];
    }
    $hasStarter = $structure['starters'] !== [];
    if (!$hasStarter) {
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
    echo '<ul class="formula-structure-lines">';
    foreach ($fold['ingredients'] as $row) {
        $percent = $row['folded_bakers_percent'];
        echo '<li><span>' . htmlspecialchars((string) $row['ingredient_name'], ENT_QUOTES, 'UTF-8') . '</span><strong>'
            . htmlspecialchars($percent === null ? '' : number_format((float) $percent, 1) . '%', ENT_QUOTES, 'UTF-8')
            . '</strong></li>';
    }
    foreach ($fold['prep'] as $prep) {
        echo '<li class="is-prep"><span>' . htmlspecialchars((string) $prep['ingredient_name'], ENT_QUOTES, 'UTF-8')
            . ' — ' . bakery_formula_structure_h('formula_structure.starter_prep') . '</span><strong>'
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
