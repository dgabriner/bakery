<?php
define('ACCESS_ALLOWED', true);

require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/header.php';
require_once 'includes/nav.php';

$page_title = bakery_t('page.daily_route');
bakery_ensure_standing_routes_order_column($db);

$days = [
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
    7 => 'Sunday'
];

$view = $_GET['view'] ?? 'day';
if (!in_array($view, ['day', 'month', 'list'], true)) {
    $view = 'day';
}

$listScope = $_GET['list_scope'] ?? 'all';
if (!in_array($listScope, ['all', 'upcoming', 'past'], true)) {
    $listScope = 'all';
}

$selectedDriverId = isset($_GET['driver_id']) ? (int)$_GET['driver_id'] : 0;
$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate) || date('Y-m-d', strtotime($selectedDate)) !== $selectedDate) {
    $selectedDate = date('Y-m-d');
}
// Keep the date and weekday in sync when navigating into day view.
$selectedDay = $view === 'day'
    ? (int)date('N', strtotime($selectedDate))
    : (isset($_GET['day']) ? (int)$_GET['day'] : (int)date('N'));
$selectedMonth = $view === 'day'
    ? date('Y-m', strtotime($selectedDate))
    : ($_GET['month'] ?? date('Y-m', strtotime($selectedDate)));

if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

$monthTimestamp = strtotime($selectedMonth . '-01');
$monthStart = date('Y-m-01', $monthTimestamp);
$monthEnd = date('Y-m-t', $monthTimestamp);
$monthLabel = date('F Y', $monthTimestamp);
$prevMonth = date('Y-m', strtotime($monthStart . ' -1 month'));
$nextMonth = date('Y-m', strtotime($monthStart . ' +1 month'));
$previousDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

