<?php
/**
 * Import ingredient invoice prices from a CSV. Dry-run unless --apply is passed.
 * Writes only to a local database (bakerysf_test, bakerysf_local, bakerysf_stage_local).
 * Never emails, texts, or contacts a vendor.
 *
 * CSV columns:
 *   ingredient name, vendor, SKU, pack grams, pack price, invoice number, invoice date
 *
 * Usage:
 *   php scripts/import_ingredient_prices.php prices.csv
 *   php scripts/import_ingredient_prices.php prices.csv --apply
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/ingredient_prices.php';

$path = null;
$apply = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        $path = null;
        break;
    }
    if ($arg !== '' && $arg[0] === '-') {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(1);
    }
    if ($path !== null) {
        fwrite(STDERR, "Pass one CSV file.\n");
        exit(1);
    }
    $path = $arg;
}

if ($path === null) {
    fwrite(STDOUT, "Usage: php scripts/import_ingredient_prices.php <file.csv> [--apply]\n");
    fwrite(STDOUT, "Columns: ingredient name, vendor, SKU, pack grams, pack price, invoice number, invoice date\n");
    fwrite(STDOUT, "Dry-run is the default. --apply writes price history on the local database.\n");
    exit($argv !== null && count($argv) > 1 && in_array('--help', $argv, true) ? 0 : 1);
}

try {
    $db = check_mysql_connection();
    $result = bakery_ingredient_prices_import_csv($db, $path, $apply);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$mode = $result['dry_run'] ? 'Dry run. No rows written.' : 'Applied ' . (int)$result['applied'] . ' price line(s).';
fwrite(STDOUT, $mode . "\n");
fwrite(STDOUT, 'Matched: ' . count($result['matched']) . "\n");
fwrite(STDOUT, 'Unmatched: ' . count($result['unmatched']) . "\n");
foreach ($result['unmatched'] as $row) {
    fwrite(STDOUT, '  line ' . (int)$row['line'] . ': ' . $row['ingredient_name'] . "\n");
}
fwrite(STDOUT, 'Invalid: ' . count($result['invalid']) . "\n");
foreach ($result['invalid'] as $row) {
    fwrite(STDOUT, '  line ' . (int)$row['line'] . ': ' . $row['ingredient_name'] . ' (' . $row['error'] . ")\n");
}
exit(0);
