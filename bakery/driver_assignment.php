<?php
define('ACCESS_ALLOWED', true);


// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/google_maps_config.php';

// Set page title
$page_title = 'Driver Assignment';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'assign_orders':
                $driverId = (int)$_POST['driver_id'];
                $deliveryDate = $_POST['delivery_date'];
                $assignments = json_decode($_POST['assignments'], true);
                
                // Debug logging
                error_log("Driver Assignment Debug - Driver ID: $driverId, Date: $deliveryDate");
                error_log("Assignments: " . print_r($assignments, true));
                
                if (!$assignments) {
                    throw new Exception('No assignments provided');
                }
                
                // Validate driver exists
                $stmt = $db->prepare("SELECT id FROM drivers WHERE id = ?");
                $stmt->execute([$driverId]);
                if (!$stmt->fetch()) {
                    throw new Exception("Driver ID $driverId does not exist in the drivers table");
                }
              
                $db->beginTransaction();
                
                // Clear existing assignments for this driver and date
                $stmt = $db->prepare("DELETE FROM daily_order_assignments WHERE driver_id = ? AND delivery_date = ?");
                $stmt->execute([$driverId, $deliveryDate]);
                
                // Insert new assignments
                $stmt = $db->prepare("
                    INSERT INTO daily_order_assignments (
                        daily_order_id, driver_id, delivery_date, route_order, 
                        scheduled_delivery_time, delivery_status
                    ) VALUES (?, ?, ?, ?, ?, 'pending')
                ");
                
                foreach ($assignments as $assignment) {
                    $stmt->execute([
                        $assignment['daily_order_id'],
                        $driverId,
                        $deliveryDate,
                        $assignment['route_order'],
                        $assignment['scheduled_delivery_time']
                    ]);
                }
                
                $db->commit();
                echo json_encode(['success' => true, 'message' => 'Orders assigned successfully']);
                break;
                
            case 'get_optimized_route':
                $driverId = (int)$_POST['driver_id'];
                $deliveryDate = $_POST['delivery_date'];
                
                // Get orders for this driver and date
                $stmt = $db->prepare("
                    SELECT 
                        do.id as daily_order_id,
                        do.customer_id,
                        c.name as customer_name,
                        c.address,
                        c.deliver_by,
                        c.deliver_after,
                        COALESCE(c.delivery_time, 20) as delivery_time,
                        c.latitude,
                        c.longitude
                    FROM daily_orders do
                    JOIN customers c ON do.customer_id = c.id
                    WHERE do.order_date = ?
                    AND do.id IN (
                        SELECT daily_order_id 
                        FROM daily_order_assignments 
                        WHERE driver_id = ? AND delivery_date = ?
                    )
                    ORDER BY c.name
                ");
                $stmt->execute([$deliveryDate, $driverId, $deliveryDate]);
                $orders = $stmt->fetchAll();
                
                echo json_encode(['success' => true, 'orders' => $orders]);
                break;
                
            case 'update_delivery_time':
                $customerId = (int)$_POST['customer_id'];
                $deliveryTime = (int)$_POST['delivery_time'];
                
                if ($deliveryTime >= 1 && $deliveryTime <= 120) {
                    $stmt = $db->prepare("UPDATE customers SET delivery_time = ? WHERE id = ?");
                    $success = $stmt->execute([$deliveryTime, $customerId]);
                    echo json_encode(['success' => $success]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Invalid delivery time']);
                }
                break;
                
            case 'remove_assignment':
                $dailyOrderId = (int)$_POST['daily_order_id'];
                $driverId = (int)$_POST['driver_id'];
                $deliveryDate = $_POST['delivery_date'];
                
                $stmt = $db->prepare("DELETE FROM daily_order_assignments WHERE daily_order_id = ? AND driver_id = ? AND delivery_date = ?");
                $success = $stmt->execute([$dailyOrderId, $driverId, $deliveryDate]);
                echo json_encode(['success' => $success]);
                break;
                
            case 'create_orders_and_assign':
                $deliveryDate = $_POST['delivery_date'];
                
                $db->beginTransaction();
                
                try {
                    // Get all customers
                    $customers = $db->query("SELECT id, name FROM customers ORDER BY name")->fetchAll();
                    
                    // Get standing routes for this day
                    $dayOfWeek = date('N', strtotime($deliveryDate));
                    $stmt = $db->prepare("
                        SELECT customer_id, driver_id 
                        FROM standing_routes 
                        WHERE day_of_week = ?
                    ");
                    $stmt->execute([$dayOfWeek]);
                    $standingRoutes = $stmt->fetchAll();
                    
                    // Create lookup for standing routes
                    $routeLookup = [];
                    foreach ($standingRoutes as $route) {
                        $routeLookup[$route['customer_id']] = $route['driver_id'];
                    }
                    
                    // Create orders for all customers and assign based on standing routes
                    $assignments = [];
                    
                    foreach ($customers as $customer) {
                        // Check if order already exists for this customer and date
                        $stmt = $db->prepare("
                            SELECT id FROM daily_orders 
                            WHERE customer_id = ? AND order_date = ?
                        ");
                        $stmt->execute([$customer['id'], $deliveryDate]);
                        $existingOrder = $stmt->fetch();
                        
                        if ($existingOrder) {
                            $dailyOrderId = $existingOrder['id'];
                        } else {
                            // Create new order
                            $stmt = $db->prepare("
                                INSERT INTO daily_orders (customer_id, order_date, total_amount) 
                                VALUES (?, ?, 0)
                            ");
                            $stmt->execute([$customer['id'], $deliveryDate]);
                            $dailyOrderId = $db->lastInsertId();
                        }
                        
                        // If customer has a standing route, assign them
                        if (isset($routeLookup[$customer['id']])) {
                            $assignments[] = [
                                'daily_order_id' => $dailyOrderId,
                                'driver_id' => $routeLookup[$customer['id']],
                                'route_order' => 0,
                                'scheduled_delivery_time' => null
                            ];
                        }
                    }
                    
                    $db->commit();
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Created orders for ' . count($customers) . ' customers and assigned ' . count($assignments) . ' based on standing routes',
                        'assignments' => $assignments
                    ]);
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }
                break;
                
            default:
                throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Get selected date (default to tomorrow)
$selectedDate = $_GET['date'] ?? date('Y-m-d', strtotime('+1 day'));
$dayName = date('l', strtotime($selectedDate));
$dayOfWeek = date('N', strtotime($selectedDate)); // 1=Monday, 7=Sunday

// Get all drivers
$drivers = $db->query("SELECT id, name FROM drivers ORDER BY name")->fetchAll();

// Get daily orders for selected date
$stmt = $db->prepare("
    SELECT 
        do.*,
        c.name as customer_name,
        c.address,
        c.phone,
        c.deliver_by,
        c.deliver_after,
        COALESCE(c.delivery_time, 20) as delivery_time,
        c.latitude,
        c.longitude,
        doa.driver_id as assigned_driver_id,
        doa.route_order,
        doa.scheduled_delivery_time,
        doa.delivery_status,
        d.name as assigned_driver_name
    FROM daily_orders do
    JOIN customers c ON do.customer_id = c.id
    LEFT JOIN daily_order_assignments doa ON do.id = doa.daily_order_id AND doa.delivery_date = do.order_date
    LEFT JOIN drivers d ON doa.driver_id = d.id
    WHERE do.order_date = ?
    ORDER BY doa.route_order, c.name
");
$stmt->execute([$selectedDate]);
$dailyOrders = $stmt->fetchAll();

// Get standing routes for this day
$stmt = $db->prepare("
    SELECT 
        sr.customer_id,
        sr.driver_id,
        c.name as customer_name,
        d.name as driver_name
    FROM standing_routes sr
    JOIN customers c ON sr.customer_id = c.id
    JOIN drivers d ON sr.driver_id = d.id
    WHERE sr.day_of_week = ?
    ORDER BY d.name, c.name
");
$stmt->execute([$dayOfWeek]);
$standingRoutes = $stmt->fetchAll();

// Group orders by assigned driver
$ordersByDriver = [];
$unassignedOrders = [];

foreach ($dailyOrders as $order) {
    if ($order['assigned_driver_id']) {
        $driverId = $order['assigned_driver_id'];
        if (!isset($ordersByDriver[$driverId])) {
            $ordersByDriver[$driverId] = [
                'driver_name' => $order['assigned_driver_name'],
                'orders' => []
            ];
        }
        $ordersByDriver[$driverId]['orders'][] = $order;
    } else {
        $unassignedOrders[] = $order;
    }
}

// Group standing routes by driver
$standingRoutesByDriver = [];
foreach ($standingRoutes as $route) {
    $driverId = $route['driver_id'];
    if (!isset($standingRoutesByDriver[$driverId])) {
        $standingRoutesByDriver[$driverId] = [];
    }
    $standingRoutesByDriver[$driverId][] = $route;
}

// Include header
require_once 'includes/header.php';

// Include navigation
require_once 'includes/nav.php';
?>

<div class="container">
    <div class="page-header">
        <h1>🚚 Driver Assignment</h1>
        <div class="button-group">
            <button type="button" class="btn btn-primary" onclick="autoAssignFromStandingRoutes()">
                Create Orders & Auto-Assign from Standing Routes
            </button>
            <button type="button" class="btn btn-secondary" onclick="showDatePicker()">
                Change Date
            </button>
        </div>
    </div>
    
    <!-- Date Navigation -->
    <div class="date-navigation">
        <div class="date-info">
            <h2>Assignments for <?= date('l, F j, Y', strtotime($selectedDate)) ?></h2>
            <span class="order-count">Total Orders: <?= count($dailyOrders) ?></span>
        </div>
        <div class="date-controls">
            <a href="?date=<?= date('Y-m-d', strtotime($selectedDate . ' -1 day')) ?>" class="btn btn-outline">← Previous Day</a>
            <a href="?date=<?= date('Y-m-d') ?>" class="btn btn-primary">Today</a>
            <a href="?date=<?= date('Y-m-d', strtotime($selectedDate . ' +1 day')) ?>" class="btn btn-outline">Next Day →</a>
        </div>
    </div>
    
    <?php if (empty($dailyOrders)): ?>
        <div class="empty-state">
            <h3>No orders for this date</h3>
            <p>Generate orders from standing orders first, then assign them to drivers.</p>
            <a href="daily_orders.php?date=<?= $selectedDate ?>" class="btn btn-primary">
                Go to Daily Orders
            </a>
        </div>
    <?php else: ?>
        <!-- Driver Assignments -->
        <div class="driver-assignments">
            <?php foreach ($drivers as $driver): ?>
                <div class="driver-section" data-driver-id="<?= $driver['id'] ?>">
                    <div class="driver-header">
                        <h3><?= htmlspecialchars($driver['name']) ?></h3>
                        <div class="driver-controls">
                            <button class="btn btn-sm btn-primary" onclick="optimizeRoute(<?= $driver['id'] ?>)">
                                🚀 Optimize Route
                            </button>
                            <button class="btn btn-sm btn-secondary" onclick="editAssignments(<?= $driver['id'] ?>)">
                                ✏️ Edit
                            </button>
                        </div>
                    </div>
                    
                    <div class="driver-orders">
                        <?php 
                        $driverOrders = $ordersByDriver[$driver['id']] ?? ['orders' => []];
                        $standingDriverRoutes = $standingRoutesByDriver[$driver['id']] ?? [];
                        ?>
                        
                        <div class="orders-list">
                            <?php if (empty($driverOrders['orders'])): ?>
                                <div class="no-orders">
                                    <p>No orders assigned</p>
                                    <?php if (!empty($standingDriverRoutes)): ?>
                                        <button class="btn btn-sm btn-outline-primary" onclick="assignFromStandingRoutes(<?= $driver['id'] ?>)">
                                            Assign from Standing Routes (<?= count($standingDriverRoutes) ?>)
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="route-order-list" data-driver-id="<?= $driver['id'] ?>">
                                    <?php foreach ($driverOrders['orders'] as $order): ?>
                                        <div class="order-item" data-order-id="<?= $order['id'] ?>" draggable="true">
                                            <div class="drag-handle">⋮⋮</div>
                                            <div class="order-info">
                                                <div class="customer-name"><?= htmlspecialchars($order['customer_name']) ?></div>
                                                <div class="customer-address"><?= htmlspecialchars($order['address']) ?></div>
                                                <div class="order-details">
                                                    <span class="route-order">#<?= $order['route_order'] ?></span>
                                                    <span class="delivery-time"><?= $order['scheduled_delivery_time'] ?: 'TBD' ?></span>
                                                    <span class="order-amount">$<?= number_format($order['total_amount'], 2) ?></span>
                                                </div>
                                            </div>
                                            <div class="order-actions">
                                                <button class="btn btn-sm btn-outline-danger" onclick="removeAssignmentFromDatabase(<?= $order['id'] ?>)">
                                                    Remove
                                                </button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <!-- Unassigned Orders -->
            <?php if (!empty($unassignedOrders)): ?>
                <div class="unassigned-section">
                    <h3>Unassigned Orders (<?= count($unassignedOrders) ?>)</h3>
                    <div class="unassigned-orders">
                        <?php foreach ($unassignedOrders as $order): ?>
                            <div class="order-item" data-order-id="<?= $order['id'] ?>">
                                <div class="order-info">
                                    <div class="customer-name"><?= htmlspecialchars($order['customer_name']) ?></div>
                                    <div class="customer-address"><?= htmlspecialchars($order['address']) ?></div>
                                    <div class="order-details">
                                        <span class="order-amount">$<?= number_format($order['total_amount'], 2) ?></span>
                                    </div>
                                </div>
                                <div class="order-actions">
                                    <select class="driver-select" onchange="assignToDriver(<?= $order['id'] ?>, this.value)">
                                        <option value="">Assign to...</option>
                                        <?php foreach ($drivers as $driver): ?>
                                            <option value="<?= $driver['id'] ?>"><?= htmlspecialchars($driver['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Route Optimization Modal -->
<div id="route-modal" class="modal-overlay" style="display: none;">
    <div class="modal">
        <div class="modal-header">
            <h3>Route Optimization</h3>
            <button class="close-btn" onclick="closeRouteModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="route-map" style="height: 400px; width: 100%; margin-bottom: 20px;"></div>
            <div id="route-info"></div>
            <div id="route-list"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-primary" onclick="saveOptimizedRoute()">Save Route</button>
            <button class="btn btn-secondary" onclick="closeRouteModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- Edit Assignments Modal -->
<div id="edit-modal" class="modal-overlay" style="display: none;">
    <div class="modal">
        <div class="modal-header">
            <h3>Edit Driver Assignments</h3>
            <button class="close-btn" onclick="closeEditModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="edit-assignments-content"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-primary" onclick="saveAssignments()">Save Changes</button>
            <button class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
        </div>
    </div>
</div>

<script>
// Global variables
let currentDriverId = null;
let currentAssignments = [];
let map = null;
let directionsService = null;
let directionsRenderer = null;
let geocoder = null;
let markers = [];

// Initialize Google Maps
function initMap() {
    if (typeof google === 'undefined' || typeof google.maps === 'undefined') {
        console.error('Google Maps API not loaded');
        return;
    }
    
    map = new google.maps.Map(document.getElementById('route-map'), {
        zoom: 12,
        center: { lat: 37.7749, lng: -122.4194 },
        mapTypeId: 'roadmap'
    });
    
    directionsService = new google.maps.DirectionsService();
    directionsRenderer = new google.maps.DirectionsRenderer({
        draggable: false,
        suppressMarkers: true
    });
    directionsRenderer.setMap(map);
    
    geocoder = new google.maps.Geocoder();
}

// Auto-assign from standing routes
function autoAssignFromStandingRoutes() {
    if (!confirm('This will create orders for ALL customers and assign them based on standing routes. Continue?')) {
        return;
    }
    
    fetch('driver_assignment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=create_orders_and_assign&delivery_date=<?= $selectedDate ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload(); // Refresh page to show updated assignments
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error creating orders and assignments');
    });
}

// Assign specific driver from standing routes
function assignFromStandingRoutes(driverId) {
    const standingRoutes = <?= json_encode($standingRoutes) ?>;
    const dailyOrders = <?= json_encode($dailyOrders) ?>;
    
    const driverRoutes = standingRoutes.filter(route => route.driver_id == driverId);
    const ordersByCustomer = {};
    dailyOrders.forEach(order => {
        ordersByCustomer[order.customer_id] = order;
    });
    
    const assignments = [];
    driverRoutes.forEach(route => {
        if (ordersByCustomer[route.customer_id]) {
            assignments.push({
                daily_order_id: ordersByCustomer[route.customer_id].id,
                driver_id: driverId,
                route_order: 0,
                scheduled_delivery_time: null
            });
        }
    });
    
    saveAssignments(assignments);
}

// Optimize route for a driver (Constraint-aware, Route Tester logic)
function optimizeRoute(driverId) {
    currentDriverId = driverId;
    fetch('driver_assignment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_optimized_route&driver_id=${driverId}&delivery_date=<?= $selectedDate ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showConstraintAwareRouteModal(data.orders);
        } else {
            alert('Error getting route data: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error getting route data');
    });
}

// Show constraint-aware route optimization modal
function showConstraintAwareRouteModal(orders) {
    document.getElementById('route-modal').style.display = 'flex';
    if (!map) initMap();
    markers.forEach(marker => marker.setMap(null));
    markers = [];
    // Add bakery marker
    const bakeryLocation = { lat: 37.7749, lng: -122.4194 };
    const bakeryMarker = new google.maps.Marker({
        position: bakeryLocation,
        map: map,
        title: 'Bakery',
        label: '🏪'
    });
    markers.push(bakeryMarker);
    // Add customer markers
    orders.forEach((order, index) => {
        if (order.latitude && order.longitude && !isNaN(order.latitude) && !isNaN(order.longitude)) {
            // Use lat/lng if present and valid
            const marker = new google.maps.Marker({
                position: { lat: parseFloat(order.latitude), lng: parseFloat(order.longitude) },
                map: map,
                title: order.customer_name,
                label: (index + 1).toString()
            });
            markers.push(marker);
        } else if (order.address && typeof google !== 'undefined' && google.maps && typeof geocoder !== 'undefined') {
            // Use geocoder to convert address to coordinates
            geocoder.geocode({ address: order.address }, (results, status) => {
                console.log('Geocoding', order.address, 'Status:', status, 'Results:', results);
                if (status === 'OK' && results[0]) {
                    const marker = new google.maps.Marker({
                        position: results[0].geometry.location,
                        map: map,
                        title: order.customer_name,
                        label: (index + 1).toString()
                    });
                    markers.push(marker);
                } else {
                    console.warn(`Geocoding failed for ${order.customer_name}: ${order.address}`);
                }
            });
        }
    });
    // Start constraint-aware optimization
    optimizeConstraintAwareRoute(orders);
}

// Constraint-aware optimization logic (adapted from Route Tester)
function optimizeConstraintAwareRoute(orders) {
    if (!orders || orders.length === 0) {
        alert('No orders to optimize.');
        return;
    }
    // Validate addresses
    const invalidAddresses = orders.filter(o => !o.address || o.address.trim().length < 10);
    if (invalidAddresses.length > 0) {
        document.getElementById('route-info').innerHTML = `<div class="alert alert-danger">❌ Invalid addresses detected:<br><small>${invalidAddresses.map(o => o.customer_name + ': ' + o.address).join('<br>')}</small></div>`;
        return;
    }
    // Prepare waypoints
    const waypoints = orders.map(order => ({ location: order.address, stopover: true }));
    if (waypoints.length > 25) {
        document.getElementById('route-info').innerHTML = `<div class="alert alert-danger">❌ Too many stops (${waypoints.length}) for route optimization. Please reduce to 25 or fewer.</div>`;
        return;
    }
    const request = {
        origin: '484 5th Street, San Francisco, CA',
        destination: '484 5th Street, San Francisco, CA',
        waypoints: waypoints,
        optimizeWaypoints: true,
        travelMode: google.maps.TravelMode.DRIVING
    };
    directionsService.route(request, (result, status) => {
        if (status === 'OK') {
            fixConstraintViolations(result, orders);
        } else {
            document.getElementById('route-info').innerHTML = `<div class="alert alert-danger">❌ Route optimization failed: ${status}</div>`;
        }
    });
}

// Fix constraint violations iteratively
function fixConstraintViolations(result, orders) {
    // Google's optimized order
    const waypointOrder = result.routes[0].waypoint_order;
    let customerOrder = waypointOrder.map(index => orders[index]);
    let currentRoute = { customerOrder: customerOrder, result: result };
    iterativeConstraintFix(currentRoute, orders);
}

// Iterative constraint fixing (async)
function iterativeConstraintFix(currentRoute, orders) {
    const maxIterations = 50;
    let iteration = 0;
    let lastMovedCustomer = null;
    function processIteration() {
        iteration++;
        const routeWithTimes = calculateArrivalTimesForRoute(currentRoute.result, currentRoute.customerOrder);
        const violations = findConstraintViolationsInRoute(routeWithTimes);
        if (violations.length === 0 || iteration >= maxIterations) {
            // Done! Display final route
            displayConstraintAwareRoute(currentRoute.result, currentRoute.customerOrder, orders, routeWithTimes, violations);
            return;
        }
        // Find largest violation that can be moved earlier
        const fixable = violations.filter(v => {
            const idx = currentRoute.customerOrder.findIndex(c => c.daily_order_id === v.customer.daily_order_id);
            return idx > 0;
        });
        if (fixable.length === 0) {
            displayConstraintAwareRoute(currentRoute.result, currentRoute.customerOrder, orders, routeWithTimes, violations);
            return;
        }
        // Move the largest violation earlier
        const largest = fixable.reduce((a, b) => a.violationMinutes > b.violationMinutes ? a : b);
        moveCustomerOneStepEarlier(currentRoute.customerOrder, largest.customer, orders).then(({ newOrder }) => {
            getRouteForOrder(newOrder, orders).then(newRoute => {
                currentRoute = newRoute || currentRoute;
                processIteration();
            });
        });
    }
    processIteration();
}

// Calculate arrival times for each stop
function calculateArrivalTimesForRoute(result, customerOrder) {
    const route = result.routes[0];
    let currentTime = new Date();
    currentTime.setHours(6, 40, 0, 0); // 6:40 AM start
    return customerOrder.map((customer, idx) => {
        const leg = route.legs[idx];
        currentTime = new Date(currentTime.getTime() + (leg.duration.value * 1000));
        const arrivalTime = new Date(currentTime);
        const deliveryTime = customer.delivery_time || 20;
        currentTime = new Date(currentTime.getTime() + (deliveryTime * 60 * 1000));
        return { customer, arrivalTime, leg, routeIndex: idx };
    });
}

// Find constraint violations
function findConstraintViolationsInRoute(routeWithTimes) {
    const violations = [];
    routeWithTimes.forEach(stop => {
        const customer = stop.customer;
        const arrivalTime = stop.arrivalTime;
        if (customer.deliver_by) {
            const deadline = new Date();
            const [h, m] = customer.deliver_by.split(':');
            deadline.setHours(parseInt(h), parseInt(m), 0, 0);
            if (arrivalTime > deadline) {
                violations.push({
                    type: 'late',
                    customer: customer,
                    arrivalTime: arrivalTime,
                    deadline: deadline,
                    routeIndex: stop.routeIndex,
                    violationMinutes: Math.ceil((arrivalTime - deadline) / (1000 * 60))
                });
            }
        }
    });
    return violations;
}

// Move customer one step earlier
function moveCustomerOneStepEarlier(customerOrder, targetCustomer, orders) {
    return new Promise(resolve => {
        const idx = customerOrder.findIndex(c => c.daily_order_id === targetCustomer.daily_order_id);
        if (idx <= 0) return resolve({ newOrder: customerOrder });
        const newOrder = [...customerOrder];
        const [moved] = newOrder.splice(idx, 1);
        newOrder.splice(idx - 1, 0, moved);
        resolve({ newOrder });
    });
}

// Get route for a specific order
function getRouteForOrder(customerOrder, orders) {
    return new Promise(resolve => {
        const waypoints = customerOrder.map(order => ({ location: order.address, stopover: true }));
        const request = {
            origin: '484 5th Street, San Francisco, CA',
            destination: '484 5th Street, San Francisco, CA',
            waypoints: waypoints,
            optimizeWaypoints: false,
            travelMode: google.maps.TravelMode.DRIVING
        };
        directionsService.route(request, (result, status) => {
            if (status === 'OK') {
                resolve({ customerOrder: customerOrder, result: result });
            } else {
                resolve(null);
            }
        });
    });
}

// Display the constraint-aware route and violations
function displayConstraintAwareRoute(result, customerOrder, orders, routeWithTimes, violations) {
    directionsRenderer.setDirections(result);
    let routeList = `<div class='route-stops'><h4>Constraint-Aware Route Order</h4><div class='draggable-route-list'>`;
    routeWithTimes.forEach((stop, idx) => {
        const customer = stop.customer;
        const arrival = stop.arrivalTime.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
        let constraintStatus = '';
        if (customer.deliver_by) {
            const deadline = new Date();
            const [h, m] = customer.deliver_by.split(':');
            deadline.setHours(parseInt(h), parseInt(m), 0, 0);
            if (stop.arrivalTime > deadline) {
                const delay = Math.ceil((stop.arrivalTime - deadline) / (1000 * 60));
                constraintStatus = `<span style='color:red'>❌ Late by ${delay} min (Deadline: ${deadline.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })})</span>`;
            } else {
                const buffer = Math.floor((deadline - stop.arrivalTime) / (1000 * 60));
                constraintStatus = `<span style='color:green'>✅ On time (${buffer} min buffer)</span>`;
            }
        } else {
            constraintStatus = '<span style="color:gray">No delivery time constraint</span>';
        }
        routeList += `
            <div class="route-stop-item" draggable="true" data-index="${idx}" data-order-id="${customer.daily_order_id}">
                <div class="drag-handle">⋮⋮</div>
                <div class="stop-number">${idx + 1}</div>
                <div class="stop-content">
                    <strong>${customer.customer_name}</strong><br>
                    <small>${customer.address}</small><br>
                    🕐 Arrival: ${arrival}<br>
                    ${constraintStatus}
                </div>
            </div>
        `;
    });
    routeList += '</div></div>';
    if (violations.length > 0) {
        routeList += `<div class='alert alert-warning'>⚠️ Some stops are late due to constraints. Consider starting earlier or splitting the route.</div>`;
    } else {
        routeList += `<div class='alert alert-success'>🎉 All delivery time constraints satisfied!</div>`;
    }
    document.getElementById('route-info').innerHTML = routeList;
    
    // Setup drag and drop functionality
    setupDragAndDrop();
    
    // Prepare assignments for saving
    currentAssignments = customerOrder.map((order, idx) => ({
        daily_order_id: order.daily_order_id,
        driver_id: currentDriverId,
        route_order: idx + 1,
        scheduled_delivery_time: routeWithTimes[idx].arrivalTime.toTimeString().substring(0, 5)
    }));
}

// Setup drag and drop functionality for route reordering
function setupDragAndDrop() {
    const routeList = document.querySelector('.draggable-route-list');
    if (!routeList) return;
    
    let draggedItem = null;
    let draggedIndex = null;
    
    // Add event listeners to all draggable items
    const items = routeList.querySelectorAll('.route-stop-item');
    items.forEach(item => {
        item.addEventListener('dragstart', handleDragStart);
        item.addEventListener('dragend', handleDragEnd);
        item.addEventListener('dragover', handleDragOver);
        item.addEventListener('drop', handleDrop);
        item.addEventListener('dragenter', handleDragEnter);
        item.addEventListener('dragleave', handleDragLeave);
    });
    
    function handleDragStart(e) {
        draggedItem = this;
        draggedIndex = parseInt(this.dataset.index);
        this.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/html', this.outerHTML);
    }
    
    function handleDragEnd(e) {
        this.classList.remove('dragging');
        draggedItem = null;
        draggedIndex = null;
    }
    
    function handleDragOver(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
    }
    
    function handleDragEnter(e) {
        e.preventDefault();
        this.classList.add('drag-over');
    }
    
    function handleDragLeave(e) {
        this.classList.remove('drag-over');
    }
    
    function handleDrop(e) {
        e.preventDefault();
        this.classList.remove('drag-over');
        
        if (draggedItem === this) return;
        
        const dropIndex = parseInt(this.dataset.index);
        const items = Array.from(routeList.querySelectorAll('.route-stop-item'));
        
        // Reorder the items
        if (draggedIndex < dropIndex) {
            // Moving down
            this.parentNode.insertBefore(draggedItem, this.nextSibling);
        } else {
            // Moving up
            this.parentNode.insertBefore(draggedItem, this);
        }
        
        // Update data-index attributes and stop numbers
        const newItems = routeList.querySelectorAll('.route-stop-item');
        newItems.forEach((item, index) => {
            item.dataset.index = index;
            item.querySelector('.stop-number').textContent = index + 1;
        });
        
        // Update currentAssignments with new order
        updateAssignmentsFromDrag(newItems);
        
        // Recalculate route with new order
        recalculateRouteWithNewOrder(newItems);
    }
}

// Update assignments array after drag and drop
function updateAssignmentsFromDrag(newItems) {
    const newOrder = Array.from(newItems).map(item => {
        const orderId = item.dataset.orderId;
        return currentAssignments.find(assignment => assignment.daily_order_id == orderId);
    });
    
    // Update route_order based on new position
    newOrder.forEach((assignment, index) => {
        if (assignment) {
            assignment.route_order = index + 1;
        }
    });
    
    currentAssignments = newOrder;
}

// Recalculate route with new customer order
function recalculateRouteWithNewOrder(newItems) {
    const newCustomerOrder = Array.from(newItems).map(item => {
        const orderId = item.dataset.orderId;
        return currentAssignments.find(assignment => assignment.daily_order_id == orderId);
    }).filter(Boolean);
    
    if (newCustomerOrder.length === 0) return;
    
    // Get the original orders data
    const orders = currentAssignments.map(assignment => {
        return { daily_order_id: assignment.daily_order_id, address: '' }; // We'll need to get the full order data
    });
    
    // Recalculate route with new order
    getRouteForOrder(newCustomerOrder, orders).then(newRoute => {
        if (newRoute) {
            // Update the map with new route
            directionsRenderer.setDirections(newRoute.result);
            
            // Recalculate arrival times
            const routeWithTimes = calculateArrivalTimesForRoute(newRoute.result, newCustomerOrder);
            
            // Update assignments with new arrival times
            currentAssignments = newCustomerOrder.map((order, idx) => ({
                daily_order_id: order.daily_order_id,
                driver_id: currentDriverId,
                route_order: idx + 1,
                scheduled_delivery_time: routeWithTimes[idx].arrivalTime.toTimeString().substring(0, 5)
            }));
            
            // Update arrival times in the UI
            updateArrivalTimesInUI(routeWithTimes);
        }
    });
}

// Update arrival times in the UI after drag and drop
function updateArrivalTimesInUI(routeWithTimes) {
    const items = document.querySelectorAll('.route-stop-item');
    items.forEach((item, index) => {
        if (routeWithTimes[index]) {
            const arrival = routeWithTimes[index].arrivalTime.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
            const arrivalElement = item.querySelector('.stop-content');
            if (arrivalElement) {
                const arrivalText = arrivalElement.innerHTML.replace(/🕐 Arrival: [^<]+/, `🕐 Arrival: ${arrival}`);
                arrivalElement.innerHTML = arrivalText;
            }
        }
    });
}

// Save optimized route
function saveOptimizedRoute() {
    if (currentAssignments.length === 0) {
        alert('No route to save');
        return;
    }
    
    saveAssignments(currentAssignments);
    closeRouteModal();
}

// Close route modal
function closeRouteModal() {
    document.getElementById('route-modal').style.display = 'none';
    currentAssignments = [];
}

// Edit assignments for a driver
function editAssignments(driverId) {
    currentDriverId = driverId;
    
    // Get current assignments for this driver
    const driverOrders = <?= json_encode($ordersByDriver) ?>[driverId] || { orders: [] };
    const allOrders = <?= json_encode($dailyOrders) ?>;
    
    let content = `
        <div class="edit-assignments">
            <h4>Edit Assignments for ${driverOrders.driver_name || 'Driver'}</h4>
            <div class="assignments-list">
    `;
    
    driverOrders.orders.forEach(order => {
        content += `
            <div class="assignment-item" data-order-id="${order.id}">
                <div class="assignment-info">
                    <div class="customer-name">${order.customer_name}</div>
                    <div class="customer-address">${order.address}</div>
                </div>
                <div class="assignment-controls">
                    <input type="number" class="route-order-input" value="${order.route_order || 0}" 
                           placeholder="Route Order" min="1">
                    <input type="time" class="delivery-time-input" value="${order.scheduled_delivery_time || ''}" 
                           placeholder="Delivery Time">
                    <button class="btn btn-sm btn-outline-danger" onclick="removeAssignment(${order.id})">Remove</button>
                </div>
            </div>
        `;
    });
    
    content += `
            </div>
            <div class="add-assignment">
                <h5>Add Orders</h5>
                <select id="add-order-select" onchange="addAssignment(this.value)">
                    <option value="">Select order to add...</option>
    `;
    
    // Add unassigned orders
    const unassignedOrders = allOrders.filter(order => !order.assigned_driver_id);
    unassignedOrders.forEach(order => {
        content += `<option value="${order.id}">${order.customer_name} - ${order.address}</option>`;
    });
    
    content += `
                </select>
            </div>
        </div>
    `;
    
    document.getElementById('edit-assignments-content').innerHTML = content;
    document.getElementById('edit-modal').style.display = 'flex';
}

// Add assignment
function addAssignment(orderId) {
    if (!orderId) return;
    
    const allOrders = <?= json_encode($dailyOrders) ?>;
    const order = allOrders.find(o => o.id == orderId);
    if (!order) return;
    
    const assignmentsList = document.querySelector('.assignments-list');
    const assignmentItem = document.createElement('div');
    assignmentItem.className = 'assignment-item';
    assignmentItem.dataset.orderId = orderId;
    assignmentItem.innerHTML = `
        <div class="assignment-info">
            <div class="customer-name">${order.customer_name}</div>
            <div class="customer-address">${order.address}</div>
        </div>
        <div class="assignment-controls">
            <input type="number" class="route-order-input" value="0" placeholder="Route Order" min="1">
            <input type="time" class="delivery-time-input" value="" placeholder="Delivery Time">
            <button class="btn btn-sm btn-outline-danger" onclick="removeAssignment(${orderId})">Remove</button>
        </div>
    `;
    
    assignmentsList.appendChild(assignmentItem);
    document.getElementById('add-order-select').value = '';
}

// Remove assignment from main view (immediate database removal)
function removeAssignmentFromDatabase(orderId) {
    if (!confirm('Are you sure you want to remove this assignment?')) {
        return;
    }
    
    // Find the driver ID for this order
    const orderItem = document.querySelector(`[data-order-id="${orderId}"]`);
    const driverSection = orderItem.closest('.driver-section');
    const driverId = driverSection.dataset.driverId;
    
    if (!driverId) {
        alert('Could not determine driver ID');
        return;
    }
    
    // Remove the assignment from database
    fetch('driver_assignment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=remove_assignment&daily_order_id=${orderId}&driver_id=${driverId}&delivery_date=<?= $selectedDate ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload(); // Refresh page to show updated assignments
        } else {
            alert('Error removing assignment: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error removing assignment');
    });
}

// Remove assignment from edit modal (visual only)
function removeAssignment(orderId) {
    const assignmentItem = document.querySelector(`[data-order-id="${orderId}"]`);
    if (assignmentItem) {
        assignmentItem.remove();
    }
}

// Save assignments
function saveAssignments(assignments = null) {
    if (!assignments) {
        // Collect assignments from edit modal
        const assignmentItems = document.querySelectorAll('.assignment-item');
        assignments = [];
        
        assignmentItems.forEach(item => {
            const orderId = item.dataset.orderId;
            const routeOrder = item.querySelector('.route-order-input').value;
            const deliveryTime = item.querySelector('.delivery-time-input').value;
            
            if (orderId && routeOrder) {
                assignments.push({
                    daily_order_id: orderId,
                    driver_id: currentDriverId,
                    route_order: routeOrder,
                    scheduled_delivery_time: deliveryTime || null
                });
            }
        });
    }
    
    if (assignments.length === 0) {
        alert('No assignments to save');
        return;
    }
    
    // Use the driver_id from the first assignment if currentDriverId is not set
    const driverId = currentDriverId || assignments[0].driver_id;
    
    // Debug logging
    console.log('Saving assignments:', {
        currentDriverId: currentDriverId,
        driverId: driverId,
        assignments: assignments
    });
    
    fetch('driver_assignment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=assign_orders&driver_id=${driverId}&delivery_date=<?= $selectedDate ?>&assignments=${JSON.stringify(assignments)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Assignments saved successfully');
            location.reload(); // Refresh page to show updated assignments
        } else {
            alert('Error saving assignments: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error saving assignments');
    });
}

// Close edit modal
function closeEditModal() {
    document.getElementById('edit-modal').style.display = 'none';
    currentDriverId = null;
}

// Assign order to driver
function assignToDriver(orderId, driverId) {
    // Convert to integer and validate
    driverId = parseInt(driverId);
    
    if (!driverId || driverId <= 0) {
        console.log('Invalid driver ID:', driverId);
        return;
    }
    
    currentDriverId = driverId; // Set the current driver ID
    
    const assignments = [{
        daily_order_id: orderId,
        driver_id: driverId,
        route_order: 1, // Default to first position
        scheduled_delivery_time: null
    }];
    
    saveAssignments(assignments);
}

// Show date picker
function showDatePicker() {
    const date = prompt('Enter date (YYYY-MM-DD):', '<?= $selectedDate ?>');
    if (date) {
        window.location.href = `?date=${date}`;
    }
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Load Google Maps API with async, defer, and onload (no callback in URL)
    const script = document.createElement('script');
    script.src = `https://maps.googleapis.com/maps/api/js?key=<?= GOOGLE_MAPS_API_KEY ?>&libraries=geometry`;
    script.async = true;
    script.defer = true;
    script.onload = function() {
        if (typeof initMap === 'function') initMap();
    };
    document.head.appendChild(script);
    
    // Setup drag and drop for main view
    setupMainViewDragAndDrop();
});

// Setup drag and drop functionality for main driver assignment view
function setupMainViewDragAndDrop() {
    const routeLists = document.querySelectorAll('.route-order-list');
    
    routeLists.forEach(routeList => {
        let draggedItem = null;
        let draggedIndex = null;
        
        // Add event listeners to all draggable items in this driver's section
        const items = routeList.querySelectorAll('.order-item');
        items.forEach(item => {
            item.addEventListener('dragstart', handleMainDragStart);
            item.addEventListener('dragend', handleMainDragEnd);
            item.addEventListener('dragover', handleMainDragOver);
            item.addEventListener('drop', handleMainDrop);
            item.addEventListener('dragenter', handleMainDragEnter);
            item.addEventListener('dragleave', handleMainDragLeave);
        });
        
        function handleMainDragStart(e) {
            draggedItem = this;
            draggedIndex = Array.from(routeList.querySelectorAll('.order-item')).indexOf(this);
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/html', this.outerHTML);
        }
        
        function handleMainDragEnd(e) {
            this.classList.remove('dragging');
            draggedItem = null;
            draggedIndex = null;
        }
        
        function handleMainDragOver(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
        }
        
        function handleMainDragEnter(e) {
            e.preventDefault();
            this.classList.add('drag-over');
        }
        
        function handleMainDragLeave(e) {
            this.classList.remove('drag-over');
        }
        
        function handleMainDrop(e) {
            e.preventDefault();
            this.classList.remove('drag-over');
            
            if (draggedItem === this) return;
            
            const dropIndex = Array.from(routeList.querySelectorAll('.order-item')).indexOf(this);
            const driverId = routeList.dataset.driverId;
            
            // Reorder the items
            if (draggedIndex < dropIndex) {
                // Moving down
                this.parentNode.insertBefore(draggedItem, this.nextSibling);
            } else {
                // Moving up
                this.parentNode.insertBefore(draggedItem, this);
            }
            
            // Update route order numbers
            updateMainViewRouteNumbers(routeList);
            
            // Save the new order automatically
            saveMainViewOrder(driverId, routeList);
        }
    });
}

// Update route order numbers in main view
function updateMainViewRouteNumbers(routeList) {
    const items = routeList.querySelectorAll('.order-item');
    items.forEach((item, index) => {
        const routeOrderSpan = item.querySelector('.route-order');
        if (routeOrderSpan) {
            routeOrderSpan.textContent = `#${index + 1}`;
        }
    });
}

// Save the new order from main view drag and drop
function saveMainViewOrder(driverId, routeList) {
    const items = routeList.querySelectorAll('.order-item');
    const assignments = Array.from(items).map((item, index) => {
        const orderId = item.dataset.orderId;
        return {
            daily_order_id: orderId,
            driver_id: parseInt(driverId),
            route_order: index + 1,
            scheduled_delivery_time: null // Keep existing time or recalculate if needed
        };
    });
    
    // Show saving indicator
    const driverSection = routeList.closest('.driver-section');
    const saveIndicator = document.createElement('div');
    saveIndicator.className = 'save-indicator';
    saveIndicator.textContent = 'Saving...';
    saveIndicator.style.cssText = 'position: absolute; top: 10px; right: 10px; background: #28a745; color: white; padding: 5px 10px; border-radius: 4px; font-size: 12px; z-index: 100;';
    driverSection.style.position = 'relative';
    driverSection.appendChild(saveIndicator);
    
    // Save assignments
    fetch('driver_assignment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=assign_orders&driver_id=${driverId}&delivery_date=<?= $selectedDate ?>&assignments=${JSON.stringify(assignments)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            saveIndicator.textContent = 'Saved!';
            saveIndicator.style.background = '#28a745';
            setTimeout(() => {
                if (saveIndicator.parentNode) {
                    saveIndicator.parentNode.removeChild(saveIndicator);
                }
            }, 2000);
        } else {
            saveIndicator.textContent = 'Error!';
            saveIndicator.style.background = '#dc3545';
            setTimeout(() => {
                if (saveIndicator.parentNode) {
                    saveIndicator.parentNode.removeChild(saveIndicator);
                }
            }, 3000);
            console.error('Error saving order:', data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        saveIndicator.textContent = 'Error!';
        saveIndicator.style.background = '#dc3545';
        setTimeout(() => {
            if (saveIndicator.parentNode) {
                saveIndicator.parentNode.removeChild(saveIndicator);
            }
        }, 3000);
    });
}
</script>

<style>
.driver-assignments {
    margin-top: 20px;
}

.driver-section {
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.driver-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    background: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
    border-radius: 8px 8px 0 0;
}

.driver-header h3 {
    margin: 0;
    color: #495057;
}

.driver-controls {
    display: flex;
    gap: 10px;
}

.driver-orders {
    padding: 20px;
}

.no-orders {
    text-align: center;
    padding: 40px;
    color: #6c757d;
}

.route-order-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.order-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 6px;
    transition: all 0.2s ease;
    cursor: grab;
}

.order-item:hover {
    background: #e9ecef;
    border-color: #dee2e6;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0, 123, 255, 0.15);
}

.order-item.dragging {
    opacity: 0.5;
    transform: rotate(2deg);
    cursor: grabbing;
    z-index: 1000;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

.order-item.drag-over {
    border-color: #28a745;
    background: #d4edda;
    transform: scale(1.02);
}

.order-item .drag-handle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 25px;
    height: 25px;
    background: #6c757d;
    color: white;
    border-radius: 4px;
    margin-right: 12px;
    cursor: grab;
    font-size: 10px;
    font-weight: bold;
    user-select: none;
    flex-shrink: 0;
}

.order-item .drag-handle:hover {
    background: #495057;
}

.order-item.dragging .drag-handle {
    cursor: grabbing;
}

.order-info {
    flex: 1;
}

.customer-name {
    font-weight: 600;
    color: #495057;
    margin-bottom: 5px;
}

.customer-address {
    font-size: 0.9em;
    color: #6c757d;
    margin-bottom: 5px;
}

.order-details {
    display: flex;
    gap: 15px;
    font-size: 0.85em;
}

.route-order {
    background: #007bff;
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-weight: 600;
}

.delivery-time {
    color: #28a745;
    font-weight: 500;
}

.order-amount {
    color: #6c757d;
    font-weight: 500;
}

.order-actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

.driver-select {
    padding: 6px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    background: white;
    font-size: 0.9em;
}

.unassigned-section {
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 20px;
    margin-top: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.unassigned-section h3 {
    margin-top: 0;
    color: #dc3545;
}

.unassigned-orders {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

/* Modal Styles */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    display: flex;
    justify-content: center;
    align-items: center;
}

.modal {
    background-color: white;
    border-radius: 10px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    max-width: 800px;
    width: 90%;
    max-height: 90%;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    border-bottom: 1px solid #dee2e6;
}

.modal-header h3 {
    margin: 0;
}

.close-btn {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #6c757d;
}

.close-btn:hover {
    color: #495057;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 20px;
    border-top: 1px solid #dee2e6;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* Route Optimization Modal */
#route-modal {
    display: none;
}

#route-map {
    height: 400px;
    width: 100%;
    margin-bottom: 20px;
    border-radius: 8px;
}

/* Drag and Drop Styles */
.draggable-route-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 15px 0;
}

