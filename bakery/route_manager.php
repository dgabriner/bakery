<?php
// Security check
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/google_maps_config.php';
require_once 'includes/route_manager.php';
require_once 'includes/driver_assignments.php';

// Handle AJAX request for delivery photos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_delivery_photos') {
    header('Content-Type: application/json');

    $date = trim((string)($_POST['date'] ?? ''));
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $customerId = (int)($_POST['customer_id'] ?? 0);

    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        echo json_encode(['success' => false, 'error' => 'Invalid date format; use YYYY-MM-DD']);
        exit;
    }
    if ($driverId <= 0 || $customerId <= 0) {
        echo json_encode(['success' => false, 'error' => 'driver_id and customer_id are required']);
        exit;
    }

    try {
        $photos = route_manager_fetch_photos($db, $driverId, $customerId, $date);
        echo json_encode([
            'success' => true,
            'photos' => $photos,
            'count' => count($photos),
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle AJAX: persist drag-and-drop route order (updates route_order only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reorder_deliveries') {
    header('Content-Type: application/json');

    $date = trim((string)($_POST['date'] ?? ''));
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $orderIds = json_decode((string)($_POST['order_ids'] ?? '[]'), true);

    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        echo json_encode(['success' => false, 'error' => 'Invalid date format; use YYYY-MM-DD']);
        exit;
    }
    if ($driverId <= 0) {
        echo json_encode(['success' => false, 'error' => 'driver_id is required']);
        exit;
    }
    if (!is_array($orderIds) || count($orderIds) === 0) {
        echo json_encode(['success' => false, 'error' => 'order_ids must contain the remaining stops']);
        exit;
    }

    $orderIds = array_values(array_filter(array_map('intval', $orderIds), static function ($id) {
        return $id > 0;
    }));
    if (count($orderIds) === 0) {
        echo json_encode(['success' => false, 'error' => 'No valid order IDs provided']);
        exit;
    }

    try {
        $result = bakery_driver_reorder_remaining_stops($db, $driverId, $date, $orderIds);

        echo json_encode([
            'success' => true,
            'message' => 'Route order updated',
            'driver_id' => $driverId,
            'date' => $date,
            'order_ids' => array_column($result['stops'], 'daily_order_id'),
            'stops' => $result['stops'],
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle AJAX request for assigned deliveries
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_deliveries') {
    header('Content-Type: application/json');

    $date = trim((string)($_POST['date'] ?? date('Y-m-d')));
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        echo json_encode(['success' => false, 'error' => 'Invalid date format; use YYYY-MM-DD']);
        exit;
    }

    $driverIds = [];
    if (isset($_POST['driver_ids'])) {
        $decoded = json_decode((string)$_POST['driver_ids'], true);
        if (is_array($decoded)) {
            $driverIds = array_values(array_filter(array_map('intval', $decoded), static function ($id) {
                return $id > 0;
            }));
        }
    }

    try {
        $driversData = route_manager_fetch_deliveries($db, $date, $driverIds);
        $totalDeliveries = 0;
        foreach ($driversData as $driver) {
            $totalDeliveries += count($driver['deliveries']);
        }

        echo json_encode([
            'success' => true,
            'date' => $date,
            'total_deliveries' => $totalDeliveries,
            'data' => $driversData,
            'pickup_grid' => route_manager_pickup_grid($db, $date, $driversData),
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_cod_turnin') {
    header('Content-Type: application/json');
    if (function_exists('bakery_require_csrf')) {
        bakery_require_csrf();
    }
    if (function_exists('bakery_require_role')) {
        bakery_require_role(['administrator', 'manager']);
    }
    require_once __DIR__ . '/includes/cod_turnins.php';
    $date = trim((string)($_POST['date'] ?? ''));
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
    $userId = (int)(bakery_current_user()['id'] ?? 0);
    try {
        if ($amount === false) {
            throw new InvalidArgumentException('Turn-in amount is required');
        }
        $id = bakery_cod_turnin_record($db, $driverId, $date, (float)$amount, $userId);
        echo json_encode([
            'success' => true,
            'id' => $id,
            'amount' => round((float)$amount, 2),
            'driver_id' => $driverId,
            'date' => $date,
        ]);
    } catch (Throwable $e) {
        error_log('record_cod_turnin: ' . $e->getMessage());
        $msg = function_exists('bakery_error_message_for_user')
            ? bakery_error_message_for_user($e)
            : 'Could not record COD turn-in';
        echo json_encode(['success' => false, 'error' => $msg]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_pack_units') {
    header('Content-Type: application/json');
    if (function_exists('bakery_require_csrf')) {
        bakery_require_csrf();
    }
    if (function_exists('bakery_user_has_role') && !bakery_user_has_role(['administrator', 'manager'])) {
        echo json_encode(['success' => false, 'error' => 'Managers can save tray and box sizes.']);
        exit;
    }
    $productId = (int)($_POST['product_id'] ?? 0);
    try {
        $saved = bakery_pack_save_count_units(
            $db,
            $productId,
            $_POST['pieces_per_tray'] ?? '',
            $_POST['pieces_per_box'] ?? ''
        );
        echo json_encode(['success' => true, 'product' => $saved]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pickup_rebalance') {
    header('Content-Type: application/json');
    if (function_exists('bakery_require_csrf')) {
        bakery_require_csrf();
    }
    if (function_exists('bakery_user_has_role') && !bakery_user_has_role(['administrator', 'manager'])) {
        echo json_encode(['success' => false, 'error' => 'Managers can reassign pickup quantities.']);
        exit;
    }
    try {
        $result = route_manager_pickup_rebalance($db, $_POST);
        echo json_encode(['success' => true] + $result);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// GPS activity history and optional map trails for the selected workday.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_tracking_data') {
    header('Content-Type: application/json');

    $date = trim((string)($_POST['date'] ?? date('Y-m-d')));
    $parsedDate = DateTime::createFromFormat('Y-m-d', $date);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
        echo json_encode(['success' => false, 'error' => 'Invalid date format; use YYYY-MM-DD']);
        exit;
    }
    $driver_ids = isset($_POST['driver_ids']) ? json_decode($_POST['driver_ids'], true) : [];

    try {
        $sql = "
            SELECT
                dh.driver_id,
                d.name as driver_name,
                dh.timestamp,
                dh.latitude,
                dh.longitude,
                DATE_FORMAT(dh.timestamp, '%H:%i') as time_formatted
            FROM driver_history dh
            JOIN drivers d ON dh.driver_id = d.id
            WHERE DATE(dh.timestamp) = ?
        ";

        $params = [$date];

        if (!empty($driver_ids) && is_array($driver_ids)) {
            $placeholders = str_repeat('?,', count($driver_ids) - 1) . '?';
            $sql .= " AND dh.driver_id IN ($placeholders)";
            $params = array_merge($params, array_map('intval', $driver_ids));
        }

        $sql .= " ORDER BY dh.driver_id, dh.timestamp";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $tracking_data = $stmt->fetchAll();

        $drivers_data = [];
        foreach ($tracking_data as $point) {
            $driver_id = $point['driver_id'];
            if (!isset($drivers_data[$driver_id])) {
                $drivers_data[$driver_id] = [
                    'name' => $point['driver_name'],
                    'points' => [],
                ];
            }
            $drivers_data[$driver_id]['points'][] = [
                'lat' => (float)$point['latitude'],
                'lng' => (float)$point['longitude'],
                'timestamp' => $point['timestamp'],
                'time' => $point['time_formatted'],
            ];
        }

        echo json_encode(['success' => true, 'data' => $drivers_data]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$page_title = bakery_t('page.route_manager');
require_once 'includes/header.php';
require_once 'includes/nav.php';
?>
<p class="manager-desktop-only-hint"><?php bakery_te('manager_phone.desktop_use_manager'); ?>
  <a href="<?php echo htmlspecialchars((defined('BASE_URL') ? BASE_URL : '') . 'manager.php?date=' . rawurlencode((string)($_GET['date'] ?? date('Y-m-d'))) . '&view=routes', ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('nav.manager_today'); ?></a>
</p>
<link rel="stylesheet" href="<?php echo bakery_asset_href('assets/photo_styles.css'); ?>">
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/route_manager.css'); ?>">
<?php

// Fetch all drivers
$drivers = bakery_get_drivers($db);

// Start on the active delivery day; future routes remain available via the date picker.
$defaultDate = date('Y-m-d');
$selectedDate = $_GET['date'] ?? $defaultDate;
$parsedSelected = DateTime::createFromFormat('Y-m-d', $selectedDate);
if (!$parsedSelected || $parsedSelected->format('Y-m-d') !== $selectedDate) {
    $selectedDate = $defaultDate;
}
?>

<div class="container manager-desktop-only">
    <div class="route-manager-header">
        <div>
            <h1>Route Manager</h1>
            <p class="subtitle">Assigned deliveries for the selected day — drag stops to reorder each driver’s route. <strong>Each driver header shows the pickup manifest from Driver Pickup Loads, plus COD cash totals.</strong></p>
        </div>
        <div class="route-manager-actions">
            <a class="btn btn-secondary" href="driver_load.php?date=<?php echo htmlspecialchars($selectedDate); ?>"><?php echo htmlspecialchars(bakery_t('page.driver_load')); ?></a>
            <a class="btn btn-secondary" href="route_summary.php?date=<?php echo htmlspecialchars($selectedDate); ?>"><?php echo htmlspecialchars(function_exists('bakery_t') ? bakery_t('page.route_summary') : 'Route Summary'); ?></a>
            <a class="btn btn-secondary" href="billing_center.php?panel=invoices&amp;range=custom&amp;start_date=<?php echo htmlspecialchars($selectedDate); ?>&amp;end_date=<?php echo htmlspecialchars($selectedDate); ?>">Invoice reconciliation</a>
        </div>
    </div>

    <!-- Controls Panel -->
    <div class="controls-panel">
        <div class="control-group">
            <label for="tracking-date">Date:</label>
            <input type="date" id="tracking-date" value="<?php echo htmlspecialchars($selectedDate); ?>">
        </div>

        <div class="control-group">
            <label>Drivers:</label>
            <div class="driver-checkboxes">
                <label class="driver-checkbox">
                    <input type="checkbox" id="select-all-drivers" checked>
                    <span class="checkbox-label">All Drivers</span>
                </label>
                <?php foreach ($drivers as $index => $driver): ?>
                    <label class="driver-checkbox">
                        <input type="checkbox" class="driver-select" data-driver-id="<?php echo (int)$driver['id']; ?>" checked>
                        <span class="checkbox-label" data-color="<?php echo (int)$index; ?>"><?php echo htmlspecialchars($driver['name']); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="control-group">
            <button type="button" id="refresh-data" class="btn btn-primary">Refresh</button>
            <label class="driver-checkbox" title="Show GPS breadcrumb trails on the map">
                <input type="checkbox" id="show-tracking">
                <span class="checkbox-label">Show GPS trail on map</span>
            </label>
        </div>
    </div>

    <section id="pickup-manifest-board" class="pickup-board" hidden
             data-unit="pieces"
             data-label-pieces="<?php echo htmlspecialchars(bakery_t('route_manager.unit_pieces'), ENT_QUOTES, 'UTF-8'); ?>"
             data-label-trays="<?php echo htmlspecialchars(bakery_t('route_manager.unit_trays'), ENT_QUOTES, 'UTF-8'); ?>"
             data-label-boxes="<?php echo htmlspecialchars(bakery_t('route_manager.unit_boxes'), ENT_QUOTES, 'UTF-8'); ?>"
             data-col-product="<?php echo htmlspecialchars(bakery_t('route_manager.col_product'), ENT_QUOTES, 'UTF-8'); ?>"
             data-col-tray="<?php echo htmlspecialchars(bakery_t('route_manager.col_per_tray'), ENT_QUOTES, 'UTF-8'); ?>"
             data-col-box="<?php echo htmlspecialchars(bakery_t('route_manager.col_per_box'), ENT_QUOTES, 'UTF-8'); ?>"
             data-col-total="<?php echo htmlspecialchars(bakery_t('route_manager.col_total'), ENT_QUOTES, 'UTF-8'); ?>"
             data-saved="<?php echo htmlspecialchars(bakery_t('route_manager.pack_units_saved'), ENT_QUOTES, 'UTF-8'); ?>"
             data-rebalanced="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_rebalanced'), ENT_QUOTES, 'UTF-8'); ?>"
             data-empty="<?php echo htmlspecialchars(bakery_t('route_manager.no_pickup'), ENT_QUOTES, 'UTF-8'); ?>"
             data-stores="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_stores'), ENT_QUOTES, 'UTF-8'); ?>"
             data-locked="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_locked'), ENT_QUOTES, 'UTF-8'); ?>"
             data-driver-total="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_driver_total'), ENT_QUOTES, 'UTF-8'); ?>"
             data-spread-existing="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_spread_existing'), ENT_QUOTES, 'UTF-8'); ?>"
             data-spread-all="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_spread_all'), ENT_QUOTES, 'UTF-8'); ?>"
             data-save-total="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_save_total'), ENT_QUOTES, 'UTF-8'); ?>"
             data-move-to="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_move_to'), ENT_QUOTES, 'UTF-8'); ?>"
             data-move-qty="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_move_qty'), ENT_QUOTES, 'UTF-8'); ?>"
             data-move="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_move'), ENT_QUOTES, 'UTF-8'); ?>"
             data-move-all="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_move_all'), ENT_QUOTES, 'UTF-8'); ?>"
             data-sheet-close="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_sheet_close'), ENT_QUOTES, 'UTF-8'); ?>"
             data-sheet-title="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_sheet_title'), ENT_QUOTES, 'UTF-8'); ?>"
             data-hand="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_hand'), ENT_QUOTES, 'UTF-8'); ?>"
             data-hand-ready="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_hand_ready'), ENT_QUOTES, 'UTF-8'); ?>"
             data-take="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_take'), ENT_QUOTES, 'UTF-8'); ?>"
             data-place="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_place'), ENT_QUOTES, 'UTF-8'); ?>"
             data-take-all="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_take_all'), ENT_QUOTES, 'UTF-8'); ?>"
             data-place-all="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_place_all'), ENT_QUOTES, 'UTF-8'); ?>"
             data-fixed="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_fixed'), ENT_QUOTES, 'UTF-8'); ?>"
             data-save-plan="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_save_plan'), ENT_QUOTES, 'UTF-8'); ?>"
             data-reset="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_reset'), ENT_QUOTES, 'UTF-8'); ?>"
             data-no-stops="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_no_stops'), ENT_QUOTES, 'UTF-8'); ?>"
             data-need-place="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_need_place'), ENT_QUOTES, 'UTF-8'); ?>"
             data-chunk="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_chunk'), ENT_QUOTES, 'UTF-8'); ?>"
             data-chunk-one="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_chunk_one'), ENT_QUOTES, 'UTF-8'); ?>"
             data-chunk-five="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_chunk_five'), ENT_QUOTES, 'UTF-8'); ?>"
             data-chunk-tray="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_chunk_tray'), ENT_QUOTES, 'UTF-8'); ?>"
             data-chunk-box="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_chunk_box'), ENT_QUOTES, 'UTF-8'); ?>"
             data-supposed="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_supposed'), ENT_QUOTES, 'UTF-8'); ?>"
             data-standing="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_standing'), ENT_QUOTES, 'UTF-8'); ?>"
             data-daily="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_daily'), ENT_QUOTES, 'UTF-8'); ?>"
             data-fill-need="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_fill_need'), ENT_QUOTES, 'UTF-8'); ?>"
             data-take-extra="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_take_extra'), ENT_QUOTES, 'UTF-8'); ?>"
             data-snap-need="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_snap_need'), ENT_QUOTES, 'UTF-8'); ?>"
             data-balance="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_balance'), ENT_QUOTES, 'UTF-8'); ?>"
             data-by-van="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_by_van'), ENT_QUOTES, 'UTF-8'); ?>"
             data-little="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_little_shop'), ENT_QUOTES, 'UTF-8'); ?>"
             data-trays="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_pack_trays'), ENT_QUOTES, 'UTF-8'); ?>"
             data-standing-aim="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_aim_standing'), ENT_QUOTES, 'UTF-8'); ?>"
             data-view-product="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_view_product'), ENT_QUOTES, 'UTF-8'); ?>"
             data-view-store="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_view_store'), ENT_QUOTES, 'UTF-8'); ?>"
             data-family-all="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_family_all'), ENT_QUOTES, 'UTF-8'); ?>"
             data-col-store="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_col_store'), ENT_QUOTES, 'UTF-8'); ?>"
             data-scope-help="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_scope_help'), ENT_QUOTES, 'UTF-8'); ?>"
             data-store-sheet="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_store_sheet'), ENT_QUOTES, 'UTF-8'); ?>"
             data-all-stops="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_all_stops'), ENT_QUOTES, 'UTF-8'); ?>"
             data-store-edit="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_store_edit'), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="pickup-board-header">
            <div>
                <p class="pickup-board-kicker"><?php echo htmlspecialchars(bakery_t('route_manager.pickup_board_kicker')); ?></p>
                <h2><?php echo htmlspecialchars(bakery_t('route_manager.pickup_manifest')); ?></h2>
                <p class="pickup-board-help"><?php echo htmlspecialchars(bakery_t('route_manager.pickup_board_help')); ?></p>
            </div>
            <div class="pickup-unit-toggle" role="group" aria-label="<?php echo htmlspecialchars(bakery_t('route_manager.unit_group')); ?>">
                <button type="button" class="pickup-unit-btn is-active" data-unit="pieces"><?php echo htmlspecialchars(bakery_t('route_manager.unit_pieces')); ?></button>
                <button type="button" class="pickup-unit-btn" data-unit="trays"><?php echo htmlspecialchars(bakery_t('route_manager.unit_trays')); ?></button>
                <button type="button" class="pickup-unit-btn" data-unit="boxes"><?php echo htmlspecialchars(bakery_t('route_manager.unit_boxes')); ?></button>
            </div>
        </div>
        <p id="pickup-board-status" class="pickup-board-status" aria-live="polite"></p>
        <div id="pickup-board-tools" class="pickup-board-tools"></div>
        <div id="pickup-board-table" class="pickup-board-table-wrap"></div>
    </section>
    <div id="pickup-sheet-root" class="pickup-sheet-root" hidden>
        <button type="button" class="pickup-sheet-backdrop" tabindex="-1" aria-label="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_sheet_close'), ENT_QUOTES, 'UTF-8'); ?>"></button>
        <div id="pickup-sheet" class="pickup-sheet" role="dialog" aria-modal="true" aria-labelledby="pickup-sheet-heading"></div>
    </div>

    <!-- Status Panel -->
    <div class="status-panel">
        <div class="status-item">
            <span class="status-label">Drivers with stops</span>
            <span id="active-drivers-count" class="status-value">0</span>
        </div>
        <div class="status-item">
            <span class="status-label">Total deliveries</span>
            <span id="total-deliveries-count" class="status-value">0</span>
        </div>
        <div class="status-item">
            <span class="status-label">Pending</span>
            <span id="pending-count" class="status-value">0</span>
        </div>
        <div class="status-item">
            <span class="status-label">Delivered</span>
            <span id="delivered-count" class="status-value">0</span>
        </div>
        <div class="status-item status-item--cash" title="Cash from delivered COD and Pan Dulce stops">
            <span class="status-label">Cash on hand</span>
            <span id="cod-cash-on-hand" class="status-value">$0.00</span>
        </div>
        <div class="status-item status-item--cash" title="Cash on hand plus estimated amounts from remaining COD and Pan Dulce stops">
            <span class="status-label">Cash turn-in total</span>
            <span id="cod-turn-in-total" class="status-value">$0.00</span>
        </div>
        <div class="status-item status-item--cash" title="Sum of order amounts for all active stops on the selected routes (COD and signature)">
            <span class="status-label">Total sold</span>
            <span id="route-total-sold" class="status-value">$0.00</span>
        </div>
        <div class="status-item">
            <span class="status-label">Last update</span>
            <span id="last-update-time" class="status-value">Never</span>
        </div>
    </div>

    <p class="cash-help-banner" role="note">
        <strong>Driver cash totals live here.</strong>
        Per-driver amounts also appear above each route in the delivery list and in the driver legend.
        <em>Cash on hand</em> = cash from delivered COD and Pan Dulce stops (using the delivery total when an older stop has no recorded cash amount).
        <em>Turn-in total</em> = on hand + estimated from undelivered COD and Pan Dulce stops.
        <em>Total sold</em> = order amounts for all active stops on the selected routes (COD and signature).
        For billable invoice amounts, use <a href="billing_center.php?panel=invoices&amp;range=custom&amp;start_date=<?php echo htmlspecialchars($selectedDate); ?>&amp;end_date=<?php echo htmlspecialchars($selectedDate); ?>">Billing Center</a>.
    </p>

    <div class="route-layout">
        <!-- Map Container -->
        <div id="route-map" class="map-container"></div>

        <!-- Delivery List -->
        <div class="delivery-list-panel">
            <div class="delivery-list-header">
                <h3>Delivery list</h3>
                <span id="reorder-status" class="reorder-status" aria-live="polite"></span>
            </div>
            <p class="delivery-list-hint">
                <span class="hint-desktop">Drag the ⋮⋮ handle, or use ↑ ↓, to change stop order.</span>
                <span class="hint-mobile">Tap ↑ or ↓ to move a stop. Changes save automatically.</span>
            </p>
            <div id="delivery-list" class="delivery-list"
                 data-pickup-title="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_manifest'), ENT_QUOTES, 'UTF-8'); ?>"
                 data-pickup-empty="<?php echo htmlspecialchars(bakery_t('route_manager.no_pickup'), ENT_QUOTES, 'UTF-8'); ?>"
                 data-pickup-edit="<?php echo htmlspecialchars(bakery_t('route_manager.edit_pickup_loads'), ENT_QUOTES, 'UTF-8'); ?>"
                 data-pickup-summary="<?php echo htmlspecialchars(bakery_t('route_manager.pickup_summary'), ENT_QUOTES, 'UTF-8'); ?>">
                <p class="text-muted">Select a date and drivers to load deliveries.</p>
            </div>
        </div>
    </div>

    <section class="gps-activity-panel" aria-labelledby="gpsActivityTitle">
        <div class="gps-activity-heading">
            <div>
                <p class="gps-activity-kicker">Driver activity</p>
                <h3 id="gpsActivityTitle">GPS history</h3>
            </div>
            <span id="gps-activity-status" class="gps-activity-status" aria-live="polite">Loading activity…</span>
        </div>
        <p class="gps-activity-help">Location pings recorded while drivers use My Route. Select an update to view it on the map.</p>
        <div id="gps-activity-list" class="gps-activity-list" aria-live="polite"></div>
    </section>

    <!-- Driver Legend -->
    <div class="driver-legend">
        <h3>Driver legend</h3>
        <div id="legend-content" class="legend-content">
            <p class="text-muted">Select drivers to see legend</p>
        </div>
    </div>
</div>

<!-- Delivery photos modal -->
<div id="deliveryPhotosModal" class="photo-modal" style="display:none;" aria-hidden="true" role="dialog" aria-labelledby="deliveryPhotosModalTitle">
    <div class="photo-modal-content">
        <div class="photo-modal-header">
            <h3 id="deliveryPhotosModalTitle">Delivery photos</h3>
            <span class="photo-modal-close" id="deliveryPhotosModalClose" role="button" tabindex="0" aria-label="Close">&times;</span>
        </div>
        <div class="photo-modal-body">
            <div id="deliveryPhotosMeta" class="photo-assignment-confirm"></div>
            <div id="deliveryPhotosStatus" class="text-muted">Loading photos…</div>
            <div id="deliveryPhotosGrid" class="photo-grid"></div>
        </div>
    </div>
</div>

<!-- Full-size photo lightbox -->
<div id="photoLightbox" class="photo-lightbox" style="display:none;" aria-hidden="true" role="dialog">
    <button type="button" class="photo-lightbox-close" id="photoLightboxClose" aria-label="Close">&times;</button>
    <img id="photoLightboxImage" alt="Delivery photo">
</div>

<!-- Stop detail sheet -->
<div id="stopDetailModal" class="stop-detail-modal" style="display:none;" aria-hidden="true" role="dialog" aria-labelledby="stopDetailModalTitle">
    <div class="stop-detail-backdrop" id="stopDetailBackdrop"></div>
    <div class="stop-detail-sheet">
        <div class="stop-detail-header">
            <div class="stop-detail-header-text">
                <p class="stop-detail-kicker" id="stopDetailKicker">Stop details</p>
                <h3 id="stopDetailModalTitle">Stop</h3>
            </div>
            <button type="button" class="stop-detail-close" id="stopDetailModalClose" aria-label="Close">&times;</button>
        </div>
        <div class="stop-detail-actions" id="stopDetailActions"></div>
        <div class="stop-detail-body" id="stopDetailBody">
            <div class="stop-detail-section">
                <h4>Timing</h4>
                <dl class="stop-detail-grid" id="stopDetailTiming"></dl>
            </div>
            <div class="stop-detail-section">
                <h4>Status &amp; payment</h4>
                <dl class="stop-detail-grid" id="stopDetailStatus"></dl>
            </div>
            <div class="stop-detail-section">
                <h4>Order &amp; invoice</h4>
                <div id="stopDetailInvoiceStatus" class="text-muted">Loading order details…</div>
                <div id="stopDetailInvoice"></div>
            </div>
            <div class="stop-detail-section">
                <h4>Photos</h4>
                <div id="stopDetailPhotosStatus" class="text-muted">Loading photos…</div>
                <div id="stopDetailPhotos" class="photo-grid"></div>
            </div>
        </div>
    </div>
</div>


<script>
window.__ROUTE_MANAGER__ = {
    apiKey: <?php echo bakery_json_for_html(GOOGLE_MAPS_API_KEY, '""'); ?>,
    drivers: <?php echo bakery_json_for_html($drivers, '[]'); ?>
};
</script>
<script async defer
    src="<?php echo GOOGLE_MAPS_JS_API_URL; ?>?key=<?php echo htmlspecialchars(GOOGLE_MAPS_API_KEY); ?>&callback=initMap">
</script>
<script src="<?php echo bakery_asset_href('includes/route_manager.js'); ?>" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const mapEl = document.getElementById('route-map');
    if (!mapEl) return;
    mapEl.innerHTML = '<div class="map-fallback">Map unavailable — cash and delivery totals still load from the selected routes. Enable maps (MAPS_ENABLED + API key) to show the map.</div>';
});
</script>

<?php require_once 'includes/footer.php'; ?>
