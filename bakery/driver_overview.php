<link rel="stylesheet" href="<?php echo bakery_asset_href('css/driver_overview.css'); ?>">
<?php
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/zones_catalog.php';

// Add cache-busting headers to ensure fresh data
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$page_title = bakery_t('page.driver_overview');

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
    $zoneColors = bakery_zone_display_cycle();
    $zonesCatalog = bakery_zones_catalog($db);
    
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
            $zoneColorMap[$zone] = bakery_zone_route_color($zonesCatalog, $zone, $zoneColors, $zoneIndex);
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
window.__DRIVER_OVERVIEW__ = {
    selectedDate: <?php echo bakery_json_for_html($selectedDate, '""'); ?>
};
</script>
<script src="<?php echo bakery_asset_href('includes/driver_overview.js'); ?>" defer></script>


<?php require_once 'includes/footer.php'; ?> 
