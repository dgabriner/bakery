<?php
/**
 * Administrator impersonation into an SF Baker customer portal session.
 * Staff identity is kept so the admin can return without re-entering a code.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sfb_agent.php';

bakery_require_role(['administrator']);
bakery_ensure_sfb_schema($db);

$action = (string)($_POST['action'] ?? '');
try {
    if ($action === 'start') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        bakery_sfb_agent_login_as_customer($db, $customerId);
        header('Location: sfb_dashboard.php');
        exit;
    }
    if ($action === 'stop') {
        bakery_sfb_agent_stop_impersonation();
        header('Location: sfb_admin_overview.php');
        exit;
    }
    throw new InvalidArgumentException('That impersonation action is not available.');
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage() . "\n";
    exit;
}
