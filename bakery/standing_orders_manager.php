<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'save_order':
                $customerId = (int)$_POST['customer_id'];
                $productId = (int)$_POST['product_id'];
                $dayOfWeek = (int)$_POST['day_of_week'];
                $quantity = (int)$_POST['quantity'];
                
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
                
                echo json_encode(['success' => true]);
                break;
                
            case 'bulk_save':
                $updates = json_decode($_POST['updates'], true);
                $db->beginTransaction();
                
                foreach ($updates as $update) {
                    $customerId = (int)$update['customer_id'];
                    $productId = (int)$update['product_id'];
                    $dayOfWeek = (int)$update['day_of_week'];
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
                echo json_encode(['success' => true, 'updated' => count($updates)]);
                break;
                
            case 'load_customer_orders':
                // AJAX endpoint for lazy loading customer order data
                $customerId = (int)$_POST['customer_id'];
                
                $orders = $db->prepare("
                    SELECT 
                        so.product_id, 
                        so.day_of_week, 
                        so.quantity,
                        p.name as product_name,
                        p.price
                    FROM standing_orders so
                    JOIN products p ON so.product_id = p.id
                    WHERE so.customer_id = ?
                    ORDER BY so.day_of_week, p.name
                ");
                $orders->execute([$customerId]);
                $orderData = $orders->fetchAll();
                
                $formattedOrders = [];
                foreach ($orderData as $order) {
                    if (!isset($formattedOrders[$order['product_id']])) {
                        $formattedOrders[$order['product_id']] = [];
                    }
                    $formattedOrders[$order['product_id']][$order['day_of_week']] = [
                        'quantity' => $order['quantity'],
                        'product_name' => $order['product_name'],
                        'price' => $order['price']
                    ];
                }
                
                echo json_encode(['success' => true, 'orders' => $formattedOrders]);
                break;
                
            case 'check_performance':
                // AJAX endpoint to check database performance
                $startTime = microtime(true);
                
                // Check for important indexes
                $indexChecks = [
                    'standing_orders' => "SHOW INDEX FROM standing_orders WHERE Key_name = 'unique_order'",
                    'standing_routes' => "SHOW INDEX FROM standing_routes WHERE Key_name = 'customer_id'",
                    'products' => "SHOW INDEX FROM products WHERE Key_name = 'products_ibfk_1'",
                    'dough_types' => "SHOW INDEX FROM dough_types WHERE Key_name = 'idx_dough_types_product_line'"
                ];
                
                $missingIndexes = [];
                foreach ($indexChecks as $table => $checkSql) {
                    $result = $db->query($checkSql);
                    if ($result->rowCount() == 0) {
                        $missingIndexes[] = $table;
                    }
                }
                
                $loadTime = number_format((microtime(true) - $startTime) * 1000, 2);
                
                echo json_encode([
                    'success' => true,
                    'load_time' => $loadTime,
                    'missing_indexes' => $missingIndexes,
                    'recommendation' => count($missingIndexes) > 0 ? 'Database indexes missing - contact administrator' : 'Database optimized'
                ]);
                break;
                
            case 'diagnostic_routes':
                // AJAX endpoint for route diagnostics
                $diagnostics = [];
                
                // Check standing_routes table
                $routesQuery = $db->query("
                    SELECT 
                        COUNT(*) as total_routes,
                        COUNT(DISTINCT customer_id) as customers_with_routes,
                        COUNT(DISTINCT day_of_week) as unique_days,
                        GROUP_CONCAT(DISTINCT day_of_week ORDER BY day_of_week) as all_days
                    FROM standing_routes
                ");
                $routesData = $routesQuery->fetch();
                $diagnostics['standing_routes'] = $routesData;
                
                // Check customer route data from main query
                $customerQuery = $db->query("
                    SELECT 
                        c.id,
                        c.name,
                        GROUP_CONCAT(DISTINCT sr.day_of_week ORDER BY sr.day_of_week) as route_days,
                        COUNT(DISTINCT sr.day_of_week) as route_count
                    FROM customers c
                    LEFT JOIN standing_routes sr ON c.id = sr.customer_id
                    GROUP BY c.id, c.name
                    HAVING route_count > 0
                    LIMIT 10
                ");
                $sampleCustomers = $customerQuery->fetchAll();
                $diagnostics['sample_customers_with_routes'] = $sampleCustomers;
                
                // Check if table exists and has data
                $tableCheck = $db->query("SELECT COUNT(*) as count FROM standing_routes")->fetch();
                $diagnostics['routes_table_count'] = $tableCheck['count'];
                
                echo json_encode([
                    'success' => true,
                    'diagnostics' => $diagnostics
                ]);
                break;
                
            case 'copy_orders':
                $sourceCustomerId = (int)$_POST['source_customer_id'];
                $targetCustomerId = (int)$_POST['target_customer_id'];
                $selectedDays = json_decode($_POST['selected_days'], true);
                
                $db->beginTransaction();
                
                // Get orders from source customer for selected days
                $stmt = $db->prepare("
                    SELECT product_id, day_of_week, quantity 
                    FROM standing_orders 
                    WHERE customer_id = ? AND day_of_week IN (" . implode(',', array_fill(0, count($selectedDays), '?')) . ")
                ");
                $stmt->execute(array_merge([$sourceCustomerId], $selectedDays));
                $sourceOrders = $stmt->fetchAll();
                
                $copiedCount = 0;
                foreach ($sourceOrders as $order) {
                    $stmt = $db->prepare("
                        INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE quantity = ?
                    ");
                    $stmt->execute([
                        $targetCustomerId, 
                        $order['product_id'], 
                        $order['day_of_week'], 
                        $order['quantity'],
                        $order['quantity']
                    ]);
                    $copiedCount++;
                }
                
                $db->commit();
                echo json_encode(['success' => true, 'copied' => $copiedCount]);
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

require_once 'includes/header.php';
require_once 'includes/nav.php';

// Fetch data
try {
    // PERFORMANCE OPTIMIZATION: Single optimized query to get all data efficiently
    $startTime = microtime(true);
    
    // Get all customers with route and order counts in one optimized query
    $customers = $db->query("
        SELECT 
            c.id, 
            c.name, 
            c.address,
            c.zone,
            COUNT(DISTINCT so.id) as order_count,
            COUNT(DISTINCT sr.id) as route_count,
            GROUP_CONCAT(DISTINCT sr.day_of_week ORDER BY sr.day_of_week) as route_days
        FROM customers c
        LEFT JOIN standing_orders so ON c.id = so.customer_id
        LEFT JOIN standing_routes sr ON c.id = sr.customer_id
        GROUP BY c.id, c.name, c.address, c.zone
        ORDER BY 
            CASE WHEN c.zone IS NULL OR c.zone = '' THEN 'ZZZ' ELSE c.zone END,
            c.name
    ")->fetchAll();
    
    // PERFORMANCE OPTIMIZATION: Single query for all products with full hierarchy
    $products = $db->query("
        SELECT 
            p.id, 
            p.name,
            p.price,
            p.weight_grams,
            p.description,
            p.dough_type_id,
            dt.name as dough_type_name,
            dt.description as dough_type_description,
            pl.id as product_line_id,
            pl.name as product_line_name,
            pl.description as product_line_description,
            pl.color_code as product_line_color,
            pl.sort_order as product_line_sort
        FROM products p
        LEFT JOIN dough_types dt ON p.dough_type_id = dt.id
        LEFT JOIN product_lines pl ON dt.product_line_id = pl.id
        ORDER BY 
            CASE WHEN pl.sort_order IS NULL THEN 999 ELSE pl.sort_order END,
            CASE WHEN pl.name IS NULL THEN 'ZZZ_Unclassified' ELSE pl.name END,
            dt.name,
            p.name
    ")->fetchAll();
    
    // PERFORMANCE OPTIMIZATION: Process customer routes from the main query
    $customerRoutes = [];
    foreach ($customers as $customer) {
        if ($customer['route_days']) {
            $customerRoutes[$customer['id']] = array_map('intval', explode(',', $customer['route_days']));
            sort($customerRoutes[$customer['id']]);
        } else {
            $customerRoutes[$customer['id']] = [];
        }
    }
    
    // DEBUG: Log route processing results
    error_log("Total customers: " . count($customers));
    error_log("Customers with route_days: " . count(array_filter($customers, function($c) { return !empty($c['route_days']); })));
    error_log("Processed customerRoutes: " . count(array_filter($customerRoutes, function($routes) { return !empty($routes); })));
    
    // Separate customers based on routes (fix: use global $customerRoutes)
    $customersWithRoutes = array_filter($customers, function($customer) use ($customerRoutes) {
        return !empty($customerRoutes[$customer['id']]);
    });
    
    $customersWithoutRoutes = array_filter($customers, function($customer) use ($customerRoutes) {
        return empty($customerRoutes[$customer['id']]);
    });
    
    // For backward compatibility
    $customersWithOrders = $customersWithRoutes;
    $customersWithoutOrders = $customersWithoutRoutes;
    
    // Group products by product line
    $productsByProductLine = [];
    foreach ($products as $product) {
        $productLine = $product['product_line_name'] ?: 'Unclassified';
        if (!isset($productsByProductLine[$productLine])) {
            $productsByProductLine[$productLine] = [
                'id' => $product['product_line_id'],
                'description' => $product['product_line_description'] ?: '',
                'color_code' => $product['product_line_color'] ?: '#6c757d',
                'sort_order' => $product['product_line_sort'] ?: 999,
                'dough_types' => [],
                'products' => []
            ];
        }
        
        // Group by dough type within product line for better organization
        $doughType = $product['dough_type_name'] ?: 'Unassigned Dough Type';
        if (!isset($productsByProductLine[$productLine]['dough_types'][$doughType])) {
            $productsByProductLine[$productLine]['dough_types'][$doughType] = [
                'description' => $product['dough_type_description'] ?: '',
                'products' => []
            ];
        }
        
        $productsByProductLine[$productLine]['dough_types'][$doughType]['products'][] = $product;
        $productsByProductLine[$productLine]['products'][] = $product;
    }
    
    // PERFORMANCE OPTIMIZATION: Load existing orders only when needed (lazy loading via AJAX would be better)
    $existingOrders = [];
    if (count($customers) <= 100) { // Only load for reasonable dataset sizes
        $orders = $db->query("
            SELECT 
                so.customer_id, 
                so.product_id, 
                so.day_of_week, 
                so.quantity,
                p.name as product_name,
                p.price
            FROM standing_orders so
            JOIN products p ON so.product_id = p.id
            ORDER BY so.customer_id, so.day_of_week, p.name
        ")->fetchAll();
        
        foreach ($orders as $order) {
            $customerId = $order['customer_id'];
            
            // Store order details
            if (!isset($existingOrders[$customerId])) {
                $existingOrders[$customerId] = [];
            }
            if (!isset($existingOrders[$customerId][$order['product_id']])) {
                $existingOrders[$customerId][$order['product_id']] = [];
            }
            $existingOrders[$customerId][$order['product_id']][$order['day_of_week']] = [
                'quantity' => $order['quantity'],
                'product_name' => $order['product_name'],
                'price' => $order['price']
            ];
        }
    }
    
    $loadTime = number_format((microtime(true) - $startTime) * 1000, 2);
    
    // PERFORMANCE LOG: Add performance monitoring
    error_log("Standing Orders Manager Load Time: {$loadTime}ms for " . count($customers) . " customers and " . count($products) . " products");
    
} catch (Exception $e) {
    echo '<div class="error">Error loading data: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}

$page_title = 'Standing Orders Manager';
$days = [
    1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 
    5 => 'Fri', 6 => 'Sat', 7 => 'Sun'
];
?>

<div class="som-container">
    <div class="som-header">
        <h1>🗓️ Standing Orders Manager</h1>
        <div class="som-actions">
            <div id="performance-info" class="performance-info" style="display: none;">
                <span class="performance-icon">⚡</span>
                <span class="performance-text">Loading...</span>
            </div>
            <div id="auto-save-status" class="auto-save-status">
                <span class="status-icon">💾</span>
                <span class="status-text">Auto-save enabled</span>
            </div>
            <button id="performance-check" class="btn btn-info">⚡ Check Performance</button>
            <button id="diagnostic-routes" class="btn btn-warning">🔍 Check Routes</button>
            <button id="bulk-save" class="btn btn-success" disabled>💾 Save Changes</button>
            <button id="view-toggle" class="btn btn-secondary">👁️ Compact View</button>
            <button id="filter-toggle" class="btn btn-primary">🔍 Filters</button>
            <button id="changes-toggle" class="btn btn-info">📊 Changes</button>
        </div>
    </div>
    
    <!-- Changes Summary Panel -->
    <div id="changes-panel" class="som-changes" style="display: none;">
        <div class="changes-header">
            <h3>📊 Pending Changes Summary</h3>
            <span id="changes-count">0 changes</span>
        </div>
        <div id="changes-list" class="changes-list">
            <p class="no-changes">No changes pending</p>
        </div>
    </div>
    
    <!-- Filters Panel -->
    <div id="filters-panel" class="som-filters" style="display: none;">
        <div class="filter-section">
            <label>Customer:</label>
            <select id="customer-filter">
                <option value="">All Customers</option>
                <optgroup label="Customers with Routes">
                    <?php foreach ($customersWithOrders as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>">
                            <?php echo htmlspecialchars($customer['name']); ?>
                            (<?php echo count($customerRoutes[$customer['id']] ?? []); ?> route days, <?php echo $customer['order_count']; ?> orders)
                        </option>
                    <?php endforeach; ?>
                </optgroup>
                <optgroup label="Customers without Routes">
                    <?php foreach ($customersWithoutOrders as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>">
                            <?php echo htmlspecialchars($customer['name']); ?>
                            (No delivery routes)
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
        </div>
        
        <div class="filter-section">
            <label>Product Line:</label>
            <select id="product-line-filter">
                <option value="">All Product Lines</option>
                <?php foreach ($productsByProductLine as $productLine => $data): ?>
                    <option value="<?php echo htmlspecialchars($productLine); ?>">
                        <?php echo htmlspecialchars($productLine); ?>
                        (<?php echo count($data['products']); ?> products)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="filter-section">
            <label>Day:</label>
            <select id="day-filter">
                <option value="">All Days</option>
                <?php foreach ($days as $num => $name): ?>
                    <option value="<?php echo $num; ?>"><?php echo $name; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <button id="clear-filters" class="btn btn-sm btn-outline">Clear All</button>
    </div>
    
    <!-- Summary Stats -->
    <div class="som-stats">
        <div class="stat-card">
            <h3><?php echo count($customersWithOrders); ?></h3>
            <span>Customers with Routes</span>
        </div>
        <div class="stat-card">
            <h3><?php echo count($customersWithoutOrders); ?></h3>
            <span>Customers without Routes</span>
        </div>
        <div class="stat-card">
            <h3><?php echo array_sum(array_column($customers, 'order_count')); ?></h3>
            <span>Total Orders</span>
        </div>
        <div class="stat-card">
            <h3><?php echo count($productsByProductLine); ?></h3>
            <span>Product Lines</span>
        </div>
        <div class="stat-card">
            <h3><?php echo array_sum(array_map(function($pl) { return count($pl['products']); }, $productsByProductLine)); ?></h3>
            <span>Products</span>
        </div>
    </div>
    
    <!-- DEBUG: Performance and Route Information -->
    <?php if (count($customers) > 50): ?>
        <div class="som-debug-info" style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <h4>📊 Large Dataset Performance Mode</h4>
            <p><strong>Total Customers:</strong> <?php echo count($customers); ?> 
               | <strong>With Routes:</strong> <?php echo count($customersWithRoutes); ?> 
               | <strong>Without Routes:</strong> <?php echo count($customersWithoutRoutes); ?></p>
            <p><strong>Auto-collapsed for performance:</strong> Customer sections are collapsed by default. Click headers to expand individual customers.</p>
            <button id="expand-all-customers" class="btn btn-sm btn-primary">🔄 Expand All Customers</button>
            <button id="collapse-all-customers" class="btn btn-sm btn-secondary">📁 Collapse All Customers</button>
        </div>
    <?php endif; ?>
    
    <!-- Main Content -->
    <div class="som-content">
        <!-- Customers Without Orders Section -->
        <?php if (!empty($customersWithoutOrders)): ?>
            <div class="no-orders-section">
                <div class="no-orders-header">
                    <h2>🆕 Customers Without Delivery Routes</h2>
                    <span class="customer-count"><?php echo count($customersWithoutOrders); ?> customers</span>
                </div>
                
                <?php 
                // Group customers without orders by zone
                $customersWithoutOrdersByZone = [];
                foreach ($customersWithoutOrders as $customer) {
                    $zone = $customer['zone'] ?: 'No Zone';
                    if (!isset($customersWithoutOrdersByZone[$zone])) {
                        $customersWithoutOrdersByZone[$zone] = [];
                    }
                    $customersWithoutOrdersByZone[$zone][] = $customer;
                }
                
                // Sort customers within each zone by order count (descending)
                foreach ($customersWithoutOrdersByZone as $zone => &$zoneCustomers) {
                    usort($zoneCustomers, function($a, $b) {
                        return $b['order_count'] - $a['order_count'];
                    });
                }
                
                foreach ($customersWithoutOrdersByZone as $zone => $zoneCustomers): 
                ?>
                    <div class="zone-section no-orders-zone">
                        <div class="zone-header zone-toggle" data-zone="no-orders-<?php echo htmlspecialchars($zone); ?>">
                            <div class="zone-title">
                                <h3>🏷️ Zone: <?php echo htmlspecialchars($zone); ?></h3>
                                <span class="zone-count"><?php echo count($zoneCustomers); ?> customers</span>
                            </div>
                            <button class="zone-toggle-btn">
                                <span class="zone-toggle-icon">▼</span>
                            </button>
                        </div>
                        
                        <div class="zone-customers" data-zone="no-orders-<?php echo htmlspecialchars($zone); ?>"><?php 
                            // Show customer summary cards that can be expanded
                            foreach ($zoneCustomers as $customer):
                                $customerActiveDays = $customerRoutes[$customer['id']] ?? [];
                        ?>
                            <div class="customer-summary-card" data-customer-id="<?php echo $customer['id']; ?>">
                                <div class="customer-summary-header" onclick="toggleCustomerDetails(this)">
                                    <div class="customer-summary-info">
                                        <h4><?php echo htmlspecialchars($customer['name']); ?></h4>
                                        <span class="customer-summary-details">
                                            <?php if ($customer['address']): ?>
                                                📍 <?php echo htmlspecialchars($customer['address']); ?>
                                            <?php endif; ?>
                                            | 📦 <?php echo $customer['order_count']; ?> orders
                                            <?php if (!empty($customerActiveDays)): ?>
                                                | 📅 <?php echo implode(', ', array_map(function($d) use ($days) { return $days[$d]; }, $customerActiveDays)); ?>
                                            <?php else: ?>
                                                | 📅 No delivery routes
                                            <?php endif; ?>
                                            <span class="no-orders-badge">📋 Ready for Orders</span>
                                        </span>
                                    </div>
                                    <div class="customer-summary-actions">
                                        <button class="btn btn-sm btn-primary copy-all-btn" 
                                                data-target-customer="<?php echo $customer['id']; ?>"
                                                data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                                title="Copy all orders from another customer"
                                                onclick="event.stopPropagation()">
                                            📋 Quick Copy
                                        </button>
                                        <span class="expand-toggle">▼</span>
                                    </div>
                                </div>
                                
                                <div class="customer-full-details" style="display: none;">
                        <?php 
                        // Only show full details if customer has route days
                        if (!empty($customerActiveDays)): 
                        ?>
                                    <div class="days-header">
                                        <div class="product-column">Product</div>
                                        <?php foreach ($customerActiveDays as $dayNum): ?>
                                            <div class="day-column" data-day="<?php echo $dayNum; ?>">
                                                <?php echo $days[$dayNum]; ?>
                                                <button class="day-copy-btn" 
                                                        data-target-customer="<?php echo $customer['id']; ?>"
                                                        data-target-day="<?php echo $dayNum; ?>"
                                                        data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                                        data-day-name="<?php echo $days[$dayNum]; ?>"
                                                        title="Copy orders from another customer for <?php echo $days[$dayNum]; ?>">
                                                    📋
                                                </button>
                                            </div>
                                        <?php endforeach; ?>
                                        <div class="total-column">
                                            Total
                                            <button class="btn btn-sm btn-primary copy-all-btn" 
                                                    data-target-customer="<?php echo $customer['id']; ?>"
                                                    data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                                    title="Copy all orders from another customer">
                                                📋 Copy All
                                            </button>
                                        </div>
                                    </div>
                            
                                    <?php foreach ($productsByProductLine as $productLine => $lineData): ?>
                                        <div class="product-line-section" data-product-line="<?php echo htmlspecialchars($productLine); ?>">
                                            <div class="product-line-header" onclick="toggleProductLine(this)">
                                                <div class="product-line-info">
                                                    <div class="product-line-title">
                                                        <div class="product-line-color-indicator" style="background-color: <?php echo $lineData['color_code']; ?>"></div>
                                                        <h4><?php echo htmlspecialchars($productLine); ?></h4>
                                                    </div>
                                                    <?php if ($lineData['description']): ?>
                                                        <span class="product-line-description"><?php echo htmlspecialchars($lineData['description']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="product-line-stats">
                                                    <span class="product-count"><?php echo count($lineData['products']); ?> products</span>
                                                    <span class="dough-type-count"><?php echo count($lineData['dough_types']); ?> dough types</span>
                                                    <span class="toggle-arrow">▼</span>
                                                </div>
                                            </div>
                                            
                                            <div class="product-line-container">
                                                <?php foreach ($lineData['dough_types'] as $doughType => $typeData): ?>
                                                    <div class="dough-type-subsection">
                                                        <div class="dough-type-subheader" onclick="toggleDoughTypeSubsection(this)">
                                                            <span class="dough-type-name"><?php echo htmlspecialchars($doughType); ?></span>
                                                            <div class="dough-type-meta">
                                                                <span class="product-count"><?php echo count($typeData['products']); ?> products</span>
                                                                <span class="toggle-arrow-small">▼</span>
                                                            </div>
                                                        </div>
                                                        <div class="dough-products-container">
                                                            <?php foreach ($typeData['products'] as $product): 
                                                    $hasOrders = isset($existingOrders[$customer['id']][$product['id']]);
                                                ?>
                                                    <div class="product-row <?php echo $hasOrders ? 'has-orders' : 'no-orders'; ?>" data-product-id="<?php echo $product['id']; ?>">
                                                        <div class="product-info">
                                                            <div class="product-main">
                                                                <span class="product-name"><?php echo htmlspecialchars($product['name']); ?></span>
                                                                <span class="new-badge">NEW</span>
                                                            </div>
                                                            <div class="product-meta">
                                                                <?php if ($product['price']): ?>
                                                                    <span class="price">$<?php echo number_format($product['price'], 2); ?></span>
                                                                <?php endif; ?>
                                                                <?php if ($product['weight_grams']): ?>
                                                                    <span class="weight"><?php echo $product['weight_grams']; ?>g</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        
                                                        <?php 
                                                        $customerTotal = 0;
                                                        foreach ($customerActiveDays as $dayNum): 
                                                            $quantity = 0;
                                                            if (isset($existingOrders[$customer['id']][$product['id']][$dayNum])) {
                                                                $quantity = $existingOrders[$customer['id']][$product['id']][$dayNum]['quantity'];
                                                                $customerTotal += $quantity;
                                                            }
                                                        ?>
                                                            <div class="quantity-cell">
                                                                <input type="number" 
                                                                       class="quantity-input" 
                                                                       value="<?php echo $quantity; ?>"
                                                                       min="0"
                                                                       data-customer-id="<?php echo $customer['id']; ?>"
                                                                       data-product-id="<?php echo $product['id']; ?>"
                                                                       data-day="<?php echo $dayNum; ?>"
                                                                       data-original="<?php echo $quantity; ?>"
                                                                       placeholder="0">
                                                            </div>
                                                        <?php endforeach; ?>
                                                        
                                                        <div class="total-cell">
                                                            <div class="total-info">
                                                                <span class="total-qty"><?php echo $customerTotal; ?></span>
                                                                <?php if ($product['price'] && $customerTotal > 0): ?>
                                                                    <span class="total-value">$<?php echo number_format($customerTotal * $product['price'], 2); ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <?php if ($customerTotal > 0): ?>
                                                                <button class="quick-clear" onclick="clearProduct(this)" title="Clear all quantities">
                                                                    <span>×</span>
                                                                </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                                            <?php endforeach; ?>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                        <?php else: ?>
                                    <div class="no-routes-message">
                                        <p>⚠️ This customer has no delivery routes set up. Please configure delivery routes first to manage standing orders.</p>
                                    </div>
                        <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="section-divider"></div>
        <?php endif; ?>
        
        <!-- Customers With Orders Section -->
        <div class="with-orders-section">
            <div class="with-orders-header">
                <h2>📋 Customers With Delivery Routes</h2>
                <span class="customer-count"><?php echo count($customersWithOrders); ?> customers</span>
            </div>
            
            <?php 
            // Group customers with orders by zone
            $customersWithOrdersByZone = [];
            foreach ($customersWithOrders as $customer) {
                $zone = $customer['zone'] ?: 'No Zone';
                if (!isset($customersWithOrdersByZone[$zone])) {
                    $customersWithOrdersByZone[$zone] = [];
                }
                $customersWithOrdersByZone[$zone][] = $customer;
            }
            
            // Sort customers within each zone by order count (descending)
            foreach ($customersWithOrdersByZone as $zone => &$zoneCustomers) {
                usort($zoneCustomers, function($a, $b) {
                    return $b['order_count'] - $a['order_count'];
                });
            }
            
            foreach ($customersWithOrdersByZone as $zone => $zoneCustomers): 
        ?>
            <div class="zone-section">
                <div class="zone-header zone-toggle" data-zone="with-orders-<?php echo htmlspecialchars($zone); ?>">
                    <div class="zone-title">
                        <h2>🏷️ Zone: <?php echo htmlspecialchars($zone); ?></h2>
                        <span class="zone-count"><?php echo count($zoneCustomers); ?> customers</span>
                    </div>
                    <button class="zone-toggle-btn">
                        <span class="zone-toggle-icon">▼</span>
                    </button>
                </div>
                
                <div class="zone-customers" data-zone="with-orders-<?php echo htmlspecialchars($zone); ?>">
                
                <?php foreach ($zoneCustomers as $customer): 
                    $customerActiveDays = $customerRoutes[$customer['id']] ?? [];
                    if (empty($customerActiveDays)) continue; // Skip customers with no route days
                ?>
                    <div class="customer-section" 
                         data-customer-id="<?php echo $customer['id']; ?>"
                         data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                         data-zone="<?php echo htmlspecialchars($zone); ?>">
                        
                        <div class="customer-header">
                            <div class="customer-info">
                                <h3><?php echo htmlspecialchars($customer['name']); ?></h3>
                                <span class="customer-details">
                                    <?php if ($customer['address']): ?>
                                        📍 <?php echo htmlspecialchars($customer['address']); ?>
                                    <?php endif; ?>
                                    | 📦 <?php echo $customer['order_count']; ?> orders
                                    | 📅 <?php echo implode(', ', array_map(function($d) use ($days) { return $days[$d]; }, $customerActiveDays)); ?>
                                </span>
                            </div>
                            <button class="customer-toggle" data-customer-id="<?php echo $customer['id']; ?>">
                                <span class="toggle-icon">▼</span>
                            </button>
                        </div>
                        
                        <div class="customer-orders" data-customer-id="<?php echo $customer['id']; ?>">
                            <div class="days-header">
                                <div class="product-column">Product</div>
                                <?php foreach ($customerActiveDays as $dayNum): ?>
                                    <div class="day-column" data-day="<?php echo $dayNum; ?>">
                                        <?php echo $days[$dayNum]; ?>
                                        <button class="day-copy-btn" 
                                                data-target-customer="<?php echo $customer['id']; ?>"
                                                data-target-day="<?php echo $dayNum; ?>"
                                                data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                                data-day-name="<?php echo $days[$dayNum]; ?>"
                                                title="Copy orders from another customer for <?php echo $days[$dayNum]; ?>">
                                            📋
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                                <div class="total-column">
                                    Total
                                    <button class="btn btn-sm btn-primary copy-all-btn" 
                                            data-target-customer="<?php echo $customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                            title="Copy all orders from another customer">
                                        📋 Copy All
                                    </button>
                                </div>
                            </div>
                    
                            <?php foreach ($productsByProductLine as $productLine => $lineData): ?>
                                <div class="product-line-section" data-product-line="<?php echo htmlspecialchars($productLine); ?>">
                                    <div class="product-line-header" onclick="toggleProductLine(this)">
                                        <div class="product-line-info">
                                            <div class="product-line-title">
                                                <div class="product-line-color-indicator" style="background-color: <?php echo $lineData['color_code']; ?>"></div>
                                                <h4><?php echo htmlspecialchars($productLine); ?></h4>
                                            </div>
                                            <?php if ($lineData['description']): ?>
                                                <span class="product-line-description"><?php echo htmlspecialchars($lineData['description']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="product-line-stats">
                                            <span class="product-count"><?php echo count($lineData['products']); ?> products</span>
                                            <span class="dough-type-count"><?php echo count($lineData['dough_types']); ?> dough types</span>
                                            <span class="toggle-arrow">▼</span>
                                        </div>
                                    </div>
                                    
                                    <div class="product-line-container">
                                        <?php foreach ($lineData['dough_types'] as $doughType => $typeData): ?>
                                            <div class="dough-type-subsection">
                                                <div class="dough-type-subheader" onclick="toggleDoughTypeSubsection(this)">
                                                    <span class="dough-type-name"><?php echo htmlspecialchars($doughType); ?></span>
                                                    <div class="dough-type-meta">
                                                        <span class="product-count"><?php echo count($typeData['products']); ?> products</span>
                                                        <span class="toggle-arrow-small">▼</span>
                                                    </div>
                                                </div>
                                                <div class="dough-products-container">
                                                    <?php foreach ($typeData['products'] as $product): 
                                                        $hasOrders = isset($existingOrders[$customer['id']][$product['id']]);
                                                    ?>
                                                        <div class="product-row <?php echo $hasOrders ? 'has-orders' : 'no-orders'; ?>" data-product-id="<?php echo $product['id']; ?>">
                                                            <div class="product-info">
                                                                <div class="product-main">
                                                                    <span class="product-name"><?php echo htmlspecialchars($product['name']); ?></span>
                                                                    <?php if (!$hasOrders): ?>
                                                                        <span class="new-badge">NEW</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <div class="product-meta">
                                                                    <?php if ($product['price']): ?>
                                                                        <span class="price">$<?php echo number_format($product['price'], 2); ?></span>
                                                                    <?php endif; ?>
                                                                    <?php if ($product['weight_grams']): ?>
                                                                        <span class="weight"><?php echo $product['weight_grams']; ?>g</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                            
                                                            <?php 
                                                            $customerTotal = 0;
                                                            foreach ($customerActiveDays as $dayNum): 
                                                                $quantity = 0;
                                                                if (isset($existingOrders[$customer['id']][$product['id']][$dayNum])) {
                                                                    $quantity = $existingOrders[$customer['id']][$product['id']][$dayNum]['quantity'];
                                                                    $customerTotal += $quantity;
                                                                }
                                                            ?>
                                                                <div class="quantity-cell">
                                                                    <input type="number" 
                                                                           class="quantity-input" 
                                                                           value="<?php echo $quantity; ?>"
                                                                           min="0"
                                                                           data-customer-id="<?php echo $customer['id']; ?>"
                                                                           data-product-id="<?php echo $product['id']; ?>"
                                                                           data-day="<?php echo $dayNum; ?>"
                                                                           data-original="<?php echo $quantity; ?>"
                                                                           placeholder="0">
                                                                </div>
                                                            <?php endforeach; ?>
                                                            
                                                            <div class="total-cell">
                                                                <div class="total-info">
                                                                    <span class="total-qty"><?php echo $customerTotal; ?></span>
                                                                    <?php if ($product['price'] && $customerTotal > 0): ?>
                                                                        <span class="total-value">$<?php echo number_format($customerTotal * $product['price'], 2); ?></span>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <?php if ($customerTotal > 0): ?>
                                                                    <button class="quick-clear" onclick="clearProduct(this)" title="Clear all quantities">
                                                                        <span>×</span>
                                                                    </button>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div> <!-- Close zone-customers -->
            </div>
        <?php endforeach; ?>
        </div> <!-- Close with-orders-section -->
    </div>
</div>

<!-- Copy Orders Modal -->
<div id="copy-orders-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>📋 Copy Orders to <span id="target-customer-name"></span> - <span id="target-day-info"></span></h3>
            <button class="modal-close">&times;</button>
        </div>
        
        <div class="modal-body">
            <div class="source-selection">
                <label for="source-customer-select">Copy from Customer:</label>
                <select id="source-customer-select">
                    <option value="">Select a customer...</option>
                </select>
                <div class="source-info" id="source-info" style="display: none;">
                    <small class="text-muted">Select a customer who has orders on the target day(s)</small>
                </div>
            </div>
            
            <div class="day-selection" id="day-selection" style="display: none;">
                <label id="day-selection-label">Select days to copy:</label>
                <div class="day-checkboxes">
                    <?php foreach ($days as $num => $name): ?>
                        <label class="day-checkbox">
                            <input type="checkbox" value="<?php echo $num; ?>" data-day-name="<?php echo $name; ?>">
                            <?php echo $name; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="preview-section" id="preview-section" style="display: none;">
                <h4>Preview - Orders to be copied:</h4>
                <div id="copy-preview"></div>
            </div>
        </div>
        
        <div class="modal-footer">
            <button id="copy-confirm" class="btn btn-success" disabled>📋 Copy Orders</button>
            <button class="btn btn-secondary modal-cancel">Cancel</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="modal-overlay" style="display: none;"></div>

<style>
.som-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.som-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #e9ecef;
}

.som-header h1 {
    margin: 0;
    color: #2c3e50;
    font-size: 2rem;
}

.som-actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

.auto-save-status {
    background: #e8f5e8;
    border: 1px solid #c3e6c3;
    color: #2d5016;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 0.85rem;
    display: flex;
    align-items: center;
    gap: 5px;
    transition: all 0.3s;
}

.auto-save-status.saving {
    background: #fff3cd;
    border-color: #ffeaa7;
    color: #856404;
}

.auto-save-status.saving .status-icon {
    animation: spin 1s linear infinite;
}

.auto-save-status.success {
    background: #d1edff;
    border-color: #bee5eb;
    color: #0c5460;
}

.auto-save-status.error {
    background: #f8d7da;
    border-color: #f5c6cb;
    color: #721c24;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.btn-primary { background: #007bff; color: white; }
.btn-success { background: #28a745; color: white; }
.btn-secondary { background: #6c757d; color: white; }
.btn-info { background: #17a2b8; color: white; }
.btn-outline { background: transparent; border: 1px solid #dee2e6; color: #495057; }

.btn:hover { transform: translateY(-1px); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
.btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

.som-filters {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    gap: 20px;
    align-items: center;
    flex-wrap: wrap;
}

.filter-section {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.filter-section label {
    font-weight: 500;
    color: #495057;
    font-size: 0.9rem;
}

.filter-section select {
    padding: 5px 10px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    min-width: 150px;
}

.som-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    margin-bottom: 25px;
}

.stat-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    text-align: center;
}

.stat-card h3 {
    margin: 0 0 5px 0;
    font-size: 2rem;
    color: #007bff;
}

.stat-card span {
    color: #6c757d;
    font-size: 0.9rem;
}

.zone-section {
    margin-bottom: 30px;
}

.zone-header {
    background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
    color: white;
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.zone-header h2 {
    margin: 0;
    font-size: 1.4rem;
}

.zone-count {
    background: rgba(255,255,255,0.2);
    padding: 5px 12px;
    border-radius: 15px;
    font-size: 0.9rem;
}

.customer-section {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 15px;
    margin-left: 20px;
    overflow: hidden;
}

.customer-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
}

.customer-info h2 {
    margin: 0 0 5px 0;
    font-size: 1.3rem;
}

.customer-details {
    font-size: 0.9rem;
    opacity: 0.9;
}

.customer-toggle {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    padding: 8px 12px;
    border-radius: 50%;
    cursor: pointer;
    transition: transform 0.2s;
}

.toggle-icon {
    display: inline-block;
    transition: transform 0.2s;
}

.customer-section.collapsed .toggle-icon {
    transform: rotate(-90deg);
}

.customer-orders {
    padding: 20px;
}

.customer-section.collapsed .customer-orders {
    display: none;
}

.days-header {
    display: grid;
    grid-template-columns: 250px repeat(auto-fit, minmax(80px, 1fr)) 120px;
    gap: 10px;
    padding: 10px 0;
    border-bottom: 2px solid #e9ecef;
    font-weight: bold;
    color: #495057;
    margin-bottom: 15px;
}

.day-column {
    text-align: center;
    cursor: pointer;
    padding: 5px;
    border-radius: 4px;
    transition: background-color 0.2s;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 5px;
}

.day-column:hover {
    background-color: #e9ecef;
}

.day-copy-btn {
    background: #007bff;
    color: white;
    border: none;
    border-radius: 3px;
    padding: 2px 5px;
    font-size: 0.7rem;
    cursor: pointer;
    opacity: 0.7;
    transition: all 0.2s;
}

.day-copy-btn:hover {
    opacity: 1;
    transform: scale(1.1);
}

.copy-all-btn {
    font-size: 0.7rem;
    padding: 2px 6px;
    margin-top: 5px;
}

.no-orders-badge {
    background: #17a2b8;
    color: white;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 0.75rem;
    margin-left: 8px;
}

.dough-type-section {
    margin-bottom: 20px;
}

/* Product Line Styles */
.product-line-section {
    margin-bottom: 25px;
    border: 1px solid #dee2e6;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 6px rgba(0,0,0,0.08);
}

.product-line-header {
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
    padding: 15px 20px;
    border-bottom: 2px solid #e9ecef;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.product-line-header:hover {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
}

.product-line-info {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.product-line-title {
    display: flex;
    align-items: center;
    gap: 10px;
}

.product-line-color-indicator {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    border: 2px solid white;
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}

.product-line-title h4 {
    margin: 0;
    color: #2c3e50;
    font-size: 1.2rem;
    font-weight: 600;
}

.product-line-description {
    font-size: 0.85rem;
    color: #6c757d;
    font-style: italic;
}

.product-line-stats {
    display: flex;
    align-items: center;
    gap: 15px;
    color: #495057;
    font-size: 0.9rem;
}

.dough-type-count {
    color: #007bff;
    font-weight: 500;
}

.product-line-container {
    transition: all 0.3s;
    background: #fafbfc;
}

.product-line-section.collapsed .product-line-container {
    display: none;
}

.product-line-section.collapsed .toggle-arrow {
    transform: rotate(-90deg);
}

/* Dough Type Subsection Styles */
.dough-type-subsection {
    margin: 0;
    border-bottom: 1px solid #e9ecef;
}

.dough-type-subsection:last-child {
    border-bottom: none;
}

.dough-type-subheader {
    background: linear-gradient(135deg, #f1f3f4 0%, #e8eaed 100%);
    padding: 10px 20px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-left: 3px solid #007bff;
}

.dough-type-subheader:hover {
    background: linear-gradient(135deg, #e8eaed 0%, #dadce0 100%);
}

.dough-type-name {
    font-weight: 500;
    color: #2c3e50;
    font-size: 1rem;
}

.dough-type-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #6c757d;
    font-size: 0.85rem;
}

.toggle-arrow-small {
    transition: transform 0.2s;
    font-weight: bold;
    color: #495057;
}

.dough-type-subsection.collapsed .toggle-arrow-small {
    transform: rotate(-90deg);
}

.dough-products-container {
    transition: all 0.3s;
    background: white;
    padding: 0 10px 10px 10px;
}

.dough-type-subsection.collapsed .dough-products-container {
    display: none;
}

.som-changes {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
}

.changes-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.changes-header h3 {
    margin: 0;
    color: #856404;
}

.changes-count {
    background: #ffc107;
    color: white;
    padding: 5px 10px;
    border-radius: 15px;
    font-size: 0.9rem;
    font-weight: bold;
}

.changes-list {
    max-height: 200px;
    overflow-y: auto;
}

.change-item {
    background: white;
    padding: 8px 12px;
    border-radius: 4px;
    margin-bottom: 5px;
    border-left: 3px solid #ffc107;
    font-size: 0.9rem;
}

.no-changes {
    color: #6c757d;
    font-style: italic;
    margin: 0;
}

.dough-type-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 12px 15px;
    border-radius: 8px;
    margin-bottom: 10px;
    border-left: 4px solid #007bff;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.dough-type-header:hover {
    background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
}

.dough-type-header.collapsed .toggle-arrow {
    transform: rotate(-90deg);
}

.dough-info h4 {
    margin: 0 0 3px 0;
    color: #2c3e50;
    font-size: 1.1rem;
}

.dough-description {
    font-size: 0.85rem;
    color: #6c757d;
    font-style: italic;
}

.dough-stats {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #6c757d;
    font-size: 0.9rem;
}

.toggle-arrow {
    transition: transform 0.2s;
    font-weight: bold;
}

.products-container {
    transition: all 0.3s;
}

.dough-type-section.collapsed .products-container {
    display: none;
}

.product-row {
    display: grid;
    grid-template-columns: 250px repeat(auto-fit, minmax(80px, 1fr)) 120px;
    gap: 10px;
    padding: 8px 12px;
    border-bottom: 1px solid #f1f3f4;
    align-items: center;
    border-radius: 4px;
    transition: all 0.2s;
}

.product-row:hover {
    background: #f8f9fa;
}

.product-row.has-orders {
    background: rgba(40, 167, 69, 0.03);
    border-left: 3px solid #28a745;
}

.product-row.no-orders {
    background: rgba(108, 117, 125, 0.02);
    opacity: 0.8;
}

.product-row.changed {
    background: rgba(255, 193, 7, 0.1);
    border-left: 3px solid #ffc107;
}

.product-info {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.product-main {
    display: flex;
    align-items: center;
    gap: 8px;
}

.product-name {
    font-weight: 500;
    color: #2c3e50;
}

.new-badge {
    background: #17a2b8;
    color: white;
    font-size: 0.7rem;
    padding: 2px 6px;
    border-radius: 10px;
    font-weight: bold;
}

.product-meta {
    display: flex;
    gap: 8px;
    font-size: 0.8rem;
}

.price {
    color: #28a745;
    font-weight: 500;
}

.weight {
    color: #6c757d;
}

.quantity-cell {
    text-align: center;
}

.quantity-input {
    width: 60px;
    padding: 4px 6px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    text-align: center;
    transition: border-color 0.2s;
}

.quantity-input:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 2px rgba(0,123,255,0.25);
}

.quantity-input.changed {
    border-color: #ffc107;
    background-color: #fff3cd;
}

.total-cell {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.total-info {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.total-qty {
    color: #007bff;
    font-size: 1.1rem;
    font-weight: bold;
}

.total-value {
    font-size: 0.8rem;
    color: #28a745;
    font-weight: 500;
}

.quick-clear {
    background: #dc3545;
    color: white;
    border: none;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    line-height: 1;
    opacity: 0.7;
    transition: all 0.2s;
}

.quick-clear:hover {
    opacity: 1;
    transform: scale(1.1);
}

/* Responsive Design */
@media (max-width: 1200px) {
    .days-header,
    .product-row {
        grid-template-columns: 200px repeat(7, 70px) 100px;
    }
}

@media (max-width: 968px) {
    .som-filters {
        flex-direction: column;
        align-items: stretch;
    }
    
    .som-stats {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .days-header,
    .product-row {
        grid-template-columns: 150px repeat(7, 60px) 80px;
        font-size: 0.9rem;
    }
}

.compact-view .customer-orders {
    padding: 10px;
}

.compact-view .product-row {
    padding: 4px 0;
}

.compact-view .dough-type-header {
    padding: 5px 10px;
    margin-bottom: 5px;
}

/* No Orders Section Styles */
.no-orders-section {
    margin-bottom: 40px;
}

.no-orders-header, .with-orders-header {
    background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
    color: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.with-orders-header {
    background: linear-gradient(135deg, #27ae60 0%, #229954 100%);
}

.no-orders-header h2, .with-orders-header h2 {
    margin: 0;
    font-size: 1.5rem;
}

.customer-count {
    background: rgba(255,255,255,0.2);
    padding: 8px 15px;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: bold;
}

.no-orders-zone {
    margin-bottom: 20px;
}

.no-orders-zone .zone-header {
    background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%);
    padding: 12px 20px;
}

.no-orders-zone .zone-header h3 {
    margin: 0;
    font-size: 1.2rem;
}

.no-orders-customers {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 15px;
    padding: 20px;
    margin-left: 20px;
}

.no-orders-customer {
    background: white;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    padding: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: all 0.2s;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.no-orders-customer:hover {
    border-color: #007bff;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    transform: translateY(-1px);
}

.customer-basic-info h4 {
    margin: 0 0 5px 0;
    color: #2c3e50;
    font-size: 1.1rem;
}

.customer-basic-info .address {
    font-size: 0.85rem;
    color: #6c757d;
}

.copy-orders-btn {
    white-space: nowrap;
    font-size: 0.9rem;
    padding: 8px 12px;
}

.section-divider {
    height: 2px;
    background: linear-gradient(90deg, transparent 0%, #dee2e6 20%, #dee2e6 80%, transparent 100%);
    margin: 40px 0;
}

/* Modal Styles */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
}

.modal {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    z-index: 1001;
    min-width: 600px;
    max-width: 800px;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-content {
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
}

.modal-header {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    color: white;
    padding: 20px;
    border-radius: 12px 12px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    font-size: 1.3rem;
}

.modal-close {
    background: none;
    border: none;
    color: white;
    font-size: 1.5rem;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    transition: background-color 0.2s;
}

.modal-close:hover {
    background: rgba(255,255,255,0.2);
}

.modal-body {
    padding: 25px;
}

.source-selection {
    margin-bottom: 20px;
}

.source-selection label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: #495057;
}

.source-selection select {
    width: 100%;
    padding: 10px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 0.95rem;
}

.day-selection {
    margin-bottom: 20px;
}

.day-selection label {
    display: block;
    margin-bottom: 10px;
    font-weight: 500;
    color: #495057;
}

.day-checkboxes {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 10px;
}

.day-checkbox {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 8px;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
    font-weight: normal !important;
}

.day-checkbox:hover:not([style*="opacity: 0.3"]) {
    background: #f8f9fa;
    border-color: #007bff;
}

.day-checkbox input:checked + span,
.day-checkbox:has(input:checked) {
    background: #e3f2fd;
    border-color: #007bff;
    color: #007bff;
}

.day-checkbox[style*="opacity: 0.3"] {
    cursor: not-allowed;
}

.source-info {
    margin-top: 8px;
}

.text-muted {
    color: #6c757d !important;
}

.preview-section {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 6px;
    margin-top: 20px;
}

.preview-section h4 {
    margin: 0 0 10px 0;
    color: #495057;
}

#copy-preview {
    max-height: 200px;
    overflow-y: auto;
}

.preview-item {
    background: white;
    padding: 8px 12px;
    border-radius: 4px;
    margin-bottom: 5px;
    border-left: 3px solid #007bff;
    font-size: 0.9rem;
}

.modal-footer {
    background: #f8f9fa;
    padding: 15px 25px;
    border-radius: 0 0 12px 12px;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* Zone Toggle Styles */
.zone-toggle {
    cursor: pointer;
    transition: all 0.2s;
}

.zone-toggle:hover {
    opacity: 0.9;
}

.zone-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex: 1;
}

.zone-toggle-btn {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    padding: 8px 12px;
    border-radius: 50%;
    cursor: pointer;
    transition: all 0.2s;
}

.zone-toggle-btn:hover {
    background: rgba(255,255,255,0.3);
}

.zone-toggle-icon {
    display: inline-block;
    transition: transform 0.2s;
    font-weight: bold;
}

.zone-section.collapsed .zone-toggle-icon {
    transform: rotate(-90deg);
}

.zone-customers {
    transition: all 0.3s;
    overflow: hidden;
}

.zone-section.collapsed .zone-customers {
    display: none;
}

/* Customer Summary Card Styles */
.customer-summary-card {
    background: white;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    margin: 10px 0 10px 20px;
    overflow: hidden;
    transition: all 0.2s;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.customer-summary-card:hover {
    border-color: #007bff;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    transform: translateY(-1px);
}

.customer-summary-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 15px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    border-bottom: 1px solid #dee2e6;
}

.customer-summary-info h4 {
    margin: 0 0 5px 0;
    color: #2c3e50;
    font-size: 1.1rem;
}

.customer-summary-details {
    font-size: 0.9rem;
    color: #6c757d;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.customer-summary-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.expand-toggle {
    color: #6c757d;
    font-weight: bold;
    transition: transform 0.2s;
}

.customer-summary-card.expanded .expand-toggle {
    transform: rotate(180deg);
}

.customer-full-details {
    padding: 20px;
    background: #fafbfc;
    border-top: 1px solid #e9ecef;
}

.no-routes-message {
    text-align: center;
    padding: 30px;
    color: #856404;
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 6px;
}

.no-routes-message p {
    margin: 0;
    font-size: 1rem;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .no-orders-customers {
        grid-template-columns: 1fr;
    }
    
    .no-orders-customer {
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
    }
    
    .modal {
        min-width: 95%;
        margin: 20px;
    }
    
    .day-checkboxes {
        grid-template-columns: repeat(4, 1fr);
    }
    
    .customer-summary-header {
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
    }
    
    .customer-summary-actions {
        justify-content: space-between;
    }
    
    .zone-title {
        flex-direction: column;
        align-items: flex-start;
        gap: 5px;
    }
}

.performance-info {
    background: #e3f2fd;
    border: 1px solid #bbdefb;
    color: #0d47a1;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 0.85rem;
    display: flex;
    align-items: center;
    gap: 5px;
    transition: all 0.3s;
}

.performance-info.warning {
    background: #fff3cd;
    border-color: #ffeaa7;
    color: #856404;
}

.performance-info.error {
    background: #f8d7da;
    border-color: #f5c6cb;
    color: #721c24;
}

.performance-info.good {
    background: #d1edff;
    border-color: #bee5eb;
    color: #0c5460;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* Loading optimization - show skeleton loading */
.customer-section.loading {
    opacity: 0.6;
    pointer-events: none;
}

.customer-section.loading .customer-orders {
    min-height: 200px;
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: loading 1.5s infinite;
}

@keyframes loading {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
    let changedInputs = new Set();
    let isCompactView = false;
    let autoSaveTimeout = null;
    let customerOrderCache = new Map(); // Cache for loaded customer orders
    const AUTO_SAVE_DELAY = 1500; // Auto-save after 1.5 seconds of inactivity
    
    console.log('Standing Orders Manager initialized');
    console.log(`Total customers: ${<?php echo count($customers); ?>}`);
    console.log(`Auto-collapse threshold: 50 customers`);
    
    // PERFORMANCE: Show initial load time
    const performanceInfo = document.getElementById('performance-info');
    const pageLoadTime = performance.now();
    setTimeout(() => {
        updatePerformanceInfo(`Page loaded in ${Math.round(pageLoadTime)}ms`, 'good');
        performanceInfo.style.display = 'flex';
    }, 100);
    
    // Performance check functionality
    document.getElementById('performance-check').addEventListener('click', function() {
        this.disabled = true;
        this.textContent = '⚡ Checking...';
        
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'check_performance' })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let status = 'good';
                let message = `DB: ${data.load_time}ms`;
                
                if (data.missing_indexes.length > 0) {
                    status = 'warning';
                    message += ` (${data.missing_indexes.length} indexes missing)`;
                } else if (parseFloat(data.load_time) > 100) {
                    status = 'warning';
                    message += ' (slow)';
                }
                
                updatePerformanceInfo(message, status);
                
                if (data.missing_indexes.length > 0) {
                    showNotification(`⚠️ Performance: Missing database indexes on ${data.missing_indexes.join(', ')}. Contact administrator.`, 'warning');
                }
            }
        })
        .catch(error => {
            updatePerformanceInfo('Check failed', 'error');
        })
        .finally(() => {
            this.disabled = false;
            this.textContent = '⚡ Check Performance';
        });
    });
    
    // Route diagnostics functionality
    document.getElementById('diagnostic-routes').addEventListener('click', function() {
        this.disabled = true;
        this.textContent = '🔍 Checking...';
        
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'diagnostic_routes' })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const diagnostics = data.diagnostics;
                let message = `Routes Table: ${diagnostics.routes_table_count} entries\n`;
                message += `Customers with routes: ${diagnostics.standing_routes.customers_with_routes}\n`;
                message += `Total route entries: ${diagnostics.standing_routes.total_routes}\n`;
                message += `Available days: ${diagnostics.standing_routes.all_days || 'None'}\n\n`;
                
                if (diagnostics.sample_customers_with_routes.length > 0) {
                    message += `Sample customers with routes:\n`;
                    diagnostics.sample_customers_with_routes.forEach(customer => {
                        message += `• ${customer.name} (Days: ${customer.route_days})\n`;
                    });
                } else {
                    message += `⚠️ No customers found with delivery routes!\n`;
                    message += `This explains why you don't see customers with routes.`;
                }
                
                alert(message);
                
                if (diagnostics.routes_table_count == 0) {
                    showNotification('⚠️ No delivery routes found in database. You need to set up delivery routes first.', 'warning');
                } else if (diagnostics.standing_routes.customers_with_routes == 0) {
                    showNotification('⚠️ Standing routes table has data but no customers are assigned to routes.', 'warning');
                }
            }
        })
        .catch(error => {
            alert('Diagnostic failed: ' + error.message);
        })
        .finally(() => {
            this.disabled = false;
            this.textContent = '🔍 Check Routes';
        });
    });
    
    function updatePerformanceInfo(text, status = 'good') {
        const performanceInfo = document.getElementById('performance-info');
        const performanceText = performanceInfo.querySelector('.performance-text');
        
        performanceInfo.className = `performance-info ${status}`;
        performanceText.textContent = text;
        
        if (status === 'warning' || status === 'error') {
            performanceInfo.style.display = 'flex';
        }
    }
    
    // Lazy loading for customer orders
    function loadCustomerOrders(customerId) {
        if (customerOrderCache.has(customerId)) {
            return Promise.resolve(customerOrderCache.get(customerId));
        }
        
        return fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'load_customer_orders',
                customer_id: customerId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                customerOrderCache.set(customerId, data.orders);
                return data.orders;
            }
            throw new Error(data.error || 'Failed to load customer orders');
        });
    }
    
    // Enhanced customer section toggle with lazy loading
    document.querySelectorAll('.customer-header').forEach(header => {
        let isToggling = false; // Prevent rapid clicking
        
        header.addEventListener('click', function(event) {
            // Don't prevent default or stop propagation unless necessary
            
            if (isToggling) {
                console.log('Toggle blocked - already in progress');
                return;
            }
            
            isToggling = true;
            
            const section = this.closest('.customer-section');
            const customerId = section.dataset.customerId;
            const ordersContainer = section.querySelector('.customer-orders');
            
            console.log(`Customer header clicked: ${section.dataset.customerName}, currently collapsed: ${section.classList.contains('collapsed')}`);
            
            if (section.classList.contains('collapsed')) {
                // Expanding - check if we need to load orders
                console.log('Expanding customer section');
                section.classList.remove('collapsed');
                
                // If this is a large dataset and orders aren't loaded, load them
                if (<?php echo count($customers) > 100 ? 'true' : 'false'; ?> && !customerOrderCache.has(customerId)) {
                    section.classList.add('loading');
                    
                    loadCustomerOrders(customerId)
                        .then(orders => {
                            // Update the UI with loaded orders
                            updateCustomerOrdersUI(customerId, orders);
                            section.classList.remove('loading');
                        })
                        .catch(error => {
                            section.classList.remove('loading');
                            showNotification(`Failed to load orders for customer: ${error.message}`, 'error');
                        });
                }
            } else {
                // Collapsing
                console.log('Collapsing customer section');
                section.classList.add('collapsed');
            }
            
            // Reset toggle lock after a short delay
            setTimeout(() => {
                isToggling = false;
            }, 300);
        });
    });
    
    function updateCustomerOrdersUI(customerId, orders) {
        // Update quantity inputs with loaded order data
        const customerSection = document.querySelector(`[data-customer-id="${customerId}"]`);
        const inputs = customerSection.querySelectorAll('.quantity-input');
        
        inputs.forEach(input => {
            const productId = input.dataset.productId;
            const dayOfWeek = input.dataset.day;
            
            if (orders[productId] && orders[productId][dayOfWeek]) {
                const quantity = orders[productId][dayOfWeek].quantity;
                input.value = quantity;
                input.dataset.original = quantity;
                updateProductTotals(input);
            }
        });
    }
    
    // PERFORMANCE: Debounced input handling
    let inputTimeout = null;
    function debounceInput(callback, delay = 300) {
        return function(...args) {
            clearTimeout(inputTimeout);
            inputTimeout = setTimeout(() => callback.apply(this, args), delay);
        };
    }
    
    // Track changes (optimized)
    document.querySelectorAll('.quantity-input').forEach(input => {
        const debouncedHandler = debounceInput(function() {
            const original = parseInt(this.dataset.original) || 0;
            const current = parseInt(this.value) || 0;
            const productRow = this.closest('.product-row');
            
            if (current !== original) {
                this.classList.add('changed');
                productRow.classList.add('changed');
                changedInputs.add(this);
            } else {
                this.classList.remove('changed');
                productRow.classList.remove('changed');
                changedInputs.delete(this);
            }
            
            updateProductTotals(this);
            updateBulkSaveButton();
            updateChangesPanel();
            
            // Update auto-save status
            if (changedInputs.size > 0) {
                updateAutoSaveStatus('pending');
            } else {
                updateAutoSaveStatus('idle');
            }
            
            // Schedule auto-save
            scheduleAutoSave();
        });
        
        input.addEventListener('input', debouncedHandler);
    });
    
    // PERFORMANCE: Virtual scrolling for large datasets (basic implementation)
    if (<?php echo count($customers); ?> > 50) {
        // Collapse all customer sections by default for better performance
        document.querySelectorAll('.customer-section, .customer-summary-card').forEach(section => {
            section.classList.add('collapsed');
        });
        
        showNotification(`📊 Large dataset detected (${<?php echo count($customers); ?>} customers). Sections collapsed for better performance. Use expand/collapse buttons to manage view.`, 'info');
    }
    
    // Add expand/collapse all functionality
    const expandAllBtn = document.getElementById('expand-all-customers');
    const collapseAllBtn = document.getElementById('collapse-all-customers');
    
    if (expandAllBtn) {
        expandAllBtn.addEventListener('click', function() {
            this.disabled = true;
            this.textContent = '🔄 Expanding...';
            
            // Get all collapsed sections (both customer sections and summary cards)
            const collapsedSections = document.querySelectorAll('.customer-section.collapsed, .customer-summary-card.collapsed');
            let index = 0;
            const batchSize = 10;
            
            function expandBatch() {
                const endIndex = Math.min(index + batchSize, collapsedSections.length);
                for (let i = index; i < endIndex; i++) {
                    const section = collapsedSections[i];
                    section.classList.remove('collapsed');
                    
                    // For customer summary cards, also expand the details
                    if (section.classList.contains('customer-summary-card')) {
                        section.classList.add('expanded');
                        const details = section.querySelector('.customer-full-details');
                        if (details) {
                            details.style.display = 'block';
                        }
                        // Update the expand toggle arrow
                        const expandToggle = section.querySelector('.expand-toggle');
                        if (expandToggle) {
                            expandToggle.style.transform = 'rotate(180deg)';
                        }
                    }
                }
                index = endIndex;
                
                if (index < collapsedSections.length) {
                    setTimeout(expandBatch, 50); // Small delay to prevent blocking
                } else {
                    expandAllBtn.disabled = false;
                    expandAllBtn.textContent = '🔄 Expand All Customers';
                    showNotification(`✅ Expanded ${collapsedSections.length} customer sections`, 'success');
                }
            }
            
            expandBatch();
        });
    }
    
    if (collapseAllBtn) {
        collapseAllBtn.addEventListener('click', function() {
            this.disabled = true;
            this.textContent = '📁 Collapsing...';
            
            // Collapse all customer sections and summary cards
            document.querySelectorAll('.customer-section, .customer-summary-card').forEach(section => {
                section.classList.add('collapsed');
                
                // For customer summary cards, also collapse the details
                if (section.classList.contains('customer-summary-card')) {
                    section.classList.remove('expanded');
                    const details = section.querySelector('.customer-full-details');
                    if (details) {
                        details.style.display = 'none';
                    }
                    // Reset the expand toggle arrow
                    const expandToggle = section.querySelector('.expand-toggle');
                    if (expandToggle) {
                        expandToggle.style.transform = 'rotate(0deg)';
                    }
                }
            });
            
            const totalSections = document.querySelectorAll('.customer-section, .customer-summary-card').length;
            
            // Re-enable button
            this.disabled = false;
            this.textContent = '📁 Collapse All Customers';
            
            showNotification(`📁 Collapsed ${totalSections} customer sections`, 'info');
            console.log(`Collapse All: Processed ${totalSections} sections`);
        });
    }
    
    // Track changes
    document.querySelectorAll('.quantity-input').forEach(input => {
        input.addEventListener('input', function() {
            const original = parseInt(this.dataset.original) || 0;
            const current = parseInt(this.value) || 0;
            const productRow = this.closest('.product-row');
            
            if (current !== original) {
                this.classList.add('changed');
                productRow.classList.add('changed');
                changedInputs.add(this);
            } else {
                this.classList.remove('changed');
                productRow.classList.remove('changed');
                changedInputs.delete(this);
            }
            
            updateProductTotals(this);
            updateBulkSaveButton();
            updateChangesPanel();
            
            // Update auto-save status
            if (changedInputs.size > 0) {
                updateAutoSaveStatus('pending');
            } else {
                updateAutoSaveStatus('idle');
            }
            
            // Schedule auto-save
            scheduleAutoSave();
        });
    });
    
    // Update product totals
    function updateProductTotals(changedInput) {
        const productRow = changedInput.closest('.product-row');
        const inputs = productRow.querySelectorAll('.quantity-input');
        const totalQty = productRow.querySelector('.total-qty');
        const totalValue = productRow.querySelector('.total-value');
        const quickClear = productRow.querySelector('.quick-clear');
        
        let total = 0;
        inputs.forEach(input => {
            total += parseInt(input.value) || 0;
        });
        
        totalQty.textContent = total;
        
        // Update value if price is available
        const priceElement = productRow.querySelector('.price');
        if (priceElement && totalValue) {
            const priceMatch = priceElement.textContent.match(/\$(\d+\.?\d*)/);
            if (priceMatch && total > 0) {
                const price = parseFloat(priceMatch[1]);
                totalValue.textContent = `$${(total * price).toFixed(2)}`;
                totalValue.style.display = 'block';
            } else {
                totalValue.style.display = 'none';
            }
        }
        
        // Show/hide quick clear button
        if (quickClear) {
            quickClear.style.display = total > 0 ? 'flex' : 'none';
        }
    }
    
    // Auto-save scheduling
    function scheduleAutoSave() {
        // Clear existing timeout
        if (autoSaveTimeout) {
            clearTimeout(autoSaveTimeout);
        }
        
        // Only schedule if there are changes
        if (changedInputs.size === 0) return;
        
        // Schedule new auto-save
        autoSaveTimeout = setTimeout(() => {
            performSave(true); // true indicates auto-save
        }, AUTO_SAVE_DELAY);
    }
    
    // Update bulk save button
    function updateBulkSaveButton() {
        const bulkSaveBtn = document.getElementById('bulk-save');
        if (changedInputs.size === 0) {
            bulkSaveBtn.disabled = true;
            bulkSaveBtn.textContent = '💾 Save Changes';
        } else {
            bulkSaveBtn.disabled = false;
            bulkSaveBtn.textContent = `💾 Save ${changedInputs.size} Changes`;
        }
    }
    
    // Update auto-save status
    function updateAutoSaveStatus(status, message = '') {
        const statusElement = document.getElementById('auto-save-status');
        const statusIcon = statusElement.querySelector('.status-icon');
        const statusText = statusElement.querySelector('.status-text');
        
        // Reset classes
        statusElement.className = 'auto-save-status';
        
        switch(status) {
            case 'idle':
                statusIcon.textContent = '💾';
                statusText.textContent = 'Auto-save enabled';
                break;
            case 'pending':
                statusElement.classList.add('saving');
                statusIcon.textContent = '⏱️';
                statusText.textContent = 'Changes pending...';
                break;
            case 'saving':
                statusElement.classList.add('saving');
                statusIcon.textContent = '💾';
                statusText.textContent = 'Auto-saving...';
                break;
            case 'success':
                statusElement.classList.add('success');
                statusIcon.textContent = '✅';
                statusText.textContent = message || 'Auto-saved';
                setTimeout(() => updateAutoSaveStatus('idle'), 2000);
                break;
            case 'error':
                statusElement.classList.add('error');
                statusIcon.textContent = '❌';
                statusText.textContent = 'Auto-save failed';
                setTimeout(() => updateAutoSaveStatus('idle'), 3000);
                break;
        }
    }
    
    // Unified save function
    function performSave(isAutoSave = false) {
        if (changedInputs.size === 0) return;
        
        const updates = Array.from(changedInputs).map(input => ({
            customer_id: input.dataset.customerId,
            product_id: input.dataset.productId,
            day_of_week: input.dataset.day,
            quantity: parseInt(input.value) || 0
        }));
        
        const bulkSaveBtn = document.getElementById('bulk-save');
        
        // Update UI
        bulkSaveBtn.disabled = true;
        if (isAutoSave) {
            bulkSaveBtn.textContent = '💾 Auto-saving...';
            updateAutoSaveStatus('saving');
        } else {
            bulkSaveBtn.textContent = '💾 Saving...';
        }
        
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'bulk_save',
                updates: JSON.stringify(updates)
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update original values and clear changes
                changedInputs.forEach(input => {
                    input.dataset.original = input.value;
                    input.classList.remove('changed');
                    input.closest('.product-row').classList.remove('changed');
                });
                changedInputs.clear();
                updateBulkSaveButton();
                updateChangesPanel();
                
                // Show success status
                if (isAutoSave) {
                    updateAutoSaveStatus('success', `Auto-saved ${data.updated} changes`);
                } else {
                    showNotification(`✅ Successfully updated ${data.updated} orders!`, 'success');
                }
            } else {
                if (isAutoSave) {
                    updateAutoSaveStatus('error');
                }
                showNotification(`❌ Error: ${data.error}`, 'error');
            }
        })
        .catch(error => {
            if (isAutoSave) {
                updateAutoSaveStatus('error');
            }
            showNotification(`❌ Network error: ${error.message}`, 'error');
        })
        .finally(() => {
            updateBulkSaveButton();
        });
    }
    
    // Manual save functionality
    document.getElementById('bulk-save').addEventListener('click', function() {
        performSave(false); // false indicates manual save
    });
    
    // Customer section toggle - REMOVED DUPLICATE HANDLER
    // The enhanced toggle with lazy loading above already handles this
    
    // View toggle
    document.getElementById('view-toggle').addEventListener('click', function() {
        isCompactView = !isCompactView;
        document.body.classList.toggle('compact-view', isCompactView);
        this.textContent = isCompactView ? '👁️ Normal View' : '👁️ Compact View';
    });
    
    // Filter toggle
    document.getElementById('filter-toggle').addEventListener('click', function() {
        const panel = document.getElementById('filters-panel');
        panel.style.display = panel.style.display === 'none' ? 'flex' : 'none';
    });
    
    // Changes panel toggle
    document.getElementById('changes-toggle').addEventListener('click', function() {
        const panel = document.getElementById('changes-panel');
        panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
    });
    
    // Filters functionality
    const customerFilter = document.getElementById('customer-filter');
    const productLineFilter = document.getElementById('product-line-filter');
    const dayFilter = document.getElementById('day-filter');
    
    [customerFilter, productLineFilter, dayFilter].forEach(filter => {
        filter.addEventListener('change', applyFilters);
    });
    
    document.getElementById('clear-filters').addEventListener('click', function() {
        customerFilter.value = '';
        productLineFilter.value = '';
        dayFilter.value = '';
        applyFilters();
    });
    
    function applyFilters() {
        const customerValue = customerFilter.value;
        const productLineValue = productLineFilter.value;
        const dayValue = dayFilter.value;
        
        // Filter customer sections
        document.querySelectorAll('.customer-section, .customer-summary-card').forEach(section => {
            const customerId = section.dataset.customerId;
            const showCustomer = !customerValue || customerId === customerValue;
            section.style.display = showCustomer ? 'block' : 'none';
        });
        
        // Filter product line sections
        document.querySelectorAll('.product-line-section').forEach(section => {
            const productLine = section.dataset.productLine;
            const showProductLine = !productLineValue || productLine === productLineValue;
            section.style.display = showProductLine ? 'block' : 'none';
        });
        
        // Filter day columns
        if (dayValue) {
            // Hide all day columns except the selected one
            document.querySelectorAll('.day-column').forEach((col, index) => {
                const dayNum = index + 1; // Assuming columns are in order
                col.style.display = dayNum.toString() === dayValue ? 'block' : 'none';
            });
        } else {
            // Show all day columns
            document.querySelectorAll('.day-column').forEach(col => {
                col.style.display = 'block';
            });
        }
    }
    
    // Notification system
    function showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.textContent = message;
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 6px;
            color: white;
            font-weight: 500;
            z-index: 1000;
            background: ${type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : '#007bff'};
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            transform: translateX(100%);
            transition: transform 0.3s;
        `;
        
        document.body.appendChild(notification);
        
        // Animate in
        setTimeout(() => {
            notification.style.transform = 'translateX(0)';
        }, 100);
        
        // Auto remove
        setTimeout(() => {
            notification.style.transform = 'translateX(100%)';
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 300);
        }, 3000);
    }
    
    // Update changes panel
    function updateChangesPanel() {
        const changesCount = document.getElementById('changes-count');
        const changesList = document.getElementById('changes-list');
        
        changesCount.textContent = `${changedInputs.size} changes`;
        
        if (changedInputs.size === 0) {
            changesList.innerHTML = '<p class="no-changes">No changes pending</p>';
        } else {
            let html = '';
            changedInputs.forEach(input => {
                const productRow = input.closest('.product-row');
                const customerSection = input.closest('.customer-section');
                const customerName = customerSection.dataset.customerName;
                const productName = productRow.querySelector('.product-name').textContent;
                const dayName = input.dataset.day;
                const dayNames = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                const original = input.dataset.original || '0';
                const current = input.value || '0';
                
                html += `<div class="change-item">
                    <strong>${customerName}</strong> - ${productName}<br>
                    ${dayNames[dayName]}: ${original} → ${current}
                </div>`;
            });
            changesList.innerHTML = html;
        }
    }
    
    // Clear product quantities
    window.clearProduct = function(button) {
        const productRow = button.closest('.product-row');
        const inputs = productRow.querySelectorAll('.quantity-input');
        
        inputs.forEach(input => {
            input.value = '0';
            input.dispatchEvent(new Event('input'));
        });
    };
    
    // Toggle dough type sections (legacy support)
    window.toggleDoughType = function(header) {
        const section = header.closest('.dough-type-section');
        section.classList.toggle('collapsed');
    };
    
    // Toggle product line sections
    window.toggleProductLine = function(header) {
        const section = header.closest('.product-line-section');
        section.classList.toggle('collapsed');
    };
    
    // Toggle dough type subsections within product lines
    window.toggleDoughTypeSubsection = function(header) {
        const subsection = header.closest('.dough-type-subsection');
        subsection.classList.toggle('collapsed');
    };
    
    // Toggle customer details in summary cards
    window.toggleCustomerDetails = function(header) {
        const card = header.closest('.customer-summary-card');
        const details = card.querySelector('.customer-full-details');
        
        if (!card || !details) {
            console.warn('toggleCustomerDetails: Missing card or details element');
            return;
        }
        
        // Toggle the expanded state
        const isCurrentlyExpanded = card.classList.contains('expanded');
        
        if (isCurrentlyExpanded) {
            // Collapsing
            card.classList.remove('expanded');
            details.style.display = 'none';
        } else {
            // Expanding
            card.classList.add('expanded');
            details.style.display = 'block';
        }
        
        // Update the expand toggle arrow
        const expandToggle = header.querySelector('.expand-toggle');
        if (expandToggle) {
            expandToggle.style.transform = isCurrentlyExpanded ? 'rotate(0deg)' : 'rotate(180deg)';
        }
    };
    
    // Zone toggle functionality
    document.querySelectorAll('.zone-toggle').forEach(header => {
        header.addEventListener('click', function() {
            const section = this.closest('.zone-section');
            section.classList.toggle('collapsed');
        });
    });
    
    // Copy Orders Modal functionality
    const copyOrdersModal = document.getElementById('copy-orders-modal');
    const modalOverlay = document.getElementById('modal-overlay');
    const sourceCustomerSelect = document.getElementById('source-customer-select');
    const daySelection = document.getElementById('day-selection');
    const previewSection = document.getElementById('preview-section');
    const copyConfirm = document.getElementById('copy-confirm');
    let currentTargetCustomerId = null;
    let currentTargetDay = null; // null means copy all days
    let currentCopyType = 'all'; // 'all' or 'day'
    
    // Store all customer data for the modal
    const customersData = <?php 
        $allCustomersData = array_merge($customersWithOrders, $customersWithoutOrders);
        echo json_encode(array_map(function($customer) use ($customerRoutes, $existingOrders) {
            return [
                'id' => $customer['id'],
                'name' => $customer['name'],
                'zone' => $customer['zone'] ?: 'No Zone',
                'route_days' => $customerRoutes[$customer['id']] ?? [],
                'has_orders' => isset($existingOrders[$customer['id']]) && !empty($existingOrders[$customer['id']]),
                'order_count' => $customer['order_count']
            ];
        }, $allCustomersData)); 
    ?>;
    
    // Open copy orders modal for specific day
    document.querySelectorAll('.day-copy-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            currentTargetCustomerId = this.dataset.targetCustomer;
            currentTargetDay = parseInt(this.dataset.targetDay);
            currentCopyType = 'day';
            
            document.getElementById('target-customer-name').textContent = this.dataset.customerName;
            document.getElementById('target-day-info').textContent = this.dataset.dayName;
            
            populateSourceCustomers();
            showModal();
        });
    });
    
    // Open copy orders modal for all days
    document.querySelectorAll('.copy-all-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            currentTargetCustomerId = this.dataset.targetCustomer;
            currentTargetDay = null;
            currentCopyType = 'all';
            
            document.getElementById('target-customer-name').textContent = this.dataset.customerName;
            document.getElementById('target-day-info').textContent = 'All Days';
            
            populateSourceCustomers();
            showModal();
        });
    });
    
    // Source customer selection
    sourceCustomerSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (selectedOption.value) {
            const availableDays = selectedOption.dataset.days.split(',');
            showDaySelection(availableDays);
        } else {
            hideDaySelection();
            hidePreview();
        }
    });
    
    // Day selection checkboxes
    document.querySelectorAll('#day-selection input[type="checkbox"]').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updatePreview();
        });
    });
    
    // Copy confirmation
    copyConfirm.addEventListener('click', function() {
        const sourceCustomerId = sourceCustomerSelect.value;
        const selectedDays = Array.from(document.querySelectorAll('#day-selection input[type="checkbox"]:checked'))
            .map(cb => parseInt(cb.value));
        
        if (!sourceCustomerId) {
            showNotification('❌ Please select a source customer', 'error');
            return;
        }
        
        if (selectedDays.length === 0) {
            showNotification('❌ Please select at least one day to copy', 'error');
            return;
        }
        
        this.disabled = true;
        this.textContent = '📋 Copying...';
        
        const sourceCustomerName = sourceCustomerSelect.options[sourceCustomerSelect.selectedIndex].text;
        const targetCustomerName = document.getElementById('target-customer-name').textContent;
        const dayNames = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        const dayText = selectedDays.map(d => dayNames[d]).join(', ');
        
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'copy_orders',
                source_customer_id: sourceCustomerId,
                target_customer_id: currentTargetCustomerId,
                selected_days: JSON.stringify(selectedDays)
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification(`✅ Successfully copied ${data.copied} orders from ${sourceCustomerName.split(' (')[0]} to ${targetCustomerName} for ${dayText}!`, 'success');
                hideModal();
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            } else {
                showNotification(`❌ Error: ${data.error}`, 'error');
            }
        })
        .catch(error => {
            showNotification(`❌ Network error: ${error.message}`, 'error');
        })
        .finally(() => {
            this.disabled = false;
            this.textContent = '📋 Copy Orders';
        });
    });
    
    // Modal controls
    function showModal() {
        copyOrdersModal.style.display = 'block';
        modalOverlay.style.display = 'block';
        document.body.style.overflow = 'hidden';
        resetModal();
    }
    
    function hideModal() {
        copyOrdersModal.style.display = 'none';
        modalOverlay.style.display = 'none';
        document.body.style.overflow = '';
        resetModal();
    }
    
    function resetModal() {
        sourceCustomerSelect.value = '';
        hideDaySelection();
        hidePreview();
        copyConfirm.disabled = true;
    }
    
    function showDaySelection(availableDays) {
        // Reset all checkboxes
        document.querySelectorAll('#day-selection input[type="checkbox"]').forEach(cb => {
            cb.checked = false;
            cb.disabled = true;
            cb.parentElement.style.opacity = '0.5';
        });
        
        // For day-specific copy, only enable and auto-select the target day
        if (currentCopyType === 'day') {
            const targetCheckbox = document.querySelector(`#day-selection input[value="${currentTargetDay}"]`);
            if (targetCheckbox && availableDays.includes(currentTargetDay.toString())) {
                targetCheckbox.disabled = false;
                targetCheckbox.checked = true;
                targetCheckbox.parentElement.style.opacity = '1';
                
                // Disable all other days for single-day copy
                document.querySelectorAll('#day-selection input[type="checkbox"]').forEach(cb => {
                    if (cb.value != currentTargetDay) {
                        cb.disabled = true;
                        cb.parentElement.style.opacity = '0.3';
                    }
                });
                
                // Auto-update preview since day is pre-selected
                setTimeout(updatePreview, 100);
            }
        } else {
            // For copy all, enable all available days
            availableDays.forEach(day => {
                const checkbox = document.querySelector(`#day-selection input[value="${day}"]`);
                if (checkbox) {
                    checkbox.disabled = false;
                    checkbox.parentElement.style.opacity = '1';
                }
            });
        }
        
        // Update label based on copy type
        const daySelectionLabel = document.getElementById('day-selection-label');
        if (currentCopyType === 'day') {
            daySelectionLabel.textContent = 'Copy this specific day:';
        } else {
            daySelectionLabel.textContent = 'Select days to copy:';
        }
        
        daySelection.style.display = 'block';
        if (currentCopyType !== 'day') {
            hidePreview();
        }
    }
    
    function hideDaySelection() {
        daySelection.style.display = 'none';
    }
    
    function hidePreview() {
        previewSection.style.display = 'none';
        copyConfirm.disabled = true;
    }
    
    function populateSourceCustomers() {
        // Clear existing options
        sourceCustomerSelect.innerHTML = '<option value="">Select a customer...</option>';
        
        // Filter customers based on copy type
        let eligibleCustomers = [];
        
        if (currentCopyType === 'day') {
            // For day-specific copy, show customers who have orders on that specific day
            eligibleCustomers = customersData.filter(customer => {
                return customer.has_orders && 
                       customer.route_days.includes(currentTargetDay) &&
                       customer.id != currentTargetCustomerId;
            });
        } else {
            // For copy all, show all customers with any orders
            eligibleCustomers = customersData.filter(customer => {
                return customer.has_orders && customer.id != currentTargetCustomerId;
            });
        }
        
        // Add options
        eligibleCustomers.forEach(customer => {
            const option = document.createElement('option');
            option.value = customer.id;
            option.textContent = `${customer.name} (${customer.zone}) - ${customer.route_days.map(d => ['','Mon','Tue','Wed','Thu','Fri','Sat','Sun'][d]).join(', ')}`;
            option.dataset.days = customer.route_days.join(',');
            option.dataset.zone = customer.zone;
            sourceCustomerSelect.appendChild(option);
        });
        
        // Show info message
        const sourceInfo = document.getElementById('source-info');
        if (eligibleCustomers.length === 0) {
            sourceInfo.innerHTML = `<small class="text-muted">No customers found with orders ${currentCopyType === 'day' ? 'on ' + ['','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'][currentTargetDay] : 'available'}</small>`;
            sourceInfo.style.display = 'block';
        } else {
            sourceInfo.innerHTML = `<small class="text-muted">${eligibleCustomers.length} customers available ${currentCopyType === 'day' ? 'with orders on this day' : 'with orders'}</small>`;
            sourceInfo.style.display = 'block';
        }
    }
    
    function updatePreview() {
        const selectedDays = Array.from(document.querySelectorAll('#day-selection input[type="checkbox"]:checked'))
            .map(cb => ({
                value: parseInt(cb.value),
                name: cb.dataset.dayName
            }));
        
        if (selectedDays.length === 0) {
            hidePreview();
            return;
        }
        
        const sourceCustomerId = sourceCustomerSelect.value;
        const sourceCustomerName = sourceCustomerSelect.options[sourceCustomerSelect.selectedIndex].text;
        
        // Show preview
        let previewHtml = `<div class="preview-item">
            <strong>Source:</strong> ${sourceCustomerName}<br>
            <strong>Target:</strong> ${document.getElementById('target-customer-name').textContent}<br>
            <strong>Copy Type:</strong> ${currentCopyType === 'day' ? 'Single Day' : 'Multiple Days'}<br>
            <strong>Days:</strong> ${selectedDays.map(d => d.name).join(', ')}<br>
            <em>All products with orders on these days will be copied.</em>
        </div>`;
        
        document.getElementById('copy-preview').innerHTML = previewHtml;
        previewSection.style.display = 'block';
        copyConfirm.disabled = false;
    }
    
    // Close modal events
    document.querySelector('.modal-close').addEventListener('click', hideModal);
    document.querySelector('.modal-cancel').addEventListener('click', hideModal);
    modalOverlay.addEventListener('click', hideModal);
    
    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && copyOrdersModal.style.display === 'block') {
            hideModal();
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?> 