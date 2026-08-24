<?php
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/product_inventory.php';
require_once 'includes/customer_record.php';
require_once 'includes/sfb_origin.php';
require_once 'includes/exception_desk.php';
require_once 'includes/operational_exceptions.php';
require_once 'includes/product_pack_yields.php';
require_once 'includes/pack_list.php';

$page_title = bakery_t('page.pack_list');

// AJAX: toggle a pack check for the selected date.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_pack_check') {
    header('Content-Type: application/json');
    bakery_require_login();
    bakery_require_csrf();
    try {
        $checkDate = trim((string)($_POST['date'] ?? ''));
        $dateObj = DateTime::createFromFormat('!Y-m-d', $checkDate);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $checkDate) {
            throw new Exception('Invalid date');
        }
        $lineKey = trim((string)($_POST['line_key'] ?? ''));
        if (!bakery_pack_line_key_valid($lineKey)) {
            throw new Exception('Invalid line');
        }
        $checked = ($_POST['checked'] ?? '0') === '1';
        $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
        bakery_pack_set_checked($db, $checkDate, $lineKey, $checked, isset($user['id']) ? (int)$user['id'] : null);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['exception_desk_mutation'] ?? '') === 'flag_shortage') {
    bakery_require_role(['baker']);
    try {
        $notice = bakery_exception_desk_handle_baker_post($db);
        $date = trim((string)($_POST['date'] ?? ''));
        $view = in_array((string)($_GET['view'] ?? $_POST['view'] ?? 'product'), ['customer', 'route'], true)
            ? (string)($_GET['view'] ?? $_POST['view'] ?? 'product')
            : 'product';
        header('Location: pack_list.php?date=' . rawurlencode($date) . '&view=' . rawurlencode($view) . '&notice=' . rawurlencode((string)$notice));
        exit;
    } catch (Throwable $e) {
        $packDeskError = $e->getMessage();
    }
}

$days = bakery_day_names();

$defaultDate = date('Y-m-d', strtotime('+1 day'));

if (!function_exists('bakery_pack_list_date_for_day')) {
    /** Calendar date for a standing weekday, anchored from a reference date (default tomorrow). */
    function bakery_pack_list_date_for_day(int $standingDay, ?string $referenceDate = null): string {
        $ref = $referenceDate ?? date('Y-m-d', strtotime('+1 day'));
        $refDay = bakery_standing_day_from_date($ref);
        $standingDay = bakery_normalize_standing_day($standingDay);
        $delta = ($standingDay - $refDay + 7) % 7;
        return date('Y-m-d', strtotime($ref . " +{$delta} days"));
    }
}

// Date selection: calendar date is primary; ?day= shortcuts still work for backward compatibility.
if (isset($_GET['date'])) {
    $rawDate = trim((string)$_GET['date']);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate) && strtotime($rawDate) !== false) {
        $selectedDate = $rawDate;
    } else {
        $selectedDate = $defaultDate;
    }
} elseif (isset($_GET['day'])) {
    $selectedDate = bakery_pack_list_date_for_day(bakery_normalize_standing_day($_GET['day']));
} else {
    $selectedDate = $defaultDate;
}

$selectedDay = bakery_standing_day_from_date($selectedDate);
$viewRaw = (string)($_GET['view'] ?? $_POST['view'] ?? 'route');
$viewMode = in_array($viewRaw, ['product', 'customer', 'route'], true) ? $viewRaw : 'route';
$returnTarget = bakery_ops_return_resolve($_GET['return'] ?? null, $selectedDate);
$pageReturnKey = $returnTarget['key'] ?? null;
$attentionShortfall = (string)($_GET['attention'] ?? '') === 'shortfall';
$attentionLabel = $attentionShortfall
    ? (function_exists('bakery_t') ? bakery_t('ops.attention.shortfall') : 'Showing products with a finished-goods shortfall')
    : '';

$bakerProductIds = function_exists('bakery_baker_product_ids') ? bakery_baker_product_ids($db) : null;
$bakerProductClause = '';
$bakerBindValues = [];
if (is_array($bakerProductIds)) {
    if (empty($bakerProductIds)) {
        $bakerProductClause = ' AND 1 = 0';
    } else {
        $bakerProductClause = ' AND p.id IN (' . implode(',', array_fill(0, count($bakerProductIds), '?')) . ')';
        $bakerBindValues = $bakerProductIds;
    }
}

$isBaker = function_exists('bakery_user_has_role') && bakery_user_has_role(['baker']);
$isDriver = function_exists('bakery_user_has_role') && bakery_user_has_role(bakery_driver_route_roles());
$inventoryReady = bakery_inventory_ready($db);
$hasDailyOrders = false;
$lineItems = [];
$exceptions = [];
$availableByProduct = [];
$loadedByProduct = [];
$producedByProduct = [];
$doughByProduct = [];
$customerRouteById = []; // customer_id => [driver_id, driver_name, route_order]
$error = null;
$packDeskError = $packDeskError ?? null;
$packDeskNotice = !empty($_GET['notice']) ? substr(trim((string)$_GET['notice']), 0, 160) : null;

try {
    require_once __DIR__ . '/includes/demand_review.php';
    $lineItems = bakery_operating_demand_lines($db, $selectedDate);
    if ($isBaker && is_array($bakerProductIds)) {
        if (empty($bakerProductIds)) {
            $lineItems = [];
        } else {
            $allowed = array_flip($bakerProductIds);
            $lineItems = array_values(array_filter($lineItems, static function ($row) use ($allowed) {
                return isset($allowed[(int)$row['product_id']]);
            }));
        }
    }
    foreach ($lineItems as $row) {
        if (($row['source'] ?? '') === 'daily') {
            $hasDailyOrders = true;
        }
    }

    // Dough labels for packing sections (operating-demand lines leave this blank).
    $doughStmt = $db->query(
        'SELECT p.id, COALESCE(dt.name, \'\') AS dough_type_name
         FROM products p
         LEFT JOIN dough_types dt ON dt.id = p.dough_type_id'
    );
    foreach ($doughStmt->fetchAll(PDO::FETCH_ASSOC) as $doughRow) {
        $doughByProduct[(int)$doughRow['id']] = (string)$doughRow['dough_type_name'];
    }

    // Dated assignments win; standing routes fill gaps for customers not yet assigned today.
    if (table_exists($db, 'daily_order_assignments') && table_exists($db, 'daily_orders')) {
        $assignStmt = $db->prepare(
            'SELECT do.customer_id, doa.driver_id, d.name AS driver_name,
                    COALESCE(doa.route_order, 2147483647) AS route_order
             FROM daily_orders do
             JOIN daily_order_assignments doa
               ON doa.daily_order_id = do.id AND doa.delivery_date = do.order_date
             JOIN drivers d ON d.id = doa.driver_id
             WHERE do.order_date = ?
             ORDER BY doa.driver_id, COALESCE(doa.route_order, 2147483647), doa.id'
        );
        $assignStmt->execute([$selectedDate]);
        foreach ($assignStmt->fetchAll(PDO::FETCH_ASSOC) as $assign) {
            $cid = (int)$assign['customer_id'];
            if (isset($customerRouteById[$cid])) {
                continue;
            }
            $customerRouteById[$cid] = [
                'driver_id' => (int)$assign['driver_id'],
                'driver_name' => (string)$assign['driver_name'],
                'route_order' => (int)$assign['route_order'],
            ];
        }
    }
    if (table_exists($db, 'standing_routes')) {
        $dayClause = bakery_standing_day_in_clause($selectedDay);
        $standingRouteStmt = $db->prepare(
            "SELECT sr.customer_id, sr.driver_id, d.name AS driver_name,
                    COALESCE(sr.route_order, 2147483647) AS route_order
             FROM standing_routes sr
             JOIN drivers d ON d.id = sr.driver_id
             JOIN customers c ON c.id = sr.customer_id AND COALESCE(c.is_active, 1) = 1
                 " . bakery_sfb_ops_origin_clause('c', $db) . "
             WHERE sr.day_of_week {$dayClause['sql']}
             ORDER BY sr.driver_id, COALESCE(sr.route_order, 2147483647), sr.id"
        );
        $standingRouteStmt->execute($dayClause['values']);
        foreach ($standingRouteStmt->fetchAll(PDO::FETCH_ASSOC) as $route) {
            $cid = (int)$route['customer_id'];
            if (isset($customerRouteById[$cid])) {
                continue;
            }
            $customerRouteById[$cid] = [
                'driver_id' => (int)$route['driver_id'],
                'driver_name' => (string)$route['driver_name'],
                'route_order' => (int)$route['route_order'],
            ];
        }
    }

    if ($inventoryReady) {
        $invStmt = $db->prepare(
            'SELECT product_id, available_quantity, produced_quantity, loaded_quantity
             FROM product_inventory_days
             WHERE delivery_date = ?'
        );
        $invStmt->execute([$selectedDate]);
        foreach ($invStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (int)$row['product_id'];
            $availableByProduct[$pid] = (int)$row['available_quantity'];
            $loadedByProduct[$pid] = (int)($row['loaded_quantity'] ?? 0);
            $producedByProduct[$pid] = (int)$row['produced_quantity'];
        }
    }
} catch (Exception $e) {
    $error = bakery_t('pack_list.error_load', ['message' => $e->getMessage()]);
}

