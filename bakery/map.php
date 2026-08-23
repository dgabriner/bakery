<?php
// Security check
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/google_maps_config.php';

// Zone colors are presentation; the zones table owns names (and colors).
$zoneColors = [
    'Centro' => '#007bff',
    'Mission' => '#dc3545',
    'Ruta Sour Flour' => '#28a745',
    'Daly City/San Mateo' => '#fd7e14',
    'North Bay' => '#6f42c1',
    'East Bay' => '#20c997',
    'No Zone' => '#6c757d'
];
$fallbackZones = ['Centro', 'Mission', 'Ruta Sour Flour', 'Daly City/San Mateo', 'North Bay', 'East Bay'];
$zones = [];
try {
    foreach ($db->query("SELECT name, color FROM zones ORDER BY name")->fetchAll() as $tableZone) {
        $zoneName = trim((string)$tableZone['name']);
        if ($zoneName === '' || in_array($zoneName, $zones, true)) {
            continue;
        }
        $zones[] = $zoneName;
        $tableColor = strtolower(trim((string)$tableZone['color']));
        if (preg_match('/^#[0-9a-f]{6}$/', $tableColor)) {
            $zoneColors[$zoneName] = $tableColor;
        } elseif (!isset($zoneColors[$zoneName])) {
            $zoneColors[$zoneName] = '#6c757d';
        }
    }
} catch (Exception $e) {
    // zones table not migrated yet — fall back to the hardcoded list
}
if (empty($zones)) {
    $zones = $fallbackZones;
}

// Handle AJAX zone updates BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_zone') {
    header('Content-Type: application/json');
    
    try {
        $customerId = (int)$_POST['customer_id'];
        $newZone = empty($_POST['zone']) ? null : $_POST['zone'];
        
        // Validate customer ID
        if ($customerId <= 0) {
            throw new Exception("Invalid customer ID");
        }
        
        // Validate zone if provided
        if ($newZone !== null && !in_array($newZone, $zones, true)) {
            throw new Exception("Invalid zone selected");
        }
        
        $stmt = $db->prepare("UPDATE customers SET zone = ? WHERE id = ?");
        $result = $stmt->execute([$newZone, $customerId]);
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Zone updated successfully']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update database']);
        }
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Handle AJAX geocoding updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_coordinates') {
    header('Content-Type: application/json');
    
    try {
        $customerId = (int)$_POST['customer_id'];
        $latitude = (float)$_POST['latitude'];
        $longitude = (float)$_POST['longitude'];
        
        // Validate inputs
        if ($customerId <= 0 || $latitude == 0 || $longitude == 0) {
            throw new Exception("Invalid coordinate data");
        }
        
        $stmt = $db->prepare("UPDATE customers SET latitude = ?, longitude = ? WHERE id = ?");
        $result = $stmt->execute([$latitude, $longitude, $customerId]);
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Coordinates updated successfully']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update coordinates']);
        }
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

$page_title = bakery_t('page.map');
require_once 'includes/header.php';
require_once 'includes/nav.php';

// Fetch customers with addresses - prioritize address over stored coordinates
$customers = $db->query("SELECT id, name, address, latitude, longitude, zone FROM customers WHERE address IS NOT NULL AND address != '' ORDER BY zone, name")->fetchAll();

// Count customers
$total_customers = $db->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$customers_with_addresses = count($customers);
$customers_without_addresses = $total_customers - $customers_with_addresses;

