<?php
/**
 * Login-gated, CSRF-exempt browser error beacon (sendBeacon / fetch keepalive).
 * Rate-limited per session. Same-origin preferred; session cookie is the gate.
 */
define('ACCESS_ALLOWED', true);
define('BAKERY_SKIP_REQUEST_SECURITY', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/client_errors.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method']);
    exit;
}

$user = bakery_current_user();
if (!$user && !bakery_login_audit_current_id()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'auth']);
    exit;
}

if (!bakery_client_error_same_origin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'origin']);
    exit;
}

$rate = bakery_client_error_rate_limit(20);
if (!$rate['allowed']) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'rate_limited']);
    exit;
}

$payload = $_POST;
$raw = file_get_contents('php://input');
if (is_string($raw) && $raw !== '' && $payload === []) {
    parse_str($raw, $parsed);
    if (is_array($parsed)) {
        $payload = $parsed;
    }
}
if (!empty($payload['payload']) && is_string($payload['payload'])) {
    $decoded = json_decode($payload['payload'], true);
    if (is_array($decoded)) {
        $payload = array_merge($payload, $decoded);
    }
}

$id = bakery_client_error_record($db, [
    'user_id' => $user ? (int)($user['id'] ?? 0) ?: null : null,
    'login_audit_id' => bakery_login_audit_current_id() ?: null,
    'kind' => (string)($payload['kind'] ?? 'error'),
    'message' => (string)($payload['message'] ?? ''),
    'stack_head' => (string)($payload['stack_head'] ?? ''),
    'page_path' => (string)($payload['page'] ?? $payload['page_path'] ?? ''),
    'page_href' => (string)($payload['href'] ?? $payload['page_href'] ?? ''),
    'build_id' => (string)($payload['build'] ?? $payload['build_id'] ?? ''),
    'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
]);

echo json_encode(['success' => true, 'id' => $id]);
