<?php
/** Shared, testable runtime for the Live additive-migration worker. */

function bakery_hosted_migration_env_file(string $path): array
{
    $out = [];
    foreach ((array)@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $out[trim($key)] = trim(trim($value), "\"'");
    }
    return $out;
}

function bakery_hosted_migration_read_json(string $path): ?array
{
    $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    return is_array($data) ? $data : null;
}

function bakery_hosted_migration_write_json(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create migration status directory.');
    }
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false
        || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot write migration status.');
    }
    if (basename($path) === 'HOSTED_MIGRATION_STATUS.json') {
        bakery_hosted_status_history_append(dirname($path) . '/HOSTED_MIGRATION_HISTORY.json', $data, 'database');
    }
}

/**
 * Append a terminal Live worker snapshot to a capped JSON history file.
 *
 * @return list<array<string,mixed>>
 */
function bakery_hosted_status_history_append(string $historyPath, array $snapshot, string $kind, int $max = 200): array
{
    $status = (string)($snapshot['status'] ?? '');
    if (!in_array($status, ['succeeded', 'failed', 'rolled_back'], true)) {
        return bakery_hosted_status_history_read($historyPath);
    }
    $event = bakery_hosted_status_history_compact($snapshot, $kind);
    $events = bakery_hosted_status_history_read($historyPath);
    foreach ($events as $existing) {
        if ((string)($existing['id'] ?? '') === (string)$event['id']) {
            return $events;
        }
    }
    $events[] = $event;
    if (count($events) > $max) {
        $events = array_slice($events, -$max);
    }
    $dir = dirname($historyPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $tmp = $historyPath . '.tmp.' . bin2hex(random_bytes(3));
    if (@file_put_contents($tmp, json_encode(['events' => $events], JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) !== false) {
        @rename($tmp, $historyPath);
        @unlink($tmp);
    }
    return $events;
}

/** @return list<array<string,mixed>> */
function bakery_hosted_status_history_read(string $historyPath): array
{
    $data = is_file($historyPath) ? json_decode((string)@file_get_contents($historyPath), true) : null;
    $events = is_array($data) ? ($data['events'] ?? $data) : [];
    if (!is_array($events)) {
        return [];
    }
    $out = [];
    foreach ($events as $event) {
        if (is_array($event) && (string)($event['id'] ?? '') !== '') {
            $out[] = $event;
        }
    }
    return $out;
}

/** @param array<string,mixed> $snapshot */
function bakery_hosted_status_history_compact(array $snapshot, string $kind): array
{
    $status = (string)($snapshot['status'] ?? 'unknown');
    $release = (string)($snapshot['release_id'] ?? '');
    $when = (string)($snapshot['completed_at'] ?? $snapshot['started_at'] ?? $snapshot['approved_at'] ?? '');
    $changed = $snapshot['changed_files'] ?? [];
    if (!is_array($changed)) {
        $changed = [];
    }
    $changed = array_values(array_filter(array_map('strval', $changed), static fn($path) => $path !== ''));
    return [
        'id' => $kind . '|' . $release . '|' . $status . '|' . $when,
        'kind' => $kind,
        'status' => $status,
        'release_id' => $release,
        'migration_id' => (string)($snapshot['migration_id'] ?? ''),
        'phase' => (string)($snapshot['phase'] ?? ''),
        'at' => $when,
        'started_at' => (string)($snapshot['started_at'] ?? ''),
        'completed_at' => (string)($snapshot['completed_at'] ?? ''),
        'file_count' => (int)($snapshot['file_count'] ?? 0),
        'changed_file_count' => (int)($snapshot['changed_file_count'] ?? count($changed)),
        'statement_count' => (int)($snapshot['statement_count'] ?? 0),
        'completed_statements' => (int)($snapshot['completed_statements'] ?? 0),
        'health' => (string)($snapshot['health'] ?? ''),
        'message' => (string)($snapshot['public_message'] ?? $snapshot['message'] ?? ''),
        'changed_files' => array_slice($changed, 0, 80),
    ];
}

/** Run without shell interpolation and enforce a real wall-clock timeout. */
function bakery_hosted_migration_run(array $command, int $timeout = 180): array
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start migration command.');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $started = microtime(true);
    $observedExit = null;
    do {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $state = proc_get_status($process);
        if (!$state['running']) {
            $observedExit = (int)$state['exitcode'];
            break;
        }
        if ((microtime(true) - $started) > $timeout) {
            proc_terminate($process);
            usleep(250000);
            $afterTerminate = proc_get_status($process);
            if ($afterTerminate['running']) proc_terminate($process, 9);
            foreach ([1, 2] as $pipeIndex) {
                $remaining = stream_get_contents($pipes[$pipeIndex]);
                if ($pipeIndex === 1) $stdout .= $remaining;
                else $stderr .= $remaining;
                fclose($pipes[$pipeIndex]);
            }
            proc_close($process);
            throw new RuntimeException('Migration command timed out.');
        }
        usleep(100000);
    } while (true);
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closedExit = proc_close($process);
    return [
        'exit' => $closedExit >= 0 ? $closedExit : ($observedExit ?? $closedExit),
        'stdout' => trim($stdout),
        'stderr' => trim($stderr),
    ];
}

/** Validate an installation without fetching an approval, backing up, or changing schema. */
function bakery_hosted_migration_preflight(string $configPath): array
{
    $config = bakery_hosted_migration_env_file($configPath);
    foreach (['STAGE_HOST', 'STAGE_USER', 'STAGE_KEY', 'LIVE_ROOT', 'DB_ENV', 'DB_BACKUP_COMMAND'] as $key) {
        if (empty($config[$key])) throw new RuntimeException("Missing migration setting {$key}.");
    }
    $root = rtrim((string)$config['LIVE_ROOT'], '/');
    if ($root !== '/home/dh_dp755h/bakery.sourflour.org/bake') {
        throw new RuntimeException('Refusing unexpected Live root.');
    }
    if (!is_file((string)$config['STAGE_KEY']) || !is_readable((string)$config['STAGE_KEY'])) {
        throw new RuntimeException('Staging read-only SSH key is missing or unreadable.');
    }
    if (!is_file((string)$config['DB_ENV']) || !is_readable((string)$config['DB_ENV'])) {
        throw new RuntimeException('Live database environment file is missing or unreadable.');
    }
    foreach (['/usr/bin/rsync', '/bin/bash'] as $tool) {
        if (!is_executable($tool)) throw new RuntimeException("Required worker tool is unavailable: {$tool}");
    }
    $dbConfig = bakery_hosted_migration_database_config(
        bakery_hosted_migration_env_file((string)$config['DB_ENV'])
    );
    $pdo = new PDO(
        'mysql:host=' . $dbConfig['host'] . ';port=' . $dbConfig['port'] . ';dbname=bakerysf;charset=utf8mb4',
        $dbConfig['user'],
        $dbConfig['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() !== 'bakerysf') {
        throw new RuntimeException('Database identity mismatch.');
    }
    return [
        'status' => 'ok',
        'target' => 'bakerysf',
        'live_root' => $root,
        'backup_executed' => false,
        'schema_changed' => false,
    ];
}

function bakery_hosted_migration_backup_succeeded(string $stdout): bool
{
    return (bool)preg_match('/["\']status["\']\s*:\s*["\']success["\']/i', $stdout);
}

/** Accept the Live app .env (DB_*) or the guarded pull file (PROD_DB_*). */
function bakery_hosted_migration_database_config(array $env): array
{
    $prefix = isset($env['DB_NAME']) && trim((string)$env['DB_NAME']) !== '' ? 'DB_' : 'PROD_DB_';
    $name = strtolower(trim((string)($env[$prefix . 'NAME'] ?? '')));
    if ($name !== 'bakerysf') throw new RuntimeException('Refusing unexpected database target.');
    foreach (['HOST', 'USER', 'PASS'] as $key) {
        if (!isset($env[$prefix . $key]) || trim((string)$env[$prefix . $key]) === '') {
            throw new RuntimeException('Live database configuration is incomplete.');
        }
    }
    return [
        'host' => (string)$env[$prefix . 'HOST'],
        'port' => (string)($env[$prefix . 'PORT'] ?? '3306'),
        'name' => $name,
        'user' => (string)$env[$prefix . 'USER'],
        'pass' => (string)$env[$prefix . 'PASS'],
    ];
}

/** Conservative cross-version policy for the exact SQL sent to Live. */
function bakery_hosted_migration_sql_safe(string $sql): array
{
    $withoutComments = preg_replace('#/\*.*?\*/#s', '', $sql);
    $withoutComments = preg_replace('/^\s*--.*$/m', '', (string)$withoutComments);
    $normal = strtolower(trim((string)$withoutComments));
    if ($normal === '') return [false, 'Migration contains no executable SQL.'];
    if (preg_match('/\b(drop|truncate|rename|replace|grant|revoke|load\s+data|create\s+database|use\s+)\b/', $normal)
        || preg_match('/\bdelete\s+from\b/', $normal)
        || preg_match('/\balter\s+table[^;]*\b(change|drop|rename)\b/', $normal)) {
        return [false, 'Only additive schema changes and safe catch-up repairs are eligible.'];
    }
    if (preg_match('/\b(?:tiny|medium|long)?(?:text|blob)\b[^,;\n]*\bdefault\b/i', $normal)) {
        return [false, 'TEXT/BLOB defaults are not portable to the Live MySQL version. Omit the DEFAULT clause.'];
    }
    $statements = array_values(array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $normal))));
    if ($statements === []) return [false, 'Migration contains no executable SQL.'];
    foreach ($statements as $statement) {
        $allowed = preg_match('/^create\s+table\s+if\s+not\s+exists\b/', $statement)
            || preg_match('/^create\s+(unique\s+)?(index|key)(\s+if\s+not\s+exists)?\b/', $statement)
            || preg_match('/^insert\s+ignore\s+into\b/', $statement)
            || preg_match('/^update\s+\w+/', $statement)
            || (preg_match('/^alter\s+table\b/', $statement) && preg_match('/\badd\s+(column|index|key|constraint|foreign\s+key)\b/', $statement))
            || (preg_match('/^alter\s+table\b/', $statement) && preg_match('/\bmodify\s+column\b/', $statement) && preg_match('/\benum\s*\(/', $statement));
        if (!$allowed) return [false, 'Migration contains a statement outside the additive allow-list.'];
    }
    return [true, 'Additive, cross-version schema change.'];
}

