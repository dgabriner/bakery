<?php
/**
 * Import a verified local production snapshot into DreamHost staging only.
 *
 * Target is always bakerysoftware. Never bakerysf. Never local mirror/test DBs.
 *
 *   php scripts/refresh_dreamhost_staging_from_snapshot.php --snapshot=path.sql.gz --confirm-refresh-staging
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/env_loader.php';
require_once __DIR__ . '/prod_db_cli.php';

$snapshot = '';
$confirm = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--confirm-refresh-staging') {
        $confirm = true;
        continue;
    }
    if (strpos($arg, '--snapshot=') === 0) {
        $snapshot = substr($arg, 11);
    }
}

if (!$confirm || $snapshot === '') {
    fwrite(STDERR, "Usage: php scripts/refresh_dreamhost_staging_from_snapshot.php --snapshot=path.sql.gz --confirm-refresh-staging\n");
    exit(1);
}

$envFile = $root . DIRECTORY_SEPARATOR . '.env.staging.dreamhost';
if (!is_readable($envFile)) {
    fwrite(STDERR, "Missing gitignored .env.staging.dreamhost\n");
    exit(1);
}
bakery_load_env_file($envFile);

$host = strtolower((string)bakery_env('DB_HOST'));
$name = strtolower((string)bakery_env('DB_NAME'));
$user = (string)bakery_env('DB_USER');
$pass = (string)bakery_env('DB_PASS');
$port = (string)bakery_env('DB_PORT', '3306');

if ($name !== 'bakerysoftware') {
    fwrite(STDERR, "Refusing: staging refresh target must be bakerysoftware, got {$name}\n");
    exit(1);
}
if ($name === 'bakerysf') {
    fwrite(STDERR, "Refusing: will not refresh production bakerysf\n");
    exit(1);
}
if (strpos($host, 'sourflour') === false && strpos($host, 'dreamhost') === false) {
    fwrite(STDERR, "Refusing: staging DB host must be DreamHost MySQL\n");
    exit(1);
}

$snapshotPath = realpath($snapshot);
if (!$snapshotPath || !is_readable($snapshotPath) || strtolower(pathinfo($snapshotPath, PATHINFO_EXTENSION)) !== 'gz') {
    fwrite(STDERR, "Snapshot must be a readable .sql.gz file.\n");
    exit(1);
}

function staging_sql_from_gzip($gzip, $sql) {
    $in = gzopen($gzip, 'rb');
    $out = fopen($sql, 'wb');
    if (!$in || !$out) {
        throw new RuntimeException('Cannot open snapshot extraction streams.');
    }
    try {
        while (!gzeof($in)) {
            $chunk = gzread($in, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException('Cannot read compressed snapshot.');
            }
            if ($chunk !== '' && fwrite($out, $chunk) !== strlen($chunk)) {
                throw new RuntimeException('Cannot extract snapshot SQL.');
            }
        }
    } finally {
        gzclose($in);
        fclose($out);
    }
}

try {
    $mysql = prod_db_find_cli_tool(['mysql', 'mariadb']);
    $mysqldump = prod_db_find_cli_tool(['mysqldump', 'mariadb-dump']);
    if (!$mysql) {
        throw new RuntimeException('Need mysql/mariadb on PATH.');
    }
    $cfg = [
        'host' => bakery_env('DB_HOST'),
        'port' => $port,
        'name' => 'bakerysoftware',
        'user' => $user,
        'pass' => $pass,
    ];
    $pdo = prod_db_pdo_connect($cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'], $cfg['name']);
    $actual = strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn());
    if ($actual !== 'bakerysoftware') {
        throw new RuntimeException('PDO is not connected to bakerysoftware.');
    }

    $stamp = gmdate('Ymd_His');
    $workDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dumps' . DIRECTORY_SEPARATOR . 'refresh-work';
    if (!is_dir($workDir) && !mkdir($workDir, 0775, true) && !is_dir($workDir)) {
        throw new RuntimeException("Cannot create {$workDir}");
    }
    $sql = $workDir . DIRECTORY_SEPARATOR . "staging_import_{$stamp}.sql";
    echo "Extracting snapshot...\n";
    staging_sql_from_gzip($snapshotPath, $sql);
    prod_db_strip_definer($sql);

    if ($mysqldump) {
        $backupDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dumps' . DIRECTORY_SEPARATOR . 'local-checkpoints';
        if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            throw new RuntimeException("Cannot create {$backupDir}");
        }
        $backup = $backupDir . DIRECTORY_SEPARATOR . "bakerysoftware_pre_refresh_{$stamp}.sql";
        echo "Checkpointing current bakerysoftware (best effort)...\n";
        try {
            prod_db_mysqldump($cfg, $mysqldump, $backup);
        } catch (Throwable $e) {
            echo "Checkpoint skipped: " . $e->getMessage() . "\n";
        }
    }

    echo "Importing into bakerysoftware only...\n";
    prod_db_mysql_import($cfg, $mysql, $sql);
    @unlink($sql);
    $counts = prod_db_table_counts($pdo, ['customers', 'products', 'standing_orders', 'drivers', 'users', 'daily_orders']);
    echo json_encode(['ok' => true, 'database' => 'bakerysoftware', 'counts' => $counts], JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