// Count customers by zone for the map
$zone_counts = [];
foreach ($customers as $customer) {
    $zone = $customer['zone'] ?: 'No Zone';
    $zone_counts[$zone] = ($zone_counts[$zone] ?? 0) + 1;
}
?>
<div class="container">
    <h1>Customer Map</h1>
    
    <!-- Map Statistics -->
    <div class="map-stats">
        <div class="stat-card">
            <span class="stat-number"><?php echo $customers_with_addresses; ?></span>
            <span class="stat-label">Customers with Addresses</span>
        </div>
        <div class="stat-card">
            <span class="stat-number"><?php echo $customers_without_addresses; ?></span>
            <span class="stat-label">Missing Addresses</span>
        </div>
        <div class="stat-card">
            <span class="stat-number"><?php echo $total_customers; ?></span>
            <span class="stat-label">Total Customers</span>
        </div>
    </div>
    
    <!-- Geocoding Status -->
    <div class="geocoding-status" id="geocodingStatus" style="display: none;">
        <div class="status-indicator">
            <span class="status-icon">🔄</span>
            <span class="status-text">Geocoding addresses...</span>
            <span class="status-progress">(<span id="geocodedCount">0</span>/<span id="totalCount">0</span>)</span>
        </div>
    </div>
    
    <!-- Zone Legend -->
    <div class="zone-legend">
        <h3>Delivery Zones</h3>
        <div class="legend-items">
            <?php foreach ($zoneColors as $zone => $color): ?>
                <?php if (isset($zone_counts[$zone])): ?>
                    <div class="legend-item">
                        <span class="legend-color" style="background-color: <?php echo $color; ?>"></span>
                        <span class="legend-text"><?php echo htmlspecialchars($zone); ?> (<?php echo $zone_counts[$zone]; ?>)</span>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div id="map" style="height: 600px; width: 100%; margin-bottom: 20px;"></div>
    
    <div class="map-controls">
        <button id="fit-bounds" class="btn btn-primary">Show All Customers</button>
        <button id="center-sf" class="btn btn-secondary">Center on San Francisco</button>
        <button id="toggle-clusters" class="btn btn-info">Toggle Clustering</button>
        <button id="refresh-addresses" class="btn btn-warning">Refresh from Addresses</button>
    </div>
    
    <div class="note">
        💡 <strong>Tip:</strong> Click on any zone badge in the customer popup to quickly reassign them to a different zone!<br>
        📍 <strong>Address Accuracy:</strong> Markers are positioned using Google Maps geocoding based on customer addresses for maximum accuracy.
        <?php if ($customers_without_addresses > 0): ?>
            <br><strong>Note:</strong> <?php echo $customers_without_addresses; ?> customer(s) are not shown because they don't have addresses.
        <?php else: ?>
            <br>All customers with addresses are displayed on the map!
        <?php endif; ?>
    </div>
</div>

<!-- Zone Assignment Modal -->
<div id="zoneModal" class="modal" style="display: none;">
    <div class="modal-content">
        <span class="close" onclick="hideZoneModal()">&times;</span>
        <h2>Reassign Customer Zone</h2>
        <div id="customerInfo">
            <p><strong>Customer:</strong> <span id="customerName"></span></p>
            <p><strong>Address:</strong> <span id="customerAddress"></span></p>
            <p><strong>Current Zone:</strong> <span id="currentZone"></span></p>
        </div>
        
        <div class="zone-selection">
            <h4>Select New Zone:</h4>
            <div class="zone-options">
                <div class="zone-option no-zone" onclick="selectZone('')" data-zone="">
                    <span class="zone-color" style="background-color: #6c757d;"></span>
                    <span class="zone-name">No Zone</span>
                </div>
                <?php foreach ($zones as $zone): ?>
                    <div class="zone-option" onclick="selectZone('<?php echo htmlspecialchars($zone); ?>')" data-zone="<?php echo htmlspecialchars($zone); ?>">
                        <span class="zone-color" style="background-color: <?php echo $zoneColors[$zone]; ?>;"></span>
                        <span class="zone-name"><?php echo htmlspecialchars($zone); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="modal-actions">
            <button type="button" class="btn-secondary" onclick="hideZoneModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- Success/Error Message -->
<div id="messageBar" class="message-bar" style="display: none;">
    <span id="messageText"></span>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<!-- Marker Cluster Plugin -->
