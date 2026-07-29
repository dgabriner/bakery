<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';

/**
 * Driver tablet AJAX: photo list/upload (CSRF enforced via database bootstrap).
 */
if (PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['driver_action'])) {
    require_once 'includes/photo_handler.php';
    header('Content-Type: application/json');

    $photoHandler = new PhotoHandler();
    $action = (string)$_POST['driver_action'];

    try {
        if ($action === 'list_photos') {
            $driverId = (int)($_POST['driver_id'] ?? 0);
            $customerId = (int)($_POST['customer_id'] ?? 0);
            $date = trim((string)($_POST['date'] ?? ''));
            $dailyOrderId = (int)($_POST['daily_order_id'] ?? 0);

            if ($driverId <= 0 || $customerId <= 0 || $dailyOrderId <= 0 || $date === '') {
                throw new Exception('driver_id, customer_id, daily_order_id, and date are required');
            }

            $assignment = $photoHandler->verifyDeliveryAssignment($db, $driverId, $customerId, $dailyOrderId, $date);
            if (!$assignment) {
                throw new Exception('Photo assignment not found for this stop');
            }

            $photos = $photoHandler->getPhotos($db, $driverId, $date, $customerId);
            echo json_encode([
                'success' => true,
                'assignment' => [
                    'customer_name' => $assignment['customer_name'],
                    'route_order' => (int)$assignment['route_order'],
                    'daily_order_id' => $dailyOrderId,
                ],
                'photos' => $photos,
            ]);
            exit;
        }

        if ($action === 'upload_photo') {
            $driverId = (int)($_POST['driver_id'] ?? 0);
            $customerId = (int)($_POST['customer_id'] ?? 0);
            $dailyOrderId = (int)($_POST['daily_order_id'] ?? 0);
            $date = trim((string)($_POST['date'] ?? ''));
            $photoType = trim((string)($_POST['photo_type'] ?? 'Before'));

            if ($driverId <= 0 || $customerId <= 0 || $dailyOrderId <= 0 || $date === '') {
                throw new Exception('driver_id, customer_id, daily_order_id, and date are required');
            }
            if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
                throw new Exception('No photo file received');
            }

            $assignment = $photoHandler->verifyDeliveryAssignment($db, $driverId, $customerId, $dailyOrderId, $date);
            if (!$assignment) {
                throw new Exception('Cannot attach photo: stop is not assigned to this driver on this date');
            }

            $latitude = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? (float)$_POST['latitude'] : null;
            $longitude = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;
            $notes = trim((string)($_POST['notes'] ?? ''));

            $upload = $photoHandler->processUpload(
                $_FILES['photo'],
                $driverId,
                $customerId,
                $photoType,
                $notes,
                $latitude,
                $longitude
            );

            if (!$upload['success']) {
                throw new Exception($upload['error'] ?? 'Upload failed');
            }

            $upload['data']['daily_order_id'] = $dailyOrderId;
            $save = $photoHandler->saveToDatabase($db, $driverId, $customerId, $upload['data']);
            if (!$save['success']) {
                throw new Exception($save['error'] ?? 'Failed to save photo record');
            }

            echo json_encode($photoHandler->buildUploadSuccessResponse($assignment, $upload['data'], $save['photo_id']));
            exit;
        }

        throw new Exception('Unknown driver_action');
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

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

// Get all drivers for the dropdown
$drivers = [];
$driverStmt = $db->query("SELECT id, name FROM drivers ORDER BY name");
$driversData = $driverStmt->fetchAll();
foreach ($driversData as $driverData) {
    $drivers[$driverData['id']] = $driverData['name'];
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
            INNER JOIN daily_order_assignments doa ON do.id = doa.daily_order_id
            INNER JOIN drivers d ON doa.driver_id = d.id
            WHERE doa.driver_id = ? AND do.order_date = ?
            ORDER BY doa.route_order, c.zone, c.name
        ");
        
        $stmt->execute([$selectedDriverId, $selectedDate]);
        $results = $stmt->fetchAll();
        
        // Debug: Check if we have results and what columns are available
        if (empty($results)) {
            $error = "No orders found for driver ID $selectedDriverId on date $selectedDate";
        } else {
            // Debug: Check first row structure
            $firstRow = $results[0];
            if (!isset($firstRow['driver_id'])) {
                $error = "Missing driver_id column in query results. Available columns: " . implode(', ', array_keys($firstRow));
            }
        }
        
        // Process the data to create a zone-grouped structure
        if (!empty($results) && isset($results[0]['driver_id'])) {
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
                    'driver_id' => isset($row['driver_id']) ? $row['driver_id'] : null,
                    'driver_name' => $row['driver_name'],
                    'route_order' => $row['route_order'],
                    'scheduled_delivery_time' => $row['scheduled_delivery_time'],
                    'delivery_status' => $row['delivery_status'],
                    'driver_initial' => $row['driver_name'] ? strtoupper(substr($row['driver_name'], 0, 1)) : 'X',
                    'zone_color' => $zoneColorMap[$zone]
                ];
            }
        }
        
        // Calculate statistics
        $totalStops = count($results);
        $totalAmount = array_sum(array_column($results, 'total_amount'));
        $totalZones = count($driverDeliveries);
        
    } catch (Exception $e) {
        $error = 'Error loading driver data: ' . htmlspecialchars($e->getMessage());
    }
}

$serverDeliveredOrderIds = [];
foreach ($driverDeliveries as $zoneStops) {
    foreach ($zoneStops as $stop) {
        if (($stop['delivery_status'] ?? '') === 'delivered') {
            $serverDeliveredOrderIds[] = (int)$stop['daily_order_id'];
        }
    }
}
$completedStopsCount = count($serverDeliveredOrderIds);
$routeStopTotal = $totalStops ?? 0;

$routeStopMeta = [];
foreach ($driverDeliveries as $zoneStops) {
    foreach ($zoneStops as $stop) {
        $routeStopMeta[(int)$stop['daily_order_id']] = [
            'customer_id' => (int)$stop['customer_id'],
            'customer_name' => $stop['customer_name'],
            'customer_address' => $stop['customer_address'],
            'customer_phone' => $stop['customer_phone'] ?? '',
            'route_order' => (int)$stop['route_order'],
            'delivery_status' => $stop['delivery_status'] ?? 'pending',
        ];
    }
}

function driver_status_badge_class($status) {
    $normalized = strtolower(trim((string)$status));
    if ($normalized === '') {
        $normalized = 'pending';
    }
    $allowed = ['pending', 'in_transit', 'delivered', 'failed', 'cancelled'];
    if (!in_array($normalized, $allowed, true)) {
        $normalized = 'pending';
    }
    return 'status-badge status-badge--' . $normalized;
}

