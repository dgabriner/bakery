<?php
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';

$page_title = bakery_t('page.customer_schedule');

// Handle AJAX zone updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_zone') {
    try {
        $customerId = $_POST['customer_id'];
        $newZone = empty($_POST['zone']) ? null : $_POST['zone'];
        
        $stmt = $db->prepare("UPDATE customers SET zone = ? WHERE id = ?");
        $stmt->execute([$newZone, $customerId]);
        
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Handle AJAX customer removal (only when no route assignments)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_customer') {
    header('Content-Type: application/json');
    try {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        if ($customerId <= 0) {
            throw new Exception('Invalid customer ID');
        }

        $stmt = $db->prepare('SELECT COUNT(*) FROM standing_routes WHERE customer_id = ?');
        $stmt->execute([$customerId]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new Exception('Cannot remove customer with active route assignments. Remove all driver assignments first.');
        }

        $stmt = $db->prepare('SELECT COUNT(*) FROM daily_orders WHERE customer_id = ?');
        $stmt->execute([$customerId]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new Exception('This customer has order history and cannot be removed. Use the Customers page to manage them.');
        }

        $stmt = $db->prepare('DELETE FROM customers WHERE id = ?');
        $stmt->execute([$customerId]);
        if ($stmt->rowCount() === 0) {
            throw new Exception('Customer not found');
        }

        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Handle AJAX driver assignment updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_driver') {
    try {
        $customerId = $_POST['customer_id'];
        // Use the application's canonical weekday numbering: 1=Mon ... 7=Sun.
        // Accept legacy Sunday=0 submissions while they are still in circulation.
        $dayOfWeek = (int)$_POST['day_of_week'];
        if ($dayOfWeek === 0) {
            $dayOfWeek = 7;
        }
        if ($dayOfWeek < 1 || $dayOfWeek > 7) {
            throw new Exception('Invalid day of week');
        }
        $driverId = empty($_POST['driver_id']) ? null : $_POST['driver_id'];
        
        if ($driverId === null) {
            // Remove the standing route entry
            if ($dayOfWeek === 7) {
                // Remove both canonical Sunday=7 and legacy Sunday=0 rows.
                $stmt = $db->prepare("DELETE FROM standing_routes WHERE customer_id = ? AND day_of_week IN (0, 7)");
                $stmt->execute([$customerId]);
            } else {
                $stmt = $db->prepare("DELETE FROM standing_routes WHERE customer_id = ? AND day_of_week = ?");
                $stmt->execute([$customerId, $dayOfWeek]);
            }
        } else {
            // Clean up a possible legacy Sunday row before saving canonical Sunday=7.
            if ($dayOfWeek === 7) {
                $stmt = $db->prepare("DELETE FROM standing_routes WHERE customer_id = ? AND day_of_week = 0");
                $stmt->execute([$customerId]);
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

// Define available zones
$zones = [];
try {
    // Try to get zones from database first
    $stmt = $db->query("SELECT name FROM zones ORDER BY name");
    $zonesFromDB = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($zonesFromDB)) {
        $zones = $zonesFromDB;
    } else {
        // Fallback to hardcoded zones if database is empty
        $zones = [
            'Centro',
            'Mission', 
            'Ruta Sour Flour',
            'Daly City/San Mateo',
            'North Bay',
            'East Bay'
        ];
    }
} catch (Exception $e) {
    // If zones table doesn't exist yet, use hardcoded zones
    $zones = [
        'Centro',
        'Mission', 
        'Ruta Sour Flour',
        'Daly City/San Mateo',
        'North Bay',
        'East Bay'
    ];
}

// Get available drivers with color assignments
$drivers = [];
$driverColors = [
    '#007bff', // Blue
    '#28a745', // Green  
    '#dc3545', // Red
    '#fd7e14', // Orange
    '#6f42c1', // Purple
    '#20c997', // Teal
    '#ffc107', // Yellow
    '#e83e8c', // Pink
    '#6c757d', // Gray
    '#17a2b8', // Cyan
    '#343a40', // Dark
    '#007bff'  // Back to blue (cycles)
];

try {
    foreach (bakery_get_drivers($db) as $index => $driver) {
        $drivers[$driver['id']] = [
            'name' => $driver['name'],
            'color' => $driverColors[$index % count($driverColors)]
        ];
    }
} catch (Exception $e) {
    $error = 'Error loading drivers: ' . htmlspecialchars($e->getMessage());
}

// Get customer delivery schedule data grouped by zone with driver information
$customerSchedulesByZone = [];

try {
    $stmt = $db->query("
        SELECT 
            c.id,
            c.name as customer_name,
            c.address,
            c.zone,
            sr.day_of_week,
            sr.driver_id,
            d.name as driver_name
        FROM customers c
        LEFT JOIN standing_routes sr ON c.id = sr.customer_id
        LEFT JOIN drivers d ON sr.driver_id = d.id
        ORDER BY 
            CASE 
                WHEN c.zone IS NULL THEN 'ZZZ_No Zone'
                ELSE c.zone
            END,
            c.name
    ");
    
    $results = $stmt->fetchAll();
    
    // Process the data to create a zone-grouped structure with driver assignments
    $customerData = [];
    foreach ($results as $row) {
        $customerId = $row['id'];
        
        if (!isset($customerData[$customerId])) {
            $customerData[$customerId] = [
                'id' => $row['id'],
                'name' => $row['customer_name'],
                'address' => $row['address'],
                'zone' => $row['zone'] ?: 'No Zone',
                'delivery_days' => []
            ];
        }
        
        if ($row['day_of_week'] !== null) {
            // Legacy data may contain Sunday as 0; display it as canonical Sunday=7.
            $dayOfWeek = (int)$row['day_of_week'];
            if ($dayOfWeek === 0) {
                $dayOfWeek = 7;
            }
            $driverColor = isset($drivers[$row['driver_id']]) ? $drivers[$row['driver_id']]['color'] : '#6c757d';
            $customerData[$customerId]['delivery_days'][$dayOfWeek] = [
                'driver_id' => $row['driver_id'],
                'driver_name' => $row['driver_name'],
                'driver_initial' => $row['driver_name'] ? strtoupper(substr($row['driver_name'], 0, 1)) : 'X',
                'driver_color' => $driverColor
            ];
        }
    }
    
    // Group by zone
    foreach ($customerData as $customer) {
        $zone = $customer['zone'];
        
        if (!isset($customerSchedulesByZone[$zone])) {
            $customerSchedulesByZone[$zone] = [];
        }
        
        $customerSchedulesByZone[$zone][] = $customer;
    }
    
} catch (Exception $e) {
    $error = 'Error loading customer schedules: ' . htmlspecialchars($e->getMessage());
}

$days = [
    1 => 'Mon',
    2 => 'Tue',
    3 => 'Wed',
    4 => 'Thu',
    5 => 'Fri',
    6 => 'Sat',
    7 => 'Sun'
];

$daysFull = [
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
    7 => 'Sunday'
];

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>

<style>
.schedule-container {
    max-width: 100%;
    margin: 0 auto;
    padding: 10px;
}

.page-header {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    text-align: center;
}

.page-header h1 {
    margin: 0 0 10px 0;
    color: #2c3e50;
    font-size: 1.8rem;
}

.page-header p {
    margin: 0;
    color: #6c757d;
    font-size: 1rem;
}

.zone-section {
    background: white;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border: 1px solid #dee2e6;
    margin-bottom: 25px;
}

.zone-header {
    padding: 15px 20px;
    font-weight: 600;
    font-size: 1.1rem;
    color: white;
    display: flex;
    align-items: center;
    gap: 10px;
}

.zone-centro {
    background: #007bff;
}

.zone-mission {
    background: #dc3545;
}

.zone-ruta-sour-flour {
    background: #28a745;
}

.zone-daly-city-san-mateo {
    background: #fd7e14;
}

.zone-north-bay {
    background: #6f42c1;
}

.zone-east-bay {
    background: #20c997;
}

.zone-no-zone {
    background: #6c757d;
}

.schedule-table {
    background: white;
}

.table-header {
    background: rgba(0,0,0,0.05);
    color: #495057;
    display: grid;
    grid-template-columns: 2fr repeat(7, 1fr);
    padding: 0;
    font-weight: 600;
    font-size: 0.85rem;
    border-bottom: 1px solid #dee2e6;
}

.header-cell {
    padding: 12px 10px;
    text-align: center;
    border-right: 1px solid #dee2e6;
}

.header-cell:first-child {
    text-align: left;
    padding-left: 20px;
}

.header-cell:last-child {
    border-right: none;
}

.customer-row {
    display: grid;
    grid-template-columns: 2fr repeat(7, 1fr);
    border-bottom: 1px solid #f1f3f4;
    transition: background-color 0.2s;
}

.customer-row:hover {
    background-color: #f8f9fa;
}

.customer-row.is-highlighted {
    outline: 2px solid #3182ce;
    outline-offset: -2px;
    background-color: #ebf8ff;
}

.customer-name .customer-hub-link {
    color: inherit;
    font-weight: inherit;
    text-decoration: none;
}

.customer-name .customer-hub-link:hover {
    color: #2b6cb0;
    text-decoration: underline;
}

.customer-row:last-child {
    border-bottom: none;
}

.customer-info {
    padding: 15px 20px;
    border-right: 1px solid #f1f3f4;
}

.customer-name-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 4px;
}

.customer-name {
    font-weight: 600;
    color: #2c3e50;
    font-size: 0.95rem;
    flex: 1;
    min-width: 0;
}

.btn-remove-customer {
    flex-shrink: 0;
    background: none;
    border: 1px solid transparent;
    border-radius: 4px;
    padding: 2px 6px;
    font-size: 0.75rem;
    color: #dc3545;
    cursor: pointer;
    opacity: 0.6;
    transition: all 0.2s ease;
    line-height: 1.2;
}

.customer-row:hover .btn-remove-customer,
.btn-remove-customer:focus {
    opacity: 1;
}

.btn-remove-customer:hover {
    background: #fff5f5;
    border-color: #f5c6cb;
}

.customer-address {
    color: #6c757d;
    font-size: 0.8rem;
    line-height: 1.3;
}

.day-cell {
    padding: 12px 8px;
    text-align: center;
    border-right: 1px solid #f1f3f4;
    display: flex;
    align-items: center;
    justify-content: center;
}

.day-cell:last-child {
    border-right: none;
}

.day-indicator {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    font-weight: 700;
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.day-indicator.has-delivery {
    color: white;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 900;
    font-size: 1.1rem;
    text-shadow: 0 1px 2px rgba(0,0,0,0.3);
    border: 2px solid #ffffff;
    /* Background color will be set inline via PHP for each driver */
}

.day-indicator.no-delivery {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    color: #6c757d;
    cursor: pointer;
    border: 2px dashed #dee2e6;
    font-size: 1.2rem;
    font-weight: 300;
}

.day-indicator.no-delivery:hover {
    background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
    border-color: #28a745;
    color: #28a745;
    transform: scale(1.15);
    box-shadow: 0 4px 12px rgba(40,167,69,0.3);
}

.driver-initial {
    font-weight: 900;
    font-size: 1.1rem;
    text-shadow: 0 1px 2px rgba(0,0,0,0.3);
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

.add-delivery {
    font-size: 1.4rem;
    font-weight: 300;
    opacity: 0.7;
}

/* Mobile optimizations */
@media (max-width: 768px) {
    .schedule-container {
        padding: 5px;
    }
    
    .page-header {
        padding: 15px;
    }
    
    .page-header h1 {
        font-size: 1.5rem;
    }
    
    .table-header {
        grid-template-columns: 1.5fr repeat(7, 1fr);
        font-size: 0.8rem;
    }
    
    .customer-row {
        grid-template-columns: 1.5fr repeat(7, 1fr);
    }
    
    .header-cell {
        padding: 12px 8px;
    }
    
    .header-cell:first-child {
        padding-left: 15px;
    }
    
    .customer-info {
        padding: 12px 15px;
    }
    
    .customer-name {
        font-size: 0.9rem;
    }
    
    .customer-address {
        font-size: 0.75rem;
    }
    
    .day-cell {
        padding: 12px 8px;
    }
    
    .day-indicator {
        width: 28px;
        height: 28px;
        font-size: 0.8rem;
    }
    
    .driver-initial {
        font-size: 0.9rem;
    }
    
    .add-delivery {
        font-size: 1.1rem;
    }
    
    .summary-cards {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }
    
    .zone-header {
        padding: 12px 15px;
        font-size: 1rem;
    }
    
    .zone-stats {
        padding: 12px 15px;
    }
}

@media (max-width: 480px) {
    .table-header {
        grid-template-columns: 1fr repeat(7, 1fr);
        font-size: 0.75rem;
    }
    
    .customer-row {
        grid-template-columns: 1fr repeat(7, 1fr);
    }
    
    .header-cell {
        padding: 10px 5px;
    }
    
    .header-cell:first-child {
        padding-left: 10px;
    }
    
    .customer-info {
        padding: 10px;
    }
    
    .customer-name {
        font-size: 0.85rem;
        margin-bottom: 2px;
    }
    
    .customer-address {
        font-size: 0.7rem;
        display: none; /* Hide address on very small screens */
    }
    
    .day-cell {
        padding: 10px 5px;
    }
    
    .day-indicator {
        width: 24px;
        height: 24px;
        font-size: 0.65rem;
    }
    
    .day-indicator.has-delivery {
        font-size: 0.7rem;
    }
    
    .day-indicator.no-delivery {
        font-size: 0.9rem;
    }
    
    .summary-cards {
        flex-direction: column;
        align-items: center;
        gap: 6px;
    }
    
    .summary-card {
        width: 100%;
        max-width: 200px;
    }
    
    .zone-buttons {
        grid-template-columns: 1fr;
    }
}

/* Clickable customer rows */
.clickable-customer {
    cursor: pointer;
    transition: all 0.3s ease;
}

.clickable-customer:hover {
    background-color: #e3f2fd !important;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,123,255,0.15);
}

.zone-edit-hint {
    color: #007bff;
    font-size: 0.7rem;
    font-style: italic;
    margin-top: 2px;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.clickable-customer:hover .zone-edit-hint {
    opacity: 1;
}

/* Zone Edit Modal */
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

.zone-edit-modal h2 {
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

#editingCustomerInfo {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
}

#editingCustomerInfo p {
    margin: 5px 0;
    color: #495057;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    color: #495057;
}

.zone-select {
    width: 100%;
    padding: 12px;
    border: 2px solid #ddd;
    border-radius: 6px;
    font-size: 1rem;
    background: white;
    transition: border-color 0.3s ease;
}

.zone-select:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
}

.modal-actions {
    display: flex;
    gap: 15px;
    justify-content: flex-end;
    margin-top: 25px;
    padding-top: 20px;
    border-top: 1px solid #dee2e6;
}

.btn-primary, .btn-secondary {
    padding: 12px 24px;
    border: none;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 14px;
}

.btn-primary {
    background: #007bff;
    color: white;
}

.btn-primary:hover {
    background: #0056b3;
    transform: translateY(-2px);
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #545b62;
    transform: translateY(-2px);
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

/* Enhanced hover effects for color-coded drivers */
.day-indicator.has-delivery:hover {
    transform: scale(1.15);
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    border-color: #ffffff;
    filter: brightness(1.1);
}

.clickable-day {
    position: relative;
}

.clickable-day::after {
    content: '';
    position: absolute;
    top: -5px;
    left: -5px;
    right: -5px;
    bottom: -5px;
    border-radius: 8px;
    border: 2px solid transparent;
    transition: border-color 0.3s ease;
    pointer-events: none;
}

.clickable-day:hover::after {
    border-color: #007bff;
}

/* Update customer row hover to not conflict with day cells */
.clickable-customer:hover .clickable-day::after {
    border-color: transparent;
}

.customer-info {
    padding: 15px 20px;
    border-right: 1px solid #f1f3f4;
}

/* Driver Color Legend */
.driver-color-legend {
    margin-top: 20px;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #dee2e6;
}

.driver-color-legend h4 {
    margin: 0 0 12px 0;
    font-size: 0.9rem;
    color: #495057;
    font-weight: 600;
}

.color-legend-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 10px;
}

.color-legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
}

.color-preview {
    width: 24px;
    height: 24px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 900;
    font-size: 0.8rem;
    text-shadow: 0 1px 2px rgba(0,0,0,0.3);
    border: 2px solid white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.driver-name {
    font-size: 0.8rem;
    color: #495057;
    font-weight: 500;
}

.summary-cards {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 15px;
    justify-content: center;
}

.summary-card {
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 10px 15px;
    text-align: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    min-width: 120px;
    flex: 0 0 auto;
}

.summary-card h3 {
    margin: 0 0 4px 0;
    font-size: 1.2rem;
    color: #007bff;
    font-weight: 700;
}

.summary-card p {
    margin: 0;
    color: #6c757d;
    font-size: 0.8rem;
    font-weight: 500;
}

/* Zone Buttons */
.zone-buttons {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 10px;
    margin-top: 10px;
}

.zone-button {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 15px;
    border: 2px solid #dee2e6;
    border-radius: 8px;
    background: white;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 0.9rem;
}

.zone-button:hover {
    border-color: #007bff;
    background: #f8f9fa;
    transform: translateY(-2px);
    box-shadow: 0 2px 8px rgba(0,123,255,0.15);
}

.zone-button.selected {
    border-color: #007bff;
    background: #e3f2fd;
    color: #0056b3;
}

.zone-icon {
    font-size: 1.1rem;
}

.zone-name {
    font-weight: 500;
}

/* Mobile responsive updates */
@media (max-width: 768px) {
    .summary-cards {
        gap: 8px;
        margin-bottom: 12px;
    }
    
    .summary-card {
        min-width: 100px;
        padding: 8px 12px;
    }
    
    .summary-card h3 {
        font-size: 1rem;
    }
    
    .summary-card p {
        font-size: 0.75rem;
    }
    
    .zone-buttons {
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    
    .zone-button {
        padding: 10px 12px;
        font-size: 0.8rem;
    }
}

/* Driver Assignment Modal */
.driver-assign-modal h2 {
    margin: 0 0 20px 0;
    color: #2c3e50;
    font-size: 1.5rem;
}

#assignmentInfo {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
}

#assignmentInfo p {
    margin: 5px 0;
    color: #495057;
}

.driver-select {
    width: 100%;
    padding: 12px;
    border: 2px solid #ddd;
    border-radius: 6px;
    font-size: 1rem;
    background: white;
    transition: border-color 0.3s ease;
}

.driver-select:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
}

/* Zone Edit Modal */
.zone-edit-modal h2 {
    margin: 0 0 20px 0;
    color: #2c3e50;
    font-size: 1.5rem;
}

#zoneEditInfo {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
}

#zoneEditInfo p {
    margin: 5px 0;
    color: #495057;
}

.zone-selection h4 {
    margin: 0 0 15px 0;
    color: #495057;
    font-size: 1rem;
}

.zone-options {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 10px;
    margin-top: 10px;
}

.zone-option {
    background: white;
    border: 2px solid #dee2e6;
    border-radius: 8px;
    padding: 15px;
    cursor: pointer;
    transition: all 0.3s ease;
    text-align: center;
    min-height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.zone-option:hover {
    border-color: #007bff;
    background-color: #f8f9ff;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 123, 255, 0.15);
}

.zone-option.no-zone {
    border-color: #dc3545;
    color: #dc3545;
}

.zone-option.no-zone:hover {
    border-color: #c82333;
    background-color: #fff5f5;
    color: #c82333;
    box-shadow: 0 4px 8px rgba(220, 53, 69, 0.15);
}

.zone-option.selected {
    border-color: #28a745;
    background-color: #f8fff9;
    color: #155724;
    box-shadow: 0 4px 8px rgba(40, 167, 69, 0.15);
}

.zone-name {
    font-weight: 600;
    font-size: 0.95rem;
}

.current-zone {
    color: #6c757d;
    font-weight: normal;
}

/* Driver Selection Styles */
.driver-selection h4 {
    margin: 0 0 15px 0;
    color: #495057;
    font-size: 1rem;
}

.driver-icons-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 15px;
    margin-top: 10px;
}

.driver-icon-option {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 15px;
    border: 2px solid #dee2e6;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.3s ease;
    background: white;
}

.driver-icon-option:hover {
    border-color: #007bff;
    background-color: #f8f9ff;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 123, 255, 0.15);
}

.driver-icon-option.selected {
    border-color: #28a745;
    background-color: #f8fff9;
    box-shadow: 0 4px 8px rgba(40, 167, 69, 0.15);
}

.driver-icon-option.no-driver {
    border-color: #dc3545;
    color: #dc3545;
}

.driver-icon-option.no-driver:hover {
    border-color: #c82333;
    background-color: #fff5f5;
    color: #c82333;
    box-shadow: 0 4px 8px rgba(220, 53, 69, 0.15);
}

.driver-icon-preview {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: bold;
    font-size: 18px;
    background: #6c757d;
}

.driver-icon-initial {
    color: white;
    font-weight: bold;
}

.driver-icon-name {
    font-size: 0.9rem;
    font-weight: 600;
    text-align: center;
}

/* Day Filtering Styles */
.day-filter-btn {
    cursor: pointer;
    transition: all 0.3s ease;
    user-select: none;
    position: relative;
}

.day-filter-btn:hover {
    background-color: #e3f2fd !important;
    color: #1976d2;
    transform: translateY(-1px);
}

.day-filter-btn.active {
    background-color: #2196f3 !important;
    color: white;
    font-weight: bold;
    box-shadow: 0 2px 4px rgba(33, 150, 243, 0.3);
}

.day-filter-btn.active::after {
    content: '';
    position: absolute;
    bottom: -1px;
    left: 0;
    right: 0;
    height: 3px;
    background: #1976d2;
}

.filter-controls {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 20px;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #dee2e6;
}

.filter-status {
    color: #495057;
    font-weight: 500;
    flex: 1;
}

.filter-status.active {
    color: #2196f3;
}

.clear-filter-btn {
    background: #6c757d;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.3s ease;
}

.clear-filter-btn:hover {
    background: #5a6268;
    transform: translateY(-1px);
}

.customer-row.filtered-hidden {
    display: none;
}

.zone-section.no-visible-customers {
    opacity: 0.5;
}

.zone-section.no-visible-customers .zone-header {
    background: #6c757d !important;
}

/* Enhanced day indicators when filtering */
.day-indicator.highlight-day {
    box-shadow: 0 0 0 2px #2196f3;
    transform: scale(1.1);
}

/* Dynamic grid layout for day filtering */
.table-header.filtered,
.customer-row.filtered {
    grid-template-columns: 2fr 1fr !important;
}

/* Ensure proper spacing when columns are hidden */
.day-cell[style*="display: none"] {
    display: none !important;
}

.header-cell[style*="display: none"] {
    display: none !important;
}

/* Enhanced filter status */
.filter-status.active {
    color: #2196f3;
    font-weight: 600;
}

/* Mobile responsive updates for filtered view */
@media (max-width: 768px) {
    .table-header.filtered,
    .customer-row.filtered {
        grid-template-columns: 1.5fr 1fr !important;
    }
}

@media (max-width: 480px) {
    .table-header.filtered,
    .customer-row.filtered {
        grid-template-columns: 1fr 1fr !important;
    }
}
</style>

<div class="schedule-container">
    <?php if (isset($error)): ?>
        <div class="error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <!-- Page Header -->
    <div class="page-header">
        <h1>📅 Customer Delivery Schedule by Zone</h1>
        <p>Overview of delivery schedules organized by delivery zones</p>
    </div>
    
    <!-- Summary Cards -->
    <?php if (!empty($customerSchedulesByZone)): ?>
        <?php
        $totalCustomers = 0;
        $customersWithDeliveries = 0;
        $totalDeliveryDays = 0;
        
        foreach ($customerSchedulesByZone as $zoneCustomers) {
            foreach ($zoneCustomers as $customer) {
                $totalCustomers++;
                if (!empty($customer['delivery_days'])) {
                    $customersWithDeliveries++;
                    $totalDeliveryDays += count($customer['delivery_days']);
                }
            }
        }
        
        $avgDeliveryDays = $customersWithDeliveries > 0 ? round($totalDeliveryDays / $customersWithDeliveries, 1) : 0;
        ?>
        
        <div class="summary-cards">
            <div class="summary-card">
                <h3><?php echo count($customerSchedulesByZone); ?></h3>
                <p>Delivery Zones</p>
            </div>
            <div class="summary-card">
                <h3><?php echo $totalCustomers; ?></h3>
                <p>Total Customers</p>
            </div>
            <div class="summary-card">
                <h3><?php echo $customersWithDeliveries; ?></h3>
                <p>With Deliveries</p>
            </div>
            <div class="summary-card">
                <h3><?php echo $avgDeliveryDays; ?></h3>
                <p>Avg Days/Customer</p>
            </div>
        </div>
        
        <!-- Filter Controls -->
        <div class="filter-controls" style="display: none;" id="filterControls">
            <div class="filter-status" id="filterStatus">
                Showing all customers
            </div>
            <button class="clear-filter-btn" onclick="clearDayFilter()">
                Show All Days
            </button>
        </div>
    <?php endif; ?>
    
    <!-- Schedule by Zone -->
    <?php if (empty($customerSchedulesByZone)): ?>
        <div class="no-data">
            <h3>No customer data found</h3>
            <p>There are no customers in the system.</p>
        </div>
    <?php else: ?>
        <?php foreach ($customerSchedulesByZone as $zoneName => $customers): ?>
            <div class="zone-section">
                <!-- Zone Header -->
                <div class="zone-header zone-<?php echo strtolower(str_replace([' ', '/'], ['-', '-'], $zoneName)); ?>">
                    <span>🗺️</span>
                    <span><?php echo htmlspecialchars($zoneName); ?></span>
                    <span>(<?php echo count($customers); ?> customers)</span>
                </div>
                
                <!-- Zone Schedule Table -->
                <div class="schedule-table">
                    <!-- Table Header -->
                    <div class="table-header">
                        <div class="header-cell">Customer</div>
                        <?php foreach ($days as $dayNum => $dayName): ?>
                            <div class="header-cell day-filter-btn" data-day="<?php echo $dayNum; ?>" title="Click to filter by <?php echo $daysFull[$dayNum]; ?>">
                                <?php echo $dayName; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Customer Rows -->
                    <?php foreach ($customers as $customer): ?>
                        <div class="customer-row clickable-customer" 
                             data-customer-id="<?php echo $customer['id']; ?>"
                             data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                             data-current-zone="<?php echo htmlspecialchars($zoneName === 'No Zone' ? '' : $zoneName); ?>">
                            <div class="customer-info">
                                <div class="customer-name-row">
                                    <div class="customer-name">
                                        <a class="customer-hub-link" href="customer_record.php?customer_id=<?php echo (int)$customer['id']; ?>" onclick="event.stopPropagation()"><?php echo htmlspecialchars($customer['name']); ?></a>
                                    </div>
                                    <?php if (empty($customer['delivery_days'])): ?>
                                        <button type="button"
                                                class="btn-remove-customer"
                                                title="Remove customer (no route assignments)"
                                                data-customer-id="<?php echo $customer['id']; ?>"
                                                data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                                onclick="confirmRemoveCustomer(event, this)">
                                            Remove
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <div class="customer-address"><?php echo htmlspecialchars($customer['address']); ?></div>
                                <div class="zone-edit-hint">Click to change zone</div>
                            </div>
                            <?php foreach ($days as $dayNum => $dayName): ?>
                                <div class="day-cell" data-day="<?php echo $dayNum; ?>">
                                    <?php 
                                    $hasDelivery = isset($customer['delivery_days'][$dayNum]);
                                    if ($hasDelivery) {
                                        $dayInfo = $customer['delivery_days'][$dayNum];
                                        $driverInitial = $dayInfo['driver_initial'];
                                        $driverName = $dayInfo['driver_name'];
                                        $driverId = $dayInfo['driver_id'];
                                        $driverColor = $dayInfo['driver_color'];
                                    } else {
                                        $driverInitial = null;
                                        $driverName = null;
                                        $driverId = null;
                                        $driverColor = null;
                                    }
                                    ?>
                                    <div class="day-indicator <?php echo $hasDelivery ? 'has-delivery' : 'no-delivery'; ?> clickable-day" 
                                         <?php if ($hasDelivery): ?>style="background: linear-gradient(135deg, <?php echo $driverColor; ?> 0%, <?php echo $driverColor; ?>dd 100%) !important;"<?php endif; ?>
                                         title="<?php echo $daysFull[$dayNum] . ': ' . ($hasDelivery ? 'Driver: ' . $driverName : 'No delivery - Click to assign driver'); ?>"
                                         data-customer-id="<?php echo $customer['id']; ?>"
                                         data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                         data-day-of-week="<?php echo $dayNum; ?>"
                                         data-day-name="<?php echo $daysFull[$dayNum]; ?>"
                                         data-current-driver-id="<?php echo $driverId ?: ''; ?>"
                                         data-current-driver-name="<?php echo htmlspecialchars($driverName ?: ''); ?>">
                                        <?php if ($hasDelivery): ?>
                                            <span class="driver-initial"><?php echo $driverInitial; ?></span>
                                        <?php else: ?>
                                            <span class="add-delivery">-</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Zone Statistics -->
                <div class="zone-stats">
                    <?php
                    $zoneCustomersWithDeliveries = 0;
                    $zoneDeliveryDays = 0;
                    foreach ($customers as $customer) {
                        if (!empty($customer['delivery_days'])) {
                            $zoneCustomersWithDeliveries++;
                            $zoneDeliveryDays += count($customer['delivery_days']);
                        }
                    }
                    $zoneAvgDays = $zoneCustomersWithDeliveries > 0 ? round($zoneDeliveryDays / $zoneCustomersWithDeliveries, 1) : 0;
                    ?>
                    <strong><?php echo $zoneCustomersWithDeliveries; ?></strong> of <strong><?php echo count($customers); ?></strong> customers have deliveries
                    <?php if ($zoneCustomersWithDeliveries > 0): ?>
                        • Average <strong><?php echo $zoneAvgDays; ?></strong> delivery days per customer
                        • Total <strong><?php echo $zoneDeliveryDays; ?></strong> delivery days in this zone
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Driver Assignment Modal -->
<div id="driverAssignModal" class="modal" style="display: none;">
    <div class="modal-content driver-assign-modal">
        <span class="close" onclick="hideDriverAssignModal()">&times;</span>
        <h2>Assign Driver</h2>
        <div id="assignmentInfo">
            <p><strong><span id="assignCustomerName"></span></strong> • <span id="assignDayName"></span></p>
            <p class="current-assignment">Current: <span id="assignCurrentDriver"></span></p>
        </div>
        
        <div class="driver-selection">
            <h4>Select Driver:</h4>
            <div class="driver-icons-grid">
                <div class="driver-icon-option no-driver" onclick="selectDriver('')">
                    <div class="driver-icon-preview">
                        <span class="driver-icon-initial">✕</span>
                    </div>
                    <span class="driver-icon-name">No Driver</span>
                </div>
                <?php foreach ($drivers as $driverId => $driverInfo): ?>
                    <div class="driver-icon-option" onclick="selectDriver('<?php echo $driverId; ?>')" data-driver-id="<?php echo $driverId; ?>">
                        <div class="driver-icon-preview" style="background: <?php echo $driverInfo['color']; ?>;">
                            <span class="driver-icon-initial"><?php echo strtoupper(substr($driverInfo['name'], 0, 1)); ?></span>
                        </div>
                        <span class="driver-icon-name"><?php echo htmlspecialchars($driverInfo['name']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="modal-actions">
            <button type="button" class="btn-secondary" onclick="hideDriverAssignModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- Zone Edit Modal -->
<div id="zoneEditModal" class="modal" style="display: none;">
    <div class="modal-content zone-edit-modal">
        <span class="close" onclick="hideZoneEditModal()">&times;</span>
        <h2>Change Zone</h2>
        <div id="zoneEditInfo">
            <p><strong><span id="editingCustomerName"></span></strong></p>
        </div>
        
        <div class="zone-selection">
            <h4>Select Zone:</h4>
            <div class="zone-options">
                <div class="zone-option no-zone" onclick="selectZone('')">
                    <span class="zone-name">No Zone</span>
                </div>
                <?php foreach ($zones as $zone): ?>
                    <div class="zone-option" onclick="selectZone('<?php echo htmlspecialchars($zone); ?>')">
                        <span class="zone-name"><?php echo htmlspecialchars($zone); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="modal-actions">
            <button type="button" class="btn-secondary" onclick="hideZoneEditModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- Success/Error Message -->
<div id="messageBar" class="message-bar" style="display: none;">
    <span id="messageText"></span>
</div>

<script>
let currentEditingCustomerId = null;
let currentDriverAssignment = null;

// Store driver data for real-time updates
const driversData = <?php echo json_encode($drivers); ?>;
const zonesData = <?php echo json_encode($zones); ?>;

document.addEventListener('DOMContentLoaded', function() {
    // Verify modal elements exist
    const zoneModal = document.getElementById('zoneEditModal');
    const driverModal = document.getElementById('driverAssignModal');
    const editingCustomerName = document.getElementById('editingCustomerName');
    
    if (!zoneModal) {
        console.error('Zone edit modal not found in DOM');
    }
    if (!driverModal) {
        console.error('Driver assign modal not found in DOM');
    }
    if (!editingCustomerName) {
        console.error('editingCustomerName element not found in DOM');
    }
    
    // Add click handlers to customer rows
    const customerRows = document.querySelectorAll('.clickable-customer');
    customerRows.forEach(row => {
        row.addEventListener('click', function(e) {
            // Don't trigger if clicking on a day cell, remove button, or customer hub link
            if (!e.target.closest('.clickable-day') && !e.target.closest('.btn-remove-customer') && !e.target.closest('.customer-hub-link')) {
                openZoneEditModal(this);
            }
        });
    });

    // Deep-link from Customer Hub: ?customer_id= scrolls/highlights that row
    (function initCustomerDeepLink() {
        const params = new URLSearchParams(window.location.search);
        const customerId = params.get('customer_id');
        if (!customerId) return;
        const row = document.querySelector('.customer-row[data-customer-id="' + customerId + '"]');
        if (!row) return;
        row.classList.add('is-highlighted');
        const zone = row.closest('.zone-section');
        if (zone) {
            zone.classList.remove('collapsed');
        }
        row.scrollIntoView({ block: 'center', behavior: 'smooth' });
    })();
    
    // Add click handlers to day cells
    const dayCells = document.querySelectorAll('.clickable-day');
    dayCells.forEach(cell => {
        cell.addEventListener('click', function(e) {
            e.stopPropagation(); // Prevent customer row click
            openDriverAssignModal(this);
        });
    });
    
    // Add click handlers to day filter buttons
    const dayFilterBtns = document.querySelectorAll('.day-filter-btn');
    dayFilterBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const day = this.dataset.day;
            toggleDayFilter(day, this);
        });
    });
});

