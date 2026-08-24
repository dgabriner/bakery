<?php
/** Staging-only approval of one additive schema migration for Live. */

require_once __DIR__ . '/schema_inventory.php';
require_once __DIR__ . '/hosted_migration_runtime.php';

function bakery_hosted_migration_source_root(): string {
    return dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . '.sourflour-migration-source';
}

function bakery_hosted_migration_export_root(): string {
    return dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . '.sourflour-migration-export';
}

function bakery_hosted_migration_approval_path(): string {
    return bakery_hosted_migration_export_root() . DIRECTORY_SEPARATOR . 'ready_for_live.json';
}

function bakery_hosted_migration_candidates(): array {
    $root = bakery_hosted_migration_source_root();
    $rows = [];
    foreach (glob($root . DIRECTORY_SEPARATOR . '[0-9][0-9][0-9]_*.sql') ?: [] as $path) {
        $name = basename($path);
        if (!preg_match('/^(\d{3}_[A-Za-z0-9_]+)\.sql$/', $name)) continue;
        if ((int)substr($name, 0, 3) < 50) continue;
        $sql = (string)file_get_contents($path);
        $supersededBy = bakery_hosted_migration_superseded_by($sql);
        [$safe, $message] = bakery_hosted_migration_sql_safe($sql);
        if ($supersededBy !== null) {
            $safe = false;
            $message = 'Superseded by ' . $supersededBy . '.';
        }
        $rows[] = [
            'file' => $name, 'id' => substr($name, 0, -4), 'sha256' => hash_file('sha256', $path),
            'safe' => $safe, 'message' => $message, 'superseded_by' => $supersededBy,
        ];
    }
    usort($rows, static fn($a, $b) => strcmp($a['file'], $b['file']));
    return $rows;
}

function bakery_hosted_migration_latest(): ?array {
    $data = is_file(bakery_hosted_migration_approval_path())
        ? json_decode((string)file_get_contents(bakery_hosted_migration_approval_path()), true) : null;
    return is_array($data) ? $data : null;
}

function bakery_hosted_fetch_json(string $url, int $timeout): array {
    $status = 0;
    $raw = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: SourFlour-Staging-SchemaCompare/1'],
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (is_string($body)) {
                $raw = $body;
            }
        }
    }
    if ($raw === null) {
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: SourFlour-Staging-SchemaCompare/1\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\b/', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
        if (is_string($body)) {
            $raw = $body;
        }
    }
    return ['raw' => $raw, 'status' => $status];
}

function bakery_hosted_live_schema_cache_path(): string {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'deploy'
        . DIRECTORY_SEPARATOR . 'LIVE_SCHEMA_INVENTORY_CACHE.json';
}

function bakery_hosted_live_schema_cache_read(int $maxAgeSeconds = 300): ?array {
    $path = bakery_hosted_live_schema_cache_path();
    if (!is_file($path) || (time() - (int)@filemtime($path)) > $maxAgeSeconds) {
        return null;
    }
    $data = json_decode((string)@file_get_contents($path), true);
    if (!is_array($data) || (string)($data['hash'] ?? '') === '' || !isset($data['columns'], $data['indexes'])) {
        return null;
    }
    return bakery_schema_inventory_public($data);
}

function bakery_hosted_live_schema_cache_write(array $inventory): void {
    $path = bakery_hosted_live_schema_cache_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(3));
    if (@file_put_contents($tmp, json_encode(bakery_schema_inventory_public($inventory), JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) !== false) {
        @rename($tmp, $path);
        @unlink($tmp);
    }
}

function bakery_hosted_live_schema_cache_clear(): void {
    $path = bakery_hosted_live_schema_cache_path();
    if (is_file($path)) {
        @unlink($path);
    }
}

function bakery_hosted_schema_unavailable_reason(?string $raw, int $httpStatus): string {
    if ($httpStatus === 404) {
        return 'missing';
    }
    if ($raw === null || $raw === '') {
        return 'timeout';
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return 'invalid';
    }
    if ((string)($data['hash'] ?? '') !== '' && isset($data['columns'], $data['indexes'])) {
        return '';
    }
    if ((string)($data['status'] ?? '') === 'unavailable') {
        return 'refused';
    }
    return 'invalid';
}

