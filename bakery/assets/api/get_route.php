<?php
require_once '../includes/config.php';
require_once '../includes/database.php';

header('Content-Type: application/json');

try {
    if (!isset($_GET['day']) || !isset($_GET['driver_id'])) {
        throw new Exception('Missing required parameters');
    }

    $day = (int)$_GET['day'];
    $day = bakery_normalize_standing_day($day);
    $driver_id = (int)$_GET['driver_id'];

    $stmt = $db->prepare("
        SELECT customer_id 
        FROM standing_routes 
        WHERE CASE WHEN day_of_week = 0 THEN 7 ELSE day_of_week END = ? AND driver_id = ?
        ORDER BY COALESCE(route_order, 2147483647), customer_id
    ");
    $stmt->execute([$day, $driver_id]);
    
    $customer_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode([
        'success' => true,
        'customers' => $customer_ids
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
