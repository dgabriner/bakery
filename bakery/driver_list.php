<?php
// Security check
define('ACCESS_ALLOWED', true);
define('BAKERY_PAGE_BUILD', 'driver-list-simple-20260729');

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';

$legacyRouteUser = function_exists('bakery_current_user') ? bakery_current_user() : null;
if ($legacyRouteUser && bakery_is_driver_route_role($legacyRouteUser['role_slug'] ?? '')) {
    header('Location: ' . BASE_URL . 'driver.php');
    exit;
}

$page_title = bakery_t('page.driver_list');

// Get selected driver and date
$selectedDriverId = isset($_GET['driver_id']) ? intval($_GET['driver_id']) : 0;
$selectedDate = $_GET['date'] ?? date('Y-m-d');

// Get driver information
$driver = null;
if ($selectedDriverId > 0) {
    $stmt = $db->prepare("SELECT id, name FROM drivers WHERE id = ?");
    $stmt->execute([$selectedDriverId]);
    $driver = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all active drivers for the dropdown
$drivers = [];
foreach (bakery_get_drivers($db) as $driverData) {
    $drivers[$driverData['id']] = $driverData['name'];
}

// Allow viewing an archived driver when accessed directly by ID
if ($selectedDriverId > 0 && !isset($drivers[$selectedDriverId])) {
    $archivedDriver = bakery_get_driver_by_id($db, $selectedDriverId);
    if ($archivedDriver) {
        $driver = $archivedDriver;
        $drivers[$archivedDriver['id']] = $archivedDriver['name'];
    }
}

// Get zone colors for consistent display
$zoneColors = [
    '#007bff', '#28a745', '#dc3545', '#fd7e14', '#6f42c1', 
    '#20c997', '#ffc107', '#e83e8c', '#6c757d', '#17a2b8',
    '#6610f2', '#fd7e14', '#e83e8c', '#6f42c1', '#20c997'
];

$zoneColorMap = [];
$zoneIndex = 0;
$driverDeliveries = [];
$error = null;

if ($selectedDriverId > 0 && $driver) {
    try {
        // Get all daily orders assigned to this driver for the selected date
        $stmt = $db->prepare("
            SELECT 
                c.id as customer_id,
                c.name as customer_name,
                c.address as customer_address,
                c.phone as customer_phone,
                c.zone,
                do.id as daily_order_id,
                do.total_amount,
                doa.route_order,
                doa.scheduled_delivery_time,
                doa.delivery_status,
                doa.driver_id,
                d.name as driver_name
            FROM daily_orders do
            INNER JOIN customers c ON do.customer_id = c.id
            " . bakery_sfb_ops_origin_clause('c', $db) . "
            INNER JOIN daily_order_assignments doa ON do.id = doa.daily_order_id
            INNER JOIN drivers d ON doa.driver_id = d.id
            WHERE doa.driver_id = ? AND do.order_date = ?
            ORDER BY doa.route_order, c.zone, c.name
        ");
        
        $stmt->execute([$selectedDriverId, $selectedDate]);
        $results = $stmt->fetchAll();
        
        // Process the data to create a zone-grouped structure
        foreach ($results as $row) {
            $zone = $row['zone'] ?: 'No Zone';
            
            // Assign color to zone if not already assigned
            if (!isset($zoneColorMap[$zone])) {
                $zoneColorMap[$zone] = $zoneColors[$zoneIndex % count($zoneColors)];
                $zoneIndex++;
            }
            
            // Group by zone
            if (!isset($driverDeliveries[$zone])) {
                $driverDeliveries[$zone] = [];
            }
            
            $driverDeliveries[$zone][] = [
                'customer_id' => $row['customer_id'],
                'customer_name' => $row['customer_name'],
                'customer_address' => $row['customer_address'],
                'customer_phone' => $row['customer_phone'] ?? '',
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
        }
        
        // Calculate statistics
        $totalStops = count($results);
        $totalAmount = array_sum(array_column($results, 'total_amount'));
        $totalZones = count($driverDeliveries);
        
    } catch (Exception $e) {
        $error = 'Error loading driver data: ' . htmlspecialchars($e->getMessage());
    }
}

require_once 'includes/header.php';
require_once 'includes/nav.php';

$driverCompletedStops = 0;
foreach ($driverDeliveries as $zoneStops) {
    foreach ($zoneStops as $stop) {
        if (($stop['delivery_status'] ?? '') === 'delivered') {
            $driverCompletedStops++;
        }
    }
}
?>

<link rel="stylesheet" href="<?php echo bakery_asset_href('css/driver.css'); ?>">
<script src="<?php echo bakery_asset_href('includes/global_tracking.js'); ?>"></script>

<style>
.driver-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
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

.driver-selector {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    margin-bottom: 30px;
    text-align: center;
}

.driver-selector select {
    padding: 10px 15px;
    border: 2px solid #dee2e6;
    border-radius: 8px;
    font-size: 1rem;
    min-width: 200px;
    margin-right: 10px;
}

.driver-selector button {
    padding: 10px 20px;
    background: #007bff;
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 1rem;
    cursor: pointer;
    transition: background-color 0.2s ease;
}

.driver-selector button:hover {
    background: #0056b3;
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
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

.stops-list {
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.stop-item {
    background: linear-gradient(135deg, var(--zone-color) 0%, var(--zone-color-dark) 100%);
    color: white;
    padding: 15px 20px;
    border-radius: 10px;
    border-left: 4px solid var(--zone-color);
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    position: relative;
}

.stop-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.15);
}

.stop-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.customer-name {
    font-weight: 600;
    font-size: 1rem;
}

.driver-initial {
    background: rgba(255, 255, 255, 0.2);
    color: white;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 0.9rem;
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

/* Zone Color Legend */
.zone-legend-section {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    margin-bottom: 30px;
}

.zone-legend-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    margin-top: 15px;
}

.zone-legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: #f8f9fa;
    border-radius: 8px;
    font-size: 0.9rem;
}

.zone-color-badge {
    width: 20px;
    height: 20px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
}
</style>

<div class="driver-container driver-page-container">
    <div class="page-header">
        <h1>Driver Route</h1>
        <p>Daily stops with tap-to-call and navigation</p>
    </div>

    <!-- Driver Selector -->
    <div class="driver-selector">
        <form method="GET" action="">
            <select name="driver_id" onchange="this.form.submit()">
                <option value="">Select a Driver</option>
                <?php foreach ($drivers as $driverId => $driverName): ?>
                <option value="<?php echo $driverId; ?>" <?php echo $selectedDriverId == $driverId ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($driverName); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>">
        </form>
    </div>

    <?php if ($selectedDriverId > 0 && $driver): ?>
    
    <!-- Date Navigation -->
    <div class="date-navigation">
        <div class="date-info">
            <h2>Orders for <?php echo htmlspecialchars($driver['name']); ?> on <?php echo date('l, F j, Y', strtotime($selectedDate)); ?></h2>
            <span class="order-count"><?php echo $driverCompletedStops; ?> of <?php echo $totalStops ?? 0; ?> stops complete</span>
        </div>
        <div class="date-controls">
            <a href="?driver_id=<?php echo $selectedDriverId; ?>&date=<?php echo date('Y-m-d', strtotime($selectedDate . ' -1 day')); ?>" class="btn btn-outline">← Previous Day</a>
            <a href="?driver_id=<?php echo $selectedDriverId; ?>&date=<?php echo date('Y-m-d'); ?>" class="btn btn-primary">Today</a>
            <a href="?driver_id=<?php echo $selectedDriverId; ?>&date=<?php echo date('Y-m-d', strtotime($selectedDate . ' +1 day')); ?>" class="btn btn-outline">Next Day →</a>
        </div>
    </div>

    <?php if (($totalStops ?? 0) > 0): ?>
    <div class="driver-sticky-bar" aria-live="polite">
        <p class="sticky-title">Route progress</p>
        <div class="route-progress-text"><?php echo $driverCompletedStops; ?> of <?php echo $totalStops; ?> stops complete</div>
        <div class="route-progress-track"><div class="route-progress-fill" style="width: <?php echo $totalStops > 0 ? round(($driverCompletedStops / $totalStops) * 100) : 0; ?>%;"></div></div>
    </div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="stats-overview">
        <div class="stat-card">
            <h3>📊 Driver Statistics</h3>
            <div class="stat-grid">
                <div class="stat-item">
                    <span class="stat-number"><?php echo $totalStops ?? 0; ?></span>
                    <span class="stat-label">Total Stops</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?php echo $totalZones ?? 0; ?></span>
                    <span class="stat-label">Active Zones</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number">$<?php echo number_format($totalAmount ?? 0, 2); ?></span>
                    <span class="stat-label">Total Amount</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?php echo $driver['name'] ? strtoupper(substr($driver['name'], 0, 1)) : 'X'; ?></span>
                    <span class="stat-label">Driver</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Zone Color Legend -->
    <?php if (!empty($zoneColorMap)): ?>
    <div class="zone-legend-section">
        <h3>🗺️ Zone Color Legend</h3>
        <div class="zone-legend-grid">
            <?php foreach ($zoneColorMap as $zoneName => $zoneColor): ?>
            <div class="zone-legend-item">
                <div class="zone-color-badge" style="background-color: <?php echo $zoneColor; ?>">
                    🗺️
                </div>
                <span><?php echo htmlspecialchars($zoneName); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Zones and Stops -->
    <div class="zones-container">
        <?php if (!empty($driverDeliveries)): ?>
            <?php foreach ($driverDeliveries as $zoneName => $zoneStops): ?>
            <div class="zone-section">
                <div class="zone-header">
                    <h2 class="zone-title">🗺️ <?php echo htmlspecialchars($zoneName); ?> (<?php echo count($zoneStops); ?> stops)</h2>
                    <div class="zone-stats">
                        <div class="zone-stat">
                            <span class="zone-stat-number"><?php echo count($zoneStops); ?></span>
                            <span class="zone-stat-label">Stops</span>
                        </div>
                        <div class="zone-stat">
                            <span class="zone-stat-number">$<?php echo number_format(array_sum(array_column($zoneStops, 'total_amount')), 2); ?></span>
                            <span class="zone-stat-label">Total Amount</span>
                        </div>
                    </div>
                </div>
                
                <div class="stops-list">
                    <?php foreach ($zoneStops as $stop):
                        $phoneHref = preg_replace('/\D+/', '', (string)($stop['customer_phone'] ?? ''));
                        $phoneHref = $phoneHref !== '' ? 'tel:' . $phoneHref : '';
                        $mapsHref = $stop['customer_address'] ? 'https://maps.google.com/?q=' . rawurlencode($stop['customer_address']) : '';
                        $stopStatus = $stop['delivery_status'] ?? 'pending';
                        $statusClass = in_array($stopStatus, ['pending', 'in_transit', 'delivered', 'failed', 'cancelled'], true) ? $stopStatus : 'pending';
                    ?>
                    <div class="stop-item" 
                        style="--zone-color: <?php echo $stop['zone_color']; ?>; --zone-color-dark: <?php echo $stop['zone_color']; ?>"
                        data-customer-id="<?php echo $stop['customer_id']; ?>"
                        data-daily-order-id="<?php echo $stop['daily_order_id']; ?>">
                        <div class="stop-header">
                            <div class="customer-name"><?php echo htmlspecialchars($stop['customer_name']); ?></div>
                            <span class="status-badge status-badge--<?php echo htmlspecialchars($statusClass); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $statusClass))); ?></span>
                        </div>
                        <div class="contact-actions">
                            <?php if ($phoneHref): ?><a class="contact-link contact-link--phone" href="<?php echo htmlspecialchars($phoneHref); ?>">📞 Call</a><?php endif; ?>
                            <?php if ($mapsHref): ?><a class="contact-link contact-link--address" href="<?php echo htmlspecialchars($mapsHref); ?>" target="_blank" rel="noopener">🗺 Navigate</a><?php endif; ?>
                            <a class="contact-link" href="driver_list.php?driver_id=<?php echo $selectedDriverId; ?>&date=<?php echo urlencode($selectedDate); ?>&view=delivery">📷 Photos / Complete</a>
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
            <div class="empty-state">
                <h3>No orders assigned</h3>
                <p>No orders have been assigned to <?php echo htmlspecialchars($driver['name']); ?> for <?php echo date('l, F j, Y', strtotime($selectedDate)); ?></p>
            </div>
        <?php endif; ?>
    </div>
    
    <?php elseif ($selectedDriverId > 0): ?>
        <div class="empty-state">
            <h3>Driver not found</h3>
            <p>The selected driver could not be found.</p>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <h3>Select a Driver</h3>
            <p>Please select a driver from the dropdown above to view their daily orders.</p>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Inline Order Details functionality
    function addCustomerClickHandlers() {
        // Stop items
        document.querySelectorAll('.stop-item').forEach(item => {
            item.addEventListener('click', function(e) {
                e.stopPropagation();
                const customerId = this.dataset.customerId;
                const customerName = this.querySelector('.customer-name').textContent;
                console.log('Stop item clicked:', { customerId, customerName });
                toggleOrderDetails(this, customerId, customerName);
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
});
</script>

<?php require_once 'includes/footer.php'; ?> 
