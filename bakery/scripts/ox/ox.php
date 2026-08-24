<?php
/**
 * Ox Prime controller CLI for Sour Flour OS.
 *
 *   php scripts/ox/ox.php doctor
 *   php scripts/ox/ox.php status
 *   php scripts/ox/ox.php lease list|acquire|release|heartbeat [...]
 *   php scripts/ox/ox.php spawn --packet=tmp/ox/prompts/wave1-planner.json
 *   php scripts/ox/ox.php nudge --session=ses_x --prompt-file=tmp/ox/prompts/n.txt
 *   php scripts/ox/ox.php abort --session=ses_x --reason="..."
 *   php scripts/ox/ox.php audit
 *
 * Transport: persistent `opencode serve` on localhost (see tmp/ox/server.json).
 * Credentials come from OPENCODE_SERVER_PASSWORD and are never printed.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);

$OX_ROOT = dirname(__DIR__, 2);
$OX_TMP = $OX_ROOT . '/tmp/ox';

$GLOBALS['ox_root'] = $OX_ROOT;
$GLOBALS['ox_tmp'] = $OX_TMP;

require __DIR__ . '/ox_lib.php';

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z0-9_-]+)=(.*)$/', $a, $m)) {
        $args[$m[1]] = $m[2];
    } elseif (preg_match('/^--([a-z0-9_-]+)$/', $a, $m)) {
        $args[$m[1]] = true;
    } elseif (!isset($args['command'])) {
        $args['command'] = $a;
    } else {
        $args['sub'] = $a;
    }
}
$command = $args['command'] ?? 'status';

function ox_out(string $s): void
{
    echo $s, PHP_EOL;
}

function ox_lease_dir(): string
{
    $dir = $GLOBALS['ox_tmp'] . '/leases';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function ox_lease_read_all(): array
{
    $out = [];
    foreach (glob(ox_lease_dir() . '/*.json') ?: [] as $f) {
        $j = json_decode((string)file_get_contents($f), true);
        if (is_array($j)) {
            $j['_file'] = basename($f);
            $out[] = $j;
        }
    }
    return $out;
}

function ox_lease_stale(array $lease, int $minutes = 20): bool
{
    $hb = strtotime($lease['heartbeat_at'] ?? '');
    return $hb === false || (time() - $hb) > $minutes * 60;
}

function ox_paths_conflict(array $a, array $b): bool
{
    foreach ($a as $pa) {
        foreach ($b as $pb) {
            $pa = rtrim((string)$pa, '/');
            $pb = rtrim((string)$pb, '/');
            if ($pa === '' || $pb === '') {
                continue;
            }
            if (str_starts_with($pa, $pb) || str_starts_with($pb, $pa)) {
                return true;
            }
        }
    }
    return false;
}

function ox_cmd_lease(): void
{
    global $args;
    $sub = $args['sub'] ?? 'list';
    if ($sub === 'list') {
        foreach (ox_lease_read_all() as $l) {
            printf(
                "%s [%s] holder=%s kind=%s paths=%s age=%s%s\n",
                $l['mission'] ?? '?',
                $l['_file'],
                $l['holder'] ?? '?',
                $l['kind'] ?? 'lane',
                implode(',', $l['paths'] ?? []),
                isset($l['acquired_at']) ? round((time() - strtotime($l['acquired_at'])) / 60) . 'm' : '?',
                ox_lease_stale($l) ? ' STALE' : ''
            );
        }
        return;
    }
    $mission = (string)($args['mission'] ?? '');
    if ($mission === '') {
        throw new InvalidArgumentException('--mission= is required');
    }
    $file = ox_lease_dir() . '/' . preg_replace('/[^a-z0-9_-]+/', '-', strtolower($mission)) . '.json';
    if ($sub === 'release') {
        if (is_file($file)) {
            unlink($file);
            ox_out("released {$mission}");
        } else {
            ox_out("no lease for {$mission}");
        }
        return;
    }
    if ($sub === 'heartbeat') {
        if (!is_file($file)) {
            throw new RuntimeException("no lease for {$mission}");
        }
        $j = json_decode((string)file_get_contents($file), true);
        $j['heartbeat_at'] = gmdate('c');
        file_put_contents($file, json_encode($j, JSON_PRETTY_PRINT));
        ox_out("heartbeat {$mission}");
        return;
    }
    if ($sub !== 'acquire') {
        throw new InvalidArgumentException("unknown lease subcommand {$sub}");
    }
    $kind = (string)($args['kind'] ?? 'lane');
    $paths = array_filter(array_map('trim', explode(',', (string)($args['paths'] ?? ''))));
    $holder = (string)($args['holder'] ?? ('pid:' . getmypid()));
    foreach (ox_lease_read_all() as $existing) {
        if (($existing['mission'] ?? '') === $mission) {
            throw new RuntimeException("mission {$mission} already holds lease {$existing['_file']}");
        }
        if (($existing['kind'] ?? 'lane') === 'testdb' || $kind === 'testdb') {
            throw new RuntimeException("test-database writer already held by {$existing['mission']} - serialize");
        }
        if (ox_paths_conflict($paths, $existing['paths'] ?? [])) {
            throw new RuntimeException(
                "lane conflict with {$existing['mission']} ({$existing['_file']}): "
                . implode(',', $existing['paths'] ?? [])
            );
        }
    }
    $now = gmdate('c');
    $payload = [
        'mission' => $mission,
        'holder' => $holder,
        'kind' => $kind,
        'paths' => array_values($paths),
        'acquired_at' => $now,
        'heartbeat_at' => $now,
    ];
    $fh = fopen($file, 'x');
    if ($fh === false) {
        throw new RuntimeException("lease file exists: {$file}");
    }
    fwrite($fh, json_encode($payload, JSON_PRETTY_PRINT));
    fclose($fh);
    ox_out("acquired {$mission} kind={$kind} paths=" . implode(',', $paths));
}

function ox_required_packet_fields(): array
{
    return ['mission_id', 'agent_slug', 'title', 'outcome', 'owned_files', 'tests', 'done_when'];
}

function ox_cmd_spawn(): void
{
    global $args;
    $packetFile = (string)($args['packet'] ?? '');
    if ($packetFile === '' || !is_file($packetFile)) {
        throw new InvalidArgumentException('--packet=<json file> is required');
    }
    $p = json_decode((string)file_get_contents($packetFile), true);
    if (!is_array($p)) {
        throw new InvalidArgumentException('packet is not valid JSON');
    }
    $missing = array_diff(ox_required_packet_fields(), array_keys($p));
    if ($missing !== []) {
        throw new InvalidArgumentException('packet missing fields: ' . implode(',', $missing));
    }
    $prompt = '';
    if (!empty($p['prompt_file']) && is_file($GLOBALS['ox_root'] . '/' . $p['prompt_file'])) {
        $prompt = (string)file_get_contents($GLOBALS['ox_root'] . '/' . $p['prompt_file']);
    } elseif (!empty($p['prompt'])) {
        $prompt = (string)$p['prompt'];
    }
    if (trim($prompt) === '') {
        throw new InvalidArgumentException('packet needs prompt or prompt_file');
    }
    ox_cmd_lease_acquire_from_packet($p);

    $session = ox_http('POST', '/session', [
        'title' => function_exists('mb_substr') ? mb_substr((string)$p['title'], 0, 80) : substr((string)$p['title'], 0, 80),
    ]);
    $sid = (string)$session['id'];

    $record = [
        'mission_id' => $p['mission_id'],
        'agent_slug' => $p['agent_slug'],
        'homebase_agent_arg' => $p['homebase_agent_arg'] ?? $p['agent_slug'],
        'title' => $p['title'],
        'outcome' => $p['outcome'],
        'role_stage' => $p['role_stage'] ?? '',
        'evidence_gap' => $p['evidence_gap'] ?? '',
        'owned_files' => $p['owned_files'],
        'shared_files' => $p['shared_files'] ?? [],
        'forbidden_paths' => $p['forbidden_paths'] ?? [],
        'mutation_class' => $p['mutation_class'] ?? 'read-only',
        'db_resource' => $p['db_resource'] ?? 'none',
        'tests' => $p['tests'],
        'invariants' => $p['invariants'] ?? [],
        'dependencies' => $p['dependencies'] ?? [],
        'risk' => $p['risk'] ?? '',
        'done_when' => $p['done_when'],
        'session_id' => $sid,
        'status' => 'spawned',
        'spawned_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
        'handoff_id' => null,
        'commit_hash' => null,
    ];
    $missionsDir = $GLOBALS['ox_tmp'] . '/missions';
    if (!is_dir($missionsDir)) {
        mkdir($missionsDir, 0777, true);
    }
    file_put_contents(
        $missionsDir . '/' . preg_replace('/[^a-z0-9_-]+/', '-', strtolower((string)$p['mission_id'])) . '.json',
        json_encode($record, JSON_PRETTY_PRINT)
    );

    $sendFile = $GLOBALS['ox_tmp'] . '/prompts/send-' . preg_replace('/[^a-z0-9_-]+/', '-', strtolower((string)$p['mission_id'])) . '.json';
    $promptDir = dirname($sendFile);
    if (!is_dir($promptDir)) {
        mkdir($promptDir, 0777, true);
    }
    file_put_contents($sendFile, json_encode([
        'session' => $sid,
        'agent' => (string)$p['agent_slug'],
        'prompt_file' => isset($p['prompt_file']) ? $GLOBALS['ox_root'] . '/' . $p['prompt_file'] : null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $php = PHP_BINARY;
    $runner = __DIR__ . DIRECTORY_SEPARATOR . 'ox_send.php';
    pclose(popen("start /B \"\" \"{$php}\" \"{$runner}\" \"{$sendFile}\" > NUL 2>&1", 'r'));
    ox_out("spawned {$p['mission_id']} session={$sid} prompt dispatched async");
}

function ox_cmd_lease_acquire_from_packet(array $p): void
{
    if (($p['mutation_class'] ?? 'read-only') === 'read-only' && empty($p['db_resource'])) {
        return;
    }
    $args = [
        'sub' => 'acquire',
        'mission' => (string)$p['mission_id'],
        'kind' => (!empty($p['db_resource']) && $p['db_resource'] !== 'none') ? 'testdb' : 'lane',
        'paths' => implode(',', (array)$p['owned_files']),
        'holder' => (string)($p['session_hint'] ?? 'controller-spawn'),
    ];
    ox_cmd_lease();
}

function ox_cmd_nudge(): void
{
    global $args;
    $sid = (string)($args['session'] ?? '');
    $pf = (string)($args['prompt-file'] ?? '');
    if ($sid === '' || $pf === '' || !is_file($pf)) {
        throw new InvalidArgumentException('--session= and --prompt-file= are required');
    }
    $text = trim((string)file_get_contents($pf));
    $stamp = gmdate('Ymd-His');
    $promptOut = $GLOBALS['ox_tmp'] . '/prompts/nudge-' . $stamp . '.txt';
    file_put_contents($promptOut, $text);
    $sendFile = $GLOBALS['ox_tmp'] . '/prompts/send-nudge-' . $stamp . '.json';
    file_put_contents($sendFile, json_encode([
        'session' => $sid,
        'prompt_file' => $promptOut,
    ], JSON_PRETTY_PRINT));
    $php = PHP_BINARY;
    $runner = __DIR__ . DIRECTORY_SEPARATOR . 'ox_send.php';
    pclose(popen("start /B \"\" \"{$php}\" \"{$runner}\" \"{$sendFile}\" > NUL 2>&1", 'r'));
    ox_out("nudge dispatched to {$sid}");
}

function ox_cmd_abort(): void
{
    global $args;
    $sid = (string)($args['session'] ?? '');
    if ($sid === '') {
        throw new InvalidArgumentException('--session= is required');
    }
    try {
        ox_http('POST', "/session/{$sid}/abort");
        ox_out("aborted {$sid}");
    } catch (RuntimeException $e) {
        ox_out("abort call failed (session may be idle): " . $e->getMessage());
    }
    if (!empty($args['reason'])) {
        $missionsDir = $GLOBALS['ox_tmp'] . '/missions';
        foreach (glob($missionsDir . '/*.json') ?: [] as $f) {
            $j = json_decode((string)file_get_contents($f), true);
            if (($j['session_id'] ?? '') === $sid) {
                $j['status'] = 'aborted';
                $j['abort_reason'] = (string)$args['reason'];
                $j['updated_at'] = gmdate('c');
                file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT));
                ox_out('mission record updated: ' . basename($f));
            }
        }
    }
}

function ox_homebase_opens(): array
{
    require_once $GLOBALS['ox_root'] . '/includes/config.php';
    require_once $GLOBALS['ox_root'] . '/includes/database.php';
    require_once $GLOBALS['ox_root'] . '/includes/test_target_guard.php';
    $db = bakery_homebase_durable_connection(check_mysql_connection());
    return $db->query(
        "SELECT id, agent_name, status, started_at, LEFT(mission, 100) AS m
         FROM agent_sessions WHERE status <> 'handed_off' ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
}

function ox_git_dirty(): array
{
    chdir($GLOBALS['ox_root']);
    exec('git status --porcelain=v1 2>&1', $lines, $code);
    return [$lines, $code];
}

function ox_cmd_doctor(): void
{
    $fail = 0;
    $check = static function (string $name, bool $ok, string $note = '') use (&$fail): void {
        ox_out(($ok ? 'PASS ' : 'FAIL ') . $name . ($note !== '' ? " ({$note})" : ''));
        if (!$ok) {
            $fail++;
        }
    };
    $check('php >= 8.3', PHP_VERSION_ID >= 80300, PHP_VERSION);
    exec('git --version', $o, $c);
    $check('git present', $c === 0, $o[0] ?? '');
    exec('opencode --version 2>&1', $o2, $c2);
    $check('opencode cli present', $c2 === 0, trim(implode(' ', $o2)));
    try {
        $proj = ox_http('GET', '/project/current');
        $check('opencode server reachable', true, ox_server_base() . ' worktree=' . basename((string)$proj['worktree']));
    } catch (Throwable $e) {
        $check('opencode server reachable', false, $e->getMessage());
    }
    try {
        $opens = ox_homebase_opens();
        $check('homebase durable ledger reachable', true, count($opens) . ' open sessions');
    } catch (Throwable $e) {
        $check('homebase durable ledger reachable', false, $e->getMessage());
    }
    [$dirty] = ox_git_dirty();
    $check('working tree understood', true, count($dirty) . ' entries; see status/audit for attribution');
    $check('tmp/ox writable', is_writable($GLOBALS['ox_tmp']), $GLOBALS['ox_tmp']);
    $leases = ox_lease_read_all();
    $stale = array_filter($leases, 'ox_lease_stale');
    $check('leases sane', count($stale) === 0, count($leases) . ' active, ' . count($stale) . ' stale');
    exit($fail === 0 ? 0 : 1);
}

function ox_cmd_status(): void
{
    ox_out('== OpenCode sessions ==');
    try {
        $sessions = ox_http('GET', '/session');
        usort($sessions, static fn ($a, $b) => strcmp((string)($b['time']['updated'] ?? ''), (string)($a['time']['updated'] ?? '')));
        foreach (array_slice($sessions, 0, 12) as $s) {
            printf(
                "%s %-40s parent=%s updated=%s\n",
                $s['id'],
                mb_substr((string)($s['title'] ?? ''), 0, 40),
                $s['parentID'] ?: '-',
                isset($s['time']['updated']) ? date('H:i', (int)round(((float)$s['time']['updated']) / 1000)) : '?'
            );
        }
    } catch (Throwable $e) {
        ox_out('unavailable: ' . $e->getMessage());
    }
    ox_out('== Homebase open sessions ==');
    try {
        foreach (ox_homebase_opens() as $r) {
            printf("#%d [%s] %s | %s\n", $r['id'], $r['status'], $r['started_at'], $r['agent_name']);
        }
    } catch (Throwable $e) {
        ox_out('unavailable: ' . $e->getMessage());
    }
    ox_out('== Leases ==');
    ob_start();
    ox_cmd_lease();
    ox_out(trim((string)ob_get_clean()) ?: '(none)');
    ox_out('== Working tree ==');
    [$dirty, $code] = ox_git_dirty();
    if ($code !== 0) {
        ox_out('git status failed');
    } elseif ($dirty === []) {
        ox_out('(clean of tracked changes)');
    } else {
        foreach ($dirty as $d) {
            ox_out('  ' . $d);
        }
    }
    ox_out('== Mission records ==');
    foreach (glob($GLOBALS['ox_tmp'] . '/missions/*.json') ?: [] as $f) {
        $j = json_decode((string)file_get_contents($f), true);
        printf("%s [%s] sid=%s\n", $j['mission_id'] ?? '?', $j['status'] ?? '?', $j['session_id'] ?? '?');
    }
}

function ox_cmd_audit(): void
{
    chdir($GLOBALS['ox_root']);
    ox_out('# OX AUDIT ' . date('c'));
    exec('git log --oneline -8', $log);
    ox_out("\n## Recent commits\n" . implode("\n", array_map(static fn ($l) => "- {$l}", $log)));
    [$dirty] = ox_git_dirty();
    ox_out("\n## Dirty files (" . count($dirty) . ")");
    $claimed = [];
    foreach (glob($GLOBALS['ox_tmp'] . '/missions/*.json') ?: [] as $f) {
        $j = json_decode((string)file_get_contents($f), true);
        if (($j['status'] ?? '') === 'active') {
            $claimed = array_merge($claimed, (array)($j['owned_files'] ?? []));
        }
    }
    foreach ($dirty as $d) {
        $path = preg_replace('/^.../', '', $d);
        $attr = '(unattributed)';
        foreach ($claimed as $cp) {
            if (str_contains($path, ltrim((string)$cp, '/'))) {
                $attr = '(claimed)';
                break;
            }
        }
        ox_out("- `{$d}` {$attr}");
    }
    ox_out("\n## Homebase opens");
    try {
        foreach (ox_homebase_opens() as $r) {
            ox_out("- #{$r['id']} [{$r['status']}] {$r['agent_name']}: {$r['m']}");
        }
    } catch (Throwable $e) {
        ox_out('- unavailable: ' . $e->getMessage());
    }
    ox_out("\n## Leases");
    foreach (ox_lease_read_all() as $l) {
        ox_out("- {$l['mission']} kind={$l['kind']} stale=" . (ox_lease_stale($l) ? 'yes' : 'no'));
    }
}

try {
    switch ($command) {
        case 'doctor':
            ox_cmd_doctor();
            break;
        case 'status':
            ox_cmd_status();
            break;
        case 'audit':
            ox_cmd_audit();
            break;
        case 'lease':
            $args['sub'] = $args['sub'] ?? 'list';
            ox_cmd_lease();
            break;
        case 'spawn':
            ox_cmd_spawn();
            break;
        case 'nudge':
            ox_cmd_nudge();
            break;
        case 'abort':
            ox_cmd_abort();
            break;
        default:
            throw new InvalidArgumentException("unknown command {$command}");
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ox error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
