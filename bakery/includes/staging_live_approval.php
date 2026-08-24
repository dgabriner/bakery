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
 * in bakery_staging_live_skip_name(), plus whitelist-only extras such as
 * staging-robots.txt (served as robots.txt on staging via root .htaccess).
 * Enumerating instead of hardcoding means this list can never drift from the
 * deploy surface again.
 *
 * Return shape unchanged: a flat list of root-relative file names.
 */
function bakery_staging_live_root_files(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $allowedExtensions = ['.php' => true, '.js' => true, '.css' => true, '.html' => true];
    $whitelistExtras = ['staging-robots.txt' => true];
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
        if ($name !== '.htaccess' && !isset($allowedExtensions[strtolower((string)strrchr($name, '.'))]) && !isset($whitelistExtras[$name])) {
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
        '.DS_Store', 'Thumbs.db', 'desktop.ini', '*.bak', '*~', '._*', '* (*).*',
    ] as $pattern) {
        if (fnmatch($pattern, $name, FNM_CASEFOLD)) return true;
    }
    return false;
}

/**
 * Same allow-list as scripts/hosted_promotion_worker.php promotion_safe_path().
 * The Live worker lives outside the web root and is not updated by file promotion,
 * so Staging must never put a rejected path in ready_for_live.json.
 */
function bakery_promotion_live_safe_relpath(string $path): bool
{
    return $path !== '' && strlen($path) <= 300 && $path[0] !== '/'
        && strpos($path, '..') === false && strpos($path, "\0") === false
        && (bool)preg_match('#^(?:\.htaccess|[A-Za-z0-9][A-Za-z0-9._/-]*)$#', $path)
        && !preg_match('#^(?:storage|database|scripts|tests|docs)(?:/|$)#', $path)
        && !preg_match('#(?:^|/)(?:\.env|\.git)(?:/|$)#', $path);
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
        if (!bakery_promotion_live_safe_relpath($relative)) {
            continue;
        }
        $paths[$relative] = $file->getPathname();
    }
}

function bakery_staging_live_snapshot_files(): array {
    $root = dirname(__DIR__);
    $paths = [];
    foreach (bakery_staging_live_root_files() as $relative) {
        if (!bakery_promotion_live_safe_relpath($relative)) {
            continue;
        }
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
    $hashCache = bakery_staging_live_hash_cache_read();
    $nextCache = [];
    $files = [];
    $totalBytes = 0;
    foreach ($paths as $relative => $absolute) {
        $size = (int)filesize($absolute);
        $mtime = (int)@filemtime($absolute);
        $totalBytes += $size;
        if ($size > 50 * 1024 * 1024 || $totalBytes > 500 * 1024 * 1024) {
            throw new RuntimeException('Staging file snapshot exceeds the promotion safety limit.');
        }
        $cached = $hashCache[$relative] ?? null;
        $hash = '';
        if (is_array($cached)
            && (int)($cached['size'] ?? -1) === $size
            && (int)($cached['mtime'] ?? -1) === $mtime
            && preg_match('/^[a-f0-9]{64}$/', (string)($cached['sha256'] ?? ''))
        ) {
            $hash = (string)$cached['sha256'];
        } else {
            $hash = hash_file('sha256', $absolute);
        }
        if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
            continue;
        }
        $nextCache[$relative] = ['size' => $size, 'mtime' => $mtime, 'sha256' => $hash];
        $files[] = ['path' => $relative, 'size' => $size, 'sha256' => $hash];
    }
    bakery_staging_live_hash_cache_write($nextCache);
    if (count($files) < 50) {
        throw new RuntimeException('Staging file snapshot is incomplete or unexpectedly large. Promotion was not queued.');
    }
    return $files;
}

function bakery_staging_live_hash_cache_path(): string {
    return bakery_staging_live_export_root() . DIRECTORY_SEPARATOR . 'file_hash_cache.json';
}

function bakery_staging_live_hash_cache_read(): array {
    $path = bakery_staging_live_hash_cache_path();
    $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    return is_array($data) ? $data : [];
}

function bakery_staging_live_hash_cache_write(array $cache): void {
    $path = bakery_staging_live_hash_cache_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return;
    }
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(3));
    if (@file_put_contents($tmp, json_encode($cache, JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) !== false) {
        @rename($tmp, $path);
        @unlink($tmp);
    }
}

function bakery_staging_live_remote_status_cache_path(string $kind): string {
    return bakery_staging_live_export_root() . DIRECTORY_SEPARATOR . 'remote_status_' . $kind . '.json';
}

function bakery_staging_live_remote_status_cache_read(string $kind, int $maxAgeSeconds = 20): ?array {
    $path = bakery_staging_live_remote_status_cache_path($kind);
    if (!is_file($path) || (time() - (int)@filemtime($path)) > $maxAgeSeconds) {
        return null;
    }
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function bakery_staging_live_remote_status_cache_write(string $kind, array $data): void {
    $path = bakery_staging_live_remote_status_cache_path($kind);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return;
    }
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(3));
    if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) !== false) {
        @rename($tmp, $path);
        @unlink($tmp);
    }
}

