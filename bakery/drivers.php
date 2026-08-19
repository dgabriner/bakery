<?php
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/google_maps_config.php';

$page_title = bakery_t('page.drivers');
bakery_ensure_standing_routes_order_column($db);

$driverColors = [
    '#007bff', '#28a745', '#dc3545', '#fd7e14', '#6f42c1',
    '#20c997', '#ffc107', '#e83e8c', '#6c757d', '#17a2b8'
];

$days = [
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
    7 => 'Sunday'
];

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_POST['action']) {
            case 'create':
                $name = trim($_POST['name'] ?? '');
                if ($name === '') {
                    throw new Exception('Driver name is required');
                }
                $stmt = $db->prepare('INSERT INTO drivers (name) VALUES (?)');
                $stmt->execute([$name]);
                echo json_encode(['success' => true, 'message' => 'Driver created successfully', 'id' => $db->lastInsertId()]);
                exit;

            case 'update':
                $id = (int)($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                if ($id <= 0) {
                    throw new Exception('Invalid driver ID');
                }
                if ($name === '') {
                    throw new Exception('Driver name is required');
                }
                bakery_ensure_drivers_archived_column($db);
                $stmt = $db->prepare('UPDATE drivers SET name = ? WHERE id = ?');
                $stmt->execute([$name, $id]);
                echo json_encode(['success' => true, 'message' => 'Driver updated successfully']);
                exit;

            case 'archive':
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception('Invalid driver ID');
                }
                bakery_ensure_drivers_archived_column($db);
                $stmt = $db->prepare('UPDATE drivers SET archived = 1, archived_at = NOW() WHERE id = ?');
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Driver archived successfully']);
                exit;

            case 'unarchive':
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception('Invalid driver ID');
                }
                bakery_ensure_drivers_archived_column($db);
                $stmt = $db->prepare('UPDATE drivers SET archived = 0, archived_at = NULL WHERE id = ?');
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Driver restored successfully']);
                exit;

            case 'delete':
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception('Invalid driver ID');
                }

                $stmt = $db->prepare('SELECT COUNT(*) FROM standing_routes WHERE driver_id = ?');
                $stmt->execute([$id]);
                $routeCount = (int)$stmt->fetchColumn();

                $stmt = $db->prepare('SELECT COUNT(*) FROM daily_order_assignments WHERE driver_id = ?');
                $stmt->execute([$id]);
                $assignmentCount = (int)$stmt->fetchColumn();

                $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE driver_id = ?');
                $stmt->execute([$id]);
                $userCount = (int)$stmt->fetchColumn();

                if ($routeCount > 0 || $assignmentCount > 0 || $userCount > 0) {
                    $parts = [];
                    if ($routeCount > 0) {
                        $parts[] = "$routeCount standing route(s)";
                    }
                    if ($assignmentCount > 0) {
                        $parts[] = "$assignmentCount daily assignment(s)";
                    }
                    if ($userCount > 0) {
                        $parts[] = "$userCount linked user account(s)";
                    }
                    throw new Exception('Cannot delete driver: has ' . implode(', ', $parts) . '. Remove assignments first.');
                }

                $stmt = $db->prepare('DELETE FROM drivers WHERE id = ?');
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Driver deleted successfully']);
                exit;

            case 'save_route':
                $driverId = (int)$_POST['driver_id'];
                $customerId = (int)$_POST['customer_id'];
                $dayOfWeek = (int)$_POST['day_of_week'];
                // Canonical weekday numbering is 1=Mon ... 7=Sun.
                if ($dayOfWeek === 0) {
                    $dayOfWeek = 7;
                }
                if ($dayOfWeek < 1 || $dayOfWeek > 7) {
                    throw new Exception('Invalid day of week');
                }

                bakery_ensure_drivers_archived_column($db);
                if ($driverId > 0) {
                    $check = $db->prepare('SELECT archived FROM drivers WHERE id = ?');
                    $check->execute([$driverId]);
                    $driverRow = $check->fetch();
                    if ($driverRow && (int)$driverRow['archived'] === 1) {
                        throw new Exception('Cannot modify routes for an archived driver. Restore the driver first.');
                    }
                }

                if ($dayOfWeek === 7) {
                    // Remove both canonical Sunday=7 and legacy Sunday=0 rows.
                    $stmt = $db->prepare('DELETE FROM standing_routes WHERE customer_id = ? AND day_of_week IN (0, 7)');
                    $stmt->execute([$customerId]);
                } else {
                    $stmt = $db->prepare('DELETE FROM standing_routes WHERE customer_id = ? AND day_of_week = ?');
                    $stmt->execute([$customerId, $dayOfWeek]);
                }

                if ($driverId > 0) {
                    $maxDaySql = $dayOfWeek === 7 ? 'day_of_week IN (0, 7)' : 'day_of_week = ?';
                    $maxOrder = $db->prepare("SELECT COALESCE(MAX(route_order), 0) FROM standing_routes WHERE driver_id = ? AND $maxDaySql");
                    $maxOrder->execute($dayOfWeek === 7 ? [$driverId] : [$driverId, $dayOfWeek]);
                    $routeOrder = (int)$maxOrder->fetchColumn() + 1;
                    $stmt = $db->prepare('INSERT INTO standing_routes (driver_id, customer_id, day_of_week, route_order) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$driverId, $customerId, $dayOfWeek, $routeOrder]);
                }

                echo json_encode(['success' => true]);
                exit;

            case 'save_route_order':
                $driverId = (int)($_POST['driver_id'] ?? 0);
                $dayOfWeek = (int)($_POST['day_of_week'] ?? 0);
                $customerIds = json_decode((string)($_POST['customer_ids'] ?? '[]'), true);
                if ($dayOfWeek === 0) {
                    $dayOfWeek = 7;
                }
                if ($driverId <= 0 || $dayOfWeek < 1 || $dayOfWeek > 7 || !is_array($customerIds)) {
                    throw new Exception('Invalid route order request');
                }

                $customerIds = array_values(array_unique(array_filter(array_map('intval', $customerIds), function ($id) {
                    return $id > 0;
                })));
                $daySql = $dayOfWeek === 7 ? 'day_of_week IN (0, 7)' : 'day_of_week = ?';
                $params = $dayOfWeek === 7 ? [$driverId] : [$driverId, $dayOfWeek];
                $check = $db->prepare("SELECT customer_id FROM standing_routes WHERE driver_id = ? AND $daySql");
                $check->execute($params);
                $existingIds = array_map('intval', $check->fetchAll(PDO::FETCH_COLUMN));
                sort($existingIds);
                $submittedIds = $customerIds;
                sort($submittedIds);
                if ($existingIds !== $submittedIds) {
                    throw new Exception('The route changed while it was being reordered. Refresh and try again.');
                }

                $db->beginTransaction();
                try {
                    $offsetUpdate = $db->prepare("UPDATE standing_routes SET route_order = route_order + 10000 WHERE driver_id = ? AND $daySql");
                    $offsetUpdate->execute($params);
                    $update = $db->prepare("UPDATE standing_routes SET route_order = ? WHERE driver_id = ? AND customer_id = ? AND $daySql");
                    foreach ($customerIds as $index => $id) {
                        $updateParams = $dayOfWeek === 7
                            ? [$index + 1, $driverId, $id]
                            : [$index + 1, $driverId, $id, $dayOfWeek];
                        $update->execute($updateParams);
                    }
                    $db->commit();
                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }

                echo json_encode(['success' => true, 'message' => 'Standing route order saved']);
                exit;
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Load data
$drivers = [];
$archivedDrivers = [];
$customers = [];
$customersByZone = [];
$routes = [];
$driverStats = [];
$error = null;
$showArchived = isset($_GET['show_archived']) && $_GET['show_archived'] === '1';

try {
    bakery_ensure_drivers_archived_column($db);

    $activeDriversData = bakery_get_drivers($db, false);
    $archivedDriversData = $db->query('SELECT id, name, archived_at FROM drivers WHERE archived = 1 ORDER BY name')->fetchAll();

    foreach ($activeDriversData as $index => $driver) {
        $drivers[$driver['id']] = [
            'name' => $driver['name'],
            'color' => $driverColors[$index % count($driverColors)],
            'archived' => false,
        ];
    }

    foreach ($archivedDriversData as $index => $driver) {
        $archivedDrivers[$driver['id']] = [
            'name' => $driver['name'],
            'color' => $driverColors[$index % count($driverColors)],
            'archived_at' => $driver['archived_at'],
        ];
    }

    $stmt = $db->query('
        SELECT driver_id, COUNT(*) as route_count
        FROM standing_routes
        GROUP BY driver_id
    ');
    foreach ($stmt->fetchAll() as $row) {
        $driverStats[$row['driver_id']] = (int)$row['route_count'];
    }

    $customers = $db->query("
        SELECT id, name, zone, address, latitude, longitude, deliver_by, deliver_after,
               COALESCE(delivery_time, 20) AS delivery_time
        FROM customers
        ORDER BY
            CASE WHEN zone IS NULL OR zone = '' THEN 'ZZZ_No Zone' ELSE zone END,
            name
    ")->fetchAll();

    foreach ($customers as $customer) {
        $zone = $customer['zone'] ?: 'No Zone';
        if (!isset($customersByZone[$zone])) {
            $customersByZone[$zone] = [];
        }
        $customersByZone[$zone][] = $customer;
    }

    $routesResult = $db->query(
        'SELECT r.driver_id, r.customer_id, r.day_of_week, r.route_order,
                c.name as customer_name, c.zone as customer_zone, c.address,
                c.latitude, c.longitude, c.deliver_by, c.deliver_after,
                COALESCE(c.delivery_time, 20) AS delivery_time
         FROM standing_routes r
         JOIN customers c ON r.customer_id = c.id
         WHERE 1=1'
        . bakery_sfb_ops_origin_clause('c', $db)
        . ' ORDER BY r.day_of_week, r.driver_id, COALESCE(r.route_order, 2147483647), c.name'
    )->fetchAll();

    foreach ($routesResult as $route) {
        $dayOfWeek = (int)$route['day_of_week'];
        // Display legacy Sunday=0 in the canonical Sunday=7 column.
        if ($dayOfWeek === 0) {
            $dayOfWeek = 7;
        }
        $routes[$dayOfWeek][$route['customer_id']] = [
            'driver_id' => $route['driver_id'],
            'customer_name' => $route['customer_name'],
            'customer_zone' => $route['customer_zone'],
            'route_order' => $route['route_order'] === null ? null : (int)$route['route_order'],
            'address' => $route['address'],
            'latitude' => $route['latitude'],
            'longitude' => $route['longitude'],
            'deliver_by' => $route['deliver_by'],
            'deliver_after' => $route['deliver_after'],
            'delivery_time' => (int)$route['delivery_time']
        ];
    }
} catch (Exception $e) {
    $error = 'Error loading data: ' . htmlspecialchars($e->getMessage());
}

$selectedDriverId = isset($_GET['driver_id']) ? (int)$_GET['driver_id'] : 0;
$viewingArchived = false;

if ($selectedDriverId > 0 && isset($archivedDrivers[$selectedDriverId])) {
    $viewingArchived = true;
    $driversForSelection = $archivedDrivers;
} else {
    $driversForSelection = $drivers;
}

if ($selectedDriverId <= 0 && !empty($driversForSelection)) {
    $selectedDriverId = (int)array_key_first($driversForSelection);
}
if ($selectedDriverId > 0 && !isset($driversForSelection[$selectedDriverId]) && !isset($drivers[$selectedDriverId])) {
    $selectedDriverId = !empty($drivers) ? (int)array_key_first($drivers) : 0;
    $viewingArchived = false;
    $driversForSelection = $drivers;
}

$allDriversForPanel = $viewingArchived ? $archivedDrivers : $drivers;

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>

<div class="drivers-page<?php echo $viewingArchived ? ' viewing-archived' : ''; ?>">
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>

    <div class="page-header">
        <h1>Driver Management</h1>
        <p>Create drivers, archive inactive ones, and manage standing routes by driver</p>
    </div>

    <div class="drivers-layout">
        <!-- Driver List Sidebar -->
        <aside class="driver-sidebar">
            <div class="sidebar-header">
                <h2>Active Drivers (<?php echo count($drivers); ?>)</h2>
                <button type="button" class="btn btn-primary btn-sm" onclick="openDriverModal()">+ Add</button>
            </div>

            <?php if (empty($drivers)): ?>
                <div class="empty-drivers">
                    <p>No active drivers</p>
                    <button type="button" class="btn btn-primary" onclick="openDriverModal()">Create First Driver</button>
                </div>
            <?php else: ?>
                <div class="driver-list">
                    <?php foreach ($drivers as $driverId => $driverInfo): ?>
                        <a href="drivers.php?driver_id=<?php echo $driverId; ?>"
                           class="driver-list-item <?php echo $driverId == $selectedDriverId && !$viewingArchived ? 'active' : ''; ?>"
                           data-driver-id="<?php echo $driverId; ?>">
                            <div class="driver-avatar" style="background: <?php echo $driverInfo['color']; ?>">
                                <?php echo strtoupper(substr($driverInfo['name'], 0, 1)); ?>
                            </div>
                            <div class="driver-list-info">
                                <span class="driver-list-name"><?php echo htmlspecialchars($driverInfo['name']); ?></span>
                                <span class="driver-list-stats">
                                    <?php
                                    $count = $driverStats[$driverId] ?? 0;
                                    echo $count . ' route' . ($count !== 1 ? 's' : '');
                                    ?>
                                </span>
                            </div>
                            <div class="driver-list-actions" onclick="event.preventDefault(); event.stopPropagation();">
                                <button type="button" class="btn-icon" title="Edit name"
                                        onclick="openDriverModal(<?php echo $driverId; ?>, '<?php echo htmlspecialchars($driverInfo['name'], ENT_QUOTES); ?>')">✏️</button>
                                <button type="button" class="btn-icon btn-icon-archive" title="Archive driver"
                                        onclick="archiveDriver(<?php echo $driverId; ?>, '<?php echo htmlspecialchars($driverInfo['name'], ENT_QUOTES); ?>')">📦</button>
                                <button type="button" class="btn-icon btn-icon-danger" title="Delete driver"
                                        onclick="deleteDriver(<?php echo $driverId; ?>, '<?php echo htmlspecialchars($driverInfo['name'], ENT_QUOTES); ?>')">🗑️</button>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($archivedDrivers)): ?>
                <div class="archived-section">
                    <button type="button" class="archived-toggle" onclick="toggleArchivedSection(this)" aria-expanded="<?php echo $showArchived ? 'true' : 'false'; ?>">
                        <span>Archived Drivers (<?php echo count($archivedDrivers); ?>)</span>
                        <span class="archived-arrow"><?php echo $showArchived ? '▲' : '▼'; ?></span>
                    </button>
                    <div class="driver-list archived-list" id="archivedDriverList" style="<?php echo $showArchived ? '' : 'display:none;'; ?>">
                        <?php foreach ($archivedDrivers as $driverId => $driverInfo): ?>
                            <a href="drivers.php?driver_id=<?php echo $driverId; ?>&show_archived=1"
                               class="driver-list-item archived <?php echo $driverId == $selectedDriverId && $viewingArchived ? 'active' : ''; ?>">
                                <div class="driver-avatar archived-avatar" style="background: <?php echo $driverInfo['color']; ?>">
                                    <?php echo strtoupper(substr($driverInfo['name'], 0, 1)); ?>
                                </div>
                                <div class="driver-list-info">
                                    <span class="driver-list-name"><?php echo htmlspecialchars($driverInfo['name']); ?></span>
                                    <span class="driver-list-stats">Archived</span>
                                </div>
                                <div class="driver-list-actions" onclick="event.preventDefault(); event.stopPropagation();">
                                    <button type="button" class="btn-icon btn-icon-restore" title="Restore driver"
                                            onclick="unarchiveDriver(<?php echo $driverId; ?>, '<?php echo htmlspecialchars($driverInfo['name'], ENT_QUOTES); ?>')">↩️</button>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </aside>

        <!-- Route Management Panel -->
        <main class="driver-routes-panel">
            <?php if ($selectedDriverId > 0 && isset($allDriversForPanel[$selectedDriverId])): ?>
                <?php $selectedDriver = $allDriversForPanel[$selectedDriverId]; ?>
                <div class="panel-header">
                    <div class="panel-title">
                        <div class="driver-avatar large" style="background: <?php echo $selectedDriver['color']; ?>">
                            <?php echo strtoupper(substr($selectedDriver['name'], 0, 1)); ?>
                        </div>
                        <div>
                            <h2><?php echo htmlspecialchars($selectedDriver['name']); ?></h2>
                            <span class="panel-subtitle">
                                <?php echo $viewingArchived ? 'Archived Driver — Standing Routes (read-only)' : 'Standing Routes'; ?>
                            </span>
                        </div>
                    </div>
                    <div class="panel-header-actions">
                        <?php if ($viewingArchived): ?>
                            <button type="button" class="btn btn-primary btn-sm"
                                    onclick="unarchiveDriver(<?php echo $selectedDriverId; ?>, '<?php echo htmlspecialchars($selectedDriver['name'], ENT_QUOTES); ?>')">
                                ↩️ Restore Driver
                            </button>
                        <?php else: ?>
                            <button type="button" class="btn btn-secondary btn-sm"
                                    onclick="openDriverModal(<?php echo $selectedDriverId; ?>, '<?php echo htmlspecialchars($selectedDriver['name'], ENT_QUOTES); ?>')">
                                ✏️ Edit Name
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm"
                                    onclick="archiveDriver(<?php echo $selectedDriverId; ?>, '<?php echo htmlspecialchars($selectedDriver['name'], ENT_QUOTES); ?>')">
                                📦 Archive
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($viewingArchived): ?>
                    <div class="archived-banner">
                        This driver is archived and hidden from route assignment dropdowns. Restore them to use again in daily operations.
                    </div>
                <?php endif; ?>

                <div class="filter-info">
                    <span id="filter-status">Showing: All Days</span>
                    <button id="clear-filter" style="display: none;" class="btn btn-sm btn-secondary">Show All Days</button>
                </div>

                <div class="zone-legend">
                    <h4>Zone Color Legend</h4>
                    <div class="zone-colors">
                        <div class="zone-color-item zone-centro"><span class="zone-name">Centro</span></div>
                        <div class="zone-color-item zone-mission"><span class="zone-name">Mission</span></div>
                        <div class="zone-color-item zone-ruta-sour-flour"><span class="zone-name">Ruta Sour Flour</span></div>
                        <div class="zone-color-item zone-daly-city-san-mateo"><span class="zone-name">Daly City San Mateo</span></div>
                        <div class="zone-color-item zone-north-bay"><span class="zone-name">North Bay</span></div>
                        <div class="zone-color-item zone-east-bay"><span class="zone-name">East Bay</span></div>
                        <div class="zone-color-item zone-no-zone"><span class="zone-name">No Zone</span></div>
                    </div>
                </div>

                <div class="routes-container" id="routes-container">
                    <div class="days-header">
                        <?php foreach ($days as $dayNum => $dayName): ?>
                            <div class="day-header clickable-day" data-day="<?php echo $dayNum; ?>" title="Click to filter by <?php echo $dayName; ?>">
                                <?php echo $dayName; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="driver-route-row">
                        <?php foreach ($days as $dayNum => $dayName): ?>
                            <div class="day-cell" data-driver-id="<?php echo $selectedDriverId; ?>" data-day="<?php echo $dayNum; ?>">
                                <div class="route-day-tools">
                                    <button type="button" class="btn btn-primary btn-sm optimize-route-btn"
                                            data-day="<?php echo $dayNum; ?>"
                                            <?php echo $viewingArchived ? 'disabled' : ''; ?>>Optimize</button>
                                    <span class="route-start-time">Start 6:40 AM</span>
                                    <span class="route-estimate" aria-live="polite">No stops</span>
                                </div>
                                <div class="customer-list" data-day="<?php echo $dayNum; ?>">
                                    <?php
                                    $driverRoutes = array_filter($routes[$dayNum] ?? [], function ($route) use ($selectedDriverId) {
                                        return $route['driver_id'] == $selectedDriverId;
                                    });

                                    uasort($driverRoutes, function ($a, $b) {
                                        $orderA = $a['route_order'] ?? PHP_INT_MAX;
                                        $orderB = $b['route_order'] ?? PHP_INT_MAX;
                                        return $orderA !== $orderB
                                            ? $orderA <=> $orderB
                                            : strcmp($a['customer_name'], $b['customer_name']);
                                    });

                                    foreach ($driverRoutes as $customerId => $route):
                                        $zone = $route['customer_zone'] ?: 'No Zone';
                                        $zoneClass = 'zone-' . strtolower(str_replace([' ', '/'], ['-', '-'], $zone));
                                    ?>
                                        <div class="assigned-customer <?php echo $zoneClass; ?>"
                                             draggable="true"
                                             data-customer-id="<?php echo $customerId; ?>"
                                             data-customer-name="<?php echo htmlspecialchars($route['customer_name']); ?>"
                                             data-customer-zone="<?php echo htmlspecialchars($zone); ?>"
                                             data-address="<?php echo htmlspecialchars((string)$route['address']); ?>"
                                             data-latitude="<?php echo htmlspecialchars((string)$route['latitude']); ?>"
                                             data-longitude="<?php echo htmlspecialchars((string)$route['longitude']); ?>"
                                             data-deliver-by="<?php echo htmlspecialchars((string)$route['deliver_by']); ?>"
                                             data-deliver-after="<?php echo htmlspecialchars((string)$route['deliver_after']); ?>"
                                             data-delivery-time="<?php echo (int)$route['delivery_time']; ?>">
                                            <span class="route-stop-time route-arrival-time"><small>Arrive</small><strong>--:--</strong></span>
                                            <button type="button" class="route-drag-handle" aria-label="Drag <?php echo htmlspecialchars($route['customer_name']); ?> to reorder" title="Drag to reorder">⋮⋮</button>
                                            <span class="route-position" aria-hidden="true"></span>
                                            <span class="customer-name"><?php echo htmlspecialchars($route['customer_name']); ?></span>
                                            <span class="stop-duration"><?php echo (int)$route['delivery_time']; ?>m</span>
                                            <span class="route-stop-time route-departure-time"><small>Leave</small><strong>--:--</strong></span>
                                            <span class="route-move-buttons">
                                                <button type="button" class="route-move-btn" data-move="up" aria-label="Move up">↑</button>
                                                <button type="button" class="route-move-btn" data-move="down" aria-label="Move down">↓</button>
                                            </span>
                                            <span class="delete-customer" title="Remove from route">×</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="customers-container">
                    <h3>Available Customers</h3>
                    <div id="customer-instruction" class="instruction-text">
                        Click a customer to assign them to a driver and day, or drag them to a day column. Click assigned customers to move them to another driver or day. Customers are color-coded by delivery zone.
                    </div>

                    <?php foreach ($customersByZone as $zoneName => $zoneCustomers):
                        $zoneClass = 'zone-' . strtolower(str_replace([' ', '/'], ['-', '-'], $zoneName));
                    ?>
                        <div class="zone-group">
                            <div class="zone-group-header <?php echo $zoneClass; ?>" onclick="toggleZoneGroup(this)">
                                <div class="zone-header-content">
                                    <h4 class="zone-group-title">
                                        <?php
                                        $zoneIcons = [
                                            'Centro' => '🏢',
                                            'Mission' => '🌮',
                                            'Ruta Sour Flour' => '🍞',
                                            'Daly City/San Mateo' => '🌉',
                                            'North Bay' => '🌲',
                                            'East Bay' => '🏔️',
                                            'No Zone' => '📍'
                                        ];
                                        echo ($zoneIcons[$zoneName] ?? '🗺️') . ' ' . htmlspecialchars($zoneName);
                                        ?>
                                    </h4>
                                    <span class="zone-customer-count"><?php echo count($zoneCustomers); ?> customers</span>
                                </div>
                                <span class="zone-toggle-icon">▼</span>
                            </div>
                            <div class="customers-list">
                                <?php foreach ($zoneCustomers as $customer): ?>
                                    <div class="customer-item clickable-customer <?php echo $zoneClass; ?>"
                                         draggable="true"
                                         data-customer-id="<?php echo $customer['id']; ?>"
                                         data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                         data-customer-zone="<?php echo htmlspecialchars($zoneName); ?>">
                                        <span class="customer-name"><?php echo htmlspecialchars($customer['name']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h3>Select or create a driver</h3>
                    <p>Add a driver from the sidebar to start managing standing routes.</p>
                    <button type="button" class="btn btn-primary" onclick="openDriverModal()">+ Add Driver</button>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Day Assignment Modal -->
<div id="assignment-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Assign Customer to Route</h3>
            <span class="close">&times;</span>
        </div>
        <div class="modal-body">
            <p>Assign <strong id="modal-customer-name"></strong> to a driver and day:</p>
            <?php if (!empty($drivers)): ?>
            <div class="selection-section">
                <h4>Select Driver:</h4>
                <div class="driver-icons-grid" id="modal-driver-options">
                    <?php foreach ($drivers as $driverId => $driverInfo): ?>
                        <div class="driver-icon-option" onclick="selectDriverInModal('<?php echo $driverId; ?>')" data-driver-id="<?php echo $driverId; ?>">
                            <div class="driver-avatar" style="background: <?php echo $driverInfo['color']; ?>">
                                <?php echo strtoupper(substr($driverInfo['name'], 0, 1)); ?>
                            </div>
                            <span class="driver-icon-name"><?php echo htmlspecialchars($driverInfo['name']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="selection-section">
                <h4>Select Day:</h4>
                <div class="day-icons-grid">
                    <div class="day-icon-option no-day" onclick="selectDayInModal('0')" data-day="0">
                        <div class="day-icon-preview"><span class="day-icon-name">Remove</span></div>
                    </div>
                    <?php foreach ($days as $dayNum => $dayName): ?>
                        <div class="day-icon-option" onclick="selectDayInModal('<?php echo $dayNum; ?>')" data-day="<?php echo $dayNum; ?>">
                            <div class="day-icon-preview"><span class="day-icon-name"><?php echo $dayName; ?></span></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create/Edit Driver Modal -->
<div id="driverModal" class="modal">
    <div class="modal-content modal-sm">
        <span class="close" onclick="closeDriverModal()">&times;</span>
        <h2 id="driverModalTitle">Add Driver</h2>
        <form id="driverForm">
            <input type="hidden" id="driverId" name="id">
            <div class="form-group">
                <label for="driverName">Driver Name *</label>
                <input type="text" id="driverName" name="name" class="form-control" required placeholder="e.g. John Smith">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeDriverModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="driverSubmitBtn">Create Driver</button>
            </div>
        </form>
    </div>
</div>

<div id="messageBar" class="message-bar" style="display: none;"><span id="messageText"></span></div>

<style>
.drivers-page { max-width: 1400px; margin: 0 auto; padding: 20px; }
.page-header { background: #f8f9fa; padding: 20px; border-radius: 10px; margin-bottom: 20px; text-align: center; }
.page-header h1 { margin: 0 0 8px; color: #2c3e50; font-size: 1.8rem; }
.page-header p { margin: 0; color: #6c757d; }

.drivers-layout { display: grid; grid-template-columns: 280px 1fr; gap: 20px; align-items: start; }

.driver-sidebar { background: white; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); border: 1px solid #dee2e6; overflow: hidden; position: sticky; top: 80px; }
.sidebar-header { display: flex; justify-content: space-between; align-items: center; padding: 15px; border-bottom: 1px solid #dee2e6; background: #f8f9fa; }
.sidebar-header h2 { margin: 0; font-size: 1rem; color: #495057; }

.driver-list { max-height: calc(100vh - 200px); overflow-y: auto; }
.driver-list-item { display: flex; align-items: center; gap: 12px; padding: 12px 15px; border-bottom: 1px solid #f1f3f4; text-decoration: none; color: inherit; transition: background 0.2s; cursor: pointer; }
.driver-list-item:hover { background: #f8f9fa; }
.driver-list-item.active { background: #e7f1ff; border-left: 4px solid #007bff; }

.driver-avatar { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 16px; flex-shrink: 0; }
.driver-avatar.large { width: 48px; height: 48px; font-size: 20px; }

.driver-list-info { flex: 1; min-width: 0; }
.driver-list-name { display: block; font-weight: 600; color: #2c3e50; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.driver-list-stats { font-size: 0.8rem; color: #6c757d; }

.driver-list-actions { display: flex; gap: 4px; opacity: 0; transition: opacity 0.2s; }
.driver-list-item:hover .driver-list-actions { opacity: 1; }
.btn-icon { background: none; border: none; cursor: pointer; padding: 4px; font-size: 14px; border-radius: 4px; }
.btn-icon:hover { background: #e9ecef; }
.btn-icon-danger:hover { background: #f8d7da; }
.btn-icon-archive:hover { background: #fff3cd; }
.btn-icon-restore:hover { background: #d4edda; }

.archived-section {
    border-top: 1px solid #dee2e6;
    background: #f8f9fa;
}

.archived-toggle {
    width: 100%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 15px;
    border: none;
    background: transparent;
    cursor: pointer;
    font-weight: 600;
    color: #6c757d;
    font-size: 0.9rem;
}

.archived-toggle:hover { background: #eef1f4; }

.archived-list .driver-list-item.archived {
    opacity: 0.85;
}

.archived-avatar {
    filter: grayscale(0.6);
}

.panel-header-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.archived-banner {
    background: #fff3cd;
    border: 1px solid #ffeeba;
    color: #856404;
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    font-size: 0.92rem;
}

.drivers-page.viewing-archived .customers-container,
.drivers-page.viewing-archived .routes-container,
.drivers-page.viewing-archived .filter-info {
    opacity: 0.75;
    pointer-events: none;
}

.empty-drivers { padding: 30px 15px; text-align: center; color: #6c757d; }

.driver-routes-panel { background: white; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); border: 1px solid #dee2e6; padding: 20px; min-height: 400px; }
.panel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #dee2e6; }
.panel-title { display: flex; align-items: center; gap: 15px; }
.panel-title h2 { margin: 0; color: #2c3e50; }
.panel-subtitle { color: #6c757d; font-size: 0.9rem; }

.filter-info { margin-bottom: 15px; padding: 10px; background: #e9ecef; border-radius: 5px; font-weight: bold; display: flex; align-items: center; gap: 10px; }
.instruction-text { font-size: 0.9em; color: #6c757d; margin-bottom: 10px; font-style: italic; }

.zone-legend { margin-bottom: 15px; padding: 10px; background: #f8f9fa; border-radius: 8px; }
.zone-legend h4 { margin: 0 0 8px; font-size: 0.9rem; }
.zone-colors { display: flex; flex-wrap: wrap; gap: 8px; }
.zone-color-item { padding: 4px 10px; border-radius: 4px; font-size: 0.8rem; font-weight: 600; color: white; }

.routes-container { margin-bottom: 25px; overflow-x: auto; }
.days-header, .driver-route-row { display: flex; border-bottom: 1px solid #dee2e6; }
.day-header { flex: 1; min-width: 220px; padding: 10px; text-align: center; font-weight: bold; background: #e9ecef; border-right: 1px solid #dee2e6; transition: background 0.2s; }
.day-header.clickable-day { cursor: pointer; user-select: none; }
.day-header.clickable-day:hover { background: #dee2e6; }
.day-header.active-filter { background: #4e73df; color: white; }
.day-cell { flex: 1; min-width: 220px; min-height: 120px; padding: 8px; border-right: 1px solid #dee2e6; background: #fff; }
.day-cell.drag-over { background: #e7f1ff; border: 2px dashed #007bff; }
.customer-list { min-height: 100px; }
.route-day-tools { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.route-day-tools .btn { padding: 5px 8px; }
.route-start-time { color: #2c3e50; font-size: 0.7rem; font-weight: 800; white-space: nowrap; }
.route-estimate { color: #5f6b76; font-size: 0.72rem; font-weight: 700; line-height: 1.2; text-align: right; }

.assigned-customer, .customer-item { position: relative; padding: 8px 25px 8px 8px; margin: 4px 0; border-radius: 4px; font-size: 0.9em; cursor: pointer; display: flex; align-items: center; color: white; text-shadow: 0 1px 2px rgba(0,0,0,0.7); transition: transform 0.2s, box-shadow 0.2s, opacity 0.2s; }
.assigned-customer:hover, .customer-item:hover { transform: translateY(-1px); box-shadow: 0 2px 4px rgba(0,0,0,0.15); }
.assigned-customer .customer-name, .customer-item .customer-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; font-weight: 500; }
.assigned-customer { gap: 5px; padding-left: 5px; }
.assigned-customer.route-dragging { opacity: 0.45; transform: none; }
.assigned-customer.route-drop-target { box-shadow: 0 -3px 0 #182433; }
.route-drag-handle, .route-move-btn { border: 0; border-radius: 4px; background: rgba(255,255,255,0.22); color: #fff; font-weight: 800; text-shadow: 0 1px 2px rgba(0,0,0,0.7); cursor: grab; }
.route-drag-handle { padding: 4px 3px; touch-action: none; user-select: none; flex: 0 0 auto; }
.route-drag-handle:active { cursor: grabbing; }
.route-position { min-width: 1.25em; font-weight: 800; text-align: center; }
.stop-duration { font-size: 0.72rem; opacity: 0.9; white-space: nowrap; }
.route-stop-time { min-width: 3.15rem; display: inline-flex; flex-direction: column; line-height: 1.05; white-space: nowrap; text-align: center; }
.route-stop-time small { font-size: 0.56rem; font-weight: 700; opacity: 0.82; text-transform: uppercase; letter-spacing: 0.02em; }
.route-stop-time strong { font-size: 0.7rem; }
.route-arrival-time { text-align: left; }
.route-departure-time { text-align: right; }
.route-move-buttons { display: none; gap: 2px; }
.route-move-btn { width: 24px; height: 24px; padding: 0; cursor: pointer; }
.route-move-btn:disabled { opacity: 0.35; cursor: default; }
.delete-customer { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); cursor: pointer; font-size: 1.2em; color: #ffc107; opacity: 0.9; }
.delete-customer:hover { opacity: 1; color: #fff; }

.zone-centro, .zone-color-item.zone-centro { background-color: #007bff !important; }
.zone-mission, .zone-color-item.zone-mission { background-color: #dc3545 !important; }
.zone-ruta-sour-flour, .zone-color-item.zone-ruta-sour-flour { background-color: #28a745 !important; }
.zone-daly-city-san-mateo, .zone-color-item.zone-daly-city-san-mateo { background-color: #fd7e14 !important; }
.zone-north-bay, .zone-color-item.zone-north-bay { background-color: #6f42c1 !important; }
.zone-east-bay, .zone-color-item.zone-east-bay { background-color: #20c997 !important; }
.zone-no-zone, .zone-color-item.zone-no-zone { background-color: #6c757d !important; }

.customers-container h3 { margin: 0 0 10px; color: #2c3e50; }
.zone-group { margin-bottom: 10px; border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden; }
.zone-group.collapsed .customers-list { display: none; }
.zone-group.collapsed .zone-toggle-icon { transform: rotate(-90deg); }
.zone-group-header { padding: 10px 15px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; color: white; }
.zone-header-content { display: flex; align-items: center; gap: 10px; }
.zone-group-title { margin: 0; font-size: 0.95rem; }
.zone-customer-count { font-size: 0.8rem; opacity: 0.9; }
.zone-toggle-icon { transition: transform 0.2s; }
.customers-list { padding: 10px; display: flex; flex-wrap: wrap; gap: 6px; background: #f8f9fa; }

.routes-container.filtered-view .day-cell:not(.show-day) { display: none; }
.routes-container.filtered-view .day-header:not(.active-filter) { display: none; }
.day-cell.show-day { flex: 1; min-width: 200px; }

.modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
.modal-content { background: white; margin: 8% auto; padding: 25px; border-radius: 12px; width: 90%; max-width: 600px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); position: relative; }
.modal-content.modal-sm { max-width: 450px; }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
.modal-header h3 { margin: 0; }
.close { color: #aaa; font-size: 28px; font-weight: bold; cursor: pointer; }
.close:hover { color: #000; }

.selection-section { margin-bottom: 18px; }
.selection-section h4 { margin: 0 0 8px; font-size: 0.95rem; color: #495057; }

.day-icons-grid, .driver-icons-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(90px, 1fr)); gap: 10px; margin-top: 10px; }
.day-icon-option, .driver-icon-option { display: flex; flex-direction: column; align-items: center; padding: 12px; border: 2px solid #dee2e6; border-radius: 8px; cursor: pointer; transition: all 0.2s; background: white; }
.day-icon-option:hover, .driver-icon-option:hover { border-color: #007bff; background: #f8f9ff; }
.day-icon-option.selected, .driver-icon-option.selected { border-color: #28a745; background: #f8fff9; }
.day-icon-option.no-day { border-color: #dc3545; color: #dc3545; }
.day-icon-name, .driver-icon-name { font-size: 0.85rem; font-weight: 600; text-align: center; }
.driver-icon-option .driver-avatar { width: 32px; height: 32px; font-size: 14px; margin-bottom: 6px; }

.form-group { margin-bottom: 15px; }
.form-group label { display: block; font-weight: 600; margin-bottom: 6px; color: #495057; }
.form-control { width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 6px; font-size: 1rem; box-sizing: border-box; }
.form-control:focus { outline: none; border-color: #007bff; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; padding-top: 15px; border-top: 1px solid #dee2e6; }

.btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.2s; }
.btn-primary { background: #007bff; color: white; }
.btn-primary:hover { background: #0056b3; }
.btn-secondary { background: #6c757d; color: white; }
.btn-secondary:hover { background: #545b62; }
.btn-sm { padding: 6px 12px; font-size: 12px; }

.message-bar { position: fixed; top: 20px; right: 20px; padding: 15px 20px; border-radius: 8px; font-weight: 600; z-index: 1001; box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
.message-bar.success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
.message-bar.error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }

.empty-state { text-align: center; padding: 60px 20px; color: #6c757d; }
.empty-state h3 { color: #495057; }

@media (max-width: 900px) {
    .drivers-layout { grid-template-columns: 1fr; }
    .driver-sidebar { position: static; }
    .driver-list-actions { opacity: 1; }
    .route-move-buttons { display: inline-flex; }
    .day-cell.show-day { min-width: 100%; }
    .route-day-tools { position: sticky; top: 0; z-index: 2; padding: 6px; margin: -8px -8px 8px; background: #fff; border-bottom: 1px solid #e4e8ed; }
}
</style>

<script>
const selectedDriverId = <?php echo (int)$selectedDriverId; ?>;
const viewingArchived = <?php echo $viewingArchived ? 'true' : 'false'; ?>;
const days = <?php echo json_encode($days); ?>;
let filteredDay = null;
let currentCustomerId = null;
let currentModalDriverId = selectedDriverId;
let draggedCustomer = null;
let isEditDriverMode = false;
let draggedRouteStop = null;
let suppressRouteClick = false;
const routeSaveQueues = new WeakMap();
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

function routeStopData(element) {
    return {
        element,
        id: Number(element.dataset.customerId),
        name: element.dataset.customerName,
        address: element.dataset.address,
        latitude: Number(element.dataset.latitude),
        longitude: Number(element.dataset.longitude),
        deliverBy: timeToMinutes(element.dataset.deliverBy),
        deliverAfter: timeToMinutes(element.dataset.deliverAfter),
        deliveryMinutes: Number(element.dataset.deliveryTime) || 20
    };
}

function getRouteStops(list) {
    return Array.from(list.querySelectorAll('.assigned-customer')).map(routeStopData);
}

function updateRoutePresentation(list, exactSchedule) {
    const stops = getRouteStops(list);
    stops.forEach((stop, index) => {
        stop.element.querySelector('.route-position').textContent = index + 1;
        const up = stop.element.querySelector('[data-move="up"]');
        const down = stop.element.querySelector('[data-move="down"]');
        if (up) up.disabled = index === 0;
        if (down) down.disabled = index === stops.length - 1;
    });

    const estimate = list.closest('.day-cell').querySelector('.route-estimate');
    const optimizeButton = list.closest('.day-cell').querySelector('.optimize-route-btn');
    if (!stops.length) {
        estimate.textContent = 'No stops';
        estimate.title = '';
        if (optimizeButton) optimizeButton.disabled = true;
        return;
    }

    if (optimizeButton && !viewingArchived) optimizeButton.disabled = false;
    // The existing route logic uses customer service time plus travel. Until an exact
    // Directions result is available, use its established 10-minute average per leg,
    // including service duration, opening-window waits, and the return to the bakery.
    let routineFinish = routeStartMinutes;
    const routineArrivals = [];
    const routineDepartures = [];
    stops.forEach(stop => {
        routineFinish += 10;
        if (stop.deliverAfter !== null && routineFinish < stop.deliverAfter) {
            routineFinish = stop.deliverAfter;
        }
        routineArrivals.push(routineFinish);
        routineFinish += stop.deliveryMinutes;
        routineDepartures.push(routineFinish);
    });
    routineFinish += 10;
    const arrivals = exactSchedule ? exactSchedule.arrivals : routineArrivals;
    const departures = exactSchedule ? exactSchedule.departures : routineDepartures;
    stops.forEach((stop, index) => {
        stop.element.querySelector('.route-arrival-time strong').textContent = formatClock(arrivals[index]);
        stop.element.querySelector('.route-departure-time strong').textContent = formatClock(departures[index]);
    });
    const estimatedMinutes = exactSchedule ? exactSchedule.totalMinutes : routineFinish - routeStartMinutes;
    estimate.textContent = (exactSchedule ? '' : '≈ ') + formatDuration(estimatedMinutes);
    estimate.title = (exactSchedule ? 'Directions estimate' : 'Routine estimate')
        + ' · starts 6:40 AM · finishes about ' + formatClock(routeStartMinutes + estimatedMinutes);
}

function saveRouteOrder(list) {
    const day = list.dataset.day;
    const customerIds = getRouteStops(list).map(stop => stop.id);
    const estimate = list.closest('.day-cell').querySelector('.route-estimate');
    const previous = routeSaveQueues.get(list) || Promise.resolve();
    const next = previous.catch(() => {}).then(async () => {
        estimate.classList.add('saving');
        const body = new URLSearchParams({
            action: 'save_route_order',
            driver_id: String(selectedDriverId),
            day_of_week: String(day),
            customer_ids: JSON.stringify(customerIds)
        });
        const response = await fetch('drivers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Could not save route order');
    }).finally(() => {
        estimate.classList.remove('saving');
    });
    routeSaveQueues.set(list, next);
    return next;
}

function completeManualReorder(list) {
    suppressRouteClick = true;
    updateRoutePresentation(list, null);
    saveRouteOrder(list).catch(error => showMessage(error.message, 'error'));
    setTimeout(() => { suppressRouteClick = false; }, 250);
}

function directionsForStops(stops, optimizeWaypoints) {
    return new Promise((resolve, reject) => {
        if (typeof google === 'undefined' || !google.maps || !google.maps.DirectionsService) {
            reject(new Error('Route optimization is still loading. Try again in a moment.'));
            return;
        }
        const service = new google.maps.DirectionsService();
        service.route({
            origin: bakeryAddress,
            destination: bakeryAddress,
            waypoints: stops.map(stop => ({ location: stop.address, stopover: true })),
            optimizeWaypoints,
            travelMode: google.maps.TravelMode.DRIVING
        }, (result, status) => {
            if (status === 'OK') resolve(result);
            else reject(new Error('Route optimization failed: ' + status));
        });
    });
}

function calculateRouteSchedule(result, stops) {
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
    if (legs[stops.length]) currentMinutes += legs[stops.length].duration.value / 60;
    return {
        totalMinutes: currentMinutes - routeStartMinutes,
        arrivals,
        departures,
        violations: stops.map((stop, index) => ({ stop, index, arrival: arrivals[index] }))
            .filter(item => item.stop.deliverBy !== null && item.arrival > item.stop.deliverBy)
    };
}

async function optimizeStandingRoute(button) {
    const cell = button.closest('.day-cell');
    const list = cell.querySelector('.customer-list');
    let stops = getRouteStops(list);
    if (!stops.length) return;
    if (stops.length > 25) {
        showMessage('Google route optimization supports up to 25 stops at a time.', 'error');
        return;
    }
    const missingAddress = stops.find(stop => !stop.address || stop.address.trim().length < 5);
    if (missingAddress) {
        showMessage('Add an address for ' + missingAddress.name + ' before optimizing this route.', 'error');
        return;
    }

    const estimate = cell.querySelector('.route-estimate');
    const oldLabel = button.textContent;
    button.disabled = true;
    button.textContent = 'Optimizing…';
    estimate.textContent = 'Calculating…';
    try {
        let result = await directionsForStops(stops, true);
        const optimizedIndexes = result.routes[0].waypoint_order || [];
        if (optimizedIndexes.length === stops.length) {
            stops = optimizedIndexes.map(index => stops[index]);
        }

        // Match the established route logic: start with shortest driving distance,
        // then move late deadline stops earlier until constraints are met or stable.
        const maxAdjustments = Math.min(20, stops.length * 2);
        for (let adjustment = 0; adjustment < maxAdjustments; adjustment++) {
            const schedule = calculateRouteSchedule(result, stops);
            if (!schedule.violations.length) break;
            const violation = schedule.violations
                .filter(item => item.index > 0)
                .sort((a, b) => (b.arrival - b.stop.deliverBy) - (a.arrival - a.stop.deliverBy))[0];
            if (!violation) break;
            const reordered = stops.slice();
            const moved = reordered.splice(violation.index, 1)[0];
            reordered.splice(violation.index - 1, 0, moved);
            stops = reordered;
            result = await directionsForStops(stops, false);
        }

        stops.forEach(stop => list.appendChild(stop.element));
        const schedule = calculateRouteSchedule(result, stops);
        updateRoutePresentation(list, schedule);
        await saveRouteOrder(list);
        const note = schedule.violations.length
            ? ' Route saved; ' + schedule.violations.length + ' delivery window still needs attention.'
            : ' Route saved with all delivery deadlines met.';
        showMessage('Optimized ' + stops.length + ' stops in ' + formatDuration(schedule.totalMinutes) + '.' + note, 'success');
    } catch (error) {
        updateRoutePresentation(list, null);
        showMessage(error.message, 'error');
    } finally {
        button.disabled = false;
        button.textContent = oldLabel;
    }
}

document.querySelectorAll('.customer-list').forEach(list => updateRoutePresentation(list, null));

document.querySelectorAll('.optimize-route-btn').forEach(button => {
    button.addEventListener('click', () => optimizeStandingRoute(button));
});

document.querySelectorAll('.route-move-btn').forEach(button => {
    button.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        const item = button.closest('.assigned-customer');
        const list = item.closest('.customer-list');
        const sibling = button.dataset.move === 'up' ? item.previousElementSibling : item.nextElementSibling;
        if (!sibling) return;
        if (button.dataset.move === 'up') list.insertBefore(item, sibling);
        else list.insertBefore(sibling, item);
        completeManualReorder(list);
    });
});

// Driver CRUD
function openDriverModal(id, name) {
    isEditDriverMode = !!id;
    document.getElementById('driverModalTitle').textContent = isEditDriverMode ? 'Edit Driver' : 'Add Driver';
    document.getElementById('driverSubmitBtn').textContent = isEditDriverMode ? 'Update Driver' : 'Create Driver';
    document.getElementById('driverId').value = id || '';
    document.getElementById('driverName').value = name || '';
    document.getElementById('driverModal').style.display = 'block';
    document.getElementById('driverName').focus();
}

function closeDriverModal() {
    document.getElementById('driverModal').style.display = 'none';
}

document.getElementById('driverForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('action', isEditDriverMode ? 'update' : 'create');

    try {
        const response = await fetch('drivers.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (result.success) {
            showMessage(result.message, 'success');
            closeDriverModal();
            setTimeout(() => {
                const url = isEditDriverMode
                    ? 'drivers.php?driver_id=' + selectedDriverId
                    : 'drivers.php?driver_id=' + result.id;
                window.location.href = url;
            }, 1000);
        } else {
            showMessage('Error: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (err) {
        showMessage('Error: ' + err.message, 'error');
    }
});

async function deleteDriver(id, name) {
    if (!confirm('Permanently delete driver "' + name + '"?\n\nOnly use this for drivers with no routes or assignments. Consider archiving instead.')) return;

    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);

    try {
        const response = await fetch('drivers.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (result.success) {
            showMessage(result.message, 'success');
            setTimeout(() => { window.location.href = 'drivers.php'; }, 1000);
        } else {
            showMessage('Error: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (err) {
        showMessage('Error: ' + err.message, 'error');
    }
}

async function archiveDriver(id, name) {
    if (!confirm('Archive driver "' + name + '"?\n\nThey will be hidden from route dropdowns but their history is kept.')) return;

    const formData = new FormData();
    formData.append('action', 'archive');
    formData.append('id', id);

    try {
        const response = await fetch('drivers.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (result.success) {
            showMessage(result.message, 'success');
            setTimeout(() => { window.location.href = 'drivers.php'; }, 1000);
        } else {
            showMessage('Error: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (err) {
        showMessage('Error: ' + err.message, 'error');
    }
}

async function unarchiveDriver(id, name) {
    const formData = new FormData();
    formData.append('action', 'unarchive');
    formData.append('id', id);

    try {
        const response = await fetch('drivers.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (result.success) {
            showMessage(result.message, 'success');
            setTimeout(() => { window.location.href = 'drivers.php?driver_id=' + id; }, 1000);
        } else {
            showMessage('Error: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (err) {
        showMessage('Error: ' + err.message, 'error');
    }
}

function toggleArchivedSection(button) {
    const list = document.getElementById('archivedDriverList');
    const expanded = list.style.display !== 'none';
    list.style.display = expanded ? 'none' : 'block';
    button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    button.querySelector('.archived-arrow').textContent = expanded ? '▼' : '▲';
}

function showMessage(message, type) {
    const bar = document.getElementById('messageBar');
    document.getElementById('messageText').textContent = message;
    bar.className = 'message-bar ' + type;
    bar.style.display = 'block';
    setTimeout(() => { bar.style.display = 'none'; }, 3000);
}

// Day filter
const dayHeaders = document.querySelectorAll('.clickable-day');
const filterStatus = document.getElementById('filter-status');
const clearFilterBtn = document.getElementById('clear-filter');
const routesContainer = document.getElementById('routes-container');
const customerInstruction = document.getElementById('customer-instruction');

if (dayHeaders.length) {
    dayHeaders.forEach(header => {
        header.addEventListener('click', () => filterByDay(header.getAttribute('data-day')));
    });
}

if (clearFilterBtn) {
    clearFilterBtn.addEventListener('click', clearDayFilter);
}

function filterByDay(day) {
    filteredDay = day;
    dayHeaders.forEach(h => {
        h.classList.toggle('active-filter', h.getAttribute('data-day') === day);
    });
    document.querySelectorAll('.day-cell').forEach(cell => {
        cell.classList.toggle('show-day', cell.getAttribute('data-day') === day);
    });
    if (routesContainer) routesContainer.classList.add('filtered-view');
    if (filterStatus) filterStatus.textContent = 'Showing: ' + days[day];
    if (clearFilterBtn) clearFilterBtn.style.display = 'inline-block';
    if (customerInstruction) {
        customerInstruction.textContent = 'Click a customer to assign them to a driver and ' + days[day] + ', or drag them to the day column.';
    }
    updateAvailableCustomers();
}

function clearDayFilter() {
    filteredDay = null;
    dayHeaders.forEach(h => h.classList.remove('active-filter'));
    document.querySelectorAll('.day-cell').forEach(cell => cell.classList.remove('show-day'));
    if (routesContainer) routesContainer.classList.remove('filtered-view');
    if (filterStatus) filterStatus.textContent = 'Showing: All Days';
    if (clearFilterBtn) clearFilterBtn.style.display = 'none';
    updateAvailableCustomers();
}

function updateAvailableCustomers() {
    const assignedIds = new Set();
    if (filteredDay) {
        document.querySelectorAll('.day-cell[data-day="' + filteredDay + '"] .assigned-customer').forEach(el => {
            assignedIds.add(el.getAttribute('data-customer-id'));
        });
    } else {
        document.querySelectorAll('.day-cell[data-driver-id="' + selectedDriverId + '"] .assigned-customer').forEach(el => {
            assignedIds.add(el.getAttribute('data-customer-id'));
        });
    }
    document.querySelectorAll('.customer-item').forEach(item => {
        const id = item.getAttribute('data-customer-id');
        item.style.display = assignedIds.has(id) ? 'none' : '';
    });
}

// Assignment modal
const modal = document.getElementById('assignment-modal');
const modalCustomerName = document.getElementById('modal-customer-name');

function openAssignmentModal(customerId, customerName, currentDay, currentDriverId) {
    currentCustomerId = customerId;
    currentModalDriverId = currentDriverId || selectedDriverId;
    modalCustomerName.textContent = customerName;
    document.querySelectorAll('.driver-icon-option').forEach(opt => {
        opt.classList.toggle('selected', Number(opt.getAttribute('data-driver-id')) === Number(currentModalDriverId));
    });
    document.querySelectorAll('.day-icon-option').forEach(opt => {
        opt.classList.toggle('selected', opt.getAttribute('data-day') === (currentDay || ''));
    });
    modal.style.display = 'block';
}

window.selectDriverInModal = function(driverId) {
    currentModalDriverId = Number(driverId);
    document.querySelectorAll('.driver-icon-option').forEach(opt => {
        opt.classList.toggle('selected', Number(opt.getAttribute('data-driver-id')) === currentModalDriverId);
    });
};

window.selectDayInModal = async function(dayOfWeek) {
    if (!currentCustomerId) return;
    if (filteredDay) localStorage.setItem('preserveFilterDay', filteredDay);

    const driverId = dayOfWeek === '0' ? 0 : currentModalDriverId;
    const day = dayOfWeek === '0' ? (filteredDay || findCustomerDay(currentCustomerId)) : dayOfWeek;

    try {
        const response = await fetch('drivers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=save_route&driver_id=' + driverId + '&customer_id=' + currentCustomerId + '&day_of_week=' + day
        });
        const result = await response.json();
        if (result.success) {
            modal.style.display = 'none';
            window.location.reload();
        } else {
            alert('Error: ' + (result.error || 'Unknown error'));
        }
    } catch (err) {
        alert('Error saving assignment');
    }
};

function findCustomerDay(customerId) {
    const el = document.querySelector('.assigned-customer[data-customer-id="' + customerId + '"]');
    return el ? el.closest('.day-cell').getAttribute('data-day') : '1';
}

// Click handlers
document.addEventListener('click', function(e) {
    const customerItem = e.target.closest('.customer-item');
    if (customerItem && !e.target.closest('.zone-group-header')) {
        e.preventDefault();
        openAssignmentModal(
            customerItem.getAttribute('data-customer-id'),
            customerItem.getAttribute('data-customer-name'),
            filteredDay,
            selectedDriverId
        );
    }

    const assigned = e.target.closest('.assigned-customer');
    if (assigned && !e.target.classList.contains('delete-customer') && !e.target.closest('.route-drag-handle, .route-move-buttons') && !suppressRouteClick) {
        e.preventDefault();
        openAssignmentModal(
            assigned.getAttribute('data-customer-id'),
            assigned.getAttribute('data-customer-name'),
            assigned.closest('.day-cell').getAttribute('data-day'),
            selectedDriverId
        );
    }

    if (e.target.classList.contains('delete-customer')) {
        e.stopPropagation();
        const item = e.target.closest('.assigned-customer');
        const customerId = item.getAttribute('data-customer-id');
        const day = item.closest('.day-cell').getAttribute('data-day');
        if (filteredDay) localStorage.setItem('preserveFilterDay', filteredDay);
        fetch('drivers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=save_route&driver_id=0&customer_id=' + customerId + '&day_of_week=' + day
        }).then(r => r.json()).then(result => {
            if (result.success) window.location.reload();
            else alert('Error removing customer');
        });
    }
});

// Reorder standing stops with desktop drag-and-drop.
document.querySelectorAll('.assigned-customer').forEach(item => {
    item.addEventListener('dragstart', function(e) {
        draggedRouteStop = this;
        this.classList.add('route-dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', this.dataset.customerId);
    });
    item.addEventListener('dragend', function() {
        const list = this.closest('.customer-list');
        this.classList.remove('route-dragging');
        document.querySelectorAll('.route-drop-target').forEach(el => el.classList.remove('route-drop-target'));
        if (draggedRouteStop === this) completeManualReorder(list);
        draggedRouteStop = null;
    });
});

document.querySelectorAll('.customer-list').forEach(list => {
    list.addEventListener('dragover', function(e) {
        if (!draggedRouteStop || draggedRouteStop.closest('.customer-list') !== this) return;
        e.preventDefault();
        const target = e.target.closest('.assigned-customer');
        if (!target || target === draggedRouteStop) return;
        document.querySelectorAll('.route-drop-target').forEach(el => el.classList.remove('route-drop-target'));
        target.classList.add('route-drop-target');
        const rect = target.getBoundingClientRect();
        this.insertBefore(draggedRouteStop, e.clientY > rect.top + rect.height / 2 ? target.nextSibling : target);
    });
    list.addEventListener('drop', function(e) {
        if (!draggedRouteStop || draggedRouteStop.closest('.customer-list') !== this) return;
        e.preventDefault();
        completeManualReorder(this);
        draggedRouteStop.classList.remove('route-dragging');
        draggedRouteStop = null;
    });
});

// Pointer events make the same drag handle work with a finger, mouse, or pen.
document.querySelectorAll('.route-drag-handle').forEach(handle => {
    handle.addEventListener('pointerdown', function(e) {
        if (viewingArchived || e.button > 0) return;
        const item = this.closest('.assigned-customer');
        const list = item.closest('.customer-list');
        let moved = false;
        this.setPointerCapture(e.pointerId);
        item.classList.add('route-dragging');
        e.preventDefault();
        e.stopPropagation();

        const onMove = event => {
            moved = true;
            const target = document.elementFromPoint(event.clientX, event.clientY)?.closest('.assigned-customer');
            if (!target || target === item || target.closest('.customer-list') !== list) return;
            const rect = target.getBoundingClientRect();
            list.insertBefore(item, event.clientY > rect.top + rect.height / 2 ? target.nextSibling : target);
            document.querySelectorAll('.route-drop-target').forEach(el => el.classList.remove('route-drop-target'));
            target.classList.add('route-drop-target');
        };
        const onEnd = () => {
            handle.removeEventListener('pointermove', onMove);
            handle.removeEventListener('pointerup', onEnd);
            handle.removeEventListener('pointercancel', onEnd);
            item.classList.remove('route-dragging');
            document.querySelectorAll('.route-drop-target').forEach(el => el.classList.remove('route-drop-target'));
            if (moved) completeManualReorder(list);
        };
        handle.addEventListener('pointermove', onMove);
        handle.addEventListener('pointerup', onEnd);
        handle.addEventListener('pointercancel', onEnd);
    });
});

// Drag available customers into a weekday.
document.querySelectorAll('.customer-item').forEach(item => {
    item.addEventListener('dragstart', function(e) {
        draggedCustomer = { id: this.getAttribute('data-customer-id'), name: this.getAttribute('data-customer-name') };
        this.style.opacity = '0.4';
        e.dataTransfer.effectAllowed = 'move';
    });
    item.addEventListener('dragend', function() { this.style.opacity = '1'; draggedCustomer = null; });
});

document.querySelectorAll('.day-cell').forEach(cell => {
    cell.addEventListener('dragover', function(e) { e.preventDefault(); this.classList.add('drag-over'); });
    cell.addEventListener('dragleave', function() { this.classList.remove('drag-over'); });
    cell.addEventListener('drop', async function(e) {
        e.preventDefault();
        this.classList.remove('drag-over');
        if (!draggedCustomer) return;
        if (filteredDay) localStorage.setItem('preserveFilterDay', filteredDay);

        const dayOfWeek = this.getAttribute('data-day');
        try {
            const response = await fetch('drivers.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=save_route&driver_id=' + selectedDriverId + '&customer_id=' + draggedCustomer.id + '&day_of_week=' + dayOfWeek
            });
            const result = await response.json();
            if (result.success) window.location.reload();
            else alert('Error: ' + (result.error || 'Unknown error'));
        } catch (err) {
            alert('Error saving route');
        }
    });
});

window.toggleZoneGroup = function(header) {
    header.closest('.zone-group').classList.toggle('collapsed');
};

// Modal close
document.querySelectorAll('#assignment-modal .close').forEach(btn => {
    btn.addEventListener('click', () => { modal.style.display = 'none'; });
});
window.addEventListener('click', e => {
    if (e.target === modal) modal.style.display = 'none';
    if (e.target === document.getElementById('driverModal')) closeDriverModal();
});

// Restore filter on reload
const preserved = localStorage.getItem('preserveFilterDay');
if (preserved) {
    localStorage.removeItem('preserveFilterDay');
    setTimeout(() => filterByDay(preserved), 100);
} else {
    updateAvailableCustomers();
}
</script>

<?php if (defined('GOOGLE_MAPS_API_KEY') && GOOGLE_MAPS_API_KEY !== ''): ?>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=<?php echo urlencode(GOOGLE_MAPS_API_KEY); ?>&loading=async"></script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