function driver_format_status_label($status) {
    $normalized = strtolower(trim((string)$status));
    return $normalized !== '' ? ucwords(str_replace('_', ' ', $normalized)) : 'Pending';
}

function driver_phone_href($phone) {
    $digits = preg_replace('/\D+/', '', (string)$phone);
    return $digits !== '' ? 'tel:' . $digits : '';
}

function driver_maps_href($address) {
    $encoded = rawurlencode((string)$address);
    return $encoded !== '' ? 'https://maps.google.com/?q=' . $encoded : '';
}

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>


<link rel=\"stylesheet\" href=\"/bakery/css/driver.css\">
<script src=\"/bakery/includes/global_tracking.js\"></script>

<div class="driver-list-container">
    <div class="view-toggle">
        <div class="btn-group" role="group">
            <button type="button" class="btn btn-outline-primary" id="mainViewBtn">
                <i class="bi bi-list-ul"></i> <span class="btn-label">Full Route</span>
            </button>
            <button type="button" class="btn btn-outline-success" id="deliveryViewBtn">
                <i class="bi bi-truck"></i> <span class="btn-label">Delivery Mode</span>
            </button>
        </div>
    </div>

    <div class="container py-4">
        <!-- Main View (Full Route) -->
        <div id="mainView">
            <div class="page-header">
                <h1>Driver Route List</h1>
                <p>Select a driver and date to view their daily delivery route with order details</p>
            </div>

            <!-- Driver and Date Selector -->
            <div class="selector-container">
                <form method="GET" action="" id="driverForm">
                    <div class="selector-grid">
                        <div class="selector-group">
                            <label class="selector-label">Select Driver</label>
                            <select name="driver_id" class="selector-input" onchange="updateForm()">
                                <option value="">Choose a driver...</option>
                                <?php foreach ($drivers as $driverId => $driverName): ?>
                                <option value="<?php echo $driverId; ?>" <?php echo $selectedDriverId == $driverId ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($driverName); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="selector-group">
                            <label class="selector-label">Select Date</label>
                            <input type="date" name="date" class="selector-input" value="<?php echo htmlspecialchars($selectedDate); ?>" onchange="updateForm()">
                        </div>
                    </div>
                    
                    <div style="text-align: center; margin-top: 25px;">
                        <button type="submit" class="go-button" <?php echo $selectedDriverId > 0 ? '' : 'disabled'; ?>>
                            View Driver Route
                        </button>
                    </div>
                </form>
            </div>

            <?php if ($selectedDriverId > 0 && $driver): ?>

            <div class="offline-hint" role="note">
                <strong>On-device cache</strong>
                Delivery mode saves completed stops in this browser (<code>delivered_orders_{driver}_{date}</code>).
                Route data is cached as <code>driver_orders_cache_{driver}_{date}</code> for offline viewing.
                Server status refreshes when you are back online.
            </div>

            <?php if ($routeStopTotal > 0): ?>
            <div class="driver-sticky-bar" id="routeStickyBar" aria-live="polite">
                <p class="sticky-title"><?php echo htmlspecialchars($driver['name']); ?> — <?php echo date('M j, Y', strtotime($selectedDate)); ?></p>
                <div class="route-progress-text" id="stickyProgressText">
                    <?php echo $completedStopsCount; ?> of <?php echo $routeStopTotal; ?> stops complete
                </div>
                <div class="route-progress-track" aria-hidden="true">
                    <div class="route-progress-fill" id="stickyProgressFill" style="width: <?php echo $routeStopTotal > 0 ? round(($completedStopsCount / $routeStopTotal) * 100) : 0; ?>%;"></div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Date Navigation -->
            <div class="date-navigation">
                <div class="date-info">
                    <h2>Route for <?php echo htmlspecialchars($driver['name']); ?> on <?php echo date('l, F j, Y', strtotime($selectedDate)); ?></h2>
                    <div class="order-count">Total Stops: <?php echo count($driverDeliveries) > 0 ? array_sum(array_map('count', $driverDeliveries)) : 0; ?></div>
                </div>
                <div class="date-controls">
                    <a href="?driver_id=<?php echo $selectedDriverId; ?>&date=<?php echo date('Y-m-d', strtotime($selectedDate . ' -1 day')); ?>" class="btn btn-outline">Previous Day</a>
                    <a href="?driver_id=<?php echo $selectedDriverId; ?>&date=<?php echo date('Y-m-d'); ?>" class="btn btn-primary">Today</a>
                    <a href="?driver_id=<?php echo $selectedDriverId; ?>&date=<?php echo date('Y-m-d', strtotime($selectedDate . ' +1 day')); ?>" class="btn btn-outline">Next Day</a>
                </div>
            </div>

            <!-- Statistics -->
            <div class="stats-container">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $totalStops; ?></div>
                        <div class="stat-label">Total Stops</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">$<?php echo number_format($totalAmount, 2); ?></div>
                        <div class="stat-label">Total Amount</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $totalZones; ?></div>
                        <div class="stat-label">Zones</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $driver['name'] ? strtoupper(substr($driver['name'], 0, 1)) : 'X'; ?></div>
                        <div class="stat-label">Driver</div>
                    </div>
                </div>
            </div>

            <!-- Deliveries by Zone -->
            <div class="deliveries-container">
                <?php if (empty($driverDeliveries)): ?>
                    <div style="padding: 40px 20px; text-align: center; color: #6c757d;">
                        <h3>No deliveries found</h3>
                        <p>No orders are assigned to this driver for the selected date.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($driverDeliveries as $zone => $stops): ?>
                        <div class="zone-section">
                            <div class="zone-header">
                                <div class="zone-color-indicator" style="background-color: <?php echo $zoneColorMap[$zone]; ?>;"></div>
                                <span><?php echo htmlspecialchars($zone); ?></span>
                                <div class="zone-stops-count"><?php echo count($stops); ?> stops</div>
                            </div>
                            <div class="zone-content">
                                <?php foreach ($stops as $stop): ?>
                                    <?php
                                    $stopStatus = $stop['delivery_status'] ?? 'pending';
                                    $isDeliveredStop = $stopStatus === 'delivered';
                                    $phoneHref = driver_phone_href($stop['customer_phone'] ?? '');
                                    $mapsHref = driver_maps_href($stop['customer_address'] ?? '');
                                    ?>
                                    <div class="customer-stop<?php echo $isDeliveredStop ? ' is-delivered' : ''; ?>" data-daily-order-id="<?php echo (int)$stop['daily_order_id']; ?>">
                                        <div class="stop-header" onclick="toggleOrderDetails(<?php echo (int)$stop['daily_order_id']; ?>, <?php echo json_encode($stop['customer_name']); ?>, <?php echo json_encode($stop['customer_address']); ?>)">
                                            <div class="stop-number"><?php echo (int)$stop['route_order']; ?></div>
                                            <div class="customer-info">
                                                <div class="customer-name"><?php echo htmlspecialchars($stop['customer_name']); ?></div>
                                                <div class="customer-address"><?php echo htmlspecialchars($stop['customer_address']); ?></div>
                                            </div>
                                            <span class="<?php echo driver_status_badge_class($stopStatus); ?>"><?php echo driver_format_status_label($stopStatus); ?></span>
                                        </div>
                                        <div class="contact-actions">
                                            <?php if ($phoneHref): ?>
                                            <a class="contact-link contact-link--phone" href="<?php echo htmlspecialchars($phoneHref); ?>" onclick="event.stopPropagation();">📞 Call</a>
                                            <?php endif; ?>
                                            <?php if ($mapsHref): ?>
                                            <a class="contact-link contact-link--address" href="<?php echo htmlspecialchars($mapsHref); ?>" target="_blank" rel="noopener" onclick="event.stopPropagation();">🗺 Navigate</a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="stop-details">
                                            <div class="stop-detail">
                                                <i class="fas fa-clock"></i>
                                                <span><?php echo $stop['scheduled_delivery_time'] ?: 'No time set'; ?></span>
                                            </div>
                                            <div class="stop-detail">
                                                <i class="fas fa-dollar-sign"></i>
                                                <span>$<?php echo number_format($stop['total_amount'], 2); ?></span>
                                            </div>
                                        </div>
                                        <div class="stop-actions">
                                            <?php if (!$isDeliveredStop): ?>
                                            <button type="button" class="photo-btn-inline" onclick="event.stopPropagation(); openPhotoUploadForStop(<?php echo (int)$stop['daily_order_id']; ?>)">📷 Photo</button>
                                            <button type="button" class="complete-delivery-btn" onclick="event.stopPropagation(); openCompleteDeliveryModal(<?php echo (int)$stop['daily_order_id']; ?>, <?php echo json_encode($stop['customer_name']); ?>)">Complete</button>
                                            <?php else: ?>
                                            <span class="status-badge status-badge--delivered">✓ Delivered</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="order-details" id="order-details-<?php echo (int)$stop['daily_order_id']; ?>">
                                            <div style="text-align: center; color: #6c757d; font-style: italic;">
                                                Tap header to view order details...
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <?php elseif ($selectedDriverId > 0): ?>
                <div class="empty-state">
                    <h3>Driver not found</h3>
                    <p>The selected driver could not be found in the system.</p>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h3>Select a Driver</h3>
                    <p>Please select a driver and date from the options above to view their daily route.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Delivery View (One at a time) -->
        <div id="deliveryView" style="display: none;">
            <div class="page-header">
                <h1>Delivery Mode</h1>
                <p>Focus on one delivery at a time - mark as delivered to move to the next</p>
            </div>

            <!-- Driver and Date Selector for Delivery Mode -->
            <div class="selector-container">
                <form method="GET" action="" id="deliveryForm">
                    <input type="hidden" name="view" value="delivery">
                    <div class="selector-grid">
                        <div class="selector-group">
                            <label class="selector-label">Select Driver</label>
                            <select name="driver_id" class="selector-input" onchange="updateDeliveryForm()">
                                <option value="">Choose a driver...</option>
                                <?php foreach ($drivers as $driverId => $driverName): ?>
                                <option value="<?php echo $driverId; ?>" <?php echo $selectedDriverId == $driverId ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($driverName); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="selector-group">
                            <label class="selector-label">Select Date</label>
                            <input type="date" name="date" class="selector-input" value="<?php echo htmlspecialchars($selectedDate); ?>" onchange="updateDeliveryForm()">
                        </div>
                    </div>
                    
                    <div style="text-align: center; margin-top: 25px;">
                        <button type="submit" class="go-button" <?php echo $selectedDriverId > 0 ? '' : 'disabled'; ?>>
                            Start Delivery Mode
                        </button>
                    </div>
                </form>
            </div>

            <?php if ($selectedDriverId > 0 && $driver): ?>
            
            <!-- Delivery Progress -->
            <div class="stats-container">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number" id="deliveryProgress">0/0</div>
                        <div class="stat-label">Progress</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" id="deliveryTotal">$0.00</div>
                        <div class="stat-label">Total Amount</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" id="deliveryZones">0</div>
                        <div class="stat-label">Zones</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $driver['name'] ? strtoupper(substr($driver['name'], 0, 1)) : 'X'; ?></div>
                        <div class="stat-label">Driver</div>
                    </div>
                </div>
            </div>

            <!-- Delivery Controls -->
            <div class="date-navigation">
                <div class="date-info">
                    <h2>Delivery for <?php echo htmlspecialchars($driver['name']); ?> on <?php echo date('l, F j, Y', strtotime($selectedDate)); ?></h2>
                    <div class="order-count" id="deliveryStatus">Loading...</div>
                </div>
                <div class="date-controls">
                    <button class="btn btn-warning" onclick="undoLastDelivery()">
                        <i class="bi bi-arrow-counterclockwise"></i> Undo Last
                    </button>
                    <button class="btn btn-info" onclick="resetAllDeliveries()">
                        <i class="bi bi-arrow-clockwise"></i> Reset All
                    </button>
                    <button class="btn btn-outline" onclick="showDeliveryHistory()">
                        <i class="bi bi-clock-history"></i> History
                    </button>
                </div>
            </div>

            <!-- Current Delivery -->
            <div id="currentDeliveryContainer">
                <!-- Current delivery will be loaded here -->
            </div>

            <!-- Delivery History Modal -->
            <div id="deliveryHistoryModal" class="modal" style="display: none;">
                <div class="modal-content">
                    <span class="close-modal" onclick="closeDeliveryHistory()">&times;</span>
                    <h2>Delivery History</h2>
                    <div id="deliveryHistoryContent">
                        <!-- History will be loaded here -->
                    </div>
                </div>
            </div>
            
            <?php else: ?>
                <div class="empty-state">
                    <h3>Select a Driver</h3>
                    <p>Please select a driver and date to start delivery mode.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="complete-delivery-modal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.4); z-index:9999; align-items:center; justify-content:center;">
  <div class="modal-content" style="background:#fff; border-radius:10px; max-width:95vw; width:400px; margin:auto; padding:24px; position:relative;">
    <span class="close-modal" onclick="closeCompleteDeliveryModal()" style="position:absolute; top:10px; right:16px; font-size:1.5rem; cursor:pointer;">&times;</span>
    <h2 id="modal-customer-name" style="margin-top:0;"></h2>
    <div id="modal-photo-section" class="modal-photo-section">
      <h3>Delivery photo (optional)</h3>
      <div id="modal-photo-assignment" class="photo-assignment-confirm"></div>
      <div class="photo-upload-row">
        <select id="modal-photo-type" class="selector-input" style="min-height:48px; flex:1;">
          <option value="Before">Before</option>
          <option value="After">After</option>
          <option value="Receipt">Receipt</option>
        </select>
        <input type="file" id="modal-photo-file" accept="image/*" capture="environment">
      </div>
      <button type="button" class="go-button" id="modal-photo-upload-btn" style="width:100%; margin-top:8px;">Upload photo for this stop</button>
      <div class="photo-upload-progress" id="modal-photo-progress">
        <div class="photo-upload-progress-bar"><div class="photo-upload-progress-fill" id="modal-photo-progress-fill"></div></div>
      </div>
      <div id="modal-photo-status" class="photo-upload-status" role="status"></div>
    </div>
    <div id="modal-step-initial">
      <p>How was the delivery?</p>
      <button onclick="completeDeliveryAsPlanned()" class="go-button" style="margin-bottom:10px; width:100%;">Delivery as planned</button>
      <button onclick="showModifyOrderForm()" class="go-button btn-outline" style="width:100%;">Modify order</button>
    </div>
    <div id="modal-step-modify" style="display:none;"></div>
    <div id="modal-step-loading" style="display:none; text-align:center; color:#6c757d;">Loading...</div>
  </div>