function bakery_hosted_empty_schema_compare(string $reason): array {
    return [
        'state' => 'unknown',
        'reason' => $reason,
        'staging_hash' => '',
        'live_hash' => '',
        'live_database' => '',
        'unexpected_database' => false,
        'missing_on_live' => [],
        'extra_on_live' => [],
        'mismatches' => [],
        'staging_only_migrations' => [],
        'live_only_migrations' => [],
    ];
}

function bakery_hosted_migration_succeeded(?array $status): bool {
    return is_array($status)
        && (string)($status['status'] ?? '') === 'succeeded'
        && (string)($status['migration_id'] ?? '') !== '';
}

function bakery_hosted_migration_status(): ?array {
    $fetched = bakery_hosted_fetch_json('https://bakery.sourflour.org/bake/migration_status.php', 8);
    $data = is_string($fetched['raw']) ? json_decode($fetched['raw'], true) : null;
    $status = function_exists('bakery_staging_live_decorate_status')
        ? bakery_staging_live_decorate_status(is_array($data) ? $data : null)
        : (is_array($data) ? $data : null);
    if (is_array($status) && function_exists('bakery_staging_live_history_ingest_live')) {
        bakery_staging_live_history_ingest_live($status, 'database');
    }
    return $status;
}

function bakery_hosted_live_schema_inventory(bool $bypassCache = false): ?array {
    if (!$bypassCache) {
        $cached = bakery_hosted_live_schema_cache_read(300);
        if ($cached !== null) {
            return $cached;
        }
    }
    $fetched = bakery_hosted_fetch_json(
        'https://bakery.sourflour.org/bake/schema_status.php' . ($bypassCache ? '?refresh=1' : ''),
        45
    );
    if (bakery_hosted_schema_unavailable_reason($fetched['raw'], $fetched['status']) !== '') {
        return null;
    }
    $data = json_decode((string)$fetched['raw'], true);
    $public = bakery_schema_inventory_public(is_array($data) ? $data : []);
    bakery_hosted_live_schema_cache_write($public);
    return $public;
}

function bakery_hosted_schema_compare(PDO $db, bool $forceLiveRefresh = false): array {
    if (!$forceLiveRefresh && ($cached = bakery_hosted_live_schema_cache_read(300)) !== null) {
        $live = $cached;
        $reason = '';
    } else {
        $fetched = bakery_hosted_fetch_json(
            'https://bakery.sourflour.org/bake/schema_status.php' . ($forceLiveRefresh ? '?refresh=1' : ''),
            45
        );
        $reason = bakery_hosted_schema_unavailable_reason($fetched['raw'], $fetched['status']);
        if ($reason !== '') {
            return bakery_hosted_empty_schema_compare($reason);
        }
        $data = json_decode((string)$fetched['raw'], true);
        $live = bakery_schema_inventory_public(is_array($data) ? $data : []);
        bakery_hosted_live_schema_cache_write($live);
    }
    $compare = bakery_schema_inventory_compare(bakery_schema_inventory_from_pdo($db), $live);
    $compare['reason'] = $reason;
    $compare['live_captured_at'] = (string)($live['captured_at'] ?? '');
    return $compare;
}

function bakery_staging_live_recommended_migrations(array $compare, array $candidates): array {
    $ids = array_values(array_map('strval', $compare['staging_only_migrations'] ?? []));
    $idSet = array_fill_keys($ids, true);
    $matches = [];
    foreach ($candidates as $candidate) {
        if (!empty($candidate['safe']) && isset($idSet[(string)$candidate['id']])) {
            $matches[] = $candidate;
        }
    }
    usort($matches, static fn($a, $b) => strcmp((string)$a['file'], (string)$b['file']));
    return $matches;
}

function bakery_staging_live_recommended_migration(array $compare, array $candidates): ?array {
    $matches = bakery_staging_live_recommended_migrations($compare, $candidates);
    return $matches[0] ?? null;
}

