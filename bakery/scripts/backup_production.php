<?php
/**
 * Backup DreamHost production bakerysf to a single SQL file (read-only on prod).
 *
 * Usage:
 *   C:\php\php.exe scripts/backup_production.php
 *   C:\php\php.exe scripts/backup_production.php --label=before_feature_x
 *
 * Output: bakery/storage/dumps/bakerysf_prod_backup_YYYYMMDD_HHMMSS[_label].sql
 * Files are gitignored and contain PII — store securely.
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once __DIR__ . '/prod_db_cli.php';

$label = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--label=') === 0) {
        $label = preg_replace('/[^a-zA-Z0-9_-]/', '', substr($arg, strlen('--label=')));
    }
}

try {
    $env = prod_db_load_envs($root);
    prod_db_validate_targets($env['prod'], $env['local']);

    $mysqldump = prod_db_find_cli_tool(['mysqldump', 'mariadb-dump']);
    if (!$mysqldump) {
        throw new RuntimeException('Need mysqldump on PATH (Scoop MariaDB: scoop install mariadb).');
    }

    echo "Connecting to production {$env['prod']['host']}/{$env['prod']['name']}...\n";
    $prodDb = prod_db_pdo_connect(
        $env['prod']['host'],
        $env['prod']['port'],
        $env['prod']['user'],
        $env['prod']['pass'],
        $env['prod']['name']
    );

    $counts = prod_db_table_counts($prodDb, prod_db_spot_tables());
    echo "Production spot counts:\n";
    foreach ($counts as $t => $c) {
        echo "  {$t}=" . ($c === null ? 'missing' : $c) . "\n";
    }

    $dumpDir = prod_db_ensure_dump_dir($root);
    $suffix = $label !== '' ? "_{$label}" : '';
    $dumpFile = $dumpDir . DIRECTORY_SEPARATOR . 'bakerysf_prod_backup_' . date('Ymd_His') . $suffix . '.sql';

    echo "Dumping to " . basename($dumpFile) . " ...\n";
    prod_db_mysqldump($env['prod'], $mysqldump, $dumpFile);
    prod_db_strip_definer($dumpFile);

    $sizeMb = round(filesize($dumpFile) / 1048576, 2);
    $absolute = realpath($dumpFile) ?: $dumpFile;
    echo "\nBackup complete.\n";
    echo "File: {$absolute}\n";
    echo "Size: {$sizeMb} MiB\n";
    echo "Contains production PII — do not commit to git or share publicly.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    if (strpos($e->getMessage(), 'Access denied') !== false) {
        fwrite(STDERR, "Whitelist your IP in DreamHost MySQL Allowable Hosts.\n");
    }
    exit(1);
}
