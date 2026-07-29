<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';

// Initialize database connection
$db = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Set page title
$page_title = 'Product Distribution Explorer';

// Helper function to convert day number to day name
function getDayName($dayNumber) {
    $days = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    return $days[$dayNumber] ?? 'Unknown';
}

// Get all products with their default quantities
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

// Group products by product line
$productLines = [];
foreach ($products as $product) {
    if (!isset($productLines[$product['product_line']])) {
        $productLines[$product['product_line']] = [];
    }
    $productLines[$product['product_line']][] = $product;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'get_product_customers':
                $productId = (int)$_POST['product_id'];
                $selectedDay = isset($_POST['day']) ? (int)$_POST['day'] : null;
                
                // Get customers with standing orders for this product
                $dayFilter = $selectedDay !== null ? "AND so.day_of_week = $selectedDay" : "";
                
                $stmt = $db->prepare("
                    SELECT 
                        c.id, 
                        c.name, 
                        c.zone,
                        so.day_of_week,
                        so.quantity,
                        sr.driver_id,
                        d.name as driver_name
                    FROM customers c
                    JOIN standing_orders so ON c.id = so.customer_id
                    LEFT JOIN standing_routes sr ON c.id = sr.customer_id AND sr.day_of_week = so.day_of_week
                    LEFT JOIN drivers d ON sr.driver_id = d.id
                    WHERE so.product_id = ? $dayFilter
                    ORDER BY c.zone, c.name, so.day_of_week
                ");
                $stmt->execute([$productId]);
                $customersWithOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Get customers with standing routes but no orders for this product
                $dayFilter2 = $selectedDay !== null ? "AND sr.day_of_week = $selectedDay" : "";
                $dayFilterSubquery = $selectedDay !== null ? "AND day_of_week = $selectedDay" : "";
                
                $stmt2 = $db->prepare("
                    SELECT 
                        c.id, 
                        c.name, 
                        c.zone,
                        sr.day_of_week,
                        sr.driver_id,
                        d.name as driver_name
                    FROM customers c
                    JOIN standing_routes sr ON c.id = sr.customer_id
                    LEFT JOIN drivers d ON sr.driver_id = d.id
                    WHERE c.id NOT IN (
                        SELECT DISTINCT customer_id 
                        FROM standing_orders 
                        WHERE product_id = ? $dayFilterSubquery
                    ) $dayFilter2
                    ORDER BY c.zone, c.name, sr.day_of_week
                ");
                $stmt2->execute([$productId]);
                $customersWithRoutes = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                
                // Get remaining customers (no standing routes)
                $stmt3 = $db->prepare("
                    SELECT 
                        c.id, 
                        c.name, 
                        c.zone
                    FROM customers c
                    WHERE c.id NOT IN (
                        SELECT DISTINCT customer_id FROM standing_routes
                    )
                    ORDER BY c.zone, c.name
                ");
                $stmt3->execute();
                $remainingCustomers = $stmt3->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'customers_with_orders' => $customersWithOrders,
                    'customers_with_routes' => $customersWithRoutes,
                    'remaining_customers' => $remainingCustomers
                ]);
                break;
                
            case 'update_standing_order':
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
                
            default:
                throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Include header
require_once 'includes/header.php';

// Include navigation
require_once 'includes/nav.php';

$dayNames = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
?>

<div class="product-distribution-container">
    <h1>Product Distribution Explorer</h1>
    
    <div class="controls-panel">
        <div class="product-selector">
            <h2>Select a Product</h2>
            <p>Click on a product to see all customers who have standing orders for that product, organized by zone.</p>
            
            <div class="day-filter">
                <label>Filter by day:</label>
                <select id="day-filter">
                    <option value="">All Days</option>
                    <?php foreach ($dayNames as $dayNum => $dayName): ?>
                        <?php if ($dayNum > 0): ?>
                            <option value="<?php echo $dayNum; ?>"><?php echo $dayName; ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="product-grid">
                <?php foreach ($productLines as $productLineName => $productLineProducts): ?>
                    <div class="product-line-section">
                        <h3><?php echo htmlspecialchars($productLineName); ?></h3>
                        <div class="products-list">
                            <?php foreach ($productLineProducts as $product): ?>
                                <div class="product-card" data-product-id="<?php echo $product['id']; ?>">
                                    <div class="product-header">
                                        <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                        <div class="product-details">
                                            <span class="dough-type"><?php echo htmlspecialchars($product['dough_type']); ?></span>
                                            <span class="price">$<?php echo number_format($product['price'] ?? 0, 2); ?></span>
                                        </div>
                                    </div>
                                    <div class="product-defaults">
                                        <div class="defaults-label">Default quantities:</div>
                                        <div class="defaults-grid">
                                            <?php 
                                            $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                                            $dayColumns = ['default_quantity_monday', 'default_quantity_tuesday', 'default_quantity_wednesday', 
                                                          'default_quantity_thursday', 'default_quantity_friday', 'default_quantity_saturday', 'default_quantity_sunday'];
                                            foreach ($days as $index => $shortDay): 
                                                $dayColumn = $dayColumns[$index];
                                                $defaultQty = $product[$dayColumn] ?? 0;
                                            ?>
                                                <div class="default-item">
                                                    <span class="day-label"><?php echo $shortDay; ?></span>
                                                    <span class="default-qty"><?php echo $defaultQty; ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <div id="customer-results" class="customer-results" style="display: none;">
        <div class="results-header">
            <h2 id="selected-product-name"></h2>
            <button id="close-results" class="close-btn">×</button>
        </div>
        
        <div class="results-content">
            <div class="customer-sections">
                <!-- Customers with standing orders -->
                <div class="customer-section">
                    <h3 class="section-title">Customers with Standing Orders</h3>
                    <div id="customers-with-orders" class="zone-groups"></div>
                </div>
                
                <!-- Customers with standing routes but no orders -->
                <div class="customer-section">
                    <h3 class="section-title">Next Assignable (Have Standing Routes)</h3>
                    <div id="customers-with-routes" class="zone-groups"></div>
                </div>
                
                <!-- Remaining customers -->
                <div class="customer-section">
                    <h3 class="section-title">Remaining Customers</h3>
                    <div id="remaining-customers" class="zone-groups"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let selectedProductId = null;
    let selectedProductName = '';
    
    // Product card click handler
    document.querySelectorAll('.product-card').forEach(card => {
        card.addEventListener('click', function() {
            selectedProductId = this.dataset.productId;
            selectedProductName = this.querySelector('.product-name').textContent;
            loadProductCustomers(selectedProductId);
        });
    });
    
    // Day filter change handler
    document.getElementById('day-filter').addEventListener('change', function() {
        if (selectedProductId) {
            loadProductCustomers(selectedProductId);
        }
    });
    
    // Close results handler
    document.getElementById('close-results').addEventListener('click', function() {
        document.getElementById('customer-results').style.display = 'none';
    });
    
    function loadProductCustomers(productId) {
        const selectedDay = document.getElementById('day-filter').value;
        
        fetch('product_distribution.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=get_product_customers&product_id=${productId}&day=${selectedDay}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayResults(data);
            } else {
                alert('Error loading customer data: ' + (data.error || 'Please try again.'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading customer data. Please try again.');
        });
    }
    
    function displayResults(data) {
        // Update header
        const dayFilter = document.getElementById('day-filter').value;
        const dayText = dayFilter ? ` (${getDayName(dayFilter)})` : ' (All Days)';
        document.getElementById('selected-product-name').textContent = selectedProductName + dayText;
        
        // Display customers with orders
        displayCustomerSection('customers-with-orders', data.customers_with_orders, true);
        
        // Display customers with routes
        displayCustomerSection('customers-with-routes', data.customers_with_routes, false);
        
        // Display remaining customers
        displayRemainingCustomers('remaining-customers', data.remaining_customers);
        
        // Show results
        document.getElementById('customer-results').style.display = 'block';
    }
    
    function displayCustomerSection(containerId, customers, hasOrders) {
        const container = document.getElementById(containerId);
        container.innerHTML = '';
        
        if (customers.length === 0) {
            container.innerHTML = '<p class="no-customers">No customers found.</p>';
            return;
        }
        
        // Group by zone
        const customersByZone = {};
        customers.forEach(customer => {
            const zone = customer.zone || 'No Zone';
            if (!customersByZone[zone]) {
                customersByZone[zone] = [];
            }
            customersByZone[zone].push(customer);
        });
        
        // Create zone sections
        Object.keys(customersByZone).sort().forEach(zone => {
            const zoneCustomers = customersByZone[zone];
            const zoneSection = document.createElement('div');
            zoneSection.className = 'zone-section';
            
            const zoneHeader = document.createElement('div');
            zoneHeader.className = 'zone-header';
            zoneHeader.innerHTML = `
                <h4>${zone} (${zoneCustomers.length} customers)</h4>
                <button class="toggle-zone">▼</button>
            `;
            
            const zoneContent = document.createElement('div');
            zoneContent.className = 'zone-content';
            
            zoneCustomers.forEach(customer => {
                const customerCard = document.createElement('div');
                customerCard.className = 'customer-card';
                
                const dayInfo = hasOrders ? 
                    `<div class="customer-days">
                        ${customer.day_of_week ? `<span class="day-badge">${getDayName(customer.day_of_week)}</span>` : ''}
                        ${customer.quantity ? `<span class="quantity-badge">${customer.quantity}</span>` : ''}
                    </div>` : 
                    `<div class="customer-days">
                        ${customer.day_of_week ? `<span class="day-badge">${getDayName(customer.day_of_week)}</span>` : ''}
                    </div>`;
                
                const driverInfo = customer.driver_name ? 
                    `<div class="driver-info">Driver: ${customer.driver_name}</div>` : '';
                
                const quantityInput = hasOrders ? 
                    `<div class="quantity-input-container">
                        <input type="number" 
                               class="quantity-input" 
                               value="${customer.quantity || 0}" 
                               min="0"
                               data-customer-id="${customer.id}"
                               data-product-id="${selectedProductId}"
                               data-day="${customer.day_of_week}"
                               placeholder="Qty">
                    </div>` : '';
                
                customerCard.innerHTML = `
                    <div class="customer-info">
                        <div class="customer-name">${customer.name}</div>
                        ${dayInfo}
                        ${driverInfo}
                    </div>
                    ${quantityInput}
                `;
                
                zoneContent.appendChild(customerCard);
            });
            
            zoneSection.appendChild(zoneHeader);
            zoneSection.appendChild(zoneContent);
            container.appendChild(zoneSection);
            
            // Add toggle functionality
            zoneHeader.querySelector('.toggle-zone').addEventListener('click', function() {
                zoneContent.classList.toggle('collapsed');
                this.textContent = zoneContent.classList.contains('collapsed') ? '▶' : '▼';
            });
        });
        
        // Add event listeners for quantity inputs
        if (hasOrders) {
            container.querySelectorAll('.quantity-input').forEach(input => {
                input.addEventListener('change', function() {
                    updateStandingOrder(
                        this.dataset.customerId,
                        this.dataset.productId,
                        this.dataset.day,
                        this.value
                    );
                });
            });
        }
    }
    
    function displayRemainingCustomers(containerId, customers) {
        const container = document.getElementById(containerId);
        container.innerHTML = '';
        
        if (customers.length === 0) {
            container.innerHTML = '<p class="no-customers">No remaining customers.</p>';
            return;
        }
        
        // Group by zone
        const customersByZone = {};
        customers.forEach(customer => {
            const zone = customer.zone || 'No Zone';
            if (!customersByZone[zone]) {
                customersByZone[zone] = [];
            }
            customersByZone[zone].push(customer);
        });
        
        // Create zone sections
        Object.keys(customersByZone).sort().forEach(zone => {
            const zoneCustomers = customersByZone[zone];
            const zoneSection = document.createElement('div');
            zoneSection.className = 'zone-section';
            
            const zoneHeader = document.createElement('div');
            zoneHeader.className = 'zone-header';
            zoneHeader.innerHTML = `
                <h4>${zone} (${zoneCustomers.length} customers)</h4>
                <button class="toggle-zone">▼</button>
            `;
            
            const zoneContent = document.createElement('div');
            zoneContent.className = 'zone-content';
            
            zoneCustomers.forEach(customer => {
                const customerCard = document.createElement('div');
                customerCard.className = 'customer-card';
                customerCard.innerHTML = `
                    <div class="customer-info">
                        <div class="customer-name">${customer.name}</div>
                        <div class="customer-note">No standing routes assigned</div>
                    </div>
                `;
                zoneContent.appendChild(customerCard);
            });
            
            zoneSection.appendChild(zoneHeader);
            zoneSection.appendChild(zoneContent);
            container.appendChild(zoneSection);
            
            // Add toggle functionality
            zoneHeader.querySelector('.toggle-zone').addEventListener('click', function() {
                zoneContent.classList.toggle('collapsed');
                this.textContent = zoneContent.classList.contains('collapsed') ? '▶' : '▼';
            });
        });
    }
    
    function updateStandingOrder(customerId, productId, dayOfWeek, quantity) {
        fetch('product_distribution.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=update_standing_order&customer_id=${customerId}&product_id=${productId}&day_of_week=${dayOfWeek}&quantity=${quantity}`
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                alert('Error updating standing order: ' + (data.error || 'Please try again.'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error updating standing order. Please try again.');
        });
    }
    
    function getDayName(dayNumber) {
        const days = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        return days[dayNumber] || 'Unknown';
    }
});
</script>

<style>
.product-distribution-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.controls-panel {
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    padding: 20px;
    margin-bottom: 20px;
}

.product-selector h2 {
    margin-top: 0;
    color: #333;
}

.day-filter {
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.day-filter label {
    font-weight: 500;
    color: #555;
}

.day-filter select {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: white;
    font-size: 14px;
}

.product-grid {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.product-line-section h3 {
    margin: 0 0 15px 0;
    color: #333;
    font-size: 1.2em;
    border-bottom: 2px solid #007bff;
    padding-bottom: 5px;
}

.products-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 15px;
}

.product-card {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 15px;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.product-card:hover {
    border-color: #007bff;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.product-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 10px;
}

.product-name {
    font-weight: bold;
    color: #333;
    font-size: 1.1em;
    flex: 1;
}

.product-details {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
}

.dough-type {
    font-size: 0.9em;
    color: #666;
    background: #f8f9fa;
    padding: 2px 6px;
    border-radius: 3px;
}

.price {
    font-weight: 500;
    color: #28a745;
}

.product-defaults {
    border-top: 1px solid #eee;
    padding-top: 10px;
}

.defaults-label {
    font-size: 0.9em;
    color: #666;
    margin-bottom: 8px;
}

.defaults-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 4px;
}

.default-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}

.day-label {
    font-size: 0.8em;
    color: #666;
    font-weight: 500;
}

.default-qty {
    font-size: 0.9em;
    color: #333;
    font-weight: 500;
}

.customer-results {
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-top: 20px;
}

.results-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    border-bottom: 1px solid #eee;
}

.results-header h2 {
    margin: 0;
    color: #333;
}

.close-btn {
    background: none;
    border: none;
    font-size: 24px;
    color: #666;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.2s ease;
}

.close-btn:hover {
    background: #f8f9fa;
    color: #333;
}

.results-content {
    padding: 20px;
}

.customer-sections {
    display: flex;
    flex-direction: column;
    gap: 30px;
}

.customer-section {
    border: 1px solid #ddd;
    border-radius: 8px;
    overflow: hidden;
}

.section-title {
    margin: 0;
    padding: 15px 20px;
    background: #f8f9fa;
    border-bottom: 1px solid #ddd;
    color: #333;
    font-size: 1.1em;
}

.zone-groups {
    padding: 20px;
}

.zone-section {
    margin-bottom: 20px;
    border: 1px solid #e9ecef;
    border-radius: 6px;
    overflow: hidden;
}

.zone-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 15px;
    background: #f8f9fa;
    border-bottom: 1px solid #e9ecef;
    cursor: pointer;
}