function openDriverAssignModal(dayCell) {
    const customerId = dayCell.dataset.customerId;
    const customerName = dayCell.dataset.customerName;
    const dayOfWeek = dayCell.dataset.dayOfWeek;
    const dayName = dayCell.dataset.dayName;
    const currentDriverId = dayCell.dataset.currentDriverId;
    const currentDriverName = dayCell.dataset.currentDriverName;
    
    currentDriverAssignment = {
        customerId: customerId,
        dayOfWeek: dayOfWeek,
        dayCell: dayCell
    };
    
    document.getElementById('assignCustomerName').textContent = customerName;
    document.getElementById('assignDayName').textContent = dayName;
    document.getElementById('assignCurrentDriver').textContent = currentDriverName || 'No driver assigned';
    
    // Highlight current driver selection
    const driverOptions = document.querySelectorAll('.driver-icon-option');
    driverOptions.forEach(option => {
        option.classList.remove('selected');
        if (currentDriverId && option.dataset.driverId === currentDriverId) {
            option.classList.add('selected');
        } else if (!currentDriverId && option.classList.contains('no-driver')) {
            option.classList.add('selected');
        }
    });
    
    document.getElementById('driverAssignModal').style.display = 'block';
}

function hideDriverAssignModal() {
    document.getElementById('driverAssignModal').style.display = 'none';
    currentDriverAssignment = null;
}

