<?php
// Security check
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/google_maps_config.php';
require_once 'includes/route_manager.php';
require_once 'includes/driver_assignments.php';

// Handle AJAX request for delivery photos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_delivery_photos') {
    header('Content-Type: application/json');

    $date = trim((string)($_POST['date'] ?? ''));
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $customerId = (int)($_POST['customer_id'] ?? 0);

    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        echo json_encode(['success' => false, 'error' => 'Invalid date format; use YYYY-MM-DD']);
        exit;
    }
    if ($driverId <= 0 || $customerId <= 0) {
        echo json_encode(['success' => false, 'error' => 'driver_id and customer_id are required']);
        exit;
    }

    try {
        $photos = route_manager_fetch_photos($db, $driverId, $customerId, $date);
        echo json_encode([
            'success' => true,
            'photos' => $photos,
            'count' => count($photos),
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle AJAX: persist drag-and-drop route order (updates route_order only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reorder_deliveries') {
    header('Content-Type: application/json');

    $date = trim((string)($_POST['date'] ?? ''));
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $orderIds = json_decode((string)($_POST['order_ids'] ?? '[]'), true);

    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        echo json_encode(['success' => false, 'error' => 'Invalid date format; use YYYY-MM-DD']);
        exit;
    }
    if ($driverId <= 0) {
        echo json_encode(['success' => false, 'error' => 'driver_id is required']);
        exit;
    }
    if (!is_array($orderIds) || count($orderIds) === 0) {
        echo json_encode(['success' => false, 'error' => 'order_ids must contain the remaining stops']);
        exit;
    }

    $orderIds = array_values(array_filter(array_map('intval', $orderIds), static function ($id) {
        return $id > 0;
    }));
    if (count($orderIds) === 0) {
        echo json_encode(['success' => false, 'error' => 'No valid order IDs provided']);
        exit;
    }

    try {
        $result = bakery_driver_reorder_remaining_stops($db, $driverId, $date, $orderIds);

        echo json_encode([
            'success' => true,
            'message' => 'Route order updated',
            'driver_id' => $driverId,
            'date' => $date,
            'order_ids' => array_column($result['stops'], 'daily_order_id'),
            'stops' => $result['stops'],
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle AJAX request for assigned deliveries
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_deliveries') {
    header('Content-Type: application/json');

    $date = trim((string)($_POST['date'] ?? date('Y-m-d')));
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        echo json_encode(['success' => false, 'error' => 'Invalid date format; use YYYY-MM-DD']);
        exit;
    }

    $driverIds = [];
    if (isset($_POST['driver_ids'])) {
        $decoded = json_decode((string)$_POST['driver_ids'], true);
        if (is_array($decoded)) {
            $driverIds = array_values(array_filter(array_map('intval', $decoded), static function ($id) {
                return $id > 0;
            }));
        }
    }

    try {
        $driversData = route_manager_fetch_deliveries($db, $date, $driverIds);
        $totalDeliveries = 0;
        foreach ($driversData as $driver) {
            $totalDeliveries += count($driver['deliveries']);
        }

        echo json_encode([
            'success' => true,
            'date' => $date,
            'total_deliveries' => $totalDeliveries,
            'data' => $driversData,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// GPS activity history and optional map trails for the selected workday.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_tracking_data') {
    header('Content-Type: application/json');

    $date = trim((string)($_POST['date'] ?? date('Y-m-d')));
    $parsedDate = DateTime::createFromFormat('Y-m-d', $date);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
        echo json_encode(['success' => false, 'error' => 'Invalid date format; use YYYY-MM-DD']);
        exit;
    }
    $driver_ids = isset($_POST['driver_ids']) ? json_decode($_POST['driver_ids'], true) : [];

    try {
        $sql = "
            SELECT
                dh.driver_id,
                d.name as driver_name,
                dh.timestamp,
                dh.latitude,
                dh.longitude,
                DATE_FORMAT(dh.timestamp, '%H:%i') as time_formatted
            FROM driver_history dh
            JOIN drivers d ON dh.driver_id = d.id
            WHERE DATE(dh.timestamp) = ?
        ";

        $params = [$date];

        if (!empty($driver_ids) && is_array($driver_ids)) {
            $placeholders = str_repeat('?,', count($driver_ids) - 1) . '?';
            $sql .= " AND dh.driver_id IN ($placeholders)";
            $params = array_merge($params, array_map('intval', $driver_ids));
        }

        $sql .= " ORDER BY dh.driver_id, dh.timestamp";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $tracking_data = $stmt->fetchAll();

        $drivers_data = [];
        foreach ($tracking_data as $point) {
            $driver_id = $point['driver_id'];
            if (!isset($drivers_data[$driver_id])) {
                $drivers_data[$driver_id] = [
                    'name' => $point['driver_name'],
                    'points' => [],
                ];
            }
            $drivers_data[$driver_id]['points'][] = [
                'lat' => (float)$point['latitude'],
                'lng' => (float)$point['longitude'],
                'timestamp' => $point['timestamp'],
                'time' => $point['time_formatted'],
            ];
        }

        echo json_encode(['success' => true, 'data' => $drivers_data]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$page_title = bakery_t('page.route_manager');
require_once 'includes/header.php';
require_once 'includes/nav.php';
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('assets/photo_styles.css'); ?>">
<?php

// Fetch all drivers
$drivers = bakery_get_drivers($db);

// Start on the active delivery day; future routes remain available via the date picker.
$defaultDate = date('Y-m-d');
$selectedDate = $_GET['date'] ?? $defaultDate;
$parsedSelected = DateTime::createFromFormat('Y-m-d', $selectedDate);
if (!$parsedSelected || $parsedSelected->format('Y-m-d') !== $selectedDate) {
    $selectedDate = $defaultDate;
}
?>

<div class="container">
    <div class="route-manager-header">
        <div>
            <h1>Route Manager</h1>
            <p class="subtitle">Assigned deliveries for the selected day — drag stops to reorder each driver’s route. <strong>Each driver header shows the pickup manifest from Driver Pickup Loads, plus COD cash totals.</strong></p>
        </div>
        <div class="route-manager-actions">
            <a class="btn btn-secondary" href="driver_load.php?date=<?php echo htmlspecialchars($selectedDate); ?>"><?php echo htmlspecialchars(bakery_t('page.driver_load')); ?></a>
            <a class="btn btn-secondary" href="route_summary.php?date=<?php echo htmlspecialchars($selectedDate); ?>"><?php echo htmlspecialchars(function_exists('bakery_t') ? bakery_t('page.route_summary') : 'Route Summary'); ?></a>
            <a class="btn btn-secondary" href="billing_center.php?panel=invoices&amp;range=custom&amp;start_date=<?php echo htmlspecialchars($selectedDate); ?>&amp;end_date=<?php echo htmlspecialchars($selectedDate); ?>">Invoice reconciliation</a>
        </div>
    </div>

    <!-- Controls Panel -->
    <div class="controls-panel">
        <div class="control-group">
            <label for="tracking-date">Date:</label>
            <input type="date" id="tracking-date" value="<?php echo htmlspecialchars($selectedDate); ?>">
        </div>

        <div class="control-group">
            <label>Drivers:</label>
            <div class="driver-checkboxes">
                <label class="driver-checkbox">
                    <input type="checkbox" id="select-all-drivers" checked>
                    <span class="checkbox-label">All Drivers</span>
                </label>
                <?php foreach ($drivers as $index => $driver): ?>
                    <label class="driver-checkbox">
                        <input type="checkbox" class="driver-select" data-driver-id="<?php echo (int)$driver['id']; ?>" checked>
                        <span class="checkbox-label" data-color="<?php echo (int)$index; ?>"><?php echo htmlspecialchars($driver['name']); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="control-group">
            <button type="button" id="refresh-data" class="btn btn-primary">Refresh</button>
            <label class="driver-checkbox" title="Show GPS breadcrumb trails on the map">
                <input type="checkbox" id="show-tracking">
                <span class="checkbox-label">Show GPS trail on map</span>
            </label>
        </div>
    </div>

    <!-- Status Panel -->
    <div class="status-panel">
        <div class="status-item">
            <span class="status-label">Drivers with stops</span>
            <span id="active-drivers-count" class="status-value">0</span>
        </div>
        <div class="status-item">
            <span class="status-label">Total deliveries</span>
            <span id="total-deliveries-count" class="status-value">0</span>
        </div>
        <div class="status-item">
            <span class="status-label">Pending</span>
            <span id="pending-count" class="status-value">0</span>
        </div>
        <div class="status-item">
            <span class="status-label">Delivered</span>
            <span id="delivered-count" class="status-value">0</span>
        </div>
        <div class="status-item status-item--cash" title="Cash from delivered COD and Pan Dulce stops">
            <span class="status-label">Cash on hand</span>
            <span id="cod-cash-on-hand" class="status-value">$0.00</span>
        </div>
        <div class="status-item status-item--cash" title="Cash on hand plus estimated amounts from remaining COD and Pan Dulce stops">
            <span class="status-label">Cash turn-in total</span>
            <span id="cod-turn-in-total" class="status-value">$0.00</span>
        </div>
        <div class="status-item status-item--cash" title="Sum of order amounts for all active stops on the selected routes (COD and signature)">
            <span class="status-label">Total sold</span>
            <span id="route-total-sold" class="status-value">$0.00</span>
        </div>
        <div class="status-item">
            <span class="status-label">Last update</span>
            <span id="last-update-time" class="status-value">Never</span>
        </div>
    </div>

    <p class="cash-help-banner" role="note">
        <strong>Driver cash totals live here.</strong>
        Per-driver amounts also appear above each route in the delivery list and in the driver legend.
        <em>Cash on hand</em> = cash from delivered COD and Pan Dulce stops (using the delivery total when an older stop has no recorded cash amount).
        <em>Turn-in total</em> = on hand + estimated from undelivered COD and Pan Dulce stops.
        <em>Total sold</em> = order amounts for all active stops on the selected routes (COD and signature).
        For billable invoice amounts, use <a href="billing_center.php?panel=invoices&amp;range=custom&amp;start_date=<?php echo htmlspecialchars($selectedDate); ?>&amp;end_date=<?php echo htmlspecialchars($selectedDate); ?>">Billing Center</a>.
    </p>

    <div class="route-layout">
        <!-- Map Container -->
        <div id="route-map" class="map-container"></div>

        <!-- Delivery List -->
        <div class="delivery-list-panel">
            <div class="delivery-list-header">
                <h3>Delivery list</h3>
                <span id="reorder-status" class="reorder-status" aria-live="polite"></span>
            </div>
            <p class="delivery-list-hint">
                <span class="hint-desktop">Drag the ⋮⋮ handle, or use ↑ ↓, to change stop order.</span>
                <span class="hint-mobile">Tap ↑ or ↓ to move a stop. Changes save automatically.</span>
            </p>
            <div id="delivery-list" class="delivery-list"
                 data-pickup-title="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_manifest'), ENT_QUOTES, 'UTF-8'); ?>"
                 data-pickup-empty="<?php echo htmlspecialchars(bakery_t('route_manager.no_pickup'), ENT_QUOTES, 'UTF-8'); ?>"
                 data-pickup-edit="<?php echo htmlspecialchars(bakery_t('route_manager.edit_pickup_loads'), ENT_QUOTES, 'UTF-8'); ?>"
                 data-pickup-summary="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_summary'), ENT_QUOTES, 'UTF-8'); ?>">
                <p class="text-muted">Select a date and drivers to load deliveries.</p>
            </div>
        </div>
    </div>

    <section class="gps-activity-panel" aria-labelledby="gpsActivityTitle">
        <div class="gps-activity-heading">
            <div>
                <p class="gps-activity-kicker">Driver activity</p>
                <h3 id="gpsActivityTitle">GPS history</h3>
            </div>
            <span id="gps-activity-status" class="gps-activity-status" aria-live="polite">Loading activity…</span>
        </div>
        <p class="gps-activity-help">Location pings recorded while drivers use My Route. Select an update to view it on the map.</p>
        <div id="gps-activity-list" class="gps-activity-list" aria-live="polite"></div>
    </section>

    <!-- Driver Legend -->
    <div class="driver-legend">
        <h3>Driver legend</h3>
        <div id="legend-content" class="legend-content">
            <p class="text-muted">Select drivers to see legend</p>
        </div>
    </div>
</div>

<!-- Delivery photos modal -->
<div id="deliveryPhotosModal" class="photo-modal" style="display:none;" aria-hidden="true" role="dialog" aria-labelledby="deliveryPhotosModalTitle">
    <div class="photo-modal-content">
        <div class="photo-modal-header">
            <h3 id="deliveryPhotosModalTitle">Delivery photos</h3>
            <span class="photo-modal-close" id="deliveryPhotosModalClose" role="button" tabindex="0" aria-label="Close">&times;</span>
        </div>
        <div class="photo-modal-body">
            <div id="deliveryPhotosMeta" class="photo-assignment-confirm"></div>
            <div id="deliveryPhotosStatus" class="text-muted">Loading photos…</div>
            <div id="deliveryPhotosGrid" class="photo-grid"></div>
        </div>
    </div>
</div>

<!-- Full-size photo lightbox -->
<div id="photoLightbox" class="photo-lightbox" style="display:none;" aria-hidden="true" role="dialog">
    <button type="button" class="photo-lightbox-close" id="photoLightboxClose" aria-label="Close">&times;</button>
    <img id="photoLightboxImage" alt="Delivery photo">
</div>

<!-- Stop detail sheet -->
<div id="stopDetailModal" class="stop-detail-modal" style="display:none;" aria-hidden="true" role="dialog" aria-labelledby="stopDetailModalTitle">
    <div class="stop-detail-backdrop" id="stopDetailBackdrop"></div>
    <div class="stop-detail-sheet">
        <div class="stop-detail-header">
            <div class="stop-detail-header-text">
                <p class="stop-detail-kicker" id="stopDetailKicker">Stop details</p>
                <h3 id="stopDetailModalTitle">Stop</h3>
            </div>
            <button type="button" class="stop-detail-close" id="stopDetailModalClose" aria-label="Close">&times;</button>
        </div>
        <div class="stop-detail-actions" id="stopDetailActions"></div>
        <div class="stop-detail-body" id="stopDetailBody">
            <div class="stop-detail-section">
                <h4>Timing</h4>
                <dl class="stop-detail-grid" id="stopDetailTiming"></dl>
            </div>
            <div class="stop-detail-section">
                <h4>Status &amp; payment</h4>
                <dl class="stop-detail-grid" id="stopDetailStatus"></dl>
            </div>
            <div class="stop-detail-section">
                <h4>Order &amp; invoice</h4>
                <div id="stopDetailInvoiceStatus" class="text-muted">Loading order details…</div>
                <div id="stopDetailInvoice"></div>
            </div>
            <div class="stop-detail-section">
                <h4>Photos</h4>
                <div id="stopDetailPhotosStatus" class="text-muted">Loading photos…</div>
                <div id="stopDetailPhotos" class="photo-grid"></div>
            </div>
        </div>
    </div>
</div>

<script>
const apiKey = <?php echo bakery_json_for_html(GOOGLE_MAPS_API_KEY, '""'); ?>;
const drivers = <?php echo bakery_json_for_html($drivers, '[]'); ?>;

const driverColors = [
    '#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4', '#FFEAA7',
    '#DDA0DD', '#FF8A65', '#81C784', '#64B5F6', '#FFB74D',
    '#F06292', '#AED581', '#90CAF9', '#FFCC02', '#FF7043'
];

const statusLabels = {
    pending: 'Pending',
    in_transit: 'In transit',
    delivered: 'Delivered',
    failed: 'Failed',
    cancelled: 'Cancelled'
};

const paymentLabels = {
    cod: 'COD',
    signature: 'Signature'
};

function formatMoney(amount) {
    return '$' + Number(amount || 0).toFixed(2);
}

function stopPaymentAmount(delivery) {
    if ((delivery.payment_collection || 'cod') !== 'cod') {
        return null;
    }
    if (delivery.delivery_status === 'delivered') {
        if (delivery.amount_collected != null) {
            return delivery.amount_collected;
        }
        if (delivery.delivery_order_total > 0) {
            return delivery.delivery_order_total;
        }
        return delivery.total_amount > 0 ? delivery.total_amount : delivery.order_total_estimate;
    }
    if (delivery.delivery_order_total > 0) {
        return delivery.delivery_order_total;
    }
    if (delivery.total_amount > 0) {
        return delivery.total_amount;
    }
    return delivery.order_total_estimate || 0;
}

let map;
let geocoder;
let driversData = {};
let deliveryMarkers = [];
let markersByOrderId = {};
let driverPaths = {};
let infoWindow;
let pendingGeocode = 0;
let reorderSaveTimer = null;
let didDragStop = false;
let deliveriesRequestSeq = 0;
let trackingRequestSeq = 0;
let deliveriesAbortController = null;
let trackingAbortController = null;

function initMap() {
    const mapEl = document.getElementById('route-map');
    if (!mapEl || typeof google === 'undefined' || !google.maps) {
        return;
    }
    map = new google.maps.Map(mapEl, {
        zoom: 11,
        center: { lat: 37.7749, lng: -122.4194 },
        mapTypeId: 'roadmap',
        streetViewControl: false,
        mapTypeControl: false,
        styles: [
            {
                featureType: 'poi',
                elementType: 'labels',
                stylers: [{ visibility: 'off' }]
            }
        ]
    });
    geocoder = new google.maps.Geocoder();
    infoWindow = new google.maps.InfoWindow();
    // Deliveries load on DOMContentLoaded; refresh map markers if data already arrived.
    if (Object.keys(driversData).length) {
        updateMap();
    }
}

function getSelectedDrivers() {
    const checkboxes = document.querySelectorAll('.driver-select:checked');
    return Array.from(checkboxes).map(cb => parseInt(cb.getAttribute('data-driver-id'), 10));
}

function driverColor(driverId) {
    const driverIndex = drivers.findIndex(d => String(d.id) === String(driverId));
    const index = driverIndex >= 0 ? driverIndex : 0;
    return driverColors[index % driverColors.length];
}

function formatTime(value) {
    if (!value) return '—';
    const parts = String(value).split(':');
    if (parts.length < 2) return value;
    let hours = parseInt(parts[0], 10);
    const minutes = parts[1];
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12 || 12;
    return hours + ':' + minutes + ' ' + ampm;
}

function loadDeliveries(options) {
    const background = options && options.background === true;
    const selectedDate = document.getElementById('tracking-date').value;
    const selectedDrivers = getSelectedDrivers();
    const selectedDriversKey = selectedDrivers.slice().sort((a, b) => a - b).join(',');
    const requestSeq = ++deliveriesRequestSeq;

    if (deliveriesAbortController) deliveriesAbortController.abort();
    deliveriesAbortController = typeof AbortController === 'function' ? new AbortController() : null;

    if (selectedDrivers.length === 0) {
        driversData = {};
        clearMapElements();
        renderGpsActivity({});
        updateStatistics();
        updateLegend();
        updateDeliveryList();
        updateLastRefreshTime();
        return;
    }

    const formData = new FormData();
    formData.append('action', 'get_deliveries');
    formData.append('date', selectedDate);
    formData.append('driver_ids', JSON.stringify(selectedDrivers));

    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        body: formData,
        signal: deliveriesAbortController ? deliveriesAbortController.signal : undefined,
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(async response => {
        const data = await response.json().catch(() => null);
        if (!response.ok || !data) {
            throw new Error((data && data.error) || ('HTTP ' + response.status));
        }
        return data;
    })
    .then(data => {
        const currentDriversKey = getSelectedDrivers().slice().sort((a, b) => a - b).join(',');
        if (requestSeq !== deliveriesRequestSeq
            || document.getElementById('tracking-date').value !== selectedDate
            || currentDriversKey !== selectedDriversKey) {
            return;
        }
        if (data.success) {
            driversData = data.data || {};
            updateMap();
            updateStatistics();
            updateLegend();
            updateDeliveryList();
            updateLastRefreshTime();
            // GPS history is always useful context; map trails remain an opt-in overlay.
            loadTrackingOverlay({ background: background });
        } else {
            console.error('Failed to load deliveries:', data.error);
            if (!background) showError('Failed to load deliveries: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        if ((error && error.name === 'AbortError') || requestSeq !== deliveriesRequestSeq) return;
        console.error('Error loading deliveries:', error);
        if (!background) {
            showError('Network error loading deliveries' + (error && error.message ? ': ' + error.message : ''));
        }
    });
}

function loadTrackingOverlay(options) {
    const background = options && options.background === true;
    const selectedDate = document.getElementById('tracking-date').value;
    const selectedDrivers = getSelectedDrivers();
    const selectedDriversKey = selectedDrivers.slice().sort((a, b) => a - b).join(',');
    const requestSeq = ++trackingRequestSeq;

    if (trackingAbortController) trackingAbortController.abort();
    trackingAbortController = typeof AbortController === 'function' ? new AbortController() : null;

    const formData = new FormData();
    formData.append('action', 'get_tracking_data');
    formData.append('date', selectedDate);
    formData.append('driver_ids', JSON.stringify(selectedDrivers));

    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        body: formData,
        signal: trackingAbortController ? trackingAbortController.signal : undefined,
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        const currentDriversKey = getSelectedDrivers().slice().sort((a, b) => a - b).join(',');
        if (requestSeq !== trackingRequestSeq
            || document.getElementById('tracking-date').value !== selectedDate
            || currentDriversKey !== selectedDriversKey) {
            return;
        }
        clearTrackingPaths();
        renderGpsActivity(data && data.success ? data.data : {});
        if (!map || !data.success || !data.data || !document.getElementById('show-tracking').checked) return;

        Object.keys(data.data).forEach(driverId => {
            const points = data.data[driverId].points || [];
            if (points.length < 2) return;
            const path = new google.maps.Polyline({
                path: points.map(p => ({ lat: p.lat, lng: p.lng })),
                geodesic: true,
                strokeColor: driverColor(driverId),
                strokeOpacity: 0.45,
                strokeWeight: 3,
                map: map
            });
            driverPaths[driverId] = path;
        });
    })
    .catch(err => {
        if ((err && err.name === 'AbortError') || requestSeq !== trackingRequestSeq) return;
        console.warn('Tracking overlay failed:', err);
        if (!background) renderGpsActivity({});
    });
}

function formatGpsActivityTime(value) {
    if (!value) return 'Time unavailable';
    const parsed = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(parsed.getTime())) return String(value);
    return parsed.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

function renderGpsActivity(data) {
    const list = document.getElementById('gps-activity-list');
    const status = document.getElementById('gps-activity-status');
    if (!list || !status) return;

    const events = [];
    Object.keys(data || {}).forEach(driverId => {
        const driver = data[driverId] || {};
        (driver.points || []).forEach(point => {
            events.push({
                driverId: String(driverId),
                driverName: driver.name || 'Driver',
                lat: Number(point.lat),
                lng: Number(point.lng),
                timestamp: point.timestamp || ''
            });
        });
    });

    events.sort((a, b) => String(b.timestamp).localeCompare(String(a.timestamp)));
    if (!events.length) {
        status.textContent = 'No GPS updates yet';
        list.innerHTML = '<p class="gps-activity-empty">No location updates have been recorded for the selected day.</p>';
        return;
    }

    const shown = events.slice(0, 60);
    status.textContent = events.length + (events.length === 1 ? ' update' : ' updates');
    list.innerHTML = shown.map(event =>
        '<button type="button" class="gps-activity-item" data-driver-id="' + escapeHtml(event.driverId) +
        '" data-lat="' + escapeHtml(String(event.lat)) + '" data-lng="' + escapeHtml(String(event.lng)) + '">' +
            '<span class="gps-activity-time">' + escapeHtml(formatGpsActivityTime(event.timestamp)) + '</span>' +
            '<span class="gps-activity-detail"><strong>' + escapeHtml(event.driverName) + '</strong><span>Location updated</span></span>' +
            '<span class="gps-activity-map">View map</span>' +
        '</button>'
    ).join('');

    list.querySelectorAll('.gps-activity-item').forEach(item => {
        item.addEventListener('click', function() {
            const lat = Number(this.dataset.lat);
            const lng = Number(this.dataset.lng);
            if (!map || !Number.isFinite(lat) || !Number.isFinite(lng)) return;
            map.panTo({ lat, lng });
            if (map.getZoom() < 14) map.setZoom(14);
        });
    });
}

function clearTrackingPaths() {
    Object.values(driverPaths).forEach(path => path.setMap(null));
    driverPaths = {};
}

function clearMapElements() {
    deliveryMarkers.forEach(marker => marker.setMap(null));
    deliveryMarkers = [];
    markersByOrderId = {};
    clearTrackingPaths();
    if (infoWindow) infoWindow.close();
}

function markerIcon(color, label) {
    return {
        path: google.maps.SymbolPath.CIRCLE,
        fillColor: color,
        fillOpacity: 1,
        strokeColor: '#ffffff',
        strokeWeight: 2,
        scale: 12,
        labelOrigin: new google.maps.Point(0, 0)
    };
}

function deliveryInfoHtml(driverName, delivery, driverId) {
    const status = statusLabels[delivery.delivery_status] || delivery.delivery_status;
    const detailsBtn = `<br><button type="button" class="btn btn-primary map-photos-btn"
                onclick="viewStopDetail(${parseInt(driverId, 10)}, ${parseInt(delivery.daily_order_id, 10)})">
                View stop details
           </button>`;
    return `
        <div class="map-info-window">
            <strong>#${delivery.route_order || '—'} ${escapeHtml(delivery.customer_name)}</strong><br>
            <span>${escapeHtml(driverName)}</span><br>
            <span>${escapeHtml(delivery.address || 'No address')}</span><br>
            <span>Zone: ${escapeHtml(delivery.zone)}</span><br>
            <span>Status: ${escapeHtml(status)}</span><br>
            <span>Payment: ${escapeHtml(paymentLabels[delivery.payment_collection] || delivery.payment_collection || 'Signature')}</span><br>
            ${stopPaymentAmount(delivery) != null ? '<span>Amount: ' + formatMoney(stopPaymentAmount(delivery)) + '</span><br>' : ''}
            <span>Scheduled: ${escapeHtml(formatTime(delivery.scheduled_delivery_time))}</span>
            ${delivery.item_count ? '<br><span>Items: ' + delivery.item_count + '</span>' : ''}
            ${detailsBtn}
        </div>
    `;
}

function placeMarker(position, driverId, driverName, delivery, bounds) {
    const color = driverColor(driverId);
    const labelText = delivery.route_order > 0 ? String(delivery.route_order) : '•';
    const marker = new google.maps.Marker({
        position: position,
        map: map,
        title: delivery.customer_name,
        label: {
            text: labelText,
            color: '#ffffff',
            fontSize: '11px',
            fontWeight: 'bold'
        },
        icon: markerIcon(color, labelText)
    });

    marker.addListener('click', () => {
        infoWindow.setContent(deliveryInfoHtml(driverName, delivery, driverId));
        infoWindow.open(map, marker);
        highlightListItem(driverId, delivery.daily_order_id);
    });

    deliveryMarkers.push(marker);
    markersByOrderId[String(delivery.daily_order_id)] = marker;
    bounds.extend(position);
}

function updateMap() {
    if (!map) return;
    clearMapElements();

    const bounds = new google.maps.LatLngBounds();
    let hasPoints = false;
    pendingGeocode = 0;

    const maybeFit = () => {
        if (pendingGeocode > 0) return;
        if (hasPoints) {
            map.fitBounds(bounds);
            google.maps.event.addListenerOnce(map, 'bounds_changed', function() {
                if (map.getZoom() > 15) map.setZoom(15);
            });
        }
    };

    Object.keys(driversData).forEach(driverId => {
        const driverData = driversData[driverId];
        (driverData.deliveries || []).forEach(delivery => {
            const hasCoords = delivery.latitude != null && delivery.longitude != null
                && !isNaN(delivery.latitude) && !isNaN(delivery.longitude)
                && !(delivery.latitude === 0 && delivery.longitude === 0);

            if (hasCoords) {
                hasPoints = true;
                placeMarker(
                    { lat: delivery.latitude, lng: delivery.longitude },
                    driverId,
                    driverData.name,
                    delivery,
                    bounds
                );
            } else if (delivery.address && geocoder) {
                pendingGeocode++;
                geocoder.geocode({ address: delivery.address }, (results, status) => {
                    pendingGeocode--;
                    if (status === 'OK' && results[0]) {
                        hasPoints = true;
                        placeMarker(
                            results[0].geometry.location,
                            driverId,
                            driverData.name,
                            delivery,
                            bounds
                        );
                    }
                    maybeFit();
                });
            }
        });
    });

    maybeFit();
}

function updateStatistics() {
    let total = 0;
    let pending = 0;
    let delivered = 0;
    let cashOnHand = 0;
    let turnInTotal = 0;
    let totalSold = 0;
    const activeDrivers = Object.keys(driversData).filter(id => (driversData[id].deliveries || []).length > 0).length;

    Object.values(driversData).forEach(driver => {
        const summary = driver.cash_summary || {};
        cashOnHand += Number(summary.cash_on_hand) || 0;
        turnInTotal += Number(summary.turn_in_total) || 0;
        totalSold += Number(summary.total_sold) || 0;
        (driver.deliveries || []).forEach(d => {
            total++;
            if (d.delivery_status === 'delivered') delivered++;
            else if (d.delivery_status === 'pending' || d.delivery_status === 'in_transit') pending++;
        });
    });

    document.getElementById('active-drivers-count').textContent = activeDrivers;
    document.getElementById('total-deliveries-count').textContent = total;
    document.getElementById('pending-count').textContent = pending;
    document.getElementById('delivered-count').textContent = delivered;
    document.getElementById('cod-cash-on-hand').textContent = formatMoney(cashOnHand);
    document.getElementById('cod-turn-in-total').textContent = formatMoney(turnInTotal);
    const soldEl = document.getElementById('route-total-sold');
    if (soldEl) soldEl.textContent = formatMoney(totalSold);
}

function updateLegend() {
    const legendContent = document.getElementById('legend-content');
    const entries = Object.keys(driversData).filter(id => (driversData[id].deliveries || []).length > 0);

    if (entries.length === 0) {
        legendContent.innerHTML = '<p class="text-muted">No assigned deliveries for selected date/drivers</p>';
        return;
    }

    legendContent.innerHTML = entries.map(driverId => {
        const driverData = driversData[driverId];
        const color = driverColor(driverId);
        const count = driverData.deliveries.length;
        const cash = driverData.cash_summary || {};
        const cashLine = (cash.cod_stop_count || 0) > 0
            ? `<div class="legend-details">Cash: ${formatMoney(cash.cash_on_hand)} on hand · ${formatMoney(cash.turn_in_total)} turn-in</div>`
            : '';
        const soldLine = Number(cash.total_sold) > 0
            ? `<div class="legend-details">Sold: ${formatMoney(cash.total_sold)}</div>`
            : '';
        const pickupLine = (driverData.pickup_sku_count || 0) > 0
            ? `<div class="legend-details">Pickup: ${driverData.pickup_sku_count} product${driverData.pickup_sku_count === 1 ? '' : 's'} · ${driverData.pickup_piece_count || 0} pcs</div>`
            : `<div class="legend-details">Pickup: not saved</div>`;
        return `
            <div class="legend-item">
                <div class="legend-color" style="background-color: ${color};"></div>
                <div class="legend-info">
                    <strong>${escapeHtml(driverData.name)}</strong>
                    <div class="legend-details">${count} stop${count === 1 ? '' : 's'}</div>
                    ${pickupLine}
                    ${cashLine}
                    ${soldLine}
                </div>
            </div>
        `;
    }).join('');
}

function pickupManifestHtml(listEl, driverData) {
    const title = listEl.dataset.pickupTitle || 'Pickup manifest';
    const empty = listEl.dataset.pickupEmpty || 'No pickup load saved yet.';
    const editLabel = listEl.dataset.pickupEdit || 'Edit pickup loads';
    const summaryTpl = listEl.dataset.pickupSummary || ':skus products · :pcs pieces';
    const items = Array.isArray(driverData.pickup_manifest) ? driverData.pickup_manifest : [];
    const skuCount = Number(driverData.pickup_sku_count) || items.length;
    const pieceCount = Number(driverData.pickup_piece_count) || items.reduce((n, item) => n + (Number(item.loaded_quantity) || 0), 0);
    const summary = summaryTpl.replace(':skus', String(skuCount)).replace(':pcs', String(pieceCount));
    const date = document.getElementById('tracking-date').value || '';
    const editHref = 'driver_load.php?date=' + encodeURIComponent(date);
    const body = items.length
        ? `<ul class="driver-pickup-list">${items.map(item =>
            `<li><strong>${escapeHtml(String(item.loaded_quantity))}</strong> ${escapeHtml(item.name || '')}</li>`
          ).join('')}</ul>`
        : `<p class="driver-pickup-empty">${escapeHtml(empty)}</p>`;
    return `
        <details class="driver-pickup-manifest"${items.length ? ' open' : ''}>
            <summary>
                <strong>${escapeHtml(title)}</strong>
                <span>${items.length ? escapeHtml(summary) : escapeHtml(empty)}</span>
            </summary>
            <div class="driver-pickup-body">
                ${body}
                <a class="driver-pickup-edit" href="${escapeHtml(editHref)}">${escapeHtml(editLabel)}</a>
            </div>
        </details>
    `;
}

function updateDeliveryList() {
    const listEl = document.getElementById('delivery-list');
    const entries = Object.keys(driversData).filter(id => (driversData[id].deliveries || []).length > 0);

    if (entries.length === 0) {
        listEl.innerHTML = '<p class="text-muted">No assigned deliveries for this date and driver selection.</p>';
        return;
    }

    // Sort drivers by name for stable list order
    entries.sort((a, b) => (driversData[a].name || '').localeCompare(driversData[b].name || ''));

    listEl.innerHTML = entries.map(driverId => {
        const driverData = driversData[driverId];
        const color = driverColor(driverId);
        const stops = (driverData.deliveries || []).slice().sort((a, b) => {
            if (a.route_order !== b.route_order) return a.route_order - b.route_order;
            return (a.customer_name || '').localeCompare(b.customer_name || '');
        });

        const stopRows = stops.map((d, stopIndex) => {
            const status = statusLabels[d.delivery_status] || d.delivery_status;
            const isDelivered = d.delivery_status === 'delivered';
            const isRouteLocked = ['delivered', 'cancelled', 'in_transit'].includes(d.delivery_status);
            const photoCount = d.photo_count || 0;
            const isFirst = stopIndex === 0;
            const isLast = stopIndex === stops.length - 1;
            const isCod = (d.payment_collection || 'cod') === 'cod';
            const cashAmount = stopPaymentAmount(d);
            const paymentBadge = isCod
                ? `<span class="payment-badge payment-badge--cod">${isDelivered ? 'COD cash ' + formatMoney(cashAmount) : 'COD expected ' + formatMoney(cashAmount)}</span>`
                : `<span class="payment-badge payment-badge--signature">Signature</span>`;
            const mapsLink = d.address
                ? `<a class="stop-external-link" href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(d.address)}" target="_blank" rel="noopener">Map</a>`
                : '';
            const photosHint = isDelivered
                ? (photoCount > 0
                    ? `<span class="photo-badge">${photoCount} photo${photoCount === 1 ? '' : 's'}</span>`
                    : `<span class="photo-badge photo-badge-empty">No photos</span>`)
                : (photoCount > 0
                    ? `<span class="photo-badge">${photoCount} photo${photoCount === 1 ? '' : 's'}</span>`
                    : '');
            return `
                <li class="delivery-stop status-${escapeHtml(d.delivery_status)}${isDelivered ? ' has-photos-action' : ''}"
                    data-driver-id="${driverId}"
                    data-order-id="${d.daily_order_id}"
                    data-customer-id="${d.customer_id}"
                    data-status="${escapeHtml(d.delivery_status)}"
                    draggable="${isRouteLocked ? 'false' : 'true'}"
                    tabindex="0"
                    title="${isRouteLocked ? 'This stop is locked in route history' : 'Move with ↑ ↓ · Tap to view stop details'}">
                    ${isRouteLocked ? '' : '<span class="drag-handle" title="Drag to reorder" aria-hidden="true">⋮⋮</span>'}
                    <div class="stop-order">${d.route_order > 0 ? d.route_order : '—'}</div>
                    <div class="stop-body">
                        <div class="stop-name">
                            <button type="button" class="customer-hub-link stop-detail-trigger">${escapeHtml(d.customer_name)}</button>
                            <a class="stop-external-link customer-record-link" href="customer_record.php?customer_id=${encodeURIComponent(d.customer_id)}&amp;date=${encodeURIComponent(document.getElementById('tracking-date').value || '')}" title="Open Customer Record" aria-label="Open Customer Record">↗</a>
                            ${paymentBadge}
                            ${photosHint}
                        </div>
                        <div class="stop-meta">${escapeHtml(d.address || 'No address')}</div>
                        <div class="stop-meta">
                            ${escapeHtml(d.zone)}
                            · ${escapeHtml(status)}
                            · ${escapeHtml(formatTime(d.scheduled_delivery_time))}
                            ${d.item_count ? ' · ' + d.item_count + ' items' : ''}
                            ${mapsLink ? ' · ' + mapsLink : ''}
                            · <span class="photos-action-hint">View details</span>
                        </div>
                    </div>
                    <div class="stop-move-controls" role="group" aria-label="Reorder stop">
                        <button type="button" class="stop-move-btn" data-move="up"
                            aria-label="Move stop up"
                            title="Move up"
                            ${isRouteLocked || isFirst ? 'disabled' : ''}>↑</button>
                        <button type="button" class="stop-move-btn" data-move="down"
                            aria-label="Move stop down"
                            title="Move down"
                            ${isRouteLocked || isLast ? 'disabled' : ''}>↓</button>
                    </div>
                </li>
            `;
        }).join('');

        const cash = driverData.cash_summary || {};
        const cashBits = [];
        if ((cash.cod_stop_count || 0) > 0) {
            cashBits.push(`Cash on hand: <strong>${formatMoney(cash.cash_on_hand)}</strong>`);
            cashBits.push(`Turn-in: <strong>${formatMoney(cash.turn_in_total)}</strong>`);
        }
        if (Number(cash.total_sold) > 0 || (cash.cod_stop_count || 0) > 0) {
            cashBits.push(`Sold: <strong>${formatMoney(cash.total_sold)}</strong>`);
        }
        const cashHeader = cashBits.length
            ? `<span class="driver-cash-summary">
                    ${cashBits.join(' · ')}
                    ${(cash.cod_stop_count || 0) > 0
                        ? `<span class="driver-cash-meta">(${cash.cod_delivered_count || 0}/${cash.cod_stop_count || 0} COD/Pan Dulce stop${(cash.cod_stop_count || 0) === 1 ? '' : 's'} delivered)</span>`
                        : ''}
               </span>`
            : '';

        const pickupCopy = pickupManifestHtml(listEl, driverData);

        return `
            <section class="driver-delivery-group" data-driver-id="${driverId}">
                <header class="driver-delivery-header">
                    <span class="legend-color" style="background-color: ${color};"></span>
                    <div class="driver-delivery-title">
                        <strong>${escapeHtml(driverData.name)}</strong>
                        <span class="stop-count">${stops.length} stop${stops.length === 1 ? '' : 's'}</span>
                        ${cashHeader}
                    </div>
                </header>
                ${pickupCopy}
                <ol class="delivery-stops" data-driver-id="${driverId}">${stopRows}</ol>
            </section>
        `;
    }).join('');

    listEl.querySelectorAll('.stop-external-link').forEach(link => {
        link.addEventListener('click', (e) => e.stopPropagation());
    });

    listEl.querySelectorAll('.stop-detail-trigger').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const item = btn.closest('.delivery-stop');
            if (!item) return;
            viewStopDetail(item.dataset.driverId, item.dataset.orderId);
        });
    });

    listEl.querySelectorAll('.stop-move-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const item = btn.closest('.delivery-stop');
            const routeList = btn.closest('.delivery-stops');
            if (!item || !routeList || btn.disabled) return;
            moveStopInList(routeList, item, btn.dataset.move === 'up' ? -1 : 1);
        });
        // Prevent drag starting from the buttons on touch/desktop
        btn.addEventListener('mousedown', (e) => e.stopPropagation());
        btn.addEventListener('touchstart', (e) => e.stopPropagation(), { passive: true });
    });

    listEl.querySelectorAll('.delivery-stop').forEach(item => {
        const activate = () => {
            if (didDragStop) {
                didDragStop = false;
                return;
            }
            const driverId = item.dataset.driverId;
            const orderId = item.dataset.orderId;
            viewStopDetail(driverId, orderId);
        };
        item.addEventListener('click', activate);
        item.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                activate();
            }
        });
    });

    setupDeliveryListDragAndDrop();
}