<script src="https://unpkg.com/leaflet.markercluster@1.4.1/dist/leaflet.markercluster.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.Default.css" />
<!-- Google Maps API for Geocoding -->
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo GOOGLE_MAPS_API_KEY; ?>" async defer></script>
<script>
const customers = <?php echo json_encode($customers); ?>;
const zoneColors = <?php echo json_encode($zoneColors); ?>;
const availableZones = <?php echo json_encode($zones); ?>;
const map = L.map('map').setView([37.7749, -122.4194], 12); // Centered on SF

let currentEditingCustomer = null;
let customerMarkers = new Map(); // Store customer ID to marker mapping
let geocoder = null;
let geocodingQueue = [];
let geocodedCount = 0;
let isGeocoding = false;

// Add map tiles
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

// Create marker cluster group
const markerCluster = L.markerClusterGroup({
    chunkedLoading: true,
    spiderfyOnMaxZoom: true,
    showCoverageOnHover: false,
    zoomToBoundsOnClick: true
});

// Create marker group for non-clustered view
const markerGroup = L.layerGroup();

// Function to create custom colored marker
function createColoredMarker(color) {
    return L.divIcon({
        className: 'custom-marker',
        html: `<div style="
            background-color: ${color};
            width: 25px;
            height: 25px;
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 12px;
        ">📍</div>`,
        iconSize: [25, 25],
        iconAnchor: [12, 12],
        popupAnchor: [0, -12]
    });
}

// Function to safely escape strings for HTML
function escapeHtml(unsafe) {
    return unsafe
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}

// Function to create popup content
function createPopupContent(customer) {
    const zone = customer.zone || 'No Zone';
    const color = zoneColors[zone] || '#6c757d';
    const safeName = escapeHtml(customer.name);
    const safeAddress = escapeHtml(customer.address);
    
    return `
        <div class="customer-popup">
            <strong>${safeName}</strong><br>
            <span class="address">${safeAddress}</span><br>
            <span class="zone-badge clickable-zone" 
                  style="
                      background-color: ${color};
                      color: white;
                      padding: 4px 12px;
                      border-radius: 12px;
                      font-size: 12px;
                      font-weight: 600;
                      margin-top: 8px;
                      display: inline-block;
                      cursor: pointer;
                      transition: all 0.3s ease;
                  "
                  onclick="showZoneModal(${customer.id}, '${safeName}', '${safeAddress}', '${zone}')"
                  onmouseover="this.style.transform='scale(1.1)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.3)'"
                  onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='none'"
                  title="Click to change zone">
                🗺️ ${zone}
            </span>
            <div class="zone-edit-hint">Click zone to reassign</div>
        </div>
    `;
}

// Geocoding functions
function initGeocoder() {
    if (typeof google !== 'undefined' && google.maps && google.maps.Geocoder) {
        geocoder = new google.maps.Geocoder();
        console.log('Google Maps Geocoder initialized');
        return true;
    }
    return false;
}

function geocodeAddress(customer, callback) {
    if (!geocoder) {
        console.warn('Geocoder not available');
        callback(null);
        return;
    }
    
    // Add San Francisco, CA if not already in address for better accuracy
    let searchAddress = customer.address;
    if (!searchAddress.toLowerCase().includes('san francisco') && 
        !searchAddress.toLowerCase().includes('daly city') &&
        !searchAddress.toLowerCase().includes('south san francisco')) {
        searchAddress += ', San Francisco, CA';
    }
    
    geocoder.geocode({ 
        address: searchAddress,
        region: 'US',
        bounds: new google.maps.LatLngBounds(
            new google.maps.LatLng(37.6, -122.5), // SW
            new google.maps.LatLng(37.9, -122.3)  // NE - SF Bay Area bounds
        )
    }, (results, status) => {
        if (status === 'OK' && results[0]) {
            const location = results[0].geometry.location;
            const lat = location.lat();
            const lng = location.lng();
            
            // Update customer object with new coordinates
            customer.latitude = lat;
            customer.longitude = lng;
            
            // Save to database
            saveCoordinatesToDatabase(customer.id, lat, lng);
            
            callback({ lat, lng });
        } else {
            console.warn(`Geocoding failed for ${customer.name} (${customer.address}): ${status}`);
            callback(null);
        }
    });
}

