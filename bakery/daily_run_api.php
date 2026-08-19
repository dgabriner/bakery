<?php
/**
 * Daily Run API — safe inline resolution actions from Daily Run / Dashboard.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/operational_exceptions.php';
require_once __DIR__ . '/includes/driver_assignments.php';
require_once __DIR__ . '/includes/daily_order_generation.php';
require_once __DIR__ . '/includes/demand_confirmation.php';
require_once __DIR__ . '/includes/production_plan.php';
require_once __DIR__ . '/includes/billing.php';

bakery_require_role(['administrator', 'manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

bakery_require_csrf();

$action = (string)($_POST['action'] ?? '');
$date = bakery_dashboard_resolve_date((string)($_POST['operating_date'] ?? ''));
$user = bakery_current_user();
$userId = isset($user['id']) ? (int)$user['id'] : null;
$returnKey = trim((string)($_POST['return'] ?? 'daily_run'));
$returnTarget = bakery_ops_return_resolve($returnKey, $date);
$redirect = $returnTarget['href'] ?? (BASE_URL . 'daily_run.php?date=' . rawurlencode($date));

try {
    switch ($action) {
        case 'assign_from_standing':
            $result = bakery_driver_assign_from_standing_routes($db, $date);
            $msg = 'Built ' . $result['stop_count'] . ' route stop'
                . ($result['stop_count'] === 1 ? '' : 's') . ' from standing route.';
            safe_redirect($redirect . '&flash=success&msg=' . urlencode($msg));
            break;

        case 'generate_daily_orders':
            $result = bakery_generate_daily_orders_from_standing($db, $date, [
                'overwrite_changed' => false,
            ]);
            safe_redirect($redirect . '&flash=success&msg=' . urlencode($result['message']));
            break;

        case 'confirm_demand':
            $result = bakery_demand_confirmation_confirm($db, $date, $userId);
            $msg = 'Demand confirmed for ' . date('l, F j', strtotime($date)) . ': '
                . $result['customers_count'] . ' customers, '
                . number_format($result['units_count']) . ' units.';
            safe_redirect($redirect . '&flash=success&msg=' . urlencode($msg));
            break;

        case 'commit_production_plan':
            $result = bakery_production_plan_commit($db, $date, $userId);
            $msg = 'Production plan committed for ' . date('l, F j', strtotime($date)) . ': '
                . $result['products_count'] . ' products, '
                . number_format($result['units_count']) . ' units.';
            safe_redirect($redirect . '&flash=success&msg=' . urlencode($msg));
            break;

        case 'mark_invoiced':
            $orderId = (int)($_POST['daily_order_id'] ?? 0);
            if ($orderId <= 0) {
                throw new RuntimeException('Order ID required');
            }
            bakery_billing_mark_invoiced($db, $orderId, $userId);
            safe_redirect($redirect . '&flash=success&msg=' . urlencode('Order marked invoiced.'));
            break;

        default:
            throw new RuntimeException('Unknown action');
    }
} catch (Throwable $e) {
    error_log('daily_run_api: ' . $e->getMessage());
    safe_redirect($redirect . '&flash=error&msg=' . urlencode($e->getMessage()));
}