function bakery_staging_live_next_step(string $state, bool $canMigrate, bool $migrationSucceeded = false, string $reason = '', bool $staleAfterApply = false): string {
    if ($staleAfterApply) {
        return 'retry';
    }
    if ($state === 'discrepancy') {
        return 'stop';
    }
    if ($state === 'live_behind') {
        return $canMigrate ? 'migrate' : 'migrate_missing';
    }
    if ($state === 'equal') {
        return 'done';
    }
    if ($reason === 'timeout' || $reason === 'invalid' || $reason === 'refused') {
        return 'retry';
    }
    if ($migrationSucceeded) {
        return 'retry';
    }
    return $reason === 'missing' ? 'promote_files' : 'retry';
}

function bakery_staging_live_unknown_detail_key(string $reason, bool $migrationSucceeded): string {
    if ($reason === 'timeout') {
        return 'manager.live_db_unknown_timeout';
    }
    if ($migrationSucceeded) {
        return 'manager.live_db_unknown_applied';
    }
    if ($reason === 'missing') {
        return 'manager.live_db_unknown_missing';
    }
    return 'manager.live_db_unknown_detail';
}

function bakery_staging_live_board(?PDO $db, bool $refreshLiveSchema = false): array {
    $migrationStatus = bakery_hosted_migration_status();
    $migrationSucceeded = bakery_hosted_migration_succeeded($migrationStatus);
    $compare = ($db instanceof PDO)
        ? bakery_hosted_schema_compare($db, $refreshLiveSchema)
        : bakery_hosted_empty_schema_compare('timeout');
    $candidates = bakery_hosted_migration_candidates();
    $recommendedAll = bakery_staging_live_recommended_migrations($compare, $candidates);
    $recommended = $recommendedAll[0] ?? null;
    $appliedIds = bakery_hosted_migration_applied_ids($migrationStatus);
    $staleAfterApply = $migrationSucceeded
        && is_array($recommended)
        && isset($appliedIds[(string)($recommended['id'] ?? '')])
        && in_array((string)$recommended['id'], array_map('strval', $compare['staging_only_migrations'] ?? []), true);
    if ($staleAfterApply && $db instanceof PDO && !$refreshLiveSchema) {
        $compare = bakery_hosted_schema_compare($db, true);
        $recommendedAll = bakery_staging_live_recommended_migrations($compare, $candidates);
        $recommended = $recommendedAll[0] ?? null;
        $staleAfterApply = $migrationSucceeded
            && is_array($recommended)
            && isset($appliedIds[(string)($recommended['id'] ?? '')])
            && in_array((string)$recommended['id'], array_map('strval', $compare['staging_only_migrations'] ?? []), true);
    }
    // Never ask the operator to guess among unrelated SQL files. The gate opens
    // when inventory identifies the remaining safe forward migrations.
    $canMigrate = ($compare['state'] ?? '') === 'live_behind' && is_array($recommended) && !$staleAfterApply;
    $state = (string)($compare['state'] ?? 'unknown');
    $reason = (string)($compare['reason'] ?? '');
    return [
        'compare' => $compare,
        'candidates' => $candidates,
        'recommended' => $recommended,
        'recommended_all' => $recommendedAll,
        'next' => bakery_staging_live_next_step($state, $canMigrate, $migrationSucceeded, $reason, $staleAfterApply),
        'approval' => bakery_staging_live_approval_latest(),
        'migration_approval' => bakery_hosted_migration_latest(),
        'files_status' => bakery_staging_live_status(),
        'migration_status' => $migrationStatus,
        'stale_after_apply' => $staleAfterApply,
    ];
}

/**
 * Concatenate remaining additive jobs into one SQL file the Live worker can apply.
 *
 * @param list<array{id?:string,sql?:string}> $loaded
 */
