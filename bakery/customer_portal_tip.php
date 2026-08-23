<?php
/**
 * Customer tip — creates a Square payment link for $1 and redirects there.
 * POST only (with CSRF token). Returns to customer_portal.php?tip=thanks on success.
 */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/customer_portal.php';
require_once __DIR__ . '/includes/square_config.php';

$customer = bakery_portal_require_customer($db);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'customer_portal.php');
    exit;
}

if (!square_is_configured()) {
    error_log('Square tip: not configured');
    header('Location: ' . BASE_URL . 'customer_portal.php?tip=error');
    exit;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = rtrim($scheme . '://' . $host . BASE_URL, '/') . '/';
$returnUrl = $base . 'customer_portal.php?tip=thanks';

try {
    $result = square_api_request('POST', '/v2/online-checkout/payment-links', [
        'idempotency_key' => 'tip-' . (int)$customer['id'] . '-' . time(),
        'quick_pay' => [
            'name'         => 'Tip — Sour Flour Bakery',
            'price_money'  => ['amount' => 100, 'currency' => 'USD'],
            'location_id'  => SQUARE_LOCATION_ID,
        ],
        'checkout_options' => [
            'redirect_url' => $returnUrl,
        ],
    ]);

    $url = $result['payment_link']['url'] ?? null;
    if (!$url) {
        throw new RuntimeException('Square did not return a payment link URL.');
    }

    header('Location: ' . $url);
    exit;
} catch (Throwable $e) {
    error_log('Square tip error: ' . $e->getMessage());
    header('Location: ' . BASE_URL . 'customer_portal.php?tip=error');
    exit;
}
