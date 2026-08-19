<?php
/**
 * Public build stamp for client refresh. No secrets; cache disabled.
 */
define('ACCESS_ALLOWED', true);
define('BAKERY_SKIP_REQUEST_SECURITY', true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/includes/config.php';

echo json_encode([
    'build' => bakery_client_build_id(),
], JSON_UNESCAPED_SLASHES);