function moveStopInList(routeList, item, delta) {
    const items = Array.from(routeList.querySelectorAll('.delivery-stop')).filter(isMovableRouteItem);
    const fromIndex = items.indexOf(item);
    if (fromIndex < 0) return;

    const toIndex = fromIndex + delta;
    if (toIndex < 0 || toIndex >= items.length) return;

    const target = items[toIndex];
    if (delta < 0) {
        routeList.insertBefore(item, target);
    } else {
        routeList.insertBefore(item, target.nextSibling);
    }

    item.classList.add('just-moved');
    setTimeout(() => item.classList.remove('just-moved'), 350);

    applyRouteOrderFromList(routeList);
}

function updateMoveButtons(routeList) {
    const allItems = Array.from(routeList.querySelectorAll('.delivery-stop'));
    const items = allItems.filter(isMovableRouteItem);
    allItems.filter(item => !isMovableRouteItem(item)).forEach(item => {
        item.querySelectorAll('.stop-move-btn').forEach(btn => { btn.disabled = true; });
    });
    items.forEach((item, index) => {
        const upBtn = item.querySelector('.stop-move-btn[data-move="up"]');
        const downBtn = item.querySelector('.stop-move-btn[data-move="down"]');
        if (upBtn) upBtn.disabled = index === 0;
        if (downBtn) downBtn.disabled = index === items.length - 1;
    });
}

