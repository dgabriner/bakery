<?php
/**
 * Production Center API — JSON dispatch for manager production mutations.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/product_inventory.php';
require_once __DIR__ . '/includes/operational_timeline.php';
require_once __DIR__ . '/includes/demand_review.php';
require_once __DIR__ . '/includes/production_plan.php';
require_once __DIR__ . '/includes/production_assign.php';
require_once __DIR__ . '/includes/operational_exceptions.php';
require_once __DIR__ . '/includes/product_pack_yields.php';
require_once __DIR__ . '/includes/schema_sql.php';
require_once __DIR__ . '/includes/production_center_actions.php';

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
    $result = bakery_production_center_dispatch($db, $_POST, $user, ['wants_json' => true]);
    $response = (string)($result['response'] ?? 'page');
    if ($response === 'json') {
        if (isset($result['http_status'])) {
            http_response_code((int)$result['http_status']);
        }
        echo json_encode($result['payload'] ?? []);
        exit;
    }
    if ($response === 'redirect') {
        echo json_encode(['ok' => true, 'redirect' => $result['redirect'] ?? 'production_center.php']);
        exit;
    }
    echo json_encode([
        'ok' => empty($result['error']),
        'notice' => $result['notice'] ?? null,
        'error' => $result['error'] ?? null,
        'kitchen_parse' => $result['kitchen_parse'] ?? null,
        'route_capacity' => $result['route_capacity'] ?? null,
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $formatted = bakery_production_center_format_dispatch_error($e, true);
    if (isset($formatted['http_status'])) {
        http_response_code((int)$formatted['http_status']);
    } else {
        http_response_code(500);
    }
    echo json_encode($formatted['payload'] ?? ['ok' => false, 'error' => bakery_error_message_for_user($e)]);
}
