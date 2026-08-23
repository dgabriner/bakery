<?php
/**
 * Staging-only hosted promotion approval.
 *
 * Approval snapshots the exact bytes currently on Staging. A restricted,
 * read-only Live worker pulls this manifest and those files; Git and localhost
 * are deliberately not part of the promotion gate.
 */

function bakery_staging_live_approval_available(): bool {
    return defined('IS_STAGING') && IS_STAGING
        && function_exists('bakery_user_has_role')
        && bakery_user_has_role(['administrator']);
}

function bakery_staging_live_approval_path(): string {
    return bakery_staging_live_export_root() . DIRECTORY_SEPARATOR . 'ready_for_live.json';
}

function bakery_staging_live_export_root(): string {
    return dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . '.sourflour-promotion-export';
}

function bakery_staging_live_approval_latest(): ?array {
    $path = bakery_staging_live_approval_path();
    if (!is_file($path)) return null;
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string)@file_get_contents($path));
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/**
 * Root web files eligible for promotion, derived from disk with the SAME rule
 * as Test-BakeryDeployWebRootFile in scripts/deploy_manifest.ps1:
 * *.php / *.js / *.css / *.html / .htaccess minus the skip patterns mirrored
 * in bakery_staging_live_skip_name(). Enumerating instead of hardcoding means
 * this list can never drift from the deploy surface again.
 *
 * Return shape unchanged: a flat list of root-relative file names.
 */
function bakery_staging_live_root_files(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $allowedExtensions = ['.php' => true, '.js' => true, '.css' => true, '.html' => true];
    $root = dirname(__DIR__);
    $files = [];
    foreach (scandir($root) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $absolute = $root . DIRECTORY_SEPARATOR . $name;
        if (!is_file($absolute) || is_link($absolute)) {
            continue;
        }
        if ($name !== '.htaccess' && !isset($allowedExtensions[strtolower((string)strrchr($name, '.'))])) {
            continue;
        }
        if (bakery_staging_live_skip_name($name)) {
            continue;
        }
        $files[] = $name;
    }
    sort($files, SORT_STRING);
    $cached = $files;
    return $files;
}

function bakery_staging_live_skip_name(string $name): bool {
    foreach ([
        '*_backup.php', '*backup.php', '*_fixed.php', '*_optimized.php', '*_working.php',
        '*Copy*.php', 'debug*.php', 'simple-debug.php', 'simple_performance_test.php',
        'health_local.php', 'health_prod.php', 'health_driver.php', 'health_deploy.php',
        'driver_pages_probe.php', 'trace_driver_list.php', 'ping.php', 'run_sql_setup.php',
        'db_test.php', 'setup_directories.php', 'oauth_setup.php', 'auto_push_api.php',
        'sourflour.html', 'tmp_*.php', 'tmp_*.js', 'tmp_*.txt',
    ] as $pattern) {
        if (fnmatch($pattern, $name, FNM_CASEFOLD)) return true;
    }
    return false;
}

function bakery_staging_live_add_tree(array &$paths, string $root, string $relativeRoot, ?array $onlyNames = null): void {
    $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeRoot);
    if (!is_dir($absolute)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink() || bakery_staging_live_skip_name($file->getFilename())) continue;
        if ($onlyNames !== null && !in_array($file->getFilename(), $onlyNames, true)) continue;
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $paths[$relative] = $file->getPathname();
    }
}

function bakery_staging_live_snapshot_files(): array {
    $root = dirname(__DIR__);
    $paths = [];
    foreach (bakery_staging_live_root_files() as $relative) {
        $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($absolute) && !is_link($absolute)) $paths[$relative] = $absolute;
    }
    foreach (['includes', 'css', 'assets', 'lang', 'vendor/phpmailer'] as $dir) {
        bakery_staging_live_add_tree($paths, $root, $dir);
    }
    ksort($paths, SORT_STRING);
    if (count($paths) < 50 || count($paths) > 2000) {
        throw new RuntimeException('Staging file snapshot is incomplete or unexpectedly large. Promotion was not queued.');
    }
    $files = [];
    $totalBytes = 0;
    foreach ($paths as $relative => $absolute) {
        $size = (int)filesize($absolute);
        $totalBytes += $size;
        if ($size > 50 * 1024 * 1024 || $totalBytes > 500 * 1024 * 1024) {
            throw new RuntimeException('Staging file snapshot exceeds the promotion safety limit.');
        }
        $files[] = ['path' => $relative, 'size' => $size, 'sha256' => hash_file('sha256', $absolute)];
    }
    return $files;
}

function bakery_staging_live_status(): ?array {
    $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
    $raw = @file_get_contents('https://bakery.sourflour.org/bake/deploy_status.php', false, $context);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : null;
}

function bakery_staging_live_approval_submit(string $releaseId = '', string $commit = ''): array {
    if (!bakery_staging_live_approval_available()) {
        throw new RuntimeException('Live promotion is available only to administrators on Staging.');
    }
    $files = bakery_staging_live_snapshot_files();
    $releaseId = 'stage-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $path = bakery_staging_live_approval_path();
    $exportRoot = bakery_staging_live_export_root();
    $releasesRoot = $exportRoot . DIRECTORY_SEPARATOR . 'releases';
    if (!is_dir($releasesRoot) && !@mkdir($releasesRoot, 0700, true) && !is_dir($releasesRoot)) {
        throw new RuntimeException('Staging approval storage is not writable.');
    }
    $user = bakery_current_user();
    $record = [
        'format' => 2,
        'status' => 'approved_for_live',
        'release_id' => $releaseId,
        'approved_at' => gmdate('c'),
        'approved_at_local' => date('c'),
        'approved_by' => (string)($user['email'] ?? $user['username'] ?? 'administrator'),
        'environment' => 'staging',
        'file_count' => count($files),
        'files' => $files,
    ];
    $releaseTemp = $releasesRoot . DIRECTORY_SEPARATOR . $releaseId . '.tmp-' . bin2hex(random_bytes(3));
    $releasePath = $releasesRoot . DIRECTORY_SEPARATOR . $releaseId;
    if (!@mkdir($releaseTemp . DIRECTORY_SEPARATOR . 'files', 0700, true)) {
        throw new RuntimeException('Could not create the private Staging release export.');
    }
    $webRoot = dirname(__DIR__);
    foreach ($files as $entry) {
        $relative = (string)$entry['path'];
        $source = $webRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $destination = $releaseTemp . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_dir(dirname($destination)) && !@mkdir(dirname($destination), 0700, true) && !is_dir(dirname($destination))) {
            throw new RuntimeException('Could not prepare the private Staging release export.');
        }
        if (!@copy($source, $destination) || !hash_equals((string)$entry['sha256'], hash_file('sha256', $destination))) {
            throw new RuntimeException('Could not verify the private Staging release export.');
        }
        @chmod($destination, 0600);
    }
    if (@file_put_contents(
        $releaseTemp . DIRECTORY_SEPARATOR . 'release.json',
        json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        LOCK_EX
    ) === false || !@rename($releaseTemp, $releasePath)) {
        throw new RuntimeException('Could not finalize the private Staging release export.');
    }
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false
        || !@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Could not queue the Staging promotion.');
    }
    return $record;
}
