<?php
define('ACCESS_ALLOWED', true);
define('BAKERY_PAGE_BUILD', 'driver-assignment-append-20260801');

require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/google_maps_config.php';
require_once 'includes/operational_timeline.php';
require_once 'includes/driver_assignments.php';
require_once 'includes/operational_exceptions.php';
require_once 'includes/customer_record.php';

$page_title = bakery_t('page.driver_assignment');
bakery_ensure_standing_routes_order_column($db);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'assign_orders':
                $driverId = (int)($_POST['driver_id'] ?? 0);
                $deliveryDate = (string)($_POST['delivery_date'] ?? '');
                $assignments = json_decode($_POST['assignments'] ?? '[]', true);
                // replace = full route rewrite (edit/optimize/reorder)
                // append = add selected orders without clearing existing ones
                $mode = strtolower(trim((string)($_POST['mode'] ?? 'replace')));
                if (!in_array($mode, ['replace', 'append'], true)) {
                    $mode = 'replace';
                }
                
                if (!is_array($assignments)) {
                    throw new Exception('Invalid assignments');
                }

                $result = bakery_driver_assign_orders($db, $driverId, $deliveryDate, $assignments, $mode);

                echo json_encode([
                    'success' => true,
                    'message' => $result['mode'] === 'append'
                        ? ($result['stop_count'] > 0
                            ? $result['stop_count'] . ' stop' . ($result['stop_count'] === 1 ? '' : 's') . ' added to ' . $result['driver_name']
                            : 'Those stops are already on ' . $result['driver_name'] . '\'s route')
                        : ($result['stop_count'] > 0
                            ? 'Route saved for ' . $result['driver_name']
                            : 'Route cleared for ' . $result['driver_name']),
                    'mode' => $result['mode'],
                    'stop_count' => $result['stop_count'],
                ]);
                break;
                
            case 'get_optimized_route':
                $driverId = (int)$_POST['driver_id'];
                $deliveryDate = $_POST['delivery_date'];
                
                // Get orders for this driver and date
                $stmt = $db->prepare("
                    SELECT 
                        do.id as daily_order_id,
                        do.customer_id,
                        c.name as customer_name,
                        c.address,
                        c.deliver_by,
                        c.deliver_after,
                        COALESCE(c.delivery_time, 20) as delivery_time,
                        c.latitude,
                        c.longitude
                    FROM daily_orders do
                    JOIN customers c ON do.customer_id = c.id
                    " . bakery_sfb_ops_origin_clause('c', $db) . "
                    WHERE do.order_date = ?
                    AND do.id IN (
                        SELECT daily_order_id 
                        FROM daily_order_assignments 
                        WHERE driver_id = ? AND delivery_date = ?
                    )
                    ORDER BY c.name
                ");
                $stmt->execute([$deliveryDate, $driverId, $deliveryDate]);
                $orders = $stmt->fetchAll();
                
                echo json_encode(['success' => true, 'orders' => $orders]);
                break;
                
            case 'update_delivery_time':
                $customerId = (int)$_POST['customer_id'];
                $deliveryTime = (int)$_POST['delivery_time'];
                
                if ($deliveryTime >= 1 && $deliveryTime <= 120) {
                    $stmt = $db->prepare("UPDATE customers SET delivery_time = ? WHERE id = ?");
                    $success = $stmt->execute([$deliveryTime, $customerId]);
                    echo json_encode(['success' => $success]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Invalid delivery time']);
                }
                break;
                
            case 'remove_assignment':
                $dailyOrderId = (int)($_POST['daily_order_id'] ?? 0);
                $driverId = (int)($_POST['driver_id'] ?? 0);
                $deliveryDate = (string)($_POST['delivery_date'] ?? '');

                bakery_driver_remove_assignment($db, $dailyOrderId, $driverId, $deliveryDate);
                echo json_encode(['success' => true, 'message' => 'Stop unassigned; the dated order and quantities were kept']);
                break;

            case 'transfer_assignments':
                $toDriverId = (int)$_POST['to_driver_id'];
                $deliveryDate = $_POST['delivery_date'];
                $fromDriverId = isset($_POST['from_driver_id']) && $_POST['from_driver_id'] !== ''
                    ? (int)$_POST['from_driver_id']
                    : null;
                $dailyOrderIds = json_decode($_POST['daily_order_ids'] ?? '[]', true);
                if (!is_array($dailyOrderIds)) {
                    throw new Exception('Invalid daily_order_ids');
                }

                $result = bakery_driver_transfer_assignments(
                    $db,
                    $dailyOrderIds,
                    $toDriverId,
                    $deliveryDate,
                    $fromDriverId
                );

                $message = $result['transferred_count'] . ' stop'
                    . ($result['transferred_count'] === 1 ? '' : 's')
                    . ' moved to ' . $result['to_driver_name'];
                if (!empty($result['skipped'])) {
                    $message .= ' (' . count($result['skipped']) . ' skipped)';
                }

                echo json_encode([
                    'success' => true,
                    'message' => $message,
                    'transferred_count' => $result['transferred_count'],
                    'skipped' => $result['skipped'],
                ]);
                break;

            case 'save_as_standing_route':
                $deliveryDate = $_POST['delivery_date'];
                $dayOfWeek = date('N', strtotime($deliveryDate));
                $stmt = $db->prepare(" 
                    SELECT doa.driver_id, do.customer_id, doa.route_order
                    FROM daily_order_assignments doa
                    JOIN daily_orders do ON do.id = doa.daily_order_id
                    WHERE doa.delivery_date = ? AND do.order_date = ?
                    ORDER BY doa.driver_id, doa.route_order, do.customer_id
                ");
                $stmt->execute([$deliveryDate, $deliveryDate]);
                $currentRoute = $stmt->fetchAll();
                if (empty($currentRoute)) {
                    throw new Exception('There are no dated route assignments to save for this day.');
                }

                $db->beginTransaction();
                try {
                    if ($dayOfWeek === 7) {
                        $db->prepare('DELETE FROM standing_routes WHERE day_of_week IN (0, 7)')->execute();
                    } else {
                        $db->prepare('DELETE FROM standing_routes WHERE day_of_week = ?')->execute([$dayOfWeek]);
                    }

                    $insertRoute = $db->prepare(" 
                        INSERT INTO standing_routes (day_of_week, driver_id, customer_id, route_order)
                        VALUES (?, ?, ?, ?)
                    ");
                    $nextOrderByDriver = [];
                    foreach ($currentRoute as $stop) {
                        $driverId = (int)$stop['driver_id'];
                        $nextOrderByDriver[$driverId] = ($nextOrderByDriver[$driverId] ?? 0) + 1;
                        $insertRoute->execute([
                            $dayOfWeek,
                            $driverId,
                            (int)$stop['customer_id'],
                            $nextOrderByDriver[$driverId]
                        ]);
                    }

                    $db->commit();
                    echo json_encode([
                        'success' => true,
                        'message' => 'Saved ' . count($currentRoute) . ' stops as the recurring ' . date('l', strtotime($deliveryDate)) . ' route.'
                    ]);
                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }
                break;

            case 'sync_driver_route':
                $driverId = (int)$_POST['driver_id'];
                $deliveryDate = $_POST['delivery_date'];
                $driverRow = bakery_get_driver_by_id($db, $driverId);
                if (!$driverRow) {
                    throw new Exception("Driver ID $driverId does not exist in the drivers table");
                }
                if ((int)($driverRow['archived'] ?? 0) === 1) {
                    throw new Exception('Cannot modify routes for an archived driver. Restore the driver first.');
                }

                $dayOfWeek = date('N', strtotime($deliveryDate));
                $stmt = $db->prepare(" 
                    SELECT sr.customer_id, c.name AS customer_name, sr.route_order
                    FROM standing_routes sr
                    JOIN customers c ON c.id = sr.customer_id
                    " . bakery_sfb_ops_origin_clause('c', $db) . "
                    WHERE sr.driver_id = ?
                      AND CASE WHEN sr.day_of_week = 0 THEN 7 ELSE sr.day_of_week END = ?
                    ORDER BY COALESCE(sr.route_order, 2147483647), c.name
                ");
                $stmt->execute([$driverId, $dayOfWeek]);
                $routeStops = $stmt->fetchAll();
                if (empty($routeStops)) {
                    throw new Exception('This driver has no standing-route stops for ' . date('l', strtotime($deliveryDate)) . '.');
                }

                bakery_generate_daily_orders_from_standing($db, $deliveryDate, [
                    'overwrite_changed' => false,
                    'record_event' => true,
                    'assign_routes' => false,
                ]);

                $findOrder = $db->prepare('SELECT id FROM daily_orders WHERE customer_id = ? AND order_date = ?');
                $createOrder = $db->prepare(
                    "INSERT IGNORE INTO daily_orders (customer_id, order_date, status, total_amount)
                     VALUES (?, ?, 'pending', 0)"
                );
                $assignments = [];
                foreach ($routeStops as $index => $stop) {
                    $findOrder->execute([$stop['customer_id'], $deliveryDate]);
                    $dailyOrderId = (int)$findOrder->fetchColumn();
                    if ($dailyOrderId <= 0) {
                        $createOrder->execute([$stop['customer_id'], $deliveryDate]);
                        $findOrder->execute([$stop['customer_id'], $deliveryDate]);
                        $dailyOrderId = (int)$findOrder->fetchColumn();
                    }
                    $assignments[] = [
                        'daily_order_id' => $dailyOrderId,
                        'route_order' => $index + 1,
                    ];
                }

                $result = bakery_driver_assign_orders($db, $driverId, $deliveryDate, $assignments, 'append');
                echo json_encode([
                    'success' => true,
                    'message' => 'Restored ' . $result['stop_count'] . ' missing standing-route stop'
                        . ($result['stop_count'] === 1 ? '' : 's') . ' for ' . $driverRow['name'] . '.'
                ]);
                break;
                
            case 'create_orders_and_assign':
                $deliveryDate = $_POST['delivery_date'];
                $result = bakery_driver_assign_from_standing_routes($db, $deliveryDate);
                echo json_encode([
                    'success' => true,
                    'message' => bakery_t('driver_assignment.build_success', [
                        'count' => $result['stop_count'],
                        'date' => date('l, F j, Y', strtotime($deliveryDate)),
                    ]),
                    'assignments' => $result['assignments'],
                    'demand' => $result['demand'],
                ]);
                break;

            case 'save_standing_route':
                $customerId = (int)$_POST['customer_id'];
                $dayOfWeek = bakery_normalize_standing_day((int)$_POST['day_of_week']);
                $driverId = (int)$_POST['driver_id'];

                if ($customerId <= 0) {
                    throw new Exception('Invalid customer ID');
                }
                if ($dayOfWeek < 1 || $dayOfWeek > 7) {
                    throw new Exception('Invalid day of week');
                }

                $dayClause = $dayOfWeek === 7 ? 'IN (0, 7)' : '= ?';
                $stmt = $db->prepare("DELETE FROM standing_routes WHERE customer_id = ? AND day_of_week $dayClause");
                $stmt->execute($dayOfWeek === 7 ? [$customerId] : [$customerId, $dayOfWeek]);

                if ($driverId > 0) {
                    $driverRow = bakery_get_driver_by_id($db, $driverId);
                    if (!$driverRow) {
                        throw new Exception('Invalid driver selected');
                    }
                    if ((int)($driverRow['archived'] ?? 0) === 1) {
                        throw new Exception('Cannot assign an archived driver to a standing route');
                    }
                    if ($dayOfWeek === 7) {
                        $db->prepare('DELETE FROM standing_routes WHERE customer_id = ? AND day_of_week = 0')
                            ->execute([$customerId]);
                    }
                    $stmt = $db->prepare('INSERT INTO standing_routes (driver_id, customer_id, day_of_week) VALUES (?, ?, ?)');
                    $stmt->execute([$driverId, $customerId, $dayOfWeek]);
                }

                echo json_encode(['success' => true]);
                break;

            case 'add_customer_to_route':
                $customerId = (int)$_POST['customer_id'];
                $driverId = (int)$_POST['driver_id'];
                $deliveryDate = $_POST['delivery_date'] ?? '';
                // A customer added from the dated route is a one-time stop unless
                // the dispatcher explicitly chooses to make it recurring.
                $saveStandingRoute = ($_POST['save_standing_route'] ?? '0') === '1';
                $applyPanDulce = $saveStandingRoute && ($_POST['apply_pan_dulce'] ?? '0') === '1';
                $standingOrderLines = json_decode($_POST['standing_order_lines'] ?? '[]', true);
                if (!is_array($standingOrderLines)) {
                    $standingOrderLines = [];
                }
                if (!$saveStandingRoute) {
                    $standingOrderLines = [];
                }

                $result = bakery_driver_add_customer_to_route(
                    $db,
                    $customerId,
                    $driverId,
                    $deliveryDate,
                    $saveStandingRoute,
                    $standingOrderLines,
                    $applyPanDulce
                );

                echo json_encode([
                    'success' => true,
                    'message' => $result['message'],
                    'daily_order_id' => $result['daily_order_id'],
                ]);
                break;

            case 'remove_daily_order':
                $dailyOrderId = (int)$_POST['daily_order_id'];
                $deliveryDate = $_POST['delivery_date'] ?? '';
                $confirmed = ($_POST['confirm_delivered'] ?? '0') === '1';
                $result = bakery_remove_empty_dated_order($db, $dailyOrderId, $deliveryDate, $confirmed);
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

// Get selected date (default to tomorrow)
$selectedDate = (string)($_GET['date'] ?? date('Y-m-d', strtotime('+1 day')));
try {
    $selectedDate = bakery_driver_validate_delivery_date($selectedDate);
} catch (RuntimeException $e) {
    $selectedDate = date('Y-m-d', strtotime('+1 day'));
}
$returnTarget = bakery_ops_return_resolve($_GET['return'] ?? null, $selectedDate);
$filterUnassigned = (string)($_GET['filter'] ?? '') === 'unassigned';
$attentionFailed = (string)($_GET['attention'] ?? '') === 'failed' || (string)($_GET['filter'] ?? '') === 'failed';
$attentionLabel = '';
if ($filterUnassigned) {
    $attentionLabel = function_exists('bakery_t') ? bakery_t('ops.attention.unassigned') : 'Showing unassigned orders only';
} elseif ($attentionFailed) {
    $attentionLabel = function_exists('bakery_t') ? bakery_t('ops.attention.failed') : 'Showing failed stops';
}
$pageReturnKey = $returnTarget['key'] ?? null;
$dayName = date('l', strtotime($selectedDate));
$dayOfWeek = date('N', strtotime($selectedDate)); // 1=Monday, 7=Sunday
$pageLoadError = null;
$drivers = [];
$dailyOrders = [];
$standingRoutes = [];
$ordersByDriver = [];
$unassignedOrders = [];
$otherUnassignedOrders = [];
$standingCustomerIds = [];
$routeSyncCountByDriver = [];
$standingRoutesByDriver = [];
$weeklyStandingRoutesByCustomer = [];
$driversById = [];
$weekDayLabels = bakery_day_names(true);
$activeCustomers = [];
$productsForStanding = [];
$assignedCustomerIdsToday = [];
$driverColors = [
    '#007bff', '#28a745', '#dc3545', '#fd7e14', '#6f42c1',
    '#20c997', '#ffc107', '#e83e8c', '#6c757d', '#17a2b8',
];

try {
    $drivers = bakery_get_drivers($db);
    foreach ($drivers as $index => $driver) {
        $driversById[(int)$driver['id']] = [
            'name' => $driver['name'],
            'color' => $driverColors[$index % count($driverColors)],
        ];
    }

    $stmt = $db->prepare("
        SELECT 
            do.*,
            c.name as customer_name,
            c.address,
            c.phone,
            c.deliver_by,
            c.deliver_after,
            COALESCE(c.delivery_time, 20) as delivery_time,
            c.latitude,
            c.longitude,
            doa.driver_id as assigned_driver_id,
            doa.route_order,
            doa.scheduled_delivery_time,
            doa.delivery_status,
            d.name as assigned_driver_name
        FROM daily_orders do
        JOIN customers c ON do.customer_id = c.id
        " . bakery_sfb_ops_origin_clause('c', $db) . "
        LEFT JOIN daily_order_assignments doa ON do.id = doa.daily_order_id AND doa.delivery_date = do.order_date
        LEFT JOIN drivers d ON doa.driver_id = d.id
        WHERE do.order_date = ?
        ORDER BY doa.route_order, c.name
    ");
    $stmt->execute([$selectedDate]);
    $dailyOrders = $stmt->fetchAll();

    $stmt = $db->prepare("
        SELECT 
            sr.customer_id,
            sr.driver_id,
            sr.route_order,
            c.name as customer_name,
            d.name as driver_name
        FROM standing_routes sr
        JOIN customers c ON sr.customer_id = c.id
        " . bakery_sfb_ops_origin_clause('c', $db) . "
        JOIN drivers d ON sr.driver_id = d.id
                        WHERE CASE WHEN sr.day_of_week = 0 THEN 7 ELSE sr.day_of_week END = ?
        ORDER BY d.name, COALESCE(sr.route_order, 2147483647), c.name
    ");
    $stmt->execute([$dayOfWeek]);
    $standingRoutes = $stmt->fetchAll();

    foreach ($dailyOrders as $order) {
        if ($order['assigned_driver_id']) {
            $driverId = $order['assigned_driver_id'];
            if (!isset($ordersByDriver[$driverId])) {
                $ordersByDriver[$driverId] = [
                    'driver_name' => $order['assigned_driver_name'],
                    'orders' => []
                ];
            }
            $ordersByDriver[$driverId]['orders'][] = $order;
        } else {
            $unassignedOrders[] = $order;
        }
    }

    if ($attentionFailed) {
        foreach ($ordersByDriver as &$bundle) {
            usort($bundle['orders'], static function ($a, $b) {
                $af = (($a['delivery_status'] ?? '') === 'failed') ? 0 : 1;
                $bf = (($b['delivery_status'] ?? '') === 'failed') ? 0 : 1;
                if ($af !== $bf) {
                    return $af <=> $bf;
                }
                return ((int)($a['route_order'] ?? 0)) <=> ((int)($b['route_order'] ?? 0));
            });
        }
        unset($bundle);
    }

    foreach ($standingRoutes as $route) {
        $standingCustomerIds[(int)$route['customer_id']] = true;
        $driverId = $route['driver_id'];
        if (!isset($standingRoutesByDriver[$driverId])) {
            $standingRoutesByDriver[$driverId] = [];
        }
        $standingRoutesByDriver[$driverId][] = $route;
    }

    foreach ($unassignedOrders as $order) {
        if (!isset($standingCustomerIds[(int)$order['customer_id']])) {
            $otherUnassignedOrders[] = $order;
        }
    }

    if (!empty($otherUnassignedOrders)) {
        $otherCustomerIds = array_values(array_unique(array_map(
            static fn($order) => (int)$order['customer_id'],
            $otherUnassignedOrders
        )));
        $placeholders = implode(',', array_fill(0, count($otherCustomerIds), '?'));
        $stmt = $db->prepare("
            SELECT sr.customer_id, sr.day_of_week, sr.driver_id, d.name AS driver_name
            FROM standing_routes sr
            LEFT JOIN drivers d ON d.id = sr.driver_id
            WHERE sr.customer_id IN ($placeholders)
        ");
        $stmt->execute($otherCustomerIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $routeRow) {
            $customerId = (int)$routeRow['customer_id'];
            $routeDay = bakery_normalize_standing_day((int)$routeRow['day_of_week']);
            $routeDriverId = (int)$routeRow['driver_id'];
            $driverInfo = $driversById[$routeDriverId] ?? null;
            $weeklyStandingRoutesByCustomer[$customerId][$routeDay] = [
                'driver_id' => $routeDriverId,
                'driver_name' => $routeRow['driver_name'] ?: '',
                'driver_color' => $driverInfo['color'] ?? '#6c757d',
            ];
        }
    }

    $assignedDriverByCustomer = [];
    foreach ($dailyOrders as $order) {
        if (!empty($order['assigned_driver_id'])) {
            $assignedDriverByCustomer[(int)$order['customer_id']] = (int)$order['assigned_driver_id'];
        }
    }
    foreach ($standingRoutes as $route) {
        $customerId = (int)$route['customer_id'];
        $driverId = (int)$route['driver_id'];
        if (($assignedDriverByCustomer[$customerId] ?? 0) !== $driverId) {
            $routeSyncCountByDriver[$driverId] = ($routeSyncCountByDriver[$driverId] ?? 0) + 1;
        }
    }

    foreach ($dailyOrders as $order) {
        if (!empty($order['assigned_driver_id'])) {
            $assignedCustomerIdsToday[(int)$order['customer_id']] = (int)$order['assigned_driver_id'];
        }
    }

    $activeCustomers = $db->query("
        SELECT c.id, c.name, c.address, c.zone
        FROM customers c
        WHERE c.is_active = 1
        ORDER BY
            CASE WHEN c.zone IS NULL OR c.zone = '' THEN 'ZZZ' ELSE c.zone END,
            c.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $productsForStanding = $db->query("
        SELECT p.id, p.name, COALESCE(pl.name, 'Other') AS product_line_name
        FROM products p
        LEFT JOIN dough_types dt ON p.dough_type_id = dt.id
        LEFT JOIN product_lines pl ON dt.product_line_id = pl.id
        ORDER BY product_line_name, p.name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $pageLoadError = $e->getMessage();
    error_log('driver_assignment.php load failed: ' . $e->getMessage());
}
if (!empty($pageLoadError)) {
    }

$pageExceptions = [];
if (empty($pageLoadError)) {
    try {
        $pageExceptions = bakery_ops_exceptions_for_date($db, $selectedDate, $pageReturnKey);
    } catch (Throwable $e) {
        error_log('driver_assignment exceptions: ' . $e->getMessage());
    }
}

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>

<!-- BUILD: driver-assignment-append-20260801 -->

<?php if ($pageLoadError): ?>
<div class="container" style="padding:1.5rem;">
  <div class="alert alert-danger" style="background:#f8d7da;color:#721c24;padding:1rem;border-radius:6px;">
    <strong>Driver assignment failed to load:</strong>
    <?php echo htmlspecialchars($pageLoadError); ?>
  </div>
</div>
<?php require_once 'includes/footer.php'; exit; endif; ?>

<div class="container<?php echo $filterUnassigned ? ' driver-assignment-filter-active' : ''; ?><?php echo $attentionFailed ? ' driver-assignment-failed-active' : ''; ?>">
    <?php echo bakery_ops_render_return_banner($returnTarget, $attentionLabel); ?>
    <div class="page-header">
        <h1>🚚 Driver Assignment</h1>
        <div class="button-group">
            <button type="button" class="btn btn-primary" onclick="autoAssignFromStandingRoutes()">
                Build Route Plan
            </button>
            <?php if (!empty($ordersByDriver)): ?>
                <button type="button" class="btn btn-success" onclick="saveAsStandingRoute()">
                    Save This Route for <?= htmlspecialchars($dayName) ?>
                </button>
            <?php else: ?>
                <button type="button" class="btn btn-success" disabled title="Build the dated route first">
                    Save Route After Building
                </button>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary" onclick="showDatePicker()">
                Change Date
            </button>
        </div>
    </div>
    
    <!-- Date Navigation -->
    <div class="date-navigation">
        <div class="date-info">
            <h2>Assignments for <?= date('l, F j, Y', strtotime($selectedDate)) ?></h2>
            <div class="route-summary">
                <span class="route-count">Standing-route stops: <?= count($standingRoutes) ?></span>
                <span class="order-count">Daily order records: <?= count($dailyOrders) ?></span>
            </div>
        </div>
        <div class="date-controls">
            <a href="?<?php echo htmlspecialchars(http_build_query(bakery_ops_workflow_query(['date' => date('Y-m-d', strtotime($selectedDate . ' -1 day'))]))); ?>" class="btn btn-outline">← Previous Day</a>
            <a href="?<?php echo htmlspecialchars(http_build_query(bakery_ops_workflow_query(['date' => date('Y-m-d')]))); ?>" class="btn btn-primary">Today</a>
            <a href="?<?php echo htmlspecialchars(http_build_query(bakery_ops_workflow_query(['date' => date('Y-m-d', strtotime($selectedDate . ' +1 day'))]))); ?>" class="btn btn-outline">Next Day →</a>
        </div>
    </div>

    <div class="route-first-guide">
        <strong><?= htmlspecialchars(bakery_t('driver_assignment.workflow_title')) ?></strong>
        <span><?= htmlspecialchars(bakery_t('driver_assignment.workflow_help')) ?></span>
        <span class="guide-save">When the route is right, save it back to the weekday template for next week.</span>
        <?php if (!empty($standingRoutes)): ?>
            <span class="guide-status">Configured for this day: <?= count($standingRoutes) ?> stops across <?= count($standingRoutesByDriver) ?> drivers</span>
        <?php else: ?>
            <span class="guide-status warning">No standing-route stops are configured for this weekday.</span>
        <?php endif; ?>
    </div>
    
    <?php if (empty($drivers)): ?>
        <div class="empty-state">
            <h3>No drivers configured</h3>
            <p>Add drivers before assigning routes.</p>
        </div>
    <?php else: ?>
        <?php if (empty($dailyOrders) && empty($standingRoutes)): ?>
            <div class="empty-state empty-state-compact">
                <p>No route stops yet for <?= htmlspecialchars($dayName) ?>.
                   Use <strong>+ Add One-Time Stop</strong> on a driver below to add someone directly,
                   or configure standing routes first.</p>
            </div>
        <?php endif; ?>
        <div class="route-plan-heading">
            <h2>Route Plan for <?= htmlspecialchars(date('F j', strtotime($selectedDate))) ?></h2>
            <p>Each driver section is a delivery route. Reorder stops here; use Daily Orders separately to manage products and quantities.</p>
        </div>
        <?php if ($filterUnassigned && !empty($unassignedOrders)): ?>
            <div class="unassigned-section ops-attention-row" id="unassigned-orders">
                <div class="unassigned-header">
                    <h3>Unassigned orders (<?= count($unassignedOrders) ?>)</h3>
                    <p class="unassigned-hint">These dated orders have no driver for this date. Build from standing or assign below.</p>
                </div>
                <div class="unassigned-orders">
                    <?php foreach ($unassignedOrders as $order): ?>
                        <div class="order-item unassigned-item ops-attention-row" id="order-<?= (int)$order['id'] ?>" data-order-id="<?= (int)$order['id'] ?>" data-customer-id="<?= (int)$order['customer_id'] ?>">
                            <div class="order-info">
                                <div class="customer-name"><?= bakery_customer_record_link_html((int)$order['customer_id'], $order['customer_name'], $selectedDate) ?>
                                <?php
                                echo bakery_ops_render_row_chips($pageExceptions, [
                                    'customer_id' => (int)$order['customer_id'],
                                    'daily_order_id' => (int)$order['id'],
                                    'flags' => ['unassigned' => true],
                                ], ['date' => $selectedDate, 'return' => (string)$pageReturnKey, 'daily_order_id' => (int)$order['id']]);
                                ?>
                                </div>
                                <div class="customer-address"><?= htmlspecialchars($order['address']) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="driver-assignments">
            <?php foreach ($drivers as $driver): ?>
                <?php
                $standingDriverRoutes = $standingRoutesByDriver[$driver['id']] ?? [];
                $missingRouteCount = $routeSyncCountByDriver[$driver['id']] ?? 0;
                $driverOrders = $ordersByDriver[$driver['id']] ?? ['orders' => []];
                ?>
                <div class="driver-section" data-driver-id="<?= $driver['id'] ?>">
                    <div class="driver-header">
                        <h3><?= htmlspecialchars($driver['name']) ?></h3>
                        <div class="driver-controls">
                            <?php if ($missingRouteCount > 0): ?>
                                <button class="btn btn-sm btn-warning" onclick="assignFromStandingRoutes(<?= $driver['id'] ?>)">
                                    Restore <?= $missingRouteCount ?> missing stop<?= $missingRouteCount === 1 ? '' : 's' ?>
                                </button>
                            <?php endif; ?>
                            <?php if (!empty($driverOrders['orders'])): ?>
                                <select class="driver-select move-all-select" id="move-all-select-<?= $driver['id'] ?>" title="Move all stops to another driver">
                                    <option value="">Move all to…</option>
                                    <?php foreach ($drivers as $otherDriver): ?>
                                        <?php if ((int)$otherDriver['id'] !== (int)$driver['id']): ?>
                                            <option value="<?= (int)$otherDriver['id'] ?>"><?= htmlspecialchars($otherDriver['name']) ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="moveAllStops(<?= $driver['id'] ?>)">
                                    Move all
                                </button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-outline-primary" onclick="openAddCustomerModal(<?= $driver['id'] ?>)">
                                + Add One-Time Stop
                            </button>
                            <button class="btn btn-sm btn-primary" onclick="optimizeRoute(<?= $driver['id'] ?>)">
                                🚀 Optimize Route
                            </button>
                            <button class="btn btn-sm btn-secondary" onclick="editAssignments(<?= $driver['id'] ?>)">
                                ✏️ Edit
                            </button>
                        </div>
                    </div>
                    
                    <div class="driver-orders">
                        <div class="driver-stop-summary">
                            <?= count($driverOrders['orders']) ?> assigned stop<?= count($driverOrders['orders']) === 1 ? '' : 's' ?>
                            <?php if (!empty($standingDriverRoutes)): ?>
                                · <?= count($standingDriverRoutes) ?> standing-route stop<?= count($standingDriverRoutes) === 1 ? '' : 's' ?>
                            <?php endif; ?>
                            <?php if (!empty($driverOrders['orders'])): ?>
                                · <span class="route-time-estimate" title="Calculating route times…"></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="orders-list">
                            <div class="route-order-list<?= empty($driverOrders['orders']) ? ' route-order-list-empty' : '' ?>" data-driver-id="<?= $driver['id'] ?>">
                            <?php if (empty($driverOrders['orders'])): ?>
                                <div class="no-orders no-orders-inline">
                                    <?php if (!empty($standingDriverRoutes)): ?>
                                        <p class="route-stop-count"><?= count($standingDriverRoutes) ?> standing-route stop<?= count($standingDriverRoutes) === 1 ? '' : 's' ?> configured</p>
                                        <?php if (empty($dailyOrders)): ?>
                                            <p>Click <strong>Build Route Plan</strong> above to create the dated route.</p>
                                        <?php elseif ($missingRouteCount > 0): ?>
                                            <p>Use <strong>Restore <?= $missingRouteCount ?> missing stop<?= $missingRouteCount === 1 ? '' : 's' ?></strong> above to sync this route.</p>
                                        <?php else: ?>
                                            <p class="drop-hint">Drop stops here to assign to this driver</p>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <p>No standing-route stops for this driver on <?= htmlspecialchars($dayName) ?>.</p>
                                        <p class="drop-hint">Drop stops here to assign to this driver</p>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                    <?php foreach ($driverOrders['orders'] as $order): ?>
                                        <?php
                                        $isLocked = in_array($order['delivery_status'] ?? '', ['delivered', 'in_transit'], true);
                                        ?>
                                        <div class="order-item<?= $isLocked ? ' order-item-locked' : '' ?><?= (($order['delivery_status'] ?? '') === 'failed') ? ' ops-attention-row' : '' ?>"
                                             id="order-<?= (int)$order['id'] ?>"
                                             data-order-id="<?= $order['id'] ?>"
                                             data-address="<?= htmlspecialchars($order['address']) ?>"
                                             data-delivery-minutes="<?= (int)$order['delivery_time'] ?>"
                                             data-deliver-by="<?= htmlspecialchars($order['deliver_by'] ?? '') ?>"
                                             data-deliver-after="<?= htmlspecialchars($order['deliver_after'] ?? '') ?>"
                                             data-delivery-status="<?= htmlspecialchars($order['delivery_status'] ?? 'pending') ?>"
                                             draggable="<?= $isLocked ? 'false' : 'true' ?>">
                                            <div class="drag-handle"><?= $isLocked ? '🔒' : '⋮⋮' ?></div>
                                            <div class="order-info">
                                                <div class="customer-name"><?= bakery_customer_record_link_html((int)$order['customer_id'], $order['customer_name'], $selectedDate) ?>
                                                <?php
                                                $orderFlags = [];
                                                if (($order['delivery_status'] ?? '') === 'failed') {
                                                    $orderFlags['failed_delivery'] = true;
                                                }
                                                echo bakery_ops_render_row_chips($pageExceptions, [
                                                    'customer_id' => (int)$order['customer_id'],
                                                    'daily_order_id' => (int)$order['id'],
                                                    'driver_id' => (int)($order['assigned_driver_id'] ?? 0),
                                                    'flags' => $orderFlags,
                                                ], ['date' => $selectedDate, 'return' => (string)$pageReturnKey, 'daily_order_id' => (int)$order['id']]);
                                                ?>
                                                </div>
                                                <div class="customer-address"><?= htmlspecialchars($order['address']) ?></div>
                                                <div class="order-details">
                                                    <span class="route-order">#<?= $order['route_order'] ?></span>
                                                    <span class="delivery-time"><?= $order['scheduled_delivery_time'] ?: 'TBD' ?></span>
                                                    <span class="order-amount">$<?= number_format($order['total_amount'], 2) ?></span>
                                                    <?php if ($isLocked): ?>
                                                        <span class="delivery-status-badge"><?= htmlspecialchars($order['delivery_status']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="order-actions">
                                                <?php if (!$isLocked): ?>
                                                    <select class="driver-select move-stop-select"
                                                            title="Move to another driver"
                                                            onchange="moveStopToDriver(<?= $order['id'] ?>, <?= $driver['id'] ?>, this)">
                                                        <option value="">Move to…</option>
                                                        <?php foreach ($drivers as $otherDriver): ?>
                                                            <?php if ((int)$otherDriver['id'] !== (int)$driver['id']): ?>
                                                                <option value="<?= (int)$otherDriver['id'] ?>"><?= htmlspecialchars($otherDriver['name']) ?></option>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php endif; ?>
                                                <?php if ($isLocked): ?>
                                                    <span class="route-stop-lock-note" title="Completed and in-transit stops stay on their recorded route">Locked</span>
                                                <?php else: ?>
                                                    <a class="btn btn-sm btn-outline-primary"
                                                       href="daily_orders.php?date=<?= urlencode($selectedDate) ?>&view=edit&review=all#order-<?= (int)$order['id'] ?>">
                                                        <?= htmlspecialchars(bakery_t('driver_assignment.edit_dated_order')) ?>
                                                    </a>
                                                    <button class="btn btn-sm btn-outline-danger" onclick="removeAssignmentFromDatabase(<?= $order['id'] ?>)">
                                                        <?= htmlspecialchars(bakery_t('driver_assignment.unassign_stop')) ?>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                            <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <!-- Unassigned Orders -->
            <?php if (!empty($otherUnassignedOrders)): ?>
                <div class="unassigned-section">
                    <div class="unassigned-header">
                        <h3>Other Daily Orders (<?= count($otherUnassignedOrders) ?>)</h3>
                        <p class="unassigned-hint"><?= htmlspecialchars(bakery_t('driver_assignment.other_orders_help', ['day' => $dayName])) ?></p>
                    </div>
                    <div class="bulk-assign-bar">
                        <label class="bulk-select-all">
                            <input type="checkbox" id="select-all-unassigned" onchange="toggleSelectAllUnassigned(this.checked)">
                            Select all
                        </label>
                        <span class="bulk-selected-count" id="bulk-selected-count">0 selected</span>
                        <select id="bulk-driver-select" class="driver-select">
                            <option value="">Assign selected to…</option>
                            <?php foreach ($drivers as $driver): ?>
                                <option value="<?= $driver['id'] ?>"><?= htmlspecialchars($driver['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-primary" onclick="assignSelectedToDriver()">
                            Assign selected
                        </button>
                    </div>
                    <div class="unassigned-week-header">
                        <div class="unassigned-week-header-store">Store</div>
                        <div class="unassigned-week-header-days">
                            <?php foreach ($weekDayLabels as $dayNum => $dayLabel): ?>
                                <div class="unassigned-week-header-day<?= (int)$dayNum === (int)$dayOfWeek ? ' is-current-day' : '' ?>">
                                    <?= htmlspecialchars($dayLabel) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="unassigned-week-header-actions">Actions</div>
                    </div>
                    <div class="unassigned-orders">
                        <?php foreach ($otherUnassignedOrders as $order): ?>
                            <?php
                            $customerId = (int)$order['customer_id'];
                            $customerWeeklyRoutes = $weeklyStandingRoutesByCustomer[$customerId] ?? [];
                            ?>
                            <div class="order-item unassigned-item" id="order-<?= (int)$order['id'] ?>" data-order-id="<?= $order['id'] ?>" data-customer-id="<?= $customerId ?>">
                                <label class="order-check">
                                    <input type="checkbox"
                                           class="unassigned-checkbox"
                                           value="<?= (int)$order['id'] ?>"
                                           onchange="updateBulkSelectedCount()">
                                </label>
                                <div class="order-info">
                                    <div class="customer-name"><?= bakery_customer_record_link_html((int)$order['customer_id'], $order['customer_name'], $selectedDate) ?>
                                    <?php
                                    echo bakery_ops_render_row_chips($pageExceptions, [
                                        'customer_id' => $customerId,
                                        'daily_order_id' => (int)$order['id'],
                                        'flags' => ['unassigned' => true],
                                    ], ['date' => $selectedDate, 'return' => (string)$pageReturnKey, 'daily_order_id' => (int)$order['id']]);
                                    ?>
                                    </div>
                                    <div class="customer-address"><?= htmlspecialchars($order['address']) ?></div>
                                    <div class="order-details">
                                        <span class="order-amount">$<?= number_format($order['total_amount'], 2) ?></span>
                                    </div>
                                </div>
                                <div class="weekly-standing-routes">
                                    <?php foreach ($weekDayLabels as $dayNum => $dayLabel): ?>
                                        <?php
                                        $routeInfo = $customerWeeklyRoutes[(int)$dayNum] ?? null;
                                        $selectedDriverId = $routeInfo['driver_id'] ?? 0;
                                        $dayClass = (int)$dayNum === (int)$dayOfWeek ? ' is-current-day' : '';
                                        $selectStyle = $selectedDriverId > 0
                                            ? 'background-color:' . htmlspecialchars($routeInfo['driver_color']) . ';color:#fff;border-color:transparent;'
                                            : '';
                                        ?>
                                        <div class="weekly-route-day<?= $dayClass ?>">
                                            <select class="standing-route-select driver-select"
                                                    data-customer-id="<?= $customerId ?>"
                                                    data-day="<?= (int)$dayNum ?>"
                                                    title="<?= htmlspecialchars($dayLabel) ?> standing route"
                                                    style="<?= $selectStyle ?>"
                                                    onchange="saveStandingRoute(this)">
                                                <option value="0">—</option>
                                                <?php foreach ($drivers as $driver): ?>
                                                    <option value="<?= (int)$driver['id'] ?>"<?= $selectedDriverId === (int)$driver['id'] ? ' selected' : '' ?>>
                                                        <?= htmlspecialchars($driver['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="unassigned-row-actions">
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="daily_orders.php?date=<?= urlencode($selectedDate) ?>&view=edit&review=all#order-<?= (int)$order['id'] ?>">
                                        <?= htmlspecialchars(bakery_t('driver_assignment.edit_dated_order')) ?>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Route Optimization Modal -->
<div id="route-modal" class="modal-overlay" style="display: none;">
    <div class="modal">
        <div class="modal-header">
            <h3>Route Optimization</h3>
            <button class="close-btn" onclick="closeRouteModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="route-map" style="height: 400px; width: 100%; margin-bottom: 20px;"></div>
            <div id="route-info"></div>
            <div id="route-list"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-primary" onclick="saveOptimizedRoute()">Save Route</button>
            <button class="btn btn-secondary" onclick="closeRouteModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- Add Customer to Route Modal -->
<div id="add-customer-modal" class="modal-overlay" style="display: none;">
    <div class="modal add-customer-modal">
        <div class="modal-header">
            <h3>Add a One-Time Stop</h3>
            <button type="button" class="close-btn" onclick="closeAddCustomerModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p class="add-customer-intro">
                Choose the customer and they will be added to this driver's route for
                <?= htmlspecialchars(date('l, F j', strtotime($selectedDate))) ?> only.
            </p>
            <input type="hidden" id="add-customer-driver-id" value="">
            <label class="add-customer-field">
                <span>Driver</span>
                <select id="add-customer-driver-select" class="driver-select">
                    <?php foreach ($drivers as $driver): ?>
                        <option value="<?= (int)$driver['id'] ?>"><?= htmlspecialchars($driver['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="add-customer-field">
                <span>Customer</span>
                <input type="search" id="add-customer-search" class="add-customer-search"
                       placeholder="Search by name, address, or zone…" autocomplete="off">
                <select id="add-customer-select" size="8" required>
                    <?php foreach ($activeCustomers as $customer): ?>
                        <?php
                        $custId = (int)$customer['id'];
                        $assignedDriverId = $assignedCustomerIdsToday[$custId] ?? 0;
                        $statusHint = $assignedDriverId > 0
                            ? ' (on route today)'
                            : '';
                        $searchBlob = strtolower(trim(
                            ($customer['name'] ?? '') . ' '
                            . ($customer['address'] ?? '') . ' '
                            . ($customer['zone'] ?? '')
                        ));
                        ?>
                        <option value="<?= $custId ?>"
                                data-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES) ?>"
                                data-assigned-driver="<?= $assignedDriverId ?>">
                            <?= htmlspecialchars($customer['name']) ?><?= $statusHint ?>
                            <?php if (!empty($customer['address'])): ?>
                                — <?= htmlspecialchars($customer['address']) ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="add-customer-checkbox">
                <input type="checkbox" id="add-customer-save-standing-route"
                       onchange="setAddCustomerStandingOptionsVisibility()">
                Also make this a recurring <?= htmlspecialchars($dayName) ?> stop
            </label>
            <div id="add-customer-standing-section" class="add-customer-standing-section" hidden>
                <div class="add-customer-standing-header">
                    <strong>Standing order for <?= htmlspecialchars($dayName) ?></strong>
                    <span class="add-customer-standing-hint">Optional — set products for future weeks too</span>
                </div>
                <div class="add-customer-standing-actions">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="applyPanDulceInAddModal()">
                        Apply Pan Dulce standard
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="addStandingOrderRow()">
                        + Add product
                    </button>
                </div>
                <div id="add-customer-standing-rows" class="add-customer-standing-rows"></div>
                <input type="hidden" id="add-customer-apply-pan-dulce" value="0">
            </div>
            <div id="add-customer-status" class="add-customer-status" aria-live="polite"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-primary" onclick="submitAddCustomerToRoute()">Add Stop to Today’s Route</button>
            <button type="button" class="btn btn-secondary" onclick="closeAddCustomerModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- Edit Assignments Modal -->
<div id="edit-modal" class="modal-overlay" style="display: none;">
    <div class="modal">
        <div class="modal-header">
            <h3>Edit Driver Assignments</h3>
            <button class="close-btn" onclick="closeEditModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="edit-assignments-content"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-primary" onclick="saveAssignments()">Save Changes</button>
            <button class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
        </div>
    </div>
</div>

<script>
const driverAssignmentConfig = {
    date: <?php echo bakery_json_for_html($selectedDate, '""'); ?>,
    mapsKey: <?php echo bakery_json_for_html(GOOGLE_MAPS_API_KEY, '""'); ?>,
    standingRoutes: <?php echo bakery_json_for_html($standingRoutes, '[]'); ?>,
    dailyOrders: <?php echo bakery_json_for_html($dailyOrders, '[]'); ?>,
    ordersByDriver: <?php echo bakery_json_for_html($ordersByDriver, '{}'); ?>,
    driversById: <?php echo bakery_json_for_html($driversById, '{}'); ?>,
    currentDayOfWeek: <?php echo (int)$dayOfWeek; ?>,
    dayName: <?php echo bakery_json_for_html($dayName, '""'); ?>,
    activeCustomers: <?php echo bakery_json_for_html($activeCustomers, '[]'); ?>,
    productsForStanding: <?php echo bakery_json_for_html($productsForStanding, '[]'); ?>,
    assignedCustomerIdsToday: <?php echo bakery_json_for_html($assignedCustomerIdsToday, '{}'); ?>
};

// Global variables
let currentDriverId = null;
let currentAssignments = [];
let map = null;
let directionsService = null;
let directionsRenderer = null;
let geocoder = null;
let markers = [];

const bakeryAddress = '484 5th Street, San Francisco, CA';
const routeStartMinutes = (6 * 60) + 40;

function formatDuration(totalMinutes) {
    const minutes = Math.max(0, Math.round(totalMinutes));
    const hours = Math.floor(minutes / 60);
    const remainder = minutes % 60;
    return hours ? hours + 'h ' + remainder + 'm' : remainder + 'm';
}

function formatClock(totalMinutes) {
    const minutesInDay = ((Math.round(totalMinutes) % 1440) + 1440) % 1440;
    const hours24 = Math.floor(minutesInDay / 60);
    const minutes = minutesInDay % 60;
    const suffix = hours24 >= 12 ? 'PM' : 'AM';
    const hours12 = hours24 % 12 || 12;
    return hours12 + ':' + String(minutes).padStart(2, '0') + ' ' + suffix;
}

function timeToMinutes(value) {
    if (!value) return null;
    const parts = String(value).split(':').map(Number);
    return Number.isFinite(parts[0]) ? (parts[0] * 60) + (parts[1] || 0) : null;
}

function minutesToTimeString(totalMinutes) {
    const minutesInDay = ((Math.round(totalMinutes) % 1440) + 1440) % 1440;
    const hours = Math.floor(minutesInDay / 60);
    const mins = minutesInDay % 60;
    return String(hours).padStart(2, '0') + ':' + String(mins).padStart(2, '0');
}

function mainViewStopData(element) {
    return {
        element,
        orderId: element.dataset.orderId,
        address: element.dataset.address,
        deliverBy: timeToMinutes(element.dataset.deliverBy),
        deliverAfter: timeToMinutes(element.dataset.deliverAfter),
        deliveryMinutes: Number(element.dataset.deliveryMinutes) || 20
    };
}

function getMainViewRouteStops(routeList) {
    return Array.from(routeList.querySelectorAll('.order-item')).map(mainViewStopData);
}

function calculateMainViewRouteSchedule(result, stops) {
    const legs = result.routes[0].legs;
    let currentMinutes = routeStartMinutes;
    const arrivals = [];
    const departures = [];
    stops.forEach((stop, index) => {
        currentMinutes += legs[index].duration.value / 60;
        if (stop.deliverAfter !== null && currentMinutes < stop.deliverAfter) {
            currentMinutes = stop.deliverAfter;
        }
        arrivals.push(currentMinutes);
        currentMinutes += stop.deliveryMinutes;
        departures.push(currentMinutes);
    });
    if (legs[stops.length]) {
        currentMinutes += legs[stops.length].duration.value / 60;
    }
    return {
        totalMinutes: currentMinutes - routeStartMinutes,
        arrivals,
        departures
    };
}

function updateMainViewRoutePresentation(routeList, exactSchedule) {
    const stops = getMainViewRouteStops(routeList);
    const driverSection = routeList.closest('.driver-section');
    const estimate = driverSection ? driverSection.querySelector('.route-time-estimate') : null;

    if (!stops.length) {
        if (estimate) {
            estimate.textContent = '';
            estimate.title = '';
        }
        return null;
    }

    let routineFinish = routeStartMinutes;
    const routineArrivals = [];
    stops.forEach(stop => {
        routineFinish += 10;
        if (stop.deliverAfter !== null && routineFinish < stop.deliverAfter) {
            routineFinish = stop.deliverAfter;
        }
        routineArrivals.push(routineFinish);
        routineFinish += stop.deliveryMinutes;
    });
    routineFinish += 10;

    const schedule = exactSchedule || {
        totalMinutes: routineFinish - routeStartMinutes,
        arrivals: routineArrivals
    };
    const isApprox = !exactSchedule;

    stops.forEach((stop, index) => {
        const timeSpan = stop.element.querySelector('.delivery-time');
        if (timeSpan) {
            timeSpan.textContent = (isApprox ? '≈ ' : '') + formatClock(schedule.arrivals[index]);
            timeSpan.classList.toggle('delivery-time-estimated', isApprox);
            timeSpan.classList.toggle('delivery-time-exact', !isApprox);
        }
    });

    if (estimate) {
        estimate.textContent = (isApprox ? '≈ ' : '') + formatDuration(schedule.totalMinutes)
            + ' · finishes ' + (isApprox ? '~' : '') + formatClock(routeStartMinutes + schedule.totalMinutes);
        estimate.title = (isApprox ? 'Routine estimate' : 'Directions estimate')
            + ' · starts 6:40 AM · finishes about ' + formatClock(routeStartMinutes + schedule.totalMinutes);
    }

    return schedule;
}

function fetchExactMainViewRouteTimes(routeList) {
    const stops = getMainViewRouteStops(routeList);
    if (!stops.length) {
        return Promise.resolve(null);
    }
    if (typeof google === 'undefined' || !google.maps) {
        return Promise.resolve(null);
    }

    const service = directionsService || new google.maps.DirectionsService();
    return new Promise(resolve => {
        service.route({
            origin: bakeryAddress,
            destination: bakeryAddress,
            waypoints: stops.map(stop => ({ location: stop.address, stopover: true })),
            optimizeWaypoints: false,
            travelMode: google.maps.TravelMode.DRIVING
        }, (result, status) => {
            if (status === 'OK') {
                resolve(calculateMainViewRouteSchedule(result, stops));
            } else {
                resolve(null);
            }
        });
    });
}

function refreshMainViewRouteTimes(routeList) {
    updateMainViewRoutePresentation(routeList, null);
    return fetchExactMainViewRouteTimes(routeList).then(schedule => {
        if (schedule) {
            updateMainViewRoutePresentation(routeList, schedule);
        }
        return schedule;
    });
}

function refreshAllMainViewRouteTimes() {
    const lists = document.querySelectorAll('.route-order-list');
    lists.forEach(list => refreshMainViewRouteTimes(list));
}

// Initialize Google Maps
function initMap() {
    if (typeof google === 'undefined' || typeof google.maps === 'undefined') {
        console.error('Google Maps API not loaded');
        return;
    }
    
    map = new google.maps.Map(document.getElementById('route-map'), {
        zoom: 12,
        center: { lat: 37.7749, lng: -122.4194 },
        mapTypeId: 'roadmap'
    });
    
    directionsService = new google.maps.DirectionsService();
    directionsRenderer = new google.maps.DirectionsRenderer({
        draggable: false,
        suppressMarkers: true
    });
    directionsRenderer.setMap(map);
    
    geocoder = new google.maps.Geocoder();
}

// Auto-assign from standing routes
function autoAssignFromStandingRoutes() {
    if (!confirm(<?= json_encode(bakery_t('driver_assignment.build_confirm')) ?>)) {
        return;
    }
    
    fetch('driver_assignment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=create_orders_and_assign&delivery_date=' + encodeURIComponent(driverAssignmentConfig.date)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload(); // Refresh page to show updated assignments
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error creating orders and assignments');
    });
}

function saveAsStandingRoute() {
    if (!confirm('Save the current dated routes as the recurring <?= addslashes($dayName) ?> route? This replaces the existing recurring route for this weekday and will affect future weeks.')) {
        return;
    }

    fetch('driver_assignment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=save_as_standing_route&delivery_date=' + encodeURIComponent(driverAssignmentConfig.date)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Could not save recurring route'));
        }
    })
    .catch(error => {
        console.error('Error saving recurring route:', error);
        alert('Error saving recurring route');
    });
}

// Assign specific driver from standing routes
function assignFromStandingRoutes(driverId) {
    if (!confirm('Restore this driver\'s dated route from the standing route?')) {
        return;
    }

    fetch('driver_assignment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=sync_driver_route&driver_id=' + encodeURIComponent(driverId)
            + '&delivery_date=' + encodeURIComponent(driverAssignmentConfig.date)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Could not restore route'));
        }
    })
    .catch(error => {
        console.error('Error restoring route:', error);
        alert('Error restoring route');
    });
}

// Optimize route for a driver (Constraint-aware, Route Tester logic)
function optimizeRoute(driverId) {
    currentDriverId = driverId;
    fetch('driver_assignment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_optimized_route&driver_id=' + driverId + '&delivery_date=' + encodeURIComponent(driverAssignmentConfig.date)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showConstraintAwareRouteModal(data.orders);
        } else {
            alert('Error getting route data: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error getting route data');
    });
}

// Show constraint-aware route optimization modal
function showConstraintAwareRouteModal(orders) {
    document.getElementById('route-modal').style.display = 'flex';
    if (!map) initMap();
    markers.forEach(marker => marker.setMap(null));
    markers = [];
    // Add bakery marker
    const bakeryLocation = { lat: 37.7749, lng: -122.4194 };
    const bakeryMarker = new google.maps.Marker({
        position: bakeryLocation,
        map: map,
        title: 'Bakery',
        label: '🏪'
    });
    markers.push(bakeryMarker);
    // Add customer markers
    orders.forEach((order, index) => {
        if (order.latitude && order.longitude && !isNaN(order.latitude) && !isNaN(order.longitude)) {
            // Use lat/lng if present and valid
            const marker = new google.maps.Marker({
                position: { lat: parseFloat(order.latitude), lng: parseFloat(order.longitude) },
                map: map,
                title: order.customer_name,
                label: (index + 1).toString()
            });
            markers.push(marker);
        } else if (order.address && typeof google !== 'undefined' && google.maps && typeof geocoder !== 'undefined') {
            // Use geocoder to convert address to coordinates
            geocoder.geocode({ address: order.address }, (results, status) => {
                console.log('Geocoding', order.address, 'Status:', status, 'Results:', results);
                if (status === 'OK' && results[0]) {
                    const marker = new google.maps.Marker({
                        position: results[0].geometry.location,
                        map: map,
                        title: order.customer_name,
                        label: (index + 1).toString()
                    });
                    markers.push(marker);
                } else {
                    console.warn(`Geocoding failed for ${order.customer_name}: ${order.address}`);
                }
            });
        }
    });
    // Start constraint-aware optimization
    optimizeConstraintAwareRoute(orders);
}

// Constraint-aware optimization logic (adapted from Route Tester)
function hasValidRouteCoordinates(order) {
    const latitude = Number(order.latitude);
    const longitude = Number(order.longitude);
    return Number.isFinite(latitude) && Number.isFinite(longitude)
        && latitude >= -90 && latitude <= 90
        && longitude >= -180 && longitude <= 180;
}

function routeLocationForOrder(order) {
    if (hasValidRouteCoordinates(order)) {
        return {
            lat: Number(order.latitude),
            lng: Number(order.longitude)
        };
    }
    return order.address;
}

function optimizeConstraintAwareRoute(orders) {
    if (!orders || orders.length === 0) {
        alert('No orders to optimize.');
        return;
    }
    // Validate addresses
    const invalidAddresses = orders.filter(o =>
        !hasValidRouteCoordinates(o) && (!o.address || o.address.trim().length < 10)
    );
    if (invalidAddresses.length > 0) {
        document.getElementById('route-info').innerHTML = `<div class="alert alert-danger">❌ Invalid addresses detected:<br><small>${invalidAddresses.map(o => o.customer_name + ': ' + o.address).join('<br>')}</small></div>`;
        return;
    }
    // Prepare waypoints
    const waypoints = orders.map(order => ({ location: routeLocationForOrder(order), stopover: true }));
    if (waypoints.length > 25) {
        document.getElementById('route-info').innerHTML = `<div class="alert alert-danger">❌ Too many stops (${waypoints.length}) for route optimization. Please reduce to 25 or fewer.</div>`;
        return;
    }
    const request = {
        origin: '484 5th Street, San Francisco, CA',
        destination: '484 5th Street, San Francisco, CA',
        waypoints: waypoints,
        optimizeWaypoints: true,
        travelMode: google.maps.TravelMode.DRIVING
    };
    directionsService.route(request, (result, status) => {
        if (status === 'OK') {
            fixConstraintViolations(result, orders);
        } else {
            document.getElementById('route-info').innerHTML = `<div class="alert alert-danger">❌ Route optimization failed: ${status}</div>`;
        }
    });
}

// Fix constraint violations iteratively
function fixConstraintViolations(result, orders) {
    // Google's optimized order
    const waypointOrder = result.routes[0].waypoint_order;
    let customerOrder = waypointOrder.map(index => orders[index]);
    let currentRoute = { customerOrder: customerOrder, result: result };
    iterativeConstraintFix(currentRoute, orders);
}

// Iterative constraint fixing (async)
function iterativeConstraintFix(currentRoute, orders) {
    const maxIterations = 50;
    let iteration = 0;
    let lastMovedCustomer = null;
    function processIteration() {
        iteration++;
        const routeWithTimes = calculateArrivalTimesForRoute(currentRoute.result, currentRoute.customerOrder);
        const violations = findConstraintViolationsInRoute(routeWithTimes);
        if (violations.length === 0 || iteration >= maxIterations) {
            // Done! Display final route
            displayConstraintAwareRoute(currentRoute.result, currentRoute.customerOrder, orders, routeWithTimes, violations);
            return;
        }
        // Find largest violation that can be moved earlier
        const fixable = violations.filter(v => {
            const idx = currentRoute.customerOrder.findIndex(c => c.daily_order_id === v.customer.daily_order_id);
            return idx > 0;
        });
        if (fixable.length === 0) {
            displayConstraintAwareRoute(currentRoute.result, currentRoute.customerOrder, orders, routeWithTimes, violations);
            return;
        }
        // Move the largest violation earlier
        const largest = fixable.reduce((a, b) => a.violationMinutes > b.violationMinutes ? a : b);
        moveCustomerOneStepEarlier(currentRoute.customerOrder, largest.customer, orders).then(({ newOrder }) => {
            getRouteForOrder(newOrder, orders).then(newRoute => {
                currentRoute = newRoute || currentRoute;
                processIteration();
            });
        });
    }
    processIteration();
}

// Calculate arrival times for each stop
function calculateArrivalTimesForRoute(result, customerOrder) {
    const route = result.routes[0];
    let currentTime = new Date();
    currentTime.setHours(6, 40, 0, 0); // 6:40 AM start
    return customerOrder.map((customer, idx) => {
        const leg = route.legs[idx];
        currentTime = new Date(currentTime.getTime() + (leg.duration.value * 1000));
        const arrivalTime = new Date(currentTime);
        const deliveryTime = customer.delivery_time || 20;
        currentTime = new Date(currentTime.getTime() + (deliveryTime * 60 * 1000));
        return { customer, arrivalTime, leg, routeIndex: idx };
    });
}

// Find constraint violations
function findConstraintViolationsInRoute(routeWithTimes) {
    const violations = [];
    routeWithTimes.forEach(stop => {
        const customer = stop.customer;
        const arrivalTime = stop.arrivalTime;
        if (customer.deliver_by) {
            const deadline = new Date();
            const [h, m] = customer.deliver_by.split(':');
            deadline.setHours(parseInt(h), parseInt(m), 0, 0);
            if (arrivalTime > deadline) {
                violations.push({
                    type: 'late',
                    customer: customer,
                    arrivalTime: arrivalTime,
                    deadline: deadline,
                    routeIndex: stop.routeIndex,
                    violationMinutes: Math.ceil((arrivalTime - deadline) / (1000 * 60))
                });
            }
        }
    });
    return violations;
}

// Move customer one step earlier
function moveCustomerOneStepEarlier(customerOrder, targetCustomer, orders) {
    return new Promise(resolve => {
        const idx = customerOrder.findIndex(c => c.daily_order_id === targetCustomer.daily_order_id);
        if (idx <= 0) return resolve({ newOrder: customerOrder });
        const newOrder = [...customerOrder];
        const [moved] = newOrder.splice(idx, 1);
        newOrder.splice(idx - 1, 0, moved);
        resolve({ newOrder });
    });
}

// Get route for a specific order
function getRouteForOrder(customerOrder, orders) {
    return new Promise(resolve => {
        const waypoints = customerOrder.map(order => ({ location: routeLocationForOrder(order), stopover: true }));
        const request = {
            origin: '484 5th Street, San Francisco, CA',
            destination: '484 5th Street, San Francisco, CA',
            waypoints: waypoints,
            optimizeWaypoints: false,
            travelMode: google.maps.TravelMode.DRIVING
        };
        directionsService.route(request, (result, status) => {
            if (status === 'OK') {
                resolve({ customerOrder: customerOrder, result: result });
            } else {
                resolve(null);
            }
        });
    });
}

// Display the constraint-aware route and violations
function displayConstraintAwareRoute(result, customerOrder, orders, routeWithTimes, violations) {
    directionsRenderer.setDirections(result);
    let routeList = `<div class='route-stops'><h4>Constraint-Aware Route Order</h4><div class='draggable-route-list'>`;
    routeWithTimes.forEach((stop, idx) => {
        const customer = stop.customer;
        const arrival = stop.arrivalTime.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
        let constraintStatus = '';
        if (customer.deliver_by) {
            const deadline = new Date();
            const [h, m] = customer.deliver_by.split(':');
            deadline.setHours(parseInt(h), parseInt(m), 0, 0);
            if (stop.arrivalTime > deadline) {
                const delay = Math.ceil((stop.arrivalTime - deadline) / (1000 * 60));
                constraintStatus = `<span style='color:red'>❌ Late by ${delay} min (Deadline: ${deadline.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })})</span>`;
            } else {
                const buffer = Math.floor((deadline - stop.arrivalTime) / (1000 * 60));
                constraintStatus = `<span style='color:green'>✅ On time (${buffer} min buffer)</span>`;
            }
        } else {
            constraintStatus = '<span style="color:gray">No delivery time constraint</span>';
        }
        routeList += `
            <div class="route-stop-item" draggable="true" data-index="${idx}" data-order-id="${customer.daily_order_id}">
                <div class="drag-handle">⋮⋮</div>
                <div class="stop-number">${idx + 1}</div>
                <div class="stop-content">
                    <strong>${customer.customer_name}</strong><br>
                    <small>${customer.address}</small><br>
                    🕐 Arrival: ${arrival}<br>
                    ${constraintStatus}
                </div>
            </div>
        `;
    });
    routeList += '</div></div>';
    if (violations.length > 0) {
        routeList += `<div class='alert alert-warning'>⚠️ Some stops are late due to constraints. Consider starting earlier or splitting the route.</div>`;
    } else {
        routeList += `<div class='alert alert-success'>🎉 All delivery time constraints satisfied!</div>`;
    }
    document.getElementById('route-info').innerHTML = routeList;
    
    // Setup drag and drop functionality
    setupDragAndDrop();
    
    // Prepare assignments for saving
    currentAssignments = customerOrder.map((order, idx) => ({
        daily_order_id: order.daily_order_id,
        driver_id: currentDriverId,
        route_order: idx + 1,
        scheduled_delivery_time: routeWithTimes[idx].arrivalTime.toTimeString().substring(0, 5)
    }));
}

// Setup drag and drop functionality for route reordering
function setupDragAndDrop() {
    const routeList = document.querySelector('.draggable-route-list');
    if (!routeList) return;
    
    let draggedItem = null;
    let draggedIndex = null;
    
    // Add event listeners to all draggable items
    const items = routeList.querySelectorAll('.route-stop-item');
    items.forEach(item => {
        item.addEventListener('dragstart', handleDragStart);
        item.addEventListener('dragend', handleDragEnd);
        item.addEventListener('dragover', handleDragOver);
        item.addEventListener('drop', handleDrop);
        item.addEventListener('dragenter', handleDragEnter);
        item.addEventListener('dragleave', handleDragLeave);
    });
    
    function handleDragStart(e) {
        draggedItem = this;
        draggedIndex = parseInt(this.dataset.index);
        this.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/html', this.outerHTML);
    }
    
    function handleDragEnd(e) {
        this.classList.remove('dragging');
        draggedItem = null;
        draggedIndex = null;
    }
    
    function handleDragOver(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
    }
    
    function handleDragEnter(e) {
        e.preventDefault();
        this.classList.add('drag-over');
    }
    
    function handleDragLeave(e) {
        this.classList.remove('drag-over');
    }
    
    function handleDrop(e) {
        e.preventDefault();
        this.classList.remove('drag-over');
        
        if (draggedItem === this) return;
        
        const dropIndex = parseInt(this.dataset.index);
        const items = Array.from(routeList.querySelectorAll('.route-stop-item'));
        
        // Reorder the items
        if (draggedIndex < dropIndex) {
            // Moving down
            this.parentNode.insertBefore(draggedItem, this.nextSibling);
        } else {
            // Moving up
            this.parentNode.insertBefore(draggedItem, this);
        }
        
        // Update data-index attributes and stop numbers
        const newItems = routeList.querySelectorAll('.route-stop-item');
        newItems.forEach((item, index) => {
            item.dataset.index = index;
            item.querySelector('.stop-number').textContent = index + 1;
        });
        
        // Update currentAssignments with new order
        updateAssignmentsFromDrag(newItems);
        
        // Recalculate route with new order
        recalculateRouteWithNewOrder(newItems);
    }
}

// Update assignments array after drag and drop
function updateAssignmentsFromDrag(newItems) {
    const newOrder = Array.from(newItems).map(item => {
        const orderId = item.dataset.orderId;
        return currentAssignments.find(assignment => assignment.daily_order_id == orderId);
    });
    
    // Update route_order based on new position
    newOrder.forEach((assignment, index) => {
        if (assignment) {
            assignment.route_order = index + 1;
        }
    });
    
    currentAssignments = newOrder;
}

// Recalculate route with new customer order
function recalculateRouteWithNewOrder(newItems) {
    const newCustomerOrder = Array.from(newItems).map(item => {
        const orderId = item.dataset.orderId;
        return currentAssignments.find(assignment => assignment.daily_order_id == orderId);
    }).filter(Boolean);
    
    if (newCustomerOrder.length === 0) return;
    
    // Get the original orders data
    const orders = currentAssignments.map(assignment => {
        return { daily_order_id: assignment.daily_order_id, address: '' }; // We'll need to get the full order data
    });
    
    // Recalculate route with new order
    getRouteForOrder(newCustomerOrder, orders).then(newRoute => {
        if (newRoute) {
            // Update the map with new route
            directionsRenderer.setDirections(newRoute.result);
            
            // Recalculate arrival times
            const routeWithTimes = calculateArrivalTimesForRoute(newRoute.result, newCustomerOrder);
            
            // Update assignments with new arrival times
            currentAssignments = newCustomerOrder.map((order, idx) => ({
                daily_order_id: order.daily_order_id,
                driver_id: currentDriverId,
                route_order: idx + 1,
                scheduled_delivery_time: routeWithTimes[idx].arrivalTime.toTimeString().substring(0, 5)
            }));
            
            // Update arrival times in the UI
            updateArrivalTimesInUI(routeWithTimes);
        }
    });
}

// Update arrival times in the UI after drag and drop
function updateArrivalTimesInUI(routeWithTimes) {
    const items = document.querySelectorAll('.route-stop-item');
    items.forEach((item, index) => {
        if (routeWithTimes[index]) {
            const arrival = routeWithTimes[index].arrivalTime.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
            const arrivalElement = item.querySelector('.stop-content');
            if (arrivalElement) {
                const arrivalText = arrivalElement.innerHTML.replace(/🕐 Arrival: [^<]+/, `🕐 Arrival: ${arrival}`);
                arrivalElement.innerHTML = arrivalText;
            }
        }
    });
}

// Save optimized route
function saveOptimizedRoute() {
    if (currentAssignments.length === 0) {
        alert('No route to save');
        return;
    }
    
    saveAssignments(currentAssignments);
    closeRouteModal();
}

// Close route modal
function closeRouteModal() {
    document.getElementById('route-modal').style.display = 'none';
    currentAssignments = [];
}

// Edit assignments for a driver
function editAssignments(driverId) {
    currentDriverId = driverId;
    
    // Get current assignments for this driver
    const driverOrders = driverAssignmentConfig.ordersByDriver[driverId] || { orders: [] };
    const allOrders = driverAssignmentConfig.dailyOrders;
    
    let content = `
        <div class="edit-assignments">
            <h4>Edit Assignments for ${driverOrders.driver_name || 'Driver'}</h4>
            <div class="assignments-list">
    `;
    
    driverOrders.orders.forEach(order => {
        const isLocked = ['delivered', 'in_transit'].includes(order.delivery_status || '');
        content += `
            <div class="assignment-item${isLocked ? ' assignment-item-locked' : ''}" data-order-id="${order.id}">
                <div class="assignment-info">
                    <div class="customer-name">${order.customer_name}</div>
                    <div class="customer-address">${order.address}</div>
                    ${isLocked ? `<div class="route-stop-lock-note">${order.delivery_status === 'delivered' ? 'Completed' : 'In transit'} — kept on this route</div>` : ''}
                </div>
                <div class="assignment-controls">
                    <input type="number" class="route-order-input" value="${order.route_order || 0}" 
                           placeholder="Route Order" min="1" ${isLocked ? 'disabled' : ''}>
                    <input type="time" class="delivery-time-input" value="${order.scheduled_delivery_time || ''}" 
                           placeholder="Delivery Time" ${isLocked ? 'disabled' : ''}>
                    ${isLocked ? '' : `<button class="btn btn-sm btn-outline-danger" onclick="removeAssignment(${order.id})">Remove</button>`}
                </div>
            </div>
        `;
    });
    
    content += `
            </div>
            <div class="add-assignment">
                <h5>Add Existing Daily Orders</h5>
                <select id="add-order-select" onchange="addAssignment(this.value)">
                    <option value="">Select order to add...</option>
    `;
    
    // Add unassigned orders
    const unassignedOrders = allOrders.filter(order => !order.assigned_driver_id);
    unassignedOrders.forEach(order => {
        content += `<option value="${order.id}">${order.customer_name} - ${order.address}</option>`;
    });
    
    content += `
                </select>
                <p class="add-customer-edit-hint">
                    Need a customer without a daily order?
                    <button type="button" class="btn-link" onclick="closeEditModal(); openAddCustomerModal(${driverId});">Add customer to route…</button>
                </p>
            </div>
        </div>
    `;
    
    document.getElementById('edit-assignments-content').innerHTML = content;
    document.getElementById('edit-modal').style.display = 'flex';
}

// Add assignment
function addAssignment(orderId) {
    if (!orderId) return;
    
    const allOrders = driverAssignmentConfig.dailyOrders;
    const order = allOrders.find(o => o.id == orderId);
    if (!order) return;
    
    const assignmentsList = document.querySelector('.assignments-list');
    const nextRouteOrder = assignmentsList.querySelectorAll('.assignment-item').length + 1;
    const assignmentItem = document.createElement('div');
    assignmentItem.className = 'assignment-item';
    assignmentItem.dataset.orderId = orderId;
    assignmentItem.innerHTML = `
        <div class="assignment-info">
            <div class="customer-name">${order.customer_name}</div>
            <div class="customer-address">${order.address}</div>
        </div>
        <div class="assignment-controls">
            <input type="number" class="route-order-input" value="${nextRouteOrder}" placeholder="Route Order" min="1">
            <input type="time" class="delivery-time-input" value="" placeholder="Delivery Time">
            <button class="btn btn-sm btn-outline-danger" onclick="removeAssignment(${orderId})">Remove</button>
        </div>
    `;
    
    assignmentsList.appendChild(assignmentItem);
    document.getElementById('add-order-select').value = '';
}

// Transfer one or more stops to another driver
function transferAssignments(dailyOrderIds, fromDriverId, toDriverId, options = {}) {
    toDriverId = parseInt(toDriverId, 10);
    fromDriverId = fromDriverId ? parseInt(fromDriverId, 10) : null;

    if (!toDriverId || toDriverId <= 0) {
        alert('Choose a destination driver');
        return Promise.reject(new Error('no_target_driver'));
    }

    const orderIds = (Array.isArray(dailyOrderIds) ? dailyOrderIds : [dailyOrderIds])
        .map(id => parseInt(id, 10))
        .filter(id => id > 0);

    if (orderIds.length === 0) {
        alert('No stops selected to move');
        return Promise.reject(new Error('no_orders'));
    }

    const confirmMessage = options.confirmMessage
        || ('Move ' + orderIds.length + ' stop' + (orderIds.length === 1 ? '' : 's') + ' to the selected driver?');
    if (options.skipConfirm !== true && !confirm(confirmMessage)) {
        return Promise.reject(new Error('cancelled'));
    }

    let body = 'action=transfer_assignments'
        + '&to_driver_id=' + toDriverId
        + '&delivery_date=' + encodeURIComponent(driverAssignmentConfig.date)
        + '&daily_order_ids=' + encodeURIComponent(JSON.stringify(orderIds));
    if (fromDriverId && fromDriverId > 0) {
        body += '&from_driver_id=' + fromDriverId;
    }

    return fetch('driver_assignment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: body
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (options.reload !== false) {
                location.reload();
            }
            return data;
        }
        throw new Error(data.error || 'Transfer failed');
    })
    .catch(error => {
        if (error.message === 'cancelled') {
            throw error;
        }
        console.error('Error:', error);
        alert('Error moving stops: ' + error.message);
        throw error;
    });
}

function moveStopToDriver(orderId, fromDriverId, selectEl) {
    const toDriverId = parseInt(selectEl.value, 10);
    if (!toDriverId || toDriverId <= 0) {
        return;
    }

    transferAssignments([orderId], fromDriverId, toDriverId)
        .catch(() => {
            selectEl.value = '';
        });
}

function moveAllStops(fromDriverId) {
    const selectEl = document.getElementById('move-all-select-' + fromDriverId);
    const toDriverId = parseInt(selectEl && selectEl.value, 10);
    if (!toDriverId || toDriverId <= 0) {
        alert('Choose a destination driver from the "Move all to…" dropdown');
        return;
    }

    const routeList = document.querySelector('.route-order-list[data-driver-id="' + fromDriverId + '"]');
    const orderIds = routeList
        ? Array.from(routeList.querySelectorAll('.order-item:not(.order-item-locked)')).map(item => parseInt(item.dataset.orderId, 10))
        : [];

    if (orderIds.length === 0) {
        alert('No movable stops on this driver');
        return;
    }

    transferAssignments(orderIds, fromDriverId, toDriverId, {
        confirmMessage: 'Move all ' + orderIds.length + ' stop' + (orderIds.length === 1 ? '' : 's') + ' to the selected driver?'
    }).catch(() => {
        if (selectEl) {
            selectEl.value = '';
        }
    });
}

// Remove assignment from main view (immediate database removal)
function removeAssignmentFromDatabase(orderId) {
    if (!confirm(<?= json_encode(bakery_t('driver_assignment.unassign_confirm')) ?>)) {
        return;
    }

    // Find the driver ID for this order
    const orderItem = document.querySelector(`[data-order-id="${orderId}"]`);
    const driverSection = orderItem.closest('.driver-section');
    const driverId = driverSection.dataset.driverId;

    if (!driverId) {
        alert('Could not determine driver ID');
        return;
    }

    // Remove the assignment from database
    fetch('driver_assignment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=remove_assignment&daily_order_id=' + orderId + '&driver_id=' + driverId + '&delivery_date=' + encodeURIComponent(driverAssignmentConfig.date)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload(); // Refresh page to show updated assignments
        } else {
            alert('Error removing assignment: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error removing assignment');
    });
}

// Remove assignment from edit modal (visual only)
function removeAssignment(orderId) {
    const assignmentItem = document.querySelector(`[data-order-id="${orderId}"]`);
    if (assignmentItem) {
        assignmentItem.remove();
    }
}

// Save assignments
// mode: 'replace' rewrites the driver's route; 'append' adds without clearing existing stops
function saveAssignments(assignments = null, mode = 'replace') {
    if (!assignments) {
        // Collect assignments from edit modal
        const assignmentItems = document.querySelectorAll('#edit-assignments-content .assignment-item');
        assignments = [];
        
        assignmentItems.forEach(item => {
            const orderId = item.dataset.orderId;
            const routeOrder = item.querySelector('.route-order-input').value;
            const deliveryTime = item.querySelector('.delivery-time-input').value;
            
            if (orderId && routeOrder) {
                assignments.push({
                    daily_order_id: orderId,
                    driver_id: currentDriverId,
                    route_order: routeOrder,
                    scheduled_delivery_time: deliveryTime || null
                });
            }
        });
        mode = 'replace';
    }
    
    // Use the driver_id from the first assignment if currentDriverId is not set
    const saveMode = mode === 'append' ? 'append' : 'replace';
    const driverId = currentDriverId || (assignments[0] && assignments[0].driver_id);
    if (!driverId) {
        alert('Choose a driver before saving');
        return;
    }
    if (assignments.length === 0 && saveMode === 'append') {
        alert('Check one or more stops to add');
        return;
    }
    if (assignments.length === 0 && !confirm('Clear every movable stop from this driver\'s route for this date?')) {
        return;
    }
    
    fetch('driver_assignment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=assign_orders'
            + '&mode=' + encodeURIComponent(saveMode)
            + '&driver_id=' + driverId
            + '&delivery_date=' + encodeURIComponent(driverAssignmentConfig.date)
            + '&assignments=' + encodeURIComponent(JSON.stringify(assignments))
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message || 'Assignments saved successfully');
            location.reload();
        } else {
            alert('Error saving assignments: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error saving assignments');
    });
}

// Close edit modal
function closeEditModal() {
    document.getElementById('edit-modal').style.display = 'none';
    currentDriverId = null;
}

function buildStandingProductOptions(selectedId) {
    const products = driverAssignmentConfig.productsForStanding || [];
    const byLine = {};
    products.forEach(p => {
        const line = p.product_line_name || 'Other';
        if (!byLine[line]) {
            byLine[line] = [];
        }
        byLine[line].push(p);
    });
    let html = '<option value="">Choose product…</option>';
    Object.keys(byLine).sort().forEach(line => {
        html += '<optgroup label="' + line.replace(/"/g, '&quot;') + '">';
        byLine[line].forEach(p => {
            const sel = parseInt(selectedId, 10) === parseInt(p.id, 10) ? ' selected' : '';
            html += '<option value="' + p.id + '"' + sel + '>' + p.name + '</option>';
        });
        html += '</optgroup>';
    });
    return html;
}

function resetAddCustomerModal() {
    document.getElementById('add-customer-search').value = '';
    document.getElementById('add-customer-save-standing-route').checked = false;
    document.getElementById('add-customer-apply-pan-dulce').value = '0';
    document.getElementById('add-customer-standing-rows').innerHTML = '';
    document.getElementById('add-customer-status').textContent = '';
    setAddCustomerStandingOptionsVisibility();
    filterAddCustomerOptions();
}

function setAddCustomerStandingOptionsVisibility() {
    const saveStandingRoute = document.getElementById('add-customer-save-standing-route')?.checked;
    const standingSection = document.getElementById('add-customer-standing-section');
    if (standingSection) {
        standingSection.hidden = !saveStandingRoute;
    }
    if (!saveStandingRoute) {
        document.getElementById('add-customer-apply-pan-dulce').value = '0';
    }
}

function openAddCustomerModal(driverId) {
    driverId = parseInt(driverId, 10);
    document.getElementById('add-customer-driver-id').value = driverId > 0 ? String(driverId) : '';
    const driverSelect = document.getElementById('add-customer-driver-select');
    if (driverSelect && driverId > 0) {
        driverSelect.value = String(driverId);
    }
    resetAddCustomerModal();
    document.getElementById('add-customer-modal').style.display = 'flex';
    window.setTimeout(() => document.getElementById('add-customer-search')?.focus(), 0);
}

function closeAddCustomerModal() {
    document.getElementById('add-customer-modal').style.display = 'none';
}

function filterAddCustomerOptions() {
    const searchEl = document.getElementById('add-customer-search');
    const selectEl = document.getElementById('add-customer-select');
    if (!searchEl || !selectEl) {
        return;
    }
    const query = searchEl.value.trim().toLowerCase();
    Array.from(selectEl.options).forEach(opt => {
        const blob = (opt.dataset.search || opt.textContent || '').toLowerCase();
        opt.hidden = query !== '' && !blob.includes(query);
    });
    const visible = Array.from(selectEl.options).filter(opt => !opt.hidden);
    if (visible.length > 0 && (!selectEl.value || selectEl.selectedOptions[0]?.hidden)) {
        selectEl.value = visible[0].value;
    }
}

function addStandingOrderRow(productId, quantity) {
    const container = document.getElementById('add-customer-standing-rows');
    const row = document.createElement('div');
    row.className = 'add-customer-standing-row';
    row.innerHTML = `
        <select class="standing-product-select">${buildStandingProductOptions(productId || '')}</select>
        <input type="number" class="standing-qty-input" min="1" step="1" value="${quantity || 1}">
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.add-customer-standing-row').remove()">Remove</button>
    `;
    container.appendChild(row);
    document.getElementById('add-customer-apply-pan-dulce').value = '0';
}

function applyPanDulceInAddModal() {
    document.getElementById('add-customer-standing-rows').innerHTML = '';
    document.getElementById('add-customer-apply-pan-dulce').value = '1';
    document.getElementById('add-customer-status').textContent =
        'Pan Dulce standard will be applied when you add this customer.';
}

function collectStandingOrderLines() {
    if (!document.getElementById('add-customer-save-standing-route')?.checked) {
        return [];
    }
    const lines = [];
    document.querySelectorAll('#add-customer-standing-rows .add-customer-standing-row').forEach(row => {
        const productId = parseInt(row.querySelector('.standing-product-select')?.value, 10);
        const quantity = parseInt(row.querySelector('.standing-qty-input')?.value, 10);
        if (productId > 0 && quantity > 0) {
            lines.push({ product_id: productId, quantity: quantity });
        }
    });
    return lines;
}

function submitAddCustomerToRoute() {
    const customerId = parseInt(document.getElementById('add-customer-select')?.value, 10);
    const driverId = parseInt(document.getElementById('add-customer-driver-select')?.value, 10);
    const saveStandingRoute = document.getElementById('add-customer-save-standing-route')?.checked;
    const applyPanDulce = saveStandingRoute
        && document.getElementById('add-customer-apply-pan-dulce')?.value === '1';
    const standingOrderLines = collectStandingOrderLines();
    const statusEl = document.getElementById('add-customer-status');

    if (!customerId || customerId <= 0) {
        alert('Choose a customer');
        return;
    }
    if (!driverId || driverId <= 0) {
        alert('Choose a driver');
        return;
    }

    const assignedDriverId = parseInt(
        driverAssignmentConfig.assignedCustomerIdsToday[String(customerId)]
            || driverAssignmentConfig.assignedCustomerIdsToday[customerId]
            || 0,
        10
    );
    if (assignedDriverId > 0 && assignedDriverId === driverId) {
        alert('This customer is already on this driver\'s route today.');
        return;
    }
    if (assignedDriverId > 0 && assignedDriverId !== driverId) {
        if (!confirm('This customer is already on another driver\'s route today. Move them to the selected driver?')) {
            return;
        }
    }

    if (statusEl) {
        statusEl.textContent = 'Adding customer…';
    }

    let body = 'action=add_customer_to_route'
        + '&customer_id=' + customerId
        + '&driver_id=' + driverId
        + '&delivery_date=' + encodeURIComponent(driverAssignmentConfig.date)
        + '&save_standing_route=' + (saveStandingRoute ? '1' : '0')
        + '&apply_pan_dulce=' + (applyPanDulce ? '1' : '0')
        + '&standing_order_lines=' + encodeURIComponent(JSON.stringify(standingOrderLines));

    fetch('driver_assignment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
            return;
        }
        throw new Error(data.error || 'Failed to add customer');
    })
    .catch(error => {
        console.error('Error:', error);
        if (statusEl) {
            statusEl.textContent = '';
        }
        alert('Error adding customer: ' + error.message);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const searchEl = document.getElementById('add-customer-search');
    if (searchEl) {
        searchEl.addEventListener('input', filterAddCustomerOptions);
    }
});

function getSelectedUnassignedOrderIds() {
    return Array.from(document.querySelectorAll('.unassigned-checkbox:checked'))
        .map(cb => parseInt(cb.value, 10))
        .filter(id => id > 0);
}

function updateBulkSelectedCount() {
    const countEl = document.getElementById('bulk-selected-count');
    if (!countEl) return;
    const n = getSelectedUnassignedOrderIds().length;
    countEl.textContent = n + ' selected';
    const selectAll = document.getElementById('select-all-unassigned');
    if (selectAll) {
        const all = document.querySelectorAll('.unassigned-checkbox');
        selectAll.checked = all.length > 0 && n === all.length;
        selectAll.indeterminate = n > 0 && n < all.length;
    }
}

function toggleSelectAllUnassigned(checked) {
    document.querySelectorAll('.unassigned-checkbox').forEach(cb => {
        cb.checked = !!checked;
    });
    updateBulkSelectedCount();
}

// Append checked unassigned orders to a driver (does not replace existing route)
function assignSelectedToDriver() {
    const driverSelect = document.getElementById('bulk-driver-select');
    const driverId = parseInt(driverSelect && driverSelect.value, 10);
    if (!driverId || driverId <= 0) {
        alert('Choose a driver first');
        return;
    }

    const orderIds = getSelectedUnassignedOrderIds();
    if (orderIds.length === 0) {
        alert('Check one or more unassigned customers first');
        return;
    }

    currentDriverId = driverId;
    const assignments = orderIds.map(orderId => ({
        daily_order_id: orderId,
        driver_id: driverId,
        route_order: 0,
        scheduled_delivery_time: null
    }));

    saveAssignments(assignments, 'append');
}

function updateStandingRouteSelectStyle(selectEl) {
    const driverId = parseInt(selectEl.value, 10);
    const driverInfo = driverAssignmentConfig.driversById[String(driverId)] || driverAssignmentConfig.driversById[driverId];
    if (driverId > 0 && driverInfo) {
        selectEl.style.backgroundColor = driverInfo.color;
        selectEl.style.color = '#fff';
        selectEl.style.borderColor = 'transparent';
    } else {
        selectEl.style.backgroundColor = '';
        selectEl.style.color = '';
        selectEl.style.borderColor = '';
    }
}

function saveStandingRoute(selectEl) {
    const customerId = parseInt(selectEl.dataset.customerId, 10);
    const dayOfWeek = parseInt(selectEl.dataset.day, 10);
    const driverId = parseInt(selectEl.value, 10);

    if (!customerId || !dayOfWeek) {
        alert('Could not determine customer or day');
        return;
    }

    selectEl.disabled = true;

    fetch('driver_assignment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=save_standing_route'
            + '&customer_id=' + customerId
            + '&day_of_week=' + dayOfWeek
            + '&driver_id=' + driverId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateStandingRouteSelectStyle(selectEl);
            if (dayOfWeek === driverAssignmentConfig.currentDayOfWeek) {
                location.reload();
            }
        } else {
            alert('Error saving standing route: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error saving standing route');
    })
    .finally(() => {
        selectEl.disabled = false;
    });
}

// Assign a single order to a driver without wiping that driver's existing stops
function assignToDriver(orderId, driverId) {
    driverId = parseInt(driverId, 10);
    
    if (!driverId || driverId <= 0) {
        return;
    }
    
    currentDriverId = driverId;
    
    const assignments = [{
        daily_order_id: orderId,
        driver_id: driverId,
        route_order: 0,
        scheduled_delivery_time: null
    }];
    
    saveAssignments(assignments, 'append');
}

// Show date picker
function showDatePicker() {
    const date = prompt('Enter date (YYYY-MM-DD):', driverAssignmentConfig.date);
    if (date) {
        window.location.href = `?date=${date}`;
    }
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    refreshAllMainViewRouteTimes();

    // Load Google Maps API with async, defer, and onload (no callback in URL)
    const script = document.createElement('script');
    script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(driverAssignmentConfig.mapsKey) + '&libraries=geometry';
    script.async = true;
    script.defer = true;
    script.onload = function() {
        if (typeof initMap === 'function') initMap();
        refreshAllMainViewRouteTimes();
    };
    document.head.appendChild(script);
    
    // Setup drag and drop for main view
    setupMainViewDragAndDrop();
    updateBulkSelectedCount();

});

// Setup drag and drop functionality for main driver assignment view
function setupMainViewDragAndDrop() {
    const routeLists = document.querySelectorAll('.route-order-list');
    let draggedItem = null;
    let draggedIndex = null;
    let sourceRouteList = null;
    let sourceDriverId = null;

    routeLists.forEach(routeList => {
        routeList.addEventListener('dragover', handleRouteListDragOver);
        routeList.addEventListener('drop', handleRouteListDrop);
        routeList.addEventListener('dragenter', handleRouteListDragEnter);
        routeList.addEventListener('dragleave', handleRouteListDragLeave);

        const items = routeList.querySelectorAll('.order-item[draggable="true"]');
        items.forEach(item => {
            item.addEventListener('dragstart', handleMainDragStart);
            item.addEventListener('dragend', handleMainDragEnd);
            item.addEventListener('dragover', handleMainDragOver);
            item.addEventListener('drop', handleMainDrop);
            item.addEventListener('dragenter', handleMainDragEnter);
            item.addEventListener('dragleave', handleMainDragLeave);
        });
    });

    function handleMainDragStart(e) {
        draggedItem = this;
        sourceRouteList = this.closest('.route-order-list');
        sourceDriverId = sourceRouteList ? sourceRouteList.dataset.driverId : null;
        draggedIndex = sourceRouteList
            ? Array.from(sourceRouteList.querySelectorAll('.order-item')).indexOf(this)
            : null;
        this.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', this.dataset.orderId || '');
    }

    function handleMainDragEnd() {
        this.classList.remove('dragging');
        routeLists.forEach(list => list.classList.remove('drag-over'));
        draggedItem = null;
        draggedIndex = null;
        sourceRouteList = null;
        sourceDriverId = null;
    }

    function handleMainDragOver(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
    }

    function handleRouteListDragOver(e) {
        if (!draggedItem) {
            return;
        }
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
    }

    function handleMainDragEnter(e) {
        e.preventDefault();
        if (draggedItem && draggedItem !== this) {
            this.classList.add('drag-over');
        }
    }

    function handleRouteListDragEnter(e) {
        if (!draggedItem) {
            return;
        }
        e.preventDefault();
        this.classList.add('drag-over');
    }

    function handleMainDragLeave() {
        this.classList.remove('drag-over');
    }

    function handleRouteListDragLeave(e) {
        if (!this.contains(e.relatedTarget)) {
            this.classList.remove('drag-over');
        }
    }

    function handleMainDrop(e) {
        e.preventDefault();
        e.stopPropagation();
        this.classList.remove('drag-over');

        if (!draggedItem || draggedItem === this) {
            return;
        }

        const targetRouteList = this.closest('.route-order-list');
        const targetDriverId = targetRouteList ? targetRouteList.dataset.driverId : null;

        if (targetRouteList && sourceRouteList && targetDriverId !== sourceDriverId) {
            moveStopBetweenDrivers(draggedItem, sourceRouteList, targetRouteList, this);
            return;
        }

        const dropIndex = Array.from(targetRouteList.querySelectorAll('.order-item')).indexOf(this);
        if (draggedIndex < dropIndex) {
            this.parentNode.insertBefore(draggedItem, this.nextSibling);
        } else {
            this.parentNode.insertBefore(draggedItem, this);
        }

        updateMainViewRouteNumbers(targetRouteList);
        saveMainViewOrder(targetDriverId, targetRouteList);
    }

    function handleRouteListDrop(e) {
        if (e.target.classList && e.target.classList.contains('order-item')) {
            return;
        }

        e.preventDefault();
        this.classList.remove('drag-over');

        if (!draggedItem || !sourceRouteList) {
            return;
        }

        const targetDriverId = this.dataset.driverId;
        if (targetDriverId !== sourceDriverId) {
            moveStopBetweenDrivers(draggedItem, sourceRouteList, this, null);
            return;
        }

        this.appendChild(draggedItem);
        updateMainViewRouteNumbers(this);
        saveMainViewOrder(targetDriverId, this);
    }

    function moveStopBetweenDrivers(item, fromList, toList, beforeItem) {
        const orderId = parseInt(item.dataset.orderId, 10);
        const fromDriverId = parseInt(fromList.dataset.driverId, 10);
        const toDriverId = parseInt(toList.dataset.driverId, 10);

        if (!orderId || !fromDriverId || !toDriverId || fromDriverId === toDriverId) {
            return;
        }

        const emptyPlaceholder = toList.querySelector('.no-orders-inline');
        if (emptyPlaceholder) {
            emptyPlaceholder.remove();
            toList.classList.remove('route-order-list-empty');
        }

        if (beforeItem) {
            toList.insertBefore(item, beforeItem);
        } else {
            toList.appendChild(item);
        }
        updateMainViewRouteNumbers(toList);

        const saveIndicator = document.createElement('div');
        saveIndicator.className = 'save-indicator';
        saveIndicator.textContent = 'Moving...';
        saveIndicator.style.cssText = 'position: absolute; top: 10px; right: 10px; background: #007bff; color: white; padding: 5px 10px; border-radius: 4px; font-size: 12px; z-index: 100;';
        const driverSection = toList.closest('.driver-section');
        driverSection.style.position = 'relative';
        driverSection.appendChild(saveIndicator);

        transferAssignments([orderId], fromDriverId, toDriverId, {
            skipConfirm: true,
            reload: false
        })
        .then(() => {
            saveIndicator.textContent = 'Moved!';
            saveIndicator.style.background = '#28a745';
            setTimeout(() => location.reload(), 600);
        })
        .catch(() => {
            fromList.appendChild(item);
            updateMainViewRouteNumbers(fromList);
            updateMainViewRouteNumbers(toList);
            if (saveIndicator.parentNode) {
                saveIndicator.parentNode.removeChild(saveIndicator);
            }
        });
    }
}

// Update route order numbers in main view
function updateMainViewRouteNumbers(routeList) {
    const items = routeList.querySelectorAll('.order-item');
    items.forEach((item, index) => {
        const routeOrderSpan = item.querySelector('.route-order');
        if (routeOrderSpan) {
            routeOrderSpan.textContent = `#${index + 1}`;
        }
    });
}

// Save the new order from main view drag and drop
function saveMainViewOrder(driverId, routeList) {
    updateMainViewRoutePresentation(routeList, null);

    const driverSection = routeList.closest('.driver-section');
    const saveIndicator = document.createElement('div');
    saveIndicator.className = 'save-indicator';
    saveIndicator.textContent = 'Saving...';
    saveIndicator.style.cssText = 'position: absolute; top: 10px; right: 10px; background: #28a745; color: white; padding: 5px 10px; border-radius: 4px; font-size: 12px; z-index: 100;';
    driverSection.style.position = 'relative';
    driverSection.appendChild(saveIndicator);

    refreshMainViewRouteTimes(routeList).then(schedule => {
        const stops = getMainViewRouteStops(routeList);
        const assignments = stops.map((stop, index) => ({
            daily_order_id: stop.orderId,
            driver_id: parseInt(driverId, 10),
            route_order: index + 1,
            scheduled_delivery_time: schedule
                ? minutesToTimeString(schedule.arrivals[index])
                : null
        }));

        return fetch('driver_assignment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=assign_orders&driver_id=' + driverId + '&delivery_date=' + encodeURIComponent(driverAssignmentConfig.date) + '&assignments=' + encodeURIComponent(JSON.stringify(assignments))
        });
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            saveIndicator.textContent = 'Saved!';
            saveIndicator.style.background = '#28a745';
            setTimeout(() => {
                if (saveIndicator.parentNode) {
                    saveIndicator.parentNode.removeChild(saveIndicator);
                }
            }, 2000);
        } else {
            saveIndicator.textContent = 'Error!';
            saveIndicator.style.background = '#dc3545';
            setTimeout(() => {
                if (saveIndicator.parentNode) {
                    saveIndicator.parentNode.removeChild(saveIndicator);
                }
            }, 3000);
            console.error('Error saving order:', data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        saveIndicator.textContent = 'Error!';
        saveIndicator.style.background = '#dc3545';
        setTimeout(() => {
            if (saveIndicator.parentNode) {
                saveIndicator.parentNode.removeChild(saveIndicator);
            }
        }, 3000);
    });
}
</script>

<style>
.driver-assignments {
    margin-top: 20px;
}

.route-plan-heading {
    margin-top: 24px;
}

.route-plan-heading h2 {
    margin: 0 0 4px;
    color: #343a40;
}

.route-plan-heading p {
    margin: 0;
    color: #6c757d;
}

.route-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 6px;
}

.route-summary span {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 0.85rem;
    font-weight: 600;
}

.route-count {
    color: #155724;
    background: #d4edda;
}

.order-count {
    color: #495057;
    background: #e9ecef;
}

.route-first-guide {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 14px;
    padding: 14px 16px;
    margin-top: 18px;
    color: #495057;
    background: #eef6ff;
    border: 1px solid #b8daff;
    border-radius: 8px;
}

.route-first-guide strong {
    color: #004085;
}

.route-first-guide .guide-status {
    flex-basis: 100%;
    color: #155724;
    font-size: 0.9rem;
    font-weight: 600;
}

.route-first-guide .guide-status.warning {
    color: #856404;
}

.route-first-guide .guide-save {
    flex-basis: 100%;
    color: #155724;
    font-size: 0.9rem;
}

.driver-section {
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.driver-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    background: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
    border-radius: 8px 8px 0 0;
}

.driver-header h3 {
    margin: 0;
    color: #495057;
}

.driver-controls {
    display: flex;
    gap: 10px;
}

.driver-orders {
    padding: 20px;
}

.driver-stop-summary {
    margin-bottom: 12px;
    color: #6c757d;
    font-size: 0.9rem;
    font-weight: 600;
}

.route-time-estimate {
    color: #495057;
}

.no-orders {
    text-align: center;
    padding: 40px;
    color: #6c757d;
}

.route-stop-count {
    color: #155724;
    font-weight: 600;
}

.route-order-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-height: 48px;
}

.route-order-list-empty {
    border: 2px dashed #dee2e6;
    border-radius: 6px;
    padding: 12px;
    background: #fcfcfd;
}

.route-order-list.drag-over {
    border-color: #007bff;
    background: #f0f7ff;
}

.no-orders-inline {
    text-align: center;
    padding: 16px;
    color: #6c757d;
}

.no-orders-inline p {
    margin: 4px 0;
}

.drop-hint {
    font-size: 0.85rem;
    color: #adb5bd;
    font-style: italic;
}

.order-item-locked {
    cursor: default;
    opacity: 0.85;
}

.order-item-locked .drag-handle {
    cursor: default;
}

.route-stop-lock-note {
    color: #6c5a38;
    font-size: 0.8rem;
    font-weight: 600;
    white-space: nowrap;
}

.assignment-item-locked {
    background: #f7f4ed;
    border-color: #d8cdb8;
}

.assignment-item-locked input:disabled {
    background: #ece7dc;
    color: #6c6255;
    cursor: not-allowed;
}

.delivery-status-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 999px;
    background: #fff3cd;
    color: #856404;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: capitalize;
}