function selectDriver(driverId) {
    if (!currentDriverAssignment) return;
    
    saveDriverAssignment(driverId);
}

async function saveDriverAssignment(driverId) {
    if (!currentDriverAssignment) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'update_driver');
        formData.append('customer_id', currentDriverAssignment.customerId);
        formData.append('day_of_week', currentDriverAssignment.dayOfWeek);
        formData.append('driver_id', driverId);
        
        const response = await fetch('customer_schedule.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Update the day cell in real-time
            updateDayCell(currentDriverAssignment.dayCell, driverId);
            updateRemoveButton(currentDriverAssignment.dayCell.closest('.customer-row'));
            
            showMessage('Driver updated!', 'success');
            hideDriverAssignModal();
            
            // Update summary statistics
            updateSummaryStats();
        } else {
            showMessage('Error: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        showMessage('Error: ' + error.message, 'error');
    }
}

function updateDayCell(dayCell, driverId) {
    if (!driverId) {
        // Remove delivery
        dayCell.className = 'day-indicator no-delivery clickable-day';
        dayCell.style.background = '';
        dayCell.innerHTML = '<span class="add-delivery">-</span>';
        dayCell.title = dayCell.title.replace(/Driver:.*/, 'No delivery - Click to assign driver');
        dayCell.dataset.currentDriverId = '';
        dayCell.dataset.currentDriverName = '';
    } else {
        // Add/update delivery
        const driver = driversData[driverId];
        if (driver) {
            const initial = driver.name.charAt(0).toUpperCase();
            dayCell.className = 'day-indicator has-delivery clickable-day';
            dayCell.style.background = `linear-gradient(135deg, ${driver.color} 0%, ${driver.color}dd 100%)`;
            dayCell.innerHTML = `<span class="driver-initial">${initial}</span>`;
            dayCell.title = dayCell.title.replace(/(Driver:.*|No delivery.*)/, `Driver: ${driver.name}`);
            dayCell.dataset.currentDriverId = driverId;
            dayCell.dataset.currentDriverName = driver.name;
        }
    }
}

