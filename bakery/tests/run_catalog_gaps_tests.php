<?php
/**
 * Catalog gaps: one list of missing weights, prices, formulas, and ingredient costs.
 *
 * Usage: php tests/run_catalog_gaps_tests.php
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
require_once $root . '/includes/operational_timeline.php';

$include = $root . '/includes/catalog_gaps.php';
if (!is_file($include)) {
    fwrite(STDERR, "FAIL  includes/catalog_gaps.php is missing\n");
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

echo "=== Posted weight and price match the product editor ===\n";
$assert(function_exists('bakery_catalog_gap_normalize_price'), 'price normalizer exists');
$assert(function_exists('bakery_catalog_gap_normalize_weight'), 'weight normalizer exists');

$blankPrice = bakery_catalog_gap_normalize_price('');
$assert($blankPrice['apply'] === false && $blankPrice['error'] === null, 'empty price is left alone, same as a blank product price');
$zeroPrice = bakery_catalog_gap_normalize_price('0');
$assert($zeroPrice['apply'] === true && (float)$zeroPrice['value'] === 0.0 && $zeroPrice['error'] === null, 'zero is a legal catalog price, same as min=0 on the product editor');
$okPrice = bakery_catalog_gap_normalize_price('4.5');
$assert($okPrice['apply'] === true && (float)$okPrice['value'] === 4.5 && $okPrice['error'] === null, 'a positive price is kept');
$badPrice = bakery_catalog_gap_normalize_price('-1');
$assert($badPrice['apply'] === false && $badPrice['error'] !== null, 'a negative price is rejected, same as the product editor check');
$junkPrice = bakery_catalog_gap_normalize_price('abc');
$assert($junkPrice['apply'] === false && $junkPrice['error'] !== null, 'a non-numeric price is rejected');

$blankWeight = bakery_catalog_gap_normalize_weight('');
$assert($blankWeight['apply'] === false && $blankWeight['error'] === null, 'empty weight is left alone');
$zeroWeight = bakery_catalog_gap_normalize_weight('0');
$assert($zeroWeight['apply'] === false && $zeroWeight['value'] === null && $zeroWeight['error'] === null, 'zero weight stores as null, same as weight_grams ?: null');
$okWeight = bakery_catalog_gap_normalize_weight('480');
$assert($okWeight['apply'] === true && (float)$okWeight['value'] === 480.0 && $okWeight['error'] === null, 'a positive weight is kept');
$badWeight = bakery_catalog_gap_normalize_weight('-5');
$assert($badWeight['apply'] === false && $badWeight['error'] !== null, 'a negative weight is rejected');
$junkWeight = bakery_catalog_gap_normalize_weight('heavy');
$assert($junkWeight['apply'] === false && $junkWeight['error'] !== null, 'a non-numeric weight is rejected');

echo "\n=== Page contracts ===\n";
$page = (string)file_get_contents($root . '/catalog_gaps.php');
$products = (string)file_get_contents($root . '/products.php');
$manifest = (string)file_get_contents($root . '/scripts/deploy_manifest.ps1');
$nav = (string)file_get_contents($root . '/includes/navigation_catalog.php');
$helper = (string)file_get_contents($include);
$assert(strpos($page, "bakery_require_role(['administrator', 'manager'])") !== false, 'page is administrator and manager only');
$csrfAt = strpos($page, 'bakery_require_csrf()');
$actionAt = strpos($page, 'bakery_catalog_gap_apply');
$assert($csrfAt !== false && $actionAt !== false && $csrfAt < $actionAt, 'save POST is behind the CSRF check');
$assert(strpos($page, 'export') !== false && strpos($page, 'csv') !== false, 'page serves the csv export');
$assert(strpos($manifest, "'catalog_gaps.php'") !== false, 'deploy manifest root list includes catalog_gaps.php');
$assert(strpos($products, 'catalog_gaps.php') !== false, 'products page links to catalog gaps');
$assert(strpos($products, 'catalog-gaps-badge') !== false, 'products page shows a count badge');
$assert(strpos($nav, "'href' => 'catalog_gaps.php'") === false, 'catalog gaps is not a new top-level nav item');
$assert(strpos($nav, "'catalog_gaps.php'") !== false, 'script registry allowlists catalog gaps for the auth gate');
$assert(strpos($helper, 'bakery_formula_product_piece_grams') !== false, 'no-formula uses the piece grams helper');
$assert(strpos($helper, 'bakery_ingredient_current_cost_per_kg') !== false, 'ingredient cost uses the current cost per kg helper');
$assert(strpos($helper, 'bakery_record_operational_event') !== false, 'saves write an operational event');

$en = require $root . '/lang/en.php';
$es = require $root . '/lang/es.php';
$enKeys = [];
$esKeys = [];
foreach ($en as $key => $_text) {
    if (strpos($key, 'catalog_gaps.') === 0) {
        $enKeys[] = $key;
    }
}
foreach ($es as $key => $_text) {
    if (strpos($key, 'catalog_gaps.') === 0) {
        $esKeys[] = $key;
    }
}
$assert($enKeys !== [] && $enKeys === $esKeys, 'catalog_gaps keys match in English and Spanish');
foreach (['en' => $root . '/lang/en.php', 'es' => $root . '/lang/es.php'] as $label => $path) {
    $linesOf = file($path) ?: [];
    $indexes = [];
    foreach ($linesOf as $i => $line) {
        if (strpos($line, "'catalog_gaps.") !== false) {
            $indexes[] = $i;
        }
    }
    $contiguous = $indexes !== [] && ($indexes[count($indexes) - 1] - $indexes[0] + 1) === count($indexes);
    $assert($contiguous, $label . ' catalog_gaps strings are one contiguous block');
    $uxLine = null;
    foreach ($linesOf as $i => $line) {
        if (strpos($line, "'ux.stage.demand'") !== false) {
            $uxLine = $i;
            break;
        }
    }
    $lastKeyLine = $indexes === [] ? -1 : $indexes[count($indexes) - 1];
    $assert($lastKeyLine >= 0 && $uxLine !== null && $lastKeyLine < $uxLine, $label . ' catalog_gaps block stays before the ux catalog');
    $block = implode('', array_slice($linesOf, $indexes[0], count($indexes)));
    $assert(strpos($block, "\u{2013}") === false && strpos($block, "\u{2014}") === false, $label . ' catalog_gaps copy has no en dash or em dash');
}

echo "\n=== Rows on bakerysf_test ===\n";
if (!table_exists($db, 'ingredient_price_history')) {
    bakery_run_sql_file($db, $root . '/database/schema/086_ingredient_prices.sql');
    bakery_forget_table_exists('ingredient_price_history');
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
$assert(strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn()) === 'bakerysf_test', 'queries stay on bakerysf_test');

$prefix = 'zzgap-' . bin2hex(random_bytes(3)) . '-';
$cleanup = static function () use ($db, $prefix): void {
    $products = $db->prepare('SELECT id FROM products WHERE name LIKE ?');
    $products->execute([$prefix . '%']);
    $productIds = array_map('intval', $products->fetchAll(PDO::FETCH_COLUMN));
    if ($productIds !== [] && table_exists($db, 'formula_subformulas')) {
        $marks = implode(',', array_fill(0, count($productIds), '?'));
        $subs = $db->prepare("SELECT id FROM formula_subformulas WHERE product_id IN ($marks)");
        $subs->execute($productIds);
        $subIds = array_map('intval', $subs->fetchAll(PDO::FETCH_COLUMN));
        if ($subIds !== []) {
            $smarks = implode(',', array_fill(0, count($subIds), '?'));
            $db->prepare("DELETE FROM formula_subformula_lines WHERE subformula_id IN ($smarks)")->execute($subIds);
        }
        $db->prepare("DELETE FROM formula_subformulas WHERE product_id IN ($marks)")->execute($productIds);
    }
    if ($productIds !== [] && table_exists($db, 'operational_events')) {
        $marks = implode(',', array_fill(0, count($productIds), '?'));
        $db->prepare("DELETE FROM operational_events WHERE product_id IN ($marks)")->execute($productIds);
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
        if (table_exists($db, 'ingredient_price_history')) {
            $db->prepare("DELETE FROM ingredient_price_history WHERE ingredient_id IN ($marks)")->execute($ingredientIds);
        }
    }
    $db->prepare('DELETE FROM ingredients WHERE name LIKE ?')->execute([$prefix . '%']);
};

$gapsFor = static function (array $rows, string $prefix): array {
    $mine = [];
    foreach ($rows as $row) {
        if (strpos((string)($row['name'] ?? ''), $prefix) === 0) {
            $mine[] = $row;
        }
    }
    return $mine;
};

try {
    $cleanup();
    $db->prepare('INSERT INTO ingredients (name, unit, is_active, supplier_name) VALUES (?, ?, 1, NULL), (?, ?, 1, ?), (?, ?, 0, NULL)')
        ->execute([
            $prefix . 'flour', 'kg',
            $prefix . 'salt', 'kg', $prefix . 'mill',
            $prefix . 'retired', 'kg',
        ]);
    $flourId = (int)$db->query('SELECT id FROM ingredients WHERE name = ' . $db->quote($prefix . 'flour'))->fetchColumn();
    $saltId = (int)$db->query('SELECT id FROM ingredients WHERE name = ' . $db->quote($prefix . 'salt'))->fetchColumn();
    $retiredId = (int)$db->query('SELECT id FROM ingredients WHERE name = ' . $db->quote($prefix . 'retired'))->fetchColumn();
    bakery_ingredient_price_add($db, [
        'ingredient_id' => $flourId,
        'vendor' => 'Test Mill',
        'pack_size_grams' => 1000,
        'pack_price' => 4,
        'invoice_date' => '2026-02-01',
    ]);
    $assert(bakery_ingredient_current_cost_per_kg($flourId, $db) === 4.0, 'priced flour has a current cost per kg');
    $assert(bakery_ingredient_current_cost_per_kg($saltId, $db) === null, 'salt with only a supplier name has no current cost');

    $db->prepare('INSERT INTO dough_types (name, standard_batch_dough_grams, batch_size_mode) VALUES (?, ?, ?)')
        ->execute([$prefix . 'dough', 2000, 'grams']);
    $doughId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dough_types (name) VALUES (?)')->execute([$prefix . 'empty']);
    $emptyDoughId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO formula_ingredients (dough_type_id, ingredient_id, percentage) VALUES (?, ?, ?)')
        ->execute([$doughId, $flourId, 100]);

    $db->prepare('INSERT INTO products (name, dough_type_id, price, weight_grams) VALUES (?, ?, ?, ?)')
        ->execute([$prefix . 'ready', $doughId, 6.50, 700]);
    $db->prepare('INSERT INTO products (name, dough_type_id, price, weight_grams) VALUES (?, ?, ?, ?)')
        ->execute([$prefix . 'light', $doughId, 5.00, null]);
    $lightId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO products (name, dough_type_id, price, weight_grams) VALUES (?, ?, ?, ?)')
        ->execute([$prefix . 'free', $doughId, null, 400]);
    $freeId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO products (name, dough_type_id, price, weight_grams) VALUES (?, ?, ?, ?)')
        ->execute([$prefix . 'bare', $emptyDoughId, 3.00, 180]);
    $bareId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO products (name, dough_type_id, price, weight_grams) VALUES (?, NULL, ?, ?)')
        ->execute([$prefix . 'plain', 2.00, 90]);

    $rows = bakery_catalog_gap_rows($db);
    $mine = $gapsFor($rows, $prefix);
    $byKey = [];
    foreach ($mine as $row) {
        $byKey[$row['gap'] . '|' . $row['name']] = $row;
    }
    $assert(!isset($byKey['missing_weight|' . $prefix . 'ready']), 'a complete product is not a weight gap');
    $assert(!isset($byKey['missing_price|' . $prefix . 'ready']), 'a complete product is not a price gap');
    $assert(!isset($byKey['no_formula|' . $prefix . 'ready']), 'a product with formula lines is not a formula gap');
    $assert(isset($byKey['missing_weight|' . $prefix . 'light']), 'null weight_grams is a gap');
    $assert(!isset($byKey['no_formula|' . $prefix . 'light']), 'missing weight does not hide a real formula');
    $assert(isset($byKey['missing_price|' . $prefix . 'free']), 'null catalog price is a gap');
    $assert(isset($byKey['no_formula|' . $prefix . 'bare']), 'a dough type with no formula lines is a gap');
    $assert(isset($byKey['no_formula|' . $prefix . 'plain']), 'a product with no dough type is a formula gap');
    $assert(($byKey['no_formula|' . $prefix . 'bare']['href'] ?? '') !== '' && strpos((string)$byKey['no_formula|' . $prefix . 'bare']['href'], 'formulas.php?dough_type=' . $emptyDoughId) !== false, 'formula gap links to the formula editor');
    $assert(isset($byKey['no_cost_or_supplier|' . $prefix . 'salt']), 'an ingredient with no current cost is a gap');
    $assert(!isset($byKey['no_cost_or_supplier|' . $prefix . 'flour']), 'an ingredient with a current cost and vendor is not a gap');
    $assert(!isset($byKey['no_cost_or_supplier|' . $prefix . 'retired']), 'an inactive ingredient is not listed');
    $saltHref = (string)($byKey['no_cost_or_supplier|' . $prefix . 'salt']['href'] ?? '');
    $assert(strpos($saltHref, 'ingredients.php?view=prices&id=' . $saltId) !== false, 'ingredient gap links to the price editor');

    $counts = bakery_catalog_gap_counts($mine);
    $assert((int)($counts['missing_weight'] ?? -1) === 1, 'weight gap count is one');
    $assert((int)($counts['missing_price'] ?? -1) === 1, 'price gap count is one');
    $assert((int)($counts['no_formula'] ?? -1) === 2, 'formula gap count is two');
    $assert((int)($counts['no_cost_or_supplier'] ?? -1) === 1, 'ingredient gap count is one');
    $assert(bakery_catalog_gap_count_rows($mine) === 5, 'badge count is every gap row');

    $order = [];
    foreach ($mine as $row) {
        $order[] = $row['gap'];
    }
    $rank = ['missing_weight' => 0, 'missing_price' => 1, 'no_formula' => 2, 'no_cost_or_supplier' => 3];
    $grouped = true;
    $prev = -1;
    foreach ($order as $gap) {
        $next = $rank[$gap] ?? 9;
        if ($next < $prev) {
            $grouped = false;
            break;
        }
        $prev = $next;
    }
    $assert($grouped && $order !== [], 'rows are grouped by gap type');

    $csv = bakery_catalog_gap_csv($mine);
    $parsed = array_map('str_getcsv', preg_split("/\r\n|\n|\r/", trim($csv)));
    $assert($parsed[0] === ['gap', 'kind', 'id', 'name', 'weight_grams', 'price', 'link'], 'csv header names the gap columns');
    $names = array_column(array_slice($parsed, 1), 3);
    $assert(in_array($prefix . 'light', $names, true) && in_array($prefix . 'salt', $names, true), 'csv includes product and ingredient gaps');

    $refused = bakery_catalog_gap_apply($db, [$lightId => '-2'], [$freeId => '3.25']);
    $assert($refused['ok'] === false && $refused['updated'] === 0, 'one invalid value saves nothing');
    $stillNull = $db->prepare('SELECT weight_grams, price FROM products WHERE id = ?');
    $stillNull->execute([$lightId]);
    $lightRow = $stillNull->fetch(PDO::FETCH_ASSOC);
    $assert($lightRow['weight_grams'] === null, 'rejected weight is not written');
    $stillNull->execute([$freeId]);
    $freeRow = $stillNull->fetch(PDO::FETCH_ASSOC);
    $assert($freeRow['price'] === null, 'a rejected batch does not write the valid price either');

    $saved = bakery_catalog_gap_apply($db, [$lightId => '480', $bareId => '0'], [$freeId => '3.25', $lightId => '']);
    $assert($saved['ok'] === true && (int)$saved['updated'] === 2, 'valid weight and price save, blank and zero weight do not');
    $stillNull->execute([$lightId]);
    $lightRow = $stillNull->fetch(PDO::FETCH_ASSOC);
    $assert((int)$lightRow['weight_grams'] === 480, 'saved weight_grams is 480');
    $stillNull->execute([$freeId]);
    $freeRow = $stillNull->fetch(PDO::FETCH_ASSOC);
    $assert(abs((float)$freeRow['price'] - 3.25) < 0.001, 'saved catalog price is 3.25');
    $stillNull->execute([$bareId]);
    $bareRow = $stillNull->fetch(PDO::FETCH_ASSOC);
    $assert((int)$bareRow['weight_grams'] === 180, 'zero weight does not clear an existing weight');

    $events = $db->prepare(
        "SELECT event_type, product_id, summary, metadata
         FROM operational_events
         WHERE product_id IN (?, ?) AND event_type = 'catalog_gap_updated'
         ORDER BY id"
    );
    $events->execute([$lightId, $freeId]);
    $eventRows = $events->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($eventRows) === 2, 'each saved field writes one operational event');
    $fields = [];
    foreach ($eventRows as $event) {
        $meta = json_decode((string)$event['metadata'], true);
        $fields[] = is_array($meta) ? (string)($meta['field'] ?? '') : '';
        $assert((int)$event['product_id'] > 0 && trim((string)$event['summary']) !== '', 'event names the product change');
    }
    sort($fields);
    $assert($fields === ['price', 'weight_grams'], 'events record price and weight_grams');

    $after = $gapsFor(bakery_catalog_gap_rows($db), $prefix);
    $afterKeys = [];
    foreach ($after as $row) {
        $afterKeys[$row['gap'] . '|' . $row['name']] = true;
    }
    $assert(!isset($afterKeys['missing_weight|' . $prefix . 'light']), 'filled weight leaves the gap list');
    $assert(!isset($afterKeys['missing_price|' . $prefix . 'free']), 'filled price leaves the gap list');
    $assert(isset($afterKeys['no_formula|' . $prefix . 'bare']), 'a formula gap stays until the formula editor is used');
    $assert(isset($afterKeys['no_cost_or_supplier|' . $prefix . 'salt']), 'an ingredient gap stays until the price editor is used');
    unset($retiredId);
} finally {
    $cleanup();
}

echo "\n" . ($fail === 0 ? 'OK' : 'FAILED') . "  failures=$fail\n";
exit($fail > 0 ? 1 : 0);