function bakery_hosted_migration_catchup_sql(array $loaded): string {
    $parts = [];
    foreach ($loaded as $job) {
        $id = trim((string)($job['id'] ?? ''));
        $sql = trim((string)($job['sql'] ?? ''));
        if ($id === '' || $sql === '') {
            throw new RuntimeException('Each queued Live update must include an id and SQL.');
        }
        $parts[] = '-- sourflour-job: ' . $id . "\n" . $sql;
    }
    if ($parts === []) {
        throw new RuntimeException('No remaining additive Live updates are published.');
    }
    return implode("\n\n", $parts) . "\n";
}

function bakery_hosted_migration_applied_ids(?array $status): array {
    $ids = [];
    if (!is_array($status)) {
        return $ids;
    }
    $primary = (string)($status['migration_id'] ?? '');
    if ($primary !== '') {
        $ids[$primary] = true;
    }
    foreach ((array)($status['migration_ids'] ?? []) as $id) {
        $id = (string)$id;
        if ($id !== '') {
            $ids[$id] = true;
        }
    }
    return $ids;
}

function bakery_hosted_migration_approve_recommended(PDO $db, string $file): array {
    $compare = bakery_hosted_schema_compare($db, true);
    $queue = bakery_staging_live_recommended_migrations($compare, bakery_hosted_migration_candidates());
    $first = $queue[0] ?? null;
    if (($compare['state'] ?? '') !== 'live_behind' || !is_array($first)
        || !hash_equals((string)$first['file'], basename(trim($file)))) {
        throw new RuntimeException('The selected migration is not the first remaining update recommended by the current Staging-to-Live schema comparison.');
    }
    return bakery_hosted_migration_approve_jobs($queue);
}

function bakery_hosted_migration_approve_jobs(array $jobs): array {
    if (count($jobs) === 1) {
        return bakery_hosted_migration_approve((string)$jobs[0]['file']);
    }
    if (!defined('IS_STAGING') || !IS_STAGING || !bakery_user_has_role(['administrator'])) {
        throw new RuntimeException('Live database migration is available only to Staging administrators.');
    }
    if ($jobs === []) {
        throw new RuntimeException('No remaining additive Live updates are published.');
    }
    $sourceRoot = bakery_hosted_migration_source_root();
    $loaded = [];
    foreach ($jobs as $job) {
        $file = basename((string)($job['file'] ?? ''));
        $id = (string)($job['id'] ?? substr($file, 0, -4));
        $source = $sourceRoot . DIRECTORY_SEPARATOR . $file;
        if (!preg_match('/^(\d{3}_[A-Za-z0-9_]+)\.sql$/', $file) || !is_file($source)) {
            throw new RuntimeException('Select a published Staging migration.');
        }
        if ((int)substr($file, 0, 3) < 50) {
            throw new RuntimeException('Only new 050+ migrations use the hosted migration gate.');
        }
        $sql = (string)file_get_contents($source);
        [$safe, $message] = bakery_hosted_migration_sql_safe($sql);
        if (!$safe) {
            throw new RuntimeException($message);
        }
        $loaded[] = [
            'id' => $id,
            'file' => $file,
            'sha256' => hash_file('sha256', $source),
            'sql' => $sql,
            'classification' => $message,
        ];
    }
    $catchupSql = bakery_hosted_migration_catchup_sql($loaded);
    [$catchupSafe, $catchupMessage] = bakery_hosted_migration_sql_safe($catchupSql);
    if (!$catchupSafe) {
        throw new RuntimeException($catchupMessage);
    }
    $first = $loaded[0];
    $release = 'migration-' . $first['id'] . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $root = bakery_hosted_migration_export_root();
    $temp = $root . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . $release . '.tmp';
    $final = $root . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . $release;
    if (!is_dir($temp) && !@mkdir($temp, 0700, true) && !is_dir($temp)) {
        throw new RuntimeException('Cannot create private migration export.');
    }
    $sqlPath = $temp . DIRECTORY_SEPARATOR . 'migration.sql';
    $record = [
        'format' => 1,
        'status' => 'approved_for_live',
        'release_id' => $release,
        'migration_id' => $first['id'],
        'file' => $first['file'],
        'migration_ids' => array_column($loaded, 'id'),
        'migrations' => array_map(static fn($row) => [
            'id' => $row['id'],
            'file' => $row['file'],
            'sha256' => $row['sha256'],
        ], $loaded),
        'approved_at' => gmdate('c'),
        'approved_by' => (string)(bakery_current_user()['email'] ?? 'administrator'),
        'classification' => $catchupMessage,
    ];
    if (@file_put_contents($sqlPath, $catchupSql) === false) {
        throw new RuntimeException('Could not write the catch-up migration export.');
    }
    $record['sha256'] = hash_file('sha256', $sqlPath);
    if (@file_put_contents($temp . DIRECTORY_SEPARATOR . 'release.json', json_encode($record, JSON_PRETTY_PRINT) . PHP_EOL, LOCK_EX) === false
        || !@rename($temp, $final)) {
        throw new RuntimeException('Could not finalize the private migration export.');
    }
    $ready = bakery_hosted_migration_approval_path();
    $tmp = $ready . '.tmp.' . bin2hex(random_bytes(3));
    if (@file_put_contents($tmp, json_encode($record, JSON_PRETTY_PRINT) . PHP_EOL, LOCK_EX) === false || !@rename($tmp, $ready)) {
        @unlink($tmp);
        throw new RuntimeException('Could not queue the migration.');
    }
    if (function_exists('bakery_staging_live_history_append')) {
        bakery_staging_live_history_append(bakery_staging_live_history_from_approval($record, 'database'));
    }
    return $record;
}