.route-stop-item {
    display: flex;
    align-items: center;
    padding: 15px;
    background: #f8f9fa;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    cursor: grab;
    transition: all 0.2s ease;
    position: relative;
}

.route-stop-item:hover {
    background: #e9ecef;
    border-color: #007bff;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0, 123, 255, 0.15);
}

.route-stop-item.dragging {
    opacity: 0.5;
    transform: rotate(2deg);
    cursor: grabbing;
    z-index: 1000;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

.route-stop-item.drag-over {
    border-color: #28a745;
    background: #d4edda;
    transform: scale(1.02);
}

.drag-handle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    background: #6c757d;
    color: white;
    border-radius: 4px;
    margin-right: 12px;
    cursor: grab;
    font-size: 12px;
    font-weight: bold;
    user-select: none;
}

.drag-handle:hover {
    background: #495057;
}

.route-stop-item.dragging .drag-handle {
    cursor: grabbing;
}

.stop-number {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 35px;
    height: 35px;
    background: #007bff;
    color: white;
    border-radius: 50%;
    margin-right: 12px;
    font-weight: bold;
    font-size: 14px;
    user-select: none;
}

.stop-content {
    flex: 1;
    line-height: 1.4;
}

.stop-content strong {
    color: #495057;
    font-size: 16px;
}

