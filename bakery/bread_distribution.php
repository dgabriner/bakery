<?php
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';

// Initialize database connection
$db = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Helper function to convert day number to day name
function getDayName($dayNumber) {
    $days = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    return $days[$dayNumber] ?? 'Unknown';
}

// Check if default_quantity column exists and add it if it doesn't
$columnExists = $db->query("SHOW COLUMNS FROM products LIKE 'default_quantity'")->rowCount() > 0;
if (!$columnExists) {
    $db->exec("ALTER TABLE products ADD COLUMN default_quantity INT NOT NULL DEFAULT 0");
}

// Get pagination parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20; // Reduced from loading all customers
$zoneFilter = isset($_GET['zone']) && $_GET['zone'] !== '' ? trim((string)$_GET['zone']) : null;
$dayFilter = isset($_GET['day']) ? bakery_normalize_standing_day($_GET['day']) : null;
$zoneQueryParam = $zoneFilter !== null ? '&zone=' . urlencode($zoneFilter) : '';

// Calculate offset
$offset = ($page - 1) * $perPage;

// Get total count for pagination
$countQuery = "SELECT COUNT(DISTINCT c.id) as total FROM customers c";
$countParams = [];

if ($zoneFilter !== null) {
    $countQuery .= " WHERE c.zone = ?";
    $countParams[] = $zoneFilter;
}

$stmt = $db->prepare($countQuery);
$stmt->execute($countParams);
$totalCustomers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalCustomers / $perPage);

