<?php
/**
 * Set the day: standard order, autosave and undo, shape split, lock.
 * Usage: php tests/run_baker_set_tests.php
 * Local bakerysf_test only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/baker_set.php';
require_once $root . '/includes/i18n.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: tests must run with APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $msg) use (&$pass, &$fail): void {
    if ($ok) {
        echo "PASS  $msg\n";
        $pass++;
    } else {
        echo "FAIL  $msg\n";
        $fail++;
    }
};

$assert(bakery_baker_set_default_date('2026-11-19') === '2026-11-20', 'default date is tomorrow, one sell day');
$assert(bakery_baker_set_default_date('2026-11-19') === bakery_next_sell_date('2026-11-19'), 'default date matches the sell-day helper');
$friday = '2026-11-20';
$assert(bakery_baker_set_screen_dates($friday) === [$friday], 'Friday is one sell day and does not roll up Saturday through Monday');
$assert(bakery_baker_set_step_date($friday, 1) === '2026-11-21', 'the next step is the next single day');
$assert(bakery_baker_set_step_date($friday, -1) === '2026-11-19', 'the previous step is the previous single day');

$_SESSION['user_id'] = 1;
$_SESSION['user_role_slug'] = 'cashier';
$assert(!bakery_baker_set_can_edit(), 'cashier cannot edit');
$_SESSION['user_role_slug'] = 'sales';
$assert(!bakery_baker_set_can_edit(), 'sales cannot edit');
$_SESSION['user_role_slug'] = 'baker';
$assert(bakery_baker_set_can_edit() && !bakery_baker_set_can_unlock(), 'baker can edit and does not unlock every day');
$_SESSION['user_role_slug'] = 'manager';
$assert(bakery_baker_set_can_edit() && bakery_baker_set_can_unlock(), 'manager can edit and unlock');
$_SESSION['user_role_slug'] = 'administrator';

$mantecada = [
    'batch_size_mode' => 'grams',
    'standard_batch_dough_grams' => 22062,
    'batch_multiplier' => 1.5,
    'pieces_per_batch' => null,
    'dough_loss_grams' => null,
];
$mantecadaIngredients = [
    ['name' => 'Bread Flour', 'percentage' => 100],
    ['name' => 'Sugar', 'percentage' => 91.67],
    ['name' => 'Milk', 'percentage' => 71.67],
    ['name' => 'Eggs', 'percentage' => 72.08],
    ['name' => 'Oil', 'percentage' => 64.17],
    ['name' => 'Baking Powder', 'percentage' => 5.73],
];
$mantecadaTotal = 405.32;
$mantecadaShapes = [
    ['product_id' => 1, 'name' => 'Barras', 'quantity' => 25, 'weight_grams' => 662, 'demand_quantity' => 20],
    ['product_id' => 2, 'name' => 'Quequitos', 'quantity' => 200, 'weight_grams' => 83, 'demand_quantity' => 200],
];
$shape = bakery_baker_set_shape_math($mantecadaShapes, $mantecada, $mantecadaTotal, $mantecadaIngredients);
$pieceGrams = 33150.0;
$doughWithLoss = $pieceGrams + 50;
$flourBasis = $doughWithLoss * 100 / $mantecadaTotal;
$sharedPull = bakery_baker_mix_percentage_amounts($flourBasis, $mantecadaIngredients);
$assert(abs((float) $shape['effective_batch_grams'] - 33093) < 0.001, 'Mantecada batch is 1.5 x 22062 g = 33093 g');
$assert(abs((float) $shape['dough_grams'] - $doughWithLoss) < 0.001, 'blank dough loss adds 50 g once');
$assert($shape['loss_is_default'] === true, 'a blank loss field uses the 50 g default');
$assert(abs((float) $shape['usable_grams'] - $pieceGrams) < 0.001, 'usable dough is the piece weight before the 50 g loss');
$assert(abs((float) $shape['batches'] - ($pieceGrams / 33093)) < 0.0001, 'batches divide usable dough, not the mix total');
$assert(abs((float) $shape['ingredients'][0]['grams'] - (float) $sharedPull[0]['grams']) < 0.001, 'ingredient pull uses the mix percentage helper');
$assert(abs((float) $sharedPull[0]['grams'] - $flourBasis) < 0.02, 'flour grams follow the formula total');
$assert($shape['hide_piece_yield'] === true, 'a Barra on the card hides the per-batch piece count');
$assert($shape['balances'][0]['balance'] === 'over', 'Barras over the order is marked over');
$assert($shape['balances'][1]['balance'] === 'match', 'Quequitos on the order is a match');
if (function_exists('bakery_set_locale')) {
    bakery_set_locale('en', false);
}
$card = bakery_baker_set_dough_card([
    'dough_type_id' => 1,
    'dough_type_name' => 'Mantecada',
    'shape' => $shape,
    'formula' => ['total_percentage' => $mantecadaTotal],
]);
$assert($card['batches'] === '1 batch + 57 g', 'the mixed card is 1 batch + 57 g');
$assert($card['barras_flag'] === 'Barras per batch unconfirmed', 'the Barra card says the per-batch count is unconfirmed');

$plain = bakery_formula_resolve_batch_grams([
    'standard_batch_dough_grams' => 22062,
    'batch_multiplier' => null,
]);
$assert(abs((float) $plain['grams'] - 22062) < 0.001 && $plain['conflict'] === false, 'a blank multiplier counts as 1');
$scaled = bakery_formula_resolve_batch_grams($mantecada);
$assert(abs((float) $scaled['grams'] - 33093) < 0.001 && $scaled['conflict'] === false, '22062 x 1.5 stays 33093');
$double = bakery_formula_resolve_batch_grams([
    'standard_batch_dough_grams' => 33093,
    'batch_multiplier' => 1.5,
    'batch_size_mode' => 'grams',
]);
$assert($double['conflict'] === true && abs((float) $double['grams'] - 33093) < 0.001, '33093 g plus a 1.5 multiplier is not scaled again');

$bagelPct = [
    'flour' => 100.0,
    'water' => 2141 / 4109 * 100,
    'starter' => 748 / 4109 * 100,
    'liquido' => 374 / 4109 * 100,
    'salt' => 103 / 4109 * 100,
];
$bagelTotal = array_sum($bagelPct);
$bagel = bakery_baker_set_shape_math(
    [['product_id' => 10, 'name' => 'Bagel', 'quantity' => 55, 'weight_grams' => 135, 'demand_quantity' => 55]],
    ['standard_batch_dough_grams' => 22062, 'batch_multiplier' => null, 'dough_loss_grams' => null],
    $bagelTotal,
    [
        ['name' => 'High gluten', 'percentage' => $bagelPct['flour']],
        ['name' => 'Water', 'percentage' => $bagelPct['water']],
        ['name' => 'Starter', 'percentage' => $bagelPct['starter']],
        ['name' => 'Liquido', 'percentage' => $bagelPct['liquido']],
        ['name' => 'Salt', 'percentage' => $bagelPct['salt']],
    ]
);
$assert(abs((float) $bagel['dough_grams'] - 7475) < 0.01, '55 bagels are 7475 g with the 50 g loss');
$bagelByName = [];
foreach ($bagel['ingredients'] as $row) {
    $bagelByName[$row['name']] = (float) $row['grams'];
}
$assert(abs($bagelByName['High gluten'] - 4109) < 0.6, 'bagel flour is 4109 g');
$assert(abs($bagelByName['Water'] - 2141) < 0.6, 'bagel water is 2141 g');
$assert(abs($bagelByName['Starter'] - 748) < 0.6, 'bagel starter is 748 g');
$assert(abs($bagelByName['Liquido'] - 374) < 0.6, 'bagel liquido is 374 g');
$assert(abs($bagelByName['Salt'] - 103) < 0.6, 'bagel salt is 103 g');

$conchaTotal = 15350 / 7271 * 100;
$concha = bakery_baker_set_shape_math(
    [['product_id' => 11, 'name' => 'Concha', 'quantity' => 340, 'weight_grams' => 45, 'demand_quantity' => 340]],
    [
        'standard_batch_dough_grams' => 22062,
        'batch_multiplier' => 1.5,
        'dough_loss_grams' => null,
    ],
    $conchaTotal,
    [
        ['name' => 'Flour', 'percentage' => 100],
        ['name' => 'Rest', 'percentage' => $conchaTotal - 100],
    ]
);
$assert(abs((float) $concha['dough_grams'] - 15350) < 0.01, '340 conchas are 15350 g with the 50 g loss');
$assert(abs((float) $concha['ingredients'][0]['grams'] - 7271) < 0.6, 'concha flour is 7271 g');
$conchaWeight = bakery_baker_set_shape_math(
    [['product_id' => 16, 'name' => 'Concha', 'quantity' => 1, 'weight_grams' => 45, 'demand_quantity' => 1]],
    [
        'standard_batch_dough_grams' => 22062,
        'batch_multiplier' => 1.5,
        'pieces_per_batch' => 96,
        'dough_loss_grams' => 0,
    ],
    0,
    []
);
$conchaCheck = $conchaWeight['weight_checks'][0] ?? [];
$assert(!empty($conchaCheck['mismatch']) && abs((float) $conchaCheck['formula_grams'] - (33093 / 96)) < 0.01, 'pieces per batch flags 33093/96 g against the 45 g catalog');

$conchaReal = bakery_baker_set_shape_math(
    [[
        'product_id' => 45,
        'name' => 'Conchas',
        'quantity' => 1,
        'weight_grams' => 45,
        'weight_unit' => 'gallon',
        'pack_yield' => [
            'input_unit' => 'gallon',
            'trays_per_gallon' => 14.666667,
            'pieces_per_tray' => 20,
            'pieces_per_input' => null,
        ],
    ]],
    [
        'standard_batch_dough_grams' => null,
        'pieces_per_batch' => null,
        'standard_batch_pieces' => null,
        'dough_loss_grams' => 0,
    ],
    0,
    []
);
$assert(abs((float) $conchaReal['dough_grams'] - 45) < 0.01, 'Conchas catalog 45 g still counts; gallon is the order unit');
$assert(($conchaReal['weight_checks'] ?? []) === [], 'Concha gallon geometry has no gram piece weight, so it does not warn');
$assert(bakery_baker_set_implied_piece_grams([
    'standard_batch_dough_grams' => null,
    'pieces_per_batch' => null,
], [
    'name' => 'Conchas',
    'weight_grams' => 45,
    'pack_yield' => ['input_unit' => 'gallon', 'trays_per_gallon' => 14.666667, 'pieces_per_tray' => 20],
]) === null, 'Concha formula piece weight is not stored');

$conchaYield = bakery_baker_set_shape_math(
    [['product_id' => 46, 'name' => 'Yield piece', 'quantity' => 1, 'weight_grams' => 45, 'demand_quantity' => 1]],
    [
        'standard_batch_dough_grams' => 4500,
        'pieces_per_batch' => null,
        'standard_batch_pieces' => 50,
        'dough_loss_grams' => 0,
    ],
    0,
    []
);
$conchaYieldCheck = $conchaYield['weight_checks'][0] ?? [];
$assert(!empty($conchaYieldCheck['mismatch']) && abs((float) $conchaYieldCheck['formula_grams'] - 90) < 0.01, 'yield pieces without pieces_per_batch still warn: 4500 g / 50 = 90 g against catalog 45 g');

$barras = bakery_baker_set_shape_math(
    [['product_id' => 3, 'name' => 'Barras', 'quantity' => 50, 'weight_grams' => 662, 'demand_quantity' => 50]],
    [
        'batch_size_mode' => 'grams',
        'standard_batch_dough_grams' => 22062,
        'batch_multiplier' => 1.5,
        'pieces_per_batch' => 100,
        'dough_loss_grams' => null,
    ],
    100,
    [['name' => 'Flour', 'percentage' => 100]]
);
$assert(abs((float) $barras['dough_grams'] - 33150) < 0.01, '50 barras are catalog weight plus 50 g loss');
$assert(abs((float) $barras['usable_grams'] - 33100) < 0.01, '50 barras of usable dough are 33100 g');
$assert($barras['batch_mode'] === 'grams' && $barras['hide_piece_yield'] === true, 'barra batches come from grams, not 100 per batch');
$assert($barras['pieces_per_batch'] === null, 'the barra card does not publish a per-batch piece count');
$assert(abs((float) $barras['effective_batch_grams'] - 33093) < 0.001, 'one scaled barra batch is 33093 g before the 50 g loss');
$assert(abs(33093 + 50 - 33143) < 0.001, '33093 g plus the 50 g loss is 33143 g, a batch-size check only');
$barrasCard = bakery_baker_set_dough_card([
    'dough_type_id' => 1,
    'dough_type_name' => 'Mantecada',
    'shape' => $barras,
]);
$assert($barrasCard['batches'] === '1 batch + 7 g', '50 barras are 1 batch + 7 g');
$assert($barrasCard['barras_flag'] === 'Barras per batch unconfirmed', '50 barras still flag that the per-batch count is unconfirmed');

$exact = bakery_baker_set_shape_math(
    [['product_id' => 20, 'name' => 'Exact piece', 'quantity' => 11031, 'weight_grams' => 3, 'demand_quantity' => 11031]],
    [
        'batch_size_mode' => 'grams',
        'standard_batch_dough_grams' => 22062,
        'batch_multiplier' => 1.5,
        'dough_loss_grams' => null,
    ],
    100,
    [['name' => 'Flour', 'percentage' => 100]]
);
$exactCard = bakery_baker_set_dough_card([
    'dough_type_id' => 1,
    'dough_type_name' => 'Mantecada',
    'shape' => $exact,
]);
$assert(abs((float) $exact['usable_grams'] - 33093) < 0.001, 'an exact usable mix is 33093 g');
$assert(abs((float) $exact['dough_grams'] - 33143) < 0.001, 'the exact mix card still adds the 50 g loss');
$assert($exactCard['batches'] === '1 batch', 'an exact 33093 g usable mix is 1 batch with no remainder');

$mondo = bakery_baker_set_shape_math(
    [['product_id' => 12, 'name' => 'Mondo', 'quantity' => 1, 'weight_grams' => 2000, 'demand_quantity' => 1]],
    ['standard_batch_dough_grams' => 2000, 'batch_multiplier' => null, 'dough_loss_grams' => null],
    100,
    [['name' => 'Flour', 'percentage' => 100]]
);
$assert(abs((float) $mondo['dough_grams'] - 2050) < 0.01, 'Mondo is 2050 g with the 50 g loss');

$explicitZero = bakery_baker_set_shape_math(
    [['product_id' => 13, 'name' => 'Mondo', 'quantity' => 1, 'weight_grams' => 2000, 'demand_quantity' => 1]],
    ['standard_batch_dough_grams' => 2000, 'dough_loss_grams' => 0],
    100,
    []
);
$assert(abs((float) $explicitZero['dough_grams'] - 2000) < 0.01 && $explicitZero['loss_grams'] === 0.0, 'a saved loss of 0 adds nothing');

$pack = bakery_baker_set_shape_math(
    [['product_id' => 14, 'name' => 'Tray Pack', 'quantity' => 4, 'weight_grams' => 600, 'weight_unit' => 'tray', 'demand_quantity' => 4]],
    ['standard_batch_dough_grams' => 22062, 'dough_loss_grams' => null],
    100,
    []
);
$assert($pack['dough_grams'] === null && $pack['pack_weights'] === ['Tray Pack'], 'a tray unit is not a piece weight');
foreach ([null, '', 'pc'] as $dinnerUnit) {
    $dinner = bakery_baker_set_shape_math(
        [[
            'product_id' => 14,
            'name' => 'Dinner Roll',
            'quantity' => 4,
            'weight_grams' => 600,
            'weight_unit' => $dinnerUnit,
            'demand_quantity' => 4,
        ]],
        ['standard_batch_dough_grams' => 7200, 'dough_loss_grams' => null],
        100,
        []
    );
    $unitLabel = $dinnerUnit === null ? 'missing' : ($dinnerUnit === '' ? 'blank' : $dinnerUnit);
    $assert(abs((float) $dinner['usable_grams'] - 1800) < 0.01 && abs((float) $dinner['dough_grams'] - 1850) < 0.01, 'Dinner Roll counts 450 g per order when the unit is ' . $unitLabel);
    $assert($dinner['pack_weights'] === [], 'Dinner Roll is not left out as an unknown pack when the unit is ' . $unitLabel);
}
$zwDinner = bakery_baker_set_shape_math(
    [['product_id' => 23, 'name' => 'ZW Dinner Roll', 'quantity' => 4, 'weight_grams' => 600, 'demand_quantity' => 4]],
    ['standard_batch_dough_grams' => 7200, 'dough_loss_grams' => 0],
    100,
    []
);
$assert($zwDinner['dough_grams'] === null && $zwDinner['pack_weights'] === ['ZW Dinner Roll'], 'a Dinner Roll name with no pack row is not a piece');
$loaf = bakery_baker_set_shape_math(
    [['product_id' => 21, 'name' => 'Country Loaf', 'quantity' => 2, 'weight_grams' => 700, 'weight_unit' => '', 'demand_quantity' => 2]],
    ['standard_batch_dough_grams' => 700, 'dough_loss_grams' => 0],
    100,
    []
);
$assert(abs((float) $loaf['dough_grams'] - 1400) < 0.01, 'a 700 g loaf with a blank unit is still a piece');
$pieceUnitHeavy = bakery_baker_set_shape_math(
    [['product_id' => 22, 'name' => 'Bulk dough', 'quantity' => 1, 'weight_grams' => 2600, 'weight_unit' => 'pc', 'demand_quantity' => 1]],
    ['standard_batch_dough_grams' => 2600, 'dough_loss_grams' => 0],
    100,
    []
);
$assert(abs((float) $pieceUnitHeavy['dough_grams'] - 2600) < 0.01 && $pieceUnitHeavy['pack_weights'] === [], 'a pc unit stays a piece above 2500 g');
$heavyYield = bakery_baker_set_shape_math(
    [[
        'product_id' => 24,
        'name' => 'Quequitos',
        'quantity' => 1,
        'weight_grams' => 2600,
        'weight_unit' => 'tray',
        'pieces_per_input' => 20,
        'demand_quantity' => 1,
    ]],
    ['standard_batch_dough_grams' => 2600, 'dough_loss_grams' => 0],
    100,
    []
);
$assert(abs((float) $heavyYield['dough_grams'] - 2600) < 0.01 && $heavyYield['pack_weights'] === [], 'a tray yield of more than one piece beats the 2500 g fallback');
$huge = bakery_baker_set_shape_math(
    [['product_id' => 25, 'name' => 'Bulk dough', 'quantity' => 1, 'weight_grams' => 2600, 'demand_quantity' => 1]],
    ['standard_batch_dough_grams' => 2600, 'dough_loss_grams' => 0],
    100,
    []
);
$assert($huge['dough_grams'] === null && $huge['pack_weights'] === ['Bulk dough'], 'above 2500 g is a pack only when the unit and yield row do not decide');
$rollTotal = 175.2;
$rollIngredients = [
    ['name' => 'Bread Flour', 'percentage' => 100],
    ['name' => 'Water', 'percentage' => 58],
    ['name' => 'Salt', 'percentage' => 2],
    ['name' => 'Instant Yeast', 'percentage' => 1.2],
    ['name' => 'Sugar', 'percentage' => 2],
    ['name' => 'Lard', 'percentage' => 4],
    ['name' => 'Milk', 'percentage' => 8],
];
$mixedPack = bakery_baker_set_shape_math(
    [
        ['product_id' => 30, 'name' => 'Bolillo', 'quantity' => 80, 'weight_grams' => 90, 'demand_quantity' => 80],
        ['product_id' => 14, 'name' => 'Dinner Roll', 'quantity' => 4, 'weight_grams' => 600, 'weight_unit' => 'pc', 'demand_quantity' => 4],
    ],
    ['standard_batch_dough_grams' => 7200, 'dough_loss_grams' => null],
    $rollTotal,
    $rollIngredients
);
$mixedFlour = 9050 * 100 / $rollTotal;
$catalogPackFlour = (7200 + (4 * 600) + 50) * 100 / $rollTotal;
$mixedCard = bakery_baker_set_dough_card([
    'dough_type_id' => 2,
    'dough_type_name' => 'Dinner Rolls',
    'shape' => $mixedPack,
]);
$assert(abs((float) $mixedPack['usable_grams'] - 9000) < 0.01, '80 Bolillo plus 4 Dinner Rolls is 7200 + 1800 = 9000 g usable');
$assert(abs((float) $mixedPack['dough_grams'] - 9050) < 0.01, 'the Dinner Roll card total is 9050 g');
$assert($mixedPack['pack_weights'] === [], 'Dinner Roll is counted at 450 g and is not an unknown pack');
$assert(bakery_baker_set_count_pack_grams() === false, 'the generic pack switch stays off');
if (function_exists('bakery_set_locale')) {
    bakery_set_locale('en', false);
}
$assert($mixedCard['dinner_roll_note'] === '450 g per pack (6 x 75 g), catalog says 600 g', 'Dinner Roll notes 450 g per pack against the 600 g catalog');
$assert($mixedCard['pack_warnings'] === [] && $mixedCard['pack_short'] === '', 'Dinner Roll does not say the grams were left out');
if (function_exists('bakery_set_locale')) {
    bakery_set_locale('es', false);
    $spanishCard = bakery_baker_set_dough_card([
        'dough_type_id' => 2,
        'dough_type_name' => 'Dinner Rolls',
        'shape' => $mixedPack,
    ]);
    $assert($spanishCard['dinner_roll_note'] === '450 g por paquete (6 x 75 g), el catálogo dice 600 g', 'Spanish notes 450 g per pack against the catalog');
    bakery_set_locale('en', false);
}
$assert($mixedCard['batches'] === '1 batch + 1,800 g', '80 Bolillo plus 4 Dinner Rolls is 1 batch + 1,800 g');
$assert(abs((float) $mixedPack['ingredients'][0]['grams'] - $mixedFlour) < 0.05, 'the flour pull follows the 9050 g card');
$assert(abs((float) $mixedPack['ingredients'][0]['grams'] - $catalogPackFlour) > 1, 'the flour pull does not use the 600 g catalog pack weight');
$countedPack = bakery_baker_set_shape_math(
    [
        ['product_id' => 30, 'name' => 'Bolillo', 'quantity' => 80, 'weight_grams' => 90, 'demand_quantity' => 80],
        ['product_id' => 31, 'name' => 'Party Pack', 'quantity' => 4, 'weight_grams' => 600, 'weight_unit' => 'pack', 'demand_quantity' => 4],
    ],
    ['standard_batch_dough_grams' => 7200, 'dough_loss_grams' => null],
    $rollTotal,
    $rollIngredients,
    true
);
$countedCard = bakery_baker_set_dough_card([
    'dough_type_id' => 2,
    'dough_type_name' => 'Dinner Rolls',
    'shape' => $countedPack,
]);
$assert(abs((float) $countedPack['usable_grams'] - 9600) < 0.01, 'with the generic pack switch on, a 600 g pack is counted');
$assert($countedCard['pack_warnings'] === [] && $countedCard['pack_short'] === '', 'a counted pack does not say the grams were left out');
$trayPiece = bakery_baker_set_shape_math(
    [[
        'product_id' => 2,
        'name' => 'Quequitos',
        'quantity' => 20,
        'weight_grams' => 83,
        'weight_unit' => 'tray',
        'pieces_per_input' => 20,
        'demand_quantity' => 20,
    ]],
    ['standard_batch_dough_grams' => 22062, 'batch_multiplier' => 1.5, 'dough_loss_grams' => 0],
    100,
    []
);
$assert(abs((float) $trayPiece['dough_grams'] - 1660) < 0.01, 'a tray yield of 20 still uses the 83 g piece weight');

$guayabaShape = [
    'product_id' => 15,
    'name' => 'Guayaba',
    'weight_grams' => null,
    'pack_yield' => [
        'input_unit' => 'gallon',
        'pieces_per_input' => 80,
        'trays_per_gallon' => null,
        'pieces_per_tray' => null,
    ],
];
$guayabaStructure = [
    'standard_batch_dough_grams' => null,
    'pieces_per_batch' => null,
    'standard_batch_pieces' => null,
];
$assert(bakery_baker_set_implied_piece_grams($guayabaStructure, $guayabaShape) === null, 'Guayaba stores 80 pieces per gallon and no gram piece weight');

$catalogSql = "SELECT p.name, p.weight_grams, y.input_unit, y.pieces_per_input, y.trays_per_gallon, y.pieces_per_tray,
        dt.name AS dough_name, dt.standard_batch_dough_grams, dt.standard_batch_pieces, dt.pieces_per_batch, dt.batch_multiplier
    FROM products p
    LEFT JOIN product_pack_yields y ON y.product_id = p.id
    LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
    WHERE p.name LIKE '%Concha%' OR p.name LIKE '%Guayaba%' OR p.name LIKE '%Dinner Roll%'
    ORDER BY p.name";
$catalogRows = $db->query($catalogSql)->fetchAll(PDO::FETCH_ASSOC);
$conchaLive = 'no Concha product row in this database';
$guayabaLive = 'no Guayaba product row in this database';
foreach ($catalogRows as $row) {
    $structure = [
        'standard_batch_dough_grams' => $row['standard_batch_dough_grams'],
        'standard_batch_pieces' => $row['standard_batch_pieces'],
        'pieces_per_batch' => $row['pieces_per_batch'],
        'batch_multiplier' => $row['batch_multiplier'],
    ];
    $shapeRow = [
        'name' => (string) $row['name'],
        'weight_grams' => $row['weight_grams'],
        'weight_unit' => $row['input_unit'],
        'pack_yield' => [
            'input_unit' => $row['input_unit'],
            'pieces_per_input' => $row['pieces_per_input'],
            'trays_per_gallon' => $row['trays_per_gallon'],
            'pieces_per_tray' => $row['pieces_per_tray'],
        ],
    ];
    $implied = bakery_baker_set_implied_piece_grams($structure, $shapeRow);
    $catalogGrams = is_numeric($row['weight_grams']) ? (float) $row['weight_grams'] : null;
    $fires = $implied !== null && $catalogGrams !== null && $catalogGrams > 0
        && (abs($catalogGrams - $implied) >= 4 || (abs($catalogGrams - $implied) / $implied) >= 0.01);
    $line = $row['name'] . ' catalog=' . ($catalogGrams === null ? 'none' : (string) $catalogGrams)
        . ' formula=' . ($implied === null ? 'none' : (string) round($implied, 2))
        . ' warn=' . ($fires ? 'yes' : 'no');
    echo "NOTE  $line\n";
    if (stripos((string) $row['name'], 'concha') !== false) {
        $conchaLive = $line;
    }
    if (stripos((string) $row['name'], 'guayaba') !== false) {
        $guayabaLive = $line;
    }
}
$doughProbe = $db->query("SELECT name, standard_batch_dough_grams, standard_batch_pieces, pieces_per_batch
    FROM dough_types
    WHERE name IN ('Concha', 'Conchas', 'Fino', 'Guayaba', 'Dinner Rolls')
    ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
foreach ($doughProbe as $dough) {
    echo 'NOTE  dough ' . $dough['name']
        . ' batch_grams=' . ($dough['standard_batch_dough_grams'] === null ? 'none' : (string) $dough['standard_batch_dough_grams'])
        . ' pieces=' . ($dough['standard_batch_pieces'] === null ? 'none' : (string) $dough['standard_batch_pieces'])
        . ' per_batch=' . ($dough['pieces_per_batch'] === null ? 'none' : (string) $dough['pieces_per_batch']) . "\n";
}
$assert($catalogRows !== false, 'catalog probe ran: Concha [' . $conchaLive . '] Guayaba [' . $guayabaLive . ']');

$missing = bakery_baker_set_shape_math(
    [['product_id' => 4, 'name' => 'Mystery', 'quantity' => 10, 'weight_grams' => null, 'demand_quantity' => 10]],
    ['batch_size_mode' => 'grams', 'standard_batch_dough_grams' => 33093, 'batch_multiplier' => null],
    100,
    [['name' => 'Bread Flour', 'percentage' => 100]]
);
$assert($missing['kg'] === null && $missing['dough_grams'] === null && $missing['batches'] === null, 'missing piece weight does not invent grams or batches');
$assert($missing['missing_weights'] !== [], 'missing piece weight is reported');

$page = (string) file_get_contents($root . '/baker_set.php');
$css = (string) file_get_contents($root . '/css/baker_set.css');
$js = (string) file_get_contents($root . '/includes/baker_set.js');
$manifest = (string) file_get_contents($root . '/scripts/deploy_manifest.ps1');
$segments = (string) file_get_contents($root . '/includes/kitchen_segments.php');
$assert(strpos($page, 'inputmode="numeric"') !== false, 'quantity uses a numeric keyboard');
$assert(strpos($page, 'name="action" value="save"') === false, 'there is no separate Save action');
$assert(strpos($page, 'bakery_production_cadence_bake_date_for_delivery') === false, 'the screen does not combine Friday with Saturday through Monday');
$assert(strpos($page, 'baker_set.orders') !== false, 'the default is labeled Orders');
$assert(bakery_baker_set_order_source('daily') && bakery_baker_set_order_source('standing'), 'dated orders and standing orders are the default');
$assert(!bakery_baker_set_order_source('pan_dulce_standard') && !bakery_baker_set_order_source('retail') && !bakery_baker_set_order_source('par'), 'par and retail quantities are not a fallback');
$assert(strpos($page, 'value="lock"') !== false, 'Done locks the day');
$assert(strpos($page, 'value="undo"') !== false, 'undo is one control');
$assert(strpos($js, 'localStorage') !== false, 'saves queue in localStorage');
$assert(strpos($js, 'ajax=revision') !== false, 'the screen polls for a stale plan');
$assert(strpos($css, '48px') !== false, 'touch targets are at least 48px');
$assert(strpos($manifest, "'baker_set.php'") !== false, 'deploy whitelist includes baker_set.php');
$assert(strpos($segments, 'baker_set.php') !== false, 'kitchen nav includes Set');
$assert(in_array('baker_set.php', bakery_baker_scripts(), true), 'bakers can open Set the day');
$assert(in_array('baker_set.php', bakery_navigation_scripts_for_role('driver'), true), 'drivers can open Set the day to view');
$assert(in_array('baker_set.php', bakery_navigation_scripts_for_role('cashier'), true), 'cashiers can open Set the day to view');
$assert(strpos((string) file_get_contents($root . '/products.php'), 'baker_set') === false, 'products.php is untouched');

$en = include $root . '/lang/en.php';
$es = include $root . '/lang/es.php';
$keys = [
    'page.baker_set',
    'nav.item.baker_set',
    'nav.item_desc.baker_set',
    'kitchen.segment_set',
    'baker_set.title',
    'baker_set.saved',
    'baker_set.undo',
    'baker_set.stale',
    'baker_set.queued',
    'baker_set.lock',
    'baker_set.unlock',
    'baker_set.copy_last',
    'baker_set.reset',
    'baker_set.orders',
    'baker_set.short',
    'baker_set.over',
    'baker_set.batch_scaled',
    'baker_set.per_batch',
    'baker_set.missing_weight',
    'baker_set.pack_excluded',
    'baker_set.pack_short',
    'baker_set.dinner_roll_pack',
    'baker_set.barras_unconfirmed',
    'baker_set.view_only',
];
foreach ($keys as $key) {
    $assert(isset($en[$key], $es[$key]) && $en[$key] !== '' && $es[$key] !== '', "en and es have $key");
    $assert($en[$key] !== $es[$key], "es translates $key");
}

$stamp = 'bs' . substr(str_replace('.', '', uniqid('', true)), -8);
$date = '2099-06-07';
$previous = '2099-05-31';
$day = bakery_standing_day_from_date($date);
$userIds = [];
$customerIds = [];
$productIds = [];
$doughIds = [];
$lineIds = [];
$ingredientIds = [];

$cleanup = static function () use ($db, &$userIds, &$customerIds, &$productIds, &$doughIds, &$lineIds, &$ingredientIds, $date, $previous): void {
    $entity = (int) str_replace('-', '', $date);
    $prevEntity = (int) str_replace('-', '', $previous);
    if (table_exists($db, 'field_audit')) {
        $db->prepare('DELETE FROM field_audit WHERE entity_type = ? AND entity_id IN (?, ?)')->execute(['baker_set', $entity, $prevEntity]);
    }
    foreach ([$date, $previous] as $when) {
        if (table_exists($db, 'production_plan_commit_items')) {
            $db->prepare('DELETE FROM production_plan_commit_items WHERE delivery_date = ?')->execute([$when]);
        }
        if (table_exists($db, 'production_plan_commits')) {
            $db->prepare('DELETE FROM production_plan_commits WHERE delivery_date = ?')->execute([$when]);
        }
        if (table_exists($db, 'production_plan_items')) {
            $db->prepare('DELETE FROM production_plan_items WHERE delivery_date = ?')->execute([$when]);
        }
        $db->prepare('DELETE FROM daily_order_items WHERE daily_order_id IN (SELECT id FROM daily_orders WHERE order_date = ?)')->execute([$when]);
        $db->prepare('DELETE FROM daily_orders WHERE order_date = ?')->execute([$when]);
    }
    if ($customerIds !== []) {
        $in = implode(',', array_map('intval', $customerIds));
        $db->exec('DELETE FROM standing_orders WHERE customer_id IN (' . $in . ')');
        $db->exec('DELETE FROM daily_order_items WHERE daily_order_id IN (SELECT id FROM daily_orders WHERE customer_id IN (' . $in . '))');
        $db->exec('DELETE FROM daily_orders WHERE customer_id IN (' . $in . ')');
        $db->exec('DELETE FROM customers WHERE id IN (' . $in . ')');
    }
    if ($productIds !== []) {
        $in = implode(',', array_map('intval', $productIds));
        if (table_exists($db, 'formula_ingredients')) {
            $db->exec('DELETE FROM formula_ingredients WHERE dough_type_id IN (SELECT dough_type_id FROM products WHERE id IN (' . $in . '))');
        }
        $db->exec('DELETE FROM production_plan_items WHERE product_id IN (' . $in . ')');
        $db->exec('DELETE FROM products WHERE id IN (' . $in . ')');
    }
    if ($doughIds !== []) {
        $in = implode(',', array_map('intval', $doughIds));
        $db->exec('DELETE FROM formula_ingredients WHERE dough_type_id IN (' . $in . ')');
        $db->exec('DELETE FROM dough_types WHERE id IN (' . $in . ')');
    }
    if ($lineIds !== []) {
        $in = implode(',', array_map('intval', $lineIds));
        $db->exec('DELETE FROM product_lines WHERE id IN (' . $in . ')');
    }
    if ($ingredientIds !== []) {
        $in = implode(',', array_map('intval', $ingredientIds));
        $db->exec('DELETE FROM ingredients WHERE id IN (' . $in . ')');
    }
    if ($userIds !== []) {
        $in = implode(',', array_map('intval', $userIds));
        $db->exec('DELETE FROM users WHERE id IN (' . $in . ')');
    }
};

try {
    $cleanup();
    $adminRole = (int) $db->query("SELECT id FROM roles WHERE slug = 'administrator' LIMIT 1")->fetchColumn();
    $assert($adminRole > 0, 'administrator role exists');
    $insertUser = $db->prepare(
        'INSERT INTO users (email, password_hash, login_code, display_name, role_id, is_active) VALUES (?, ?, NULL, ?, ?, 1)'
    );
    $insertUser->execute([$stamp . '@example.test', 'x', $stamp, $adminRole]);
    $adminId = (int) $db->lastInsertId();
    $userIds[] = $adminId;
    $_SESSION['user_id'] = $adminId;
    $_SESSION['user_role_slug'] = 'administrator';
    $_SESSION['user_display_name'] = $stamp;

    $insertCustomer = $db->prepare(
        'INSERT INTO customers (name, zone, email, address, phone, payment_collection, is_active, pricing_tier)
         VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
    );
    $insertCustomer->execute([$stamp . ' dated', 'North Zone', $stamp . '-a@example.test', '1 Test', '555-0100', 'signature', 'retail']);
    $datedCustomer = (int) $db->lastInsertId();
    $customerIds[] = $datedCustomer;
    $insertCustomer->execute([$stamp . ' standing', 'North Zone', $stamp . '-b@example.test', '2 Test', '555-0101', 'signature', 'retail']);
    $standingCustomer = (int) $db->lastInsertId();
    $customerIds[] = $standingCustomer;

    $insertLine = $db->prepare('INSERT INTO product_lines (name) VALUES (?)');
    $insertLine->execute([$stamp . ' line']);
    $lineId = (int) $db->lastInsertId();
    $lineIds[] = $lineId;
    $insertDough = $db->prepare('INSERT INTO dough_types (name, product_line_id) VALUES (?, ?)');
    $insertDough->execute([$stamp . ' dough', $lineId]);
    $doughId = (int) $db->lastInsertId();
    $doughIds[] = $doughId;
    if (function_exists('column_exists') && column_exists($db, 'dough_types', 'standard_batch_dough_grams')) {
        $sets = ['standard_batch_dough_grams = 22062'];
        if (column_exists($db, 'dough_types', 'batch_multiplier')) {
            $sets[] = 'batch_multiplier = 1.5';
        }
        if (column_exists($db, 'dough_types', 'batch_size_mode')) {
            $sets[] = "batch_size_mode = 'grams'";
        }
        $db->exec('UPDATE dough_types SET ' . implode(', ', $sets) . ' WHERE id = ' . $doughId);
    }

    $insertIngredient = $db->prepare('INSERT INTO ingredients (name, unit) VALUES (?, ?)');
    $insertFormula = $db->prepare('INSERT INTO formula_ingredients (dough_type_id, ingredient_id, percentage) VALUES (?, ?, ?)');
    foreach ([['Flour ' . $stamp, 100], ['Water ' . $stamp, 70], ['Salt ' . $stamp, 2], ['Levain ' . $stamp, 20]] as $ingredient) {
        $insertIngredient->execute([$ingredient[0], 'g']);
        $ingredientId = (int) $db->lastInsertId();
        $ingredientIds[] = $ingredientId;
        $insertFormula->execute([$doughId, $ingredientId, $ingredient[1]]);
    }

    $insertProduct = $db->prepare('INSERT INTO products (name, dough_type_id, price, wholesale_price, weight_grams) VALUES (?, ?, 0, NULL, ?)');
    $insertProduct->execute([$stamp . ' barras', $doughId, 662]);
    $barrasId = (int) $db->lastInsertId();
    $productIds[] = $barrasId;
    $insertProduct->execute([$stamp . ' rolls', $doughId, 83]);
    $rollsId = (int) $db->lastInsertId();
    $productIds[] = $rollsId;
    $insertProduct->execute([$stamp . ' zero', $doughId, 50]);
    $zeroId = (int) $db->lastInsertId();
    $productIds[] = $zeroId;

    $db->prepare('INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, ?)')
        ->execute([$datedCustomer, $barrasId, $day, 4]);
    $db->prepare('INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, ?)')
        ->execute([$standingCustomer, $rollsId, $day, 3]);
    $db->prepare('INSERT INTO daily_orders (customer_id, order_date, status) VALUES (?, ?, ?)')
        ->execute([$datedCustomer, $date, 'pending']);
    $orderId = (int) $db->lastInsertId();
    $item = $db->prepare('INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, ?, 0, 0)');
    $item->execute([$orderId, $barrasId, 9]);
    $item->execute([$orderId, $zeroId, 0]);

    $standard = bakery_baker_set_standard_order($db, $date);
    $assert(($standard['by_product'][$barrasId] ?? null) === 9, 'dated order beats standing for the same customer');
    $assert(($standard['by_product'][$rollsId] ?? null) === 3, 'standing fills a customer with no dated order');
    $assert(array_key_exists($zeroId, $standard['by_product']) && $standard['by_product'][$zeroId] === 0, 'an explicit zero line still shows');
    $expectedLines = 0;
    foreach (bakery_operating_demand_lines($db, $date) as $line) {
        $source = (string) ($line['source'] ?? '');
        if (($source === 'daily' || $source === 'standing') && (int) ($line['quantity'] ?? 0) > 0) {
            $expectedLines++;
        }
    }
    $assert($standard['real_order_lines'] === $expectedLines, 'line count includes real dated and standing lines only');
    $assert($expectedLines >= 2, 'the fixture has at least two real order lines');

    $sheet = bakery_baker_set_sheet($db, $date, null);
    $foundZero = false;
    foreach ($sheet['batches'] as $batch) {
        foreach ($batch['products'] as $product) {
            if ((int) $product['product_id'] === $zeroId && (int) $product['planned_quantity'] === 0) {
                $foundZero = true;
            }
        }
    }
    $assert($foundZero, 'the sheet keeps the zero line');

    $saved = bakery_baker_set_save_quantity($db, $date, $barrasId, 10, false, 0, $adminId, null);
    $assert(($saved['patch']['quantity'] ?? null) === 10, 'autosave stores the tapped quantity');
    $assert(bakery_production_plan_draft_quantities($db, $date)[$barrasId] === 10, 'draft plan has the autosaved quantity');
    $conflicted = false;
    try {
        bakery_baker_set_save_quantity($db, $date, $barrasId, 11, false, 0, $adminId, null);
    } catch (RuntimeException $e) {
        $conflicted = str_starts_with($e->getMessage(), 'production_plan_conflict:');
    }
    $assert($conflicted, 'a stale tab cannot overwrite a newer quantity');
    $undone = bakery_baker_set_undo($db, $date, $adminId, null);
    $assert(($undone['patch']['quantity'] ?? null) === 9, 'undo restores the previous quantity');
    $audit = $db->prepare('SELECT actor_user_id FROM field_audit WHERE entity_type = ? AND entity_id = ? AND field = ? ORDER BY id ASC LIMIT 1');
    $audit->execute(['baker_set', (int) str_replace('-', '', $date), 'qty:' . $barrasId]);
    $assert((int) $audit->fetchColumn() === $adminId, 'the change log stores the user');

    $db->prepare('INSERT INTO production_plan_items (delivery_date, product_id, planned_quantity, created_by_user_id) VALUES (?, ?, ?, ?)')
        ->execute([$previous, $barrasId, 42, $adminId]);
    bakery_baker_set_copy_previous($db, $date, $adminId, null);
    $assert((bakery_production_plan_draft_quantities($db, $date)[$barrasId] ?? null) === 42, 'copy last weekday brings that plan forward');

    bakery_baker_set_reset($db, $date, $adminId, null);
    $assert(!array_key_exists($barrasId, bakery_production_plan_draft_quantities($db, $date)), 'reset clears the draft back to the standard order');

    bakery_baker_set_save_quantity($db, $date, $barrasId, 10, false, 0, $adminId, null);
    bakery_baker_set_lock($db, $date, $adminId);
    $bake = bakery_production_bake_list($db, $date);
    $baked = null;
    foreach ($bake['items'] as $itemRow) {
        if ((int) $itemRow['product_id'] === $barrasId) {
            $baked = $itemRow;
        }
    }
    $assert($bake['committed'] === true && $baked !== null && (int) $baked['bake_quantity'] === 10, 'lock commits the plan onto the bake list');

    $_SESSION['user_id'] = $adminId + 100000;
    $_SESSION['user_role_slug'] = 'baker';
    $bakerBlocked = false;
    try {
        bakery_baker_set_unlock($db, $date);
    } catch (RuntimeException $e) {
        $bakerBlocked = $e->getMessage() === 'baker_set_forbidden';
    }
    $assert($bakerBlocked, 'a baker cannot unlock a day someone else marked Done');
    $assert(bakery_baker_set_is_locked($db, $date), 'a refused unlock leaves the day locked');

    $_SESSION['user_role_slug'] = 'sales';
    $salesBlocked = false;
    try {
        bakery_baker_set_unlock($db, $date);
    } catch (RuntimeException $e) {
        $salesBlocked = $e->getMessage() === 'baker_set_forbidden';
    }
    $assert($salesBlocked && bakery_baker_set_is_locked($db, $date), 'sales cannot unlock');

    $_SESSION['user_id'] = $adminId;
    $_SESSION['user_role_slug'] = 'administrator';
    bakery_baker_set_unlock($db, $date);
    $adminUnlock = $db->prepare("SELECT actor_user_id, new_value, created_at FROM field_audit WHERE entity_type = ? AND entity_id = ? AND action = 'unlock' ORDER BY id DESC LIMIT 1");
    $adminUnlock->execute(['baker_set', (int) str_replace('-', '', $date)]);
    $adminUnlockRow = $adminUnlock->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($adminUnlockRow) && (int) $adminUnlockRow['actor_user_id'] === $adminId && strpos((string) $adminUnlockRow['new_value'], 'role:administrator') !== false && (string) $adminUnlockRow['created_at'] !== '', 'an admin unlock is logged with who, when, and role');
    $open = bakery_production_bake_list($db, $date);
    $opened = null;
    foreach ($open['items'] as $itemRow) {
        if ((int) $itemRow['product_id'] === $barrasId) {
            $opened = $itemRow;
        }
    }
    $assert($open['committed'] === false && $opened !== null && (int) $opened['bake_quantity'] === 9, 'unlock returns Mix and Daily Production to demand');

    $roleId = static function (PDO $db, string $slug): int {
        return (int) $db->query("SELECT id FROM roles WHERE slug = " . $db->quote($slug) . " LIMIT 1")->fetchColumn();
    };
    $bakerRole = $roleId($db, 'baker');
    $managerRole = $roleId($db, 'manager');
    $assert($bakerRole > 0 && $managerRole > 0, 'baker and manager roles exist');
    $insertUser->execute([$stamp . '-baker@example.test', 'x', $stamp . ' baker', $bakerRole]);
    $bakerId = (int) $db->lastInsertId();
    $userIds[] = $bakerId;
    $insertUser->execute([$stamp . '-baker2@example.test', 'x', $stamp . ' baker2', $bakerRole]);
    $otherBakerId = (int) $db->lastInsertId();
    $userIds[] = $otherBakerId;
    $insertUser->execute([$stamp . '-manager@example.test', 'x', $stamp . ' manager', $managerRole]);
    $managerId = (int) $db->lastInsertId();
    $userIds[] = $managerId;

    $_SESSION['user_id'] = $bakerId;
    $_SESSION['user_role_slug'] = 'baker';
    bakery_baker_set_save_quantity($db, $date, $barrasId, 8, true, 10, $bakerId, null);
    bakery_baker_set_lock($db, $date, $bakerId);
    $assert(bakery_baker_set_can_unlock_date($db, $date), 'the baker who marked Done can unlock that day');
    bakery_baker_set_unlock($db, $date);
    $bakerUnlock = $db->prepare("SELECT actor_user_id, new_value, created_at FROM field_audit WHERE entity_type = ? AND entity_id = ? AND action = 'unlock' ORDER BY id DESC LIMIT 1");
    $bakerUnlock->execute(['baker_set', (int) str_replace('-', '', $date)]);
    $bakerUnlockRow = $bakerUnlock->fetch(PDO::FETCH_ASSOC);
    $bakerLock = $db->prepare("SELECT actor_user_id, new_value, created_at FROM field_audit WHERE entity_type = ? AND entity_id = ? AND action = 'lock' ORDER BY id DESC LIMIT 1");
    $bakerLock->execute(['baker_set', (int) str_replace('-', '', $date)]);
    $bakerLockRow = $bakerLock->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($bakerUnlockRow) && (int) $bakerUnlockRow['actor_user_id'] === $bakerId && strpos((string) $bakerUnlockRow['new_value'], 'role:baker') !== false && (string) $bakerUnlockRow['created_at'] !== '', 'a baker self-unlock is logged with who, when, and role');
    $assert(is_array($bakerLockRow) && (int) $bakerLockRow['actor_user_id'] === $bakerId && strpos((string) $bakerLockRow['new_value'], 'role:baker') !== false, 'a lock is logged with who and role');
    $assert(!bakery_baker_set_is_locked($db, $date), 'the baker unlock opens the day');

    bakery_baker_set_lock($db, $date, $bakerId);
    $_SESSION['user_id'] = $otherBakerId;
    $otherBlocked = false;
    try {
        bakery_baker_set_unlock($db, $date);
    } catch (RuntimeException $e) {
        $otherBlocked = $e->getMessage() === 'baker_set_forbidden';
    }
    $assert($otherBlocked && bakery_baker_set_is_locked($db, $date), 'another baker cannot unlock that day');

    $_SESSION['user_id'] = $managerId;
    $_SESSION['user_role_slug'] = 'manager';
    $assert(bakery_baker_set_can_unlock_date($db, $date), 'a manager can unlock a day a baker marked Done');
    bakery_baker_set_unlock($db, $date);
    $managerUnlock = $db->prepare("SELECT actor_user_id, new_value FROM field_audit WHERE entity_type = ? AND entity_id = ? AND action = 'unlock' ORDER BY id DESC LIMIT 1");
    $managerUnlock->execute(['baker_set', (int) str_replace('-', '', $date)]);
    $managerUnlockRow = $managerUnlock->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($managerUnlockRow) && (int) $managerUnlockRow['actor_user_id'] === $managerId && strpos((string) $managerUnlockRow['new_value'], 'role:manager') !== false, 'a manager unlock is logged with who and role');
    $assert(!bakery_baker_set_is_locked($db, $date), 'the manager unlock opens the day');
} catch (Throwable $e) {
    echo 'FAIL  fixture crashed: ' . $e->getMessage() . "\n";
    $fail++;
} finally {
    $cleanup();
}

echo $fail === 0 ? "\n$pass passed, 0 failed\n" : "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