.stop-content small {
    color: #6c757d;
    font-size: 13px;
}

/* Route optimization styles */
.route-summary {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.route-summary h4 {
    margin-top: 0;
    color: #495057;
}

.route-stats {
    display: flex;
    gap: 20px;
    margin: 10px 0;
}

.stat {
    background: #007bff;
    color: white;
    padding: 5px 12px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: 600;
}

.optimization-info {
    margin-top: 15px;
}

.copy-route-section {
    margin-top: 15px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.copy-route-btn {
    background: #28a745;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
}

.copy-route-btn:hover {
    background: #218838;
}

.copy-status {
    font-size: 12px;
    color: #6c757d;
}

.route-order {
    list-style: none;
    padding: 0;
    margin: 0;
}

.route-stop {
    padding: 15px;
    margin-bottom: 10px;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    background: white;
}

.route-stop.bakery {
    background: #fff3cd;
    border-color: #ffeaa7;
}

.route-stop.customer {
    background: #f8f9fa;
}

.route-stop.violation {
    border-left: 4px solid #dc3545;
    background: #f8d7da;
}

.constraint-violation {
    color: #dc3545;
    font-weight: 600;
    margin-top: 5px;
}

.constraint-ok {
    color: #28a745;
    font-weight: 600;
    margin-top: 5px;
}

.constraint-early {
    color: #ffc107;
    font-weight: 600;
    margin-top: 5px;
}

.no-constraints {
    color: #6c757d;
    font-style: italic;
    margin-top: 5px;
}

.manual-route-controls {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #dee2e6;
}

.lock-stop-btn, .move-up-btn, .move-down-btn, .move-to-btn {
    background: #6c757d;
    color: white;
    border: none;
    padding: 4px 8px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
}

.lock-stop-btn:hover, .move-up-btn:hover, .move-down-btn:hover, .move-to-btn:hover {
    background: #495057;
}

.move-to-input {
    width: 50px;
    padding: 4px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    text-align: center;
}

.move-to-controls {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    color: #6c757d;
}

.constraints-summary, .violations-summary {
    margin-top: 20px;
    padding: 15px;
    border-radius: 8px;
}

.constraints-summary {
    background: #d4edda;
    border: 1px solid #c3e6cb;
}

.violations-summary {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
}

.violations-summary h4 {
    color: #721c24;
    margin-top: 0;
}

.violations-list {
    margin: 10px 0;
    padding-left: 20px;
}

.violations-list li {
    color: #721c24;
    margin-bottom: 5px;
}

.suggestions {
    margin-top: 15px;
}

.suggestions ul {
    margin: 10px 0;
    padding-left: 20px;
}

.suggestions li {
    color: #721c24;
    margin-bottom: 5px;
}

.save-indicator {
    position: absolute;
    top: 10px;
    right: 10px;
    background: #28a745;
    color: white;
    padding: 5px 10px;
    border-radius: 4px;
    font-size: 12px;
    z-index: 100;
    animation: fadeInOut 0.3s ease-in;
}

@keyframes fadeInOut {
    0% { opacity: 0; transform: translateY(-10px); }
    100% { opacity: 1; transform: translateY(0); }
}
</style>