function isMovableRouteItem(item) {
    return item && !['delivered', 'cancelled', 'in_transit'].includes(item.dataset.status || 'pending');
}

function setReorderStatus(message, tone) {
    const el = document.getElementById('reorder-status');
    if (!el) return;
    el.textContent = message || '';
    el.className = 'reorder-status' + (tone ? ' is-' + tone : '');
}

function prefersTouchReorder() {
    return window.matchMedia('(hover: none) and (pointer: coarse)').matches
        || window.matchMedia('(max-width: 980px)').matches;
}

function setupDeliveryListDragAndDrop() {
    const touchMode = prefersTouchReorder();

    document.querySelectorAll('.delivery-stops').forEach(routeList => {
        let draggedItem = null;
        updateMoveButtons(routeList);

        routeList.querySelectorAll('.delivery-stop').forEach(item => {
            if (!isMovableRouteItem(item)) {
                item.draggable = false;
                return;
            }
            // On phones/tablets, use ↑ ↓ buttons only — native drag fights with scrolling
            if (touchMode) {
                item.draggable = false;
                return;
            }

            item.addEventListener('dragstart', function(e) {
                draggedItem = this;
                didDragStop = true;
                this.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
                try {
                    e.dataTransfer.setData('text/plain', this.dataset.orderId || '');
                } catch (err) { /* IE/older Safari */ }
            });

            item.addEventListener('dragend', function() {
                this.classList.remove('dragging');
                routeList.querySelectorAll('.delivery-stop').forEach(el => el.classList.remove('drag-over'));
                draggedItem = null;
                // Keep didDragStop true briefly so the click after drag is ignored
                setTimeout(() => { didDragStop = false; }, 50);
            });

            item.addEventListener('dragover', function(e) {
                if (!isMovableRouteItem(this)) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
            });

            item.addEventListener('dragenter', function(e) {
                if (!isMovableRouteItem(this)) return;
                e.preventDefault();
                if (draggedItem && draggedItem !== this && draggedItem.parentNode === this.parentNode) {
                    this.classList.add('drag-over');
                }
            });

            item.addEventListener('dragleave', function() {
                this.classList.remove('drag-over');
            });

            item.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('drag-over');
                if (!draggedItem || draggedItem === this) return;
                if (draggedItem.parentNode !== routeList) return;

                const items = Array.from(routeList.querySelectorAll('.delivery-stop'));
                const fromIndex = items.indexOf(draggedItem);
                const toIndex = items.indexOf(this);
                if (fromIndex < 0 || toIndex < 0 || fromIndex === toIndex) return;

                if (fromIndex < toIndex) {
                    routeList.insertBefore(draggedItem, this.nextSibling);
                } else {
                    routeList.insertBefore(draggedItem, this);
                }

                applyRouteOrderFromList(routeList);
            });
        });
    });
}

