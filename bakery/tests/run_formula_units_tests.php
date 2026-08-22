<?php
/**
 * Formula unit conversion + i18n key tests (no database).
 *
 * Usage:
 *   php bakery/tests/run_formula_units_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
require_once $root . '/includes/formula_units.php';
require_once $root . '/includes/i18n.php';

$pass = 0;
$fail = 0;

function fu_assert_true($condition, $message)
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

function fu_assert_near($expected, $actual, $message, $epsilon = 0.0001)
{
    fu_assert_true(abs((float) $expected - (float) $actual) <= $epsilon, $message . " (expected=$expected actual=$actual)");
}

echo "=== Grams to lb / gal ===\n";
fu_assert_near(1.0, bakery_grams_to_lb(453.592), '453.592 g = 1 lb');
fu_assert_near(2.0, bakery_grams_to_lb(907.184), '907.184 g = 2 lb');
fu_assert_true(bakery_grams_to_gal(1000, 0) === null, 'zero density yields no gallons');
$waterGramsPerGal = 8.34 * 453.592;
fu_assert_near(1.0, bakery_grams_to_gal($waterGramsPerGal, 8.34), 'water density 8.34 lb/gal → 1 gal');
$milkGramsPerGal = 8.6 * 453.592;
fu_assert_near(1.0, bakery_grams_to_gal($milkGramsPerGal, 8.6), 'milk density 8.6 lb/gal → 1 gal');

echo "\n=== Ingredient classification ===\n";
$water = bakery_formula_classify_ingredient('Demo Water', 'g');
fu_assert_true($water['liquid'] === true, 'Demo Water is liquid');
fu_assert_near(8.34, $water['density_lb_per_gal'], 'Demo Water uses 8.34 lb/gal');

$agua = bakery_formula_classify_ingredient('Agua', 'g');
fu_assert_true($agua['liquid'] === true && $agua['kind'] === 'water', 'Agua is water');

$milk = bakery_formula_classify_ingredient('Whole Milk', 'g');
fu_assert_true($milk['liquid'] === true, 'Milk is liquid');
fu_assert_near(8.6, $milk['density_lb_per_gal'], 'Milk uses 8.6 lb/gal');

$oil = bakery_formula_classify_ingredient('Vegetable Oil', 'g');
fu_assert_true($oil['liquid'] === true && $oil['kind'] === 'oil', 'Oil is liquid');
fu_assert_near(7.7, $oil['density_lb_per_gal'], 'Oil uses 7.7 lb/gal');

$eggs = bakery_formula_classify_ingredient('Eggs', 'g');
fu_assert_true($eggs['liquid'] === true && $eggs['kind'] === 'eggs', 'Eggs (weight) treated as liquid measure');
fu_assert_near(8.65, $eggs['density_lb_per_gal'], 'Eggs use 8.65 lb/gal');

$eggEach = bakery_formula_classify_ingredient('Eggs', 'each');
fu_assert_true($eggEach['liquid'] === false, 'Eggs counted each are not gallons');

$flour = bakery_formula_classify_ingredient('Demo Flour', 'g');
fu_assert_true($flour['liquid'] === false, 'Flour is dry');

$butter = bakery_formula_classify_ingredient('Butter', 'g');
fu_assert_true($butter['liquid'] === false, 'Butter (fat) stays dry / lb');

$powderMilk = bakery_formula_classify_ingredient('Powdered Milk', 'g');
fu_assert_true($powderMilk['liquid'] === false, 'Powdered milk is not gallons');

$starter = bakery_formula_classify_ingredient('Starter', 'g');
fu_assert_true($starter['liquid'] === false, 'Regular starter is not gallons');

$starterL = bakery_formula_classify_ingredient('Starter Liquido', 'g');
fu_assert_true($starterL['liquid'] === true && $starterL['kind'] === 'starter_liquido', 'Starter Liquido is liquid');
fu_assert_near(8.5, $starterL['density_lb_per_gal'], 'Liquid starter uses 8.5 lb/gal');

$byUnit = bakery_formula_classify_ingredient('Unknown Sauce', 'ml');
fu_assert_true($byUnit['liquid'] === true, 'ml unit marks unknown sauce as liquid');
fu_assert_near(8.34, $byUnit['density_lb_per_gal'], 'unknown liquid defaults to water density');

echo "\n=== Amount markup ===\n";
$waterHtml = bakery_formula_amount_markup(8340, $water);
fu_assert_true(strpos($waterHtml, 'qty-g') !== false && strpos($waterHtml, '8,340 g') !== false, 'water markup includes grams');
fu_assert_true(strpos($waterHtml, 'qty-lb') !== false && strpos($waterHtml, 'lb') !== false, 'water markup includes lb');
fu_assert_true(strpos($waterHtml, 'qty-gal') !== false && strpos($waterHtml, 'gal') !== false, 'water markup includes gal');

$flourHtml = bakery_formula_amount_markup(10000, $flour);
fu_assert_true(strpos($flourHtml, '10,000 g') !== false, 'flour markup includes grams');
fu_assert_true(strpos($flourHtml, 'qty-gal') === false, 'flour markup has no gallons');

echo "\n=== i18n catalogs ===\n";
$en = bakery_lang_catalog('en');
$es = bakery_lang_catalog('es');
fu_assert_true($en !== [] && $es !== [], 'en and es catalogs load');
$enKeys = array_keys($en);
$esKeys = array_keys($es);
sort($enKeys);
sort($esKeys);
fu_assert_true($enKeys === $esKeys, 'en and es have the same keys');
foreach ($en as $key => $value) {
    fu_assert_true(is_string($value) && $value !== '', "en $key is a non-empty string");
    fu_assert_true(isset($es[$key]) && is_string($es[$key]) && $es[$key] !== '', "es $key is a non-empty string");
}

$_GET['lang'] = 'es';
// Reset static lang by using catalog + bakery_t after forcing GET.
// bakery_current_lang() caches; verify catalogs independently and t() fallback.
fu_assert_true($es['formula.total_dough'] === 'Masa total', 'Spanish total dough string');
fu_assert_true($en['formula.total_dough'] === 'Total Dough', 'English total dough string');
fu_assert_true(strpos($en['formula.help_lead'], '453.592') !== false, 'English help documents ÷ 453.592');
fu_assert_true(strpos($es['formula.help_lead'], '453.592') !== false, 'Spanish help documents ÷ 453.592');
fu_assert_true(strpos($en['formula.density.water'], '8.34') !== false, 'English water density documented');
fu_assert_true(strpos($es['formula.density.milk'], '8.6') !== false, 'Spanish milk density documented');

echo "\n=== Summary ===\n";
echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
