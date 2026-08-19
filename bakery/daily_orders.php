<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/customer_portal.php';
require_once 'includes/customer_order_mutations.php';
require_once 'includes/demand_review.php';
require_once 'includes/daily_order_generation.php';
require_once 'includes/operational_exceptions.php';
require_once 'includes/operational_timeline.php';
require_once 'includes/pan_dulce_standards.php';
bakery_ensure_portal_schema($db);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'preview_generate':
                $date = $_POST['date'] ?? '';
                $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
                if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
                    throw new Exception('Invalid order date');
                }
                echo json_encode([
                    'success' => true,
                    'preview' => bakery_demand_review_preview_generate($db, $date),
                ]);
                break;

            case 'generate_from_standing':
                $date = $_POST['date'] ?? '';
                // Default preserves dated quantity changes. Explicit opt-in overwrites them.
                $overwriteChanged = ($_POST['overwrite_changed'] ?? '0') === '1';
                $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
                if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
                    throw new Exception('Invalid order date');
                }
                try {
                    $result = bakery_generate_daily_orders_from_standing($db, $date, [
                        'overwrite_changed' => $overwriteChanged,
                    ]);
                    echo json_encode(array_merge(['success' => true], $result));
                } catch (Exception $e) {
                    error_log('Error generating orders: ' . $e->getMessage());
                    echo json_encode([
                        'success' => false,
                        'error' => 'Failed to generate orders: ' . $e->getMessage(),
                    ]);
                }
                break;

            case 'generate_week_from_standing':
                $date = $_POST['date'] ?? '';
                $overwriteChanged = ($_POST['overwrite_changed'] ?? '0') === '1';
                $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
                if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
                    throw new Exception('Invalid order date');
                }
                try {
                    $result = bakery_generate_daily_orders_week($db, $date, [
                        'overwrite_changed' => $overwriteChanged,
                    ]);
                    echo json_encode(array_merge(['success' => true], $result));
                } catch (Exception $e) {
                    error_log('Error generating week orders: ' . $e->getMessage());
                    echo json_encode([
                        'success' => false,
                        'error' => 'Failed to generate week: ' . $e->getMessage(),
                    ]);
                }
                break;

            case 'create_dated_order':
                $customerId = (int)($_POST['customer_id'] ?? 0);
                $date = (string)($_POST['date'] ?? '');
                $result = bakery_staff_create_dated_order($db, $customerId, $date);
                echo json_encode([
                    'success' => true,
                    'daily_order_id' => $result['daily_order_id'],
                    'created' => $result['created'],
                    'item_count' => $result['item_count'],
                    'message' => $result['created']
                        ? 'Dated order created for ' . $result['customer_name']
                        : 'That customer already has a dated order. Opening it now.',
                ]);
                break;

            case 'update_quantity':
                $itemId = (int)$_POST['item_id'];
                $quantity = (int)$_POST['quantity'];

                $stmt = $db->prepare(
                    'SELECT doi.daily_order_id, doi.quantity, doi.product_id, p.name AS product_name,
                            do.order_date, do.customer_id, c.name AS customer_name
                     FROM daily_order_items doi
                     JOIN daily_orders do ON do.id = doi.daily_order_id
                     JOIN customers c ON c.id = do.customer_id
                     JOIN products p ON p.id = doi.product_id
                     WHERE doi.id = ?'
                );
                $stmt->execute([$itemId]);
                $itemRow = $stmt->fetch(PDO::FETCH_ASSOC);

                // Capture parent before a possible delete so totals still recalculate.
                $dailyOrderId = $itemRow ? (int)$itemRow['daily_order_id'] : null;
                $oldQty = $itemRow ? (int)$itemRow['quantity'] : null;
                
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
                
                if ($dailyOrderId) {
                    updateOrderTotal($db, $dailyOrderId);
                }

                if ($itemRow && $oldQty !== null && $oldQty !== $quantity) {
                    $label = $quantity <= 0 ? 'removed' : 'changed';
                    bakery_record_operational_event($db, BAKERY_OP_DAILY_ORDER_QUANTITY_CHANGED,
                        'Order quantity ' . $label . ' for ' . $itemRow['customer_name'] . ' — ' . $itemRow['product_name'], [
                        'operational_date' => $itemRow['order_date'],
                        'customer_id' => (int)$itemRow['customer_id'],
                        'daily_order_id' => (int)$itemRow['daily_order_id'],
                        'product_id' => (int)$itemRow['product_id'],
                        'metadata' => [
                            'product_name' => $itemRow['product_name'],
                            'old_quantity' => $oldQty,
                            'new_quantity' => max(0, $quantity),
                        ],
                    ]);
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

                $prevStmt = $db->prepare(
                    'SELECT do.status, do.order_date, do.customer_id, c.name AS customer_name
                     FROM daily_orders do JOIN customers c ON c.id = do.customer_id WHERE do.id = ?'
                );
                $prevStmt->execute([$orderId]);
                $prev = $prevStmt->fetch(PDO::FETCH_ASSOC);
                
                $stmt = $db->prepare("UPDATE daily_orders SET status = ? WHERE id = ?");
                $stmt->execute([$status, $orderId]);

                if ($prev && (string)$prev['status'] !== $status) {
                    $eventType = $status === 'invoiced'
                        ? BAKERY_OP_INVOICE_GENERATED
                        : BAKERY_OP_DAILY_ORDER_STATUS_CHANGED;
                    bakery_record_operational_event($db, $eventType,
                        'Order status set to ' . str_replace('_', ' ', $status) . ' for ' . $prev['customer_name'], [
                        'operational_date' => $prev['order_date'],
                        'customer_id' => (int)$prev['customer_id'],
                        'daily_order_id' => $orderId,
                        'metadata' => [
                            'old_status' => $prev['status'],
                            'new_status' => $status,
                        ],
                    ]);
                }
                
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

                $orderMeta = $db->prepare(
                    'SELECT do.order_date, do.customer_id, c.name AS customer_name, p.name AS product_name
                     FROM daily_orders do
                     JOIN customers c ON c.id = do.customer_id
                     JOIN products p ON p.id = ?
                     WHERE do.id = ?'
                );
                $orderMeta->execute([$productId, $orderId]);
                $meta = $orderMeta->fetch(PDO::FETCH_ASSOC);
                if ($meta) {
                    bakery_record_operational_event($db, BAKERY_OP_DAILY_ORDER_ITEM_ADDED,
                        'Added ' . $quantity . ' × ' . $meta['product_name'] . ' to ' . $meta['customer_name'], [
                        'operational_date' => $meta['order_date'],
                        'customer_id' => (int)$meta['customer_id'],
                        'daily_order_id' => $orderId,
                        'product_id' => $productId,
                        'metadata' => ['quantity' => $quantity, 'product_name' => $meta['product_name']],
                    ]);
                }
                
                echo json_encode(['success' => true]);
                break;

            case 'apply_pan_dulce_standard_to_order':
                $orderId = (int)$_POST['order_id'];
                $multiplier = isset($_POST['multiplier']) ? (float)$_POST['multiplier'] : 1.0;

                $result = bakery_apply_pan_dulce_daily_standard($db, $orderId, $multiplier);
                updateOrderTotal($db, $orderId);

                bakery_record_operational_event($db, BAKERY_OP_DAILY_ORDER_ITEM_ADDED,
                    'Applied Pan Dulce standard (' . $multiplier . '×) to ' . $result['customer_name'], [
                    'operational_date' => $result['order_date'],
                    'customer_id' => $result['customer_id'],
                    'daily_order_id' => $orderId,
                    'metadata' => [
                        'multiplier' => $multiplier,
                        'products' => $result['products'],
                        'updated' => $result['updated'],
                    ],
                ]);

                echo json_encode(['success' => true] + $result);
                break;

            case 'remove_order':
                $orderId = (int)($_POST['order_id'] ?? 0);
                $date = $_POST['date'] ?? '';
                $confirmed = ($_POST['confirm_delivered'] ?? '0') === '1';
                $result = bakery_remove_empty_dated_order($db, $orderId, $date, $confirmed);
                if ($result['requires_confirmation']) {
                    echo json_encode([
                        'success' => false,
                        'requires_delivered_confirmation' => true,
                        'status' => $result['status'],
                    ]);
                    break;
                }
                echo json_encode(['success' => true, 'message' => $result['message']]);
                break;

            case 'clear_day':
                $date = $_POST['date'] ?? '';
                $confirmed = ($_POST['confirm_delivered'] ?? '0') === '1';
                $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
                if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
                    throw new Exception('Invalid order date');
                }

                $stmt = $db->prepare("SELECT COUNT(*) FROM daily_orders WHERE order_date = ? AND status IN ('delivered', 'invoiced')");
                $stmt->execute([$date]);
                $deliveredCount = (int)$stmt->fetchColumn();

                // Require an explicit second confirmation whenever delivered history is involved.
                if ($deliveredCount > 0 && !$confirmed) {
                    echo json_encode([
                        'success' => false,
                        'requires_delivered_confirmation' => true,
                        'delivered_count' => $deliveredCount,
                    ]);
                    break;
                }

                $db->beginTransaction();
                try {
                    // Photos are keyed by customer/date rather than daily_order_id.
                    if (table_exists($db, 'driver_photos')) {
                        $stmt = $db->prepare("DELETE FROM driver_photos WHERE delivery_date = ? AND customer_id IN (SELECT customer_id FROM daily_orders WHERE order_date = ?)");
                        $stmt->execute([$date, $date]);
                    }
                    // daily_order_items and daily_order_assignments have ON DELETE CASCADE.
                    $stmt = $db->prepare('DELETE FROM daily_orders WHERE order_date = ?');
                    $stmt->execute([$date]);
                    $deletedCount = $stmt->rowCount();
                    $db->commit();

                    bakery_record_operational_event($db, BAKERY_OP_DAILY_ORDER_CLEARED,
                        'Cleared all daily orders for ' . date('l, F j, Y', strtotime($date)), [
                        'operational_date' => $date,
                        'metadata' => [
                            'deleted_count' => $deletedCount,
                            'had_delivered' => $deliveredCount > 0,
                            'confirmed_delivered' => $confirmed,
                        ],
                    ]);

                    echo json_encode(['success' => true, 'deleted_count' => $deletedCount]);
                } catch (Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    throw $e;
                }
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
$selectedDriverId = isset($_GET['driver_id']) ? (int)$_GET['driver_id'] : 0;
$selectedZone = isset($_GET['zone']) ? trim((string)$_GET['zone']) : '';
$groupBy = ($_GET['group_by'] ?? 'driver') === 'zone' ? 'zone' : 'driver';
$reviewFilter = trim((string)($_GET['review'] ?? 'differences'));
$allowedReviewFilters = ['all', 'differences', 'missing', 'changed', 'one_off', 'matches', 'paused', 'empty'];
if (!in_array($reviewFilter, $allowedReviewFilters, true)) {
    $reviewFilter = 'differences';
}
$customerSearch = trim((string)($_GET['customer'] ?? ''));
$selectedProductId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$viewMode = ($_GET['view'] ?? 'review') === 'edit' ? 'edit' : 'review';
$returnTarget = bakery_ops_return_resolve($_GET['return'] ?? null, $selectedDate);
$pageReturnKey = $returnTarget['key'] ?? null;