function bakery_hosted_migration_approve(string $file): array {
    if (!defined('IS_STAGING') || !IS_STAGING || !bakery_user_has_role(['administrator'])) {
        throw new RuntimeException('Live database migration is available only to Staging administrators.');
    }
    $file = basename(trim($file));
    $source = bakery_hosted_migration_source_root() . DIRECTORY_SEPARATOR . $file;
    if (!preg_match('/^(\d{3}_[A-Za-z0-9_]+)\.sql$/', $file) || !is_file($source)) {
        throw new RuntimeException('Select a published Staging migration.');
    }
    if ((int)substr($file, 0, 3) < 50) throw new RuntimeException('Only new 050+ migrations use the hosted migration gate.');
    $sql = (string)file_get_contents($source);
    [$safe, $message] = bakery_hosted_migration_sql_safe($sql);
    if (!$safe) throw new RuntimeException($message);
    $id = substr($file, 0, -4);
    $release = 'migration-' . $id . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $root = bakery_hosted_migration_export_root();
    $temp = $root . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . $release . '.tmp';
    $final = $root . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . $release;
    if (!is_dir($temp) && !@mkdir($temp, 0700, true) && !is_dir($temp)) throw new RuntimeException('Cannot create private migration export.');
    $record = ['format' => 1, 'status' => 'approved_for_live', 'release_id' => $release, 'migration_id' => $id,
        'file' => $file, 'sha256' => hash_file('sha256', $source), 'approved_at' => gmdate('c'),
        'approved_by' => (string)(bakery_current_user()['email'] ?? 'administrator'), 'classification' => $message];
    if (!@copy($source, $temp . DIRECTORY_SEPARATOR . 'migration.sql')
        || !hash_equals($record['sha256'], hash_file('sha256', $temp . DIRECTORY_SEPARATOR . 'migration.sql'))
        || @file_put_contents($temp . DIRECTORY_SEPARATOR . 'release.json', json_encode($record, JSON_PRETTY_PRINT) . PHP_EOL, LOCK_EX) === false
        || !@rename($temp, $final)) throw new RuntimeException('Could not finalize the private migration export.');
    $ready = bakery_hosted_migration_approval_path(); $tmp = $ready . '.tmp.' . bin2hex(random_bytes(3));
    if (@file_put_contents($tmp, json_encode($record, JSON_PRETTY_PRINT) . PHP_EOL, LOCK_EX) === false || !@rename($tmp, $ready)) {
        @unlink($tmp); throw new RuntimeException('Could not queue the migration.');
    }
    if (function_exists('bakery_staging_live_history_append')) {
        bakery_staging_live_history_append(bakery_staging_live_history_from_approval($record, 'database'));
    }
    return $record;
}