function updateRemoveButton(customerRow) {
    if (!customerRow) return;

    const hasDeliveries = customerRow.querySelector('.day-indicator.has-delivery');
    const nameRow = customerRow.querySelector('.customer-name-row');
    if (!nameRow) return;

    let removeBtn = nameRow.querySelector('.btn-remove-customer');

    if (!hasDeliveries && !removeBtn) {
        removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn-remove-customer';
        removeBtn.title = 'Remove customer (no route assignments)';
        removeBtn.dataset.customerId = customerRow.dataset.customerId;
        removeBtn.dataset.customerName = customerRow.dataset.customerName;
        removeBtn.textContent = 'Remove';
        removeBtn.onclick = function(e) { confirmRemoveCustomer(e, this); };
        nameRow.appendChild(removeBtn);
    } else if (hasDeliveries && removeBtn) {
        removeBtn.remove();
    }
}

function openZoneEditModal(customerRow) {
    // Add a small delay to ensure DOM is fully ready
    setTimeout(() => {
        const customerId = customerRow.dataset.customerId;
        const customerName = customerRow.dataset.customerName;
        const currentZone = customerRow.dataset.currentZone;
        
        // Double check that all elements exist
        const customerNameElement = document.getElementById('editingCustomerName');
        const modal = document.getElementById('zoneEditModal');
        
        if (!customerNameElement || !modal) {
            console.error('Required modal elements not found:', {
                customerNameElement: !!customerNameElement,
                modal: !!modal
            });
            // Fallback: refresh page and try again
            showMessage('Loading modal... Please try again.', 'error');
            return;
        }
        
        // Debug: Log the modal content
        console.log('Modal found:', modal);
        console.log('Modal innerHTML:', modal.innerHTML.substring(0, 200) + '...');
        
        currentEditingCustomerId = {
            id: customerId,
            row: customerRow,
            currentZone: currentZone
        };
        
        customerNameElement.textContent = customerName;
        
        // Highlight current zone selection with simpler, more reliable method
        const zoneOptions = modal.querySelectorAll('.zone-option');
        console.log('Found zone options in modal:', zoneOptions.length);
        
        // Reset all selections first
        zoneOptions.forEach(option => {
            if (option && option.classList) {
                option.classList.remove('selected');
            }
        });
        
        // Select the current zone
        if (currentZone) {
            // Find option with matching zone name
            zoneOptions.forEach(option => {
                if (option && option.textContent && option.textContent.trim() === currentZone) {
                    option.classList.add('selected');
                }
            });
        } else {
            // Select "No Zone" option
            zoneOptions.forEach(option => {
                if (option && option.classList && option.classList.contains('no-zone')) {
                    option.classList.add('selected');
                }
            });
        }
        
        modal.style.display = 'block';
    }, 10); // Small delay to ensure DOM is ready
}