// Never-manual demand: lazy-generate missing dated orders on first view.
// Always preserves standing→dated quantity edits.
$autoGeneratedDemand = null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $ensure = bakery_ensure_daily_orders_for_date($db, $selectedDate);
        if (!empty($ensure['ran']) && is_array($ensure['result'] ?? null)) {
            $autoGeneratedDemand = $ensure['result'];
        }
    } catch (Throwable $e) {
        error_log('daily_orders auto-generate: ' . $e->getMessage());
    }
}
$attentionLabels = [
    'missing' => 'Showing customers missing dated orders',
    'empty' => 'Showing dated orders with no line items',
    'differences' => 'Showing customers with demand differences',
];
$attentionLabel = $attentionLabels[$reviewFilter] ?? (
    in_array($reviewFilter, ['missing', 'empty', 'changed', 'one_off'], true) ? 'Showing items requiring attention' : ''
);

function daily_orders_filter_url($date, $driverId, $zone, $groupBy = 'driver', $extra = []) {
    $params = array_merge([
        'date' => $date,
        'group_by' => $groupBy,
    ], $extra);
    if ($driverId > 0) {
        $params['driver_id'] = $driverId;
    }
    if ($zone !== '') {
        $params['zone'] = $zone;
    }
    return '?' . http_build_query($params);
}

// Set page title
$page_title = bakery_t('page.daily_orders') . ' - ' . date('M j, Y', strtotime($selectedDate));

