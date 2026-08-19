<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/pan_dulce_standards.php';
require_once 'includes/sfb_origin.php';

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

                if (!bakery_sfb_ops_customer_allowed($db, $customerId)) {
                    throw new RuntimeException('Synthetic SF Bakers cannot have standing orders');
                }
                
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
                if (!is_array($updates)) {
                    throw new InvalidArgumentException('Invalid standing order updates');
                }
                $db->beginTransaction();

                // Prepare each statement once instead of once per changed cell.
                $upsert = $db->prepare("\n                    INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity)\n                    VALUES (?, ?, ?, ?)\n                    ON DUPLICATE KEY UPDATE quantity = ?\n                ");
                $delete = $db->prepare("DELETE FROM standing_orders WHERE customer_id = ? AND product_id = ? AND day_of_week = ?");
                
                foreach ($updates as $update) {
                    $customerId = (int)$update['customer_id'];
                    $productId = (int)$update['product_id'];
                    $dayOfWeek = (int)$update['day_of_week'];
                    $quantity = (int)$update['quantity'];
                    
                    if ($quantity > 0) {
                        $upsert->execute([$customerId, $productId, $dayOfWeek, $quantity, $quantity]);
                    } else {
                        $delete->execute([$customerId, $productId, $dayOfWeek]);
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
                        CASE WHEN so.day_of_week = 0 THEN 7 ELSE so.day_of_week END AS day_of_week,
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
                
            case 'apply_pan_dulce_standard':
                $customerId = (int)$_POST['customer_id'];
                $multiplier = isset($_POST['multiplier']) ? (float)$_POST['multiplier'] : 1.0;
                $dayOfWeek = isset($_POST['day_of_week']) && $_POST['day_of_week'] !== ''
                    ? (int)$_POST['day_of_week']
                    : null;
                $routeDays = $dayOfWeek !== null ? [$dayOfWeek] : null;

                $db->beginTransaction();
                $result = bakery_apply_pan_dulce_standing_standard($db, $customerId, $multiplier, $routeDays);
                $db->commit();

                echo json_encode(['success' => true] + $result);
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
    $drivers = bakery_get_drivers($db, true);
    
    // Get all customers with route and order counts in one optimized query
    $customers = $db->query("
        SELECT 
            c.id, 
            c.name, 
            c.address,
            c.zone,
            COUNT(DISTINCT so.id) as order_count,
            COUNT(DISTINCT sr.id) as route_count,
            GROUP_CONCAT(DISTINCT sr.day_of_week ORDER BY sr.day_of_week) as route_days,
            GROUP_CONCAT(DISTINCT sr.driver_id ORDER BY sr.driver_id) as route_driver_ids
        FROM customers c
        LEFT JOIN standing_orders so ON c.id = so.customer_id
        LEFT JOIN standing_routes sr ON c.id = sr.customer_id
        WHERE 1=1 " . bakery_sfb_ops_origin_clause('c', $db) . "
        GROUP BY c.id, c.name, c.address, c.zone
        ORDER BY 
            CASE WHEN c.zone IS NULL OR c.zone = '' THEN 'ZZZ' ELSE c.zone END,
            c.name
    ")->fetchAll();
    
    // Pan Dulce standard quantities are optional so this page remains compatible
    // with production databases that have not yet run migration 012.
    $panDulceStandardsAvailable = table_exists($db, 'pan_dulce_product_quantity_standards');
    $standardQuantityJoin = $panDulceStandardsAvailable
        ? 'LEFT JOIN pan_dulce_product_quantity_standards pdqs ON pdqs.product_id = p.id'
        : '';
    $standardQuantitySelect = $panDulceStandardsAvailable
        ? "COALESCE(pdqs.standard_quantity, CASE WHEN pl.name = 'Pan Dulce' THEN 12 ELSE 0 END) AS standard_quantity"
        : "CASE WHEN pl.name = 'Pan Dulce' THEN 12 ELSE 0 END AS standard_quantity";

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
            pl.sort_order as product_line_sort,
            {$standardQuantitySelect}
        FROM products p
        LEFT JOIN dough_types dt ON p.dough_type_id = dt.id
        LEFT JOIN product_lines pl ON dt.product_line_id = pl.id
        {$standardQuantityJoin}
        ORDER BY 
            CASE WHEN pl.sort_order IS NULL THEN 999 ELSE pl.sort_order END,
            CASE WHEN pl.name IS NULL THEN 'ZZZ_Unclassified' ELSE pl.name END,
            dt.name,
            p.name
    ")->fetchAll();
    
    // PERFORMANCE OPTIMIZATION: Process customer routes from the main query
    $customerRoutes = [];
    $customerDrivers = [];
    foreach ($customers as $customer) {
        if ($customer['route_days']) {
            $customerRoutes[$customer['id']] = array_map(function($day) {
                $day = (int)$day;
                return $day === 0 ? 7 : $day;
            }, explode(',', $customer['route_days']));
            sort($customerRoutes[$customer['id']]);
        } else {
            $customerRoutes[$customer['id']] = [];
        }
        $customerDrivers[$customer['id']] = $customer['route_driver_ids']
            ? array_map('intval', explode(',', $customer['route_driver_ids']))
            : [];
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
                        CASE WHEN so.day_of_week = 0 THEN 7 ELSE so.day_of_week END AS day_of_week,
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
    
    // Load a compact day-level order summary for the route/order coverage view.
    // This stays lightweight and works even when the detailed order grid is lazy-loaded.
    $orderDaySummary = [];
    $orderDayRows = $db->query("
        SELECT customer_id, day_of_week,
               COUNT(DISTINCT product_id) AS product_count,
               SUM(quantity) AS quantity_total
        FROM standing_orders
        GROUP BY customer_id, day_of_week
    ")->fetchAll();
    foreach ($orderDayRows as $orderDayRow) {
        $day = (int)$orderDayRow['day_of_week'];
        $day = $day === 0 ? 7 : $day;
        $orderDaySummary[(int)$orderDayRow['customer_id']][$day] = [
            'product_count' => (int)$orderDayRow['product_count'],
            'quantity_total' => (int)$orderDayRow['quantity_total']
        ];
    }

    $customerDaySummary = [];
    foreach ($customers as $customer) {
        $customerId = (int)$customer['id'];
        $customerDaySummary[$customerId] = [
            'route_days' => $customerRoutes[$customerId] ?? [],
            'order_days' => $orderDaySummary[$customerId] ?? []
        ];
    }

    $loadTime = number_format((microtime(true) - $startTime) * 1000, 2);
    
    // PERFORMANCE LOG: Add performance monitoring
    error_log("Standing Orders Manager Load Time: {$loadTime}ms for " . count($customers) . " customers and " . count($products) . " products");
    
} catch (Exception $e) {
    echo '<div class="error">Error loading data: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}

$page_title = bakery_t('page.standing_orders_manager');
$days = [
    1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 
    5 => 'Fri', 6 => 'Sat', 7 => 'Sun'
];
$allWeekDays = array_keys($days);
// Full-week mode restores legacy standing_orders.php behavior: edit any weekday,
// not only standing_routes days. Default remains route-focused for routed customers.
$showFullWeek = isset($_GET['full_week']) && (string)$_GET['full_week'] === '1';

/**
 * Weekdays available for editing for one customer.
 * No-route customers always get Mon–Sun (legacy parity).
 *
 * @param int[] $routeDays
 * @param int[] $orderDays
 * @param int[] $allWeekDays
 * @return int[]
 */
$somEditDays = static function (array $routeDays, array $orderDays, array $allWeekDays, $showFullWeek) {
    if ($showFullWeek || $routeDays === []) {
        return $allWeekDays;
    }
    $days = array_values(array_unique(array_merge($routeDays, $orderDays)));
    sort($days);
    return $days !== [] ? $days : $allWeekDays;
};
?>

<div class="som-container" data-full-week="<?php echo $showFullWeek ? '1' : '0'; ?>">
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
            <button id="week-view-toggle" class="btn btn-secondary" title="Show every weekday for every customer, like the classic Standing Orders page">
                <?php echo $showFullWeek ? '📅 Route Days' : '📅 Full Week'; ?>
            </button>
            <button id="view-toggle" class="btn btn-secondary">👁️ Compact View</button>
            <button id="filter-toggle" class="btn btn-primary">🔍 Filters</button>
            <button id="changes-toggle" class="btn btn-info">📊 Changes</button>
        </div>
    </div>
    <?php if ($showFullWeek): ?>
        <div class="som-week-mode-banner" role="status">
            Full week editing is on — every customer shows Monday–Sunday, matching the classic Standing Orders page. Route days stay marked for reference.
        </div>
    <?php endif; ?>
    
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
                <optgroup label="Customers without Routes (full week editable)">
                    <?php foreach ($customersWithoutOrders as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>">
                            <?php echo htmlspecialchars($customer['name']); ?>
                            (No routes — <?php echo (int)$customer['order_count']; ?> orders)
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
        </div>

        <div class="filter-section">
            <label for="driver-filter">Driver:</label>
            <select id="driver-filter" multiple size="4" title="Hold Ctrl or Command to select multiple drivers">
                <?php foreach ($drivers as $driver): ?>
                    <option value="<?php echo (int)$driver['id']; ?>"><?php echo htmlspecialchars($driver['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <small class="filter-help">Select one or more drivers.</small>
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

    <section class="quick-standing-order" aria-labelledby="quick-standing-order-title">
        <div>
            <h2 id="quick-standing-order-title">Add a product for one day</h2>
            <p>Choose a customer, day, product, and quantity. This saves immediately.</p>
        </div>
        <form id="quick-standing-order-form" class="quick-standing-order-form">
            <label>Customer
                <select name="customer_id" required>
                    <option value="">Choose customer</option>
                    <?php foreach ($customers as $customer): ?><option value="<?php echo (int)$customer['id']; ?>"><?php echo htmlspecialchars($customer['name']); ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Day
                <select name="day_of_week" required>
                    <?php foreach ($days as $num => $name): ?><option value="<?php echo $num; ?>"><?php echo htmlspecialchars($name); ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Product
                <select name="product_id" required>
                    <option value="">Choose product</option>
                    <?php foreach ($productsByProductLine as $productLine => $lineData): ?>
                        <optgroup label="<?php echo htmlspecialchars($productLine); ?>">
                            <?php foreach ($lineData['products'] as $product): ?><option value="<?php echo (int)$product['id']; ?>"><?php echo htmlspecialchars($product['name']); ?></option><?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Quantity
                <input type="number" name="quantity" min="1" step="1" value="1" required>
            </label>
            <button class="btn btn-primary" type="submit">Add standing order</button>
        </form>
        <div id="quick-standing-order-status" class="quick-standing-order-status" aria-live="polite"></div>
    </section>

    <!-- Day-specific route/order coverage -->
    <div id="day-coverage-panel" class="day-coverage-panel">
        <div class="coverage-header">
            <div>
                <h2>Route &amp; Order Coverage</h2>
                <p>Use the Day filter to find customers who need products added or changed. Apply Pan Dulce from the Resolve column to set the standard standing order.</p>
            </div>
            <span id="coverage-day-label" class="coverage-day-label">All days</span>
            <label class="coverage-sort-control">Sort by
                <select id="coverage-status-sort">
                    <option value="default">Status (default)</option>
                    <option value="attention">Needs attention first</option>
                    <option value="route">Route (earliest day)</option>
                    <option value="route-orders">Route + orders</option>
                    <option value="route-empty">Route, no orders</option>
                    <option value="no-route-orders">Orders, no route</option>
                    <option value="no-route-empty">No route / no orders</option>
                </select>
            </label>
        </div>

        <div class="coverage-summary" aria-live="polite">
            <div class="coverage-stat coverage-stat-route-orders"><strong id="coverage-route-orders">0</strong><span>Route + orders</span></div>
            <div class="coverage-stat coverage-stat-route-empty"><strong id="coverage-route-empty">0</strong><span>Route, no orders</span></div>
            <div class="coverage-stat coverage-stat-no-route-orders"><strong id="coverage-no-route-orders">0</strong><span>Orders, no route</span></div>
            <div class="coverage-stat coverage-stat-no-route-empty"><strong id="coverage-no-route-empty">0</strong><span>No route / no orders</span></div>
        </div>

        <div class="coverage-table-wrap">
            <table id="day-coverage-table" class="day-coverage-table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Status</th>
                        <th>Standing route</th>
                        <th>Orders</th>
                        <th>Resolve</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $customer):
                        $customerId = (int)$customer['id'];
                        $summary = $customerDaySummary[$customerId] ?? ['route_days' => [], 'order_days' => []];
                        $routeDayNumbers = $summary['route_days'];
                        $orderDayNumbers = array_map('intval', array_keys($summary['order_days']));
                        sort($orderDayNumbers);
                        $orderDetails = [];
                        foreach ($summary['order_days'] as $dayNumber => $orderDetail) {
                            $orderDetails[(int)$dayNumber] = $orderDetail;
                        }
                    ?>
                        <tr class="coverage-row"
                            data-customer-id="<?php echo $customerId; ?>"
                            data-customer-name="<?php echo htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-route-days="<?php echo htmlspecialchars(implode(',', $routeDayNumbers), ENT_QUOTES, 'UTF-8'); ?>"
                            data-driver-ids="<?php echo htmlspecialchars(implode(',', $customerDrivers[$customerId] ?? []), ENT_QUOTES, 'UTF-8'); ?>"
                            data-order-days="<?php echo htmlspecialchars(implode(',', $orderDayNumbers), ENT_QUOTES, 'UTF-8'); ?>"
                            data-order-details="<?php echo htmlspecialchars(json_encode($orderDetails), ENT_QUOTES, 'UTF-8'); ?>">
                            <td class="coverage-customer">
                                <strong><?php echo htmlspecialchars($customer['name']); ?></strong>
                                <?php if (!empty($customer['zone'])): ?><small><?php echo htmlspecialchars($customer['zone']); ?></small><?php endif; ?>
                            </td>
                            <td class="coverage-status-cell"><span class="coverage-status">—</span></td>
                            <td class="coverage-route-cell">—</td>
                            <td class="coverage-orders-cell">—</td>
                            <td class="coverage-actions-cell">—</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
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
                <p class="no-orders-help">These customers have no standing routes yet, but you can still set standing orders for any day of the week — same as the classic Standing Orders page. Add routes later when delivery days are known.</p>
                
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
                                $customerRouteDays = $customerRoutes[$customer['id']] ?? [];
                                $customerOrderDays = array_map('intval', array_keys($customerDaySummary[$customer['id']]['order_days'] ?? []));
                                // No-route customers always get the full week (legacy Standing Orders parity).
                                $customerActiveDays = $somEditDays($customerRouteDays, $customerOrderDays, $allWeekDays, true);
                        ?>
                            <div class="customer-summary-card no-route-customer" data-customer-id="<?php echo $customer['id']; ?>"
                                 data-customer-name="<?php echo htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                 data-route-days="<?php echo htmlspecialchars(implode(',', $customerRouteDays), ENT_QUOTES, 'UTF-8'); ?>"
                                 data-driver-ids="<?php echo htmlspecialchars(implode(',', $customerDrivers[$customer['id']] ?? []), ENT_QUOTES, 'UTF-8'); ?>"
                                 data-order-days="<?php echo htmlspecialchars(implode(',', $customerOrderDays), ENT_QUOTES, 'UTF-8'); ?>"
                                 data-edit-days="<?php echo htmlspecialchars(implode(',', $customerActiveDays), ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="customer-summary-header" onclick="toggleCustomerDetails(this)">
                                    <div class="customer-summary-info">
                                        <h4><a class="customer-hub-link" href="customer_record.php?customer_id=<?php echo (int)$customer['id']; ?>" onclick="event.stopPropagation()"><?php echo htmlspecialchars($customer['name']); ?></a></h4>
                                        <span class="customer-summary-details">
                                            <?php if ($customer['address']): ?>
                                                📍 <?php echo htmlspecialchars($customer['address']); ?>
                                            <?php endif; ?>
                                            | 📦 <?php echo $customer['order_count']; ?> orders
                                            | 📅 No delivery routes — full week editable
                                            <span class="no-orders-badge">📋 Ready for Orders</span>
                                        </span>
                                    </div>
                                    <div class="customer-summary-actions">
                                        <div class="standard-quantity-actions" onclick="event.stopPropagation()" title="Apply Pan Dulce standard quantities for this customer">
                                            <span>Pan Dulce:</span>
                                            <button type="button" class="btn btn-sm btn-outline apply-standard-btn" data-multiplier="1">1×</button>
                                            <button type="button" class="btn btn-sm btn-outline apply-standard-btn" data-multiplier="1.5">1.5×</button>
                                            <button type="button" class="btn btn-sm btn-outline apply-standard-btn" data-multiplier="2">2×</button>
                                        </div>
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
                                    <div class="days-header">
                                        <div class="product-column">Product</div>
                                        <?php foreach ($customerActiveDays as $dayNum): ?>
                                            <div class="day-column is-non-route-day" data-day="<?php echo $dayNum; ?>" data-is-route-day="0">
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
                                                    <div class="product-row <?php echo $hasOrders ? 'has-orders' : 'no-orders'; ?>" data-product-id="<?php echo $product['id']; ?>" data-standard-quantity="<?php echo (int)$product['standard_quantity']; ?>" data-standard-enabled="<?php echo (int)$product['standard_quantity'] > 0 ? '1' : '0'; ?>">
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
                                                            <div class="quantity-cell is-non-route-day" data-day="<?php echo $dayNum; ?>" data-is-route-day="0">
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
                    $customerRouteDays = $customerRoutes[$customer['id']] ?? [];
                    if (empty($customerRouteDays)) continue; // Skip customers with no route days
                    $customerOrderDays = array_map('intval', array_keys($customerDaySummary[$customer['id']]['order_days'] ?? []));
                    $customerActiveDays = $somEditDays($customerRouteDays, $customerOrderDays, $allWeekDays, $showFullWeek);
                ?>
                    <div class="customer-section has-route-customer" 
                         data-customer-id="<?php echo $customer['id']; ?>"
                         data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                         data-zone="<?php echo htmlspecialchars($zone); ?>"
                         data-route-days="<?php echo htmlspecialchars(implode(',', $customerRouteDays), ENT_QUOTES, 'UTF-8'); ?>"
                         data-driver-ids="<?php echo htmlspecialchars(implode(',', $customerDrivers[$customer['id']] ?? []), ENT_QUOTES, 'UTF-8'); ?>"
                         data-order-days="<?php echo htmlspecialchars(implode(',', $customerOrderDays), ENT_QUOTES, 'UTF-8'); ?>"
                         data-edit-days="<?php echo htmlspecialchars(implode(',', $customerActiveDays), ENT_QUOTES, 'UTF-8'); ?>">
                        
                        <div class="customer-header">
                            <div class="customer-info">
                                <h3><a class="customer-hub-link" href="customer_record.php?customer_id=<?php echo (int)$customer['id']; ?>" onclick="event.stopPropagation()"><?php echo htmlspecialchars($customer['name']); ?></a></h3>
                                <span class="customer-details">
                                    <?php if ($customer['address']): ?>
                                        📍 <?php echo htmlspecialchars($customer['address']); ?>
                                    <?php endif; ?>
                                    | 📦 <?php echo $customer['order_count']; ?> orders
                                    | 📅 Route: <?php echo implode(', ', array_map(function($d) use ($days) { return $days[$d]; }, $customerRouteDays)); ?>
                                    <?php if ($showFullWeek): ?>
                                        | Editing: Full week
                                    <?php elseif (array_diff($customerActiveDays, $customerRouteDays)): ?>
                                        | + order days without route
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="standard-quantity-actions" onclick="event.stopPropagation()" title="Apply Pan Dulce standard quantities for this customer">
                                <span>Pan Dulce:</span>
                                <button type="button" class="btn btn-sm btn-outline apply-standard-btn" data-multiplier="1">1×</button>
                                <button type="button" class="btn btn-sm btn-outline apply-standard-btn" data-multiplier="1.5">1.5×</button>
                                <button type="button" class="btn btn-sm btn-outline apply-standard-btn" data-multiplier="2">2×</button>
                            </div>
                            <button class="customer-toggle" data-customer-id="<?php echo $customer['id']; ?>">
                                <span class="toggle-icon">▼</span>
                            </button>
                        </div>
                        
                        <div class="customer-orders" data-customer-id="<?php echo $customer['id']; ?>">
                            <div class="days-header">
                                <div class="product-column">Product</div>
                                <?php foreach ($customerActiveDays as $dayNum): 
                                    $isRouteDay = in_array($dayNum, $customerRouteDays, true);
                                ?>
                                    <div class="day-column <?php echo $isRouteDay ? 'is-route-day' : 'is-non-route-day'; ?>" data-day="<?php echo $dayNum; ?>" data-is-route-day="<?php echo $isRouteDay ? '1' : '0'; ?>" title="<?php echo $isRouteDay ? 'Route day' : 'No standing route on this day'; ?>">
                                        <?php echo $days[$dayNum]; ?><?php echo $isRouteDay ? '' : ' *'; ?>
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
                                                        <div class="product-row <?php echo $hasOrders ? 'has-orders' : 'no-orders'; ?>" data-product-id="<?php echo $product['id']; ?>" data-standard-quantity="<?php echo (int)$product['standard_quantity']; ?>" data-standard-enabled="<?php echo (int)$product['standard_quantity'] > 0 ? '1' : '0'; ?>">
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
                                                                $isRouteDay = in_array($dayNum, $customerRouteDays, true);
                                                                if (isset($existingOrders[$customer['id']][$product['id']][$dayNum])) {
                                                                    $quantity = $existingOrders[$customer['id']][$product['id']][$dayNum]['quantity'];
                                                                    $customerTotal += $quantity;
                                                                }
                                                            ?>
                                                                <div class="quantity-cell <?php echo $isRouteDay ? 'is-route-day' : 'is-non-route-day'; ?>" data-day="<?php echo $dayNum; ?>" data-is-route-day="<?php echo $isRouteDay ? '1' : '0'; ?>">
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

.quick-standing-order{display:grid;grid-template-columns:minmax(220px,1fr);gap:12px;margin:0 0 22px;padding:16px 18px;border:1px solid #cfe2d4;border-radius:10px;background:#f4fbf5}.quick-standing-order h2{margin:0 0 4px;color:#285b39;font-size:1.15rem}.quick-standing-order p{margin:0;color:#617067;font-size:.9rem}.quick-standing-order-form{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr)) auto;gap:10px;align-items:end}.quick-standing-order-form label{display:flex;flex-direction:column;gap:5px;font-weight:600;color:#45544b;font-size:.85rem}.quick-standing-order-form select,.quick-standing-order-form input{width:100%;min-height:40px;padding:7px;border:1px solid #b8c9bd;border-radius:5px;background:#fff;font-size:16px}.quick-standing-order-form .btn{min-height:40px;white-space:nowrap}.quick-standing-order-status{min-height:1.2em;color:#2d6b40;font-size:.9rem}.coverage-sort-control{display:flex;align-items:center;gap:7px;font-size:.82rem;font-weight:600;color:#506057;white-space:nowrap}.coverage-sort-control select{padding:6px 8px;border:1px solid #c5d0c9;border-radius:5px;background:#fff;font-size:14px}
.day-coverage-panel {
    background: #fff;
    border: 1px solid #dfe5ec;
    border-radius: 10px;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    overflow: hidden;
}

.coverage-header {
    padding: 18px 20px;
    background: linear-gradient(135deg, #f7f9fc 0%, #eef3f8 100%);
    border-bottom: 1px solid #e5eaf0;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 15px;
}

.coverage-header h2 {
    margin: 0 0 5px;
    color: #2c3e50;
    font-size: 1.35rem;
}

.coverage-header p {
    margin: 0;
    color: #6c757d;
    font-size: .9rem;
}

.coverage-day-label {
    background: #007bff;
    color: #fff;
    border-radius: 15px;
    padding: 6px 12px;
    font-size: .85rem;
    font-weight: 600;
    white-space: nowrap;
}

.coverage-summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(150px, 1fr));
    gap: 10px;
    padding: 15px 20px;
}

.coverage-stat {
    border-radius: 7px;
    padding: 10px 12px;
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 10px;
    color: #34495e;
    background: #f8f9fa;
    border-left: 4px solid #6c757d;
}

.coverage-stat strong { font-size: 1.35rem; }
.coverage-stat span { font-size: .78rem; text-align: right; }
.coverage-stat-route-orders { border-left-color: #28a745; background: #f1fbf4; }
.coverage-stat-route-empty { border-left-color: #f39c12; background: #fff9ef; }
.coverage-stat-no-route-orders { border-left-color: #dc3545; background: #fff4f4; }
.coverage-stat-no-route-empty { border-left-color: #6c757d; }

.coverage-table-wrap {
    max-height: 420px;
    overflow: auto;
    border-top: 1px solid #edf0f3;
}

.day-coverage-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
}

.day-coverage-table th,
.day-coverage-table td {
    padding: 9px 12px;
    border-bottom: 1px solid #edf0f3;
    text-align: left;
    vertical-align: middle;
}

.day-coverage-table th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: #f8f9fa;
    color: #495057;
    font-size: .8rem;
    text-transform: uppercase;
    letter-spacing: .03em;
}

.coverage-customer small {
    display: block;
    color: #6c757d;
    font-size: .75rem;
    margin-top: 2px;
}

.coverage-status {
    display: inline-block;
    border-radius: 12px;
    padding: 4px 9px;
    font-size: .78rem;
    font-weight: 600;
    white-space: nowrap;
}

.coverage-status.route-orders { color: #176b2c; background: #d9f3df; }
.coverage-status.route-empty { color: #8a5700; background: #fff0c9; }
.coverage-status.no-route-orders { color: #9c1c28; background: #f9d7da; }
.coverage-status.no-route-empty { color: #59636d; background: #e9ecef; }
.coverage-row.coverage-highlight { background: #fffaf0; }
.coverage-actions-cell { white-space: nowrap; }
.coverage-resolve-btn { font-size: .78rem; padding: 4px 10px; }

@media (max-width: 800px) {
    .quick-standing-order-form { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .quick-standing-order-form .btn { width: 100%; }
    .coverage-summary { grid-template-columns: repeat(2, minmax(140px, 1fr)); }
    .coverage-header { flex-direction: column; }
}

@media (max-width: 520px) {
    .quick-standing-order { padding: 14px; }
    .quick-standing-order-form { grid-template-columns: 1fr; }
    .coverage-sort-control { width: 100%; justify-content: space-between; }
    .coverage-sort-control select { flex: 1; }
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

.customer-header .customer-hub-link,
.customer-summary-info .customer-hub-link {
    color: inherit;
    text-decoration: none;
}

.customer-header .customer-hub-link:hover,
.customer-summary-info .customer-hub-link:hover {
    text-decoration: underline;
}

.customer-info h2 {
    margin: 0 0 5px 0;
    font-size: 1.3rem;
}

.customer-details {
    font-size: 0.9rem;
    opacity: 0.9;
}

.standard-quantity-actions {
    display: flex;
    align-items: center;
    gap: 5px;
    margin-left: auto;
    margin-right: 12px;
    font-size: 0.78rem;
    white-space: nowrap;
}

.standard-quantity-actions .btn {
    padding: 4px 8px;
    border-color: rgba(255,255,255,0.65);
    color: white;
    background: rgba(255,255,255,0.14);
    font-size: 0.78rem;
}

.standard-quantity-actions .btn:hover {
    background: rgba(255,255,255,0.3);
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

.som-week-mode-banner {
    margin: 0 0 16px;
    padding: 10px 14px;
    border-radius: 8px;
    background: #e8f4ff;
    border: 1px solid #b6d4fe;
    color: #084298;
    font-size: 0.92rem;
}

.no-orders-help {
    margin: 0 0 14px;
    padding: 10px 12px;
    background: #f8f9fa;
    border-left: 4px solid #17a2b8;
    color: #495057;
    font-size: 0.9rem;
}

.day-column.is-route-day {
    box-shadow: inset 0 -3px 0 #28a745;
}

.day-column.is-non-route-day {
    color: #6c757d;
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
    .customer-header {
        flex-wrap: wrap;
        gap: 10px;
    }

    .standard-quantity-actions {
        order: 3;
        width: 100%;
        margin: 0;
    }

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
    let saveInFlight = false;
    let saveQueued = false;
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
        const customerSection = document.querySelector(`.customer-section[data-customer-id="${customerId}"], .customer-summary-card[data-customer-id="${customerId}"]`);
        if (!customerSection) return;
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
    
    // One delegated handler covers the initial grid and any lazily loaded inputs.
    // The 1.5s auto-save delay already debounces typing, so a second input
    // handler only added duplicate work and timing races.
    document.addEventListener('input', function(event) {
        const input = event.target.closest('.quantity-input');
        if (!input) return;

        const original = parseInt(input.dataset.original) || 0;
        const current = parseInt(input.value) || 0;
        const productRow = input.closest('.product-row');

        if (current !== original) {
            input.classList.add('changed');
            productRow.classList.add('changed');
            changedInputs.add(input);
        } else {
            input.classList.remove('changed');
            productRow.classList.remove('changed');
            changedInputs.delete(input);
        }

        updateProductTotals(input);
        updateBulkSaveButton();
        updateChangesPanel();
        updateAutoSaveStatus(changedInputs.size > 0 ? 'pending' : 'idle');
        scheduleAutoSave();
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

        // Keep saves single-flight. A slow request must not overlap with the
        // next debounce window and produce competing writes/network errors.
        if (saveInFlight) {
            saveQueued = true;
            return;
        }
        saveInFlight = true;

        // Snapshot the inputs for this request. Inputs changed while the request
        // is in flight must remain pending instead of being cleared by an older
        // successful response.
        const inputsToSave = Array.from(changedInputs);
        const updates = inputsToSave.map(input => ({
            customer_id: input.dataset.customerId,
            product_id: input.dataset.productId,
            day_of_week: input.dataset.day,
            quantity: parseInt(input.value) || 0
        }));

        const bulkSaveBtn = document.getElementById('bulk-save');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        
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
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: new URLSearchParams({
                action: 'bulk_save',
                updates: JSON.stringify(updates)
            })
        })
        .then(async response => {
            const responseText = await response.text();
            let data;
            try {
                data = JSON.parse(responseText);
            } catch (parseError) {
                throw new Error(responseText.trim() || `Save failed (HTTP ${response.status})`);
            }
            if (!response.ok) {
                throw new Error(data.error || `Save failed (HTTP ${response.status})`);
            }
            return data;
        })
        .then(data => {
            if (data.success) {
                // Update original values and clear changes
                inputsToSave.forEach((input, index) => {
                    // Only clear an input if it still has the value that was sent.
                    // A newer edit should stay in changedInputs for the next save.
                    if (String(parseInt(input.value) || 0) === String(updates[index].quantity)) {
                        input.dataset.original = input.value;
                        input.classList.remove('changed');
                        input.closest('.product-row').classList.remove('changed');
                        changedInputs.delete(input);
                    }
                });
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
            saveInFlight = false;
            updateBulkSaveButton();

            // Send the latest values once the current request has completed.
            if (saveQueued) {
                saveQueued = false;
                scheduleAutoSave();
            }
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
    const driverFilter = document.getElementById('driver-filter');
    const productLineFilter = document.getElementById('product-line-filter');
    const dayFilter = document.getElementById('day-filter');
    const coverageStatusSort = document.getElementById('coverage-status-sort');

    const quickStandingOrderForm = document.getElementById('quick-standing-order-form');
    const quickStandingOrderStatus = document.getElementById('quick-standing-order-status');
    if (quickStandingOrderForm) {
        quickStandingOrderForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            const submitButton = quickStandingOrderForm.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            quickStandingOrderStatus.textContent = 'Saving…';
            try {
                const body = new URLSearchParams(new FormData(quickStandingOrderForm));
                body.append('action', 'save_order');
                const response = await fetch('standing_orders_manager.php', { method: 'POST', body: body.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
                const data = await response.json();
                if (!response.ok || !data.success) throw new Error(data.error || 'Could not save standing order');
                quickStandingOrderStatus.textContent = 'Saved. Refreshing order coverage…';
                setTimeout(function () { window.location.reload(); }, 250);
            } catch (error) {
                quickStandingOrderStatus.textContent = error.message;
                submitButton.disabled = false;
            }
        });
    }
    
    const FILTER_STORAGE_KEY = 'preserveStandingOrdersManagerFilters';

    function persistFilters() {
        const payload = {
            customer: customerFilter ? customerFilter.value : '',
            drivers: driverFilter ? Array.from(driverFilter.selectedOptions).map(option => option.value) : [],
            productLine: productLineFilter ? productLineFilter.value : '',
            day: dayFilter ? dayFilter.value : '',
        };
        try {
            localStorage.setItem(FILTER_STORAGE_KEY, JSON.stringify(payload));
        } catch (error) {
            // Ignore quota / private-mode failures; filters still work for the session.
        }
    }

    function restoreFiltersFromStorage() {
        try {
            const raw = localStorage.getItem(FILTER_STORAGE_KEY);
            if (!raw) return false;
            const payload = JSON.parse(raw);
            if (!payload || typeof payload !== 'object') return false;
            if (customerFilter && payload.customer) customerFilter.value = payload.customer;
            if (productLineFilter && payload.productLine) productLineFilter.value = payload.productLine;
            if (dayFilter && payload.day) dayFilter.value = payload.day;
            if (driverFilter && Array.isArray(payload.drivers)) {
                Array.from(driverFilter.options).forEach(option => {
                    option.selected = payload.drivers.includes(option.value);
                });
            }
            return true;
        } catch (error) {
            return false;
        }
    }

    [customerFilter, driverFilter, productLineFilter, dayFilter].forEach(filter => {
        filter.addEventListener('change', function () {
            applyFilters();
            persistFilters();
        });
    });
    if (dayFilter && quickStandingOrderForm) {
        dayFilter.addEventListener('change', function () {
            const quickDay = quickStandingOrderForm.querySelector('[name="day_of_week"]');
            if (dayFilter.value && quickDay) quickDay.value = dayFilter.value;
        });
    }
    
    document.getElementById('clear-filters').addEventListener('click', function() {
        customerFilter.value = '';
        Array.from(driverFilter.options).forEach(option => { option.selected = false; });
        productLineFilter.value = '';
        dayFilter.value = '';
        applyFilters();
        persistFilters();
    });

    document.getElementById('week-view-toggle')?.addEventListener('click', function () {
        if (typeof changedInputs !== 'undefined' && changedInputs.size > 0) {
            const proceed = window.confirm(
                'You have unsaved quantity changes. Switching week view reloads the page and will discard them. Continue?'
            );
            if (!proceed) return;
        }
        const url = new URL(window.location.href);
        const enablingFullWeek = url.searchParams.get('full_week') !== '1';
        if (enablingFullWeek) {
            url.searchParams.set('full_week', '1');
            try { localStorage.setItem('somFullWeek', '1'); } catch (error) {}
        } else {
            url.searchParams.delete('full_week');
            try { localStorage.setItem('somFullWeek', '0'); } catch (error) {}
        }
        persistFilters();
        window.location.href = url.toString();
    });

    const dayLabels = {1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri', 6: 'Sat', 7: 'Sun'};

    function parseDayList(value) {
        return (value || '').split(',').map(Number).filter(day => day >= 1 && day <= 7);
    }

    function parseNumberList(value) {
        return (value || '').split(',').map(Number).filter(number => number > 0);
    }

    // Apply the configured Pan Dulce standard to every active route day for one customer.
    // Values are dispatched through the normal input handler so autosave and the pending
    // changes panel treat this exactly like manually edited quantities.
    document.querySelectorAll('.apply-standard-btn').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            const customerSection = button.closest('.customer-section, .customer-summary-card');
            if (!customerSection) return;

            // Expand collapsed no-route cards so inputs exist in an open panel.
            const details = customerSection.querySelector('.customer-full-details');
            if (details && details.style.display === 'none') {
                details.style.display = 'block';
                const toggle = customerSection.querySelector('.expand-toggle');
                if (toggle) toggle.textContent = '▲';
            }

            const multiplier = Number(button.dataset.multiplier || 1);
            const rows = customerSection.querySelectorAll('.product-row[data-standard-enabled="1"]');
            const routeDays = parseDayList(customerSection.dataset.routeDays);
            const fullWeek = document.querySelector('.som-container')?.dataset.fullWeek === '1'
                || customerSection.classList.contains('no-route-customer');
            let updated = 0;
            rows.forEach(function (row) {
                const standard = Number(row.dataset.standardQuantity || 0);
                const quantity = Math.round(standard * multiplier);
                row.querySelectorAll('.quantity-input').forEach(function (input) {
                    const day = Number(input.dataset.day);
                    // Route-focused mode: only fill route days. Full week / no-route: fill visible edit days.
                    if (!fullWeek && routeDays.length && !routeDays.includes(day)) {
                        return;
                    }
                    input.value = quantity;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    updated++;
                });
            });

            if (updated > 0) {
                // Save immediately instead of waiting for debounce or requiring
                // the user to find the separate Save Changes button.
                if (autoSaveTimeout) {
                    clearTimeout(autoSaveTimeout);
                    autoSaveTimeout = null;
                }
                performSave(true);
            } else {
                showNotification('No Pan Dulce standard quantities are configured for this customer.', 'warning');
            }
        });
    });

    function sortCoverageRows() {
        const tbody = document.querySelector('#day-coverage-table tbody');
        if (!tbody) return;
        const mode = coverageStatusSort ? coverageStatusSort.value : 'default';
        const rank = mode === 'attention'
            ? {'route-empty': 1, 'no-route-orders': 2, 'route-orders': 3, 'no-route-empty': 4}
            : {[mode]: 1};
        Array.from(tbody.querySelectorAll('.coverage-row'))
            .sort(function (a, b) {
                if (mode === 'default') return Number(a.dataset.originalIndex) - Number(b.dataset.originalIndex);
                if (mode === 'route') {
                    const aRoute = parseDayList(a.dataset.routeDays);
                    const bRoute = parseDayList(b.dataset.routeDays);
                    return (aRoute[0] || 99) - (bRoute[0] || 99)
                        || a.dataset.customerName.localeCompare(b.dataset.customerName);
                }
                const aRank = rank[a.dataset.coverageStatus] || 99;
                const bRank = rank[b.dataset.coverageStatus] || 99;
                return aRank - bRank || a.dataset.customerName.localeCompare(b.dataset.customerName);
            })
            .forEach(function (row) { tbody.appendChild(row); });
    }

    function updateCoverage(dayValue, customerValue, driverValues) {
        const selectedDay = dayValue ? Number(dayValue) : null;
        const coverageLabel = document.getElementById('coverage-day-label');
        coverageLabel.textContent = selectedDay ? dayLabels[selectedDay] : 'All days';

        const counts = {
            routeOrders: 0,
            routeEmpty: 0,
            noRouteOrders: 0,
            noRouteEmpty: 0
        };

        document.querySelectorAll('.coverage-row').forEach(row => {
            const routeDays = parseDayList(row.dataset.routeDays);
            const orderDays = parseDayList(row.dataset.orderDays);
            const driverIds = parseNumberList(row.dataset.driverIds);
            const customerMatches = !customerValue || row.dataset.customerId === customerValue;
            const driverMatches = !driverValues.length || driverValues.some(driverId => driverIds.includes(Number(driverId)));
            const hasRoute = selectedDay ? routeDays.includes(selectedDay) : routeDays.length > 0;
            const hasOrders = selectedDay ? orderDays.includes(selectedDay) : orderDays.length > 0;
            const visible = customerMatches && driverMatches;
            row.style.display = visible ? '' : 'none';
            if (!visible) return;

            const status = row.querySelector('.coverage-status');
            const routeCell = row.querySelector('.coverage-route-cell');
            const ordersCell = row.querySelector('.coverage-orders-cell');
            const actionsCell = row.querySelector('.coverage-actions-cell');
            status.className = 'coverage-status';
            row.classList.remove('coverage-highlight');
            if (actionsCell) actionsCell.textContent = '—';

            if (hasRoute && hasOrders) {
                counts.routeOrders++;
                row.dataset.coverageStatus = 'route-orders';
                status.classList.add('route-orders');
                status.textContent = 'Route + orders';
            } else if (hasRoute) {
                counts.routeEmpty++;
                row.dataset.coverageStatus = 'route-empty';
                status.classList.add('route-empty');
                status.textContent = 'Route — add products';
                row.classList.add('coverage-highlight');
                if (actionsCell) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn btn-sm btn-primary coverage-resolve-btn';
                    btn.textContent = 'Apply Pan Dulce';
                    btn.title = 'Set standard Pan Dulce standing order for this customer'
                        + (selectedDay ? (' on ' + dayLabels[selectedDay]) : ' on all route days');
                    btn.dataset.customerId = row.dataset.customerId;
                    if (selectedDay) btn.dataset.dayOfWeek = String(selectedDay);
                    actionsCell.textContent = '';
                    actionsCell.appendChild(btn);
                }
            } else if (hasOrders) {
                counts.noRouteOrders++;
                row.dataset.coverageStatus = 'no-route-orders';
                status.classList.add('no-route-orders');
                status.textContent = 'Orders — no route';
                row.classList.add('coverage-highlight');
            } else {
                counts.noRouteEmpty++;
                row.dataset.coverageStatus = 'no-route-empty';
                status.classList.add('no-route-empty');
                status.textContent = 'No route / no orders';
            }

            routeCell.textContent = selectedDay
                ? (hasRoute ? `Yes — ${dayLabels[selectedDay]}` : 'No route')
                : (routeDays.length ? routeDays.map(day => dayLabels[day]).join(', ') : 'No route');

            let orderDetails = {};
            try { orderDetails = JSON.parse(row.dataset.orderDetails || '{}'); } catch (error) { orderDetails = {}; }
            if (selectedDay) {
                const detail = orderDetails[selectedDay];
                ordersCell.textContent = detail
                    ? `${detail.product_count} product${detail.product_count == 1 ? '' : 's'} / ${detail.quantity_total} qty`
                    : 'No orders';
            } else {
                const orderSummary = Object.values(orderDetails);
                const productCount = orderSummary.reduce((total, detail) => total + Number(detail.product_count || 0), 0);
                const quantityTotal = orderSummary.reduce((total, detail) => total + Number(detail.quantity_total || 0), 0);
                ordersCell.textContent = orderSummary.length
                    ? `${orderSummary.length} day${orderSummary.length == 1 ? '' : 's'} / ${productCount} products / ${quantityTotal} qty`
                    : 'No orders';
            }
        });

        document.getElementById('coverage-route-orders').textContent = counts.routeOrders;
        document.getElementById('coverage-route-empty').textContent = counts.routeEmpty;
        document.getElementById('coverage-no-route-orders').textContent = counts.noRouteOrders;
        document.getElementById('coverage-no-route-empty').textContent = counts.noRouteEmpty;
        sortCoverageRows();
    }
    
    function applyFilters() {
        const customerValue = customerFilter.value;
        const driverValues = Array.from(driverFilter.selectedOptions).map(option => option.value);
        const productLineValue = productLineFilter.value;
        const dayValue = dayFilter.value;
        const fullWeek = document.querySelector('.som-container')?.dataset.fullWeek === '1';
        
        // Filter customer sections
        document.querySelectorAll('.customer-section, .customer-summary-card').forEach(section => {
            const customerId = section.dataset.customerId;
            const routeDays = parseDayList(section.dataset.routeDays);
            const driverIds = parseNumberList(section.dataset.driverIds);
            const orderDays = parseDayList(section.dataset.orderDays);
            const editDays = parseDayList(section.dataset.editDays);
            const driverMatches = !driverValues.length || driverValues.some(driverId => driverIds.includes(Number(driverId)));
            let showCustomer = (!customerValue || customerId === customerValue) && driverMatches;
            if (showCustomer && dayValue) {
                const selectedDay = Number(dayValue);
                // No-route / full-week customers can edit any day. Otherwise show if the
                // selected day is a route day, an existing order day, or an edit column.
                if (!routeDays.length || fullWeek || section.classList.contains('no-route-customer')) {
                    showCustomer = editDays.length ? editDays.includes(selectedDay) : true;
                } else {
                    showCustomer = routeDays.includes(selectedDay)
                        || orderDays.includes(selectedDay)
                        || editDays.includes(selectedDay);
                }
            }
            section.style.display = showCustomer ? 'block' : 'none';
        });

        // Keep empty zones/sections from taking up space after a day filter is applied.
        document.querySelectorAll('.zone-section').forEach(zone => {
            const hasVisibleCustomer = Array.from(zone.querySelectorAll('.customer-section, .customer-summary-card'))
                .some(section => section.style.display !== 'none');
            zone.style.display = hasVisibleCustomer ? '' : 'none';
        });
        document.querySelectorAll('.no-orders-section, .with-orders-section').forEach(section => {
            const hasVisibleZone = Array.from(section.querySelectorAll('.zone-section'))
                .some(zone => zone.style.display !== 'none');
            section.style.display = hasVisibleZone ? '' : 'none';
        });
        
        // Filter product line sections
        document.querySelectorAll('.product-line-section').forEach(section => {
            const productLine = section.dataset.productLine;
            const showProductLine = !productLineValue || productLine === productLineValue;
            section.style.display = showProductLine ? 'block' : 'none';
        });
        
        // Filter day columns
        // Hide both headers and quantity cells by their explicit day metadata.
        document.querySelectorAll('.day-column[data-day], .quantity-cell[data-day]').forEach(cell => {
            cell.style.display = !dayValue || cell.dataset.day === dayValue ? '' : 'none';
        });

        updateCoverage(dayValue, customerValue, driverValues);
    }

    document.querySelectorAll('.coverage-row').forEach(function (row, index) {
        row.dataset.originalIndex = index;
    });
    if (coverageStatusSort) coverageStatusSort.addEventListener('change', sortCoverageRows);

    document.getElementById('day-coverage-table')?.addEventListener('click', async function (event) {
        const button = event.target.closest('.coverage-resolve-btn');
        if (!button || button.disabled) return;
        event.preventDefault();

        const customerId = button.dataset.customerId;
        const dayOfWeek = button.dataset.dayOfWeek || '';
        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = 'Applying…';

        try {
            const body = new URLSearchParams({
                action: 'apply_pan_dulce_standard',
                customer_id: customerId,
                multiplier: '1',
            });
            if (dayOfWeek) body.append('day_of_week', dayOfWeek);

            const response = await fetch('standing_orders_manager.php', {
                method: 'POST',
                body: body.toString(),
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Could not apply Pan Dulce standard');
            }

            showNotification(
                'Applied standard Pan Dulce order (' + data.products + ' products, '
                    + data.days.length + ' day' + (data.days.length === 1 ? '' : 's') + ').',
                'success'
            );
            setTimeout(function () { window.location.reload(); }, 400);
        } catch (error) {
            button.disabled = false;
            button.textContent = originalText;
            showNotification(error.message, 'error');
        }
    });

    // Restore filters / full-week preference, then apply. Deep-link customer_id wins.
    (function initStandingOrdersViewState() {
        const params = new URLSearchParams(window.location.search);
        const customerId = params.get('customer_id');
        const hasFullWeekParam = params.has('full_week');

        // Remember full-week preference across visits (legacy parity with classic page).
        if (!hasFullWeekParam) {
            try {
                if (localStorage.getItem('somFullWeek') === '1') {
                    params.set('full_week', '1');
                    const next = window.location.pathname + '?' + params.toString() + window.location.hash;
                    window.location.replace(next);
                    return;
                }
            } catch (error) {}
        }

        let restored = false;
        if (!customerId) {
            restored = restoreFiltersFromStorage();
        }

        if (customerId && customerFilter) {
            const option = Array.from(customerFilter.options).find(function (opt) {
                return opt.value === String(customerId);
            });
            if (option) {
                customerFilter.value = String(customerId);
                const panel = document.getElementById('filters-panel');
                if (panel) panel.style.display = 'flex';
            }
        } else if (restored) {
            const panel = document.getElementById('filters-panel');
            if (panel && (customerFilter.value || dayFilter.value || productLineFilter.value
                || (driverFilter && Array.from(driverFilter.selectedOptions).length))) {
                panel.style.display = 'flex';
            }
        }

        applyFilters();

        if (customerId) {
            const section = document.querySelector(
                '.customer-section[data-customer-id="' + customerId + '"], .customer-summary-card[data-customer-id="' + customerId + '"]'
            );
            if (section) {
                section.classList.remove('collapsed');
                const details = section.querySelector('.customer-full-details');
                if (details) details.style.display = 'block';
                section.scrollIntoView({ block: 'start', behavior: 'smooth' });
            }
        }
    })();
    
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
                const customerSection = input.closest('.customer-section, .customer-summary-card');
                const customerName = customerSection ? customerSection.dataset.customerName : 'Customer';
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