function bakery_staging_live_remote_status_cache_clear(?string $kind = null): void {
    foreach (($kind === null ? ['files', 'database'] : [$kind]) as $name) {
        $path = bakery_staging_live_remote_status_cache_path($name);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function bakery_pacific_display_time(string $value): string {
    $raw = trim($value);
    if ($raw === '') {
        return '';
    }
    try {
        $dt = new DateTimeImmutable($raw);
        return $dt->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('M j, Y g:i A T');
    } catch (Throwable $e) {
        return $raw;
    }
}

function bakery_staging_live_decorate_status(?array $data): ?array {
    if (!is_array($data)) {
        return null;
    }
    foreach (['completed_at', 'started_at', 'approved_at', 'updated_at'] as $key) {
        if (!empty($data[$key]) && is_string($data[$key])) {
            $data[$key . '_display'] = bakery_pacific_display_time($data[$key]);
        }
    }
    return $data;
}

function bakery_staging_live_status(bool $ingestHistory = true, bool $bypassCache = false): ?array {
    if (!$bypassCache) {
        $cached = bakery_staging_live_remote_status_cache_read('files', 20);
        if (is_array($cached)) {
            if ($ingestHistory) {
                bakery_staging_live_history_ingest_live($cached, 'files');
            }
            return $cached;
        }
    }
    $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
    $raw = @file_get_contents('https://bakery.sourflour.org/bake/deploy_status.php', false, $context);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    $status = bakery_staging_live_decorate_status(is_array($data) ? $data : null);
    if (is_array($status)) {
        bakery_staging_live_remote_status_cache_write('files', $status);
        if ($ingestHistory) {
            bakery_staging_live_history_ingest_live($status, 'files');
        }
    }
    return $status;
}

function bakery_staging_live_history_path(): string {
    return bakery_staging_live_export_root() . DIRECTORY_SEPARATOR . 'operation_history.json';
}

/** @return list<array<string,mixed>> */
function bakery_staging_live_history_read(): array {
    $path = bakery_staging_live_history_path();
    $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    $events = is_array($data) ? ($data['events'] ?? []) : [];
    return is_array($events) ? array_values(array_filter($events, 'is_array')) : [];
}

/** @param array<string,mixed> $event */
function bakery_staging_live_history_append(array $event): void {
    $events = bakery_staging_live_history_read();
    $id = (string)($event['id'] ?? '');
    if ($id === '') {
        return;
    }
    foreach ($events as $existing) {
        if ((string)($existing['id'] ?? '') === $id) {
            return;
        }
    }
    $events[] = $event;
    if (count($events) > 400) {
        $events = array_slice($events, -400);
    }
    $path = bakery_staging_live_history_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return;
    }
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(3));
    if (@file_put_contents($tmp, json_encode(['events' => $events], JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) !== false) {
        @rename($tmp, $path);
        @unlink($tmp);
    }
}

/** @param array<string,mixed> $record */
function bakery_staging_live_history_from_approval(array $record, string $kind): array {
    $status = (string)($record['status'] ?? 'queued');
    if ($status === 'approved_for_live') {
        $status = 'queued';
    }
    $release = (string)($record['release_id'] ?? '');
    $when = (string)($record['approved_at'] ?? $record['completed_at'] ?? $record['started_at'] ?? '');
    $files = [];
    foreach ((array)($record['files'] ?? []) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $path = (string)($entry['path'] ?? '');
        if ($path === '') {
            continue;
        }
        $files[] = [
            'path' => $path,
            'size' => (int)($entry['size'] ?? 0),
            'sha256' => (string)($entry['sha256'] ?? ''),
        ];
    }
    return [
        'id' => $kind . '|' . $release . '|' . $status . '|' . $when,
        'kind' => $kind,
        'status' => $status,
        'release_id' => $release,
        'migration_id' => (string)($record['migration_id'] ?? ''),
        'file' => (string)($record['file'] ?? ''),
        'approved_by' => (string)($record['approved_by'] ?? ''),
        'at' => $when,
        'file_count' => (int)($record['file_count'] ?? count($files)),
        'sha256' => (string)($record['sha256'] ?? ''),
        'message' => (string)($record['public_message'] ?? $record['message'] ?? $record['classification'] ?? ''),
        'source' => 'staging',
        'files' => $files,
    ];
}

/** @param array<string,mixed> $status */
function bakery_staging_live_history_ingest_live(array $status, string $kind): void {
    $compact = function_exists('bakery_hosted_status_history_compact')
        ? bakery_hosted_status_history_compact($status, $kind)
        : null;
    if (is_array($compact) && in_array((string)($compact['status'] ?? ''), ['succeeded', 'failed', 'rolled_back'], true)
        && (string)($compact['release_id'] ?? '') !== '') {
        $compact['source'] = 'live';
        bakery_staging_live_history_append($compact);
    }
    foreach ((array)($status['history'] ?? []) as $event) {
        if (!is_array($event)) {
            continue;
        }
        $event['kind'] = (string)($event['kind'] ?? $kind);
        $event['source'] = (string)($event['source'] ?? 'live');
        if ((string)($event['id'] ?? '') === '') {
            $event['id'] = $event['kind'] . '|' . ($event['release_id'] ?? '') . '|' . ($event['status'] ?? '') . '|' . ($event['at'] ?? $event['completed_at'] ?? '');
        }
        bakery_staging_live_history_append($event);
    }
}

function bakery_staging_live_history_scan_releases(string $root, string $kind): void {
    $releases = $root . DIRECTORY_SEPARATOR . 'releases';
    if (!is_dir($releases)) {
        return;
    }
    foreach (scandir($releases) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $json = $releases . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'release.json';
        if (!is_file($json)) {
            continue;
        }
        $record = json_decode((string)@file_get_contents($json), true);
        if (is_array($record)) {
            bakery_staging_live_history_append(bakery_staging_live_history_from_approval($record, $kind));
        }
    }
}

/** @return array{events:list<array<string,mixed>>,failed:int,files:int,database:int} */
function bakery_staging_live_history_board(): array {
    bakery_staging_live_history_scan_releases(bakery_staging_live_export_root(), 'files');
    if (function_exists('bakery_hosted_migration_export_root')) {
        bakery_staging_live_history_scan_releases(bakery_hosted_migration_export_root(), 'database');
    }
    $latestFiles = bakery_staging_live_approval_latest();
    if (is_array($latestFiles)) {
        bakery_staging_live_history_append(bakery_staging_live_history_from_approval($latestFiles, 'files'));
    }
    if (function_exists('bakery_hosted_migration_latest')) {
        $latestMigration = bakery_hosted_migration_latest();
        if (is_array($latestMigration)) {
            bakery_staging_live_history_append(bakery_staging_live_history_from_approval($latestMigration, 'database'));
        }
    }
    $events = bakery_staging_live_history_read();
    usort($events, static function ($a, $b) {
        return strcmp((string)($b['at'] ?? $b['completed_at'] ?? ''), (string)($a['at'] ?? $a['completed_at'] ?? ''));
    });
    $failed = 0;
    $files = 0;
    $database = 0;
    foreach ($events as $event) {
        $kind = (string)($event['kind'] ?? '');
        if ($kind === 'files') {
            $files++;
        } elseif ($kind === 'database') {
            $database++;
        }
        if (in_array((string)($event['status'] ?? ''), ['failed', 'rolled_back'], true)) {
            $failed++;
        }
    }
    return [
        'events' => $events,
        'failed' => $failed,
        'files' => $files,
        'database' => $database,
    ];
}

function bakery_staging_live_approval_submit(string $releaseId = '', string $commit = ''): array {
    if (!bakery_staging_live_approval_available()) {
        throw new RuntimeException('Live promotion is available only to administrators on Staging.');
    }
    $previous = bakery_staging_live_approval_latest();
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
    $previousHashes = [];
    $previousFilesRoot = '';
    if (is_array($previous)) {
        foreach ((array)($previous['files'] ?? []) as $priorEntry) {
            if (!is_array($priorEntry)) {
                continue;
            }
            $priorPath = (string)($priorEntry['path'] ?? '');
            $priorHash = (string)($priorEntry['sha256'] ?? '');
            if ($priorPath !== '' && preg_match('/^[a-f0-9]{64}$/', $priorHash)) {
                $previousHashes[$priorPath] = $priorHash;
            }
        }
        $previousRelease = (string)($previous['release_id'] ?? '');
        if ($previousRelease !== '' && preg_match('/^stage-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}$/', $previousRelease)) {
            $candidate = $releasesRoot . DIRECTORY_SEPARATOR . $previousRelease . DIRECTORY_SEPARATOR . 'files';
            if (is_dir($candidate)) {
                $previousFilesRoot = $candidate;
            }
        }
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
        $linked = false;
        if ($previousFilesRoot !== ''
            && isset($previousHashes[$relative])
            && hash_equals($previousHashes[$relative], (string)$entry['sha256'])
        ) {
            $priorFile = $previousFilesRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (is_file($priorFile) && !is_link($priorFile) && @link($priorFile, $destination)) {
                if (hash_equals((string)$entry['sha256'], (string)hash_file('sha256', $destination))) {
                    $linked = true;
                } else {
                    @unlink($destination);
                }
            }
        }
        if (!$linked && (!@copy($source, $destination) || !hash_equals((string)$entry['sha256'], hash_file('sha256', $destination)))) {
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
    bakery_staging_live_remote_status_cache_clear('files');
    bakery_staging_live_history_append(bakery_staging_live_history_from_approval($record, 'files'));
    return $record;
}
