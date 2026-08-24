<?php
/**
 * Detached prompt sender used by ox.php spawn/nudge.
 * Usage: php scripts/ox/ox_send.php tmp/ox/prompts/send-xxx.json
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
if (!is_array($j) || empty($j['session'])) {
    fwrite(STDERR, "bad send payload\n");
    exit(1);
}
require __DIR__ . '/ox_lib.php';

try {
    ox_http('POST', "/session/{$j['session']}/message", [
        'parts' => [['type' => 'text', 'text' => (string)$j['text']]],
        'agent' => (string)($j['agent'] ?? 'build'),
    ], 3600);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ox_send error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
