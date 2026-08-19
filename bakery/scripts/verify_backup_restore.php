<?php
/** Restore one verified snapshot into a disposable local DB, validate it, then drop it. */
define('ACCESS_ALLOWED', true);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

$root = dirname(__DIR__);
require_once __DIR__ . '/prod_db_cli.php';
$snapshot = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--snapshot=') === 0) $snapshot = substr($arg, 11);
}

function drill_write_json(string $path, array $data): void {
    if (file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException("Cannot write restore-drill receipt: {$path}");
    }
}

$target = 'bakerysf_refresh_local';
$receiptDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dumps' . DIRECTORY_SEPARATOR . 'restore-drills';
$resultPath = '';
try {
    if ($snapshot === '') {
        $candidates = array_merge(
            glob($root . '/storage/dumps/weekly/*.sql.gz') ?: [],
            glob($root . '/storage/dumps/nightly/*.sql.gz') ?: []
        );
        usort($candidates, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $snapshot = $candidates[0] ?? '';
    }
    $snapshotPath = realpath($snapshot);
    if (!$snapshotPath || !is_readable($snapshotPath) || !str_ends_with(strtolower($snapshotPath), '.sql.gz')) {
        throw new RuntimeException('Restore drill needs a readable .sql.gz snapshot.');
    }
    $metaPath = preg_replace('/\.sql\.gz$/i', '.json', $snapshotPath);
    if (!$metaPath || !is_readable($metaPath)) throw new RuntimeException('Snapshot metadata is missing.');
    $meta = json_decode((string)file_get_contents($metaPath), true, 512, JSON_THROW_ON_ERROR);
    $actualHash = hash_file('sha256', $snapshotPath);
    if (!$actualHash || !hash_equals(strtolower((string)($meta['sha256'] ?? '')), strtolower($actualHash))) {
        throw new RuntimeException('Snapshot SHA-256 does not match metadata.');
    }

    if (!is_dir($receiptDir) && !mkdir($receiptDir, 0775, true) && !is_dir($receiptDir)) {
        throw new RuntimeException('Cannot create restore-drill receipt directory.');
    }
    $resultPath = $receiptDir . DIRECTORY_SEPARATOR . '.restore_result_' . getmypid() . '.json';
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/refresh_local_from_snapshot.php')
        . ' --snapshot=' . escapeshellarg($snapshotPath) . ' --verify-only --result=' . escapeshellarg($resultPath);
    passthru($command, $code);
    if ($code !== 0) throw new RuntimeException("Disposable restore failed (exit {$code}).");
    if (!is_readable($resultPath)) throw new RuntimeException('Restore verification result is missing.');
    $result = json_decode((string)file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
    $counts = $result['spot_counts'] ?? [];
    foreach (($meta['spot_counts'] ?? []) as $table => $expected) {
        if (array_key_exists($table, $counts) && $expected !== null && $counts[$table] !== (int)$expected) {
            throw new RuntimeException("Restore count mismatch for {$table}: got {$counts[$table]}, expected {$expected}.");
        }
    }

    $receipt = $receiptDir . DIRECTORY_SEPARATOR . 'restore_' . gmdate('Ymd_His') . '.json';
    drill_write_json($receipt, [
        'verified_at_utc' => gmdate('c'),
        'snapshot' => $snapshotPath,
        'sha256' => $actualHash,
        'disposable_database' => $target,
        'spot_counts' => $counts,
        'database_dropped_after_test' => true,
    ]);
    echo "Restore drill passed: " . basename($snapshotPath) . "\n";
    echo "Receipt: {$receipt}\n";
    $exitCode = 0;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($resultPath !== '' && is_file($resultPath)) @unlink($resultPath);
}
exit($exitCode);
