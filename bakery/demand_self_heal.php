<?php
/**
 * POST beacon: build today's sell day when the nightly demand job did not.
 * GET writes nothing. This file has one action. No mail or SMS.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/daily_order_generation.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($db) || !$db instanceof PDO) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'ran' => false, 'reason' => 'database', 'source' => 'self_heal']);
    exit;
}

try {
    $result = bakery_demand_self_heal_dispatch($db);
} catch (Throwable $e) {
    error_log('demand self heal: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'ran' => false, 'reason' => 'failed', 'source' => 'self_heal']);
    exit;
}

http_response_code((int)$result['status']);
echo json_encode($result['body'], JSON_UNESCAPED_SLASHES);