function applyRouteOrderFromList(routeList) {
    const driverId = routeList.dataset.driverId;
    const items = Array.from(routeList.querySelectorAll('.delivery-stop'));
    const orderIds = items.filter(isMovableRouteItem).map(item => parseInt(item.dataset.orderId, 10));

    items.forEach((item, index) => {
        const orderEl = item.querySelector('.stop-order');
        if (orderEl) orderEl.textContent = String(index + 1);
    });
    updateMoveButtons(routeList);

    // Keep in-memory data + map marker labels in sync immediately
    if (driversData[driverId] && Array.isArray(driversData[driverId].deliveries)) {
        const byId = {};
        driversData[driverId].deliveries.forEach(d => {
            byId[String(d.daily_order_id)] = d;
        });
        const reordered = [];
        orderIds.forEach((id, index) => {
            const delivery = byId[String(id)];
            if (!delivery) return;
            delivery.route_order = index + 1;
            reordered.push(delivery);

            const marker = markersByOrderId[String(id)];
            if (marker && marker.getLabel) {
                const label = marker.getLabel() || {};
                marker.setLabel(Object.assign({}, label, { text: String(index + 1) }));
            }
        });
        if (reordered.length === orderIds.length) {
            driversData[driverId].deliveries = reordered;
        }
    }

    saveRouteOrder(driverId, orderIds);
}