// Build grouped views and detect exceptions.
$byProduct = [];
$byCustomer = [];
$byRoute = []; // driver_id => section (0 = unassigned)
$productTotals = [];
$customerTotals = [];
$allLineKeys = [];
$lineKeysByDriver = [];
$totalUnits = 0;
$shortageProducts = [];
$shortageUnits = 0;

foreach ($lineItems as $row) {
    $customerId = (int)$row['customer_id'];
    $productId = (int)$row['product_id'];
    $qty = (int)$row['quantity'];
    $customerName = (string)$row['customer_name'];
    $customerZone = trim((string)($row['customer_zone'] ?? ''));
    $productName = (string)$row['product_name'];
    $doughType = (string)($row['dough_type_name'] ?? '');
    if ($doughType === '' && isset($doughByProduct[$productId])) {
        $doughType = $doughByProduct[$productId];
    }

    if ($qty <= 0) {
        continue;
    }

    $totalUnits += $qty;
    $productTotals[$productId] = ($productTotals[$productId] ?? 0) + $qty;
    $customerTotals[$customerId] = ($customerTotals[$customerId] ?? 0) + $qty;
    $lineKey = bakery_pack_line_key($customerId, $productId);
    $allLineKeys[$lineKey] = true;

    if (!isset($byProduct[$productId])) {
        $byProduct[$productId] = [
            'product_id' => $productId,
            'product_name' => $productName,
            'dough_type' => $doughType,
            'total' => 0,
            'customers' => [],
        ];
    }
    $byProduct[$productId]['total'] += $qty;
    $byProduct[$productId]['customers'][] = [
        'customer_id' => $customerId,
        'customer_name' => $customerName,
        'zone' => $customerZone,
        'quantity' => $qty,
        'line_key' => $lineKey,
    ];

    if (!isset($byCustomer[$customerId])) {
        $byCustomer[$customerId] = [
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'zone' => $customerZone,
            'total' => 0,
            'products' => [],
        ];
    }
    $byCustomer[$customerId]['total'] += $qty;
    $byCustomer[$customerId]['products'][] = [
        'product_id' => $productId,
        'product_name' => $productName,
        'dough_type' => $doughType,
        'quantity' => $qty,
        'line_key' => $lineKey,
    ];

    $routeMeta = $customerRouteById[$customerId] ?? null;
    $driverId = $routeMeta ? (int)$routeMeta['driver_id'] : 0;
    $driverName = $routeMeta
        ? (string)$routeMeta['driver_name']
        : bakery_t('pack_list.unassigned_route');
    $routeOrder = $routeMeta ? (int)$routeMeta['route_order'] : 2147483647;

    if (!isset($byRoute[$driverId])) {
        $byRoute[$driverId] = [
            'driver_id' => $driverId,
            'driver_name' => $driverName,
            'total' => 0,
            'customers' => [],
        ];
    }
    $byRoute[$driverId]['total'] += $qty;
    if (!isset($byRoute[$driverId]['customers'][$customerId])) {
        $byRoute[$driverId]['customers'][$customerId] = [
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'zone' => $customerZone,
            'route_order' => $routeOrder,
            'total' => 0,
            'products' => [],
        ];
    }
    $byRoute[$driverId]['customers'][$customerId]['total'] += $qty;
    $byRoute[$driverId]['customers'][$customerId]['products'][] = [
        'product_id' => $productId,
        'product_name' => $productName,
        'dough_type' => $doughType,
        'quantity' => $qty,
        'line_key' => $lineKey,
    ];
    $lineKeysByDriver[$driverId][$lineKey] = true;

    if (preg_match('/^Customer #\d+$/', $customerName)) {
        $exceptions[] = [
            'type' => 'missing_customer',
            'message' => bakery_t('pack_list.exception_missing_customer', ['name' => $customerName]),
        ];
    }
    if ($customerZone === '') {
        $exceptions[] = [
            'type' => 'missing_zone',
            'message' => bakery_t('pack_list.exception_missing_zone', ['name' => $customerName]),
        ];
    }
}

// Deduplicate exception messages.
$seenException = [];
$uniqueExceptions = [];
foreach ($exceptions as $ex) {
    $key = $ex['type'] . '|' . $ex['message'];
    if (isset($seenException[$key])) {
        continue;
    }
    $seenException[$key] = true;
    $uniqueExceptions[] = $ex;
}
$exceptions = $uniqueExceptions;

if (!$hasDailyOrders && !empty($lineItems)) {
    $exceptions[] = [
        'type' => 'standing_fallback',
        'message' => bakery_t('pack_list.exception_standing_fallback'),
    ];
}

if ($inventoryReady) {
    foreach ($productTotals as $productId => $required) {
        $available = ($availableByProduct[$productId] ?? 0) + ($loadedByProduct[$productId] ?? 0);
        if ($available < $required) {
            $short = $required - $available;
            $shortageUnits += $short;
            $productName = $byProduct[$productId]['product_name'] ?? bakery_t('ui.product_num', ['id' => $productId]);
            $shortageProducts[] = [
                'product_id' => $productId,
                'product_name' => $productName,
                'required' => $required,
                'available' => $available,
                'short' => $short,
            ];
            if (isset($byProduct[$productId])) {
                $byProduct[$productId]['short'] = $short;
            }
            $exceptions[] = [
                'type' => 'shortage',
                'message' => bakery_t('pack_list.exception_shortage', [
                    'product' => $productName,
                    'required' => $required,
                    'available' => $available,
                    'short' => $short,
                ]),
            ];
        }
    }
}

