<?php
/**
 * Auto-push must not call proc_open when PowerShell or proc_open is missing,
 * and the banner script must not paste response HTML into the status line.
 *
 *   php tests/run_auto_push_quiet_fail_tests.php
 *
 * Local only. Does not open a database and does not contact Staging or Live.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
$fail = 0;
$assert = function ($ok, $label) use (&$fail) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
};

require_once $root . '/includes/auto_push_control.php';

$plain = function ($message) {
    $message = (string)$message;
    if ($message === '') {
        return false;
    }
    if (strpos($message, '<') !== false || strpos($message, '>') !== false) {
        return false;
    }
    if (stripos($message, 'Warning') !== false || stripos($message, 'proc_open') !== false) {
        return false;
    }
    return true;
};

$runChild = function ($disableProcOpen, $hidePowerShell = false) use ($root) {
    $probe = tempnam(sys_get_temp_dir(), 'apq');
    $script = $probe . '.php';
    @unlink($probe);
    file_put_contents($script, <<<'PHP'
<?php
define('ACCESS_ALLOWED', true);
require getenv('AUTO_PUSH_CONTROL');
$warnings = [];
set_error_handler(static function ($severity, $message) use (&$warnings) {
    $warnings[] = (string)$message;
    return true;
});
try {
    bakery_auto_push_run_ctl('ensure');
    $payload = ['threw' => false, 'message' => '', 'warnings' => $warnings];
} catch (Throwable $e) {
    $payload = ['threw' => true, 'message' => $e->getMessage(), 'warnings' => $warnings];
}
echo json_encode($payload);
PHP
    );
    $cmd = [PHP_BINARY, '-d', 'display_errors=1', '-d', 'html_errors=1'];
    if ($disableProcOpen) {
        $cmd[] = '-d';
        $cmd[] = 'disable_functions=proc_open';
    }
    $cmd[] = $script;
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    $env['AUTO_PUSH_CONTROL'] = $root . '/includes/auto_push_control.php';
    // GitHub-hosted Ubuntu includes pwsh. Hide every PowerShell name so this
    // probe tests the missing-shell path instead of launching a real shell.
    if ($hidePowerShell) {
        $bare = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'apq-nops';
        if (!is_dir($bare) && !@mkdir($bare, 0700, true) && !is_dir($bare)) {
            @unlink($script);
            return ['spawned' => false, 'stdout' => '', 'stderr' => '', 'data' => null];
        }
        $env['PATH'] = $bare;
        $env['Path'] = $bare;
    }
    $proc = proc_open($cmd, $descriptors, $pipes, $root, $env);
    if (!is_resource($proc)) {
        @unlink($script);
        return ['spawned' => false, 'stdout' => '', 'stderr' => '', 'data' => null];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    @unlink($script);
    $data = json_decode((string)$stdout, true);
    return [
        'spawned' => true,
        'stdout' => (string)$stdout,
        'stderr' => (string)$stderr,
        'data' => is_array($data) ? $data : null,
    ];
};

$missing = $runChild(false, true);
$missingMessage = is_array($missing['data']) ? (string)($missing['data']['message'] ?? '') : '';
$assert($missing['spawned'] && is_array($missing['data']), 'missing PowerShell probe returned JSON');
$assert(
    is_array($missing['data'])
        && !empty($missing['data']['threw'])
        && $missingMessage === 'PowerShell not found',
    'missing PowerShell fails with a plain message'
        . ($missingMessage === 'PowerShell not found' ? '' : ' (got: ' . $missingMessage . ')')
);
$assert(
    is_array($missing['data']) && empty($missing['data']['warnings'])
        && stripos($missing['stderr'], 'Warning') === false
        && strpos($missing['stdout'], '<b>') === false,
    'missing PowerShell does not emit a proc_open warning'
);
$assert(
    bakery_auto_push_powershell() === null || is_file((string)bakery_auto_push_powershell()),
    'PowerShell lookup returns null or a real file'
);

$disabled = $runChild(true);
$assert($disabled['spawned'] && is_array($disabled['data']), 'disabled proc_open probe returned JSON');
$assert(
    is_array($disabled['data'])
        && !empty($disabled['data']['threw'])
        && ($disabled['data']['message'] ?? '') === 'Auto-push control is unavailable',
    'disabled proc_open fails with a plain message'
);
$disabledWarnings = is_array($disabled['data']) ? implode("\n", $disabled['data']['warnings'] ?? []) : '';
$assert(
    is_array($disabled['data']) && $disabledWarnings === ''
        && stripos($disabled['stderr'], 'Warning') === false
        && stripos($disabled['stderr'] . $disabledWarnings, 'proc_open') === false,
    'disabled proc_open is refused before proc_open is called'
);

$warnings = [];
set_error_handler(static function ($severity, $message) use (&$warnings) {
    $warnings[] = (string)$message;
    return true;
});
$status = bakery_auto_push_status(true);
restore_error_handler();
$assert($warnings === [], 'status ensure does not raise a PHP warning');
$assert(function_exists('bakery_auto_push_proc_open_available'), 'proc_open availability helper exists');
$canSpawn = function_exists('bakery_auto_push_proc_open_available')
    && bakery_auto_push_proc_open_available()
    && bakery_auto_push_powershell() !== null;
if (!$canSpawn) {
    $assert(($status['ok'] ?? null) === false, 'status reports ok false when auto-push cannot start');
    $assert(($status['error'] ?? '') === 'PowerShell not found' || ($status['error'] ?? '') === 'Auto-push control is unavailable', 'status error is a plain message');
} else {
    $assert(($status['ok'] ?? null) === true, 'status stays ok when PowerShell can start');
}

$assert(function_exists('bakery_auto_push_local_message'), 'page-local status copy exists');
if (function_exists('bakery_auto_push_local_message')) {
    $enMissing = bakery_auto_push_local_message('powershell_missing', 'en');
    $esMissing = bakery_auto_push_local_message('powershell_missing', 'es');
    $enUnavailable = bakery_auto_push_local_message('control_unavailable', 'en');
    $esUnavailable = bakery_auto_push_local_message('control_unavailable', 'es');
    $assert($enMissing === 'PowerShell not found', 'English PowerShell message');
    $assert($plain($esMissing) && $esMissing !== $enMissing, 'Spanish PowerShell message');
    $assert($enUnavailable === 'Auto-push control is unavailable', 'English unavailable message');
    $assert($plain($esUnavailable) && $esUnavailable !== $enUnavailable, 'Spanish unavailable message');
    $copy = $enMissing . $esMissing . $enUnavailable . $esUnavailable;
    $assert(strpos($copy, '—') === false && strpos($copy, '–') === false, 'status copy has no dash glyphs');
}

$js = file_get_contents($root . '/includes/auto_push_control.js');
$assert(strpos($js, 'text.slice') === false, 'banner script does not slice raw response text');
$assert(strpos($js, 'innerHTML') === false, 'banner script does not assign innerHTML');
$assert(strpos($js, 'createTextNode') !== false, 'banner script inserts status as a text node');
$assert(strpos($js, '—') === false && strpos($js, '–') === false, 'banner script copy has no dash glyphs');

if ($fail > 0) {
    fwrite(STDERR, $fail . " auto-push quiet-fail check(s) failed.\n");
    exit(1);
}
echo "OK  auto-push quiet fail\n";
exit(0);
