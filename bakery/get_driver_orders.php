<?php
/**
 * Driver orders JSON API — matches Checkpoint 0C contract.
 * POST: driver_id, date (+ CSRF). Auth: driver, manager, administrator.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

header('Content-Type: application/json');

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('POST required');
    }

    if (!isset($_POST['driver_id']) || !isset($_POST['date'])) {
        throw new Exception('driver_id and date are required');
    }

    $driverId = (int)$_POST['driver_id'];
    $date = trim((string)$_POST['date']);

    if ($driverId <= 0) {
        throw new Exception('Invalid driver_id');
    }

    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new Exception('Invalid date format; use YYYY-MM-DD');
    }

    $stmt = $db->prepare("
        SELECT
            c.name AS customer_name,
            c.address AS customer_address,
            c.zone,
            do.id AS daily_order_id,
            do.total_amount,
            doa.route_order,
            doa.scheduled_delivery_time
        FROM daily_orders do
        INNER JOIN customers c ON do.customer_id = c.id
        INNER JOIN daily_order_assignments doa ON do.id = doa.daily_order_id
        INNER JOIN drivers d ON doa.driver_id = d.id
        WHERE doa.driver_id = ? AND do.order_date = ?
        ORDER BY doa.route_order, c.zone, c.name
    ");
    $stmt->execute([$driverId, $date]);
    $results = $stmt->fetchAll();

    $orders = [];
    foreach ($results as $row) {
        $orders[] = [
            'daily_order_id' => (int)$row['daily_order_id'],
            'customer_name' => (string)$row['customer_name'],
            'customer_address' => (string)$row['customer_address'],
            'zone' => ($row['zone'] !== null && $row['zone'] !== '') ? (string)$row['zone'] : 'No Zone',
            'route_order' => (int)$row['route_order'],
            'scheduled_delivery_time' => $row['scheduled_delivery_time'],
            'total_amount' => (float)$row['total_amount'],
        ];
    }

    echo json_encode([
        'success' => true,
        'orders' => $orders,
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
