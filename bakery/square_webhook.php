<?php
/**
 * Square invoice webhooks (payment / status). Signature-checked; no staff session.
 */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/square_invoices.php';

http_response_code(200);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok' => true, 'service' => 'square_webhook']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$raw = (string)file_get_contents('php://input');
$signature = (string)($_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE'] ?? $_SERVER['HTTP_X_SQUARE_SIGNATURE'] ?? '');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string)($_SERVER['HTTP_HOST'] ?? '');
$notificationUrl = rtrim($scheme . '://' . $host . (defined('BASE_URL') ? BASE_URL : '/'), '/') . '/square_webhook.php';
if (defined('SQUARE_WEBHOOK_NOTIFICATION_URL') && SQUARE_WEBHOOK_NOTIFICATION_URL !== '') {
    $notificationUrl = (string)SQUARE_WEBHOOK_NOTIFICATION_URL;
}

// Fail closed: no signature key means no payment truth. Refuse and log once so
// a forged invoice.payment_made cannot mark anything paid or unlock a purchase.
if (!bakery_square_webhook_configured()) {
    error_log('square_webhook: refused — SQUARE_WEBHOOK_SIGNATURE_KEY is not configured');
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'webhook_unconfigured']);
    exit;
}
if (!bakery_square_webhook_valid($raw, $signature, $notificationUrl)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'signature']);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    echo json_encode(['ok' => false, 'error' => 'json']);
    exit;
}

try {
    // Type-based routing: invoice events keep their wholesale handler and
    // semantics; payment/refund/checkout events belong to education purchases.
    $eventType = (string)($payload['type'] ?? '');
    if (strpos($eventType, 'invoice.') === 0) {
        $result = bakery_square_handle_webhook($db, $payload);
    } else {
        require_once __DIR__ . '/includes/sf_baker.php';
        $result = bakery_sfb_handle_education_webhook($db, $payload);
    }
    echo json_encode($result);
} catch (Throwable $e) {
    error_log('square_webhook: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'handler']);
}
