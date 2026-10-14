<?php
/**
 * Read-only product cost and margin.
 *
 * Usage: php tests/run_cost_margin_tests.php
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
require_once $root . '/includes/ingredient_prices.php';
require_once $root . '/includes/formula_structure.php';

$include = $root . '/includes/cost_margin.php';
if (!is_file($include)) {
    fwrite(STDERR, "FAIL  includes/cost_margin.php is missing\n");
    exit(1);
}
require_once $include;

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
$near = static function ($expected, $actual, string $label, float $epsilon = 0.0001) use ($assert): void {
    $assert(
        $actual !== null && abs((float)$expected - (float)$actual) <= $epsilon,
        $label . ' (expected=' . $expected . ' actual=' . var_export($actual, true) . ')'
    );
};

echo "=== Piece cost from baker's percent and cost per kg ===\n";
$assert(bakery_cost_margin_loss_grams() === 50.0, 'dough loss used by costing is 50 g per mix');

$lines = [
    ['ingredient_id' => 1, 'ingredient_name' => 'Flour', 'unit' => 'kg', 'percentage' => 100],
    ['ingredient_id' => 2, 'ingredient_name' => 'Water', 'unit' => 'g', 'percentage' => 50],
];
$structure = [
    'batch_size_mode' => 'grams',
    'standard_batch_dough_grams' => 2000,
    'standard_batch_pieces' => null,
    'batch_multiplier' => null,
    'dough_loss_grams' => 80,
    'starters' => [],
];
$costs = [1 => 2.0, 2 => 0.10];
$quote = bakery_cost_margin_quote($lines, $structure, [], 200.0, $costs, 1.50);
$near(200.0, $quote['piece_grams'], 'piece grams stay the finished piece weight');
$near(0.2733333333, $quote['ingredient_cost'], 'ingredient cost is baker percent times cost per kg, without loss');
$near(5.0, $quote['loss_share_grams'], '50 g loss is spread across the batch, ignoring a different saved loss');
$near(0.0068333333, $quote['dough_loss'], 'dough loss dollars are the extra dough, not zero');
$near(1.2198333333, $quote['margin_dollars'], 'margin dollars are price minus ingredient cost minus loss');
$near(81.32222222, $quote['margin_percent'], 'margin percent is margin dollars over unit price');
$assert($quote['flags'] === [], 'priced formula has no flags');
$near(0.2733333333, $quote['cost_floor'], 'floor matches ingredient cost when every ingredient has an invoice price');
$near(0.2733333333, $quote['cost_upper'], 'upper bound matches the floor when every ingredient has an invoice price');
$near(100.0, $quote['priced_dough_percent'], 'fully priced dough is 100 percent');

$scaled = bakery_cost_margin_quote($lines, [
    'batch_size_mode' => 'grams',
    'standard_batch_dough_grams' => 1000,
    'batch_multiplier' => 2,
    'dough_loss_grams' => null,
    'starters' => [],
], [], 100.0, [1 => 1.0, 2 => 0.0], 2.0);
$near(2.5, $scaled['loss_share_grams'], 'multiplier scales the batch before the 50 g loss is spread');

$pieces = bakery_cost_margin_quote(
    [['ingredient_id' => 1, 'ingredient_name' => 'Flour', 'percentage' => 100]],
    [
        'batch_size_mode' => 'pieces',
        'standard_batch_pieces' => 20,
        'batch_multiplier' => 1.5,
        'dough_loss_grams' => null,
        'starters' => [],
    ],
    [],
    100.0,
    [1 => 1.0],
    1.0
);
$near(50 / 30, $pieces['loss_share_grams'], 'pieces mode spreads 50 g across multiplied pieces');

$topping = bakery_cost_margin_quote(
    [['ingredient_id' => 1, 'ingredient_name' => 'Flour', 'percentage' => 100]],
    [
        'batch_size_mode' => 'pieces',
        'standard_batch_pieces' => 10,
        'dough_loss_grams' => null,
        'starters' => [],
    ],
    [[
        'kind' => 'topping',
        'lines' => [[
            'ingredient_id' => 9,
            'ingredient_name' => 'Sesame',
            'grams_per_piece' => 10,
        ]],
    ]],
    100.0,
    [1 => 1.0, 9 => 4.0],
    3.0
);
$near(0.14, $topping['ingredient_cost'], 'topping grams are in the piece cost');
$near(0.005, $topping['dough_loss'], 'topping grams are not scaled by dough loss');
$near(100.0, $topping['priced_dough_percent'], 'a priced topping does not change fully priced dough weight');

$topGap = bakery_cost_margin_quote(
    [['ingredient_id' => 1, 'ingredient_name' => 'Flour', 'percentage' => 100]],
    [
        'batch_size_mode' => 'pieces',
        'standard_batch_pieces' => 10,
        'dough_loss_grams' => null,
        'starters' => [],
    ],
    [[
        'kind' => 'topping',
        'lines' => [[
            'ingredient_id' => 9,
            'ingredient_name' => 'Sesame',
            'grams_per_piece' => 10,
        ]],
    ]],
    100.0,
    [1 => 1.0],
    3.0
);
$assert($topGap['ingredient_cost'] === null, 'an unpriced topping blanks the full ingredient cost');
$near(0.1, $topGap['cost_floor'], 'the floor still prices the invoiced dough');
$near(100.0, $topGap['priced_dough_percent'], 'topping grams are outside dough weight');

echo "\n=== Empty formula and missing price stay blank ===\n";
$empty = bakery_cost_margin_quote([], $structure, [], 180.0, $costs, 2.0);
$assert($empty['flags'] === ['empty_formula'], 'empty formula is flagged');
$assert($empty['ingredient_cost'] === null, 'empty formula cost is null');
$assert($empty['dough_loss'] === null, 'empty formula loss is null');
$assert($empty['margin_dollars'] === null && $empty['margin_percent'] === null, 'empty formula margin is null');
$assert($empty['ingredient_cost'] !== 0.0 && $empty['dough_loss'] !== 0.0, 'empty formula does not use zero');

$noWeight = bakery_cost_margin_quote($lines, $structure, [], null, $costs, 2.0);
$assert($noWeight['flags'] === ['empty_formula'], 'missing piece weight is an empty formula');
$assert($noWeight['piece_grams'] === null, 'missing piece weight is blank');

$missing = bakery_cost_margin_quote($lines, $structure, [], 200.0, [1 => 2.0], 1.50);
$assert($missing['flags'] === ['missing_price'], 'ingredient without a price is flagged');
$assert($missing['ingredient_cost'] === null && $missing['dough_loss'] === null, 'missing price blanks cost and loss');
$assert($missing['margin_dollars'] === null && $missing['margin_percent'] === null, 'missing price blanks margin');
$near(0.2666666667, $missing['cost_floor'], 'floor keeps the invoiced share of the dough');
$near(0.2666666667, $missing['cost_upper'], 'upper bound matches the floor when the gap has no other price');
$near(66.66666667, $missing['priced_dough_percent'], 'priced dough weight is the share of dough grams that have a price');

$filled = bakery_cost_margin_quote($lines, $structure, [], 200.0, [
    1 => ['invoice' => 2.0, 'fill' => null],
    2 => ['invoice' => null, 'fill' => 0.20],
], 1.50);
$assert($filled['ingredient_cost'] === null && $filled['flags'] === ['missing_price'], 'a quote or public list does not complete the invoice cost');
$near(0.2666666667, $filled['cost_floor'], 'floor ignores the quote where an invoice price is missing');
$near(0.28, $filled['cost_upper'], 'upper bound uses the quote only where an invoice price is missing');
$near(100.0, $filled['priced_dough_percent'], 'a quote or public list counts as a price on that dough weight');

$nonePriced = bakery_cost_margin_quote($lines, $structure, [], 200.0, [], 1.50);
$assert($nonePriced['cost_floor'] === null && $nonePriced['cost_upper'] === null, 'no prices leave the floor and upper bound blank');
$assert($nonePriced['cost_floor'] !== 0.0 && $nonePriced['cost_upper'] !== 0.0, 'a blank floor is not zero');
$near(0.0, $nonePriced['priced_dough_percent'], 'priced dough weight is zero when nothing has a price');

$emptyBounds = bakery_cost_margin_quote([], $structure, [], 180.0, $costs, 2.0);
$assert($emptyBounds['cost_floor'] === null && $emptyBounds['cost_upper'] === null && $emptyBounds['priced_dough_percent'] === null, 'an empty formula has no floor, upper bound, or priced weight');

$zeroPrice = bakery_cost_margin_quote(
    [['ingredient_id' => 1, 'ingredient_name' => 'Flour', 'percentage' => 100]],
    ['batch_size_mode' => 'pieces', 'standard_batch_pieces' => 10, 'starters' => []],
    [],
    100.0,
    [1 => 0.0],
    1.0
);
$near(0.0, $zeroPrice['ingredient_cost'], 'a known zero cost per kg is a real cost');
$assert($zeroPrice['flags'] === [], 'zero cost per kg is not a missing price');
$near(0.0, $zeroPrice['cost_floor'], 'a known zero invoice price is a real floor');
$near(100.0, $zeroPrice['priced_dough_percent'], 'a zero price still covers that dough weight');

$noBatch = bakery_cost_margin_quote($lines, ['starters' => []], [], 200.0, $costs, 1.50);
$near(0.2733333333, $noBatch['ingredient_cost'], 'ingredient cost still shows when the batch is blank');
$assert($noBatch['dough_loss'] === null && $noBatch['loss_share_grams'] === null, 'unspread loss is blank, not zero');
$assert($noBatch['margin_dollars'] === null && $noBatch['margin_percent'] === null, 'margin stays blank until loss can be spread');
$assert($noBatch['flags'] === [], 'a blank batch is not an empty formula');

$free = bakery_cost_margin_quote(
    [['ingredient_id' => 1, 'ingredient_name' => 'Flour', 'percentage' => 100]],
    ['batch_size_mode' => 'pieces', 'standard_batch_pieces' => 10, 'starters' => []],
    [],
    100.0,
    [1 => 1.0],
    0.0
);
$assert($free['margin_percent'] === null, 'margin percent is blank when unit price is zero');
$near(-0.105, $free['margin_dollars'], 'margin dollars still subtract cost from a zero price');

echo "\n=== CSV blanks flagged cells ===\n";
$csv = bakery_cost_margin_csv([
    [
        'product_name' => 'Plain, "Roll"',
        'piece_grams' => 200.0,
        'ingredient_cost' => 0.2733333333,
        'dough_loss' => 0.0068333333,
        'unit_price' => 1.5,
        'margin_dollars' => 1.2198333333,
        'margin_percent' => 81.32222222,
        'delivered_90_days' => 12,
        'flags' => [],
    ],
    [
        'product_name' => 'Empty loaf',
        'piece_grams' => 180.0,
        'ingredient_cost' => null,
        'dough_loss' => null,
        'unit_price' => 2.0,
        'margin_dollars' => null,
        'margin_percent' => null,
        'delivered_90_days' => 0,
        'flags' => ['empty_formula'],
    ],
]);
$parsed = array_map('str_getcsv', preg_split("/\r\n|\n|\r/", trim($csv)));
$assert($parsed[0] === [
    'product', 'piece_grams', 'ingredient_cost', 'cost_floor', 'cost_upper', 'priced_dough_percent',
    'dough_loss', 'unit_price', 'margin_dollars', 'margin_percent', 'delivered_90_days', 'flag',
], 'csv header matches the page columns');
$assert($parsed[1][0] === 'Plain, "Roll"', 'csv quotes commas and quotes in names');
$assert($parsed[2][2] === '' && $parsed[2][3] === '' && $parsed[2][4] === '' && $parsed[2][5] === '', 'flagged csv cost cells are empty');
$assert($parsed[2][6] === '' && $parsed[2][8] === '' && $parsed[2][9] === '', 'flagged csv loss and margin cells are empty');
$assert($parsed[2][2] !== '0' && $parsed[2][3] !== '0', 'flagged csv cells are not zero');
$assert($parsed[2][11] === 'empty_formula', 'csv flag code names the empty formula');

echo "\n=== Page contracts ===\n";
$page = (string)file_get_contents($root . '/cost_margin.php');
$products = (string)file_get_contents($root . '/products.php');
$manifest = (string)file_get_contents($root . '/scripts/deploy_manifest.ps1');
$nav = (string)file_get_contents($root . '/includes/navigation_catalog.php');
$assert(strpos($page, "bakery_require_role(['administrator', 'manager'])") !== false, 'page is administrator and manager only');
$assert(strpos($page, 'bakery_cost_margin_method_allowed') !== false, 'page checks the request method');
$assert(bakery_cost_margin_method_allowed('GET') && bakery_cost_margin_method_allowed('HEAD'), 'GET and HEAD are allowed');
$assert(!bakery_cost_margin_method_allowed('POST') && !bakery_cost_margin_method_allowed('PUT'), 'POST and PUT are refused');
$assert(strpos($page, 'export') !== false && strpos($page, 'csv') !== false, 'page serves the csv export');
$assert(strpos($manifest, "'cost_margin.php'") !== false, 'deploy manifest root list includes cost_margin.php');
$assert(strpos($products, 'cost_margin.php') !== false, 'products page links to cost and margin');
$assert(strpos($nav, "'href' => 'cost_margin.php'") === false, 'cost and margin is not a new top-level nav item');
$assert(strpos($nav, "'cost_margin.php'") !== false, 'script registry allowlists cost and margin for the auth gate');
$assert(strpos($page, 'bakery_ingredient_current_cost_per_kg') !== false || strpos((string)file_get_contents($include), 'bakery_ingredient_current_cost_per_kg') !== false, 'cost uses the current cost per kg helper');
$assert(strpos($page, 'cost_margin.col_floor') !== false, 'page shows the invoice cost floor');
$assert(strpos($page, 'cost_margin.col_upper') !== false, 'page shows the upper bound');
$assert(strpos($page, 'cost_margin.col_priced_weight') !== false, 'page shows priced dough weight');
$assert(strpos($page, 'container-type') !== false, 'the cost table adapts to a narrow screen');
$assert(strpos((string)file_get_contents($include), 'bakery_formula_product_piece_grams') !== false, 'piece grams use the formula structure helper');

$en = require $root . '/lang/en.php';
$es = require $root . '/lang/es.php';
$enKeys = [];
$esKeys = [];
foreach ($en as $key => $_text) {
    if (strpos($key, 'cost_margin.') === 0) {
        $enKeys[] = $key;
    }
}
foreach ($es as $key => $_text) {
    if (strpos($key, 'cost_margin.') === 0) {
        $esKeys[] = $key;
    }
}
$assert($enKeys !== [] && $enKeys === $esKeys, 'cost_margin keys match in English and Spanish');
foreach (['en' => $root . '/lang/en.php', 'es' => $root . '/lang/es.php'] as $label => $path) {
    $linesOf = file($path) ?: [];
    $indexes = [];
    foreach ($linesOf as $i => $line) {
        if (strpos($line, "'cost_margin.") !== false) {
            $indexes[] = $i;
        }
    }
    $contiguous = $indexes !== [] && ($indexes[count($indexes) - 1] - $indexes[0] + 1) === count($indexes);
    $assert($contiguous, $label . ' cost_margin strings are one contiguous block');
    $block = implode('', array_slice($linesOf, $indexes[0], count($indexes)));
    $assert(strpos($block, "\u{2013}") === false && strpos($block, "\u{2014}") === false, $label . ' cost_margin copy has no en dash or em dash');
}
$assert(strpos($en['cost_margin.page_title'], "\u{2013}") === false, 'English title has no en dash');

echo "\n=== Rows on bakerysf_test ===\n";
if (!table_exists($db, 'ingredient_price_history')) {
    bakery_run_sql_file($db, $root . '/database/schema/086_ingredient_prices.sql');
    bakery_forget_table_exists('ingredient_price_history');
}
if (is_file($root . '/database/schema/093_ingredient_price_type.sql') && !column_exists($db, 'ingredient_price_history', 'price_type')) {
    bakery_run_sql_file($db, $root . '/database/schema/093_ingredient_price_type.sql');
    bakery_forget_column_exists('ingredient_price_history', 'price_type');
}
if (!column_exists($db, 'dough_types', 'dough_loss_grams')) {
    bakery_run_sql_file($db, $root . '/database/schema/087_formula_structure.sql');
    bakery_forget_column_exists('dough_types', 'dough_loss_grams');
    bakery_forget_column_exists('dough_types', 'batch_multiplier');
    bakery_forget_column_exists('dough_types', 'standard_batch_pieces');
    bakery_forget_column_exists('dough_types', 'batch_size_mode');
    bakery_forget_table_exists('formula_subformulas');
    bakery_forget_table_exists('formula_subformula_lines');
}
$assert(table_exists($db, 'ingredient_price_history'), 'price history is on bakerysf_test');
$assert(column_exists($db, 'dough_types', 'dough_loss_grams'), 'formula structure is on bakerysf_test');
$assert(strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn()) === 'bakerysf_test', 'queries stay on bakerysf_test');

$prefix = 'zzcm-' . bin2hex(random_bytes(3)) . '-';
$cleanup = static function () use ($db, $prefix): void {
    $ids = $db->prepare('SELECT id FROM products WHERE name LIKE ?');
    $ids->execute([$prefix . '%']);
    $productIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
    if ($productIds !== []) {
        $marks = implode(',', array_fill(0, count($productIds), '?'));
        $db->prepare("DELETE FROM daily_order_items WHERE product_id IN ($marks)")->execute($productIds);
    }
    $doughs = $db->prepare('SELECT id FROM dough_types WHERE name LIKE ?');
    $doughs->execute([$prefix . '%']);
    $doughIds = array_map('intval', $doughs->fetchAll(PDO::FETCH_COLUMN));
    if ($doughIds !== []) {
        $marks = implode(',', array_fill(0, count($doughIds), '?'));
        $db->prepare("DELETE FROM formula_ingredients WHERE dough_type_id IN ($marks)")->execute($doughIds);
    }
    $db->prepare('DELETE FROM products WHERE name LIKE ?')->execute([$prefix . '%']);
    $db->prepare('DELETE FROM dough_types WHERE name LIKE ?')->execute([$prefix . '%']);
    $ingredients = $db->prepare('SELECT id FROM ingredients WHERE name LIKE ?');
    $ingredients->execute([$prefix . '%']);
    $ingredientIds = array_map('intval', $ingredients->fetchAll(PDO::FETCH_COLUMN));
    if ($ingredientIds !== []) {
        $marks = implode(',', array_fill(0, count($ingredientIds), '?'));
        $db->prepare("DELETE FROM ingredient_price_history WHERE ingredient_id IN ($marks)")->execute($ingredientIds);
    }
    $db->prepare('DELETE FROM ingredients WHERE name LIKE ?')->execute([$prefix . '%']);
    $customers = $db->prepare('SELECT id FROM customers WHERE name LIKE ?');
    $customers->execute([$prefix . '%']);
    $customerIds = array_map('intval', $customers->fetchAll(PDO::FETCH_COLUMN));
    if ($customerIds !== []) {
        $marks = implode(',', array_fill(0, count($customerIds), '?'));
        $orderIds = $db->prepare("SELECT id FROM daily_orders WHERE customer_id IN ($marks)");
        $orderIds->execute($customerIds);
        $oids = array_map('intval', $orderIds->fetchAll(PDO::FETCH_COLUMN));
        if ($oids !== []) {
            $omarks = implode(',', array_fill(0, count($oids), '?'));
            $db->prepare("DELETE FROM daily_order_items WHERE daily_order_id IN ($omarks)")->execute($oids);
            $db->prepare("DELETE FROM daily_orders WHERE id IN ($omarks)")->execute($oids);
        }
    }
    $db->prepare('DELETE FROM customers WHERE name LIKE ?')->execute([$prefix . '%']);
};

try {
    $cleanup();
    $db->prepare('INSERT INTO ingredients (name, unit) VALUES (?, ?), (?, ?)')
        ->execute([$prefix . 'flour', 'kg', $prefix . 'water', 'g']);
    $flourId = (int)$db->query('SELECT id FROM ingredients WHERE name = ' . $db->quote($prefix . 'flour'))->fetchColumn();
    $waterId = (int)$db->query('SELECT id FROM ingredients WHERE name = ' . $db->quote($prefix . 'water'))->fetchColumn();
    bakery_ingredient_price_add($db, [
        'ingredient_id' => $flourId,
        'vendor' => 'Test Mill',
        'pack_size_grams' => 1000,
        'pack_price' => 2,
        'invoice_date' => '2026-01-01',
    ]);
    bakery_ingredient_price_add($db, [
        'ingredient_id' => $flourId,
        'vendor' => 'Test Mill',
        'pack_size_grams' => 1000,
        'pack_price' => 4,
        'invoice_date' => '2026-02-01',
    ]);
    bakery_ingredient_price_add($db, [
        'ingredient_id' => $waterId,
        'vendor' => 'Test Mill',
        'pack_size_grams' => 1000,
        'pack_price' => 0.10,
        'invoice_date' => '2026-01-15',
    ]);
    $assert(bakery_ingredient_current_cost_per_kg($flourId, $db) === 4.0, 'latest flour invoice wins before the row is built');

    $db->prepare('INSERT INTO dough_types (name, standard_batch_dough_grams, batch_size_mode) VALUES (?, ?, ?)')
        ->execute([$prefix . 'dough', 2000, 'grams']);
    $doughId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dough_types (name) VALUES (?)')->execute([$prefix . 'empty']);
    $emptyDoughId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO formula_ingredients (dough_type_id, ingredient_id, percentage) VALUES (?, ?, ?), (?, ?, ?)')
        ->execute([$doughId, $flourId, 100, $doughId, $waterId, 50]);

    $db->prepare('INSERT INTO products (name, dough_type_id, price, weight_grams) VALUES (?, ?, ?, ?)')
        ->execute([$prefix . 'roll', $doughId, 1.50, 200]);
    $rollId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO products (name, dough_type_id, price, weight_grams) VALUES (?, ?, ?, ?)')
        ->execute([$prefix . 'bare', $emptyDoughId, 2.00, 180]);
    $bareId = (int)$db->lastInsertId();

    $db->prepare('INSERT INTO customers (name) VALUES (?)')->execute([$prefix . 'shop']);
    $customerId = (int)$db->lastInsertId();
    $asOf = '2026-10-09';
    $inside = (new DateTimeImmutable($asOf))->modify('-90 days')->format('Y-m-d');
    $outside = (new DateTimeImmutable($asOf))->modify('-91 days')->format('Y-m-d');
    $order = $db->prepare('INSERT INTO daily_orders (customer_id, order_date, status) VALUES (?, ?, ?)');
    $item = $db->prepare('INSERT INTO daily_order_items (daily_order_id, product_id, quantity, delivered_quantity) VALUES (?, ?, ?, ?)');
    $order->execute([$customerId, $asOf, 'delivered']);
    $item->execute([(int)$db->lastInsertId(), $rollId, 6, 4]);
    $order->execute([$customerId, $inside, 'invoiced']);
    $item->execute([(int)$db->lastInsertId(), $rollId, 3, null]);
    $order->execute([$customerId, $outside, 'delivered']);
    $item->execute([(int)$db->lastInsertId(), $rollId, 9, 9]);
    $order->execute([$customerId, (new DateTimeImmutable($asOf))->modify('-1 day')->format('Y-m-d'), 'pending']);
    $item->execute([(int)$db->lastInsertId(), $rollId, 8, 8]);

    $rows = bakery_cost_margin_rows($db, $asOf);
    $byName = [];
    foreach ($rows as $row) {
        if (strpos((string)$row['product_name'], $prefix) === 0) {
            $byName[$row['product_name']] = $row;
        }
    }
    $assert(isset($byName[$prefix . 'roll'], $byName[$prefix . 'bare']), 'each seeded product has one row');
    $roll = $byName[$prefix . 'roll'];
    $near(4.0, bakery_ingredient_current_cost_per_kg($flourId, $db), 'row build still reads the latest cost per kg');
    $near(0.54, $roll['ingredient_cost'], 'row ingredient cost uses the latest cost per kg');
    $near(0.0135, $roll['dough_loss'], 'row spreads 50 g across the 2000 g batch');
    $assert((int)$roll['delivered_90_days'] === 7, 'delivered units are the last 90 days of delivered and invoiced lines');
    $bare = $byName[$prefix . 'bare'];
    $assert($bare['flags'] === ['empty_formula'], 'product with no formula lines is flagged');
    $assert($bare['ingredient_cost'] === null && $bare['margin_dollars'] === null, 'empty formula row stays blank');
    $assert($bare['cost_floor'] === null && $bare['priced_dough_percent'] === null, 'empty formula row has no floor or priced weight');
    $assert((int)$bare['delivered_90_days'] === 0, 'a product with no deliveries shows zero units');
    $near(0.54, $roll['cost_floor'], 'a fully invoiced row uses the invoice floor');
    $near(0.54, $roll['cost_upper'], 'a fully invoiced row upper bound matches the floor');
    $near(100.0, $roll['priced_dough_percent'], 'a fully invoiced dough is 100 percent priced');

    $db->prepare('INSERT INTO ingredients (name, unit) VALUES (?, ?)')->execute([$prefix . 'oil', 'kg']);
    $oilId = (int)$db->query('SELECT id FROM ingredients WHERE name = ' . $db->quote($prefix . 'oil'))->fetchColumn();
    $oilQuote = bakery_ingredient_price_add($db, [
        'ingredient_id' => $oilId,
        'vendor' => 'Quote Mill',
        'pack_size_grams' => 1000,
        'pack_price' => 8,
        'invoice_date' => '2026-03-01',
        'price_type' => 'quote',
    ]);
    $oilList = bakery_ingredient_price_add($db, [
        'ingredient_id' => $oilId,
        'vendor' => 'List Mill',
        'pack_size_grams' => 1000,
        'pack_price' => 10,
        'invoice_date' => '2026-04-01',
        'price_type' => 'public_list',
    ]);
    $assert(($oilQuote['ok'] ?? false) && ($oilList['ok'] ?? false), 'quote and public_list rows store for the upper bound');
    $assert(bakery_ingredient_current_cost_per_kg($oilId, $db) === null, 'oil has no invoice price so it has no current cost');
    $db->prepare('INSERT INTO dough_types (name, standard_batch_dough_grams, batch_size_mode) VALUES (?, ?, ?)')
        ->execute([$prefix . 'mixed', 2000, 'grams']);
    $mixedDoughId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO formula_ingredients (dough_type_id, ingredient_id, percentage) VALUES (?, ?, ?), (?, ?, ?), (?, ?, ?)')
        ->execute([$mixedDoughId, $flourId, 100, $mixedDoughId, $waterId, 50, $mixedDoughId, $oilId, 10]);
    $db->prepare('INSERT INTO products (name, dough_type_id, price, weight_grams) VALUES (?, ?, ?, ?)')
        ->execute([$prefix . 'mixed', $mixedDoughId, 2.00, 200]);
    $mixedRows = bakery_cost_margin_rows($db, $asOf);
    $mixed = null;
    foreach ($mixedRows as $row) {
        if ($row['product_name'] === $prefix . 'mixed') {
            $mixed = $row;
        }
    }
    $assert($mixed !== null, 'mixed price product has a row');
    $assert($mixed['ingredient_cost'] === null && $mixed['margin_dollars'] === null, 'missing invoice price still blanks the full cost and margin');
    $near(0.50625, $mixed['cost_floor'], 'row floor uses invoice prices only');
    $near(0.63125, $mixed['cost_upper'], 'row upper bound fills the gap with the latest public list price');
    $near(100.0, $mixed['priced_dough_percent'], 'row priced dough weight counts the public list price');
} finally {
    $cleanup();
}

echo "\n" . ($fail === 0 ? "OK" : "FAILED") . "  failures=$fail\n";
exit($fail > 0 ? 1 : 0);
