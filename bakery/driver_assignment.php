<link rel="stylesheet" href="<?php echo bakery_asset_href('css/driver_assignment.css'); ?>">
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
window.__DRIVER_ASSIGNMENT__ = {
    selectedDate: <?php echo bakery_json_for_html($selectedDate, '""'); ?>,
    apiKey: <?php echo bakery_json_for_html(GOOGLE_MAPS_API_KEY, '""'); ?>,
    standingRoutes: <?php echo bakery_json_for_html($standingRoutes, '[]'); ?>,
    dailyOrders: <?php echo bakery_json_for_html($dailyOrders, '[]'); ?>,
    ordersByDriver: <?php echo bakery_json_for_html($ordersByDriver, '{}'); ?>,
    driversById: <?php echo bakery_json_for_html($driversById, '{}'); ?>,
    dayOfWeek: <?php echo (int)$dayOfWeek; ?>,
    dayName: <?php echo bakery_json_for_html($dayName, '""'); ?>,
    activeCustomers: <?php echo bakery_json_for_html($activeCustomers, '[]'); ?>,
    productsForStanding: <?php echo bakery_json_for_html($productsForStanding, '[]'); ?>,
    assignedCustomerIdsToday: <?php echo bakery_json_for_html($assignedCustomerIdsToday, '{}'); ?>,
    buildConfirm: <?php echo bakery_json_for_html(bakery_t('driver_assignment.build_confirm'), '""'); ?>,
    unassignConfirm: <?php echo bakery_json_for_html(bakery_t('driver_assignment.unassign_confirm'), '""'); ?>
};
</script>
<script src="<?php echo bakery_asset_href('includes/driver_assignment.js'); ?>" defer></script>




<?php require_once 'includes/footer.php'; ?>
