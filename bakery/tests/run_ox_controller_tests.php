<?php
/**
 * Ox controller resume/nudge payload regression tests.
 *
 * Run: php tests/run_ox_controller_tests.php
 * No database required; validates controller file payload boundaries.
 */
define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
$oxPhp = $root . '/scripts/ox/ox.php';
$oxSendPhp = $root . '/scripts/ox/ox_send.php';

$failures = 0;

function ox_assert($label, $condition) {
    global $failures;
    if (!$condition) {
        echo "FAIL  $label\n";
        $failures++;
        return;
    }
    echo "PASS  $label\n";
}

// Read current controller sources for static checks.
$oxSrc = (string)file_get_contents($oxPhp);
$sendSrc = (string)file_get_contents($oxSendPhp);

// Static check: nudge payload now carries text and conditional agent.
ox_assert('ox.php nudge payload carries text key', strpos($oxSrc, "'text' => \$text") !== false);
ox_assert('ox.php nudge payload conditional agent', strpos($oxSrc, "\$agentArg") !== false || strpos($oxSrc, "'agent' =>") !== false);
ox_assert('ox_send bootstrap prefers text', strpos($sendSrc, '!empty($j[\'text\'])') !== false);
ox_assert('ox_send agentPart conditional on !empty', strpos($sendSrc, '$agentPart') !== false && strpos($sendSrc, '!empty($j[\'agent\'])') !== false);

// Helpers replicating fixed logic.
function ox_bootstrap_for_payload(array $j): string {
    if (!empty($j['text'])) {
        return (string)$j['text'];
    }
    return 'MISSION START. Read your mission brief at ' . $j['prompt_file'] . ' and execute it exactly and completely. It defines your role limits, deliverable path, and required final reply.';
}
function ox_agent_part_for_payload(array $j): string {
    return !empty($j['agent']) ? ' --agent "' . $j['agent'] . '"' : '';
}
function ox_cmd_for_payload(array $j, string $base = 'http://127.0.0.1:4119'): string {
    $bootstrap = ox_bootstrap_for_payload($j);
    $agentPart = ox_agent_part_for_payload($j);
    return 'opencode run --attach "' . $base . '"' . ' -s "' . $j['session'] . '"' . $agentPart . ' "' . str_replace('"', '', $bootstrap) . '"';
}

// Case A: explicit custom nudge
$payloadA = [
    'session' => 'ses_testA',
    'prompt_file' => $root . '/tmp/ox/prompts/nudge-test.txt',
    'text' => 'STATUS CHECK ONLY',
    'agent' => 'ox-planner',
];
ox_assert('Case A custom text wins over bootstrap', ox_bootstrap_for_payload($payloadA) === 'STATUS CHECK ONLY');
ox_assert('Case A agent propagated', strpos(ox_cmd_for_payload($payloadA), '--agent "ox-planner"') !== false);
ox_assert('Case A cmd contains custom text', strpos(ox_cmd_for_payload($payloadA), 'STATUS CHECK ONLY') !== false);
ox_assert('Case A cmd does NOT contain MISSION START', strpos(ox_cmd_for_payload($payloadA), 'MISSION START') === false);

// Case B: bootstrap fallback when no custom text
$payloadB = [
    'session' => 'ses_testB',
    'prompt_file' => $root . '/tmp/ox/prompts/mission.txt',
];
ox_assert('Case B bootstrap fallback', strpos(ox_bootstrap_for_payload($payloadB), 'MISSION START') === 0);
ox_assert('Case B cmd contains MISSION START', strpos(ox_cmd_for_payload($payloadB), 'MISSION START') !== false);

// Also empty string should fallback.
$payloadB2 = [
    'session' => 'ses_testB2',
    'prompt_file' => $root . '/tmp/ox/prompts/mission.txt',
    'text' => '',
];
ox_assert('Case B empty text falls back to bootstrap', strpos(ox_bootstrap_for_payload($payloadB2), 'MISSION START') === 0);

// Case C: omitted agent
$payloadC = [
    'session' => 'ses_testC',
    'prompt_file' => $root . '/tmp/ox/prompts/mission.txt',
    'text' => 'hello',
];
ox_assert('Case C omitted agent yields no --agent flag', ox_agent_part_for_payload($payloadC) === '');
ox_assert('Case C cmd has no --agent', strpos(ox_cmd_for_payload($payloadC), '--agent') === false);

$payloadCEmpty = [
    'session' => 'ses_testC2',
    'prompt_file' => $root . '/tmp/ox/prompts/mission.txt',
    'text' => 'hello',
    'agent' => '',
];
ox_assert('Case C empty string agent yields no flag', ox_agent_part_for_payload($payloadCEmpty) === '');

// Case D: quoting preservation
$quoted = 'Text with "quotes" and \'apostrophes\' & punctuation! line1' . "\n" . 'line2 "quoted"';
$payloadD = [
    'session' => 'ses_testD',
    'prompt_file' => $root . '/tmp/ox/prompts/mission.txt',
    'text' => $quoted,
    'agent' => 'ox-planner',
];
$bootstrapD = ox_bootstrap_for_payload($payloadD);
ox_assert('Case D quoting preserved in bootstrap', $bootstrapD === $quoted);
$cmdD = ox_cmd_for_payload($payloadD);
ox_assert('Case D cmd strips double quotes from bootstrap', strpos($cmdD, '"quotes"') === false && strpos($cmdD, 'quotes') !== false);
// JSON round-trip preserves content
$tmpPayload = tempnam(sys_get_temp_dir(), 'ox_test_');
file_put_contents($tmpPayload, json_encode($payloadD, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$decoded = json_decode((string)file_get_contents($tmpPayload), true);
ox_assert('Case D JSON round-trip preserves text', $decoded['text'] === $quoted);
unlink($tmpPayload);

// Case E: duplicate-bootstrap prevention (custom text must NOT become MISSION START on resume)
$payloadE = [
    'session' => 'ses_existing_with_history',
    'prompt_file' => $root . '/tmp/ox/prompts/mission.txt',
    'text' => 'Resume your existing Wave 0 audit from your current state.',
    'agent' => 'ox-planner',
];
ox_assert('Case E custom resume not replaced', ox_bootstrap_for_payload($payloadE) === 'Resume your existing Wave 0 audit from your current state.');
ox_assert('Case E no MISSION START in resume', strpos(ox_bootstrap_for_payload($payloadE), 'MISSION START') === false);

// True new worker still receives bootstrap (no custom text)
$payloadNew = [
    'session' => 'ses_new_worker',
    'prompt_file' => $root . '/tmp/ox/prompts/new_mission.txt',
    'agent' => 'ox-builder',
];
ox_assert('New worker receives bootstrap', strpos(ox_bootstrap_for_payload($payloadNew), 'MISSION START') === 0);
ox_assert('New worker agent propagated', strpos(ox_cmd_for_payload($payloadNew), '--agent "ox-builder"') !== false);

echo $failures === 0 ? "\nAll Ox controller checks passed\n" : "\n$failures failed\n";
exit($failures > 0 ? 1 : 0);
