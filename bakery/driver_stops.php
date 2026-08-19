<?php
// Security is enforced by includes/database.php for every non-public page.
define('ACCESS_ALLOWED', true);

require_once 'includes/config.php';
require_once 'includes/database.php';

$user = function_exists('bakery_current_user') ? bakery_current_user() : null;
$selectedDate = date('Y-m-d');
$selectedDriverId = ($user && bakery_is_driver_route_role($user['role_slug'] ?? ''))
    ? bakery_route_worker_driver_id($db, $user, $selectedDate)
    : (function_exists('bakery_get_selected_driver_id') ? (int)bakery_get_selected_driver_id() : 0);
$driver = null;
$stops = [];
$error = null;

if ($selectedDriverId > 0) {
    $driverStmt = $db->prepare('SELECT id, name FROM drivers WHERE id = ? LIMIT 1');
    $driverStmt->execute([$selectedDriverId]);
    $driver = $driverStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($driver) {
    try {
        $stmt = $db->prepare(
            "SELECT
                c.id AS customer_id,
                c.name AS customer_name,
                c.address AS customer_address,
                c.zone,
                do.id AS daily_order_id,
                doa.route_order,
                doa.scheduled_delivery_time,
                doa.delivery_status
             FROM daily_order_assignments doa
             INNER JOIN daily_orders do ON do.id = doa.daily_order_id
             INNER JOIN customers c ON c.id = do.customer_id
             " . bakery_sfb_ops_origin_clause('c', $db) . "
             WHERE doa.driver_id = ?
               AND doa.delivery_date = ?
               AND do.order_date = ?
             ORDER BY doa.route_order, c.name"
        );
        $stmt->execute([$selectedDriverId, $selectedDate, $selectedDate]);
        $stops = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $error = bakery_t('driver.stops_load_error');
        error_log('Driver stops page error: ' . $e->getMessage());
    }
}

$completedCount = 0;
$currentStopIndex = null;
foreach ($stops as $index => $stop) {
    $status = (string)($stop['delivery_status'] ?? 'pending');
    $isDone = in_array($status, ['delivered', 'cancelled'], true);
    if ($isDone) {
        $completedCount++;
    } elseif ($currentStopIndex === null) {
        $currentStopIndex = $index;
    }
}

function bakery_driver_stops_maps_url($address) {
    $address = trim((string)$address);
    if ($address === '') {
        return '';
    }
    $query = rawurlencode($address);
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (preg_match('/iPhone|iPad|iPod/i', $userAgent)) {
        return 'https://maps.apple.com/?daddr=' . $query . '&dirflg=d';
    }
    return 'https://www.google.com/maps/dir/?api=1&destination=' . $query . '&travelmode=driving';
}

function bakery_driver_stops_status_label($status) {
    $key = 'driver.status_' . $status;
    $translated = function_exists('bakery_t') ? bakery_t($key) : $status;
    return $translated !== $key ? $translated : ucwords(str_replace('_', ' ', $status));
}

function bakery_driver_stops_open_href($dailyOrderId, $selectedDriverId, $selectedDate, $extraParams = []) {
    $params = array_merge([
        'daily_order_id' => (int)$dailyOrderId,
        'open_stop' => '1',
    ], is_array($extraParams) ? $extraParams : []);
    if ($selectedDriverId > 0) {
        $params['driver_id'] = (int)$selectedDriverId;
    }
    if ($selectedDate !== date('Y-m-d')) {
        $params['date'] = $selectedDate;
    }
    return BASE_URL . 'driver.php?' . http_build_query($params);
}

$page_title = bakery_t('page.driver_stops');
require_once 'includes/header.php';
require_once 'includes/nav.php';
?>

<link rel="stylesheet" href="<?php echo bakery_asset_href('css/driver_stops.css'); ?>">

<main class="driver-stops-page">
    <header class="driver-stops-header">
        <div>
            <p class="driver-stops-eyebrow"><?php bakery_te('driver.stops_today'); ?></p>
            <h1><?php echo htmlspecialchars($driver['name'] ?? ($user['display_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="driver-stops-date"><?php echo htmlspecialchars(bakery_localized_date_label(new DateTimeImmutable($selectedDate)), ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <div class="driver-stops-count" aria-label="<?php echo htmlspecialchars(bakery_t('driver.done_count', ['done' => $completedCount, 'total' => count($stops)]), ENT_QUOTES, 'UTF-8'); ?>">
            <strong><?php echo (int)$completedCount; ?>/<?php echo (int)count($stops); ?></strong>
            <span><?php bakery_te('driver.done_short'); ?></span>
        </div>
    </header>

    <?php if ($error): ?>
        <section class="driver-stops-empty" role="alert">
            <strong><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></strong>
        </section>
    <?php elseif (!$driver): ?>
        <section class="driver-stops-empty">
            <strong><?php bakery_te('driver.no_route_login'); ?></strong>
            <p><?php bakery_te('driver.no_route_login_hint'); ?></p>
        </section>
    <?php elseif (!$stops): ?>
        <section class="driver-stops-empty">
            <span class="driver-stops-empty-icon" aria-hidden="true">✓</span>
            <strong><?php bakery_te('driver.no_stops'); ?></strong>
            <p><?php echo htmlspecialchars(bakery_t('driver.nothing_assigned', ['driver' => $driver['name'], 'date' => bakery_localized_month_day(new DateTimeImmutable($selectedDate))]), ENT_QUOTES, 'UTF-8'); ?></p>
        </section>
    <?php else: ?>
        <?php if ($currentStopIndex !== null):
            $currentStop = $stops[$currentStopIndex];
            $currentMapsUrl = bakery_driver_stops_maps_url($currentStop['customer_address'] ?? '');
        ?>
        <section class="driver-current-stop" aria-labelledby="driverCurrentStopTitle">
            <div class="driver-current-stop-topline">
                <span class="driver-current-stop-marker" aria-hidden="true">●</span>
                <span><?php bakery_te('driver.you_are_here'); ?></span>
                <span class="driver-current-stop-position"><?php echo htmlspecialchars(bakery_t('driver.stop_of', ['current' => $currentStopIndex + 1, 'total' => count($stops)]), ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <h2 id="driverCurrentStopTitle"><?php echo htmlspecialchars($currentStop['customer_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <p><?php echo htmlspecialchars($currentStop['customer_address'] ?: bakery_t('driver.no_address'), ENT_QUOTES, 'UTF-8'); ?></p>
            <?php if ($currentMapsUrl): ?><a class="driver-current-stop-action" href="<?php echo htmlspecialchars($currentMapsUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php bakery_te('driver.navigate'); ?> <span aria-hidden="true">↗</span></a><?php endif; ?>
            <a class="driver-current-stop-action driver-current-stop-action--qr" href="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>qr_login.php">Generate customer QR login</a>
        </section>
        <?php else: ?>
        <section class="driver-route-complete" role="status">
            <strong><?php bakery_te('driver.route_complete'); ?></strong>
            <span><?php bakery_te('driver.all_stops_done'); ?></span>
        </section>
        <?php endif; ?>

        <section class="driver-stops-list-section" aria-labelledby="driverStopsListTitle">
            <div class="driver-stops-list-heading">
                <div>
                    <p class="driver-stops-eyebrow"><?php bakery_te('driver.full_list'); ?></p>
                    <h2 id="driverStopsListTitle"><?php bakery_te('driver.stops_today'); ?></h2>
                </div>
                <span><?php echo (int)count($stops); ?> <?php bakery_te('driver.stops_label'); ?></span>
            </div>
            <p class="driver-stops-list-hint"><?php bakery_te('driver.stop_list_hint'); ?></p>
            <ol class="driver-stops-list">
                <?php foreach ($stops as $index => $stop):
                    $status = (string)($stop['delivery_status'] ?? 'pending');
                    $isDone = in_array($status, ['delivered', 'cancelled'], true);
                    $isCurrent = $index === $currentStopIndex;
                    $statusClass = preg_match('/^[a-z_]+$/', $status) ? $status : 'pending';
                    $mapsUrl = bakery_driver_stops_maps_url($stop['customer_address'] ?? '');
                    $canOpenStop = $status !== 'cancelled';
                    $openHref = $canOpenStop
                        ? bakery_driver_stops_open_href($stop['daily_order_id'], $selectedDriverId, $selectedDate)
                        : '';
                    $ordersHref = $canOpenStop && !$isDone
                        ? bakery_driver_stops_open_href($stop['daily_order_id'], $selectedDriverId, $selectedDate, ['view_orders' => '1'])
                        : '';
                ?>
                <li class="driver-stop-row<?php echo $isCurrent ? ' is-current' : ''; ?><?php echo $isDone ? ' is-complete' : ''; ?><?php echo $canOpenStop ? ' is-clickable' : ''; ?>">
                    <?php if ($canOpenStop): ?><a class="driver-stop-open" href="<?php echo htmlspecialchars($openHref, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                    <span class="driver-stop-number" aria-hidden="true"><?php echo $isDone ? '✓' : (int)($stop['route_order'] ?: $index + 1); ?></span>
                    <div class="driver-stop-content">
                        <div class="driver-stop-title-row">
                            <h3><?php echo htmlspecialchars($stop['customer_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                            <?php if ($isCurrent): ?><span class="driver-stop-current-badge"><?php bakery_te('driver.now'); ?></span><?php else: ?><span class="driver-stop-status driver-stop-status--<?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(bakery_driver_stops_status_label($status), ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                        </div>
                        <p><?php echo htmlspecialchars($stop['customer_address'] ?: bakery_t('driver.no_address_short'), ENT_QUOTES, 'UTF-8'); ?></p>
                        <div class="driver-stop-meta">
                            <?php if (!empty($stop['zone'])): ?><span><?php echo htmlspecialchars($stop['zone'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                            <?php if (!empty($stop['scheduled_delivery_time'])): ?><span><?php echo htmlspecialchars(date('g:i A', strtotime($stop['scheduled_delivery_time'])), ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                        </div>
                    </div>
                    <?php if ($canOpenStop): ?></a><?php endif; ?>
                    <?php if ($ordersHref): ?><a class="driver-stop-orders" href="<?php echo htmlspecialchars($ordersHref, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(bakery_t('driver.what_delivering') . ' — ' . $stop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('driver.store_orders'); ?></a><?php endif; ?>
                    <?php if (!$isDone && $mapsUrl): ?><a class="driver-stop-navigate" href="<?php echo htmlspecialchars($mapsUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" aria-label="<?php echo htmlspecialchars(bakery_t('driver.navigate_to', ['name' => $stop['customer_name']]), ENT_QUOTES, 'UTF-8'); ?>">↗</a><?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ol>
        </section>
    <?php endif; ?>
</main>