.order-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}

.move-stop-select,
.move-all-select {
    min-width: 130px;
    font-size: 0.85rem;
}

.driver-controls .move-all-select {
    max-width: 160px;
}

.order-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 6px;
    transition: all 0.2s ease;
    cursor: grab;
}

.order-item:hover {
    background: #e9ecef;
    border-color: #dee2e6;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0, 123, 255, 0.15);
}

.order-item.dragging {
    opacity: 0.5;
    transform: rotate(2deg);
    cursor: grabbing;
    z-index: 1000;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

.order-item.drag-over {
    border-color: #28a745;
    background: #d4edda;
    transform: scale(1.02);
}

.order-item .drag-handle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 25px;
    height: 25px;
    background: #6c757d;
    color: white;
    border-radius: 4px;
    margin-right: 12px;
    cursor: grab;
    font-size: 10px;
    font-weight: bold;
    user-select: none;
    flex-shrink: 0;
}

.order-item .drag-handle:hover {
    background: #495057;
}

.order-item.dragging .drag-handle {
    cursor: grabbing;
}

.order-info {
    flex: 1;
}

.customer-name {
    font-weight: 600;
    color: #495057;
    margin-bottom: 5px;
}

.customer-name a.customer-hub-link {
    color: inherit;
    text-decoration: none;
}

