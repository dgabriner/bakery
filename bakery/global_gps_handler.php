<?php
// Security check
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';

// Handle AJAX request to log GPS coordinates from any page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'log_gps') {
    header('Content-Type: application/json');
    
    $driver_id = intval($_POST['driver_id']);
    $latitude = floatval($_POST['latitude']);
    $longitude = floatval($_POST['longitude']);
    $timestamp = $_POST['timestamp'];
    
    // Validate inputs
    if ($driver_id > 0 && $latitude !== 0.0 && $longitude !== 0.0) {
        try {
            $stmt = $db->prepare("INSERT INTO driver_history (driver_id, timestamp, latitude, longitude) VALUES (?, ?, ?, ?)");
            $success = $stmt->execute([$driver_id, $timestamp, $latitude, $longitude]);
            
            echo json_encode([
                'success' => $success,
                'message' => 'GPS coordinate logged successfully',
                'timestamp' => $timestamp,
                'source' => 'global_handler'
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false, 
                'error' => $e->getMessage(),
                'source' => 'global_handler'
            ]);
        }
    } else {
        echo json_encode([
            'success' => false, 
            'error' => 'Invalid GPS data',
            'received' => [
                'driver_id' => $driver_id,
                'latitude' => $latitude,
                'longitude' => $longitude
            ],
            'source' => 'global_handler'
        ]);
    }
} else {
    echo json_encode([
        'success' => false, 
        'error' => 'Invalid request',
        'source' => 'global_handler'
    ]);
}
?> 