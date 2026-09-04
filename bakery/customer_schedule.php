<link rel="stylesheet" href="<?php echo bakery_asset_href('css/customer_schedule.css'); ?>">
<?php
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/zones_catalog.php';

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
$zonesCatalog = bakery_zones_catalog($db);
$zones = array_column($zonesCatalog, 'name');

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
window.__CUSTOMER_SCHEDULE__ = {
    drivers: <?php echo bakery_json_for_html($drivers, '[]'); ?>,
    zones: <?php echo bakery_json_for_html($zones, '[]'); ?>
};
</script>
<script src="<?php echo bakery_asset_href('includes/customer_schedule.js'); ?>" defer></script>


<?php require_once 'includes/footer.php'; ?> 
