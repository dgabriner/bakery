<?php
/**
 * Print the next unused schema migration id so concurrent agents do not reuse 062.
 *
 *   php scripts/next_schema_migration.php --name=my_feature
 *   php scripts/next_schema_migration.php --json
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
require_once dirname(__DIR__) . '/includes/schema_migration_numbers.php';

$slug = 'new_change';
$asJson = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--json') {
        $asJson = true;
        continue;
    }
    if (strpos($arg, '--name=') === 0) {
        $slug = substr($arg, 7);
    }
}

$number = bakery_schema_next_migration_number();
$id = bakery_schema_next_migration_id($slug);
$payload = [
    'number' => $number,
    'id' => $id,
    'file' => 'database/schema/' . $id . '.sql',
    'live_only_ledger_numbers' => bakery_schema_live_only_ledger_numbers(),
    'historical_duplicate_prefixes' => array_keys(bakery_schema_historical_duplicate_prefixes()),
    'unexpected_duplicates' => bakery_schema_unexpected_duplicate_prefixes(),
    'live_ledger_reuse' => bakery_schema_files_reusing_live_ledger(),
];

if ($asJson) {
    echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $blocked = $payload['unexpected_duplicates'] !== [] || $payload['live_ledger_reuse'] !== [];
    exit($blocked ? 1 : 0);
}

echo $id . PHP_EOL;
echo 'Write ' . $payload['file'] . PHP_EOL;
echo 'Do not reuse Live-only ledger numbers: ' . implode(', ', $payload['live_only_ledger_numbers']) . PHP_EOL;
echo '114 and 115 are taken by Live ledger rows that are not in git. The next schema file starts at 116.' . PHP_EOL;
if ($payload['live_ledger_reuse'] !== []) {
    fwrite(STDERR, "Schema files reuse Live-only ledger numbers 114 or 115: " . implode(', ', $payload['live_ledger_reuse']) . "\n");
    exit(1);
}
if ($payload['unexpected_duplicates'] !== []) {
    fwrite(STDERR, "Unexpected duplicate prefixes exist. Do not add another colliding file.\n");
    exit(1);
}
