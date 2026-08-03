<?php
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';

// Add cache-busting headers to ensure fresh data
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$page_title = 'Driver Overview by Driver';

// Get selected date (default to tomorrow)
$selectedDate = $_GET['date'] ?? date('Y-m-d', strtotime('+1 day'));
$dayName = date('l', strtotime($selectedDate));
$dayOfWeek = date('N', strtotime($selectedDate)); // 1=Monday, 7=Sunday

// Handle AJAX driver assignment updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_driver') {
    try {
        $customerId = $_POST['customer_id'];
        $dayOfWeek = bakery_normalize_standing_day((int)$_POST['day_of_week']);
        $driverId = empty($_POST['driver_id']) ? null : $_POST['driver_id'];
        
        if ($driverId === null) {
            // Remove the standing route entry
            $dayClause = $dayOfWeek === 7 ? 'IN (0, 7)' : '= ?';
            $stmt = $db->prepare("DELETE FROM standing_routes WHERE customer_id = ? AND day_of_week $dayClause");
            $stmt->execute($dayOfWeek === 7 ? [$customerId] : [$customerId, $dayOfWeek]);
        } else {
            bakery_ensure_drivers_archived_column($db);
            $driverRow = bakery_get_driver_by_id($db, (int)$driverId);
            if ($driverRow && (int)($driverRow['archived'] ?? 0) === 1) {
                throw new Exception('Cannot assign standing routes to an archived driver');
            }

            // Check if route already exists
            $stmt = $db->prepare("SELECT id FROM standing_routes WHERE customer_id = ? AND day_of_week = ?");
            $stmt->execute([$customerId, $dayOfWeek]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Update existing route
                $stmt = $db->prepare("UPDATE standing_routes SET driver_id = ? WHERE customer_id = ? AND day_of_week = ?");
                $stmt->execute([$driverId, $customerId, $dayOfWeek]);
            } else {
                // Create new route
                $stmt = $db->prepare("INSERT INTO standing_routes (customer_id, driver_id, day_of_week) VALUES (?, ?, ?)");
                $stmt->execute([$customerId, $driverId, $dayOfWeek]);
            }
        }
        
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Get zone data organized with customers and their assigned drivers for the selected date
$zoneData = [];
$driverStats = [];
$visitStats = [];

try {
    // Get all customers with their daily order assignments for the selected date
    $stmt = $db->prepare("
        SELECT 
            c.id as customer_id,
            c.name as customer_name,
            c.address as customer_address,
            c.zone,
            do.id as daily_order_id,
            do.total_amount,
            doa.driver_id,
            doa.route_order,
            doa.scheduled_delivery_time,
            doa.delivery_status,
            d.name as driver_name
        FROM customers c
        INNER JOIN daily_orders do ON c.id = do.customer_id
        LEFT JOIN daily_order_assignments doa ON do.id = doa.daily_order_id AND doa.delivery_date = do.order_date
        LEFT JOIN drivers d ON doa.driver_id = d.id
        WHERE do.order_date = ?
        ORDER BY 
            d.name,
            doa.route_order,
            c.zone,
            c.name
    ");
    $stmt->execute([$selectedDate]);
    $results = $stmt->fetchAll();
    
    // Debug information
    error_log("Driver Overview - Selected Date: $selectedDate");
    error_log("Driver Overview - Found " . count($results) . " orders for this date");
    if (count($results) > 0) {
        error_log("Driver Overview - Sample order: " . print_r($results[0], true));
    }
    
    // Check what dates have orders
    $dateStmt = $db->query("SELECT DISTINCT order_date FROM daily_orders ORDER BY order_date DESC LIMIT 5");
    $availableDates = $dateStmt->fetchAll(PDO::FETCH_COLUMN);
    error_log("Driver Overview - Available dates with orders: " . implode(', ', $availableDates));
    
    // Get zone colors for consistent display
    $zoneColors = [
        '#007bff', '#28a745', '#dc3545', '#fd7e14', '#6f42c1', 
        '#20c997', '#ffc107', '#e83e8c', '#6c757d', '#17a2b8',
        '#6610f2', '#fd7e14', '#e83e8c', '#6f42c1', '#20c997'
    ];
    
    $drivers = [];
    foreach (bakery_get_drivers($db) as $driver) {
        $drivers[$driver['id']] = [
            'name' => $driver['name']
        ];
    }
    
    // Create zone color mapping
    $zoneColorMap = [];
    $zoneIndex = 0;
    
    // Process the data to create a driver-grouped structure with zone assignments
    $driverDeliveries = [];
    $unassignedOrders = [];
    
    foreach ($results as $row) {
        $zone = $row['zone'] ?: 'No Zone';
        $customerId = $row['customer_id'];
        
        // Assign color to zone if not already assigned
        if (!isset($zoneColorMap[$zone])) {
            $zoneColorMap[$zone] = $zoneColors[$zoneIndex % count($zoneColors)];
            $zoneIndex++;
        }
        
        if ($row['driver_id']) {
            // Assigned order - group by driver first
            $driverId = $row['driver_id'];
            if (!isset($driverDeliveries[$driverId])) {
                $driverDeliveries[$driverId] = [
                    'driver_name' => $row['driver_name'],
                    'zones' => []
                ];
            }
            
            // Group by zone within driver
            if (!isset($driverDeliveries[$driverId]['zones'][$zone])) {
                $driverDeliveries[$driverId]['zones'][$zone] = [];
            }
            
            $driverDeliveries[$driverId]['zones'][$zone][] = [
                'customer_id' => $row['customer_id'],
                'customer_name' => $row['customer_name'],
                'customer_address' => $row['customer_address'],
                'zone' => $zone,
                'daily_order_id' => $row['daily_order_id'],
                'total_amount' => $row['total_amount'],
                'driver_id' => $row['driver_id'],
                'driver_name' => $row['driver_name'],
                'route_order' => $row['route_order'],
                'scheduled_delivery_time' => $row['scheduled_delivery_time'],
                'delivery_status' => $row['delivery_status'],
                'driver_initial' => $row['driver_name'] ? strtoupper(substr($row['driver_name'], 0, 1)) : 'X',
                'zone_color' => $zoneColorMap[$zone]
            ];
        } else {
            // Unassigned order
            if (!isset($unassignedOrders[$zone])) {
                $unassignedOrders[$zone] = [];
            }
            
            $unassignedOrders[$zone][] = [
                'customer_id' => $row['customer_id'],
                'customer_name' => $row['customer_name'],
                'customer_address' => $row['customer_address'],
                'daily_order_id' => $row['daily_order_id'],
                'total_amount' => $row['total_amount']
            ];
        }
    }
    
    // Calculate statistics for each driver
    foreach ($driverDeliveries as $driverId => $driverData) {
        $totalStops = 0;
        $totalAmount = 0;
        $zones = [];
        
        foreach ($driverData['zones'] as $zoneName => $zoneStops) {
            $totalStops += count($zoneStops);
            foreach ($zoneStops as $stop) {
                $totalAmount += $stop['total_amount'];
            }
            $zones[] = $zoneName;
        }
        
        $driverStats[$driverId] = [
            'name' => $driverData['driver_name'],
            'total_stops' => $totalStops,
            'total_amount' => $totalAmount,
            'zones' => $zones,
            'zone_count' => count($zones)
        ];
    }
    
    // Calculate zone statistics for backward compatibility
    foreach ($driverDeliveries as $driverId => $driverData) {
        foreach ($driverData['zones'] as $zoneName => $zoneStops) {
            if (!isset($visitStats[$zoneName])) {
                $visitStats[$zoneName] = [
                    'total_stops' => 0,
                    'unique_drivers' => 0,
                    'total_amount' => 0,
                    'drivers' => []
                ];
            }
            
            $visitStats[$zoneName]['total_stops'] += count($zoneStops);
            $visitStats[$zoneName]['total_amount'] += array_sum(array_column($zoneStops, 'total_amount'));
            $visitStats[$zoneName]['drivers'][$driverId] = $driverData['driver_name'];
            $visitStats[$zoneName]['unique_drivers'] = count($visitStats[$zoneName]['drivers']);
        }
    }
    
    // Create customer table data (for table view)
    $customerTableData = [];
    
    // Process customer data for table view
    foreach ($results as $row) {
        $zone = $row['zone'] ?: 'No Zone';
        $customerId = $row['customer_id'];
        
        if (!isset($customerTableData[$zone])) {
            $customerTableData[$zone] = [];
        }
        
        if (!isset($customerTableData[$zone][$customerId])) {
            $customerTableData[$zone][$customerId] = [
                'customer_id' => $row['customer_id'],
                'customer_name' => $row['customer_name'],
                'customer_address' => $row['customer_address'],
                'zone' => $zone,
                'daily_order_id' => $row['daily_order_id'],
                'total_amount' => $row['total_amount'],
                'driver_id' => $row['driver_id'],
                'driver_name' => $row['driver_name'],
                'route_order' => $row['route_order'],
                'scheduled_delivery_time' => $row['scheduled_delivery_time'],
                'delivery_status' => $row['delivery_status']
            ];
        }
    }
    
    // Sort customers by driver name and route order within each zone
    foreach ($customerTableData as $zoneName => $zoneCustomers) {
        uasort($zoneCustomers, function($a, $b) {
            // First sort by driver name
            $driverCompare = strcasecmp($a['driver_name'] ?: 'ZZZ', $b['driver_name'] ?: 'ZZZ');
            if ($driverCompare !== 0) {
                return $driverCompare;
            }
            // Then by route order
            $routeOrderA = $a['route_order'] ?: 999;
            $routeOrderB = $b['route_order'] ?: 999;
            if ($routeOrderA !== $routeOrderB) {
                return $routeOrderA - $routeOrderB;
            }
            // Then by customer name
            return strcasecmp($a['customer_name'], $b['customer_name']);
        });
        $customerTableData[$zoneName] = $zoneCustomers;
    }
    
} catch (Exception $e) {
    $error = 'Error loading driver data: ' . htmlspecialchars($e->getMessage());
}

$days = [
    1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 
    5 => 'Fri', 6 => 'Sat', 0 => 'Sun'
];

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>

<style>
.overview-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: white;
    padding: 30px;
    border-radius: 15px;
    margin-bottom: 30px;
    text-align: center;
    box-shadow: 0 8px 32px rgba(0,0,0,0.1);
}

.page-header h1 {
    margin: 0 0 10px 0;
    font-size: 2.2rem;
    font-weight: 600;
}

.page-header p {
    margin: 0;
    font-size: 1.1rem;
    opacity: 0.9;
}

.date-navigation {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    margin-bottom: 30px;
    text-align: center;
}

.date-info {
    margin-bottom: 15px;
}

.date-info h2 {
    margin: 0 0 5px 0;
    color: #2c3e50;
    font-size: 1.5rem;
}

.date-controls {
    display: flex;
    justify-content: center;
    gap: 15px;
    flex-wrap: wrap;
}

.date-controls a {
    padding: 10px 20px;
    text-decoration: none;
    border-radius: 8px;
    font-weight: 500;
    transition: all 0.2s ease;
}

.date-controls .btn-outline {
    background: #f8f9fa;
    color: #6c757d;
    border: 2px solid #dee2e6;
}

.date-controls .btn-outline:hover {
    background: #e9ecef;
    border-color: #adb5bd;
}

.date-controls .btn-primary {
    background: #007bff;
    color: white;
    border: 2px solid #007bff;
}

.date-controls .btn-primary:hover {
    background: #0056b3;
    border-color: #0056b3;
}

.stats-overview {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    border-left: 4px solid #28a745;
    transition: transform 0.2s ease;
}

.stat-card:hover {
    transform: translateY(-2px);
}

.stat-card h3 {
    margin: 0 0 15px 0;
    color: #2c3e50;
    font-size: 1.1rem;
    font-weight: 600;
}

.stat-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

.stat-item {
    text-align: center;
    padding: 8px;
    background: #f8f9fa;
    border-radius: 8px;
}

.stat-number {
    display: block;
    font-size: 1.8rem;
    font-weight: bold;
    color: #28a745;
    line-height: 1;
}

.stat-label {
    font-size: 0.9rem;
    color: #6c757d;
    margin-top: 5px;
}

.zones-container {
    display: flex;
    flex-direction: column;
    gap: 30px;
}

.drivers-container {
    display: flex;
    flex-direction: column;
    gap: 30px;
}

.driver-section {
    background: white;
    border-radius: 15px;
    box-shadow: 0 6px 30px rgba(0,0,0,0.08);
    overflow: hidden;
}

.driver-header {
    background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
    color: white;
    padding: 20px 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.driver-title {
    font-size: 1.4rem;
    font-weight: 600;
    margin: 0;
}

.driver-stats {
    display: flex;
    gap: 20px;
    font-size: 0.9rem;
}

.driver-stat {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}

.driver-stat-number {
    font-size: 1.2rem;
    font-weight: bold;
    line-height: 1;
}

.driver-stat-label {
    font-size: 0.8rem;
    opacity: 0.9;
    margin-top: 2px;
}

.driver-content {
    padding: 30px;
}

.zone-group {
    margin-bottom: 25px;
    border: 1px solid #e9ecef;
    border-radius: 10px;
    overflow: hidden;
}

.zone-group:last-child {
    margin-bottom: 0;
}

.zone-subheader {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 15px 20px;
    border-bottom: 1px solid #dee2e6;
}

.zone-subtitle {
    margin: 0;
    color: #495057;
    font-size: 1.1rem;
    font-weight: 600;
}

.stops-list {
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.zone-badge {
    background: rgba(255, 255, 255, 0.2);
    color: white;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stop-zone {
    color: white;
    font-weight: 500;
    font-size: 0.85rem;
    margin-top: 5px;
}

.empty-driver {
    text-align: center;
    padding: 40px;
    color: #6c757d;
    font-style: italic;
    background: #f8f9fa;
    border-radius: 8px;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6c757d;
}

.empty-state h3 {
    margin: 0 0 15px 0;
    color: #495057;
    font-size: 1.3rem;
}

.empty-state p {
    margin: 0;
    font-size: 1rem;
}

.unassigned-zone {
    margin-bottom: 20px;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #e9ecef;
}

.unassigned-zone h4 {
    margin: 0 0 15px 0;
    color: #dc3545;
    font-size: 1rem;
}

.zone-section {
    background: white;
    border-radius: 15px;
    box-shadow: 0 6px 30px rgba(0,0,0,0.08);
    overflow: hidden;
}

.zone-header {
    background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
    color: white;
    padding: 20px 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.zone-title {
    font-size: 1.4rem;
    font-weight: 600;
    margin: 0;
}

.zone-stats {
    display: flex;
    gap: 20px;
    font-size: 0.9rem;
}

.zone-stat {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}

.zone-stat-number {
    font-size: 1.2rem;
    font-weight: bold;
    line-height: 1;
}

.zone-stat-label {
    font-size: 0.8rem;
    opacity: 0.9;
    margin-top: 2px;
}

.zone-content {
    padding: 30px;
}

.driver-legend-section {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    margin-bottom: 30px;
    padding: 20px;
}

.driver-legend-section h3 {
    margin: 0 0 20px 0;
    color: #2c3e50;
    font-size: 1.2rem;
    font-weight: 600;
}

.driver-legend-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.driver-legend-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 8px;
    transition: transform 0.2s ease;
}

.driver-legend-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.driver-color-badge {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 0.9rem;
    color: white;
    border: 2px solid rgba(255,255,255,0.8);
}

.days-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 1px;
    background: #dee2e6;
    margin: 15px;
    border-radius: 8px;
    overflow: hidden;
}

.day-header {
    background: #495057;
    color: white;
    padding: 10px;
    text-align: center;
    font-weight: 600;
    font-size: 0.9rem;
}

.day-stops {
    background: white;
    padding: 15px 10px;
    min-height: 120px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.stop-item {
    background: rgba(255, 255, 255, 0.9);
    border-left: 4px solid;
    padding: 12px 10px;
    border-radius: 8px;
    font-size: 0.85rem;
    transition: all 0.2s ease;
    cursor: pointer;
    margin-bottom: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.stop-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
}

.stop-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
}

.stop-customer {
    font-weight: 600;
    color: #2c3e50;
    flex: 1;
}

.driver-initial {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 0.8rem;
    color: white;
    border: 2px solid rgba(255,255,255,0.8);
}

.stop-address {
    color: #666;
    font-size: 0.8rem;
    line-height: 1.2;
    margin-bottom: 4px;
}

.stop-driver {
    color: #495057;
    font-size: 0.75rem;
    font-weight: 500;
    background: rgba(255, 255, 255, 0.8);
    padding: 2px 6px;
    border-radius: 4px;
}

.empty-day {
    background: #f8f9fa;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6c757d;
    font-style: italic;
    font-size: 0.9rem;
}

/* Table View Styles */
.table-view {
    display: none;
}

.table-view.active {
    display: block;
}

.customer-table {
    background: white;
    border-radius: 15px;
    box-shadow: 0 6px 30px rgba(0,0,0,0.08);
    overflow: hidden;
    margin-bottom: 30px;
}

.table-zone-header {
    background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
    color: white;
    padding: 20px 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.table-zone-title {
    font-size: 1.4rem;
    font-weight: 600;
    margin: 0;
}

.customer-schedule-table {
    width: 100%;
    border-collapse: collapse;
}

.table-header {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}

.table-header th {
    padding: 15px 12px;
    text-align: left;
    font-weight: 600;
    color: #495057;
    border-right: 1px solid #dee2e6;
    font-size: 0.9rem;
}

.table-header th:first-child {
    width: 25%;
    min-width: 200px;
}

.table-header th.day-column {
    width: 10%;
    text-align: center;
    min-width: 80px;
}

.table-header th:last-child {
    border-right: none;
}

.customer-row {
    border-bottom: 1px solid #e9ecef;
    transition: background-color 0.2s ease;
}

.customer-row:hover {
    background-color: #f8f9fa;
}

.customer-row:last-child {
    border-bottom: none;
}

.customer-info-cell {
    padding: 15px 12px;
    border-right: 1px solid #e9ecef;
    vertical-align: top;
}

.table-customer-name {
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 4px;
    font-size: 0.95rem;
}

.table-customer-address {
    color: #6c757d;
    font-size: 0.85rem;
    line-height: 1.3;
}

.table-primary-driver {
    color: #495057;
    font-size: 0.8rem;
    margin-top: 4px;
    font-weight: 500;
}

.day-cell {
    padding: 8px;
    text-align: center;
    border-right: 1px solid #e9ecef;
    vertical-align: middle;
    min-height: 60px;
}

.day-cell:last-child {
    border-right: none;
}

.day-assignment {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    color: white;
    font-weight: bold;
    font-size: 0.9rem;
    border: 2px solid rgba(255,255,255,0.8);
    cursor: pointer;
    transition: all 0.2s ease;
}

.day-assignment:hover {
    transform: scale(1.1);
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}

.day-assignment.empty {
    background: #f8f9fa;
    color: #6c757d;
    border-color: #dee2e6;
    font-size: 0.7rem;
}

.day-assignment.empty:hover {
    background: #e9ecef;
    transform: none;
    box-shadow: none;
}

.controls-section {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    margin-bottom: 30px;
}

.view-toggle {
    margin-bottom: 20px;
    padding-bottom: 20px;
    border-bottom: 1px solid #e9ecef;
}

.toggle-label {
    display: flex;
    align-items: center;
    gap: 15px;
    font-weight: 600;
    color: #2c3e50;
}

.toggle-text {
    font-size: 1rem;
}

.toggle-switch {
    display: flex;
    background: #f8f9fa;
    border-radius: 8px;
    padding: 4px;
    gap: 4px;
}

.toggle-switch input[type="radio"] {
    display: none;
}

.toggle-option {
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 0.9rem;
    font-weight: 500;
    color: #6c757d;
    background: transparent;
}

.toggle-switch input[type="radio"]:checked + .toggle-option {
    background: #28a745;
    color: white;
    box-shadow: 0 2px 4px rgba(40, 167, 69, 0.3);
}

.toggle-option:hover {
    color: #28a745;
}

.toggle-switch input[type="radio"]:checked + .toggle-option:hover {
    color: white;
}

.filters-bar {
    display: flex;
    gap: 20px;
    align-items: center;
    flex-wrap: wrap;
}

.filter-group {
    display: flex;
    align-items: center;
    gap: 10px;
}

.filter-label {
    font-weight: 600;
    color: #495057;
    font-size: 0.9rem;
}

.filter-select {
    padding: 8px 12px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 0.9rem;
    background: white;
    min-width: 150px;
}

.filter-select:focus {
    outline: none;
    border-color: #28a745;
    box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.25);
}

.no-stops {
    text-align: center;
    padding: 40px;
    color: #6c757d;
    font-style: italic;
}

@media (max-width: 1200px) {
    .days-grid {
        grid-template-columns: repeat(4, 1fr);
    }
    
    .day-header:nth-child(n+5) {
        display: none;
    }
    
    .day-stops:nth-child(n+9) {
        display: none;
    }
    
    /* Table view responsive */
    .customer-schedule-table {
        font-size: 0.85rem;
    }
    
    .table-header th:first-child {
        min-width: 150px;
    }
    
    .day-assignment {
        width: 30px;
        height: 30px;
        font-size: 0.8rem;
    }
}

@media (max-width: 768px) {
    .overview-container {
        padding: 10px;
    }
    
    .page-header {
        padding: 20px;
    }
    
    .page-header h1 {
        font-size: 1.8rem;
    }
    
    .days-grid {
        grid-template-columns: 1fr;
        margin: 10px;
    }
    
    .zone-header, .table-zone-header {
        padding: 15px 20px;
        flex-direction: column;
        gap: 10px;
        text-align: center;
    }
    
    .zone-stats {
        justify-content: center;
    }
    
    .filters-bar {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-group {
        justify-content: space-between;
    }
    
    .zone-content {
        padding: 20px;
    }
    
    .toggle-label {
        flex-direction: column;
        gap: 10px;
        align-items: stretch;
        text-align: center;
    }
    
    .toggle-switch {
        justify-content: center;
    }
    
    /* Table view mobile */
    .customer-schedule-table {
        font-size: 0.8rem;
    }
    
    .table-header th {
        padding: 10px 6px;
    }
    
    .table-header th:first-child {
        min-width: 120px;
    }
    
    .customer-info-cell {
        padding: 12px 8px;
    }
    
    .day-cell {
        padding: 6px 4px;
    }
    
    .day-assignment {
        width: 26px;
        height: 26px;
        font-size: 0.75rem;
    }
    
    .table-customer-name {
        font-size: 0.9rem;
    }
    
    .table-customer-address {
        font-size: 0.8rem;
    }
    
    .table-primary-driver {
        font-size: 0.75rem;
    }
    
    .driver-legend-grid {
        grid-template-columns: 1fr;
    }
}

/* Driver Assignment Modal */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
    backdrop-filter: blur(3px);
}

.modal-content {
    background-color: white;
    margin: 10% auto;
    padding: 30px;
    border-radius: 12px;
    width: 90%;
    max-width: 500px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    position: relative;
}

.driver-assign-modal h2 {
    margin: 0 0 20px 0;
    color: #2c3e50;
    font-size: 1.5rem;
}

.close {
    color: #aaa;
    float: right;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    position: absolute;
    right: 20px;
    top: 15px;
}

.close:hover {
    color: #000;
}

/* Message Bar */
.message-bar {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 15px 20px;
    border-radius: 8px;
    font-weight: 600;
    z-index: 1001;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    transform: translateX(100%);
    animation: slideIn 0.3s ease forwards;
}

.message-bar.success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

.message-bar.error {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

@keyframes slideIn {
    to {
        transform: translateX(0);
    }
}

.assignments-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.stop-details {
    display: flex;
    gap: 15px;
    font-size: 0.85rem;
    margin: 5px 0;
}

.route-order {
    background: rgba(255, 255, 255, 0.2);
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-weight: 600;
}

.delivery-time {
    color: white;
    font-weight: 500;
}

.order-amount {
    color: white;
    font-weight: 600;
}

.empty-zone {
    text-align: center;
    padding: 40px;
    color: #6c757d;
    font-style: italic;
    background: #f8f9fa;
    border-radius: 8px;
}

.unassigned-section {
    margin-top: 30px;
    padding-top: 20px;
    border-top: 2px solid #e9ecef;
}

.unassigned-section h4 {
    margin: 0 0 15px 0;
    color: #dc3545;
    font-size: 1.1rem;
}

.unassigned-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.unassigned-item {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.unassigned-customer {
    font-weight: 600;
    color: #495057;
}

.unassigned-address {
    color: #6c757d;
    font-size: 0.9rem;
    margin: 5px 0;
}

.unassigned-amount {
    color: #28a745;
    font-weight: 600;
    font-size: 1.1rem;
}

.customer-address-cell,
.driver-cell,
.route-order-cell,
.delivery-time-cell,
.amount-cell,
.status-cell {
    padding: 15px 12px;
    border-right: 1px solid #e9ecef;
    vertical-align: middle;
}

.driver-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.driver-name {
    font-weight: 500;
    color: #495057;
}

.unassigned {
    color: #dc3545;
    font-style: italic;
}

.status-badge {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 500;
    text-transform: uppercase;
}

.status-pending {
    background: #fff3cd;
    color: #856404;
}

.status-delivered {
    background: #d4edda;
    color: #155724;
}

.status-in-transit {
    background: #cce5ff;
    color: #004085;
}

.status-cancelled {
    background: #f8d7da;
    color: #721c24;
}

/* Make stop items clickable */
.stop-item {
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.stop-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.15);
}

.customer-row {
    cursor: pointer;
    transition: background-color 0.2s ease;
}

.customer-row:hover {
    background-color: #f8f9fa;
}

/* Inline Order Details Styles */
.order-details-container {
    margin-top: 6px;
    padding: 8px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 4px;
    border-top: 1px solid rgba(255, 255, 255, 0.2);
}

.order-details-loading {
    text-align: center;
    color: rgba(255, 255, 255, 0.8);
    font-style: italic;
    font-size: 0.8rem;
}

.order-details-content {
    color: white;
}

.product-groups {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.product-item {
    padding: 4px 6px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 3px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.75rem;
}

.product-details {
    flex: 1;
    min-width: 0;
}

.product-name {
    font-weight: 500;
    color: rgba(255, 255, 255, 0.9);
    font-size: 0.8rem;
    margin-bottom: 1px;
}

.product-meta {
    color: rgba(255, 255, 255, 0.6);
    font-size: 0.7rem;
}

.product-info {
    display: flex;
    gap: 6px;
    align-items: center;
    font-size: 0.7rem;
    white-space: nowrap;
}

.unit-price {
    color: rgba(255, 255, 255, 0.7);
}

.quantity {
    color: rgba(255, 255, 255, 0.8);
    font-weight: 500;
}

.total-price {
    color: rgba(255, 255, 255, 0.9);
    font-weight: 600;
    min-width: 45px;
    text-align: right;
}

.no-products {
    text-align: center;
    padding: 12px;
    opacity: 0.7;
    font-style: italic;
    font-size: 0.8rem;
}

/* Expandable animation */
.order-details-container.expanded {
    animation: expandDetails 0.3s ease-out;
}

@keyframes expandDetails {
    from {
        opacity: 0;
        max-height: 0;
    }
    to {
        opacity: 1;
        max-height: 500px;
    }
}
</style>

<div class="overview-container">
    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <strong>Error:</strong> <?php echo $error; ?>
        </div>
    <?php else: ?>
        
    <div class="page-header">
        <h1>🚛 Driver Overview by Driver</h1>
        <p>Daily order assignments organized by driver showing route order and zone distribution for <?= date('l, F j, Y', strtotime($selectedDate)) ?></p>
    </div>

    <!-- Date Navigation -->
    <div class="date-navigation">
        <div class="date-info">
            <h2>Assignments for <?= date('l, F j, Y', strtotime($selectedDate)) ?></h2>
            <span class="order-count">Total Orders: <?= count($results) ?></span>
        </div>
        <div class="date-controls">
            <a href="?date=<?= date('Y-m-d', strtotime($selectedDate . ' -1 day')) ?>" class="btn btn-outline">← Previous Day</a>
            <a href="?date=<?= date('Y-m-d') ?>" class="btn btn-primary">Today</a>
            <a href="?date=<?= date('Y-m-d', strtotime($selectedDate . ' +1 day')) ?>" class="btn btn-outline">Next Day →</a>
        </div>
    </div>

    <!-- Overall Statistics -->
    <div class="stats-overview">
        <?php 
        $totalZones = count($visitStats);
        $totalStops = array_sum(array_column($visitStats, 'total_stops'));
        $totalDrivers = count($driverStats);
        $totalAmount = array_sum(array_column($visitStats, 'total_amount'));
        $avgStopsPerDriver = $totalDrivers > 0 ? round($totalStops / $totalDrivers, 1) : 0;
        ?>
        
        <div class="stat-card">
            <h3>📊 Daily Statistics</h3>
            <div class="stat-grid">
                <div class="stat-item">
                    <span class="stat-number"><?php echo $totalDrivers; ?></span>
                    <span class="stat-label">Active Drivers</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?php echo $totalStops; ?></span>
                    <span class="stat-label">Total Stops</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?php echo $totalZones; ?></span>
                    <span class="stat-label">Active Zones</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number">$<?php echo number_format($totalAmount, 2); ?></span>
                    <span class="stat-label">Total Amount</span>
                </div>
            </div>
        </div>
        
        <?php foreach (array_slice($driverStats, 0, 3) as $driverId => $stats): ?>
        <div class="stat-card">
            <h3>🚛 <?php echo htmlspecialchars($stats['name']); ?></h3>
            <div class="stat-grid">
                <div class="stat-item">
                    <span class="stat-number"><?php echo $stats['zone_count']; ?></span>
                    <span class="stat-label">Zones</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?php echo $stats['total_stops']; ?></span>
                    <span class="stat-label">Daily Stops</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number">$<?php echo number_format($stats['total_amount'], 2); ?></span>
                    <span class="stat-label">Total Amount</span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- View Toggle and Filters -->
    <div class="controls-section">
        <div class="view-toggle">
            <label class="toggle-label">
                <span class="toggle-text">View Mode:</span>
                <div class="toggle-switch">
                    <input type="radio" name="viewMode" value="list" id="listView" checked>
                    <label for="listView" class="toggle-option">📋 Driver View</label>
                    <input type="radio" name="viewMode" value="table" id="tableView">
                    <label for="tableView" class="toggle-option">📊 Customer Table</label>
                </div>
            </label>
        </div>
        
        <div class="filters-bar">
            <div class="filter-group">
                <span class="filter-label">Filter by Zone:</span>
                <select class="filter-select" id="zoneFilter">
                    <option value="all">All Zones</option>
                    <?php foreach (array_keys($visitStats) as $zoneName): ?>
                    <option value="<?php echo htmlspecialchars($zoneName); ?>"><?php echo htmlspecialchars($zoneName); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <span class="filter-label">Filter by Driver:</span>
                <select class="filter-select" id="driverFilter">
                    <option value="all">All Drivers</option>
                    <?php foreach ($drivers as $driverId => $driverInfo): ?>
                    <option value="<?php echo $driverId; ?>"><?php echo htmlspecialchars($driverInfo['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- Zone Color Legend -->
    <div class="driver-legend-section">
        <h3>🗺️ Zone Color Legend</h3>
        <div class="driver-legend-grid">
            <?php foreach ($zoneColorMap as $zoneName => $zoneColor): ?>
            <div class="driver-legend-item">
                <div class="driver-color-badge" style="background-color: <?php echo $zoneColor; ?>">
                    🗺️
                </div>
                <span><?php echo htmlspecialchars($zoneName); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- List View (Default) - Now Driver-First -->
    <div class="list-view active">
        <div class="drivers-container">
            <?php if (!empty($driverDeliveries)): ?>
                <?php foreach ($driverDeliveries as $driverId => $driverData): ?>
                <div class="driver-section" data-driver-id="<?php echo $driverId; ?>">
                    <div class="driver-header">
                        <h2 class="driver-title">🚛 <?php echo htmlspecialchars($driverData['driver_name']); ?></h2>
                        <div class="driver-stats">
                            <div class="driver-stat">
                                <span class="driver-stat-number"><?php echo $driverStats[$driverId]['zone_count']; ?></span>
                                <span class="driver-stat-label">Zones</span>
                            </div>
                            <div class="driver-stat">
                                <span class="driver-stat-number"><?php echo $driverStats[$driverId]['total_stops']; ?></span>
                                <span class="driver-stat-label">Total Stops</span>
                            </div>
                            <div class="driver-stat">
                                <span class="driver-stat-number">$<?php echo number_format($driverStats[$driverId]['total_amount'], 2); ?></span>
                                <span class="driver-stat-label">Total Amount</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="driver-content">
                        <?php if (!empty($driverData['zones'])): ?>
                            <?php foreach ($driverData['zones'] as $zoneName => $zoneStops): ?>
                            <div class="zone-group" data-zone="<?php echo htmlspecialchars($zoneName); ?>">
                                <div class="zone-subheader">
                                    <h3 class="zone-subtitle">🗺️ <?php echo htmlspecialchars($zoneName); ?> (<?php echo count($zoneStops); ?> stops)</h3>
                                </div>
                                
                                <div class="stops-list">
                                    <?php foreach ($zoneStops as $stop): ?>
                                    <div class="stop-item" 
                                        style="background-color: <?php echo $stop['zone_color']; ?>; border-left-color: <?php echo $stop['zone_color']; ?>"
                                        title="Zone: <?php echo htmlspecialchars($stop['zone']); ?> | Address: <?php echo htmlspecialchars($stop['customer_address']); ?>"
                                        data-customer-id="<?php echo $stop['customer_id']; ?>"
                                        data-daily-order-id="<?php echo $stop['daily_order_id']; ?>"
                                        data-customer-name="<?php echo htmlspecialchars($stop['customer_name']); ?>"
                                        data-driver-name="<?php echo htmlspecialchars($stop['driver_name']); ?>"
                                        data-day-name="<?php echo date('l', strtotime($selectedDate)); ?>">
                                        <div class="stop-header">
                                            <div class="customer-name"><?php echo htmlspecialchars($stop['customer_name']); ?></div>
                                            <div class="driver-initial"><?php echo $stop['driver_initial']; ?></div>
                                        </div>
                                        <div class="stop-details">
                                            <div class="route-order">#<?php echo $stop['route_order'] ?: 'TBD'; ?></div>
                                            <?php if ($stop['scheduled_delivery_time']): ?>
                                            <div class="delivery-time"><?php echo date('g:i A', strtotime($stop['scheduled_delivery_time'])); ?></div>
                                            <?php endif; ?>
                                            <div class="order-amount">$<?php echo number_format($stop['total_amount'], 2); ?></div>
                                        </div>
                                        
                                        <!-- Inline Order Details -->
                                        <div class="order-details-container" style="display: none;">
                                            <div class="order-details-loading">Loading order details...</div>
                                            <div class="order-details-content" style="display: none;"></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-driver">No assignments for this driver on <?= date('l, F j, Y', strtotime($selectedDate)) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No driver assignments for this date</h3>
                    <p>No orders have been assigned to drivers for <?= date('l, F j, Y', strtotime($selectedDate)) ?></p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Unassigned Orders Section -->
        <?php if (!empty($unassignedOrders)): ?>
        <div class="unassigned-section">
            <h3>Unassigned Orders</h3>
            <?php foreach ($unassignedOrders as $zoneName => $orders): ?>
            <div class="unassigned-zone">
                <h4><?php echo htmlspecialchars($zoneName); ?> (<?php echo count($orders); ?> orders)</h4>
                <div class="unassigned-list">
                    <?php foreach ($orders as $order): ?>
                    <div class="unassigned-item">
                        <div class="unassigned-customer"><?php echo htmlspecialchars($order['customer_name']); ?></div>
                        <div class="unassigned-address"><?php echo htmlspecialchars($order['customer_address']); ?></div>
                        <div class="unassigned-amount">$<?php echo number_format($order['total_amount'], 2); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Table View -->
    <div class="table-view">
        <?php foreach ($customerTableData as $zoneName => $zoneCustomers): ?>
        <div class="customer-table" data-zone="<?php echo htmlspecialchars($zoneName); ?>">
            <div class="table-zone-header">
                <h2 class="table-zone-title">🗺️ <?php echo htmlspecialchars($zoneName); ?></h2>
                <div class="zone-stats">
                    <div class="zone-stat">
                        <span class="zone-stat-number"><?php echo count($zoneCustomers); ?></span>
                        <span class="zone-stat-label">Customers</span>
                    </div>
                    <div class="zone-stat">
                        <span class="zone-stat-number"><?php echo $visitStats[$zoneName]['unique_drivers']; ?></span>
                        <span class="zone-stat-label">Drivers</span>
                    </div>
                    <div class="zone-stat">
                        <span class="zone-stat-number"><?php echo $visitStats[$zoneName]['total_stops']; ?></span>
                        <span class="zone-stat-label">Daily Stops</span>
                    </div>
                    <div class="zone-stat">
                        <span class="zone-stat-number">$<?php echo number_format($visitStats[$zoneName]['total_amount'], 2); ?></span>
                        <span class="zone-stat-label">Total Amount</span>
                    </div>
                </div>
            </div>
            
            <table class="customer-schedule-table">
                <thead class="table-header">
                    <tr>
                        <th>Customer</th>
                        <th>Address</th>
                        <th>Driver</th>
                        <th>Route Order</th>
                        <th>Delivery Time</th>
                        <th>Order Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($zoneCustomers as $customer): ?>
                    <tr class="customer-row" 
                        data-customer-id="<?php echo $customer['customer_id']; ?>"
                        data-daily-order-id="<?php echo $customer['daily_order_id']; ?>"
                        data-zone="<?php echo htmlspecialchars($zoneName); ?>"
                        data-driver-id="<?php echo $customer['driver_id']; ?>">
                        <td class="customer-info-cell">
                            <div class="table-customer-name"><?php echo htmlspecialchars($customer['customer_name']); ?></div>
                        </td>
                        <td class="customer-address-cell">
                            <?php if ($customer['customer_address']): ?>
                            <div class="table-customer-address"><?php echo htmlspecialchars($customer['customer_address']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="driver-cell">
                            <?php if ($customer['driver_name']): ?>
                                <div class="driver-info">
                                    <span class="driver-initial" style="background-color: <?php echo $zoneColorMap[$zoneName]; ?>">
                                        <?php echo strtoupper(substr($customer['driver_name'], 0, 1)); ?>
                                    </span>
                                    <span class="driver-name"><?php echo htmlspecialchars($customer['driver_name']); ?></span>
                                </div>
                            <?php else: ?>
                                <span class="unassigned">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td class="route-order-cell">
                            <?php echo $customer['route_order'] ?: 'TBD'; ?>
                        </td>
                        <td class="delivery-time-cell">
                            <?php echo $customer['scheduled_delivery_time'] ?: 'TBD'; ?>
                        </td>
                        <td class="amount-cell">
                            $<?php echo number_format($customer['total_amount'], 2); ?>
                        </td>
                        <td class="status-cell">
                            <span class="status-badge status-<?php echo $customer['delivery_status'] ?: 'pending'; ?>">
                                <?php echo ucfirst($customer['delivery_status'] ?: 'pending'); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
    </div>
    
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const zoneFilter = document.getElementById('zoneFilter');
    const driverFilter = document.getElementById('driverFilter');
    const listViewRadio = document.getElementById('listView');
    const tableViewRadio = document.getElementById('tableView');
    const listViewContainer = document.querySelector('.list-view');
    const tableViewContainer = document.querySelector('.table-view');
    
    // View toggle functionality
    function toggleView() {
        if (tableViewRadio.checked) {
            listViewContainer.classList.remove('active');
            tableViewContainer.classList.add('active');
            filterTableView();
        } else {
            tableViewContainer.classList.remove('active');
            listViewContainer.classList.add('active');
            filterListView();
        }
    }
    
    // Filter function for list view
    function filterListView() {
        const selectedZone = zoneFilter.value;
        const selectedDriver = driverFilter.value;
        
        document.querySelectorAll('.list-view .driver-section').forEach(driverSection => {
            let showDriver = true;
            
            // Filter by zone
            if (selectedZone !== 'all') {
                const driverId = driverSection.dataset.driverId;
                if (driverId !== selectedZone) {
                    showDriver = false;
                }
            }
            
            if (showDriver) {
                // Filter stops by driver within each driver
                const zoneGroups = driverSection.querySelectorAll('.zone-group');
                let hasVisibleContent = false;
                
                zoneGroups.forEach(zoneGroup => {
                    const zoneName = zoneGroup.dataset.zone;
                    let showZone = true;
                    
                    // Filter by driver
                    if (selectedDriver !== 'all') {
                        const zoneDriverId = zoneGroup.dataset.driverId;
                        if (zoneDriverId !== selectedDriver) {
                            showZone = false;
                        }
                    }
                    
                    zoneGroup.style.display = showZone ? 'block' : 'none';
                    if (showZone) hasVisibleContent = true;
                });
                
                // Hide driver if no content is visible
                if (!hasVisibleContent && (selectedZone !== 'all' || selectedDriver !== 'all')) {
                    showDriver = false;
                }
            }
            
            driverSection.style.display = showDriver ? 'block' : 'none';
        });
    }
    
    // Filter function for table view
    function filterTableView() {
        const selectedZone = zoneFilter.value;
        const selectedDriver = driverFilter.value;
        
        document.querySelectorAll('.customer-table').forEach(table => {
            let showTable = true;
            
            // Filter by zone
            if (selectedZone !== 'all') {
                const zoneName = table.dataset.zone;
                if (zoneName !== selectedZone) {
                    showTable = false;
                }
            }
            
            table.style.display = showTable ? 'block' : 'none';
            
            if (showTable) {
                // Filter customer rows and day cells
                const customerRows = table.querySelectorAll('.customer-row');
                
                customerRows.forEach(row => {
                    let showRow = true;
                    
                    // Filter by driver (check if customer has any assignments with this driver)
                    if (selectedDriver !== 'all') {
                        const dayAssignments = row.querySelectorAll('.day-assignment');
                        let hasDriverAssignment = false;
                        
                        dayAssignments.forEach(assignment => {
                            if (assignment.dataset.driverId === selectedDriver) {
                                hasDriverAssignment = true;
                            }
                        });
                        
                        if (!hasDriverAssignment) {
                            showRow = false;
                        }
                    }
                    
                    row.style.display = showRow ? 'table-row' : 'none';
                    
                    if (showRow) {
                        // Filter day cells within each row
                        const dayCells = row.querySelectorAll('.day-cell');
                        dayCells.forEach(cell => {
                            const day = cell.dataset.day;
                            const showDayCell = selectedZone === 'all' || day === selectedZone;
                            cell.style.display = showDayCell ? 'table-cell' : 'none';
                        });
                    }
                });
            }
        });
    }
    
    // Main filter function that calls appropriate view filter
    function applyFilters() {
        if (tableViewRadio.checked) {
            filterTableView();
        } else {
            filterListView();
        }
    }
    
    // Add event listeners
    listViewRadio.addEventListener('change', toggleView);
    tableViewRadio.addEventListener('change', toggleView);
    zoneFilter.addEventListener('change', applyFilters);
    driverFilter.addEventListener('change', applyFilters);
    
    // Add click handlers for driver legend items to filter by driver
    document.querySelectorAll('.driver-legend-item').forEach(item => {
        item.addEventListener('click', function() {
            const driverName = this.querySelector('span').textContent;
            const driverOption = Array.from(driverFilter.options).find(option => option.text === driverName);
            if (driverOption) {
                driverFilter.value = driverOption.value;
                applyFilters();
            }
        });
    });
    
    // Inline Order Details functionality
    function addCustomerClickHandlers() {
        // List view - stop items
        document.querySelectorAll('.stop-item').forEach(item => {
            item.addEventListener('click', function(e) {
                e.stopPropagation();
                const customerId = this.dataset.customerId;
                const customerName = this.querySelector('.customer-name').textContent;
                console.log('Stop item clicked:', { customerId, customerName });
                toggleOrderDetails(this, customerId, customerName);
            });
        });
        
        // Table view - customer rows
        document.querySelectorAll('.customer-row').forEach(row => {
            row.addEventListener('click', function(e) {
                e.stopPropagation();
                const customerId = this.dataset.customerId;
                const customerName = this.querySelector('.table-customer-name').textContent;
                console.log('Customer row clicked:', { customerId, customerName });
                // For table view, we'll add inline details if needed
            });
        });
    }
    
    function toggleOrderDetails(stopItem, customerId, customerName) {
        const detailsContainer = stopItem.querySelector('.order-details-container');
        const loadingDiv = stopItem.querySelector('.order-details-loading');
        const contentDiv = stopItem.querySelector('.order-details-content');
        
        // If already expanded, collapse
        if (detailsContainer.style.display === 'block') {
            detailsContainer.style.display = 'none';
            detailsContainer.classList.remove('expanded');
            return;
        }
        
        // Show container and loading
        detailsContainer.style.display = 'block';
        loadingDiv.style.display = 'block';
        contentDiv.style.display = 'none';
        detailsContainer.classList.add('expanded');
        
        // Load order details via AJAX
        const requestData = `customer_id=${customerId}&date=${encodeURIComponent('<?php echo $selectedDate; ?>')}`;
        console.log('Sending request data:', requestData);
        
        fetch('get_customer_order_details.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: requestData
        })
        .then(response => {
            console.log('Response status:', response.status);
            return response.json();
        })
        .then(data => {
            console.log('Response data:', data);
            loadingDiv.style.display = 'none';
            contentDiv.style.display = 'block';
            
            if (data.success) {
                displayInlineOrderDetails(contentDiv, data.order, data.products);
            } else {
                contentDiv.innerHTML = '<div class="no-products">Error loading order details: ' + data.message + '</div>';
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            loadingDiv.style.display = 'none';
            contentDiv.style.display = 'block';
            contentDiv.innerHTML = '<div class="no-products">Error loading order details. Please try again.</div>';
        });
    }
    
    function displayInlineOrderDetails(container, order, products) {
        console.log('Displaying inline order details:', { order, products });
        
        // Group products by product line and dough type
        const groupedProducts = {};
        
        if (products && products.length > 0) {
            products.forEach(product => {
                const productLine = product.product_line || 'Other';
                const doughType = product.dough_type || 'Standard';
                
                if (!groupedProducts[productLine]) {
                    groupedProducts[productLine] = {};
                }
                if (!groupedProducts[productLine][doughType]) {
                    groupedProducts[productLine][doughType] = [];
                }
                
                groupedProducts[productLine][doughType].push(product);
            });
        }
        
        // Build HTML for grouped products
        let productsHtml = '';
        
        if (Object.keys(groupedProducts).length === 0) {
            productsHtml = '<div class="no-products">No products found for this order.</div>';
        } else {
            productsHtml = '<div class="product-groups">';
            Object.keys(groupedProducts).forEach(productLine => {
                Object.keys(groupedProducts[productLine]).forEach(doughType => {
                    const productsInGroup = groupedProducts[productLine][doughType];
                    
                    productsInGroup.forEach(product => {
                        const totalPrice = product.line_total ? parseFloat(product.line_total).toFixed(2) : 
                                         (product.quantity * product.unit_price).toFixed(2);
                        productsHtml += `
                            <div class="product-item">
                                <div class="product-details">
                                    <div class="product-name">${product.product_name || 'Unknown Product'}</div>
                                    <div class="product-meta">${productLine} • ${doughType}</div>
                                </div>
                                <div class="product-info">
                                    <span class="unit-price">$${parseFloat(product.unit_price || 0).toFixed(2)}</span>
                                    <span class="quantity">×${product.quantity || 0}</span>
                                    <span class="total-price">$${totalPrice}</span>
                                </div>
                            </div>
                        `;
                    });
                });
            });
            productsHtml += '</div>';
        }
        
        container.innerHTML = productsHtml;
    }
    
    // Initialize click handlers
    addCustomerClickHandlers();
    
    // Re-add click handlers after view changes
    listViewRadio.addEventListener('change', function() {
        setTimeout(addCustomerClickHandlers, 100);
    });
    tableViewRadio.addEventListener('change', function() {
        setTimeout(addCustomerClickHandlers, 100);
    });
});
</script>

<?php require_once 'includes/footer.php'; ?> 
