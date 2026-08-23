<?php
/**
 * Text comms media tests — MMS storage, inbound capture, history sync upserts,
 * path safety, and the viewer endpoint contract.
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
require_once __DIR__ . '/../includes/text_comms_media.php';

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

$GLOBALS['bakery_text_force_record_only'] = true;

$cleanupMessageIds = [];

try {
    // ---- Schema: media columns present ---------------------------------------
    $cols = [];
    foreach ($db->query('SHOW COLUMNS FROM text_messages', PDO::FETCH_ASSOC) as $c) {
        $cols[] = $c['Field'];
    }
    $assert(in_array('kind', $cols, true) && in_array('media_count', $cols, true) && in_array('media_json', $cols, true), '058 adds kind, media_count, and media_json columns');

    // ---- Content type mapping -------------------------------------------------
    $assert(bakery_text_media_extension('image/png') === 'png', 'png maps to png extension');
    $assert(bakery_text_media_extension('image/jpeg') === 'jpg', 'jpeg maps to jpg extension');
    $assert(bakery_text_media_extension('application/pdf') === 'pdf', 'pdf keeps pdf extension');
    $assert(bakery_text_media_extension('weird/type') === 'bin', 'unknown types fall back to bin');

    // ---- Path safety ------------------------------------------------------------
    $assert(bakery_text_media_path_safe('storage/text_media/2026-08-23/SM123-0.png'), 'normal media path accepted');
    $assert(!bakery_text_media_path_safe('storage/text_media/../../.env'), 'traversal path rejected');
    $assert(!bakery_text_media_path_safe('includes/config.php'), 'non-media path rejected');
    $assert(!bakery_text_media_path_safe("storage/text_media/\0evil"), 'null byte rejected');

    // ---- Inbound capture with mocked fetch --------------------------------------
    $GLOBALS['bakery_twilio_media_fetch'] = static function (string $url): ?array {
        return ["\x89PNG-fake-bytes-for-" . md5($url), 'image/png'];
    };

    $rowId = bakery_text_record($db, [
        'direction' => 'inbound',
        'status' => 'received',
        'from_number' => '+14155558801',
        'to_number' => '+14156907987',
        'body' => '',
        'context_type' => 'inbound',
        'operating_date' => date('Y-m-d'),
    ]);
    $cleanupMessageIds[] = $rowId;

    $captured = bakery_text_media_capture_inbound($db, $rowId, 'MMfake00000000000000000000000aa1', [
        'MediaUrl0' => 'https://media.twilio.com/fake/one',
        'MediaContentType0' => 'image/png',
        'MediaUrl1' => 'https://media.twilio.com/fake/two',
        'MediaContentType1' => 'image/jpeg',
    ]);
    $assert($captured === 2, 'inbound capture downloads and attaches both webhook images');

    $media = bakery_text_media_decode((string)$db->query('SELECT media_json FROM text_messages WHERE id = ' . $rowId)->fetchColumn());
    $assert(count($media) === 2, 'ledger row stores two media descriptors');
    $assert(($media[0]['content_type'] ?? '') === 'image/png', 'webhook content type wins over fetched header');
    $assert(strpos((string)($media[0]['path'] ?? ''), 'storage/text_media/') === 0, 'descriptor paths stay under storage/text_media/');
    $kindRow = $db->query('SELECT kind, media_count FROM text_messages WHERE id = ' . $rowId)->fetch(PDO::FETCH_ASSOC);
    $assert($kindRow && $kindRow['kind'] === 'mms' && (int)$kindRow['media_count'] === 2, 'row flips to kind=mms with media_count=2');

    // Stored file actually exists on disk
    $firstPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$media[0]['path']);
    $assert(is_file($firstPath), 'downloaded image exists on disk under storage/text_media');

    // Capture without MediaUrls is a no-op
    $plainId = bakery_text_record($db, [
        'direction' => 'inbound',
        'status' => 'received',
        'from_number' => '+14155558802',
        'to_number' => '+14156907987',
        'body' => 'no images here',
        'operating_date' => date('Y-m-d'),
    ]);
    $cleanupMessageIds[] = $plainId;
    $assert(bakery_text_media_capture_inbound($db, $plainId, 'MMfakeaa2', []) === 0, 'capture without media params changes nothing');

    // Failing download leaves the row untouched
    $GLOBALS['bakery_twilio_media_fetch'] = static function (string $url): ?array {
        return null;
    };
    $failId = bakery_text_record($db, [
        'direction' => 'inbound',
        'status' => 'received',
        'from_number' => '+14155558803',
        'to_number' => '+14156907987',
        'body' => '',
        'operating_date' => date('Y-m-d'),
    ]);
    $cleanupMessageIds[] = $failId;
    $assert(bakery_text_media_capture_inbound($db, $failId, 'MMfakeaa3', ['MediaUrl0' => 'https://x/y']) === 0, 'failed download attaches nothing');

    unset($GLOBALS['bakery_twilio_media_fetch']);

    // ---- History sync with mocked Messages API ----------------------------------
    $GLOBALS['bakery_text_force_record_only'] = null;
    $GLOBALS['bakery_text_force_live_ready'] = true;

    $messagesPage = [
        'messages' => [
            [
                'sid' => 'SMsync000000000000000000000001',
                'from' => '+14156907987',
                'to' => '+14155551234',
                'body' => 'outbound from history',
                'status' => 'delivered',
                'num_media' => '0',
                'date_sent' => '2026-08-20T18:30:00+00:00',
            ],
            [
                'sid' => 'SMsync000000000000000000000002',
                'from' => '+14155557777',
                'to' => '+14156907987',
                'body' => 'customer reply from history',
                'status' => 'received',
                'num_media' => '0',
                'date_sent' => '2026-08-21T15:00:00+00:00',
            ],
            ['sid' => 'CAvoicecallnotsms', 'from' => '+14150000000', 'to' => '+14156907987', 'body' => '', 'status' => 'completed'],
        ],
        'next_page_uri' => '',
    ];
    $GLOBALS['bakery_twilio_api_handler'] = static function (string $method, string $path) use ($messagesPage): array {
        return $messagesPage;
    };

    $insCustomer = $db->prepare("INSERT INTO customers (name, phone, is_active) VALUES ('Media Sync Cafe', '415-555-7777', 1)");
    $insCustomer->execute();
    $syncCustomerId = (int)$db->lastInsertId();

    $summaryBefore = bakery_text_sync_history($db, 30, 4);
    $assert($summaryBefore['found'] === 3 && $summaryBefore['inserted'] === 2 && $summaryBefore['skipped'] === 1, 'sync inserts both SMS resources and skips the voice-call sid');

    $outboundRow = $db->query("SELECT direction, status, operating_date, customer_id FROM text_messages WHERE twilio_sid = 'SMsync000000000000000000000001'")->fetch(PDO::FETCH_ASSOC);
    $assert($outboundRow && $outboundRow['direction'] === 'outbound' && $outboundRow['status'] === 'delivered', 'history outbound message lands as outbound/delivered');
    $assert($outboundRow && $outboundRow['operating_date'] === '2026-08-20', 'date_sent becomes the operating date');

    $inboundRow = $db->query("SELECT direction, customer_id FROM text_messages WHERE twilio_sid = 'SMsync000000000000000000000002'")->fetch(PDO::FETCH_ASSOC);
    $assert($inboundRow && (int)$inboundRow['customer_id'] === $syncCustomerId, 'history inbound links to the customer by phone tail');

    // Re-running sync updates instead of duplicating
    $secondRun = bakery_text_sync_history($db, 30, 4);
    $assert($secondRun['inserted'] === 0 && $secondRun['updated'] >= 2, 'second sync pass updates existing rows without duplicating');
    $dupCount = (int)$db->query("SELECT COUNT(*) FROM text_messages WHERE twilio_sid LIKE 'SMsync%'")->fetchColumn();
    $assert($dupCount === 2, 'sid uniqueness holds across sync runs');

    unset($GLOBALS['bakery_twilio_api_handler']);
    $GLOBALS['bakery_text_force_live_ready'] = null;
    $GLOBALS['bakery_text_force_record_only'] = true;

    // ---- Sync fails honestly when the API is unreachable -------------------------
    $GLOBALS['bakery_twilio_api_handler'] = static function (): array {
        throw new RuntimeException('Twilio API cURL error: simulated outage');
    };
    try {
        $unreachable = bakery_text_sync_history($db, 30);
        $assert($unreachable['found'] === 0 && $unreachable['ended_at'] !== '', 'sync survives an API outage with zero claims');
    } catch (Throwable $e) {
        $assert(false, 'sync survives an API outage with zero claims');
    }
    unset($GLOBALS['bakery_twilio_api_handler']);

    // ---- Viewer endpoint source contract ------------------------------------------
    $viewerSrc = file_get_contents(__DIR__ . '/../text_media.php');
    $assert(strpos($viewerSrc, "bakery_require_role(['administrator', 'manager'])") !== false, 'media viewer gates to administrator/manager');
    $assert(strpos($viewerSrc, 'bakery_text_media_path_safe') !== false && strpos($viewerSrc, 'realpath') !== false, 'viewer double-checks path containment');
    $assert(strpos($viewerSrc, 'X-Content-Type-Options: nosniff') !== false, 'viewer sends nosniff');
} finally {
    $GLOBALS['bakery_twilio_api_handler'] = null;
    $GLOBALS['bakery_twilio_media_fetch'] = null;
    $GLOBALS['bakery_text_force_record_only'] = null;
    $GLOBALS['bakery_text_force_live_ready'] = null;
    if ($cleanupMessageIds) {
        $ids = implode(',', array_map('intval', $cleanupMessageIds));
        $db->exec("DELETE FROM text_messages WHERE id IN ($ids)");
    }
    $db->exec("DELETE FROM text_messages WHERE twilio_sid LIKE 'SMsync%'");
    if (!empty($syncCustomerId)) {
        $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$syncCustomerId]);
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
