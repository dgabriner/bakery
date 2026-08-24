<?php
/**
 * Canonical command-line SMS sender - closes bug 4689.
 *
 * Ops and agents sometimes need one-off texts (driver route notes, owner
 * pings). Temp scripts that call Twilio directly bypass the text_messages
 * ledger, so the Command Center stops telling the truth about what left.
 * This wrapper IS the sanctioned shortcut: same validation, same customer
 * linking, same one-row-per-attempt contract as the Texting Command Center,
 * because it goes through bakery_text_send().
 *
 * Usage:
 *   php scripts/text_send.php --to=+14155551234 --body="Route updated"
 *       [--context=test|general|manual|driver] [--date=Y-m-d]
 *       [--media-url=https://...] [--send] [--json]
 *
 * Honesty rules:
 *   - Without --send: prints what WOULD happen and writes nothing (a preview
 *     is not an attempt).
 *   - With --send but no Twilio credentials: records one 'logged' row
 *     ("recorded, not sent") - never a silent pretend-send.
 *   - With --send and credentials: one real send, one row, sid echoed.
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
chdir($root);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/text_comms.php';

$options = ['to' => '', 'body' => '', 'context' => '', 'date' => '', 'media-url' => '',];
$send = false;
$json = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--send') {
        $send = true;
        continue;
    }
    if ($arg === '--json') {
        $json = true;
        continue;
    }
    if (strpos($arg, '--') === 0 && strpos($arg, '=') !== false) {
        [$key, $value] = explode('=', substr($arg, 2), 2);
        if (array_key_exists($key, $options)) {
            $options[$key] = trim((string)$value);
        }
    }
}

if ($options['to'] === '' || $options['body'] === '') {
    fwrite(STDERR, "--to= and --body= are required\n");
    fwrite(STDERR, "Usage: php scripts/text_send.php --to=+1415... --body=\"...\" [--context=test] [--send]\n");
    exit(1);
}

try {
    $db = check_mysql_connection();
} catch (Throwable $e) {
    fwrite(STDERR, 'Database unavailable: ' . $e->getMessage() . "\n");
    exit(1);
}

$liveReady = bakery_text_live_ready();

if (!$send) {
    // Preview: not an attempt, so the ledger stays untouched.
    $mode = $liveReady ? 'LIVE SEND (Twilio configured)' : 'RECORD-ONLY (no credentials; a logged row would note it)';
    $line = [
        'mode' => $liveReady ? 'live' : 'record_only',
        'would_send_to' => bakery_text_normalize_phone($options['to']),
        'body' => $options['body'],
        'context' => $options['context'] !== '' ? $options['context'] : 'manual',
        'ledger_row_written' => false,
        'hint' => 'Add --send to actually attempt this message.',
    ];
    if ($json) {
        echo json_encode($line, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo "PREVIEW - nothing sent, no ledger row written.\n";
        echo "Mode: {$mode}\n";
        echo "To: " . $line['would_send_to'] . "\n";
        echo "Body: {$options['body']}\n";
        echo "Add --send to attempt this message.\n";
    }
    exit(0);
}

$result = bakery_text_send(
    $db,
    $options['to'],
    $options['body'],
    [
        'context_type' => $options['context'] !== '' ? $options['context'] : 'manual',
        'operating_date' => $options['date'] !== '' ? $options['date'] : null,
        'media_url' => $options['media-url'] !== '' ? $options['media-url'] : null,
    ]
);

if ($json) {
    echo json_encode([
        'ok' => $result['ok'],
        'recorded_only' => $result['recorded_only'],
        'status' => $result['status'],
        'sid' => $result['sid'],
        'error' => $result['error'],
        'ledger_id' => $result['id'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    if ($result['recorded_only']) {
        echo "RECORDED, NOT SENT (no credentials): ledger row {$result['id']} status={$result['status']}\n";
    } elseif ($result['ok']) {
        echo "SENT: ledger row {$result['id']} status={$result['status']} sid={$result['sid']}\n";
    } else {
        echo "ATTEMPT FAILED (row kept for honesty): ledger row {$result['id']} error={$result['error']}\n";
    }
}

exit($result['ok'] || $result['recorded_only'] ? 0 : 1);
