<?php
/**
 * Named-file Live queue: promotion-set limits, commit hash match, and the
 * worker contract that a snapshot still requires 50 files.
 *
 * Usage: php tests/run_files_live_only_tests.php
 * Database: bakerysf_test only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
$db = require $root . '/tests/harness.php';

require_once $root . '/includes/staging_live_approval.php';
define('BAKERY_PROMOTION_WORKER_LIBRARY', true);
require_once $root . '/scripts/hosted_promotion_worker.php';

$fail = 0;
$assert = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
};

$dbName = (string)$db->query('SELECT DATABASE()')->fetchColumn();
$assert($dbName === 'bakerysf_test', 'queries stay on bakerysf_test');

$throws = static function (callable $fn): bool {
    try {
        $fn();
    } catch (Throwable $e) {
        return true;
    }
    return false;
};

$assert(
    $throws(static function (): void {
        bakery_staging_live_files_only_normalize([]);
    }),
    'an empty files-only list is refused'
);
foreach (['staging_update.php', 'oven_light.php', 'proof_window.php', 'includes/oven_light.php'] as $blocked) {
    $assert(
        $throws(static function () use ($blocked): void {
            bakery_staging_live_files_only_normalize([$blocked]);
        }),
        'skip-list file is refused: ' . $blocked
    );
}
foreach (['scripts/deploy_manifest.ps1', 'uploads/driver_photos/.htaccess', 'tests/run_files_live_only_tests.php', '../.env', 'includes/missing-live-only.php'] as $outside) {
    $assert(
        $throws(static function () use ($outside): void {
            bakery_staging_live_files_only_normalize([$outside]);
        }),
        'path outside the promotion set is refused: ' . $outside
    );
}
$assert(
    $throws(static function (): void {
        bakery_staging_live_files_only_normalize(['route_photo_journey.php', 'route_photo_journey.php']);
    }),
    'a duplicate files-only path is refused'
);

$journey = $root . '/route_photo_journey.php';
$journeyHash = hash_file('sha256', $journey);
$entries = bakery_staging_live_files_only_entries(
    ['route_photo_journey.php', '.htaccess'],
    [
        'route_photo_journey.php' => $journeyHash,
        '.htaccess' => hash_file('sha256', $root . '/.htaccess'),
    ]
);
$assert(count($entries) === 2, 'two matching files produce two manifest entries');
$assert(($entries[0]['path'] ?? '') === 'route_photo_journey.php', 'manifest keeps the requested path');
$assert(($entries[0]['sha256'] ?? '') === $journeyHash, 'manifest hash is the file bytes');
$assert(
    $throws(static function () use ($journeyHash): void {
        bakery_staging_live_files_only_entries(
            ['route_photo_journey.php'],
            ['route_photo_journey.php' => str_repeat('ab', 32)]
        );
    }),
    'a hash that does not match the file is refused'
);
$assert($journeyHash !== str_repeat('ab', 32), 'the refusal hash is not the real file hash');

$tmp = sys_get_temp_dir() . '/files-only-' . bin2hex(random_bytes(3));
$tools = $tmp . '/.sourflour-stage-tools';
$stage = $tmp . '/web';
mkdir($tools, 0700, true);
mkdir($stage, 0700, true);
$manifestPath = $tools . '/files_live_only.json';
file_put_contents($manifestPath, json_encode([
    'commit' => '8285e99',
    'files' => [
        ['path' => 'route_photo_journey.php', 'sha256' => $journeyHash],
    ],
]));
$parsed = bakery_staging_live_read_files_only_manifest($manifestPath, $stage);
$assert(($parsed['commit'] ?? '') === '8285e99', 'manifest reader keeps the commit');
$assert(($parsed['paths'][0] ?? '') === 'route_photo_journey.php', 'manifest reader keeps the path');
$assert(($parsed['hashes']['route_photo_journey.php'] ?? '') === $journeyHash, 'manifest reader keeps the hash');
$outsideManifest = $tmp . '/elsewhere.json';
file_put_contents($outsideManifest, '{"commit":"8285e99","files":[{"path":"a.php","sha256":"' . $journeyHash . '"}]}');
$assert(
    $throws(static function () use ($outsideManifest, $stage): void {
        bakery_staging_live_read_files_only_manifest($outsideManifest, $stage);
    }),
    'a manifest outside the stage tools directory is refused'
);
@unlink($manifestPath);
@unlink($outsideManifest);
@rmdir($tools);
@rmdir($stage);
@rmdir($tmp);

$assert(promotion_manifest_scope([]) === 'snapshot', 'a manifest without scope is still a full snapshot');
$assert(promotion_manifest_scope(['scope' => 'files_only']) === 'files_only', 'files_only scope is recognized');
$assert(
    $throws(static function (): void {
        promotion_manifest_scope(['scope' => 'everything']);
    }),
    'an unknown promotion scope is refused'
);
$assert(promotion_manifest_bounds('snapshot') === ['min' => 50, 'max' => 2000], 'snapshot promotions still require 50 files');
$assert(promotion_manifest_bounds('files_only') === ['min' => 1, 'max' => 2000], 'a named list may be smaller than 50');
$assert(promotion_files_only_blocked('staging_update.php'), 'worker blocks staging_update.php');
$assert(promotion_files_only_blocked('css/oven_light.php'), 'worker blocks oven_light.php by basename');
$assert(promotion_files_only_blocked('proof_window.php'), 'worker blocks proof_window.php');
$assert(!promotion_files_only_blocked('route_photo_journey.php'), 'worker allows a normal page');
$assert(promotion_files_only_commit_ok(['commit' => '8285e99']), 'a 7 character commit is accepted');
$assert(promotion_files_only_commit_ok(['commit' => '8285e99f28104be30474357f17b3cc1f957688c2']), 'a 40 character commit is accepted');
$assert(!promotion_files_only_commit_ok(['commit' => 'main']), 'a branch name is not a commit SHA');

$merged = promotion_merge_hash_index(
    [
        ['path' => 'driver.php', 'sha256' => str_repeat('11', 32)],
        ['path' => 'route_photo_journey.php', 'sha256' => str_repeat('22', 32)],
    ],
    [
        ['path' => 'route_photo_journey.php', 'sha256' => $journeyHash, 'size' => 10],
    ]
);
$byPath = [];
foreach ($merged as $entry) {
    $byPath[$entry['path']] = $entry['sha256'];
}
$assert(isset($byPath['driver.php']) && $byPath['driver.php'] === str_repeat('11', 32), 'files-only keeps unrelated Live hashes');
$assert(($byPath['route_photo_journey.php'] ?? '') === $journeyHash, 'files-only replaces only the named hash');
$assert(count($merged) === 2, 'files-only does not drop the rest of the index');

$stagePy = file_get_contents($root . '/scripts/cloud_agent_stage.py');
$queuePhp = file_get_contents($root . '/scripts/queue_hosted_live.php');
$worker = file_get_contents($root . '/scripts/hosted_promotion_worker.php');
$manifest = file_get_contents($root . '/scripts/deploy_manifest.ps1');
$assert(strpos($stagePy, '--files-live-only') !== false, 'cloud agent exposes --files-live-only');
$assert(strpos($stagePy, 'flags.append("--files")') !== false, 'full --files-live still queues the snapshot flag');
$assert(strpos($stagePy, 'Refusing: --files-live and --files-live-only together.') !== false, 'the two Live file modes cannot be combined');
$assert(strpos($queuePhp, 'bakery_staging_live_approval_submit()') !== false, '--files still calls the full snapshot submit');
$assert(strpos($queuePhp, 'bakery_staging_live_approval_submit_files') !== false, 'files-only calls the named submit');
$assert(strpos($queuePhp, 'pass either --files or --files-only-manifest, not both') !== false, 'queue refuses both file modes');
$assert(strpos($worker, "return ['min' => 50, 'max' => 2000];") !== false, 'worker source keeps the snapshot floor');
$assert(strpos($manifest, "'staging_update.php', 'oven_light.php', 'proof_window.php'") !== false, 'deploy skip patterns still name the tour pages');

$python = <<<PY
import hashlib, importlib.util, sys
from pathlib import Path
root = Path("$root")
spec = importlib.util.spec_from_file_location("cloud_agent_stage", root / "scripts" / "cloud_agent_stage.py")
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
mod.reject_files_live_only_paths([".htaccess", "includes/photo_handler.php", "vendor/phpmailer/src/PHPMailer.php"])
for bad in ["staging_update.php", "oven_light.php", "proof_window.php", "uploads/driver_photos/.htaccess", "scripts/deploy_manifest.ps1", ""]:
    try:
        mod.reject_files_live_only_paths([bad] if bad != "" else [])
    except SystemExit:
        continue
    raise SystemExit("accepted bad path: " + bad)
try:
    mod.reject_files_live_only_paths(["route_photo_journey.php", "route_photo_journey.php"])
except SystemExit:
    pass
else:
    raise SystemExit("accepted a duplicate")
digest = mod.git_file_sha256("HEAD", "route_photo_journey.php")
disk = hashlib.sha256((root / "route_photo_journey.php").read_bytes()).hexdigest()
if digest != disk:
    raise SystemExit("git HEAD hash does not match route_photo_journey.php")
try:
    mod.resolve_commit("not-a-sha")
except SystemExit:
    pass
else:
    raise SystemExit("accepted a non SHA commit")
print("OK python files-only checks")
PY;
$pythonPath = tempnam(sys_get_temp_dir(), 'files-only-py');
file_put_contents($pythonPath, $python);
$pythonOut = [];
$pythonCode = 0;
exec('python3 ' . escapeshellarg($pythonPath) . ' 2>&1', $pythonOut, $pythonCode);
@unlink($pythonPath);
$assert($pythonCode === 0, 'python files-only checks exit 0');
$assert(in_array('OK python files-only checks', $pythonOut, true), 'python files-only checks reported OK');
if ($pythonCode !== 0) {
    fwrite(STDERR, implode("\n", $pythonOut) . "\n");
}

$changed = [
    $root . '/includes/staging_live_approval.php',
    $root . '/scripts/hosted_promotion_worker.php',
    $root . '/scripts/queue_hosted_live.php',
    $root . '/scripts/cloud_agent_stage.py',
    $root . '/tests/run_files_live_only_tests.php',
    $root . '/includes/agent_work_map.php',
];
foreach ($changed as $path) {
    $text = (string)file_get_contents($path);
    $assert(strpos($text, "\u{2013}") === false && strpos($text, "\u{2014}") === false, basename($path) . ' has no en dash or em dash');
}

if ($fail > 0) {
    fwrite(STDERR, $fail . " files-only check(s) failed.\n");
    exit(1);
}
echo "OK\n";
exit(0);
