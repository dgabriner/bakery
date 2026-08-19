<?php
/** Legacy URL — redirect to canonical portal billing page. */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = 'customer_portal_billing.php' . ($query !== '' ? '?' . $query : '');
header('Location: ' . BASE_URL . $target, true, 301);
exit;