function saveCoordinatesToDatabase(customerId, latitude, longitude) {
    const formData = new FormData();
    formData.append('action', 'update_coordinates');
    formData.append('customer_id', customerId);
    formData.append('latitude', latitude);
    formData.append('longitude', longitude);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            console.error('Failed to save coordinates:', data.error);
        }
    })
    .catch(error => {
        console.error('Error saving coordinates:', error);
    });
}

function updateGeocodingStatus(show, current = 0, total = 0) {
    const statusElement = document.getElementById('geocodingStatus');
    if (show) {
        statusElement.style.display = 'block';
        document.getElementById('geocodedCount').textContent = current;
        document.getElementById('totalCount').textContent = total;
    } else {
        statusElement.style.display = 'none';
    }
}

function processGeocodingQueue() {
    if (geocodingQueue.length === 0 || isGeocoding) {
        return;
    }
    
    isGeocoding = true;
    updateGeocodingStatus(true, geocodedCount, geocodingQueue.length + geocodedCount);
    
    const customer = geocodingQueue.shift();
    
    geocodeAddress(customer, (coordinates) => {
        geocodedCount++;
        updateGeocodingStatus(true, geocodedCount, geocodingQueue.length + geocodedCount);
        
        if (coordinates) {
            addMarkerToMap(customer, coordinates.lat, coordinates.lng);
        }
        
        // Process next customer after a short delay to respect API limits
        setTimeout(() => {
            isGeocoding = false;
            if (geocodingQueue.length > 0) {
                processGeocodingQueue();
            } else {
                updateGeocodingStatus(false);
                autoFitBounds();
                showMessage('All addresses geocoded successfully!', 'success');
            }
        }, 200); // 200ms delay between requests
    });
}

function addMarkerToMap(customer, lat, lng) {
    const zone = customer.zone || 'No Zone';
    const color = zoneColors[zone] || '#6c757d';
    
    // Create custom colored marker
    const marker = L.marker([lat, lng], {
        icon: createColoredMarker(color)
    });
    
    // Create popup with customer info
    const popupContent = createPopupContent(customer);
    marker.bindPopup(popupContent);
    
    // Store marker reference
    customerMarkers.set(customer.id, {
        marker: marker,
        customer: customer
    });
    
    // Add to both groups
    markerGroup.addLayer(marker);
    markerCluster.addLayer(marker);
}

// Initialize map with customers
function initializeCustomers() {
    // Wait for Google Maps to load
    function waitForGoogleMaps() {
        if (initGeocoder()) {
            loadCustomersOnMap();
        } else {
            setTimeout(waitForGoogleMaps, 100);
        }
    }
    
    if (typeof google === 'undefined') {
        // Wait for Google Maps script to load
        setTimeout(waitForGoogleMaps, 1000);
    } else {
        waitForGoogleMaps();
    }
}

function loadCustomersOnMap() {
    geocodingQueue = [];
    geocodedCount = 0;
    
    customers.forEach(customer => {
        const lat = parseFloat(customer.latitude);
        const lng = parseFloat(customer.longitude);
        
        // If we have valid stored coordinates, use them
        if (!isNaN(lat) && !isNaN(lng) && lat !== 0 && lng !== 0) {
            addMarkerToMap(customer, lat, lng);
        } else {
            // Queue for geocoding
            geocodingQueue.push(customer);
        }
    });
    
    // Start geocoding process for customers without coordinates
    if (geocodingQueue.length > 0) {
        console.log(`Geocoding ${geocodingQueue.length} customer addresses...`);
        processGeocodingQueue();
    } else {
        autoFitBounds();
    }
}