$drivers = [];
try {
    $drivers = bakery_get_drivers($db);
} catch (Exception $e) {
    echo '<div class="error">Error loading drivers: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

$selectedDriverName = '';
foreach ($drivers as $driver) {
    if ((int)$driver['id'] === $selectedDriverId) {
        $selectedDriverName = $driver['name'];
        break;
    }
}

function buildDailyRouteUrl(array $params): string
{
    $defaults = [
        'view' => 'day',
        'driver_id' => 0,
        'day' => date('N'),
        'date' => date('Y-m-d'),
        'month' => date('Y-m'),
    ];
    $merged = array_merge($defaults, $params);
    $query = [];
    foreach ($merged as $key => $value) {
        if ($value === '' || $value === null || ($key === 'driver_id' && (int)$value <= 0)) {
            continue;
        }
        $query[$key] = $value;
    }
    return 'daily_route.php?' . http_build_query($query);
}

function zoneClassFromName(?string $zone): string
{
    $zone = $zone ?: 'No Zone';
    return 'zone-' . strtolower(str_replace([' ', '/'], ['-', '-'], $zone));
}

function assignmentStatusClass(?string $status): string
{
    $map = [
        'pending' => 'status-pending',
        'in_transit' => 'status-transit',
        'delivered' => 'status-delivered',
        'failed' => 'status-failed',
        'cancelled' => 'status-cancelled',
    ];
    return $map[$status] ?? 'status-pending';
}

function assignmentStatusLabel(?string $status): string
{
    $labels = [
        'pending' => 'Pending',
        'in_transit' => 'In Transit',
        'delivered' => 'Delivered',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ];
    return $labels[$status] ?? ucfirst((string)$status);
}

function formatRouteTime(?string $time): string
{
    if (empty($time)) {
        return '';
    }
    return date('g:i A', strtotime($time));
}

$routeData = [];
$totalItems = 0;
$productSummaryByDoughType = [];
$scheduledStopCount = 0;
$routeStartTime = null;
$routeEndTime = null;
$routesByDay = [];
$calendarDays = [];
$monthTotals = [
    'stops' => 0,
    'items' => 0,
    'days_with_routes' => 0,
];
$assignmentDays = [];
$assignmentTotals = [
    'assignments' => 0,
    'dates' => 0,
    'items' => 0,
    'pending' => 0,
    'delivered' => 0,
];

if ($selectedDriverId > 0) {
    try {
        $stmt = $db->prepare("
            SELECT
                sr.day_of_week,
                MIN(sr.route_order) AS standing_route_order,
                c.id AS customer_id,
                c.name AS customer_name,
                c.zone,
                COALESCE(SUM(so.quantity), 0) AS item_count
            FROM standing_routes sr
            JOIN customers c ON sr.customer_id = c.id
            " . bakery_sfb_ops_origin_clause('c', $db) . "
            LEFT JOIN standing_orders so
                ON so.customer_id = c.id
               AND CASE WHEN so.day_of_week = 0 THEN 7 ELSE so.day_of_week END
                   = CASE WHEN sr.day_of_week = 0 THEN 7 ELSE sr.day_of_week END
            WHERE sr.driver_id = ?
            GROUP BY sr.day_of_week, c.id, c.name, c.zone
            ORDER BY sr.day_of_week, COALESCE(MIN(sr.route_order), 2147483647), c.zone, c.name
        ");
        $stmt->execute([$selectedDriverId]);

        foreach ($stmt->fetchAll() as $row) {
            $dayOfWeek = (int)$row['day_of_week'];
            if ($dayOfWeek === 0) {
                $dayOfWeek = 7;
            }
            if (!isset($routesByDay[$dayOfWeek])) {
                $routesByDay[$dayOfWeek] = [
                    'customers' => [],
                    'stop_count' => 0,
                    'item_count' => 0,
                ];
            }
            $routesByDay[$dayOfWeek]['customers'][] = [
                'customer_id' => (int)$row['customer_id'],
                'customer_name' => $row['customer_name'],
                'zone' => $row['zone'],
                'item_count' => (int)$row['item_count'],
            ];
            $routesByDay[$dayOfWeek]['stop_count']++;
            $routesByDay[$dayOfWeek]['item_count'] += (int)$row['item_count'];
        }

        if ($view === 'month') {
            $daysInMonth = (int)date('t', $monthTimestamp);
            for ($dayNum = 1; $dayNum <= $daysInMonth; $dayNum++) {
                $date = date('Y-m-d', strtotime($monthStart . ' +' . ($dayNum - 1) . ' days'));
                $dayOfWeek = (int)date('N', strtotime($date));
                $dayRoute = $routesByDay[$dayOfWeek] ?? ['customers' => [], 'stop_count' => 0, 'item_count' => 0];

                $calendarDays[] = [
                    'date' => $date,
                    'day_num' => $dayNum,
                    'day_of_week' => $dayOfWeek,
                    'day_name' => $days[$dayOfWeek],
                    'customers' => $dayRoute['customers'],
                    'stop_count' => $dayRoute['stop_count'],
                    'item_count' => $dayRoute['item_count'],
                    'is_today' => $date === date('Y-m-d'),
                    'is_weekend' => $dayOfWeek >= 6,
                ];

                if ($dayRoute['stop_count'] > 0) {
                    $monthTotals['stops'] += $dayRoute['stop_count'];
                    $monthTotals['items'] += $dayRoute['item_count'];
                    $monthTotals['days_with_routes']++;
                }
            }
        }

        if ($view === 'list') {
            $scopeSql = '';
            if ($listScope === 'upcoming') {
                $scopeSql = ' AND doa.delivery_date >= CURDATE()';
            } elseif ($listScope === 'past') {
                $scopeSql = ' AND doa.delivery_date < CURDATE()';
            }

            $stmt = $db->prepare("
                SELECT
                    doa.delivery_date,
                    doa.delivery_status,
                    doa.route_order,
                    doa.scheduled_delivery_time,
                    c.id AS customer_id,
                    c.name AS customer_name,
                    c.zone,
                    do.id AS daily_order_id,
                    do.total_amount,
                    (
                        SELECT COALESCE(SUM(doi.quantity), 0)
                        FROM daily_order_items doi
                        WHERE doi.daily_order_id = do.id
                    ) AS item_count
                FROM daily_order_assignments doa
                JOIN daily_orders do
                    ON do.id = doa.daily_order_id AND do.order_date = doa.delivery_date
                JOIN customers c ON do.customer_id = c.id
                " . bakery_sfb_ops_origin_clause('c', $db) . "
                WHERE doa.driver_id = ?{$scopeSql}
                ORDER BY doa.delivery_date ASC, doa.route_order ASC, c.name ASC
            ");
            $stmt->execute([$selectedDriverId]);

            $grouped = [];
            foreach ($stmt->fetchAll() as $row) {
                $date = $row['delivery_date'];
                $dayOfWeek = (int)date('N', strtotime($date));

                if (!isset($grouped[$date])) {
                    $grouped[$date] = [
                        'date' => $date,
                        'day_of_week' => $dayOfWeek,
                        'day_name' => $days[$dayOfWeek],
                        'customers' => [],
                        'stop_count' => 0,
                        'item_count' => 0,
                        'pending' => 0,
                        'delivered' => 0,
                        'time_start' => null,
                        'time_end' => null,
                        'is_today' => $date === date('Y-m-d'),
                        'is_past' => $date < date('Y-m-d'),
                    ];
                }

                $status = $row['delivery_status'] ?? 'pending';
                $grouped[$date]['customers'][] = [
                    'customer_id' => (int)$row['customer_id'],
                    'customer_name' => $row['customer_name'],
                    'zone' => $row['zone'],
                    'item_count' => (int)$row['item_count'],
                    'delivery_status' => $status,
                    'route_order' => (int)$row['route_order'],
                    'scheduled_delivery_time' => $row['scheduled_delivery_time'],
                    'total_amount' => (float)$row['total_amount'],
                    'daily_order_id' => (int)$row['daily_order_id'],
                ];
                $grouped[$date]['stop_count']++;
                $grouped[$date]['item_count'] += (int)$row['item_count'];
                if (!empty($row['scheduled_delivery_time'])) {
                    if ($grouped[$date]['time_start'] === null || $row['scheduled_delivery_time'] < $grouped[$date]['time_start']) {
                        $grouped[$date]['time_start'] = $row['scheduled_delivery_time'];
                    }
                    if ($grouped[$date]['time_end'] === null || $row['scheduled_delivery_time'] > $grouped[$date]['time_end']) {
                        $grouped[$date]['time_end'] = $row['scheduled_delivery_time'];
                    }
                }
                $assignmentTotals['assignments']++;
                $assignmentTotals['items'] += (int)$row['item_count'];

                if ($status === 'delivered') {
                    $grouped[$date]['delivered']++;
                    $assignmentTotals['delivered']++;
                } elseif (in_array($status, ['pending', 'in_transit'], true)) {
                    $grouped[$date]['pending']++;
                    $assignmentTotals['pending']++;
                }
            }

            $assignmentDays = array_values($grouped);
            $assignmentTotals['dates'] = count($assignmentDays);
        }

        if ($view === 'day' && $selectedDay > 0) {
            $stmt = $db->prepare("
                SELECT
                    MIN(sr.route_order) AS standing_route_order,
                    c.id AS customer_id,
                    c.name AS customer_name,
                    c.address,
                    c.phone,
                    c.email,
                    c.deliver_after,
                    c.deliver_by,
                    c.delivery_time AS stop_duration,
                    MAX(doa.scheduled_delivery_time) AS scheduled_time,
                    MAX(doa.estimated_delivery_time) AS estimated_time,
                    MAX(COALESCE(doa.scheduled_delivery_time, doa.estimated_delivery_time, daily_order.delivery_time)) AS route_time,
                    GROUP_CONCAT(DISTINCT
                        CONCAT(
                            COALESCE(dt.name, 'Unclassified'), '|',
                            p.name,
                            ' (', COALESCE(so.quantity, 0), ')'
                        )
                        ORDER BY COALESCE(dt.name, 'Unclassified'), p.name
                        SEPARATOR '||'
                    ) AS orders
                FROM standing_routes sr
                JOIN customers c ON sr.customer_id = c.id
                " . bakery_sfb_ops_origin_clause('c', $db) . "
                LEFT JOIN daily_orders daily_order
                    ON daily_order.customer_id = c.id
                   AND daily_order.order_date = ?
                LEFT JOIN daily_order_assignments doa
                    ON doa.daily_order_id = daily_order.id
                   AND doa.driver_id = ?
                   AND doa.delivery_date = ?
                LEFT JOIN standing_orders so
                    ON so.customer_id = c.id
                   AND CASE WHEN so.day_of_week = 0 THEN 7 ELSE so.day_of_week END
                       = CASE WHEN sr.day_of_week = 0 THEN 7 ELSE sr.day_of_week END
                LEFT JOIN products p ON so.product_id = p.id
                LEFT JOIN dough_types dt ON p.dough_type_id = dt.id
                WHERE sr.driver_id = ?
                  AND CASE WHEN sr.day_of_week = 0 THEN 7 ELSE sr.day_of_week END = ?
                GROUP BY c.id, c.name, c.address, c.phone, c.email, c.deliver_after, c.deliver_by, c.delivery_time
                ORDER BY COALESCE(MIN(sr.route_order), 2147483647), c.name
            ");
            $stmt->execute([$selectedDate, $selectedDriverId, $selectedDate, $selectedDriverId, $selectedDay]);
            $routeData = $stmt->fetchAll();

            foreach ($routeData as &$customer) {
                $customer['route_time_label'] = !empty($customer['scheduled_time'])
                    ? 'Scheduled'
                    : (!empty($customer['estimated_time']) ? 'Estimated' : 'Order time');
                if (!empty($customer['route_time'])) {
                    $scheduledStopCount++;
                    if ($routeStartTime === null || $customer['route_time'] < $routeStartTime) {
                        $routeStartTime = $customer['route_time'];
                    }
                    if ($routeEndTime === null || $customer['route_time'] > $routeEndTime) {
                        $routeEndTime = $customer['route_time'];
                    }
                }
                if (empty($customer['orders'])) {
                    continue;
                }

                $orderLines = explode('||', $customer['orders']);
                $customerOrdersByDoughType = [];

                foreach ($orderLines as $line) {
                    if (!preg_match('/(.+?)\|(.+?)\s*\((\d+)\)$/', $line, $matches)) {
                        continue;
                    }

                    $doughType = trim($matches[1]);
                    $productName = trim($matches[2]);
                    $quantity = (int)$matches[3];
                    $totalItems += $quantity;

                    if (!isset($productSummaryByDoughType[$doughType])) {
                        $productSummaryByDoughType[$doughType] = [];
                    }
                    if (!isset($productSummaryByDoughType[$doughType][$productName])) {
                        $productSummaryByDoughType[$doughType][$productName] = 0;
                    }
                    $productSummaryByDoughType[$doughType][$productName] += $quantity;

                    if (!isset($customerOrdersByDoughType[$doughType])) {
                        $customerOrdersByDoughType[$doughType] = [];
                    }
                    $customerOrdersByDoughType[$doughType][] = $productName . ' (' . $quantity . ')';
                }

                $customer['orders_by_dough_type'] = $customerOrdersByDoughType;
            }
            unset($customer);
        }
    } catch (Exception $e) {
        echo '<div class="error">Error loading route data: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

$leadingBlanks = (int)date('N', strtotime($monthStart)) - 1;
?>

<div class="container">
    <div class="page-header">
        <h1>Daily Route</h1>
        <div class="view-toggles">
            <a href="<?php echo htmlspecialchars(buildDailyRouteUrl([
                'view' => 'day',
                'driver_id' => $selectedDriverId,
                'day' => $selectedDay,
                'date' => $selectedDate,
                'month' => $selectedMonth,
            ])); ?>" class="btn <?php echo $view === 'day' ? 'btn-primary' : 'btn-outline'; ?>">Day View</a>
            <a href="<?php echo htmlspecialchars(buildDailyRouteUrl([
                'view' => 'month',
                'driver_id' => $selectedDriverId,
                'month' => $selectedMonth,
            ])); ?>" class="btn <?php echo $view === 'month' ? 'btn-primary' : 'btn-outline'; ?>">Month View</a>
            <a href="<?php echo htmlspecialchars(buildDailyRouteUrl([
                'view' => 'list',
                'driver_id' => $selectedDriverId,
                'list_scope' => $listScope,
            ])); ?>" class="btn <?php echo $view === 'list' ? 'btn-primary' : 'btn-outline'; ?>">List View</a>
        </div>
    </div>

    <div class="route-filters">
        <form method="get" action="" class="filter-form">
            <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">

            <div class="form-group">
                <label for="driver_id">Driver</label>
                <select name="driver_id" id="driver_id" class="form-control" required>
                    <option value="">Select a driver</option>
                    <?php foreach ($drivers as $driver): ?>
                        <option value="<?php echo $driver['id']; ?>" <?php echo $selectedDriverId == $driver['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($driver['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($view === 'day'): ?>
                <div class="form-group">
                    <label for="date">Route date</label>
                    <input type="date" name="date" id="date" class="form-control" value="<?php echo htmlspecialchars($selectedDate); ?>" required>
                </div>
                <input type="hidden" name="day" value="<?php echo $selectedDay; ?>">
                <input type="hidden" name="month" value="<?php echo htmlspecialchars($selectedMonth); ?>">
            <?php elseif ($view === 'month'): ?>
                <div class="form-group">
                    <label for="month">Month</label>
                    <input type="month" name="month" id="month" class="form-control" value="<?php echo htmlspecialchars($selectedMonth); ?>">
                </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary"><?php echo $view === 'month' ? 'Show Month' : ($view === 'list' ? 'Show Assignments' : 'Show Route'); ?></button>
        </form>

        <?php if ($view === 'month' && $selectedDriverId > 0): ?>
            <div class="month-nav">
                <a href="<?php echo htmlspecialchars(buildDailyRouteUrl([
                    'view' => 'month',
                    'driver_id' => $selectedDriverId,
                    'month' => $prevMonth,
                ])); ?>" class="btn btn-outline">← Previous</a>
                <a href="<?php echo htmlspecialchars(buildDailyRouteUrl([
                    'view' => 'month',
                    'driver_id' => $selectedDriverId,
                    'month' => date('Y-m'),
                ])); ?>" class="btn btn-secondary">This Month</a>
                <a href="<?php echo htmlspecialchars(buildDailyRouteUrl([
                    'view' => 'month',
                    'driver_id' => $selectedDriverId,
                    'month' => $nextMonth,
                ])); ?>" class="btn btn-outline">Next →</a>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($selectedDriverId <= 0): ?>
        <div class="alert alert-info">Select a driver to view their route<?php echo $view === 'month' ? ' schedule for the month' : ($view === 'list' ? ' assignments' : ''); ?>.</div>
    <?php elseif ($view === 'list'): ?>
        <div class="route-summary month-summary">
            <h3><?php echo htmlspecialchars($selectedDriverName); ?> — Daily Assignments</h3>
            <p class="summary-note">All scheduled delivery assignments for this driver, grouped by date.</p>
            <div class="summary-grid">
                <div class="summary-item">
                    <span class="summary-label">Total Assignments</span>
                    <span class="summary-value"><?php echo $assignmentTotals['assignments']; ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Delivery Dates</span>
                    <span class="summary-value"><?php echo $assignmentTotals['dates']; ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Pending</span>
                    <span class="summary-value"><?php echo $assignmentTotals['pending']; ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Delivered</span>
                    <span class="summary-value"><?php echo $assignmentTotals['delivered']; ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Total Items</span>
                    <span class="summary-value"><?php echo $assignmentTotals['items']; ?></span>
                </div>
            </div>
        </div>

        <div class="list-controls">
            <div class="list-filter-toggles">
                <a href="<?php echo htmlspecialchars(buildDailyRouteUrl([
                    'view' => 'list',
                    'driver_id' => $selectedDriverId,
                    'list_scope' => 'all',
                ])); ?>" class="btn btn-sm <?php echo $listScope === 'all' ? 'btn-primary' : 'btn-outline'; ?>">All</a>
                <a href="<?php echo htmlspecialchars(buildDailyRouteUrl([
                    'view' => 'list',
                    'driver_id' => $selectedDriverId,
                    'list_scope' => 'upcoming',
                ])); ?>" class="btn btn-sm <?php echo $listScope === 'upcoming' ? 'btn-primary' : 'btn-outline'; ?>">Upcoming</a>
                <a href="<?php echo htmlspecialchars(buildDailyRouteUrl([
                    'view' => 'list',
                    'driver_id' => $selectedDriverId,
                    'list_scope' => 'past',
                ])); ?>" class="btn btn-sm <?php echo $listScope === 'past' ? 'btn-primary' : 'btn-outline'; ?>">Past</a>
            </div>
            <div class="list-action-links">
                <a href="driver_assignment.php" class="btn btn-secondary btn-sm">Manage Assignments</a>
                <a href="drivers.php?driver_id=<?php echo $selectedDriverId; ?>" class="btn btn-secondary btn-sm">Edit Standing Routes</a>
            </div>
        </div>

        <div class="delivery-list">
            <?php if (empty($assignmentDays)): ?>
                <div class="list-empty-block">
                    <h3>No daily assignments found</h3>
                    <p>
                        <?php if ($listScope === 'upcoming'): ?>
                            This driver has no upcoming delivery assignments.
                        <?php elseif ($listScope === 'past'): ?>
                            This driver has no past delivery assignments.
                        <?php else: ?>
                            This driver has no daily order assignments yet.
                        <?php endif; ?>
                    </p>
                    <a href="driver_assignment.php" class="btn btn-primary btn-sm">Create Assignments</a>
                </div>
            <?php else: ?>
                <table class="delivery-list-table assignment-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>#</th>
                            <th>Customer</th>
                            <th>Zone</th>
                            <th>Status</th>
                            <th>Items</th>
                            <th>Time</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignmentDays as $day): ?>
                            <tr class="date-group-row <?php echo $day['is_today'] ? 'is-today' : ''; ?> <?php echo $day['is_past'] ? 'is-past' : ''; ?>">
                                <td colspan="8">
                                    <div class="date-group-header">
                                        <div class="date-group-main">
                                            <strong><?php echo date('l, M j, Y', strtotime($day['date'])); ?></strong>
                                            <?php if ($day['is_today']): ?>
                                                <span class="today-badge">Today</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="date-group-meta">
                                            <?php echo $day['stop_count']; ?> stop<?php echo $day['stop_count'] !== 1 ? 's' : ''; ?>
                                            · <?php echo $day['item_count']; ?> items
                                            · <?php echo $day['pending']; ?> pending
                                            · <?php echo $day['delivered']; ?> delivered
                                            <?php if ($day['time_start']): ?>
                                                · <strong><?php echo htmlspecialchars(formatRouteTime($day['time_start'])); ?>–<?php echo htmlspecialchars(formatRouteTime($day['time_end'])); ?></strong>
                                            <?php endif; ?>
                                        </div>
                                        <a href="driver.php?driver_id=<?php echo $selectedDriverId; ?>&date=<?php echo urlencode($day['date']); ?>"
                                           class="btn btn-sm btn-outline">View Route</a>
                                    </div>
                                </td>
                            </tr>
                            <?php foreach ($day['customers'] as $assignment): ?>
                                <tr class="assignment-row">
                                    <td class="list-date-sub"><?php echo date('M j', strtotime($day['date'])); ?></td>
                                    <td class="list-route-order"><?php echo $assignment['route_order'] > 0 ? $assignment['route_order'] : '—'; ?></td>
                                    <td class="list-customer-name">
                                        <span class="customer-chip <?php echo zoneClassFromName($assignment['zone']); ?>">
                                            <?php echo htmlspecialchars($assignment['customer_name']); ?>
                                        </span>
                                    </td>
                                    <td class="list-zone"><?php echo htmlspecialchars($assignment['zone'] ?: 'No Zone'); ?></td>
                                    <td class="list-status">
                                        <span class="status-badge <?php echo assignmentStatusClass($assignment['delivery_status']); ?>">
                                            <?php echo htmlspecialchars(assignmentStatusLabel($assignment['delivery_status'])); ?>
                                        </span>
                                    </td>
                                    <td class="list-items"><?php echo $assignment['item_count']; ?></td>
                                    <td class="list-time">
                                        <?php if ($assignment['scheduled_delivery_time']): ?>
                                            <span class="list-time-badge"><i class="fas fa-clock"></i> <?php echo htmlspecialchars(formatRouteTime($assignment['scheduled_delivery_time'])); ?></span>
                                        <?php else: ?>
                                            <span class="muted-text">Not set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="list-action">
                                        <a href="driver.php?driver_id=<?php echo $selectedDriverId; ?>&date=<?php echo urlencode($day['date']); ?>"
                                           class="btn btn-sm btn-outline">Open</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php elseif ($view === 'month'): ?>
        <div class="route-summary month-summary">
            <h3><?php echo htmlspecialchars($selectedDriverName); ?> — <?php echo htmlspecialchars($monthLabel); ?></h3>
            <p class="summary-note">Standing routes repeat each week. Each calendar day shows the route for that weekday.</p>
            <div class="summary-grid">
                <div class="summary-item">
                    <span class="summary-label">Delivery Days</span>
                    <span class="summary-value"><?php echo $monthTotals['days_with_routes']; ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Total Stops (Month)</span>
                    <span class="summary-value"><?php echo $monthTotals['stops']; ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Total Items (Month)</span>
                    <span class="summary-value"><?php echo $monthTotals['items']; ?></span>
                </div>
            </div>
            <div class="summary-actions">
                <a href="drivers.php?driver_id=<?php echo $selectedDriverId; ?>" class="btn btn-secondary btn-sm">Edit Standing Routes</a>
            </div>
        </div>

        <div class="month-calendar">
            <div class="calendar-weekdays">
                <?php foreach ($days as $dayName): ?>
                    <div class="calendar-weekday"><?php echo $dayName; ?></div>
                <?php endforeach; ?>
            </div>
            <div class="calendar-grid">
                <?php for ($i = 0; $i < $leadingBlanks; $i++): ?>
                    <div class="calendar-cell empty"></div>
                <?php endfor; ?>

                <?php foreach ($calendarDays as $day): ?>
                    <a href="<?php echo htmlspecialchars(buildDailyRouteUrl([
                        'view' => 'day',
                        'driver_id' => $selectedDriverId,
                        'day' => $day['day_of_week'],
                        'date' => $day['date'],
                        'month' => $selectedMonth,
                    ])); ?>"
                       class="calendar-cell <?php echo $day['is_today'] ? 'is-today' : ''; ?> <?php echo $day['is_weekend'] ? 'is-weekend' : ''; ?> <?php echo $day['stop_count'] === 0 ? 'no-route' : ''; ?>"
                       title="View <?php echo htmlspecialchars($day['day_name'] . ', ' . date('M j', strtotime($day['date']))); ?>">
                        <div class="cell-header">
                            <span class="cell-date"><?php echo $day['day_num']; ?></span>
                            <?php if ($day['stop_count'] > 0): ?>
                                <span class="cell-stats"><?php echo $day['stop_count']; ?> stops · <?php echo $day['item_count']; ?> items</span>
                            <?php else: ?>
                                <span class="cell-stats muted">No route</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($day['stop_count'] > 0): ?>
                            <div class="cell-customers">
                                <?php
                                $visibleCustomers = array_slice($day['customers'], 0, 4);
                                $hiddenCount = count($day['customers']) - count($visibleCustomers);
                                foreach ($visibleCustomers as $customer):
                                ?>
                                    <span class="customer-chip <?php echo zoneClassFromName($customer['zone']); ?>">
                                        <?php echo htmlspecialchars($customer['customer_name']); ?>
                                    </span>
                                <?php endforeach; ?>
                                <?php if ($hiddenCount > 0): ?>
                                    <span class="customer-more">+<?php echo $hiddenCount; ?> more</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="weekly-pattern">
            <h3>Weekly Route Pattern</h3>
            <p class="summary-note">Recurring standing route for <?php echo htmlspecialchars($selectedDriverName); ?>.</p>
            <div class="pattern-grid">
                <?php foreach ($days as $dayNum => $dayName): ?>
                    <?php $pattern = $routesByDay[$dayNum] ?? ['customers' => [], 'stop_count' => 0, 'item_count' => 0]; ?>
                    <div class="pattern-day <?php echo $pattern['stop_count'] === 0 ? 'no-route' : ''; ?>">
                        <div class="pattern-day-header">
                            <strong><?php echo $dayName; ?></strong>
                            <span><?php echo $pattern['stop_count']; ?> stops · <?php echo $pattern['item_count']; ?> items</span>
                        </div>
                        <?php if ($pattern['stop_count'] > 0): ?>
                            <ul class="pattern-customer-list">
                                <?php foreach ($pattern['customers'] as $customer): ?>
                                    <li class="<?php echo zoneClassFromName($customer['zone']); ?>">
                                        <?php echo htmlspecialchars($customer['customer_name']); ?>
                                        <?php if ($customer['item_count'] > 0): ?>
                                            <span class="item-count">(<?php echo $customer['item_count']; ?>)</span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <a href="<?php echo htmlspecialchars(buildDailyRouteUrl([
                                'view' => 'day',
                                'driver_id' => $selectedDriverId,
                                'day' => $dayNum,
                                'date' => $selectedDate,
                                'month' => $selectedMonth,
                            ])); ?>" class="pattern-link">View day detail →</a>
                        <?php else: ?>
                            <div class="pattern-empty">No standing route</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php elseif ($selectedDay > 0): ?>
        <div class="day-context">
            <a href="<?php echo htmlspecialchars(buildDailyRouteUrl([
                'view' => 'list',
                'driver_id' => $selectedDriverId,
                'list_scope' => $listScope,
            ])); ?>" class="back-link">← Back to List View</a>
            <a href="<?php echo htmlspecialchars(buildDailyRouteUrl([
                'view' => 'month',
                'driver_id' => $selectedDriverId,
                'month' => date('Y-m', strtotime($selectedDate)),
            ])); ?>" class="back-link secondary">Month View</a>
            <?php if (!empty($selectedDate)): ?>
                <span class="context-date"><?php echo htmlspecialchars($days[$selectedDay] . ', ' . date('F j, Y', strtotime($selectedDate))); ?></span>
            <?php endif; ?>
        </div>

        <div class="day-toolbar" aria-label="Route day controls">
            <div class="day-navigation">
                <a class="btn btn-outline btn-sm" href="<?php echo htmlspecialchars(buildDailyRouteUrl(['view' => 'day', 'driver_id' => $selectedDriverId, 'date' => $previousDate, 'month' => date('Y-m', strtotime($previousDate))])); ?>">&larr; Previous day</a>
                <a class="btn btn-secondary btn-sm" href="<?php echo htmlspecialchars(buildDailyRouteUrl(['view' => 'day', 'driver_id' => $selectedDriverId, 'date' => date('Y-m-d'), 'month' => date('Y-m')])); ?>">Today</a>
                <a class="btn btn-outline btn-sm" href="<?php echo htmlspecialchars(buildDailyRouteUrl(['view' => 'day', 'driver_id' => $selectedDriverId, 'date' => $nextDate, 'month' => date('Y-m', strtotime($nextDate))])); ?>">Next day &rarr;</a>
            </div>
            <div class="route-tools">
                <a class="btn btn-primary btn-sm" href="driver_assignment.php?date=<?php echo urlencode($selectedDate); ?>">
                    Add One-Time Stop
                </a>
                <label class="route-search-label" for="route-search">Find a stop</label>
                <input id="route-search" class="form-control route-search" type="search" placeholder="Customer or address" autocomplete="off">
                <span id="route-search-count" class="search-count" aria-live="polite"></span>
                <button type="button" class="btn btn-outline btn-sm" id="print-route">Print route</button>
            </div>
        </div>

        <div class="route-summary">
            <h3>Route Summary — <?php echo htmlspecialchars($selectedDriverName); ?> · <?php echo htmlspecialchars($days[$selectedDay]); ?></h3>
            <div class="summary-grid">
                <div class="summary-item">
                    <span class="summary-label">Total Stops</span>
                    <span class="summary-value"><?php echo count($routeData); ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Total Items</span>
                    <span class="summary-value"><?php echo $totalItems; ?></span>
                </div>
                <div class="summary-item time-summary-item">
                    <span class="summary-label">Stops With Times</span>
                    <span class="summary-value"><?php echo $scheduledStopCount; ?> <small>of <?php echo count($routeData); ?></small></span>
                </div>
                <div class="summary-item time-summary-item">
                    <span class="summary-label">Scheduled Span</span>
                    <span class="summary-value summary-time-range">
                        <?php echo $routeStartTime ? htmlspecialchars(formatRouteTime($routeStartTime) . ' – ' . formatRouteTime($routeEndTime)) : 'Not scheduled'; ?>
                    </span>
                </div>
            </div>

            <?php if (!empty($productSummaryByDoughType)): ?>
                <div class="product-summary">
                    <h4>Products Needed</h4>
                    <table class="product-table">
                        <thead>
                            <tr>
                                <th>Dough Type</th>
                                <th>Product</th>
                                <th>Quantity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($productSummaryByDoughType as $doughType => $products): ?>
                                <?php $firstProduct = true; ?>
                                <?php foreach ($products as $product => $quantity): ?>
                                    <tr>
                                        <?php if ($firstProduct): ?>
                                            <td rowspan="<?php echo count($products); ?>" class="dough-type-cell">
                                                <?php echo htmlspecialchars($doughType); ?>
                                            </td>
                                            <?php $firstProduct = false; ?>
                                        <?php endif; ?>
                                        <td><?php echo htmlspecialchars($product); ?></td>
                                        <td class="quantity"><?php echo $quantity; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="route-customers">
            <?php if (!empty($routeData)): ?>
                <?php foreach ($routeData as $index => $customer): ?>
                    <div class="customer-card" data-route-stop data-search-text="<?php echo htmlspecialchars(strtolower($customer['customer_name'] . ' ' . ($customer['address'] ?? '')), ENT_QUOTES); ?>">
                        <div class="customer-header">
                            <div class="stop-heading">
                                <span class="stop-number" aria-label="Stop <?php echo $index + 1; ?>"><?php echo $index + 1; ?></span>
                                <div>
                                    <h3><?php echo htmlspecialchars($customer['customer_name']); ?></h3>
                                    <div class="stop-time-row">
                                        <?php if (!empty($customer['route_time'])): ?>
                                            <span class="time-badge time-scheduled"><i class="fas fa-clock"></i> <?php echo htmlspecialchars(formatRouteTime($customer['route_time'])); ?></span>
                                            <span class="time-caption"><?php echo htmlspecialchars($customer['route_time_label']); ?></span>
                                        <?php elseif (!empty($customer['deliver_after']) || !empty($customer['deliver_by'])): ?>
                                            <span class="time-badge time-window"><i class="fas fa-clock"></i>
                                                <?php if (!empty($customer['deliver_after']) && !empty($customer['deliver_by'])): ?>
                                                    <?php echo htmlspecialchars(formatRouteTime($customer['deliver_after']) . ' – ' . formatRouteTime($customer['deliver_by'])); ?>
                                                <?php elseif (!empty($customer['deliver_after'])): ?>
                                                    After <?php echo htmlspecialchars(formatRouteTime($customer['deliver_after'])); ?>
                                                <?php else: ?>
                                                    By <?php echo htmlspecialchars(formatRouteTime($customer['deliver_by'])); ?>
                                                <?php endif; ?>
                                            </span>
                                            <span class="time-caption">Customer window</span>
                                        <?php else: ?>
                                            <span class="time-badge time-missing"><i class="far fa-clock"></i> No time set</span>
                                        <?php endif; ?>
                                        <?php if (!empty($customer['stop_duration'])): ?>
                                            <span class="duration-label"><?php echo (int)$customer['stop_duration']; ?> min stop</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="customer-actions">
                                <?php if (!empty($customer['phone'])): ?>
                                    <a href="tel:<?php echo htmlspecialchars($customer['phone']); ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-phone"></i> Call
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($customer['address'])): ?>
                                    <a href="https://www.google.com/maps/search/?api=1&query=<?php echo urlencode($customer['address']); ?>"
                                       target="_blank" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-map-marker-alt"></i> Map
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="customer-details">
                            <?php if (!empty($customer['route_time']) && (!empty($customer['deliver_after']) || !empty($customer['deliver_by']))): ?>
                                <div class="delivery-window-note">
                                    Customer window:
                                    <?php echo !empty($customer['deliver_after']) ? 'after ' . htmlspecialchars(formatRouteTime($customer['deliver_after'])) : ''; ?>
                                    <?php echo (!empty($customer['deliver_after']) && !empty($customer['deliver_by'])) ? ' · ' : ''; ?>
                                    <?php echo !empty($customer['deliver_by']) ? 'by ' . htmlspecialchars(formatRouteTime($customer['deliver_by'])) : ''; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($customer['address'])): ?>
                                <p><strong>Address:</strong> <?php echo nl2br(htmlspecialchars($customer['address'])); ?></p>
                            <?php endif; ?>

                            <?php if (!empty($customer['phone'])): ?>
                                <p><strong>Phone:</strong>
                                    <a href="tel:<?php echo htmlspecialchars($customer['phone']); ?>">
                                        <?php echo htmlspecialchars($customer['phone']); ?>
                                    </a>
                                </p>
                            <?php endif; ?>

                            <?php if (!empty($customer['email'])): ?>
                                <p><strong>Email:</strong>
                                    <a href="mailto:<?php echo htmlspecialchars($customer['email']); ?>">
                                        <?php echo htmlspecialchars($customer['email']); ?>
                                    </a>
                                </p>
                            <?php endif; ?>

                            <?php if (!empty($customer['orders_by_dough_type'])): ?>
                                <div class="customer-orders">
                                    <strong>Standing Orders:</strong>
                                    <div class="order-items">
                                        <?php foreach ($customer['orders_by_dough_type'] as $doughType => $orders): ?>
                                            <strong><?php echo htmlspecialchars($doughType); ?></strong>
                                            <ul>
                                                <?php foreach ($orders as $order): ?>
                                                    <li><?php echo htmlspecialchars($order); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="no-orders">No standing orders for this day.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div id="route-search-empty" class="alert alert-info" hidden>No stops match that search.</div>
            <?php else: ?>
                <div class="alert alert-info">No customers found for the selected driver and day.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<style>
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }

    .page-header h1 {
        margin: 0;
    }

    .view-toggles {
        display: flex;
        gap: 8px;
    }

    .route-filters {
        background-color: #f8f9fa;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
    }

    .filter-form {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        gap: 12px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    label {
        font-weight: 500;
        font-size: 0.9rem;
    }

    .form-control {
        padding: 8px 10px;
        border: 1px solid #ced4da;
        border-radius: 6px;
        min-width: 180px;
    }

    .btn {
        padding: 8px 16px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .btn-primary { background: #4e73df; color: #fff; }
    .btn-secondary { background: #6c757d; color: #fff; }
    .btn-outline { background: transparent; color: #4e73df; border: 2px solid #4e73df; }
    .btn-sm { padding: 6px 12px; font-size: 12px; }

    .month-nav {
        display: flex;
        gap: 8px;
        margin-top: 12px;
        flex-wrap: wrap;
    }

    .route-summary {
        background: linear-gradient(135deg, #f2f7ff 0%, #f7fbf8 100%);
        padding: 22px;
        border: 1px solid #dce7f5;
        border-radius: 12px;
        margin-bottom: 25px;
        box-shadow: 0 8px 24px rgba(44, 62, 80, 0.06);
    }

    .route-summary h3 {
        margin-top: 0;
        color: #2c3e50;
    }

    .summary-note {
        margin: 0 0 12px;
        color: #6c757d;
        font-size: 0.92rem;
    }

    .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: 12px;
        margin-bottom: 15px;
    }

    .summary-item {
        display: flex;
        flex-direction: column;
        padding: 12px 14px;
        background: rgba(255,255,255,0.78);
        border: 1px solid rgba(209, 220, 235, 0.9);
        border-radius: 9px;
    }

    .summary-label {
        font-weight: 500;
        color: #2c3e50;
        margin-bottom: 3px;
    }

    .summary-value {
        font-size: 1.35em;
        font-weight: bold;
        color: #2c3e50;
    }

    .summary-value small { color: #7a8794; font-size: 0.58em; font-weight: 600; }
    .summary-time-range { font-size: 1.05rem; white-space: nowrap; }

    .summary-actions {
        margin-top: 8px;
    }

    .day-context {
        display: flex;
        align-items: center;
        gap: 16px;
        margin-bottom: 16px;
        flex-wrap: wrap;
    }

    .back-link {
        color: #4e73df;
        text-decoration: none;
        font-weight: 600;
    }

    .back-link.secondary {
        color: #6c757d;
        font-weight: 500;
    }

    .context-date {
        color: #6c757d;
        font-weight: 500;
    }

    .day-toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 12px;
        margin-bottom: 18px;
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        flex-wrap: wrap;
    }

    .day-navigation, .route-tools {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .route-search-label {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0 0 0 0);
    }

    .route-search { min-width: 220px; }
    .search-count { color: #6c757d; font-size: 0.82rem; min-width: 58px; }

    .month-calendar {
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: 10px;
        overflow: hidden;
        margin-bottom: 30px;
    }

    .calendar-weekdays,
    .calendar-grid {
        display: grid;
        grid-template-columns: repeat(7, minmax(0, 1fr));
    }

    .calendar-weekday {
        padding: 10px;
        text-align: center;
        font-weight: 700;
        background: #f1f3f5;
        border-bottom: 1px solid #dee2e6;
        font-size: 0.85rem;
    }

    .calendar-cell {
        min-height: 130px;
        padding: 8px;
        border-right: 1px solid #eef1f4;
        border-bottom: 1px solid #eef1f4;
        text-decoration: none;
        color: inherit;
        display: flex;
        flex-direction: column;
        gap: 6px;
        transition: background 0.2s, box-shadow 0.2s;
        background: #fff;
    }

    .calendar-cell:hover {
        background: #f8fbff;
        box-shadow: inset 0 0 0 2px #4e73df;
    }

    .calendar-cell.empty {
        background: #fafbfc;
        pointer-events: none;
    }

    .calendar-cell.is-today {
        background: #fff8e6;
    }

    .calendar-cell.is-weekend {
        background: #fcfcfd;
    }

    .calendar-cell.no-route {
        opacity: 0.75;
    }

    .cell-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 6px;
    }

    .cell-date {
        font-weight: 700;
        font-size: 1rem;
        color: #2c3e50;
    }

    .cell-stats {
        font-size: 0.72rem;
        color: #495057;
        text-align: right;
        line-height: 1.3;
    }

    .cell-stats.muted {
        color: #adb5bd;
    }

    .cell-customers {
        display: flex;
        flex-direction: column;
        gap: 4px;
        overflow: hidden;
    }

    .customer-chip {
        display: block;
        padding: 3px 6px;
        border-radius: 4px;
        font-size: 0.72rem;
        color: #fff;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .customer-more {
        font-size: 0.72rem;
        color: #6c757d;
        font-style: italic;
    }

    .weekly-pattern {
        margin-bottom: 30px;
    }

    .weekly-pattern h3 {
        margin-bottom: 6px;
        color: #2c3e50;
    }

    .pattern-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 12px;
    }

    .pattern-day {
        border: 1px solid #dee2e6;
        border-radius: 8px;
        background: #fff;
        padding: 12px;
    }

    .pattern-day.no-route {
        background: #f8f9fa;
    }

    .pattern-day-header {
        display: flex;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 8px;
        font-size: 0.9rem;
    }

    .pattern-day-header span {
        color: #6c757d;
        font-size: 0.8rem;
    }

    .pattern-customer-list {
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .pattern-customer-list li {
        padding: 4px 8px;
        margin-bottom: 4px;
        border-radius: 4px;
        font-size: 0.85rem;
        color: #fff;
    }

    .pattern-customer-list .item-count {
        opacity: 0.85;
        font-size: 0.8rem;
    }

    .pattern-empty {
        color: #6c757d;
        font-style: italic;
        font-size: 0.9rem;
    }

    .pattern-link {
        display: inline-block;
        margin-top: 8px;
        font-size: 0.85rem;
        color: #4e73df;
        text-decoration: none;
        font-weight: 600;
    }

    .list-controls {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
        flex-wrap: wrap;
    }

    .list-filter-toggles {
        display: flex;
        gap: 8px;
    }

    .delivery-list {
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: 10px;
        overflow: hidden;
        margin-bottom: 30px;
    }

    .delivery-list-table {
        width: 100%;
        border-collapse: collapse;
    }

    .delivery-list-table th {
        background: #f1f3f5;
        padding: 12px 14px;
        text-align: left;
        font-size: 0.85rem;
        font-weight: 700;
        color: #495057;
        border-bottom: 2px solid #dee2e6;
    }

    .delivery-list-table td {
        padding: 12px 14px;
        border-bottom: 1px solid #eef1f4;
        vertical-align: top;
    }

    .list-row.has-delivery {
        background: #f8fff9;
    }

    .list-row.no-delivery {
        background: #fafbfc;
        color: #6c757d;
    }

    .list-row.is-today {
        box-shadow: inset 4px 0 0 #ffc107;
    }

    .list-row.has-delivery.is-today {
        background: #fffdf5;
    }

    .list-date strong {
        color: #2c3e50;
    }

    .today-badge {
        display: inline-block;
        margin-left: 8px;
        padding: 2px 8px;
        background: #ffc107;
        color: #333;
        border-radius: 10px;
        font-size: 0.72rem;
        font-weight: 700;
        vertical-align: middle;
    }

    .status-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 0.78rem;
        font-weight: 700;
        white-space: nowrap;
    }

    .status-badge.delivery {
        background: #d4edda;
        color: #155724;
    }

    .status-badge.off {
        background: #e9ecef;
        color: #6c757d;
    }

    .list-stops,
    .list-items {
        font-weight: 600;
        text-align: center;
    }

    .list-customer-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
    }

    .list-customer-chips .customer-chip {
        display: inline-block;
        width: auto;
        max-width: 160px;
    }

    .muted-text {
        color: #adb5bd;
    }

    .list-empty {
        text-align: center;
        padding: 40px 20px !important;
        color: #6c757d;
        font-style: italic;
    }

    .list-action {
        text-align: right;
        white-space: nowrap;
    }

    .list-action-links {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .list-empty-block {
        text-align: center;
        padding: 50px 20px;
        color: #6c757d;
    }

    .list-empty-block h3 {
        margin: 0 0 8px;
        color: #495057;
    }

    .assignment-table .date-group-row td {
        background: #eef2ff;
        border-bottom: 1px solid #d8dff7;
        padding: 10px 14px;
    }

    .assignment-table .date-group-row.is-today td {
        background: #fff8e6;
        box-shadow: inset 4px 0 0 #ffc107;
    }

    .assignment-table .date-group-row.is-past td {
        background: #f8f9fa;
    }

    .date-group-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
    }

    .date-group-main {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #2c3e50;
    }

    .date-group-meta {
        color: #6c757d;
        font-size: 0.85rem;
        flex: 1;
    }

    .assignment-row td {
        background: #fff;
    }

    .assignment-row:hover td {
        background: #f8fbff;
    }

    .list-date-sub {
        color: #6c757d;
        font-size: 0.85rem;
        white-space: nowrap;
    }

    .list-route-order {
        text-align: center;
        font-weight: 700;
        color: #495057;
    }

    .list-customer-name .customer-chip {
        display: inline-block;
        width: auto;
        max-width: 220px;
    }

    .list-zone {
        font-size: 0.85rem;
        color: #495057;
    }

    .list-time {
        font-size: 0.85rem;
        white-space: nowrap;
        color: #495057;
    }

    .list-time-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 8px;
        color: #174f37;
        background: #e2f3ea;
        border: 1px solid #c3e2d1;
        border-radius: 7px;
        font-weight: 750;
    }

    .status-badge.status-pending {
        background: #fff3cd;
        color: #856404;
    }

    .status-badge.status-transit {
        background: #cce5ff;
        color: #004085;
    }

    .status-badge.status-delivered {
        background: #d4edda;
        color: #155724;
    }

    .status-badge.status-failed {
        background: #f8d7da;
        color: #721c24;
    }

    .status-badge.status-cancelled {
        background: #e9ecef;
        color: #6c757d;
    }

    .zone-centro { background-color: #007bff !important; }
    .zone-mission { background-color: #dc3545 !important; }
    .zone-ruta-sour-flour { background-color: #28a745 !important; }
    .zone-daly-city-san-mateo { background-color: #fd7e14 !important; }
    .zone-north-bay { background-color: #6f42c1 !important; }
    .zone-east-bay { background-color: #20c997 !important; }
    .zone-no-zone { background-color: #6c757d !important; }

    .product-summary { margin-top: 20px; }
    .product-summary h4 { margin: 0 0 10px; color: #2c3e50; }

    .product-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }

    .product-table th,
    .product-table td {
        padding: 8px 12px;
        text-align: left;
        border-bottom: 1px solid #dee2e6;
    }

    .product-table th {
        background-color: #f8f9fa;
        font-weight: 600;
    }

    .product-table .quantity {
        text-align: right;
        font-weight: bold;
    }

    .dough-type-cell {
        background-color: #e9ecef;
        font-weight: bold;
        vertical-align: top;
        border-right: 2px solid #dee2e6;
    }

    .customer-card {
        background-color: #fff;
        border: 1px solid #dfe5ec;
        border-radius: 12px;
        margin-bottom: 14px;
        box-shadow: 0 4px 14px rgba(35, 52, 70, 0.06);
        overflow: hidden;
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    }

    .customer-card:hover {
        transform: translateY(-1px);
        border-color: #bdcce0;
        box-shadow: 0 8px 22px rgba(35, 52, 70, 0.1);
    }

    .customer-header {
        background: #f8fafc;
        padding: 15px 17px;
        border-bottom: 1px solid #dee2e6;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
    }

    .customer-header h3 {
        margin: 0;
        font-size: 1.08rem;
        color: #24364b;
        line-height: 1.25;
    }

    .stop-heading { display: flex; align-items: center; gap: 12px; min-width: 0; }

    .stop-number {
        width: 38px;
        height: 38px;
        flex: 0 0 38px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        background: #355c9a;
        font-size: 0.95rem;
        font-weight: 800;
        box-shadow: 0 3px 8px rgba(53, 92, 154, 0.24);
    }

    .stop-time-row { display: flex; align-items: center; gap: 7px; margin-top: 6px; flex-wrap: wrap; }

    .time-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 9px;
        border-radius: 7px;
        font-size: 0.86rem;
        font-weight: 800;
        letter-spacing: 0.01em;
        white-space: nowrap;
    }

    .time-scheduled { color: #174f37; background: #dff3e9; border: 1px solid #b9dfcc; }
    .time-window { color: #664d03; background: #fff4ce; border: 1px solid #ecd98a; }
    .time-missing { color: #6f7780; background: #edf0f3; border: 1px solid #d9dee3; font-weight: 650; }
    .time-caption, .duration-label { color: #788593; font-size: 0.76rem; }
    .duration-label { padding-left: 7px; border-left: 1px solid #ccd4dc; }

    .customer-actions {
        display: flex;
        gap: 5px;
    }

    .customer-details { padding: 16px 18px 18px; }
    .customer-details p { margin-bottom: 8px; }

    .delivery-window-note {
        padding: 8px 10px;
        margin-bottom: 12px;
        color: #5a4a13;
        background: #fff9e6;
        border-left: 3px solid #e4bd3d;
        border-radius: 4px;
        font-size: 0.84rem;
        font-weight: 600;
    }

    .customer-orders {
        margin-top: 15px;
        padding: 10px;
        background-color: #f8f9fa;
        border-radius: 4px;
        border-left: 3px solid #28a745;
    }

    .customer-orders .order-items strong {
        display: block;
        margin-top: 10px;
        margin-bottom: 5px;
        color: #2c3e50;
        font-size: 0.9em;
        text-transform: uppercase;
    }

    .customer-orders .order-items strong:first-child { margin-top: 0; }
    .customer-orders .order-items ul { margin: 0 0 5px 20px; padding: 0; }
    .no-orders { font-style: italic; color: #6c757d; margin-top: 10px; }

    @media (max-width: 900px) {
        .calendar-cell { min-height: 100px; }
        .cell-stats { display: none; }
        .pattern-grid { grid-template-columns: 1fr; }
        .delivery-list { overflow-x: auto; }
        .delivery-list-table { min-width: 720px; }
        .day-toolbar, .day-navigation, .route-tools { align-items: stretch; }
        .day-navigation, .route-tools { width: 100%; }
        .day-navigation .btn { flex: 1; }
        .route-search { min-width: 0; flex: 1 1 180px; }
        .customer-header { align-items: flex-start; }
        .customer-actions { flex-direction: column; }
        .time-caption { display: none; }
        .summary-time-range { white-space: normal; }
    }

    @media (max-width: 560px) {
        .customer-header { padding: 13px; }
        .customer-details { padding: 13px; }
        .stop-heading { align-items: flex-start; gap: 9px; }
        .stop-number { width: 32px; height: 32px; flex-basis: 32px; }
        .time-badge { font-size: 0.8rem; }
        .duration-label { width: 100%; padding-left: 0; border-left: 0; }
    }

    @media print {
        .route-filters, .view-toggles, .customer-actions, .back-link, .summary-actions, .pattern-link, .day-toolbar {
            display: none;
        }
        .customer-card { page-break-inside: avoid; }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const monthInput = document.getElementById('month');
    const filterForm = document.querySelector('.filter-form');
    if (monthInput && filterForm) {
        monthInput.addEventListener('change', function() {
            if (document.getElementById('driver_id').value) {
                filterForm.submit();
            }
        });
    }

    document.querySelectorAll('#driver_id, #date').forEach(function(field) {
        field.addEventListener('change', function() {
            if (filterForm && document.getElementById('driver_id').value) {
                filterForm.submit();
            }
        });
    });

    const searchInput = document.getElementById('route-search');
    const stopCards = Array.from(document.querySelectorAll('[data-route-stop]'));
    const searchCount = document.getElementById('route-search-count');
    const searchEmpty = document.getElementById('route-search-empty');
    if (searchInput && stopCards.length) {
        const filterStops = function() {
            const query = searchInput.value.trim().toLocaleLowerCase();
            let visible = 0;
            stopCards.forEach(function(card) {
                const matches = !query || card.dataset.searchText.includes(query);
                card.hidden = !matches;
                if (matches) visible++;
            });
            searchCount.textContent = query ? visible + ' of ' + stopCards.length : stopCards.length + ' stops';
            searchEmpty.hidden = visible !== 0;
        };
        searchInput.addEventListener('input', filterStops);
        filterStops();
    }

    const printButton = document.getElementById('print-route');
    if (printButton) {
        printButton.addEventListener('click', function() { window.print(); });
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
