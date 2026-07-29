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
            case 'generate_from_standing':
                $date = $_POST['date'];
                $phpDayOfWeek = date('N', strtotime($date)); // 1 = Monday, 7 = Sunday (PHP format)
                
                // Convert PHP day numbering (1=Monday, 7=Sunday) to database format (0=Sunday, 1=Monday, etc.)
                $dbDayOfWeek = ($phpDayOfWeek == 7) ? 0 : $phpDayOfWeek;
                
                // Start transaction for better performance
                $db->beginTransaction();
                
                try {
                    // Get all standing orders for this day in one efficient query
                    $stmt = $db->prepare("
                        SELECT so.customer_id, so.product_id, so.quantity,
                               COALESCE(p.price, 0) as price,
                               c.default_pan_dulce_price,
                               pl.name as product_line_name
                        FROM standing_orders so
                        JOIN customers c ON so.customer_id = c.id
                        JOIN products p ON so.product_id = p.id
                        JOIN dough_types dt ON p.dough_type_id = dt.id
                        JOIN product_lines pl ON dt.product_line_id = pl.id
                        WHERE so.day_of_week = ?
                        ORDER BY so.customer_id, so.product_id
                    ");
                    $stmt->execute([$dbDayOfWeek]);
                    $standingOrders = $stmt->fetchAll();
                    
                    $ordersCreated = 0;
                    $itemsCreated = 0;
                    
                    if (count($standingOrders) > 0) {
                        // Group orders by customer for batch processing
                        $customerOrders = [];
                        foreach ($standingOrders as $order) {
                            $customerId = $order['customer_id'];
                            if (!isset($customerOrders[$customerId])) {
                                $customerOrders[$customerId] = [];
                            }
                            $customerOrders[$customerId][] = $order;
                        }
                        
                        // Process each customer's orders
                        foreach ($customerOrders as $customerId => $orders) {
                            // Create or get daily order for this customer/date
                            $stmt = $db->prepare("
                                INSERT IGNORE INTO daily_orders (customer_id, order_date, status, total_amount)
                                VALUES (?, ?, 'pending', 0)
                            ");
                            $stmt->execute([$customerId, $date]);
                            
                            if ($stmt->rowCount() > 0) {
                                $ordersCreated++;
                            }
                            
                            // Get the daily order ID
                            $stmt = $db->prepare("
                                SELECT id FROM daily_orders 
                                WHERE customer_id = ? AND order_date = ?
                            ");
                            $stmt->execute([$customerId, $date]);
                            $dailyOrderId = $stmt->fetchColumn();
                            
                            if ($dailyOrderId) {
                                // Prepare batch insert for all items for this customer
                                $itemValues = [];
                                $itemParams = [];
                                
                                foreach ($orders as $order) {
                                    // Determine the unit price based on product line and customer pricing
                                    $unitPrice = floatval($order['price'] ?? 0);
                                    
                                    // If this is a Pan Dulce product and customer has a custom price, use it
                                    if ($order['product_line_name'] === 'Pan Dulce' && 
                                        !empty($order['default_pan_dulce_price'])) {
                                        $unitPrice = floatval($order['default_pan_dulce_price']);
                                    }
                                    
                                    $lineTotal = $order['quantity'] * $unitPrice;
                                    
                                    $itemValues[] = "(?, ?, ?, ?, ?)";
                                    $itemParams[] = $dailyOrderId;
                                    $itemParams[] = $order['product_id'];
                                    $itemParams[] = $order['quantity'];
                                    $itemParams[] = $unitPrice;
                                    $itemParams[] = $lineTotal;
                                }
                                
                                if (!empty($itemValues)) {
                                    $sql = "
                                        INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
                                        VALUES " . implode(', ', $itemValues) . "
                                        ON DUPLICATE KEY UPDATE 
                                        quantity = VALUES(quantity),
                                        unit_price = VALUES(unit_price),
                                        line_total = VALUES(line_total)
                                    ";
                                    
                                    $stmt = $db->prepare($sql);
                                    $stmt->execute($itemParams);
                                    $itemsCreated += count($orders);
                                }
                                
                                // Update order total efficiently
                                $stmt = $db->prepare("
                                    UPDATE daily_orders 
                                    SET total_amount = (
                                        SELECT COALESCE(SUM(line_total), 0) 
                                        FROM daily_order_items 
                                        WHERE daily_order_id = ?
                                    )
                                    WHERE id = ?
                                ");
                                $stmt->execute([$dailyOrderId, $dailyOrderId]);
                            }
                        }
                    }
                    
                    $db->commit();
                    
                    echo json_encode([
                        'success' => true, 
                        'orders_created' => $ordersCreated,
                        'items_created' => $itemsCreated,
                        'message' => "Generated $ordersCreated orders with $itemsCreated items for " . date('l, F j, Y', strtotime($date))
                    ]);
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    error_log("Error generating orders: " . $e->getMessage());
                    echo json_encode([
                        'success' => false,
                        'error' => 'Failed to generate orders: ' . $e->getMessage()
                    ]);
                }
                break;
                
            case 'update_quantity':
                $itemId = (int)$_POST['item_id'];
                $quantity = (int)$_POST['quantity'];
                
                if ($quantity <= 0) {
                    // Delete the item if quantity is 0 or negative
                    $stmt = $db->prepare("DELETE FROM daily_order_items WHERE id = ?");
                    $stmt->execute([$itemId]);
                } else {
                    // Update quantity and recalculate line total
                    $stmt = $db->prepare("
                        UPDATE daily_order_items 
                        SET quantity = ?, line_total = quantity * unit_price
                        WHERE id = ?
                    ");
                    $stmt->execute([$quantity, $itemId]);
                }
                
                // Get the daily order ID to update total
                $stmt = $db->prepare("SELECT daily_order_id FROM daily_order_items WHERE id = ?");
                $stmt->execute([$itemId]);
                $dailyOrderId = $stmt->fetchColumn();
                
                if ($dailyOrderId) {
                    updateOrderTotal($db, $dailyOrderId);
                }
                
                echo json_encode(['success' => true]);
                break;
                
            case 'update_status':
                $orderId = (int)$_POST['order_id'];
                $status = $_POST['status'];
                
                $allowedStatuses = ['pending', 'confirmed', 'in_production', 'ready', 'out_for_delivery', 'delivered', 'invoiced'];
                if (!in_array($status, $allowedStatuses)) {
                    throw new Exception('Invalid status');
                }
                
                $stmt = $db->prepare("UPDATE daily_orders SET status = ? WHERE id = ?");
                $stmt->execute([$status, $orderId]);
                
                echo json_encode(['success' => true]);
                break;
                
            case 'add_item':
                $orderId = (int)$_POST['order_id'];
                $productId = (int)$_POST['product_id'];
                $quantity = (int)$_POST['quantity'];
                
                // Get product price and check if it's Pan Dulce
                $stmt = $db->prepare("
                    SELECT p.price, c.default_pan_dulce_price, pl.name as product_line_name
                    FROM products p
                    JOIN dough_types dt ON p.dough_type_id = dt.id
                    JOIN product_lines pl ON dt.product_line_id = pl.id
                    JOIN daily_orders do ON do.id = ?
                    JOIN customers c ON do.customer_id = c.id
                    WHERE p.id = ?
                ");
                $stmt->execute([$orderId, $productId]);
                $productData = $stmt->fetch();
                
                // Determine the unit price based on product line and customer pricing
                $unitPrice = floatval($productData['price'] ?? 0);
                
                // If this is a Pan Dulce product and customer has a custom price, use it
                if ($productData['product_line_name'] === 'Pan Dulce' && 
                    !empty($productData['default_pan_dulce_price'])) {
                    $unitPrice = floatval($productData['default_pan_dulce_price']);
                }
                
                $lineTotal = $quantity * $unitPrice;
                
                $stmt = $db->prepare("
                    INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    quantity = quantity + VALUES(quantity),
                    line_total = quantity * unit_price
                ");
                $stmt->execute([$orderId, $productId, $quantity, $unitPrice, $lineTotal]);
                
                updateOrderTotal($db, $orderId);
                
                echo json_encode(['success' => true]);
                break;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Helper function to update order total
function updateOrderTotal($db, $orderId) {
    $stmt = $db->prepare("
        UPDATE daily_orders 
        SET total_amount = (
            SELECT COALESCE(SUM(line_total), 0) 
            FROM daily_order_items 
            WHERE daily_order_id = ?
        )
        WHERE id = ?
    ");
    $stmt->execute([$orderId, $orderId]);
}

require_once 'includes/header.php';
require_once 'includes/nav.php';

// Get selected date (default to tomorrow)
$selectedDate = $_GET['date'] ?? date('Y-m-d', strtotime('+1 day'));
$dayName = date('l', strtotime($selectedDate));

// Set page title
$page_title = 'Daily Orders - ' . date('M j, Y', strtotime($selectedDate));

// Get daily orders for selected date
try {
    $stmt = $db->prepare("
        SELECT do.*, c.name as customer_name, c.address, c.phone
        FROM daily_orders do
        JOIN customers c ON do.customer_id = c.id
        WHERE do.order_date = ?
        ORDER BY c.name
    ");
    $stmt->execute([$selectedDate]);
    $dailyOrders = $stmt->fetchAll();
    
    // Get all order items
    $orderItems = [];
    if (!empty($dailyOrders)) {
        $orderIds = array_column($dailyOrders, 'id');
        $placeholders = str_repeat('?,', count($orderIds) - 1) . '?';
        
        $stmt = $db->prepare("
            SELECT doi.*, p.name as product_name
            FROM daily_order_items doi
            JOIN products p ON doi.product_id = p.id
            WHERE doi.daily_order_id IN ($placeholders)
            ORDER BY p.name
        ");
        $stmt->execute($orderIds);
        $items = $stmt->fetchAll();
        
        foreach ($items as $item) {
            $orderItems[$item['daily_order_id']][] = $item;
        }
    }
    
    // Get all products for adding new items
    $products = $db->query("SELECT id, name, price FROM products ORDER BY name")->fetchAll();
    
    // Get customers for creating new orders
    $customers = $db->query("SELECT id, name FROM customers ORDER BY name")->fetchAll();
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error loading daily orders: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}
?>

<div class="container">
    <div class="page-header">
        <h1>Daily Orders</h1>
        <div class="button-group">
            <button type="button" class="btn btn-primary" onclick="showGenerateModal()">
                Generate from Standing Orders
            </button>
            <button type="button" class="btn btn-secondary" onclick="showDatePicker()">
                Change Date
            </button>
        </div>
    </div>
    
    <!-- Date Navigation -->
    <div class="date-navigation">
        <div class="date-info">
            <h2>Orders for <?= date('l, F j, Y', strtotime($selectedDate)) ?></h2>
            <span class="order-count">Total Orders: <?= count($dailyOrders) ?></span>
        </div>
        <div class="date-controls">
            <a href="?date=<?= date('Y-m-d', strtotime($selectedDate . ' -1 day')) ?>" class="btn btn-outline">← Previous Day</a>
            <a href="?date=<?= date('Y-m-d') ?>" class="btn btn-primary">Today</a>
            <a href="?date=<?= date('Y-m-d', strtotime($selectedDate . ' +1 day')) ?>" class="btn btn-outline">Next Day →</a>
        </div>
    </div>
    
    <!-- Orders List -->
    <?php if (empty($dailyOrders)): ?>
        <div class="empty-state">
            <h3>No orders for this date</h3>
            <p>Generate orders from standing orders or create new orders manually.</p>
            <button class="btn btn-primary" onclick="showGenerateModal()">
                Generate from Standing Orders
            </button>
        </div>
    <?php else: ?>
        <div class="orders-list">
            <?php foreach ($dailyOrders as $order): ?>
                <div class="order-card">
                    <div class="order-header">
                        <div class="customer-info">
                            <h3><?= htmlspecialchars($order['customer_name']) ?></h3>
                            <p class="address"><?= htmlspecialchars($order['address']) ?></p>
                        </div>
                        <div class="order-status">
                            <span class="status-badge status-<?= $order['status'] ?>">
                                <?= ucwords(str_replace('_', ' ', $order['status'])) ?>
                            </span>
                        </div>
                        <div class="order-total">
                            <strong>$<?= number_format($order['total_amount'], 2) ?></strong>
                        </div>
                    </div>
                    
                    <div class="order-body">
                        <!-- Order Items -->
                        <?php if (isset($orderItems[$order['id']])): ?>
                            <div class="order-items">
                                <table class="items-table">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Quantity</th>
                                            <th>Unit Price</th>
                                            <th>Total</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($orderItems[$order['id']] as $item): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($item['product_name']) ?></td>
                                                <td>
                                                    <input type="number" 
                                                           value="<?= $item['quantity'] ?>" 
                                                           min="0" 
                                                           class="quantity-input" 
                                                           onchange="updateQuantity(<?= $item['id'] ?>, this.value)">
                                                </td>
                                                <td>$<?= number_format($item['unit_price'], 2) ?></td>
                                                <td>$<?= number_format($item['line_total'], 2) ?></td>
                                                <td>
                                                    <button class="btn btn-small btn-danger" 
                                                            onclick="updateQuantity(<?= $item['id'] ?>, 0)">
                                                        Remove
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Status Control and Add Product -->
                        <div class="order-controls">
                            <div class="control-group">
                                <label>Status:</label>
                                <select class="status-select" onchange="updateStatus(<?= $order['id'] ?>, this.value)">
                                    <option value="pending" <?= $order['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="confirmed" <?= $order['status'] === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                    <option value="in_production" <?= $order['status'] === 'in_production' ? 'selected' : '' ?>>In Production</option>
                                    <option value="ready" <?= $order['status'] === 'ready' ? 'selected' : '' ?>>Ready</option>
                                    <option value="out_for_delivery" <?= $order['status'] === 'out_for_delivery' ? 'selected' : '' ?>>Out for Delivery</option>
                                    <option value="delivered" <?= $order['status'] === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                                    <option value="invoiced" <?= $order['status'] === 'invoiced' ? 'selected' : '' ?>>Invoiced</option>
                                </select>
                            </div>
                            <div class="control-group">
                                <label>Add Product:</label>
                                <div class="add-product-controls">
                                    <select class="product-select" id="newProduct_<?= $order['id'] ?>">
                                        <option value="">Select Product</option>
                                        <?php foreach ($products as $product): ?>
                                            <option value="<?= $product['id'] ?>"><?= htmlspecialchars($product['name']) ?> ($<?= $product['price'] ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="number" class="quantity-input" placeholder="Qty" id="newQty_<?= $order['id'] ?>">
                                    <button class="btn btn-primary" onclick="addItem(<?= $order['id'] ?>)">Add</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Generate Orders Modal -->
<div class="modal-overlay" id="generateModal" style="display: none;">
    <div class="modal">
        <div class="modal-header">
            <h3>Generate Orders from Standing Orders</h3>
            <button type="button" class="modal-close" onclick="hideGenerateModal()">×</button>
        </div>
        <div class="modal-body">
            <p>Generate daily orders for <strong><?= date('l, F j, Y', strtotime($selectedDate)) ?></strong> based on standing orders?</p>
            <p class="modal-note">This will create orders for all customers who have standing orders for <?= $dayName ?>s.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="hideGenerateModal()">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="generateFromStanding()">Generate Orders</button>
        </div>
    </div>
</div>

<style>
.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid #e9ecef;
}

.page-header h1 {
    margin: 0;
    color: #2c3e50;
}

.button-group {
    display: flex;
    gap: 10px;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-block;
}

.btn-primary {
    background-color: #4e73df;
    color: white;
}

.btn-primary:hover {
    background-color: #2e59d9;
    transform: translateY(-2px);
}

.btn-secondary {
    background-color: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background-color: #545b62;
}

.btn-outline {
    background-color: transparent;
    color: #4e73df;
    border: 2px solid #4e73df;
}

.btn-outline:hover {
    background-color: #4e73df;
    color: white;
}

.btn-small {
    padding: 5px 10px;
    font-size: 12px;
}

.btn-danger {
    background-color: #e74a3b;
    color: white;
}

.btn-danger:hover {
    background-color: #c0392b;
}

.date-navigation {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background-color: #f8f9fa;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 30px;
}

.date-info h2 {
    margin: 0 0 5px 0;
    color: #2c3e50;
}

.order-count {
    color: #6c757d;
    font-size: 14px;
}

.date-controls {
    display: flex;
    gap: 10px;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    background-color: #f8f9fa;
    border-radius: 10px;
}

.empty-state h3 {
    color: #6c757d;
    margin-bottom: 10px;
}

.empty-state p {
    color: #6c757d;
    margin-bottom: 20px;
}

.orders-list {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.order-card {
    background-color: white;
    border: 1px solid #e9ecef;
    border-radius: 10px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.order-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    background-color: #f8f9fa;
    border-bottom: 1px solid #e9ecef;
    border-radius: 10px 10px 0 0;
}

.customer-info h3 {
    margin: 0 0 5px 0;
    color: #2c3e50;
}

.customer-info .address {
    margin: 0;
    color: #6c757d;
    font-size: 14px;
}

.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
    text-transform: uppercase;
}

.status-pending { background-color: #6c757d; color: white; }
.status-confirmed { background-color: #17a2b8; color: white; }
.status-in_production { background-color: #ffc107; color: #212529; }
.status-ready { background-color: #007bff; color: white; }
.status-out_for_delivery { background-color: #17a2b8; color: white; }
.status-delivered { background-color: #28a745; color: white; }
.status-invoiced { background-color: #343a40; color: white; }

.order-total {
    font-size: 18px;
    color: #2c3e50;
}

.order-body {
    padding: 20px;
}

.order-items {
    margin-bottom: 20px;
}

.items-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
}

.items-table th, .items-table td {
    padding: 10px;
    text-align: left;
    border-bottom: 1px solid #e9ecef;
}

.items-table th {
    background-color: #f8f9fa;
    font-weight: bold;
    color: #2c3e50;
}

.quantity-input {
    width: 80px;
    padding: 5px;
    border: 1px solid #ddd;
    border-radius: 3px;
}

.order-controls {
    display: flex;
    gap: 30px;
    flex-wrap: wrap;
}

.control-group {
    flex: 1;
    min-width: 300px;
}

.control-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
    color: #2c3e50;
}

.status-select, .product-select {
    width: 100%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 5px;
    font-size: 14px;
}

.add-product-controls {
    display: flex;
    gap: 10px;
    align-items: center;
}

.add-product-controls .product-select {
    flex: 1;
}

.add-product-controls .quantity-input {
    width: 80px;
}

/* Modal Styles */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    display: flex;
    justify-content: center;
    align-items: center;
}

.modal {
    background-color: white;
    border-radius: 10px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    max-width: 500px;
    width: 90%;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    border-bottom: 1px solid #e9ecef;
}

.modal-header h3 {
    margin: 0;
    color: #2c3e50;
}

.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #6c757d;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    justify-content: center;
    align-items: center;
}

.modal-close:hover {
    color: #e74a3b;
}

.modal-body {
    padding: 20px;
}

.modal-note {
    color: #6c757d;
    font-style: italic;
    margin-top: 10px;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 20px;
    border-top: 1px solid #e9ecef;
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        gap: 20px;
        text-align: center;
    }
    
    .date-navigation {
        flex-direction: column;
        gap: 20px;
        text-align: center;
    }
    
    .order-header {
        flex-direction: column;
        gap: 15px;
        text-align: center;
    }
    
    .order-controls {
        flex-direction: column;
        gap: 20px;
    }
    
    .control-group {
        min-width: auto;
    }
    
    .add-product-controls {
        flex-direction: column;
        gap: 10px;
    }
    
    .add-product-controls .product-select {
        width: 100%;
    }
}
</style>

<script>
function showGenerateModal() {
    document.getElementById('generateModal').style.display = 'flex';
}

function hideGenerateModal() {
    document.getElementById('generateModal').style.display = 'none';
}

function showDatePicker() {
    const date = prompt('Enter date (YYYY-MM-DD):', '<?= $selectedDate ?>');
    if (date) {
        window.location.href = `?date=${date}`;
    }
}

function generateFromStanding() {
    fetch('daily_orders.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=generate_from_standing&date=<?= $selectedDate ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    });
    
    hideGenerateModal();
}

function updateQuantity(itemId, quantity) {
    fetch('daily_orders.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=update_quantity&item_id=${itemId}&quantity=${quantity}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
}

function updateStatus(orderId, status) {
    fetch('daily_orders.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=update_status&order_id=${orderId}&status=${status}`
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            alert('Error: ' + data.error);
            location.reload();
        }
    });
}

function addItem(orderId) {
    const productSelect = document.getElementById(`newProduct_${orderId}`);
    const qtyInput = document.getElementById(`newQty_${orderId}`);
    
    const productId = productSelect.value;
    const quantity = parseInt(qtyInput.value) || 0;
    
    if (!productId || quantity <= 0) {
        alert('Please select a product and enter a quantity');
        return;
    }
    
    fetch('daily_orders.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=add_item&order_id=${orderId}&product_id=${productId}&quantity=${quantity}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
}

// Close modal when clicking outside
document.addEventListener('click', function(event) {
    const modal = document.getElementById('generateModal');
    if (event.target === modal) {
        hideGenerateModal();
    }
});
</script>

<?php
function getStatusColor($status) {
    switch ($status) {
        case 'pending': return 'secondary';
        case 'confirmed': return 'info';
        case 'in_production': return 'warning';
        case 'ready': return 'primary';
        case 'out_for_delivery': return 'info';
        case 'delivered': return 'success';
        case 'invoiced': return 'dark';
        default: return 'secondary';
    }
}

require_once 'includes/footer.php';
?>