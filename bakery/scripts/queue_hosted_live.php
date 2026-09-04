<?php
/**
 * Queue Staging → Live from the hosted Staging SSH account.
 *
 * Cloud agents (and Staging Manager) use this after files and additive
 * migrations are already on Staging. It writes the same private approval
 * manifests the Manager "Next" buttons write. Live workers pick them up.
 *
 *   BAKERY_HOSTED_STAGE_ROOT=/home/.../... \
 *     php scripts/queue_hosted_live.php --confirm-live --migration=074_slug.sql --files
 */
define('ACCESS_ALLOWED', true);
define('BAKERY_HOSTED_LIVE_QUEUE', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$confirm = in_array('--confirm-live', $argv, true);
$queueFiles = in_array('--files', $argv, true);
$migrationFile = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--migration=') === 0) {
        $migrationFile = basename(trim(substr($arg, 12)));
    }
}

if (!$confirm) {
    fwrite(STDERR, "Refusing: pass --confirm-live after the owner asked to promote Live.\n");
    exit(1);
}
if ($migrationFile === '' && !$queueFiles) {
    fwrite(STDERR, "Nothing to queue. Pass --migration=NNN_slug.sql and/or --files.\n");
    exit(1);
}

$hostedStageRoot = rtrim((string)getenv('BAKERY_HOSTED_STAGE_ROOT'), '/');
if ($hostedStageRoot === '' || !preg_match('#^/home/[^/]+/[^/]+$#', $hostedStageRoot)) {
    fwrite(STDERR, "Refusing unexpected hosted Staging application root.\n");
    exit(1);
}

$root = $hostedStageRoot;
require_once $root . '/includes/env_loader.php';
bakery_clear_env_keys(['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'APP_ENV', 'USE_PROD_DB']);
$stagingEnv = $root . DIRECTORY_SEPARATOR . '.env';
if (!is_readable($stagingEnv)) {
    fwrite(STDERR, "Missing hosted Staging .env\n");
    exit(1);
}
bakery_load_env_file($stagingEnv, true);
putenv('APP_ENV=staging');
$_ENV['APP_ENV'] = 'staging';
$_SERVER['APP_ENV'] = 'staging';
putenv('USE_PROD_DB=false');
$_ENV['USE_PROD_DB'] = 'false';
$_SERVER['USE_PROD_DB'] = 'false';

require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/staging_live_approval.php';
require_once $root . '/includes/hosted_migration_approval.php';

if (!defined('IS_STAGING') || !IS_STAGING) {
    fwrite(STDERR, "Refusing: this CLI only queues Live from hosted Staging.\n");
    exit(1);
}

try {
    if ($migrationFile !== '') {
        $record = bakery_hosted_migration_approve($migrationFile);
        echo 'Queued Live migration ' . ($record['migration_id'] ?? $migrationFile) . "\n";
    }
    if ($queueFiles) {
        $record = bakery_staging_live_approval_submit();
        echo 'Queued Live files release ' . ($record['release_id'] ?? '') . ' (' . (int)($record['file_count'] ?? 0) . " files)\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
