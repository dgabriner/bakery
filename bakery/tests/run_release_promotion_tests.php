<?php
/**
 * Source contracts for staging → immutable candidate → Live promotion.
 * Does not contact production.
 *
 *   php tests/run_release_promotion_tests.php
 */
if (PHP_SAPI !== 'cli') { exit(1); }

define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
$fail = 0;
$assert = function ($ok, $label) use (&$fail) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
};

$create = file_get_contents($root . '/scripts/create_release_candidate.ps1');
$promote = file_get_contents($root . '/scripts/promote_release.ps1');
$manifest = file_get_contents($root . '/scripts/deploy_manifest.ps1');
$pushLive = file_get_contents($root . '/scripts/push_sftp.ps1');
$control = file_get_contents($root . '/includes/auto_push_control.php');
$sftp = file_get_contents($root . '/scripts/sftp_upload.py');
$api = file_get_contents($root . '/auto_push_api.php');

$assert(strpos($manifest, 'function Write-BakeryJsonFile') !== false, 'JSON writer helper exists');
$assert(strpos($manifest, 'UTF8Encoding $false') !== false, 'JSON writer is UTF-8 without BOM');
$assert(strpos($manifest, 'function Get-BakeryLatestCompleteStagingManifest') !== false, 'complete staging manifest picker exists');
$assert(strpos($manifest, 'function Resolve-BakeryReleaseFile') !== false, 'release file reconstruction exists');
$assert(strpos($manifest, 'function Get-BakeryEffectiveStagingRelease') !== false, 'effective staging overlay exists');
$assert(strpos($manifest, 'function Fetch-BakeryStagingFiles') !== false, 'staging fetch helper exists');
$assert(strpos($manifest, 'function Restore-BakeryReleaseFiles') !== false, 'release restore helper exists');
$assert(strpos($create, 'Get-BakeryEffectiveStagingRelease') !== false, 'candidate uses effective staging overlay');
$assert(strpos($sftp, "'--fetch'") !== false || strpos($sftp, '"--fetch"') !== false || strpos($sftp, '--fetch') !== false, 'SFTP tool can fetch staging files');
$assert(strpos($create, 'Deployable files changed after the staging manifest') === false, 'candidate no longer requires HEAD to match staging');
$assert(strpos($create, 'Deployable working tree is dirty. No candidate can be created') === false, 'candidate allows a dirty working tree');
$assert(strpos($create, 'CANDIDATE_PATH=') !== false, 'candidate prints machine-readable path');
$assert(strpos($create, 'Write-BakeryJsonFile') !== false, 'candidate writes JSON without BOM');
$assert(strpos($create, 'git_commit = $stagingCommit') !== false, 'candidate git_commit is the staging-tested commit');
$assert(strpos($promote, 'Candidate Git commit is not current HEAD') === false, 'promote does not require current HEAD');
$assert(strpos($promote, 'Deployable working tree is dirty. Commit or set aside these files before promoting') === false, 'promote does not require a clean working tree');
$assert(strpos($promote, 'Restore-BakeryReleaseFiles') !== false, 'promote reconstructs staging-tested files');
$assert(strpos($promote, '-UploadList') !== false && strpos($promote, '-SourceRoot') !== false, 'promote uploads reconstructed files only');
$assert(strpos($manifest, 'function Select-BakeryChangedReleaseFiles') !== false, 'live hash delta picker exists');
$assert(strpos($manifest, '$changed.ToArray()') !== false && strpos($manifest, '[pscustomobject]@{') !== false, 'delta picker returns PSCustomObject arrays not hashtable-wrapped generic lists');
$assert(strpos($manifest, 'LIVE_HASHES.json') !== false, 'live hash index path exists');
$assert(strpos($promote, 'Select-BakeryChangedReleaseFiles') !== false, 'promote compares candidate hashes to Live');
$assert(strpos($promote, 'already has') !== false && strpos($promote, 'differ and will be uploaded') !== false, 'promote reports unchanged vs changed counts');
$assert(strpos($pushLive, 'Merge-BakeryLiveHashIndex') !== false, 'live push records uploaded file hashes');
$assert(strpos($pushLive, '$UploadList') !== false, 'live push accepts an explicit upload list');
$assert(strpos($pushLive, '$SourceRoot') !== false, 'live push accepts an alternate source root');
$assert(strpos($control, 'function bakery_json_decode_file') !== false, 'PHP JSON reader strips BOM');
$assert(strpos($control, 'function bakery_latest_complete_staging_release') !== false, 'PHP finds the complete staging release');
$assert(strpos($control, 'CANDIDATE_PATH=') !== false, 'PHP launcher reads the created candidate path');
$assert(strpos($control, "rev-parse HEAD 2>NUL") === false, 'PHP launcher does not select candidates by local HEAD');
$assert(strpos($api, 'uploaded') !== false && strpos($api, 'changed file') !== false, 'promote API reports how many changed files were uploaded');

$bomPath = $root . '/storage/deploy/releases/_bom_probe.json';
@mkdir($root . '/storage/deploy/releases', 0770, true);
file_put_contents($bomPath, "\xEF\xBB\xBF" . json_encode(['release_id' => 'probe', 'production_status' => 'not-promoted', 'git_commit' => 'abc', 'files' => array_fill(0, 50, ['path' => 'x'])]));
require_once $root . '/includes/auto_push_control.php';
$decoded = bakery_json_decode_file($bomPath);
$assert(is_array($decoded) && ($decoded['release_id'] ?? '') === 'probe', 'BOM-prefixed candidate JSON decodes');
@unlink($bomPath);

if ($fail > 0) {
    fwrite(STDERR, $fail . " release promotion check(s) failed.\n");
    exit(1);
}
echo "OK  release promotion contracts\n";
exit(0);