.customer-name a.customer-hub-link:hover {
    color: #0d6efd;
    text-decoration: underline;
}

.customer-address {
    font-size: 0.9em;
    color: #6c757d;
    margin-bottom: 5px;
}

.order-details {
    display: flex;
    gap: 15px;
    font-size: 0.85em;
}

.route-order {
    background: #007bff;
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-weight: 600;
}

.delivery-time {
    color: #28a745;
    font-weight: 500;
}

.delivery-time.delivery-time-estimated {
    color: #6c757d;
    font-style: italic;
    font-weight: 500;
}

.delivery-time.delivery-time-exact {
    color: #28a745;
    font-weight: 600;
}

.order-amount {
    color: #6c757d;
    font-weight: 500;
}

.driver-select {
    padding: 6px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    background: white;
    font-size: 0.9em;
}

.unassigned-section {
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 20px;
    margin-top: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.unassigned-header h3 {
    margin: 0 0 6px 0;
    color: #dc3545;
}

.unassigned-hint {
    margin: 0 0 14px 0;
    color: #6c757d;
    font-size: 0.95em;
}

.bulk-assign-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    margin-bottom: 14px;
    background: #fff8f8;
    border: 1px solid #f1c0c0;
    border-radius: 6px;
}

.bulk-select-all {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    color: #495057;
    cursor: pointer;
    margin: 0;
}

