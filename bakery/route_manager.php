<?php
// Security check
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/google_maps_config.php';

// Handle AJAX request to get driver tracking data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_tracking_data') {
    header('Content-Type: application/json');
    
    $date = $_POST['date'] ?? date('Y-m-d');
    $driver_ids = isset($_POST['driver_ids']) ? json_decode($_POST['driver_ids'], true) : [];
    
    try {
        // Base query to get driver tracking data
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
        
        // Filter by specific drivers if provided
        if (!empty($driver_ids)) {
            $placeholders = str_repeat('?,', count($driver_ids) - 1) . '?';
            $sql .= " AND dh.driver_id IN ($placeholders)";
            $params = array_merge($params, $driver_ids);
        }
        
        $sql .= " ORDER BY dh.driver_id, dh.timestamp";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $tracking_data = $stmt->fetchAll();
        
        // Group data by driver
        $drivers_data = [];
        foreach ($tracking_data as $point) {
            $driver_id = $point['driver_id'];
            if (!isset($drivers_data[$driver_id])) {
                $drivers_data[$driver_id] = [
                    'name' => $point['driver_name'],
                    'points' => []
                ];
            }
            $drivers_data[$driver_id]['points'][] = [
                'lat' => (float)$point['latitude'],
                'lng' => (float)$point['longitude'],
                'timestamp' => $point['timestamp'],
                'time' => $point['time_formatted']
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

// Fetch all drivers
$drivers = $db->query("SELECT id, name FROM drivers ORDER BY name")->fetchAll();

// Get today's date for default
$today = date('Y-m-d');
?>

<div class="container">
    <h1>🗺️ Route Manager</h1>
    <p class="subtitle">Real-time driver tracking and route monitoring</p>
    
    <!-- Controls Panel -->
    <div class="controls-panel">
        <div class="control-group">
            <label for="tracking-date">📅 Date:</label>
            <input type="date" id="tracking-date" value="<?php echo $today; ?>" max="<?php echo $today; ?>">
        </div>
        
        <div class="control-group">
            <label>👥 Drivers:</label>
            <div class="driver-checkboxes">
                <label class="driver-checkbox">
                    <input type="checkbox" id="select-all-drivers" checked>
                    <span class="checkbox-label">All Drivers</span>
                </label>
                <?php foreach ($drivers as $index => $driver): ?>
                    <label class="driver-checkbox">
                        <input type="checkbox" class="driver-select" data-driver-id="<?php echo $driver['id']; ?>" checked>
                        <span class="checkbox-label" data-color="<?php echo $index; ?>"><?php echo htmlspecialchars($driver['name']); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="control-group">
            <button id="refresh-data" class="btn btn-primary">🔄 Refresh Data</button>
            <button id="auto-refresh-toggle" class="btn btn-secondary">🔄 Auto Refresh: OFF</button>
        </div>
    </div>
    
    <!-- Status Panel -->
    <div class="status-panel">
        <div class="status-item">
            <span class="status-label">📍 Active Drivers:</span>
            <span id="active-drivers-count" class="status-value">0</span>
        </div>
        <div class="status-item">
            <span class="status-label">📊 Total Points:</span>
            <span id="total-points-count" class="status-value">0</span>
        </div>
        <div class="status-item">
            <span class="status-label">🕐 Last Update:</span>
            <span id="last-update-time" class="status-value">Never</span>
        </div>
    </div>
    
    <!-- Map Container -->
    <div id="route-map" class="map-container"></div>
    
    <!-- Driver Legend -->
    <div class="driver-legend">
        <h3>🎨 Driver Legend</h3>
        <div id="legend-content" class="legend-content">
            <p class="text-muted">Select drivers to see legend</p>
        </div>
    </div>
    
    <!-- Driver Details Panel -->
    <div class="driver-details">
        <h3>📊 Driver Statistics</h3>
        <div id="driver-stats" class="stats-content">
            <p class="text-muted">Click on a driver path to see details</p>
        </div>
    </div>
</div>

<script>
// Configuration
const apiKey = '<?php echo GOOGLE_MAPS_API_KEY; ?>';
const drivers = <?php echo json_encode($drivers); ?>;

// Driver colors (rotating through nice distinct colors)
const driverColors = [
    '#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4', '#FFEAA7',
    '#DDA0DD', '#FF8A65', '#81C784', '#64B5F6', '#FFB74D',
    '#F06292', '#AED581', '#90CAF9', '#FFCC02', '#FF7043'
];

// Global variables
let map;
let driversData = {};
let driverPaths = {};
let driverMarkers = {};
let autoRefreshInterval = null;
let isAutoRefresh = false;

// Initialize Google Map
function initMap() {
    map = new google.maps.Map(document.getElementById('route-map'), {
        zoom: 11,
        center: { lat: 37.7749, lng: -122.4194 }, // San Francisco
        mapTypeId: 'roadmap',
        styles: [
            {
                featureType: 'poi',
                elementType: 'labels',
                stylers: [{ visibility: 'off' }]
            }
        ]
    });
    
    // Load initial data
    loadTrackingData();
}

// Load tracking data from server
function loadTrackingData() {
    const selectedDate = document.getElementById('tracking-date').value;
    const selectedDrivers = getSelectedDrivers();
    
    const formData = new FormData();
    formData.append('action', 'get_tracking_data');
    formData.append('date', selectedDate);
    formData.append('driver_ids', JSON.stringify(selectedDrivers));
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            driversData = data.data;
            updateMap();
            updateStatistics();
            updateLegend();
            updateLastRefreshTime();
        } else {
            console.error('Failed to load tracking data:', data.error);
            showError('Failed to load tracking data: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error loading tracking data:', error);
        showError('Network error loading tracking data');
    });
}

// Get selected driver IDs
function getSelectedDrivers() {
    const checkboxes = document.querySelectorAll('.driver-select:checked');
    return Array.from(checkboxes).map(cb => parseInt(cb.getAttribute('data-driver-id')));
}

// Update map with driver paths
function updateMap() {
    // Clear existing paths and markers
    clearMapElements();
    
    let bounds = new google.maps.LatLngBounds();
    let hasPoints = false;
    
    // Create paths for each driver
    Object.keys(driversData).forEach((driverId, index) => {
        const driverData = driversData[driverId];
        const driverIndex = drivers.findIndex(d => d.id == driverId);
        const color = driverColors[driverIndex % driverColors.length];
        
        if (driverData.points.length > 0) {
            hasPoints = true;
            
            // Create path coordinates
            const pathCoordinates = driverData.points.map(point => ({
                lat: point.lat,
                lng: point.lng
            }));
            
            // Create polyline for driver path
            const driverPath = new google.maps.Polyline({
                path: pathCoordinates,
                geodesic: true,
                strokeColor: color,
                strokeOpacity: 0.8,
                strokeWeight: 3,
                map: map
            });
            
            // Add click listener for driver details
            driverPath.addListener('click', () => {
                showDriverDetails(driverId, driverData);
            });
            
            driverPaths[driverId] = driverPath;
            
            // Add start marker (green)
            const startPoint = driverData.points[0];
            const startMarker = new google.maps.Marker({
                position: { lat: startPoint.lat, lng: startPoint.lng },
                map: map,
                title: `${driverData.name} - Start (${startPoint.time})`,
                icon: {
                    path: google.maps.SymbolPath.CIRCLE,
                    fillColor: '#4CAF50',
                    fillOpacity: 1,
                    strokeColor: '#2E7D32',
                    strokeWeight: 2,
                    scale: 8
                }
            });
            
            // Add end marker (red) if different from start
            const endPoint = driverData.points[driverData.points.length - 1];
            if (driverData.points.length > 1) {
                const endMarker = new google.maps.Marker({
                    position: { lat: endPoint.lat, lng: endPoint.lng },
                    map: map,
                    title: `${driverData.name} - Current (${endPoint.time})`,
                    icon: {
                        path: google.maps.SymbolPath.CIRCLE,
                        fillColor: color,
                        fillOpacity: 1,
                        strokeColor: '#FFFFFF',
                        strokeWeight: 2,
                        scale: 10
                    }
                });
                
                if (!driverMarkers[driverId]) driverMarkers[driverId] = [];
                driverMarkers[driverId].push(endMarker);
            }
            
            if (!driverMarkers[driverId]) driverMarkers[driverId] = [];
            driverMarkers[driverId].push(startMarker);
            
            // Extend bounds
            pathCoordinates.forEach(coord => bounds.extend(coord));
        }
    });
    
    // Fit map to show all paths
    if (hasPoints) {
        map.fitBounds(bounds);
        // Don't zoom too close if only one point
        google.maps.event.addListenerOnce(map, 'bounds_changed', function() {
            if (map.getZoom() > 15) {
                map.setZoom(15);
            }
        });
    }
}

// Clear all map elements
function clearMapElements() {
    // Clear paths
    Object.values(driverPaths).forEach(path => path.setMap(null));
    driverPaths = {};
    
    // Clear markers
    Object.values(driverMarkers).forEach(markerArray => {
        markerArray.forEach(marker => marker.setMap(null));
    });
    driverMarkers = {};
}

// Update statistics
function updateStatistics() {
    const activeDrivers = Object.keys(driversData).length;
    const totalPoints = Object.values(driversData).reduce((sum, driver) => sum + driver.points.length, 0);
    
    document.getElementById('active-drivers-count').textContent = activeDrivers;
    document.getElementById('total-points-count').textContent = totalPoints;
}

// Update legend
function updateLegend() {
    const legendContent = document.getElementById('legend-content');
    
    if (Object.keys(driversData).length === 0) {
        legendContent.innerHTML = '<p class="text-muted">No active drivers for selected date/filters</p>';
        return;
    }
    
    let legendHTML = '';
    Object.keys(driversData).forEach((driverId) => {
        const driverData = driversData[driverId];
        const driverIndex = drivers.findIndex(d => d.id == driverId);
        const color = driverColors[driverIndex % driverColors.length];
        const pointCount = driverData.points.length;
        
        let timeRange = 'No data';
        if (pointCount > 0) {
            const startTime = driverData.points[0].time;
            const endTime = driverData.points[pointCount - 1].time;
            timeRange = pointCount === 1 ? startTime : `${startTime} - ${endTime}`;
        }
        
        legendHTML += `
            <div class="legend-item">
                <div class="legend-color" style="background-color: ${color};"></div>
                <div class="legend-info">
                    <strong>${driverData.name}</strong>
                    <div class="legend-details">
                        📍 ${pointCount} points | 🕐 ${timeRange}
                    </div>
                </div>
            </div>
        `;
    });
    
    legendContent.innerHTML = legendHTML;
}

// Show driver details
function showDriverDetails(driverId, driverData) {
    const driverStatsElement = document.getElementById('driver-stats');
    const pointCount = driverData.points.length;
    
    if (pointCount === 0) {
        driverStatsElement.innerHTML = '<p class="text-muted">No tracking data for this driver</p>';
        return;
    }
    
    const startTime = driverData.points[0].time;
    const endTime = driverData.points[pointCount - 1].time;
    
    // Calculate approximate distance (rough estimate)
    let totalDistance = 0;
    for (let i = 1; i < driverData.points.length; i++) {
        const prev = driverData.points[i - 1];
        const curr = driverData.points[i];
        totalDistance += calculateDistance(prev.lat, prev.lng, curr.lat, curr.lng);
    }
    
    const driverIndex = drivers.findIndex(d => d.id == driverId);
    const color = driverColors[driverIndex % driverColors.length];
    
    const detailsHTML = `
        <div class="driver-detail-card">
            <div class="driver-header">
                <div class="legend-color" style="background-color: ${color};"></div>
                <h4>${driverData.name}</h4>
            </div>
            <div class="detail-stats">
                <div class="stat-item">
                    <span class="stat-label">📍 GPS Points:</span>
                    <span class="stat-value">${pointCount}</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">🕐 Time Range:</span>
                    <span class="stat-value">${startTime} - ${endTime}</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">📏 Est. Distance:</span>
                    <span class="stat-value">${totalDistance.toFixed(1)} miles</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">📍 Last Position:</span>
                    <span class="stat-value">${driverData.points[pointCount - 1].lat.toFixed(6)}, ${driverData.points[pointCount - 1].lng.toFixed(6)}</span>
                </div>
            </div>
        </div>
    `;
    
    driverStatsElement.innerHTML = detailsHTML;
}

// Calculate distance between two points (rough estimate)
function calculateDistance(lat1, lng1, lat2, lng2) {
    const R = 3959; // Earth radius in miles
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLng = (lng2 - lng1) * Math.PI / 180;
    const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLng/2) * Math.sin(dLng/2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    return R * c;
}

// Update last refresh time
function updateLastRefreshTime() {
    document.getElementById('last-update-time').textContent = new Date().toLocaleTimeString();
}

// Show error message
function showError(message) {
    // You could implement a toast notification system here
    alert(message);
}

// Event Listeners
document.addEventListener('DOMContentLoaded', function() {
    // Date change
    document.getElementById('tracking-date').addEventListener('change', loadTrackingData);
    
    // Driver selection
    document.getElementById('select-all-drivers').addEventListener('change', function() {
        const isChecked = this.checked;
        document.querySelectorAll('.driver-select').forEach(checkbox => {
            checkbox.checked = isChecked;
        });
        loadTrackingData();
    });
    
    document.querySelectorAll('.driver-select').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            // Update "All Drivers" checkbox state
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
            
            loadTrackingData();
        });
    });
    
    // Refresh button
    document.getElementById('refresh-data').addEventListener('click', loadTrackingData);
    
    // Auto refresh toggle
    document.getElementById('auto-refresh-toggle').addEventListener('click', function() {
        isAutoRefresh = !isAutoRefresh;
        
        if (isAutoRefresh) {
            this.textContent = '🔄 Auto Refresh: ON';
            this.classList.remove('btn-secondary');
            this.classList.add('btn-success');
            autoRefreshInterval = setInterval(loadTrackingData, 30000); // Refresh every 30 seconds
        } else {
            this.textContent = '🔄 Auto Refresh: OFF';
            this.classList.remove('btn-success');
            this.classList.add('btn-secondary');
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
            }
        }
    });
});

