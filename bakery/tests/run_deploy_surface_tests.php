<?php
/**
 * Deploy-surface regression gate (DB-free): every deployable root web file must
 * ship to Staging/Live, or a new root page can 404 while sync looks green.
 * Closes bug new-root-php-pages-can-404-on-staging-while-sync-looks-green.
 *
 * Strategy: where powershell.exe is reachable from PHP CLI, dot-source
 * scripts/deploy_manifest.ps1 and run Get-BakeryDeployFileList for a real
 * enumeration cross-check; otherwise fall back to source contracts alone.
 * PHP-side checks always run: bakery_staging_live_root_files() (the real
 * include) must equal the actual root web set on disk minus skip patterns,
 * navigation_catalog pages must be inside that set (link-rot guard).
 * Usage: php tests/run_deploy_surface_tests.php   (filesystem-only, no DB)
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;
$skipped = 0;

function deploy_surface_assert(bool $condition, string $message): void {
    global $passed, $failed;
    if ($condition) {
        echo "PASS  $message\n";
        $passed++;
        return;
    }
    echo "FAIL  $message\n";
    $failed++;
}

function deploy_surface_skip(string $message): void {
    global $skipped;
    echo "SKIP  $message\n";
    $skipped++;
}

/* Mirror of Test-BakeryDeployWebRootFile: root *.php/.js/.css/.html/.htaccess
 * minus Get-BakeryDeployExcludeNamePatterns junk (via the mirrored PHP helper),
 * plus the Get-BakeryDeployRootFiles whitelist entries that are not web-root
 * sweepable by extension (staging-robots.txt serves as robots.txt on staging). */
function deploy_surface_expected_root_files(string $root): array {
    $allowedExtensions = ['.php' => true, '.js' => true, '.css' => true, '.html' => true];
    $whitelistExtras = ['staging-robots.txt' => true];
    $files = [];
    foreach (scandir($root) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        if (!is_file($root . DIRECTORY_SEPARATOR . $name)) continue;
        if ($name !== '.htaccess' && !isset($allowedExtensions[strtolower((string)strrchr($name, '.'))]) && !isset($whitelistExtras[$name])) continue;
        if (bakery_staging_live_skip_name($name)) continue;
        $files[] = $name;
    }
    sort($files, SORT_STRING);
    return $files;
}

function deploy_surface_function_body(string $source, string $functionName): string {
    $start = strpos($source, 'function ' . $functionName);
    if ($start === false) return '';
    $next = strpos($source, "\nfunction ", $start);
    return $next === false ? substr($source, $start) : substr($source, $start, $next - $start);
}

require_once $root . '/includes/staging_live_approval.php';

/* 1. Manifest source contracts: enumeration is unconditional and authoritative. */
$manifestPath = $root . '/scripts/deploy_manifest.ps1';
$manifestSrc = (string)@file_get_contents($manifestPath);
$listBody = deploy_surface_function_body($manifestSrc, 'Get-BakeryDeployFileList');
deploy_surface_assert($listBody !== '', 'Get-BakeryDeployFileList exists in deploy_manifest.ps1');
deploy_surface_assert(
    strpos($listBody, 'Get-ChildItem -Path $BakeryRoot -File') !== false
    && strpos($listBody, 'Test-BakeryDeployWebRootFile $_') !== false,
    'Get-BakeryDeployFileList sweeps the root through Test-BakeryDeployWebRootFile'
);
deploy_surface_assert(
    strpos($listBody, '$AlsoIncludeRootModifiedAfterUtc -gt') === false,
    'root sweep is NOT gated behind the mtime parameter'
);
deploy_surface_assert(
    strpos($listBody, '[datetime]$AlsoIncludeRootModifiedAfterUtc') !== false
    && stripos($listBody, 'no-op') !== false,
    '-AlsoIncludeRootModifiedAfterUtc stays accepted for callers and is documented as a no-op'
);
deploy_surface_assert(
    strpos(deploy_surface_function_body($manifestSrc, 'Get-BakeryDeployRootFiles'), "'index.php'") !== false,
    'Get-BakeryDeployRootFiles stays defined (ordering/completeness aid)'
);

/* 2. Caller compatibility pins: nothing needed to change outside this manifest. */
$pushStageSrc = (string)@file_get_contents($root . '/scripts/push_sftp_stage.ps1');
$pushLiveSrc = (string)@file_get_contents($root . '/scripts/push_sftp.ps1');
$buildZipSrc = (string)@file_get_contents($root . '/scripts/build_deploy_zip.ps1');
deploy_surface_assert(strpos($pushStageSrc, 'AlsoIncludeRootModifiedAfterUtc') !== false,
    'push_sftp_stage.ps1 still passes -AlsoIncludeRootModifiedAfterUtc (accepted no-op)');
deploy_surface_assert(strpos($pushLiveSrc, 'AlsoIncludeRootModifiedAfterUtc') !== false,
    'push_sftp.ps1 still passes -AlsoIncludeRootModifiedAfterUtc (accepted no-op)');
deploy_surface_assert(strpos($buildZipSrc, '(Get-BakeryDeployRootFiles)') !== false,
    'build_deploy_zip.ps1 still iterates Get-BakeryDeployRootFiles directly');