// Get all products with their default quantities (simplified query)
$stmt = $db->query("
    SELECT DISTINCT p.id, p.name, p.dough_type_id, p.price, dt.name as dough_type, pl.name as product_line,
           p.default_quantity_monday, p.default_quantity_tuesday, p.default_quantity_wednesday,
           p.default_quantity_thursday, p.default_quantity_friday, p.default_quantity_saturday,
           p.default_quantity_sunday
    FROM products p
    JOIN dough_types dt ON p.dough_type_id = dt.id
    JOIN product_lines pl ON dt.product_line_id = pl.id
    ORDER BY pl.name, dt.name, p.name
");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Create a flat list of all products for the filter dropdown
$allProducts = [];
foreach ($products as $product) {
    $allProducts[] = [
        'id' => $product['id'],
        'name' => $product['name'],
        'product_line' => $product['product_line'],
        'dough_type' => $product['dough_type']
    ];
}

// Group products by product line, ensuring no duplicates
$productLines = [];
foreach ($products as $product) {
    if (!isset($productLines[$product['product_line']])) {
        $productLines[$product['product_line']] = [];
    }
    // Only add the product if it's not already in the array
    $productExists = false;
    foreach ($productLines[$product['product_line']] as $existingProduct) {
        if ($existingProduct['id'] === $product['id']) {
            $productExists = true;
            break;
        }
    }
    if (!$productExists) {
        $productLines[$product['product_line']][] = $product;
    }
}

// Get zones for filtering
$zones = $db->query("SELECT * FROM zones ORDER BY name")->fetchAll();

// Get drivers for delivery day assignment
$drivers = $db->query("SELECT id, name FROM drivers ORDER BY name")->fetchAll();

// Build customer query with pagination and filters
$customerQuery = "
    SELECT 
        c.id, 
        c.name, 
        c.zone,
        z.name as zone_name,
        GROUP_CONCAT(DISTINCT sr.day_of_week ORDER BY sr.day_of_week) as delivery_days
    FROM customers c
    " . bakery_customer_zone_join_sql() . "
    LEFT JOIN standing_routes sr ON c.id = sr.customer_id
";

$customerParams = [];

if ($zoneFilter !== null) {
    $customerQuery .= " WHERE c.zone = ?";
    $customerParams[] = $zoneFilter;
}

$customerQuery .= " GROUP BY c.id ORDER BY c.zone, c.name LIMIT $perPage OFFSET $offset";

$stmt = $db->prepare($customerQuery);
$stmt->execute($customerParams);
$customers = $stmt->fetchAll();

// Get existing standing orders for the current page customers only
$existingOrders = [];
if (!empty($customers)) {
    $customerIds = array_column($customers, 'id');
    $placeholders = str_repeat('?,', count($customerIds) - 1) . '?';
    
    $orders = $db->prepare("
        SELECT 
            customer_id, 
            product_id, 
            day_of_week, 
            quantity
        FROM standing_orders
        WHERE customer_id IN ($placeholders)
    ");
    $orders->execute($customerIds);
    $ordersData = $orders->fetchAll();

    foreach ($ordersData as $order) {
        $existingOrders[$order['customer_id']][$order['product_id']][$order['day_of_week']] = $order['quantity'];
    }
}

// Get day names
$dayNames = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'update_default':
                if (!isset($_POST['product_id']) || !isset($_POST['day']) || !isset($_POST['quantity'])) {
                    throw new Exception('Missing required parameters');
                }

                $product_id = $_POST['product_id'];
                $day = $_POST['day'];
                $quantity = $_POST['quantity'];

                // Map day number to column name
                $day_columns = [
                    '7' => 'default_quantity_sunday',
                    '1' => 'default_quantity_monday',
                    '2' => 'default_quantity_tuesday',
                    '3' => 'default_quantity_wednesday',
                    '4' => 'default_quantity_thursday',
                    '5' => 'default_quantity_friday',
                    '6' => 'default_quantity_saturday'
                ];

                if (!isset($day_columns[$day])) {
                    throw new Exception('Invalid day number');
                }

                $column = $day_columns[$day];
                
                // Update the default quantity
                $stmt = $db->prepare("UPDATE products SET $column = ? WHERE id = ?");
                $stmt->execute([$quantity, $product_id]);
                
                echo json_encode(['success' => true]);
                break;

            case 'save_distribution':
                $updates = json_decode($_POST['updates'], true);
                
                if ($updates) {
                    $db->beginTransaction();
                    
                    try {
                        foreach ($updates as $update) {
                            $customerId = (int)$update['customer_id'];
                            $productId = (int)$update['product_id'];
                            $dayOfWeek = bakery_normalize_standing_day((int)$update['day_of_week']);
                            $quantity = (int)$update['quantity'];
                            
                            if ($quantity > 0) {
                                $stmt = $db->prepare("
                                    INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity)
                                    VALUES (?, ?, ?, ?)
                                    ON DUPLICATE KEY UPDATE quantity = ?
                                ");
                                $stmt->execute([$customerId, $productId, $dayOfWeek, $quantity, $quantity]);
                            } else {
                                $stmt = $db->prepare("DELETE FROM standing_orders WHERE customer_id = ? AND product_id = ? AND day_of_week = ?");
                                $stmt->execute([$customerId, $productId, $dayOfWeek]);
                            }
                        }
                        
                        $db->commit();
                        echo json_encode(['success' => true, 'message' => 'Successfully saved distribution!']);
                    } catch (Exception $e) {
                        $db->rollBack();
                        echo json_encode(['success' => false, 'error' => 'Error saving distribution: ' . $e->getMessage()]);
                    }
                } else {
                    echo json_encode(['success' => false, 'error' => 'No updates provided']);
                }
                exit;
                break;
                
            case 'apply_default':
                $productId = (int)$_POST['product_id'];
                $day = (int)$_POST['day'];
                $quantity = (int)$_POST['quantity'];
                
                // Map day number to column name
                $dayColumns = [
                    1 => 'default_quantity_monday',
                    2 => 'default_quantity_tuesday',
                    3 => 'default_quantity_wednesday',
                    4 => 'default_quantity_thursday',
                    5 => 'default_quantity_friday',
                    6 => 'default_quantity_saturday',
                    7 => 'default_quantity_sunday'
                ];
                
                if (!isset($dayColumns[$day])) {
                    echo json_encode([
                        'success' => false,
                        'error' => "Invalid day number: $day"
                    ]);
                    exit;
                }
                
                $columnName = $dayColumns[$day];
                
                try {
                    $stmt = $db->prepare("
                        UPDATE products 
                        SET $columnName = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$quantity, $productId]);
                    
                    echo json_encode(['success' => true]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                exit;
                break;

            case 'get_customer_orders':
                $customerId = (int)$_POST['customer_id'];
                
                $stmt = $db->prepare("
                    SELECT 
                        customer_id, 
                        product_id, 
                        day_of_week, 
                        quantity
                    FROM standing_orders
                    WHERE customer_id = ?
                ");
                $stmt->execute([$customerId]);
                $orders = $stmt->fetchAll();
                
                $customerOrders = [];
                foreach ($orders as $order) {
                    $customerOrders[$order['product_id']][$order['day_of_week']] = $order['quantity'];
                }
                
                echo json_encode(['success' => true, 'orders' => $customerOrders]);
                exit;
                break;

            default:
                echo json_encode(['success' => false, 'error' => 'Unknown action']);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Handle success/error messages
$successMessage = null;
$errorMessage = null;

if (isset($_GET['success'])) {
    $successMessage = $_GET['success'];
}

if (isset($_GET['error'])) {
    $errorMessage = $_GET['error'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bread Distribution</title>
    <!-- Temporarily commented out to test if external CSS is causing conflicts -->
    <!-- <link rel="stylesheet" href="css/styles.css"> -->
    <style>
        /* Override and enhance existing styles */
        .distribution-container {
            display: flex;
            flex-direction: column;
            gap: 20px;
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 20px 0;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .pagination a, .pagination span {
            padding: 8px 12px;
            text-decoration: none;
            border: 1px solid #ddd;
            color: #333;
            border-radius: 4px;
            display: inline-block;
        }
        
        .pagination a:hover {
            background-color: #f5f5f5;
        }
        
        .pagination .current {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
        }
        
        .pagination .disabled {
            color: #999;
            cursor: not-allowed;
        }
        
        .filters {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            align-items: center;
            flex-wrap: wrap;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
        }
        
        .filter-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-group label {
            font-weight: bold;
            white-space: nowrap;
        }
        
        .filter-group select {
            padding: 5px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            min-width: 120px;
        }
        
        .loading {
            text-align: center;
            padding: 20px;
            font-style: italic;
            color: #666;
        }
        
        .customer-card {
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .customer-header {
            background-color: #f8f9fa;
            padding: 10px 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            border-bottom: 1px solid #ddd;
        }
        
        .customer-header:hover {
            background-color: #e9ecef;
        }
        
        .customer-content {
            display: none;
            padding: 15px;
            background: white;
        }
        
        .customer-content.loaded {
            display: block;
        }
        
        .day-badge {
            background-color: #007bff;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            margin-left: 5px;
            display: inline-block;
        }
        
        .zone-badge {
            background-color: #28a745;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            margin-right: 10px;
            display: inline-block;
        }
        
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }
        
        .product-card {
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 10px;
            background: white;
        }
        
        .product-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .quantity-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
        }
        
        .quantity-input-container {
            text-align: center;
        }
        
        .quantity-input-container label {
            display: block;
            font-size: 12px;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .quantity-input {
            width: 100%;
            padding: 5px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-align: center;
        }
        
        .quantity-input:disabled {
            background-color: #f5f5f5;
            color: #999;
        }
        
        .delivery-day {
            background-color: #e8f5e8;
        }
        
        .non-delivery-day {
            background-color: #f8f8f8;
        }
        
        .default-quantities {
            margin-bottom: 30px;
        }
        
        .default-quantities-grid {
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            background: white;
            width: 100%;
            margin: 0;
            padding: 0;
        }
        
        .grid-header {
            display: grid;
            grid-template-columns: 200px repeat(7, 1fr);
            background-color: #f8f9fa;
            font-weight: bold;
            width: 100%;
            margin: 0;
            padding: 0;
        }
        
        .grid-header > div {
            padding: 10px;
            border-right: 1px solid #ddd;
            text-align: center;
            min-width: 0;
            margin: 0;
        }
        
        .grid-row {
            display: grid;
            grid-template-columns: 200px repeat(7, 1fr);
            border-bottom: 1px solid #ddd;
            width: 100%;
            margin: 0;
            padding: 0;
        }
        
        .grid-row > div {
            padding: 10px;
            border-right: 1px solid #ddd;
            min-width: 0;
            margin: 0;
        }
        
        .grid-row:last-child {
            border-bottom: none;
        }
        
        .default-quantity-field {
            width: 100%;
            padding: 5px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-align: center;
            box-sizing: border-box;
            margin: 0;
        }
        
        .product-line-header {
            display: grid;
            grid-template-columns: 200px repeat(7, 1fr);
            background-color: #e9ecef;
            font-weight: bold;
            width: 100%;
            margin: 0;
            padding: 0;
        }
        
        .product-line-header > div {
            padding: 10px;
            border-right: 1px solid #ddd;
            min-width: 0;
            margin: 0;
        }
        
        /* Day totals summary */
        .day-totals {
            margin-top: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
        }
        
        .day-totals h3 {
            margin-top: 0;
            color: #2c3e50;
        }
        
        .day-totals-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 10px;
            margin-top: 10px;
        }
        
        .day-total-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 10px;
            text-align: center;
        }
        
        .day-total-card .day-name {
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .day-total-card .total-quantity {
            font-size: 1.2rem;
            font-weight: bold;
            color: #28a745;
        }
        
        .day-total-card .total-products {
            font-size: 0.9rem;
            color: #6c757d;
            margin-top: 3px;
        }
        
        .summary-section {
            margin-top: 30px;
        }
        
        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .summary-table th,
        .summary-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
        }
        
        .summary-table th {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        
        .save-controls {
            margin-top: 20px;
            text-align: center;
        }
        
        .save-button {
            background-color: #28a745;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        
        .save-button:hover {
            background-color: #218838;
        }
        
        .notification {
            padding: 10px 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .notification.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .notification.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .error {
            color: #dc3545;
            padding: 10px;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 4px;
            margin: 10px 0;
        }
        
        .performance-info {
            background-color: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 4px;
            padding: 10px;
            margin-bottom: 20px;
        }
        
        .performance-info ul {
            margin: 5px 0;
            padding-left: 20px;
        }
        
        .performance-info li {
            margin: 2px 0;
        }
        
        .controls-panel {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .filter-controls {
            margin-bottom: 15px;
        }
        
        .day-filter {
            margin-bottom: 10px;
        }
        
        .day-filter-btn {
            margin-right: 5px;
            padding: 5px 10px;
            border: 1px solid #ddd;
            background: white;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .day-filter-btn:hover {
            background: #f0f0f0;
        }
        
        .day-filter-btn.active {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }
        
        .product-filter-select {
            padding: 5px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            min-width: 200px;
        }
        
        .customer-distribution {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .no-customers {
            text-align: center;
            padding: 40px;
            color: #666;
            font-style: italic;
        }
        
        /* Responsive design */
        @media (max-width: 768px) {
            .filters {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-group {
                flex-direction: column;
                align-items: stretch;
            }
            
            .grid-header,
            .grid-row,
            .product-line-header {
                grid-template-columns: 150px repeat(7, 1fr);
            }
            
            .product-grid {
                grid-template-columns: 1fr;
            }
            
            .quantity-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <?php include 'includes/nav.php'; ?>

<script>
window.availableDrivers = <?php echo json_encode($drivers); ?>;
</script>

<div class="distribution-container">
    <h1>Bread Distribution</h1>
    
    <div class="performance-info">
        <strong>Performance Optimizations:</strong>
        <ul>
            <li>Pagination: Showing <?php echo $perPage; ?> customers per page (<?php echo $totalCustomers; ?> total)</li>
            <li>Lazy Loading: Customer data loads only when expanded</li>
            <li>Efficient Queries: Reduced complex JOINs and optimized database calls</li>
            <li>Memory Management: Only loads data for current page</li>
        </ul>
        <div style="margin-top: 10px; padding: 10px; background: #f0f0f0; border-radius: 4px; font-size: 12px;">
            <strong>Debug Info:</strong><br>
            Products loaded: <?php echo count($products); ?><br>
            Product lines: <?php echo count($productLines); ?><br>
            Customers on this page: <?php echo count($customers); ?><br>
            Page: <?php echo $page; ?> of <?php echo $totalPages; ?>
        </div>
    </div>
    
    <?php if (isset($successMessage)): ?>
        <div class="notification success"><?php echo $successMessage; ?></div>
    <?php endif; ?>
    
    <?php if (isset($errorMessage)): ?>
        <div class="notification error"><?php echo $errorMessage; ?></div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="filters">
        <div class="filter-group">
            <label for="zone-filter">Zone:</label>
            <select id="zone-filter" onchange="applyFilters()">
                <option value="">All Zones</option>
                <?php foreach ($zones as $zone): ?>
                    <option value="<?php echo htmlspecialchars($zone['name']); ?>" <?php echo $zoneFilter === $zone['name'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($zone['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="filter-group">
            <label for="day-filter">Delivery Day:</label>
            <select id="day-filter" onchange="applyFilters()">
                <option value="">All Days</option>
                <option value="1" <?php echo $dayFilter == 1 ? 'selected' : ''; ?>>Monday</option>
                <option value="2" <?php echo $dayFilter == 2 ? 'selected' : ''; ?>>Tuesday</option>
                <option value="3" <?php echo $dayFilter == 3 ? 'selected' : ''; ?>>Wednesday</option>
                <option value="4" <?php echo $dayFilter == 4 ? 'selected' : ''; ?>>Thursday</option>
                <option value="5" <?php echo $dayFilter == 5 ? 'selected' : ''; ?>>Friday</option>
                <option value="6" <?php echo $dayFilter == 6 ? 'selected' : ''; ?>>Saturday</option>
                <option value="7" <?php echo $dayFilter == 7 ? 'selected' : ''; ?>>Sunday</option>
            </select>
        </div>
        
        <div class="filter-group">
            <label for="per-page">Customers per page:</label>
            <select id="per-page" onchange="applyFilters()">
                <option value="10" <?php echo $perPage == 10 ? 'selected' : ''; ?>>10</option>
                <option value="20" <?php echo $perPage == 20 ? 'selected' : ''; ?>>20</option>
                <option value="50" <?php echo $perPage == 50 ? 'selected' : ''; ?>>50</option>
            </select>
        </div>
    </div>
    
    <div class="controls-panel">
        <h2>Default Quantities</h2>
        <p>Set the default quantities for each product by day of the week. Changes are saved automatically.</p>
        
        <div class="filter-controls">
            <div class="day-filter">
                <span class="filter-label">Filter by Day:</span>
                <button class="day-filter-btn" data-day="all">All Days</button>
                <button class="day-filter-btn" data-day="1">Monday</button>
                <button class="day-filter-btn" data-day="2">Tuesday</button>
                <button class="day-filter-btn" data-day="3">Wednesday</button>
                <button class="day-filter-btn" data-day="4">Thursday</button>
                <button class="day-filter-btn" data-day="5">Friday</button>
                <button class="day-filter-btn" data-day="6">Saturday</button>
                <button class="day-filter-btn" data-day="7">Sunday</button>
            </div>
            
            <div class="product-filter">
                <span class="filter-label">Filter by Product:</span>
                <select id="product-filter-select" class="product-filter-select">
                    <option value="all">All Products</option>
                    <?php foreach ($allProducts as $product): ?>
                        <option value="<?php echo $product['id']; ?>">
                            <?php echo htmlspecialchars($product['name']); ?> 
                            (<?php echo htmlspecialchars($product['product_line']); ?> - <?php echo htmlspecialchars($product['dough_type']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div class="default-quantities">
            <div class="default-quantities-grid">
                <div class="grid-header">
                    <div class="product-name-header">Product</div>
                    <div class="day-header" data-day="7">Sunday</div>
                    <div class="day-header" data-day="1">Monday</div>
                    <div class="day-header" data-day="2">Tuesday</div>
                    <div class="day-header" data-day="3">Wednesday</div>
                    <div class="day-header" data-day="4">Thursday</div>
                    <div class="day-header" data-day="5">Friday</div>
                    <div class="day-header" data-day="6">Saturday</div>
                </div>
                <?php 
                if (empty($productLines)) {
                    echo '<div style="padding: 20px; text-align: center; color: #666;">No products found</div>';
                } else {
                    foreach ($productLines as $productLineName => $productLineProducts): 
                ?>
                    <div class="product-line-header">
                        <div class="product-name"><?php echo htmlspecialchars($productLineName); ?></div>
                        <div></div><div></div><div></div><div></div><div></div><div></div><div></div>
                    </div>
                    <?php foreach ($productLineProducts as $product): ?>
                        <div class="grid-row">
                            <div class="product-name" data-product-id="<?php echo $product['id']; ?>"><?php echo htmlspecialchars($product['name']); ?></div>
                            <div class="quantity-input-container" data-day="7">
                                <input type="number" 
                                       id="default_<?php echo $product['id']; ?>_7" 
                                       value="<?php echo $product['default_quantity_sunday']; ?>" 
                                       min="0" 
                                       data-product-id="<?php echo $product['id']; ?>"
                                       data-day="7"
                                       class="default-quantity-field">
                                <div class="product-total" style="font-size: 0.8rem; color: #6c757d; margin-top: 2px;">
                                    Total: <?php 
                                        $sunClause = bakery_standing_day_in_clause(7);
                                        $stmt = $db->prepare("SELECT SUM(quantity) as total FROM standing_orders WHERE product_id = ? AND day_of_week {$sunClause['sql']}");
                                        $stmt->execute(array_merge([$product['id']], $sunClause['values']));
                                        $total = $stmt->fetchColumn() ?: 0;
                                        echo number_format($total);
                                    ?>
                                </div>
                            </div>
                            <div class="quantity-input-container" data-day="1">
                                <input type="number" 
                                       id="default_<?php echo $product['id']; ?>_1" 
                                       value="<?php echo $product['default_quantity_monday']; ?>" 
                                       min="0" 
                                       data-product-id="<?php echo $product['id']; ?>"
                                       data-day="1"
                                       class="default-quantity-field">
                                <div class="product-total" style="font-size: 0.8rem; color: #6c757d; margin-top: 2px;">
                                    Total: <?php 
                                        $stmt = $db->prepare("SELECT SUM(quantity) as total FROM standing_orders WHERE product_id = ? AND day_of_week = 1");
                                        $stmt->execute([$product['id']]);
                                        $total = $stmt->fetchColumn() ?: 0;
                                        echo number_format($total);
                                    ?>
                                </div>
                            </div>
                            <div class="quantity-input-container" data-day="2">
                                <input type="number" 
                                       id="default_<?php echo $product['id']; ?>_2" 
                                       value="<?php echo $product['default_quantity_tuesday']; ?>" 
                                       min="0" 
                                       data-product-id="<?php echo $product['id']; ?>"
                                       data-day="2"
                                       class="default-quantity-field">
                                <div class="product-total" style="font-size: 0.8rem; color: #6c757d; margin-top: 2px;">
                                    Total: <?php 
                                        $stmt = $db->prepare("SELECT SUM(quantity) as total FROM standing_orders WHERE product_id = ? AND day_of_week = 2");
                                        $stmt->execute([$product['id']]);
                                        $total = $stmt->fetchColumn() ?: 0;
                                        echo number_format($total);
                                    ?>
                                </div>
                            </div>
                            <div class="quantity-input-container" data-day="3">
                                <input type="number" 
                                       id="default_<?php echo $product['id']; ?>_3" 
                                       value="<?php echo $product['default_quantity_wednesday']; ?>" 
                                       min="0" 
                                       data-product-id="<?php echo $product['id']; ?>"
                                       data-day="3"
                                       class="default-quantity-field">
                                <div class="product-total" style="font-size: 0.8rem; color: #6c757d; margin-top: 2px;">
                                    Total: <?php 
                                        $stmt = $db->prepare("SELECT SUM(quantity) as total FROM standing_orders WHERE product_id = ? AND day_of_week = 3");
                                        $stmt->execute([$product['id']]);
                                        $total = $stmt->fetchColumn() ?: 0;
                                        echo number_format($total);
                                    ?>
                                </div>
                            </div>
                            <div class="quantity-input-container" data-day="4">
                                <input type="number" 
                                       id="default_<?php echo $product['id']; ?>_4" 
                                       value="<?php echo $product['default_quantity_thursday']; ?>" 
                                       min="0" 
                                       data-product-id="<?php echo $product['id']; ?>"
                                       data-day="4"
                                       class="default-quantity-field">
                                <div class="product-total" style="font-size: 0.8rem; color: #6c757d; margin-top: 2px;">
                                    Total: <?php 
                                        $stmt = $db->prepare("SELECT SUM(quantity) as total FROM standing_orders WHERE product_id = ? AND day_of_week = 4");
                                        $stmt->execute([$product['id']]);
                                        $total = $stmt->fetchColumn() ?: 0;
                                        echo number_format($total);
                                    ?>
                                </div>
                            </div>
                            <div class="quantity-input-container" data-day="5">
                                <input type="number" 
                                       id="default_<?php echo $product['id']; ?>_5" 
                                       value="<?php echo $product['default_quantity_friday']; ?>" 
                                       min="0" 
                                       data-product-id="<?php echo $product['id']; ?>"
                                       data-day="5"
                                       class="default-quantity-field">
                                <div class="product-total" style="font-size: 0.8rem; color: #6c757d; margin-top: 2px;">
                                    Total: <?php 
                                        $stmt = $db->prepare("SELECT SUM(quantity) as total FROM standing_orders WHERE product_id = ? AND day_of_week = 5");
                                        $stmt->execute([$product['id']]);
                                        $total = $stmt->fetchColumn() ?: 0;
                                        echo number_format($total);
                                    ?>
                                </div>
                            </div>
                            <div class="quantity-input-container" data-day="6">
                                <input type="number" 
                                       id="default_<?php echo $product['id']; ?>_6" 
                                       value="<?php echo $product['default_quantity_saturday']; ?>" 
                                       min="0" 
                                       data-product-id="<?php echo $product['id']; ?>"
                                       data-day="6"
                                       class="default-quantity-field">
                                <div class="product-total" style="font-size: 0.8rem; color: #6c757d; margin-top: 2px;">
                                    Total: <?php 
                                        $stmt = $db->prepare("SELECT SUM(quantity) as total FROM standing_orders WHERE product_id = ? AND day_of_week = 6");
                                        $stmt->execute([$product['id']]);
                                        $total = $stmt->fetchColumn() ?: 0;
                                        echo number_format($total);
                                    ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php 
                    endforeach;
                }
                ?>
            </div>
            
            <!-- Day Totals Summary -->
            <div class="day-totals">
                <h3>Daily Totals Summary</h3>
                <div class="day-totals-grid">
                    <?php
                    $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                    $dayColumns = [
                        'default_quantity_sunday',
                        'default_quantity_monday', 
                        'default_quantity_tuesday',
                        'default_quantity_wednesday',
                        'default_quantity_thursday',
                        'default_quantity_friday',
                        'default_quantity_saturday'
                    ];
                    
                    foreach ($dayNames as $index => $dayName) {
                        $column = $dayColumns[$index];
                        
                        // Calculate total for this day
                        $totalStmt = $db->prepare("SELECT SUM($column) as total, COUNT(*) as product_count FROM products WHERE $column > 0");
                        $totalStmt->execute();
                        $dayTotal = $totalStmt->fetch();
                        $totalQuantity = $dayTotal['total'] ?? 0;
                        $productCount = $dayTotal['product_count'] ?? 0;
                        
                        echo '<div class="day-total-card">';
                        echo '<div class="day-name">' . $dayName . '</div>';
                        echo '<div class="total-quantity">' . number_format($totalQuantity) . '</div>';
                        echo '<div class="total-products">' . $productCount . ' products</div>';
                        echo '</div>';
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Customer Distribution Section -->
    <div class="customer-distribution">
        <h2>Customer Distribution (Page <?php echo $page; ?> of <?php echo $totalPages; ?>)</h2>
        <p>Showing <?php echo count($customers); ?> of <?php echo $totalCustomers; ?> customers</p>
        
        <div class="customers-container">
            <?php if (empty($customers)): ?>
                <div class="no-customers">
                    <p>No customers found for the selected filters.</p>
                </div>
            <?php else: ?>
                <?php foreach ($customers as $customer): 
                    $deliveryDays = $customer['delivery_days'] ? explode(',', $customer['delivery_days']) : [];
                ?>
                    <div class="customer-card" data-customer-id="<?php echo $customer['id']; ?>">
                        <div class="customer-header" onclick="toggleCustomerContent(<?php echo $customer['id']; ?>)">
                            <div class="customer-name"><?php echo htmlspecialchars($customer['name']); ?></div>
                            <div class="customer-days">
                                <span class="zone-badge"><?php echo htmlspecialchars($customer['zone_name'] ?: 'No Zone'); ?></span>
                                <?php foreach ($deliveryDays as $day): ?>
                                    <span class="day-badge"><?php echo getDayName($day); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="customer-content" id="customer-content-<?php echo $customer['id']; ?>">
                            <div class="loading">Loading customer data...</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=1<?php echo $zoneQueryParam; ?><?php echo $dayFilter ? '&day=' . $dayFilter : ''; ?><?php echo $perPage != 20 ? '&per_page=' . $perPage : ''; ?>">First</a>
                    <a href="?page=<?php echo $page - 1; ?><?php echo $zoneQueryParam; ?><?php echo $dayFilter ? '&day=' . $dayFilter : ''; ?><?php echo $perPage != 20 ? '&per_page=' . $perPage : ''; ?>">Previous</a>
                <?php else: ?>
                    <span class="disabled">First</span>
                    <span class="disabled">Previous</span>
                <?php endif; ?>
                
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                
                for ($i = $startPage; $i <= $endPage; $i++):
                ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?page=<?php echo $i; ?><?php echo $zoneQueryParam; ?><?php echo $dayFilter ? '&day=' . $dayFilter : ''; ?><?php echo $perPage != 20 ? '&per_page=' . $perPage : ''; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?><?php echo $zoneQueryParam; ?><?php echo $dayFilter ? '&day=' . $dayFilter : ''; ?><?php echo $perPage != 20 ? '&per_page=' . $perPage : ''; ?>">Next</a>
                    <a href="?page=<?php echo $totalPages; ?><?php echo $zoneQueryParam; ?><?php echo $dayFilter ? '&day=' . $dayFilter : ''; ?><?php echo $perPage != 20 ? '&per_page=' . $perPage : ''; ?>">Last</a>
                <?php else: ?>
                    <span class="disabled">Next</span>
                    <span class="disabled">Last</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="save-controls">
        <button id="save-distribution" class="save-button">Save Distribution</button>
        <div id="save-status"></div>
    </div>
</div>

<script>
// Store product data globally
window.productLines = <?php echo json_encode($productLines); ?>;
window.existingOrders = <?php echo json_encode($existingOrders); ?>;

function applyFilters() {
    const zoneFilter = document.getElementById('zone-filter').value;
    const dayFilter = document.getElementById('day-filter').value;
    const perPage = document.getElementById('per-page').value;
    
    let url = 'bread_distribution.php?page=1';
    if (zoneFilter) url += '&zone=' + encodeURIComponent(zoneFilter);
    if (dayFilter) url += '&day=' + dayFilter;
    if (perPage != 20) url += '&per_page=' + perPage;
    
    window.location.href = url;
}

function toggleCustomerContent(customerId) {
    const contentDiv = document.getElementById('customer-content-' + customerId);
    
    if (contentDiv.classList.contains('loaded')) {
        // Toggle visibility
        contentDiv.style.display = contentDiv.style.display === 'none' ? 'block' : 'none';
    } else {
        // Load customer data
        loadCustomerData(customerId);
    }
}

function loadCustomerData(customerId) {
    const contentDiv = document.getElementById('customer-content-' + customerId);
    
    // Show loading
    contentDiv.innerHTML = '<div class="loading">Loading customer data...</div>';
    contentDiv.style.display = 'block';
    
    // Fetch customer orders
    fetch('bread_distribution.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_customer_orders&customer_id=' + customerId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update existing orders for this customer
            if (data.orders) {
                window.existingOrders[customerId] = data.orders;
            }
            
            // Generate customer content
            contentDiv.innerHTML = generateCustomerContent(customerId);
            contentDiv.classList.add('loaded');
        } else {
            contentDiv.innerHTML = '<div class="error">Error loading customer data: ' + (data.error || 'Unknown error') + '</div>';
        }
    })
    .catch(error => {
        contentDiv.innerHTML = '<div class="error">Error loading customer data: ' + error.message + '</div>';
    });
}

function generateCustomerContent(customerId) {
    let html = '';
    
    // Get customer delivery days from the page data
    const customerElement = document.querySelector(`[data-customer-id="${customerId}"]`);
    const dayBadges = customerElement.querySelectorAll('.day-badge');
    const deliveryDays = [];
    dayBadges.forEach(badge => {
        const dayText = badge.textContent;
        const dayMap = {
            'Monday': 1, 'Tuesday': 2, 'Wednesday': 3, 'Thursday': 4,
            'Friday': 5, 'Saturday': 6, 'Sunday': 7
        };
        if (dayMap[dayText] !== undefined) {
            deliveryDays.push(dayMap[dayText]);
        }
    });
    
    // Generate product sections
    for (const [productLineName, productLineProducts] of Object.entries(window.productLines)) {
        html += `
            <div class="product-line-section">
                <div class="product-line-header">
                    <div class="product-line-name">${productLineName}</div>
                    <div class="product-line-toggle">▼</div>
                </div>
                <div class="product-line-content">
        `;
        
        // Group by dough type
        const productsByDoughType = {};
        productLineProducts.forEach(product => {
            if (!productsByDoughType[product.dough_type_id]) {
                productsByDoughType[product.dough_type_id] = {
                    name: product.dough_type,
                    products: []
                };
            }
            productsByDoughType[product.dough_type_id].products.push(product);
        });
        
        for (const [doughTypeId, doughTypeData] of Object.entries(productsByDoughType)) {
            html += `
                <div class="dough-type-subsection">
                    <div class="dough-type-header">
                        <div class="dough-type-name">${doughTypeData.name}</div>
                        <div class="dough-type-toggle">▼</div>
                    </div>
                    <div class="dough-type-content">
                        <div class="product-grid">
            `;
            
            doughTypeData.products.forEach(product => {
                html += `
                    <div class="product-card" data-product-id="${product.id}">
                        <div class="product-header">
                            <div class="product-name">${product.name}</div>
                            <div class="product-price">$${(product.price || 0).toFixed(2)}</div>
                        </div>
                        <div class="quantity-grid">
                `;
                
                const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                days.forEach((shortDay, index) => {
                    const dayNumber = index === 6 ? 7 : index + 1; // Sunday is 7
                    const isDeliveryDay = deliveryDays.includes(dayNumber);
                    const quantity = window.existingOrders[customerId]?.[product.id]?.[dayNumber] || 0;
                    
                    html += `
                        <div class="quantity-input-container ${isDeliveryDay ? 'delivery-day' : 'non-delivery-day'}" data-day="${dayNumber}">
                            <label>${shortDay}</label>
                            <input type="number" 
                                   id="qty_${customerId}_${product.id}_${dayNumber}" 
                                   class="quantity-input" 
                                   data-customer-id="${customerId}" 
                                   data-product-id="${product.id}" 
                                   data-day="${dayNumber}" 
                                   data-product-name="${product.name}"
                                   value="${quantity}" 
                                   min="0"
                                   ${!isDeliveryDay ? 'disabled' : ''}>
                        </div>
                    `;
                });
                
                html += `
                        </div>
                    </div>
                `;
            });
            
            html += `
                        </div>
                    </div>
                </div>
            `;
        }
        
        html += `
                </div>
            </div>
        `;
    }
    
    return html;
}

// Default quantity field change handler
document.addEventListener('DOMContentLoaded', function() {
    // Initialize filters
    initializeFilters();
    
    // Handle default quantity field changes
    document.querySelectorAll('.default-quantity-field').forEach(input => {
        input.addEventListener('change', function() {
            const productId = this.dataset.productId;
            const day = this.dataset.day;
            const quantity = this.value;
            
            fetch('bread_distribution.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=update_default&product_id=${productId}&day=${day}&quantity=${quantity}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('Default quantity updated successfully');
                    // Update day totals
                    updateDayTotals();
                } else {
                    console.error('Error updating default quantity:', data.error);
                    alert('Error updating default quantity: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error updating default quantity:', error);
                alert('Error updating default quantity: ' + error.message);
            });
        });
    });
    
    // Initialize filter functionality
    function initializeFilters() {
        // Day filter buttons
        document.querySelectorAll('.day-filter-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const day = this.dataset.day;
                filterByDay(day);
                
                // Update active state
                document.querySelectorAll('.day-filter-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
            });
        });
        
        // Product filter dropdown
        document.getElementById('product-filter-select').addEventListener('change', function() {
            const productId = this.value;
            filterByProduct(productId);
        });
        
        // Set initial active state
        document.querySelector('.day-filter-btn[data-day="all"]').classList.add('active');
    }
    
    // Filter by day
    function filterByDay(day) {
        const rows = document.querySelectorAll('.grid-row');
        const headers = document.querySelectorAll('.grid-header > div, .product-line-header > div');
        
        if (day === 'all') {
            // Show all
            rows.forEach(row => row.style.display = 'grid');
            headers.forEach(header => header.style.display = 'block');
        } else {
            // Hide all columns except product name and selected day
            const dayIndex = parseInt(day) + 1; // +1 because first column is product name
            
            headers.forEach((header, index) => {
                if (index === 0 || index === dayIndex) {
                    header.style.display = 'block';
                } else {
                    header.style.display = 'none';
                }
            });
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('div');
                cells.forEach((cell, index) => {
                    if (index === 0 || index === dayIndex) {
                        cell.style.display = 'block';
                    } else {
                        cell.style.display = 'none';
                    }
                });
            });
        }
    }
    
    // Filter by product
    function filterByProduct(productId) {
        const rows = document.querySelectorAll('.grid-row');
        
        if (productId === 'all') {
            // Show all products
            rows.forEach(row => row.style.display = 'grid');
        } else {
            // Show only selected product
            rows.forEach(row => {
                const productNameCell = row.querySelector('.product-name');
                if (productNameCell && productNameCell.dataset.productId === productId) {
                    row.style.display = 'grid';
                } else {
                    row.style.display = 'none';
                }
            });
        }
    }
    
    // Function to update day totals
    function updateDayTotals() {
        const dayCards = document.querySelectorAll('.day-total-card');
        const dayColumns = [
            'default_quantity_sunday',
            'default_quantity_monday', 
            'default_quantity_tuesday',
            'default_quantity_wednesday',
            'default_quantity_thursday',
            'default_quantity_friday',
            'default_quantity_saturday'
        ];
        const dayNumbers = [7, 1, 2, 3, 4, 5, 6];
        
        // Recalculate totals for each day
        dayColumns.forEach((column, index) => {
            let totalQuantity = 0;
            let productCount = 0;
            
            // Get all inputs for this day
            const inputs = document.querySelectorAll(`input[data-day="${dayNumbers[index]}"]`);
            inputs.forEach(input => {
                const quantity = parseInt(input.value) || 0;
                if (quantity > 0) {
                    totalQuantity += quantity;
                    productCount++;
                }
            });
            
            // Update the display
            if (dayCards[index]) {
                const totalElement = dayCards[index].querySelector('.total-quantity');
                const countElement = dayCards[index].querySelector('.total-products');
                if (totalElement) totalElement.textContent = totalQuantity.toLocaleString();
                if (countElement) countElement.textContent = productCount + ' products';
            }
        });
    }
    
    // Handle save distribution button
    document.getElementById('save-distribution').addEventListener('click', function() {
        const updates = [];
        
        // Collect all quantity changes
        document.querySelectorAll('input[type="number"][data-customer-id][data-product-id][data-day]:not(.default-quantity-field)').forEach(input => {
            const customerId = input.dataset.customerId;
            const productId = input.dataset.productId;
            const dayOfWeek = input.dataset.day;
            const quantity = parseInt(input.value) || 0;
            
            updates.push({
                customer_id: customerId,
                product_id: productId,
                day_of_week: dayOfWeek,
                quantity: quantity
            });
        });
        
        if (updates.length === 0) {
            alert('No changes to save');
            return;
        }
        
        // Show saving status
        const saveStatus = document.getElementById('save-status');
        saveStatus.textContent = 'Saving...';
        
        fetch('bread_distribution.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=save_distribution&updates=${JSON.stringify(updates)}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                saveStatus.textContent = data.message || 'Distribution saved successfully!';
                saveStatus.style.color = 'green';
                
                // Update existing orders in memory
                updates.forEach(update => {
                    if (!window.existingOrders[update.customer_id]) {
                        window.existingOrders[update.customer_id] = {};
                    }
                    if (!window.existingOrders[update.customer_id][update.product_id]) {
                        window.existingOrders[update.customer_id][update.product_id] = {};
                    }
                    window.existingOrders[update.customer_id][update.product_id][update.day_of_week] = update.quantity;
                });
            } else {
                saveStatus.textContent = 'Error: ' + (data.error || 'Unknown error');
                saveStatus.style.color = 'red';
            }
        })
        .catch(error => {
            saveStatus.textContent = 'Error: ' + error.message;
            saveStatus.style.color = 'red';
        });
    });
});
</script>

<?php include 'includes/footer.php'; ?>
</body>
</html>
