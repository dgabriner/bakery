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
        $dayOfWeek = (int)$_POST['day_of_week'];
        
        // First, remove any existing route for this customer on this day
        $stmt = $db->prepare("DELETE FROM standing_routes WHERE customer_id = ? AND day_of_week = ?");
        $stmt->execute([$customerId, $dayOfWeek]);
        
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
$page_title = 'Standing Routes';

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
    
    $driversData = $db->query("SELECT id, name FROM drivers ORDER BY name")->fetchAll();
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
        FROM customers 
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
        ORDER BY r.day_of_week
    ")->fetchAll();
    
    // Organize routes by day and customer for easy lookup
    foreach ($routesResult as $route) {
        $routes[$route['day_of_week']][$route['customer_id']] = [
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

<style>
    .filter-info {
        margin-bottom: 20px;
        padding: 10px;
        background-color: #e9ecef;
        border-radius: 5px;
        font-weight: bold;
    }
    
    .instruction-text {
        font-size: 0.9em;
        color: #6c757d;
        margin-bottom: 10px;
        font-style: italic;
    }
    
    .routes-container {
        margin-top: 20px;
        width: 100%;
        overflow-x: auto;
    }
    
    .days-header, .driver-row {
        display: flex;
        border-bottom: 1px solid #dee2e6;
        width: 100%;
    }
    
    .driver-label {
        width: 150px;
        min-width: 150px;
        max-width: 150px;
        padding: 10px;
        font-weight: bold;
        background-color: #f8f9fa;
        border-right: 1px solid #dee2e6;
        display: flex;
        align-items: center;
        flex-shrink: 0;
    }
    
    .day-header {
        flex: 1 1 0;
        width: calc((100% - 150px) / 7);
        min-width: 0;
        padding: 10px;
        text-align: center;
        font-weight: bold;
        background-color: #e9ecef;
        border-right: 1px solid #dee2e6;
        transition: background-color 0.2s;
        box-sizing: border-box;
    }
    
    .day-header.clickable-day {
        cursor: pointer;
        user-select: none;
    }
    
    .day-header.clickable-day:hover {
        background-color: #dee2e6;
    }
    
    .day-header.active-filter {
        background-color: #4e73df;
        color: white;
    }
    
    .day-cell {
        flex: 1 1 0;
        width: calc((100% - 150px) / 7);
        min-width: 0;
        min-height: 150px;
        padding: 10px;
        border-right: 1px solid #dee2e6;
        background-color: #fff;
        box-sizing: border-box;
        overflow-wrap: break-word;
    }
    
    .customer-list {
        min-height: 130px;
    }
    
    .assigned-customer {
        position: relative;
        background-color: #e9f7ef;
        padding: 8px 25px 8px 8px;
        margin: 4px 0;
        border-radius: 4px;
        font-size: 0.95em;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s ease;
        color: white;
        text-shadow: 0 1px 2px rgba(0,0,0,0.7);
        min-height: 28px;
        line-height: 1.3;
    }
    
    .assigned-customer:hover {
        background-color: #d4edda;
        transform: translateY(-1px);
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .assigned-customer .customer-name {
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        flex: 1;
        min-width: 0;
        font-weight: 500;
    }
    
    .assigned-customer .delete-customer {
        position: absolute;
        top: 50%;
        right: 6px;
        transform: translateY(-50%);
        cursor: pointer;
        font-size: 1.3em;
        color: #dc3545;
        opacity: 0.8;
        transition: opacity 0.2s;
        width: 16px;
        height: 16px;
        text-align: center;
        line-height: 1;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .assigned-customer .delete-customer:hover {
        opacity: 1;
        color: #bd2130;
    }
    
    /* Zone badge styles */
    .zone-badge {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 10px;
        font-size: 0.75em;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        opacity: 0.9;
    }
    
    /* Zone color coding */
    .customer-item.zone-centro,
    .assigned-customer.zone-centro {
        background-color: #007bff !important;
        border-left: 4px solid #0056b3;
        color: #ffffff !important;
        text-shadow: 0 1px 3px rgba(0,0,0,0.8) !important;
    }
    
    .customer-item.zone-mission,
    .assigned-customer.zone-mission {
        background-color: #dc3545 !important;
        border-left: 4px solid #a71e2a;
        color: #ffffff !important;
        text-shadow: 0 1px 3px rgba(0,0,0,0.8) !important;
    }
    
    .customer-item.zone-ruta-sour-flour,
    .assigned-customer.zone-ruta-sour-flour {
        background-color: #28a745 !important;
        border-left: 4px solid #1e7e34;
        color: #ffffff !important;
        text-shadow: 0 1px 3px rgba(0,0,0,0.8) !important;
    }
    
    .customer-item.zone-daly-city-san-mateo,
    .assigned-customer.zone-daly-city-san-mateo {
        background-color: #fd7e14 !important;
        border-left: 4px solid #e55a00;
        color: #ffffff !important;
        text-shadow: 0 1px 3px rgba(0,0,0,0.8) !important;
    }
    
    .customer-item.zone-north-bay,
    .assigned-customer.zone-north-bay {
        background-color: #6f42c1 !important;
        border-left: 4px solid #542788;
        color: #ffffff !important;
        text-shadow: 0 1px 3px rgba(0,0,0,0.8) !important;
    }
    
    .customer-item.zone-east-bay,
    .assigned-customer.zone-east-bay {
        background-color: #20c997 !important;
        border-left: 4px solid #159570;
        color: #ffffff !important;
        text-shadow: 0 1px 3px rgba(0,0,0,0.8) !important;
    }
    
    .customer-item.zone-no-zone,
    .assigned-customer.zone-no-zone {
        background-color: #6c757d !important;
        border-left: 4px solid #495057;
        color: #ffffff !important;
        text-shadow: 0 1px 3px rgba(0,0,0,0.8) !important;
    }
    
    /* Zone badge colors to match parent background */
    .zone-centro .zone-badge,
    .zone-mission .zone-badge,
    .zone-ruta-sour-flour .zone-badge,
    .zone-daly-city-san-mateo .zone-badge,
    .zone-north-bay .zone-badge,
    .zone-east-bay .zone-badge,
    .zone-no-zone .zone-badge {
        background-color: rgba(0,0,0,0.2) !important;
        color: #ffffff !important;
        text-shadow: 0 1px 3px rgba(0,0,0,0.9) !important;
        font-weight: 600 !important;
    }
    
    /* Driver Selection Styles */
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
        position: relative;
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

    .driver-status {
        font-size: 0.8rem;
        color: #6c757d;
        font-style: italic;
        position: absolute;
        bottom: 5px;
        left: 5px;
        right: 5px;
        text-align: center;
    }

    /* Day Selection Styles */
    .day-icons-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
        gap: 10px;
        margin-top: 10px;
    }

    .day-icon-option {
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 12px;
        border: 2px solid #dee2e6;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s ease;
        background: white;
    }

    .day-icon-option:hover {
        border-color: #007bff;
        background-color: #f8f9ff;
        transform: translateY(-1px);
        box-shadow: 0 2px 6px rgba(0, 123, 255, 0.15);
    }

    .day-icon-option.selected {
        border-color: #28a745;
        background-color: #f8fff9;
        box-shadow: 0 2px 6px rgba(40, 167, 69, 0.15);
    }

    .day-icon-preview {
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .day-icon-name {
        font-size: 0.9rem;
        font-weight: 600;
        text-align: center;
        color: #495057;
    }
    
    /* Zone Legend */
    .zone-legend {
        margin-bottom: 20px;
        padding: 15px;
        background-color: #f8f9fa;
        border-radius: 8px;
        border: 1px solid #dee2e6;
    }
    
    .zone-legend h4 {
        margin: 0 0 10px 0;
        color: #495057;
        font-size: 16px;
    }
    
    .zone-colors {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    
    .zone-color-item {
        padding: 6px 12px;
        border-radius: 4px;
        color: white;
        font-size: 0.85em;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-left: 4px solid rgba(0,0,0,0.2);
    }
    
    .zone-color-item .zone-name {
        text-shadow: 0 1px 3px rgba(0,0,0,0.8);
        font-weight: 700;
    }
    
    /* Zone color item specific background colors */
    .zone-color-item.zone-centro {
        background-color: #007bff;
    }
    
    .zone-color-item.zone-mission {
        background-color: #dc3545;
    }
    
    .zone-color-item.zone-ruta-sour-flour {
        background-color: #28a745;
    }
    
    .zone-color-item.zone-daly-city-san-mateo {
        background-color: #fd7e14;
    }
    
    .zone-color-item.zone-north-bay {
        background-color: #6f42c1;
    }
    
    .zone-color-item.zone-east-bay {
        background-color: #20c997;
    }
    
    .zone-color-item.zone-no-zone {
        background-color: #6c757d;
    }
    
    /* Zone header gradient colors using CSS custom properties */
    .zone-group-header.zone-centro {
        --zone-color: #007bff;
        --zone-color-dark: #0056b3;
    }
    
    .zone-group-header.zone-mission {
        --zone-color: #dc3545;
        --zone-color-dark: #a71e2a;
    }
    
    .zone-group-header.zone-ruta-sour-flour {
        --zone-color: #28a745;
        --zone-color-dark: #1e7e34;
    }
    
    .zone-group-header.zone-daly-city-san-mateo {
        --zone-color: #fd7e14;
        --zone-color-dark: #e55a00;
    }
    
    .zone-group-header.zone-north-bay {
        --zone-color: #6f42c1;
        --zone-color-dark: #542788;
    }
    
    .zone-group-header.zone-east-bay {
        --zone-color: #20c997;
        --zone-color-dark: #159570;
    }
    
    .zone-group-header.zone-no-zone {
        --zone-color: #6c757d;
        --zone-color-dark: #495057;
    }
    
    /* Responsive Design for Zone Legend */
    @media (max-width: 768px) {
        .zone-colors {
            gap: 6px;
        }
        
        .zone-color-item {
            padding: 4px 8px;
            font-size: 0.75em;
        }
        
        .zone-legend h4 {
            font-size: 14px;
        }
        
        .customer-item .zone-badge {
            font-size: 0.7em;
            padding: 1px 4px;
        }
        
        .assigned-customer .zone-badge {
            font-size: 0.7em;
            padding: 1px 4px;
        }
        
        .zone-group-header {
            padding: 15px 18px;
        }
        
        .zone-header-content {
            margin-right: 10px;
        }
        
        .zone-group-title {
            font-size: 16px;
        }
        
        .zone-customer-count {
            font-size: 0.8em;
            padding: 4px 10px;
        }
        
        .zone-toggle-icon {
            font-size: 16px;
        }
        
        .zone-group .customers-list {
            padding: 12px;
        }
    }
    
    @media (max-width: 480px) {
        .zone-legend {
            padding: 10px;
        }
        
        .zone-colors {
            gap: 4px;
        }
        
        .zone-color-item {
            padding: 3px 6px;
            font-size: 0.7em;
        }
        
        .zone-group-header {
            padding: 12px 15px;
        }
        
        .zone-header-content {
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
            margin-right: 8px;
        }
        
        .zone-group-title {
            font-size: 14px;
        }
        
        .zone-customer-count {
            align-self: flex-end;
            font-size: 0.75em;
            padding: 3px 8px;
        }
        
        .zone-toggle-icon {
            font-size: 14px;
        }
        
        .zone-group .customers-list {
            padding: 10px;
        }
        
        .driver-icons-grid {
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 10px;
        }
        
        .driver-icon-option {
            padding: 10px;
        }
        
        .driver-icon-preview {
            width: 32px;
            height: 32px;
            font-size: 16px;
        }
        
        .driver-icon-name {
            font-size: 0.8rem;
        }
        
        .day-icons-grid {
            grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
            gap: 8px;
        }
        
        .day-icon-option {
            padding: 8px;
        }
        
        .day-icon-name {
            font-size: 0.8rem;
        }
    }
    
    /* Customers list styles */
    .customers-container {
        margin-bottom: 30px;
        padding: 15px;
        background-color: #f8f9fa;
        border-radius: 5px;
    }
    
    .zone-group {
        margin-bottom: 25px;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        transition: all 0.3s ease;
        background: white;
        border: 1px solid rgba(0,0,0,0.05);
    }
    
    .zone-group:hover {
        box-shadow: 0 6px 25px rgba(0,0,0,0.15);
        transform: translateY(-2px);
    }
    
    .zone-group-header {
        padding: 18px 25px;
        color: white;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border: none;
        border-radius: 12px 12px 0 0;
        cursor: pointer;
        transition: all 0.3s ease;
        background: linear-gradient(135deg, var(--zone-color) 0%, var(--zone-color-dark) 100%);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        position: relative;
        overflow: hidden;
    }
    
    .zone-group-header::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0.05) 50%, rgba(0,0,0,0.05) 100%);
        pointer-events: none;
    }
    
    .zone-group-header:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.25);
        filter: brightness(1.08);
    }
    
    .zone-group-header:active {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    }
    
    .zone-header-content {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-grow: 1;
        margin-right: 15px;
        z-index: 1;
        position: relative;
    }
    
    .zone-toggle-icon {
        font-size: 18px;
        transition: transform 0.3s ease;
        user-select: none;
        z-index: 1;
        position: relative;
        opacity: 0.9;
    }
    
    .zone-group.collapsed .zone-toggle-icon {
        transform: rotate(-90deg);
    }
    
    .zone-group.collapsed .customers-list {
        display: none;
    }
    
    .zone-group-title {
        margin: 0;
        font-size: 18px;
        font-weight: 700;
        text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        letter-spacing: 0.5px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .zone-customer-count {
        background: rgba(255,255,255,0.25);
        backdrop-filter: blur(10px);
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 0.85em;
        font-weight: 600;
        border: 1px solid rgba(255,255,255,0.2);
        text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    .zone-group .customers-list {
        background-color: white;
        padding: 20px;
        border: none;
        border-top: 1px solid rgba(0,0,0,0.08);
        border-radius: 0 0 12px 12px;
    }
    
    .customers-list {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin: 0;
    }
    
    .customers-list .customer-item {
        background-color: #4e73df;
        color: white;
        padding: 10px 14px;
        border-radius: 4px;
        cursor: move;
        transition: all 0.2s;
        user-select: none;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        text-shadow: 0 1px 2px rgba(0,0,0,0.7);
        min-height: 32px;
        font-size: 0.95em;
        font-weight: 500;
        line-height: 1.3;
    }
    
    .customers-list .customer-item:hover {
        background-color: #2e59d9;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    }
    
    .customers-list .customer-item.clickable-customer {
        cursor: pointer;
        border: 2px solid transparent;
    }
    
    .customers-list .customer-item.clickable-customer:hover {
        border-color: #ffc107;
        box-shadow: 0 0 10px rgba(255, 193, 7, 0.3);
    }
    
    .customers-list .customer-item.filtered-clickable {
        border: 2px solid #ffc107;
        box-shadow: 0 0 10px rgba(255, 193, 7, 0.5);
    }
    
    .customers-list .customer-item.hidden-assigned {
        display: none;
    }
    
    /* Special styling for filtered mode */
    .customers-container.filtered-mode {
        border-left: 4px solid #28a745;
        background-color: #f8fff9;
    }
    
    .customers-container.filtered-mode h3::after {
        content: " (Unassigned Only)";
        color: #28a745;
        font-size: 0.8em;
        font-weight: normal;
    }
    
    .filter-info.active-filter {
        background-color: #d4edda;
        border-left: 4px solid #28a745;
        color: #155724;
    }
    
            .customer-draggable.visible {
            opacity: 1;
        }
        
        .day-cell.drag-over {
            background-color: #e9f7ef;
            border: 2px dashed #28a745;
    }
    
    /* Filtered view styles */
    .filtered-view .day-header:not(.active-filter) {
        display: none;
    }
    
    .filtered-view .day-cell:not(.show-day) {
        display: none;
    }
    
    .filtered-view .day-cell.show-day {
        display: block !important;
        flex: none;
        width: calc(100% - 150px);
    }
    
    .filtered-view .days-header {
        display: flex;
    }
    
    .filtered-view .driver-row {
        display: flex;
    }
    
    /* Modal styles */
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
    }
    
    .modal-content {
        background-color: #fefefe;
        margin: 15% auto;
        padding: 0;
        border: none;
        border-radius: 8px;
        width: 80%;
        max-width: 500px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    }
    
    .modal-header {
        padding: 20px;
        border-bottom: 1px solid #dee2e6;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .modal-header h3 {
        margin: 0;
        color: #495057;
    }
    
    .close {
        color: #aaa;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
        line-height: 1;
    }
    
    .close:hover {
        color: #000;
    }
    
    .modal-body {
        padding: 20px;
    }
    
    .selection-section {
        margin-bottom: 20px;
    }
    
    .selection-section h4 {
        margin: 0 0 10px 0;
        color: #495057;
        font-size: 16px;
    }
    
    .day-selection {
        margin-top: 10px;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 8px;
    }
    
    .day-selection label {
        display: flex;
        align-items: center;
        padding: 6px 0;
        cursor: pointer;
    }
    
    .day-selection input[type="radio"] {
        margin-right: 8px;
    }
    
    .driver-selection {
        margin-top: 10px;
    }
    
    .driver-selection label {
        display: block;
        padding: 8px 0;
        cursor: pointer;
        display: flex;
        align-items: center;
    }
    
    .driver-selection input[type="radio"] {
        margin-right: 10px;
    }
    
    .modal-footer {
        padding: 20px;
        border-top: 1px solid #dee2e6;
        text-align: right;
    }
    
    .btn {
        padding: 8px 16px;
        margin-left: 10px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
    }
    
    .btn-primary {
        background-color: #4e73df;
        color: white;
    }
    
    .btn-primary:hover {
        background-color: #2e59d9;
    }
    
    .btn-secondary {
        background-color: #6c757d;
        color: white;
    }
    
    .btn-secondary:hover {
        background-color: #545b62;
    }
    
    .btn-sm {
        padding: 4px 8px;
        font-size: 12px;
    }
    
    /* Driver click list styles (for filtered mode) */
    .driver-click-list {
        margin-top: 10px;
    }
    
    .driver-click-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 16px;
        margin: 6px 0;
        border: 2px solid #dee2e6;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.2s ease;
        background-color: #fff;
    }
    
    .driver-click-item:hover {
        border-color: #4e73df;
        background-color: #f8f9ff;
        transform: translateY(-1px);
        box-shadow: 0 2px 8px rgba(78, 115, 223, 0.15);
    }
    
    .driver-click-item.current-assignment {
        border-color: #28a745;
        background-color: #f8fff9;
    }
    
    .driver-click-item.current-assignment .driver-status {
        color: #28a745;
        font-weight: bold;
    }
    
    .driver-click-item.current-assignment:hover {
        border-color: #1e7e34;
        background-color: #f1f9f1;
    }
    
    .driver-name {
        font-weight: 500;
        color: #495057;
    }
    
    .driver-status {
        font-size: 0.9em;
        color: #6c757d;
        font-style: italic;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let draggedCustomer = null;
    let filteredDay = null;
    const days = {
        1: 'Monday',
        2: 'Tuesday', 
        3: 'Wednesday',
        4: 'Thursday',
        5: 'Friday',
        6: 'Saturday',
        7: 'Sunday'
    };
    
    // Day filtering functionality
    const dayHeaders = document.querySelectorAll('.clickable-day');
    const clearFilterBtn = document.getElementById('clear-filter');
    const filterStatus = document.getElementById('filter-status');
    const routesContainer = document.querySelector('.routes-container');
    const customerInstruction = document.getElementById('customer-instruction');
    
    dayHeaders.forEach(header => {
        header.addEventListener('click', function() {
            const day = this.getAttribute('data-day');
            filterByDay(day);
        });
    });
    
    clearFilterBtn.addEventListener('click', function() {
        clearDayFilter();
    });
    
    function filterByDay(day) {
        filteredDay = day;
        
        // Update visual state
        dayHeaders.forEach(h => h.classList.remove('active-filter'));
        document.querySelector(`[data-day="${day}"]`).classList.add('active-filter');
        
        // Hide/show appropriate cells
        routesContainer.classList.add('filtered-view');
        
        const allDayCells = document.querySelectorAll('.day-cell');
        allDayCells.forEach(cell => {
            if (cell.getAttribute('data-day') === day) {
                cell.classList.add('show-day');
            } else {
                cell.classList.remove('show-day');
            }
        });
        
        // Filter customers to show only unassigned ones for this day
        filterUnassignedCustomers(day);
        
        // Add visual styling for filtered mode
        document.querySelector('.customers-container').classList.add('filtered-mode');
        document.querySelector('.filter-info').classList.add('active-filter');
        
        // Update filter status
        filterStatus.textContent = `Showing: ${days[day]} (Unassigned Customers Only)`;
        clearFilterBtn.style.display = 'inline-block';
        
        // Update customer instruction
        customerInstruction.textContent = `Showing customers not assigned to any driver for ${days[day]}. Click to instantly assign or drag as usual. Click assigned customers to instantly reassign them.`;
        
        // Make customers clickable with special highlighting for filtered mode
        updateCustomerInteraction();
    }
    
    function filterUnassignedCustomers(day) {
        // Get all customers assigned to any driver for this day
        const assignedCustomerIds = new Set();
        const dayCells = document.querySelectorAll(`.day-cell[data-day="${day}"]`);
        
        dayCells.forEach(cell => {
            const assignedCustomers = cell.querySelectorAll('.assigned-customer');
            assignedCustomers.forEach(customer => {
                assignedCustomerIds.add(customer.getAttribute('data-customer-id'));
            });
        });
        
        // Show/hide customers based on assignment status
        const customerItems = document.querySelectorAll('.customer-item');
        customerItems.forEach(item => {
            const customerId = item.getAttribute('data-customer-id');
            
            if (assignedCustomerIds.has(customerId)) {
                // Customer is assigned - hide them
                item.style.display = 'none';
                item.classList.add('hidden-assigned');
            } else {
                // Customer is unassigned - show them
                item.style.display = 'block';
                item.classList.remove('hidden-assigned');
            }
        });
    }
    
    function clearDayFilter() {
        filteredDay = null;
        
        // Reset visual state
        dayHeaders.forEach(h => h.classList.remove('active-filter'));
        routesContainer.classList.remove('filtered-view');
        
        const allDayCells = document.querySelectorAll('.day-cell');
        allDayCells.forEach(cell => {
            cell.classList.remove('show-day');
        });
        
        // Show all customers again
        const customerItems = document.querySelectorAll('.customer-item');
        customerItems.forEach(item => {
            item.style.display = 'block';
            item.classList.remove('hidden-assigned');
        });
        
        // Remove visual styling for filtered mode
        document.querySelector('.customers-container').classList.remove('filtered-mode');
        document.querySelector('.filter-info').classList.remove('active-filter');
        
        // Update filter status
        filterStatus.textContent = 'Showing: All Days';
        clearFilterBtn.style.display = 'none';
        
        // Reset customer instruction
        customerInstruction.textContent = 'Click a customer to instantly assign them to a driver and day, or drag them to specific driver/day cells. Click assigned customers to instantly reassign them. Customers are organized and color-coded by their delivery zone.';
        
        // Update customer interaction
        updateCustomerInteraction();
    }
    
    function updateCustomerInteraction() {
        const customerItems = document.querySelectorAll('.customer-item');
        customerItems.forEach(item => {
            // Remove existing classes
            item.classList.remove('filtered-clickable');
            
            if (filteredDay) {
                item.classList.add('filtered-clickable');
                item.title = `Click to assign to ${days[filteredDay]}`;
            } else {
                item.title = 'Click to assign to driver and day';
            }
        });
    }
    
    // Modal functionality
    const modal = document.getElementById('assignment-modal');
    const modalCustomerName = document.getElementById('modal-customer-name');
    const modalDayName = document.getElementById('modal-day-name');
    const modalDayContext = document.getElementById('modal-day-context');
    const daySelectionSection = document.getElementById('day-selection-section');
    const driverSelectionSection = document.getElementById('driver-selection-section');
    const driverClickSection = document.getElementById('driver-click-section');
    
    let currentCustomerId = null;
    let currentDayOfWeek = null;
    let selectedDriverId = null;
    let selectedDayOfWeek = null;
    
    // Customer click functionality (works always now)
    document.addEventListener('click', function(e) {
        // Handle clicks on customer items in the customer list
        const customerItem = e.target.closest('.customer-item');
        if (customerItem && !e.target.closest('.zone-group-header')) {
            e.preventDefault();
            e.stopPropagation();
            
            currentCustomerId = customerItem.getAttribute('data-customer-id');
            const customerName = customerItem.getAttribute('data-customer-name');
            
            // Update modal content
            modalCustomerName.textContent = customerName;
            
            if (filteredDay) {
                // Filtered mode - show clickable driver list, hide traditional controls
                currentDayOfWeek = filteredDay;
                modalDayName.textContent = days[filteredDay];
                modalDayContext.style.display = 'inline';
                daySelectionSection.style.display = 'none';
                driverSelectionSection.style.display = 'none';
                driverClickSection.style.display = 'block';
                
                // Update driver click list with current assignment status
                updateDriverClickList(currentCustomerId, currentDayOfWeek);
            } else {
                // Non-filtered mode - show visual interface
                currentDayOfWeek = null;
                modalDayContext.style.display = 'none';
                daySelectionSection.style.display = 'block';
                driverSelectionSection.style.display = 'block';
                driverClickSection.style.display = 'none';
                
                // Reset day selection to Monday (first option)
                selectedDayOfWeek = '1';
                const dayOptions = document.querySelectorAll('.day-icon-option');
                dayOptions.forEach(option => {
                    option.classList.remove('selected');
                    if (option.getAttribute('data-day') === '1') {
                        option.classList.add('selected');
                    }
                });
                
                // Reset driver selection
                selectedDriverId = '0';
                const driverOptions = document.querySelectorAll('#driver-selection-section .driver-icon-option');
                driverOptions.forEach(option => {
                    option.classList.remove('selected');
                    if (option.getAttribute('data-driver-id') === '0') {
                        option.classList.add('selected');
                    }
                });
                
                // Check for existing assignment for the default selected day
                updateDriverSelectionForDay('1');
            }
            
            // Show modal
            modal.style.display = 'block';
        }
        
        // Handle clicks on assigned customers to reassign them
        const assignedCustomer = e.target.closest('.assigned-customer');
        if (assignedCustomer && !e.target.classList.contains('delete-customer')) {
            e.preventDefault();
            e.stopPropagation();
            
            currentCustomerId = assignedCustomer.getAttribute('data-customer-id');
            const customerName = assignedCustomer.getAttribute('data-customer-name');
            const dayCell = assignedCustomer.closest('.day-cell');
            const currentDay = dayCell.getAttribute('data-day');
            
            // Update modal content
            modalCustomerName.textContent = customerName;
            
            if (filteredDay) {
                // Filtered mode - show clickable driver list, hide traditional controls
                currentDayOfWeek = filteredDay;
                modalDayName.textContent = days[filteredDay];
                modalDayContext.style.display = 'inline';
                daySelectionSection.style.display = 'none';
                driverSelectionSection.style.display = 'none';
                driverClickSection.style.display = 'block';
                
                // Update driver click list with current assignment status
                updateDriverClickList(currentCustomerId, currentDayOfWeek);
            } else {
                // Non-filtered mode - show visual interface
                currentDayOfWeek = null;
                modalDayContext.style.display = 'none';
                daySelectionSection.style.display = 'block';
                driverSelectionSection.style.display = 'block';
                driverClickSection.style.display = 'none';
                
                // Set the day to the current assignment day
                selectedDayOfWeek = currentDay;
                const dayOptions = document.querySelectorAll('.day-icon-option');
                dayOptions.forEach(option => {
                    option.classList.remove('selected');
                    if (option.getAttribute('data-day') === currentDay) {
                        option.classList.add('selected');
                    }
                });
                
                // Set driver selection based on current assignment
                updateDriverSelectionForDay(currentDay);
            }
            
            // Show modal
            modal.style.display = 'block';
        }
    });
    
    // Driver click functionality is now handled by the visual interface onclick handlers
    
    function updateDriverClickList(customerId, dayOfWeek) {
        const existingAssignment = findExistingAssignment(customerId, dayOfWeek);
        const driverItems = document.querySelectorAll('#driver-click-section .driver-icon-option');
        
        driverItems.forEach(item => {
            const driverId = item.getAttribute('data-driver-id');
            const statusSpan = item.querySelector('.driver-status');
            
            // Remove existing status classes
            item.classList.remove('selected');
            
            if (driverId === existingAssignment) {
                item.classList.add('selected');
                statusSpan.textContent = '(Current)';
            } else {
                statusSpan.textContent = '';
            }
        });
    }
    
    async function saveDriverAssignment(driverId, dayOfWeek) {
        // Store the current filter state before reload
        if (filteredDay) {
            localStorage.setItem('preserveFilterDay', filteredDay);
        }
        
        try {
            const response = await fetch('standing_routes.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=save_route&driver_id=${driverId}&customer_id=${currentCustomerId}&day_of_week=${dayOfWeek}`
            });
            
            const result = await response.json();
            
            if (result.success) {
                modal.style.display = 'none';
                // Reload the page to show updated routes
                window.location.reload();
            } else {
                alert('Error saving assignment: ' + (result.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Error saving assignment');
        }
    }
    
    function updateDriverSelectionForDay(dayOfWeek) {
        if (currentCustomerId) {
            const existingAssignment = findExistingAssignment(currentCustomerId, dayOfWeek);
            selectedDriverId = existingAssignment || '0';
            
            const driverOptions = document.querySelectorAll('#driver-selection-section .driver-icon-option');
            driverOptions.forEach(option => {
                option.classList.remove('selected');
                if (option.getAttribute('data-driver-id') === selectedDriverId) {
                    option.classList.add('selected');
                }
            });
        }
    }
    
    function findExistingAssignment(customerId, dayOfWeek) {
        const assignedCustomer = document.querySelector(
            `.day-cell[data-day="${dayOfWeek}"] .assigned-customer[data-customer-id="${customerId}"]`
        );
        
        if (assignedCustomer) {
            const dayCell = assignedCustomer.closest('.day-cell');
            return dayCell.getAttribute('data-driver-id');
        }
        
        return null;
    }
    
    // Close modal when clicking outside
    window.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.style.display = 'none';
        }
    });
    
    // Note: Day selection change handling is now done via visual interface onclick handlers
    
    // Close modal when clicking the X button
    const closeBtn = document.querySelector('.close');
    closeBtn.addEventListener('click', function() {
        modal.style.display = 'none';
    });
    
    // Initialize customer interaction
    updateCustomerInteraction();
    
    // Zone group toggle functionality
    window.toggleZoneGroup = function(headerElement) {
        const zoneGroup = headerElement.closest('.zone-group');
        zoneGroup.classList.toggle('collapsed');
    };
    
    // Visual driver selection functions
    window.selectDriverInModal = function(driverId) {
        selectedDriverId = driverId || '0';
        
        // Update visual selection
        const driverOptions = document.querySelectorAll('#driver-selection-section .driver-icon-option');
        driverOptions.forEach(option => {
            option.classList.remove('selected');
            if (option.getAttribute('data-driver-id') === selectedDriverId) {
                option.classList.add('selected');
            }
        });
        
        // Automatically save the assignment
        const dayOfWeek = filteredDay || selectedDayOfWeek || '1';
        saveDriverAssignment(selectedDriverId, dayOfWeek);
    };
    
    window.selectDriverFilteredMode = function(driverId) {
        // Automatically save the assignment in filtered mode
        saveDriverAssignment(driverId || '0', currentDayOfWeek);
    };
    
    window.selectDayInModal = function(dayOfWeek) {
        selectedDayOfWeek = dayOfWeek;
        
        // Update visual selection
        const dayOptions = document.querySelectorAll('.day-icon-option');
        dayOptions.forEach(option => {
            option.classList.remove('selected');
            if (option.getAttribute('data-day') === dayOfWeek) {
                option.classList.add('selected');
            }
        });
        
        // Update driver selection based on current assignment for this day
        updateDriverSelectionForDay(dayOfWeek);
        
        // If there's a current driver selection, automatically save the assignment
        if (selectedDriverId && selectedDriverId !== '0') {
            saveDriverAssignment(selectedDriverId, dayOfWeek);
        }
    };
    
    // Check for preserved filter on page load
    const preservedFilterDay = localStorage.getItem('preserveFilterDay');
    if (preservedFilterDay) {
        localStorage.removeItem('preserveFilterDay');
        // Apply the filter after a short delay to ensure everything is loaded
        setTimeout(() => {
            filterByDay(preservedFilterDay);
        }, 100);
    }
    
    // Make day cells droppable
    const dayCells = document.querySelectorAll('.day-cell');
    
    // Make all customer items draggable
    document.querySelectorAll('.customer-item').forEach(customerItem => {
        customerItem.addEventListener('dragstart', function(e) {
            // Only allow drag if in filtered mode or if we want to allow both
            // For now, let's allow both drag and click functionality
            
            draggedCustomer = {
                id: this.getAttribute('data-customer-id'),
                name: this.getAttribute('data-customer-name'),
                element: this
            };
            
            // Add visual feedback
            this.style.opacity = '0.4';
            
            // Set drag data
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', JSON.stringify({
                customerId: draggedCustomer.id,
                customerName: draggedCustomer.name
            }));
        });
        
        customerItem.addEventListener('dragend', function() {
            this.style.opacity = '1';
            draggedCustomer = null;
        });
    });
    
    // Handle drag over for day cells
    dayCells.forEach(cell => {
        cell.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('drag-over');
        });
        
        cell.addEventListener('dragleave', function() {
            this.classList.remove('drag-over');
        });
        
        cell.addEventListener('drop', async function(e) {
            e.preventDefault();
            this.classList.remove('drag-over');
            
            if (!draggedCustomer) return;
            
            const driverId = this.getAttribute('data-driver-id');
            const dayOfWeek = this.getAttribute('data-day');
            
            // Store the current filter state before reload
            if (filteredDay) {
                localStorage.setItem('preserveFilterDay', filteredDay);
            }
            
            try {
                const response = await fetch('standing_routes.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=save_route&driver_id=${driverId}&customer_id=${draggedCustomer.id}&day_of_week=${dayOfWeek}`
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Reload the page to show updated routes
                    window.location.reload();
                } else {
                    alert('Error saving route: ' + (result.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error saving route');
            }
        });
    });
    
    // Make assigned customer items draggable
    function makeAssignedItemsDraggable() {
        document.querySelectorAll('.assigned-customer').forEach(item => {
            item.setAttribute('draggable', 'true');
            
            item.addEventListener('dragstart', function(e) {
                draggedCustomer = {
                    id: this.getAttribute('data-customer-id'),
                    name: this.getAttribute('data-customer-name'),
                    element: this
                };
                
                this.style.opacity = '0.4';
                
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', JSON.stringify({
                    customerId: draggedCustomer.id,
                    customerName: draggedCustomer.name
                }));
                
                // Store the original location to handle reordering
                this._originalParent = this.parentNode;
            });
            
            item.addEventListener('dragend', function() {
                this.style.opacity = '1';
                draggedCustomer = null;
                
                // If the item was dragged but not dropped in a valid target, return it
                if (this.parentNode !== this._originalParent) {
                    this._originalParent.appendChild(this);
                }
            });
        });
    }
    
    // Initialize draggable items
    makeAssignedItemsDraggable();
    
    // Handle customer deletion
    document.addEventListener('click', async function(e) {
        if (e.target.classList.contains('delete-customer')) {
            e.stopPropagation();
            const customerItem = e.target.closest('.assigned-customer');
            const customerId = customerItem.getAttribute('data-customer-id');
            const dayOfWeek = customerItem.closest('.day-cell').getAttribute('data-day');
            
            // Store the current filter state before reload
            if (filteredDay) {
                localStorage.setItem('preserveFilterDay', filteredDay);
            }
            
            try {
                const response = await fetch('standing_routes.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=save_route&driver_id=0&customer_id=${customerId}&day_of_week=${dayOfWeek}`
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // For deletion, we can just remove the element without full page reload
                    // But to maintain consistency and ensure data integrity, let's reload
                    window.location.reload();
                } else {
                    alert('Error removing customer: ' + (result.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error removing customer');
            }
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
