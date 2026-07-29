<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';

// Add viewport meta tag for mobile responsiveness
echo '<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">';

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

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>

<style>
/* Mobile-first responsive design */
* {
    box-sizing: border-box;
}

.driver-list-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 10px;
    min-height: 100vh;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.page-header {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    color: #2c3e50;
    padding: 20px;
    border-radius: 15px;
    margin-bottom: 20px;
    text-align: center;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.page-header h1 {
    margin: 0 0 10px 0;
    font-size: 1.8rem;
    font-weight: 700;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.page-header p {
    margin: 0;
    font-size: 1rem;
    color: #6c757d;
    font-weight: 500;
}

.selector-container {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 20px;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    margin-bottom: 20px;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.selector-grid {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.selector-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.selector-label {
    font-weight: 600;
    color: #2c3e50;
    font-size: 1rem;
    margin-bottom: 5px;
}

.selector-input {
    padding: 15px 20px;
    border: 2px solid #e9ecef;
    border-radius: 12px;
    font-size: 16px; /* Prevents zoom on iOS */
    background: white;
    transition: all 0.3s ease;
    cursor: pointer;
    min-height: 50px; /* Touch-friendly height */
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
}

.selector-input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.selector-input:hover {
    border-color: #667eea;
}

.go-button {
    padding: 15px 30px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-transform: uppercase;
    letter-spacing: 1px;
    min-height: 50px; /* Touch-friendly height */
    width: 100%;
}

.go-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
}

.go-button:active {
    transform: translateY(0);
}

.date-navigation {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 20px;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    margin-bottom: 20px;
    text-align: center;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.date-info {
    margin-bottom: 15px;
}

.date-info h2 {
    margin: 0 0 8px 0;
    color: #2c3e50;
    font-size: 1.5rem;
    font-weight: 600;
}

.date-info .order-count {
    color: #6c757d;
    font-size: 1rem;
    font-weight: 500;
}

.date-controls {
    display: flex;
    justify-content: center;
    gap: 10px;
    flex-wrap: wrap;
}

.date-controls a {
    padding: 12px 20px;
    text-decoration: none;
    border-radius: 10px;
    font-weight: 600;
    transition: all 0.3s ease;
    font-size: 0.9rem;
    min-height: 45px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.date-controls .btn-outline {
    background: rgba(255, 255, 255, 0.8);
    color: #6c757d;
    border: 2px solid #e9ecef;
}

.date-controls .btn-outline:hover {
    background: white;
    border-color: #667eea;
    color: #667eea;
    transform: translateY(-2px);
}

.date-controls .btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.stats-container {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 20px;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    margin-bottom: 20px;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 15px;
}

.stat-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 15px;
    border-radius: 12px;
    text-align: center;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.stat-number {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 5px;
}

.stat-label {
    font-size: 0.8rem;
    opacity: 0.9;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.deliveries-container {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    overflow: hidden;
}

.zone-section {
    margin-bottom: 0;
    border-bottom: 1px solid rgba(0,0,0,0.1);
}

.zone-section:last-child {
    border-bottom: none;
}

.zone-header {
    background: rgba(0,0,0,0.05);
    padding: 15px 20px;
    font-weight: 600;
    color: #2c3e50;
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    transition: all 0.3s ease;
    min-height: 50px; /* Touch-friendly */
}

.zone-header:hover {
    background: rgba(0,0,0,0.08);
}

.zone-color-indicator {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    flex-shrink: 0;
}

.zone-stops-count {
    background: rgba(0,0,0,0.1);
    color: #2c3e50;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 500;
    margin-left: auto;
}

.zone-content {
    padding: 0;
}

.customer-stop {
    padding: 15px 20px;
    border-bottom: 1px solid rgba(0,0,0,0.05);
    transition: all 0.3s ease;
    cursor: pointer;
    min-height: 60px; /* Touch-friendly */
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.customer-stop:last-child {
    border-bottom: none;
}

.customer-stop:hover {
    background: rgba(0,0,0,0.02);
}

.customer-stop:active {
    background: rgba(0,0,0,0.05);
}

.stop-header {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.stop-number {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.9rem;
    flex-shrink: 0;
}

.customer-info {
    flex: 1;
    min-width: 0;
}

.customer-name {
    font-weight: 600;
    color: #2c3e50;
    font-size: 1rem;
    margin-bottom: 2px;
    word-break: break-word;
}

.customer-address {
    color: #6c757d;
    font-size: 0.85rem;
    word-break: break-word;
}

.stop-details {
    display: flex;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
    font-size: 0.8rem;
    color: #6c757d;
}

.stop-detail {
    display: flex;
    align-items: center;
    gap: 5px;
}

.stop-detail i {
    font-size: 0.9rem;
}

.order-details {
    background: rgba(0,0,0,0.02);
    border-radius: 8px;
    padding: 10px;
    margin-top: 8px;
    font-size: 0.8rem;
    display: none;
}

.order-details.expanded {
    display: block;
}

.product-line-group {
    margin-bottom: 8px;
}

.product-line-header {
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 4px;
    font-size: 0.85rem;
}

.product-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 4px 0;
    border-bottom: 1px solid rgba(0,0,0,0.05);
    font-size: 0.75rem;
}

.product-item:last-child {
    border-bottom: none;
}

.product-info {
    flex: 1;
    min-width: 0;
}

.product-name {
    font-weight: 500;
    color: #2c3e50;
    word-break: break-word;
}

.product-meta {
    color: #6c757d;
    font-size: 0.7rem;
}

.product-quantity {
    font-weight: 600;
    color: #667eea;
    margin: 0 8px;
}

.product-total {
    font-weight: 600;
    color: #2c3e50;
    min-width: 60px;
    text-align: right;
}

.special-instructions {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 6px;
    padding: 8px;
    margin-top: 8px;
    font-size: 0.75rem;
    color: #856404;
}

/* Mobile-specific improvements */
@media (max-width: 768px) {
    .driver-list-container {
        padding: 5px;
    }
    
    .page-header {
        padding: 15px;
        margin-bottom: 15px;
    }
    
    .page-header h1 {
        font-size: 1.5rem;
    }
    
    .selector-container {
        padding: 15px;
        margin-bottom: 15px;
    }
    
    .selector-grid {
        gap: 15px;
    }
    
    .date-navigation {
        padding: 15px;
        margin-bottom: 15px;
    }
    
    .date-info h2 {
        font-size: 1.3rem;
    }
    
    .date-controls {
        gap: 8px;
    }
    
    .date-controls a {
        padding: 10px 15px;
        font-size: 0.8rem;
        min-height: 40px;
    }
    
    .stats-container {
        padding: 15px;
        margin-bottom: 15px;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }
    
    .stat-card {
        padding: 12px;
    }
    
    .stat-number {
        font-size: 1.3rem;
    }
    
    .zone-header {
        padding: 12px 15px;
        font-size: 1rem;
        min-height: 45px;
    }
    
    .customer-stop {
        padding: 12px 15px;
        min-height: 55px;
    }
    
    .stop-number {
        width: 25px;
        height: 25px;
        font-size: 0.8rem;
    }
    
    .customer-name {
        font-size: 0.9rem;
    }
    
    .customer-address {
        font-size: 0.8rem;
    }
    
    .stop-details {
        gap: 10px;
        font-size: 0.75rem;
    }
    
    .order-details {
        padding: 8px;
        font-size: 0.75rem;
    }
    
    .product-item {
        font-size: 0.7rem;
        padding: 3px 0;
    }
    
    .product-name {
        font-size: 0.7rem;
    }
    
    .product-meta {
        font-size: 0.65rem;
    }
    
    .product-total {
        min-width: 50px;
        font-size: 0.7rem;
    }
}

/* Touch-friendly improvements */
@media (hover: none) and (pointer: coarse) {
    .customer-stop {
        min-height: 70px;
    }
    
    .zone-header {
        min-height: 55px;
    }
    
    .go-button {
        min-height: 55px;
    }
    
    .selector-input {
        min-height: 55px;
    }
    
    .date-controls a {
        min-height: 50px;
    }
}

/* Landscape mobile optimization */
@media (max-width: 768px) and (orientation: landscape) {
    .page-header {
        padding: 10px 15px;
    }
    
    .page-header h1 {
        font-size: 1.3rem;
        margin-bottom: 5px;
    }
    
    .selector-grid {
        flex-direction: row;
        gap: 15px;
    }
    
    .selector-group {
        flex: 1;
    }
    
    .stats-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

/* High DPI displays */
@media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
    .zone-color-indicator {
        border: 1px solid rgba(0,0,0,0.1);
    }
}

/* Reduced motion for accessibility */
@media (prefers-reduced-motion: reduce) {
    * {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
    .driver-list-container {
        background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
    }
    
    .page-header,
    .selector-container,
    .date-navigation,
    .stats-container,
    .deliveries-container {
        background: rgba(255, 255, 255, 0.1);
        color: #ecf0f1;
    }
    
    .zone-header {
        background: rgba(255, 255, 255, 0.05);
        color: #ecf0f1;
    }
    
    .customer-stop:hover {
        background: rgba(255, 255, 255, 0.05);
    }
    
    .customer-name {
        color: #ecf0f1;
    }
    
    .customer-address {
        color: #bdc3c7;
    }
}

/* Additional mobile optimizations */
@media (max-width: 480px) {
    .driver-list-container {
        padding: 2px;
    }
    
    .page-header {
        padding: 10px;
        margin-bottom: 10px;
    }
    
    .page-header h1 {
        font-size: 1.3rem;
    }
    
    .selector-container {
        padding: 10px;
        margin-bottom: 10px;
    }
    
    .date-navigation {
        padding: 10px;
        margin-bottom: 10px;
    }
    
    .stats-container {
        padding: 10px;
        margin-bottom: 10px;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
        gap: 8px;
    }
    
    .zone-header {
        padding: 10px;
        font-size: 0.9rem;
    }
    
    .customer-stop {
        padding: 10px;
    }
    
    .stop-details {
        flex-direction: column;
        gap: 5px;
    }
}

/* Prevent text selection on interactive elements */
.customer-stop,
.zone-header,
.go-button,
.date-controls a {
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    user-select: none;
}

/* Improve touch targets */
.customer-stop,
.zone-header {
    cursor: pointer;
    -webkit-tap-highlight-color: rgba(102, 126, 234, 0.1);
}

/* Smooth scrolling for mobile */
html {
    scroll-behavior: smooth;
}

/* Optimize for mobile performance */
* {
    -webkit-tap-highlight-color: transparent;
}

/* Better focus indicators for accessibility */
.selector-input:focus,
.go-button:focus,
.date-controls a:focus {
    outline: 2px solid #667eea;
    outline-offset: 2px;
}

.modal { display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.4); z-index:9999; align-items:center; justify-content:center; }
.modal-content { background:#fff; border-radius:10px; max-width:95vw; width:400px; margin:auto; padding:24px; position:relative; max-height:90vh; overflow-y:auto; }
.close-modal { position:absolute; top:10px; right:16px; font-size:1.5rem; cursor:pointer; }
.modal-content h2 { color: #2c3e50; font-size: 1.3rem; margin-bottom: 20px; }
.modal-content p { color: #6c757d; margin-bottom: 20px; }
.btn-outline { background: transparent; color: #6c757d; border: 2px solid #6c757d; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; }
.btn-outline:hover { background: #6c757d; color: white; }
.product-item input[type="number"] { width: 70px; padding: 8px; border: 2px solid #e9ecef; border-radius: 6px; text-align: center; font-weight: 600; transition: border-color 0.3s ease; }
.product-item input[type="number"]:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
.product-item input[type="number"]::-webkit-inner-spin-button,
.product-item input[type="number"]::-webkit-outer-spin-button { opacity: 1; height: 20px; }
.complete-delivery-btn { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; border: none; padding: 8px 16px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease; text-transform: uppercase; letter-spacing: 0.5px; min-height: 35px; }
.complete-delivery-btn:hover { transform: translateY(-1px); box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3); }
.complete-delivery-btn:active { transform: translateY(0); }
.complete-delivery-btn:disabled { background: #6c757d; cursor: not-allowed; transform: none; box-shadow: none; }

.view-toggle {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 1000;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 10px;
    padding: 5px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.view-toggle .btn-group {
    display: flex;
    gap: 2px;
}

.view-toggle .btn {
    padding: 8px 12px;
    font-size: 0.8rem;
    border-radius: 8px;
    border: none;
    background: transparent;
    color: #6c757d;
    transition: all 0.3s ease;
    white-space: nowrap;
}

.view-toggle .btn:hover {
    background: rgba(102, 126, 234, 0.1);
    color: #667eea;
}

.view-toggle .btn.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
}

.view-toggle .btn i {
    margin-right: 4px;
}

/* Delivery mode specific styles */
#deliveryView .deliveries-container {
    margin-top: 20px;
}

#deliveryView .customer-stop {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 15px;
    margin: 10px 0;
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.2);
}

#deliveryView .customer-stop:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
}

#deliveryView .customer-name {
    color: white;
    font-weight: 700;
}

#deliveryView .customer-address {
    color: rgba(255, 255, 255, 0.8);
}

#deliveryView .stop-details {
    color: rgba(255, 255, 255, 0.9);
}

#deliveryView .complete-delivery-btn {
    background: rgba(255, 255, 255, 0.2);
    color: white;
    border: 2px solid rgba(255, 255, 255, 0.3);
    font-weight: 600;
    padding: 10px 20px;
    border-radius: 10px;
    transition: all 0.3s ease;
}

#deliveryView .complete-delivery-btn:hover {
    background: rgba(255, 255, 255, 0.3);
    border-color: rgba(255, 255, 255, 0.5);
    transform: translateY(-1px);
}

/* Mobile responsive for view toggle */
@media (max-width: 768px) {
    .view-toggle {
        top: 10px;
        right: 10px;
        padding: 3px;
    }
    
    .view-toggle .btn {
        padding: 6px 8px;
        font-size: 0.7rem;
    }
    
    .view-toggle .btn i {
        margin-right: 2px;
    }
}

/* Empty state styling */
.empty-state {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    padding: 40px 20px;
    border-radius: 15px;
    text-align: center;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.empty-state h3 {
    color: #2c3e50;
    margin-bottom: 10px;
}

.empty-state p {
    color: #6c757d;
    margin: 0;
}

/* Container adjustments for view toggle */
.driver-list-container {
    padding-top: 80px; /* Make room for fixed toggle */
}

@media (max-width: 768px) {
    .driver-list-container {
        padding-top: 70px;
    }
}
</style>

<div class="driver-list-container">
    <div class="view-toggle">
        <div class="btn-group" role="group">
            <button type="button" class="btn btn-outline-primary" id="mainViewBtn">
                <i class="bi bi-list-ul"></i> Full Route
            </button>
            <button type="button" class="btn btn-outline-success" id="deliveryViewBtn">
                <i class="bi bi-truck"></i> Delivery Mode
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
                                    <div class="customer-stop" onclick="toggleOrderDetails(<?php echo $stop['daily_order_id']; ?>, '<?php echo htmlspecialchars($stop['customer_name']); ?>', '<?php echo htmlspecialchars($stop['customer_address']); ?>')">
                                        <div class="stop-header">
                                            <div class="stop-number"><?php echo $stop['route_order']; ?></div>
                                            <div class="customer-info">
                                                <div class="customer-name"><?php echo htmlspecialchars($stop['customer_name']); ?></div>
                                                <div class="customer-address"><?php echo htmlspecialchars($stop['customer_address']); ?></div>
                                            </div>
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
                                            <div class="stop-detail">
                                                <i class="fas fa-info-circle"></i>
                                                <span><?php echo ucfirst($stop['delivery_status'] ?: 'pending'); ?></span>
                                            </div>
                                        </div>
                                        <div style="margin-top: 8px; text-align: right;">
                                            <button class="complete-delivery-btn" onclick="openCompleteDeliveryModal(<?php echo $stop['daily_order_id']; ?>, '<?php echo htmlspecialchars($stop['customer_name']); ?>')">Complete Delivery</button>
                                        </div>
                                        <div class="order-details" id="order-details-<?php echo $stop['daily_order_id']; ?>">
                                            <div style="text-align: center; color: #6c757d; font-style: italic;">
                                                Click to view order details...
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
    
    // Load delivered orders from localStorage
    const storageKey = `delivered_orders_${driverId}_${date}`;
    deliveredOrders = JSON.parse(localStorage.getItem(storageKey) || '[]');
    deliveryHistory = JSON.parse(localStorage.getItem(storageKey + '_history') || '[]');
    
    // Get all orders for this driver and date
    fetch('get_driver_orders.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `driver_id=${driverId}&date=${date}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            deliveryData = data.orders;
            updateDeliveryView();
        } else {
            console.error('Error loading delivery data:', data.error);
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
    });
}

// Update delivery view with current data
function updateDeliveryView() {
    const undeliveredOrders = deliveryData.filter(order => !deliveredOrders.includes(order.daily_order_id));
    const nextOrder = undeliveredOrders[0];
    
    // Update progress
    const progress = deliveredOrders.length;
    const total = deliveryData.length;
    document.getElementById('deliveryProgress').textContent = `${progress}/${total}`;
    
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
    const container = document.getElementById('currentDeliveryContainer');
    container.innerHTML = `
        <div class="deliveries-container">
            <div class="zone-section">
                <div class="zone-header">
                    <div class="zone-color-indicator" style="background-color: ${getZoneColor(order.zone)};"></div>
                    <span>${order.zone}</span>
                    <div class="zone-stops-count">Stop ${order.route_order}</div>
                </div>
                <div class="zone-content">
                    <div class="customer-stop" onclick="toggleOrderDetails(${order.daily_order_id}, '${order.customer_name}', '${order.customer_address}')">
                        <div class="stop-header">
                            <div class="stop-number">${order.route_order}</div>
                            <div class="customer-info">
                                <div class="customer-name">${order.customer_name}</div>
                                <div class="customer-address">${order.customer_address}</div>
                            </div>
                        </div>
                        <div class="stop-details">
                            <div class="stop-detail">
                                <i class="fas fa-clock"></i>
                                <span>${order.scheduled_delivery_time || 'No time set'}</span>
                            </div>
                            <div class="stop-detail">
                                <i class="fas fa-dollar-sign"></i>
                                <span>$${parseFloat(order.total_amount).toFixed(2)}</span>
                            </div>
                            <div class="stop-detail">
                                <i class="fas fa-info-circle"></i>
                                <span>Pending</span>
                            </div>
                        </div>
                        <div style="margin-top: 8px; text-align: right;">
                            <button class="complete-delivery-btn" onclick="markAsDelivered(${order.daily_order_id}, '${order.customer_name}')">Mark as Delivered</button>
                        </div>
                        <div class="order-details" id="order-details-${order.daily_order_id}">
                            <div style="text-align: center; color: #6c757d; font-style: italic;">
                                Click to view order details...
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
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
    
    // Initialize form state
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