function hideZoneEditModal() {
    document.getElementById('zoneEditModal').style.display = 'none';
    currentEditingCustomerId = null;
}

async function selectZone(newZone) {
    if (!currentEditingCustomerId) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'update_zone');
        formData.append('customer_id', currentEditingCustomerId.id);
        formData.append('zone', newZone);
        
        const response = await fetch('customer_schedule.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Update the zone in real-time
            updateCustomerZone(currentEditingCustomerId.row, newZone, currentEditingCustomerId.currentZone);
            
            showMessage('Zone updated!', 'success');
            hideZoneEditModal();
            
            // Update summary statistics
            updateSummaryStats();
        } else {
            showMessage('Error: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        showMessage('Error: ' + error.message, 'error');
    }
}

function updateCustomerZone(customerRow, newZone, oldZone) {
    const displayZone = newZone || 'No Zone';
    customerRow.dataset.currentZone = newZone;
    
    console.log('updateCustomerZone called:', {
        newZone: newZone,
        oldZone: oldZone,
        displayZone: displayZone,
        customerName: customerRow.dataset.customerName
    });
    
    // If zones are different, we need to move the customer row
    if ((oldZone || 'No Zone') !== displayZone) {
        console.log('Zones are different, moving customer');
        moveCustomerToNewZone(customerRow, displayZone, oldZone || 'No Zone');
    } else {
        console.log('Zones are the same, no movement needed');
    }
}