</div>

<script>
// Global variables for delivery mode
let deliveryData = [];
let deliveredOrders = [];
let deliveryHistory = [];
const serverDeliveredOrderIds = <?php echo json_encode($serverDeliveredOrderIds); ?>;
const routeStopMeta = <?php echo json_encode($routeStopMeta); ?>;
const driverPageConfig = {
    driverId: <?php echo $selectedDriverId ?: 'null'; ?>,
    date: <?php echo json_encode($selectedDate); ?>
};

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
}

function phoneDigits(phone) {
    return String(phone || '').replace(/\D+/g, '');
}

function buildContactActions(orderId) {
    const meta = routeStopMeta[orderId] || {};
    const parts = [];
    const digits = phoneDigits(meta.customer_phone);
    if (digits) {
        parts.push('<a class="contact-link contact-link--phone" href="tel:' + digits + '" onclick="event.stopPropagation();">📞 Call</a>');
    }
    if (meta.customer_address) {
        parts.push('<a class="contact-link contact-link--address" href="https://maps.google.com/?q=' + encodeURIComponent(meta.customer_address) + '" target="_blank" rel="noopener" onclick="event.stopPropagation();">🗺 Navigate</a>');
    }
    return parts.length ? '<div class="contact-actions">' + parts.join('') + '</div>' : '';
}

