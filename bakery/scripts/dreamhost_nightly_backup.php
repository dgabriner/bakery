<?php
/** CLI-only DreamHost production backup. Install outside the public web root. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit("CLI only\n"); }
$envFile = $argv[1] ?? (getenv('BAKERY_BACKUP_ENV') ?: '');
$outputDir = $argv[2] ?? (getenv('BAKERY_BACKUP_DIR') ?: '');
if ($envFile === '' || $outputDir === '') { fwrite(STDERR, "Usage: php dreamhost_nightly_backup.php /home/user/.bakery-backup.env /home/user/bakery-backups\n"); exit(2); }
if (!is_file($envFile)) { fwrite(STDERR, "Backup env file not found.\n"); exit(2); }
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line); if ($line === '' || $line[0] === '#') continue;
    [$key, $value] = array_pad(explode('=', $line, 2), 2, ''); $env[trim($key)] = trim($value, " \t\"'");
}
foreach (['DB_HOST','DB_NAME','DB_USER','DB_PASS'] as $key) if (($env[$key] ?? '') === '') { fwrite(STDERR, "Missing {$key}.\n"); exit(2); }
if (strtolower($env['DB_NAME']) !== 'bakerysf') { fwrite(STDERR, "Refusing: backup must target bakerysf only.\n"); exit(3); }
$dump = trim((string)shell_exec('command -v mysqldump 2>/dev/null')) ?: trim((string)shell_exec('command -v mariadb-dump 2>/dev/null'));
if ($dump === '') { fwrite(STDERR, "mysqldump/mariadb-dump not found.\n"); exit(4); }
if (!is_dir($outputDir) && !mkdir($outputDir, 0700, true) && !is_dir($outputDir)) { fwrite(STDERR, "Cannot create backup directory.\n"); exit(5); }
$stamp = gmdate('Ymd_His'); $sql = rtrim($outputDir, '/\\') . "/live_{$stamp}.sql"; $gz = $sql . '.gz';
$cmd = escapeshellarg($dump) . ' --single-transaction --quick --no-tablespaces --skip-routines --skip-triggers --hex-blob'
    . ' -h ' . escapeshellarg($env['DB_HOST']) . ' -P ' . escapeshellarg($env['DB_PORT'] ?? '3306')
    . ' -u ' . escapeshellarg($env['DB_USER']) . ' --password=' . escapeshellarg($env['DB_PASS']) . ' ' . escapeshellarg($env['DB_NAME']) . ' > ' . escapeshellarg($sql);
passthru($cmd, $exit);
if ($exit !== 0 || !is_file($sql) || filesize($sql) < 1024) { @unlink($sql); fwrite(STDERR, "Database dump failed.\n"); exit(6); }
$in = fopen($sql, 'rb'); $out = gzopen($gz, 'wb9'); while (!feof($in)) gzwrite($out, fread($in, 1048576)); fclose($in); gzclose($out); unlink($sql);
$hash = hash_file('sha256', $gz); file_put_contents($gz . '.sha256', $hash . "  " . basename($gz) . "\n", LOCK_EX);
$files = glob(rtrim($outputDir, '/\\') . '/live_*.sql.gz') ?: []; usort($files, fn($a,$b) => filemtime($b) <=> filemtime($a));
foreach (array_slice($files, 14) as $old) { @unlink($old); @unlink($old . '.sha256'); }
echo json_encode(['status'=>'success','file'=>$gz,'sha256'=>$hash,'retained'=>min(14,count($files))], JSON_UNESCAPED_SLASHES) . PHP_EOL;
