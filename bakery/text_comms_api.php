<?php
/**
 * Text comms API — read-only views for the Texting Command Center.
 * Sending happens only through text_comms.php (one mutation path).
 *
 * GET text_comms_api.php?action=summary|conversations|thread&date=&phone=
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/text_comms.php';

bakery_require_role(['administrator', 'manager']);

header('Cache-Control: no-store, max-age=0');
header('Content-Type: application/json; charset=utf-8');

function bakery_text_api_fail(int $code, string $error): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    bakery_text_api_fail(405, 'Method not allowed');
}

$action = (string)($_GET['action'] ?? 'summary');
$date = trim((string)($_GET['date'] ?? ''));
if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = '';
}
$phone = trim((string)($_GET['phone'] ?? ''));

try {
    switch ($action) {
        case 'summary':
            echo json_encode([
                'success' => true,
                'available' => bakery_text_messages_ready($db),
                'live' => twilio_is_configured(),
                'credentials_sane' => twilio_credentials_look_sane(),
                'today' => $date ?: date('Y-m-d'),
                'generated_at' => date('c'),
                'counts' => bakery_text_summary($db, $date ?: null)['counts'],
                'labels' => [
                    'live_badge' => bakery_t('texts.badge_live'),
                    'log_badge' => bakery_t('texts.badge_log'),
                    'token_warning' => bakery_t('texts.token_warning'),
                ],
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'conversations':
            $data = bakery_text_conversations($db, 7);
            echo json_encode([
                'success' => true,
                'available' => $data['available'],
                'generated_at' => date('c'),
                'conversations' => $data['conversations'],
                'labels' => [
                    'no_conversations' => bakery_t('texts.no_conversations'),
                    'unavailable' => bakery_t('texts.unavailable_table'),
                ],
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'thread':
            if ($phone === '') {
                bakery_text_api_fail(400, 'Missing phone');
            }
            $data = bakery_text_thread($db, $phone, true);
            echo json_encode([
                'success' => true,
                'available' => $data['available'],
                'phone' => bakery_text_normalize_phone($phone),
                'messages' => $data['messages'],
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'feed':
            $data = bakery_text_feed($db, 14, 200);
            echo json_encode([
                'success' => true,
                'available' => $data['available'],
                'generated_at' => date('c'),
                'messages' => $data['messages'],
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'delivery':
            $data = bakery_text_delivery($db, 14);
            echo json_encode([
                'success' => true,
                'available' => $data['available'],
                'generated_at' => date('c'),
                'failed' => $data['failed'],
                'in_flight' => $data['in_flight'],
                'logged' => $data['logged'],
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'ops':
            $ops = bakery_text_ops_snapshot($db, $date !== '' ? $date : null);
            echo json_encode([
                'success' => true,
                'generated_at' => date('c'),
                'ops' => $ops,
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            bakery_text_api_fail(400, 'Unknown action');
    }
} catch (Throwable $e) {
    error_log('text comms api: ' . $e->getMessage());
    bakery_text_api_fail(500, (string)bakery_t('texts.load_error'));
}
