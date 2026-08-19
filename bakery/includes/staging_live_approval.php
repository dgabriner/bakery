<?php
/**
 * Staging-only release approval queue.
 * This records an explicit approval; it never connects to or mutates Live.
 */

function bakery_staging_live_approval_available(): bool {
    return defined('IS_STAGING') && IS_STAGING
        && function_exists('bakery_user_has_role')
        && bakery_user_has_role(['administrator']);
}

function bakery_staging_live_approval_path(): string {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'deploy'
        . DIRECTORY_SEPARATOR . 'approvals' . DIRECTORY_SEPARATOR . 'ready_for_live.json';
}

function bakery_staging_live_approval_latest(): ?array {
    $path = bakery_staging_live_approval_path();
    if (!is_file($path)) return null;
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function bakery_staging_live_approval_submit(string $releaseId, string $commit): void {
    if (!bakery_staging_live_approval_available()) {
        throw new RuntimeException('Live approval is available only to administrators on staging.');
    }
    $releaseId = trim($releaseId);
    $commit = trim($commit);
    if ($releaseId === '' || !preg_match('/^[A-Za-z0-9._:-]{3,160}$/', $releaseId)) {
        throw new RuntimeException('Enter the staging release identifier before approving.');
    }
    if ($commit !== '' && !preg_match('/^[0-9a-f]{7,64}$/i', $commit)) {
        throw new RuntimeException('The commit must be a valid Git hash.');
    }
    $path = bakery_staging_live_approval_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Staging approval storage is not writable.');
    }
    $user = bakery_current_user();
    $record = [
        'status' => 'approved_for_live',
        'release_id' => $releaseId,
        'git_commit' => $commit,
        'approved_at' => gmdate('c'),
        'approved_at_local' => date('c'),
        'approved_by' => (string)($user['email'] ?? $user['username'] ?? 'administrator'),
        'environment' => 'staging',
        'live_mutated' => false,
    ];
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false
        || !@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Could not save the staging approval.');
    }
}
