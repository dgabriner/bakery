<?php
/**
 * Capture one verified, compressed, read-only production snapshot.
 *
 * This command never imports or writes to production. The compressed SQL and
 * metadata are stored under storage/dumps/nightly/ (gitignored).
 *
 * Usage:
 *   php scripts/snapshot_production.php
 *   php scripts/snapshot_production.php --label=before_phase2
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
        $label = preg_replace('/[^a-zA-Z0-9_-]/', '', substr($arg, 8));
    }
}

function snapshot_json_write($path, array $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if (file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException("Cannot write metadata: {$path}");
    }
}

function snapshot_gzip_file($source, $target) {
    $in = fopen($source, 'rb');
    $out = gzopen($target, 'wb6');
    if (!$in || !$out) {
        if (is_resource($in)) fclose($in);
        if (is_resource($out)) gzclose($out);
        throw new RuntimeException('Cannot open snapshot compression streams.');
    }
    try {
        while (!feof($in)) {
            $chunk = fread($in, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException('Cannot read temporary SQL dump.');
            }
            if ($chunk !== '' && gzwrite($out, $chunk) !== strlen($chunk)) {
                throw new RuntimeException('Cannot write compressed SQL snapshot.');
            }
        }
    } finally {
        fclose($in);
        gzclose($out);
    }
}

function snapshot_validate_sql($path) {
    $sql = file_get_contents($path);
    if ($sql === false || strlen($sql) < 100) {
        throw new RuntimeException('Snapshot SQL is missing or unexpectedly small.');
    }
    $required = [
        'CREATE TABLE' => 'table definitions',
        'customers' => 'customers table',
        'products' => 'products table',
        'schema_migrations' => 'migration ledger',
        '-- Dump completed on' => 'dump completion marker',
    ];
    foreach ($required as $marker => $label) {
        if (stripos($sql, $marker) === false) {
            throw new RuntimeException("Snapshot is missing {$label} ({$marker}).");
        }
    }
}

try {
    $env = prod_db_load_envs($root);
    prod_db_validate_targets($env['prod'], $env['local']);
    $mysqldump = prod_db_find_cli_tool(['mysqldump', 'mariadb-dump']);
    if (!$mysqldump) {
        throw new RuntimeException('Need mysqldump or mariadb-dump on PATH.');
    }

    $nightly = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dumps' . DIRECTORY_SEPARATOR . 'nightly';
    if (!is_dir($nightly) && !mkdir($nightly, 0775, true) && !is_dir($nightly)) {
        throw new RuntimeException("Cannot create {$nightly}");
    }

    $stamp = gmdate('Ymd_His');
    $suffix = $label !== '' ? "_{$label}" : '';
    $base = 'live_' . $stamp . $suffix;
    $sql = $nightly . DIRECTORY_SEPARATOR . $base . '.sql';
    $gz = $nightly . DIRECTORY_SEPARATOR . $base . '.sql.gz';
    $meta = $nightly . DIRECTORY_SEPARATOR . $base . '.json';

    echo "Connecting to production {$env['prod']['host']}/{$env['prod']['name']}...\n";
    $prod = prod_db_pdo_connect(
        $env['prod']['host'],
        $env['prod']['port'],
        $env['prod']['user'],
        $env['prod']['pass'],
        $env['prod']['name']
    );
    $counts = prod_db_table_counts($prod, prod_db_spot_tables());
    $ledger = ['count' => null, 'max_id' => null];
    try {
        $ledger = $prod->query('SELECT COUNT(*) AS c, MAX(id) AS max_id FROM schema_migrations')->fetch();
        $ledger = ['count' => (int)$ledger['c'], 'max_id' => $ledger['max_id'] === null ? null : (int)$ledger['max_id']];
    } catch (Throwable $e) {
        // Metadata remains useful for older databases without a ledger.
    }
    echo "Production spot counts: " . json_encode($counts, JSON_UNESCAPED_SLASHES) . "\n";

    prod_db_mysqldump($env['prod'], $mysqldump, $sql);
    prod_db_strip_definer($sql);
    snapshot_validate_sql($sql);
    snapshot_gzip_file($sql, $gz);
    if (!is_readable($gz) || filesize($gz) < 100) {
        throw new RuntimeException('Compressed snapshot is missing or unexpectedly small.');
    }
    $sha = hash_file('sha256', $gz);
    if (!$sha) {
        throw new RuntimeException('Cannot hash compressed snapshot.');
    }

    $metadata = [
        'format' => 1,
        'captured_at_utc' => gmdate('c'),
        'source' => ['host' => $env['prod']['host'], 'database' => $env['prod']['name']],
        'snapshot' => basename($gz),
        'bytes' => filesize($gz),
        'sha256' => $sha,
        'spot_counts' => $counts,
        'schema_migrations' => $ledger,
        'verification' => ['sql_markers' => true, 'gzip_size_nonzero' => true],
    ];
    snapshot_json_write($meta, $metadata);
    @unlink($sql);

    echo "Snapshot verified: " . basename($gz) . "\n";
    echo "Size: " . filesize($gz) . " bytes\n";
    echo "SHA-256: {$sha}\n";
    echo "Metadata: " . basename($meta) . "\n";
    exit(0);
} catch (Throwable $e) {
    if (isset($sql) && is_file($sql)) @unlink($sql);
    if (isset($gz) && is_file($gz) && !isset($sha)) @unlink($gz);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
