<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load essential includes first
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/standing_route_analysis.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = (string)$_POST['action'];
    try {
        if ($action === 'save_route') {
            bakery_standing_route_save(
                $db,
                (int)$_POST['customer_id'],
                (int)$_POST['driver_id'],
                (int)$_POST['day_of_week']
            );
            echo json_encode(['success' => true]);
            exit;
        }
        if ($action === 'apply_suggested') {
            $dayRaw = $_POST['day_of_week'] ?? '';
            $onlyDay = $dayRaw === '' || $dayRaw === null ? null : (int)$dayRaw;
            $applied = bakery_standing_route_apply_suggestions($db, (int)$_POST['customer_id'], $onlyDay);
            echo json_encode(['success' => true, 'applied' => $applied]);
            exit;
        }
        throw new RuntimeException('Unknown standing route action.');
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Load the rest of the includes for normal page load
require_once 'includes/header.php';
require_once 'includes/nav.php';
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/standing_routes.css'); ?>">
<?php

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

$days = bakery_day_names(false);
$view = (string)($_GET['view'] ?? 'stores');
if ($view !== 'board') {
    $view = 'stores';
}
$analysis = $view === 'stores' ? bakery_standing_route_store_analysis($db) : null;
$dayShort = bakery_day_names(true);
?>

<div class="container">
    <h1><?php echo htmlspecialchars($view === 'stores' ? bakery_t('standing_routes.stores_title') : bakery_t('standing_routes.board_title'), ENT_QUOTES, 'UTF-8'); ?></h1>
    <div class="sr-view-tabs" role="tablist">
        <a class="sr-view-tab<?php echo $view === 'stores' ? ' is-active' : ''; ?>" href="standing_routes.php?view=stores"><?php bakery_te('standing_routes.view_stores'); ?></a>
        <a class="sr-view-tab<?php echo $view === 'board' ? ' is-active' : ''; ?>" href="standing_routes.php?view=board"><?php bakery_te('standing_routes.view_board'); ?></a>
    </div>
<?php if ($view === 'stores' && is_array($analysis)): ?>
    <p class="instruction-text"><?php bakery_te('standing_routes.stores_help'); ?></p>
    <p class="sr-history-window"><?php bakery_te('standing_routes.history_window', ['from' => $analysis['bounds'][0], 'to' => $analysis['bounds'][1]]); ?></p>
    <div class="sr-summary">
        <span><?php bakery_te('standing_routes.summary_stores', ['n' => $analysis['summary']['stores']]); ?></span>
        <span><?php bakery_te('standing_routes.summary_match', ['n' => $analysis['summary']['match']]); ?></span>
        <span><?php bakery_te('standing_routes.summary_add', ['n' => $analysis['summary']['add']]); ?></span>
        <span><?php bakery_te('standing_routes.summary_change', ['n' => $analysis['summary']['change']]); ?></span>
        <span><?php bakery_te('standing_routes.summary_ask', ['n' => $analysis['summary']['ask']]); ?></span>
        <span><?php bakery_te('standing_routes.summary_orders', ['n' => $analysis['summary']['orders']]); ?></span>
    </div>
    <div class="sr-filters">
        <label>
            <span><?php bakery_te('standing_routes.search'); ?></span>
            <input type="search" id="sr-store-search" autocomplete="off">
        </label>
        <label>
            <span><?php bakery_te('standing_routes.filter_zone'); ?></span>
            <select id="sr-filter-zone">
                <option value=""><?php bakery_te('standing_routes.all_zones'); ?></option>
                <?php
                $zones = [];
                foreach ($analysis['stores'] as $store) {
                    $zones[$store['zone'] === '' ? 'No Zone' : $store['zone']] = true;
                }
                ksort($zones);
                foreach (array_keys($zones) as $zoneName):
                ?>
                    <option value="<?php echo htmlspecialchars($zoneName, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($zoneName, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span><?php bakery_te('standing_routes.filter_status'); ?></span>
            <select id="sr-filter-status">
                <option value=""><?php bakery_te('standing_routes.all_statuses'); ?></option>
                <option value="add"><?php bakery_te('standing_routes.status_add'); ?></option>
                <option value="change"><?php bakery_te('standing_routes.status_change'); ?></option>
                <option value="orders"><?php bakery_te('standing_routes.status_orders'); ?></option>
                <option value="ask"><?php bakery_te('standing_routes.status_ask'); ?></option>
                <option value="match"><?php bakery_te('standing_routes.status_match'); ?></option>
                <option value="fill"><?php bakery_te('standing_routes.status_fill'); ?></option>
            </select>
        </label>
    </div>
    <div class="sr-store-board" id="sr-store-board">
        <table class="sr-store-table">
            <thead>
                <tr>
                    <th><?php bakery_te('standing_routes.col_store'); ?></th>
                    <th><?php bakery_te('standing_routes.col_zone'); ?></th>
                    <?php foreach ($dayShort as $dow => $label): ?>
                        <th><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></th>
                    <?php endforeach; ?>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($analysis['stores'] as $store):
                $storeStatuses = [];
                foreach ($store['days'] as $cell) {
                    $storeStatuses[$cell['status']] = true;
                }
                $zoneLabel = $store['zone'] !== '' ? $store['zone'] : 'No Zone';
                ?>
                <tr class="sr-store-row"
                    data-store-name="<?php echo htmlspecialchars(strtolower($store['name']), ENT_QUOTES, 'UTF-8'); ?>"
                    data-zone="<?php echo htmlspecialchars($zoneLabel, ENT_QUOTES, 'UTF-8'); ?>"
                    data-statuses="<?php echo htmlspecialchars(implode(' ', array_keys($storeStatuses)), ENT_QUOTES, 'UTF-8'); ?>">
                    <td class="sr-store-name">
                        <a href="customer_record.php?customer_id=<?php echo (int)$store['id']; ?>"><?php echo htmlspecialchars($store['name'], ENT_QUOTES, 'UTF-8'); ?></a>
                        <a class="sr-orders-link" href="standing_orders_manager.php"><?php bakery_te('standing_routes.edit_orders'); ?></a>
                    </td>
                    <td><?php echo htmlspecialchars($zoneLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                    <?php foreach ($days as $dow => $_dayName):
                        $cell = $store['days'][$dow];
                        $suggestName = '';
                        if (!empty($cell['suggest_driver_id'])) {
                            foreach ($analysis['drivers'] as $driver) {
                                if ((int)$driver['id'] === (int)$cell['suggest_driver_id']) {
                                    $suggestName = (string)$driver['name'];
                                    break;
                                }
                            }
                        }
                        $mixBits = [];
                        foreach ($cell['mix'] as $mixRow) {
                            $mixBits[] = $mixRow['name'] . ' ' . $mixRow['visits'];
                        }
                        ?>
                        <td class="sr-day-cell sr-status-<?php echo htmlspecialchars($cell['status'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-status="<?php echo htmlspecialchars($cell['status'], ENT_QUOTES, 'UTF-8'); ?>">
                            <label class="sr-driver-label">
                                <span class="sr-visually-hidden"><?php echo htmlspecialchars($store['name'] . ' ' . $dayShort[$dow], ENT_QUOTES, 'UTF-8'); ?></span>
                                <select class="sr-driver-select"
                                        data-customer-id="<?php echo (int)$store['id']; ?>"
                                        data-day="<?php echo (int)$dow; ?>">
                                    <option value="0"><?php bakery_te('standing_routes.no_driver'); ?></option>
                                    <?php foreach ($analysis['drivers'] as $driver): ?>
                                        <option value="<?php echo (int)$driver['id']; ?>"<?php echo (int)($cell['standing_driver_id'] ?? 0) === (int)$driver['id'] ? ' selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string)$driver['name'], ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <div class="sr-cell-usual">
                                <?php
                                if ($cell['usual_name']) {
                                    bakery_te('standing_routes.usual', [
                                        'driver' => $cell['usual_name'],
                                        'visits' => $cell['usual_visits'],
                                    ]);
                                } elseif ($mixBits) {
                                    echo htmlspecialchars(implode(' · ', $mixBits), ENT_QUOTES, 'UTF-8');
                                } elseif ($cell['has_orders']) {
                                    bakery_te('standing_routes.order_units', ['n' => $cell['order_units']]);
                                } else {
                                    echo '—';
                                }
                                ?>
                            </div>
                            <div class="sr-cell-reason"><?php echo htmlspecialchars(bakery_t($cell['reason_key'], $cell['reason_params']), ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php if ($cell['applyable'] && $suggestName !== ''): ?>
                                <button type="button"
                                        class="sr-apply"
                                        data-customer-id="<?php echo (int)$store['id']; ?>"
                                        data-day="<?php echo (int)$dow; ?>"
                                        data-driver-id="<?php echo (int)$cell['suggest_driver_id']; ?>">
                                    <?php bakery_te('standing_routes.apply_to', ['driver' => $suggestName]); ?>
                                </button>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td>
                        <?php if ($store['applyable_count'] > 0): ?>
                            <button type="button" class="sr-apply-store" data-customer-id="<?php echo (int)$store['id']; ?>">
                                <?php bakery_te('standing_routes.apply_all', ['n' => $store['applyable_count']]); ?>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    
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
<?php endif; ?>
</div>



<script src="<?php echo bakery_asset_href('includes/standing_routes.js'); ?>" defer></script>


<?php require_once 'includes/footer.php'; ?>
