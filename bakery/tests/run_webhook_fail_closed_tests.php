<?php
/**
 * Mission 32 — webhooks fail closed.
 *
 * Boots php -S on loopback twice: once with no Square signature key / no Twilio
 * auth token (must answer 503 and write nothing), once with a test key (bad
 * signature 403, good signature processed). bakerysf_test only.
 *
 * Usage: php tests/run_webhook_fail_closed_tests.php
 */
require __DIR__ . '/isolate_test_db.php';
define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/square_invoices.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: run with APP_ENV=local\n");
    exit(1);
}
$db = check_mysql_connection();
bakery_assert_local_test_target($db);
bakery_square_ensure_schema($db);

$pass = 0;
$fail = 0;
$assert = function ($ok, $label) use (&$pass, &$fail) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};


function webhook_test_request(string $method, string $url, string $body = '', array $headers = []): array
{
    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", array_merge(['Content-Type: application/json'], $headers)),
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 5,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int)$m[1];
        }
    }
    return ['status' => $status, 'body' => (string)$resp];
}

/** @return resource|null */
function webhook_test_server(string $root, array $envOverrides, int $port)
{
    // Minimal explicit environment: the server reads bakery/.env itself, and
    // process env wins over .env for the keys we pin.
    $env = ['PATH' => (string)getenv('PATH'), 'HOME' => (string)getenv('HOME')];
    $env['DB_NAME'] = 'bakerysf_test';
    $env['USE_PROD_DB'] = 'false';
    $env['APP_ENV'] = 'local';
    foreach ($envOverrides as $k => $v) {
        $env[$k] = $v;
    }
    $proc = proc_open(
        '"' . PHP_BINARY . '" -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root),
        [
            0 => ['pipe', 'r'],
            1 => ['file', sys_get_temp_dir() . '/bakery-webhook-server-out.log', 'w'],
            2 => ['file', sys_get_temp_dir() . '/bakery-webhook-server-err.log', 'w'],
        ],
        $pipes,
        $root,
        $env
    );
    if (!is_resource($proc)) {
        return null;
    }
    for ($i = 0; $i < 30; $i++) {
        usleep(100000);
        $probe = @file_get_contents('http://127.0.0.1:' . $port . '/square_webhook.php', false, stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]));
        if ($probe !== false) {
            return $proc;
        }
    }
    proc_terminate($proc);
    return null;
}

function webhook_test_stop($proc): void
{
    if (is_resource($proc)) {
        proc_terminate($proc);
        usleep(200000);
        if (is_resource($proc)) {
            proc_close($proc);
        }
    }
}

// ---- source-level contract -------------------------------------------------
$squareSrc = (string)file_get_contents($root . '/square_webhook.php');
$assert(strpos($squareSrc, 'bakery_square_webhook_configured()') !== false, 'square_webhook.php checks the configured helper before any handler');
$assert(strpos($squareSrc, 'webhook_unconfigured') !== false, 'square_webhook.php names the unconfigured refusal');
$assert(strpos($squareSrc, 'bakery_square_webhook_configured()') < strpos($squareSrc, 'bakery_square_handle_webhook'), 'configured check runs before invoice handler');
$twilioSrc = (string)file_get_contents($root . '/twilio_webhook.php');
$assert(strpos($twilioSrc, "TWILIO_AUTH_TOKEN === ''") !== false && strpos($twilioSrc, '503') !== false, 'twilio_webhook.php refuses unsigned traffic when no auth token exists');

$db->exec("DELETE FROM square_webhook_events WHERE event_id LIKE 'wh-fail-closed-%'");
$eventsBefore = (int)$db->query('SELECT COUNT(*) FROM square_webhook_events')->fetchColumn();
$textReady = table_exists($db, 'text_messages');
$textBefore = $textReady ? (int)$db->query('SELECT COUNT(*) FROM text_messages')->fetchColumn() : 0;

