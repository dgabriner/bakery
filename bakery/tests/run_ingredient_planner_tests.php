<?php
/**
 * Ingredient planner calculation tests (CLI).
 *
 * Usage: php tests/run_ingredient_planner_tests.php
 */
define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
require_once $root . '/includes/env_loader.php';
$envPath = $root . '/.env';
if (is_readable($envPath)) {
    bakery_load_env_file($envPath);
}
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/ingredient_units.php';
require_once $root . '/includes/ingredient_requirements.php';

$GLOBALS['TEST_PASS'] = 0;
$GLOBALS['TEST_FAIL'] = 0;

function assert_true($condition, $message) {
    if ($condition) {
        $GLOBALS['TEST_PASS']++;
        echo "  PASS: {$message}\n";
    } else {
        $GLOBALS['TEST_FAIL']++;
        echo "  FAIL: {$message}\n";
    }
}

function assert_eq($expected, $actual, $message) {
    assert_true($expected === $actual, $message . " (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")");
}

function assert_float_near($expected, $actual, $message, $epsilon = 0.01) {
    assert_true(abs((float)$expected - (float)$actual) <= $epsilon, $message);
}

echo "=== Ingredient unit conversion ===\n";
assert_float_near(1000.0, bakery_ingredient_unit_to_grams(1, 'kg'), '1 kg to grams');
assert_float_near(453.59237, bakery_ingredient_unit_to_grams(1, 'lb'), '1 lb to grams');
assert_float_near(1.0, bakery_ingredient_grams_to_unit(1000, 'kg'), '1000 g to kg');
assert_true(bakery_ingredient_unit_to_grams(5, 'each') === null, 'each is not converted');
assert_true(bakery_ingredient_stock_unit_comparable('kg'), 'kg comparable');
assert_true(!bakery_ingredient_stock_unit_comparable('bag'), 'bag not comparable');

echo "\n=== Batch math ===\n";
$batch = bakery_ingredient_requirements_product_batches(120, 500, 60000, 18000);
assert_true($batch['batch_reference_configured'], 'batch reference configured');
assert_float_near(36.0, $batch['reference_yield_units'], '18000g batch / 500g loaf = 36 units');
assert_float_near(3.333333, $batch['theoretical_product_batches'], '120 / 36 batches', 0.001);
assert_eq(4, $batch['suggested_whole_product_batches'], 'ceil(3.33) = 4 whole batches');

echo "\n=== Formula explosion (shared ingredient) ===\n";
$products = [
    [
        'product_id' => 1,
        'product_name' => 'Sourdough',
        'quantity' => 120,
        'weight_grams' => 500,
        'dough_type_id' => 10,
        'dough_type_name' => 'Sourdough',
        'standard_batch_dough_grams' => 18000,
        'demand_quantity' => 100,
        'quantity_basis' => 'production_plan',
        'plan_vs_demand_delta' => 20,
    ],
    [
        'product_id' => 2,
        'product_name' => 'Baguette',
        'quantity' => 60,
        'weight_grams' => 300,
        'dough_type_id' => 11,
        'dough_type_name' => 'Baguette',
        'standard_batch_dough_grams' => null,
        'demand_quantity' => 60,
        'quantity_basis' => 'production_plan',
        'plan_vs_demand_delta' => 0,
    ],
];
$formulas = [
    10 => [
        ['ingredient_id' => 1, 'ingredient_name' => 'Flour', 'unit' => 'kg', 'percentage' => 100.0],
        ['ingredient_id' => 2, 'ingredient_name' => 'Salt', 'unit' => 'g', 'percentage' => 2.2],
    ],
    11 => [
        ['ingredient_id' => 1, 'ingredient_name' => 'Flour', 'unit' => 'kg', 'percentage' => 100.0],
        ['ingredient_id' => 2, 'ingredient_name' => 'Salt', 'unit' => 'g', 'percentage' => 2.0],
    ],
];
$exploded = bakery_ingredient_requirements_explode($products, $formulas);
$flour = null;
$salt = null;
foreach ($exploded['ingredients'] as $row) {
    if ((int)$row['ingredient_id'] === 1) {
        $flour = $row;
    }
    if ((int)$row['ingredient_id'] === 2) {
        $salt = $row;
    }
}
assert_true($flour !== null, 'flour aggregated across products');
assert_float_near(76355.5, (float)$flour['required_grams'], 'flour total grams', 1.0);
assert_true(count($flour['contributors']) === 2, 'flour has two product contributors');
assert_true($salt !== null, 'salt aggregated');
assert_eq(2, (int)$exploded['totals']['products'], 'two products exploded');
assert_eq(0, (int)$exploded['totals']['exceptions'], 'no blocking config errors');
assert_true(count($exploded['exceptions']) >= 1, 'informational batch reference notice present');

echo "\n=== Stock enrichment ===\n";
$inventory = [
    1 => [
        'ingredient_id' => 1,
        'ingredient_name' => 'Flour',
        'catalogue_unit' => 'kg',
        'quantity_on_hand' => 50.0,
        'on_hand_grams' => 50000.0,
        'stock_unit_comparable' => true,
        'package_size' => 25.0,
    ],
];
$enriched = bakery_ingredient_requirements_enrich_stock([$flour], $inventory);
$row = $enriched[0];
assert_true($row['stock_trustworthy'], 'flour stock comparable');
assert_float_near(26355.5, (float)$row['shortage_grams'], 'shortage grams', 1.0);
assert_true(strpos((string)$row['suggested_purchase'], '×') !== false, 'package-based purchase suggestion');

echo "\n=== Default source ===\n";
assert_eq('plan', bakery_ingredient_requirements_resolve_source(null), 'default source is plan');
assert_eq('plan', bakery_ingredient_requirements_resolve_source(''), 'empty resolves to plan');

echo "\n=== Summary ===\n";
echo "Passed: {$GLOBALS['TEST_PASS']}\n";
echo "Failed: {$GLOBALS['TEST_FAIL']}\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