/* 3. Skip-pattern mirror behaves like Get-BakeryDeployExcludeNamePatterns. */
deploy_surface_assert(bakery_staging_live_skip_name('health_deploy.php'), 'skip pattern excludes health_deploy.php');
deploy_surface_assert(bakery_staging_live_skip_name('debug_something.php'), 'skip pattern excludes debug*.php');
deploy_surface_assert(bakery_staging_live_skip_name('tmp_note.js'), 'skip pattern excludes tmp_*.js');
deploy_surface_assert(bakery_staging_live_skip_name('old_page_backup.php'), 'skip pattern excludes *_backup.php');
deploy_surface_assert(!bakery_staging_live_skip_name('login.php'), 'skip pattern keeps login.php');
deploy_surface_assert(!bakery_staging_live_skip_name('staff_alerts_api.php'), 'skip pattern keeps staff_alerts_api.php');

/* 4. PHP promotion list equals the actual root web set on disk (drift-proof). */
$liveRootFiles = bakery_staging_live_root_files();
$expectedRootFiles = deploy_surface_expected_root_files($root);
deploy_surface_assert(is_array($liveRootFiles) && $liveRootFiles !== [] && array_keys($liveRootFiles) === range(0, count($liveRootFiles) - 1),
    'bakery_staging_live_root_files returns a flat list (shape preserved)');
$missingFromPhpList = array_diff($expectedRootFiles, $liveRootFiles);
$staleInPhpList = array_diff($liveRootFiles, $expectedRootFiles);
deploy_surface_assert($missingFromPhpList === [] && $staleInPhpList === [],
    'bakery_staging_live_root_files matches disk web-root minus skip patterns'
    . ($missingFromPhpList ? ' [missing: ' . implode(', ', $missingFromPhpList) . ']' : '')
    . ($staleInPhpList ? ' [stale: ' . implode(', ', $staleInPhpList) . ']' : ''));

/* 5. Nav link-rot guard: catalog pages are inside the deployable root set. */
$catalogSrc = (string)@file_get_contents($root . '/includes/navigation_catalog.php');
$navRefs = [];
if (preg_match_all('/\'href\'\s*=>\s*\'([a-z0-9_]+\.php)\'/', $catalogSrc, $m)) {
    $navRefs = array_values(array_unique($m[1]));
}
deploy_surface_assert(count($navRefs) > 20, 'nav link extraction found the expected reference set');
$navOutsideSet = array_diff($navRefs, $liveRootFiles);
deploy_surface_assert($navOutsideSet === [],
    'every navigation_catalog page is inside bakery_staging_live_root_files'
    . ($navOutsideSet ? ' [outside: ' . implode(', ', $navOutsideSet) . ']' : ''));

/* 6. Playground-adjacent pages ship whenever they exist on disk. */
foreach (['staff_alerts_api.php', 'product_manager_plan.php', 'customer_portal_tip.php', 'deploy_status.php'] as $page) {
    if (!is_file($root . '/' . $page)) {
        deploy_surface_skip("$page not on disk (nothing to pin)");
        continue;
    }
    deploy_surface_assert(in_array($page, $liveRootFiles, true), "$page is inside the promotion root set");
}

/* 7. Live PowerShell enumeration cross-check (real Get-BakeryDeployFileList run). */
$psOutput = [];
$psCode = 1;
$q = "'";
$psManifestArg = str_replace($q, $q . $q, $manifestPath);
$psBakeryRootArg = str_replace($q, $q . $q, $root);
$command = 'powershell -NoProfile -NonInteractive -Command ". ' . $q . $psManifestArg . $q
    . '; Get-BakeryDeployFileList -BakeryRoot ' . $q . $psBakeryRootArg . $q . '"';
exec($command . ' 2>&1', $psOutput, $psCode);
if ($psCode !== 0 || $psOutput === []) {
    deploy_surface_skip('powershell.exe unreachable from PHP CLI; source contracts above carry the invariant');
} else {
    $deployList = [];
    foreach ($psOutput as $lineOut) {
        $entry = trim((string)$lineOut);
        if ($entry !== '') {
            $deployList[] = $entry;
        }
    }
    deploy_surface_assert(count($deployList) > 50,
        'Get-BakeryDeployFileList returned a full manifest (' . count($deployList) . ' files)');
    deploy_surface_assert(in_array('includes/config.php', $deployList, true),
        'recursive includes sweep still ships includes/config.php');
    $returnedRootEntries = array_values(array_filter($deployList, static function ($entry) {
        return strpos($entry, '/') === false;
    }));
    $missingFromPsList = array_diff($expectedRootFiles, $returnedRootEntries);
    $staleInPsList = array_diff($returnedRootEntries, $expectedRootFiles);
    deploy_surface_assert($missingFromPsList === [] && $staleInPsList === [],
        'Get-BakeryDeployFileList root entries equal disk web-root minus skip patterns'
        . ($missingFromPsList ? ' [missing: ' . implode(', ', $missingFromPsList) . ']' : '')
        . ($staleInPsList ? ' [unexpected: ' . implode(', ', $staleInPsList) . ']' : ''));
}

echo "\nDeploy surface tests: {$passed} passed, {$failed} failed, {$skipped} skipped\n";
exit($failed === 0 ? 0 : 1);
