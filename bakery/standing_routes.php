<link rel="stylesheet" href="<?php echo bakery_asset_href('css/standing_routes.css'); ?>">
<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load essential includes first
require_once 'includes/config.php';
require_once 'includes/database.php';

// Handle AJAX request to save route
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_route') {
    header('Content-Type: application/json');
    
    try {
        $driverId = (int)$_POST['driver_id'];
        $customerId = (int)$_POST['customer_id'];
        $dayOfWeek = bakery_normalize_standing_day((int)$_POST['day_of_week']);

        if (!bakery_sfb_ops_customer_allowed($db, $customerId)) {
            throw new Exception('Synthetic SF Bakers cannot be added to standing routes');
        }
        
        // First, remove any existing route for this customer on this day
        $dayClause = $dayOfWeek === 7 ? 'IN (0, 7)' : '= ?';
        $stmt = $db->prepare("DELETE FROM standing_routes WHERE customer_id = ? AND day_of_week $dayClause");
        $stmt->execute($dayOfWeek === 7 ? [$customerId] : [$customerId, $dayOfWeek]);
        
        // If a driver is selected (not empty), add the new route
        if ($driverId > 0) {
            $stmt = $db->prepare("
                INSERT INTO standing_routes (driver_id, customer_id, day_of_week)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$driverId, $customerId, $dayOfWeek]);
        }
        
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Load the rest of the includes for normal page load
require_once 'includes/header.php';
require_once 'includes/nav.php';

// Set page title
$page_title = bakery_t('page.standing_routes');

// Fetch data
$drivers = [];
$customers = [];
$routes = [];

