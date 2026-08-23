<?php
/**
 * Live-side worker for the hosted Staging -> Live promotion.
 *
 * Runs as the Live DreamHost shell user. It can read Staging only through an
 * rrsync -ro key. It never reads Git and never needs the developer PC.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

function promotion_env(string $path): array {
    $values = [];
    foreach ((array)@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim(trim($value), "\"'");
    }
    return $values;
}

function promotion_write_json(string $path, array $data): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException("Cannot create {$dir}");
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false
        || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("Cannot write {$path}");
    }
}

function promotion_read_json(string $path): ?array {
    $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    return is_array($data) ? $data : null;
}

function promotion_run(array $command, int $timeout = 180): array {
    $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) throw new RuntimeException('Could not start command.');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $started = time();
    $observedExit = null;
    do {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($proc);
        if (!$status['running']) {
            $observedExit = (int)$status['exitcode'];
            break;
        }
        if (time() - $started > $timeout) {
            proc_terminate($proc);
            throw new RuntimeException('Command timed out.');
        }
        usleep(100000);
    } while (true);
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closedExit = proc_close($proc);
    $exit = $closedExit >= 0 ? $closedExit : ($observedExit ?? $closedExit);
    return ['exit' => $exit, 'stdout' => trim($stdout), 'stderr' => trim($stderr)];
}

function promotion_remove_tree(string $path, string $requiredParent): void {
    $realParent = realpath($requiredParent);
    $real = realpath($path);
    if ($realParent === false || $real === false || strpos($real, $realParent . DIRECTORY_SEPARATOR) !== 0) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($real);
}

function promotion_safe_path(string $path): bool {
    return $path !== '' && strlen($path) <= 300 && $path[0] !== '/'
        && strpos($path, '..') === false && strpos($path, "\0") === false
        && (bool)preg_match('#^(?:\.htaccess|[A-Za-z0-9][A-Za-z0-9._/-]*)$#', $path)
        && !preg_match('#^(?:storage|database|scripts|tests|docs)(?:/|$)#', $path)
        && !preg_match('#(?:^|/)(?:\.env|\.git)(?:/|$)#', $path);
}

$configPath = $argv[1] ?? '/home/dh_dp755h/.bakery-hosted-promotion.env';
$config = promotion_env($configPath);
foreach (['STAGE_HOST', 'STAGE_USER', 'STAGE_KEY', 'LIVE_ROOT', 'WORK_ROOT', 'BACKUP_ROOT'] as $required) {
    if (empty($config[$required])) { fwrite(STDERR, "Missing {$required} in promotion config.\n"); exit(1); }
}
$liveRoot = rtrim($config['LIVE_ROOT'], '/');
$workRoot = rtrim($config['WORK_ROOT'], '/');
$backupRoot = rtrim($config['BACKUP_ROOT'], '/');
if ($liveRoot !== '/home/dh_dp755h/bakery.sourflour.org/bake') {
    fwrite(STDERR, "Refusing unexpected Live root.\n");
    exit(1);
}
$statusPath = $liveRoot . '/storage/deploy/HOSTED_PROMOTION_STATUS.json';
$hashIndexPath = $liveRoot . '/storage/deploy/HOSTED_PROMOTION_FILES.json';
$lockPath = '/home/dh_dp755h/.bakery-hosted-promotion.lock';
$lock = fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);

$ssh = 'ssh -i ' . $config['STAGE_KEY'] . ' -o BatchMode=yes -o StrictHostKeyChecking=yes';
$remote = $config['STAGE_USER'] . '@' . $config['STAGE_HOST'] . ':';
$approvalTemp = $workRoot . '/approval-' . bin2hex(random_bytes(4)) . '.json';
$activeWork = '';
$status = null;

try {
    if (!is_dir($workRoot) && !mkdir($workRoot, 0750, true) && !is_dir($workRoot)) throw new RuntimeException('Cannot create work root.');
    $fetchApproval = promotion_run(['/usr/bin/rsync', '-a', '-e', $ssh, $remote . '/ready_for_live.json', $approvalTemp], 30);
    if ($fetchApproval['exit'] !== 0 || !is_file($approvalTemp)) throw new RuntimeException('Could not read the Staging approval.');
    $approval = promotion_read_json($approvalTemp);
    @unlink($approvalTemp);
    if (!$approval || ($approval['status'] ?? '') !== 'approved_for_live' || (int)($approval['format'] ?? 0) !== 2) exit(0);
    $releaseId = (string)($approval['release_id'] ?? '');
    if (!preg_match('/^stage-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}$/', $releaseId)) throw new RuntimeException('Invalid release identifier.');
    $prior = promotion_read_json($statusPath);
    if (($prior['release_id'] ?? '') === $releaseId && in_array(($prior['status'] ?? ''), ['succeeded', 'failed', 'rolled_back', 'promoting'], true)) exit(0);
    $files = $approval['files'] ?? [];
    if (!is_array($files) || count($files) < 50 || count($files) > 2000 || count($files) !== (int)($approval['file_count'] ?? -1)) {
        throw new RuntimeException('Staging manifest file count is invalid.');
    }
    $paths = [];
    foreach ($files as $entry) {
        $path = (string)($entry['path'] ?? '');
        $hash = strtolower((string)($entry['sha256'] ?? ''));
        if (!promotion_safe_path($path) || !preg_match('/^[a-f0-9]{64}$/', $hash) || isset($paths[$path])) {
            throw new RuntimeException('Staging manifest contains an unsafe file entry.');
        }
        $paths[$path] = $hash;
    }
    $priorIndex = promotion_read_json($hashIndexPath);
    $liveHashes = [];
    foreach ((array)($priorIndex['files'] ?? []) as $entry) {
        $path = (string)($entry['path'] ?? '');
        $hash = strtolower((string)($entry['sha256'] ?? ''));
        if (promotion_safe_path($path) && preg_match('/^[a-f0-9]{64}$/', $hash)) $liveHashes[$path] = $hash;
    }
    $deployPaths = [];
    foreach ($paths as $path => $hash) {
        if (($liveHashes[$path] ?? '') !== $hash) $deployPaths[$path] = $hash;
    }
    $status = [
        'status' => 'promoting', 'release_id' => $releaseId,
        'requested_at' => (string)($approval['approved_at'] ?? ''), 'started_at' => gmdate('c'),
        'file_count' => count($paths), 'changed_file_count' => count($deployPaths), 'health' => 'pending',
        'public_message' => count($deployPaths) . ' changed file(s) are being backed up and verified.',
    ];
    promotion_write_json($statusPath, $status);

    if (!$deployPaths) {
        $health = promotion_run(['/usr/bin/curl', '--fail', '--silent', '--show-error', '--max-time', '20', '-o', '/dev/null', '-w', '%{http_code}', 'https://bakery.sourflour.org/bake/login.php'], 30);
        if ($health['exit'] !== 0 || trim($health['stdout']) !== '200') throw new RuntimeException('Live health check failed.');
        promotion_write_json($hashIndexPath, ['release_id' => $releaseId, 'updated_at' => gmdate('c'), 'files' => $files]);
        $status['status'] = 'succeeded';
        $status['health'] = 'http_200';
        $status['completed_at'] = gmdate('c');
        $status['public_message'] = 'Live already matched the approved Staging version; no files were transferred.';
        promotion_write_json($statusPath, $status);
        echo "ALREADY_CURRENT {$releaseId}\n";
        exit(0);
    }

    if (!empty($config['DB_BACKUP_COMMAND'])) {
        $backup = promotion_run(['/bin/bash', '-lc', $config['DB_BACKUP_COMMAND']], 180);
        if ($backup['exit'] !== 0 || strpos($backup['stdout'], '"status":"success"') === false) {
            throw new RuntimeException('The pre-promotion database backup failed.');
        }
        $status['database_backup'] = $backup['stdout'];
        promotion_write_json($statusPath, $status);
    }

    $activeWork = $workRoot . '/' . $releaseId;
    $incoming = $activeWork . '/incoming';
    if (!mkdir($incoming, 0750, true) && !is_dir($incoming)) throw new RuntimeException('Cannot create incoming release directory.');
    $listPath = $activeWork . '/files.txt';
    file_put_contents($listPath, implode("\n", array_keys($deployPaths)) . "\n", LOCK_EX);
    $releaseSource = $remote . '/releases/' . $releaseId . '/files/';
    $fetch = promotion_run(['/usr/bin/rsync', '-a', '--relative', '--files-from=' . $listPath, '-e', $ssh, $releaseSource, $incoming . '/'], 300);
    if ($fetch['exit'] !== 0) throw new RuntimeException('Could not fetch the approved files from Staging.');
    foreach ($deployPaths as $path => $hash) {
        $source = $incoming . '/' . $path;
        if (!is_file($source) || !hash_equals($hash, hash_file('sha256', $source))) {
            throw new RuntimeException('Staging changed after approval; no Live files were changed. Approve the new Staging version again.');
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
            $lint = promotion_run([PHP_BINARY, '-l', $source], 20);
            if ($lint['exit'] !== 0) throw new RuntimeException('A PHP file failed validation; no Live files were changed.');
        }
    }

    $releaseBackup = $backupRoot . '/' . $releaseId;
    $backupFiles = $releaseBackup . '/files';
    if (!mkdir($backupFiles, 0750, true) && !is_dir($backupFiles)) throw new RuntimeException('Cannot create release backup.');
    $existed = [];
    foreach (array_keys($deployPaths) as $path) {
        $target = $liveRoot . '/' . $path;
        $existed[$path] = is_file($target);
        if ($existed[$path]) {
            $backupTarget = $backupFiles . '/' . $path;
            if (!is_dir(dirname($backupTarget))) mkdir(dirname($backupTarget), 0750, true);
            if (!copy($target, $backupTarget)) throw new RuntimeException('Could not back up a Live file.');
        }
    }
    promotion_write_json($releaseBackup . '/release.json', ['approval' => $approval, 'previously_existed' => $existed]);

    $changed = [];
    try {
        foreach (array_keys($deployPaths) as $path) {
            $source = $incoming . '/' . $path;
            $target = $liveRoot . '/' . $path;
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) {
                throw new RuntimeException('Could not create a Live directory.');
            }
            $tempTarget = $target . '.promote-' . bin2hex(random_bytes(3));
            if (!copy($source, $tempTarget) || !chmod($tempTarget, 0644) || !rename($tempTarget, $target)) {
                @unlink($tempTarget);
                throw new RuntimeException('Could not atomically replace a Live file.');
            }
            $changed[] = $path;
        }
        $health = promotion_run(['/usr/bin/curl', '--fail', '--silent', '--show-error', '--max-time', '20', '-o', '/dev/null', '-w', '%{http_code}', 'https://bakery.sourflour.org/bake/login.php'], 30);
        if ($health['exit'] !== 0 || trim($health['stdout']) !== '200') throw new RuntimeException('Live health check failed after promotion.');
    } catch (Throwable $deployError) {
        foreach (array_reverse($changed) as $path) {
            $target = $liveRoot . '/' . $path;
            $old = $backupFiles . '/' . $path;
            if (!empty($existed[$path]) && is_file($old)) copy($old, $target); else @unlink($target);
        }
        $status['status'] = 'rolled_back';
        $status['health'] = 'failed';
        $status['completed_at'] = gmdate('c');
        $status['public_message'] = 'Promotion failed its safety check and Live was restored automatically.';
        promotion_write_json($statusPath, $status);
        throw $deployError;
    }

    $status['status'] = 'succeeded';
    $status['health'] = 'http_200';
    $status['completed_at'] = gmdate('c');
    $status['public_message'] = count($deployPaths) . ' changed file(s) from the approved Staging version are now Live.';
    $status['backup_path'] = $releaseBackup;
    promotion_write_json($hashIndexPath, ['release_id' => $releaseId, 'updated_at' => gmdate('c'), 'files' => $files]);
    promotion_write_json($statusPath, $status);
    echo "PROMOTED {$releaseId} changed_files=" . count($deployPaths) . " total_files=" . count($paths) . "\n";
} catch (Throwable $error) {
    @unlink($approvalTemp);
    if (is_array($status) && ($status['status'] ?? '') !== 'rolled_back') {
        $status['status'] = 'failed';
        $status['health'] = 'not_changed';
        $status['completed_at'] = gmdate('c');
        $status['public_message'] = 'Promotion stopped safely before completion. Approve Staging again after reviewing the server log.';
        $status['error'] = $error->getMessage();
        promotion_write_json($statusPath, $status);
    }
    fwrite(STDERR, 'PROMOTION FAILED: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if ($activeWork !== '') promotion_remove_tree($activeWork, $workRoot);
    flock($lock, LOCK_UN);
    fclose($lock);
}
