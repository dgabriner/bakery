<?php
/** Authenticated browser telemetry endpoint for the current login session. */
define('ACCESS_ALLOWED', true);
define('BAKERY_SKIP_REQUEST_SECURITY', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/customer_portal.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !bakery_login_audit_current_id()) {
    http_response_code(401);
    echo json_encode(['success' => false]);
    exit;
}
if (!bakery_verify_csrf()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$payload = $_POST;
if (!empty($_POST['payload'])) {
    $decoded = json_decode((string)$_POST['payload'], true);
    if (is_array($decoded)) {
        $payload = array_merge($payload, $decoded);
    }
}
bakery_login_audit_touch($db, $payload);
echo json_encode(['success' => true]);