usort($byProduct, static function ($a, $b) use ($attentionShortfall) {
    if ($attentionShortfall) {
        $shortCmp = ((int)($b['short'] ?? 0)) <=> ((int)($a['short'] ?? 0));
        if ($shortCmp !== 0) {
            return $shortCmp;
        }
    }
    return strcasecmp($a['product_name'], $b['product_name']);
});
usort($byCustomer, static function ($a, $b) {
    return strcasecmp($a['customer_name'], $b['customer_name']);
});
usort($shortageProducts, static function ($a, $b) {
    return $b['short'] <=> $a['short'];
});

// Sort routes: named drivers A–Z, unassigned last. Stops by route_order then name.
$byRouteList = array_values($byRoute);
usort($byRouteList, static function ($a, $b) {
    $aUnassigned = ((int)$a['driver_id'] === 0) ? 1 : 0;
    $bUnassigned = ((int)$b['driver_id'] === 0) ? 1 : 0;
    if ($aUnassigned !== $bUnassigned) {
        return $aUnassigned <=> $bUnassigned;
    }
    return strcasecmp($a['driver_name'], $b['driver_name']);
});
foreach ($byRouteList as &$routeSection) {
    $customers = array_values($routeSection['customers']);
    usort($customers, static function ($a, $b) {
        if ($a['route_order'] !== $b['route_order']) {
            return $a['route_order'] <=> $b['route_order'];
        }
        return strcasecmp($a['customer_name'], $b['customer_name']);
    });
    $routeSection['customers'] = $customers;
    $productRoll = [];
    foreach ($customers as $stop) {
        foreach ($stop['products'] as $prodLine) {
            $pid = (int)$prodLine['product_id'];
            if (!isset($productRoll[$pid])) {
                $productRoll[$pid] = [
                    'product_id' => $pid,
                    'product_name' => (string)$prodLine['product_name'],
                    'dough_type' => (string)$prodLine['dough_type'],
                    'quantity' => 0,
                    'stores' => [],
                ];
            }
            $productRoll[$pid]['quantity'] += (int)$prodLine['quantity'];
            $productRoll[$pid]['stores'][] = [
                'customer_id' => (int)$stop['customer_id'],
                'customer_name' => (string)$stop['customer_name'],
                'zone' => (string)$stop['zone'],
                'quantity' => (int)$prodLine['quantity'],
                'line_key' => (string)$prodLine['line_key'],
            ];
        }
    }
    uasort($productRoll, static function ($a, $b) {
        return strcasecmp($a['product_name'], $b['product_name']);
    });
    $routeSection['product_totals'] = array_values($productRoll);
}
unset($routeSection);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pack_all') {
    bakery_require_login();
    bakery_require_csrf();
    $packDate = $selectedDate;
    $driverFilter = array_key_exists('driver_id', $_POST) && $_POST['driver_id'] !== ''
        ? (int)$_POST['driver_id']
        : null;
    $keys = [];
    if ($driverFilter === null) {
        $keys = array_keys($allLineKeys);
    } elseif (isset($lineKeysByDriver[$driverFilter])) {
        $keys = array_keys($lineKeysByDriver[$driverFilter]);
    }
    try {
        $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
        bakery_pack_mark_keys($db, $packDate, $keys, isset($user['id']) ? (int)$user['id'] : null);
        $qs = bakery_ops_workflow_query([
            'date' => $packDate,
            'view' => $viewMode,
            'packed' => '1',
        ]);
        header('Location: pack_list.php?' . http_build_query($qs));
        exit;
    } catch (Throwable $e) {
        $packDeskError = $e->getMessage();
    }
}

// Persisted check-offs for this date (survive refresh and are shared by all staff).
$checkedMap = [];
$packProgressReady = bakery_pack_progress_ready($db);
try {
    if ($packProgressReady) {
        $checkStmt = $db->prepare('SELECT line_key FROM pack_progress WHERE pack_date = ?');
        $checkStmt->execute([$selectedDate]);
        foreach ($checkStmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
            $checkedMap[$key] = true;
        }
    }
} catch (Exception $e) {
    error_log('pack_list progress: ' . $e->getMessage());
}

$totalPackLines = count($allLineKeys);
$packedLineCount = 0;
foreach (array_keys($allLineKeys) as $packKey) {
    if (isset($checkedMap[$packKey])) {
        $packedLineCount++;
    }
}
$packComplete = $packProgressReady && $totalPackLines > 0 && $packedLineCount >= $totalPackLines;
if ((string)($_GET['packed'] ?? '') === '1') {
    $packDeskNotice = bakery_t('pack_list.packed_notice');
}

$totalCustomers = count($byCustomer);
$totalProducts = count($byProduct);
$totalMadeUnits = array_sum($producedByProduct);
$orderSourceLabel = $hasDailyOrders ? bakery_t('pack_list.daily_orders') : bakery_t('pack_list.standing_schedule');
$dateLabel = $days[$selectedDay] . ', ' . date('M j, Y', strtotime($selectedDate));
$queryBase = http_build_query(bakery_ops_workflow_query(['date' => $selectedDate]));
$productionHref = 'production.php?' . http_build_query(bakery_ops_workflow_query(['date' => $selectedDate]));
$canOpenProductionCenter = !$isBaker && !$isDriver
    && function_exists('bakery_user_has_role')
    && bakery_user_has_role(['administrator', 'manager']);
$productionCenterHref = function_exists('bakery_ops_link_production_center')
    ? bakery_ops_link_production_center(
        date('Y-m-d', strtotime('monday this week', strtotime($selectedDate))),
        ['date' => $selectedDate],
        $pageReturnKey ?: 'pack_list'
    )
    : ('production_center.php?date=' . rawurlencode($selectedDate));
$workflowStages = [];
try {
    require_once __DIR__ . '/includes/production_workflow_strip.php';
    $workflowStages = bakery_production_workflow_kitchen_stages($db, $selectedDate);
} catch (Throwable $e) {
    error_log('pack_list workflow strip: ' . $e->getMessage());
}
$pageExceptions = [];
try {
    $pageExceptions = bakery_ops_exceptions_for_date($db, $selectedDate, $pageReturnKey);
} catch (Throwable $e) {
    error_log('pack_list exceptions: ' . $e->getMessage());
}

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/exception_desk.css'); ?>">

