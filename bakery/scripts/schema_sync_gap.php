<?php
/**
 * Print 050+ schema ids present on main and absent from a target ledger.
 * Reads two id lists. Does not connect to a database.
 *
 *   php scripts/schema_sync_gap.php --main=main.txt --applied=applied.txt
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
require_once dirname(__DIR__) . '/includes/schema_migration_numbers.php';

$mainPath = '';
$appliedPath = '';
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--main=') === 0) {
        $mainPath = substr($arg, 7);
    } elseif (strpos($arg, '--applied=') === 0) {
        $appliedPath = substr($arg, 10);
    }
}
if ($mainPath === '' || $appliedPath === '' || !is_file($mainPath) || !is_file($appliedPath)) {
    fwrite(STDERR, "Usage: php scripts/schema_sync_gap.php --main=ids.txt --applied=ids.txt\n");
    exit(2);
}

$read = static function (string $path): array {
    $ids = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim((string)$line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $ids[] = $line;
    }
    return $ids;
};

foreach (bakery_schema_migrations_unapplied($read($mainPath), $read($appliedPath)) as $id) {
    echo $id, "\n";
}
exit(0);
