<?php
/**
 * Service Issues API — manager review and resolution.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/customer_delivery_issues.php';

bakery_require_role(['administrator', 'manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

bakery_require_csrf();

$action = (string)($_POST['action'] ?? '');
$user = bakery_current_user();
$userId = isset($user['id']) ? (int)$user['id'] : null;
$redirect = (string)($_POST['redirect'] ?? 'service_issues.php');

try {
    switch ($action) {
        case 'start_review':
            $issueId = (int)($_POST['issue_id'] ?? 0);
            bakery_delivery_issue_start_review($db, $issueId, $userId);
            safe_redirect($redirect ?: ('service_issues.php?id=' . $issueId));
            break;

        case 'resolve':
            $issueId = (int)($_POST['issue_id'] ?? 0);
            bakery_delivery_issue_resolve($db, $issueId, [
                'status' => $_POST['status'] ?? 'resolved',
                'resolution_note' => $_POST['resolution_note'] ?? '',
                'internal_note' => $_POST['internal_note'] ?? '',
                'credit_recommendation' => $_POST['credit_recommendation'] ?? 'none',
                'credit_pieces' => $_POST['credit_pieces'] ?? null,
            ], $userId);
            safe_redirect($redirect ?: ('service_issues.php?id=' . $issueId . '&flash=resolved'));
            break;

        default:
            throw new RuntimeException('Unknown action');
    }
} catch (Throwable $e) {
    error_log('service_issues_api: ' . $e->getMessage());
    safe_redirect($redirect ?: 'service_issues.php', 'error');
}
