<?php
/**
 * Local-only staging auto-push controls (DreamHost SFTP to staging.sourflour.org).
 * Used by header UI + auto_push_api.php. Safe no-op / refuse outside local.
 * Sync/hooks never call scripts/push_sftp.ps1. Promote approved to Live uses
 * promote_release.ps1, which uploads the staging-tested commit only.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_json_decode_file($path) {
    if (!is_file($path)) {
        return null;
    }
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string)@file_get_contents($path));
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function bakery_auto_push_live_fail_message($output) {
    $out = str_replace("\r\n", "\n", (string)$output);
    if (stripos($out, 'Deployable working tree is dirty') !== false
        || stripos($out, 'Local directly to Live needs a clean') !== false) {
        return 'Local directly to Live needs a clean working tree. Use Promote approved to Live instead — it uploads the staging-tested candidate and ignores in-progress edits.';
    }
    if (preg_match('/Staging no longer has the tested bytes for:\s*([^\r\n.]+)/', $out, $m)) {
        return 'Live promotion failed: staging no longer has the tested bytes for ' . trim($m[1]) . '. A later incremental push overwrote them.';
    }
    if (preg_match('/FullyQualifiedErrorId\s*:\s*(.+)/', $out, $m)) {
        $id = trim($m[1]);
        if ($id !== '' && strlen($id) < 280) {
            return 'Live promotion failed: ' . $id;
        }
    }
    return 'Live promotion failed';
}

function bakery_latest_complete_staging_release($root = null) {
    $root = $root ?: dirname(__DIR__);
    $dir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'deploy'
        . DIRECTORY_SEPARATOR . 'stage' . DIRECTORY_SEPARATOR . 'releases';
    $files = glob($dir . DIRECTORY_SEPARATOR . 'release_*.json') ?: [];
    usort($files, static function ($a, $b) { return filemtime($b) <=> filemtime($a); });
    $complete = null;
    foreach ($files as $path) {
        $data = bakery_json_decode_file($path);
        if (!is_array($data)) {
            continue;
        }
        if (($data['target'] ?? '') !== 'dreamhost-stage' || ($data['lint'] ?? '') !== 'ok') {
            continue;
        }
        if (($data['mode'] ?? '') !== 'all') {
            continue;
        }
        $count = isset($data['files']) && is_array($data['files']) ? count($data['files']) : (int)($data['file_count'] ?? 0);
        if ($count < 50) {
            continue;
        }
        $data['_path'] = $path;
        $complete = $data;
        break;
    }
    if ($complete === null) {
        return null;
    }
    $byPath = [];
    foreach ($complete['files'] as $entry) {
        if (!empty($entry['path'])) {
            $byPath[$entry['path']] = $entry;
        }
    }
    $completeTime = (int)@filemtime($complete['_path']);
    usort($files, static function ($a, $b) { return filemtime($a) <=> filemtime($b); });
    foreach ($files as $path) {
        if ((int)@filemtime($path) <= $completeTime) {
            continue;
        }
        $data = bakery_json_decode_file($path);
        if (!is_array($data) || ($data['target'] ?? '') !== 'dreamhost-stage' || ($data['lint'] ?? '') !== 'ok') {
            continue;
        }
        $nextFiles = $data['files'] ?? [];
        foreach ($nextFiles as $entry) {
            if (!empty($entry['path'])) {
                $byPath[$entry['path']] = $entry;
            }
        }
        if (!empty($data['git_commit'])) {
            $complete['git_commit'] = $data['git_commit'];
        }
    }
    $complete['files'] = array_values($byPath);
    $complete['mode'] = 'effective';
    $complete['file_count'] = count($complete['files']);
    return $complete;
}

function bakery_auto_push_deploy_dir() {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'deploy';
}

function bakery_auto_push_disabled_flag_path() {
    return bakery_auto_push_deploy_dir() . DIRECTORY_SEPARATOR . '.auto_push_disabled';
}

function bakery_auto_push_allowed_emails() {
    $emails = ['danny@sourflour.org'];
    $envEmail = trim((string)($_ENV['LOCAL_ADMIN_EMAIL'] ?? getenv('LOCAL_ADMIN_EMAIL') ?: ''));
    if ($envEmail !== '') {
        $emails[] = strtolower($envEmail);
    }
    return array_values(array_unique(array_map('strtolower', $emails)));
}

function bakery_user_can_control_auto_push($user = null) {
    if (!defined('IS_LOCAL') || !IS_LOCAL) {
        return false;
    }
    if ($user === null) {
        $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
    }
    if (!$user) {
        return false;
    }
    $email = strtolower(trim((string)($user['email'] ?? '')));
    if ($email === '' || !in_array($email, bakery_auto_push_allowed_emails(), true)) {
        return false;
    }
    return ($user['role_slug'] ?? '') === 'administrator';
}

function bakery_auto_push_is_enabled() {
    return !is_file(bakery_auto_push_disabled_flag_path());
}

function bakery_auto_push_powershell() {
    foreach ([
        'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe',
        'powershell.exe',
        'pwsh.exe',
    ] as $candidate) {
        if ($candidate === 'powershell.exe' || $candidate === 'pwsh.exe') {
            return $candidate;
        }
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    return null;
}

function bakery_auto_push_run_ctl($action) {
    $ps = bakery_auto_push_powershell();
    if ($ps === null) {
        throw new RuntimeException('PowerShell not found');
    }
    $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'auto_push_watcher_ctl.ps1';
    if (!is_file($script)) {
        throw new RuntimeException('Missing scripts/auto_push_watcher_ctl.ps1');
    }

    $cmd = [
        $ps,
        '-NoProfile',
        '-ExecutionPolicy', 'Bypass',
        '-File', $script,
        $action,
    ];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Failed to run watcher control');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $data = json_decode(trim((string)$stdout), true);
    if (!is_array($data)) {
        throw new RuntimeException('Watcher control failed: ' . trim($stdout . ' ' . $stderr));
    }
    $data['exit_code'] = $exit;
    return $data;
}

function bakery_auto_push_run_live_promotion($direct = false) {
    if (!bakery_user_can_control_auto_push()) {
        throw new RuntimeException('Only the local administrator can promote to Live.');
    }
    $ps = bakery_auto_push_powershell();
    if ($ps === null) throw new RuntimeException('PowerShell not found');
    $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR
        . ($direct ? 'promote_local_direct.ps1' : 'promote_release.ps1');
    if (!is_file($script)) throw new RuntimeException('Missing Live promotion script');
    $args = [$ps, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $script];
    if ($direct) {
        $args[] = '-Execute';
    } else {
        $root = dirname(__DIR__);
        $staging = bakery_latest_complete_staging_release($root);
        if ($staging === null) {
            throw new RuntimeException('No complete staging release exists. Sync the full site to staging, phone-test, then approve again.');
        }
        $wantCommit = (string)($staging['git_commit'] ?? '');
        $candidateDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'deploy' . DIRECTORY_SEPARATOR . 'releases';
        $candidate = glob($candidateDir . DIRECTORY_SEPARATOR . 'candidate_*.json') ?: [];
        $candidate = array_values(array_filter($candidate, static function ($path) use ($wantCommit) {
            $data = bakery_json_decode_file($path);
            if (!is_array($data) || ($data['production_status'] ?? '') !== 'not-promoted') {
                return false;
            }
            $files = $data['files'] ?? [];
            if (!is_array($files) || count($files) < 50) {
                return false;
            }
            return $wantCommit !== '' && (string)($data['git_commit'] ?? '') === $wantCommit;
        }));
        if (!$candidate) {
            $createScript = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'create_release_candidate.ps1';
            if (!is_file($createScript)) throw new RuntimeException('Release candidate tool is missing.');
            $createArgs = [$ps, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $createScript, '-StagingTestedBy', 'staging-web-approval'];
            $createProc = proc_open($createArgs, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $createPipes, $root, null, ['bypass_shell'=>true]);
            if (!is_resource($createProc)) throw new RuntimeException('Could not create the release candidate.');
            fclose($createPipes[0]);
            $createOut = stream_get_contents($createPipes[1]); $createErr = stream_get_contents($createPipes[2]);
            fclose($createPipes[1]); fclose($createPipes[2]);
            if (proc_close($createProc) !== 0) throw new RuntimeException('Could not create the release candidate: ' . trim($createOut . ' ' . $createErr));
            $created = '';
            if (preg_match('/^CANDIDATE_PATH=(.+)$/m', $createOut, $m)) {
                $created = trim($m[1]);
            }
            if ($created !== '' && is_file($created)) {
                $candidate = [$created];
            } else {
                $candidate = glob($candidateDir . DIRECTORY_SEPARATOR . 'candidate_*.json') ?: [];
                $candidate = array_values(array_filter($candidate, static function ($path) use ($wantCommit) {
                    $data = bakery_json_decode_file($path);
                    return is_array($data)
                        && ($data['production_status'] ?? '') === 'not-promoted'
                        && (string)($data['git_commit'] ?? '') === $wantCommit
                        && is_array($data['files'] ?? null)
                        && count($data['files']) >= 50;
                }));
            }
        }
        if (!$candidate) throw new RuntimeException('No immutable release candidate could be created from the verified Staging release.');
        usort($candidate, static function ($a, $b) { return filemtime($b) <=> filemtime($a); });
        $data = bakery_json_decode_file($candidate[0]);
        $id = is_array($data) ? (string)($data['release_id'] ?? '') : '';
        if ($id === '') throw new RuntimeException('Latest release candidate is invalid.');
        $args[] = '-Candidate'; $args[] = $candidate[0];
        $args[] = '-Execute'; $args[] = '-ConfirmReleaseId'; $args[] = $id;
    }
    // Preserve the complete Windows process environment (SystemRoot, PATH,
    // crypto providers, etc.). Replacing it breaks Windows PowerShell startup.
    putenv('BAKERY_ENABLE_LIVE_PROMOTION=YES');
    $proc = proc_open($args, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__), null, ['bypass_shell'=>true]);
    if (!is_resource($proc)) throw new RuntimeException('Failed to start Live promotion');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc);
    return ['ok'=>$exit === 0, 'exit_code'=>$exit, 'output'=>trim($out . "\n" . $err)];
}

function bakery_auto_push_watcher_running() {
    $pidPath = bakery_auto_push_deploy_dir() . DIRECTORY_SEPARATOR . '.watch_push.pid';
    if (!is_file($pidPath)) {
        return false;
    }
    $info = json_decode((string)file_get_contents($pidPath), true);
    $pid = isset($info['pid']) ? (int)$info['pid'] : 0;
    if ($pid <= 0) {
        return false;
    }
    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        $out = [];
        @exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL', $out);
        $joined = implode(' ', $out);
        return (strpos($joined, (string)$pid) !== false);
    }
    return function_exists('posix_kill') ? @posix_kill($pid, 0) : true;
}

function bakery_auto_push_ensure_watcher() {
    if (!bakery_auto_push_is_enabled()) {
        try {
            bakery_auto_push_run_ctl('stop');
        } catch (Throwable $e) {
            // ignore
        }
        return ['running' => false, 'message' => 'Auto-push off'];
    }
    return bakery_auto_push_run_ctl('ensure');
}

function bakery_auto_push_set_enabled($enabled) {
    $dir = bakery_auto_push_deploy_dir();
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create deploy directory');
        }
    }
    $flag = bakery_auto_push_disabled_flag_path();
    if ($enabled) {
        if (is_file($flag) && !unlink($flag)) {
            throw new RuntimeException('Could not enable auto-push (remove disable flag failed)');
        }
        $log = $dir . DIRECTORY_SEPARATOR . 'auto_push.log';
        $line = date('Y-m-d H:i:s') . "  UI  auto-push ENABLED\n";
        @file_put_contents($log, $line, FILE_APPEND);
        bakery_auto_push_ensure_watcher();
        return true;
    }

    $payload = "Disabled from local UI at " . date('c') . "\n";
    if (file_put_contents($flag, $payload) === false) {
        throw new RuntimeException('Could not disable auto-push');
    }
    $log = $dir . DIRECTORY_SEPARATOR . 'auto_push.log';
    $line = date('Y-m-d H:i:s') . "  UI  auto-push DISABLED\n";
    @file_put_contents($log, $line, FILE_APPEND);
    try {
        bakery_auto_push_run_ctl('stop');
    } catch (Throwable $e) {
        // Flag is enough; watcher self-exits on disable file too.
    }
    return false;
}

function bakery_auto_push_last_record() {
    $dir = bakery_auto_push_deploy_dir();
    $stageLast = $dir . DIRECTORY_SEPARATOR . 'stage' . DIRECTORY_SEPARATOR . 'LAST_DEPLOY.json';
    $last = is_file($stageLast)
        ? $stageLast
        : $dir . DIRECTORY_SEPARATOR . 'LAST_DEPLOY.json';
    if (!is_file($last)) {
        return null;
    }
    $data = json_decode((string)file_get_contents($last), true);
    if (!is_array($data)) {
        return null;
    }
    return [
        'recorded_at' => $data['recorded_at'] ?? null,
        'method' => $data['method'] ?? null,
        'file_count' => isset($data['uploaded_files']) && is_array($data['uploaded_files'])
            ? count($data['uploaded_files'])
            : null,
        'push_stamp' => $data['push_stamp'] ?? null,
    ];
}

function bakery_auto_push_status($ensureWatcher = false) {
    $enabled = bakery_auto_push_is_enabled();
    $watcher = ['running' => bakery_auto_push_watcher_running()];
    if ($ensureWatcher && $enabled && empty($watcher['running'])) {
        try {
            $watcher = bakery_auto_push_ensure_watcher();
        } catch (Throwable $e) {
            $watcher = ['running' => false, 'error' => $e->getMessage()];
        }
    } elseif ($ensureWatcher && !$enabled && !empty($watcher['running'])) {
        try {
            bakery_auto_push_run_ctl('stop');
            $watcher = ['running' => false];
        } catch (Throwable $e) {
            // ignore
        }
    }

    return [
        'ok' => true,
        'local' => defined('IS_LOCAL') && IS_LOCAL,
        'enabled' => $enabled,
        'watching' => !empty($watcher['running']),
        'watcher' => $watcher,
        'last' => bakery_auto_push_last_record(),
        'live_url' => 'https://staging.sourflour.org/',
        'staging_url' => 'https://staging.sourflour.org/',
    ];
}

/**
 * Run push_sftp_stage.ps1 and return structured result.
 * Auto-push never invokes the live /bake script.
 */
