<?php
/**
 * Read-only daily operations snapshot.
 *
 * GET daily_status_api.php?date=YYYY-MM-DD
 *
 * Auth: an administrator or manager session, or
 *   Authorization: Bearer <DAILY_STATUS_TOKEN>
 * The token is read from the server environment (or bakery/.env, which is not
 * committed). Compare with hash_equals. Unauthenticated requests get 401.
 * This script never writes.
 */
define('ACCESS_ALLOWED', true);
define('BAKERY_SKIP_REQUEST_SECURITY', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/daily_status.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

if (!isset($db) || !($db instanceof PDO)) {
    $db = check_mysql_connection();
}

$result = bakery_daily_status_dispatch(
    $db,
    (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    (string)($_GET['date'] ?? ''),
    $_SERVER
);
http_response_code($result['status']);
echo json_encode($result['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
