<?php
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';

$page_title = 'Pack List';

// Get selected day (default to today's day of week)
$selectedDay = isset($_GET['day']) ? (int)$_GET['day'] : date('w'); // 0 = Sunday, 1 = Monday, etc.

$days = [
    0 => 'Sunday',
    1 => 'Monday', 
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday'
];

// Get Ruta Sour Flour zone summary
$rutaSourFlourSummary = [];
$rutaTotalItems = 0;

// Get pack list data grouped by dough type
$packListData = [];
$totalItems = 0;

try {
    // First, get Ruta Sour Flour zone summary grouped by customer
    $stmt = $db->prepare("
        SELECT 
            c.name as customer_name,
            p.name as product_name,
            so.quantity,
            COALESCE(dt.name, 'Unclassified') as dough_type_name
        FROM standing_orders so
        JOIN customers c ON so.customer_id = c.id
        JOIN products p ON so.product_id = p.id
        LEFT JOIN dough_types dt ON p.dough_type_id = dt.id
        WHERE so.day_of_week = ? 
        AND so.quantity > 0
        AND c.zone = 'Ruta Sour Flour'
        ORDER BY 
            c.name,
            COALESCE(dt.name, 'Unclassified'),
            p.name
    ");
    
    $stmt->execute([$selectedDay]);
    $rutaOrders = $stmt->fetchAll();
    
    // Group Ruta orders by customer, then dough type, then product
    foreach ($rutaOrders as $order) {
        $customer = $order['customer_name'];
        $doughType = $order['dough_type_name'];
        $product = $order['product_name'];
        $quantity = (int)$order['quantity'];
        
        if (!isset($rutaSourFlourSummary[$customer])) {
            $rutaSourFlourSummary[$customer] = [];
        }
        
        if (!isset($rutaSourFlourSummary[$customer][$doughType])) {
            $rutaSourFlourSummary[$customer][$doughType] = [];
        }
        
        $rutaSourFlourSummary[$customer][$doughType][] = [
            'product' => $product,
            'quantity' => $quantity
        ];
        
        $rutaTotalItems += $quantity;
    }
    
    // Then get full detailed pack list
    $stmt = $db->prepare("
        SELECT 
            c.name as customer_name,
            c.zone as customer_zone,
            p.name as product_name,
            so.quantity,
            COALESCE(dt.name, 'Unclassified') as dough_type_name
        FROM standing_orders so
        JOIN customers c ON so.customer_id = c.id
        JOIN products p ON so.product_id = p.id
        LEFT JOIN dough_types dt ON p.dough_type_id = dt.id
        WHERE so.day_of_week = ? AND so.quantity > 0
        ORDER BY 
            COALESCE(dt.name, 'Unclassified'),
            c.name,
            p.name
    ");
    
    $stmt->execute([$selectedDay]);
    $orders = $stmt->fetchAll();
    
    // Group by dough type, then by customer
    foreach ($orders as $order) {
        $doughType = $order['dough_type_name'];
        $customer = $order['customer_name'];
        $customerZone = $order['customer_zone'];
        $product = $order['product_name'];
        $quantity = (int)$order['quantity'];
        
        if (!isset($packListData[$doughType])) {
            $packListData[$doughType] = [];
        }
        
        if (!isset($packListData[$doughType][$customer])) {
            $packListData[$doughType][$customer] = [
                'zone' => $customerZone,
                'products' => []
            ];
        }
        
        $packListData[$doughType][$customer]['products'][] = [
            'product' => $product,
            'quantity' => $quantity
        ];
        
        $totalItems += $quantity;
    }
    
} catch (Exception $e) {
    $error = 'Error loading pack list: ' . htmlspecialchars($e->getMessage());
}

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>

<style>
.pack-list-container {
    max-width: 100%;
    margin: 0 auto;
    padding: 10px;
}

.day-selector {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    text-align: center;
}

.day-selector h2 {
    margin: 0 0 15px 0;
    color: #2c3e50;
}

.day-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: center;
}

.day-btn {
    padding: 10px 15px;
    border: none;
    border-radius: 6px;
    background: #e9ecef;
    color: #495057;
    text-decoration: none;
    font-weight: 500;
    transition: all 0.2s;
    font-size: 14px;
}

.day-btn.active {
    background: #007bff;
    color: white;
}

.day-btn:hover {
    background: #007bff;
    color: white;
    transform: translateY(-1px);
}

.summary-card {
    background: #e8f5e8;
    border: 1px solid #c3e6c3;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
    text-align: center;
}

.summary-card h3 {
    margin: 0 0 10px 0;
    color: #155724;
}

.ruta-summary {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 10px;
    margin-bottom: 25px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.ruta-summary-header {
    background: #fd7e14;
    color: white;
    padding: 15px 20px;
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.ruta-summary-content {
    padding: 20px;
}

.ruta-customer {
    margin-bottom: 20px;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    overflow: hidden;
}

.ruta-customer:last-child {
    margin-bottom: 0;
}

.ruta-customer-name {
    background: #f8f9fa;
    border-bottom: 1px solid #e9ecef;
    padding: 12px 15px;
    font-weight: 700;
    color: #2c3e50;
    font-size: 16px;
}

.ruta-dough-type {
    margin-bottom: 15px;
    padding: 0 15px;
}

.ruta-dough-type:last-child {
    margin-bottom: 15px;
}

.ruta-dough-type-name {
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 8px;
    font-size: 16px;
    border-bottom: 1px solid #dee2e6;
    padding-bottom: 4px;
}

.ruta-products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 10px;
}

.ruta-product-item {
    background: #f8f9fa;
    border: 1px solid #fd7e14;
    border-radius: 6px;
    padding: 10px 12px;
    font-size: 14px;
    color: #495057;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.ruta-quantity {
    background: #fd7e14;
    color: white;
    border-radius: 12px;
    padding: 3px 8px;
    font-weight: 600;
    font-size: 12px;
    min-width: 24px;
    text-align: center;
}

.zone-badge {
    background: #6c757d;
    color: white;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 11px;
    margin-left: 8px;
    font-weight: 500;
}

.zone-badge.ruta-sour-flour {
    background: #fd7e14;
}

.dough-type-section {
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 10px;
    margin-bottom: 20px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.dough-type-header {
    background: #007bff;
    color: white;
    padding: 15px 20px;
    margin: 0;
    font-size: 18px;
    font-weight: 600;
}

.customers-list {
    padding: 0;
}

.customer-item {
    border-bottom: 1px solid #f1f3f4;
    padding: 15px 20px;
}

.customer-item:last-child {
    border-bottom: none;
}

.customer-name {
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 8px;
    font-size: 16px;
}

.products-list {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.product-item {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 6px;
    padding: 8px 12px;
    font-size: 14px;
    color: #495057;
}

.quantity {
    background: #007bff;
    color: white;
    border-radius: 12px;
    padding: 2px 8px;
    font-weight: 600;
    margin-left: 8px;
    font-size: 12px;
}

.no-orders {
    text-align: center;
    padding: 40px 20px;
    color: #6c757d;
    font-style: italic;
}

.error {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 20px;
}

/* Mobile optimizations */
@media (max-width: 768px) {
    .pack-list-container {
        padding: 5px;
    }
    
    .day-buttons {
        gap: 5px;
    }
    
    .day-btn {
        padding: 8px 12px;
        font-size: 13px;
    }
    
    .ruta-summary-header {
        padding: 12px 15px;
        font-size: 16px;
    }
    
    .ruta-summary-content {
        padding: 15px;
    }
    
    .ruta-products-grid {
        grid-template-columns: 1fr;
        gap: 8px;
    }
    
    .ruta-product-item {
        padding: 8px 10px;
        font-size: 13px;
    }
    
    .dough-type-header {
        padding: 12px 15px;
        font-size: 16px;
    }
    
    .customer-item {
        padding: 12px 15px;
    }
    
    .customer-name {
        font-size: 15px;
    }
    
    .products-list {
        gap: 8px;
    }
    
    .product-item {
        padding: 6px 10px;
        font-size: 13px;
    }
}

@media (max-width: 480px) {
    .day-buttons {
        flex-direction: column;
        align-items: center;
    }
    
    .day-btn {
        width: 100%;
        max-width: 200px;
    }
    
    .ruta-products-grid {
        grid-template-columns: 1fr;
    }
    
    .products-list {
        flex-direction: column;
    }
    
    .product-item {
        width: 100%;
    }
}
</style>

<div class="pack-list-container">
    <?php if (isset($error)): ?>
        <div class="error"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <!-- Day Selector -->
    <div class="day-selector">
        <h2>📦 Pack List</h2>
        <div class="day-buttons">
            <?php foreach ($days as $dayNum => $dayName): ?>
                <a href="?day=<?php echo $dayNum; ?>" 
                   class="day-btn <?php echo $selectedDay === $dayNum ? 'active' : ''; ?>">
                    <?php echo $dayName; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($selectedDay === 0): ?>
        <?php
        $sundayStandingCount = (int)$db->query(
            "SELECT COALESCE(SUM(quantity), 0) FROM standing_orders WHERE day_of_week = 7 AND quantity > 0"
        )->fetchColumn();
        ?>
        <div class="error" style="background: #fff3cd; color: #856404; border-color: #ffc107;">
            <strong>Sunday encoding mismatch:</strong> Pack list uses day <code>0</code> for Sunday (<code>date('w')</code>).
            Standing orders and fixtures store Sunday as day <code>7</code>.
            <?php if ($sundayStandingCount > 0): ?>
                There are <?php echo number_format($sundayStandingCount); ?> units in standing orders for Sunday (day 7) that will not appear on this list.
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <!-- Summary -->
    <?php if (!empty($packListData)): ?>
        <div class="summary-card">
            <h3><?php echo $days[$selectedDay]; ?> Pack List</h3>
            <p><strong><?php echo $totalItems; ?></strong> total items to pack</p>
            <p><strong><?php echo count($packListData); ?></strong> dough types</p>
        </div>
    <?php endif; ?>
    
    <!-- Ruta Sour Flour Summary -->
    <?php if (!empty($rutaSourFlourSummary)): ?>
        <div class="ruta-summary">
            <h3 class="ruta-summary-header">
                <span>🚛</span>
                <span>Ruta Sour Flour Zone Summary</span>
                <span style="margin-left: auto; font-size: 14px;"><?php echo $rutaTotalItems; ?> items</span>
            </h3>
            <div class="ruta-summary-content">
                <?php foreach ($rutaSourFlourSummary as $customer => $doughTypes): ?>
                    <div class="ruta-customer">
                        <div class="ruta-customer-name"><?php echo htmlspecialchars($customer); ?></div>
                        <?php foreach ($doughTypes as $doughType => $products): ?>
                            <div class="ruta-dough-type">
                                <div class="ruta-dough-type-name"><?php echo htmlspecialchars($doughType); ?></div>
                                <div class="ruta-products-grid">
                                    <?php foreach ($products as $product): ?>
                                        <div class="ruta-product-item">
                                            <span><?php echo htmlspecialchars($product['product']); ?></span>
                                            <span class="ruta-quantity"><?php echo $product['quantity']; ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Pack List by Dough Type -->
    <?php if (empty($packListData)): ?>
        <div class="no-orders">
            <h3>No orders for <?php echo $days[$selectedDay]; ?></h3>
            <p>There are no standing orders scheduled for this day.</p>
        </div>
    <?php else: ?>
        <?php foreach ($packListData as $doughType => $customers): ?>
            <div class="dough-type-section">
                <h3 class="dough-type-header"><?php echo htmlspecialchars($doughType); ?></h3>
                <div class="customers-list">
                    <?php foreach ($customers as $customerName => $customerData): ?>
                        <div class="customer-item">
                            <div class="customer-name">
                                <?php echo htmlspecialchars($customerName); ?>
                                <?php if (!empty($customerData['zone'])): ?>
                                    <span class="zone-badge <?php echo $customerData['zone'] === 'Ruta Sour Flour' ? 'ruta-sour-flour' : ''; ?>">
                                        <?php echo htmlspecialchars($customerData['zone']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="products-list">
                                <?php foreach ($customerData['products'] as $product): ?>
                                    <div class="product-item">
                                        <?php echo htmlspecialchars($product['product']); ?>
                                        <span class="quantity"><?php echo $product['quantity']; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?> 