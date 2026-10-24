<?php
/**
 * Read-only Sales Leads feed for a bot.
 *
 * GET sales_leads_api.php
 * GET sales_leads_api.php?format=csv
 *
 * Auth: an administrator or manager session, or
 *   Authorization: Bearer <SALES_LEADS_TOKEN>
 * The token is read from the server environment (or bakery/.env, which is not
 * committed). Compare with hash_equals. Unauthenticated requests get 401.
 * This script never writes.
 */
define('ACCESS_ALLOWED', true);
define('BAKERY_SKIP_REQUEST_SECURITY', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sales_leads.php';

header('Cache-Control: private, no-store');

if (!isset($db) || !($db instanceof PDO)) {
    $db = check_mysql_connection();
}

$result = bakery_sales_leads_api_dispatch(
    $db,
    (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    $_GET,
    $_SERVER
);
http_response_code($result['status']);
header('Content-Type: ' . $result['content_type']);
if (!empty($result['filename'])) {
    header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
}
if (is_array($result['body'])) {
    echo json_encode($result['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} else {
    echo (string)$result['body'];
}
