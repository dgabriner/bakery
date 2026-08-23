<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes ($db + auth gate come from database.php)
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/product_inventory.php';
require_once 'includes/demand_review.php';

// Set page title
$page_title = bakery_t('page.product_distribution');

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
                $selectedDay = (isset($_POST['day']) && $_POST['day'] !== '') ? (int)$_POST['day'] : null;
                $deliveryDate = trim((string)($_POST['date'] ?? ''));
                if ($deliveryDate !== '') {
                    try {
                        $deliveryDate = bakery_inventory_validate_date($deliveryDate);
                    } catch (Throwable $e) {
                        $deliveryDate = '';
                    }
                }

                if ($deliveryDate !== '' && $productId > 0) {
                    $weekday = bakery_standing_day_from_date($deliveryDate);
                    if ($selectedDay === null || $selectedDay === $weekday) {
                        echo json_encode([
                            'success' => true,
                            'effective' => true,
                            'customers_with_orders' => bakery_operating_demand_customers_for_product($db, $deliveryDate, $productId),
                            'customers_with_routes' => [],
                            'remaining_customers' => [],
                            'mix' => bakery_operating_demand_by_product($db, $deliveryDate, ['product_id' => $productId])['mix'] ?? [],
                        ]);
                        break;
                    }
                }

                $dayClause = $selectedDay !== null ? bakery_standing_day_in_clause($selectedDay) : null;
                $dayFilter = $dayClause ? ('AND so.day_of_week ' . $dayClause['sql']) : '';
                $dayValues = $dayClause['values'] ?? [];

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
                    LEFT JOIN standing_routes sr
                        ON c.id = sr.customer_id
                       AND CASE WHEN sr.day_of_week = 0 THEN 7 ELSE sr.day_of_week END
                           = CASE WHEN so.day_of_week = 0 THEN 7 ELSE so.day_of_week END
                    LEFT JOIN drivers d ON sr.driver_id = d.id
                    WHERE so.product_id = ? $dayFilter
                    " . bakery_sfb_ops_origin_clause('c', $db) . "
                    ORDER BY c.zone, c.name, so.day_of_week
                ");
                $stmt->execute(array_merge([$productId], $dayValues));
                $customersWithOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $dayFilter2 = $dayClause
                    ? ('AND CASE WHEN sr.day_of_week = 0 THEN 7 ELSE sr.day_of_week END ' . $dayClause['sql'])
                    : '';
                $dayFilterSubquery = $dayClause ? ('AND day_of_week ' . $dayClause['sql']) : '';

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
                    " . bakery_sfb_ops_origin_clause('c', $db) . "
                    ORDER BY c.zone, c.name, sr.day_of_week
                ");
                $stmt2->execute(array_merge([$productId], $dayValues, $dayValues));
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
                    " . bakery_sfb_ops_origin_clause('c', $db) . "
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

// Build a dated production-needs view from the same sources used by the
// production and finished-goods inventory pages.
$productionDate = (string)($_GET['production_date'] ?? date('Y-m-d', strtotime('+1 day')));
try {
    $productionDate = bakery_inventory_validate_date($productionDate);
} catch (Throwable $e) {
    $productionDate = date('Y-m-d', strtotime('+1 day'));
}
$productionWeekday = bakery_standing_day_from_date($productionDate);
$inventoryReady = bakery_inventory_ready($db);
$productionError = '';
$demandMix = [
    'mode' => 'none',
    'has_daily' => false,
    'daily_customers' => 0,
    'standing_customers' => 0,
];
$productionGroups = [];
$productionTotals = [
    'required' => 0,
    'stock' => 0,
    'produced' => 0,
    'to_produce' => 0,
    'dough_grams' => 0,
    'missing_weight_products' => 0,
];

