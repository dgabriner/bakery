<?php
/**
 * Legacy entry — Invoice Center is now part of Billing Center.
 */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';

$params = $_GET;
$params['panel'] = 'invoices';
$query = http_build_query($params);
header('Location: billing_center.php' . ($query !== '' ? '?' . $query : ''));
exit;
