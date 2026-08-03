<?php
// Security check
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/google_maps_config.php';
require_once 'includes/photo_handler.php';

/**
 * Fetch assigned deliveries for a date, optionally filtered by driver IDs.
 */
function route_manager_fetch_deliveries(PDO $db, string $date, array $driverIds = []): array
{
    $photosAvailable = table_exists($db, 'driver_photos');
    $photoCountSql = $photosAvailable
        ? "(
                SELECT COUNT(*)
                FROM driver_photos dp
                WHERE dp.driver_id = doa.driver_id
                  AND dp.customer_id = c.id
                  AND dp.delivery_date = doa.delivery_date
            )"
        : '0';

    $sql = "
        SELECT
            doa.driver_id,
            d.name AS driver_name,
            doa.route_order,
            doa.scheduled_delivery_time,
            doa.delivery_status,
            doa.actual_delivery_time,
            do.id AS daily_order_id,
            do.total_amount,
            c.id AS customer_id,
            c.name AS customer_name,
            c.address,
            c.zone,
            c.phone,
            c.latitude,
            c.longitude,
            c.deliver_by,
            c.deliver_after,
            (
                SELECT COALESCE(SUM(doi.quantity), 0)
                FROM daily_order_items doi
                WHERE doi.daily_order_id = do.id
            ) AS item_count,
            {$photoCountSql} AS photo_count
        FROM daily_order_assignments doa
        INNER JOIN drivers d ON doa.driver_id = d.id
        INNER JOIN daily_orders do
            ON do.id = doa.daily_order_id
            AND do.order_date = doa.delivery_date
        INNER JOIN customers c ON do.customer_id = c.id
        WHERE doa.delivery_date = ?
    ";

    $params = [$date];

    if (!empty($driverIds)) {
        $placeholders = implode(',', array_fill(0, count($driverIds), '?'));
        $sql .= " AND doa.driver_id IN ($placeholders)";
        foreach ($driverIds as $id) {
            $params[] = (int)$id;
        }
    }

    $sql .= " ORDER BY d.name, doa.route_order, c.name";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $driversData = [];
    foreach ($rows as $row) {
        $driverId = (int)$row['driver_id'];
        if (!isset($driversData[$driverId])) {
            $driversData[$driverId] = [
                'id' => $driverId,
                'name' => $row['driver_name'],
                'deliveries' => [],
            ];
        }

        $driversData[$driverId]['deliveries'][] = [
            'daily_order_id' => (int)$row['daily_order_id'],
            'customer_id' => (int)$row['customer_id'],
            'customer_name' => $row['customer_name'],
            'address' => $row['address'] ?? '',
            'zone' => $row['zone'] ?: 'No Zone',
            'phone' => $row['phone'] ?? '',
            'route_order' => (int)$row['route_order'],
            'scheduled_delivery_time' => $row['scheduled_delivery_time'],
            'actual_delivery_time' => $row['actual_delivery_time'] ?? null,
            'delivery_status' => $row['delivery_status'] ?: 'pending',
            'total_amount' => (float)$row['total_amount'],
            'item_count' => (int)$row['item_count'],
            'latitude' => $row['latitude'] !== null && $row['latitude'] !== '' ? (float)$row['latitude'] : null,
            'longitude' => $row['longitude'] !== null && $row['longitude'] !== '' ? (float)$row['longitude'] : null,
            'deliver_by' => $row['deliver_by'],
            'deliver_after' => $row['deliver_after'],
            'photo_count' => (int)$row['photo_count'],
        ];
    }

    return $driversData;
}

/**
 * Fetch delivery photos for a driver/customer/date with display URLs.
 */
