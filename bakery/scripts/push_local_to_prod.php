<?php
/**
 * Push local bakerysf_local → DreamHost production bakerysf.
 *
 * DESTRUCTIVE: replaces production table data with local dumps (--add-drop-table).
 * Always creates a production backup first.
 *
 * Usage:
 *   C:\php\php.exe scripts/push_local_to_prod.php --dry-run
 *   C:\php\php.exe scripts/push_local_to_prod.php --confirm-push-to-production
 *   C:\php\php.exe scripts/push_local_to_prod.php --confirm-push-to-production --include-auth
 *
 * By default excludes local auth tables (users, roles, permissions, role_permissions)
 * and schema_migrations so production login is not overwritten.
 *
 * Requires: bakery/.env, bakery/.env.production.pull, mysqldump + mysql on PATH.
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once __DIR__ . '/prod_db_cli.php';

$dryRun = in_array('--dry-run', $argv, true);
$confirmed = in_array('--confirm-push-to-production', $argv, true);
$includeAuth = in_array('--include-auth', $argv, true);

if (!$dryRun && !$confirmed) {
    fwrite(STDERR, "Refusing: push overwrites production data.\n");
    fwrite(STDERR, "Preview:  php scripts/push_local_to_prod.php --dry-run\n");
    fwrite(STDERR, "Execute:  php scripts/push_local_to_prod.php --confirm-push-to-production\n");
    exit(1);
}

try {
    $env = prod_db_load_envs($root);
    prod_db_validate_targets($env['prod'], $env['local']);

    $mysqldump = prod_db_find_cli_tool(['mysqldump', 'mariadb-dump']);
    $mysql = prod_db_find_cli_tool(['mysql', 'mariadb']);
    if (!$mysqldump || !$mysql) {
        throw new RuntimeException('Need mysqldump and mysql on PATH.');
    }

    $prodDb = prod_db_pdo_connect(
        $env['prod']['host'],
        $env['prod']['port'],
        $env['prod']['user'],
        $env['prod']['pass'],
        $env['prod']['name']
    );
    $localDb = prod_db_pdo_connect(
        $env['local']['host'],
        $env['local']['port'],
        $env['local']['user'],
        $env['local']['pass'],
        $env['local']['name']
    );

    $tables = prod_db_spot_tables();
    $prodCounts = prod_db_table_counts($prodDb, $tables);
    $localCounts = prod_db_table_counts($localDb, $tables);

    echo "=== Push preview: local → production ===\n";
    echo "Local:  {$env['local']['host']}/{$env['local']['name']}\n";
    echo "Prod:   {$env['prod']['host']}/{$env['prod']['name']}\n";
    echo "Auth tables included: " . ($includeAuth ? 'YES' : 'NO (default)') . "\n\n";

    foreach ($tables as $t) {
        $p = $prodCounts[$t];
        $l = $localCounts[$t];
        $pLabel = $p === null ? 'missing' : (string)$p;
        $lLabel = $l === null ? 'missing' : (string)$l;
        echo sprintf("  %-18s prod=%-8s local=%s\n", $t . ':', $pLabel, $lLabel);
    }

    $exclude = prod_db_push_exclude_tables($includeAuth);
    if (!empty($exclude)) {
        echo "\nExcluded from push: " . implode(', ', $exclude) . "\n";
    }

    if ($dryRun) {
        echo "\nDry run only — no changes made.\n";
        exit(0);
    }

    echo "\n*** WARNING: proceeding will OVERWRITE production tables with local data. ***\n";

    $dumpDir = prod_db_ensure_dump_dir($root);
    $preBackup = $dumpDir . DIRECTORY_SEPARATOR . 'bakerysf_prod_pre_push_' . date('Ymd_His') . '.sql';
    echo "Step 1/3: Backing up production to " . basename($preBackup) . " ...\n";
    prod_db_mysqldump($env['prod'], $mysqldump, $preBackup);
    prod_db_strip_definer($preBackup);
    echo "  Production backup OK (" . round(filesize($preBackup) / 1048576, 2) . " MiB)\n";

    $localDump = $dumpDir . DIRECTORY_SEPARATOR . 'bakerysf_local_push_' . date('Ymd_His') . '.sql';
    echo "Step 2/3: Dumping local database ...\n";
    prod_db_mysqldump($env['local'], $mysqldump, $localDump, ['ignore_tables' => $exclude]);
    prod_db_strip_definer($localDump);
    prod_db_rewrite_db_name($localDump, $env['local']['name'], $env['prod']['name']);
    echo "  Local dump OK (" . round(filesize($localDump) / 1048576, 2) . " MiB)\n";

    echo "Step 3/3: Importing into production ...\n";
    prod_db_mysql_import($env['prod'], $mysql, $localDump);

    $afterCounts = prod_db_table_counts($prodDb, $tables);
    echo "\nSpot-check after push:\n";
    foreach ($tables as $t) {
        if (in_array($t, $exclude, true)) {
            echo "  {$t}: skipped (excluded)\n";
            continue;
        }
        $before = $prodCounts[$t];
        $after = $afterCounts[$t];
        $local = $localCounts[$t];
        echo "  {$t}: prod {$before} → {$after} (local was {$local})\n";
    }

    echo "\nPush complete.\n";
    echo "Production rollback backup: " . (realpath($preBackup) ?: $preBackup) . "\n";
    echo "Local dump used: " . (realpath($localDump) ?: $localDump) . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Push failed: " . $e->getMessage() . "\n");
    exit(1);
}
