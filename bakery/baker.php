<?php
/**
 * Baker auto-login entry — public exception to auth gate.
 * Visits establish a baker session and redirect. Use ?baker=niko for Niko.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';

$dest = BASE_URL . 'production.php?date=' . urlencode(date('Y-m-d', strtotime('+1 day')));
$requestedBaker = strtolower(trim((string)($_GET['baker'] ?? 'juan')));
$loginCode = $requestedBaker === 'niko' ? BAKERY_NIKO_CODE : BAKERY_BAKER_CODE;

try {
    $db = check_mysql_connection();
    if (!bakery_ensure_baker_user($db)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Baker login unavailable.\n";
        exit;
    }

    $user = bakery_current_user();
    if ($user && $user['role_slug'] === 'baker' && (($requestedBaker === 'niko' && ($user['email'] ?? '') === BAKERY_NIKO_EMAIL) || ($requestedBaker !== 'niko' && ($user['email'] ?? '') === BAKERY_BAKER_EMAIL))) {
        header('Location: ' . $dest);
        exit;
    }

    if ($user) {
        bakery_logout();
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            session_start();
        }
    }

    if (!bakery_login($db, $loginCode)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Baker login failed.\n";
        exit;
    }

    header('Location: ' . $dest);
    exit;
} catch (Exception $e) {
    error_log('Baker auto-login error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Baker login unavailable.\n";
    exit;
}
