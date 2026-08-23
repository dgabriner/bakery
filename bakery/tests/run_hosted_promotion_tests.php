<?php
/** Read-only safety tests for the Staging approval manifest. */

define('IS_STAGING', true);
function bakery_user_has_role($roles) { return true; }
function bakery_current_user() { return ['email' => 'test@example.com']; }
require dirname(__DIR__) . '/includes/staging_live_approval.php';

$files = bakery_staging_live_snapshot_files();
$failed = [];
if (count($files) < 50 || count($files) > 2000) $failed[] = 'unexpected file count';
$seen = [];
foreach ($files as $entry) {
    $path = (string)($entry['path'] ?? '');
    $hash = (string)($entry['sha256'] ?? '');
    if ($path === '' || isset($seen[$path])) $failed[] = 'empty or duplicate path';
    if (!preg_match('/^[a-f0-9]{64}$/', $hash)) $failed[] = 'invalid hash for ' . $path;
    if (preg_match('#^(storage|database|scripts|tests|docs|uploads)(/|$)#', $path)) $failed[] = 'unsafe tree included: ' . $path;
    if (strpos($path, '..') !== false || strpos($path, '.env') !== false) $failed[] = 'unsafe path included: ' . $path;
    $seen[$path] = true;
}
if ($failed) {
    fwrite(STDERR, "FAIL\n - " . implode("\n - ", array_unique($failed)) . "\n");
    exit(1);
}
$paths = array_column($files, 'path');
foreach (['deploy_status.php', 'migration_status.php', 'schema_status.php'] as $required) {
    if (!in_array($required, $paths, true)) {
        fwrite(STDERR, "FAIL\n - missing hosted status file: {$required}\n");
        exit(1);
    }
}
echo 'PASS hosted promotion manifest: ' . count($files) . " safe files\n";