try {
    // Fetch all drivers with color assignments
    $driverColors = [
        '#007bff', '#28a745', '#dc3545', '#fd7e14', '#6f42c1', 
        '#20c997', '#ffc107', '#e83e8c', '#6c757d', '#17a2b8'
    ];
    
    $driversData = bakery_get_drivers($db);
    $drivers = [];
    
    foreach ($driversData as $index => $driver) {
        $drivers[$driver['id']] = [
            'name' => $driver['name'],
            'color' => $driverColors[$index % count($driverColors)]
        ];
    }
    
    // Fetch all customers with zone information, grouped by zone
    $customers = $db->query("
        SELECT id, name, zone 
        FROM customers c
        WHERE 1=1 " . bakery_sfb_ops_origin_clause('c', $db) . "
        ORDER BY 
            CASE 
                WHEN zone IS NULL OR zone = '' THEN 'ZZZ_No Zone'
                ELSE zone
            END,
            name
    ")->fetchAll();
    
    // Group customers by zone
    $customersByZone = [];
    foreach ($customers as $customer) {
        $zone = $customer['zone'] ?: 'No Zone';
        if (!isset($customersByZone[$zone])) {
            $customersByZone[$zone] = [];
        }
        $customersByZone[$zone][] = $customer;
    }
    
    // Fetch existing routes with customer zone information
    $routesResult = $db->query("
        SELECT r.driver_id, r.customer_id, r.day_of_week, c.name as customer_name, c.zone as customer_zone
        FROM standing_routes r
        JOIN customers c ON r.customer_id = c.id
        WHERE 1=1 " . bakery_sfb_ops_origin_clause('c', $db) . "
        ORDER BY r.day_of_week
    ")->fetchAll();
    
    // Organize routes by day and customer for easy lookup
    foreach ($routesResult as $route) {
        $dayOfWeek = (int)$route['day_of_week'];
        if ($dayOfWeek === 0) {
            $dayOfWeek = 7;
        }
        $routes[$dayOfWeek][$route['customer_id']] = [
            'driver_id' => $route['driver_id'],
            'customer_name' => $route['customer_name'],
            'customer_zone' => $route['customer_zone']
        ];
    }
} catch (Exception $e) {
    echo '<div class="error">Error loading data: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}

$days = [
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
    7 => 'Sunday'
];
?>

<div class="container">
    <h1>Standing Routes - Color Coded by Zone</h1>
    
    <!-- Filter Info -->
    <div class="filter-info">
        <span id="filter-status">Showing: All Days</span>
        <button id="clear-filter" style="display: none; margin-left: 10px;" class="btn btn-sm btn-secondary">Show All Days</button>
    </div>
    
    <!-- Zone Legend -->
    <div class="zone-legend">
        <h4>🗺️ Zone Color Legend</h4>
        <div class="zone-colors">
            <div class="zone-color-item zone-centro">
                <span class="zone-name">Centro</span>
            </div>
            <div class="zone-color-item zone-mission">
                <span class="zone-name">Mission</span>
            </div>
            <div class="zone-color-item zone-ruta-sour-flour">
                <span class="zone-name">Ruta Sour Flour</span>
            </div>
            <div class="zone-color-item zone-daly-city-san-mateo">
                <span class="zone-name">Daly City San Mateo</span>
            </div>
            <div class="zone-color-item zone-north-bay">
                <span class="zone-name">North Bay</span>
            </div>
            <div class="zone-color-item zone-east-bay">
                <span class="zone-name">East Bay</span>
            </div>
            <div class="zone-color-item zone-no-zone">
                <span class="zone-name">No Zone</span>
            </div>
        </div>
    </div>
    
    <!-- Customers List -->
    <div class="customers-container">
        <h3>Customers by Zone</h3>
        <div id="customer-instruction" class="instruction-text">
            Click a customer to instantly assign them to a driver and day, or drag them to specific driver/day cells. Click assigned customers to instantly reassign them. Customers are organized and color-coded by their delivery zone.
        </div>
        
        <?php foreach ($customersByZone as $zoneName => $zoneCustomers): 
            $zoneClass = 'zone-' . strtolower(str_replace([' ', '/'], ['-', '-'], $zoneName));
        ?>
            <div class="zone-group">
                <div class="zone-group-header <?php echo $zoneClass; ?>" onclick="toggleZoneGroup(this)">
                    <div class="zone-header-content">
                        <h4 class="zone-group-title">
                            <?php 
                            // Zone-specific icons
                            $zoneIcons = [
                                'Centro' => '🏢',
                                'Mission' => '🌮', 
                                'Ruta Sour Flour' => '🍞',
                                'Daly City/San Mateo' => '🌉',
                                'North Bay' => '🌲',
                                'East Bay' => '🏔️',
                                'No Zone' => '📍'
                            ];
                            echo $zoneIcons[$zoneName] ?? '🗺️';
                            ?>
                            <?php echo htmlspecialchars($zoneName); ?>
                        </h4>
                        <span class="zone-customer-count"><?php echo count($zoneCustomers); ?> customers</span>
                    </div>
                    <span class="zone-toggle-icon">▼</span>
                </div>
                <div class="customers-list">
                    <?php foreach ($zoneCustomers as $customer): ?>
                        <div class="customer-item clickable-customer <?php echo $zoneClass; ?>" 
                             draggable="true" 
                             data-customer-id="<?php echo $customer['id']; ?>"
                             data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                             data-customer-zone="<?php echo htmlspecialchars($zoneName); ?>">
                            <span class="customer-name"><?php echo htmlspecialchars($customer['name']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <div class="routes-container">
        <div class="days-header">
            <div class="driver-label">Driver</div>
            <?php foreach ($days as $dayNum => $dayName): ?>
                <div class="day-header clickable-day" data-day="<?php echo $dayNum; ?>" title="Click to filter by <?php echo $dayName; ?>">
                    <?php echo $dayName; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php foreach ($drivers as $driverId => $driverInfo): ?>
            <div class="driver-row">
                <div class="driver-label"><?php echo htmlspecialchars($driverInfo['name']); ?></div>
                
                                        <?php foreach ($days as $dayNum => $dayName): ?>
                    <div class="day-cell" data-driver-id="<?php echo $driverId; ?>" data-day="<?php echo $dayNum; ?>">
                        <div class="customer-list">
                            <?php 
                            $driverRoutes = array_filter($routes[$dayNum] ?? [], function($route) use ($driverId) {
                                return $route['driver_id'] == $driverId;
                            });
                            
                            // Sort routes by zone to group similar colors together
                            uasort($driverRoutes, function($a, $b) {
                                $zoneA = $a['customer_zone'] ?: 'ZZZ_No Zone'; // Put No Zone at the end
                                $zoneB = $b['customer_zone'] ?: 'ZZZ_No Zone';
                                
                                // First sort by zone
                                $zoneCompare = strcmp($zoneA, $zoneB);
                                if ($zoneCompare !== 0) {
                                    return $zoneCompare;
                                }
                                
                                // Then sort by customer name within the same zone
                                return strcmp($a['customer_name'], $b['customer_name']);
                            });
                            
                            foreach ($driverRoutes as $customerId => $route): 
                                $zone = $route['customer_zone'] ?: 'No Zone';
                                $zoneClass = 'zone-' . strtolower(str_replace([' ', '/'], ['-', '-'], $zone));
                            ?>
                                <div class="assigned-customer <?php echo $zoneClass; ?>" 
                                     data-customer-id="<?php echo $customerId; ?>"
                                     data-customer-name="<?php echo htmlspecialchars($route['customer_name']); ?>"
                                     data-customer-zone="<?php echo htmlspecialchars($zone); ?>">
                                    <span class="customer-name"><?php echo htmlspecialchars($route['customer_name']); ?></span>
                                    <span class="delete-customer" title="Remove from route">×</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Customer Assignment Modal -->
<div id="assignment-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Assign Customer to Driver</h3>
            <span class="close">&times;</span>
        </div>
        <div class="modal-body">
            <p id="modal-intro-text">Assign <strong id="modal-customer-name"></strong> to driver <span id="modal-day-context">for <strong id="modal-day-name"></strong></span>:</p>
            
            <!-- Day Selection (shown when no day filter is active) -->
            <div id="day-selection-section" class="selection-section">
                <h4>Select Day:</h4>
                <div class="day-icons-grid">
                    <?php foreach ($days as $dayNum => $dayName): ?>
                        <div class="day-icon-option <?php echo $dayNum == 1 ? 'selected' : ''; ?>" onclick="selectDayInModal('<?php echo $dayNum; ?>')" data-day="<?php echo $dayNum; ?>">
                            <div class="day-icon-preview">
                                <span class="day-icon-name"><?php echo $dayName; ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Driver Selection - Visual Grid (non-filtered mode) -->
            <div id="driver-selection-section" class="selection-section">
                <h4>Select Driver:</h4>
                <div class="driver-icons-grid">
                    <div class="driver-icon-option no-driver" onclick="selectDriverInModal('')" data-driver-id="0">
                        <div class="driver-icon-preview">
                            <span class="driver-icon-initial">✕</span>
                        </div>
                        <span class="driver-icon-name">No Driver</span>
                    </div>
                    <?php foreach ($drivers as $driverId => $driverInfo): ?>
                        <div class="driver-icon-option" onclick="selectDriverInModal('<?php echo $driverId; ?>')" data-driver-id="<?php echo $driverId; ?>">
                            <div class="driver-icon-preview" style="background: <?php echo $driverInfo['color']; ?>;">
                                <span class="driver-icon-initial"><?php echo strtoupper(substr($driverInfo['name'], 0, 1)); ?></span>
                            </div>
                            <span class="driver-icon-name"><?php echo htmlspecialchars($driverInfo['name']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Driver Selection - Visual Grid (filtered mode) -->
            <div id="driver-click-section" class="selection-section" style="display: none;">
                <h4>Select Driver:</h4>
                <div class="driver-icons-grid">
                    <div class="driver-icon-option no-driver" onclick="selectDriverFilteredMode('')" data-driver-id="0">
                        <div class="driver-icon-preview">
                            <span class="driver-icon-initial">✕</span>
                        </div>
                        <span class="driver-icon-name">No Driver</span>
                        <span class="driver-status"></span>
                    </div>
                    <?php foreach ($drivers as $driverId => $driverInfo): ?>
                        <div class="driver-icon-option" onclick="selectDriverFilteredMode('<?php echo $driverId; ?>')" data-driver-id="<?php echo $driverId; ?>">
                            <div class="driver-icon-preview" style="background: <?php echo $driverInfo['color']; ?>;">
                                <span class="driver-icon-initial"><?php echo strtoupper(substr($driverInfo['name'], 0, 1)); ?></span>
                            </div>
                            <span class="driver-icon-name"><?php echo htmlspecialchars($driverInfo['name']); ?></span>
                            <span class="driver-status"></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>




<script src="<?php echo bakery_asset_href('includes/standing_routes.js'); ?>" defer></script>


<?php require_once 'includes/footer.php'; ?>