function saveRouteOrder(driverId, orderIds) {
    const selectedDate = document.getElementById('tracking-date').value;
    setReorderStatus('Saving…', 'saving');

    if (reorderSaveTimer) {
        clearTimeout(reorderSaveTimer);
        reorderSaveTimer = null;
    }

    const formData = new FormData();
    formData.append('action', 'reorder_deliveries');
    formData.append('date', selectedDate);
    formData.append('driver_id', String(driverId));
    formData.append('order_ids', JSON.stringify(orderIds));

    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            setReorderStatus('Order saved', 'saved');
            updateLastRefreshTime();
            reorderSaveTimer = setTimeout(() => setReorderStatus(''), 2000);
        } else {
            setReorderStatus('Save failed', 'error');
            console.error('Failed to save route order:', data.error);
            showError('Failed to save route order: ' + (data.error || 'Unknown error'));
            // Reload to restore canonical order from the server
            loadDeliveries();
        }
    })
    .catch(error => {
        console.error('Error saving route order:', error);
        setReorderStatus('Save failed', 'error');
        showError('Network error saving route order');
        loadDeliveries();
    });
}

function highlightListItem(driverId, orderId) {
    document.querySelectorAll('.delivery-stop').forEach(el => el.classList.remove('is-active'));
    const el = document.querySelector(`.delivery-stop[data-driver-id="${driverId}"][data-order-id="${orderId}"]`);
    if (el) {
        el.classList.add('is-active');
        el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}

function focusDelivery(driverId, orderId) {
    highlightListItem(driverId, orderId);
    const driverData = driversData[driverId];
    if (!driverData) return;
    const delivery = (driverData.deliveries || []).find(d => String(d.daily_order_id) === String(orderId));
    if (!delivery) return;

    const marker = markersByOrderId[String(orderId)];
    if (marker) {
        map.panTo(marker.getPosition());
        if (map.getZoom() < 13) map.setZoom(14);
        infoWindow.setContent(deliveryInfoHtml(driverData.name, delivery, driverId));
        infoWindow.open(map, marker);
        return;
    }

    if (delivery.latitude != null && delivery.longitude != null) {
        const pos = { lat: delivery.latitude, lng: delivery.longitude };
        map.panTo(pos);
        if (map.getZoom() < 13) map.setZoom(14);
        infoWindow.setContent(deliveryInfoHtml(driverData.name, delivery, driverId));
        infoWindow.setPosition(pos);
        infoWindow.open(map);
    }
}

function findDelivery(driverId, orderId) {
    const driverData = driversData[driverId];
    if (!driverData) return null;
    const delivery = (driverData.deliveries || []).find(d => String(d.daily_order_id) === String(orderId));
    if (!delivery) return null;
    return { driverData, delivery };
}

function formatDateTime(value) {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit'
    });
}

function formatReceivingWindow(deliverAfter, deliverBy) {
    if (!deliverAfter && !deliverBy) return '—';
    if (deliverAfter && deliverBy) {
        return formatTime(deliverAfter) + ' – ' + formatTime(deliverBy);
    }
    if (deliverAfter) return 'After ' + formatTime(deliverAfter);
    return 'By ' + formatTime(deliverBy);
}

function renderDetailGrid(items) {
    return items.map(([label, value]) => `
        <div class="stop-detail-item">
            <dt>${escapeHtml(label)}</dt>
            <dd>${value}</dd>
        </div>
    `).join('');
}

