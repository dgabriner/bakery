<?php
require_once '../includes/config.php';
require_once '../includes/database.php';

header('Content-Type: application/json');

try {
    if (!isset($_GET['day']) || !isset($_GET['driver_id'])) {
        throw new Exception('Missing required parameters');
    }

    $day = (int)$_GET['day'];
    $driver_id = (int)$_GET['driver_id'];

    $stmt = $db->prepare("
        SELECT customer_id 
        FROM standing_routes 
        WHERE day_of_week = ? AND driver_id = ?
        ORDER BY stop_number
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