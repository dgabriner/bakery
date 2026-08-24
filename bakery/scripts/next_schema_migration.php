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
    'historical_duplicate_prefixes' => array_keys(bakery_schema_historical_duplicate_prefixes()),
    'unexpected_duplicates' => bakery_schema_unexpected_duplicate_prefixes(),
];

if ($asJson) {
    echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($payload['unexpected_duplicates'] === [] ? 0 : 1);
}

echo $id . PHP_EOL;
echo 'Write ' . $payload['file'] . PHP_EOL;
if ($payload['unexpected_duplicates'] !== []) {
    fwrite(STDERR, "Unexpected duplicate prefixes exist. Do not add another colliding file.\n");
    exit(1);
}