function bakery_auto_push_run_sync() {
    if (!defined('IS_LOCAL') || !IS_LOCAL) {
        throw new RuntimeException('Sync is only available on the local app');
    }

    $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'push_sftp_stage.ps1';
    if (!is_file($script)) {
        throw new RuntimeException('Missing scripts/push_sftp_stage.ps1');
    }

    $ps = bakery_auto_push_powershell();
    if ($ps === null) {
        throw new RuntimeException('PowerShell not found');
    }

    $cmd = [
        $ps,
        '-NoProfile',
        '-ExecutionPolicy', 'Bypass',
        '-File', $script,
    ];

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    // Inherit PHP's environment. Get-PythonLauncher in push_sftp_stage.ps1 also
    // resolves absolute Python paths so a stripped PATH still works.
    $process = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Failed to start push script');
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start = time();
    $timeout = 360;
    $exit = null;

    while (true) {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            // On Windows, only the first finished proc_get_status() has the real exit code.
            if ($exit === null && isset($status['exitcode']) && (int)$status['exitcode'] >= 0) {
                $exit = (int)$status['exitcode'];
            }
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            break;
        }
        if ((time() - $start) > $timeout) {
            proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            throw new RuntimeException('Sync timed out after ' . $timeout . 's');
        }
        usleep(200000);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeExit = proc_close($process);
    if ($exit === null || $exit < 0) {
        $exit = (int)$closeExit;
    }

    $output = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));
    // Strip PowerShell CLIXML noise sometimes written to stderr when piped.
    $output = preg_replace('/#< CLIXML[\s\S]*$/m', '', $output);
    $output = trim((string)$output);

    $log = bakery_auto_push_deploy_dir() . DIRECTORY_SEPARATOR . 'auto_push.log';
    $logLine = date('Y-m-d H:i:s') . "  UI  SYNC exit={$exit}";
    if ($exit !== 0 && $output !== '') {
        $snippet = preg_replace('/\s+/', ' ', $output);
        $logLine .= '  ' . substr($snippet, 0, 500);
    }
    $logLine .= "\n";
    @file_put_contents($log, $logLine, FILE_APPEND);

    return [
        'ok' => ($exit === 0),
        'exit_code' => $exit,
        'output' => $output,
        'status' => bakery_auto_push_status(),
    ];
}
