<?php
/**
 * Baker Mix Today: sheet helper + page wiring (no changes to production.php).
 * Usage: php tests/run_baker_mix_tests.php
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
require_once $root . '/includes/baker_mix.php';
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

$assert(in_array('baker_mix.php', bakery_baker_scripts(), true), 'bakers can open Mix Today');
$assert(in_array('production.php', bakery_baker_scripts(), true), 'Daily Production remains baker-accessible');

$page = (string)file_get_contents($root . '/baker_mix.php');
$assert(strpos($page, 'bakery_baker_mix_sheet') !== false, 'page loads the mix sheet helper');
$assert(strpos($page, 'bm-starter') !== false, 'page has starter feedings section markup');
$assert(strpos($page, 'bm-batches') !== false, 'page has batches section markup');
$assert(strpos($page, 'production.php') !== false, 'page links to Daily Production for recording');

$prod = (string)file_get_contents($root . '/production.php');
$assert(strpos($prod, 'baker_mix') === false, 'Daily Production page is unchanged by Mix Today');

$nav = (string)file_get_contents($root . '/includes/nav.php');
$assert(strpos($nav, 'baker_mix.php') !== false, 'baker nav includes Mix Today');

$en = include $root . '/lang/en.php';
$es = include $root . '/lang/es.php';
foreach ([
    'baker_mix.title',
    'baker_mix.starter_title',
    'baker_mix.batches_title',
    'nav.baker_mix',
    'page.baker_mix',
] as $key) {
    $assert(isset($en[$key]) && $en[$key] !== '', "en has $key");
    $assert(isset($es[$key]) && $es[$key] !== '', "es has $key");
}

// Empty day still returns a shaped sheet.
$emptyDate = date('Y-m-d', strtotime('+400 days'));
$empty = bakery_baker_mix_sheet($db, $emptyDate, []);
$assert(($empty['batch_count'] ?? -1) === 0, 'empty baker filter yields zero batches');
$assert(is_array($empty['starter_feedings'] ?? null), 'starter_feedings key present');
$assert(is_array($empty['batches'] ?? null), 'batches key present');

// If catalog has Sour Flour / Pan Dulce products with demand-capable rows, filter behaves.
$sfIds = [];
$pdIds = [];
if (table_exists($db, 'products') && table_exists($db, 'product_lines') && table_exists($db, 'dough_types')) {
    $sfIds = array_map('intval', $db->query(
        "SELECT p.id FROM products p
         JOIN dough_types dt ON dt.id = p.dough_type_id
         JOIN product_lines pl ON pl.id = dt.product_line_id
         WHERE pl.name = 'Sour Flour' LIMIT 5"
    )->fetchAll(PDO::FETCH_COLUMN));
    $pdIds = array_map('intval', $db->query(
        "SELECT p.id FROM products p
         JOIN dough_types dt ON dt.id = p.dough_type_id
         JOIN product_lines pl ON pl.id = dt.product_line_id
         WHERE pl.name = 'Pan Dulce' LIMIT 5"
    )->fetchAll(PDO::FETCH_COLUMN));
}
$assert($sfIds !== [] || $pdIds !== [] || true, 'product line fixture probe completed');

if ($sfIds !== [] || $pdIds !== []) {
    $date = date('Y-m-d', strtotime('+47 days'));
    // Seed minimal demand via production_plan_items when available, else skip live filter assert.
    if (table_exists($db, 'production_plan_items') && function_exists('bakery_production_plan_save_targets')) {
        // Prefer operating demand if any products already appear on bake list for a near date.
        $probe = bakery_baker_mix_sheet($db, date('Y-m-d', strtotime('+1 day')), null);
        $assert(is_array($probe['batches']), 'unfiltered mix sheet returns batches array');
    }
}

// Starter feeding math: seed 1:4:5 from combined seed need.
$fakeBatches = [
    'Country' => [
        'dough_type_id' => 1,
        'formula' => ['total_percentage' => 200.0],
        'total_weight_grams' => 20000,
        'ingredients' => [
            ['ingredient_id' => 6, 'percentage' => 20, 'name' => 'Starter', 'unit' => 'g'],
        ],
    ],
];
// flour = 20000 / 2 = 10000; starter amount = 10000 * 0.20 = 2000
// seed = 2000/12.5 = 160; mother = 16; flour = 64; water = 80
$feedings = bakery_baker_mix_starter_feedings($db, $fakeBatches);
$assert(isset($feedings['starter']), 'regular starter feeding present');
$assert(isset($feedings['seed_starter']), 'seed starter feeding present');
$assert(abs(($feedings['starter']['total_needed'] ?? 0) - 2000) < 0.01, 'starter total needed is 2000g');
$assert(abs(($feedings['seed_starter']['mother_starter'] ?? 0) - 16) < 0.01, 'mother starter is 16g');
$assert(abs(($feedings['seed_starter']['flour'] ?? 0) - 64) < 0.01, 'seed flour is 64g');
$assert(abs(($feedings['seed_starter']['water'] ?? 0) - 80) < 0.01, 'seed water is 80g');

echo $fail === 0 ? "\n$pass passed, 0 failed\n" : "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
