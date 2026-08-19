<?php
/**
 * Keeps an authenticated driver's route session active while the route is open.
 * Request security is applied by includes/database.php before this response.
 */
if (!defined('ACCESS_ALLOWED')) {
    define('ACCESS_ALLOWED', true);
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'role' => (string)((bakery_current_user()['role_slug'] ?? '')),
    'csrf_token' => bakery_csrf_token(),
]);