.zone-header h4 {
    margin: 0;
    color: #333;
    font-size: 1em;
}

.toggle-zone {
    background: none;
    border: none;
    font-size: 16px;
    color: #666;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 4px;
    transition: all 0.2s ease;
}

.toggle-zone:hover {
    background: #e9ecef;
    color: #333;
}

.zone-content {
    padding: 15px;
    background: white;
}

.zone-content.collapsed {
    display: none;
}

.customer-card {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    border: 1px solid #e9ecef;
    border-radius: 6px;
    margin-bottom: 8px;
    background: white;
    transition: all 0.2s ease;
}

.customer-card:hover {
    border-color: #007bff;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.customer-info {
    flex: 1;
}

.customer-name {
    font-weight: 500;
    color: #333;
    margin-bottom: 4px;
}

.customer-days {
    display: flex;
    gap: 8px;
    align-items: center;
}

.day-badge {
    background: #007bff;
    color: white;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 0.8em;
    font-weight: 500;
}

.quantity-badge {
    background: #28a745;
    color: white;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 0.8em;
    font-weight: 500;
}

.driver-info {
    font-size: 0.9em;
    color: #666;
    margin-top: 4px;
}

.customer-note {
    font-size: 0.9em;
    color: #666;
    font-style: italic;
}

.quantity-input-container {
    margin-left: 15px;
}

.quantity-input {
    width: 80px;
    padding: 6px 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    text-align: center;
    font-size: 0.9em;
}

.quantity-input:focus {
    border-color: #007bff;
    outline: none;
    box-shadow: 0 0 0 2px rgba(0,123,255,0.25);
}

.no-customers {
    text-align: center;
    color: #666;
    font-style: italic;
    padding: 20px;
}

/* Responsive design */
@media (max-width: 768px) {
    .products-list {
        grid-template-columns: 1fr;
    }
    
    .customer-card {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .quantity-input-container {
        margin-left: 0;
        align-self: flex-end;
    }
    
    .defaults-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}
</style>

<?php
require_once 'includes/footer.php';
?>
