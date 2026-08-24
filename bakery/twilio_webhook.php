<?php
/**
 * Twilio webhook — inbound SMS + delivery status callbacks.
 *
 * Point the Twilio number's "A message comes in" webhook here:
 *   https://<host>/twilio_webhook.php
 * Delivery status callbacks land on the same endpoint (MessageStatus present).
 *
 * Signature validation follows Twilio's X-Twilio-Signature HMAC-SHA1 scheme
 * whenever TWILIO_VALIDATE_WEBHOOK is on (default once an auth token exists).
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/text_comms.php';

header('Content-Type: text/xml; charset=utf-8');

function bakery_twiml(string $message = ''): void
{
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>' . ($message !== '' ? '<Message>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</Message>' : '') . '</Response>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    bakery_twiml();
}

$params = $_POST;

// Signature validation against the public URL Twilio called.
if (TWILIO_VALIDATE_WEBHOOK) {
    $signature = (string)($_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $url = $host !== '' ? $scheme . '://' . $host . $uri : '';
    if ($signature === '' || $url === '' || !twilio_validate_signature($signature, $url, $params)) {
        error_log('twilio webhook: invalid signature');
        http_response_code(403);
        bakery_twiml();
    }
}

if (!bakery_text_messages_ready($db)) {
    error_log('twilio webhook: text_messages table missing');
    http_response_code(503);
    bakery_twiml();
}

$messageSid = trim((string)($params['MessageSid'] ?? $params['CallSid'] ?? ''));
$messageStatus = strtolower(trim((string)($params['MessageStatus'] ?? '')));
$from = trim((string)($params['From'] ?? ''));
$to = trim((string)($params['To'] ?? ''));
$body = trim((string)($params['Body'] ?? ''));

try {
    // Status callback for an outbound message we sent: Twilio includes
    // MessageStatus on callbacks; plain inbound webhooks do not carry it.
    if ($messageSid !== '' && isset($params['MessageStatus']) && $messageStatus !== '') {
        $errorMessage = trim((string)($params['ErrorMessage'] ?? ''));
        bakery_text_apply_status_callback($db, $messageSid, $messageStatus, $errorMessage);
        bakery_twiml();
    }

    // Inbound message from a counterpart. An MMS may carry only images
    // (empty body) — media presence alone is enough to keep it.
    $hasMedia = isset($params['MediaUrl0']) && trim((string)$params['MediaUrl0']) !== '';
    if ($from !== '' && ($body !== '' || $hasMedia)) {
        // Twilio retries webhook POSTs until they answer 200; a replay of an
        // already-recorded message must answer success, not violate the unique
        // sid key and spiral into a 500-retry loop.
        if ($messageSid !== '' && bakery_text_inbound_already_recorded($db, $messageSid)) {
            bakery_twiml();
        }
        try {
            $customerId = bakery_text_link_customer($db, $from);
            $rowId = bakery_text_record($db, [
                'direction' => 'inbound',
                'status' => 'received',
                'from_number' => $from,
                'to_number' => $to,
                'body' => $body,
                'twilio_sid' => $messageSid !== '' ? $messageSid : null,
                'customer_id' => $customerId,
                'context_type' => 'inbound',
                'operating_date' => date('Y-m-d'),
                // unread: read_at stays NULL until staff open the thread
            ]);
            // Download any inbound images to local storage and mark the row mms.
            if ($hasMedia) {
                require_once __DIR__ . '/includes/text_comms_media.php';
                bakery_text_media_capture_inbound($db, $rowId, $messageSid, $params);
            }
            // Tie the reply to any open text-reply survey from this sender.
            if ($body !== '' && function_exists('table_exists') && table_exists($db, 'surveys')) {
                require_once __DIR__ . '/includes/surveys.php';
                bakery_survey_record_inbound_reply($db, $from, (int)$rowId, $body);
            }
        } catch (Throwable $dup) {
            // Lost a race against a concurrent retry: the fact is already on
            // the ledger, which is success for the sender.
            if (strpos((string)$dup->getMessage(), '1062') !== false
                || stripos((string)$dup->getMessage(), 'duplicate entry') !== false) {
                bakery_twiml();
            }
            throw $dup;
        }
    }
} catch (Throwable $e) {
    error_log('twilio webhook: ' . $e->getMessage());
    http_response_code(500);
    bakery_twiml();
}

bakery_twiml();