function statusBadgeClass(status) {
    const normalized = String(status || 'pending').toLowerCase().replace(/\s+/g, '_');
    const allowed = ['pending', 'in_transit', 'delivered', 'failed', 'cancelled'];
    const key = allowed.includes(normalized) ? normalized : 'pending';
    return 'status-badge status-badge--' + key;
}

function formatStatusLabel(status) {
    const normalized = String(status || 'pending').toLowerCase();
    if (!normalized) return 'Pending';
    return normalized.split('_').map(function(w) { return w.charAt(0).toUpperCase() + w.slice(1); }).join(' ');
}

function updateStickyProgress(completed, total) {
    const textEl = document.getElementById('stickyProgressText');
    const fillEl = document.getElementById('stickyProgressFill');
    if (textEl) {
        textEl.textContent = completed + ' of ' + total + ' stops complete';
    }
    if (fillEl && total > 0) {
        fillEl.style.width = Math.round((completed / total) * 100) + '%';
    }
}

function getOrdersCacheKey(driverId, date) {
    return 'driver_orders_cache_' + driverId + '_' + date;
}

function setPhotoStatus(message, type) {
    const el = document.getElementById('modal-photo-status');
    if (!el) return;
    el.textContent = message || '';
    el.className = 'photo-upload-status' + (type ? ' is-' + type : '');
}

function resetPhotoUploadUi() {
    const fileInput = document.getElementById('modal-photo-file');
    const progress = document.getElementById('modal-photo-progress');
    const fill = document.getElementById('modal-photo-progress-fill');
    const btn = document.getElementById('modal-photo-upload-btn');
    if (fileInput) fileInput.value = '';
    if (progress) progress.classList.remove('is-active');
    if (fill) fill.style.width = '0%';
    if (btn) btn.disabled = false;
    setPhotoStatus('', '');
}

function updateModalPhotoAssignment(orderId) {
    const meta = routeStopMeta[orderId] || {};
    const el = document.getElementById('modal-photo-assignment');
    if (!el) return;
    if (!meta.customer_name) {
        el.textContent = 'Stop #' + orderId;
        return;
    }
    el.textContent = 'Attaching to: ' + meta.customer_name + ' (stop #' + (meta.route_order || '?') + ', order #' + orderId + ')';
}

function openPhotoUploadForStop(orderId) {
    openCompleteDeliveryModal(orderId, (routeStopMeta[orderId] || {}).customer_name || 'Customer');
}

function uploadModalPhoto() {
    const orderId = currentDeliveryOrderId;
    const meta = routeStopMeta[orderId];
    const fileInput = document.getElementById('modal-photo-file');
    const btn = document.getElementById('modal-photo-upload-btn');
    const progress = document.getElementById('modal-photo-progress');
    const fill = document.getElementById('modal-photo-progress-fill');

    if (!orderId || !meta || !meta.customer_id) {
        setPhotoStatus('Cannot upload: stop assignment not found on this page.', 'error');
        return;
    }
    if (!fileInput || !fileInput.files || !fileInput.files.length) {
        setPhotoStatus('Choose a photo first.', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('driver_action', 'upload_photo');
    formData.append('driver_id', driverPageConfig.driverId);
    formData.append('customer_id', meta.customer_id);
    formData.append('daily_order_id', orderId);
    formData.append('date', driverPageConfig.date);
    formData.append('photo_type', document.getElementById('modal-photo-type').value);
    formData.append('photo', fileInput.files[0]);

    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function(pos) {
            formData.append('latitude', pos.coords.latitude);
            formData.append('longitude', pos.coords.longitude);
            sendPhotoUpload(formData, btn, progress, fill);
        }, function() {
            sendPhotoUpload(formData, btn, progress, fill);
        }, { timeout: 5000 });
    } else {
        sendPhotoUpload(formData, btn, progress, fill);
    }
}

