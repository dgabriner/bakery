<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/customer_order_mutations.php';

// Handle AJAX request to save order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_order') {
    header('Content-Type: application/json');
    
    try {
        $customerId = (int)$_POST['customer_id'];
        $productId = (int)$_POST['product_id'];
        $dayOfWeek = (int)$_POST['day_of_week'];
        $quantity = (int)$_POST['quantity'];
        bakery_standing_order_upsert($db, $customerId, $productId, $dayOfWeek, $quantity);
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => bakery_error_message_for_user($e)]);
    }
    exit;
}

require_once 'includes/header.php';
require_once 'includes/nav.php';

// Fetch customers and products
try {
    $customers = $db->query("SELECT id, name FROM customers ORDER BY name")->fetchAll();
    
    // Fetch products with their dough types, including unclassified products
    $products = $db->query("
        SELECT 
            p.id, 
            p.name,
            p.dough_type_id,
            dt.name as dough_type_name
        FROM products p
        LEFT JOIN dough_types dt ON p.dough_type_id = dt.id
        ORDER BY 
            CASE WHEN dt.name IS NULL THEN 1 ELSE 0 END, 
            dt.name,
            p.name
    ")->fetchAll();
    
    // Group products by dough type
    $productsByDoughType = [];
    foreach ($products as $product) {
        $doughType = $product['dough_type_name'] ?: 'Unclassified';
        if (!isset($productsByDoughType[$doughType])) {
            $productsByDoughType[$doughType] = [];
        }
        $productsByDoughType[$doughType][] = $product;
    }
} catch (Exception $e) {
    // Initialize empty array to prevent undefined variable errors
    $productsByDoughType = [];
    echo '<div class="error">Error loading data: ' . htmlspecialchars($e->getMessage()) . '</div>';
    // Don't exit, allow page to render with empty data
}

// Set page title
$page_title = bakery_t('page.standing_orders');

// Load existing standing orders
try {
    $existingOrders = [];
    $orders = $db->query("SELECT customer_id, product_id, day_of_week, quantity FROM standing_orders")->fetchAll();
    
    foreach ($orders as $order) {
        if (!isset($existingOrders[$order['customer_id']])) {
            $existingOrders[$order['customer_id']] = [];
        }
        if (!isset($existingOrders[$order['customer_id']][$order['product_id']])) {
            $existingOrders[$order['customer_id']][$order['product_id']] = [];
        }
        $existingOrders[$order['customer_id']][$order['product_id']][$order['day_of_week']] = $order['quantity'];
    }
} catch (Exception $e) {
    // If there's an error, just continue with empty orders
    $existingOrders = [];
}

$days = [
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
    7 => 'Sunday'
];
?>

<div class="container">
    <h1>Standing Orders</h1>
    
    <!-- Filter Info -->
    <div class="filter-info">
        <span id="filter-status">Showing: All Customers, All Days</span>
        <button id="clear-filter" style="display: none; margin-left: 10px;" class="btn btn-sm btn-secondary">Clear All Filters</button>
    </div>
    
    <!-- Day Filter Instructions -->
    <div class="day-filter-container">
        <h3>Filter by Day</h3>
        <div id="day-instruction" class="instruction-text">
            Click on any day column header (Monday, Tuesday, etc.) to show only that day's standing orders across all customers.
        </div>
    </div>
    
    <!-- Customer Filter Buttons -->
    <div class="customers-container">
        <h3>Filter by Customer</h3>
        <div id="customer-instruction" class="instruction-text">
            Click a customer to filter and show only their standing orders.
        </div>
        <div class="customers-list">
            <?php foreach ($customers as $customer): ?>
                <div class="customer-filter-btn" 
                     data-customer-id="<?php echo $customer['id']; ?>"
                     data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>">
                    <?php echo htmlspecialchars($customer['name']); ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div class="standing-orders">
        <?php foreach ($customers as $customer): ?>
            <div class="customer-section" data-customer-id="<?php echo $customer['id']; ?>">
                <h2><?php echo htmlspecialchars($customer['name']); ?></h2>
                <div class="week-schedule">
                    <?php foreach ($days as $dayNum => $dayName): ?>
                        <div class="day-column" data-day-num="<?php echo $dayNum; ?>">
                            <h3 class="day-header clickable" data-day-num="<?php echo $dayNum; ?>" data-day-name="<?php echo $dayName; ?>">
                                <?php echo $dayName; ?>
                            </h3>
                            <div class="product-list">
                                <?php 
                                // Safety check to prevent undefined variable errors
                                if (!isset($productsByDoughType)) {
                                    $productsByDoughType = [];
                                }
                                foreach ($productsByDoughType as $doughType => $doughProducts): 
                                ?>
                                    <div class="dough-type-section">
                                        <h4 class="dough-type-header"><?php echo htmlspecialchars($doughType); ?></h4>
                                        <div class="dough-type-products">
                                            <?php foreach ($doughProducts as $product): 
                                                // Get existing quantity for this product and day
                                                $quantity = 0;
                                                if (isset($existingOrders[$customer['id']][$product['id']][$dayNum])) {
                                                    $quantity = (int)$existingOrders[$customer['id']][$product['id']][$dayNum];
                                                }
                                            ?>
                                                <div class="product-item">
                                                    <span class="product-name"><?php echo htmlspecialchars($product['name']); ?></span>
                                                    <input type="number" 
                                                           class="product-quantity" 
                                                           placeholder="0" 
                                                           min="0"
                                                           data-customer-id="<?php echo $customer['id']; ?>"
                                                           data-product-id="<?php echo $product['id']; ?>"
                                                           data-day="<?php echo $dayNum; ?>"
                                                           value="<?php echo $quantity; ?>">
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

<style>
    .filter-info {
        margin-bottom: 20px;
        padding: 10px;
        background-color: #e9ecef;
        border-radius: 5px;
        font-weight: bold;
    }
    
    .instruction-text {
        font-size: 0.9em;
        color: #6c757d;
        margin-bottom: 10px;
        font-style: italic;
    }
    
    /* Customer filter buttons */
    .customers-container {
        margin-bottom: 30px;
        padding: 15px;
        background-color: #f8f9fa;
        border-radius: 5px;
    }
    
    .customers-list {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 10px;
    }
    
    .customer-filter-btn {
        background-color: #4e73df;
        color: white;
        padding: 8px 12px;
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.2s;
        user-select: none;
        border: 2px solid transparent;
    }
    
    .customer-filter-btn:hover {
        background-color: #2e59d9;
        transform: translateY(-2px);
        border-color: #ffc107;
        box-shadow: 0 0 10px rgba(255, 193, 7, 0.3);
    }
    
    .customer-filter-btn.active {
        background-color: #28a745;
        border-color: #20c997;
        box-shadow: 0 0 15px rgba(40, 167, 69, 0.4);
    }
    
    .customer-filter-btn.active:hover {
        background-color: #218838;
        transform: translateY(-2px);
    }
    
    /* Filter states */
    .filtered-view .customer-section:not(.show-customer) {
        display: none;
    }
    
    .btn {
        padding: 8px 16px;
        margin-left: 10px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
    }
    
    .btn-secondary {
        background-color: #6c757d;
        color: white;
    }
    
    .btn-secondary:hover {
        background-color: #545b62;
    }
    
    .btn-sm {
        padding: 4px 8px;
        font-size: 12px;
    }
    
    /* Day filter styles */
    .day-filter-container {
        margin-bottom: 20px;
        padding: 15px;
        background-color: #e3f2fd;
        border-radius: 5px;
        border-left: 4px solid #2196f3;
    }
    
    /* Clickable day headers */
    .day-header.clickable {
        cursor: pointer;
        transition: all 0.3s ease;
        padding: 8px;
        border-radius: 4px;
        user-select: none;
    }
    
    .day-header.clickable:hover {
        background-color: #e3f2fd;
        color: #1976d2;
        transform: translateY(-1px);
        box-shadow: 0 2px 4px rgba(33, 150, 243, 0.2);
    }
    
    .day-header.active {
        background-color: #2196f3;
        color: white;
        box-shadow: 0 0 10px rgba(33, 150, 243, 0.4);
    }
    
    .day-header.active:hover {
        background-color: #1976d2;
    }
    
    /* Day filtering states */
    .day-filtered .day-column:not(.show-day) {
        display: none;
    }
    
    .day-column.show-day {
        border: 2px solid #2196f3;
        box-shadow: 0 0 10px rgba(33, 150, 243, 0.3);
    }

    .standing-orders {
        display: flex;
        flex-direction: column;
        gap: 2rem;
    }
    .customer-section {
        background-color: #f8f9fa;
        padding: 1rem;
        border-radius: 8px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        transition: all 0.3s ease;
    }
    
    .customer-section.show-customer {
        border: 2px solid #28a745;
        box-shadow: 0 6px 12px rgba(40, 167, 69, 0.2);
    }
    
    .week-schedule {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
    }
    .day-column {
        flex: 1;
        background-color: #ffffff;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 0.5rem;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }
    .product-list {
        margin-top: 0.5rem;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }
    .dough-type-section {
        margin-bottom: 1rem;
        background-color: #f8f9fa;
        border-radius: 6px;
        overflow: hidden;
    }
    .dough-type-header {
        background-color: #e9ecef;
        padding: 0.25rem 0.5rem;
        margin: 0;
        font-size: 0.9rem;
        font-weight: 600;
        color: #495057;
    }
    .dough-type-products {
        padding: 0.5rem;
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }
    .product-item {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        margin-bottom: 1rem;
        padding: 0.5rem;
        background: white;
        border-radius: 4px;
        box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    }
    
    .product-item .product-name {
        font-weight: 600;
        margin-bottom: 0.25rem;
    }
    
    .product-quantity {
        width: 60px;
        text-align: center;
        padding: 0.25rem;
        border: 1px solid #ced4da;
        border-radius: 4px;
    }
    
    .day-inputs input:focus {
        border-color: #80bdff;
        outline: 0;
        box-shadow: 0 0 0 0.2rem rgba(0,123,255,0.25);
    }
    .product-name {
        font-weight: 500;
    }
    .product-quantity {
        width: 60px;
        padding: 0.25rem;
        border: 1px solid #ccc;
        border-radius: 4px;
    }
    
    /* Responsive design */
    @media (max-width: 768px) {
        .week-schedule {
            flex-direction: column;
        }
        
        .day-column {
            margin-bottom: 1rem;
        }
        
        .customers-list {
            justify-content: center;
        }
        
        .customer-filter-btn {
            flex: 1;
            text-align: center;
            min-width: 120px;
        }
        
        .day-header.clickable {
            font-size: 1.1em;
            padding: 10px;
        }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let filteredCustomerId = null;
    let filteredDayOfWeek = null;
    let filteredDayName = null;
    
    // Get DOM elements
    const customerFilterBtns = document.querySelectorAll('.customer-filter-btn');
    const clearFilterBtn = document.getElementById('clear-filter');
    const filterStatus = document.getElementById('filter-status');
    const standingOrders = document.querySelector('.standing-orders');
    
    // Day filter elements
    const dayHeaders = document.querySelectorAll('.day-header.clickable');
    
    // Customer filtering functionality
    customerFilterBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const customerId = this.getAttribute('data-customer-id');
            const customerName = this.getAttribute('data-customer-name');
            filterByCustomer(customerId, customerName);
        });
    });
    
    clearFilterBtn.addEventListener('click', function() {
        clearAllFilters();
    });
    
    // Day filtering functionality
    dayHeaders.forEach(header => {
        header.addEventListener('click', function() {
            const dayNum = this.getAttribute('data-day-num');
            const dayName = this.getAttribute('data-day-name');
            filterByDay(dayNum, dayName);
        });
    });
    
    function filterByCustomer(customerId, customerName) {
        filteredCustomerId = customerId;
        
        // Update visual state of filter buttons
        customerFilterBtns.forEach(btn => btn.classList.remove('active'));
        document.querySelector(`[data-customer-id="${customerId}"]`).classList.add('active');
        
        // Show/hide customer sections
        standingOrders.classList.add('filtered-view');
        
        const allCustomerSections = document.querySelectorAll('.customer-section');
        allCustomerSections.forEach(section => {
            if (section.getAttribute('data-customer-id') === customerId) {
                section.classList.add('show-customer');
            } else {
                section.classList.remove('show-customer');
            }
        });
        
        // Update filter status and buttons
        updateFilterStatus();
        clearFilterBtn.style.display = 'inline-block';
        
        // Scroll to the filtered customer section
        const filteredSection = document.querySelector(`.customer-section[data-customer-id="${customerId}"]`);
        if (filteredSection) {
            filteredSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
    
    function filterByDay(dayNum, dayName) {
        filteredDayOfWeek = dayNum;
        filteredDayName = dayName;
        
        // Update visual state of day headers
        dayHeaders.forEach(header => header.classList.remove('active'));
        document.querySelector(`[data-day-num="${dayNum}"]`).classList.add('active');
        
        // Show/hide day columns
        standingOrders.classList.add('day-filtered');
        
        const allDayColumns = document.querySelectorAll('.day-column');
        allDayColumns.forEach((column, index) => {
            // Columns are ordered Monday=0, Tuesday=1, ..., Sunday=6
            const columnDayOfWeek = index + 1; // Convert to our 1-7 system
            if (columnDayOfWeek == filteredDayOfWeek) {
                column.classList.add('show-day');
            } else {
                column.classList.remove('show-day');
            }
        });
        
        // Update UI
        updateFilterStatus();
        clearFilterBtn.style.display = 'inline-block';
    }
    
    function clearCustomerFilter() {
        filteredCustomerId = null;
        
        // Reset visual state
        customerFilterBtns.forEach(btn => btn.classList.remove('active'));
        standingOrders.classList.remove('filtered-view');
        
        const allCustomerSections = document.querySelectorAll('.customer-section');
        allCustomerSections.forEach(section => {
            section.classList.remove('show-customer');
        });
        
        // Update UI
        updateFilterStatus();
        
        // Hide clear all button if no other filters active
        if (!filteredDayOfWeek) {
            clearFilterBtn.style.display = 'none';
        }
        
        // Scroll back to top
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    
    function clearDayFilter() {
        filteredDayOfWeek = null;
        filteredDayName = null;
        
        // Reset visual state
        dayHeaders.forEach(header => header.classList.remove('active'));
        standingOrders.classList.remove('day-filtered');
        
        const allDayColumns = document.querySelectorAll('.day-column');
        allDayColumns.forEach(column => {
            column.classList.remove('show-day');
        });
        
        // Update UI
        updateFilterStatus();
        
        // Hide clear all button if no other filters active
        if (!filteredCustomerId) {
            clearFilterBtn.style.display = 'none';
        }
    }
    
    function clearAllFilters() {
        clearCustomerFilter();
        clearDayFilter();
    }
    
    function updateFilterStatus() {
        let statusText = 'Showing: ';
        
        if (filteredCustomerId) {
            const customerName = document.querySelector(`[data-customer-id="${filteredCustomerId}"]`).getAttribute('data-customer-name');
            statusText += customerName;
        } else {
            statusText += 'All Customers';
        }
        
        statusText += ', ';
        
        if (filteredDayName) {
            statusText += filteredDayName;
        } else {
            statusText += 'All Days';
        }
        
        filterStatus.textContent = statusText;
    }

    // Check for preserved filters on page load
    const preservedFilters = localStorage.getItem('preserveStandingOrdersFilters');
    if (preservedFilters) {
        localStorage.removeItem('preserveStandingOrdersFilters');
        const filters = JSON.parse(preservedFilters);
        setTimeout(() => {
            if (filters.customer) {
                filterByCustomer(filters.customer.id, filters.customer.name);
            }
            if (filters.day) {
                filterByDay(filters.day.dayNum, filters.day.dayName);
            }
        }, 100);
    }

    // Add debounce function to limit how often we save
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, 500); // 500ms delay
        };
    }

    // Function to save order
    const saveOrder = debounce(function(input) {
        const customerId = input.dataset.customerId;
        const productId = input.dataset.productId;
        const dayOfWeek = input.dataset.day;
        const quantity = parseInt(input.value) || 0;

        // Store the current filter state before potential reload
        const filtersToPreserve = {};
        if (filteredCustomerId) {
            const customerName = document.querySelector(`[data-customer-id="${filteredCustomerId}"]`).getAttribute('data-customer-name');
            filtersToPreserve.customer = {
                id: filteredCustomerId,
                name: customerName
            };
        }
        if (filteredDayOfWeek) {
            filtersToPreserve.day = {
                dayNum: filteredDayOfWeek,
                dayName: filteredDayName
            };
        }
        
        if (Object.keys(filtersToPreserve).length > 0) {
            localStorage.setItem('preserveStandingOrdersFilters', JSON.stringify(filtersToPreserve));
        }

        // Show saving indicator
        const originalValue = input.value;
        input.value = 'Saving...';
        input.disabled = true;

        fetch('standing_orders.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=save_order&customer_id=${customerId}&product_id=${productId}&day_of_week=${dayOfWeek}&quantity=${quantity}`
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                throw new Error(data.error || 'Failed to save order');
            }
            // Restore input value and enable it
            input.value = originalValue;
            input.disabled = false;
            
            // Show success feedback
            const feedback = document.createElement('span');
            feedback.className = 'save-feedback';
            feedback.textContent = '✓';
            feedback.style.color = 'green';
            feedback.style.marginLeft = '5px';
            feedback.style.transition = 'opacity 1.5s';
            
            // Remove any existing feedback
            const existingFeedback = input.parentNode.querySelector('.save-feedback');
            if (existingFeedback) {
                existingFeedback.remove();
            }
            
            input.parentNode.appendChild(feedback);
            
            // Fade out and remove feedback after delay
            setTimeout(() => {
                feedback.style.opacity = '0';
                setTimeout(() => feedback.remove(), 1500);
            }, 500);
        })
        .catch(error => {
            console.error('Error:', error);
            input.value = originalValue;
            input.disabled = false;
            
            // Show error feedback
            const feedback = document.createElement('span');
            feedback.className = 'save-feedback';
            feedback.textContent = '✗';
            feedback.style.color = 'red';
            feedback.style.marginLeft = '5px';
            
            // Remove any existing feedback
            const existingFeedback = input.parentNode.querySelector('.save-feedback');
            if (existingFeedback) {
                existingFeedback.remove();
            }
            
            input.parentNode.appendChild(feedback);
            
            // Auto-remove feedback after delay
            setTimeout(() => {
                feedback.style.opacity = '0';
                setTimeout(() => feedback.remove(), 1500);
            }, 2000);
        });
    });

    // Add event listeners to all quantity inputs
    document.querySelectorAll('.product-quantity').forEach(input => {
        // Save on change
        input.addEventListener('change', function() {
            // Ensure value is not negative
            if (this.value < 0) this.value = 0;
            saveOrder(this);
        });
        
        // Also save on blur in case user tabs out
        input.addEventListener('blur', function() {
            if (this.value === '') this.value = 0;
            saveOrder(this);
        });
        
        // Allow keyboard navigation with arrow keys
        input.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                e.preventDefault();
                this.value = parseInt(this.value || 0) + (e.key === 'ArrowUp' ? 1 : -1);
                if (this.value < 0) this.value = 0;
                saveOrder(this);
            }
        });
    });
});
</script>