/**
 * @param list<array{id:string,sql:string}> $jobs
 */
function bakery_hosted_migration_catchup_sql(array $jobs): string
{
    $chunks = [];
    $extraIds = [];
    foreach ($jobs as $index => $job) {
        $id = (string)($job['id'] ?? '');
        if (!preg_match('/^\d{3}_[A-Za-z0-9_]+$/', $id)) {
            throw new RuntimeException('Catch-up includes an invalid migration id.');
        }
        $sql = rtrim((string)($job['sql'] ?? ''));
        if ($sql === '') {
            throw new RuntimeException('Catch-up includes an empty migration.');
        }
        $chunks[] = '-- catchup ' . $id . "\n" . $sql . (str_ends_with($sql, ';') ? '' : ';') . "\n";
        if ($index > 0) {
            $extraIds[] = $id;
        }
    }
    foreach ($extraIds as $id) {
        $chunks[] = "INSERT IGNORE INTO schema_migrations (id) VALUES ('" . $id . "');\n";
    }
    return implode("\n", $chunks);
}

/** @return list<string> */
function bakery_hosted_migration_ids_from_approval(array $approval): array
{
    $ids = [];
    foreach ((array)($approval['migration_ids'] ?? []) as $id) {
        $id = (string)$id;
        if (preg_match('/^\d{3}_[A-Za-z0-9_]+$/', $id)) {
            $ids[] = $id;
        }
    }
    foreach ((array)($approval['migrations'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string)($row['id'] ?? '');
        if (preg_match('/^\d{3}_[A-Za-z0-9_]+$/', $id)) {
            $ids[] = $id;
        }
    }
    $primary = (string)($approval['migration_id'] ?? '');
    if ($ids === [] && preg_match('/^\d{3}_[A-Za-z0-9_]+$/', $primary)) {
        $ids[] = $primary;
    }
    $unique = [];
    foreach ($ids as $id) {
        $unique[$id] = true;
    }
    return array_keys($unique);
}

function bakery_hosted_migration_superseded_by(string $sql): ?string
{
    if (preg_match('/^\s*--\s*superseded-by:\s*(\d{3}_[A-Za-z0-9_]+)\s*$/mi', $sql, $match)) {
        return (string)$match[1];
    }
    return null;
}

/** Execute one additive statement; accept only a verified already-present column or key. */
function bakery_hosted_migration_exec_statement(PDO $pdo, string $statement): bool
{
    try {
        $pdo->exec($statement);
        return true;
    } catch (PDOException $error) {
        $driverCode = (int)($error->errorInfo[1] ?? 0);
        if ($driverCode === 1060
            && preg_match('/^\s*ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s+ADD\s+COLUMN\s+`?([A-Za-z0-9_]+)`?/i', $statement, $match)) {
            $check = $pdo->prepare(
                'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
            );
            $check->execute([$match[1], $match[2]]);
            if ($check->fetchColumn()) return false;
        }
        if ($driverCode === 1061
            && preg_match('/^\s*ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s+ADD\s+(?:UNIQUE\s+)?(?:INDEX|KEY)\s+`?([A-Za-z0-9_]+)`?/i', $statement, $match)) {
            $check = $pdo->prepare(
                'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
            );
            $check->execute([$match[1], $match[2]]);
            if ($check->fetchColumn()) return false;
        }
        throw $error;
    }
}

/** @return list<string> */
function bakery_hosted_migration_job_ids(array $approval): array
{
    $ids = [];
    foreach ((array)($approval['migration_ids'] ?? []) as $id) {
        $id = (string)$id;
        if (preg_match('/^\d{3}_[A-Za-z0-9_]+$/', $id)) {
            $ids[] = $id;
        }
    }
    $primary = (string)($approval['migration_id'] ?? '');
    if ($ids === [] && preg_match('/^\d{3}_[A-Za-z0-9_]+$/', $primary)) {
        $ids[] = $primary;
    }
    return array_values(array_unique($ids));
}

function bakery_hosted_migration_remove_tree(string $path, string $requiredParent): void
{
    $parent = realpath($requiredParent);
    $real = realpath($path);
    if ($parent === false || $real === false || strpos($real, $parent . DIRECTORY_SEPARATOR) !== 0) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($real);
}

function bakery_hosted_migration_worker_main(string $configPath): int
{
    $config = bakery_hosted_migration_env_file($configPath);
    foreach (['STAGE_HOST', 'STAGE_USER', 'STAGE_KEY', 'LIVE_ROOT', 'DB_ENV', 'DB_BACKUP_COMMAND'] as $key) {
        if (empty($config[$key])) {
            fwrite(STDERR, "MIGRATION CONFIG ERROR: missing {$key}.\n");
            return 2;
        }
    }
    $root = rtrim((string)$config['LIVE_ROOT'], '/');
    if ($root !== '/home/dh_dp755h/bakery.sourflour.org/bake') {
        fwrite(STDERR, "MIGRATION CONFIG ERROR: refusing unexpected Live root.\n");
        return 3;
    }
    $statusPath = $root . '/storage/deploy/HOSTED_MIGRATION_STATUS.json';
    $lock = fopen('/home/dh_dp755h/.bakery-hosted-migration.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return 0;

    $approvalTemp = '/home/dh_dp755h/.migration-approval-' . bin2hex(random_bytes(4)) . '.json';
    $workRoot = '/home/dh_dp755h/.bakery-migration-work';
    $activeWork = '';
    $status = null;
    $databaseLock = false;
    $pdo = null;
    try {
        $ssh = 'ssh -i ' . $config['STAGE_KEY'] . ' -o BatchMode=yes -o StrictHostKeyChecking=yes';
        $remote = $config['STAGE_USER'] . '@' . $config['STAGE_HOST'] . ':';
        $fetchApproval = bakery_hosted_migration_run(['/usr/bin/rsync', '-a', '-e', $ssh, $remote . '/ready_for_live.json', $approvalTemp], 30);
        if ($fetchApproval['exit'] !== 0 || !is_file($approvalTemp)) return 0;
        $approval = bakery_hosted_migration_read_json($approvalTemp);
        @unlink($approvalTemp);
        if (!$approval || ($approval['status'] ?? '') !== 'approved_for_live' || (int)($approval['format'] ?? 0) !== 1) return 0;

        $id = (string)($approval['migration_id'] ?? '');
        $ids = function_exists('bakery_hosted_migration_ids_from_approval')
            ? bakery_hosted_migration_ids_from_approval($approval)
            : [];
        if ($ids === []) {
            $ids = $id !== '' ? [$id] : [];
        }
        $release = (string)($approval['release_id'] ?? '');
        $hash = strtolower((string)($approval['sha256'] ?? ''));
        if (!preg_match('/^\d{3}_[A-Za-z0-9_]+$/', $id)
            || !preg_match('/^migration-\d{3}_[A-Za-z0-9_]+-\d{8}-\d{6}-[a-f0-9]{6}$/', $release)
            || !preg_match('/^[a-f0-9]{64}$/', $hash)) throw new RuntimeException('Invalid migration approval.');
        $prior = bakery_hosted_migration_read_json($statusPath);
        if (($prior['release_id'] ?? '') === $release && in_array(($prior['status'] ?? ''), ['succeeded', 'failed'], true)) return 0;

        $status = [
            'format' => 2, 'worker_version' => 3, 'status' => 'preflighting', 'phase' => 'source',
            'release_id' => $release, 'migration_id' => $id, 'migration_ids' => $ids, 'started_at' => gmdate('c'),
            'public_message' => count($ids) > 1
                ? 'Validating the approved remaining additive migrations.'
                : 'Validating the approved additive migration.',
        ];
        bakery_hosted_migration_write_json($statusPath, $status);
        if (!is_dir($workRoot) && !mkdir($workRoot, 0700, true) && !is_dir($workRoot)) throw new RuntimeException('Cannot create migration work directory.');
        $activeWork = $workRoot . '/' . $release;
        if (!mkdir($activeWork, 0700, true) && !is_dir($activeWork)) throw new RuntimeException('Cannot create migration release directory.');
        $sqlPath = $activeWork . '/migration.sql';
        $fetchSql = bakery_hosted_migration_run(['/usr/bin/rsync', '-a', '-e', $ssh, $remote . '/releases/' . $release . '/migration.sql', $sqlPath], 60);
        if ($fetchSql['exit'] !== 0 || !is_file($sqlPath) || !hash_equals($hash, hash_file('sha256', $sqlPath))) {
            throw new RuntimeException('Migration source did not match the approved Staging export.');
        }
        $sql = (string)file_get_contents($sqlPath);
        [$safe, $classification] = bakery_hosted_migration_sql_safe($sql);
        if (!$safe) throw new RuntimeException($classification);

        $dbConfig = bakery_hosted_migration_database_config(bakery_hosted_migration_env_file((string)$config['DB_ENV']));
        $pdo = new PDO('mysql:host=' . $dbConfig['host'] . ';port=' . $dbConfig['port'] . ';dbname=bakerysf;charset=utf8mb4',
            $dbConfig['user'], $dbConfig['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() !== 'bakerysf') throw new RuntimeException('Database identity mismatch.');
        $ledgerExists = (bool)$pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema='bakerysf' AND table_name='schema_migrations' LIMIT 1")->fetchColumn();
        if ($ledgerExists) {
            $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE id = ?');
            $missing = [];
            foreach ($ids as $checkId) {
                $check->execute([$checkId]);
                if (!$check->fetchColumn()) {
                    $missing[] = $checkId;
                }
            }
            if ($missing === []) {
                $status['status'] = 'succeeded'; $status['phase'] = 'complete'; $status['completed_at'] = gmdate('c');
                $status['migration_ids'] = $ids;
                $status['public_message'] = 'Migration was already recorded; no schema change was made.';
                bakery_hosted_migration_write_json($statusPath, $status);
                if (function_exists('bakery_schema_inventory_cache_path')) {
                    @unlink(bakery_schema_inventory_cache_path());
                } else {
                    @unlink($root . '/storage/deploy/HOSTED_SCHEMA_STATUS.json');
                }
                return 0;
            }
        }

        if ((string)$pdo->query("SELECT GET_LOCK('bakery_schema_migration',15)")->fetchColumn() !== '1') throw new RuntimeException('Could not obtain migration lock.');
        $databaseLock = true;
        $status['phase'] = 'backup'; $status['public_message'] = 'Migration lock acquired; creating a fresh production backup.';
        bakery_hosted_migration_write_json($statusPath, $status);
        $backup = bakery_hosted_migration_run(['/bin/bash', '-lc', (string)$config['DB_BACKUP_COMMAND']], 180);
        if ($backup['exit'] !== 0 || !bakery_hosted_migration_backup_succeeded($backup['stdout'])) throw new RuntimeException('Pre-migration production backup failed.');

        $status['database_backup'] = $backup['stdout']; $status['status'] = 'applying'; $status['phase'] = 'schema';
        $status['public_message'] = count($ids) > 1
            ? 'Production backup verified; applying remaining additive migrations.'
            : 'Production backup verified; applying one additive migration.';
        bakery_hosted_migration_write_json($statusPath, $status);
        if (!$ledgerExists) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (id VARCHAR(64) NOT NULL PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        }
        if (!defined('ACCESS_ALLOWED')) define('ACCESS_ALLOWED', true);
        require_once $root . '/includes/schema_sql.php';
        $statements = bakery_parse_sql_file($sqlPath);
        if ($statements === []) {
            throw new RuntimeException('Migration contained no executable statements.');
        }
        $status['statement_count'] = count($statements); $status['completed_statements'] = 0;
        foreach ($statements as $index => $statement) {
            bakery_hosted_migration_exec_statement($pdo, $statement);
            $status['completed_statements'] = $index + 1;
            bakery_hosted_migration_write_json($statusPath, $status);
        }
        $mark = $pdo->prepare('INSERT INTO schema_migrations (id) VALUES (?)');
        $mark->execute([$id]);
        $markIgnore = $pdo->prepare('INSERT IGNORE INTO schema_migrations (id) VALUES (?)');
        foreach ($ids as $extraId) {
            if ($extraId === $id) {
                continue;
            }
            $markIgnore->execute([$extraId]);
        }
        if (function_exists('bakery_schema_inventory_cache_path')) {
            @unlink(bakery_schema_inventory_cache_path());
        } else {
            @unlink($root . '/storage/deploy/HOSTED_SCHEMA_STATUS.json');
        }
        $status['status'] = 'succeeded'; $status['phase'] = 'complete'; $status['completed_at'] = gmdate('c');
        $status['migration_ids'] = $ids;
        $status['public_message'] = count($ids) > 1
            ? 'Additive migrations ' . implode(', ', $ids) . ' applied and recorded on Live.'
            : 'Additive migration ' . $id . ' applied and recorded on Live.';
        bakery_hosted_migration_write_json($statusPath, $status);
        echo 'MIGRATED ' . implode(',', $ids) . "\n";
        return 0;
    } catch (Throwable $error) {
        if (is_array($status)) {
            $mayBePartial = ($status['phase'] ?? '') === 'schema';
            $status['status'] = 'failed'; $status['completed_at'] = gmdate('c');
            $status['public_message'] = $mayBePartial
                ? 'Migration stopped while applying schema. Review statement progress and use a forward repair before retrying.'
                : 'Migration stopped safely before schema changes were applied.';
            $status['error'] = $error->getMessage();
            bakery_hosted_migration_write_json($statusPath, $status);
        }
        fwrite(STDERR, 'MIGRATION FAILED: ' . $error->getMessage() . PHP_EOL);
        return 1;
    } finally {
        if ($databaseLock && $pdo instanceof PDO) {
            try { $pdo->query("SELECT RELEASE_LOCK('bakery_schema_migration')"); } catch (Throwable $ignored) {}
        }
        @unlink($approvalTemp);
        if ($activeWork !== '') bakery_hosted_migration_remove_tree($activeWork, $workRoot);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