function route_manager_fetch_photos(PDO $db, int $driverId, int $customerId, string $date): array
{
    if (!table_exists($db, 'driver_photos')) {
        return [];
    }

    $photoHandler = new PhotoHandler();
    $rows = $photoHandler->getPhotos($db, $driverId, $date, $customerId);
    $photos = [];

    foreach ($rows as $row) {
        $urls = $photoHandler->getPhotoUrlWithFallback($row['file_path']);
        $photos[] = [
            'id' => (int)$row['id'],
            'photo_type' => $row['photo_type'] ?? 'Photo',
            'notes' => $row['notes'] ?? '',
            'created_at' => $row['created_at'] ?? '',
            'url' => $urls['primary'],
            'fallback_url' => $urls['fallback'],
            'customer_name' => $row['customer_name'] ?? '',
            'customer_address' => $row['customer_address'] ?? '',
        ];
    }

    return $photos;
}

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
        echo json_encode(['success' => false, 'error' => 'order_ids must be a non-empty array']);
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
        // Require a complete ordered list of this driver's stops for the date
        $checkStmt = $db->prepare("
            SELECT daily_order_id
            FROM daily_order_assignments
            WHERE driver_id = ?
              AND delivery_date = ?
        ");
        $checkStmt->execute([$driverId, $date]);
        $assigned = array_map('intval', $checkStmt->fetchAll(PDO::FETCH_COLUMN));
        $assignedSorted = $assigned;
        $submittedSorted = $orderIds;
        sort($assignedSorted);
        sort($submittedSorted);
        if ($assignedSorted !== $submittedSorted) {
            echo json_encode([
                'success' => false,
                'error' => 'Route changed since last load — refresh and try again',
            ]);
            exit;
        }

        $db->beginTransaction();

        // Two-phase update avoids unique (driver, date, route_order) collisions if present
        $tempStmt = $db->prepare("
            UPDATE daily_order_assignments
            SET route_order = ?
            WHERE daily_order_id = ?
              AND driver_id = ?
              AND delivery_date = ?
        ");
        foreach ($orderIds as $index => $dailyOrderId) {
            $tempStmt->execute([1000 + $index, $dailyOrderId, $driverId, $date]);
        }

        $finalStmt = $db->prepare("
            UPDATE daily_order_assignments
            SET route_order = ?
            WHERE daily_order_id = ?
              AND driver_id = ?
              AND delivery_date = ?
        ");
        foreach ($orderIds as $index => $dailyOrderId) {
            $finalStmt->execute([$index + 1, $dailyOrderId, $driverId, $date]);
        }

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Route order updated',
            'driver_id' => $driverId,
            'date' => $date,
            'order_ids' => $orderIds,
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

// Optional: GPS tracking overlay (kept for same-day monitoring)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_tracking_data') {
    header('Content-Type: application/json');

    $date = $_POST['date'] ?? date('Y-m-d');
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

$page_title = 'Route Manager';
require_once 'includes/header.php';
require_once 'includes/nav.php';
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(BASE_URL); ?>assets/photo_styles.css">
<?php

// Fetch all drivers
$drivers = bakery_get_drivers($db);

// Default to tomorrow (planning day), allow override via ?date=
$defaultDate = date('Y-m-d', strtotime('+1 day'));
$selectedDate = $_GET['date'] ?? $defaultDate;
$parsedSelected = DateTime::createFromFormat('Y-m-d', $selectedDate);
if (!$parsedSelected || $parsedSelected->format('Y-m-d') !== $selectedDate) {
    $selectedDate = $defaultDate;
}
?>

<div class="container">
    <h1>Route Manager</h1>
    <p class="subtitle">Assigned deliveries for the selected day — drag stops to reorder each driver’s route</p>

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
            <label class="driver-checkbox" title="Show GPS breadcrumb trails when available">
                <input type="checkbox" id="show-tracking">
                <span class="checkbox-label">Show GPS trails</span>
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
        <div class="status-item">
            <span class="status-label">Last update</span>
            <span id="last-update-time" class="status-value">Never</span>
        </div>
    </div>

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
            <div id="delivery-list" class="delivery-list">
                <p class="text-muted">Select a date and drivers to load deliveries.</p>
            </div>
        </div>
    </div>

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

function initMap() {
    map = new google.maps.Map(document.getElementById('route-map'), {
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
    loadDeliveries();
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

function loadDeliveries() {
    const selectedDate = document.getElementById('tracking-date').value;
    const selectedDrivers = getSelectedDrivers();

    if (selectedDrivers.length === 0) {
        driversData = {};
        clearMapElements();
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
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            driversData = data.data || {};
            updateMap();
            updateStatistics();
            updateLegend();
            updateDeliveryList();
            updateLastRefreshTime();
            if (document.getElementById('show-tracking').checked) {
                loadTrackingOverlay();
            }
        } else {
            console.error('Failed to load deliveries:', data.error);
            showError('Failed to load deliveries: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error loading deliveries:', error);
        showError('Network error loading deliveries');
    });
}

function loadTrackingOverlay() {
    const selectedDate = document.getElementById('tracking-date').value;
    const selectedDrivers = getSelectedDrivers();

    const formData = new FormData();
    formData.append('action', 'get_tracking_data');
    formData.append('date', selectedDate);
    formData.append('driver_ids', JSON.stringify(selectedDrivers));

    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        clearTrackingPaths();
        if (!data.success || !data.data) return;

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
    .catch(err => console.warn('Tracking overlay failed:', err));
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
    const canViewPhotos = delivery.delivery_status === 'delivered' || (delivery.photo_count || 0) > 0;
    const photoLabel = (delivery.photo_count || 0) > 0
        ? `View photos (${delivery.photo_count})`
        : 'View photos';
    const photosBtn = canViewPhotos
        ? `<br><button type="button" class="btn btn-primary map-photos-btn"
                onclick="viewDeliveryPhotos(${parseInt(driverId, 10)}, ${parseInt(delivery.daily_order_id, 10)})">
                ${escapeHtml(photoLabel)}
           </button>`
        : '';
    return `
        <div class="map-info-window">
            <strong>#${delivery.route_order || '—'} ${escapeHtml(delivery.customer_name)}</strong><br>
            <span>${escapeHtml(driverName)}</span><br>
            <span>${escapeHtml(delivery.address || 'No address')}</span><br>
            <span>Zone: ${escapeHtml(delivery.zone)}</span><br>
            <span>Status: ${escapeHtml(status)}</span><br>
            <span>Scheduled: ${escapeHtml(formatTime(delivery.scheduled_delivery_time))}</span>
            ${delivery.item_count ? '<br><span>Items: ' + delivery.item_count + '</span>' : ''}
            ${photosBtn}
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
    const activeDrivers = Object.keys(driversData).filter(id => (driversData[id].deliveries || []).length > 0).length;

    Object.values(driversData).forEach(driver => {
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
        return `
            <div class="legend-item">
                <div class="legend-color" style="background-color: ${color};"></div>
                <div class="legend-info">
                    <strong>${escapeHtml(driverData.name)}</strong>
                    <div class="legend-details">${count} stop${count === 1 ? '' : 's'}</div>
                </div>
            </div>
        `;
    }).join('');
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
            const photoCount = d.photo_count || 0;
            const isFirst = stopIndex === 0;
            const isLast = stopIndex === stops.length - 1;
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
                    draggable="true"
                    tabindex="0"
                    title="${isDelivered ? 'Move with ↑ ↓ · Tap to view photos' : 'Move with ↑ ↓ · Tap to focus on map'}">
                    <span class="drag-handle" title="Drag to reorder" aria-hidden="true">⋮⋮</span>
                    <div class="stop-order">${d.route_order > 0 ? d.route_order : '—'}</div>
                    <div class="stop-body">
                        <div class="stop-name">
                            ${escapeHtml(d.customer_name)}
                            ${photosHint}
                        </div>
                        <div class="stop-meta">${escapeHtml(d.address || 'No address')}</div>
                        <div class="stop-meta">
                            ${escapeHtml(d.zone)}
                            · ${escapeHtml(status)}
                            · ${escapeHtml(formatTime(d.scheduled_delivery_time))}
                            ${d.item_count ? ' · ' + d.item_count + ' items' : ''}
                            ${mapsLink ? ' · ' + mapsLink : ''}
                            ${isDelivered ? ' · <span class="photos-action-hint">View photos</span>' : ''}
                        </div>
                    </div>
                    <div class="stop-move-controls" role="group" aria-label="Reorder stop">
                        <button type="button" class="stop-move-btn" data-move="up"
                            aria-label="Move stop up"
                            title="Move up"
                            ${isFirst ? 'disabled' : ''}>↑</button>
                        <button type="button" class="stop-move-btn" data-move="down"
                            aria-label="Move stop down"
                            title="Move down"
                            ${isLast ? 'disabled' : ''}>↓</button>
                    </div>
                </li>
            `;
        }).join('');

        return `
            <section class="driver-delivery-group" data-driver-id="${driverId}">
                <header class="driver-delivery-header">
                    <span class="legend-color" style="background-color: ${color};"></span>
                    <strong>${escapeHtml(driverData.name)}</strong>
                    <span class="stop-count">${stops.length} stop${stops.length === 1 ? '' : 's'}</span>
                </header>
                <ol class="delivery-stops" data-driver-id="${driverId}">${stopRows}</ol>
            </section>
        `;
    }).join('');

    listEl.querySelectorAll('.stop-external-link').forEach(link => {
        link.addEventListener('click', (e) => e.stopPropagation());
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
            focusDelivery(driverId, orderId);
            if (item.dataset.status === 'delivered') {
                viewDeliveryPhotos(driverId, orderId);
            }
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
    const items = Array.from(routeList.querySelectorAll('.delivery-stop'));
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
    const items = Array.from(routeList.querySelectorAll('.delivery-stop'));
    items.forEach((item, index) => {
        const upBtn = item.querySelector('.stop-move-btn[data-move="up"]');
        const downBtn = item.querySelector('.stop-move-btn[data-move="down"]');
        if (upBtn) upBtn.disabled = index === 0;
        if (downBtn) downBtn.disabled = index === items.length - 1;
    });
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

        routeList.querySelectorAll('.delivery-stop').forEach(item => {
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
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
            });

            item.addEventListener('dragenter', function(e) {
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
    const orderIds = items.map(item => parseInt(item.dataset.orderId, 10));

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

function viewDeliveryPhotos(driverId, orderId) {
    const found = findDelivery(driverId, orderId);
    if (!found) {
        showError('Delivery not found');
        return;
    }

    const { driverData, delivery } = found;
    const selectedDate = document.getElementById('tracking-date').value;
    const modal = document.getElementById('deliveryPhotosModal');
    const title = document.getElementById('deliveryPhotosModalTitle');
    const meta = document.getElementById('deliveryPhotosMeta');
    const status = document.getElementById('deliveryPhotosStatus');
    const grid = document.getElementById('deliveryPhotosGrid');

    title.textContent = 'Delivery photos';
    meta.innerHTML = `
        <strong>${escapeHtml(delivery.customer_name)}</strong>
        · ${escapeHtml(driverData.name)}
        · stop #${delivery.route_order || '—'}
        · ${escapeHtml(selectedDate)}
        <div class="stop-meta">${escapeHtml(delivery.address || '')}</div>
    `;
    status.textContent = 'Loading photos…';
    status.style.display = 'block';
    grid.innerHTML = '';
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');

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
            status.textContent = 'Failed to load photos: ' + (data.error || 'Unknown error');
            return;
        }

        const photos = data.photos || [];
        if (photos.length === 0) {
            status.textContent = 'No photos uploaded for this delivery.';
            return;
        }

        status.style.display = 'none';
        grid.innerHTML = photos.map(photo => `
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

        grid.querySelectorAll('.photo-thumb').forEach(thumb => {
            const open = () => openPhotoLightbox(thumb.dataset.url, thumb.dataset.fallback);
            thumb.addEventListener('click', open);
            thumb.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    open();
                }
            });
        });
    })
    .catch(err => {
        console.error(err);
        status.textContent = 'Network error loading photos';
    });
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

    document.getElementById('show-tracking').addEventListener('change', function() {
        if (this.checked) {
            loadTrackingOverlay();
        } else {
            clearTrackingPaths();
        }
    });

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

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (document.getElementById('photoLightbox').style.display === 'flex') {
            closePhotoLightbox();
            return;
        }
        if (document.getElementById('deliveryPhotosModal').style.display === 'flex') {
            closeDeliveryPhotosModal();
        }
    });
});

window.initMap = initMap;
</script>

<script async defer
    src="<?php echo GOOGLE_MAPS_JS_API_URL; ?>?key=<?php echo htmlspecialchars(GOOGLE_MAPS_API_KEY); ?>&callback=initMap">
</script>

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

.map-container {
    height: 620px;
    width: 100%;
    border: 2px solid #dee2e6;
    border-radius: 8px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
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
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
    padding-bottom: 6px;
    border-bottom: 1px solid #e9ecef;
}

.driver-delivery-header .stop-count {
    margin-left: auto;
    color: #6c757d;
    font-size: 13px;
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