function sendPhotoUpload(formData, btn, progress, fill) {
    btn.disabled = true;
    progress.classList.add('is-active');
    fill.style.width = '35%';
    setPhotoStatus('Uploading photo…', 'loading');

    fetch('driver_list.php', { method: 'POST', body: formData })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            fill.style.width = '100%';
            if (data.success) {
                setPhotoStatus(data.message || 'Photo uploaded.', 'success');
                fileInputClear();
            } else {
                setPhotoStatus(data.error || 'Upload failed.', 'error');
                btn.disabled = false;
            }
        })
        .catch(function(err) {
            setPhotoStatus('Upload error: ' + err.message, 'error');
            btn.disabled = false;
        })
        .finally(function() {
            setTimeout(function() {
                progress.classList.remove('is-active');
                fill.style.width = '0%';
            }, 800);
        });
}

function fileInputClear() {
    const fileInput = document.getElementById('modal-photo-file');
    if (fileInput) fileInput.value = '';
}

// View toggle functionality
function showMainView() {
    document.getElementById('mainView').style.display = 'block';
    document.getElementById('deliveryView').style.display = 'none';
    document.getElementById('mainViewBtn').classList.add('active');
    document.getElementById('deliveryViewBtn').classList.remove('active');
}

function showDeliveryView() {
    document.getElementById('mainView').style.display = 'none';
    document.getElementById('deliveryView').style.display = 'block';
    document.getElementById('mainViewBtn').classList.remove('active');
    document.getElementById('deliveryViewBtn').classList.add('active');
    
    // Load delivery data if driver is selected
    if (<?php echo $selectedDriverId > 0 ? 'true' : 'false'; ?>) {
        loadDeliveryData();
    }
}

// Load delivery data for the selected driver and date
function loadDeliveryData() {
    const driverId = <?php echo $selectedDriverId ?: 'null'; ?>;
    const date = '<?php echo $selectedDate; ?>';
    
    if (!driverId) return;
    
    // Load delivered orders from localStorage and merge server-side delivered state
    const storageKey = `delivered_orders_${driverId}_${date}`;
    deliveredOrders = JSON.parse(localStorage.getItem(storageKey) || '[]');
    serverDeliveredOrderIds.forEach(function(id) {
        if (!deliveredOrders.includes(id)) {
            deliveredOrders.push(id);
        }
    });
    deliveryHistory = JSON.parse(localStorage.getItem(storageKey + '_history') || '[]');

    const cacheKey = getOrdersCacheKey(driverId, date);
    const cached = localStorage.getItem(cacheKey);
    if (cached) {
        try {
            deliveryData = JSON.parse(cached);
            updateDeliveryView();
        } catch (e) {
            console.warn('Invalid orders cache', e);
        }
    }

    fetch('get_driver_orders.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'driver_id=' + driverId + '&date=' + encodeURIComponent(date)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            deliveryData = data.orders;
            localStorage.setItem(cacheKey, JSON.stringify(deliveryData));
            updateDeliveryView();
        } else {
            console.error('Error loading delivery data:', data.error);
            if (!deliveryData.length && cached) {
                document.getElementById('deliveryStatus').textContent = 'Showing cached route (offline)';
            }
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        if (deliveryData.length) {
            const statusEl = document.getElementById('deliveryStatus');
            if (statusEl) statusEl.textContent = 'Offline — showing cached route';
        }
    });
}

// Update delivery view with current data
function updateDeliveryView() {
    const undeliveredOrders = deliveryData.filter(order => !deliveredOrders.includes(order.daily_order_id));
    const nextOrder = undeliveredOrders[0];
    
    // Update progress
    const progress = deliveredOrders.length;
    const total = deliveryData.length;
    document.getElementById('deliveryProgress').textContent = progress + '/' + total;
    updateStickyProgress(progress, total);
    
    // Update total amount
    const totalAmount = deliveryData.reduce((sum, order) => sum + parseFloat(order.total_amount), 0);
    document.getElementById('deliveryTotal').textContent = '$' + totalAmount.toFixed(2);
    
    // Update zones count
    const zones = [...new Set(deliveryData.map(order => order.zone))];
    document.getElementById('deliveryZones').textContent = zones.length;
    
    // Update status
    if (nextOrder) {
        document.getElementById('deliveryStatus').textContent = `Next: ${nextOrder.customer_name}`;
        showCurrentDelivery(nextOrder);
    } else {
        document.getElementById('deliveryStatus').textContent = 'All deliveries completed!';
        showCompletionMessage();
    }
}

// Show current delivery
function showCurrentDelivery(order) {
    const meta = routeStopMeta[order.daily_order_id] || {};
    const status = deliveredOrders.includes(order.daily_order_id) ? 'delivered' : (meta.delivery_status || 'pending');
    const statusLabel = formatStatusLabel(status);
    const container = document.getElementById('currentDeliveryContainer');
    container.innerHTML =
        '<div class="deliveries-container">' +
            '<div class="zone-section">' +
                '<div class="zone-header">' +
                    '<div class="zone-color-indicator" style="background-color: ' + getZoneColor(order.zone) + ';"></div>' +
                    '<span>' + escapeHtml(order.zone) + '</span>' +
                    '<div class="zone-stops-count">Stop ' + order.route_order + '</div>' +
                '</div>' +
                '<div class="zone-content">' +
                    '<div class="customer-stop' + (status === 'delivered' ? ' is-delivered' : '') + '">' +
                        '<div class="stop-header" onclick="toggleOrderDetails(' + order.daily_order_id + ', ' + JSON.stringify(order.customer_name) + ', ' + JSON.stringify(order.customer_address) + ')">' +
                            '<div class="stop-number">' + order.route_order + '</div>' +
                            '<div class="customer-info">' +
                                '<div class="customer-name">' + escapeHtml(order.customer_name) + '</div>' +
                                '<div class="customer-address">' + escapeHtml(order.customer_address) + '</div>' +
                            '</div>' +
                            '<span class="' + statusBadgeClass(status) + '">' + escapeHtml(statusLabel) + '</span>' +
                        '</div>' +
                        buildContactActions(order.daily_order_id) +
                        '<div class="stop-details">' +
                            '<div class="stop-detail"><i class="fas fa-clock"></i><span>' + escapeHtml(order.scheduled_delivery_time || 'No time set') + '</span></div>' +
                            '<div class="stop-detail"><i class="fas fa-dollar-sign"></i><span>$' + parseFloat(order.total_amount).toFixed(2) + '</span></div>' +
                        '</div>' +
                        '<div class="stop-actions">' +
                            '<button type="button" class="photo-btn-inline" onclick="event.stopPropagation(); openPhotoUploadForStop(' + order.daily_order_id + ')">📷 Photo</button>' +
                            '<button type="button" class="complete-delivery-btn" onclick="event.stopPropagation(); markAsDelivered(' + order.daily_order_id + ', ' + JSON.stringify(order.customer_name) + ')">Complete</button>' +
                        '</div>' +
                        '<div class="order-details" id="order-details-' + order.daily_order_id + '">' +
                            '<div style="text-align: center; color: #6c757d; font-style: italic;">Tap header to view order details...</div>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>' +
        '</div>';
}

