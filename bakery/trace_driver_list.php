<?php
/** Quarantined legacy diagnostic; excluded from production release. */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
if (!defined('IS_LOCAL') || !IS_LOCAL) {
    http_response_code(404);
    exit;
}
bakery_require_role(['administrator']);
header('Content-Type: text/plain; charset=utf-8');
echo "Legacy driver trace is quarantined. Use the local test gate instead.\n";
