<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/header.php';
require_once 'includes/nav.php';

// Set page title
$page_title = 'Daily Route';

// Get list of drivers
$drivers = [];
try {
    $drivers = $db->query("SELECT id, name FROM drivers ORDER BY name")->fetchAll();
} catch (Exception $e) {
    echo '<div class="error">Error loading drivers: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

// Days of the week
$days = [
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
    7 => 'Sunday'
];

// Get selected driver and day from GET parameters
$selectedDriverId = isset($_GET['driver_id']) ? (int)$_GET['driver_id'] : 0;
$selectedDay = isset($_GET['day']) ? (int)$_GET['day'] : date('N'); // Default to current day

// Get route data if driver and day are selected
$routeData = [];
$totalItems = 0;

if ($selectedDriverId > 0 && $selectedDay > 0) {
    try {
        // Get route customers with their standing orders
        $stmt = $db->prepare("
            SELECT 
                c.id as customer_id,
                c.name as customer_name,
                c.address,
                c.phone,
                c.email,
                GROUP_CONCAT(DISTINCT 
                    CONCAT(
                        COALESCE(dt.name, 'Unclassified'), '|',
                        p.name, 
                        ' (', COALESCE(so.quantity, 0), ')'
                    ) 
                    ORDER BY COALESCE(dt.name, 'Unclassified'), p.name 
                    SEPARATOR '||'
                ) as orders
            FROM standing_routes sr
            JOIN customers c ON sr.customer_id = c.id
            LEFT JOIN standing_orders so ON so.customer_id = c.id 
                AND so.day_of_week = sr.day_of_week
            LEFT JOIN products p ON so.product_id = p.id
            LEFT JOIN dough_types dt ON p.dough_type_id = dt.id
            WHERE sr.driver_id = ? AND sr.day_of_week = ?
            GROUP BY c.id, c.name, c.address, c.phone, c.email
        ");
        
        $stmt->execute([$selectedDriverId, $selectedDay]);
        $routeData = $stmt->fetchAll();
        
        // Calculate total items and product summary grouped by dough type
        $productSummaryByDoughType = [];
        foreach ($routeData as &$customer) {
            if (!empty($customer['orders'])) {
                $orderLines = explode('||', $customer['orders']);
                $customerOrdersByDoughType = [];
                
                foreach ($orderLines as $line) {
                    if (preg_match('/(.+?)\|(.+?)\s*\((\d+)\)$/', $line, $matches)) {
                        $doughType = trim($matches[1]);
                        $productName = trim($matches[2]);
                        $quantity = (int)$matches[3];
                        $totalItems += $quantity;
                        
                        // For route summary
                        if (!isset($productSummaryByDoughType[$doughType])) {
                            $productSummaryByDoughType[$doughType] = [];
                        }
                        if (!isset($productSummaryByDoughType[$doughType][$productName])) {
                            $productSummaryByDoughType[$doughType][$productName] = 0;
                        }
                        $productSummaryByDoughType[$doughType][$productName] += $quantity;
                        
                        // For customer orders display
                        if (!isset($customerOrdersByDoughType[$doughType])) {
                            $customerOrdersByDoughType[$doughType] = [];
                        }
                        $customerOrdersByDoughType[$doughType][] = $productName . ' (' . $quantity . ')';
                    }
                }
                
                // Format customer orders by dough type
                $customer['orders_by_dough_type'] = $customerOrdersByDoughType;
            }
        }
    } catch (Exception $e) {
        echo '<div class="error">Error loading route data: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}
?>

<div class="container">
    <h1>Daily Route</h1>
    
    <div class="route-filters">
        <form method="get" action="" class="form-inline">
            <div class="form-group">
                <label for="driver_id">Driver:</label>
                <select name="driver_id" id="driver_id" class="form-control" required>
                    <option value="">Select a driver</option>
                    <?php foreach ($drivers as $driver): ?>
                        <option value="<?php echo $driver['id']; ?>" <?php echo $selectedDriverId == $driver['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($driver['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="day">Day:</label>
                <select name="day" id="day" class="form-control" required>
                    <?php foreach ($days as $dayNum => $dayName): ?>
                        <option value="<?php echo $dayNum; ?>" <?php echo $selectedDay == $dayNum ? 'selected' : ''; ?>>
                            <?php echo $dayName; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <button type="submit" class="btn btn-primary">Show Route</button>
        </form>
    </div>
    
    <?php if ($selectedDriverId > 0 && $selectedDay > 0): ?>
        <div class="route-summary">
            <h3>Route Summary</h3>
            <div class="summary-grid">
                <div class="summary-item">
                    <span class="summary-label">Total Stops:</span>
                    <span class="summary-value"><?php echo count($routeData); ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Total Items:</span>
                    <span class="summary-value"><?php echo $totalItems; ?></span>
                </div>
            </div>
            
            <?php if (!empty($productSummaryByDoughType)): ?>
                <div class="product-summary">
                    <h4>Products Needed:</h4>
                    <table class="product-table">
                        <thead>
                            <tr>
                                <th>Dough Type</th>
                                <th>Product</th>
                                <th>Quantity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($productSummaryByDoughType as $doughType => $products): ?>
                                <?php $firstProduct = true; ?>
                                <?php foreach ($products as $product => $quantity): ?>
                                    <tr>
                                        <?php if ($firstProduct): ?>
                                            <td rowspan="<?php echo count($products); ?>" class="dough-type-cell">
                                                <?php echo htmlspecialchars($doughType); ?>
                                            </td>
                                            <?php $firstProduct = false; ?>
                                        <?php endif; ?>
                                        <td><?php echo htmlspecialchars($product); ?></td>
                                        <td class="quantity"><?php echo $quantity; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="route-customers">
            <?php if (!empty($routeData)): ?>
                <?php foreach ($routeData as $index => $customer): ?>
                    <div class="customer-card">
                        <div class="customer-header">
                            <h3>#<?php echo ($index + 1); ?> - <?php echo htmlspecialchars($customer['customer_name']); ?></h3>
                            <div class="customer-actions">
                                <a href="tel:<?php echo htmlspecialchars($customer['phone']); ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-phone"></i> Call
                                </a>
                                <?php if (!empty($customer['address'])): ?>
                                    <a href="https://www.google.com/maps/search/?api=1&query=<?php echo urlencode($customer['address']); ?>" 
                                       target="_blank" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-map-marker-alt"></i> Map
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="customer-details">
                            <?php if (!empty($customer['address'])): ?>
                                <p><strong>Address:</strong> <?php echo nl2br(htmlspecialchars($customer['address'])); ?></p>
                            <?php endif; ?>
                            
                            <?php if (!empty($customer['phone'])): ?>
                                <p><strong>Phone:</strong> 
                                    <a href="tel:<?php echo htmlspecialchars($customer['phone']); ?>">
                                        <?php echo htmlspecialchars($customer['phone']); ?>
                                    </a>
                                </p>
                            <?php endif; ?>
                            
                            <?php if (!empty($customer['email'])): ?>
                                <p><strong>Email:</strong> 
                                    <a href="mailto:<?php echo htmlspecialchars($customer['email']); ?>">
                                        <?php echo htmlspecialchars($customer['email']); ?>
                                    </a>
                                </p>
                            <?php endif; ?>
                            
                            <?php if (!empty($customer['orders_by_dough_type']) && $customer['orders_by_dough_type'] !== ' (0)') : ?>
                                <div class="customer-orders">
                                    <strong>Standing Orders:</strong>
                                    <div class="order-items">
                                        <?php foreach ($customer['orders_by_dough_type'] as $doughType => $orders): ?>
                                            <strong><?php echo htmlspecialchars($doughType); ?></strong>
                                            <ul>
                                                <?php foreach ($orders as $order): ?>
                                                    <li><?php echo htmlspecialchars($order); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="no-orders">No standing orders for this day.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="alert alert-info">No customers found for the selected driver and day.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<style>
    .route-filters {
        background-color: #f8f9fa;
        padding: 15px;
        border-radius: 5px;
        margin-bottom: 20px;
    }
    
    .form-group {
        margin-right: 15px;
        margin-bottom: 10px;
        display: inline-block;
    }
    
    label {
        margin-right: 5px;
        font-weight: 500;
    }
    
    .route-summary {
        background-color: #e9f7ef;
        padding: 20px;
        border-radius: 5px;
        margin-bottom: 25px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    .summary-grid {
        display: flex;
        gap: 30px;
        margin-bottom: 15px;
    }
    
    .summary-item {
        display: flex;
        flex-direction: column;
    }
    
    .summary-label {
        font-weight: 500;
        color: #2c3e50;
        margin-bottom: 3px;
    }
    
    .summary-value {
        font-size: 1.2em;
        font-weight: bold;
        color: #2c3e50;
    }
    
    .product-summary {
        margin-top: 20px;
    }
    
    .product-summary h4 {
        margin: 0 0 10px 0;
        color: #2c3e50;
    }
    
    .product-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }
    
    .product-table th,
    .product-table td {
        padding: 8px 12px;
        text-align: left;
        border-bottom: 1px solid #dee2e6;
    }
    
    .product-table th {
        background-color: #f8f9fa;
        font-weight: 600;
        color: #495057;
    }
    
    .product-table tr:last-child td {
        border-bottom: none;
    }
    
    .product-table .quantity {
        text-align: right;
        font-weight: bold;
        color: #2c3e50;
    }
    
    .dough-type-cell {
        background-color: #e9ecef;
        font-weight: bold;
        vertical-align: top;
        border-right: 2px solid #dee2e6;
    }
    
    .customer-orders .order-items strong {
        display: block;
        margin-top: 10px;
        margin-bottom: 5px;
        color: #2c3e50;
        font-size: 0.9em;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .customer-orders .order-items strong:first-child {
        margin-top: 0;
    }
    
    .customer-orders .order-items ul {
        margin: 0 0 5px 20px;
        padding: 0;
    }
    
    .customer-orders .order-items li {
        margin-bottom: 2px;
    }
    
    .route-summary h3 {
        margin-top: 0;
        color: #2c3e50;
    }
    
    .customer-card {
        background-color: #fff;
        border: 1px solid #dee2e6;
        border-radius: 5px;
        margin-bottom: 20px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    
    .customer-header {
        background-color: #f8f9fa;
        padding: 12px 15px;
        border-bottom: 1px solid #dee2e6;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .customer-header h3 {
        margin: 0;
        font-size: 1.1rem;
    }
    
    .customer-actions {
        display: flex;
        gap: 5px;
    }
    
    .customer-details {
        padding: 15px;
    }
    
    .customer-details p {
        margin-bottom: 8px;
    }
    
    .delivery-notes {
        margin: 15px 0;
        padding: 10px;
        background-color: #f8f9fa;
        border-radius: 4px;
        border-left: 3px solid #4e73df;
    }
    
    .customer-orders {
        margin-top: 15px;
        padding: 10px;
        background-color: #f8f9fa;
        border-radius: 4px;
        border-left: 3px solid #28a745;
    }
    
    .order-items {
        margin-top: 5px;
    }
    
    .no-orders {
        font-style: italic;
        color: #6c757d;
        margin-top: 10px;
    }
    
    @media print {
        .route-filters, .customer-actions {
            display: none;
        }
        
        .customer-card {
            page-break-inside: avoid;
        }
    }
</style>

<script>
// Auto-submit form when dropdowns change
// document.addEventListener('DOMContentLoaded', function() {
//     const driverSelect = document.getElementById('driver_id');
//     const daySelect = document.getElementById('day');
    
//     if (driverSelect && daySelect) {
//         driverSelect.addEventListener('change', function() {
//             if (this.value && document.getElementById('day').value) {
//                 this.form.submit();
//             }
//         });
        
//         daySelect.addEventListener('change', function() {
//             if (this.value && document.getElementById('driver_id').value) {
//                 this.form.submit();
//             }
//         });
//     }
// });
</script>

<?php require_once 'includes/footer.php'; ?>