// Get daily orders for selected date
try {
    $customerZoneJoin = bakery_customer_zone_join_sql();
    $orderSql = "
        SELECT do.*, c.name as customer_name, c.address, c.phone, c.zone, c.zone_id,
               COALESCE(z.name, NULLIF(c.zone, ''), 'No Zone') AS zone_label,
               COALESCE(
                   (SELECT d.name FROM daily_order_assignments doa JOIN drivers d ON d.id = doa.driver_id
                    WHERE doa.daily_order_id = do.id AND doa.delivery_date = do.order_date
                    ORDER BY doa.route_order, doa.id LIMIT 1),
                   (SELECT d.name FROM drivers d WHERE d.id = do.driver_id LIMIT 1)
               ) AS route_driver_name
        FROM daily_orders do
        JOIN customers c ON do.customer_id = c.id
        " . bakery_sfb_ops_origin_clause('c', $db) . "
        {$customerZoneJoin}
        WHERE do.order_date = ?";
    $orderParams = [$selectedDate];

    // Daily assignments are the canonical source for a driver's route. The
    // daily_orders.driver_id check keeps legacy, directly-assigned orders visible.
    if ($selectedDriverId > 0) {
        $orderSql .= "
            AND (
                do.driver_id = ?
                OR EXISTS (
                    SELECT 1
                    FROM daily_order_assignments doa
                    WHERE doa.daily_order_id = do.id
                      AND doa.delivery_date = do.order_date
                      AND doa.driver_id = ?
                )
            )";
        $orderParams[] = $selectedDriverId;
        $orderParams[] = $selectedDriverId;
    }

    if ($selectedZone !== '') {
        $orderSql .= " AND COALESCE(z.name, c.zone, '') = ?";
        $orderParams[] = $selectedZone;
    }

    $orderSql .= $groupBy === 'zone'
        ? " ORDER BY zone_label, c.name"
        : " ORDER BY COALESCE(route_driver_name, 'Unassigned route'), c.name";
    $stmt = $db->prepare($orderSql);
    $stmt->execute($orderParams);
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
    
    // Product-level Pan Dulce standards are introduced by migration 012.
    // Fall back to the older dough-type table (or 12) during an upgrade.
    $panDulceProductStandardsAvailable = table_exists($db, 'pan_dulce_product_quantity_standards');
    $panDulceTypeStandardsAvailable = table_exists($db, 'pan_dulce_quantity_standards');
    $standardQuantityJoin = $panDulceProductStandardsAvailable
        ? 'LEFT JOIN pan_dulce_product_quantity_standards pdqs ON pdqs.product_id = p.id'
        : ($panDulceTypeStandardsAvailable ? 'LEFT JOIN pan_dulce_quantity_standards pdqs ON pdqs.dough_type_id = dt.id' : '');
    $standardQuantitySelect = ($panDulceProductStandardsAvailable || $panDulceTypeStandardsAvailable)
        ? 'COALESCE(pdqs.standard_quantity, 12) AS pan_dulce_standard_quantity'
        : '12 AS pan_dulce_standard_quantity';
    $products = $db->query("
        SELECT p.id, p.name, p.price, dt.name AS dough_type_name, pl.name AS product_line_name,
               {$standardQuantitySelect}
        FROM products p
        LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
        LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
        {$standardQuantityJoin}
        ORDER BY p.name
    ")->fetchAll();
    
    // Get customers for creating new orders
    $customers = $db->query(
        "SELECT id, name FROM customers c WHERE c.is_active = 1 " . bakery_sfb_ops_origin_clause('c', $db) . " ORDER BY name"
    )->fetchAll();
    $drivers = $db->query("SELECT id, name FROM drivers ORDER BY name")->fetchAll();
    $zones = $db->query("SELECT DISTINCT zone FROM customers WHERE zone IS NOT NULL AND zone <> '' ORDER BY zone")->fetchAll(PDO::FETCH_COLUMN);
    if (table_exists($db, 'zones')) {
        $zoneNames = $db->query("SELECT name FROM zones WHERE name IS NOT NULL AND name <> '' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $zones = array_values(array_unique(array_merge($zones, $zoneNames)));
        sort($zones, SORT_NATURAL | SORT_FLAG_CASE);
    }

    $demandSummaryAll = bakery_demand_review_build($db, $selectedDate, [
        'driver_id' => $selectedDriverId,
        'zone' => $selectedZone,
        'customer' => $customerSearch,
        'product_id' => $selectedProductId,
        'review' => 'all',
    ]);
    $demandReviewCustomers = array_values(array_filter(
        $demandSummaryAll['customers'],
        function ($c) use ($reviewFilter) {
            return bakery_demand_review_matches_filter($c, $reviewFilter);
        }
    ));
    $reviewByOrderId = [];
    $reviewByCustomerId = [];
    foreach ($demandSummaryAll['customers'] as $reviewCustomer) {
        $reviewByCustomerId[(int)$reviewCustomer['customer_id']] = $reviewCustomer;
        if (!empty($reviewCustomer['daily_order_id'])) {
            $reviewByOrderId[(int)$reviewCustomer['daily_order_id']] = $reviewCustomer;
        }
    }
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error loading daily orders: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}

$pageExceptions = [];
try {
    $pageExceptions = bakery_ops_exceptions_for_date($db, $selectedDate, $pageReturnKey);
} catch (Throwable $e) {
    error_log('daily_orders exceptions: ' . $e->getMessage());
}

$filterExtra = [
    'view' => $viewMode,
    'review' => $reviewFilter,
];
if ($pageReturnKey) {
    $filterExtra['return'] = $pageReturnKey;
}
if ($customerSearch !== '') {
    $filterExtra['customer'] = $customerSearch;
}
if ($selectedProductId > 0) {
    $filterExtra['product_id'] = $selectedProductId;
}
$summary = $demandSummaryAll['summary'];
?>

<div class="container">
    <?php echo bakery_ops_render_return_banner($returnTarget, $attentionLabel); ?>
    <?php if ($autoGeneratedDemand !== null): ?>
        <div class="alert alert-success" role="status">
            <?= htmlspecialchars(bakery_t('daily_orders.demand_auto_generated', [
                'orders' => (int)($autoGeneratedDemand['orders_created'] ?? 0),
                'items' => (int)($autoGeneratedDemand['items_created'] ?? 0)
                    + (int)($autoGeneratedDemand['items_updated'] ?? 0),
            ])) ?>
        </div>
    <?php endif; ?>
    <div class="page-header">
        <h1>Daily Orders</h1>
        <div class="button-group">
            <button type="button" class="btn btn-success" onclick="showCreateDatedOrderModal()"<?= $selectedDate < date('Y-m-d') ? ' disabled' : '' ?>>
                <?= htmlspecialchars(bakery_t('daily_orders.create_dated_order')) ?>
            </button>
            <button type="button" class="btn btn-primary" onclick="showGenerateModal()">
                Generate from Standing Orders
            </button>
            <button type="button" class="btn btn-secondary" onclick="generateWeekFromStanding()">
                Generate This Week
            </button>
            <button type="button" class="btn btn-secondary" onclick="showDatePicker()">
                Change Date
            </button>
            <button type="button" class="btn btn-danger" onclick="clearSelectedDay()">
                Clear This Day
            </button>
            <a class="btn btn-outline" href="standing_orders_manager.php">Edit Standing Forecast</a>
            <a class="btn btn-outline" href="pan_dulce_quantities.php">Pan Dulce Standards</a>
            <a class="btn btn-outline" href="production.php?date=<?= urlencode($selectedDate) ?>">Daily Production</a>
            <a class="btn btn-outline" href="driver_assignment.php?date=<?= urlencode($selectedDate) ?>">Driver Assignment</a>
        </div>
    </div>

    <div class="source-legend">
        <div>
            <strong>Standing forecast</strong>
            <span>Recurring template for <?= htmlspecialchars($dayName) ?>s (not date-specific).</span>
        </div>
        <div>
            <strong>Dated daily order</strong>
            <span>What this page edits — applies only to <?= htmlspecialchars(date('D, M j, Y', strtotime($selectedDate))) ?>.</span>
        </div>
    </div>
    
    <!-- Date Navigation -->
    <div class="date-navigation">
        <div class="date-info">
            <h2><?= date('l, F j, Y', strtotime($selectedDate)) ?></h2>
            <span class="order-count">
                Standing expected: <?= (int)$summary['expected_customers'] ?> customers
                · Dated orders: <?= (int)$summary['customers_with_daily'] ?>
                · Exceptions: <?= (int)$summary['changed'] + (int)$summary['missing_daily'] + (int)$summary['one_off'] + (int)$summary['empty_daily'] ?>
            </span>
        </div>
        <div class="date-controls">
            <a href="<?= htmlspecialchars(daily_orders_filter_url(date('Y-m-d', strtotime($selectedDate . ' -1 day')), $selectedDriverId, $selectedZone, $groupBy, $filterExtra)) ?>" class="btn btn-outline">← Previous Day</a>
            <a href="<?= htmlspecialchars(daily_orders_filter_url(date('Y-m-d'), $selectedDriverId, $selectedZone, $groupBy, $filterExtra)) ?>" class="btn btn-primary">Today</a>
            <a href="<?= htmlspecialchars(daily_orders_filter_url(date('Y-m-d', strtotime($selectedDate . ' +1 day')), $selectedDriverId, $selectedZone, $groupBy, $filterExtra)) ?>" class="btn btn-outline">Next Day →</a>
        </div>
    </div>

    <div class="view-toggle">
        <a class="btn <?= $viewMode === 'review' ? 'btn-primary' : 'btn-outline' ?>"
           href="<?= htmlspecialchars(daily_orders_filter_url($selectedDate, $selectedDriverId, $selectedZone, $groupBy, array_merge($filterExtra, ['view' => 'review']))) ?>">
            Demand Review
        </a>
        <a class="btn <?= $viewMode === 'edit' ? 'btn-primary' : 'btn-outline' ?>"
           href="<?= htmlspecialchars(daily_orders_filter_url($selectedDate, $selectedDriverId, $selectedZone, $groupBy, array_merge($filterExtra, ['view' => 'edit']))) ?>">
            Edit Dated Orders
        </a>
    </div>
    
    <form class="order-filters" method="get">
        <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate) ?>">
        <input type="hidden" name="view" value="<?= htmlspecialchars($viewMode) ?>">
        <fieldset class="filter-group group-by-options">
            <legend>Group dated orders by</legend>
            <label><input type="radio" name="group_by" value="driver" <?= $groupBy === 'driver' ? 'checked' : '' ?>> Driver route</label>
            <label><input type="radio" name="group_by" value="zone" <?= $groupBy === 'zone' ? 'checked' : '' ?>> Zone</label>
        </fieldset>
        <div class="filter-group">
            <label for="review">Demand focus</label>
            <select id="review" name="review">
                <option value="differences" <?= $reviewFilter === 'differences' ? 'selected' : '' ?>>Differences only</option>
                <option value="missing" <?= $reviewFilter === 'missing' ? 'selected' : '' ?>>No dated order yet</option>
                <option value="changed" <?= $reviewFilter === 'changed' ? 'selected' : '' ?>>Changed quantities</option>
                <option value="one_off" <?= $reviewFilter === 'one_off' ? 'selected' : '' ?>>Added for this date</option>
                <option value="empty" <?= $reviewFilter === 'empty' ? 'selected' : '' ?>>Dated order has no items</option>
                <option value="paused" <?= $reviewFilter === 'paused' ? 'selected' : '' ?>>Paused this week</option>
                <option value="matches" <?= $reviewFilter === 'matches' ? 'selected' : '' ?>>Matches standing</option>
                <option value="all" <?= $reviewFilter === 'all' ? 'selected' : '' ?>>All customers</option>
            </select>
        </div>
        <div class="filter-group">
            <label for="customer">Customer</label>
            <input type="search" id="customer" name="customer" value="<?= htmlspecialchars($customerSearch) ?>" placeholder="Search name">
        </div>
        <div class="filter-group">
            <label for="product_id">Product</label>
            <select id="product_id" name="product_id">
                <option value="">All products</option>
                <?php foreach ($products as $product): ?>
                    <option value="<?= (int)$product['id'] ?>" <?= $selectedProductId === (int)$product['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($product['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label for="driver_id">Driver route</label>
            <select id="driver_id" name="driver_id">
                <option value="">All driver routes</option>
                <?php foreach ($drivers as $driver): ?>
                    <option value="<?= (int)$driver['id'] ?>" <?= $selectedDriverId === (int)$driver['id'] ? 'selected' : '' ?>><?= htmlspecialchars($driver['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label for="zone">Zone</label>
            <select id="zone" name="zone">
                <option value="">All zones</option>
                <?php foreach ($zones as $zone): ?>
                    <option value="<?= htmlspecialchars($zone) ?>" <?= $selectedZone === $zone ? 'selected' : '' ?>><?= htmlspecialchars($zone) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Apply Filters</button>
        <?php if ($selectedDriverId > 0 || $selectedZone !== '' || $customerSearch !== '' || $selectedProductId > 0 || $reviewFilter !== 'differences'): ?>
            <a href="<?= htmlspecialchars(daily_orders_filter_url($selectedDate, 0, '', $groupBy, ['view' => $viewMode, 'review' => 'differences'])) ?>" class="btn btn-outline">Clear Filters</a>
        <?php endif; ?>
    </form>

    <!-- Demand Review -->
    <section class="demand-review" id="demand-review">
        <div class="demand-review-header">
            <h2>Selected-date demand review</h2>
            <p>
                Compare <strong>standing forecast</strong> (recurring <?= htmlspecialchars($dayName) ?> demand)
                with <strong>dated daily orders</strong> for this delivery date.
                Production and delivery use dated orders when they exist.
            </p>
        </div>

        <div class="demand-summary-grid">
            <div class="demand-stat">
                <span class="demand-stat-label">Expected from standing</span>
                <span class="demand-stat-value"><?= (int)$summary['expected_customers'] ?></span>
                <span class="demand-stat-sub"><?= (int)$summary['standing_units'] ?> units</span>
            </div>
            <div class="demand-stat">
                <span class="demand-stat-label">Customers with dated orders</span>
                <span class="demand-stat-value"><?= (int)$summary['customers_with_daily'] ?></span>
                <span class="demand-stat-sub"><?= (int)$summary['daily_units'] ?> units</span>
            </div>
            <div class="demand-stat <?= (int)$summary['missing_daily'] > 0 ? 'is-alert' : '' ?>">
                <span class="demand-stat-label">No dated order yet</span>
                <span class="demand-stat-value"><?= (int)$summary['missing_daily'] ?></span>
                <span class="demand-stat-sub">Standing exists; daily row missing</span>
            </div>
            <div class="demand-stat <?= (int)$summary['changed'] > 0 ? 'is-warn' : '' ?>">
                <span class="demand-stat-label">Changed for this date</span>
                <span class="demand-stat-value"><?= (int)$summary['changed'] ?></span>
                <span class="demand-stat-sub">Quantities differ from standing</span>
            </div>
            <div class="demand-stat">
                <span class="demand-stat-label">Added for this date</span>
                <span class="demand-stat-value"><?= (int)$summary['one_off'] ?></span>
                <span class="demand-stat-sub">Dated order, no standing</span>
            </div>
            <div class="demand-stat">
                <span class="demand-stat-label">Unit delta (daily − standing)</span>
                <span class="demand-stat-value"><?= ((int)$summary['unit_delta'] > 0 ? '+' : '') . (int)$summary['unit_delta'] ?></span>
                <span class="demand-stat-sub"><?= (int)$summary['matches'] ?> match · <?= (int)$summary['paused'] ?> paused · <?= (int)$summary['empty_daily'] ?> empty</span>
            </div>
        </div>

        <?php if ((int)$demandSummaryAll['advanced_status_count'] > 0): ?>
            <div class="demand-ops-warning">
                <?= (int)$demandSummaryAll['advanced_status_count'] ?> dated order(s) on this date already look progressed
                (in production, ready, out for delivery, delivered, or invoiced).
                Quantity edits affect operational demand — confirm with production/delivery before changing them.
            </div>
        <?php endif; ?>

        <?php
        $productDiffs = array_values(array_filter(
            $demandSummaryAll['product_totals'],
            function ($p) {
                return (int)$p['standing_qty'] !== (int)$p['daily_qty'];
            }
        ));
        ?>
        <?php if (!empty($productDiffs)): ?>
            <div class="product-diff-panel">
                <h3>Product totals that differ</h3>
                <table class="items-table compact-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Standing</th>
                            <th>Dated</th>
                            <th>Delta</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($productDiffs, 0, 20) as $pt): ?>
                            <?php $delta = (int)$pt['daily_qty'] - (int)$pt['standing_qty']; ?>
                            <tr>
                                <td><?= htmlspecialchars($pt['product_name']) ?></td>
                                <td><span class="qty-standing"><?= (int)$pt['standing_qty'] ?></span></td>
                                <td><span class="qty-daily"><?= (int)$pt['daily_qty'] ?></span></td>
                                <td class="<?= $delta === 0 ? '' : ($delta > 0 ? 'delta-up' : 'delta-down') ?>">
                                    <?= ($delta > 0 ? '+' : '') . $delta ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (count($productDiffs) > 20): ?>
                    <p class="modal-note">Showing top 20 product differences.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="review-customer-list">
            <h3>
                Customer demand
                <span>(<?= count($demandReviewCustomers) ?> shown · focus: <?= htmlspecialchars(str_replace('_', ' ', $reviewFilter)) ?>)</span>
            </h3>

            <?php if (empty($demandReviewCustomers)): ?>
                <div class="empty-state compact-empty">
                    <h3>No customers match this demand focus</h3>
                    <p>
                        <?php if ($reviewFilter === 'differences' && (int)$summary['expected_customers'] + (int)$summary['customers_with_daily'] > 0): ?>
                            All visible customers match standing for this date. Switch focus to “All customers” or “Matches standing” to browse them.
                        <?php elseif ((int)$summary['expected_customers'] === 0 && (int)$summary['customers_with_daily'] === 0): ?>
                            No standing forecast and no dated orders for this date yet.
                        <?php else: ?>
                            Try a broader demand focus or clear filters.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <?php foreach ($demandReviewCustomers as $rc): ?>
                    <article class="review-card state-<?= htmlspecialchars($rc['state']) ?><?= in_array($rc['state'], ['missing_daily', 'empty_daily'], true) ? ' ops-attention-row' : '' ?>" id="customer-<?= (int)$rc['customer_id'] ?>">
                        <div class="review-card-header">
                            <div>
                                <h4><a href="customer_record.php?customer_id=<?= (int)$rc['customer_id'] ?>&date=<?= urlencode($selectedDate) ?>" style="color:inherit;text-decoration:none"><?= htmlspecialchars($rc['customer_name']) ?></a></h4>
                                <?php
                                $chipFlags = [];
                                if (($rc['state'] ?? '') === 'missing_daily') {
                                    $chipFlags['missing_daily'] = true;
                                }
                                if (($rc['state'] ?? '') === 'empty_daily') {
                                    $chipFlags['empty_daily'] = true;
                                }
                                echo bakery_ops_render_row_chips($pageExceptions, [
                                    'customer_id' => (int)$rc['customer_id'],
                                    'daily_order_id' => (int)($rc['daily_order_id'] ?? 0),
                                    'flags' => $chipFlags,
                                ], ['date' => $selectedDate, 'return' => (string)$pageReturnKey, 'daily_order_id' => (int)($rc['daily_order_id'] ?? 0)]);
                                ?>
                                <p>
                                    <?= htmlspecialchars($rc['zone_label']) ?>
                                    <?php if (!empty($rc['route_driver_name'])): ?>
                                        · <?= htmlspecialchars($rc['route_driver_name']) ?>
                                    <?php endif; ?>
                                    <?php if (!empty($rc['status'])): ?>
                                        · status <?= htmlspecialchars(str_replace('_', ' ', $rc['status'])) ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="review-badges">
                                <span class="state-badge state-<?= htmlspecialchars($rc['state']) ?>" title="<?= htmlspecialchars(bakery_demand_review_state_help($rc['state'])) ?>">
                                    <?= htmlspecialchars(bakery_demand_review_state_label($rc['state'])) ?>
                                </span>
                                <?php if (!empty($rc['paused'])): ?>
                                    <span class="state-badge state-paused">Paused week</span>
                                <?php endif; ?>
                                <?php if (!empty($rc['is_advanced'])): ?>
                                    <span class="state-badge state-advanced">In ops pipeline</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <table class="items-table compact-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Standing (<?= htmlspecialchars($dayName) ?>)</th>
                                    <th>Dated (<?= htmlspecialchars(date('M j', strtotime($selectedDate))) ?>)</th>
                                    <th>Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $lines = $rc['line_map'];
                                if ($reviewFilter !== 'all' && $reviewFilter !== 'matches' && !empty($rc['diff_lines'])) {
                                    $diffIds = array_column($rc['diff_lines'], 'product_id');
                                    $lines = array_filter($lines, function ($line) use ($diffIds) {
                                        return in_array((int)$line['product_id'], $diffIds, true);
                                    });
                                }
                                if (empty($lines)):
                                ?>
                                    <tr>
                                        <td colspan="4">No product lines for this customer under the current filters.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($lines as $line): ?>
                                        <?php
                                        $s = $line['standing_qty'];
                                        $d = $line['daily_qty'];
                                        $note = 'Matches';
                                        if ($s !== null && $d === null) {
                                            $note = 'On standing only';
                                        } elseif ($s === null && $d !== null) {
                                            $note = 'Dated only (this date)';
                                        } elseif ($s !== null && $d !== null && (int)$s !== (int)$d) {
                                            $note = 'Changed for this date';
                                        } elseif ($s === null && $d === null) {
                                            $note = '—';
                                        }
                                        ?>
                                        <tr class="<?= ($s !== null && $d !== null && (int)$s === (int)$d) ? 'row-match' : 'row-diff' ?>">
                                            <td><?= htmlspecialchars($line['product_name']) ?></td>
                                            <td>
                                                <?php if ($s === null): ?>
                                                    <span class="muted">—</span>
                                                <?php else: ?>
                                                    <span class="qty-standing" title="From standing_orders (recurring)"><?= (int)$s ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($d === null): ?>
                                                    <span class="muted">—</span>
                                                <?php else: ?>
                                                    <span class="qty-daily" title="From daily_order_items for <?= htmlspecialchars($selectedDate) ?>"><?= (int)$d ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($note) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <div class="review-card-actions">
                            <?php if (!empty($rc['daily_order_id'])): ?>
                                <a class="btn btn-small btn-primary"
                                   href="<?= htmlspecialchars(daily_orders_filter_url($selectedDate, $selectedDriverId, $selectedZone, $groupBy, array_merge($filterExtra, ['view' => 'edit', 'review' => 'all']))) ?>#order-<?= (int)$rc['daily_order_id'] ?>">
                                    Edit dated order
                                </a>
                                <?php if ($rc['state'] === 'empty_daily'): ?>
                                    <button type="button"
                                            class="btn btn-small btn-outline-danger"
                                            onclick="removeDatedOrder(<?= (int)$rc['daily_order_id'] ?>, <?= json_encode($rc['customer_name']) ?>)">
                                        Remove dated order
                                    </button>
                                <?php endif; ?>
                            <?php elseif ($rc['state'] === 'missing_daily'): ?>
                                <span class="review-hint">No dated order yet — use Generate from Standing Orders to create dated demand from the forecast (paused customers are skipped).</span>
                            <?php endif; ?>
                            <a class="btn btn-small btn-outline" href="standing_orders_manager.php">Standing forecast (recurring)</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($viewMode === 'edit'): ?>
    <section class="dated-orders-section" id="dated-orders">
        <div class="demand-review-header">
            <h2>Edit dated orders for <?= htmlspecialchars(date('l, F j, Y', strtotime($selectedDate))) ?></h2>
            <p class="date-scope-banner">
                Quantity and product changes below apply <strong>only to this date</strong>.
                They do not change standing/recurring forecast. Use Standing Orders to edit the template.
            </p>
        </div>

    <!-- Orders List -->
    <?php if (empty($dailyOrders)): ?>
        <div class="empty-state">
            <h3>No dated orders for this date</h3>
            <p>
                Standing forecast expects <?= (int)$summary['expected_customers'] ?> customer(s) on <?= htmlspecialchars($dayName) ?>s.
                Generate dated orders from standing, or review the Demand Review panel above for who is missing.
            </p>
            <button class="btn btn-primary" onclick="showGenerateModal()">
                Generate from Standing Orders
            </button>
        </div>
    <?php else: ?>
        <?php
        $ordersByGroup = [];
        foreach ($dailyOrders as $order) {
            $groupName = $groupBy === 'zone'
                ? ($order['zone_label'] ?? ($order['zone'] ?: 'No Zone'))
                : ($order['route_driver_name'] ?: 'Unassigned route');
            $ordersByGroup[$groupName][] = $order;
        }
        ?>
        <div class="orders-list">
            <?php foreach ($ordersByGroup as $groupName => $groupOrders): ?>
                <section class="order-group">
                    <h2><?= $groupBy === 'zone' ? 'Zone: ' : 'Driver route: ' ?><?= htmlspecialchars($groupName) ?> <span>(<?= count($groupOrders) ?>)</span></h2>
                    <?php foreach ($groupOrders as $order): ?>
                <?php
                    $orderReview = $reviewByOrderId[(int)$order['id']] ?? null;
                    $orderState = $orderReview['state'] ?? 'one_off';
                    $isAdvanced = !empty($orderReview['is_advanced']) || bakery_demand_review_is_advanced_status($order['status'], null);
                    $standingByProduct = [];
                    if ($orderReview) {
                        foreach ($orderReview['line_map'] as $line) {
                            if ($line['standing_qty'] !== null) {
                                $standingByProduct[(int)$line['product_id']] = (int)$line['standing_qty'];
                            }
                        }
                    }
                ?>
                <div class="order-card state-<?= htmlspecialchars($orderState) ?>" id="order-<?= (int)$order['id'] ?>" data-advanced="<?= $isAdvanced ? '1' : '0' ?>">
                    <div class="order-header">
                        <div class="customer-info">
                            <h3><a href="customer_record.php?customer_id=<?= (int)$order['customer_id'] ?>&date=<?= urlencode($selectedDate) ?>" style="color:inherit;text-decoration:none"><?= htmlspecialchars($order['customer_name']) ?></a></h3>
                            <p class="address"><?= htmlspecialchars($order['address']) ?></p>
                            <p class="address">
                                Dated order for <?= htmlspecialchars(date('D, M j', strtotime($selectedDate))) ?>
                                · <?= htmlspecialchars(bakery_demand_review_state_label($orderState)) ?>
                            </p>
                        </div>
                        <div class="order-status">
                            <span class="status-badge status-<?= $order['status'] ?>">
                                <?= ucwords(str_replace('_', ' ', $order['status'])) ?>
                            </span>
                            <span class="state-badge state-<?= htmlspecialchars($orderState) ?>">
                                <?= htmlspecialchars(bakery_demand_review_state_label($orderState)) ?>
                            </span>
                        </div>
                        <div class="order-total">
                            <strong>$<?= number_format($order['total_amount'], 2) ?></strong>
                        </div>
                    </div>
                    
                    <div class="order-body">
                        <?php if ($isAdvanced): ?>
                            <div class="demand-ops-warning compact-warning">
                                This dated order appears progressed in production/delivery
                                (<?= htmlspecialchars(str_replace('_', ' ', $order['status'])) ?>).
                                Changing quantities here updates operational demand for this date only.
                            </div>
                        <?php endif; ?>

                        <!-- Order Items -->
                        <?php if (isset($orderItems[$order['id']])): ?>
                            <div class="order-items">
                                <table class="items-table">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Standing</th>
                                            <th>Dated qty <small>(this date)</small></th>
                                            <th>Delivered</th>
                                            <th>Unit Price</th>
                                            <th>Total</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($orderItems[$order['id']] as $item): ?>
                                            <?php
                                                $standingQty = $standingByProduct[(int)$item['product_id']] ?? null;
                                                $differs = $standingQty !== null && (int)$standingQty !== (int)$item['quantity'];
                                                $dailyOnly = $standingQty === null;
                                            ?>
                                            <tr class="<?= $differs || $dailyOnly ? 'row-diff' : 'row-match' ?>">
                                                <td>
                                                    <?= htmlspecialchars($item['product_name']) ?>
                                                    <?php if ($dailyOnly): ?>
                                                        <div class="source-tag">Dated only</div>
                                                    <?php elseif ($differs): ?>
                                                        <div class="source-tag">Changed vs standing</div>
                                                    <?php else: ?>
                                                        <div class="source-tag quiet">Matches standing</div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($standingQty === null): ?>
                                                        <span class="muted">—</span>
                                                    <?php else: ?>
                                                        <span class="qty-standing"><?= (int)$standingQty ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <input type="number" 
                                                           value="<?= $item['quantity'] ?>" 
                                                           min="0" 
                                                           class="quantity-input" 
                                                           title="Edits the dated daily order for <?= htmlspecialchars($selectedDate) ?> only"
                                                           onchange="updateQuantity(<?= $item['id'] ?>, this.value, <?= $isAdvanced ? 'true' : 'false' ?>)">
                                                </td>
                                                <td>
                                                    <?php if ($item['delivered_quantity'] === null): ?>
                                                        <span class="delivery-quantity-pending">&mdash;</span>
                                                    <?php else: ?>
                                                        <strong><?= (int)$item['delivered_quantity'] ?></strong>
                                                    <?php endif; ?>
                                                </td>
                                                <td>$<?= number_format($item['unit_price'], 2) ?></td>
                                                <td>$<?= number_format($item['line_total'], 2) ?></td>
                                                <td>
                                                    <button class="btn btn-small btn-danger" 
                                                            onclick="updateQuantity(<?= $item['id'] ?>, 0, <?= $isAdvanced ? 'true' : 'false' ?>)">
                                                        Remove
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if ($orderReview): ?>
                                            <?php foreach ($orderReview['line_map'] as $line): ?>
                                                <?php if ($line['standing_qty'] !== null && $line['daily_qty'] === null): ?>
                                                    <tr class="row-diff">
                                                        <td>
                                                            <?= htmlspecialchars($line['product_name']) ?>
                                                            <div class="source-tag">On standing only</div>
                                                        </td>
                                                        <td><span class="qty-standing"><?= (int)$line['standing_qty'] ?></span></td>
                                                        <td><span class="muted">not on dated order</span></td>
                                                        <td colspan="4"><span class="muted">Add below if needed for this date</span></td>
                                                    </tr>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-order-shell">
                                <p class="muted">This dated order has no line items yet.</p>
                                <button type="button"
                                        class="btn btn-small btn-outline-danger"
                                        onclick="removeDatedOrder(<?= (int)$order['id'] ?>, <?= json_encode($order['customer_name']) ?>)">
                                    Remove dated order
                                </button>
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
                                <label>Add product to this date only:</label>
                                <div class="add-product-controls">
                                    <select class="product-select" id="newProduct_<?= $order['id'] ?>" onchange="setPanDulceQuantity(<?= $order['id'] ?>)">
                                        <option value="">Select Product</option>
                                        <?php foreach ($products as $product): ?>
                                            <option value="<?= $product['id'] ?>"
                                                data-pan-dulce="<?= $product['product_line_name'] === 'Pan Dulce' ? '1' : '0' ?>"
                                                data-standard-quantity="<?= (int)$product['pan_dulce_standard_quantity'] ?>">
                                                <?= htmlspecialchars($product['name']) ?> ($<?= $product['price'] ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select class="pan-dulce-multiplier" id="panMultiplier_<?= $order['id'] ?>" onchange="setPanDulceQuantity(<?= $order['id'] ?>)" title="Pan Dulce amount">
                                        <option value="1">1×</option>
                                        <option value="1.5">1.5×</option>
                                        <option value="2">2×</option>
                                    </select>
                                    <input type="number" class="quantity-input" placeholder="Qty" id="newQty_<?= $order['id'] ?>">
                                    <button class="btn btn-primary" onclick="addItem(<?= $order['id'] ?>, <?= $isAdvanced ? 'true' : 'false' ?>)">Add</button>
                                </div>
                                <small class="pan-dulce-note">Adds to the dated order for <?= htmlspecialchars($selectedDate) ?> only. Leave product blank and choose 1× / 1.5× / 2× to add the full Pan Dulce suite, or pick one product to add a single line.</small>
                            </div>
                        </div>
                    </div>
                </div>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    </section>
    <?php else: ?>
        <div class="edit-view-nudge">
            <p>Demand Review is focused on exceptions. Switch to <a href="<?= htmlspecialchars(daily_orders_filter_url($selectedDate, $selectedDriverId, $selectedZone, $groupBy, array_merge($filterExtra, ['view' => 'edit']))) ?>">Edit Dated Orders</a> to change quantities for this date.</p>
        </div>
    <?php endif; ?>
</div>

<!-- One-Time Dated Order Modal -->
<div class="modal-overlay" id="createDatedOrderModal" style="display: none;">
    <div class="modal">
        <div class="modal-header">
            <h3><?= htmlspecialchars(bakery_t('daily_orders.create_dated_order_title')) ?></h3>
            <button type="button" class="modal-close" onclick="hideCreateDatedOrderModal()">×</button>
        </div>
        <div class="modal-body">
            <p><?= htmlspecialchars(bakery_t('daily_orders.create_dated_order_help', [
                'date' => date('l, F j, Y', strtotime($selectedDate)),
            ])) ?></p>
            <label for="createDatedOrderCustomer"><strong><?= htmlspecialchars(bakery_t('daily_orders.customer')) ?></strong></label>
            <select id="createDatedOrderCustomer" class="product-select" style="width:100%;margin-top:.5rem;">
                <option value=""><?= htmlspecialchars(bakery_t('daily_orders.choose_customer')) ?></option>
                <?php foreach ($customers as $customer): ?>
                    <option value="<?= (int)$customer['id'] ?>"><?= htmlspecialchars($customer['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="modal-note"><?= htmlspecialchars(bakery_t('daily_orders.create_dated_order_note')) ?></p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="hideCreateDatedOrderModal()">
                <?= htmlspecialchars(bakery_t('daily_orders.cancel')) ?>
            </button>
            <button type="button" class="btn btn-success" id="createDatedOrderBtn" onclick="createDatedOrder()">
                <?= htmlspecialchars(bakery_t('daily_orders.create_and_edit')) ?>
            </button>
        </div>
    </div>
</div>

<!-- Generate Orders Modal -->
<div class="modal-overlay" id="generateModal" style="display: none;">
    <div class="modal modal-wide">
        <div class="modal-header">
            <h3>Generate dated orders from standing forecast</h3>
            <button type="button" class="modal-close" onclick="hideGenerateModal()">×</button>
        </div>
        <div class="modal-body">
            <p>
                Create or refresh <strong>dated daily orders</strong> for
                <strong><?= date('l, F j, Y', strtotime($selectedDate)) ?></strong>
                from the <strong>standing forecast</strong> for <?= htmlspecialchars($dayName) ?>s.
            </p>
            <p class="modal-note">
                This does not edit standing orders. Paused customers for this week are skipped.
                Inactive customers are excluded. One-off dated lines that are not on standing are left alone.
            </p>
            <div id="generatePreview" class="generate-preview">
                <p class="muted">Loading preview…</p>
            </div>
            <label class="overwrite-option" id="overwriteOption" style="display:none;">
                <input type="checkbox" id="overwriteChanged">
                Overwrite dated quantities that already differ from standing
            </label>
            <p class="modal-note" id="overwriteHelp">
                By default, generation fills missing dated demand and refreshes lines that already match standing.
                Lines you already changed for this date are preserved unless you opt in above.
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="hideGenerateModal()">Cancel</button>
            <button type="button" class="btn btn-primary" id="generateConfirmBtn" onclick="generateFromStanding()" disabled>Generate Dated Orders</button>
        </div>
    </div>
</div>

<style>
/* Shared layout/buttons: css/base.css */

.order-filters {
    display: flex;
    align-items: end;
    gap: 15px;
    flex-wrap: wrap;
    margin: 0 0 30px;
    padding: 15px 20px;
    background-color: #f8f9fa;
    border-radius: 10px;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.filter-group label {
    font-weight: bold;
    color: #2c3e50;
}

.filter-group select {
    min-width: 190px;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 5px;
    font-size: 14px;
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

.order-group h2 {
    margin: 10px 0;
    color: #2c3e50;
    font-size: 20px;
}

.order-group h2 span {
    color: #6c757d;
    font-size: 14px;
    font-weight: normal;
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

.pan-dulce-multiplier {
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 5px;
}

.pan-dulce-note {
    display: block;
    color: #6c757d;
    margin-top: 5px;
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

.source-legend {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 12px;
    margin: -10px 0 24px;
}
.source-legend > div {
    background: #f4f7fb;
    border-left: 4px solid #4e73df;
    padding: 12px 14px;
}
.source-legend > div:last-child {
    border-left-color: #1cc88a;
}
.source-legend strong {
    display: block;
    margin-bottom: 4px;
    color: #2c3e50;
}
.source-legend span {
    color: #5a6a7a;
    font-size: 13px;
}

.view-toggle {
    display: flex;
    gap: 8px;
    margin: 0 0 18px;
    flex-wrap: wrap;
}

.demand-review {
    margin-bottom: 28px;
    padding: 20px;
    background: #fbfcfe;
    border: 1px solid #e3e8ef;
    border-radius: 10px;
}
.demand-review-header h2,
.dated-orders-section .demand-review-header h2 {
    margin: 0 0 8px;
    color: #2c3e50;
}
.demand-review-header p {
    margin: 0 0 16px;
    color: #5a6a7a;
}
.date-scope-banner {
    background: #eaf7f0;
    border: 1px solid #b7e0c5;
    padding: 12px 14px;
    border-radius: 8px;
    color: #215c36 !important;
}

.demand-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}
.demand-stat {
    background: white;
    border: 1px solid #e3e8ef;
    border-radius: 8px;
    padding: 12px;
}
.demand-stat.is-alert {
    border-color: #e74a3b;
    background: #fff5f5;
}
.demand-stat.is-warn {
    border-color: #f6c23e;
    background: #fffbeb;
}
.demand-stat-label {
    display: block;
    font-size: 12px;
    color: #6c757d;
    margin-bottom: 6px;
}
.demand-stat-value {
    display: block;
    font-size: 28px;
    font-weight: 700;
    color: #2c3e50;
    line-height: 1.1;
}
.demand-stat-sub {
    display: block;
    margin-top: 4px;
    font-size: 12px;
    color: #6c757d;
}

.demand-ops-warning {
    background: #fff4e5;
    border: 1px solid #f0ad4e;
    color: #8a5a00;
    padding: 12px 14px;
    border-radius: 8px;
    margin-bottom: 16px;
}
.compact-warning {
    margin-bottom: 14px;
    font-size: 13px;
}
.empty-order-shell {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    margin-bottom: 14px;
}
.empty-order-shell p {
    margin: 0;
}
.btn-outline-danger {
    background: transparent;
    color: #e74a3b;
    border: 1px solid #e74a3b;
}
.btn-outline-danger:hover {
    background: #e74a3b;
    color: #fff;
}

.product-diff-panel,
.review-customer-list {
    margin-top: 18px;
}
.product-diff-panel h3,
.review-customer-list h3 {
    margin: 0 0 10px;
    color: #2c3e50;
}
.review-customer-list h3 span {
    color: #6c757d;
    font-size: 14px;
    font-weight: normal;
}

.review-card {
    background: white;
    border: 1px solid #e3e8ef;
    border-radius: 8px;
    padding: 14px;
    margin-bottom: 12px;
}
.review-card.state-missing_daily,
.order-card.state-missing_daily { border-left: 4px solid #e74a3b; }
.review-card.state-changed,
.order-card.state-changed { border-left: 4px solid #f6c23e; }
.review-card.state-one_off,
.order-card.state-one_off { border-left: 4px solid #36b9cc; }
.review-card.state-empty_daily,
.order-card.state-empty_daily { border-left: 4px solid #858796; }
.review-card.state-paused { border-left: 4px solid #6f42c1; }
.review-card.state-matches,
.order-card.state-matches { border-left: 4px solid #1cc88a; }

.review-card-header {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: flex-start;
    margin-bottom: 10px;
}
.review-card-header h4 {
    margin: 0 0 4px;
    color: #2c3e50;
}
.review-card-header p {
    margin: 0;
    color: #6c757d;
    font-size: 13px;
}
.review-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    justify-content: flex-end;
}
.state-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.02em;
    background: #e9ecef;
    color: #2c3e50;
}
.state-badge.state-missing_daily { background: #fdecea; color: #c0392b; }
.state-badge.state-changed { background: #fff3cd; color: #856404; }
.state-badge.state-one_off { background: #d1ecf1; color: #0c5460; }
.state-badge.state-empty_daily { background: #e2e3e5; color: #383d41; }
.state-badge.state-paused { background: #efe8ff; color: #5a3d9e; }
.state-badge.state-matches { background: #d4edda; color: #155724; }
.state-badge.state-advanced { background: #ffe8cc; color: #9a5b00; }

.review-card-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin-top: 10px;
}
.review-hint {
    color: #6c757d;
    font-size: 13px;
}
.compact-table th, .compact-table td {
    padding: 7px 8px;
    font-size: 13px;
}
.row-diff { background: #fffaf0; }
.row-match { background: transparent; }
.qty-standing {
    display: inline-block;
    min-width: 2ch;
    color: #4e73df;
    font-weight: 700;
}
.qty-daily {
    display: inline-block;
    min-width: 2ch;
    color: #1cc88a;
    font-weight: 700;
}
.delta-up { color: #1cc88a; font-weight: 700; }
.delta-down { color: #e74a3b; font-weight: 700; }
.muted { color: #95a5a6; }
.source-tag {
    font-size: 11px;
    color: #856404;
    margin-top: 2px;
}
.source-tag.quiet { color: #6c757d; }
.order-status {
    display: flex;
    flex-direction: column;
    gap: 6px;
    align-items: flex-end;
}
.compact-empty {
    padding: 30px 16px;
}
.edit-view-nudge {
    margin: 10px 0 30px;
    padding: 14px 16px;
    background: #f8f9fa;
    border-radius: 8px;
    color: #5a6a7a;
}
.order-filters input[type="search"] {
    min-width: 190px;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 5px;
    font-size: 14px;
}
.modal-wide {
    max-width: 720px;
}
.generate-preview {
    background: #f8f9fa;
    border: 1px solid #e3e8ef;
    border-radius: 8px;
    padding: 12px 14px;
    margin: 12px 0;
    font-size: 14px;
}
.generate-preview ul {
    margin: 8px 0 0 18px;
    padding: 0;
}
.overwrite-option {
    display: flex;
    gap: 8px;
    align-items: flex-start;
    margin: 12px 0 0;
    font-weight: 600;
    color: #2c3e50;
}
.dated-orders-section {
    margin-top: 10px;
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

    .order-filters {
        align-items: stretch;
    }

    .filter-group select,
    .order-filters input[type="search"] {
        min-width: 0;
        width: 100%;
    }
    
    .order-header,
    .review-card-header {
        flex-direction: column;
        gap: 15px;
        text-align: center;
    }
    .order-status,
    .review-badges {
        align-items: center;
        justify-content: center;
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
const selectedOrderDate = '<?= htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8') ?>';

function showCreateDatedOrderModal() {
    document.getElementById('createDatedOrderModal').style.display = 'flex';
    document.getElementById('createDatedOrderCustomer').focus();
}

function hideCreateDatedOrderModal() {
    document.getElementById('createDatedOrderModal').style.display = 'none';
}

function createDatedOrder() {
    const customerId = parseInt(document.getElementById('createDatedOrderCustomer').value, 10);
    if (!customerId) {
        alert(<?= json_encode(bakery_t('daily_orders.choose_customer_error')) ?>);
        return;
    }

    const button = document.getElementById('createDatedOrderBtn');
    button.disabled = true;
    fetch('daily_orders.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=create_dated_order&customer_id=' + customerId + '&date=' + encodeURIComponent(selectedOrderDate)
    })
    .then(async response => {
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) {
            throw new Error(data.error || <?= json_encode(bakery_t('daily_orders.create_error')) ?>);
        }
        return data;
    })
    .then(data => {
        window.location.href = 'daily_orders.php?date=' + encodeURIComponent(selectedOrderDate)
            + '&view=edit&review=all#order-' + data.daily_order_id;
    })
    .catch(error => {
        alert('Error: ' + error.message);
        button.disabled = false;
    });
}

function showGenerateModal() {
    document.getElementById('generateModal').style.display = 'flex';
    document.getElementById('generateConfirmBtn').disabled = true;
    document.getElementById('overwriteChanged').checked = false;
    document.getElementById('generatePreview').innerHTML = '<p class="muted">Loading preview…</p>';
    loadGeneratePreview();
}

function hideGenerateModal() {
    document.getElementById('generateModal').style.display = 'none';
}

function loadGeneratePreview() {
    fetch('daily_orders.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=preview_generate&date=' + encodeURIComponent(selectedOrderDate)
    })
    .then(response => response.json())
    .then(data => {
        const box = document.getElementById('generatePreview');
        const overwriteWrap = document.getElementById('overwriteOption');
        const btn = document.getElementById('generateConfirmBtn');
        if (!data.success) {
            box.innerHTML = '<p class="delta-down">Preview failed: ' + escapeHtml(data.error || 'unknown error') + '</p>';
            return;
        }
        const p = data.preview;
        let html = '<p><strong>Preview for ' + escapeHtml(p.day_name) + ', ' + escapeHtml(p.date) + '</strong></p>';
        html += '<ul>';
        html += '<li>Standing expects <strong>' + p.expected_customers + '</strong> customer(s); <strong>' + p.customers_with_daily + '</strong> already have dated orders.</li>';
        html += '<li>Would create dated orders for about <strong>' + p.would_create_customers + '</strong> customer(s) still missing them.</li>';
        html += '<li>Would write about <strong>' + p.would_create_items + '</strong> new dated item line(s) from standing.</li>';
        html += '<li>Paused customers skipped: <strong>' + p.paused_customers_skipped + '</strong>.</li>';
        if (p.would_overwrite_changed_items > 0) {
            html += '<li class="delta-down"><strong>' + p.would_overwrite_changed_items + '</strong> dated item(s) currently differ from standing and would be reset if overwrite is enabled.</li>';
        } else {
            html += '<li>No differing dated quantities would be overwritten.</li>';
        }
        if (p.advanced_status_count > 0) {
            html += '<li class="delta-down"><strong>' + p.advanced_status_count + '</strong> dated order(s) already look progressed in production/delivery.</li>';
        }
        html += '</ul>';
        if (p.overwrite_examples && p.overwrite_examples.length) {
            html += '<p><strong>Examples that differ today:</strong></p><ul>';
            p.overwrite_examples.forEach(function (ex) {
                html += '<li>' + escapeHtml(ex.customer_name) + ' — ' + escapeHtml(ex.product_name) +
                    ': standing ' + ex.standing_qty + ' → dated ' + ex.daily_qty +
                    (ex.status ? ' (' + escapeHtml(ex.status) + ')' : '') + '</li>';
            });
            html += '</ul>';
        }
        box.innerHTML = html;
        overwriteWrap.style.display = p.would_overwrite_changed_items > 0 ? 'flex' : 'none';
        btn.disabled = false;
    })
    .catch(function (error) {
        document.getElementById('generatePreview').innerHTML =
            '<p class="delta-down">Preview failed: ' + escapeHtml(error.message) + '</p>';
    });
}

function escapeHtml(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function setPanDulceQuantity(orderId) {
    const product = document.getElementById(`newProduct_${orderId}`);
    const quantity = document.getElementById(`newQty_${orderId}`);
    const multiplier = document.getElementById(`panMultiplier_${orderId}`);
    const selected = product.options[product.selectedIndex];
    if (selected && selected.dataset.panDulce === '1') {
        quantity.value = Math.round(Number(selected.dataset.standardQuantity) * Number(multiplier.value));
    }
}

function showDatePicker() {
    const date = prompt('Enter date (YYYY-MM-DD):', selectedOrderDate);
    if (date) {
        const params = new URLSearchParams(window.location.search);
        params.set('date', date);
        window.location.href = `?${params.toString()}`;
    }
}

function generateFromStanding() {
    const overwrite = document.getElementById('overwriteChanged').checked ? '1' : '0';
    if (overwrite === '1') {
        const ok = confirm(
            'Overwrite mode will reset dated quantities that already differ from standing for ' +
            selectedOrderDate + '.\n\nContinue?'
        );
        if (!ok) return;
    }

    const btn = document.getElementById('generateConfirmBtn');
    btn.disabled = true;

    fetch('daily_orders.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=generate_from_standing&date=' + encodeURIComponent(selectedOrderDate) +
              '&overwrite_changed=' + overwrite
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Generation failed'));
            btn.disabled = false;
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
        btn.disabled = false;
    });
}

function generateWeekFromStanding() {
    const ok = confirm(
        'Generate dated orders from standing for the full Monday–Sunday week that contains ' +
        selectedOrderDate + '?\n\nPaused customers are skipped, inactive customers are excluded, ' +
        'and dated quantity changes are preserved.'
    );
    if (!ok) return;

    fetch('daily_orders.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=generate_week_from_standing&date=' + encodeURIComponent(selectedOrderDate) +
              '&overwrite_changed=0'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Week generation failed'));
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    });
}

function removeDatedOrder(orderId, customerName, confirmDelivered) {
    const label = customerName ? (' for ' + customerName) : '';
    if (confirmDelivered !== true && !confirm('Remove the empty dated order' + label + ' on ' + selectedOrderDate + '? Standing forecast is not affected. This cannot be undone.')) {
        return;
    }

    let body = 'action=remove_order'
        + '&order_id=' + orderId
        + '&date=' + encodeURIComponent(selectedOrderDate);
    if (confirmDelivered === true) {
        body += '&confirm_delivered=1';
    }

    fetch('daily_orders.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else if (data.requires_delivered_confirmation) {
            const statusLabel = data.status === 'invoiced' ? 'invoiced' : 'delivered';
            if (confirm('This order is marked ' + statusLabel + '. Remove it anyway?')) {
                removeDatedOrder(orderId, customerName, true);
            }
        } else {
            alert('Error: ' + (data.error || 'Unable to remove dated order'));
        }
    })
    .catch(error => alert('Error: ' + error.message));
}

function clearSelectedDay() {
    const date = selectedOrderDate;
    if (!confirm('Clear all dated daily orders for ' + date + '? Standing forecast is not affected. This cannot be undone.')) return;

    clearSelectedDayRequest(date, false);
}

function clearSelectedDayRequest(date, confirmDelivered) {
    fetch('daily_orders.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=clear_day&date=' + encodeURIComponent(date) + '&confirm_delivered=' + (confirmDelivered ? '1' : '0')
    })
    .then(response => response.json())
    .then(data => {
        if (data.requires_delivered_confirmation) {
            const message = 'This day includes ' + data.delivered_count + ' delivered/invoiced order(s).\n\n' +
                'Confirm again to permanently delete those orders and all associated delivery history and photos.';
            if (confirm(message)) clearSelectedDayRequest(date, true);
            return;
        }
        if (data.success) {
            alert('Cleared ' + data.deleted_count + ' daily order(s).');
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Unable to clear orders'));
        }
    })
    .catch(error => alert('Error: ' + error.message));
}

function confirmAdvancedEdit(isAdvanced) {
    if (!isAdvanced) return true;
    return confirm(
        'This dated order already appears progressed in production or delivery.\n\n' +
        'Changing it updates demand for ' + selectedOrderDate + ' only (not standing).\n\nContinue?'
    );
}

function updateQuantity(itemId, quantity, isAdvanced) {
    if (!confirmAdvancedEdit(!!isAdvanced)) {
        location.reload();
        return;
    }
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

function addItem(orderId, isAdvanced) {
    const productSelect = document.getElementById(`newProduct_${orderId}`);
    const qtyInput = document.getElementById(`newQty_${orderId}`);
    const multiplierSelect = document.getElementById(`panMultiplier_${orderId}`);

    const productId = productSelect.value;
    const quantity = parseInt(qtyInput.value) || 0;
    const multiplier = multiplierSelect ? multiplierSelect.value : '1';

    if (!productId) {
        applyPanDulceStandardToOrder(orderId, multiplier, isAdvanced);
        return;
    }

    if (quantity <= 0) {
        alert('Please enter a quantity');
        return;
    }
    if (!confirmAdvancedEdit(!!isAdvanced)) {
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
            alert('Error: ' + (data.error || 'Unable to add item'));
        }
    });
}

function applyPanDulceStandardToOrder(orderId, multiplier, isAdvanced) {
    if (!confirmAdvancedEdit(!!isAdvanced)) {
        return;
    }

    fetch('daily_orders.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=apply_pan_dulce_standard_to_order&order_id=${orderId}&multiplier=${encodeURIComponent(multiplier)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Unable to apply Pan Dulce standard'));
        }
    })
    .catch(error => alert('Error: ' + error.message));
}

// Close modal when clicking outside
document.addEventListener('click', function(event) {
    const modal = document.getElementById('generateModal');
    if (event.target === modal) {
        hideGenerateModal();
    }
});

// Jump to an order card when linked from Demand Review.
if (window.location.hash.startsWith('#order-')) {
    const target = document.querySelector(window.location.hash);
    if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        target.style.boxShadow = '0 0 0 3px rgba(78,115,223,0.35)';
    }
}
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