// Show completion message
function showCompletionMessage() {
    const container = document.getElementById('currentDeliveryContainer');
    container.innerHTML = `
        <div class="deliveries-container">
            <div style="padding: 60px 20px; text-align: center; color: #28a745;">
                <i class="fas fa-check-circle" style="font-size: 4rem; margin-bottom: 20px;"></i>
                <h2>All Deliveries Complete!</h2>
                <p>Great job! All deliveries for this route have been completed successfully.</p>
                <button class="go-button" onclick="resetAllDeliveries()" style="margin-top: 20px;">
                    <i class="bi bi-arrow-clockwise"></i> Reset All Deliveries
                </button>
            </div>
        </div>
    `;
}

// Mark order as delivered
function markAsDelivered(dailyOrderId, customerName) {
    // Open the complete delivery modal instead of directly marking as delivered
    openCompleteDeliveryModal(dailyOrderId, customerName);
}

// Update the openCompleteDeliveryModal function to work with delivery mode
function openCompleteDeliveryModal(orderId, customerName) {
    currentDeliveryOrderId = orderId;
    document.getElementById('modal-customer-name').textContent = 'Complete Delivery for ' + customerName;
    document.getElementById('modal-step-initial').style.display = '';
    document.getElementById('modal-step-modify').style.display = 'none';
    document.getElementById('modal-step-loading').style.display = 'none';
    document.getElementById('modal-photo-section').style.display = '';
    updateModalPhotoAssignment(orderId);
    resetPhotoUploadUi();
    document.getElementById('complete-delivery-modal').style.display = 'flex';
}

// Update completeDeliveryAsPlanned to work with delivery mode
function completeDeliveryAsPlanned() {
    if (!currentDeliveryOrderId) return;
    
    // Show loading state
    document.getElementById('modal-step-initial').style.display = 'none';
    document.getElementById('modal-step-loading').style.display = '';
    document.getElementById('modal-step-loading').innerHTML = '<div style="text-align: center; color: #6c757d;">Marking delivery as completed...</div>';
    
    // Make AJAX call to mark as delivered
    fetch('complete_delivery.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=mark_delivered&daily_order_id=' + currentDeliveryOrderId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('modal-step-loading').innerHTML = '<div style="text-align: center; color: #28a745; padding: 20px;">✅ ' + data.message + '</div>';
            setTimeout(() => {
                closeCompleteDeliveryModal();
                // For delivery mode, update the view instead of reloading
                if (document.getElementById('deliveryView').style.display !== 'none') {
                    // Add to delivered orders for delivery mode
                    if (!deliveredOrders.includes(currentDeliveryOrderId)) {
                        deliveredOrders.push(currentDeliveryOrderId);
                        
                        // Add to history
                        const customerName = document.getElementById('modal-customer-name').textContent.replace('Complete Delivery for ', '');
                        deliveryHistory.unshift({
                            daily_order_id: currentDeliveryOrderId,
                            customer_name: customerName,
                            timestamp: new Date().toLocaleString()
                        });
                        
                        // Save to localStorage
                        const driverId = <?php echo $selectedDriverId ?: 'null'; ?>;
                        const date = '<?php echo $selectedDate; ?>';
                        const storageKey = `delivered_orders_${driverId}_${date}`;
                        localStorage.setItem(storageKey, JSON.stringify(deliveredOrders));
                        localStorage.setItem(storageKey + '_history', JSON.stringify(deliveryHistory));
                        
                        // Update delivery view
                        updateDeliveryView();
                    }
                } else {
                    // For main view, reload the page
                    location.reload();
                }
            }, 1500);
        } else {
            document.getElementById('modal-step-loading').innerHTML = '<div style="text-align: center; color: #dc3545; padding: 20px;">❌ Error: ' + (data.error || 'Unknown error') + '</div>';
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        document.getElementById('modal-step-loading').innerHTML = '<div style="text-align: center; color: #dc3545; padding: 20px;">❌ Error: ' + error.message + '</div>';
    });
}

// Update saveModifiedOrder to work with delivery mode
function saveModifiedOrder() {
    if (!currentDeliveryOrderId) return;
    
    // Collect all quantity updates
    const updates = {};
    const form = document.getElementById('modify-order-form');
    const inputs = form.querySelectorAll('input[name^="quantity_"]');
    
    inputs.forEach(input => {
        const itemId = input.name.replace('quantity_', '');
        const quantity = parseInt(input.value) || 0;
        updates[itemId] = quantity;
    });
    
    console.log('Updates to send:', updates); // Debug log
    
    // Show loading state
    document.getElementById('modal-step-modify').style.display = 'none';
    document.getElementById('modal-step-loading').style.display = '';
    document.getElementById('modal-step-loading').innerHTML = '<div style="text-align: center; color: #6c757d;">Saving changes and completing delivery...</div>';
    
    // Make AJAX call to update order and mark as delivered
    const params = new URLSearchParams();
    params.append('action', 'update_order_and_deliver');
    params.append('daily_order_id', currentDeliveryOrderId);
    params.append('updates', JSON.stringify(updates));
    
    fetch('complete_delivery.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: params.toString()
    })
    .then(response => response.json())
    .then(data => {
        console.log('Response:', data); // Debug log
        if (data.success) {
            document.getElementById('modal-step-loading').innerHTML = '<div style="text-align: center; color: #28a745; padding: 20px;">✅ ' + data.message + '</div>';
            setTimeout(() => {
                closeCompleteDeliveryModal();
                // For delivery mode, update the view instead of reloading
                if (document.getElementById('deliveryView').style.display !== 'none') {
                    // Add to delivered orders for delivery mode
                    if (!deliveredOrders.includes(currentDeliveryOrderId)) {
                        deliveredOrders.push(currentDeliveryOrderId);
                        
                        // Add to history
                        const customerName = document.getElementById('modal-customer-name').textContent.replace('Complete Delivery for ', '');
                        deliveryHistory.unshift({
                            daily_order_id: currentDeliveryOrderId,
                            customer_name: customerName,
                            timestamp: new Date().toLocaleString()
                        });
                        
                        // Save to localStorage
                        const driverId = <?php echo $selectedDriverId ?: 'null'; ?>;
                        const date = '<?php echo $selectedDate; ?>';
                        const storageKey = `delivered_orders_${driverId}_${date}`;
                        localStorage.setItem(storageKey, JSON.stringify(deliveredOrders));
                        localStorage.setItem(storageKey + '_history', JSON.stringify(deliveryHistory));
                        
                        // Update delivery view
                        updateDeliveryView();
                    }
                } else {
                    // For main view, reload the page
                    location.reload();
                }
            }, 1500);
        } else {
            document.getElementById('modal-step-loading').innerHTML = '<div style="text-align: center; color: #dc3545; padding: 20px;">❌ Error: ' + (data.error || 'Unknown error') + '</div>';
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        document.getElementById('modal-step-loading').innerHTML = '<div style="text-align: center; color: #dc3545; padding: 20px;">❌ Error: ' + error.message + '</div>';
    });
}