// ---- unconfigured server: 503, nothing written -------------------------------
$base = 'http://127.0.0.1:8093';
$server = webhook_test_server($root, [
    'SQUARE_WEBHOOK_SIGNATURE_KEY' => '',
    'TWILIO_AUTH_TOKEN' => '',
    'TWILIO_VALIDATE_WEBHOOK' => '',
], 8093);
if ($server === null) {
    echo "FAIL  could not start php -S for the unconfigured pass\n";
    $fail++;
} else {
    $forged = json_encode(['type' => 'invoice.payment_made', 'event_id' => 'wh-fail-closed-1', 'data' => ['object' => ['invoice' => ['id' => 'SQI_FORGED', 'status' => 'PAID']]]]);
    $r = webhook_test_request('POST', $base . '/square_webhook.php', $forged);
    $assert($r['status'] === 503, 'unsigned Square POST with no key → 503 (got ' . $r['status'] . ')');
    $j = json_decode($r['body'], true);
    $assert(is_array($j) && ($j['error'] ?? '') === 'webhook_unconfigured', 'refusal body says webhook_unconfigured');
    $eventsAfter = (int)$db->query('SELECT COUNT(*) FROM square_webhook_events')->fetchColumn();
    $assert($eventsAfter === $eventsBefore, 'no square_webhook_events row written for the forged event');

    $get = webhook_test_request('GET', $base . '/square_webhook.php');
    $assert($get['status'] === 200, 'GET liveness still answers 200');

    webhook_test_stop($server);
}

// Twilio: no auth token and no explicit TWILIO_VALIDATE_WEBHOOK=0 → refuse.
$base = 'http://127.0.0.1:8094';
$server = webhook_test_server($root, [
    'SQUARE_WEBHOOK_SIGNATURE_KEY' => '',
    'TWILIO_AUTH_TOKEN' => '',
    'TWILIO_VALIDATE_WEBHOOK' => '',
], 8094);
if ($server === null) {
    echo "FAIL  could not start php -S for the Twilio pass\n";
    $fail++;
} else {
    $r = webhook_test_request('POST', $base . '/twilio_webhook.php', http_build_query([
        'MessageSid' => 'SMforged000', 'From' => '+15550001111', 'To' => '+15550002222', 'Body' => 'forged',
    ]), ['Content-Type: application/x-www-form-urlencoded']);
    // 503 when validation is unset and no token exists; 403 when .env keeps
    // TWILIO_VALIDATE_WEBHOOK=1 (signature required). Either way: refused.
    $assert(in_array($r['status'], [403, 503], true), 'unsigned Twilio POST with no auth token is refused (got ' . $r['status'] . ')');
    if ($textReady) {
        $textAfter = (int)$db->query('SELECT COUNT(*) FROM text_messages')->fetchColumn();
        $assert($textAfter === $textBefore, 'no text_messages row written for the forged inbound');
    }
    webhook_test_stop($server);
}

// ---- configured server: bad signature 403, good signature processed ----------
$key = 'wh-test-key-abcd1234';
$notificationUrl = 'http://127.0.0.1:8095/square_webhook.php';
$server = webhook_test_server($root, [
    'SQUARE_WEBHOOK_SIGNATURE_KEY' => $key,
    'SQUARE_WEBHOOK_NOTIFICATION_URL' => $notificationUrl,
], 8095);
if ($server === null) {
    echo "FAIL  could not start php -S for the configured pass\n";
    $fail++;
} else {
    $payload = json_encode(['type' => 'invoice.updated', 'event_id' => 'wh-fail-closed-2', 'data' => ['object' => ['invoice' => ['id' => 'SQI_WH_TEST', 'status' => 'UNPAID']]]]);
    $bad = webhook_test_request('POST', $notificationUrl, $payload, ['X-Square-HmacSha256-Signature: bm90LWEtc2lnbmF0dXJl']);
    $assert($bad['status'] === 403, 'bad signature → 403 (got ' . $bad['status'] . ')');

    $sig = base64_encode(hash_hmac('sha256', $notificationUrl . $payload, $key, true));
    $good = webhook_test_request('POST', $notificationUrl, $payload, ['X-Square-HmacSha256-Signature: ' . $sig]);
    $assert($good['status'] === 200, 'good signature → 200 (got ' . $good['status'] . ' ' . $good['body'] . ')');
    $recorded = (int)$db->query("SELECT COUNT(*) FROM square_webhook_events WHERE event_id = 'wh-fail-closed-2'")->fetchColumn();
    $assert($recorded === 1, 'good signature event is recorded exactly once');
    webhook_test_stop($server);
}

$db->exec("DELETE FROM square_webhook_events WHERE event_id LIKE 'wh-fail-closed-%'");

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
