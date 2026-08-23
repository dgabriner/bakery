<?php
/**
 * Staff alerts API — read-only summary for the nav bell.
 *
 * GET staff_alerts_api.php?action=summary → JSON alert list for the signed-in
 * administrator/manager. Live exceptions only; nothing is stored here.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/staff_alerts.php';

bakery_require_login();

header('Cache-Control: no-store, max-age=0');
header('Content-Type: application/json; charset=utf-8');

$user = bakery_current_user();
if (!bakery_staff_alerts_role_eligible($user)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not available for this role']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$action = (string)($_GET['action'] ?? 'summary');
if ($action !== 'summary') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

try {
    $summary = bakery_staff_alerts_collect($db, $user);
    echo json_encode([
        'success' => true,
        'available' => $summary['available'],
        'today' => $summary['today'],
        'generated_at' => date('c'),
        'counts' => $summary['counts'],
        'alerts' => $summary['alerts'],
        'labels' => [
            'all_clear' => bakery_t('alerts.all_clear'),
            'load_error' => bakery_t('alerts.load_error'),
            'assigned_to_you' => bakery_t('alerts.assigned_to_you'),
            'due' => bakery_t('alerts.due'),
            'panel_title' => bakery_t('alerts.panel_title'),
            'view_all' => bakery_t('alerts.view_all'),
            'open_label' => bakery_t('dashboard.open'),
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('staff alerts api: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'available' => false,
        'error' => bakery_t('alerts.load_error'),
    ]);
}
