<?php
/**
 * Read-only dough readout: hydration, preferment, target mix, implied pieces.
 * Baker proposals U2 and U3. No database.
 * Fixture numbers are test inputs only — nothing here is a recipe to store.
 * Guayaba and Concha percentages are not in git. The cases below reproduce
 * the published piece counts (Guayaba about 314 vs 500; Concha 344.7 vs 340,
 * which U3's summary rounds to 345). Barra's stated 100 has no piece weight,
 * so that check stays "not enough data".
 *
 * Usage: php tests/run_formula_readout_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
require_once $root . '/includes/formula_readout.php';
require_once $root . '/includes/formula_structure_ui.php';
require_once $root . '/includes/i18n.php';

$pass = 0;
$fail = 0;

function fr_assert_true($condition, $message)
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

function fr_assert_near($expected, $actual, $message, $epsilon = 0.001)
{
    fr_assert_true(
        $actual !== null && abs((float) $expected - (float) $actual) <= $epsilon,
        $message . ' (expected=' . $expected . ' actual=' . var_export($actual, true) . ')'
    );
}

function fr_line(array $rows, $ingredientId)
{
    foreach ($rows as $row) {
        if ((int) ($row['ingredient_id'] ?? 0) === (int) $ingredientId) {
            return $row;
        }
    }
    return null;
}

echo "=== GET piece count is a number, not a write ===\n";
fr_assert_true(bakery_formula_readout_query_pieces(null) === null, 'missing pieces stay unset');
fr_assert_true(bakery_formula_readout_query_pieces('') === null, 'blank pieces stay unset');
fr_assert_true(bakery_formula_readout_query_pieces('0') === null, 'zero pieces are not a mix');
fr_assert_true(bakery_formula_readout_query_pieces('-4') === null, 'negative pieces are ignored');
fr_assert_true(bakery_formula_readout_query_pieces('nope') === null, 'text pieces are ignored');
fr_assert_true(bakery_formula_readout_query_pieces(['12']) === null, 'array pieces are ignored');
fr_assert_near(12.5, bakery_formula_readout_query_pieces('12.5'), 'a positive piece count is kept');
fr_assert_near(40, bakery_formula_readout_query_pieces(40), 'a numeric piece count is kept');

$math = (string) file_get_contents($root . '/includes/formula_readout.php');
fr_assert_true(strpos($math, 'PDO') === false, 'readout math does not take a database');
fr_assert_true(stripos($math, 'INSERT') === false, 'readout math does not insert');
fr_assert_true(stripos($math, 'UPDATE') === false, 'readout math does not update');
fr_assert_true(stripos($math, 'DELETE') === false, 'readout math does not delete');
fr_assert_true(strpos($math, '$_POST') === false, 'readout math does not read POST');
fr_assert_true(strpos($math, '$_GET') === false, 'readout math does not read the request');

echo "\n=== Egg counts as 75% water; milk counts in full; dry milk does not ===\n";
$enriched = [
    ['ingredient_id' => 1, 'ingredient_name' => 'Bread Flour', 'unit' => 'g', 'percentage' => 100],
    ['ingredient_id' => 2, 'ingredient_name' => 'Water', 'unit' => 'g', 'percentage' => 40],
    ['ingredient_id' => 5, 'ingredient_name' => 'Milk', 'unit' => 'g', 'percentage' => 20],
    ['ingredient_id' => 7, 'ingredient_name' => 'Eggs', 'unit' => 'g', 'percentage' => 20],
    ['ingredient_id' => 8, 'ingredient_name' => 'Oil', 'unit' => 'g', 'percentage' => 10],
    ['ingredient_id' => 3, 'ingredient_name' => 'Salt', 'unit' => 'g', 'percentage' => 2],
];
$egg = bakery_formula_readout($enriched, [], [
    'piece_weight_grams' => null,
    'pieces' => null,
]);
fr_assert_true($egg['hydration']['enough'] === true, 'enriched formula has hydration');
fr_assert_near(75.0, $egg['hydration']['percent'], 'hydration is water + milk + 75% of egg over flour');
fr_assert_near(40.0, $egg['hydration']['water_grams'], 'water grams are the water line');
fr_assert_near(20.0, $egg['hydration']['milk_grams'], 'milk grams count in full');
fr_assert_near(20.0, $egg['hydration']['egg_grams'], 'egg grams are the egg line before the 75% factor');
fr_assert_true(abs((float) $egg['hydration']['percent'] - 80.0) > 1, 'full egg weight is not used as water');
fr_assert_near(0.0, $egg['pff']['percent'], 'no preferment means 0% prefermented flour');
fr_assert_near(0.0, $egg['preferment_share']['percent'], 'no preferment means 0% of dough weight');

$powder = bakery_formula_readout([
    ['ingredient_id' => 1, 'ingredient_name' => 'Bread Flour', 'unit' => 'g', 'percentage' => 100],
    ['ingredient_id' => 2, 'ingredient_name' => 'Water', 'unit' => 'g', 'percentage' => 40],
    ['ingredient_id' => 5, 'ingredient_name' => 'Milk Powder', 'unit' => 'g', 'percentage' => 20],
], [], ['piece_weight_grams' => null, 'pieces' => null]);
fr_assert_near(40.0, $powder['hydration']['percent'], 'milk powder is not liquid milk');
fr_assert_near(0.0, $powder['hydration']['milk_grams'], 'milk powder grams stay out of the milk total');

echo "\n=== Starter folds once: flour and water are not counted twice ===\n";
$country = [
    ['ingredient_id' => 1, 'ingredient_name' => 'Bread Flour', 'unit' => 'g', 'percentage' => 100],
    ['ingredient_id' => 2, 'ingredient_name' => 'Water', 'unit' => 'g', 'percentage' => 70],
    ['ingredient_id' => 6, 'ingredient_name' => 'Starter', 'unit' => 'g', 'percentage' => 20],
    ['ingredient_id' => 3, 'ingredient_name' => 'Salt', 'unit' => 'g', 'percentage' => 2],
];
$starter = [[
    'name' => 'Test starter',
    'replaces_ingredient_id' => 6,
    'hydration_percent' => 100,
    'lines' => [
        ['ingredient_id' => 1, 'ingredient_name' => 'Bread Flour', 'unit' => 'g', 'line_role' => 'flour', 'percentage' => 100],
        ['ingredient_id' => 2, 'ingredient_name' => 'Water', 'unit' => 'g', 'line_role' => 'water', 'percentage' => null],
    ],
]];
$folded = bakery_formula_readout($country, ['starters' => $starter, 'dough_loss_grams' => 0], [
    'piece_weight_grams' => 192,
    'pieces' => 10,
]);
fr_assert_true($folded['starter_incomplete'] === false, 'complete starter folds');
fr_assert_near(72.727272, $folded['hydration']['percent'], 'folded hydration uses starter water once', 0.01);
fr_assert_near(9.090909, $folded['pff']['percent'], 'prefermented flour is starter flour over total flour', 0.01);
fr_assert_near(10.416667, $folded['preferment_share']['percent'], 'preferment share is starter weight over dough', 0.01);
fr_assert_true($folded['mix']['enough'] === true, 'target mix has a piece count and a piece weight');
fr_assert_near(1920, $folded['mix']['dough_grams'], '10 × 192 g + 0 g loss = 1920 g dough');
fr_assert_near(1000, $folded['mix']['direct_flour_grams'], 'flour to add excludes the starter flour');
fr_assert_near(700, $folded['mix']['direct_water_grams'], 'water to add excludes the starter water');
fr_assert_near(200, $folded['mix']['starter_grams'], 'starter is weighed as one lump');
fr_assert_near(20, $folded['mix']['salt_grams'], 'salt stays on its own line');
$bowl = 0.0;
foreach ($folded['mix']['lines'] as $row) {
    $bowl += (float) $row['grams'];
}
fr_assert_near(1920, $bowl, 'bowl lines sum to the dough and do not add starter flour again');
fr_assert_true(fr_line($folded['mix']['lines'], 6)['kind'] === 'starter', 'starter line stays a starter, not flour');
$double = (float) $folded['mix']['direct_flour_grams'] + (float) $folded['mix']['starter_grams'];
fr_assert_true($double < 1300, 'direct flour is not the folded total plus the starter');

$poolish = [
    ['ingredient_id' => 1, 'ingredient_name' => 'Bread Flour', 'unit' => 'g', 'percentage' => 100],
    ['ingredient_id' => 2, 'ingredient_name' => 'Water', 'unit' => 'g', 'percentage' => 40],
    [
        'ingredient_id' => 9,
        'ingredient_name' => 'Poolish',
        'unit' => 'g',
        'percentage' => 20,
        'subformula' => [
            'dough_type_id' => 99,
            'lines' => [
                ['ingredient_id' => 1, 'ingredient_name' => 'Bread Flour', 'unit' => 'g', 'percentage' => 100],
                ['ingredient_id' => 5, 'ingredient_name' => 'Milk', 'unit' => 'g', 'percentage' => 100],
            ],
        ],
    ],
];
$preferment = bakery_formula_readout($poolish, [], ['piece_weight_grams' => null, 'pieces' => null]);
fr_assert_near(10.0, $preferment['hydration']['milk_grams'], 'milk inside a preferment is counted once, on the formula scale');
fr_assert_true(abs((float) $preferment['hydration']['milk_grams'] - 20.0) > 1, 'preferment milk is not counted twice');
fr_assert_near(45.454545, $preferment['hydration']['percent'], 'preferment milk joins hydration over total flour', 0.01);
fr_assert_near(9.090909, $preferment['pff']['percent'], 'poolish flour is prefermented flour', 0.01);
fr_assert_near(12.5, $preferment['preferment_share']['percent'], 'poolish is 20 of 160 dough weight');

echo "\n=== Missing data stays blank (Barra: 100 pieces and no unit) ===\n";
$empty = bakery_formula_readout([], [], ['piece_weight_grams' => 55, 'pieces' => 10]);
fr_assert_true($empty['hydration']['enough'] === false, 'empty formula has no hydration');
fr_assert_true($empty['pff']['enough'] === false, 'empty formula has no prefermented flour');
fr_assert_true($empty['preferment_share']['enough'] === false, 'empty formula has no preferment share');
fr_assert_true($empty['mix']['enough'] === false, 'empty formula has no mix lines');
fr_assert_true($empty['implied']['enough'] === false, 'empty formula has no implied pieces');
fr_assert_true($empty['hydration']['percent'] === null, 'missing hydration is null, not zero');

$noWeight = bakery_formula_readout($enriched, [
    'pieces_per_batch' => 40,
    'standard_batch_dough_grams' => 8000,
    'dough_loss_grams' => 50,
], ['piece_weight_grams' => null, 'pieces' => 40]);
fr_assert_true($noWeight['mix']['dough_enough'] === false, 'no piece weight means no dough total');
fr_assert_true($noWeight['mix']['enough'] === false, 'no piece weight means no mix grams');
fr_assert_true($noWeight['implied']['enough'] === false, 'no piece weight means no implied pieces');
fr_assert_true($noWeight['hydration']['enough'] === true, 'hydration does not need a batch');

$noBatch = bakery_formula_readout($enriched, [], [
    'piece_weight_grams' => 55,
    'pieces' => null,
]);
fr_assert_true($noBatch['mix']['enough'] === false, 'no pieces and no entered batch means no mix');
fr_assert_true($noBatch['implied']['enough'] === false, 'no flour basis means no implied pieces');
fr_assert_true($noBatch['implied']['entered_pieces'] === null, 'no entered batch stays null');

$incomplete = bakery_formula_readout($country, ['starters' => [[
    'replaces_ingredient_id' => 6,
    'hydration_percent' => null,
    'lines' => [
        ['ingredient_id' => 1, 'ingredient_name' => 'Bread Flour', 'unit' => 'g', 'line_role' => 'flour', 'percentage' => null],
    ],
]]], [
    'piece_weight_grams' => 55,
    'pieces' => 10,
    'flour_grams' => 1000,
]);
fr_assert_true($incomplete['starter_incomplete'] === true, 'starter without hydration is incomplete');
fr_assert_true($incomplete['hydration']['enough'] === false, 'incomplete starter does not invent hydration');
fr_assert_true($incomplete['pff']['enough'] === false, 'incomplete starter does not invent prefermented flour');
fr_assert_true($incomplete['mix']['enough'] === false, 'incomplete starter does not invent direct flour');
fr_assert_true($incomplete['hydration']['percent'] === null, 'incomplete hydration is not a number');

echo "\n=== 2% flag is strict: exactly 2% is not amber, just over is ===\n";
$onEdge = bakery_formula_readout_flag(510.0, 500.0);
fr_assert_true($onEdge['enough'] === true, 'both piece counts can be compared');
fr_assert_near(0.02, $onEdge['divergence'], '510 vs 500 is exactly 2%');
fr_assert_true($onEdge['flag'] === false, 'exactly 2% is not flagged');
$over = bakery_formula_readout_flag(510.1, 500.0);
fr_assert_true($over['flag'] === true, 'just over 2% is flagged');
$under = bakery_formula_readout_flag(509.9, 500.0);
fr_assert_true($under['flag'] === false, 'just under 2% is not flagged');
$lowEdge = bakery_formula_readout_flag(490.0, 500.0);
fr_assert_near(0.02, $lowEdge['divergence'], '490 vs 500 is exactly 2% under');
fr_assert_true($lowEdge['flag'] === false, 'exactly 2% under is not flagged');
fr_assert_true(bakery_formula_readout_flag(510.1, 0.0)['enough'] === false, 'entered zero is not a percent');
fr_assert_true(bakery_formula_readout_flag(null, 500.0)['flag'] === false, 'missing implied pieces do not flag');

$edgeFormula = [
    ['ingredient_id' => 1, 'ingredient_name' => 'Bread Flour', 'unit' => 'g', 'percentage' => 100],
    ['ingredient_id' => 2, 'ingredient_name' => 'Water', 'unit' => 'g', 'percentage' => 60],
];
$edgeReadout = bakery_formula_readout($edgeFormula, [
    'batch_size_mode' => 'grams',
    'standard_batch_dough_grams' => 28100,
    'pieces_per_batch' => 500,
    'dough_loss_grams' => 50,
], ['piece_weight_grams' => 55, 'pieces' => null]);
fr_assert_near(510.0, $edgeReadout['implied']['pieces'], 'edge batch implies 510 pieces');
fr_assert_near(500.0, $edgeReadout['implied']['entered_pieces'], 'entered batch is the stated 500');
fr_assert_true($edgeReadout['implied']['flag'] === false, 'full readout does not flag an exact 2% gap');

echo "\n=== Guayaba: 17 lb flour implies about 314 pieces at 55 g, not 500 ===\n";
$guayaba = [
    ['ingredient_id' => 1, 'ingredient_name' => 'Bread Flour', 'unit' => 'g', 'percentage' => 100],
    ['ingredient_id' => 2, 'ingredient_name' => 'Water', 'unit' => 'g', 'percentage' => 48],
    ['ingredient_id' => 4, 'ingredient_name' => 'Sugar', 'unit' => 'g', 'percentage' => 30],
    ['ingredient_id' => 8, 'ingredient_name' => 'Shortening', 'unit' => 'g', 'percentage' => 25],
    ['ingredient_id' => 7, 'ingredient_name' => 'Eggs', 'unit' => 'g', 'percentage' => 12],
    ['ingredient_id' => 5, 'ingredient_name' => 'Milk', 'unit' => 'g', 'percentage' => 6],
    ['ingredient_id' => 3, 'ingredient_name' => 'Salt', 'unit' => 'g', 'percentage' => 2],
    ['ingredient_id' => 10, 'ingredient_name' => 'Yeast', 'unit' => 'g', 'percentage' => 1],
];
$guayabaFlour = 17 * BAKERY_GRAMS_PER_LB;
$guayabaFromFlour = bakery_formula_readout($guayaba, [
    'batch_size_mode' => 'pieces',
    'standard_batch_pieces' => 500,
    'dough_loss_grams' => 50,
], [
    'piece_weight_grams' => 55,
    'pieces' => null,
    'flour_grams' => $guayabaFlour,
]);
fr_assert_true(abs((float) $guayabaFromFlour['implied']['pieces'] - 314) < 1.5, 'Guayaba at 17 lb flour implies about 314 pieces');
fr_assert_near(500, $guayabaFromFlour['implied']['entered_pieces'], 'Guayaba entered batch is 500');
fr_assert_true($guayabaFromFlour['implied']['flag'] === true, 'Guayaba 314 vs 500 is past 2%');
$guayabaDough = $guayabaFlour * 2.24;
$guayabaFromDough = bakery_formula_readout($guayaba, [
    'batch_size_mode' => 'grams',
    'standard_batch_dough_grams' => $guayabaDough,
    'pieces_per_batch' => 500,
    'dough_loss_grams' => 50,
], ['piece_weight_grams' => 55, 'pieces' => null]);
fr_assert_near(
    $guayabaFromFlour['implied']['pieces'],
    $guayabaFromDough['implied']['pieces'],
    'a dough batch built from 17 lb flour implies the same pieces',
    0.05
);
fr_assert_true($guayabaFromDough['loss_unset'] === false, 'saved 50 g loss is used');

echo "\n=== Concha: implied 344.7 vs 340 stated stays inside 2% ===\n";
$concha = [
    ['ingredient_id' => 1, 'ingredient_name' => 'Bread Flour', 'unit' => 'g', 'percentage' => 100],
    ['ingredient_id' => 2, 'ingredient_name' => 'Water', 'unit' => 'g', 'percentage' => 80],
    ['ingredient_id' => 4, 'ingredient_name' => 'Sugar', 'unit' => 'g', 'percentage' => 60],
    ['ingredient_id' => 8, 'ingredient_name' => 'Butter', 'unit' => 'g', 'percentage' => 50],
    ['ingredient_id' => 7, 'ingredient_name' => 'Eggs', 'unit' => 'g', 'percentage' => 30],
    ['ingredient_id' => 5, 'ingredient_name' => 'Milk', 'unit' => 'g', 'percentage' => 23],
    ['ingredient_id' => 3, 'ingredient_name' => 'Salt', 'unit' => 'g', 'percentage' => 2],
    ['ingredient_id' => 10, 'ingredient_name' => 'Yeast', 'unit' => 'g', 'percentage' => 0.2],
];
$conchaReadout = bakery_formula_readout($concha, [
    'batch_size_mode' => 'grams',
    'standard_batch_dough_grams' => 34520,
    'pieces_per_batch' => 340,
    'dough_loss_grams' => 50,
], ['piece_weight_grams' => 100, 'pieces' => null]);
fr_assert_near(344.7, $conchaReadout['implied']['pieces'], 'Concha implies 344.7 pieces', 0.05);
fr_assert_near(340, $conchaReadout['implied']['entered_pieces'], 'Concha entered batch is 340');
fr_assert_true($conchaReadout['implied']['flag'] === false, 'Concha 344.7 vs 340 is inside 2%');
fr_assert_true($conchaReadout['implied']['divergence'] !== null && $conchaReadout['implied']['divergence'] < 0.02, 'Concha divergence is under 2%');

echo "\n=== Blank loss counts as 0 g and a GET piece count overrides the batch ===\n";
$override = bakery_formula_readout($enriched, [
    'pieces_per_batch' => 40,
    'dough_loss_grams' => null,
], ['piece_weight_grams' => 50, 'pieces' => 8]);
fr_assert_true($override['loss_unset'] === true, 'blank dough loss is unset');
fr_assert_near(0, $override['loss_grams'], 'blank dough loss counts as 0 g');
fr_assert_near(400, $override['mix']['dough_grams'], '8 × 50 g + 0 g = 400 g dough');
fr_assert_near(40, $override['implied']['entered_pieces'], 'entered batch stays 40 while the mix uses 8');

echo "\n=== Card markup is read-only, amber only past 2%, and touch sized ===\n";
function fr_render(array $lines, array $structure, array $products, $mixPieces): string
{
    ob_start();
    bakery_formula_readout_render(7, $lines, $structure, $products, $mixPieces);
    return (string) ob_get_clean();
}

$missingHtml = fr_render([], [], [], null);
fr_assert_true(strpos($missingHtml, 'Not enough data') !== false, 'empty card says not enough data');
fr_assert_true(strpos($missingHtml, 'method="get"') !== false, 'mix form is GET');
fr_assert_true(strpos($missingHtml, 'name="mix_pieces"') !== false, 'mix form sends pieces');
fr_assert_true(strpos($missingHtml, 'name="action"') === false, 'mix form has no write action');
fr_assert_true(stripos($missingHtml, 'csrf') === false, 'mix form has no CSRF field');
fr_assert_true(strpos($missingHtml, 'formula-readout-flag') === false, 'missing data is not an amber flag');

$guayabaHtml = fr_render($guayaba, [
    'batch_size_mode' => 'grams',
    'standard_batch_dough_grams' => $guayabaDough,
    'pieces_per_batch' => 500,
    'dough_loss_grams' => 50,
], [['id' => 3, 'name' => 'Guayaba', 'weight_grams' => 55]], null);
fr_assert_true(strpos($guayabaHtml, 'formula-readout-flag') !== false, 'Guayaba card flags the piece gap');
fr_assert_true(strpos($guayabaHtml, 'Off by more than 2%') !== false, 'the flag is text, not color alone');
fr_assert_true(strpos($guayabaHtml, 'Guayaba') !== false, 'the card names the product');

$conchaHtml = fr_render($concha, [
    'batch_size_mode' => 'grams',
    'standard_batch_dough_grams' => 34520,
    'pieces_per_batch' => 340,
    'dough_loss_grams' => 50,
], [['id' => 4, 'name' => 'Concha', 'weight_grams' => 100]], null);
fr_assert_true(strpos($conchaHtml, '344.7') !== false, 'Concha card shows 344.7 implied pieces');
fr_assert_true(strpos($conchaHtml, 'formula-readout-flag') === false, 'Concha card is not amber');

$page = (string) file_get_contents($root . '/formulas.php');
fr_assert_true(strpos($page, 'bakery_formula_readout_query_pieces') !== false, 'formulas page reads mix pieces from the query');
fr_assert_true(strpos($page, 'bakery_formula_readout_render') !== false || strpos($page, 'bakery_formula_structure_render_card') !== false, 'formulas page still renders the dough card');
$postAt = strpos($page, "REQUEST_METHOD'] === 'POST'");
$getAt = strpos($page, 'bakery_formula_readout_query_pieces');
fr_assert_true($postAt !== false && $getAt !== false && $getAt > $postAt, 'mix pieces are read with the view, outside the POST writes');
fr_assert_true(strpos($page, 'min-height: var(--sf-touch-min, 44px)') !== false, 'readout controls use the 44px touch minimum');

$map = (string) file_get_contents($root . '/includes/agent_work_map.php');
fr_assert_true(strpos($map, 'run_formula_readout_tests.php') !== false, 'work map registers the readout suite');

$en = bakery_lang_catalog('en');
$es = bakery_lang_catalog('es');
foreach ([
    'formula_readout.title',
    'formula_readout.lead',
    'formula_readout.hydration',
    'formula_readout.pff',
    'formula_readout.preferment_share',
    'formula_readout.mix_title',
    'formula_readout.mix_pieces',
    'formula_readout.mix_show',
    'formula_readout.direct_flour',
    'formula_readout.direct_water',
    'formula_readout.starter',
    'formula_readout.salt',
    'formula_readout.implied_pieces',
    'formula_readout.entered_pieces',
    'formula_readout.flag',
    'formula_readout.not_enough',
    'formula_readout.loss_unset',
] as $key) {
    fr_assert_true(isset($en[$key]) && $en[$key] !== '', "en has $key");
    fr_assert_true(isset($es[$key]) && $es[$key] !== '', "es has $key");
}
fr_assert_true($en['formula_readout.not_enough'] === 'Not enough data', 'English missing-data copy is exact');
fr_assert_true($es['formula_readout.not_enough'] === 'No hay datos suficientes', 'Spanish missing-data copy is set');

echo "\n=== Summary ===\n";
echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
