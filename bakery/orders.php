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
            case 'get_order_details':
                $customerId = (int)$_POST['customer_id'];
                $date = $_POST['date'];
                
                // Get the daily order
                $stmt = $db->prepare("
                    SELECT do.*, c.name as customer_name, c.address, c.phone
                    FROM daily_orders do
                    JOIN customers c ON do.customer_id = c.id
                    WHERE do.customer_id = ? AND do.order_date = ?
                ");
                $stmt->execute([$customerId, $date]);
                $order = $stmt->fetch();
                
                if (!$order) {
                    throw new Exception('Order not found');
                }
                
                // Get order items
                $stmt = $db->prepare("
                    SELECT doi.*, p.name as product_name
                    FROM daily_order_items doi
                    JOIN products p ON doi.product_id = p.id
                    WHERE doi.daily_order_id = ?
                    ORDER BY p.name
                ");
                $stmt->execute([$order['id']]);
                $items = $stmt->fetchAll();
                
                echo json_encode([
                    'success' => true,
                    'order' => $order,
                    'items' => $items
                ]);
                break;
                
            default:
                throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

require_once 'includes/header.php';
require_once 'includes/nav.php';

// Get view type and dates
$view = $_GET['view'] ?? 'week';
$selectedDate = $_GET['date'] ?? date('Y-m-d');

// Calculate date ranges
if ($view === 'week') {
    $startOfWeek = date('Y-m-d', strtotime('monday this week', strtotime($selectedDate)));
    $endOfWeek = date('Y-m-d', strtotime('sunday this week', strtotime($selectedDate)));
    $startDate = $startOfWeek;
    $endDate = $endOfWeek;
    $periodTitle = 'Week of ' . date('M j', strtotime($startOfWeek)) . ' - ' . date('M j, Y', strtotime($endOfWeek));
} else {
    $startOfMonth = date('Y-m-01', strtotime($selectedDate));
    $endOfMonth = date('Y-m-t', strtotime($selectedDate));
    $startDate = $startOfMonth;
    $endDate = $endOfMonth;
    $periodTitle = date('F Y', strtotime($selectedDate));
}

// Set page title
$page_title = 'Orders Summary - ' . $periodTitle;

try {
    // Get all orders for the period
    $stmt = $db->prepare("
        SELECT 
            do.*,
            c.name as customer_name,
            c.address,
            c.zone,
            DATE_FORMAT(do.order_date, '%W') as day_name,
            DATE_FORMAT(do.order_date, '%m/%d') as date_short
        FROM daily_orders do
        JOIN customers c ON do.customer_id = c.id
        WHERE do.order_date BETWEEN ? AND ?
        ORDER BY c.name, do.order_date
    ");
    $stmt->execute([$startDate, $endDate]);
    $orders = $stmt->fetchAll();
    
    // Get detailed order items for summary
    if (!empty($orders)) {
        $orderIds = array_column($orders, 'id');
        $placeholders = str_repeat('?,', count($orderIds) - 1) . '?';
        
        $stmt = $db->prepare("
            SELECT 
                doi.*,
                p.name as product_name,
                dt.name as dough_type_name
            FROM daily_order_items doi
            JOIN products p ON doi.product_id = p.id
            LEFT JOIN dough_types dt ON p.dough_type_id = dt.id
            WHERE doi.daily_order_id IN ($placeholders)
            ORDER BY p.name
        ");
        $stmt->execute($orderIds);
        $allItems = $stmt->fetchAll();
        
        // Group items by order
        $orderItems = [];
        foreach ($allItems as $item) {
            $orderItems[$item['daily_order_id']][] = $item;
        }
    } else {
        $orderItems = [];
    }
    
    // Organize data for display
    $customerSummary = [];
    $dailySummary = [];
    $weekDates = [];
    
    // Generate week dates if in week view
    if ($view === 'week') {
        for ($i = 0; $i < 7; $i++) {
            $date = date('Y-m-d', strtotime($startOfWeek . " +$i days"));
            $weekDates[] = [
                'date' => $date,
                'day_name' => date('D', strtotime($date)),
                'date_short' => date('m/d', strtotime($date))
            ];
        }
    }
    
    foreach ($orders as $order) {
        $customerId = $order['customer_id'];
        $orderDate = $order['order_date'];
        
        // Initialize customer summary
        if (!isset($customerSummary[$customerId])) {
            $customerSummary[$customerId] = [
                'customer_name' => $order['customer_name'],
                'address' => $order['address'],
                'zone' => $order['zone'],
                'total_orders' => 0,
                'total_amount' => 0,
                'order_dates' => [],
                'products_summary' => []
            ];
        }
        
        $customerSummary[$customerId]['total_orders']++;
        $customerSummary[$customerId]['total_amount'] += $order['total_amount'];
        $customerSummary[$customerId]['order_dates'][$orderDate] = [
            'status' => $order['status'],
            'amount' => $order['total_amount'],
            'day_name' => $order['day_name'],
            'date_short' => $order['date_short']
        ];
        
        // Add to daily summary
        if (!isset($dailySummary[$orderDate])) {
            $dailySummary[$orderDate] = [
                'total_orders' => 0,
                'total_amount' => 0,
                'customers' => []
            ];
        }
        $dailySummary[$orderDate]['total_orders']++;
        $dailySummary[$orderDate]['total_amount'] += $order['total_amount'];
        $dailySummary[$orderDate]['customers'][] = $order['customer_name'];
        
        // Add product summary for customer
        if (isset($orderItems[$order['id']])) {
            foreach ($orderItems[$order['id']] as $item) {
                $productKey = $item['product_name'];
                if (!isset($customerSummary[$customerId]['products_summary'][$productKey])) {
                    $customerSummary[$customerId]['products_summary'][$productKey] = [
                        'quantity' => 0,
                        'total_amount' => 0
                    ];
                }
                $customerSummary[$customerId]['products_summary'][$productKey]['quantity'] += $item['quantity'];
                $customerSummary[$customerId]['products_summary'][$productKey]['total_amount'] += $item['line_total'];
            }
        }
    }
    
    // Calculate totals
    $totalOrders = count($orders);
    $totalAmount = array_sum(array_column($orders, 'total_amount'));
    $totalCustomers = count($customerSummary);
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error loading orders: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}
?>

<div class="container">
    <div class="page-header">
        <h1>Orders Summary</h1>
        <div class="view-controls">
            <div class="view-toggles">
                <a href="?view=week&date=<?= $selectedDate ?>" class="btn <?= $view === 'week' ? 'btn-primary' : 'btn-outline' ?>">Week View</a>
                <a href="?view=month&date=<?= $selectedDate ?>" class="btn <?= $view === 'month' ? 'btn-primary' : 'btn-outline' ?>">Month View</a>
            </div>
            <div class="date-controls">
                <?php if ($view === 'week'): ?>
                    <a href="?view=week&date=<?= date('Y-m-d', strtotime($startOfWeek . ' -7 days')) ?>" class="btn btn-outline">← Previous Week</a>
                    <a href="?view=week&date=<?= date('Y-m-d') ?>" class="btn btn-secondary">This Week</a>
                    <a href="?view=week&date=<?= date('Y-m-d', strtotime($startOfWeek . ' +7 days')) ?>" class="btn btn-outline">Next Week →</a>
                <?php else: ?>
                    <a href="?view=month&date=<?= date('Y-m-d', strtotime($startOfMonth . ' -1 month')) ?>" class="btn btn-outline">← Previous Month</a>
                    <a href="?view=month&date=<?= date('Y-m-d') ?>" class="btn btn-secondary">This Month</a>
                    <a href="?view=month&date=<?= date('Y-m-d', strtotime($startOfMonth . ' +1 month')) ?>" class="btn btn-outline">Next Month →</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Period Summary -->
    <div class="period-summary">
        <h2><?= $periodTitle ?></h2>
        <div class="summary-stats">
            <div class="stat-card">
                <div class="stat-number"><?= $totalCustomers ?></div>
                <div class="stat-label">Customers</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $totalOrders ?></div>
                <div class="stat-label">Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">$<?= number_format($totalAmount, 2) ?></div>
                <div class="stat-label">Total Value</div>
            </div>
        </div>
    </div>
    
    <?php if ($view === 'week'): ?>
        <!-- Week View -->
        <div class="week-view">
            <div class="week-grid">
                <div class="week-header">
                    <div class="customer-column">Customer</div>
                    <?php foreach ($weekDates as $dateInfo): ?>
                        <div class="day-column">
                            <div class="day-name"><?= $dateInfo['day_name'] ?></div>
                            <div class="date-short"><?= $dateInfo['date_short'] ?></div>
                        </div>
                    <?php endforeach; ?>
                    <div class="total-column">Total</div>
                </div>
                
                <?php foreach ($customerSummary as $customerId => $customer): ?>
                    <div class="customer-row">
                        <div class="customer-info">
                            <div class="customer-name"><?= htmlspecialchars($customer['customer_name']) ?></div>
                            <div class="customer-zone"><?= htmlspecialchars($customer['zone']) ?></div>
                        </div>
                        
                        <?php foreach ($weekDates as $dateInfo): ?>
                            <div class="day-cell">
                                <?php if (isset($customer['order_dates'][$dateInfo['date']])): ?>
                                    <?php $orderInfo = $customer['order_dates'][$dateInfo['date']]; ?>
                                    <div class="order-cell status-<?= $orderInfo['status'] ?>" 
                                         onclick="showOrderDetails(<?= $customerId ?>, '<?= $dateInfo['date'] ?>')">
                                        <div class="order-amount">$<?= number_format($orderInfo['amount'], 2) ?></div>
                                        <div class="order-status"><?= ucfirst($orderInfo['status']) ?></div>
                                    </div>
                                <?php else: ?>
                                    <div class="no-order">-</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        
                        <div class="customer-total">
                            <div class="total-amount">$<?= number_format($customer['total_amount'], 2) ?></div>
                            <div class="total-orders"><?= $customer['total_orders'] ?> orders</div>
                            <div class="invoice-actions">
                                <button class="btn btn-invoice" onclick="generateInvoice(<?= $customerId ?>, '<?= $startDate ?>', '<?= $endDate ?>')">
                                    📄 Create Invoice
                                </button>
                                <button class="btn btn-email" onclick="emailInvoice(this, <?= $customerId ?>, '<?= $startDate ?>', '<?= $endDate ?>')">
                                    📧 Email Invoice
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php else: ?>
        <!-- Month View -->
        <div class="month-view">
            <?php foreach ($customerSummary as $customerId => $customer): ?>
                <div class="customer-summary-card">
                    <div class="customer-header">
                        <h3><?= htmlspecialchars($customer['customer_name']) ?></h3>
                        <div class="customer-meta">
                            <span class="zone"><?= htmlspecialchars($customer['zone']) ?></span>
                            <span class="order-count"><?= $customer['total_orders'] ?> orders</span>
                            <span class="total-amount">$<?= number_format($customer['total_amount'], 2) ?></span>
                        </div>
                        <div class="invoice-actions">
                            <button class="btn btn-invoice" onclick="generateInvoice(<?= $customerId ?>, '<?= $startDate ?>', '<?= $endDate ?>')">
                                📄 Create Invoice
                            </button>
                            <button class="btn btn-email" onclick="emailInvoice(this, <?= $customerId ?>, '<?= $startDate ?>', '<?= $endDate ?>')">
                                📧 Email Invoice
                            </button>
                        </div>
                    </div>
                    
                    <div class="customer-details">
                        <div class="order-dates">
                            <?php foreach ($customer['order_dates'] as $date => $orderInfo): ?>
                                <div class="order-date-item status-<?= $orderInfo['status'] ?>" 
                                     onclick="showOrderDetails(<?= $customerId ?>, '<?= $date ?>')">
                                    <div class="date"><?= date('M j', strtotime($date)) ?></div>
                                    <div class="amount">$<?= number_format($orderInfo['amount'], 2) ?></div>
                                    <div class="status"><?= ucfirst($orderInfo['status']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="products-summary">
                            <h4>Products Summary</h4>
                            <?php foreach ($customer['products_summary'] as $productName => $productInfo): ?>
                                <div class="product-summary-item">
                                    <span class="product-name"><?= htmlspecialchars($productName) ?></span>
                                    <span class="product-quantity"><?= $productInfo['quantity'] ?> units</span>
                                    <span class="product-amount">$<?= number_format($productInfo['total_amount'], 2) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Order Details Modal -->
<div class="modal-overlay" id="orderDetailsModal" style="display: none;">
    <div class="modal modal-large">
        <div class="modal-header">
            <h3 id="modalTitle">Order Details</h3>
            <button type="button" class="modal-close" onclick="hideOrderDetails()">×</button>
        </div>
        <div class="modal-body" id="modalContent">
            <!-- Content loaded dynamically -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="hideOrderDetails()">Close</button>
        </div>
    </div>
</div>

<style>
.container {
    max-width: 1400px;
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

.view-controls {
    display: flex;
    gap: 20px;
    align-items: center;
}

.view-toggles {
    display: flex;
    gap: 5px;
}

.date-controls {
    display: flex;
    gap: 10px;
}

.btn {
    padding: 8px 16px;
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

.btn-secondary {
    background-color: #6c757d;
    color: white;
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

.btn-invoice {
    background-color: #1cc88a;
    color: white;
    font-size: 12px;
    padding: 6px 12px;
    margin: 2px;
}

.btn-invoice:hover {
    background-color: #17a673;
}

.btn-email {
    background-color: #f6c23e;
    color: #333;
    font-size: 12px;
    padding: 6px 12px;
    margin: 2px;
}

.btn-email:hover {
    background-color: #dda20a;
}

.invoice-actions {
    margin-top: 8px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.period-summary {
    background-color: #f8f9fa;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 30px;
}

.period-summary h2 {
    margin: 0 0 20px 0;
    color: #2c3e50;
}

.summary-stats {
    display: flex;
    gap: 30px;
}

.stat-card {
    text-align: center;
}

.stat-number {
    font-size: 32px;
    font-weight: bold;
    color: #4e73df;
}

.stat-label {
    color: #6c757d;
    font-size: 14px;
}

/* Week View Styles */
.week-grid {
    background-color: white;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.week-header {
    display: grid;
    grid-template-columns: 200px repeat(7, 1fr) 120px;
    background-color: #4e73df;
    color: white;
    font-weight: bold;
}

.week-header > div {
    padding: 15px 10px;
    text-align: center;
    border-right: 1px solid rgba(255,255,255,0.2);
}

.customer-column {
    text-align: left !important;
}

.customer-row {
    display: grid;
    grid-template-columns: 200px repeat(7, 1fr) 120px;
    border-bottom: 1px solid #e9ecef;
}

.customer-row:hover {
    background-color: #f8f9fa;
}

.customer-info {
    padding: 15px 10px;
    border-right: 1px solid #e9ecef;
}

.customer-name {
    font-weight: bold;
    color: #2c3e50;
}

.customer-zone {
    font-size: 12px;
    color: #6c757d;
}

.day-cell {
    padding: 5px;
    text-align: center;
    border-right: 1px solid #e9ecef;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 60px;
}

.order-cell {
    background-color: #e9ecef;
    padding: 8px;
    border-radius: 5px;
    cursor: pointer;
    transition: all 0.3s ease;
    width: 100%;
}

.order-cell:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.order-amount {
    font-weight: bold;
    font-size: 14px;
}

.order-status {
    font-size: 10px;
    text-transform: uppercase;
}

.no-order {
    color: #6c757d;
}

.customer-total {
    padding: 15px 10px;
    text-align: center;
    background-color: #f8f9fa;
}

.total-amount {
    font-weight: bold;
    color: #2c3e50;
}

.total-orders {
    font-size: 12px;
    color: #6c757d;
}

/* Month View Styles */
.month-view {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 20px;
}

.customer-summary-card {
    background-color: white;
    border: 1px solid #e9ecef;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.customer-header {
    background-color: #f8f9fa;
    padding: 20px;
    border-bottom: 1px solid #e9ecef;
}

.customer-header h3 {
    margin: 0 0 10px 0;
    color: #2c3e50;
}

.customer-meta {
    display: flex;
    gap: 15px;
    font-size: 14px;
}

.customer-meta span {
    padding: 4px 8px;
    background-color: #e9ecef;
    border-radius: 3px;
}

.customer-details {
    padding: 20px;
}

.order-dates {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 20px;
}

.order-date-item {
    background-color: #f8f9fa;
    padding: 10px;
    border-radius: 5px;
    cursor: pointer;
    transition: all 0.3s ease;
    text-align: center;
    min-width: 80px;
}

.order-date-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.products-summary h4 {
    margin: 0 0 15px 0;
    color: #2c3e50;
    font-size: 16px;
}

.product-summary-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #f8f9fa;
}

.product-name {
    flex: 1;
    font-weight: bold;
}

.product-quantity {
    color: #6c757d;
    margin-right: 15px;
}

.product-amount {
    font-weight: bold;
    color: #2c3e50;
}

/* Status Colors */
.status-pending { border-left: 4px solid #6c757d; }
.status-confirmed { border-left: 4px solid #17a2b8; }
.status-in_production { border-left: 4px solid #ffc107; }
.status-ready { border-left: 4px solid #007bff; }
.status-out_for_delivery { border-left: 4px solid #17a2b8; }
.status-delivered { border-left: 4px solid #28a745; }
.status-invoiced { border-left: 4px solid #343a40; }

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
    max-width: 600px;
    width: 90%;
}

.modal-large {
    max-width: 800px;
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
    max-height: 60vh;
    overflow-y: auto;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 20px;
    border-top: 1px solid #e9ecef;
}

.order-detail-header {
    background-color: #f8f9fa;
    padding: 15px;
    border-radius: 5px;
    margin-bottom: 20px;
}

.order-items-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
}

.order-items-table th,
.order-items-table td {
    padding: 10px;
    text-align: left;
    border-bottom: 1px solid #e9ecef;
}

.order-items-table th {
    background-color: #f8f9fa;
    font-weight: bold;
}

.order-total {
    background-color: #f8f9fa;
    padding: 15px;
    border-radius: 5px;
    text-align: right;
}

@media (max-width: 768px) {
    .week-grid {
        overflow-x: auto;
    }
    
    .week-header,
    .customer-row {
        min-width: 800px;
    }
    
    .view-controls {
        flex-direction: column;
        gap: 15px;
    }
    
    .summary-stats {
        flex-direction: column;
        gap: 15px;
    }
    
    .month-view {
        grid-template-columns: 1fr;
    }
    
    .customer-meta {
        flex-direction: column;
        gap: 8px;
    }
}
</style>

<script>
function showOrderDetails(customerId, date) {
    const modal = document.getElementById('orderDetailsModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalContent = document.getElementById('modalContent');
    
    modalTitle.textContent = 'Loading...';
    modalContent.innerHTML = '<div style="text-align: center; padding: 40px;">Loading order details...</div>';
    modal.style.display = 'flex';
    
    fetch('orders.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_order_details&customer_id=${customerId}&date=${date}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const order = data.order;
            const items = data.items;
            
            modalTitle.textContent = `${order.customer_name} - ${new Date(date).toLocaleDateString()}`;
            
            let itemsHtml = '';
            items.forEach(item => {
                itemsHtml += `
                    <tr>
                        <td>${item.product_name}</td>
                        <td>${item.quantity}</td>
                        <td>$${parseFloat(item.unit_price).toFixed(2)}</td>
                        <td>$${parseFloat(item.line_total).toFixed(2)}</td>
                    </tr>
                `;
            });
            
            modalContent.innerHTML = `
                <div class="order-detail-header">
                    <h4>${order.customer_name}</h4>
                    <p><strong>Address:</strong> ${order.address}</p>
                    <p><strong>Date:</strong> ${new Date(date).toLocaleDateString()}</p>
                    <p><strong>Status:</strong> <span class="status-badge status-${order.status}">${order.status.replace('_', ' ').toUpperCase()}</span></p>
                    ${order.notes ? `<p><strong>Notes:</strong> ${order.notes}</p>` : ''}
                </div>
                
                <table class="order-items-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${itemsHtml}
                    </tbody>
                </table>
                
                <div class="order-total">
                    <h3>Total: $${parseFloat(order.total_amount).toFixed(2)}</h3>
                </div>
            `;
        } else {
            modalContent.innerHTML = `<div class="alert alert-danger">Error: ${data.error}</div>`;
        }
    })
    .catch(error => {
        modalContent.innerHTML = `<div class="alert alert-danger">Error loading order details: ${error.message}</div>`;
    });
}

function hideOrderDetails() {
    document.getElementById('orderDetailsModal').style.display = 'none';
}

// Close modal when clicking outside
document.addEventListener('click', function(event) {
    const modal = document.getElementById('orderDetailsModal');
    if (event.target === modal) {
        hideOrderDetails();
    }
});

function generateInvoice(customerId, startDate, endDate) {
    // Use the simple working version
    const url = `simple_invoice.php?customer_id=${customerId}&start_date=${startDate}&end_date=${endDate}`;
    window.open(url, '_blank');
}

function emailInvoice(button, customerId, startDate, endDate) {
    const originalText = button.innerHTML;
    const resetButton = (label, color) => {
        button.innerHTML = label;
        button.style.backgroundColor = color;
        setTimeout(() => {
            button.innerHTML = originalText;
            button.style.backgroundColor = '#f6c23e';
            button.disabled = false;
        }, 4000);
    };

    button.innerHTML = '📧 Sending...';
    button.disabled = true;

    fetch(`generate_invoice_simple.php?customer_id=${customerId}&start_date=${startDate}&end_date=${endDate}&action=email`, {
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
    })
        .then(async (response) => {
            const raw = await response.text();
            let data;
            try {
                data = JSON.parse(raw);
            } catch (parseError) {
                const snippet = (raw || '').replace(/\s+/g, ' ').trim().slice(0, 240);
                throw new Error(snippet
                    ? `Server returned non-JSON (HTTP ${response.status}): ${snippet}`
                    : `Server returned empty/non-JSON response (HTTP ${response.status})`);
            }
            if (!response.ok && !data.message && !data.error) {
                throw new Error(`HTTP ${response.status}`);
            }
            return data;
        })
        .then(data => {
            if (data.success) {
                resetButton('✅ Sent!', '#28a745');
                showNotification('success', data.message);
            } else {
                resetButton('❌ Failed', '#dc3545');
                showNotification('error', data.message || data.error || 'Failed to send email');
            }
        })
        .catch(error => {
            resetButton('❌ Error', '#dc3545');
            showNotification('error', error.message || 'Network error occurred');
            console.error('emailInvoice failed:', error);
        });
}

function showNotification(type, message) {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <div class="notification-content">
            <span class="notification-icon">${type === 'success' ? '✅' : '❌'}</span>
            <span class="notification-message">${message}</span>
            <button class="notification-close" onclick="this.parentElement.parentElement.remove()">×</button>
        </div>
    `;
    
    // Add styles if not already added
    if (!document.querySelector('#notification-styles')) {
        const styles = document.createElement('style');
        styles.id = 'notification-styles';
        styles.textContent = `
            .notification {
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 10000;
                max-width: 400px;
                margin-bottom: 10px;
                animation: slideIn 0.3s ease-out;
            }
            
            .notification-content {
                display: flex;
                align-items: center;
                padding: 15px;
                border-radius: 5px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                color: white;
                font-weight: 500;
            }
            
            .notification-success .notification-content {
                background-color: #28a745;
            }
            
            .notification-error .notification-content {
                background-color: #dc3545;
            }
            
            .notification-icon {
                margin-right: 10px;
                font-size: 16px;
            }
            
            .notification-message {
                flex: 1;
                margin-right: 10px;
            }
            
            .notification-close {
                background: none;
                border: none;
                color: white;
                font-size: 18px;
                cursor: pointer;
                padding: 0;
                width: 20px;
                height: 20px;
                display: flex;
                justify-content: center;
                align-items: center;
            }
            
            @keyframes slideIn {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
        `;
        document.head.appendChild(styles);
    }
    
    // Add to page
    document.body.appendChild(notification);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, 5000);
}
</script>

<?php require_once 'includes/footer.php'; ?>
