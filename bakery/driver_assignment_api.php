<?php
/**
 * Driver Assignment API — JSON dispatch for manager driver-route mutations.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/customer_order_mutations.php';
require_once __DIR__ . '/includes/driver_assignments.php';
require_once __DIR__ . '/includes/driver_assignment_actions.php';

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
    echo json_encode(bakery_driver_assignment_dispatch($db, $_POST, $user));
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
