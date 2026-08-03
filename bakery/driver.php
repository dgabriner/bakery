<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/product_inventory.php';

$changeDriver = isset($_GET['change_driver']) && (string)$_GET['change_driver'] === '1';
$routeUser = function_exists('bakery_current_user') ? bakery_current_user() : null;
$isAuthenticatedDriver = $routeUser && ($routeUser['role_slug'] ?? '') === 'driver';
$linkedDriverId = $isAuthenticatedDriver ? (int)($routeUser['driver_id'] ?? 0) : 0;
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedDateObject = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$selectedDate);
if (!$selectedDateObject || $selectedDateObject->format('Y-m-d') !== (string)$selectedDate) {
    $selectedDate = date('Y-m-d');
    $selectedDateObject = new DateTimeImmutable($selectedDate);
}
$todayDate = date('Y-m-d');
$previousDate = $selectedDateObject->modify('-1 day')->format('Y-m-d');
$nextDate = $selectedDateObject->modify('+1 day')->format('Y-m-d');
$routeDayChoices = [];
for ($dayOffset = -3; $dayOffset <= 3; $dayOffset++) {
    $choiceDate = $selectedDateObject->modify(($dayOffset >= 0 ? '+' : '') . $dayOffset . ' days');
    $routeDayChoices[] = [
        'date' => $choiceDate->format('Y-m-d'),
        'weekday' => $choiceDate->format('D'),
        'day' => $choiceDate->format('j'),
        'month' => $choiceDate->format('M'),
    ];
}

if ($isAuthenticatedDriver && $linkedDriverId > 0) {
    $selectedDriverId = $linkedDriverId;
    $changeDriver = false;
} elseif (isset($_GET['driver_id'])) {
    $selectedDriverId = (int)$_GET['driver_id'];
    if ($selectedDriverId <= 0 && function_exists('bakery_set_selected_driver')) {
        bakery_set_selected_driver(0);
    }
} elseif (!$changeDriver && function_exists('bakery_get_selected_driver_id')) {
    $selectedDriverId = bakery_get_selected_driver_id();
} else {
    $selectedDriverId = 0;
}

// Get driver information
$driver = null;
if ($selectedDriverId > 0) {
    $stmt = $db->prepare('SELECT id, name FROM drivers WHERE id = ?');
    $stmt->execute([$selectedDriverId]);
    $driver = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all active drivers for the dropdown
$drivers = [];
foreach (bakery_get_drivers($db) as $driverData) {
    $drivers[$driverData['id']] = $driverData['name'];
}

// Allow viewing an archived driver when accessed directly by ID
if ($selectedDriverId > 0 && !isset($drivers[$selectedDriverId])) {
    $archivedDriver = bakery_get_driver_by_id($db, $selectedDriverId);
    if ($archivedDriver) {
        $driver = $archivedDriver;
        $drivers[$archivedDriver['id']] = $archivedDriver['name'];
    }
}

// Persist selection once we know the driver name
if ($driver && $selectedDriverId > 0 && function_exists('bakery_set_selected_driver') && !$changeDriver) {
    bakery_set_selected_driver($selectedDriverId, $driver['name']);
}

$zoneColors = [
    '#007bff', '#28a745', '#dc3545', '#fd7e14', '#6f42c1',
    '#20c997', '#ffc107', '#e83e8c', '#6c757d', '#17a2b8',
    '#6610f2', '#fd7e14', '#e83e8c', '#6f42c1', '#20c997',
];

$zoneColorMap = [];
$zoneIndex = 0;
$orderedStops = [];
$error = null;
$totalStops = 0;
$totalAmount = 0;
$driverCompletedStops = 0;
$driverLoadItems = [];

/**
 * Build a directions URL for the current client (Apple Maps / Google Maps).
 */
function bakery_driver_maps_url($address) {
    $address = trim((string)$address);
    if ($address === '') {
        return '';
    }
    $q = rawurlencode($address);
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
        return 'https://maps.apple.com/?daddr=' . $q . '&dirflg=d';
    }
    return 'https://www.google.com/maps/dir/?api=1&destination=' . $q . '&travelmode=driving';
}

