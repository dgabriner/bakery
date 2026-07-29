<?php
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';

// Add cache-busting headers to ensure fresh data
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$page_title = 'Customer Overview by Zone';

// Handle AJAX driver assignment updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_driver') {
    try {
        $customerId = $_POST['customer_id'];
        $dayOfWeek = $_POST['day_of_week'];
        $driverId = empty($_POST['driver_id']) ? null : $_POST['driver_id'];
        
        if ($driverId === null) {
            // Remove the standing route entry
            $stmt = $db->prepare("DELETE FROM standing_routes WHERE customer_id = ? AND day_of_week = ?");
            $stmt->execute([$customerId, $dayOfWeek]);
        } else {
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

// Get customer data grouped by zone with delivery schedules and driver information
$customerData = [];
$visitStats = [];

try {
    // Get all customers with their delivery schedules and driver info
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
                WHEN c.zone IS NULL OR c.zone = '' THEN 'ZZZ_No Zone'
                ELSE c.zone
            END,
            c.name,
            sr.day_of_week
    ");
    
    $results = $stmt->fetchAll();
    
    // Get driver colors for consistent display
    $driverColors = [
        '#007bff', '#28a745', '#dc3545', '#fd7e14', '#6f42c1', 
        '#20c997', '#ffc107', '#e83e8c', '#6c757d', '#17a2b8'
    ];
    
    $drivers = [];
    $driverStmt = $db->query("SELECT id, name FROM drivers ORDER BY name");
    $driversData = $driverStmt->fetchAll();
    
    foreach ($driversData as $index => $driver) {
        $drivers[$driver['id']] = [
            'name' => $driver['name'],
            'color' => $driverColors[$index % count($driverColors)]
        ];
    }
    
    // Process the data to create a zone-grouped structure with driver assignments
    // Using the same logic as customer_schedule.php which works correctly
    $customerDeliveries = [];
    foreach ($results as $row) {
        $customerId = $row['id'];
        
        if (!isset($customerDeliveries[$customerId])) {
            $customerDeliveries[$customerId] = [
                'id' => $row['id'],
                'name' => $row['customer_name'],
                'address' => $row['address'],
                'zone' => $row['zone'] ?: 'No Zone',
                'delivery_days' => [],
                'visit_count' => 0
            ];
        }
        
        if ($row['day_of_week'] !== null) {
            $driverColor = isset($drivers[$row['driver_id']]) ? $drivers[$row['driver_id']]['color'] : '#6c757d';
            $customerDeliveries[$customerId]['delivery_days'][(int)$row['day_of_week']] = [
                'driver_id' => $row['driver_id'],
                'driver_name' => $row['driver_name'],
                'driver_initial' => $row['driver_name'] ? strtoupper(substr($row['driver_name'], 0, 1)) : 'X',
                'driver_color' => $driverColor
            ];
            $customerDeliveries[$customerId]['visit_count']++;
        }
    }
    
    // Group by zone using the same simple logic as customer_schedule.php
    $customersByZone = [];
    foreach ($customerDeliveries as $customer) {
        $zone = $customer['zone'];
        
        if (!isset($customersByZone[$zone])) {
            $customersByZone[$zone] = [];
        }
        
        $customersByZone[$zone][] = $customer;
    }
    
    // Sort customers within each zone by frequency (high->medium->low->no visits) then by name
    foreach ($customersByZone as $zoneName => $zoneCustomerList) {
        usort($zoneCustomerList, function($a, $b) {
            // Define frequency priority (lower number = higher priority)
            $getFrequencyPriority = function($visitCount) {
                if ($visitCount >= 5) return 1; // High
                if ($visitCount >= 3) return 2; // Medium  
                if ($visitCount >= 1) return 3; // Low
                return 4; // No visits
            };
            
            $priorityA = $getFrequencyPriority($a['visit_count']);
            $priorityB = $getFrequencyPriority($b['visit_count']);
            
            // First sort by frequency priority
            if ($priorityA !== $priorityB) {
                return $priorityA - $priorityB;
            }
            
            // Then sort by name within the same frequency
            return strcasecmp($a['name'], $b['name']);
        });
        
        // Assign the sorted array back to the zone
        $customersByZone[$zoneName] = $zoneCustomerList;
    }
    
    // Calculate zone statistics
    foreach ($customersByZone as $statZoneName => $statZoneCustomers) {
        $totalCustomers = count($statZoneCustomers);
        $totalVisits = array_sum(array_column($statZoneCustomers, 'visit_count'));
        $customersWithDeliveries = count(array_filter($statZoneCustomers, function($c) { return $c['visit_count'] > 0; }));
        
        $visitStats[$statZoneName] = [
            'total_customers' => $totalCustomers,
            'customers_with_deliveries' => $customersWithDeliveries,
            'customers_without_deliveries' => $totalCustomers - $customersWithDeliveries,
            'total_weekly_visits' => $totalVisits,
            'avg_visits_per_customer' => $totalCustomers > 0 ? round($totalVisits / $totalCustomers, 1) : 0
        ];
    }
    
} catch (Exception $e) {
    $error = 'Error loading customer data: ' . htmlspecialchars($e->getMessage());
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
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
    border-left: 4px solid #007bff;
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
    color: #007bff;
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

.customers-grid {
    display: flex;
    flex-direction: column;
    gap: 20px;
    padding: 30px;
}

.frequency-group-header {
    grid-column: 1 / -1;
    padding: 15px 20px;
    border-radius: 10px;
    margin: 10px 0 5px 0;
    border-left: 4px solid;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
}

.frequency-group-header.high {
    border-left-color: #28a745;
    background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
}

.frequency-group-header.medium {
    border-left-color: #fd7e14;
    background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
}

.frequency-group-header.low {
    border-left-color: #6c757d;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
}

.frequency-group-header.no-visits {
    border-left-color: #dc3545;
    background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
}

.frequency-group-header h4 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    display: flex;
    align-items: center;
    gap: 10px;
}

.frequency-group-header.high h4:before { content: '🟢'; }
.frequency-group-header.medium h4:before { content: '🟡'; }
.frequency-group-header.low h4:before { content: '🔵'; }
.frequency-group-header.no-visits h4:before { content: '🔴'; }

.frequency-group {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.customer-card {
    background: #f8f9fa;
    border-radius: 12px;
    padding: 20px;
    border: 1px solid #e9ecef;
    transition: all 0.2s ease;
}

.customer-card:hover {
    background: #e9ecef;
    border-color: #007bff;
}

.customer-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.customer-name {
    font-weight: 600;
    color: #2c3e50;
    font-size: 1.1rem;
    margin: 0;
}

.visit-frequency {
    background: #007bff;
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 500;
}

.visit-frequency.low { background: #6c757d; }
.visit-frequency.medium { background: #fd7e14; }
.visit-frequency.high { background: #28a745; }

.customer-address {
    color: #6c757d;
    font-size: 0.9rem;
    margin-bottom: 15px;
    line-height: 1.3;
}

.schedule-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 5px;
    margin-bottom: 10px;
}

.day-header {
    text-align: center;
    font-size: 0.8rem;
    font-weight: 600;
    color: #6c757d;
    padding: 5px;
    background: #e9ecef;
    border-radius: 4px;
}

.day-slot {
    height: 40px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    color: white;
    font-size: 0.9rem;
    border: 2px solid transparent;
    transition: all 0.2s ease;
}

.day-slot.empty {
    background: #e9ecef;
    color: #6c757d;
    font-weight: normal;
}

.day-slot:not(.empty):hover {
    border-color: #fff;
    transform: scale(1.05);
}

.day-slot.clickable-day {
    cursor: pointer;
    transition: all 0.2s ease;
}

.day-slot.clickable-day:hover {
    transform: scale(1.1);
    z-index: 10;
    position: relative;
}

.day-slot.empty:hover {
    background: #e3f2fd;
    color: #1976d2;
    border-color: #1976d2;
}

.driver-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #dee2e6;
}

.driver-badge {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 0.8rem;
    color: #495057;
}

.driver-color {
    width: 12px;
    height: 12px;
    border-radius: 3px;
}

.no-deliveries {
    text-align: center;
    color: #6c757d;
    font-style: italic;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 8px;
    margin-top: 10px;
}

.filters-bar {
    background: white;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    display: flex;
    gap: 15px;
    align-items: center;
    flex-wrap: wrap;
}

.filter-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-label {
    font-weight: 600;
    color: #495057;
    font-size: 0.9rem;
}

.filter-select {
    padding: 8px 12px;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    font-size: 0.9rem;
    background: white;
    cursor: pointer;
}

.filter-select:focus {
    outline: none;
    border-color: #007bff;
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
    
    .customers-grid {
        grid-template-columns: 1fr;
        padding: 20px;
    }
    
    .zone-header {
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
</style>

<div class="overview-container">
    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <strong>Error:</strong> <?php echo $error; ?>
        </div>
    <?php else: ?>
        
    <div class="page-header">
        <h1>🗺️ Customer Overview by Zone</h1>
        <p>Comprehensive view of all customers organized by delivery zones with weekly schedules and visit frequency analysis</p>
    </div>

    <!-- Overall Statistics -->
    <div class="stats-overview">
        <?php 
        $totalCustomers = array_sum(array_column($visitStats, 'total_customers'));
        $totalVisits = array_sum(array_column($visitStats, 'total_weekly_visits'));
        $totalZones = count($customersByZone);
        $avgVisitsPerCustomer = $totalCustomers > 0 ? round($totalVisits / $totalCustomers, 1) : 0;
        ?>
        
        <div class="stat-card">
            <h3>📊 Overall Statistics</h3>
            <div class="stat-grid">
                <div class="stat-item">
                    <span class="stat-number"><?php echo $totalCustomers; ?></span>
                    <span class="stat-label">Total Customers</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?php echo $totalZones; ?></span>
                    <span class="stat-label">Active Zones</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?php echo $totalVisits; ?></span>
                    <span class="stat-label">Weekly Visits</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?php echo $avgVisitsPerCustomer; ?></span>
                    <span class="stat-label">Avg Visits/Customer</span>
                </div>
            </div>
        </div>
        
        <?php foreach (array_slice($visitStats, 0, 3) as $zone => $stats): ?>
        <div class="stat-card">
            <h3>🗺️ <?php echo htmlspecialchars($zone); ?></h3>
            <div class="stat-grid">
                <div class="stat-item">
                    <span class="stat-number"><?php echo $stats['total_customers']; ?></span>
                    <span class="stat-label">Customers</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?php echo $stats['total_weekly_visits']; ?></span>
                    <span class="stat-label">Weekly Visits</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?php echo $stats['customers_with_deliveries']; ?></span>
                    <span class="stat-label">Active</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?php echo $stats['avg_visits_per_customer']; ?></span>
                    <span class="stat-label">Avg Frequency</span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
        <div class="filter-group">
            <span class="filter-label">Filter by Visit Frequency:</span>
            <select class="filter-select" id="frequencyFilter">
                <option value="all">All Customers</option>
                <option value="no-visits">No Deliveries</option>
                <option value="low">Low (1-2 visits/week)</option>
                <option value="medium">Medium (3-4 visits/week)</option>
                <option value="high">High (5+ visits/week)</option>
            </select>
        </div>
        <div class="filter-group">
            <span class="filter-label">Zone:</span>
            <select class="filter-select" id="zoneFilter">
                <option value="all">All Zones</option>
                <?php foreach (array_keys($customersByZone) as $zoneName): ?>
                <option value="<?php echo htmlspecialchars($zoneName); ?>"><?php echo htmlspecialchars($zoneName); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Customer Data by Zone -->
    <div class="zones-container">
        <?php foreach ($customersByZone as $zoneName => $zoneCustomers): ?>
        <div class="zone-section" data-zone="<?php echo htmlspecialchars($zoneName); ?>">
            <div class="zone-header">
                <h2 class="zone-title"><?php echo htmlspecialchars($zoneName); ?></h2>
                <div class="zone-stats">
                    <div class="zone-stat">
                        <span class="zone-stat-number"><?php echo $visitStats[$zoneName]['total_customers']; ?></span>
                        <span class="zone-stat-label">Customers</span>
                    </div>
                    <div class="zone-stat">
                        <span class="zone-stat-number"><?php echo $visitStats[$zoneName]['customers_with_deliveries']; ?></span>
                        <span class="zone-stat-label">Active</span>
                    </div>
                    <div class="zone-stat">
                        <span class="zone-stat-number"><?php echo $visitStats[$zoneName]['total_weekly_visits']; ?></span>
                        <span class="zone-stat-label">Weekly Visits</span>
                    </div>
                    <div class="zone-stat">
                        <span class="zone-stat-number"><?php echo $visitStats[$zoneName]['avg_visits_per_customer']; ?></span>
                        <span class="zone-stat-label">Avg Frequency</span>
                    </div>
                </div>
            </div>
            
            <div class="customers-grid">
                <?php 
                // Create frequency groups for this specific zone
                $zoneFrequencyGroups = [
                    'high' => [],
                    'medium' => [],
                    'low' => [],
                    'no-visits' => []
                ];
                
                // Process each customer in this zone specifically
                foreach ($zoneCustomers as $zoneCustomer) {
                    $customerVisitCount = $zoneCustomer['visit_count'];
                    if ($customerVisitCount >= 5) {
                        $zoneFrequencyGroups['high'][] = $zoneCustomer;
                    } elseif ($customerVisitCount >= 3) {
                        $zoneFrequencyGroups['medium'][] = $zoneCustomer;
                    } elseif ($customerVisitCount >= 1) {
                        $zoneFrequencyGroups['low'][] = $zoneCustomer;
                    } else {
                        $zoneFrequencyGroups['no-visits'][] = $zoneCustomer;
                    }
                }
                
                $frequencyGroupTitles = [
                    'high' => 'High Frequency (5+ visits/week)',
                    'medium' => 'Medium Frequency (3-4 visits/week)', 
                    'low' => 'Low Frequency (1-2 visits/week)',
                    'no-visits' => 'No Deliveries'
                ];
                
                // Display each frequency group for this zone
                foreach ($zoneFrequencyGroups as $frequencyGroupKey => $frequencyGroupCustomers):
                    if (empty($frequencyGroupCustomers)) continue;
                ?>
                <div class="frequency-group-header <?php echo $frequencyGroupKey; ?>">
                    <h4><?php echo $frequencyGroupTitles[$frequencyGroupKey]; ?> (<?php echo count($frequencyGroupCustomers); ?> customers)</h4>
                </div>
                <div class="frequency-group">
                <?php 
                // Display each customer in this frequency group
                foreach ($frequencyGroupCustomers as $displayCustomer): 
                    $customerVisitCount = $displayCustomer['visit_count'];
                    $customerFrequencyClass = $frequencyGroupKey;
                    $customerFrequencyText = 'No Deliveries';
                    
                    if ($customerVisitCount > 0) {
                        if ($customerVisitCount <= 2) {
                            $customerFrequencyText = 'Low (' . $customerVisitCount . '/week)';
                        } elseif ($customerVisitCount <= 4) {
                            $customerFrequencyText = 'Medium (' . $customerVisitCount . '/week)';
                        } else {
                            $customerFrequencyText = 'High (' . $customerVisitCount . '/week)';
                        }
                    }
                ?>
                <div class="customer-card" data-frequency="<?php echo $customerFrequencyClass; ?>">
                    <div class="customer-header">
                        <h3 class="customer-name"><?php echo htmlspecialchars($displayCustomer['name']); ?></h3>
                        <span class="visit-frequency <?php echo $customerFrequencyClass; ?>"><?php echo $customerFrequencyText; ?></span>
                    </div>
                    
                    <?php if ($displayCustomer['address']): ?>
                    <div class="customer-address">
                        📍 <?php echo htmlspecialchars($displayCustomer['address']); ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($displayCustomer['delivery_days'])): ?>
                        <div class="schedule-grid">
                            <?php foreach ($days as $dayNum => $dayName): ?>
                            <div class="day-header"><?php echo $dayName; ?></div>
                            <?php endforeach; ?>
                            
                            <?php foreach ($days as $dayNum => $dayName): ?>
                            <?php if (isset($displayCustomer['delivery_days'][$dayNum])): ?>
                                <?php $deliveryInfo = $displayCustomer['delivery_days'][$dayNum]; ?>
                                <div class="day-slot clickable-day" 
                                     style="background-color: <?php echo $deliveryInfo['driver_color']; ?>"
                                     title="<?php echo htmlspecialchars($deliveryInfo['driver_name']); ?> - Click to change driver"
                                     data-customer-id="<?php echo $displayCustomer['id']; ?>"
                                     data-customer-name="<?php echo htmlspecialchars($displayCustomer['name']); ?>"
                                     data-day-of-week="<?php echo $dayNum; ?>"
                                     data-day-name="<?php echo ucfirst($days[$dayNum]); ?>"
                                     data-current-driver-id="<?php echo $deliveryInfo['driver_id']; ?>"
                                     data-current-driver-name="<?php echo htmlspecialchars($deliveryInfo['driver_name']); ?>">
                                    <?php echo $deliveryInfo['driver_initial']; ?>
                                </div>
                            <?php else: ?>
                                <div class="day-slot empty clickable-day"
                                     title="No delivery - Click to assign driver"
                                     data-customer-id="<?php echo $displayCustomer['id']; ?>"
                                     data-customer-name="<?php echo htmlspecialchars($displayCustomer['name']); ?>"
                                     data-day-of-week="<?php echo $dayNum; ?>"
                                     data-day-name="<?php echo ucfirst($days[$dayNum]); ?>"
                                     data-current-driver-id=""
                                     data-current-driver-name="">-</div>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Driver Legend for this customer -->
                        <?php 
                        $customerDriversLegend = [];
                        foreach ($displayCustomer['delivery_days'] as $deliveryLegend) {
                            if (!isset($customerDriversLegend[$deliveryLegend['driver_id']])) {
                                $customerDriversLegend[$deliveryLegend['driver_id']] = $deliveryLegend;
                            }
                        }
                        ?>
                        
                        <?php if (!empty($customerDriversLegend)): ?>
                        <div class="driver-legend">
                            <?php foreach ($customerDriversLegend as $driverLegendInfo): ?>
                            <div class="driver-badge">
                                <div class="driver-color" style="background-color: <?php echo $driverLegendInfo['driver_color']; ?>"></div>
                                <span><?php echo htmlspecialchars($driverLegendInfo['driver_name']); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="no-deliveries">
                            No scheduled deliveries
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
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

<!-- Success/Error Message -->
<div id="messageBar" class="message-bar" style="display: none;">
    <span id="messageText"></span>
</div>

<script>
let currentDriverAssignment = null;

// Store driver data for real-time updates
const driversData = <?php echo json_encode($drivers); ?>;

document.addEventListener('DOMContentLoaded', function() {
    const frequencyFilter = document.getElementById('frequencyFilter');
    const zoneFilter = document.getElementById('zoneFilter');
    
    function applyFilters() {
        const frequencyValue = frequencyFilter.value;
        const zoneValue = zoneFilter.value;
        
        // Filter zones
        document.querySelectorAll('.zone-section').forEach(zoneSection => {
            const zoneName = zoneSection.dataset.zone;
            const zoneMatch = zoneValue === 'all' || zoneName === zoneValue;
            
            if (zoneMatch) {
                zoneSection.style.display = 'block';
                
                // Filter customers within visible zones
                const customerCards = zoneSection.querySelectorAll('.customer-card');
                let visibleCustomers = 0;
                
                customerCards.forEach(card => {
                    const customerFrequency = card.dataset.frequency;
                    const frequencyMatch = frequencyValue === 'all' || customerFrequency === frequencyValue;
                    
                    if (frequencyMatch) {
                        card.style.display = 'block';
                        visibleCustomers++;
                    } else {
                        card.style.display = 'none';
                    }
                });
                
                // Hide zone if no customers match
                if (visibleCustomers === 0 && frequencyValue !== 'all') {
                    zoneSection.style.display = 'none';
                }
            } else {
                zoneSection.style.display = 'none';
            }
        });
    }
    
    frequencyFilter.addEventListener('change', applyFilters);
    zoneFilter.addEventListener('change', applyFilters);
    
    // Add click handlers to day slots
    const daySlots = document.querySelectorAll('.clickable-day');
    daySlots.forEach(slot => {
        slot.addEventListener('click', function(e) {
            e.stopPropagation();
            openDriverAssignModal(this);
        });
    });
});

function openDriverAssignModal(daySlot) {
    const customerId = daySlot.dataset.customerId;
    const customerName = daySlot.dataset.customerName;
    const dayOfWeek = daySlot.dataset.dayOfWeek;
    const dayName = daySlot.dataset.dayName;
    const currentDriverId = daySlot.dataset.currentDriverId;
    const currentDriverName = daySlot.dataset.currentDriverName;
    
    currentDriverAssignment = {
        customerId: customerId,
        dayOfWeek: dayOfWeek,
        daySlot: daySlot
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
        
        const response = await fetch('customer_overview.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Update the day slot in real-time
            updateDaySlot(currentDriverAssignment.daySlot, driverId);
            
            showMessage('Driver assignment updated!', 'success');
            hideDriverAssignModal();
            
            // Update visit frequency display
            updateVisitFrequency(currentDriverAssignment.daySlot);
        } else {
            showMessage('Error: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        showMessage('Error: ' + error.message, 'error');
    }
}

function updateDaySlot(daySlot, driverId) {
    if (!driverId) {
        // Remove delivery
        daySlot.classList.add('empty');
        daySlot.classList.remove('clickable-day');
        daySlot.style.backgroundColor = '';
        daySlot.textContent = '-';
        daySlot.title = 'No delivery - Click to assign driver';
        daySlot.dataset.currentDriverId = '';
        daySlot.dataset.currentDriverName = '';
        daySlot.classList.add('clickable-day'); // Re-add clickable class
    } else {
        // Add/update delivery
        const driver = driversData[driverId];
        if (driver) {
            const initial = driver.name.charAt(0).toUpperCase();
            daySlot.classList.remove('empty');
            daySlot.style.backgroundColor = driver.color;
            daySlot.textContent = initial;
            daySlot.title = driver.name + ' - Click to change driver';
            daySlot.dataset.currentDriverId = driverId;
            daySlot.dataset.currentDriverName = driver.name;
        }
    }
}

function updateVisitFrequency(daySlot) {
    // Find the customer card that contains this day slot
    const customerCard = daySlot.closest('.customer-card');
    if (customerCard) {
        // Count all delivery days for this customer
        const allDaySlots = customerCard.querySelectorAll('.day-slot:not(.empty)');
        const visitCount = allDaySlots.length;
        
        // Update frequency badge
        const frequencyBadge = customerCard.querySelector('.visit-frequency');
        if (frequencyBadge) {
            let frequencyClass = 'no-visits';
            let frequencyText = 'No Deliveries';
            
            if (visitCount > 0) {
                if (visitCount <= 2) {
                    frequencyClass = 'low';
                    frequencyText = 'Low (' + visitCount + '/week)';
                } else if (visitCount <= 4) {
                    frequencyClass = 'medium'; 
                    frequencyText = 'Medium (' + visitCount + '/week)';
                } else {
                    frequencyClass = 'high';
                    frequencyText = 'High (' + visitCount + '/week)';
                }
            }
            
            // Update the badge
            frequencyBadge.className = 'visit-frequency ' + frequencyClass;
            frequencyBadge.textContent = frequencyText;
            
            // Update the customer card data attribute for filtering
            customerCard.dataset.frequency = frequencyClass;
        }
    }
}

function showMessage(message, type) {
    const messageBar = document.getElementById('messageBar');
    const messageText = document.getElementById('messageText');
    
    messageText.textContent = message;
    messageBar.className = 'message-bar ' + type;
    messageBar.style.display = 'block';
    
    // Auto-hide after 3 seconds
    setTimeout(() => {
        messageBar.style.display = 'none';
    }, 3000);
}

// Close modal when clicking outside
window.onclick = function(event) {
    const driverModal = document.getElementById('driverAssignModal');
    
    if (event.target === driverModal) {
        hideDriverAssignModal();
    }
}
</script>

<?php require_once 'includes/footer.php'; ?> 