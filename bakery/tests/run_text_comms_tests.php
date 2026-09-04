<?php
/**
 * Text comms tests — ledger honesty, send paths, webhook status, conversations.
 *
 * Runs against bakerysf_test. Never touches local/staging/live data.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);

require __DIR__ . '/isolate_test_db.php';
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/test_target_guard.php';

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

require_once __DIR__ . '/../includes/twilio_config.php';
require_once __DIR__ . '/../includes/text_comms.php';

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $msg) use (&$pass, &$fail): void {
    if ($ok) {
        echo "PASS  {$msg}\n";
        $pass++;
        return;
    }
    echo "FAIL  {$msg}\n";
    $fail++;
};

// Deterministic record-only mode unless a test opts into the API seam.
$GLOBALS['bakery_text_force_record_only'] = true;

$cleanupMessageIds = [];
$cleanupCustomerIds = [];

try {
    // ---- Schema present -----------------------------------------------------
    $assert(bakery_text_messages_ready($db), 'text_messages table exists (migration 057 applied to the snapshot source)');

    if (!bakery_text_messages_ready($db)) {
        echo "SKIP  remaining suites (ledger missing)\n";
        exit($fail > 0 ? 1 : 0);
    }

    // ---- Phone normalization ------------------------------------------------
    $assert(bakery_text_normalize_phone('(415) 555-1234') === '+14155551234', 'US 10-digit normalizes to +1 E.164');
    $assert(bakery_text_normalize_phone('1-415-555-1234') === '+14155551234', 'leading 1 collapses into +1');
    $assert(bakery_text_normalize_phone('+442071234567') === '+442071234567', 'international keeps digits with +');
    $assert(bakery_text_phone_tail('+14155551234') === '4155551234', 'tail strips country code to last 10');

    // ---- Validation without touching the API --------------------------------
    $missingTo = bakery_text_send($db, '', 'hello');
    $assert(!$missingTo['ok'] && $missingTo['error'] === 'missing_to' && $missingTo['id'] === 0, 'send without destination fails fast and writes nothing');
    $missingBody = bakery_text_send($db, '+14155551234', '   ');
    $assert(!$missingBody['ok'] && $missingBody['error'] === 'missing_body' && $missingBody['id'] === 0, 'send without body fails fast and writes nothing');

    // ---- Record-only path ----------------------------------------------------
    $recorded = bakery_text_send($db, '(415) 555-0001', 'Your bread is ready', [
        'context_type' => 'order',
        'operating_date' => '2026-08-23',
    ]);
    $cleanupMessageIds[] = $recorded['id'];
    $assert($recorded['recorded_only'] && $recorded['status'] === 'logged' && !$recorded['ok'], 'unconfigured send records as logged, not sent');
    $row = $db->query('SELECT direction, status, to_number, operating_date FROM text_messages WHERE id = ' . (int)$recorded['id'])->fetch(PDO::FETCH_ASSOC);
    $assert($row && $row['direction'] === 'outbound' && $row['status'] === 'logged', 'logged row lands in ledger as outbound/logged');
    $assert($row && $row['to_number'] === '+14155550001', 'ledger stores normalized E.164 number');
    $assert($row && $row['operating_date'] === '2026-08-23', 'ledger keys on the chosen operating date');

    // ---- Mocked live success -------------------------------------------------
    $GLOBALS['bakery_text_force_record_only'] = null;
    $GLOBALS['bakery_text_force_live_ready'] = true;
    $GLOBALS['bakery_twilio_api_handler'] = static function (string $method, string $path, array $form): array {
        return ['sid' => 'SMfake00000000000000000000000001', 'status' => 'queued', 'from' => '+14155550999'];
    };
    $sent = bakery_text_send($db, '+14155550002', 'Route delayed 20 min', ['operating_date' => '2026-08-23']);
    $cleanupMessageIds[] = $sent['id'];
    $assert($sent['ok'] && $sent['sid'] === 'SMfake00000000000000000000000001' && $sent['status'] === 'queued', 'live path returns ok with sid');

    $capturedForm = [];
    $GLOBALS['bakery_twilio_api_handler'] = static function (string $method, string $path, array $form) use (&$capturedForm): array {
        $capturedForm = $form;
        return ['sid' => 'SMfake00000000000000000000000002', 'status' => 'queued', 'from' => '+14155550999'];
    };
    $mms = bakery_text_send($db, '+14155550002', 'Route card', [
        'operating_date' => '2026-08-23',
        'media_url' => 'https://staging.sourflour.org/ops-monday-routes.png',
    ]);
    $cleanupMessageIds[] = $mms['id'];
    $assert($mms['ok'] && ($capturedForm['MediaUrl'] ?? '') === 'https://staging.sourflour.org/ops-monday-routes.png', 'https media_url is posted as MediaUrl');
    $badMedia = bakery_text_send($db, '+14155550002', 'nope', ['media_url' => 'http://example.com/x.png']);
    $assert(!$badMedia['ok'] && $badMedia['error'] === 'media_url must be https' && $badMedia['id'] === 0, 'non-https media_url is refused without a ledger row');
    $sentRow = $db->query("SELECT status, twilio_sid, from_number FROM text_messages WHERE id = " . (int)$sent['id'])->fetch(PDO::FETCH_ASSOC);
    $assert($sentRow && $sentRow['status'] === 'queued' && $sentRow['twilio_sid'] === 'SMfake00000000000000000000000001', 'sent row stores twilio sid');

    // ---- Status callback updates by sid --------------------------------------
    $applied = bakery_text_apply_status_callback($db, 'SMfake00000000000000000000000001', 'delivered');
    $assert($applied, 'status callback flips row to delivered');
    $deliveredStatus = $db->query("SELECT status FROM text_messages WHERE id = " . (int)$sent['id'])->fetchColumn();
    $assert($deliveredStatus === 'delivered', 'ledger reflects delivered state');
    $assert(!bakery_text_apply_status_callback($db, 'SMunknown', 'delivered'), 'callback for unknown sid changes nothing');

    // ---- Ledger honesty: callbacks cannot regress or poison the ledger -------
    // Twilio retries and reorders webhooks; the ledger must only ever learn more.
    $assert(!bakery_text_apply_status_callback($db, 'SMfake00000000000000000000000001', 'queued'), 'out-of-order queued callback is refused for a delivered row');
    $assert(!bakery_text_apply_status_callback($db, 'SMfake00000000000000000000000001', 'sent'), 'stale sent callback cannot move a delivered row backwards');
    $stillDelivered = $db->query("SELECT status FROM text_messages WHERE id = " . (int)$sent['id'])->fetchColumn();
    $assert($stillDelivered === 'delivered', 'delivered state survives replayed webhooks');
    $assert(!bakery_text_apply_status_callback($db, 'SMfake00000000000000000000000001', 'carrier-magic'), 'unknown callback vocabulary is refused outright');

    $flippedToUndelivered = bakery_text_apply_status_callback($db, 'SMfake00000000000000000000000001', 'undelivered');
    $assert($flippedToUndelivered, 'equal-rank carrier flip away from delivered is accepted');
    $restoredToDelivered = bakery_text_apply_status_callback($db, 'SMfake00000000000000000000000001', 'delivered');
    $finalStatus = $db->query("SELECT status FROM text_messages WHERE id = " . (int)$sent['id'])->fetchColumn();
    $assert($restoredToDelivered && $finalStatus === 'delivered', 'equal-rank flip can be corrected in place');

    $failedWithSid = bakery_text_record($db, [
        'direction' => 'outbound',
        'status' => 'failed',
        'to_number' => '+14155550003',
        'body' => 'evidence preservation test',
        'twilio_sid' => 'SMfake00000000000000000000000003',
        'error_message' => 'Twilio API HTTP 400: invalid phone number',
        'context_type' => 'test',
        'operating_date' => '2026-08-23',
    ]);
    $cleanupMessageIds[] = $failedWithSid;
    bakery_text_apply_status_callback($db, 'SMfake00000000000000000000000003', 'failed');
    $keptEvidence = (string)$db->query(
        "SELECT error_message FROM text_messages WHERE twilio_sid = 'SMfake00000000000000000000000003'"
    )->fetchColumn();
    $assert(strpos($keptEvidence, 'invalid phone') !== false, 'repeat failure callback never erases stored error evidence');
    $assert(!bakery_text_apply_status_callback($db, 'SMfake00000000000000000000000003', 'sent'), 'sent callback cannot resurrect a failed row');

    // ---- Inbound webhook retry dedup ------------------------------------------
    // Twilio retries webhook POSTs until they answer 200; one inbound fact
    // must stay exactly one ledger row.
    $inboundSid = 'SMfakeINBOUND00000000000000000001';
    $assert(!bakery_text_inbound_already_recorded($db, $inboundSid), 'fresh inbound sid is not flagged as recorded');
    $dedupProbe = bakery_text_record($db, [
        'direction' => 'inbound',
        'status' => 'received',
        'from_number' => '+14155559999',
        'to_number' => '+14155550999',
        'body' => 'webhook retry dedup probe',
        'twilio_sid' => $inboundSid,
        'context_type' => 'inbound',
        'operating_date' => '2026-08-23',
    ]);
    $cleanupMessageIds[] = $dedupProbe;
    $assert(bakery_text_inbound_already_recorded($db, $inboundSid), 'recorded inbound sid is detected so webhook retries do not duplicate');
    $assert(!bakery_text_inbound_already_recorded($db, ''), 'empty sid never counts as recorded');
    $sidRowCount = (int)$db->query(
        'SELECT COUNT(*) FROM text_messages WHERE twilio_sid = ' . $db->quote($inboundSid)
    )->fetchColumn();
    $assert($sidRowCount === 1, 'unique sid key keeps one inbound fact as exactly one row');

    // ---- Mocked live failure --------------------------------------------------
    $GLOBALS['bakery_twilio_api_handler'] = static function (): array {
        throw new RuntimeException('Twilio API HTTP 400: invalid phone number');
    };
    $failed = bakery_text_send($db, '+10000000000', 'should fail', ['operating_date' => '2026-08-23']);
    $cleanupMessageIds[] = $failed['id'];
    $assert(!$failed['ok'] && $failed['status'] === 'failed' && strpos((string)$failed['error'], 'HTTP 400') !== false, 'API error becomes failed ledger row, not an exception');
    $failedRow = $db->query("SELECT error_message FROM text_messages WHERE id = " . (int)$failed['id'])->fetch(PDO::FETCH_ASSOC);
    $assert($failedRow && strpos((string)$failedRow['error_message'], 'invalid phone') !== false, 'failure reason stored beside the attempt');

    unset($GLOBALS['bakery_twilio_api_handler']);
    $GLOBALS['bakery_text_force_live_ready'] = null;
    $GLOBALS['bakery_text_force_record_only'] = true;

    // ---- Customer linking + inbound thread -----------------------------------
    $ins = $db->prepare("INSERT INTO customers (name, phone, is_active) VALUES ('Text Test Cafe', '415-555-7777', 1)");
    $ins->execute();
    $customerId = (int)$db->lastInsertId();
    $cleanupCustomerIds[] = $customerId;

    $linkedId = bakery_text_record($db, [
        'direction' => 'inbound',
        'status' => 'received',
        'from_number' => '+14155557777',
        'to_number' => '+14155550999',
        'body' => 'Can we get 3 extra conchas?',
        'customer_id' => $customerId,
        'context_type' => 'inbound',
        'operating_date' => '2026-08-23',
    ]);
    $cleanupMessageIds[] = $linkedId;
    $assert(bakery_text_link_customer($db, '(415) 555-7777') === $customerId, 'inbound links to customer by phone tail');

    $reply = bakery_text_send($db, '+14155557777', 'Yes - added to tomorrow', ['customer_id' => $customerId]);
    $cleanupMessageIds[] = $reply['id'];

    $threadData = bakery_text_thread($db, '415-555-7777', true);
    $bodies = array_column($threadData['messages'], 'body');
    $assert(count($threadData['messages']) >= 2, 'thread collects both directions for one counterpart');
    $assert(strpos((string)$bodies[0], 'conchas') !== false || strpos(json_encode($bodies), 'conchas') !== false, 'thread includes the inbound question');
    $readAts = array_column($threadData['messages'], 'read_at');
    $unreadLeft = 0;
    foreach ($threadData['messages'] as $m) {
        if ($m['direction'] === 'inbound' && empty($m['read_at'])) {
            $unreadLeft++;
        }
    }
    $assert($unreadLeft === 0, 'opening the thread marks inbound messages read');

    // ---- Inbound retry safety: one fact, one row ------------------------------
    $dupSid = 'SMfakeinbound000000000000000001';
    $dupRowId = bakery_text_record($db, [
        'direction' => 'inbound',
        'status' => 'received',
        'from_number' => '+14155557777',
        'to_number' => '+14155550999',
        'body' => 'retry-safety fixture',
        'twilio_sid' => $dupSid,
        'customer_id' => $customerId,
        'context_type' => 'inbound',
        'operating_date' => '2026-08-23',
    ]);
    $cleanupMessageIds[] = $dupRowId;
    $assert(bakery_text_inbound_already_recorded($db, $dupSid), 'recorded inbound sid is detected for webhook retries');
    $assert(!bakery_text_inbound_already_recorded($db, 'SMfakeinbound-unknown'), 'unseen sid reports as fresh');
    $dupCountStmt = $db->prepare("SELECT COUNT(*) FROM text_messages WHERE twilio_sid = ? AND direction = 'inbound'");
    $dupCountStmt->execute([$dupSid]);
    $assert((int)$dupCountStmt->fetchColumn() === 1, 'one inbound fact sits in exactly one row');
    $duplicateCaught = false;
    try {
        bakery_text_record($db, [
            'direction' => 'inbound',
            'status' => 'received',
            'from_number' => '+14155557777',
            'to_number' => '+14155550999',
            'body' => 'retry of the same message',
            'twilio_sid' => $dupSid,
            'context_type' => 'inbound',
            'operating_date' => '2026-08-23',
        ]);
    } catch (Throwable $e) {
        $duplicateCaught = strpos((string)$e->getMessage(), '1062') !== false
            || stripos((string)$e->getMessage(), 'duplicate entry') !== false;
    }
    $assert($duplicateCaught, 'unique sid key backs the webhook retry guard');

    // ---- Conversations grouping ----------------------------------------------
    $convos = bakery_text_conversations($db, 30)['conversations'];
    $mine = null;
    foreach ($convos as $c) {
        if (bakery_text_phone_tail((string)$c['phone']) === '4155557777') {
            $mine = $c;
        }
    }
    $assert(is_array($mine), 'conversation appears in lookback window list');
    $assert($mine !== null && (string)$mine['label'] === 'Text Test Cafe', 'conversation labeled with linked customer name');
    $assert($mine !== null && (int)$mine['outbound'] >= 1 && (int)$mine['inbound'] >= 1, 'conversation counts both directions');
    $assert($mine !== null && (string)$mine['lane'] === 'customer', 'linked customer conversation sits in the customer lane');

    // ---- Lanes: test + general, grouped by phone tail -------------------------
    $testId = bakery_text_record($db, [
        'direction' => 'outbound',
        'status' => 'logged',
        'to_number' => '415-509-1210',
        'body' => 'Command center test ping',
        'context_type' => 'test',
        'operating_date' => '2026-08-23',
    ]);
    $cleanupMessageIds[] = $testId;
    $generalId = bakery_text_record($db, [
        'direction' => 'outbound',
        'status' => 'logged',
        'to_number' => '4155091210',
        'body' => 'Route list for Monday',
        'context_type' => 'general',
        'operating_date' => '2026-08-23',
    ]);
    $cleanupMessageIds[] = $generalId;
    $assert(bakery_text_lane(['context_type' => 'test', 'customer_id' => 9]) === 'test', 'test context wins over customer id');
    $assert(bakery_text_lane(['context_type' => 'manual', 'customer_id' => null]) === 'general', 'unlinked manual texts are general');
    $assert(bakery_text_normalize_context_type('recovery') === 'recovery', 'recovery context_type is allowed for failed-stop writeback');
    $assert(bakery_text_normalize_context_type('nope') === 'manual', 'unknown context types fall back to manual');

    $convos2 = bakery_text_conversations($db, 30)['conversations'];
    $testConvo = null;
    foreach ($convos2 as $c) {
        if (bakery_text_phone_tail((string)$c['phone']) === '4155091210') {
            $testConvo = $c;
        }
    }
    $assert(is_array($testConvo), 'test/general number appears even without a customer');
    $assert($testConvo !== null && (int)$testConvo['outbound'] >= 2, 'same 10-digit tail groups formatted and unformatted numbers');
    $assert($testConvo !== null && (string)$testConvo['lane'] === 'test', 'test context marks the conversation as test');

    $feed = bakery_text_feed($db, 30, 200)['messages'];
    $feedLanes = array_unique(array_column($feed, 'lane'));
    $assert(in_array('test', $feedLanes, true) && in_array('customer', $feedLanes, true), 'activity feed includes customer and test lanes');
    $delivery = bakery_text_delivery($db, 30);
    $assert($delivery['available'] && count($delivery['logged']) >= 1, 'delivery view lists recorded-only outbound');
    $ops = bakery_text_ops_snapshot($db, '2026-08-23');
    $summary = bakery_text_summary($db, '2026-08-23');
    $assert((int)$ops['lanes']['test'] >= 1 && (int)$ops['lanes']['general'] >= 1, 'ops snapshot counts test and general on the day');
    $assert((int)$summary['lanes']['customer'] >= 1 || (int)$ops['lanes']['customer'] >= 1, 'ops/day counts include the customer lane');

    $pageSrcEarly = file_get_contents(__DIR__ . '/../text_comms.php');
    $assert(strpos($pageSrcEarly, "'feed'") !== false && strpos($pageSrcEarly, "'delivery'") !== false && strpos($pageSrcEarly, "'ops'") !== false, 'command center exposes inbox, activity, delivery, and ops views');

    // ---- Summary counts by operating date -------------------------------------
    $summary = bakery_text_summary($db, '2026-08-23');
    $assert($summary['available'] && $summary['counts']['received'] >= 1, 'summary counts inbound on the operating date');
    $assert($summary['counts']['outbound'] >= 3, 'summary counts all outbound attempts including logged and failed');
    $assert($summary['counts']['delivered'] >= 1 && $summary['counts']['failed'] >= 1, 'summary separates delivered and failed states');
    $otherDay = bakery_text_summary($db, '2000-01-01');
    $assert($otherDay['counts']['outbound'] === 0, 'another day reads zero, not unavailable');

    // ---- Source contracts -------------------------------------------------------
    $apiSrc = file_get_contents(__DIR__ . '/../text_comms_api.php');
    $assert(strpos($apiSrc, "'POST'") === false || strpos($apiSrc, 'REQUEST_METHOD') !== false, 'api source readable');
    $assert(strpos($apiSrc, 'action=send') === false && strpos($apiSrc, "\$_POST") === false, 'api stays read-only (single send mutation path lives on the page)');
    $assert(strpos($apiSrc, "case 'feed'") !== false && strpos($apiSrc, "case 'ops'") !== false, 'api exposes feed and ops read views');
    $pageSrc = file_get_contents(__DIR__ . '/../text_comms.php');
    $assert(strpos($pageSrc, 'bakery_csrf_field()') !== false && strpos($pageSrc, 'bakery_require_csrf()') !== false, 'page send path requires CSRF');
    $webhookSrc = file_get_contents(__DIR__ . '/../twilio_webhook.php');
    $assert(strpos($webhookSrc, 'twilio_validate_signature') !== false && strpos($webhookSrc, 'hash_hmac') === false, 'webhook delegates signature math to config helper');
    $assert(strpos($webhookSrc, 'bakery_text_inbound_already_recorded') !== false, 'webhook answers retries of recorded inbound messages with success, not a 500 loop');
    $assert(twilio_validate_signature('', 'https://example.com', []) === false, 'empty signature rejected when no token configured');

    // ---- Delivery-view honesty hint + i18n parity ------------------------------
    $pageSrcHint = (string)file_get_contents(__DIR__ . '/../text_comms.php');
    $assert(strpos($pageSrcHint, "'texts.delivery_no_callback_hint'") !== false || strpos($pageSrcHint, 'texts.delivery_no_callback_hint') !== false, 'delivery view says when in-flight states have no callback to advance them');
    $langEnTexts = require __DIR__ . '/../lang/en.php';
    $langEsTexts = require __DIR__ . '/../lang/es.php';
    $assert(isset($langEnTexts['texts.delivery_no_callback_hint'], $langEsTexts['texts.delivery_no_callback_hint']), 'no-callback hint exists in both languages');
    $includesSrc = (string)file_get_contents(__DIR__ . '/../includes/text_comms.php');
    $assert(strpos($includesSrc, 'function bakery_text_status_rank') !== false, 'status vocabulary is ranked in one helper the callback trusts');
    $assert(substr($includesSrc, 0, 3) !== "\xEF\xBB\xBF", 'text comms include carries no UTF-8 BOM that would leak into JSON responses');

    // ---- Canonical CLI sender (bug 4689: no more temp-script bypass) ----------
    $cliPath = __DIR__ . '/../scripts/text_send.php';
    $cliSrc = (string)@file_get_contents($cliPath);
    $assert($cliSrc !== '', 'canonical CLI sender script exists');
    $assert(strpos($cliSrc, "PHP_SAPI !== 'cli'") !== false, 'CLI sender is command-line only');
    $assert(strpos($cliSrc, 'bakery_text_send(') !== false, 'CLI sender writes through the one canonical send path');
    $assert(strpos($cliSrc, "'--send'") !== false, 'CLI sender requires explicit --send before any real attempt');

    $runCli = static function (array $args): array {
        $appRoot = dirname(__DIR__);
        $cmd = [PHP_BINARY, $appRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'text_send.php'];
        foreach ($args as $arg) {
            $cmd[] = $arg;
        }
        $env = array_merge($_ENV, getenv() ?: [], [
            'DB_NAME' => 'bakerysf_test',
            'USE_PROD_DB' => 'false',
            'BAKERY_TEXT_FORCE_RECORD_ONLY' => '1',
        ]);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $appRoot, $env);
        if (!is_resource($proc)) {
            return [-1, '', ''];
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return [$code, (string)$stdout, (string)$stderr];
    };
    $countRows = static function () use ($db): int {
        $stmt = $db->query("SELECT COUNT(*) FROM text_messages WHERE body LIKE 'CLI smoke%'");
        return (int)$stmt->fetchColumn();
    };

    $missingArgs = $runCli(['--body=CLI smoke missing to']);
    $assert((int)$missingArgs[0] === 1 && strpos($missingArgs[2], '--to=') !== false, 'CLI sender refuses missing --to with usage help');

    $beforeRows = $countRows();
    $preview = $runCli(['--to=+14155550999', '--body=CLI smoke preview run']);
    $assert((int)$preview[0] === 0 && strpos($preview[1], 'PREVIEW') !== false, 'preview exits clean and says nothing was sent');
    $assert(strpos($preview[1], 'ledger row') === false || strpos($preview[1], 'no ledger row written') !== false, 'preview states the ledger stays untouched');
    $assert($countRows() === $beforeRows, 'preview writes zero rows - a look is not an attempt');

    $sentRun = $runCli(['--to=+14155550999', '--body=CLI smoke send one', '--send', '--json']);
    $payload = json_decode(trim(preg_replace('/^[\x{FEFF}]+/u', '', $sentRun[1]) ?? ''), true);
    $afterOne = $countRows();
    $assert((int)$sentRun[0] === 0 && is_array($payload) && $payload['recorded_only'] === true && $payload['status'] === 'logged', 'forced record-only send answers honestly');
    $assert($afterOne === $beforeRows + 1, 'one attempt leaves exactly one ledger row');
    $lastRowStmt = $db->query("SELECT id FROM text_messages WHERE body LIKE 'CLI smoke%' ORDER BY id DESC LIMIT 1");
    $cleanupMessageIds[] = (int)$lastRowStmt->fetchColumn();
} finally {
    $GLOBALS['bakery_twilio_api_handler'] = null;
    $GLOBALS['bakery_text_force_record_only'] = null;
    $GLOBALS['bakery_text_force_live_ready'] = null;
    if ($cleanupMessageIds) {
        $ids = implode(',', array_map('intval', $cleanupMessageIds));
        $db->exec("DELETE FROM text_messages WHERE id IN ($ids)");
    }
    foreach ($cleanupCustomerIds as $cid) {
        $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$cid]);
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