.bulk-selected-count {
    color: #6c757d;
    font-size: 0.9em;
    min-width: 5.5rem;
}

.bulk-assign-bar .driver-select {
    min-width: 180px;
}

.unassigned-orders {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.unassigned-week-header,
.order-item.unassigned-item {
    display: grid;
    grid-template-columns: auto minmax(220px, 1.2fr) minmax(420px, 2fr) auto;
    gap: 12px;
    align-items: center;
}

.unassigned-week-header {
    padding: 8px 12px;
    margin-bottom: 4px;
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 700;
    color: #495057;
}

.unassigned-week-header-store,
.unassigned-week-header-actions {
    text-align: center;
}

.unassigned-week-header-days,
.weekly-standing-routes {
    display: grid;
    grid-template-columns: repeat(7, minmax(72px, 1fr));
    gap: 6px;
}

.unassigned-week-header-day {
    text-align: center;
}

.unassigned-week-header-day.is-current-day,
.weekly-route-day.is-current-day .standing-route-select {
    box-shadow: 0 0 0 2px rgba(78, 115, 223, 0.35);
}

.weekly-route-day {
    min-width: 0;
}

.standing-route-select {
    width: 100%;
    min-width: 0;
    padding: 6px 4px;
    font-size: 0.78rem;
    text-overflow: ellipsis;
}

.standing-route-select:disabled {
    opacity: 0.7;
    cursor: wait;
}

.unassigned-row-actions {
    display: flex;
    justify-content: flex-end;
    min-width: 140px;
}

.order-item.unassigned-item {
    cursor: default;
    padding: 12px;
}

.order-item.unassigned-item:hover {
    transform: none;
}

.order-check {
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 12px;
    cursor: pointer;
}

.order-check input {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

@media (max-width: 640px) {
    .bulk-assign-bar {
        flex-direction: column;
        align-items: stretch;
    }

    .bulk-assign-bar .driver-select,
    .bulk-assign-bar .btn {
        width: 100%;
    }

    .unassigned-week-header {
        display: none;
    }

    .unassigned-week-header-days,
    .weekly-standing-routes {
        grid-template-columns: repeat(7, minmax(56px, 1fr));
    }

    .unassigned-week-header,
    .order-item.unassigned-item {
        grid-template-columns: auto 1fr;
        grid-template-areas:
            "check store"
            "routes routes"
            "actions actions";
    }

    .order-item.unassigned-item .order-check {
        grid-area: check;
    }

    .order-item.unassigned-item .order-info {
        grid-area: store;
    }

    .order-item.unassigned-item .weekly-standing-routes {
        grid-area: routes;
    }

    .order-item.unassigned-item .unassigned-row-actions {
        grid-area: actions;
        justify-content: stretch;
    }

    .order-item.unassigned-item .unassigned-row-actions .btn {
        width: 100%;
    }
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
    max-width: 800px;
    width: 90%;
    max-height: 90%;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    border-bottom: 1px solid #dee2e6;
}

.modal-header h3 {
    margin: 0;
}

.close-btn {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #6c757d;
}

.close-btn:hover {
    color: #495057;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 20px;
    border-top: 1px solid #dee2e6;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* Route Optimization Modal */
#route-modal {
    display: none;
}

#route-map {
    height: 400px;
    width: 100%;
    margin-bottom: 20px;
    border-radius: 8px;
}

/* Drag and Drop Styles */
.draggable-route-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 15px 0;
}