// Initialize map when Google Maps API loads
window.initMap = initMap;
</script>

<script async defer 
    src="<?php echo GOOGLE_MAPS_JS_API_URL; ?>?key=<?php echo GOOGLE_MAPS_API_KEY; ?>&callback=initMap">
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

/* Controls Panel */
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
    background-color: var(--driver-color);
}

/* Generate CSS custom properties for driver colors */
<?php foreach ($drivers as $index => $driver): ?>
.checkbox-label[data-color="<?php echo $index; ?>"]:before {
    background-color: <?php echo ['#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4', '#FFEAA7', '#DDA0DD', '#FF8A65', '#81C784', '#64B5F6', '#FFB74D', '#F06292', '#AED581', '#90CAF9', '#FFCC02', '#FF7043'][$index % 15]; ?>;
}
<?php endforeach; ?>

/* Status Panel */
.status-panel {
    background: linear-gradient(135deg, #007bff, #0056b3);
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
    font-size: 14px;
    opacity: 0.9;
    margin-bottom: 5px;
}

.status-value {
    display: block;
    font-size: 24px;
    font-weight: bold;
}

/* Map Container */
.map-container {
    height: 600px;
    width: 100%;
    border: 2px solid #dee2e6;
    border-radius: 8px;
    margin-bottom: 20px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

/* Driver Legend */
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
    flex-direction: column;
    gap: 10px;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 6px;
    border: 1px solid #e9ecef;
}

.legend-color {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    flex-shrink: 0;
    border: 2px solid white;
    box-shadow: 0 1px 3px rgba(0,0,0,0.3);
}

.legend-info {
    flex: 1;
}

.legend-details {
    font-size: 12px;
    color: #6c757d;
    margin-top: 4px;
}

/* Driver Details */
.driver-details {
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.driver-details h3 {
    margin: 0 0 15px 0;
    color: #495057;
}

.driver-detail-card {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 6px;
    padding: 15px;
}

.driver-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 15px;
}

.driver-header h4 {
    margin: 0;
    color: #495057;
}

.detail-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 10px;
}

.stat-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #dee2e6;
}

.stat-item:last-child {
    border-bottom: none;
}

.stat-label {
    font-weight: 500;
    color: #6c757d;
}

.stat-value {
    font-weight: bold;
    color: #495057;
}

/* Buttons */
.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-weight: 500;
    text-decoration: none;
    display: inline-block;
    transition: all 0.3s ease;
    font-size: 14px;
}

.btn-primary {
    background-color: #007bff;
    color: white;
}

.btn-primary:hover {
    background-color: #0056b3;
}

.btn-secondary {
    background-color: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background-color: #5a6268;
}

.btn-success {
    background-color: #28a745;
    color: white;
}

.btn-success:hover {
    background-color: #218838;
}

.text-muted {
    color: #6c757d;
}

/* Responsive Design */
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
    
    .map-container {
        height: 400px;
    }
    
    .detail-stats {
        grid-template-columns: 1fr;
    }
    
    .legend-item {
        flex-direction: column;
        align-items: flex-start;
        text-align: left;
    }
}

@media (max-width: 480px) {
    .map-container {
        height: 300px;
    }
    
    .status-value {
        font-size: 20px;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?> 