<link rel="stylesheet" href="<?php echo bakery_asset_href('css/standing_orders_manager.css'); ?>">
<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/customer_portal.php';
require_once 'includes/pan_dulce_standards.php';
require_once 'includes/sfb_origin.php';
require_once 'includes/standing_orders_manager_actions.php';
bakery_ensure_portal_schema($db);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    try {
        echo json_encode(bakery_standing_orders_manager_dispatch($db, $_POST));
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => bakery_error_message_for_user($e)]);
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




<script>
window.__STANDING_ORDERS_MANAGER__ = {
    customerCount: <?php echo (int)count($customers); ?>,
    largeCustomerSet: <?php echo count($customers) > 100 ? 'true' : 'false'; ?>,
    customersData: <?php
        $allCustomersData = array_merge($customersWithOrders, $customersWithoutOrders);
        echo bakery_json_for_html(array_map(static function ($customer) use ($customerRoutes, $existingOrders) {
            return [
                'id' => $customer['id'],
                'name' => $customer['name'],
                'zone' => $customer['zone'] ?: 'No Zone',
                'route_days' => $customerRoutes[$customer['id']] ?? [],
                'has_orders' => isset($existingOrders[$customer['id']]) && !empty($existingOrders[$customer['id']]),
                'order_count' => $customer['order_count']
            ];
        }, $allCustomersData), '[]');
    ?>
};
</script>
<script src="<?php echo bakery_asset_href('includes/standing_orders_manager.js'); ?>" defer></script>


<?php require_once 'includes/footer.php'; ?>