<style>
.pack-page {
    --pack-accent: var(--sf-primary, #1a6b63);
    --pack-accent-light: var(--sf-success-bg, #e8f4f2);
    --pack-warn: var(--sf-danger, #c0392b);
    --pack-warn-bg: var(--sf-danger-bg, #fdecea);
    --pack-ok: var(--sf-success, #1d6534);
    --pack-ok-bg: var(--sf-success-bg, #e7f6ea);
    --pack-qty-bg: var(--sf-brand, #173f3c);
    --pack-border: var(--sf-border, #d5e0dc);
    max-width: 1100px;
    margin: 0 auto;
    padding: 12px 14px 24px;
    font-family: var(--sf-font-sans);
}

.pack-toolbar {
    background: #f7faf9;
    border: 1px solid var(--pack-border);
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 14px;
}

.pack-toolbar__row {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 10px 14px;
    justify-content: space-between;
}

.pack-title {
    color: #173f3c;
    font-size: 1.35rem;
    font-weight: 760;
    margin: 0;
}

.pack-toolbar__actions {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.pack-btn {
    appearance: none;
    background: #fff;
    border: 1px solid #b8c9c3;
    border-radius: 8px;
    color: #23443f;
    cursor: pointer;
    font: inherit;
    font-size: .88rem;
    font-weight: 650;
    padding: 9px 14px;
    text-decoration: none;
}

.pack-btn:hover, .pack-btn:focus-visible {
    background: var(--pack-accent-light);
    border-color: var(--pack-accent);
    outline: none;
}

.pack-btn--primary {
    background: var(--pack-accent);
    border-color: var(--pack-accent);
    color: #fff;
}

.pack-btn--primary:hover, .pack-btn--primary:focus-visible {
    background: #14524c;
    color: #fff;
}

.pack-btn--active {
    background: var(--pack-accent);
    border-color: var(--pack-accent);
    color: #fff;
}

.pack-date-form {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 12px;
}

.pack-date-form label {
    color: #45615a;
    font-size: .88rem;
    font-weight: 650;
}

.pack-date-form input[type="date"] {
    border: 1px solid #b8c9c3;
    border-radius: 8px;
    font: inherit;
    padding: 9px 10px;
}

.pack-day-shortcuts {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 10px;
}

.pack-day-btn {
    background: #eef3f1;
    border: 1px solid #c8d8d2;
    border-radius: 999px;
    color: #34524c;
    font-size: .78rem;
    font-weight: 650;
    padding: 6px 11px;
    text-decoration: none;
}

.pack-day-btn.active, .pack-day-btn:hover {
    background: var(--pack-accent);
    border-color: var(--pack-accent);
    color: #fff;
}

.pack-meta {
    color: #5a716b;
    font-size: .86rem;
    margin: 8px 0 0;
}

.pack-meta strong { color: #23443f; }

.pack-source {
    background: #eef3f1;
    border-radius: 999px;
    color: #45615a;
    display: inline-block;
    font-size: .78rem;
    font-weight: 650;
    margin-left: 6px;
    padding: 2px 9px;
}

.pack-source--fallback {
    background: #fff3cd;
    color: #856404;
}

.pack-totals {
    display: grid;
    gap: 10px;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    margin-bottom: 14px;
}

.pack-total-card {
    background: #fff;
    border: 1px solid var(--pack-border);
    border-radius: 10px;
    padding: 12px 14px;
    text-align: center;
}

.pack-total-card--alert {
    background: var(--pack-warn-bg);
    border-color: #f1b8b3;
}

.pack-total-card--ok {
    background: var(--pack-ok-bg);
    border-color: #b8dfc4;
}

.pack-total-card__label {
    color: #61706c;
    display: block;
    font-size: .76rem;
    font-weight: 650;
    letter-spacing: .03em;
    text-transform: uppercase;
}

.pack-total-card__value {
    color: #173f3c;
    display: block;
    font-size: 1.65rem;
    font-weight: 800;
    line-height: 1.15;
    margin-top: 4px;
}

.pack-total-card--alert .pack-total-card__value { color: var(--pack-warn); }
.pack-total-card--ok .pack-total-card__value { color: var(--pack-ok); }

.pack-exceptions {
    background: var(--pack-warn-bg);
    border: 1px solid #f1b8b3;
    border-left: 4px solid var(--pack-warn);
    border-radius: 10px;
    margin-bottom: 14px;
    padding: 12px 14px;
}

.pack-exceptions h2 {
    color: var(--pack-warn);
    font-size: .95rem;
    margin: 0 0 8px;
}

.pack-exceptions ul {
    margin: 0;
    padding-left: 18px;
}

.pack-exceptions li {
    color: #7a2e28;
    font-size: .88rem;
    margin: 4px 0;
}

.pack-exceptions li.standing-fallback {
    color: #856404;
}

.pack-baker-help {
    margin-bottom: 14px;
    border: 1px solid var(--pack-border);
    border-radius: 10px;
    background: #f8faf9;
}

.pack-baker-help > summary {
    cursor: pointer;
    padding: 12px 14px;
    color: #42545a;
    font-weight: 700;
}

.pack-baker-help .exception-desk { margin: 0 12px 12px; }

.pack-inventory-bar {
    background: #fff;
    border: 1px solid var(--pack-border);
    border-radius: 10px;
    margin-bottom: 14px;
    overflow: hidden;
}

.pack-inventory-bar h2 {
    background: #f0f6f4;
    border-bottom: 1px solid var(--pack-border);
    color: #23443f;
    font-size: .92rem;
    margin: 0;
    padding: 10px 14px;
}

.pack-inventory-table {
    border-collapse: collapse;
    font-size: .88rem;
    width: 100%;
}

.pack-inventory-table th,
.pack-inventory-table td {
    border-bottom: 1px solid #e8efec;
    padding: 10px 12px;
    text-align: left;
}

.pack-inventory-table th {
    background: #fafcfb;
    color: #61706c;
    font-size: .76rem;
    text-transform: uppercase;
}

.pack-inventory-table .qty-req { font-weight: 750; }
.pack-inventory-table .qty-avail { color: #45615a; }
.pack-inventory-table tr.shortage td { background: #fff5f4; }
.pack-inventory-table .short-badge {
    background: var(--pack-warn);
    border-radius: 999px;
    color: #fff;
    font-size: .76rem;
    font-weight: 700;
    padding: 2px 8px;
}

.pack-view-toggle {
    display: flex;
    gap: 0;
    margin-bottom: 14px;
}

.pack-view-toggle a {
    background: #eef3f1;
    border: 1px solid #c8d8d2;
    color: #34524c;
    flex: 1;
    font-size: .9rem;
    font-weight: 700;
    padding: 12px;
    text-align: center;
    text-decoration: none;
}

.pack-view-toggle a:first-child { border-radius: 10px 0 0 10px; }
.pack-view-toggle a:last-child { border-radius: 0 10px 10px 0; }
.pack-view-toggle a:not(:first-child) { border-left: 0; }
.pack-view-toggle a.active {
    background: var(--pack-accent);
    border-color: var(--pack-accent);
    color: #fff;
}

.pack-section__header--route {
    background: #3d5a80;
}

.pack-section__header--unassigned {
    background: #6c757d;
}

.pack-stop {
    border-bottom: 1px solid #dfe8e4;
}

.pack-stop:last-child { border-bottom: 0; }

.pack-stop__header {
    align-items: center;
    background: #f4f8f6;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: space-between;
    padding: 10px 14px;
}

.pack-stop__name {
    color: #23443f;
    font-size: .95rem;
    font-weight: 720;
}

.pack-stop__units {
    color: #45615a;
    font-size: .82rem;
    font-weight: 650;
}

.pack-unassigned-hint {
    color: #fff;
    font-size: .8rem;
    font-weight: 500;
    margin: 4px 0 0;
    opacity: .92;
}

.pack-section {
    background: #fff;
    border: 1px solid var(--pack-border);
    border-radius: 12px;
    margin-bottom: 14px;
    overflow: hidden;
}

.pack-section__header {
    align-items: center;
    background: var(--pack-accent);
    color: #fff;
    display: flex;
    flex-wrap: wrap;
    gap: 8px 12px;
    justify-content: space-between;
    padding: 14px 16px;
}

.pack-section__header--customer {
    background: #2f6b8a;
}

.pack-section__title {
    font-size: 1.1rem;
    font-weight: 760;
    margin: 0;
}

.pack-section__subtitle {
    font-size: .82rem;
    opacity: .9;
}

.pack-section__totals {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.pack-qty-pill {
    background: rgba(255, 255, 255, .18);
    border: 1px solid rgba(255, 255, 255, .35);
    border-radius: 999px;
    font-size: .88rem;
    font-weight: 750;
    padding: 4px 12px;
}

.pack-qty-pill--big {
    background: #fff;
    color: var(--pack-qty-bg);
    font-size: 1.15rem;
    padding: 6px 14px;
}

.pack-qty-pill--short {
    background: #ffe0dc;
    border-color: #ffb4ab;
    color: var(--pack-warn);
}

.pack-section__body { padding: 0; }

.pack-line {
    align-items: center;
    border-bottom: 1px solid #edf2f0;
    display: flex;
    gap: 12px;
    min-height: 52px;
    padding: 10px 14px;
}

.pack-line:last-child { border-bottom: 0; }

.pack-line--checked {
    background: #f3faf6;
    opacity: .72;
}

.pack-line--checked .pack-line__label { text-decoration: line-through; }

.pack-check {
    align-items: center;
    background: #fff;
    border: 2px solid #9eb8b1;
    border-radius: 8px;
    cursor: pointer;
    display: inline-flex;
    flex: 0 0 34px;
    height: 34px;
    justify-content: center;
    width: 34px;
}

.pack-check:focus-visible {
    outline: 2px solid var(--pack-accent);
    outline-offset: 2px;
}

.pack-check.is-checked {
    background: var(--pack-ok-bg);
    border-color: var(--pack-ok);
    color: var(--pack-ok);
    font-weight: 800;
}

.pack-line__main {
    flex: 1 1 auto;
    min-width: 0;
}

.pack-line__label {
    color: #23443f;
    display: block;
    font-size: 1rem;
    font-weight: 650;
}

.pack-customer-link,
.pack-section__title .customer-hub-link {
    color: inherit;
    text-decoration: none;
}

.pack-customer-link:hover,
.pack-section__title .customer-hub-link:hover {
    color: #1d4f47;
    text-decoration: underline;
}

.pack-line__meta {
    color: #6a7f79;
    display: block;
    font-size: .8rem;
    margin-top: 2px;
}

.pack-line__qtywrap {
    align-items: flex-end;
    display: flex;
    flex: 0 0 auto;
    flex-direction: column;
    gap: 4px;
}

.pack-line__qty {
    background: var(--pack-qty-bg);
    border-radius: 10px;
    color: #fff;
    flex: 0 0 auto;
    font-size: 1.25rem;
    font-weight: 800;
    line-height: 1;
    min-width: 48px;
    padding: 10px 14px;
    text-align: center;
}

.pack-convert {
    color: #45615a;
    font-size: .75rem;
    font-weight: 650;
    max-width: 16rem;
    text-align: right;
}

.pack-complete-banner {
    background: var(--pack-ok-bg);
    border: 1px solid #b8dfc4;
    border-radius: 10px;
    margin-bottom: 14px;
    padding: 12px 14px;
}

.pack-complete-banner p { margin: 0 0 8px; color: #1d6534; font-weight: 700; }

.pack-driver-product {
    border-bottom: 1px solid #edf2f0;
}

.pack-driver-product > summary {
    align-items: center;
    cursor: pointer;
    display: flex;
    flex-wrap: wrap;
    gap: 8px 12px;
    justify-content: space-between;
    list-style: none;
    padding: 12px 14px;
}

.pack-driver-product > summary::-webkit-details-marker { display: none; }

.pack-driver-product__name {
    color: #23443f;
    font-size: 1.02rem;
    font-weight: 720;
}

.pack-driver-product__hint {
    color: #6a7f79;
    font-size: .8rem;
}

.pack-zone-badge {
    background: #6c757d;
    border-radius: 4px;
    color: #fff;
    display: inline-block;
    font-size: .72rem;
    font-weight: 650;
    margin-left: 6px;
    padding: 2px 6px;
    vertical-align: middle;
}

.pack-zone-badge--ruta { background: #e67e22; }

.pack-session-note {
    color: #6a7f79;
    font-size: .78rem;
    margin: 0 0 14px;
    text-align: center;
}

.pack-empty {
    color: #6a7f79;
    font-style: italic;
    padding: 40px 20px;
    text-align: center;
}

.pack-error {
    background: var(--pack-warn-bg);
    border: 1px solid #f1b8b3;
    border-radius: 8px;
    color: var(--pack-warn);
    margin-bottom: 14px;
    padding: 12px 14px;
}

@media (max-width: 720px) {
    .pack-page { padding: 8px 8px 20px; }
    .pack-toolbar__row { align-items: flex-start; flex-direction: column; }
    .pack-line__qty { font-size: 1.1rem; min-width: 42px; padding: 8px 10px; }
    .pack-section__header { padding: 12px; }
    .pack-total-card__value { font-size: 1.35rem; }
}

@media print {
    .bakery-nav, .pack-toolbar__actions, .pack-view-toggle, .pack-check,
    .pack-session-note, .pack-date-form, .pack-day-shortcuts, .auth-bar,
    footer, .pack-btn, .pack-all-form { display: none !important; }
    .pack-page { max-width: none; padding: 0; }
    .pack-toolbar { background: none; border: 0; padding: 0 0 8px; }
    .pack-section, .pack-inventory-bar, .pack-totals { break-inside: avoid; }
    .pack-line--checked { opacity: 1; }
    .pack-line--checked .pack-line__label { text-decoration: none; }
    .pack-section__header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>

<main class="pack-page" id="packPage" data-date="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>" data-csrf="<?php echo htmlspecialchars(bakery_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <?php echo bakery_ops_render_return_banner($returnTarget, $attentionLabel); ?>
    <?php if ($error): ?>
        <div class="pack-error" role="alert"><?php echo $error; ?></div>
    <?php endif; ?>
    <?php if (!empty($packDeskError)): ?>
        <div class="pack-error" role="alert"><?php echo htmlspecialchars($packDeskError, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if (!empty($packDeskNotice)): ?>
        <p class="exception-desk__reported" role="status"><?php echo htmlspecialchars($packDeskNotice, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <div class="pack-toolbar">
        <div class="pack-toolbar__row">
            <h1 class="pack-title"><?php bakery_te('pack_list.title'); ?></h1>
            <div class="pack-toolbar__actions">
                <?php if ($canOpenProductionCenter): ?>
                    <a class="pack-btn" href="<?php echo htmlspecialchars($productionCenterHref, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('pack_list.production_center'); ?></a>
                <?php endif; ?>
                <?php if ($isBaker): ?>
                    <a class="pack-btn" href="<?php echo htmlspecialchars($productionHref, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('pack_list.daily_production'); ?></a>
                <?php elseif (!$isDriver): ?>
                    <a class="pack-btn" href="<?php echo htmlspecialchars($productionHref, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('pack_list.daily_production'); ?></a>
                    <a class="pack-btn" href="driver_load.php?date=<?php echo urlencode($selectedDate); ?>"><?php bakery_te('pack_list.driver_loads'); ?></a>
                <?php endif; ?>
                <?php if ($packProgressReady && !empty($allLineKeys) && !$packComplete): ?>
                    <form method="post" class="pack-all-form">
                        <?php echo bakery_csrf_field(); ?>
                        <input type="hidden" name="action" value="pack_all">
                        <input type="hidden" name="view" value="<?php echo htmlspecialchars($viewMode, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" class="pack-btn pack-btn--primary"><?php bakery_te('pack_list.pack_all'); ?></button>
                    </form>
                <?php endif; ?>
                <button type="button" class="pack-btn" onclick="window.print()"><?php bakery_te('pack_list.print'); ?></button>
            </div>
        </div>
        <?php
        if (!$isBaker && function_exists('bakery_production_workflow_strip_css')) {
            echo bakery_production_workflow_strip_css();
            echo bakery_production_workflow_strip_html($workflowStages, [
                'current' => 'pack',
                'compact' => true,
                'title' => bakery_t('production_workflow.title'),
                'lead' => bakery_t('production_workflow.lead_packer'),
            ]);
        }
        ?>
        <form method="get" action="pack_list.php" class="pack-date-form">
            <input type="hidden" name="view" value="<?php echo htmlspecialchars($viewMode, ENT_QUOTES, 'UTF-8'); ?>">
            <label for="packDate"><?php bakery_te('pack_list.delivery_date'); ?></label>
            <input type="date" id="packDate" name="date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>" onchange="this.form.submit()">
            <?php if ($pageReturnKey): ?><input type="hidden" name="return" value="<?php echo htmlspecialchars($pageReturnKey, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
            <?php if ($attentionShortfall): ?><input type="hidden" name="attention" value="shortfall"><?php endif; ?>
        </form>
        <div class="pack-day-shortcuts" aria-label="<?php bakery_te('pack_list.jump_weekday'); ?>">
            <?php
            $packWeekStart = date('Y-m-d', strtotime('-' . (bakery_standing_day_from_date($selectedDate) - 1) . ' days', strtotime($selectedDate)));
            foreach ($days as $dayNum => $dayName):
                $dayDate = date('Y-m-d', strtotime($packWeekStart . ' +' . ((int)$dayNum - 1) . ' days'));
                $isActiveDay = bakery_standing_day_from_date($selectedDate) === $dayNum;
            ?>
                <a class="pack-day-btn<?php echo $isActiveDay ? ' active' : ''; ?>"
                   href="pack_list.php?date=<?php echo urlencode($dayDate); ?>&amp;view=<?php echo urlencode($viewMode); ?>">
                    <?php echo htmlspecialchars(substr($dayName, 0, 3), ENT_QUOTES, 'UTF-8'); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <p class="pack-meta">
            <strong><?php echo htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
            <span class="pack-source<?php echo $hasDailyOrders ? '' : ' pack-source--fallback'; ?>">
                <?php echo htmlspecialchars($orderSourceLabel, ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </p>
    </div>

    <?php if (!empty($lineItems)): ?>
        <div class="pack-totals" aria-label="Pack totals">
            <div class="pack-total-card">
                <span class="pack-total-card__label"><?php bakery_te('pack_list.units_to_pack'); ?></span>
                <span class="pack-total-card__value"><?php echo number_format($totalUnits); ?></span>
            </div>
            <div class="pack-total-card">
                <span class="pack-total-card__label"><?php bakery_te('pack_list.customers'); ?></span>
                <span class="pack-total-card__value"><?php echo number_format($totalCustomers); ?></span>
            </div>
            <div class="pack-total-card">
                <span class="pack-total-card__label"><?php bakery_te('pack_list.products'); ?></span>
                <span class="pack-total-card__value"><?php echo number_format($totalProducts); ?></span>
            </div>
            <?php if ($inventoryReady && !$isBaker): ?>
                <div class="pack-total-card<?php echo $totalMadeUnits > 0 ? ' pack-total-card--ok' : ''; ?>">
                    <span class="pack-total-card__label"><?php bakery_te('pack_list.made_units'); ?></span>
                    <span class="pack-total-card__value"><?php echo number_format($totalMadeUnits); ?></span>
                </div>
                <div class="pack-total-card<?php echo $shortageUnits > 0 ? ' pack-total-card--alert' : ' pack-total-card--ok'; ?>">
                    <span class="pack-total-card__label"><?php bakery_te('pack_list.shortage_units'); ?></span>
                    <span class="pack-total-card__value"><?php echo number_format($shortageUnits); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($packProgressReady && $totalPackLines > 0): ?>
                <div class="pack-total-card<?php echo $packComplete ? ' pack-total-card--ok' : ''; ?>">
                    <span class="pack-total-card__label"><?php bakery_te('pack_list.packed_lines'); ?></span>
                    <span class="pack-total-card__value"><?php echo number_format($packedLineCount); ?>/<?php echo number_format($totalPackLines); ?></span>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($packComplete): ?>
            <div class="pack-complete-banner" role="status">
                <p><?php bakery_te('pack_list.ready_to_load'); ?></p>
                <?php if (!$isBaker): ?>
                    <a class="pack-btn pack-btn--primary" href="driver_load.php?date=<?php echo urlencode($selectedDate); ?>"><?php bakery_te('pack_list.open_driver_loads'); ?></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!$isBaker && !empty($exceptions)): ?>
        <section class="pack-exceptions" aria-label="Packing exceptions">
            <h2><?php echo count($shortageProducts) > 0 ? bakery_t('pack_list.attention_before_loading') : bakery_t('pack_list.notes'); ?></h2>
            <ul>
                <?php foreach ($exceptions as $ex): ?>
                    <li class="<?php echo $ex['type'] === 'standing_fallback' ? 'standing-fallback' : ''; ?>">
                        <?php echo $ex['message']; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php
    if ($isBaker && !empty($shortageProducts) && function_exists('bakery_exception_desk_render_baker')) {
        echo '<details class="pack-baker-help"><summary>' . htmlspecialchars(bakery_t('production.baker_help'), ENT_QUOTES, 'UTF-8') . '</summary>';
        bakery_exception_desk_render_baker(
            $db,
            $selectedDate,
            $shortageProducts,
            'pack_list.php?date=' . rawurlencode($selectedDate) . '&view=' . rawurlencode($viewMode)
        );
        echo '</details>';
    }
    ?>

    <?php if (!$isBaker && $inventoryReady && !empty($productTotals)): ?>
        <section class="pack-inventory-bar" aria-label="Finished goods reconciliation">
            <h2><?php bakery_te('pack_list.finished_goods'); ?></h2>
            <div style="overflow-x:auto">
                <table class="pack-inventory-table">
                    <thead>
                        <tr>
                            <th><?php bakery_te('pack_list.product'); ?></th>
                            <th><?php bakery_te('pack_list.required'); ?></th>
                            <th><?php bakery_te('pack_list.available'); ?></th>
                            <th><?php bakery_te('pack_list.loaded'); ?></th>
                            <th><?php bakery_te('pack_list.covered_stock'); ?></th>
                            <th><?php bakery_te('pack_list.produced'); ?></th>
                            <th><?php bakery_te('pack_list.status'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($byProduct as $product):
                            $pid = (int)$product['product_id'];
                            $required = (int)$product['total'];
                            $onHand = (int)($availableByProduct[$pid] ?? 0);
                            $loaded = (int)($loadedByProduct[$pid] ?? 0);
                            // Same coverage math as dashboard / Daily Run: on-hand + already loaded.
                            $covered = $onHand + $loaded;
                            $produced = $producedByProduct[$pid] ?? 0;
                            $short = max(0, $required - $covered);
                        ?>
                            <tr class="<?php echo $short > 0 ? 'shortage' : ''; ?>">
                                <td><strong><?php echo htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <td class="qty-req"><?php echo number_format($required); ?></td>
                                <td class="qty-avail"><?php echo number_format($onHand); ?></td>
                                <td><?php echo number_format($loaded); ?></td>
                                <td class="qty-avail"><strong><?php echo number_format($covered); ?></strong></td>
                                <td><?php echo number_format($produced); ?></td>
                                <td>
                                    <?php if ($short > 0): ?>
                                        <span class="short-badge"><?php echo htmlspecialchars(bakery_t('pack_list.short', ['count' => number_format($short)]), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php else: ?>
                                        <?php bakery_te('pack_list.covered'); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if (empty($lineItems)): ?>
        <div class="pack-empty">
            <p><?php echo htmlspecialchars(bakery_t('pack_list.no_quantities', ['date' => $dateLabel]), ENT_QUOTES, 'UTF-8'); ?></p>
            <?php if (!$hasDailyOrders): ?>
                <p><?php bakery_te('pack_list.no_daily_orders_hint'); ?></p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p class="pack-session-note"><?php bakery_te($packProgressReady ? 'pack_list.session_note' : 'pack_list.progress_unavailable'); ?></p>

        <nav class="pack-view-toggle" aria-label="<?php bakery_te('pack_list.by_product'); ?>">
            <a class="<?php echo $viewMode === 'product' ? 'active' : ''; ?>"
               href="pack_list.php?<?php echo $queryBase; ?>&amp;view=product"><?php bakery_te('pack_list.by_product'); ?></a>
            <a class="<?php echo $viewMode === 'customer' ? 'active' : ''; ?>"
               href="pack_list.php?<?php echo $queryBase; ?>&amp;view=customer"><?php bakery_te('pack_list.by_customer'); ?></a>
            <a class="<?php echo $viewMode === 'route' ? 'active' : ''; ?>"
               href="pack_list.php?<?php echo $queryBase; ?>&amp;view=route"><?php bakery_te('pack_list.by_route'); ?></a>
        </nav>

        <?php if ($viewMode === 'product'): ?>
            <?php foreach ($byProduct as $product):
                $pid = (int)$product['product_id'];
                $required = (int)$product['total'];
                $available = $inventoryReady ? (($availableByProduct[$pid] ?? 0) + ($loadedByProduct[$pid] ?? 0)) : null;
                $short = ($available !== null && $available < $required) ? ($required - $available) : 0;
            ?>
                <section class="pack-section<?php echo !$isBaker && $short > 0 ? ' ops-attention-row' : ''; ?>" id="pack-product-<?php echo $pid; ?>">
                    <header class="pack-section__header">
                        <div>
                            <h2 class="pack-section__title"><?php echo htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                            <?php if (!$isBaker): ?>
                                <?php
                                echo bakery_ops_render_row_chips($pageExceptions, [
                                    'product_id' => $pid,
                                    'flags' => $short > 0 ? ['fg_shortfall' => true] : [],
                                ], ['date' => $selectedDate, 'return' => (string)$pageReturnKey]);
                                ?>
                            <?php endif; ?>
                            <span class="pack-section__subtitle"><?php echo htmlspecialchars($product['dough_type'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="pack-section__totals">
                            <span class="pack-qty-pill pack-qty-pill--big"><?php echo htmlspecialchars(bakery_t('pack_list.total', ['count' => number_format($required)]), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php
                            $prodBreak = bakery_pack_count_breakdown($db, $pid, $required);
                            if (($prodBreak['trays'] ?? 0) > 0 || ($prodBreak['boxes'] ?? 0) > 0):
                            ?>
                                <span class="pack-qty-pill"><?php echo htmlspecialchars($prodBreak['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                            <?php if ($inventoryReady && !$isBaker): ?>
                                <span class="pack-qty-pill"><?php echo htmlspecialchars(bakery_t('pack_list.available_count', ['count' => number_format($available)]), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if ($short > 0): ?>
                                    <span class="pack-qty-pill pack-qty-pill--short"><?php echo htmlspecialchars(bakery_t('pack_list.short', ['count' => number_format($short)]), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </header>
                    <div class="pack-section__body">
                        <?php foreach ($product['customers'] as $line): ?>
                            <?php $lineChecked = isset($checkedMap[$line['line_key']]); ?>
                            <div class="pack-line<?php echo $lineChecked ? ' pack-line--checked' : ''; ?>" data-check-key="<?php echo htmlspecialchars($line['line_key'], ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="button" class="pack-check<?php echo $lineChecked ? ' is-checked' : ''; ?>" aria-label="<?php bakery_te('pack_list.mark_packed'); ?>" aria-pressed="<?php echo $lineChecked ? 'true' : 'false'; ?>"<?php echo $packProgressReady ? '' : ' disabled'; ?>><?php echo $lineChecked ? '✓' : ' '; ?></button>
                                <div class="pack-line__main">
                                    <span class="pack-line__label">
                                        <?php echo bakery_customer_record_link_html((int)$line['customer_id'], $line['customer_name'], $selectedDate, 'pack-customer-link'); ?>
                                        <?php if ($line['zone'] !== ''): ?>
                                            <span class="pack-zone-badge<?php echo $line['zone'] === 'Ruta Sour Flour' ? ' pack-zone-badge--ruta' : ''; ?>">
                                                <?php echo htmlspecialchars($line['zone'], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php echo bakery_pack_qty_html($db, $pid, (int)$line['quantity']); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php elseif ($viewMode === 'customer'): ?>
            <?php foreach ($byCustomer as $customer): ?>
                <section class="pack-section" id="pack-customer-<?php echo (int)$customer['customer_id']; ?>">
                    <header class="pack-section__header pack-section__header--customer">
                        <div>
                            <h2 class="pack-section__title">
                                <?php echo bakery_customer_record_link_html((int)$customer['customer_id'], $customer['customer_name'], $selectedDate, 'pack-customer-link'); ?>
                                <?php if ($customer['zone'] !== ''): ?>
                                    <span class="pack-zone-badge<?php echo $customer['zone'] === 'Ruta Sour Flour' ? ' pack-zone-badge--ruta' : ''; ?>">
                                        <?php echo htmlspecialchars($customer['zone'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                <?php endif; ?>
                            </h2>
                            <?php if (!$isBaker): ?>
                                <?php
                                echo bakery_ops_render_row_chips($pageExceptions, [
                                    'customer_id' => (int)$customer['customer_id'],
                                    'flags' => [],
                                ], ['date' => $selectedDate, 'return' => (string)$pageReturnKey]);
                                ?>
                            <?php endif; ?>
                        </div>
                        <div class="pack-section__totals">
                            <span class="pack-qty-pill pack-qty-pill--big"><?php echo htmlspecialchars(bakery_t('pack_list.units', ['count' => number_format($customer['total'])]), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </header>
                    <div class="pack-section__body">
                        <?php foreach ($customer['products'] as $line): ?>
                            <?php $lineChecked = isset($checkedMap[$line['line_key']]); ?>
                            <div class="pack-line<?php echo $lineChecked ? ' pack-line--checked' : ''; ?>" data-check-key="<?php echo htmlspecialchars($line['line_key'], ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="button" class="pack-check<?php echo $lineChecked ? ' is-checked' : ''; ?>" aria-label="<?php bakery_te('pack_list.mark_packed'); ?>" aria-pressed="<?php echo $lineChecked ? 'true' : 'false'; ?>"<?php echo $packProgressReady ? '' : ' disabled'; ?>><?php echo $lineChecked ? '✓' : ' '; ?></button>
                                <div class="pack-line__main">
                                    <span class="pack-line__label"><?php echo htmlspecialchars($line['product_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="pack-line__meta"><?php echo htmlspecialchars($line['dough_type'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <?php echo bakery_pack_qty_html($db, (int)$line['product_id'], (int)$line['quantity']); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php else: ?>
            <?php foreach ($byRouteList as $route):
                $isUnassigned = ((int)$route['driver_id'] === 0);
                $stopCount = count($route['customers']);
            ?>
                <section class="pack-section">
                    <header class="pack-section__header <?php echo $isUnassigned ? 'pack-section__header--unassigned' : 'pack-section__header--route'; ?>">
                        <div>
                            <h2 class="pack-section__title"><?php echo htmlspecialchars($route['driver_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                            <span class="pack-section__subtitle">
                                <?php echo htmlspecialchars(bakery_t('pack_list.stops', ['count' => number_format($stopCount)]), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <?php if ($isUnassigned): ?>
                                <p class="pack-unassigned-hint"><?php bakery_te('pack_list.unassigned_hint'); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="pack-section__totals">
                            <span class="pack-qty-pill pack-qty-pill--big"><?php echo htmlspecialchars(bakery_t('pack_list.units', ['count' => number_format($route['total'])]), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php if ($packProgressReady && !$packComplete): ?>
                                <form method="post" class="pack-all-form">
                                    <?php echo bakery_csrf_field(); ?>
                                    <input type="hidden" name="action" value="pack_all">
                                    <input type="hidden" name="view" value="route">
                                    <input type="hidden" name="driver_id" value="<?php echo (int)$route['driver_id']; ?>">
                                    <button type="submit" class="pack-btn"><?php bakery_te('pack_list.pack_driver'); ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </header>
                    <div class="pack-section__body">
                        <?php foreach (($route['product_totals'] ?? []) as $roll):
                            $rollBreak = bakery_pack_count_breakdown($db, (int)$roll['product_id'], (int)$roll['quantity']);
                        ?>
                            <details class="pack-driver-product">
                                <summary>
                                    <div>
                                        <div class="pack-driver-product__name"><?php echo htmlspecialchars($roll['product_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="pack-driver-product__hint"><?php echo htmlspecialchars(bakery_t('pack_list.expand_stores', ['count' => number_format(count($roll['stores']))]), ENT_QUOTES, 'UTF-8'); ?></div>
                                    </div>
                                    <div class="pack-line__qtywrap">
                                        <span class="pack-line__qty"><?php echo number_format((int)$roll['quantity']); ?></span>
                                        <?php if (($rollBreak['trays'] ?? 0) > 0 || ($rollBreak['boxes'] ?? 0) > 0): ?>
                                            <span class="pack-convert"><?php echo htmlspecialchars($rollBreak['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </summary>
                                <?php foreach ($roll['stores'] as $line): ?>
                                    <?php $lineChecked = isset($checkedMap[$line['line_key']]); ?>
                                    <div class="pack-line<?php echo $lineChecked ? ' pack-line--checked' : ''; ?>" data-check-key="<?php echo htmlspecialchars($line['line_key'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="button" class="pack-check<?php echo $lineChecked ? ' is-checked' : ''; ?>" aria-label="<?php bakery_te('pack_list.mark_packed'); ?>" aria-pressed="<?php echo $lineChecked ? 'true' : 'false'; ?>"<?php echo $packProgressReady ? '' : ' disabled'; ?>><?php echo $lineChecked ? '✓' : ' '; ?></button>
                                        <div class="pack-line__main">
                                            <span class="pack-line__label">
                                                <?php echo bakery_customer_record_link_html((int)$line['customer_id'], $line['customer_name'], $selectedDate, 'pack-customer-link'); ?>
                                                <?php if ($line['zone'] !== ''): ?>
                                                    <span class="pack-zone-badge<?php echo $line['zone'] === 'Ruta Sour Flour' ? ' pack-zone-badge--ruta' : ''; ?>">
                                                        <?php echo htmlspecialchars($line['zone'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <?php echo bakery_pack_qty_html($db, (int)$roll['product_id'], (int)$line['quantity']); ?>
                                    </div>
                                <?php endforeach; ?>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</main>

<script>
(function () {
    var root = document.getElementById('packPage');
    if (!root) return;

    var dateKey = root.getAttribute('data-date') || 'unknown';
    var csrf = root.getAttribute('data-csrf') || '';
    // Check-offs persist server-side per date; initial state is rendered by PHP.
    var pending = {};

    root.querySelectorAll('.pack-line[data-check-key]').forEach(function (line) {
        var key = line.getAttribute('data-check-key');
        var btn = line.querySelector('.pack-check');
        if (!btn || !key) return;

        function apply(checked) {
            btn.classList.toggle('is-checked', checked);
            btn.setAttribute('aria-pressed', checked ? 'true' : 'false');
            btn.textContent = checked ? '\u2713' : ' ';
            line.classList.toggle('pack-line--checked', checked);
        }

        btn.addEventListener('click', function () {
            if (pending[key]) return;
            var checked = btn.getAttribute('aria-pressed') !== 'true';
            apply(checked);
            pending[key] = true;

            var body = 'action=toggle_pack_check&date=' + encodeURIComponent(dateKey)
                + '&line_key=' + encodeURIComponent(key)
                + '&checked=' + (checked ? '1' : '0')
                + '&csrf_token=' + encodeURIComponent(csrf);

            fetch('pack_list.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json'
                },
                body: body
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data || !data.success) {
                        apply(!checked);
                    }
                })
                .catch(function () {
                    apply(!checked);
                })
                .finally(function () {
                    delete pending[key];
                });
        });
    });
})();
</script>

<?php require_once 'includes/footer.php'; ?>
