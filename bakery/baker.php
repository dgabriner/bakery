<?php
/** Legacy baker entry: public auto-login has been removed. */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';

$dest = BASE_URL . 'production.php?date=' . urlencode(date('Y-m-d', strtotime('+1 day')));
if (!bakery_current_user()) {
    header('Location: ' . BASE_URL . 'login.php?next=' . rawurlencode($dest));
    exit;
}
bakery_require_role(['baker', 'manager', 'administrator']);
header('Location: ' . $dest);
exit;