// Function to update product total in real-time
function updateProductTotal(input, unitPrice) {
    const quantity = parseInt(input.value) || 0;
    const itemId = input.name.replace('quantity_', '');
    const totalElement = document.getElementById('total_' + itemId);
    
    if (totalElement) {
        const total = quantity * unitPrice;
        totalElement.textContent = '$' + total.toFixed(2);
    }
}

// Show delivery history
function showDeliveryHistory() {
    const modal = document.getElementById('deliveryHistoryModal');
    const content = document.getElementById('deliveryHistoryContent');
    
    if (deliveryHistory.length === 0) {
        content.innerHTML = '<p style="text-align: center; color: #6c757d;">No deliveries completed yet.</p>';
    } else {
        const historyHtml = deliveryHistory.map(delivery => `
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px; border-bottom: 1px solid #e9ecef;">
                <div>
                    <strong>${delivery.customer_name}</strong>
                    <br>
                    <small style="color: #6c757d;">${delivery.timestamp}</small>
                </div>
                <button class="btn btn-sm btn-outline-danger" onclick="undoSpecificDelivery(${delivery.daily_order_id})">
                    Undo
                </button>
            </div>
        `).join('');
        content.innerHTML = historyHtml;
    }
    
    modal.style.display = 'flex';
}

// Close delivery history
function closeDeliveryHistory() {
    document.getElementById('deliveryHistoryModal').style.display = 'none';
}

// Undo specific delivery
function undoSpecificDelivery(dailyOrderId) {
    deliveredOrders = deliveredOrders.filter(id => id !== dailyOrderId);
    deliveryHistory = deliveryHistory.filter(delivery => delivery.daily_order_id !== dailyOrderId);
    
    // Save to localStorage
    const driverId = <?php echo $selectedDriverId ?: 'null'; ?>;
    const date = '<?php echo $selectedDate; ?>';
    const storageKey = `delivered_orders_${driverId}_${date}`;
    localStorage.setItem(storageKey, JSON.stringify(deliveredOrders));
    localStorage.setItem(storageKey + '_history', JSON.stringify(deliveryHistory));
    
    // Update view and close modal
    updateDeliveryView();
    closeDeliveryHistory();
}

// Get zone color
function getZoneColor(zone) {
    const colors = ['#007bff', '#28a745', '#dc3545', '#fd7e14', '#6f42c1', '#20c997', '#ffc107', '#e83e8c', '#6c757d', '#17a2b8'];
    const index = zone.charCodeAt(0) % colors.length;
    return colors[index];
}

// Update delivery form
function updateDeliveryForm() {
    const driverSelect = document.querySelector('#deliveryForm select[name="driver_id"]');
    const goButton = document.querySelector('#deliveryForm .go-button');
    
    if (driverSelect.value) {
        goButton.disabled = false;
        goButton.style.opacity = '1';
    } else {
        goButton.disabled = true;
        goButton.style.opacity = '0.6';
    }
}

// Global function for order details toggle
function toggleOrderDetails(dailyOrderId, customerName, customerAddress) {
    const orderDetailsElement = document.getElementById('order-details-' + dailyOrderId);
    if (!orderDetailsElement) {
        console.error('Order details element not found for ID:', dailyOrderId);
        return;
    }
    
    const isExpanded = orderDetailsElement.classList.contains('expanded');
    
    if (!isExpanded) {
        // Load order details if not already loaded
        if (orderDetailsElement.innerHTML.includes('Click to view order details') || orderDetailsElement.innerHTML.includes('Loading order details')) {
            loadOrderDetails(dailyOrderId, orderDetailsElement);
        }
        
        // Mobile-friendly expand animation
        orderDetailsElement.style.maxHeight = '0';
        orderDetailsElement.style.overflow = 'hidden';
        orderDetailsElement.classList.add('expanded');
        
        // Smooth expansion animation
        setTimeout(() => {
            orderDetailsElement.style.maxHeight = orderDetailsElement.scrollHeight + 'px';
        }, 10);
        
        // Clean up after animation
        setTimeout(() => {
            orderDetailsElement.style.maxHeight = '';
            orderDetailsElement.style.overflow = '';
        }, 300);
    } else {
        // Collapse animation
        orderDetailsElement.style.maxHeight = orderDetailsElement.scrollHeight + 'px';
        orderDetailsElement.style.overflow = 'hidden';
        
        setTimeout(() => {
            orderDetailsElement.style.maxHeight = '0';
        }, 10);
        
        setTimeout(() => {
            orderDetailsElement.classList.remove('expanded');
            orderDetailsElement.style.maxHeight = '';
            orderDetailsElement.style.overflow = '';
        }, 300);
    }
}

