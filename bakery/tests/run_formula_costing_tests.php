<?php
/**
 * Costing-ready formulas: batch yield, preferment expansion, package cost.
 *
 * Usage: php tests/run_formula_costing_tests.php
 * Database: bakerysf_test only. Never Staging or Live.
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
require_once $root . '/includes/schema_sql.php';
require_once $root . '/includes/hosted_migration_runtime.php';
require_once $root . '/includes/formula_structure.php';
require_once $root . '/includes/ingredient_requirements.php';
require_once $root . '/includes/ingredient_units.php';
require_once $root . '/includes/cost_margin.php';
require_once $root . '/includes/i18n.php';

$db = check_mysql_connection();
bakery_assert_local_test_target($db);
$GLOBALS['db'] = $db;

$fail = 0;
$assert = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
};
$near = static function (float $expected, $actual, string $label, float $epsilon = 0.02) use ($assert): void {
    $assert(is_numeric($actual) && abs($expected - (float) $actual) <= $epsilon, $label . " (expected=$expected actual=" . var_export($actual, true) . ')');
};

echo "=== Pieces per batch stays blank until staff fill it ===\n";
$assert(function_exists('bakery_formula_mix_batch_math'), 'mix batch math helper exists');
$blank = function_exists('bakery_formula_normalize_structure')
    ? bakery_formula_normalize_structure([])
    : [];
$assert(array_key_exists('pieces_per_batch', $blank) && $blank['pieces_per_batch'] === null, 'missing pieces per batch stay null');

$math = function_exists('bakery_formula_mix_batch_math')
    ? bakery_formula_mix_batch_math(22062.0, null, 44124.0, 200.0)
    : [];
$assert(array_key_exists('pieces_per_batch', $math) && $math['pieces_per_batch'] === null, 'null yield is not invented from piece weight');
$near(2.0, $math['theoretical_batches'] ?? null, 'gram batches stay dough grams divided by the standard batch');
$assert(array_key_exists('batches_from_pieces', $math) && $math['batches_from_pieces'] === null, 'piece batches stay blank without a yield');

$filled = function_exists('bakery_formula_mix_batch_math')
    ? bakery_formula_mix_batch_math(7200.0, 80.0, 7200.0, 160.0)
    : [];
$near(80.0, $filled['pieces_per_batch'] ?? null, 'staff yield is kept');
$near(1.0, $filled['theoretical_batches'] ?? null, 'one standard batch of dough grams');
$near(2.0, $filled['batches_from_pieces'] ?? null, 'planned pieces divided by pieces per batch');

$planner = bakery_ingredient_requirements_product_batches(80, 100, 7200.0, 7200.0, [
    'pieces_per_batch' => 80,
    'standard_batch_dough_grams' => 7200,
]);
$near(80.0, $planner['reference_yield_units'] ?? null, 'staff yield replaces grams divided by piece weight');
$near(1.0, $planner['theoretical_dough_batches'] ?? null, 'dough batches still use the gram batch');
$near(1.0, $planner['theoretical_product_batches'] ?? null, 'product batches use pieces per batch');

$inferred = bakery_ingredient_requirements_product_batches(80, 100, 7200.0, 7200.0, []);
$near(72.0, $inferred['reference_yield_units'] ?? null, 'blank yield still uses grams divided by piece weight');

echo "\n=== Preferment flour and water expand into true hydration ===\n";
$sourdough = [
    ['ingredient_id' => 1, 'ingredient_name' => 'Flour', 'unit' => 'g', 'percentage' => 100],
    ['ingredient_id' => 2, 'ingredient_name' => 'Water', 'unit' => 'g', 'percentage' => 70],
    [
        'ingredient_id' => 6,
        'ingredient_name' => 'Starter',
        'unit' => 'g',
        'percentage' => 20,
        'subformula' => [
            'dough_type_id' => 40,
            'lines' => [
                ['ingredient_id' => 1, 'ingredient_name' => 'Flour', 'unit' => 'g', 'percentage' => 100],
                ['ingredient_id' => 2, 'ingredient_name' => 'Water', 'unit' => 'g', 'percentage' => 100],
            ],
        ],
    ],
];
$folded = bakery_formula_fold_formula($sourdough, [], 190.0);
$assert(!empty($folded['folded']), 'a linked preferment folds');
$flour = (float) ($folded['total_flour_grams'] ?? 0);
$water = (float) ($folded['total_water_grams'] ?? 0);
$near(110.0, $flour, 'starter flour is added to total flour');
$near(80.0, $water, 'starter water is added to total water');
$hydration = $flour > 0 ? ($water / $flour) * 100.0 : null;
$near(72.727, $hydration, 'true hydration is total water over total flour');
$starterCounted = false;
foreach ($folded['ingredients'] as $row) {
    if ((int) ($row['ingredient_id'] ?? 0) === 6 && !empty($row['counts_in_totals'])) {
        $starterCounted = true;
    }
}
$assert($starterCounted === false, 'the preferment line is not counted twice');

$cycled = $sourdough;
$cycled[2]['subformula']['lines'][] = [
    'ingredient_id' => 6,
    'ingredient_name' => 'Starter',
    'unit' => 'g',
    'percentage' => 20,
    'subformula' => $sourdough[2]['subformula'],
];
$cycledFold = bakery_formula_fold_formula($cycled, [], 190.0);
$assert(!empty($cycledFold['folded']), 'a cycle still folds the flour and water');
$near(110.0, $cycledFold['total_flour_grams'] ?? null, 'a cycle does not multiply flour');

$quote = bakery_cost_margin_quote(
    $sourdough,
    ['starters' => [], 'standard_batch_dough_grams' => 1000, 'standard_batch_pieces' => 10, 'batch_size_mode' => 'pieces'],
    [[
        'kind' => 'topping',
        'lines' => [[
            'ingredient_id' => 9,
            'ingredient_name' => 'Concha Topping',
            'grams_per_piece' => 15,
        ]],
    ]],
    100.0,
    [1 => 2.0, 2 => 0.0, 9 => 4.0],
    3.0
);
$assert($quote['flags'] === [], 'expanded flour is priced even when the preferment has no cost');
$assert($quote['ingredient_cost'] !== null && $quote['ingredient_cost'] > 0, 'piece cost includes preferment flour and the topping');

echo "\n=== Package cost is read when invoice cost is missing ===\n";
$assert(function_exists('bakery_cost_margin_package_cost_per_kg'), 'package cost helper exists');
$package = function_exists('bakery_cost_margin_package_cost_per_kg')
    ? bakery_cost_margin_package_cost_per_kg(12.5, 5.0, 'kg')
    : null;
$near(2.5, $package, '12.50 for 5 kg is 2.50 per kg');
$assert(
    function_exists('bakery_cost_margin_package_cost_per_kg')
        && bakery_cost_margin_package_cost_per_kg(null, 5.0, 'kg') === null,
    'a blank package cost stays null'
);
$assert(
    function_exists('bakery_cost_margin_package_cost_per_kg')
        && bakery_cost_margin_package_cost_per_kg(4.0, 1.0, 'each') === null,
    'a non-mass package is not guessed into a kilogram cost'
);

echo "\n=== Migration is additive and does not seed yield ===\n";
$migrationPath = null;
foreach (glob($root . '/database/schema/*_formula_costing.sql') ?: [] as $candidate) {
    $migrationPath = $candidate;
}
$assert($migrationPath !== null, 'formula costing migration exists');
$sql = $migrationPath !== null ? (string) file_get_contents($migrationPath) : '';
$assert(strpos($sql, 'pieces_per_batch') !== false, 'migration adds pieces_per_batch');
$assert(strpos($sql, 'subformula_dough_type_id') !== false, 'migration adds the preferment link');
$assert(stripos($sql, 'INSERT') === false && stripos($sql, 'UPDATE') === false, 'migration does not seed values');
$assert(strpos($sql, '22062') === false && strpos($sql, '7200') === false, 'migration does not write Mantecada or Dinner Roll numbers');
$assert(stripos($sql, 'price_type') === false, 'migration leaves the importer price type alone');
$importer = (string) file_get_contents($root . '/scripts/import_ingredient_prices.php');
$assert(strpos($importer, 'pieces_per_batch') === false && strpos($importer, 'subformula_dough_type_id') === false, 'ingredient importer is untouched');

$doughPage = (string) file_get_contents($root . '/dough_types.php');
$formulaPage = (string) file_get_contents($root . '/formulas.php');
$formulaUi = (string) file_get_contents($root . '/includes/formula_structure_ui.php');
$mixPage = (string) file_get_contents($root . '/baker_mix.php');
$assert(strpos($doughPage, 'pieces_per_batch') !== false, 'dough types shows pieces per batch');
$assert(strpos($formulaPage . $formulaUi, 'pieces_per_batch') !== false, 'formulas shows pieces per batch');
$assert(strpos($formulaPage, 'formula_structure.true_hydration') !== false || strpos((string) file_get_contents($root . '/includes/formula_structure_ui.php'), 'formula_structure.true_hydration') !== false, 'formulas shows true hydration');
$assert(strpos($mixPage, 'batches_from_pieces') !== false || strpos((string) file_get_contents($root . '/includes/baker_mix.php'), 'batches_from_pieces') !== false, 'mix sheet uses the yield');

$en = bakery_lang_catalog('en');
$es = bakery_lang_catalog('es');
foreach ([
    'formula_structure.pieces_per_batch',
    'formula_structure.true_hydration',
    'formula_structure.subformula_link',
    'cost_margin.package_note',
] as $key) {
    $enText = (string) ($en[$key] ?? '');
    $esText = (string) ($es[$key] ?? '');
    $assert($enText !== '' && $esText !== '', "en and es have $key");
    $assert(strpos($enText, "\u{2013}") === false && strpos($enText, "\u{2014}") === false, "en $key has no dash glyph");
}

echo "\n=== bakerysf_test columns stay nullable ===\n";
$assert(strtolower((string) $db->query('SELECT DATABASE()')->fetchColumn()) === 'bakerysf_test', 'queries stay on bakerysf_test');
if ($migrationPath !== null && !column_exists($db, 'dough_types', 'pieces_per_batch')) {
    foreach (bakery_parse_sql_file($migrationPath) as $statement) {
        bakery_hosted_migration_exec_statement($db, $statement);
    }
    bakery_forget_column_exists('dough_types', 'pieces_per_batch');
    bakery_forget_column_exists('ingredients', 'subformula_dough_type_id');
}
$assert(column_exists($db, 'dough_types', 'pieces_per_batch'), 'pieces_per_batch column exists');
$assert(column_exists($db, 'ingredients', 'subformula_dough_type_id'), 'subformula link column exists');
$assert(column_exists($db, 'ingredients', 'supplier_name'), 'supplier column exists');
$assert(column_exists($db, 'ingredients', 'package_size'), 'package size column exists');
$assert(column_exists($db, 'ingredients', 'unit_cost'), 'cost column exists');

$prefix = 'zzfc-' . bin2hex(random_bytes(3)) . '-';
$cleanup = static function () use ($db, $prefix): void {
    $ingredients = $db->prepare('SELECT id FROM ingredients WHERE name LIKE ?');
    $ingredients->execute([$prefix . '%']);
    $ingredientIds = array_map('intval', $ingredients->fetchAll(PDO::FETCH_COLUMN));
    if ($ingredientIds !== [] && column_exists($db, 'ingredients', 'subformula_dough_type_id')) {
        $marks = implode(',', array_fill(0, count($ingredientIds), '?'));
        $db->prepare("UPDATE ingredients SET subformula_dough_type_id = NULL WHERE id IN ($marks)")->execute($ingredientIds);
    }
    $doughs = $db->prepare('SELECT id FROM dough_types WHERE name LIKE ?');
    $doughs->execute([$prefix . '%']);
    $doughIds = array_map('intval', $doughs->fetchAll(PDO::FETCH_COLUMN));
    if ($doughIds !== []) {
        $marks = implode(',', array_fill(0, count($doughIds), '?'));
        $db->prepare("DELETE FROM formula_ingredients WHERE dough_type_id IN ($marks)")->execute($doughIds);
        if (column_exists($db, 'ingredients', 'subformula_dough_type_id')) {
            $db->prepare("UPDATE ingredients SET subformula_dough_type_id = NULL WHERE subformula_dough_type_id IN ($marks)")->execute($doughIds);
        }
    }
    $db->prepare('DELETE FROM dough_types WHERE name LIKE ?')->execute([$prefix . '%']);
    $db->prepare('DELETE FROM ingredients WHERE name LIKE ?')->execute([$prefix . '%']);
};

try {
    $cleanup();
    $db->prepare('INSERT INTO dough_types (name, standard_batch_dough_grams) VALUES (?, ?)')
        ->execute([$prefix . 'mantecada', 22062]);
    $doughId = (int) $db->query('SELECT id FROM dough_types WHERE name = ' . $db->quote($prefix . 'mantecada'))->fetchColumn();
    $yield = column_exists($db, 'dough_types', 'pieces_per_batch')
        ? $db->query('SELECT pieces_per_batch FROM dough_types WHERE id = ' . $doughId)->fetchColumn()
        : 'missing';
    $assert($yield === null, 'a new standard batch does not receive a seeded yield');

    $db->prepare('INSERT INTO ingredients (name, unit, supplier_name, package_size, unit_cost) VALUES (?, ?, ?, ?, ?)')
        ->execute([$prefix . 'flour', 'kg', 'Mill', 10, 40]);
    $flourId = (int) $db->query('SELECT id FROM ingredients WHERE name = ' . $db->quote($prefix . 'flour'))->fetchColumn();
    $resolved = bakery_cost_margin_resolve_cost_per_kg($db, $flourId);
    $near(4.0, $resolved, 'cost view reads package size and unit cost when no invoice price exists');

    $db->prepare('INSERT INTO dough_types (name) VALUES (?)')->execute([$prefix . 'starter dough']);
    $starterDoughId = (int) $db->query('SELECT id FROM dough_types WHERE name = ' . $db->quote($prefix . 'starter dough'))->fetchColumn();
    $db->prepare('INSERT INTO ingredients (name, unit) VALUES (?, ?)')->execute([$prefix . 'starter', 'g']);
    $starterId = (int) $db->query('SELECT id FROM ingredients WHERE name = ' . $db->quote($prefix . 'starter'))->fetchColumn();
    bakery_formula_save_ingredient_subformula($db, $starterId, $starterDoughId);
    $linked = $db->query('SELECT subformula_dough_type_id FROM ingredients WHERE id = ' . $starterId)->fetchColumn();
    $assert((int) $linked === $starterDoughId, 'an ingredient can link to a dough type sub-formula');
    bakery_formula_save_ingredient_subformula($db, $starterId, null);
    $cleared = $db->query('SELECT subformula_dough_type_id FROM ingredients WHERE id = ' . $starterId)->fetchColumn();
    $assert($cleared === null, 'clearing the link stores NULL');
} finally {
    $cleanup();
}

if ($fail > 0) {
    echo "\n$fail failed\n";
    exit(1);
}
echo "\nAll formula costing tests passed\n";
exit(0);