.route-stop-item {
    display: flex;
    align-items: center;
    padding: 15px;
    background: #f8f9fa;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    cursor: grab;
    transition: all 0.2s ease;
    position: relative;
}

.route-stop-item:hover {
    background: #e9ecef;
    border-color: #007bff;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0, 123, 255, 0.15);
}

.route-stop-item.dragging {
    opacity: 0.5;
    transform: rotate(2deg);
    cursor: grabbing;
    z-index: 1000;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

.route-stop-item.drag-over {
    border-color: #28a745;
    background: #d4edda;
    transform: scale(1.02);
}

.drag-handle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    background: #6c757d;
    color: white;
    border-radius: 4px;
    margin-right: 12px;
    cursor: grab;
    font-size: 12px;
    font-weight: bold;
    user-select: none;
}

.drag-handle:hover {
    background: #495057;
}

.route-stop-item.dragging .drag-handle {
    cursor: grabbing;
}

.stop-number {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 35px;
    height: 35px;
    background: #007bff;
    color: white;
    border-radius: 50%;
    margin-right: 12px;
    font-weight: bold;
    font-size: 14px;
    user-select: none;
}

.stop-content {
    flex: 1;
    line-height: 1.4;
}

.stop-content strong {
    color: #495057;
    font-size: 16px;
}

