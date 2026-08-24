<?php
/**
 * Detached mission launcher used by ox.php spawn.
 * Sends a short bootstrap message through `opencode run --attach` so project
 * agents (.opencode/agent/*.md) resolve client-side. The worker reads its full
 * mission brief from an authored file, avoiding shell quoting/length damage.
 *
 * Payload JSON: {session, agent, prompt_file}
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "send payload missing\n");
    exit(1);
}
$j = json_decode((string)file_get_contents($file), true);
if (!is_array($j) || empty($j['session']) || empty($j['prompt_file']) || !is_file($j['prompt_file'])) {
    fwrite(STDERR, "bad send payload\n");
    exit(1);
}

$base = 'http://127.0.0.1:4119';
$serverFile = dirname(__DIR__, 2) . '/tmp/ox/server.json';
if (is_file($serverFile)) {
    $srv = json_decode((string)file_get_contents($serverFile), true);
    if (!empty($srv['url'])) {
        $base = rtrim((string)$srv['url'], '/');
    }
}

$bootstrap = 'MISSION START. Read your mission brief at '
    . $j['prompt_file']
    . ' and execute it exactly and completely. It defines your role limits, deliverable path, and required final reply.';

$cmd = 'opencode run --attach "' . $base . '"'
    . ' -s "' . $j['session'] . '"'
    . ' --agent "' . ($j['agent'] ?? 'build') . '"'
    . ' "' . str_replace('"', '', $bootstrap) . '"';

$logs = dirname(__DIR__, 2) . '/tmp/ox/prompts/launch-' . gmdate('Ymd-His') . '-'
    . substr((string)$j['session'], -6) . '.log';
$handle = popen("cmd /c \"{$cmd}\" > \"{$logs}\" 2>&1", 'r');
if ($handle === false) {
    fwrite(STDERR, "failed to launch opencode run\n");
    exit(1);
}
pclose($handle);
exit(0);
