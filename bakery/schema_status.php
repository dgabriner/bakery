<?php
/** Public, non-sensitive Live schema inventory for Staging comparison. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
if (($_SERVER['HTTP_ORIGIN'] ?? '') === 'https://staging.sourflour.org') {
    header('Access-Control-Allow-Origin: https://staging.sourflour.org');
    header('Vary: Origin');
}

define('ACCESS_ALLOWED', true);
define('BAKERY_SKIP_REQUEST_SECURITY', true);

try {
    require_once __DIR__ . '/includes/config.php';
    if (!bakery_is_live_bakery_host()) {
        http_response_code(404);
        echo json_encode(['status' => 'unavailable', 'message' => 'Schema inventory is published by Live only.']);
        exit;
    }
    require_once __DIR__ . '/includes/database.php';
    require_once __DIR__ . '/includes/schema_inventory.php';
    if (!isset($db) || !($db instanceof PDO)) {
        $db = check_mysql_connection();
    }
    $name = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    if ($name !== 'bakerysf') {
        http_response_code(503);
        echo json_encode(['status' => 'unavailable', 'message' => 'Live schema identity could not be confirmed.']);
        exit;
    }
    $force = isset($_GET['refresh']) && (string)$_GET['refresh'] === '1';
    echo json_encode(bakery_schema_inventory_for_live_publish($db, $force), JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['status' => 'unavailable', 'message' => 'Live schema inventory is temporarily unavailable.']);
}
