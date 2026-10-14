<?php
/**
 * Ingredient invoice price history, reorder flag, draft PO grouping, CSV import.
 *
 * Usage: php tests/run_ingredient_prices_tests.php
 * Database: bakerysf_test only.
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
require_once $root . '/includes/ingredient_prices.php';

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
    $assert(abs((float)$expected - (float)$actual) <= $epsilon, $label . ' (expected=' . $expected . ' actual=' . $actual . ')');
};

echo "=== Cost per kg math ===\n";
$near(1.2, bakery_ingredient_cost_per_kg_from_pack(24.0, 20000), '24.00 for 20000 g is 1.20 per kg');
$near(1.6, bakery_ingredient_cost_per_kg_from_pack(40.0, 25000), '40.00 for 25000 g is 1.60 per kg');
$near(4.0123, bakery_ingredient_cost_per_kg_from_pack(45.50, 11340), '45.50 for 11340 g rounds to 4.0123');
$badPack = bakery_ingredient_price_validate([
    'ingredient_id' => 1,
    'vendor' => 'KGBS',
    'pack_size_grams' => 0,
    'pack_price' => 10,
    'invoice_date' => '2026-05-01',
]);
$assert($badPack === 'ingredient_prices.invalid_pack', 'zero pack grams is rejected');

echo "\n=== Reorder flag ===\n";
$assert(bakery_ingredient_needs_reorder([
    'quantity_on_hand' => 2,
    'reorder_level' => 5,
]) === true, 'on hand below reorder point is flagged');
$assert(bakery_ingredient_needs_reorder([
    'quantity_on_hand' => 5,
    'reorder_level' => 5,
]) === true, 'on hand equal to reorder point is flagged');
$assert(bakery_ingredient_needs_reorder([
    'quantity_on_hand' => 5.1,
    'reorder_level' => 5,
]) === false, 'on hand above reorder point is not flagged');
$assert(bakery_ingredient_needs_reorder([
    'quantity_on_hand' => 0,
    'reorder_level' => null,
]) === false, 'missing reorder point is not flagged');
$assert(bakery_ingredient_needs_reorder([
    'quantity_on_hand' => null,
    'reorder_level' => 1,
]) === true, 'blank on hand counts as zero against a reorder point');

$migration = $root . '/database/schema/086_ingredient_prices.sql';
$migrationSql = (string)file_get_contents($migration);
[$safe, $safeMessage] = bakery_hosted_migration_sql_safe($migrationSql);
$assert($safe, '086 is an additive hosted migration: ' . $safeMessage);
$assert(strpos($migrationSql, 'ingredient_price_history') !== false, '086 creates ingredient_price_history');
$assert(strpos($migrationSql, 'reorder_qty') !== false, '086 adds reorder_qty');
foreach ([
    'ingredient_id', 'vendor', 'vendor_sku', 'pack_size_grams', 'pack_price',
    'cost_per_kg', 'invoice_number', 'invoice_date', 'entered_by', 'created_at',
] as $column) {
    $assert(strpos($migrationSql, $column) !== false, '086 includes ' . $column);
}

if (!table_exists($db, 'ingredient_price_history')) {
    bakery_run_sql_file($db, $migration);
    bakery_forget_table_exists('ingredient_price_history');
}
$assert(table_exists($db, 'ingredient_price_history'), 'price history table exists on bakerysf_test');
$qtyColumn = $db->prepare(
    'SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
);
$qtyColumn->execute(['ingredients', 'reorder_qty']);
$assert((int)$qtyColumn->fetchColumn() === 1, 'ingredients.reorder_qty exists');

echo "\n=== Current cost is the latest invoice date ===\n";
$prefix = 'zzp-' . bin2hex(random_bytes(3)) . '-';
$cleanup = $db->prepare('DELETE FROM ingredients WHERE name LIKE ?');
$cleanup->execute([$prefix . '%']);

$insert = $db->prepare(
    'INSERT INTO ingredients (name, unit, quantity_on_hand, reorder_level, reorder_qty, supplier_name)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$insert->execute([$prefix . 'flour', 'kg', 2, 10, 4, 'Old Supplier']);
$flourId = (int)$db->lastInsertId();
$insert->execute([$prefix . 'salt', 'kg', 1, 5, 2, 'KGBS']);
$saltId = (int)$db->lastInsertId();
$insert->execute([$prefix . 'yeast', 'g', 2, 10, 6, 'BakeMark']);
$yeastId = (int)$db->lastInsertId();
$insert->execute([$prefix . 'oil', 'L', 30, 5, 1, 'KGBS']);
$oilId = (int)$db->lastInsertId();
$insert->execute([$prefix . 'seeds', 'kg', 0, 2, null, '']);
$seedsId = (int)$db->lastInsertId();

$userId = (int)$db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
$enteredBy = $userId > 0 ? $userId : null;

$older = bakery_ingredient_price_add($db, [
    'ingredient_id' => $flourId,
    'vendor' => 'BakeMark',
    'vendor_sku' => 'BM-OLD',
    'pack_size_grams' => 10000,
    'pack_price' => 50,
    'invoice_number' => 'OLD-1',
    'invoice_date' => '2026-01-15',
    'entered_by' => $enteredBy,
]);
$latest = bakery_ingredient_price_add($db, [
    'ingredient_id' => $flourId,
    'vendor' => 'KGBS',
    'vendor_sku' => 'KG-NEW',
    'pack_size_grams' => 20000,
    'pack_price' => 24,
    'invoice_number' => 'NEW-9',
    'invoice_date' => '2026-03-01',
    'entered_by' => $enteredBy,
]);
$middle = bakery_ingredient_price_add($db, [
    'ingredient_id' => $flourId,
    'vendor' => 'KGBS',
    'vendor_sku' => 'KG-MID',
    'pack_size_grams' => 10000,
    'pack_price' => 30,
    'invoice_number' => 'MID-2',
    'invoice_date' => '2026-02-01',
    'entered_by' => $enteredBy,
]);
$assert(($older['ok'] ?? false) && ($latest['ok'] ?? false) && ($middle['ok'] ?? false), 'three invoice lines insert');
$near(1.2, bakery_ingredient_current_cost_per_kg($flourId), 'current cost ignores a later insert with an older invoice date');
$current = bakery_ingredient_current_price($db, $flourId);
$assert(($current['vendor'] ?? '') === 'KGBS', 'current vendor is the latest invoice vendor');
$assert(($current['invoice_number'] ?? '') === 'NEW-9', 'current row is the newest invoice date');
$assert(($current['vendor_sku'] ?? '') === 'KG-NEW', 'current SKU follows the latest invoice');

$sameDayA = bakery_ingredient_price_add($db, [
    'ingredient_id' => $saltId,
    'vendor' => 'KGBS',
    'vendor_sku' => 'SALT-A',
    'pack_size_grams' => 10000,
    'pack_price' => 10,
    'invoice_number' => 'SALT-A',
    'invoice_date' => '2026-04-01',
    'entered_by' => $enteredBy,
]);
$sameDayB = bakery_ingredient_price_add($db, [
    'ingredient_id' => $saltId,
    'vendor' => 'KGBS',
    'vendor_sku' => 'SALT-B',
    'pack_size_grams' => 10000,
    'pack_price' => 40,
    'invoice_number' => 'SALT-B',
    'invoice_date' => '2026-04-01',
    'entered_by' => $enteredBy,
]);
$assert(($sameDayA['ok'] ?? false) && ($sameDayB['ok'] ?? false), 'same-day invoice lines insert');
$near(4.0, bakery_ingredient_current_cost_per_kg($saltId), 'same invoice date uses the later row');
$history = bakery_ingredient_price_history($db, $flourId);
$assert(count($history) === 3, 'history returns every invoice line');
$assert(($history[0]['invoice_number'] ?? '') === 'NEW-9', 'history is newest invoice first');

echo "\n=== Draft PO groups flagged ingredients by vendor ===\n";
bakery_ingredient_price_add($db, [
    'ingredient_id' => $yeastId,
    'vendor' => 'BakeMark',
    'vendor_sku' => 'YST-1',
    'pack_size_grams' => 500,
    'pack_price' => 8.5,
    'invoice_number' => 'Y-1',
    'invoice_date' => '2026-04-02',
    'entered_by' => $enteredBy,
]);
$po = bakery_ingredient_draft_purchase_order($db, [$flourId, $saltId, $yeastId, $oilId, $seedsId]);
$assert(($po['sends'] ?? true) === false, 'draft PO never sends');
$groups = [];
foreach ($po['vendors'] as $group) {
    $groups[$group['vendor']] = array_column($group['lines'], 'ingredient_id');
}
$assert(isset($groups['KGBS']) && in_array($flourId, $groups['KGBS'], true) && in_array($saltId, $groups['KGBS'], true), 'KGBS lines group together');
$assert(!in_array($oilId, $groups['KGBS'] ?? [], true), 'ingredient above its reorder point is left off the draft');
$assert(isset($groups['BakeMark']) && $groups['BakeMark'] === [$yeastId], 'BakeMark is its own group');
$assert(isset($groups['No vendor']) && in_array($seedsId, $groups['No vendor'], true), 'missing vendor is grouped on its own');
$flourLine = null;
$seedLine = null;
foreach ($po['vendors'] as $group) {
    foreach ($group['lines'] as $line) {
        if ((int)$line['ingredient_id'] === $flourId) {
            $flourLine = $line;
        }
        if ((int)$line['ingredient_id'] === $seedsId) {
            $seedLine = $line;
        }
    }
}
$assert($flourLine && (float)$flourLine['suggested_qty'] === 4.0, 'suggested qty uses reorder_qty');
$near(24.0, $flourLine['pack_price'] ?? -1, 'draft line keeps the latest pack price');
$assert($seedLine && (float)$seedLine['suggested_qty'] === 1.0, 'missing reorder qty suggests one');
$assert($seedLine && $seedLine['pack_price'] === null, 'unpriced line has no pack price');

echo "\n=== CSV import dry-run does not write; --apply does ===\n";
$before = (int)$db->query('SELECT COUNT(*) FROM ingredient_price_history')->fetchColumn();
$csv = tempnam(sys_get_temp_dir(), 'ingprice');
$csvPath = $csv . '.csv';
rename($csv, $csvPath);
file_put_contents($csvPath, implode("\n", [
    'ingredient name,vendor,SKU,pack grams,pack price,invoice number,invoice date',
    $prefix . 'flour,KGBS,KG-CSV,20000,30.00,CSV-1,2026-06-01',
    'Not A Real Ingredient,BakeMark,BM-9,5000,10.00,CSV-2,2026-06-02',
    $prefix . 'salt,KGBS,BAD,0,10.00,CSV-3,2026-06-03',
]) . "\n");

$dry = bakery_ingredient_prices_import_csv($db, $csvPath, false);
$afterDry = (int)$db->query('SELECT COUNT(*) FROM ingredient_price_history')->fetchColumn();
$assert(($dry['dry_run'] ?? false) === true, 'import defaults to dry-run');
$assert($afterDry === $before, 'dry-run writes no price rows');
$assert(count($dry['matched'] ?? []) === 1, 'dry-run matches the known ingredient');
$assert(count($dry['unmatched'] ?? []) === 1, 'dry-run reports the unknown ingredient');
$assert(($dry['unmatched'][0]['ingredient_name'] ?? '') === 'Not A Real Ingredient', 'unmatched row keeps the source name');
$assert(count($dry['invalid'] ?? []) === 1, 'dry-run reports the zero-gram row');

$applied = bakery_ingredient_prices_import_csv($db, $csvPath, true);
$afterApply = (int)$db->query('SELECT COUNT(*) FROM ingredient_price_history')->fetchColumn();
$assert(($applied['applied'] ?? 0) === 1, 'apply writes the matched row only');
$assert($afterApply === $before + 1, 'apply adds one history row');
$assert(count($applied['unmatched'] ?? []) === 1, 'apply still reports unmatched rows');
$near(1.5, bakery_ingredient_current_cost_per_kg($flourId), 'applied June invoice becomes the current cost');

$cli = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/import_ingredient_prices.php') . ' ' . escapeshellarg($csvPath);
$dryOut = [];
$dryCode = 0;
exec($cli . ' 2>&1', $dryOut, $dryCode);
$afterCliDry = (int)$db->query('SELECT COUNT(*) FROM ingredient_price_history')->fetchColumn();
$assert($dryCode === 0, 'CLI dry-run exits 0');
$assert($afterCliDry === $afterApply, 'CLI dry-run does not write');
$assert(strpos(implode("\n", $dryOut), 'Not A Real Ingredient') !== false, 'CLI dry-run prints unmatched names');

$applyOut = [];
$applyCode = 0;
exec($cli . ' --apply 2>&1', $applyOut, $applyCode);
$afterCliApply = (int)$db->query('SELECT COUNT(*) FROM ingredient_price_history')->fetchColumn();
$assert($applyCode === 0, 'CLI --apply exits 0');
$assert($afterCliApply === $afterApply + 1, 'CLI --apply writes one more history row');

@unlink($csvPath);

echo "\n=== Optional price_type: only invoice rows import ===\n";
$helpOut = [];
$helpCode = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/import_ingredient_prices.php') . ' --help 2>&1',
    $helpOut,
    $helpCode
);
$helpText = implode("\n", $helpOut);
$assert($helpCode === 0, 'importer help exits 0');
$assert(strpos($helpText, 'price_type') !== false, 'importer help names the optional price_type column');
$assert(strpos($helpText, 'quote') !== false && strpos($helpText, 'public_list') !== false, 'importer help names quote and public_list');
$assert(stripos($helpText, 'blank') !== false && stripos($helpText, 'invoice') !== false, 'importer help treats a missing price_type as invoice');
$assert(stripos($helpText, 'refus') !== false, 'importer help says a non-invoice row is refused');
$scriptDoc = (string)file_get_contents($root . '/scripts/import_ingredient_prices.php');
$assert(strpos($scriptDoc, 'price_type') !== false && strpos($scriptDoc, 'public_list') !== false, 'importer file header documents price_type');

$migration093 = $root . '/database/schema/093_ingredient_price_type.sql';
$assert(is_file($migration093), '093_ingredient_price_type.sql stores non-invoice prices');
$migration093Sql = (string)file_get_contents($migration093);
[$safe093, $safe093Message] = bakery_hosted_migration_sql_safe($migration093Sql);
$assert($safe093, '093 is an additive hosted migration: ' . $safe093Message);
$assert(strpos($migration093Sql, 'price_type') !== false, '093 adds price_type');
$assert(strpos($migration093Sql, '091') !== false && strpos($migration093Sql, '092') !== false, '093 notes that 091 and 092 stay with the time clock');
if (!column_exists($db, 'ingredient_price_history', 'price_type')) {
    bakery_run_sql_file($db, $migration093);
    bakery_forget_column_exists('ingredient_price_history', 'price_type');
}
$assert(column_exists($db, 'ingredient_price_history', 'price_type'), 'price_type column exists on bakerysf_test');

$typeCsv = tempnam(sys_get_temp_dir(), 'ingtype');
$typeCsvPath = $typeCsv . '.csv';
rename($typeCsv, $typeCsvPath);
file_put_contents($typeCsvPath, implode("\n", [
    'ingredient name,vendor,SKU,pack grams,pack price,invoice number,invoice date,price_type',
    $prefix . 'flour,KGBS,KG-INV,20000,20.00,T-INV,2026-07-01,invoice',
    $prefix . 'flour,KGBS,KG-BLANK,20000,22.00,T-BLANK,2026-07-02,',
    $prefix . 'flour,KGBS,KG-CASE,20000,26.00,T-CASE,2026-07-03,Invoice',
    $prefix . 'salt,KGBS,KG-QUOTE,10000,80.00,T-QUOTE,2026-08-01,quote',
    $prefix . 'yeast,BakeMark,KG-LIST,500,9.00,T-LIST,2026-08-02,public_list',
    $prefix . 'oil,KGBS,KG-BAD,1000,5.00,T-BAD,2026-08-03,catalog',
    'Not A Real Ingredient,BakeMark,BM-Q,5000,10.00,T-MISS,2026-08-04,quote',
]) . "\n");
$beforeType = (int)$db->query('SELECT COUNT(*) FROM ingredient_price_history')->fetchColumn();
$typed = bakery_ingredient_prices_import_csv($db, $typeCsvPath, true);
$afterType = (int)$db->query('SELECT COUNT(*) FROM ingredient_price_history')->fetchColumn();
$assert(($typed['applied'] ?? 0) === 3, 'invoice, blank, and Invoice rows are applied');
$assert($afterType === $beforeType + 3, 'quote, public_list, and other types write nothing');
$assert(count($typed['unmatched'] ?? []) === 0, 'a quote for an unknown ingredient is refused, not unmatched');
$invalidByInvoice = [];
foreach ($typed['invalid'] ?? [] as $invalidRow) {
    $invalidByInvoice[(string)($invalidRow['invoice_number'] ?? '')] = (string)($invalidRow['error'] ?? '');
}
$assert(($invalidByInvoice['T-QUOTE'] ?? '') === 'ingredient_prices.not_invoice', 'a quote row is refused with a per-row reason');
$assert(($invalidByInvoice['T-LIST'] ?? '') === 'ingredient_prices.not_invoice', 'a public_list row is refused with a per-row reason');
$assert(($invalidByInvoice['T-BAD'] ?? '') === 'ingredient_prices.not_invoice', 'an unknown price_type is refused');
$assert(($invalidByInvoice['T-MISS'] ?? '') === 'ingredient_prices.not_invoice', 'a non-invoice row is refused even when the name does not match');
$near(1.3, bakery_ingredient_current_cost_per_kg($flourId), 'the latest invoice cell becomes current cost');
$flourCurrent = bakery_ingredient_current_price($db, $flourId);
$assert(($flourCurrent['price_type'] ?? '') === 'invoice', 'a capitalized Invoice cell is stored as invoice');
$assert(($flourCurrent['invoice_number'] ?? '') === 'T-CASE', 'Invoice is accepted and wins on date');

$quoteStored = bakery_ingredient_price_add($db, [
    'ingredient_id' => $flourId,
    'vendor' => 'Quote Mill',
    'vendor_sku' => 'Q-1',
    'pack_size_grams' => 10000,
    'pack_price' => 100,
    'invoice_number' => 'Q-LATER',
    'invoice_date' => '2026-09-01',
    'price_type' => 'quote',
    'entered_by' => $enteredBy,
]);
$listStored = bakery_ingredient_price_add($db, [
    'ingredient_id' => $flourId,
    'vendor' => 'List Mill',
    'vendor_sku' => 'L-1',
    'pack_size_grams' => 10000,
    'pack_price' => 120,
    'invoice_number' => 'L-LATER',
    'invoice_date' => '2026-09-15',
    'price_type' => 'public_list',
    'entered_by' => $enteredBy,
]);
$assert(($quoteStored['ok'] ?? false) && ($listStored['ok'] ?? false), 'quote and public_list can be stored for the upper bound');
$near(1.3, bakery_ingredient_current_cost_per_kg($flourId), 'a later quote or public list does not become current cost');
$near(12.0, bakery_ingredient_fill_cost_per_kg($db, $flourId), 'the fill price is the latest quote or public list');
$poAfterQuote = bakery_ingredient_draft_purchase_order($db, [$flourId]);
$poPack = null;
foreach ($poAfterQuote['vendors'] as $group) {
    foreach ($group['lines'] as $line) {
        if ((int)$line['ingredient_id'] === $flourId) {
            $poPack = $line['pack_price'];
        }
    }
}
$near(26.0, $poPack ?? -1, 'the draft order keeps the invoice pack price');

$sevenCsv = tempnam(sys_get_temp_dir(), 'ingseven');
$sevenPath = $sevenCsv . '.csv';
rename($sevenCsv, $sevenPath);
file_put_contents($sevenPath, implode("\n", [
    'ingredient name,vendor,SKU,pack grams,pack price,invoice number,invoice date',
    $prefix . 'oil,KGBS,OIL-7,1000,3.50,SEVEN-1,2026-07-04',
]) . "\n");
$seven = bakery_ingredient_prices_import_csv($db, $sevenPath, true);
$assert(($seven['applied'] ?? 0) === 1, 'the original 7 columns still import when price_type is absent');
$oilCurrent = bakery_ingredient_current_price($db, $oilId);
$assert(($oilCurrent['price_type'] ?? '') === 'invoice', 'a missing price_type column is stored as invoice');
$near(3.5, bakery_ingredient_current_cost_per_kg($oilId), 'a 7 column invoice becomes current cost');

$typeCli = [];
$typeCliCode = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/import_ingredient_prices.php') . ' ' . escapeshellarg($typeCsvPath) . ' 2>&1',
    $typeCli,
    $typeCliCode
);
$typeCliText = implode("\n", $typeCli);
$assert($typeCliCode === 0, 'CLI dry-run of a typed file exits 0');
$assert(strpos($typeCliText, 'Only invoice prices can become the current cost') !== false, 'CLI prints the refusal reason in words');
$assert(strpos($typeCliText, $prefix . 'salt') !== false, 'CLI names the refused row');

@unlink($typeCsvPath);
@unlink($sevenPath);

echo "\n=== Page stays on ingredients and does not send ===\n";
$page = (string)file_get_contents($root . '/ingredients.php');
$script = (string)file_get_contents($root . '/scripts/import_ingredient_prices.php');
$include = (string)file_get_contents($root . '/includes/ingredient_prices.php');
$assert(strpos($page, 'add_ingredient_price') !== false, 'ingredients page posts a price line');
$assert(strpos($page, 'bakery_require_csrf()') !== false, 'ingredients POST requires CSRF');
$csrfAt = strpos($page, 'bakery_require_csrf()');
$actionAt = strpos($page, "case 'add_ingredient_price':");
$assert($csrfAt !== false && $actionAt !== false && $csrfAt < $actionAt, 'price POST is behind the CSRF check');
$assert(strpos($page, 'ingredient_prices.draft_only') !== false, 'draft PO shows the do-not-send copy');
$assert(strpos($page, "view=po") !== false || strpos($page, "'po'") !== false, 'draft PO is a view on ingredients');
$assert(!preg_match('/\b(mail|wp_mail|curl_exec|twilio)\s*\(/i', $include . $script . $page), 'price code does not email, text, or HTTP-send');

$en = require $root . '/lang/en.php';
$es = require $root . '/lang/es.php';
$priceKeys = array_values(array_filter(array_keys($en), static function ($key) {
    return strpos((string)$key, 'ingredient_prices.') === 0;
}));
$assert(count($priceKeys) >= 10, 'English has an ingredient_prices block');
$missingEs = [];
foreach ($priceKeys as $key) {
    if (!isset($es[$key]) || $es[$key] === '') {
        $missingEs[] = $key;
    }
}
$assert($missingEs === [], 'Spanish has every ingredient_prices key (' . implode(', ', $missingEs) . ')');

$cleanup->execute([$prefix . '%']);

echo $fail . " failed\n";
exit($fail === 0 ? 0 : 1);