function renderStopDetailInvoiceItems(items) {
    if (!items || items.length === 0) {
        return '<p class="text-muted stop-detail-empty">No priced items for this order.</p>';
    }
    return `
        <div class="stop-detail-invoice-list">
            <div class="stop-detail-invoice-heading">
                <span>Item</span>
                <span>Amount</span>
            </div>
            ${items.map(item => `
                <div class="stop-detail-invoice-row">
                    <span>
                        <strong>${escapeHtml(item.product_name || 'Product')}</strong>
                        <small>${escapeHtml(String(item.quantity || 0))} × ${formatMoney(item.unit_price || 0)}</small>
                    </span>
                    <strong>${formatMoney(item.line_total || 0)}</strong>
                </div>
            `).join('')}
        </div>
    `;
}

function renderStopDetailPhotos(photos, gridEl) {
    if (!photos || photos.length === 0) {
        return;
    }
    gridEl.innerHTML = photos.map(photo => `
        <div class="photo-thumb" tabindex="0" role="button"
             data-url="${escapeHtml(photo.url)}"
             data-fallback="${escapeHtml(photo.fallback_url || '')}"
             title="${escapeHtml(photo.photo_type || 'Photo')}">
            <img src="${escapeHtml(photo.url)}"
                 alt="${escapeHtml(photo.photo_type || 'Delivery photo')}"
                 loading="lazy"
                 onerror="if (this.dataset.fallbackTried) return; this.dataset.fallbackTried='1'; this.src=this.parentNode.dataset.fallback;">
            <div class="photo-info">
                <span class="photo-type">${escapeHtml(photo.photo_type || 'Photo')}</span>
                ${photo.created_at ? `<span class="customer-name">${escapeHtml(photo.created_at)}</span>` : ''}
            </div>
        </div>
    `).join('');

    gridEl.querySelectorAll('.photo-thumb').forEach(thumb => {
        const open = () => openPhotoLightbox(thumb.dataset.url, thumb.dataset.fallback);
        thumb.addEventListener('click', open);
        thumb.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                open();
            }
        });
    });
}

function closeStopDetailModal() {
    const modal = document.getElementById('stopDetailModal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
}

function viewStopDetail(driverId, orderId) {
    const found = findDelivery(driverId, orderId);
    if (!found) {
        showError('Delivery not found');
        return;
    }

    const { driverData, delivery } = found;
    const selectedDate = document.getElementById('tracking-date').value;
    const modal = document.getElementById('stopDetailModal');
    const title = document.getElementById('stopDetailModalTitle');
    const kicker = document.getElementById('stopDetailKicker');
    const actions = document.getElementById('stopDetailActions');
    const timing = document.getElementById('stopDetailTiming');
    const statusEl = document.getElementById('stopDetailStatus');
    const invoiceStatus = document.getElementById('stopDetailInvoiceStatus');
    const invoiceEl = document.getElementById('stopDetailInvoice');
    const photosStatus = document.getElementById('stopDetailPhotosStatus');
    const photosGrid = document.getElementById('stopDetailPhotos');

    const status = statusLabels[delivery.delivery_status] || delivery.delivery_status;
    const paymentType = paymentLabels[delivery.payment_collection] || delivery.payment_collection || 'Signature';
    const paymentAmount = stopPaymentAmount(delivery);
    const customerRecordUrl = `customer_record.php?customer_id=${encodeURIComponent(delivery.customer_id)}&date=${encodeURIComponent(selectedDate)}`;
    const mapsUrl = delivery.address
        ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(delivery.address)}`
        : '';

    kicker.textContent = `${driverData.name} · Stop #${delivery.route_order || '—'} · ${selectedDate}`;
    title.textContent = delivery.customer_name;

    actions.innerHTML = `
        <a class="btn btn-primary stop-detail-action" href="${escapeHtml(customerRecordUrl)}">Customer Record</a>
        ${mapsUrl ? `<a class="btn btn-secondary stop-detail-action" href="${escapeHtml(mapsUrl)}" target="_blank" rel="noopener">Open in Maps</a>` : ''}
        ${delivery.phone ? `<a class="btn btn-secondary stop-detail-action" href="tel:${escapeHtml(String(delivery.phone).replace(/[^\d+]/g, ''))}">${escapeHtml(delivery.phone)}</a>` : ''}
    `;

    timing.innerHTML = renderDetailGrid([
        ['Scheduled', escapeHtml(formatTime(delivery.scheduled_delivery_time))],
        ['Actual delivery', escapeHtml(formatDateTime(delivery.actual_delivery_time))],
        ['Receiving window', escapeHtml(formatReceivingWindow(delivery.deliver_after, delivery.deliver_by))],
        ['Confirmed at', escapeHtml(formatDateTime(delivery.delivery_confirmed_at))],
    ]);

    statusEl.innerHTML = renderDetailGrid([
        ['Status', `<span class="stop-detail-status status-${escapeHtml(delivery.delivery_status)}">${escapeHtml(status)}</span>`],
        ['Zone', escapeHtml(delivery.zone || '—')],
        ['Address', escapeHtml(delivery.address || 'No address')],
        ['Payment', escapeHtml(paymentType)],
        ['Amount', paymentAmount != null ? formatMoney(paymentAmount) : '—'],
        ['Collected', delivery.amount_collected != null ? formatMoney(delivery.amount_collected) : '—'],
        ['Items ordered', delivery.item_count ? String(delivery.item_count) : '—'],
    ]);

    invoiceStatus.textContent = 'Loading order details…';
    invoiceStatus.style.display = 'block';
    invoiceEl.innerHTML = '';
    photosStatus.textContent = 'Loading photos…';
    photosStatus.style.display = 'block';
    photosGrid.innerHTML = '';

    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    document.getElementById('stopDetailModalClose').focus();

    focusDelivery(driverId, orderId);

    const summaryBody = 'action=get_delivery_summary&daily_order_id=' + encodeURIComponent(String(orderId));
    fetch('complete_delivery.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: summaryBody
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            invoiceStatus.textContent = 'Could not load order details: ' + (data.error || 'Unknown error');
            return;
        }

        invoiceStatus.style.display = 'none';
        const billable = Math.max(0, Number(data.delivered_pieces || 0) - Number(data.credits_taken_back || 0));
        const summaryBits = [
            `<div class="stop-detail-invoice-summary">
                <span><strong>Ordered:</strong> ${Number(data.ordered_pieces || 0)} pcs</span>
                <span><strong>Delivered:</strong> ${Number(data.delivered_pieces || 0)} pcs</span>
                <span><strong>Credits:</strong> ${Number(data.credits_taken_back || 0)}</span>
                <span><strong>Billable:</strong> ${billable} pcs</span>
            </div>`,
            `<div class="stop-detail-invoice-totals">
                <span>Order total: <strong>${formatMoney(data.order_total || 0)}</strong></span>
                <span>Saved total: <strong>${formatMoney(data.saved_total || 0)}</strong></span>
                ${data.pricing_label ? `<span class="stop-detail-pricing-label">${escapeHtml(data.pricing_label)}</span>` : ''}
            </div>`,
            renderStopDetailInvoiceItems(data.items || [])
        ].join('');
        invoiceEl.innerHTML = summaryBits;
    })
    .catch(err => {
        console.error(err);
        invoiceStatus.textContent = 'Network error loading order details';
    });

    const formData = new FormData();
    formData.append('action', 'get_delivery_photos');
    formData.append('date', selectedDate);
    formData.append('driver_id', String(driverId));
    formData.append('customer_id', String(delivery.customer_id));

    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            photosStatus.textContent = 'Failed to load photos: ' + (data.error || 'Unknown error');
            return;
        }

        const photos = data.photos || [];
        if (photos.length === 0) {
            photosStatus.textContent = 'No photos uploaded for this stop yet.';
            return;
        }

        photosStatus.style.display = 'none';
        renderStopDetailPhotos(photos, photosGrid);
    })
    .catch(err => {
        console.error(err);
        photosStatus.textContent = 'Network error loading photos';
    });
}

function viewDeliveryPhotos(driverId, orderId) {
    viewStopDetail(driverId, orderId);
}

function closeDeliveryPhotosModal() {
    const modal = document.getElementById('deliveryPhotosModal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
}

function openPhotoLightbox(url, fallbackUrl) {
    const lightbox = document.getElementById('photoLightbox');
    const img = document.getElementById('photoLightboxImage');
    img.onerror = function() {
        if (fallbackUrl && img.src !== fallbackUrl) {
            img.src = fallbackUrl;
        }
    };
    img.src = url;
    lightbox.style.display = 'flex';
    lightbox.setAttribute('aria-hidden', 'false');
}

function closePhotoLightbox() {
    const lightbox = document.getElementById('photoLightbox');
    lightbox.style.display = 'none';
    lightbox.setAttribute('aria-hidden', 'true');
    document.getElementById('photoLightboxImage').src = '';
}

window.viewDeliveryPhotos = viewDeliveryPhotos;
window.viewStopDetail = viewStopDetail;

function updateLastRefreshTime() {
    document.getElementById('last-update-time').textContent = new Date().toLocaleTimeString();
}

function showError(message) {
    alert(message);
}

function escapeHtml(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('tracking-date').addEventListener('change', loadDeliveries);

    document.getElementById('select-all-drivers').addEventListener('change', function() {
        const isChecked = this.checked;
        document.querySelectorAll('.driver-select').forEach(checkbox => {
            checkbox.checked = isChecked;
        });
        loadDeliveries();
    });

    document.querySelectorAll('.driver-select').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const allCheckboxes = document.querySelectorAll('.driver-select');
            const checkedCheckboxes = document.querySelectorAll('.driver-select:checked');
            const selectAllCheckbox = document.getElementById('select-all-drivers');

            if (checkedCheckboxes.length === 0) {
                selectAllCheckbox.indeterminate = false;
                selectAllCheckbox.checked = false;
            } else if (checkedCheckboxes.length === allCheckboxes.length) {
                selectAllCheckbox.indeterminate = false;
                selectAllCheckbox.checked = true;
            } else {
                selectAllCheckbox.indeterminate = true;
            }

            loadDeliveries();
        });
    });

    document.getElementById('refresh-data').addEventListener('click', loadDeliveries);

    // Load route/cash data immediately — do not wait for Google Maps.
    // Previously totals stayed at $0.00 whenever Maps failed to call initMap.
    loadDeliveries();

    document.getElementById('show-tracking').addEventListener('change', function() {
        if (this.checked) {
            loadTrackingOverlay();
        } else {
            clearTrackingPaths();
        }
    });

    // Keep delivery, COD, photo, and GPS state current without interrupting an edit.
    window.setInterval(function() {
        if (document.hidden
            || document.querySelector('.delivery-stop.dragging')
            || document.querySelector('#reorder-status.is-saving')) return;
        loadDeliveries({ background: true });
    }, 60000);

    const photosClose = document.getElementById('deliveryPhotosModalClose');
    const photosModal = document.getElementById('deliveryPhotosModal');
    photosClose.addEventListener('click', closeDeliveryPhotosModal);
    photosClose.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            closeDeliveryPhotosModal();
        }
    });
    photosModal.addEventListener('click', (e) => {
        if (e.target === photosModal) closeDeliveryPhotosModal();
    });

    document.getElementById('photoLightboxClose').addEventListener('click', closePhotoLightbox);
    document.getElementById('photoLightbox').addEventListener('click', (e) => {
        if (e.target.id === 'photoLightbox' || e.target.id === 'photoLightboxClose') {
            closePhotoLightbox();
        }
    });

    const stopDetailClose = document.getElementById('stopDetailModalClose');
    const stopDetailBackdrop = document.getElementById('stopDetailBackdrop');
    const stopDetailModal = document.getElementById('stopDetailModal');
    stopDetailClose.addEventListener('click', closeStopDetailModal);
    stopDetailBackdrop.addEventListener('click', closeStopDetailModal);
    stopDetailModal.addEventListener('click', (e) => {
        if (e.target === stopDetailModal) closeStopDetailModal();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (document.getElementById('photoLightbox').style.display === 'flex') {
            closePhotoLightbox();
            return;
        }
        if (document.getElementById('stopDetailModal').style.display === 'flex') {
            closeStopDetailModal();
            return;
        }
        if (document.getElementById('deliveryPhotosModal').style.display === 'flex') {
            closeDeliveryPhotosModal();
        }
    });
});

