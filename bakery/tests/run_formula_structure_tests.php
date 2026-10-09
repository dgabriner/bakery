<?php
/**
 * Formula structure math: starter folding, batch multiplier, dough loss, toppings.
 * No database. Fixture numbers are test inputs only — nothing here is a recipe to store.
 *
 * Usage: php tests/run_formula_structure_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
require_once $root . '/includes/formula_structure.php';
require_once $root . '/includes/ingredient_units.php';
require_once $root . '/includes/ingredient_requirements.php';
require_once $root . '/includes/i18n.php';

$pass = 0;
$fail = 0;

function fs_assert_true($condition, $message)
{
    global $pass, $fail;
    if ($condition) {
        echo "PASS  $message\n";
        $pass++;
        return;
    }
    echo "FAIL  $message\n";
    $fail++;
}

function fs_assert_near($expected, $actual, $message, $epsilon = 0.001)
{
    fs_assert_true(abs((float) $expected - (float) $actual) <= $epsilon, $message . " (expected=$expected actual=$actual)");
}

function fs_line(array $rows, $ingredientId)
{
    foreach ($rows as $row) {
        if ((int) $row['ingredient_id'] === (int) $ingredientId) {
            return $row;
        }
    }
    return null;
}

echo "=== Suggested dough loss is 50 g and blank means unset ===\n";
fs_assert_near(50.0, bakery_formula_default_dough_loss_grams(), 'suggested dough loss default is 50 g');
$blank = bakery_formula_normalize_structure([]);
fs_assert_true($blank['dough_loss_grams'] === null, 'missing dough loss stays null');
fs_assert_true($blank['batch_multiplier'] === null, 'missing multiplier stays null');
fs_assert_true($blank['batch_size_mode'] === null, 'missing batch mode stays null');
fs_assert_true($blank['standard_batch_dough_grams'] === null, 'missing batch grams stay null');
fs_assert_true($blank['standard_batch_pieces'] === null, 'missing batch pieces stay null');
fs_assert_true($blank['starters'] === [], 'missing starters stay empty');
$emptyStrings = bakery_formula_normalize_structure([
    'batch_size_mode' => '',
    'standard_batch_dough_grams' => '',
    'standard_batch_pieces' => '',
    'batch_multiplier' => '',
    'dough_loss_grams' => '',
]);
fs_assert_true($emptyStrings['dough_loss_grams'] === null, 'blank dough loss string stays null');
fs_assert_true($emptyStrings['batch_multiplier'] === null, 'blank multiplier string stays null');

echo "\n=== Batch multiplier and pieces mode ===\n";
$gramsOnly = bakery_formula_batch_reference([
    'batch_size_mode' => null,
    'standard_batch_dough_grams' => 18000,
    'standard_batch_pieces' => null,
    'batch_multiplier' => null,
    'dough_loss_grams' => null,
    'starters' => [],
], 500);
fs_assert_true($gramsOnly['configured'] === true, 'legacy grams batch is configured');
fs_assert_near(18000, $gramsOnly['grams'], 'null multiplier does not change batch grams');
fs_assert_true($gramsOnly['multiplier_applied'] === false, 'null multiplier is not applied');
fs_assert_near(36, $gramsOnly['pieces'], '18000 g / 500 g = 36 pieces');

$scaled = bakery_formula_batch_reference(bakery_formula_normalize_structure([
    'batch_size_mode' => 'grams',
    'standard_batch_dough_grams' => 22062,
    'batch_multiplier' => 1.5,
]), 662);
fs_assert_true($scaled['multiplier_applied'] === true, '1.5 multiplier is applied');
fs_assert_near(33093, $scaled['grams'], '22062 g × 1.5 = 33093 g');
fs_assert_near(33093 / 662, $scaled['pieces'], 'effective batch grams ÷ piece weight');

$pieces = bakery_formula_batch_reference(bakery_formula_normalize_structure([
    'batch_size_mode' => 'pieces',
    'standard_batch_pieces' => 40,
    'batch_multiplier' => 2,
]), 80);
fs_assert_true($pieces['mode'] === 'pieces', 'pieces mode wins when selected');
fs_assert_near(80, $pieces['pieces'], '40 pieces × 2 = 80 pieces per mix');
fs_assert_near(6400, $pieces['grams'], '80 pieces × 80 g = 6400 g');

$legacyBatches = bakery_ingredient_requirements_product_batches(120, 500, 60000, 18000);
$blankBatches = bakery_ingredient_requirements_product_batches(120, 500, 60000, 18000, $blank);
fs_assert_near($legacyBatches['theoretical_dough_batches'], $blankBatches['theoretical_dough_batches'], 'blank structure keeps theoretical dough batches');
fs_assert_near($legacyBatches['reference_yield_units'], $blankBatches['reference_yield_units'], 'blank structure keeps reference yield');
fs_assert_near($legacyBatches['theoretical_product_batches'], $blankBatches['theoretical_product_batches'], 'blank structure keeps theoretical product batches');

$multipliedBatches = bakery_ingredient_requirements_product_batches(120, 500, 60000, 18000, [
    'batch_multiplier' => 1.5,
    'standard_batch_dough_grams' => 18000,
]);
fs_assert_near(2.222222, $multipliedBatches['theoretical_dough_batches'], '60000 / (18000 × 1.5) mixes', 0.001);
fs_assert_near(54, $multipliedBatches['reference_yield_units'], 'effective batch yields 54 units');

echo "\n=== Dough loss spread ===\n";
fs_assert_true(
    bakery_formula_loss_share_grams($blank, 100) === null,
    'blank loss is not spread'
);
$lossNoBatch = bakery_formula_normalize_structure(['dough_loss_grams' => 50]);
fs_assert_true(
    bakery_formula_loss_share_grams($lossNoBatch, 100) === null,
    'loss without a batch is not guessed across pieces'
);
$lossBatch = bakery_formula_normalize_structure([
    'batch_size_mode' => 'grams',
    'standard_batch_dough_grams' => 1000,
    'dough_loss_grams' => 50,
]);
fs_assert_near(5, bakery_formula_loss_share_grams($lossBatch, 100), '50 g loss / 10 pieces = 5 g');
$lossPieces = bakery_formula_normalize_structure([
    'batch_size_mode' => 'pieces',
    'standard_batch_pieces' => 40,
    'batch_multiplier' => 2,
    'dough_loss_grams' => 50,
]);
fs_assert_near(0.625, bakery_formula_loss_share_grams($lossPieces, 90), '50 g / (40 × 2) pieces');
fs_assert_near(
    50,
    bakery_formula_mix_loss_grams($lossBatch, [
        ['weight_grams' => 100, 'quantity' => 10],
    ]),
    'one full mix carries the full 50 g loss'
);
fs_assert_near(
    100,
    bakery_formula_mix_loss_grams($lossBatch, [
        ['weight_grams' => 100, 'quantity' => 20],
    ]),
    'two mixes carry 100 g loss'
);

echo "\n=== Starter folding ===\n";
$formula = [
    ['ingredient_id' => 1, 'ingredient_name' => 'Bread Flour', 'unit' => 'g', 'percentage' => 100],
    ['ingredient_id' => 2, 'ingredient_name' => 'Water', 'unit' => 'g', 'percentage' => 70],
    ['ingredient_id' => 6, 'ingredient_name' => 'Starter', 'unit' => 'g', 'percentage' => 20],
    ['ingredient_id' => 3, 'ingredient_name' => 'Salt', 'unit' => 'g', 'percentage' => 2],
];
$classic = bakery_formula_fold_formula($formula, [], 1920);
fs_assert_true($classic['folded'] === false, 'no starter sub-formula does not fold');
fs_assert_near(1000, fs_line($classic['ingredients'], 1)['grams'], 'classic flour is 1000 g');
fs_assert_near(200, fs_line($classic['ingredients'], 6)['grams'], 'classic starter stays a plain ingredient');
$classicSum = 0.0;
foreach ($classic['ingredients'] as $row) {
    $classicSum += (float) $row['grams'];
}
fs_assert_near(1920, $classicSum, 'classic ingredient grams close the dough');

$incomplete = [[
    'replaces_ingredient_id' => 6,
    'hydration_percent' => null,
    'lines' => [
        ['ingredient_id' => 1, 'ingredient_name' => 'Bread Flour', 'unit' => 'g', 'line_role' => 'flour', 'percentage' => null],
    ],
]];
$notFolded = bakery_formula_fold_formula($formula, $incomplete, 1920);
fs_assert_true($notFolded['folded'] === false, 'starter without hydration or line percentages is not folded');
fs_assert_near(200, fs_line($notFolded['ingredients'], 6)['grams'], 'incomplete starter is still counted as starter');

$noWaterLine = [[
    'replaces_ingredient_id' => 6,
    'hydration_percent' => 100,
    'lines' => [
        ['ingredient_id' => 1, 'ingredient_name' => 'Bread Flour', 'unit' => 'g', 'line_role' => 'flour', 'percentage' => 100],
    ],
]];
$noWater = bakery_formula_fold_formula($formula, $noWaterLine, 1920);
fs_assert_true($noWater['folded'] === false, 'hydration without a water ingredient is not folded');

$starter = [[
    'name' => 'Test starter',
    'replaces_ingredient_id' => 6,
    'hydration_percent' => 100,
    'lines' => [
        ['ingredient_id' => 1, 'ingredient_name' => 'Bread Flour', 'unit' => 'g', 'line_role' => 'flour', 'percentage' => 100],
        ['ingredient_id' => 2, 'ingredient_name' => 'Water', 'unit' => 'g', 'line_role' => 'water', 'percentage' => null],
    ],
]];
$folded = bakery_formula_fold_formula($formula, $starter, 1920);
fs_assert_true($folded['folded'] === true, 'complete starter sub-formula folds');
fs_assert_true(fs_line($folded['ingredients'], 6) === null, 'starter ingredient is not in the folded totals');
fs_assert_near(1100, fs_line($folded['ingredients'], 1)['grams'], 'starter flour is added to bread flour');
fs_assert_near(800, fs_line($folded['ingredients'], 2)['grams'], 'starter water is added to water');
fs_assert_near(20, fs_line($folded['ingredients'], 3)['grams'], 'salt is unchanged');
fs_assert_near(1100, $folded['total_flour_grams'], 'folded total flour is 1100 g');
fs_assert_near(800, $folded['total_water_grams'], 'folded total water is 800 g');
$foldedSum = 0.0;
foreach ($folded['ingredients'] as $row) {
    fs_assert_true(!empty($row['counts_in_totals']), 'folded ingredient counts in totals');
    $foldedSum += (float) $row['grams'];
}
fs_assert_near(1920, $foldedSum, 'folded totals do not double-count starter');
fs_assert_near(100, fs_line($folded['ingredients'], 1)['folded_bakers_percent'], 'folded flour is 100% of total flour');
fs_assert_near(72.727272, fs_line($folded['ingredients'], 2)['folded_bakers_percent'], 'folded water baker\'s % uses total flour', 0.01);
fs_assert_true(count($folded['prep']) === 1, 'starter prep line is kept for the baker');
fs_assert_near(200, $folded['prep'][0]['grams'], 'starter prep is the 200 g the baker uses');
fs_assert_true(empty($folded['prep'][0]['counts_in_totals']), 'starter prep is not added again');
fs_assert_true(($folded['prep'][0]['label_key'] ?? '') === 'formula_structure.starter_folded_note', 'starter prep is labeled as folded');

$twoFlours = [[
    'replaces_ingredient_id' => 6,
    'hydration_percent' => 100,
    'lines' => [
        ['ingredient_id' => 4, 'ingredient_name' => 'Whole Wheat', 'unit' => 'g', 'line_role' => 'flour', 'percentage' => 100],
        ['ingredient_id' => 2, 'ingredient_name' => 'Water', 'unit' => 'g', 'line_role' => 'water', 'percentage' => null],
    ],
]];
$splitFlour = bakery_formula_fold_formula($formula, $twoFlours, 1920);
fs_assert_near(1000, fs_line($splitFlour['ingredients'], 1)['grams'], 'dough flour stays 1000 g');
fs_assert_near(100, fs_line($splitFlour['ingredients'], 4)['grams'], 'starter flour is its own flour');
fs_assert_near(1100, $splitFlour['total_flour_grams'], 'both flours make total flour');
fs_assert_near(90.909090, fs_line($splitFlour['ingredients'], 1)['folded_bakers_percent'], 'dough flour share of total flour', 0.01);
fs_assert_near(9.090909, fs_line($splitFlour['ingredients'], 4)['folded_bakers_percent'], 'starter flour share of total flour', 0.01);

$feeding = bakery_formula_starter_feeding_parts(2000, $starter[0]);
fs_assert_true($feeding !== null, 'foldable starter can feed the mix sheet');
fs_assert_near(1000, $feeding['flour_grams'], 'feeding flour comes from the sub-formula');
fs_assert_near(1000, $feeding['water_grams'], 'feeding water comes from the sub-formula');
fs_assert_true(bakery_formula_starter_feeding_parts(2000, $incomplete[0]) === null, 'incomplete starter does not replace the guessed feeding');

echo "\n=== Per-piece grams: dough, starter, topping, filling, loss ===\n";
$structure = bakery_formula_normalize_structure([
    'batch_size_mode' => 'grams',
    'standard_batch_dough_grams' => 1920,
    'dough_loss_grams' => 50,
    'starters' => $starter,
]);
$parts = [
    [
        'kind' => 'topping',
        'name' => 'Coating',
        'lines' => [
            ['ingredient_id' => 9, 'ingredient_name' => 'Sugar', 'unit' => 'g', 'grams_per_piece' => 12],
        ],
    ],
    [
        'kind' => 'filling',
        'name' => 'Filling',
        'lines' => [
            ['ingredient_id' => 8, 'ingredient_name' => 'Guava Paste', 'unit' => 'g', 'grams_per_piece' => 30],
        ],
    ],
];
$pieceWeight = 192;
$piece = bakery_formula_product_piece_grams($formula, $structure, $parts, $pieceWeight);
fs_assert_near(5, $piece['loss_share_grams'], '1920 g batch / 192 g piece = 10 pieces, 50 g / 10');
fs_assert_near(197, $piece['dough_grams'], 'piece dough includes the loss share');
fs_assert_true($piece['folded'] === true, 'per-piece view folds the starter');
$flourPiece = fs_line($piece['ingredients'], 1);
fs_assert_near(1100 * (197 / 1920), $flourPiece['grams'], 'flour scales with dough plus loss');
fs_assert_true(($flourPiece['source'] ?? '') === 'dough', 'folded flour is dough source');
$sugar = fs_line($piece['ingredients'], 9);
$guava = fs_line($piece['ingredients'], 8);
fs_assert_near(12, $sugar['grams'], 'topping is grams per piece');
fs_assert_true(($sugar['source'] ?? '') === 'topping', 'sugar line is a topping');
fs_assert_near(30, $guava['grams'], 'filling is grams per piece');
fs_assert_true(($guava['source'] ?? '') === 'filling', 'guava line is a filling');
fs_assert_true($sugar['folded_bakers_percent'] === null, 'topping is not given a baker\'s %');
$pieceSum = 0.0;
foreach ($piece['ingredients'] as $row) {
    $pieceSum += (float) $row['grams'];
}
fs_assert_near(197 + 12 + 30, $pieceSum, 'per-piece total is dough + loss share + topping + filling');

$plainPiece = bakery_formula_product_piece_grams($formula, $blank, [], $pieceWeight);
$plainSum = 0.0;
foreach ($plainPiece['ingredients'] as $row) {
    $plainSum += (float) $row['grams'];
}
fs_assert_near($pieceWeight, $plainSum, 'blank structure per-piece grams equal the piece weight');
fs_assert_true($plainPiece['loss_applied'] === false, 'blank structure does not apply loss');
fs_assert_true(fs_line($plainPiece['ingredients'], 6) !== null, 'blank structure keeps starter as a plain ingredient');

echo "\n=== Ingredient planner fallback and fold ===\n";
$products = [
    [
        'product_id' => 1,
        'product_name' => 'Loaf',
        'quantity' => 10,
        'weight_grams' => 192,
        'dough_type_id' => 10,
        'dough_type_name' => 'Country',
        'standard_batch_dough_grams' => null,
        'demand_quantity' => 10,
        'quantity_basis' => 'production_plan',
        'plan_vs_demand_delta' => 0,
    ],
];
$formulas = [10 => $formula];
$before = bakery_ingredient_requirements_explode($products, $formulas);
$withBlank = $products;
$withBlank[0]['formula_structure'] = $blank;
$withBlank[0]['formula_parts'] = [];
$afterBlank = bakery_ingredient_requirements_explode($withBlank, $formulas);
fs_assert_near(
    fs_line($before['ingredients'], 1)['required_grams'],
    fs_line($afterBlank['ingredients'], 1)['required_grams'],
    'blank structure does not change flour requirements'
);
fs_assert_near(
    fs_line($before['ingredients'], 6)['required_grams'],
    fs_line($afterBlank['ingredients'], 6)['required_grams'],
    'blank structure does not change starter requirements'
);

$withStarter = $products;
$withStarter[0]['formula_structure'] = bakery_formula_normalize_structure(['starters' => $starter]);
$withStarter[0]['standard_batch_dough_grams'] = 1920;
$withStarter[0]['formula_structure']['standard_batch_dough_grams'] = 1920;
$withStarter[0]['formula_structure']['batch_size_mode'] = 'grams';
$withStarter[0]['formula_structure']['dough_loss_grams'] = 50;
$withStarter[0]['formula_parts'] = $parts;
$exploded = bakery_ingredient_requirements_explode($withStarter, $formulas);
fs_assert_true(fs_line($exploded['ingredients'], 6) === null, 'planner does not buy starter on top of its flour and water');
fs_assert_near(1100 * (197 / 1920) * 10, fs_line($exploded['ingredients'], 1)['required_grams'], 'planner flour includes folded starter flour and loss');
fs_assert_near(12 * 10, fs_line($exploded['ingredients'], 9)['required_grams'], 'planner includes topping grams');
fs_assert_near(30 * 10, fs_line($exploded['ingredients'], 8)['required_grams'], 'planner includes filling grams');
fs_assert_near(1970 / 1920, $exploded['dough_types'][0]['theoretical_dough_batches'], 'loss increases dough against the 1920 g batch', 0.001);

$multiplierOnly = $products;
$multiplierOnly[0]['standard_batch_dough_grams'] = 1920;
$multiplierOnly[0]['formula_structure'] = bakery_formula_normalize_structure([
    'standard_batch_dough_grams' => 1920,
    'batch_multiplier' => 1.5,
]);
$multiplied = bakery_ingredient_requirements_explode($multiplierOnly, $formulas);
fs_assert_near(
    fs_line($before['ingredients'], 1)['required_grams'],
    fs_line($multiplied['ingredients'], 1)['required_grams'],
    'multiplier alone does not change ingredient grams'
);
fs_assert_near(
    (10 * 192) / (1920 * 1.5),
    $multiplied['dough_types'][0]['theoretical_dough_batches'],
    'multiplier enlarges the mix used for batch count',
    0.001
);

echo "\n=== i18n block ===\n";
$en = bakery_lang_catalog('en');
$es = bakery_lang_catalog('es');
foreach ([
    'formula_structure.section_title',
    'formula_structure.dough_loss',
    'formula_structure.dough_loss_hint',
    'formula_structure.batch_multiplier',
    'formula_structure.folded_title',
    'formula_structure.starter_folded_note',
    'formula_structure.topping_title',
    'formula_structure.grams_per_piece',
    'formula_structure.total_flour',
    'formula_structure.total_water',
] as $key) {
    fs_assert_true(isset($en[$key]) && $en[$key] !== '', "en has $key");
    fs_assert_true(isset($es[$key]) && $es[$key] !== '', "es has $key");
}
fs_assert_true(strpos($en['formula_structure.dough_loss_hint'], '50') !== false, 'English loss hint states 50 g');
fs_assert_true(strpos($es['formula_structure.dough_loss_hint'], '50') !== false, 'Spanish loss hint states 50 g');

echo "\n=== Schema gap and wiring ===\n";
$migration = $root . '/database/schema/087_formula_structure.sql';
fs_assert_true(is_file($migration), 'migration 087 exists');
$migrationSql = is_file($migration) ? (string) file_get_contents($migration) : '';
fs_assert_true(strpos($migrationSql, '086_ingredient_prices.sql') !== false, 'migration 087 leaves 086 for ingredient prices');
$structureSrc = (string) file_get_contents($root . '/includes/formula_structure.php');
fs_assert_true(strpos($structureSrc, 'bakery_ingredient_current_cost_per_kg(') === false, 'per-piece grams helper does not call ingredient cost');
fs_assert_true(strpos($migrationSql, 'dough_loss_grams') !== false, 'migration adds dough_loss_grams');
fs_assert_true(strpos($migrationSql, 'batch_multiplier') !== false, 'migration adds batch_multiplier');
fs_assert_true(strpos($migrationSql, 'standard_batch_pieces') !== false, 'migration adds standard_batch_pieces');
fs_assert_true(strpos($migrationSql, 'formula_subformulas') !== false, 'migration adds formula_subformulas');
fs_assert_true(strpos($migrationSql, 'grams_per_piece') !== false, 'migration adds grams_per_piece');
$plannerSrc = (string) file_get_contents($root . '/includes/ingredient_requirements.php');
fs_assert_true(strpos($plannerSrc, 'bakery_formula_requirement_scale') !== false, 'ingredient planner uses formula structure scale');
$mixSrc = (string) file_get_contents($root . '/includes/baker_mix.php');
fs_assert_true(strpos($mixSrc, 'formula_structure') !== false, 'mix sheet helper reads formula structure');
$formulasSrc = (string) file_get_contents($root . '/formulas.php');
fs_assert_true(strpos($formulasSrc, 'bakery_formula_structure_render_card') !== false, 'formulas page renders the structure section');
$mapSrc = (string) file_get_contents($root . '/includes/agent_work_map.php');
fs_assert_true(strpos($mapSrc, 'run_formula_structure_tests.php') !== false, 'work map registers the formula structure suite');

echo "\n=== Summary ===\n";
echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