function moveCustomerToNewZone(customerRow, newZone, oldZone) {
    // Find the target zone section - match PHP class generation exactly
    // PHP: strtolower(str_replace([' ', '/'], ['-', '-'], $zoneName))
    const targetZoneClass = 'zone-' + newZone.toLowerCase().replace(/[ \/]/g, '-');
    console.log('Looking for zone class:', targetZoneClass, 'for zone:', newZone);
    
    // Debug: Show all available zone classes
    const allZoneHeaders = document.querySelectorAll('[class*="zone-"]');
    console.log('All available zone headers:', Array.from(allZoneHeaders).map(el => ({
        className: el.className,
        textContent: el.textContent.trim()
    })));
    
    const targetZoneSection = document.querySelector(`.${targetZoneClass}`);
    console.log('Target zone section found:', !!targetZoneSection);
    
    if (targetZoneSection && targetZoneSection.nextElementSibling) {
        const targetScheduleTable = targetZoneSection.nextElementSibling.querySelector('.schedule-table');
        console.log('Target schedule table found:', !!targetScheduleTable);
        
        if (targetScheduleTable) {
            // Debug: Show where customer currently is
            const currentParent = customerRow.parentElement;
            console.log('Customer current location:', {
                parentClass: currentParent ? currentParent.className : 'no parent',
                parentTagName: currentParent ? currentParent.tagName : 'no parent'
            });
            
            const targetTableBody = targetScheduleTable.querySelector('.customer-row:last-child');
            
            if (targetTableBody) {
                // Insert after the last customer row
                targetTableBody.insertAdjacentElement('afterend', customerRow);
                console.log('Customer moved after existing customer');
            } else {
                // Insert after the header if no customers exist
                const header = targetScheduleTable.querySelector('.table-header');
                if (header) {
                    header.insertAdjacentElement('afterend', customerRow);
                    console.log('Customer moved after header (first in zone)');
                } else {
                    console.error('Could not find table header for zone:', newZone);
                    showMessage('Zone updated! Please refresh to see changes.', 'success');
                    return;
                }
            }
            
            // Debug: Verify the move
            const newParent = customerRow.parentElement;
            console.log('Customer new location:', {
                parentClass: newParent ? newParent.className : 'no parent',
                parentTagName: newParent ? newParent.tagName : 'no parent'
            });
            
            // Update zone counters
            updateZoneCounters(newZone, oldZone);
            
            // Re-attach event listeners
            attachCustomerRowEvents(customerRow);
            
            console.log('Customer successfully moved to zone:', newZone);
        } else {
            console.error('Could not find schedule table for zone:', newZone);
            showMessage('Zone updated! Please refresh to see changes.', 'success');
        }
    } else {
        console.error('Could not find target zone section:', targetZoneClass);
        console.log('Available zones:', Array.from(document.querySelectorAll('[class*="zone-"]')).map(el => el.className));
        showMessage('Zone updated! Please refresh to see the new zone.', 'success');
    }
}

