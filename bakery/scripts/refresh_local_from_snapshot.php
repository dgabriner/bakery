<?php
/**
 * Refresh one explicitly local target from a verified .sql.gz snapshot.
 *
 * The snapshot is first imported into a temporary database and checked. The
 * existing target is checkpointed before replacement. A failed replacement
 * restores that checkpoint, so a partial import never becomes the target.
 *
 * Usage:
 *   php scripts/refresh_local_from_snapshot.php --snapshot=path --target=bakerysf_stage_local
 *   php scripts/refresh_local_from_snapshot.php --snapshot=path --target=bakerysf_test
 *   php scripts/refresh_local_from_snapshot.php --snapshot=path --target=bakerysf_restore_drill
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once __DIR__ . '/prod_db_cli.php';

$snapshot = '';
$target = '';
$verifyOnly = false;
$resultPath = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--snapshot=') === 0) $snapshot = substr($arg, 11);
    if (strpos($arg, '--target=') === 0) $target = substr($arg, 9);
    if ($arg === '--verify-only') $verifyOnly = true;
    if (strpos($arg, '--result=') === 0) $resultPath = substr($arg, 9);
}

if ($snapshot === '' || (!$verifyOnly && $target === '')) {
    fwrite(STDERR, "Usage: php scripts/refresh_local_from_snapshot.php --snapshot=path --target=bakerysf_local|bakerysf_stage_local|bakerysf_test|bakerysf_restore_drill\n");
    exit(1);
}

$allowedTargets = ['bakerysf_local', 'bakerysf_stage_local', 'bakerysf_test'];
if (!$verifyOnly && !in_array(strtolower($target), $allowedTargets, true)) {
    fwrite(STDERR, "Refusing: target is not an approved local database.\n");
    exit(1);
}
$target = strtolower($target);

function refresh_sql_from_gzip($gzip, $sql) {
    $in = gzopen($gzip, 'rb');
    $out = fopen($sql, 'wb');
    if (!$in || !$out) {
        if (is_resource($out)) fclose($out);
        if (is_resource($in)) gzclose($in);
        throw new RuntimeException('Cannot open snapshot extraction streams.');
    }
    try {
        while (!gzeof($in)) {
            $chunk = gzread($in, 1024 * 1024);
            if ($chunk === false) throw new RuntimeException('Cannot read compressed snapshot.');
            if ($chunk !== '' && fwrite($out, $chunk) !== strlen($chunk)) throw new RuntimeException('Cannot extract snapshot SQL.');
        }
    } finally {
        gzclose($in);
        fclose($out);
    }
}

function refresh_pdo($cfg, $name = null) {
    return prod_db_pdo_connect($cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'], $name);
}

function refresh_ident($name) {
    return '`' . str_replace('`', '``', $name) . '`';
}

function refresh_import($cfg, $mysql, $name, $sql) {
    $local = $cfg;
    $local['name'] = $name;
    prod_db_mysql_import($local, $mysql, $sql);
}

function refresh_run_migrations($root, $name) {
    if ($name === 'bakerysf_local') {
        return;
    }
    $script = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'run_migrations.php';
    if (!is_readable($script)) {
        throw new RuntimeException('Missing canonical migration runner.');
    }
    $oldDb = getenv('DB_NAME');
    $oldUseProd = getenv('USE_PROD_DB');
    putenv('DB_NAME=' . $name);
    putenv('USE_PROD_DB=false');
    $_ENV['DB_NAME'] = $name;
    $_SERVER['DB_NAME'] = $name;
    $_ENV['USE_PROD_DB'] = 'false';
    $_SERVER['USE_PROD_DB'] = 'false';
    try {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --database=' . escapeshellarg($name), $code);
    } finally {
        if ($oldDb === false) putenv('DB_NAME'); else putenv('DB_NAME=' . $oldDb);
        if ($oldUseProd === false) putenv('USE_PROD_DB'); else putenv('USE_PROD_DB=' . $oldUseProd);
    }
    if ($code !== 0) {
        throw new RuntimeException("Canonical migrations failed for {$name} (exit {$code}).");
    }
}

try {
    $env = prod_db_load_envs($root);
    prod_db_validate_targets($env['prod'], $env['local']);
    $snapshotPath = realpath($snapshot);
    if (!$snapshotPath || !is_readable($snapshotPath) || strtolower(pathinfo($snapshotPath, PATHINFO_EXTENSION)) !== 'gz') {
        throw new RuntimeException('Snapshot must be a readable .sql.gz file.');
    }
    $mysql = prod_db_find_cli_tool(['mysql', 'mariadb']);
    $mysqldump = prod_db_find_cli_tool(['mysqldump', 'mariadb-dump']);
    if (!$mysql || !$mysqldump) throw new RuntimeException('Need mysql/mariadb and mysqldump/mariadb-dump on PATH.');

    $server = refresh_pdo($env['local'], null);
    $stamp = gmdate('Ymd_His');
    // Fixed local-only staging area keeps the required REFERENCES grant narrow.
    // The workflow is serialized; any prior failed temp is dropped below.
    $tempName = 'bakerysf_refresh_local';
    $checkpointDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dumps' . DIRECTORY_SEPARATOR . 'local-checkpoints';
    $workDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dumps' . DIRECTORY_SEPARATOR . 'refresh-work';
    foreach ([$checkpointDir, $workDir] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException("Cannot create {$dir}");
    }
    $sql = $workDir . DIRECTORY_SEPARATOR . $tempName . '.sql';
    refresh_sql_from_gzip($snapshotPath, $sql);
    prod_db_strip_definer($sql);

    $server->exec('DROP DATABASE IF EXISTS ' . refresh_ident($tempName));
    $server->exec('CREATE DATABASE ' . refresh_ident($tempName) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    echo "Importing verified snapshot into temporary {$tempName}...\n";
    refresh_import($env['local'], $mysql, $tempName, $sql);
    $tempDb = refresh_pdo($env['local'], $tempName);
    $counts = prod_db_table_counts($tempDb, prod_db_spot_tables());
    foreach (['customers', 'products', 'standing_orders', 'drivers', 'default_quantities', 'daily_orders'] as $table) {
        if ($counts[$table] === null) throw new RuntimeException("Temporary import is missing {$table}.");
    }
    refresh_run_migrations($root, $tempName);
    $counts = prod_db_table_counts($tempDb, prod_db_spot_tables());
    echo "Temporary verification passed: " . json_encode($counts, JSON_UNESCAPED_SLASHES) . "\n";

    if ($verifyOnly) {
        if ($resultPath !== '') {
            $resultDir = dirname($resultPath);
            if (!is_dir($resultDir) && !mkdir($resultDir, 0775, true) && !is_dir($resultDir)) {
                throw new RuntimeException("Cannot create verification result directory: {$resultDir}");
            }
            $result = [
                'verified_at_utc' => gmdate('c'),
                'snapshot' => $snapshotPath,
                'disposable_database' => $tempName,
                'spot_counts' => $counts,
            ];
            if (file_put_contents($resultPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException("Cannot write verification result: {$resultPath}");
            }
        }
        $tempDb = null;
        $server->exec('DROP DATABASE IF EXISTS ' . refresh_ident($tempName));
        @unlink($sql);
        echo "Verify-only complete; disposable {$tempName} dropped.\n";
        exit(0);
    }

    $checkpoint = $checkpointDir . DIRECTORY_SEPARATOR . $target . '_before_' . $stamp . '.sql';
    $targetExists = (bool)$server->query('SHOW DATABASES LIKE ' . $server->quote($target))->fetchColumn();
    if ($targetExists) {
        echo "Checkpointing {$target}...\n";
        $cfg = $env['local'];
        $cfg['name'] = $target;
        prod_db_mysqldump($cfg, $mysqldump, $checkpoint);
        prod_db_strip_definer($checkpoint);
    }

    $server->exec('DROP DATABASE IF EXISTS ' . refresh_ident($target));
    $server->exec('CREATE DATABASE ' . refresh_ident($target) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    try {
        echo "Refreshing {$target}...\n";
        refresh_import($env['local'], $mysql, $target, $sql);
        refresh_run_migrations($root, $target);
        $final = refresh_pdo($env['local'], $target);
        $finalCounts = prod_db_table_counts($final, prod_db_spot_tables());
        foreach (['customers', 'products', 'standing_orders', 'drivers', 'default_quantities', 'daily_orders'] as $table) {
            if ($finalCounts[$table] !== $counts[$table]) throw new RuntimeException("Final {$target} count mismatch for {$table}.");
        }
        echo "Refresh verified: {$target} " . json_encode($finalCounts, JSON_UNESCAPED_SLASHES) . "\n";
    } catch (Throwable $refreshError) {
        fwrite(STDERR, "Refresh failed; restoring {$target} checkpoint.\n");
        $server->exec('DROP DATABASE IF EXISTS ' . refresh_ident($target));
        $server->exec('CREATE DATABASE ' . refresh_ident($target) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        if ($targetExists && is_readable($checkpoint)) {
            $cfg = $env['local'];
            $cfg['name'] = $target;
            prod_db_mysql_import($cfg, $mysql, $checkpoint);
            echo "Checkpoint restored: {$checkpoint}\n";
        }
        throw $refreshError;
    }

    $server->exec('DROP DATABASE IF EXISTS ' . refresh_ident($tempName));
    @unlink($sql);
    echo "Done. Checkpoint: " . ($targetExists ? $checkpoint : '(target was new)') . "\n";
    exit(0);
} catch (Throwable $e) {
    if (isset($server) && $server instanceof PDO && isset($tempName)) {
        try { $server->exec('DROP DATABASE IF EXISTS ' . refresh_ident($tempName)); } catch (Throwable $ignored) {}
    }
    if (isset($sql) && is_file($sql)) @unlink($sql);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