window.initMap = initMap;
</script>

<?php
$mapsReady = defined('MAPS_ENABLED') && MAPS_ENABLED && defined('GOOGLE_MAPS_API_KEY') && GOOGLE_MAPS_API_KEY !== '';
if ($mapsReady):
?>
<script async defer
    src="<?php echo GOOGLE_MAPS_JS_API_URL; ?>?key=<?php echo htmlspecialchars(GOOGLE_MAPS_API_KEY); ?>&callback=initMap">
</script>
<?php else: ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const mapEl = document.getElementById('route-map');
    if (!mapEl) return;
    mapEl.innerHTML = '<div class="map-fallback">Map unavailable — cash and delivery totals still load from the selected routes. Enable maps (MAPS_ENABLED + API key) to show the map.</div>';
});
</script>
<?php endif; ?>

<style>
.container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.subtitle {
    color: #6c757d;
    margin-bottom: 20px;
    font-style: italic;
}

.controls-panel {
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    align-items: center;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.control-group {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.control-group label {
    font-weight: bold;
    color: #495057;
    white-space: nowrap;
}

#tracking-date {
    padding: 8px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 14px;
}

.driver-checkboxes {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
}

.driver-checkbox {
    display: flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    font-size: 14px;
    user-select: none;
    font-weight: normal;
}

.driver-checkbox input[type="checkbox"] {
    margin: 0;
}

.checkbox-label {
    font-weight: normal;
}

.checkbox-label[data-color] {
    position: relative;
    padding-left: 20px;
}

.checkbox-label[data-color]:before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 12px;
    height: 12px;
    border-radius: 50%;
}

<?php foreach ($drivers as $index => $driver): ?>
.checkbox-label[data-color="<?php echo (int)$index; ?>"]:before {
    background-color: <?php echo ['#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4', '#FFEAA7', '#DDA0DD', '#FF8A65', '#81C784', '#64B5F6', '#FFB74D', '#F06292', '#AED581', '#90CAF9', '#FFCC02', '#FF7043'][$index % 15]; ?>;
}
<?php endforeach; ?>

