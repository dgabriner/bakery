<?php
/**
 * Logout — destroys session and returns to login.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!bakery_verify_csrf()) {
        http_response_code(403);
        echo "Invalid CSRF token";
        exit;
    }
}

bakery_logout();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Location: ' . BASE_URL . 'login.php');
exit;