function confirmRemoveCustomer(event, button) {
    event.stopPropagation();
    const customerId = button.dataset.customerId;
    const customerName = button.dataset.customerName;
    const customerRow = button.closest('.customer-row');

    if (!confirm(`Remove "${customerName}" from the schedule?\n\nThis customer has no route assignments and will be permanently deleted.`)) {
        return;
    }

    removeCustomer(customerId, customerRow);
}

async function removeCustomer(customerId, customerRow) {
    try {
        const formData = new FormData();
        formData.append('action', 'delete_customer');
        formData.append('customer_id', customerId);

        const response = await fetch('customer_schedule.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            const zoneSection = customerRow.closest('.zone-section');
            customerRow.remove();

            if (zoneSection) {
                const remainingRows = zoneSection.querySelectorAll('.customer-row');
                const zoneHeader = zoneSection.querySelector('.zone-header');
                const countSpan = zoneHeader?.querySelector('span:last-child');

                if (remainingRows.length === 0) {
                    zoneSection.remove();
                } else if (countSpan) {
                    countSpan.textContent = `(${remainingRows.length} customers)`;
                }
            }

            updateSummaryStats();
            showMessage('Customer removed', 'success');
        } else {
            showMessage('Error: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        showMessage('Error: ' + error.message, 'error');
    }
}

function attachCustomerRowEvents(customerRow) {
    // Reattach customer row click event
    customerRow.addEventListener('click', function(e) {
        if (!e.target.closest('.clickable-day') && !e.target.closest('.btn-remove-customer')) {
            openZoneEditModal(this);
        }
    });
    
    // Reattach day cell events
    const dayCells = customerRow.querySelectorAll('.clickable-day');
    dayCells.forEach(cell => {
        // Remove existing listeners by cloning
        const newCell = cell.cloneNode(true);
        cell.parentNode.replaceChild(newCell, cell);
        
        newCell.addEventListener('click', function(e) {
            e.stopPropagation();
            openDriverAssignModal(this);
        });
    });
}

function updateZoneCounters(newZone, oldZone) {
    // Update zone headers with customer counts
    const zones = [newZone, oldZone];
    zones.forEach(zone => {
        if (!zone || zone === 'No Zone') zone = 'no-zone';
        // Match PHP class generation: strtolower(str_replace([' ', '/'], ['-', '-'], $zoneName))
        const zoneClass = 'zone-' + zone.toLowerCase().replace(/[ \/]/g, '-');
        const zoneHeader = document.querySelector(`.${zoneClass}`);
        
        if (zoneHeader) {
            const zoneSection = zoneHeader.closest('.zone-section');
            if (zoneSection) {
                const customerRows = zoneSection.querySelectorAll('.customer-row');
                const count = customerRows.length;
                
                // Update the count in the header
                const countSpan = zoneHeader.querySelector('span:last-child');
                if (countSpan) {
                    countSpan.textContent = `(${count} customers)`;
                    console.log(`Updated ${zone} count to ${count}`);
                }
            }
        }
    });
}

function updateSummaryStats() {
    // This is a simplified update - for full accuracy you might want to recalculate
    // For now, we'll just trigger a subtle visual feedback that something changed
    const summaryCards = document.querySelectorAll('.summary-card');
    summaryCards.forEach(card => {
        card.style.transform = 'scale(1.05)';
        setTimeout(() => {
            card.style.transform = 'scale(1)';
        }, 200);
    });
}

function showMessage(message, type) {
    const messageBar = document.getElementById('messageBar');
    const messageText = document.getElementById('messageText');
    
    messageText.textContent = message;
    messageBar.className = 'message-bar ' + type;
    messageBar.style.display = 'block';
    
    // Auto-hide after 2 seconds for faster workflow
    setTimeout(() => {
        messageBar.style.display = 'none';
    }, 2000);
}

// Close modal when clicking outside
window.onclick = function(event) {
    const zoneModal = document.getElementById('zoneEditModal');
    const driverModal = document.getElementById('driverAssignModal');
    
    if (event.target === zoneModal) {
        hideZoneEditModal();
    } else if (event.target === driverModal) {
        hideDriverAssignModal();
    }
}

// Day Filtering Functionality
let currentDayFilter = null;
const dayNames = {
    '1': 'Monday',
    '2': 'Tuesday',
    '3': 'Wednesday',
    '4': 'Thursday',
    '5': 'Friday',
    '6': 'Saturday',
    '7': 'Sunday'
};

function toggleDayFilter(day, clickedBtn) {
    if (currentDayFilter === day) {
        // Clicking the same day - clear filter
        clearDayFilter();
    } else {
        // Apply new day filter
        applyDayFilter(day, clickedBtn);
    }
}

function applyDayFilter(day, clickedBtn) {
    currentDayFilter = day;
    
    // Update button states
    document.querySelectorAll('.day-filter-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    clickedBtn.classList.add('active');
    
    // Hide all day columns except the selected one
    const dayIndex = parseInt(day);
    
    // Hide header cells (skip first cell which is "Customer")
    document.querySelectorAll('.table-header .header-cell').forEach((cell, index) => {
        if (index === 0) return; // Skip "Customer" header
        
        const cellDay = cell.dataset.day;
        if (cellDay === String(dayIndex)) {
            cell.style.display = 'block';
            cell.classList.add('highlight-day');
        } else {
            cell.style.display = 'none';
        }
    });
    
    // Hide day cells in customer rows
    document.querySelectorAll('.customer-row').forEach(row => {
        const dayCells = row.querySelectorAll('.day-cell');
        dayCells.forEach(cell => {
            if (cell.dataset.day === String(dayIndex)) {
                cell.style.display = 'flex';
                // Highlight the day indicator if it has delivery
                const dayIndicator = cell.querySelector('.day-indicator');
                if (dayIndicator && dayIndicator.classList.contains('has-delivery')) {
                    dayIndicator.classList.add('highlight-day');
                }
            } else {
                cell.style.display = 'none';
                // Remove highlights from hidden day indicators
                const dayIndicator = cell.querySelector('.day-indicator');
                if (dayIndicator) {
                    dayIndicator.classList.remove('highlight-day');
                }
            }
        });
    });
    
    // Update grid layout to accommodate fewer columns and add filtered class
    document.querySelectorAll('.table-header, .customer-row').forEach(element => {
        element.style.gridTemplateColumns = '2fr 1fr'; // Customer column + 1 day column
        element.classList.add('filtered');
    });
    
    // Count customers with deliveries on this day
    let customersWithDelivery = 0;
    let totalCustomers = 0;
    
    document.querySelectorAll('.customer-row').forEach(row => {
        totalCustomers++;
        const dayCell = row.querySelector(`.day-cell[data-day="${dayIndex}"]`);
        const hasDelivery = dayCell && dayCell.querySelector('.has-delivery');
        if (hasDelivery) {
            customersWithDelivery++;
        }
    });
    
    // Show filter controls and update status
    const filterControls = document.getElementById('filterControls');
    const filterStatus = document.getElementById('filterStatus');
    
    filterControls.style.display = 'flex';
    filterStatus.textContent = `Showing ${dayNames[day]} schedule - ${customersWithDelivery} of ${totalCustomers} customers have deliveries`;
    filterStatus.classList.add('active');
}

function clearDayFilter() {
    currentDayFilter = null;
    
    // Clear button states
    document.querySelectorAll('.day-filter-btn').forEach(btn => {
        btn.classList.remove('active');
        btn.classList.remove('highlight-day');
    });
    
    // Show all day columns
    document.querySelectorAll('.table-header .header-cell').forEach(cell => {
        cell.style.display = 'block';
    });
    
    document.querySelectorAll('.customer-row .day-cell').forEach(cell => {
        cell.style.display = 'flex';
    });
    
    // Reset grid layout by removing inline styles and filtered class so CSS media queries work
    document.querySelectorAll('.table-header, .customer-row').forEach(element => {
        element.style.gridTemplateColumns = ''; // Remove inline style to let CSS take over
        element.classList.remove('filtered'); // Remove filtered class
    });
    
    // Remove highlights from day indicators
    document.querySelectorAll('.day-indicator').forEach(indicator => {
        indicator.classList.remove('highlight-day');
    });
    
    // Reset zone appearances (remove any dimming)
    document.querySelectorAll('.zone-section').forEach(zoneSection => {
        zoneSection.classList.remove('no-visible-customers');
    });
    
    // Hide filter controls
    const filterControls = document.getElementById('filterControls');
    const filterStatus = document.getElementById('filterStatus');
    
    filterControls.style.display = 'none';
    filterStatus.textContent = 'Showing all customers';
    filterStatus.classList.remove('active');
}
</script>

<?php require_once 'includes/footer.php'; ?> 
