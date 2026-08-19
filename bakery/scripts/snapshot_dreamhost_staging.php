<?php
/**
 * Read-only dump of DreamHost staging (bakerysoftware) before schema changes.
 * Never bakerysf. Never production.
 *
 *   php scripts/snapshot_dreamhost_staging.php --confirm-snapshot-staging
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/env_loader.php';
require_once __DIR__ . '/prod_db_cli.php';

$confirm = in_array('--confirm-snapshot-staging', array_slice($argv, 1), true);
if (!$confirm) {
    fwrite(STDERR, "Usage: php scripts/snapshot_dreamhost_staging.php --confirm-snapshot-staging\n");
    exit(1);
}

bakery_clear_env_keys(['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'APP_ENV', 'USE_PROD_DB']);
$envFile = $root . DIRECTORY_SEPARATOR . '.env.staging.dreamhost';
if (!is_readable($envFile)) {
    fwrite(STDERR, "Missing gitignored .env.staging.dreamhost\n");
    exit(1);
}
bakery_load_env_file($envFile, true);

$host = strtolower((string)bakery_env('DB_HOST'));
$name = strtolower((string)bakery_env('DB_NAME'));
$user = (string)bakery_env('DB_USER');
$pass = (string)bakery_env('DB_PASS');
$port = (string)bakery_env('DB_PORT', '3306');

if ($name !== 'bakerysoftware') {
    fwrite(STDERR, "Refusing: snapshot target must be bakerysoftware, got {$name}\n");
    exit(1);
}
if ($name === 'bakerysf') {
    fwrite(STDERR, "Refusing: will not dump production bakerysf\n");
    exit(1);
}
if (strpos($host, 'sourflour') === false && strpos($host, 'dreamhost') === false) {
    fwrite(STDERR, "Refusing: staging DB host must be DreamHost MySQL\n");
    exit(1);
}

$mysqldump = prod_db_find_cli_tool(['mysqldump', 'mariadb-dump']);
if (!$mysqldump) {
    fwrite(STDERR, "Need mysqldump or mariadb-dump on PATH.\n");
    exit(1);
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
    fwrite(STDERR, "Refusing: PDO is not connected to bakerysoftware\n");
    exit(1);
}

$stamp = gmdate('Ymd_His');
$backupDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dumps' . DIRECTORY_SEPARATOR . 'staging-checkpoints';
if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
    fwrite(STDERR, "Cannot create {$backupDir}\n");
    exit(1);
}
$backup = $backupDir . DIRECTORY_SEPARATOR . "bakerysoftware_pre_migrate_{$stamp}.sql";
prod_db_mysqldump($cfg, $mysqldump, $backup);
echo json_encode([
    'ok' => true,
    'database' => 'bakerysoftware',
    'path' => $backup,
    'bytes' => filesize($backup),
], JSON_UNESCAPED_SLASHES) . "\n";