if ($selectedDriverId > 0 && $driver) {
    try {
        $stmt = $db->prepare("
            SELECT
                c.id as customer_id,
                c.name as customer_name,
                c.address as customer_address,
                c.phone as customer_phone,
                c.zone,
                do.id as daily_order_id,
                do.total_amount,
                doa.route_order,
                doa.scheduled_delivery_time,
                doa.delivery_status,
                doa.driver_id,
                d.name as driver_name
            FROM daily_orders do
            INNER JOIN customers c ON do.customer_id = c.id
            INNER JOIN daily_order_assignments doa ON do.id = doa.daily_order_id
            INNER JOIN drivers d ON doa.driver_id = d.id
            WHERE doa.driver_id = ? AND do.order_date = ?
            ORDER BY doa.route_order, c.zone, c.name
        ");

        $stmt->execute([$selectedDriverId, $selectedDate]);
        $results = $stmt->fetchAll();

        foreach ($results as $row) {
            $zone = $row['zone'] ?: 'No Zone';
            if (!isset($zoneColorMap[$zone])) {
                $zoneColorMap[$zone] = $zoneColors[$zoneIndex % count($zoneColors)];
                $zoneIndex++;
            }

            $status = $row['delivery_status'] ?? 'pending';
            if ($status === 'delivered') {
                $driverCompletedStops++;
            }

            $orderedStops[] = [
                'customer_id' => $row['customer_id'],
                'customer_name' => $row['customer_name'],
                'customer_address' => $row['customer_address'],
                'customer_phone' => $row['customer_phone'] ?? '',
                'zone' => $zone,
                'daily_order_id' => $row['daily_order_id'],
                'total_amount' => $row['total_amount'],
                'driver_id' => $row['driver_id'],
                'driver_name' => $row['driver_name'],
                'route_order' => $row['route_order'],
                'scheduled_delivery_time' => $row['scheduled_delivery_time'],
                'delivery_status' => $status,
                'zone_color' => $zoneColorMap[$zone],
            ];
        }

        $totalStops = count($results);
        $totalAmount = array_sum(array_column($results, 'total_amount'));
    } catch (Exception $e) {
        $error = 'Error loading driver data: ' . htmlspecialchars($e->getMessage());
    }

    if (bakery_inventory_ready($db)) {
        $loadStmt = $db->prepare(
            'SELECT p.name, li.loaded_quantity
             FROM driver_loads dl
             JOIN driver_load_items li ON li.driver_load_id = dl.id
             JOIN products p ON p.id = li.product_id
             WHERE dl.driver_id = ? AND dl.delivery_date = ? AND li.loaded_quantity > 0
             ORDER BY p.name'
        );
        $loadStmt->execute([$selectedDriverId, $selectedDate]);
        $driverLoadItems = $loadStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$nextStop = null;
$upcomingStops = [];
$pastStops = [];
foreach ($orderedStops as $stop) {
    $status = $stop['delivery_status'] ?? 'pending';
    $isDone = in_array($status, ['delivered', 'cancelled'], true);
    if ($isDone) {
        $pastStops[] = $stop;
    } elseif ($nextStop === null) {
        $nextStop = $stop;
    } else {
        $upcomingStops[] = $stop;
    }
}

$showSelector = !$isAuthenticatedDriver && ($changeDriver || $selectedDriverId <= 0 || !$driver);
$remainingStops = count($upcomingStops) + ($nextStop ? 1 : 0);
$pastStopCount = count($pastStops);
$page_title = $driver ? ('Route - ' . $driver['name']) : 'Driver Route';

require_once 'includes/header.php';
require_once 'includes/nav.php';

$progressPct = $totalStops > 0 ? round(($driverCompletedStops / $totalStops) * 100) : 0;
?>

<link rel="stylesheet" href="<?php echo htmlspecialchars(BASE_URL); ?>assets/photo_styles.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars(BASE_URL); ?>css/driver.css">
<script src="<?php echo htmlspecialchars(BASE_URL); ?>includes/driver_delivery.js" defer></script>
<script>document.body.classList.add('driver-workflow-page');</script>
<style>
.driver-pickup-manifest{margin:16px 0;padding:16px 18px;border:1px solid #b8d8c2;border-radius:14px;background:#f2fbf4}.driver-pickup-manifest h2{margin:2px 0 8px;font-size:1.1rem;color:#1f6637}.driver-pickup-manifest p{margin:0;color:#536258}.driver-pickup-manifest ul{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 0;padding:0;list-style:none}.driver-pickup-manifest li{padding:7px 10px;background:#fff;border-radius:8px;color:#34483a}.driver-pickup-manifest li strong{color:#1f6637}
</style>

<div class="driver-route" id="driverRouteRoot"
    data-driver-id="<?php echo (int)$selectedDriverId; ?>"
    data-date="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>"
    data-total="<?php echo (int)$totalStops; ?>"
    data-completed="<?php echo (int)$driverCompletedStops; ?>">

    <?php if ($showSelector): ?>
    <section class="driver-whoami">
        <h1>Who is driving?</h1>
        <p>Choose your name once — we’ll remember it until you change it in the menu.</p>
        <form method="GET" action="" class="driver-whoami-form">
            <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>">
            <div class="driver-whoami-list">
                <?php foreach ($drivers as $driverId => $driverName): ?>
                <button type="submit" name="driver_id" value="<?php echo (int)$driverId; ?>" class="driver-whoami-btn">
                    <?php echo htmlspecialchars($driverName); ?>
                </button>
                <?php endforeach; ?>
            </div>
        </form>
        <?php if (empty($drivers)): ?>
        <p class="empty-state">No active drivers found.</p>
        <?php endif; ?>
    </section>

    <?php elseif ($selectedDriverId > 0 && $driver): ?>

    <header class="route-topbar">
        <div class="route-topbar-main">
            <div class="route-identity">
                <span class="route-live-dot" aria-hidden="true"></span>
                <div>
                <div class="route-driver-label"><?php echo htmlspecialchars($driver['name']); ?></div>
                <div class="route-date-label">
                    <?php echo $selectedDate === $todayDate ? 'Today &middot; ' : ''; ?><?php echo $selectedDateObject->format('l, F j'); ?>
                </div>
                </div>
            </div>
            <?php if (!$isAuthenticatedDriver): ?>
            <a class="route-change-link" href="?change_driver=1&amp;date=<?php echo urlencode($selectedDate); ?>">Change driver</a>
            <?php endif; ?>
        </div>
        <div class="route-day-picker" id="routeDayPicker">
            <a class="route-day-arrow js-day-link" href="?driver_id=<?php echo $selectedDriverId; ?>&amp;date=<?php echo $previousDate; ?>" aria-label="Previous day">&lsaquo;</a>
            <div class="route-day-strip" aria-label="Choose route day">
                <?php foreach ($routeDayChoices as $routeDay):
                    $isSelectedRouteDay = $routeDay['date'] === $selectedDate;
                    $isTodayRouteDay = $routeDay['date'] === $todayDate;
                ?>
                <a class="route-day-chip js-day-link<?php echo $isSelectedRouteDay ? ' is-selected' : ''; ?><?php echo $isTodayRouteDay ? ' is-today' : ''; ?>"
                   href="?driver_id=<?php echo $selectedDriverId; ?>&amp;date=<?php echo $routeDay['date']; ?>"
                   <?php echo $isSelectedRouteDay ? 'aria-current="date"' : ''; ?>>
                    <span class="route-day-weekday"><?php echo htmlspecialchars($routeDay['weekday']); ?></span>
                    <strong><?php echo htmlspecialchars($routeDay['day']); ?></strong>
                    <span class="route-day-month"><?php echo htmlspecialchars($routeDay['month']); ?></span>
                </a>
                <?php endforeach; ?>
            </div>
            <a class="route-day-arrow js-day-link" href="?driver_id=<?php echo $selectedDriverId; ?>&amp;date=<?php echo $nextDate; ?>" aria-label="Next day">&rsaquo;</a>
        </div>
        <div class="route-date-tools">
            <?php if ($selectedDate !== $todayDate): ?>
            <a class="route-today-link js-day-link" href="?driver_id=<?php echo $selectedDriverId; ?>&amp;date=<?php echo $todayDate; ?>">Jump to today</a>
            <?php endif; ?>
            <label class="route-calendar-label" for="routeDateInput">
                Pick a date
                <input type="date" id="routeDateInput" value="<?php echo htmlspecialchars($selectedDate); ?>" data-driver-id="<?php echo (int)$selectedDriverId; ?>">
            </label>
        </div>
        <?php if ($totalStops > 0): ?>
        <div class="route-progress" aria-live="polite">
            <div class="route-progress-text" id="routeProgressText"><?php echo $driverCompletedStops; ?> of <?php echo $totalStops; ?> done</div>
            <div class="route-progress-track"><div class="route-progress-fill" id="routeProgressFill" style="width: <?php echo $progressPct; ?>%;"></div></div>
        </div>
        <?php endif; ?>
    </header>

    <?php if ($error): ?>
    <div class="empty-state"><p><?php echo $error; ?></p></div>
    <?php elseif ($totalStops === 0): ?>
    <div class="empty-state">
        <h3>No stops today</h3>
        <p>Nothing assigned to <?php echo htmlspecialchars($driver['name']); ?> for <?php echo date('l, F j', strtotime($selectedDate)); ?>.</p>
    </div>
    <?php else: ?>

    <div class="route-dashboard">
        <div class="route-primary-column">
            <section class="route-overview" aria-label="Route summary">
                <div class="route-overview-heading">
                    <div>
                        <p class="route-section-kicker"><?php echo $selectedDate === $todayDate ? 'Today at a glance' : 'Route at a glance'; ?></p>
                        <h1><?php echo $selectedDate === $todayDate ? 'Your route is ready' : 'Route overview'; ?></h1>
                        <p class="route-overview-copy"><strong id="routeRemainingCount"><?php echo (int)$remainingStops; ?></strong> <?php echo $remainingStops === 1 ? 'stop' : 'stops'; ?> left to visit</p>
                    </div>
                    <span class="route-progress-ring" style="--route-progress: <?php echo (int)$progressPct; ?>%;" aria-hidden="true"><strong id="routeProgressPercent"><?php echo (int)$progressPct; ?>%</strong><span>done</span></span>
                </div>
                <div class="route-stat-grid" role="list" aria-label="Route totals">
                    <button type="button" class="route-stat route-stat--done" id="routeCompletedButton" role="listitem" aria-controls="pastStopsDetails" <?php echo $pastStopCount === 0 ? 'disabled' : ''; ?> title="View completed stops for this day">
                        <strong id="routeCompletedCount"><?php echo (int)$driverCompletedStops; ?></strong>
                        <span>Completed · view history</span>
                    </button>
                    <div class="route-stat route-stat--next" role="listitem">
                        <strong id="routeTotalCount"><?php echo (int)$totalStops; ?></strong>
                        <span>Total stops</span>
                    </div>
                    <button type="button" class="route-stat route-stat--past" id="routePastStopsButton" role="listitem" aria-controls="pastStopsDetails" <?php echo $pastStopCount === 0 ? 'disabled' : ''; ?> title="View completed stops for this day">
                        <strong id="routePastCount"><?php echo (int)$pastStopCount; ?></strong>
                        <span>Past stops</span>
                    </button>
                </div>
            </section>

            <section class="driver-pickup-manifest" aria-label="Pickup manifest">
                <div><p class="route-section-kicker">Before you leave</p><h2>Pickup manifest</h2></div>
                <?php if ($driverLoadItems): ?>
                    <ul><?php foreach ($driverLoadItems as $item): ?><li><strong><?php echo number_format($item['loaded_quantity']); ?></strong> <?php echo htmlspecialchars($item['name']); ?></li><?php endforeach; ?></ul>
                <?php else: ?>
                    <p>No pickup has been assigned yet. Confirm your load with the route manager before starting deliveries.</p>
                <?php endif; ?>
            </section>

    <!-- Next stop (primary on-route view) -->
    <section class="next-stop" id="nextStopCard" <?php echo $nextStop ? '' : 'hidden'; ?> aria-live="polite">
        <?php if ($nextStop):
            $phoneHref = preg_replace('/\D+/', '', (string)($nextStop['customer_phone'] ?? ''));
            $phoneHref = $phoneHref !== '' ? 'tel:' . $phoneHref : '';
            $mapsHref = bakery_driver_maps_url($nextStop['customer_address'] ?? '');
        ?>
        <p class="next-stop-eyebrow">Next stop<?php if ($nextStop['route_order']): ?> &middot; #<?php echo (int)$nextStop['route_order']; ?><?php endif; ?></p>
        <h2 class="next-stop-store" id="nextStopStore"><?php echo htmlspecialchars($nextStop['customer_name']); ?></h2>
        <p class="next-stop-address" id="nextStopAddress"><?php echo htmlspecialchars($nextStop['customer_address'] ?: 'No address on file'); ?></p>
        <?php if (!empty($nextStop['zone'])): ?>
        <p class="next-stop-zone"><?php echo htmlspecialchars($nextStop['zone']); ?></p>
        <?php endif; ?>

        <div class="next-stop-actions">
            <?php if ($mapsHref): ?>
            <a class="route-btn route-btn--navigate js-navigate-link"
                href="<?php echo htmlspecialchars($mapsHref); ?>"
                data-address="<?php echo htmlspecialchars($nextStop['customer_address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                target="_blank" rel="noopener">Navigate</a>
            <?php endif; ?>
            <button type="button"
                class="route-btn route-btn--photo photo-complete-btn"
                data-driver-id="<?php echo (int)$selectedDriverId; ?>"
                data-customer-id="<?php echo (int)$nextStop['customer_id']; ?>"
                data-daily-order-id="<?php echo (int)$nextStop['daily_order_id']; ?>"
                data-customer-name="<?php echo htmlspecialchars($nextStop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>"
                data-address="<?php echo htmlspecialchars($nextStop['customer_address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                data-date="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">Photo &amp; finish</button>
            <?php if ($phoneHref): ?>
            <a class="route-btn route-btn--call" href="<?php echo htmlspecialchars($phoneHref); ?>">Call</a>
            <?php endif; ?>
        </div>

        <button type="button" class="next-stop-details-toggle" id="nextStopDetailsToggle"
            data-daily-order-id="<?php echo (int)$nextStop['daily_order_id']; ?>"
            data-customer-id="<?php echo (int)$nextStop['customer_id']; ?>">Order details</button>
        <div class="order-details-container next-stop-order-details" id="nextStopOrderDetails" style="display:none;">
            <div class="order-details-loading">Loading...</div>
            <div class="order-details-content" style="display:none;"></div>
        </div>
        <?php endif; ?>
    </section>

    <section class="route-done-banner" id="routeDoneBanner" <?php echo $nextStop ? 'hidden' : ''; ?>>
        <h2>Route complete</h2>
        <p>All stops for <?php echo htmlspecialchars($driver['name']); ?> are done.</p>
    </section>

        </div>

        <div class="route-secondary-column">

    <!-- Full stop list: upcoming first, past collapsed -->
    <section class="stop-list-section">
        <div class="stop-list-heading-row">
            <div>
                <p class="route-section-kicker">Your queue</p>
                <h3 class="stop-list-heading">Upcoming stops</h3>
            </div>
            <span class="stop-list-count"><strong id="queueCount"><?php echo (int)$remainingStops; ?></strong> remaining</span>
        </div>
        <p class="stop-list-helper">Tap a stop for order details. Use the buttons for directions, photos, or a call.</p>
        <div class="stop-list" id="stopList">
            <?php foreach ($orderedStops as $stop):
                $status = $stop['delivery_status'] ?? 'pending';
                $statusClass = in_array($status, ['pending', 'in_transit', 'delivered', 'failed', 'cancelled'], true) ? $status : 'pending';
                $isDone = in_array($status, ['delivered', 'cancelled'], true);
                $isNext = $nextStop && (int)$nextStop['daily_order_id'] === (int)$stop['daily_order_id'];
                $phoneHref = preg_replace('/\D+/', '', (string)($stop['customer_phone'] ?? ''));
                $phoneHref = $phoneHref !== '' ? 'tel:' . $phoneHref : '';
                $mapsHref = bakery_driver_maps_url($stop['customer_address'] ?? '');
                $itemClass = 'stop-item';
                if ($isDone) {
                    $itemClass .= ' stop-item--past';
                } elseif ($isNext) {
                    $itemClass .= ' stop-item--next';
                } else {
                    $itemClass .= ' stop-item--upcoming';
                }
            ?>
            <article class="<?php echo $itemClass; ?>"
                data-customer-id="<?php echo (int)$stop['customer_id']; ?>"
                data-daily-order-id="<?php echo (int)$stop['daily_order_id']; ?>"
                data-customer-name="<?php echo htmlspecialchars($stop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>"
                data-address="<?php echo htmlspecialchars($stop['customer_address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                data-phone="<?php echo htmlspecialchars($stop['customer_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                data-zone="<?php echo htmlspecialchars($stop['zone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                data-route-order="<?php echo htmlspecialchars((string)($stop['route_order'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                data-status="<?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="stop-item-main">
                    <span class="stop-item-order">#<?php echo $stop['route_order'] ?: '&mdash;'; ?></span>
                    <div class="stop-item-body">
                        <div class="stop-item-heading">
                            <div class="stop-item-name"><?php echo htmlspecialchars($stop['customer_name']); ?></div>
                            <?php if ($isNext): ?><span class="stop-item-next-label">Next</span><?php endif; ?>
                        </div>
                        <div class="stop-item-address"><?php echo htmlspecialchars($stop['customer_address'] ?: 'No address'); ?></div>
                        <div class="stop-item-meta">
                            <?php if (!empty($stop['zone'])): ?><span><?php echo htmlspecialchars($stop['zone']); ?></span><?php endif; ?>
                            <?php if (!empty($stop['scheduled_delivery_time'])): ?><span><?php echo htmlspecialchars(date('g:i A', strtotime($stop['scheduled_delivery_time']))); ?></span><?php endif; ?>
                        </div>
                    </div>
                    <span class="status-badge status-badge--<?php echo htmlspecialchars($statusClass); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $statusClass))); ?></span>
                </div>
                <div class="contact-actions">
                    <?php if (!$isDone && $mapsHref): ?>
                    <a class="contact-link contact-link--address js-navigate-link"
                        href="<?php echo htmlspecialchars($mapsHref); ?>"
                        data-address="<?php echo htmlspecialchars($stop['customer_address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                        target="_blank" rel="noopener">Navigate</a>
                    <?php endif; ?>
                    <button type="button"
                        class="contact-link photo-complete-btn"
                        data-photo-mode="<?php echo $isDone ? 'review' : 'capture'; ?>"
                        data-driver-id="<?php echo (int)$selectedDriverId; ?>"
                        data-customer-id="<?php echo (int)$stop['customer_id']; ?>"
                        data-daily-order-id="<?php echo (int)$stop['daily_order_id']; ?>"
                        data-customer-name="<?php echo htmlspecialchars($stop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-address="<?php echo htmlspecialchars($stop['customer_address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                        data-date="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $isDone ? 'View invoice &amp; photos' : 'Photo'; ?></button>
                    <?php if (!$isDone && $phoneHref): ?>
                    <a class="contact-link contact-link--phone" href="<?php echo htmlspecialchars($phoneHref); ?>">Call</a>
                    <?php endif; ?>
                </div>
                <div class="order-details-container" style="display: none;">
                    <div class="order-details-loading">Loading order details...</div>
                    <div class="order-details-content" style="display: none;"></div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($pastStops)): ?>
        <details class="past-stops-details" id="pastStopsDetails">
            <summary><span>Past stops &amp; photos</span><span class="past-stops-summary-count"><?php echo count($pastStops); ?></span></summary>
            <p class="past-stops-hint">Open a stop to review its delivery photos or order details.</p>
        </details>
        <?php endif; ?>
    </section>

        </div>
    </div>

    <?php endif; ?>

    <?php else: ?>
    <div class="empty-state">
        <h3>Driver not found</h3>
        <p>The selected driver could not be found.</p>
        <a class="route-change-link" href="?change_driver=1">Choose another driver</a>
    </div>
    <?php endif; ?>
</div>

<!-- Photos / Complete modal -->
<div id="deliveryPhotoModal" class="photo-modal" style="display:none;" aria-hidden="true" aria-modal="true" role="dialog" aria-labelledby="deliveryPhotoModalTitle">
    <div class="photo-modal-content delivery-photo-modal-content">
        <div class="photo-modal-header">
            <button type="button" class="photo-modal-close" id="deliveryPhotoModalClose" aria-label="Back to route">‹</button>
            <div>
                <span class="photo-modal-eyebrow">Delivery proof</span>
                <h3 id="deliveryPhotoModalTitle">Photo &amp; finish</h3>
            </div>
        </div>
        <div class="photo-modal-body">
            <div class="photo-assignment-confirm" id="deliveryPhotoAssignment"></div>

            <div class="photo-upload-section delivery-camera-section" id="deliveryCameraSection">
                <div class="delivery-camera-title-row">
                    <h4 id="deliveryCameraHeading">Take delivery photo</h4>
                    <select id="deliveryPhotoType" class="photo-type-select" aria-label="Photo type">
                        <option value="Before">Before</option>
                        <option value="After" selected>After</option>
                        <option value="Receipt">Receipt</option>
                    </select>
                </div>
                <div class="delivery-camera-frame" id="deliveryCameraFrame">
                    <video id="deliveryCameraVideo" autoplay playsinline muted></video>
                    <canvas id="deliveryCameraCanvas" class="hidden"></canvas>
                    <img id="deliveryPhotoPreview" alt="Captured photo preview" style="display:none;">
                </div>
                <div class="photo-upload-controls delivery-camera-controls">
                    <button type="button" class="btn btn-primary delivery-shutter-btn" id="deliveryCaptureBtn">Take photo</button>
                    <button type="button" class="btn btn-outline hidden" id="deliveryRetakeBtn">Retake</button>
                    <button type="button" class="btn btn-success hidden" id="deliveryUploadBtn">Use this photo</button>
                    <button type="button" class="btn btn-outline delivery-picker-btn" id="deliveryFilePickerBtn">Phone camera / library</button>
                    <input type="file" id="deliveryFileInput" accept="image/*" hidden>
                </div>
                <textarea id="deliveryPhotoNotes" rows="2" placeholder="Add a note (optional)" aria-label="Photo notes"></textarea>
                <div class="photo-upload-progress" id="deliveryPhotoProgress">
                    <div class="photo-upload-progress-bar"><div class="photo-upload-progress-fill" id="deliveryPhotoProgressFill"></div></div>
                </div>
                <div class="photo-upload-status" id="deliveryPhotoStatus" aria-live="polite"></div>
            </div>

            <details class="existing-photos-section delivery-photos-gallery" id="deliveryPhotosGallerySection">
                <summary>Saved photos <span id="deliveryPhotoCount" class="delivery-photo-count"></span></summary>
                <div class="existing-photos-grid" id="deliveryPhotosGrid">
                    <div class="loading-photos">Loading photos…</div>
                </div>
            </details>
            <div class="delivery-review-actions" id="deliveryReviewActions" hidden>
                <button type="button" class="btn btn-primary" id="deliveryReviewActivateCameraBtn">Activate camera to add a photo</button>
            </div>
        </div>
        <div class="photo-modal-footer">
            <div class="photo-finish-hint" id="deliveryFinishHint">Photo saved? Finish this stop and move to the next one.</div>
            <section class="delivery-confirmation" id="deliveryConfirmation" aria-label="Confirm delivery quantities">
                <div class="delivery-confirmation-heading">
                    <div><strong>Confirm delivery</strong><span>Enter the pieces delivered and any credits taken back.</span></div>
                    <span class="delivery-confirmation-total" id="deliveryConfirmationTotal">$0.00</span>
                </div>
                <div class="delivery-confirmation-fields">
                    <label>Pieces delivered<div class="quantity-stepper"><button type="button" class="quantity-stepper-btn" data-quantity-target="deliveryPiecesInput" data-quantity-step="-1" aria-label="Decrease pieces delivered">−</button><input type="number" id="deliveryPiecesInput" min="0" step="1" inputmode="numeric"><button type="button" class="quantity-stepper-btn" data-quantity-target="deliveryPiecesInput" data-quantity-step="1" aria-label="Increase pieces delivered">+</button></div></label>
                    <label>Credits taken back<div class="quantity-stepper"><button type="button" class="quantity-stepper-btn" data-quantity-target="deliveryCreditsInput" data-quantity-step="-1" aria-label="Decrease credits taken back">−</button><input type="number" id="deliveryCreditsInput" min="0" step="1" value="0" inputmode="numeric"><button type="button" class="quantity-stepper-btn" data-quantity-target="deliveryCreditsInput" data-quantity-step="1" aria-label="Increase credits taken back">+</button></div></label>
                </div>
                <p class="delivery-confirmation-breakdown" id="deliveryConfirmationBreakdown" aria-live="polite"></p>
            </section>
            <section class="delivery-invoice-preview" id="deliveryInvoicePreview" hidden aria-label="Invoice preview">
                <div class="delivery-invoice-brand"><span>Delivery invoice</span><strong>Sour Flour Bakery</strong></div>
                <div class="delivery-invoice-meta"><div><span>Date</span><strong id="deliveryInvoiceDate"></strong></div><div><span>Customer</span><strong id="deliveryInvoiceCustomer"></strong></div><div><span>Address</span><strong id="deliveryInvoiceAddress"></strong></div></div>
                <div class="delivery-invoice-items" id="deliveryInvoiceItems"></div>
                <div class="delivery-invoice-lines"><div><span>Ordered pieces</span><strong id="deliveryInvoiceOrderedPieces"></strong></div><div><span>Pieces delivered</span><strong id="deliveryInvoicePieces"></strong></div><div><span>Credits taken back</span><strong id="deliveryInvoiceCredits"></strong></div><div><span>Price per piece</span><strong id="deliveryInvoicePrice"></strong></div></div>
                <div class="delivery-invoice-total"><span>Total</span><strong id="deliveryInvoiceTotal"></strong></div>
                <p class="delivery-invoice-note" id="deliveryInvoicePricingNote"></p>
                <button type="button" class="btn btn-outline delivery-invoice-edit-saved" id="deliveryInvoiceEditSavedBtn" hidden>Edit saved delivery</button>
                <div class="delivery-invoice-actions" id="deliveryInvoiceActions"><button type="button" class="btn btn-outline" id="deliveryInvoiceBackBtn">Back to edit</button><button type="button" class="complete-delivery-btn" id="deliveryInvoiceConfirmBtn">Confirm &amp; save</button></div>
            </section>
            <button type="button" class="complete-delivery-btn" id="deliveryCompleteBtn">Review invoice</button>
        </div>
    </div>
</div>

<!-- Full-size photo viewer -->
<div id="deliveryPhotoViewer" class="photo-viewer-modal" style="display:none;" aria-hidden="true" role="dialog">
    <div class="photo-viewer-content">
        <span class="photo-viewer-close" id="deliveryPhotoViewerClose" role="button" tabindex="0" aria-label="Close">&times;</span>
        <img id="deliveryViewerImage" alt="Delivery photo">
        <div class="photo-viewer-info">
            <h4 id="deliveryViewerTitle"></h4>
            <p id="deliveryViewerMeta"></p>
            <div class="delivery-viewer-actions">
                <button type="button" class="btn btn-outline" id="deliveryViewerRetakeBtn">Retake</button>
                <button type="button" class="btn btn-danger" id="deliveryViewerRemoveBtn">Remove</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    function mapsDirectionsUrl(address) {
        if (!address) return '';
        var q = encodeURIComponent(address);
        var ua = navigator.userAgent || '';
        if (/iPhone|iPad|iPod/i.test(ua)) {
            return 'https://maps.apple.com/?daddr=' + q + '&dirflg=d';
        }
        if (/Android/i.test(ua)) {
            return 'https://www.google.com/maps/dir/?api=1&destination=' + q + '&travelmode=driving';
        }
        return 'https://www.google.com/maps/dir/?api=1&destination=' + q + '&travelmode=driving';
    }

    function applyNavigateLinks(root) {
        (root || document).querySelectorAll('.js-navigate-link[data-address]').forEach(function (link) {
            var url = mapsDirectionsUrl(link.getAttribute('data-address') || '');
            if (url) link.setAttribute('href', url);
        });
    }

    function phoneHref(phone) {
        var digits = String(phone || '').replace(/\D+/g, '');
        return digits ? 'tel:' + digits : '';
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function loadOrderDetails(container, dailyOrderId) {
        var loadingDiv = container.querySelector('.order-details-loading');
        var contentDiv = container.querySelector('.order-details-content');
        if (container.style.display === 'block') {
            container.style.display = 'none';
            return;
        }
        container.style.display = 'block';
        if (loadingDiv) loadingDiv.style.display = 'block';
        if (contentDiv) {
            contentDiv.style.display = 'none';
            contentDiv.innerHTML = '';
        }

        fetch('get_customer_order_details.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'daily_order_id=' + encodeURIComponent(String(dailyOrderId || 0))
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (loadingDiv) loadingDiv.style.display = 'none';
                if (!contentDiv) return;
                contentDiv.style.display = 'block';
                if (!data || !data.success) {
                    var err = (data && (data.error || data.message)) || 'Could not load order details';
                    contentDiv.innerHTML = '<div class="no-products">Error: ' + escapeHtml(err) + '</div>';
                    return;
                }
                if (data.html) {
                    contentDiv.innerHTML = data.html;
                    return;
                }
                displayInlineOrderDetails(contentDiv, data.products || []);
            })
            .catch(function () {
                if (loadingDiv) loadingDiv.style.display = 'none';
                if (contentDiv) {
                    contentDiv.style.display = 'block';
                    contentDiv.innerHTML = '<div class="no-products">Error loading order details.</div>';
                }
            });
    }

    function displayInlineOrderDetails(container, products) {
        if (!products || !products.length) {
            container.innerHTML = '<div class="no-products">No products found for this order.</div>';
            return;
        }
        var html = '<div class="product-groups">';
        products.forEach(function (product) {
            var totalPrice = product.line_total
                ? parseFloat(product.line_total).toFixed(2)
                : (product.quantity * product.unit_price).toFixed(2);
            html +=
                '<div class="product-item">' +
                '<div class="product-details">' +
                '<div class="product-name">' + escapeHtml(product.product_name || 'Unknown Product') + '</div>' +
                '<div class="product-meta">' +
                escapeHtml(product.product_line || product.product_line_name || 'Other') +
                ' · ' +
                escapeHtml(product.dough_type || product.dough_type_name || 'Standard') +
                '</div></div>' +
                '<div class="product-info">' +
                '<span class="quantity">×' + escapeHtml(String(product.quantity || 0)) + '</span>' +
                '<span class="total-price">$' + totalPrice + '</span>' +
                '</div></div>';
        });
        html += '</div>';
        container.innerHTML = html;
    }

    function getActiveStops() {
        return Array.prototype.slice.call(document.querySelectorAll('#stopList .stop-item')).filter(function (el) {
            var status = el.getAttribute('data-status') || '';
            return status !== 'delivered' && status !== 'cancelled';
        });
    }

    function renderNextStop(stopEl) {
        var card = document.getElementById('nextStopCard');
        var done = document.getElementById('routeDoneBanner');
        if (!card) return;

        if (!stopEl) {
            card.hidden = true;
            card.innerHTML = '';
            if (done) done.hidden = false;
            return;
        }
        if (done) done.hidden = true;
        card.hidden = false;

        var name = stopEl.getAttribute('data-customer-name') || '';
        var address = stopEl.getAttribute('data-address') || '';
        var phone = stopEl.getAttribute('data-phone') || '';
        var zone = stopEl.getAttribute('data-zone') || '';
        var routeOrder = stopEl.getAttribute('data-route-order') || '';
        var customerId = stopEl.getAttribute('data-customer-id') || '0';
        var dailyOrderId = stopEl.getAttribute('data-daily-order-id') || '0';
        var root = document.getElementById('driverRouteRoot');
        var driverId = root ? root.getAttribute('data-driver-id') : '0';
        var date = root ? root.getAttribute('data-date') : '';
        var maps = mapsDirectionsUrl(address);
        var tel = phoneHref(phone);

        var actions =
            (maps
                ? '<a class="route-btn route-btn--navigate js-navigate-link" href="' +
                  maps +
                  '" data-address="' +
                  escapeHtml(address) +
                  '" target="_blank" rel="noopener">Navigate</a>'
                : '') +
            '<button type="button" class="route-btn route-btn--photo photo-complete-btn"' +
            ' data-driver-id="' +
            escapeHtml(driverId) +
            '" data-customer-id="' +
            escapeHtml(customerId) +
            '" data-daily-order-id="' +
            escapeHtml(dailyOrderId) +
            '" data-customer-name="' +
            escapeHtml(name) +
            '" data-address="' +
            escapeHtml(address) +
            '" data-date="' +
            escapeHtml(date) +
            '">Photo &amp; finish</button>' +
            (tel ? '<a class="route-btn route-btn--call" href="' + tel + '">Call</a>' : '');

        card.innerHTML =
            '<p class="next-stop-eyebrow">Next stop' +
            (routeOrder ? ' · #' + escapeHtml(routeOrder) : '') +
            '</p>' +
            '<h2 class="next-stop-store">' +
            escapeHtml(name) +
            '</h2>' +
            '<p class="next-stop-address">' +
            escapeHtml(address || 'No address on file') +
            '</p>' +
            (zone ? '<p class="next-stop-zone">' + escapeHtml(zone) + '</p>' : '') +
            '<div class="next-stop-actions">' +
            actions +
            '</div>' +
            '<button type="button" class="next-stop-details-toggle" data-daily-order-id="' +
            escapeHtml(dailyOrderId) +
            '" data-customer-id="' +
            escapeHtml(customerId) +
            '">Order details</button>' +
            '<div class="order-details-container next-stop-order-details" style="display:none;">' +
            '<div class="order-details-loading">Loading…</div>' +
            '<div class="order-details-content" style="display:none;"></div></div>';

        applyNavigateLinks(card);
        if (window.DriverDelivery && typeof window.DriverDelivery.bindPhotoButtons === 'function') {
            window.DriverDelivery.bindPhotoButtons(card);
        }
    }

    function refreshRouteUi() {
        var list = document.getElementById('stopList');
        if (!list) return;

        var items = Array.prototype.slice.call(list.querySelectorAll('.stop-item'));
        var nextAssigned = false;
        items.forEach(function (el) {
            var status = el.getAttribute('data-status') || '';
            var isDone = status === 'delivered' || status === 'cancelled';
            el.classList.remove('stop-item--next', 'stop-item--upcoming', 'stop-item--past');
            if (isDone) {
                el.classList.add('stop-item--past');
            } else if (!nextAssigned) {
                el.classList.add('stop-item--next');
                nextAssigned = true;
            } else {
                el.classList.add('stop-item--upcoming');
            }
        });

        // Move past stops after active ones
        var past = items.filter(function (el) {
            var s = el.getAttribute('data-status') || '';
            return s === 'delivered' || s === 'cancelled';
        });
        past.forEach(function (el) {
            list.appendChild(el);
        });

        var active = getActiveStops();
        renderNextStop(active[0] || null);

        var root = document.getElementById('driverRouteRoot');
        var total = root ? parseInt(root.getAttribute('data-total') || '0', 10) : 0;
        var completed = items.filter(function (el) {
            return el.getAttribute('data-status') === 'delivered';
        }).length;
        if (root) root.setAttribute('data-completed', String(completed));
        var text = document.getElementById('routeProgressText');
        var fill = document.getElementById('routeProgressFill');
        if (text) text.textContent = completed + ' of ' + total + ' done';
        if (fill && total > 0) fill.style.width = Math.round((completed / total) * 100) + '%';
        var progressPercent = document.getElementById('routeProgressPercent');
        var progressRing = document.querySelector('.route-progress-ring');
        var completedCount = document.getElementById('routeCompletedCount');
        var remainingCount = document.getElementById('routeRemainingCount');
        var totalCount = document.getElementById('routeTotalCount');
        var pastCount = document.getElementById('routePastCount');
        var queueCount = document.getElementById('queueCount');
        var percent = total > 0 ? Math.round((completed / total) * 100) : 0;
        if (progressPercent) progressPercent.textContent = percent + '%';
        if (progressRing) progressRing.style.setProperty('--route-progress', percent + '%');
        if (completedCount) completedCount.textContent = completed;
        if (remainingCount) remainingCount.textContent = active.length;
        if (totalCount) totalCount.textContent = total;
        if (pastCount) pastCount.textContent = past.length;
        if (queueCount) queueCount.textContent = active.length;

        var pastDetails = document.getElementById('pastStopsDetails');
        var completedButton = document.getElementById('routeCompletedButton');
        if (pastDetails) {
            var summary = pastDetails.querySelector('summary');
            if (summary) summary.innerHTML = '<span>Past stops & photos</span><span class="past-stops-summary-count">' + past.length + '</span>';
            pastDetails.hidden = past.length === 0;
        }
        if (completedButton) completedButton.disabled = past.length === 0;
        var pastButton = document.getElementById('routePastStopsButton');
        if (pastButton) pastButton.disabled = past.length === 0;
    }

    window.DriverRoute = {
        mapsDirectionsUrl: mapsDirectionsUrl,
        afterDeliveryComplete: function (dailyOrderId) {
            var stop = document.querySelector(
                '.stop-item[data-daily-order-id="' + String(dailyOrderId) + '"]'
            );
            if (stop) {
                stop.setAttribute('data-status', 'delivered');
                var badge = stop.querySelector('.status-badge');
                if (badge) {
                    badge.textContent = 'Delivered';
                    badge.className = 'status-badge status-badge--delivered';
                }
                var actions = stop.querySelector('.contact-actions');
                    if (actions) {
                        actions.querySelectorAll('.contact-link--address, .contact-link--phone').forEach(function (el) {
                            el.remove();
                        });
                        var photoLink = actions.querySelector('.photo-complete-btn');
                        if (photoLink) {
                            photoLink.textContent = 'View invoice & photos';
                            photoLink.setAttribute('data-photo-mode', 'review');
                        }
                    }
            }
            refreshRouteUi();
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        applyNavigateLinks(document);
        refreshRouteUi();

        var dayStrip = document.querySelector('.route-day-strip');
        var selectedDay = dayStrip && dayStrip.querySelector('.route-day-chip.is-selected');
        if (selectedDay && selectedDay.scrollIntoView) {
            selectedDay.scrollIntoView({ behavior: 'auto', block: 'nearest', inline: 'center' });
        }

        var dateInput = document.getElementById('routeDateInput');
        if (dateInput) {
            dateInput.addEventListener('change', function () {
                if (!dateInput.value) return;
                document.body.classList.add('route-day-loading');
                window.location.assign(
                    '?driver_id=' + encodeURIComponent(dateInput.getAttribute('data-driver-id') || '0') +
                    '&date=' + encodeURIComponent(dateInput.value)
                );
            });
        }

        document.querySelectorAll('.js-day-link').forEach(function (link) {
            link.addEventListener('click', function (event) {
                if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
                event.preventDefault();
                var href = link.href;
                document.body.classList.add('route-day-loading');
                var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                setTimeout(function () { window.location.assign(href); }, reduceMotion ? 0 : 110);
            });
        });

        var touchStart = null;
        var routeSurface = document.getElementById('driverRouteRoot');
        if (routeSurface) {
            routeSurface.addEventListener('touchstart', function (event) {
                if (document.body.classList.contains('photo-mode-open')) return;
                if (event.target.closest('a, button, input, select, textarea, .route-day-strip, .order-details-container')) return;
                var touch = event.changedTouches && event.changedTouches[0];
                if (touch) touchStart = { x: touch.clientX, y: touch.clientY };
            }, { passive: true });
            routeSurface.addEventListener('touchend', function (event) {
                if (!touchStart) return;
                var touch = event.changedTouches && event.changedTouches[0];
                if (!touch) return;
                var dx = touch.clientX - touchStart.x;
                var dy = touch.clientY - touchStart.y;
                touchStart = null;
                if (Math.abs(dx) < 90 || Math.abs(dx) < Math.abs(dy) * 1.4) return;
                document.body.classList.add('route-day-loading');
                var targetDate = dx < 0 ? <?php echo json_encode($nextDate); ?> : <?php echo json_encode($previousDate); ?>;
                window.location.assign('?driver_id=<?php echo (int)$selectedDriverId; ?>&date=' + encodeURIComponent(targetDate));
            }, { passive: true });
        }

        var root = document.getElementById('driverRouteRoot');
        var selectedId = root && root.getAttribute('data-driver-id');
        if (selectedId && parseInt(selectedId, 10) > 0) {
            try {
                localStorage.setItem('tracking_driver_id', String(selectedId));
                localStorage.setItem('gps_tracking_active', 'true');
            } catch (err) { /* ignore */ }
        }

        var pastDetails = document.getElementById('pastStopsDetails');
        var section = document.querySelector('.stop-list-section');
        if (section) section.classList.add('past-collapsed');
        if (pastDetails) {
            pastDetails.addEventListener('toggle', function () {
                if (!section) return;
                if (pastDetails.open) {
                    section.classList.remove('past-collapsed');
                } else {
                    section.classList.add('past-collapsed');
                }
            });
        }
        function openPastHistory(button) {
            if (!pastDetails || button.disabled) return;
                pastDetails.open = true;
                if (section) section.classList.remove('past-collapsed');
                setTimeout(function () {
                    pastDetails.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 0);
        }
        var completedButton = document.getElementById('routeCompletedButton');
        var pastButton = document.getElementById('routePastStopsButton');
        if (completedButton) completedButton.addEventListener('click', function () { openPastHistory(completedButton); });
        if (pastButton) pastButton.addEventListener('click', function () { openPastHistory(pastButton); });

        document.addEventListener('click', function (e) {
            var detailsBtn = e.target.closest('.next-stop-details-toggle');
            if (detailsBtn) {
                e.preventDefault();
                var wrap = detailsBtn.parentElement.querySelector('.order-details-container');
                var nextCard = document.getElementById('nextStopCard');
                var orderId =
                    (detailsBtn.getAttribute('data-daily-order-id') ||
                        (nextCard &&
                            nextCard.querySelector('.photo-complete-btn') &&
                            nextCard.querySelector('.photo-complete-btn').getAttribute('data-daily-order-id')) ||
                        '0');
                if (wrap) loadOrderDetails(wrap, orderId);
                return;
            }

            var stop = e.target.closest('.stop-item');
            if (!stop) return;
            if (e.target.closest('.contact-actions, a, button, input, select, textarea, label')) {
                return;
            }

            // Delivered stops: open photos (common need when reviewing the route)
            if ((stop.getAttribute('data-status') || '') === 'delivered') {
                var photoBtn = stop.querySelector('.photo-complete-btn');
                if (photoBtn) {
                    photoBtn.click();
                    return;
                }
            }

            var container = stop.querySelector('.order-details-container');
            if (container) {
                loadOrderDetails(container, stop.getAttribute('data-daily-order-id'));
            }
        });
    });
})();
</script>

<?php require_once 'includes/footer.php'; ?>
