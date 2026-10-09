<?php
/**
 * Customer tip — creates a Square payment link for $1 and redirects there.
 * POST only (with CSRF token). One checkout key per click, so a double submit
 * reuses the same Square idempotency key. Returns to customer_portal.php?tip=thanks.
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

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = rtrim($scheme . '://' . $host . BASE_URL, '/') . '/';
$returnUrl = $base . 'customer_portal.php?tip=thanks';

try {
    $checkout = bakery_portal_create_tip_checkout(
        $customer,
        (string)($_POST['checkout_key'] ?? ''),
        $returnUrl
    );
    header('Location: ' . $checkout['url']);
    exit;
} catch (Throwable $e) {
    error_log('Square tip error: ' . $e->getMessage());
    header('Location: ' . BASE_URL . 'customer_portal.php?tip=error');
    exit;
}