.status-panel {
    background: linear-gradient(135deg, #1f6f4a, #145c3a);
    color: white;
    border-radius: 8px;
    padding: 15px 20px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-around;
    flex-wrap: wrap;
    gap: 20px;
}

.status-item {
    text-align: center;
}

.status-label {
    display: block;
    font-size: 13px;
    opacity: 0.9;
    margin-bottom: 5px;
}

.status-value {
    display: block;
    font-size: 22px;
    font-weight: bold;
}

.route-layout {
    display: grid;
    grid-template-columns: 1.4fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

.gps-activity-panel {
    margin: 0 0 20px;
    padding: 18px;
    border: 1px solid #d9e7e2;
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 2px 4px rgba(0,0,0,0.06);
}

.gps-activity-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.gps-activity-kicker {
    margin: 0 0 3px;
    color: #39705a;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.gps-activity-heading h3 {
    margin: 0;
    color: #234638;
}

.gps-activity-status {
    padding: 5px 9px;
    border-radius: 999px;
    background: #eef8f2;
    color: #236143;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}

.gps-activity-help {
    margin: 8px 0 12px;
    color: #667085;
    font-size: 13px;
}

.gps-activity-list {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(205px, 1fr));
    gap: 8px;
    max-height: 310px;
    overflow: auto;
}

.gps-activity-item {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 10px;
    min-height: 58px;
    padding: 9px 10px;
    border: 1px solid #e2ebe7;
    border-radius: 7px;
    background: #fbfdfc;
    color: #243b31;
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.gps-activity-item:hover,
.gps-activity-item:focus-visible {
    border-color: #4d9871;
    background: #f1faf4;
    outline: none;
}

.gps-activity-time {
    color: #1f6f4a;
    font-size: 12px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}

.gps-activity-detail {
    display: grid;
    gap: 2px;
    min-width: 0;
    font-size: 12px;
}

.gps-activity-detail strong {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.gps-activity-detail span,
.gps-activity-map {
    color: #667085;
}

.gps-activity-map {
    font-size: 11px;
    font-weight: 700;
}

.gps-activity-empty {
    grid-column: 1 / -1;
    margin: 4px 0;
    color: #667085;
    font-size: 13px;
}

.map-container {
    height: 620px;
    width: 100%;
    border: 2px solid #dee2e6;
    border-radius: 8px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.map-fallback {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    padding: 24px;
    text-align: center;
    color: #5a6b63;
    background: linear-gradient(180deg, #f7faf8 0%, #eef3f0 100%);
    font-size: 14px;
    line-height: 1.45;
}

.delivery-list-panel {
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 16px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    max-height: 620px;
    overflow: auto;
}

.delivery-list-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 4px;
}

.delivery-list-panel h3 {
    margin: 0;
    color: #495057;
}

.delivery-list-hint {
    margin: 0 0 12px 0;
    font-size: 12px;
    color: #6c757d;
}

.hint-mobile {
    display: none;
}

.reorder-status {
    font-size: 12px;
    font-weight: 600;
    min-height: 1.2em;
    white-space: nowrap;
}

.reorder-status.is-saving {
    color: #856404;
}

.reorder-status.is-saved {
    color: #1f6f4a;
}

.reorder-status.is-error {
    color: #dc3545;
}

.driver-delivery-group {
    margin-bottom: 18px;
}

.driver-delivery-header {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 8px;
    padding-bottom: 6px;
    border-bottom: 1px solid #e9ecef;
}

.driver-delivery-title {
    flex: 1;
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 8px;
}

.driver-delivery-header .stop-count {
    color: #6c757d;
    font-size: 13px;
}

.driver-cash-summary {
    flex-basis: 100%;
    font-size: 13px;
    color: #1f6f4a;
    margin-top: 2px;
}

.driver-cash-summary strong {
    font-weight: 700;
}

.driver-cash-meta {
    color: #6c757d;
    font-size: 12px;
}

.driver-pickup-manifest {
    margin: 0 0 10px 0;
    padding: 0;
    border: 1px solid #b8d8c2;
    border-radius: 10px;
    background: #f2fbf4;
}

.driver-pickup-manifest summary {
    list-style: none;
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 6px 12px;
    padding: 8px 12px;
    cursor: pointer;
}

.driver-pickup-manifest summary::-webkit-details-marker {
    display: none;
}

.driver-pickup-manifest summary strong {
    color: #1f6637;
    font-size: 13px;
}

.driver-pickup-manifest summary span {
    color: #536258;
    font-size: 12px;
}

.driver-pickup-body {
    padding: 0 12px 10px;
}

.driver-pickup-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin: 0 0 8px;
    padding: 0;
    list-style: none;
}

.driver-pickup-list li {
    padding: 5px 8px;
    background: #fff;
    border-radius: 8px;
    color: #34483a;
    font-size: 13px;
}

.driver-pickup-list li strong {
    color: #1f6637;
}

.driver-pickup-empty {
    margin: 0 0 8px;
    color: #536258;
    font-size: 13px;
}

.driver-pickup-edit {
    font-size: 12px;
    font-weight: 600;
    color: #1f6f4a;
}

.status-item--cash .status-value {
    font-size: 20px;
}

.route-manager-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 8px;
}

.route-manager-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.cash-help-banner {
    margin: 0 0 20px;
    padding: 12px 14px;
    border: 1px solid #c3e6cb;
    border-radius: 8px;
    background: #f4fbf6;
    color: #1f4d33;
    font-size: 14px;
    line-height: 1.5;
}

.cash-help-banner a {
    color: #145c3a;
    font-weight: 600;
}

.payment-badge {
    display: inline-block;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 6px;
    border-radius: 4px;
    margin-left: 6px;
    vertical-align: middle;
}

.payment-badge--cod {
    background: #fff3cd;
    color: #856404;
}

.payment-badge--signature {
    background: #e9ecef;
    color: #495057;
}

.delivery-stops {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.delivery-stop {
    display: flex;
    gap: 10px;
    padding: 10px;
    border: 1px solid #e9ecef;
    border-radius: 6px;
    background: #f8f9fa;
    cursor: grab;
    transition: background 0.15s ease, border-color 0.15s ease, opacity 0.15s ease, box-shadow 0.15s ease;
    user-select: none;
}

.delivery-stop:hover,
.delivery-stop.is-active {
    border-color: #1f6f4a;
    background: #eef8f2;
}

.delivery-stop.dragging {
    opacity: 0.55;
    cursor: grabbing;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
}

.delivery-stop.drag-over {
    border-color: #1f6f4a;
    border-style: dashed;
    background: #e3f5eb;
}

.drag-handle {
    color: #adb5bd;
    font-size: 14px;
    line-height: 1;
    padding: 4px 2px;
    cursor: grab;
    flex-shrink: 0;
    align-self: center;
    letter-spacing: -2px;
}

.drag-handle:hover {
    color: #495057;
}

.delivery-stop.dragging .drag-handle {
    cursor: grabbing;
}

.stop-move-controls {
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex-shrink: 0;
    align-self: center;
}

.stop-move-btn {
    width: 36px;
    height: 32px;
    padding: 0;
    border: 1px solid #ced4da;
    border-radius: 6px;
    background: #fff;
    color: #343a40;
    font-size: 16px;
    font-weight: 700;
    line-height: 1;
    cursor: pointer;
    touch-action: manipulation;
    -webkit-tap-highlight-color: transparent;
}

.stop-move-btn:hover:not(:disabled) {
    background: #eef8f2;
    border-color: #1f6f4a;
    color: #1f6f4a;
}

.stop-move-btn:active:not(:disabled) {
    background: #d8f0e3;
    transform: scale(0.96);
}

.stop-move-btn:disabled {
    opacity: 0.35;
    cursor: not-allowed;
}

.delivery-stop.just-moved {
    border-color: #1f6f4a;
    background: #e3f5eb;
    box-shadow: 0 0 0 2px rgba(31, 111, 74, 0.15);
}

.delivery-stop.status-delivered {
    opacity: 0.95;
}

.delivery-stop.has-photos-action {
    border-left: 3px solid #1f6f4a;
}

.photo-badge {
    display: inline-block;
    background: #1f6f4a;
    color: #fff;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 999px;
}

.photo-badge-empty {
    background: #6c757d;
}

.photos-action-hint {
    color: #1f6f4a;
    font-weight: 600;
}

.map-photos-btn {
    margin-top: 8px;
    padding: 6px 10px;
    font-size: 12px;
}

.photo-lightbox {
    display: none;
    position: fixed;
    z-index: 2000;
    inset: 0;
    background: rgba(0, 0, 0, 0.9);
    align-items: center;
    justify-content: center;
    padding: 24px;
}

.photo-lightbox img {
    max-width: min(96vw, 1100px);
    max-height: 90vh;
    object-fit: contain;
    border-radius: 6px;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.5);
}

.photo-lightbox-close {
    position: absolute;
    top: 16px;
    right: 20px;
    background: transparent;
    border: none;
    color: #fff;
    font-size: 36px;
    line-height: 1;
    cursor: pointer;
}

.stop-order {
    min-width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #495057;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 12px;
    flex-shrink: 0;
}

.stop-name {
    font-weight: 600;
    color: #343a40;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}

.stop-name .customer-hub-link {
    color: inherit;
    text-decoration: none;
    background: none;
    border: none;
    padding: 0;
    font: inherit;
    font-weight: 600;
    cursor: pointer;
    text-align: left;
}

.stop-name .customer-hub-link:hover {
    color: #0d6efd;
    text-decoration: underline;
}

.customer-record-link {
    font-size: 13px;
    line-height: 1;
    padding: 2px 4px;
}

.stop-detail-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 1500;
    align-items: flex-end;
    justify-content: center;
}

.stop-detail-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
}

.stop-detail-sheet {
    position: relative;
    width: min(720px, 100%);
    max-height: min(88vh, 900px);
    background: #fff;
    border-radius: 16px 16px 0 0;
    box-shadow: 0 -8px 30px rgba(0, 0, 0, 0.18);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.stop-detail-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 18px 10px;
    border-bottom: 1px solid #e9ecef;
}

.stop-detail-kicker {
    margin: 0 0 4px;
    font-size: 12px;
    color: #6c757d;
}

.stop-detail-header h3 {
    margin: 0;
    font-size: 20px;
    line-height: 1.2;
}

.stop-detail-close {
    background: transparent;
    border: none;
    font-size: 28px;
    line-height: 1;
    color: #6c757d;
    cursor: pointer;
    padding: 0 4px;
}

.stop-detail-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding: 0 18px 12px;
    border-bottom: 1px solid #e9ecef;
}

.stop-detail-action {
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.btn-secondary {
    background-color: #f8f9fa;
    color: #343a40;
    border: 1px solid #ced4da;
}

.btn-secondary:hover {
    background-color: #e9ecef;
}

.stop-detail-body {
    overflow: auto;
    padding: 12px 18px 20px;
}

.stop-detail-section + .stop-detail-section {
    margin-top: 18px;
    padding-top: 16px;
    border-top: 1px solid #eef1f4;
}

.stop-detail-section h4 {
    margin: 0 0 10px;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #6c757d;
}

.stop-detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 10px 16px;
    margin: 0;
}

.stop-detail-item dt {
    margin: 0;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #868e96;
}

.stop-detail-item dd {
    margin: 2px 0 0;
    font-size: 14px;
    color: #212529;
}

.stop-detail-status.status-delivered {
    color: #1f6f4a;
    font-weight: 600;
}

.stop-detail-status.status-pending,
.stop-detail-status.status-in_transit {
    color: #0d6efd;
    font-weight: 600;
}

.stop-detail-status.status-failed,
.stop-detail-status.status-cancelled {
    color: #dc3545;
    font-weight: 600;
}

.stop-detail-invoice-summary,
.stop-detail-invoice-totals {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 16px;
    margin-bottom: 10px;
    font-size: 13px;
}

.stop-detail-pricing-label {
    color: #6c757d;
    font-style: italic;
}

.stop-detail-invoice-list {
    border: 1px solid #e9ecef;
    border-radius: 8px;
    overflow: hidden;
}

.stop-detail-invoice-heading,
.stop-detail-invoice-row {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 12px;
    align-items: center;
    padding: 10px 12px;
}

.stop-detail-invoice-heading {
    background: #f8f9fa;
    font-size: 12px;
    font-weight: 600;
    color: #6c757d;
    text-transform: uppercase;
}

.stop-detail-invoice-row + .stop-detail-invoice-row {
    border-top: 1px solid #eef1f4;
}

.stop-detail-invoice-row strong {
    font-size: 14px;
}

.stop-detail-invoice-row small {
    display: block;
    color: #6c757d;
    font-size: 12px;
    margin-top: 2px;
}

.stop-detail-empty {
    margin: 0;
}

#stopDetailPhotos.photo-grid {
    margin-top: 8px;
}

@media (min-width: 768px) {
    .stop-detail-modal {
        align-items: center;
        padding: 24px;
    }

    .stop-detail-sheet {
        border-radius: 16px;
        max-height: min(84vh, 900px);
    }
}

.stop-meta {
    font-size: 12px;
    color: #6c757d;
    margin-top: 2px;
}

.driver-legend {
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.driver-legend h3 {
    margin: 0 0 15px 0;
    color: #495057;
}

.legend-content {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    background: #f8f9fa;
    border-radius: 6px;
    border: 1px solid #e9ecef;
    min-width: 180px;
}

.legend-color {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    flex-shrink: 0;
    border: 2px solid white;
    box-shadow: 0 1px 3px rgba(0,0,0,0.3);
}

.legend-details {
    font-size: 12px;
    color: #6c757d;
    margin-top: 2px;
}

.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-weight: 500;
    font-size: 14px;
}

.btn-primary {
    background-color: #1f6f4a;
    color: white;
}

.btn-primary:hover {
    background-color: #145c3a;
}

.text-muted {
    color: #6c757d;
}

.map-info-window {
    max-width: 240px;
    line-height: 1.4;
}

@media (max-width: 980px) {
    .route-layout {
        grid-template-columns: 1fr;
    }

    .map-container,
    .delivery-list-panel {
        max-height: none;
        height: 420px;
    }

    .delivery-list-panel {
        height: auto;
        max-height: 480px;
    }

    .hint-desktop {
        display: none;
    }

    .hint-mobile {
        display: inline;
    }

    .drag-handle {
        display: none;
    }

    .delivery-stop {
        cursor: pointer;
        padding: 12px;
        gap: 8px;
        align-items: stretch;
    }

    .stop-move-controls {
        gap: 6px;
    }

    .stop-move-btn {
        width: 48px;
        height: 44px;
        font-size: 20px;
        border-radius: 8px;
        border-width: 1.5px;
        background: #f1f3f5;
    }
}

@media (max-width: 768px) {
    .container {
        padding: 10px;
    }

    .controls-panel {
        flex-direction: column;
        align-items: stretch;
    }

    .control-group {
        flex-direction: column;
        align-items: stretch;
    }

    .driver-checkboxes {
        flex-direction: column;
        gap: 10px;
    }

    .status-panel {
        flex-direction: column;
        text-align: center;
    }

    .delivery-list-panel {
        max-height: none;
    }

    .stop-body {
        min-width: 0;
        flex: 1;
    }

    .stop-name {
        font-size: 15px;
    }

    .stop-order {
        min-width: 32px;
        height: 32px;
        font-size: 13px;
        align-self: flex-start;
        margin-top: 2px;
    }
}

/* Prefer tap buttons over accidental drag on coarse pointers */
@media (hover: none) and (pointer: coarse) {
    .hint-desktop {
        display: none;
    }

    .hint-mobile {
        display: inline;
    }

    .drag-handle {
        display: none;
    }

    .delivery-stop {
        cursor: pointer;
    }

    .delivery-stop[draggable="true"] {
        -webkit-user-drag: none;
    }

    .stop-move-btn {
        width: 48px;
        height: 44px;
        font-size: 20px;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>
