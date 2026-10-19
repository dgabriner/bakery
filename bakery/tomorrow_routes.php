<?php
/**
 * Mobile page for a manager to set tomorrow's driver stops.
 * GET only reads. Assign, skip, move, and driver-off are POST plus CSRF.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/tomorrow_routes.php';
require_once __DIR__ . '/includes/survey_dual_open.php';

bakery_require_role(['administrator', 'manager']);

$requestedDate = (string)($_GET['date'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $requestedDate = (string)($_POST['date'] ?? $requestedDate);
}
$date = bakery_tomorrow_routes_resolve_date($requestedDate);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    bakery_require_csrf();
    bakery_tomorrow_routes_apply($db, 'POST', $_POST, $date);
    safe_redirect('tomorrow_routes.php?date=' . rawurlencode($date) . '&saved=1');
}

try {
    bakery_survey_dual_open_if_due($db, null, (int)(bakery_current_user()['id'] ?? 0));
} catch (Throwable $e) {
    error_log('tomorrow_routes.php open if due: ' . $e->getMessage());
}

$board = bakery_tomorrow_routes_board($db, $date);
$board['saved'] = isset($_GET['saved']);
$page_title = bakery_t('tomorrow_routes.title');

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
echo '<link rel="stylesheet" href="' . htmlspecialchars(BASE_URL . 'css/tomorrow_routes.css', ENT_QUOTES, 'UTF-8') . '">';
echo bakery_tomorrow_routes_render($board);
require_once __DIR__ . '/includes/footer.php';