// Global function for loading order details
function loadOrderDetails(dailyOrderId, orderDetailsElement) {
    console.log('Loading order details for daily order ID:', dailyOrderId);
    
    // Show loading state
    orderDetailsElement.innerHTML = '<div style="text-align: center; color: #6c757d; font-style: italic; padding: 20px;">Loading order details...</div>';
    
    // Make the AJAX call to get order details
    fetch('get_customer_order_details.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'daily_order_id=' + dailyOrderId
    })
    .then(response => {
        console.log('Response status:', response.status);
        if (!response.ok) {
            throw new Error('Network response was not ok: ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        console.log('Response data:', data);
        if (data.success) {
            orderDetailsElement.innerHTML = data.html;
        } else {
            orderDetailsElement.innerHTML = '<div style="text-align: center; color: #dc3545; padding: 20px;">Error loading order details: ' + (data.error || 'Unknown error') + '</div>';
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        orderDetailsElement.innerHTML = '<div style="text-align: center; color: #dc3545; padding: 20px;">Error loading order details: ' + error.message + '</div>';
    });
}

document.addEventListener('DOMContentLoaded', function() {
    // Check if we should show delivery view
    const urlParams = new URLSearchParams(window.location.search);
    const view = urlParams.get('view');
    
    if (view === 'delivery') {
        showDeliveryView();
    } else {
        showMainView();
    }
    
    // Add event listeners for view toggle
    document.getElementById('mainViewBtn').addEventListener('click', showMainView);
    document.getElementById('deliveryViewBtn').addEventListener('click', showDeliveryView);

    const photoUploadBtn = document.getElementById('modal-photo-upload-btn');
    if (photoUploadBtn) {
        photoUploadBtn.addEventListener('click', uploadModalPhoto);
    }
    
    // Update form button state
    function updateForm() {
        const driverSelect = document.querySelector('select[name="driver_id"]');
        const goButton = document.querySelector('.go-button');
        
        if (driverSelect.value) {
            goButton.disabled = false;
            goButton.style.opacity = '1';
        } else {
            goButton.disabled = true;
            goButton.style.opacity = '0.6';
        }
    }
    
    // Make functions available globally
    window.updateForm = updateForm;
    window.updateDeliveryForm = updateDeliveryForm;
    window.toggleOrderDetails = toggleOrderDetails;
    window.loadOrderDetails = loadOrderDetails;
    window.markAsDelivered = markAsDelivered;
    window.undoLastDelivery = undoLastDelivery;
    window.resetAllDeliveries = resetAllDeliveries;
    window.showDeliveryHistory = showDeliveryHistory;
    window.closeDeliveryHistory = closeDeliveryHistory;
    window.undoSpecificDelivery = undoSpecificDelivery;
    window.openCompleteDeliveryModal = openCompleteDeliveryModal;
    window.openPhotoUploadForStop = openPhotoUploadForStop;
    window.uploadModalPhoto = uploadModalPhoto;
    window.closeCompleteDeliveryModal = closeCompleteDeliveryModal;
    updateForm();
    updateDeliveryForm();
    
    // Mobile-friendly enhancements
    let lastTouchEnd = 0;
    document.addEventListener('touchend', function (event) {
        const now = (new Date()).getTime();
        if (now - lastTouchEnd <= 300) {
            event.preventDefault();
        }
        lastTouchEnd = now;
    }, false);

    // Add touch feedback for interactive elements
    const touchElements = document.querySelectorAll('.customer-stop, .zone-header, .go-button, .date-controls a, .date-controls button');
    touchElements.forEach(element => {
        element.addEventListener('touchstart', function() {
            this.style.transform = 'scale(0.98)';
        });
        
        element.addEventListener('touchend', function() {
            this.style.transform = 'scale(1)';
        });
    });

    // Improve form interactions on mobile
    const selectors = document.querySelectorAll('.selector-input');
    selectors.forEach(selector => {
        selector.addEventListener('focus', function() {
            // Scroll to element on focus for better mobile UX
            setTimeout(() => {
                this.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 300);
        });
    });
});

let currentDeliveryOrderId = null;
function closeCompleteDeliveryModal() {
    document.getElementById('complete-delivery-modal').style.display = 'none';
    resetPhotoUploadUi();
}
function showModifyOrderForm() {
    if (!currentDeliveryOrderId) return;
    
    document.getElementById('modal-step-initial').style.display = 'none';
    document.getElementById('modal-step-modify').style.display = 'none';
    document.getElementById('modal-step-loading').style.display = '';
    document.getElementById('modal-step-loading').innerHTML = '<div style="text-align: center; color: #6c757d;">Loading order details...</div>';
    
    // Make AJAX call to get order items
    fetch('complete_delivery.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_order_items&daily_order_id=' + currentDeliveryOrderId
    })
    .then(response => response.json())
    .then(data => {
        document.getElementById('modal-step-loading').style.display = 'none';
        if (data.success) {
            document.getElementById('modal-step-modify').innerHTML = data.html;
            document.getElementById('modal-step-modify').style.display = '';
        } else {
            document.getElementById('modal-step-modify').innerHTML = '<div style="text-align: center; color: #dc3545; padding: 20px;">❌ Error: ' + (data.error || 'Unknown error') + '</div>';
            document.getElementById('modal-step-modify').style.display = '';
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        document.getElementById('modal-step-loading').style.display = 'none';
        document.getElementById('modal-step-modify').innerHTML = '<div style="text-align: center; color: #dc3545; padding: 20px;">❌ Error: ' + error.message + '</div>';
        document.getElementById('modal-step-modify').style.display = '';
    });
}

// Undo last delivery
function undoLastDelivery() {
    if (deliveryHistory.length > 0) {
        const lastDelivery = deliveryHistory.shift();
        deliveredOrders = deliveredOrders.filter(id => id !== lastDelivery.daily_order_id);
        
        // Save to localStorage
        const driverId = <?php echo $selectedDriverId ?: 'null'; ?>;
        const date = '<?php echo $selectedDate; ?>';
        const storageKey = `delivered_orders_${driverId}_${date}`;
        localStorage.setItem(storageKey, JSON.stringify(deliveredOrders));
        localStorage.setItem(storageKey + '_history', JSON.stringify(deliveryHistory));
        
        // Update view
        updateDeliveryView();
    }
}

// Reset all deliveries
function resetAllDeliveries() {
    if (confirm('Are you sure you want to reset all deliveries? This will mark all orders as undelivered.')) {
        deliveredOrders = [];
        deliveryHistory = [];
        
        // Save to localStorage
        const driverId = <?php echo $selectedDriverId ?: 'null'; ?>;
        const date = '<?php echo $selectedDate; ?>';
        const storageKey = `delivered_orders_${driverId}_${date}`;
        localStorage.setItem(storageKey, JSON.stringify(deliveredOrders));
        localStorage.setItem(storageKey + '_history', JSON.stringify(deliveryHistory));
        
        // Update view
        updateDeliveryView();
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
