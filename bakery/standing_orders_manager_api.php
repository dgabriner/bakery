<?php
/**
 * Standing Orders Manager API — JSON dispatch for manager standing-order mutations.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/customer_portal.php';
require_once __DIR__ . '/includes/pan_dulce_standards.php';
require_once __DIR__ . '/includes/sfb_origin.php';
require_once __DIR__ . '/includes/standing_orders_manager_actions.php';

bakery_ensure_portal_schema($db);
bakery_require_role(['administrator', 'manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

bakery_require_csrf();

header('Content-Type: application/json');

$user = bakery_current_user();

try {
    echo json_encode(bakery_standing_orders_manager_dispatch($db, $_POST, $user));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => bakery_error_message_for_user($e)]);
}