try {
    // Effective demand is the per-customer merge of dated orders and standing
    // forecast — the same source Daily Production uses. No all-or-nothing flip.
    $demand = bakery_operating_demand_by_product($db, $productionDate);
    $demandMix = $demand['mix'] ?? $demandMix;
    $demandByProduct = $demand['by_product'];

    $inventorySelect = $inventoryReady
        ? 'COALESCE(inv.available_quantity, 0) AS available_quantity,
           COALESCE(inv.loaded_quantity, 0) AS loaded_quantity,
           COALESCE(inv.produced_quantity, 0) AS produced_quantity'
        : '0 AS available_quantity, 0 AS loaded_quantity, 0 AS produced_quantity';
    $inventoryJoin = $inventoryReady
        ? 'LEFT JOIN product_inventory_days inv
               ON inv.product_id = p.id AND inv.delivery_date = ?'
        : '';

    $needsStmt = $db->prepare("
        SELECT p.id, p.name, p.weight_grams,
               dt.id AS dough_type_id, dt.name AS dough_type,
               pl.id AS product_line_id, pl.name AS product_line,
               pl.color_code, pl.sort_order,
               {$inventorySelect}
        FROM products p
        LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
        LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
        {$inventoryJoin}
        ORDER BY COALESCE(pl.sort_order, 999), pl.name, dt.name, p.name
    ");
    $needsStmt->execute($inventoryReady ? [$productionDate] : []);

    foreach ($needsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $required = (int)($demandByProduct[(int)$row['id']] ?? 0);
        $stock = (int)$row['available_quantity'] + (int)$row['loaded_quantity'];
        $toProduce = max(0, $required - $stock);
        $weightGrams = max(0, (int)($row['weight_grams'] ?? 0));
        $doughGrams = $toProduce * $weightGrams;

        // Keep the planning view focused on products that affect this delivery day.
        if ($required === 0 && $stock === 0 && (int)$row['produced_quantity'] === 0) {
            continue;
        }

        $className = trim((string)($row['product_line'] ?? '')) ?: 'Unclassified';
        $doughName = trim((string)($row['dough_type'] ?? '')) ?: 'Unclassified';
        $classKey = (string)($row['product_line_id'] ?? 'none') . ':' . $className;
        $doughKey = (string)($row['dough_type_id'] ?? 'none') . ':' . $doughName;
        if (!isset($productionGroups[$classKey])) {
            $productionGroups[$classKey] = [
                'name' => $className,
                'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)$row['color_code']) ? $row['color_code'] : '#39744d',
                'totals' => ['required' => 0, 'stock' => 0, 'produced' => 0, 'to_produce' => 0, 'dough_grams' => 0],
                'dough_types' => [],
            ];
        }
        if (!isset($productionGroups[$classKey]['dough_types'][$doughKey])) {
            $productionGroups[$classKey]['dough_types'][$doughKey] = [
                'name' => $doughName,
                'totals' => ['required' => 0, 'stock' => 0, 'produced' => 0, 'to_produce' => 0, 'dough_grams' => 0],
                'products' => [],
            ];
        }

        $metrics = [
            'required' => $required,
            'stock' => $stock,
            'produced' => (int)$row['produced_quantity'],
            'to_produce' => $toProduce,
            'dough_grams' => $doughGrams,
        ];
        $row['metrics'] = $metrics;
        $row['weight_missing'] = $toProduce > 0 && $weightGrams === 0;
        $productionGroups[$classKey]['dough_types'][$doughKey]['products'][] = $row;
        foreach ($metrics as $metric => $value) {
            $productionGroups[$classKey]['totals'][$metric] += $value;
            $productionGroups[$classKey]['dough_types'][$doughKey]['totals'][$metric] += $value;
            $productionTotals[$metric] += $value;
        }
        if ($row['weight_missing']) {
            $productionTotals['missing_weight_products']++;
        }
    }
} catch (Throwable $e) {
    $productionError = 'Unable to calculate production needs: ' . $e->getMessage();
}

// Include header
require_once 'includes/header.php';

// Include navigation
require_once 'includes/nav.php';

$dayNames = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
?>

<div class="product-distribution-container"
     data-label-effective="<?php echo htmlspecialchars(bakery_t('distribution.explorer_effective'), ENT_QUOTES, 'UTF-8'); ?>"
     data-label-standing="<?php echo htmlspecialchars(bakery_t('distribution.explorer_standing'), ENT_QUOTES, 'UTF-8'); ?>"
     data-badge-daily="<?php echo htmlspecialchars(bakery_t('distribution.source_badge_daily'), ENT_QUOTES, 'UTF-8'); ?>"
     data-badge-standing="<?php echo htmlspecialchars(bakery_t('distribution.source_badge_standing'), ENT_QUOTES, 'UTF-8'); ?>"
     data-badge-standard="<?php echo htmlspecialchars(bakery_t('distribution.source_badge_standard'), ENT_QUOTES, 'UTF-8'); ?>">
    <h1><?php echo htmlspecialchars(bakery_t('page.product_distribution'), ENT_QUOTES, 'UTF-8'); ?></h1>

    <section class="production-needs" aria-labelledby="production-needs-heading">
        <div class="production-needs-heading">
            <div>
                <p class="production-eyebrow"><?php echo htmlspecialchars(bakery_t('distribution.planning_eyebrow'), ENT_QUOTES, 'UTF-8'); ?></p>
                <h2 id="production-needs-heading"><?php echo htmlspecialchars(bakery_t('distribution.needs_heading'), ENT_QUOTES, 'UTF-8'); ?></h2>
                <p><?php echo htmlspecialchars(bakery_t('distribution.needs_lead'), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="production-links">
                <a class="btn btn-outline" href="inventory.php?date=<?php echo urlencode($productionDate); ?>">Open inventory</a>
                <a class="btn btn-primary" href="production.php?date=<?php echo urlencode($productionDate); ?>">Open production</a>
            </div>
        </div>

        <form method="get" class="production-date-filter" data-production-date="<?php echo htmlspecialchars($productionDate, ENT_QUOTES, 'UTF-8'); ?>">
            <label><?php echo htmlspecialchars(bakery_t('distribution.delivery_day'), ENT_QUOTES, 'UTF-8'); ?>
                <input type="date" name="production_date" value="<?php echo htmlspecialchars($productionDate, ENT_QUOTES, 'UTF-8'); ?>">
            </label>
            <button class="btn btn-outline" type="submit"><?php echo htmlspecialchars(bakery_t('distribution.view_needs'), ENT_QUOTES, 'UTF-8'); ?></button>
            <?php
            $mixMode = (string)($demandMix['mode'] ?? 'none');
            $mixClass = $mixMode === 'merged' || $mixMode === 'dated' ? 'actual' : 'standing';
            $mixKey = 'distribution.source_' . $mixMode;
            $mixLabel = bakery_t($mixKey);
            if ($mixLabel === $mixKey) {
                $mixLabel = bakery_t('distribution.source_standing');
            }
            ?>
            <span class="production-source <?php echo $mixClass; ?>">
                <?php echo htmlspecialchars($mixLabel, ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </form>

        <?php if ($productionError): ?>
            <div class="production-notice error"><?php echo htmlspecialchars($productionError); ?></div>
        <?php endif; ?>
        <?php if (!$inventoryReady): ?>
            <div class="production-notice warning">Finished-goods inventory is not installed, so inventory coverage is shown as zero until migration 009 is applied.</div>
        <?php endif; ?>
        <?php if ($productionTotals['missing_weight_products'] > 0): ?>
            <div class="production-notice warning">
                <?php echo number_format($productionTotals['missing_weight_products']); ?> product<?php echo $productionTotals['missing_weight_products'] === 1 ? '' : 's'; ?> needing production lack a saved weight. Unit needs are correct, but their dough weight is excluded.
            </div>
        <?php endif; ?>

        <div class="production-summary" aria-label="Production totals">
            <div><span>Required</span><strong><?php echo number_format($productionTotals['required']); ?></strong><small>finished units</small></div>
            <div><span>Covered by stock</span><strong><?php echo number_format($productionTotals['required'] - $productionTotals['to_produce']); ?></strong><small><?php echo number_format($productionTotals['stock']); ?> units on hand</small></div>
            <div class="<?php echo $productionTotals['to_produce'] > 0 ? 'needs-attention' : 'is-covered'; ?>"><span>Still to produce</span><strong><?php echo number_format($productionTotals['to_produce']); ?></strong><small>finished units</small></div>
            <div><span>Dough needed</span><strong><?php echo number_format($productionTotals['dough_grams'] / 1000, 1); ?> kg</strong><small>for uncovered units</small></div>
        </div>

        <?php if ($productionGroups): ?>
            <div class="production-classes">
                <?php foreach ($productionGroups as $class): ?>
                    <article class="production-class" style="--class-color: <?php echo htmlspecialchars($class['color'], ENT_QUOTES, 'UTF-8'); ?>;">
                        <header class="production-class-header">
                            <div><span>Product class</span><h3><?php echo htmlspecialchars($class['name']); ?></h3></div>
                            <div class="class-metrics">
                                <span><strong><?php echo number_format($class['totals']['required']); ?></strong> required</span>
                                <span><strong><?php echo number_format($class['totals']['stock']); ?></strong> in stock</span>
                                <span class="<?php echo $class['totals']['to_produce'] > 0 ? 'needs-attention' : 'is-covered'; ?>"><strong><?php echo number_format($class['totals']['to_produce']); ?></strong> to produce</span>
                                <span><strong><?php echo number_format($class['totals']['dough_grams'] / 1000, 1); ?> kg</strong> dough</span>
                            </div>
                        </header>
                        <div class="production-table-wrap">
                            <table class="production-table">
                                <thead><tr><th>Class / Dough Type / Product</th><th>Required</th><th>Produced</th><th>Inventory</th><th>Still to Produce</th><th>Dough Needed</th></tr></thead>
                                <tbody>
                                <?php foreach ($class['dough_types'] as $dough): ?>
                                    <tr class="dough-total-row">
                                        <td><strong><?php echo htmlspecialchars($dough['name']); ?></strong><small>Dough type · <?php echo count($dough['products']); ?> product<?php echo count($dough['products']) === 1 ? '' : 's'; ?></small></td>
                                        <td><?php echo number_format($dough['totals']['required']); ?></td>
                                        <td><?php echo number_format($dough['totals']['produced']); ?></td>
                                        <td><?php echo number_format($dough['totals']['stock']); ?></td>
                                        <td class="<?php echo $dough['totals']['to_produce'] > 0 ? 'needs-attention' : 'is-covered'; ?>"><?php echo number_format($dough['totals']['to_produce']); ?></td>
                                        <td><strong><?php echo number_format($dough['totals']['dough_grams'] / 1000, 1); ?> kg</strong></td>
                                    </tr>
                                    <?php foreach ($dough['products'] as $product): $metrics = $product['metrics']; ?>
                                        <tr class="product-need-row">
                                            <td><span><?php echo htmlspecialchars($product['name']); ?></span><small><?php echo (int)($product['weight_grams'] ?? 0) > 0 ? number_format((int)$product['weight_grams']) . ' g each' : 'Weight not set'; ?></small></td>
                                            <td><?php echo number_format($metrics['required']); ?></td>
                                            <td><?php echo number_format($metrics['produced']); ?></td>
                                            <td><?php echo number_format($metrics['stock']); ?><small><?php echo number_format((int)$product['available_quantity']); ?> available + <?php echo number_format((int)$product['loaded_quantity']); ?> loaded</small></td>
                                            <td class="<?php echo $metrics['to_produce'] > 0 ? 'needs-attention' : 'is-covered'; ?>"><?php echo $metrics['to_produce'] > 0 ? number_format($metrics['to_produce']) : 'Covered'; ?></td>
                                            <td><?php echo $product['weight_missing'] ? '—' : number_format($metrics['dough_grams'] / 1000, 2) . ' kg'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php elseif (!$productionError): ?>
            <p class="production-empty">No standing orders, Daily Orders, or inventory activity affects this delivery day.</p>
        <?php endif; ?>
    </section>
    
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
                            <option value="<?php echo $dayNum; ?>"<?php echo (int)$productionWeekday === (int)$dayNum ? ' selected' : ''; ?>><?php echo $dayName; ?></option>
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
                    <h3 class="section-title" id="orders-section-title"><?php echo htmlspecialchars(bakery_t('distribution.explorer_standing'), ENT_QUOTES, 'UTF-8'); ?></h3>
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
        const dateForm = document.querySelector('.production-date-filter');
        const productionDate = dateForm ? dateForm.getAttribute('data-production-date') : '';
        const params = new URLSearchParams();
        params.set('action', 'get_product_customers');
        params.set('product_id', productId);
        params.set('day', selectedDay);
        if (productionDate) {
            params.set('date', productionDate);
        }

        fetch('product_distribution.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: params.toString()
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
        const root = document.querySelector('.product-distribution-container');
        const dayFilter = document.getElementById('day-filter').value;
        const dayText = dayFilter ? ` (${getDayName(dayFilter)})` : ' (All Days)';
        document.getElementById('selected-product-name').textContent = selectedProductName + dayText;

        const effective = !!data.effective;
        const ordersTitle = document.getElementById('orders-section-title');
        if (ordersTitle && root) {
            ordersTitle.textContent = effective
                ? (root.getAttribute('data-label-effective') || 'Effective demand this delivery day')
                : (root.getAttribute('data-label-standing') || 'Customers with standing orders');
        }
        document.querySelectorAll('.customer-section').forEach(function (section, index) {
            if (index === 0) {
                section.style.display = '';
                return;
            }
            section.style.display = effective ? 'none' : '';
        });

        displayCustomerSection('customers-with-orders', data.customers_with_orders || [], true, effective);
        if (!effective) {
            displayCustomerSection('customers-with-routes', data.customers_with_routes || [], false, false);
            displayRemainingCustomers('remaining-customers', data.remaining_customers || []);
        }

        document.getElementById('customer-results').style.display = 'block';
    }
    
    function sourceBadge(source) {
        const root = document.querySelector('.product-distribution-container');
        const key = source === 'daily' ? 'data-badge-daily'
            : (source === 'pan_dulce_standard' ? 'data-badge-standard' : 'data-badge-standing');
        const label = root ? (root.getAttribute(key) || source) : source;
        return `<span class="source-badge source-${source || 'standing'}">${label}</span>`;
    }

    function displayCustomerSection(containerId, customers, hasOrders, effective) {
        const container = document.getElementById(containerId);
        container.innerHTML = '';
        effective = !!effective;
        
        if (!customers || customers.length === 0) {
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
                        ${customer.source ? sourceBadge(customer.source) : ''}
                        ${customer.quantity !== undefined && customer.quantity !== null ? `<span class="quantity-badge">${customer.quantity}</span>` : ''}
                    </div>` : 
                    `<div class="customer-days">
                        ${customer.day_of_week ? `<span class="day-badge">${getDayName(customer.day_of_week)}</span>` : ''}
                    </div>`;
                
                const driverInfo = customer.driver_name ? 
                    `<div class="driver-info">Driver: ${customer.driver_name}</div>` : '';
                
                const quantityInput = hasOrders && !effective ? 
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
        if (hasOrders && !effective) {
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

.production-needs {
    margin: 18px 0 28px;
    padding: 22px;
    background: #f7faf7;
    border: 1px solid #d9e6dc;
    border-radius: 12px;
}

.production-needs-heading,
.production-class-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 18px;
}

.production-needs-heading h2,
.production-class-header h3 {
    margin: 0;
    color: #1d432c;
}

.production-needs-heading p:not(.production-eyebrow) {
    margin: 6px 0 0;
    color: #5c6e62;
    max-width: 760px;
}

.production-eyebrow {
    margin: 0 0 4px;
    color: #2f7a4b;
    font-size: .76rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.production-links,
.production-date-filter,
.class-metrics {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.production-links .btn {
    white-space: nowrap;
}

.production-date-filter {
    margin: 18px 0;
}

.production-date-filter label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    color: #405248;
}

.production-date-filter input {
    padding: 8px;
    border: 1px solid #c5d2c9;
    border-radius: 5px;
    background: #fff;
}

.production-source {
    padding: 5px 10px;
    border-radius: 999px;
    font-size: .78rem;
    font-weight: 700;
}

.production-source.actual {
    color: #075d83;
    background: #deeff8;
}

.production-source.standing {
    color: #745410;
    background: #fff0c9;
}

.production-notice {
    margin: 12px 0;
    padding: 11px 14px;
    border-radius: 6px;
}

.production-notice.error {
    color: #982727;
    background: #fde9e9;
}

.production-notice.warning {
    color: #76540f;
    background: #fff3d7;
}

.production-summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(150px, 1fr));
    gap: 12px;
    margin: 16px 0 20px;
}

.production-summary > div {
    padding: 14px;
    background: #fff;
    border: 1px solid #dce7de;
    border-radius: 8px;
}

.production-summary span,
.production-summary small {
    display: block;
    color: #66776c;
    font-size: .78rem;
}

.production-summary strong {
    display: block;
    margin: 3px 0;
    color: #1d432c;
    font-size: 1.55rem;
}

.production-classes {
    display: grid;
    gap: 16px;
}

.production-class {
    overflow: hidden;
    background: #fff;
    border: 1px solid #dce6df;
    border-top: 4px solid var(--class-color);
    border-radius: 9px;
}

.production-class-header {
    align-items: center;
    padding: 14px 16px;
    background: #fbfcfb;
    border-bottom: 1px solid #e4ebe6;
}

.production-class-header > div:first-child span {
    display: block;
    color: #738178;
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .05em;
}

.class-metrics span {
    color: #5a695f;
    font-size: .82rem;
}

.class-metrics strong {
    color: #243e2e;
}

.production-table-wrap {
    overflow-x: auto;
}

.production-table {
    width: 100%;
    min-width: 780px;
    border-collapse: collapse;
}

.production-table th,
.production-table td {
    padding: 11px 13px;
    border-bottom: 1px solid #e9eeea;
    text-align: right;
    vertical-align: middle;
}

.production-table th {
    color: #617168;
    background: #f8faf8;
    font-size: .75rem;
    letter-spacing: .03em;
    text-transform: uppercase;
}

.production-table th:first-child,
.production-table td:first-child {
    text-align: left;
}

.production-table td small {
    display: block;
    margin-top: 2px;
    color: #7a887f;
    font-size: .72rem;
}

.dough-total-row td {
    background: #edf5ef;
    color: #294b35;
}

.product-need-row td:first-child {
    padding-left: 32px;
}

.product-need-row:last-child td {
    border-bottom: 0;
}

.needs-attention {
    color: #b32d2d !important;
    font-weight: 700;
}

.is-covered {
    color: #21703f !important;
    font-weight: 700;
}

.production-empty {
    margin: 12px 0 0;
    padding: 18px;
    color: #637168;
    background: #fff;
    border: 1px dashed #cdd8d0;
    border-radius: 7px;
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

.source-badge {
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 0.8em;
    font-weight: 500;
}

.source-badge.source-daily {
    background: #075d83;
    color: #fff;
}

.source-badge.source-standing {
    background: #745410;
    color: #fff;
}

.source-badge.source-pan_dulce_standard {
    background: #5c6e62;
    color: #fff;
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
    .production-needs {
        padding: 15px;
    }

    .production-needs-heading,
    .production-class-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .production-links {
        width: 100%;
    }

    .production-links .btn {
        flex: 1;
        text-align: center;
    }

    .production-summary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

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

@media (max-width: 480px) {
    .production-summary {
        grid-template-columns: 1fr;
    }

    .production-date-filter {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>

<?php
require_once 'includes/footer.php';
?>
