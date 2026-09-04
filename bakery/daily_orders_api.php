<?php
/**
 * Daily Orders API — JSON dispatch for manager daily-order mutations.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/customer_portal.php';
require_once __DIR__ . '/includes/customer_order_mutations.php';
require_once __DIR__ . '/includes/demand_review.php';
require_once __DIR__ . '/includes/daily_order_generation.php';
require_once __DIR__ . '/includes/operational_exceptions.php';
require_once __DIR__ . '/includes/operational_timeline.php';
require_once __DIR__ . '/includes/pan_dulce_standards.php';
require_once __DIR__ . '/includes/daily_orders_actions.php';

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
    echo json_encode(bakery_daily_orders_dispatch($db, $_POST, $user));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => bakery_error_message_for_user($e)]);
}