.stop-content small {
    color: #6c757d;
    font-size: 13px;
}

/* Route optimization styles */
.route-summary {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.route-summary h4 {
    margin-top: 0;
    color: #495057;
}

.route-stats {
    display: flex;
    gap: 20px;
    margin: 10px 0;
}

.stat {
    background: #007bff;
    color: white;
    padding: 5px 12px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: 600;
}

.optimization-info {
    margin-top: 15px;
}

.copy-route-section {
    margin-top: 15px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.copy-route-btn {
    background: #28a745;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
}

.copy-route-btn:hover {
    background: #218838;
}

.copy-status {
    font-size: 12px;
    color: #6c757d;
}

.route-order {
    list-style: none;
    padding: 0;
    margin: 0;
}

.route-stop {
    padding: 15px;
    margin-bottom: 10px;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    background: white;
}

.route-stop.bakery {
    background: #fff3cd;
    border-color: #ffeaa7;
}

.route-stop.customer {
    background: #f8f9fa;
}

.route-stop.violation {
    border-left: 4px solid #dc3545;
    background: #f8d7da;
}

.constraint-violation {
    color: #dc3545;
    font-weight: 600;
    margin-top: 5px;
}

.constraint-ok {
    color: #28a745;
    font-weight: 600;
    margin-top: 5px;
}

.constraint-early {
    color: #ffc107;
    font-weight: 600;
    margin-top: 5px;
}

.no-constraints {
    color: #6c757d;
    font-style: italic;
    margin-top: 5px;
}

.manual-route-controls {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #dee2e6;
}

.lock-stop-btn, .move-up-btn, .move-down-btn, .move-to-btn {
    background: #6c757d;
    color: white;
    border: none;
    padding: 4px 8px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
}

.lock-stop-btn:hover, .move-up-btn:hover, .move-down-btn:hover, .move-to-btn:hover {
    background: #495057;
}

.move-to-input {
    width: 50px;
    padding: 4px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    text-align: center;
}

.move-to-controls {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    color: #6c757d;
}

.constraints-summary, .violations-summary {
    margin-top: 20px;
    padding: 15px;
    border-radius: 8px;
}

.constraints-summary {
    background: #d4edda;
    border: 1px solid #c3e6cb;
}

.violations-summary {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
}

.violations-summary h4 {
    color: #721c24;
    margin-top: 0;
}

.violations-list {
    margin: 10px 0;
    padding-left: 20px;
}

.violations-list li {
    color: #721c24;
    margin-bottom: 5px;
}

.suggestions {
    margin-top: 15px;
}

.suggestions ul {
    margin: 10px 0;
    padding-left: 20px;
}

.suggestions li {
    color: #721c24;
    margin-bottom: 5px;
}

.save-indicator {
    position: absolute;
    top: 10px;
    right: 10px;
    background: #28a745;
    color: white;
    padding: 5px 10px;
    border-radius: 4px;
    font-size: 12px;
    z-index: 100;
    animation: fadeInOut 0.3s ease-in;
}

@keyframes fadeInOut {
    0% { opacity: 0; transform: translateY(-10px); }
    100% { opacity: 1; transform: translateY(0); }
}

.empty-state-compact {
    margin-bottom: 1rem;
    padding: 0.85rem 1rem;
    background: #fff3cd;
    border: 1px solid #ffeeba;
    border-radius: 6px;
}

.empty-state-compact p {
    margin: 0;
    color: #856404;
}

.add-customer-modal {
    max-width: 640px;
}

.add-customer-intro {
    color: #555;
    margin-top: 0;
    margin-bottom: 1rem;
    font-size: 0.95rem;
}

.add-customer-field {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    margin-bottom: 1rem;
}

.add-customer-field > span {
    font-weight: 600;
    font-size: 0.9rem;
}

.add-customer-search {
    width: 100%;
    padding: 0.5rem 0.65rem;
    border: 1px solid #ced4da;
    border-radius: 4px;
}

#add-customer-select {
    width: 100%;
    min-height: 10rem;
    border: 1px solid #ced4da;
    border-radius: 4px;
}

.add-customer-checkbox {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 1rem;
    font-size: 0.95rem;
}

.add-customer-standing-section {
    border: 1px solid #e9ecef;
    border-radius: 6px;
    padding: 0.85rem;
    background: #f8f9fa;
}

.add-customer-standing-header {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    margin-bottom: 0.65rem;
}

.add-customer-standing-hint {
    color: #6c757d;
    font-size: 0.85rem;
    font-weight: normal;
}

.add-customer-standing-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 0.65rem;
}

.add-customer-standing-rows {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.add-customer-standing-row {
    display: grid;
    grid-template-columns: 1fr 5rem auto;
    gap: 0.5rem;
    align-items: center;
}

.add-customer-standing-row select,
.add-customer-standing-row input {
    width: 100%;
    padding: 0.35rem 0.5rem;
    border: 1px solid #ced4da;
    border-radius: 4px;
}

.add-customer-status {
    margin-top: 0.75rem;
    color: #155724;
    font-size: 0.9rem;
}

.add-customer-edit-hint {
    margin: 0.65rem 0 0;
    font-size: 0.9rem;
    color: #6c757d;
}

.btn-link {
    background: none;
    border: none;
    color: #007bff;
    padding: 0;
    cursor: pointer;
    text-decoration: underline;
    font: inherit;
}
</style>

<?php require_once 'includes/footer.php'; ?>