// Start with clustered view
map.addLayer(markerCluster);
let clusteringEnabled = true;

// Map control functions
document.getElementById('fit-bounds').addEventListener('click', autoFitBounds);

function autoFitBounds() {
    const activeGroup = clusteringEnabled ? markerCluster : markerGroup;
    if (activeGroup.getLayers && activeGroup.getLayers().length > 0) {
        map.fitBounds(activeGroup.getBounds(), {padding: [20, 20]});
    } else if (activeGroup.getBounds) {
        map.fitBounds(activeGroup.getBounds(), {padding: [20, 20]});
    }
}

document.getElementById('center-sf').addEventListener('click', function() {
    map.setView([37.7749, -122.4194], 12);
});

document.getElementById('toggle-clusters').addEventListener('click', function() {
    if (clusteringEnabled) {
        map.removeLayer(markerCluster);
        map.addLayer(markerGroup);
        this.textContent = 'Enable Clustering';
        clusteringEnabled = false;
    } else {
        map.removeLayer(markerGroup);
        map.addLayer(markerCluster);
        this.textContent = 'Disable Clustering';
        clusteringEnabled = true;
    }
});

document.getElementById('refresh-addresses').addEventListener('click', function() {
    // Clear existing markers
    customerMarkers.clear();
    markerGroup.clearLayers();
    markerCluster.clearLayers();
    
    // Reload with fresh geocoding
    customers.forEach(customer => {
        customer.latitude = null;
        customer.longitude = null;
    });
    
    loadCustomersOnMap();
    showMessage('Refreshing all addresses from Google Maps...', 'info');
});

// Zone modal functions
function showZoneModal(customerId, customerName, customerAddress, currentZone) {
    currentEditingCustomer = {
        id: customerId,
        name: customerName,
        address: customerAddress,
        currentZone: currentZone
    };
    
    document.getElementById('customerName').textContent = customerName;
    document.getElementById('customerAddress').textContent = customerAddress;
    document.getElementById('currentZone').textContent = currentZone;
    
    // Clear previous selections
    document.querySelectorAll('.zone-option').forEach(option => {
        option.classList.remove('selected');
    });
    
    // Highlight current zone
    const currentZoneElement = document.querySelector(`[data-zone="${currentZone === 'No Zone' ? '' : currentZone}"]`);
    if (currentZoneElement) {
        currentZoneElement.classList.add('selected');
    }
    
    document.getElementById('zoneModal').style.display = 'block';
}

function hideZoneModal() {
    document.getElementById('zoneModal').style.display = 'none';
    currentEditingCustomer = null;
}

async function selectZone(newZone) {
    if (!currentEditingCustomer) return;
    
    const displayZone = newZone || 'No Zone';
    
    // Don't update if same zone
    if (currentEditingCustomer.currentZone === displayZone) {
        hideZoneModal();
        return;
    }
    
    try {
        showMessage('Updating zone...', 'info');
        
        const formData = new FormData();
        formData.append('action', 'update_zone');
        formData.append('customer_id', currentEditingCustomer.id);
        formData.append('zone', newZone);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        // Check if response is ok
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        // Get response text first to debug
        const responseText = await response.text();
        console.log('Response:', responseText);
        
        // Try to parse as JSON
        let result;
        try {
            result = JSON.parse(responseText);
        } catch (parseError) {
            console.error('JSON Parse Error:', parseError);
            console.error('Response was:', responseText);
            throw new Error('Server returned invalid JSON. Check console for details.');
        }
        
        if (result.success) {
            // Update the marker and popup in real-time
            updateCustomerMarker(currentEditingCustomer.id, newZone);
            
            showMessage(`Customer moved to ${displayZone}!`, 'success');
            hideZoneModal();
        } else {
            showMessage('Error: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        console.error('Zone update error:', error);
        showMessage('Error: ' + error.message, 'error');
    }
}

function updateCustomerMarker(customerId, newZone) {
    const markerData = customerMarkers.get(customerId);
    if (!markerData) return;
    
    const { marker, customer } = markerData;
    const displayZone = newZone || 'No Zone';
    const newColor = zoneColors[displayZone] || '#6c757d';
    
    // Update customer data
    customer.zone = newZone;
    
    // Update marker icon
    marker.setIcon(createColoredMarker(newColor));
    
    // Update popup content
    const newPopupContent = createPopupContent(customer);
    marker.setPopupContent(newPopupContent);
    
    // If popup is open, refresh it
    if (marker.isPopupOpen()) {
        marker.openPopup();
    }
}

function showMessage(message, type) {
    const messageBar = document.getElementById('messageBar');
    const messageText = document.getElementById('messageText');
    
    messageText.textContent = message;
    messageBar.className = `message-bar ${type}`;
    messageBar.style.display = 'block';
    
    // Auto-hide success and info messages
    if (type === 'success' || type === 'info') {
        setTimeout(() => {
            messageBar.style.display = 'none';
        }, 3000);
    }
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('zoneModal');
    if (event.target === modal) {
        hideZoneModal();
    }
}

// Add keyboard support
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        hideZoneModal();
    }
});

// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    initializeCustomers();
});

console.log(`Loading ${customers.length} customers on map with accurate address-based positioning`);
</script>

<style>
#map { 
    border: 2px solid #ccc; 
    border-radius: 8px; 
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.map-stats {
    display: flex;
    gap: 15px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.stat-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 15px 20px;
    border-radius: 8px;
    text-align: center;
    min-width: 120px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.stat-number {
    display: block;
    font-size: 24px;
    font-weight: bold;
    margin-bottom: 5px;
}

.stat-label {
    font-size: 12px;
    opacity: 0.9;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Geocoding Status */
.geocoding-status {
    background: #e3f2fd;
    border: 2px solid #2196f3;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
    text-align: center;
}

.status-indicator {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    font-weight: 500;
    color: #1976d2;
}

.status-icon {
    font-size: 24px;
    animation: spin 2s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.status-progress {
    background: rgba(33, 150, 243, 0.1);
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
}

/* Zone Legend Styles */
.zone-legend {
    background: white;
    border: 2px solid #ddd;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.zone-legend h3 {
    margin: 0 0 15px 0;
    color: #333;
    font-size: 18px;
}

.legend-items {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
}

.legend-color {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: 2px solid white;
    box-shadow: 0 1px 3px rgba(0,0,0,0.3);
    flex-shrink: 0;
}

.legend-text {
    font-weight: 500;
    color: #333;
}

.map-controls {
    margin-bottom: 15px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    transition: background-color 0.3s;
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
    background-color: #545b62;
}

.btn-info {
    background-color: #17a2b8;
    color: white;
}

.btn-info:hover {
    background-color: #138496;
}

.btn-warning {
    background-color: #ffc107;
    color: #212529;
}

.btn-warning:hover {
    background-color: #e0a800;
}

.note { 
    color: #666; 
    font-size: 0.95em; 
    padding: 10px;
    background-color: #f8f9fa;
    border-radius: 4px;
    border-left: 4px solid #007bff;
}

.note a {
    color: #007bff;
    text-decoration: none;
}

.note a:hover {
    text-decoration: underline;
}

.customer-popup {
    font-family: Arial, sans-serif;
}

.customer-popup strong {
    color: #333;
    font-size: 16px;
}

.customer-popup .address {
    color: #666;
    font-size: 14px;
    line-height: 1.4;
    margin-bottom: 5px;
    display: block;
}

.zone-edit-hint {
    color: #007bff;
    font-size: 11px;
    font-style: italic;
    margin-top: 4px;
    opacity: 0.8;
}

/* Custom marker styles */
.custom-marker {
    background: transparent !important;
    border: none !important;
}

/* Zone Modal Styles */
.modal {
    display: none;
    position: fixed;
    z-index: 10000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
    backdrop-filter: blur(3px);
}

.modal-content {
    background-color: white;
    margin: 5% auto;
    padding: 30px;
    border-radius: 12px;
    width: 90%;
    max-width: 500px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    position: relative;
    max-height: 80vh;
    overflow-y: auto;
}

.modal-content h2 {
    margin: 0 0 20px 0;
    color: #2c3e50;
    font-size: 1.5rem;
}

.close {
    color: #aaa;
    float: right;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    position: absolute;
    right: 20px;
    top: 15px;
}

.close:hover {
    color: #000;
}

#customerInfo {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 25px;
    border-left: 4px solid #007bff;
}

#customerInfo p {
    margin: 5px 0;
    color: #495057;
}

.zone-selection h4 {
    margin-bottom: 15px;
    color: #495057;
    font-size: 1.1rem;
}

.zone-options {
    display: grid;
    gap: 10px;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
}

.zone-option {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 15px;
    border: 2px solid #dee2e6;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
    background: white;
}

.zone-option:hover {
    border-color: #007bff;
    background-color: #f8f9fa;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,123,255,0.15);
}

.zone-option.selected {
    border-color: #007bff;
    background-color: #e3f2fd;
    box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
}

.zone-color {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: 2px solid white;
    box-shadow: 0 1px 3px rgba(0,0,0,0.3);
    flex-shrink: 0;
}

.zone-name {
    font-weight: 500;
    color: #495057;
    flex: 1;
}

.modal-actions {
    display: flex;
    gap: 15px;
    justify-content: flex-end;
    margin-top: 25px;
    padding-top: 20px;
    border-top: 1px solid #dee2e6;
}

/* Message Bar */
.message-bar {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 12px 24px;
    border-radius: 6px;
    color: white;
    font-weight: 600;
    z-index: 10001;
    min-width: 200px;
    text-align: center;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    transform: translateX(100%);
    animation: slideIn 0.3s ease forwards;
}

.message-bar.success {
    background-color: #28a745;
}

.message-bar.error {
    background-color: #dc3545;
}

.message-bar.info {
    background-color: #17a2b8;
}

@keyframes slideIn {
    to {
        transform: translateX(0);
    }
}

/* Cluster customization */
.marker-cluster-small {
    background-color: rgba(181, 226, 140, 0.6);
    border: 1px solid rgba(181, 226, 140, 0.8);
}
.marker-cluster-small div {
    background-color: rgba(110, 204, 57, 0.6);
}

.marker-cluster-medium {
    background-color: rgba(241, 211, 87, 0.6);
    border: 1px solid rgba(241, 211, 87, 0.8);
}
.marker-cluster-medium div {
    background-color: rgba(240, 194, 12, 0.6);
}

.marker-cluster-large {
    background-color: rgba(253, 156, 115, 0.6);
    border: 1px solid rgba(253, 156, 115, 0.8);
}
.marker-cluster-large div {
    background-color: rgba(241, 128, 23, 0.6);
}

/* Responsive design */
@media (max-width: 768px) {
    .map-stats {
        justify-content: center;
    }
    
    .stat-card {
        flex: 1;
        min-width: 100px;
    }
    
    .legend-items {
        justify-content: center;
    }
    
    .legend-item {
        font-size: 13px;
    }
    
    .map-controls {
        justify-content: center;
    }
    
    .btn {
        flex: 1;
        min-width: 120px;
    }
    
    .zone-legend h3 {
        text-align: center;
    }
    
    .modal-content {
        margin: 10% auto;
        width: 95%;
        padding: 20px;
    }
    
    .zone-options {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 480px) {
    .legend-items {
        flex-direction: column;
        align-items: center;
    }
    
    .legend-item {
        justify-content: center;
    }
    
    .modal-content {
        margin: 5% auto;
        padding: 15px;
    }
    
    .message-bar {
        right: 10px;
        left: 10px;
        min-width: auto;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?> 