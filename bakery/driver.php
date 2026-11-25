<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/zones_catalog.php';
require_once 'includes/google_maps_config.php';
require_once 'includes/product_inventory.php';
require_once 'includes/customer_account.php';
require_once 'includes/operational_exceptions.php';
require_once 'includes/exception_desk.php';
require_once 'includes/pan_carry.php';
require_once 'includes/driver_route_snapshot.php';

bakery_customer_account_ensure_schema($db);

$changeDriver = isset($_GET['change_driver']) && (string)$_GET['change_driver'] === '1';
$routeUser = function_exists('bakery_current_user') ? bakery_current_user() : null;
$isAuthenticatedDriver = $routeUser && bakery_is_driver_route_role($routeUser['role_slug'] ?? '');
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedDateObject = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$selectedDate);
if (!$selectedDateObject || $selectedDateObject->format('Y-m-d') !== (string)$selectedDate) {
    $selectedDate = date('Y-m-d');
    $selectedDateObject = new DateTimeImmutable($selectedDate);
}
$returnTarget = bakery_ops_return_resolve($_GET['return'] ?? null, $selectedDate);
$attentionFailed = (string)($_GET['attention'] ?? '') === 'failed';
$attentionLabel = $attentionFailed
    ? (function_exists('bakery_t') ? bakery_t('ops.attention.failed') : 'Showing failed stops')
    : '';
$driverDeskNotice = null;
$driverDeskError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'remove_pan_carry') {
    try {
        if (function_exists('bakery_require_csrf')) {
            bakery_require_csrf();
        }
        bakery_pan_carry_remove($db, (int)($_POST['carry_id'] ?? 0), (int)($routeUser['id'] ?? 0) ?: null);
        header('Location: driver.php?date=' . rawurlencode((string)$selectedDate) . '&notice=' . rawurlencode(bakery_t('pan_carry.removed')));
        exit;
    } catch (Throwable $e) {
        $driverDeskError = $e->getMessage();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'report_failed_stop') {
    try {
        if (function_exists('bakery_require_csrf')) {
            bakery_require_csrf();
        }
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $dailyOrderId = (int)($_POST['daily_order_id'] ?? 0);
        if ($assignmentId <= 0 && $dailyOrderId > 0) {
            $find = $db->prepare(
                'SELECT id FROM daily_order_assignments WHERE daily_order_id = ? ORDER BY id DESC LIMIT 1'
            );
            $find->execute([$dailyOrderId]);
            $assignmentId = (int)$find->fetchColumn();
        }
        bakery_delivery_recovery_report_failure($db, $assignmentId, $_POST);
        $driverDeskNotice = bakery_t('exception_desk.reported');
        header('Location: driver.php?date=' . rawurlencode((string)$selectedDate) . '&notice=' . rawurlencode($driverDeskNotice));
        exit;
    } catch (Throwable $e) {
        $driverDeskError = $e->getMessage();
    }
}
if (!empty($_GET['notice'])) {
    $driverDeskNotice = substr(trim((string)$_GET['notice']), 0, 160);
}

$todayDate = date('Y-m-d');
$previousDate = $selectedDateObject->modify('-1 day')->format('Y-m-d');
$nextDate = $selectedDateObject->modify('+1 day')->format('Y-m-d');
$todayDateObject = new DateTimeImmutable($todayDate);
$routeDayDiff = (int) round(($selectedDateObject->getTimestamp() - $todayDateObject->getTimestamp()) / 86400);
$routeDateKind = 'today';
$routeDateRelative = bakery_t('common.today');
if ($routeDayDiff < -1) {
    $routeDateKind = 'past';
    $routeDateRelative = bakery_t('driver.route_history');
} elseif ($routeDayDiff === -1) {
    $routeDateKind = 'past';
    $routeDateRelative = bakery_t('driver.yesterday');
} elseif ($routeDayDiff === 0) {
    $routeDateKind = 'today';
    $routeDateRelative = bakery_t('common.today');
} elseif ($routeDayDiff === 1) {
    $routeDateKind = 'future';
    $routeDateRelative = bakery_t('driver.tomorrow');
} else {
    $routeDateKind = 'future';
    $routeDateRelative = bakery_t('driver.route_upcoming');
}
if (!function_exists('bakery_driver_chrome_date')) {
    /**
     * Used when includes/i18n.php on the server is older than this page.
     * Spanish stays day-first. English stays month-day.
     */
    function bakery_driver_chrome_date(DateTimeInterface $date, string $style = 'weekday'): string {
        $es = function_exists('bakery_locale') && bakery_locale() === 'es';
        $day = $date->format('j');
        $monthIndex = (int)$date->format('n');
        if (!$es) {
            if ($style === 'compact') {
                return $date->format('M j');
            }
            return function_exists('bakery_localized_date_label')
                ? bakery_localized_date_label($date)
                : $date->format('F j');
        }
        $months = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $short = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        if ($style === 'compact') {
            return $day . ' ' . $short[$monthIndex];
        }
        $weekdays = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];
        return $weekdays[(int)$date->format('N')] . ' ' . $day . ' de ' . $months[$monthIndex];
    }
}
$routeDateLabel = bakery_driver_chrome_date($selectedDateObject, 'weekday');
$routeDateCompact = bakery_driver_chrome_date($selectedDateObject, 'compact');
$routeView = strtolower(trim((string)($_GET['view'] ?? '')));
// Tomorrow and every past day open the same drive route. Prep stays a choice.
$routePrepMode = $routeView === 'prep' && $routeDateKind !== 'past';
$routeLatestDate = $todayDateObject->modify('+1 day')->format('Y-m-d');
$routeForwardDate = $selectedDate < $routeLatestDate ? $nextDate : '';
$dateInputMax = $selectedDate > $routeLatestDate ? $selectedDate : $routeLatestDate;
$routeDayChoices = [];
for ($dayOffset = -3; $dayOffset <= 3; $dayOffset++) {
    $choiceDate = $selectedDateObject->modify(($dayOffset >= 0 ? '+' : '') . $dayOffset . ' days');
    $choiceKey = $choiceDate->format('Y-m-d');
    if ($choiceKey > $routeLatestDate && $choiceKey !== $selectedDate) {
        continue;
    }
    $routeDayChoices[] = [
        'date' => $choiceDate->format('Y-m-d'),
        'weekday' => bakery_day_names(true)[(int)$choiceDate->format('N')],
        'day' => $choiceDate->format('j'),
        'month' => bakery_localized_month_short($choiceDate),
    ];
}

// A manager/administrator who also drives gets a direct way into their own route.
require_once 'includes/staff_driver_profile.php';
$meRequested = isset($_GET['me']) && (string)$_GET['me'] === '1';
$staffOwnDriverId = $isAuthenticatedDriver ? 0 : bakery_staff_own_driver_id($db, $routeUser);

if ($isAuthenticatedDriver) {
    $selectedDriverId = bakery_route_worker_driver_id($db, $routeUser, $selectedDate);
    $changeDriver = false;
} elseif ($meRequested && $staffOwnDriverId > 0) {
    $selectedDriverId = $staffOwnDriverId;
    $changeDriver = false;
} elseif (isset($_GET['driver_id'])) {
    $selectedDriverId = (int)$_GET['driver_id'];
    if ($selectedDriverId <= 0 && function_exists('bakery_set_selected_driver')) {
        bakery_set_selected_driver(0);
    }
} elseif (!$changeDriver && function_exists('bakery_get_selected_driver_id')) {
    $selectedDriverId = bakery_get_selected_driver_id();
    if ($selectedDriverId <= 0 && $staffOwnDriverId > 0) {
        $selectedDriverId = $staffOwnDriverId;
    }
} else {
    $selectedDriverId = 0;
}
$staffDrivingAsSelf = $staffOwnDriverId > 0 && $selectedDriverId === $staffOwnDriverId;

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

$zoneColors = bakery_zone_display_cycle();
$zonesCatalog = bakery_zones_catalog($db);

$zoneColorMap = [];
$zoneIndex = 0;
$orderedStops = [];
$error = null;
$totalStops = 0;
$totalAmount = 0;
$driverCompletedStops = 0;
$driverLoadItems = [];

/**
 * True when a customer pin is a usable WGS84 point (not empty / 0,0).
 *
 * @return array{lat:float,lng:float}|null
 */
function bakery_driver_valid_latlng($lat, $lng): ?array
{
    if ($lat === null || $lat === '' || $lng === null || $lng === '') {
        return null;
    }
    $lat = filter_var($lat, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($lng, FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lng === false) {
        return null;
    }
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return null;
    }
    if ($lat == 0.0 && $lng == 0.0) {
        return null;
    }

    return ['lat' => (float)$lat, 'lng' => (float)$lng];
}

/**
 * Build a directions URL for the current client (Apple Maps / Google Maps).
 */
function bakery_driver_maps_url($address, $lat = null, $lng = null) {
    $coords = bakery_driver_valid_latlng($lat, $lng);
    if ($coords) {
        $q = rawurlencode($coords['lat'] . ',' . $coords['lng']);
    } else {
        $address = trim((string)$address);
        if ($address === '') {
            return '';
        }
        $q = rawurlencode($address);
    }
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
        return 'https://maps.apple.com/?daddr=' . $q . '&dirflg=d';
    }
    return 'https://www.google.com/maps/dir/?api=1&destination=' . $q . '&travelmode=driving';
}

/**
 * Build a My Route URL for a specific delivery date.
 */
function bakery_driver_route_day_url(int $driverId, string $date, array $extra = []): string
{
    $query = array_merge(['date' => $date], $extra);
    if ($driverId > 0) {
        $query['driver_id'] = $driverId;
    }

    return '?' . http_build_query($query);
}

/**
 * Special-order lines for one dated stop. Loaded once per date.
 *
 * @return list<array<string, mixed>>
 */
function bakery_driver_special_lines_for_stop(int $dailyOrderId, string $date): array
{
    static $byOrder = null;
    static $loadedDate = null;
    if ($byOrder === null || $loadedDate !== $date) {
        $byOrder = [];
        $loadedDate = $date;
        global $db;
        if ($db instanceof PDO) {
            $specialInclude = __DIR__ . '/includes/special_orders.php';
            if (is_file($specialInclude)) {
                require_once $specialInclude;
            }
            if (function_exists('bakery_special_order_lines_by_daily_order')) {
                $byOrder = bakery_special_order_lines_by_daily_order($db, $date);
            }
        }
    }
    return $byOrder[$dailyOrderId] ?? [];
}

/**
 * Product lines (name and quantity) for every stop on one driver's dated route,
 * loaded in a single query so the Now card never fetches per stop.
 *
 * @return array<int, list<array{0:string,1:int}>>
 */
function bakery_driver_route_stop_lines(PDO $db, int $driverId, string $date): array
{
    $byOrder = [];
    if ($driverId <= 0) {
        return $byOrder;
    }
    $stmt = $db->prepare(
        'SELECT doi.daily_order_id, p.name AS product_name, SUM(doi.quantity) AS quantity
         FROM daily_order_items doi
         INNER JOIN products p ON p.id = doi.product_id
         INNER JOIN daily_orders do ON do.id = doi.daily_order_id
         INNER JOIN daily_order_assignments doa ON doa.daily_order_id = do.id
         WHERE doa.driver_id = ? AND do.order_date = ?
         GROUP BY doi.daily_order_id, p.id, p.name
         HAVING SUM(doi.quantity) > 0
         ORDER BY doi.daily_order_id, p.name'
    );
    $stmt->execute([$driverId, $date]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byOrder[(int)$row['daily_order_id']][] = [(string)$row['product_name'], (int)$row['quantity']];
    }
    return $byOrder;
}

/**
 * Compact JSON of a stop's lines for the Now card: dated items, then special-order lines.
 */
function bakery_driver_stop_lines_json(array $stop, string $date): string
{
    $lines = is_array($stop['lines'] ?? null) ? $stop['lines'] : [];
    foreach (bakery_driver_special_lines_for_stop((int)$stop['daily_order_id'], $date) as $special) {
        $name = trim((string)($special['description'] ?? $special['product_name'] ?? ''));
        $qty = (int)($special['quantity'] ?? 0);
        if ($name !== '' && $qty > 0) {
            $lines[] = [$name, $qty];
        }
    }
    return (string)json_encode($lines, JSON_UNESCAPED_UNICODE);
}

/**
 * Inline icon for a route action. SVG stays in the page so CSP does not have to allow an icon font.
 */
function bakery_driver_action_icon(string $name): string
{
    $common = ' class="route-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"';
    if ($name === 'photo') {
        return '<svg' . $common . '><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path><circle cx="12" cy="13" r="4" fill="none" stroke="currentColor" stroke-width="2"></circle></svg>';
    }
    if ($name === 'navigate') {
        return '<svg' . $common . '><path d="M12 2l7 19-7-4-7 4 7-19z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"></path></svg>';
    }
    if ($name === 'amounts') {
        return '<svg' . $common . '><path d="M4 6h16M4 12h16M4 18h10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path></svg>';
    }
    return '<svg' . $common . '><path d="M12 3l10 18H2L12 3z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"></path><path d="M12 9v5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path><circle cx="12" cy="17" r="1" fill="currentColor"></circle></svg>';
}

function bakery_driver_action_label(string $icon, string $text): string
{
    $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    return bakery_driver_action_icon($icon) . '<span class="route-action-label">' . $safe . '</span>';
}

/**
 * Render one driver route stop card for the active queue or history panel.
 */
function bakery_render_driver_stop_item(
    array $stop,
    bool $isNext,
    int $selectedDriverId,
    string $selectedDate,
    string $driverName,
    string $listKind = 'active'
): void {
    $status = $stop['delivery_status'] ?? 'pending';
    $statusClass = in_array($status, ['pending', 'in_transit', 'delivered', 'failed', 'cancelled', 'office_flag'], true) ? $status : 'pending';
    $isDone = in_array($status, ['delivered', 'cancelled', 'failed', 'office_flag'], true);
    $isPastList = $listKind === 'past';
    $phoneHref = preg_replace('/\D+/', '', bakery_driver_stop_phone($stop));
    $phoneHref = $phoneHref !== '' ? 'tel:' . $phoneHref : '';
    $displayPhone = bakery_driver_stop_phone($stop);
    $receivingHours = bakery_driver_receiving_hours_label($stop);
    $stopCoords = bakery_driver_valid_latlng($stop['latitude'] ?? null, $stop['longitude'] ?? null);
    $mapsHref = bakery_driver_maps_url(
        $stop['customer_address'] ?? '',
        $stopCoords['lat'] ?? null,
        $stopCoords['lng'] ?? null
    );

    $itemClass = 'stop-item';
    if ($isPastList || $isDone) {
        $itemClass .= ' stop-item--past';
    } elseif ($isNext) {
        $itemClass .= ' stop-item--next';
    } else {
        $itemClass .= ' stop-item--upcoming';
    }
    if ($status === 'cancelled') {
        $itemClass .= ' stop-item--skipped';
    }
    ?>
    <article class="<?php echo $itemClass; ?>"
        data-customer-id="<?php echo (int)$stop['customer_id']; ?>"
        data-daily-order-id="<?php echo (int)$stop['daily_order_id']; ?>"
        data-customer-name="<?php echo htmlspecialchars($stop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>"
        data-address="<?php echo htmlspecialchars($stop['customer_address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
        data-phone="<?php echo htmlspecialchars($displayPhone, ENT_QUOTES, 'UTF-8'); ?>"
        data-receiving-hours="<?php echo htmlspecialchars($receivingHours, ENT_QUOTES, 'UTF-8'); ?>"
        data-zone="<?php echo htmlspecialchars($stop['zone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
        data-route-order="<?php echo htmlspecialchars((string)($stop['route_order'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
        data-ordered-pieces="<?php echo (int)($stop['ordered_pieces'] ?? 0); ?>"
        data-stop-notes="<?php echo htmlspecialchars(bakery_driver_stop_notes($stop), ENT_QUOTES, 'UTF-8'); ?>"
        data-scheduled-time="<?php echo !empty($stop['scheduled_delivery_time']) ? htmlspecialchars(bakery_locale_time(strtotime($stop['scheduled_delivery_time'])), ENT_QUOTES, 'UTF-8') : ''; ?>"
        data-deliver-after="<?php echo htmlspecialchars((string)($stop['deliver_after'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
        data-deliver-by="<?php echo htmlspecialchars((string)($stop['deliver_by'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
        data-lat="<?php echo $stopCoords ? htmlspecialchars((string)$stopCoords['lat'], ENT_QUOTES, 'UTF-8') : ''; ?>"
        data-lng="<?php echo $stopCoords ? htmlspecialchars((string)$stopCoords['lng'], ENT_QUOTES, 'UTF-8') : ''; ?>"
        data-maps-url="<?php echo htmlspecialchars($mapsHref, ENT_QUOTES, 'UTF-8'); ?>"
        data-assignment-id="<?php echo (int)($stop['assignment_id'] ?? 0); ?>"
        data-lines="<?php echo htmlspecialchars(bakery_driver_stop_lines_json($stop, $selectedDate), ENT_QUOTES, 'UTF-8'); ?>"
        data-status="<?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="stop-item-main">
            <span class="stop-item-order">#<?php echo $stop['route_order'] ?: '&mdash;'; ?></span>
            <span class="stop-item-pick" aria-hidden="true"></span>
            <div class="stop-item-body">
                <div class="stop-item-heading">
                    <div class="stop-item-name"><?php echo htmlspecialchars($stop['customer_name']); ?><?php echo bakery_pan_carry_stop_badge_html($stop, $selectedDate, function_exists('bakery_current_user') ? bakery_current_user() : null); ?></div>
                    <?php if ($isNext && !$isPastList): ?><span class="stop-item-next-label"><?php bakery_te('driver.next_label'); ?></span><?php endif; ?>
                </div>
                <div class="stop-item-address"><?php echo htmlspecialchars($stop['customer_address'] ?: bakery_t('driver.no_address_short')); ?></div>
                <div class="stop-item-meta">
                    <?php if (!empty($stop['zone'])): ?><span><?php echo htmlspecialchars($stop['zone']); ?></span><?php endif; ?>
                    <?php if ($receivingHours !== ''): ?><span><?php echo htmlspecialchars($receivingHours); ?></span><?php endif; ?>
                    <?php if (!empty($stop['scheduled_delivery_time'])): ?><span><?php echo htmlspecialchars(bakery_locale_time(strtotime($stop['scheduled_delivery_time']))); ?></span><?php endif; ?>
                    <?php if ((int)($stop['ordered_pieces'] ?? 0) > 0 && !$isDone): ?><span><?php echo number_format((int)$stop['ordered_pieces']); ?> <?php bakery_te('driver.pcs'); ?></span><?php endif; ?>
                    <?php if (!$isDone): ?><span class="stop-item-leg" data-stop-leg hidden></span><?php endif; ?>
                </div>
                <?php
                $specialStopLines = bakery_driver_special_lines_for_stop((int)$stop['daily_order_id'], $selectedDate);
                if ($specialStopLines !== [] && function_exists('bakery_special_order_stop_summary_html')) {
                    echo bakery_special_order_stop_summary_html($specialStopLines);
                }
                ?>
            </div>
            <span class="status-badge status-badge--<?php echo htmlspecialchars($statusClass); ?>"><?php echo htmlspecialchars(bakery_t('driver.status_' . $statusClass)); ?></span>
            <span class="driver-outbox-chip driver-outbox-chip--stop" data-outbox-chip hidden></span>
        </div>
        <?php if (!$isPastList && !$isDone): ?>
        <div class="stop-row-actions">
            <button type="button"
                class="stop-photo-btn photo-complete-btn"
                data-start-step="photo"
                data-driver-id="<?php echo (int)$selectedDriverId; ?>"
                data-driver-name="<?php echo htmlspecialchars($stop['driver_name'] ?? $driverName, ENT_QUOTES, 'UTF-8'); ?>"
                data-customer-id="<?php echo (int)$stop['customer_id']; ?>"
                data-daily-order-id="<?php echo (int)$stop['daily_order_id']; ?>"
                data-assignment-id="<?php echo (int)($stop['assignment_id'] ?? 0); ?>"
                data-customer-name="<?php echo htmlspecialchars($stop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>"
                data-address="<?php echo htmlspecialchars($stop['customer_address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                data-date="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>"
                aria-label="<?php echo htmlspecialchars(bakery_t('driver.photo_btn'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo bakery_driver_action_label('photo', bakery_t('driver.photo_btn')); ?></button>
            <button type="button"
                class="stop-open-delivery photo-complete-btn"
                data-start-step="delivery"
                data-driver-id="<?php echo (int)$selectedDriverId; ?>"
                data-driver-name="<?php echo htmlspecialchars($stop['driver_name'] ?? $driverName, ENT_QUOTES, 'UTF-8'); ?>"
                data-customer-id="<?php echo (int)$stop['customer_id']; ?>"
                data-daily-order-id="<?php echo (int)$stop['daily_order_id']; ?>"
                data-assignment-id="<?php echo (int)($stop['assignment_id'] ?? 0); ?>"
                data-customer-name="<?php echo htmlspecialchars($stop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>"
                data-address="<?php echo htmlspecialchars($stop['customer_address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                data-date="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>"
                aria-label="<?php echo htmlspecialchars(bakery_t('driver.amounts'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo bakery_driver_action_label('amounts', bakery_t('driver.amounts')); ?></button>
            <button type="button"
                class="stop-go-next"
                data-daily-order-id="<?php echo (int)$stop['daily_order_id']; ?>"
                data-customer-name="<?php echo htmlspecialchars($stop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('driver.make_next'); ?></button>
            <div class="stop-adjust-moves">
                <button type="button" class="stop-move-up" data-daily-order-id="<?php echo (int)$stop['daily_order_id']; ?>" aria-label="<?php echo htmlspecialchars(bakery_t('driver.move_up'), ENT_QUOTES, 'UTF-8'); ?>">&#9650;</button>
                <button type="button" class="stop-move-down" data-daily-order-id="<?php echo (int)$stop['daily_order_id']; ?>" aria-label="<?php echo htmlspecialchars(bakery_t('driver.move_down'), ENT_QUOTES, 'UTF-8'); ?>">&#9660;</button>
            </div>
            <button type="button"
                class="stop-problem-btn"
                data-daily-order-id="<?php echo (int)$stop['daily_order_id']; ?>"
                data-assignment-id="<?php echo (int)($stop['assignment_id'] ?? 0); ?>"
                data-customer-name="<?php echo htmlspecialchars($stop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>"
                aria-label="<?php echo htmlspecialchars(bakery_t('driver.stop_problem_aria', ['name' => $stop['customer_name']]), ENT_QUOTES, 'UTF-8'); ?>">&#8943;</button>
        </div>
        <?php endif; ?>
        <div class="contact-actions"<?php echo $isDone ? '' : ' hidden'; ?>>
            <?php if ($isDone && in_array($status, ['delivered', 'failed', 'office_flag'], true)): ?>
            <button type="button"
                class="contact-link contact-link--photo"
                data-open-store-photo
                data-driver-id="<?php echo (int)$selectedDriverId; ?>"
                data-customer-id="<?php echo (int)$stop['customer_id']; ?>"
                data-daily-order-id="<?php echo (int)$stop['daily_order_id']; ?>"
                data-customer-name="<?php echo htmlspecialchars($stop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>"
                data-address="<?php echo htmlspecialchars($stop['customer_address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                data-date="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">&#128247; <?php bakery_te('driver.add_store_photo'); ?></button>
            <?php endif; ?>
            <?php if ($isDone && $status === 'delivered'): ?>
            <button type="button"
                class="contact-link photo-complete-btn"
                data-photo-mode="review"
                data-driver-id="<?php echo (int)$selectedDriverId; ?>"
                data-driver-name="<?php echo htmlspecialchars($stop['driver_name'] ?? $driverName, ENT_QUOTES, 'UTF-8'); ?>"
                data-customer-id="<?php echo (int)$stop['customer_id']; ?>"
                data-daily-order-id="<?php echo (int)$stop['daily_order_id']; ?>"
                data-customer-name="<?php echo htmlspecialchars($stop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>"
                data-address="<?php echo htmlspecialchars($stop['customer_address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                data-date="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('driver.view_invoice_photos'); ?></button>
            <?php endif; ?>
            <?php if ($isDone && $status === 'cancelled'): ?>
            <button type="button"
                class="contact-link contact-link--unskip unskip-stop-btn"
                data-daily-order-id="<?php echo (int)$stop['daily_order_id']; ?>"
                data-customer-name="<?php echo htmlspecialchars($stop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('driver.unskip_stop'); ?></button>
            <?php endif; ?>
        </div>
    </article>
    <?php
}

function bakery_render_driver_prep_stop(array $stop, int $index, int $total, int $selectedDriverId, string $selectedDate, string $driverName): void
{
    $status = $stop['delivery_status'] ?? 'pending';
    $statusClass = in_array($status, ['pending', 'in_transit', 'delivered', 'failed', 'cancelled', 'office_flag'], true) ? $status : 'pending';
    $locked = in_array($status, ['delivered', 'in_transit', 'office_flag'], true);
    $skipped = $status === 'cancelled';
    $movable = !$locked && !$skipped;
    $receivingHours = bakery_driver_receiving_hours_label($stop);
    $phoneHref = preg_replace('/\D+/', '', bakery_driver_stop_phone($stop));
    $phoneHref = $phoneHref !== '' ? 'tel:' . $phoneHref : '';
    $displayPhone = bakery_driver_stop_phone($stop);
    $stopCoords = bakery_driver_valid_latlng($stop['latitude'] ?? null, $stop['longitude'] ?? null);
    $itemClass = 'route-prep-stop';
    if ($locked) {
        $itemClass .= ' route-prep-stop--locked';
    } elseif ($skipped) {
        $itemClass .= ' route-prep-stop--skipped';
    }
    ?>
    <article class="<?php echo $itemClass; ?>"
        data-daily-order-id="<?php echo (int)$stop['daily_order_id']; ?>"
        data-customer-id="<?php echo (int)$stop['customer_id']; ?>"
        data-customer-name="<?php echo htmlspecialchars($stop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>"
        data-address="<?php echo htmlspecialchars($stop['customer_address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
        data-status="<?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>"
        data-route-order="<?php echo htmlspecialchars((string)($stop['route_order'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
        data-ordered-pieces="<?php echo (int)($stop['ordered_pieces'] ?? 0); ?>"
        data-scheduled-time="<?php echo !empty($stop['scheduled_delivery_time']) ? htmlspecialchars(bakery_locale_time(strtotime($stop['scheduled_delivery_time'])), ENT_QUOTES, 'UTF-8') : ''; ?>"
        data-deliver-after="<?php echo htmlspecialchars((string)($stop['deliver_after'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
        data-deliver-by="<?php echo htmlspecialchars((string)($stop['deliver_by'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
        data-lat="<?php echo $stopCoords ? htmlspecialchars((string)$stopCoords['lat'], ENT_QUOTES, 'UTF-8') : ''; ?>"
        data-lng="<?php echo $stopCoords ? htmlspecialchars((string)$stopCoords['lng'], ENT_QUOTES, 'UTF-8') : ''; ?>">
        <div class="route-prep-stop-top">
            <span class="route-prep-stop-num" aria-hidden="true"><?php echo (int)$stop['route_order'] ?: ($index + 1); ?></span>
            <div class="route-prep-stop-body">
                <strong class="route-prep-stop-name"><?php echo htmlspecialchars($stop['customer_name']); ?><?php echo bakery_pan_carry_stop_badge_html($stop, $selectedDate, function_exists('bakery_current_user') ? bakery_current_user() : null); ?></strong>
                <p class="route-prep-stop-address"><?php echo htmlspecialchars($stop['customer_address'] ?: bakery_t('driver.no_address_short')); ?></p>
                <p class="route-prep-stop-meta">
                    <?php if (!empty($stop['zone'])): ?><span><?php echo htmlspecialchars($stop['zone']); ?></span><?php endif; ?>
                    <?php if ($receivingHours !== ''): ?><span><?php echo htmlspecialchars($receivingHours); ?></span><?php endif; ?>
                    <?php if ((int)($stop['ordered_pieces'] ?? 0) > 0): ?><span><?php echo htmlspecialchars(bakery_t('driver.prep_pieces', ['count' => number_format((int)$stop['ordered_pieces'])])); ?></span><?php endif; ?>
                    <?php if ($skipped): ?><span><?php bakery_te('driver.prep_skipped'); ?></span><?php endif; ?>
                </p>
            </div>
            <?php if ($movable): ?>
            <div class="route-prep-move">
                <button type="button" class="route-prep-move-btn route-prep-move-up" <?php echo $index === 0 ? 'disabled' : ''; ?> aria-label="<?php echo htmlspecialchars(bakery_t('driver.prep_move_up'), ENT_QUOTES, 'UTF-8'); ?>">▲</button>
                <button type="button" class="route-prep-move-btn route-prep-move-down" <?php echo $index >= $total - 1 ? 'disabled' : ''; ?> aria-label="<?php echo htmlspecialchars(bakery_t('driver.prep_move_down'), ENT_QUOTES, 'UTF-8'); ?>">▼</button>
            </div>
            <?php endif; ?>
        </div>
        <div class="route-prep-stop-actions">
            <button type="button" class="route-prep-action" data-open-store-photo data-customer-id="<?php echo (int)$stop['customer_id']; ?>" data-customer-name="<?php echo htmlspecialchars($stop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>" data-daily-order-id="<?php echo (int)$stop['daily_order_id']; ?>" data-driver-id="<?php echo (int)$selectedDriverId; ?>" data-date="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">&#128247; <?php bakery_te('driver.photo_btn'); ?></button>
            <?php if ($movable): ?>
            <button type="button" class="route-prep-action stop-orders-btn" data-daily-order-id="<?php echo (int)$stop['daily_order_id']; ?>"><?php bakery_te('driver.store_orders'); ?></button>
            <?php if ($phoneHref): ?>
            <a class="route-prep-action" href="<?php echo htmlspecialchars($phoneHref); ?>"><?php echo htmlspecialchars($displayPhone !== '' ? bakery_t('driver.call') : bakery_t('driver.call')); ?></a>
            <?php endif; ?>
            <button type="button" class="route-prep-action skip-stop-btn" data-daily-order-id="<?php echo (int)$stop['daily_order_id']; ?>" data-customer-name="<?php echo htmlspecialchars($stop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('driver.skip_stop'); ?></button>
            <button type="button" class="route-prep-action route-prep-action--danger route-prep-remove" data-daily-order-id="<?php echo (int)$stop['daily_order_id']; ?>" data-customer-name="<?php echo htmlspecialchars($stop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('driver.prep_remove'); ?></button>
            <?php elseif ($skipped): ?>
            <button type="button" class="route-prep-action unskip-stop-btn" data-daily-order-id="<?php echo (int)$stop['daily_order_id']; ?>" data-customer-name="<?php echo htmlspecialchars($stop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('driver.unskip_stop'); ?></button>
            <?php endif; ?>
        </div>
        <div class="order-details-container" style="display: none;">
            <div class="order-details-loading"><?php bakery_te('driver.loading_order_details'); ?></div>
            <div class="order-details-content" style="display: none;"></div>
        </div>
    </article>
    <?php
}

if ($selectedDriverId > 0 && $driver) {
    try {
        $assignmentHasNotes = column_exists($db, 'daily_order_assignments', 'notes');
        $assignmentNotesSelect = $assignmentHasNotes
            ? 'doa.notes as assignment_notes,'
            : "'' as assignment_notes,";
        $hasCustomerCoords = column_exists($db, 'customers', 'latitude')
            && column_exists($db, 'customers', 'longitude');
        $coordSelect = $hasCustomerCoords
            ? 'c.latitude, c.longitude,'
            : 'NULL as latitude, NULL as longitude,';

        $stmt = $db->prepare("
            SELECT
                c.id as customer_id,
                c.name as customer_name,
                c.address as customer_address,
                {$coordSelect}
                c.phone as customer_phone,
                c.deliver_after,
                c.deliver_by,
                c.delivery_instructions,
                c.delivery_contact_phone,
                c.zone,
                do.id as daily_order_id,
                do.total_amount,
                do.notes as order_notes,
                doa.id as assignment_id,
                doa.route_order,
                doa.scheduled_delivery_time,
                doa.delivery_status,
                {$assignmentNotesSelect}
                doa.driver_id,
                d.name as driver_name,
                (SELECT COALESCE(SUM(doi.quantity), 0)
                 FROM daily_order_items doi
                 WHERE doi.daily_order_id = do.id) as ordered_pieces
            FROM daily_orders do
            INNER JOIN customers c ON do.customer_id = c.id
            " . bakery_sfb_ops_origin_clause('c', $db) . "
            INNER JOIN daily_order_assignments doa ON do.id = doa.daily_order_id
            INNER JOIN drivers d ON doa.driver_id = d.id
            WHERE doa.driver_id = ? AND do.order_date = ?
            ORDER BY doa.route_order, c.zone, c.name
        ");

        $stmt->execute([$selectedDriverId, $selectedDate]);
        $results = $stmt->fetchAll();
        $routeLinesByOrder = bakery_driver_route_stop_lines($db, (int)$selectedDriverId, $selectedDate);
        $officeFlaggedOrders = function_exists('bakery_delivery_empty_flagged_order_ids')
            ? bakery_delivery_empty_flagged_order_ids($db, $selectedDate)
            : [];

        foreach ($results as $row) {
            $zone = $row['zone'] ?: bakery_t('driver.no_zone');
            if (!isset($zoneColorMap[$zone])) {
                $zoneColorMap[$zone] = bakery_zone_route_color($zonesCatalog, $zone, $zoneColors, $zoneIndex);
                $zoneIndex++;
            }

            $status = $row['delivery_status'] ?? 'pending';
            if (isset($officeFlaggedOrders[(int)$row['daily_order_id']])) {
                $status = 'office_flag';
            }
            if (in_array($status, ['delivered', 'cancelled', 'failed', 'office_flag'], true)) {
                $driverCompletedStops++;
            }

            $orderedStops[] = [
                'customer_id' => $row['customer_id'],
                'customer_name' => $row['customer_name'],
                'customer_address' => $row['customer_address'],
                'latitude' => $row['latitude'] ?? null,
                'longitude' => $row['longitude'] ?? null,
                'customer_phone' => $row['customer_phone'] ?? '',
                'deliver_after' => $row['deliver_after'] ?? null,
                'deliver_by' => $row['deliver_by'] ?? null,
                'delivery_instructions' => $row['delivery_instructions'] ?? null,
                'delivery_contact_phone' => $row['delivery_contact_phone'] ?? null,
                'zone' => $zone,
                'daily_order_id' => $row['daily_order_id'],
                'assignment_id' => (int)($row['assignment_id'] ?? 0),
                'total_amount' => $row['total_amount'],
                'ordered_pieces' => (int)($row['ordered_pieces'] ?? 0),
                'lines' => $routeLinesByOrder[(int)$row['daily_order_id']] ?? [],
                'order_notes' => trim((string)($row['order_notes'] ?? '')),
                'assignment_notes' => trim((string)($row['assignment_notes'] ?? '')),
                'driver_id' => $row['driver_id'],
                'driver_name' => $row['driver_name'],
                'route_order' => $row['route_order'],
                'scheduled_delivery_time' => $row['scheduled_delivery_time'],
                'delivery_status' => $status,
                'zone_color' => $zoneColorMap[$zone],
                'carry_label' => '',
                'carry_id' => 0,
            ];
        }
        $carryByCustomer = bakery_pan_carry_labels_for_date($db, $selectedDate);
        foreach ($orderedStops as $stopIndex => $stop) {
            $carry = $carryByCustomer[(int)$stop['customer_id']] ?? null;
            if (!is_array($carry)) {
                continue;
            }
            $orderedStops[$stopIndex]['carry_label'] = (string)($carry['label'] ?? '');
            $orderedStops[$stopIndex]['carry_id'] = (int)($carry['id'] ?? 0);
        }

        $totalStops = count($results);
        $totalAmount = array_sum(array_column($results, 'total_amount'));
    } catch (Exception $e) {
        $error = 'Error loading driver data: ' . htmlspecialchars($e->getMessage());
    }

    if (bakery_inventory_ready($db) && $selectedDriverId > 0) {
        $manifests = bakery_inventory_pickup_manifests($db, $selectedDate, [$selectedDriverId]);
        $driverLoadItems = $manifests[$selectedDriverId] ?? [];
    }
}

$nextStop = null;
$upcomingStops = [];
$pastStops = [];
foreach ($orderedStops as $stop) {
    $status = $stop['delivery_status'] ?? 'pending';
    $isDone = in_array($status, ['delivered', 'cancelled', 'failed', 'office_flag'], true);
    if ($isDone) {
        $pastStops[] = $stop;
    } elseif ($nextStop === null) {
        $nextStop = $stop;
    } else {
        $upcomingStops[] = $stop;
    }
}

$showSelector = !$isAuthenticatedDriver && ($changeDriver || $selectedDriverId <= 0 || !$driver);
$heroStop = $nextStop;
$openStopOrderId = (string)($_GET['open_stop'] ?? '') === '1' ? (int)($_GET['daily_order_id'] ?? 0) : 0;
$openStopOrders = $openStopOrderId > 0 && (string)($_GET['view_orders'] ?? '') === '1';
if ($openStopOrderId > 0) {
    foreach ($orderedStops as $candidateStop) {
        if ((int)($candidateStop['daily_order_id'] ?? 0) !== $openStopOrderId) {
            continue;
        }
        $candidateStatus = (string)($candidateStop['delivery_status'] ?? 'pending');
        if (!in_array($candidateStatus, ['delivered', 'cancelled', 'failed', 'office_flag'], true)) {
            $heroStop = $candidateStop;
        }
        break;
    }
}
$remainingStops = count($upcomingStops) + ($nextStop ? 1 : 0);
$pastStopCount = count($pastStops);
$prepPieceTotal = 0;
foreach ($orderedStops as $stop) {
    $prepPieceTotal += (int)($stop['ordered_pieces'] ?? 0);
}
$prepTomorrowDate = $todayDateObject->modify('+1 day')->format('Y-m-d');
$prepTomorrowCount = 0;
if ($selectedDriverId > 0) {
    try {
        $tomorrowCountStmt = $db->prepare(
            'SELECT COUNT(*) FROM daily_order_assignments WHERE driver_id = ? AND delivery_date = ?'
        );
        $tomorrowCountStmt->execute([$selectedDriverId, $prepTomorrowDate]);
        $prepTomorrowCount = (int)$tomorrowCountStmt->fetchColumn();
    } catch (Throwable $e) {
        $prepTomorrowCount = 0;
    }
}
$prepEditUrl = bakery_driver_route_day_url((int)$selectedDriverId, $selectedDate, ['view' => 'prep']);
$prepDriveUrl = bakery_driver_route_day_url((int)$selectedDriverId, $selectedDate, ['view' => 'drive']);
$prepTomorrowUrl = bakery_driver_route_day_url((int)$selectedDriverId, $prepTomorrowDate);
$page_title = $driver
    ? bakery_t($routePrepMode ? 'page.driver_prep' : 'page.driver_route', ['name' => $driver['name']])
    : bakery_t('page.driver');
$mapsReady = defined('MAPS_ENABLED') && MAPS_ENABLED && defined('GOOGLE_MAPS_API_KEY') && GOOGLE_MAPS_API_KEY !== '';
// Samsung Internet and Chrome auto-dark recolor the map, photo, and status chips.
$pageColorScheme = 'light';

require_once 'includes/header.php';
require_once 'includes/nav.php';

$progressPct = $totalStops > 0 ? round(($driverCompletedStops / $totalStops) * 100) : 0;
?>

<link rel="stylesheet" href="<?php echo bakery_asset_href('assets/photo_styles.css'); ?>">
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/driver.css'); ?>">
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/exception_desk.css'); ?>">
<script src="<?php echo bakery_asset_href('includes/driver_route.js'); ?>" defer></script>
<script src="<?php echo bakery_asset_href('includes/driver_offline_outbox.js'); ?>" defer></script>
<script src="<?php echo bakery_asset_href('includes/driver_delivery.js'); ?>" defer></script>
<script src="<?php echo bakery_asset_href('includes/driver_route_map.js'); ?>" defer></script>
<script src="<?php echo bakery_asset_href('includes/driver_route_prep.js'); ?>" defer></script>
<script>
window.DriverDataSaver = (function () {
    var key = 'bakeryDriverDataSaver';
    var pref = null;
    try { pref = window.localStorage.getItem(key); } catch (e) {}
    var conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    var auto = !!(conn && (conn.saveData || /^(slow-2g|2g|3g)$/.test(String(conn.effectiveType || ''))));
    var active = pref === 'on' ? true : (pref === 'off' ? false : auto);
    document.documentElement.classList.toggle('driver-data-saver', active);
    return {
        active: active,
        auto: auto,
        pref: pref === 'on' || pref === 'off' ? pref : 'auto',
        set: function (mode) {
            try {
                if (mode === 'on' || mode === 'off') window.localStorage.setItem(key, mode);
                else window.localStorage.removeItem(key);
            } catch (e) {}
            window.location.reload();
        }
    };
})();
document.body.classList.add('driver-workflow-page');
<?php if ($heroStop && empty($routePrepMode)): ?>
document.body.classList.add('route-has-sticky-dock');
<?php endif; ?>
<?php if ($selectedDriverId > 0 && $driver && !$showSelector): ?>
document.body.classList.add('driver-field-mode');
<?php endif; ?>
<?php if (!empty($routePrepMode) && $selectedDriverId > 0 && $driver && !$showSelector): ?>
document.body.classList.add('driver-route-prep');
<?php endif; ?>
</script>
<style>
.driver-pickup-manifest{margin:0;padding:0;border:0;background:transparent}.driver-pickup-manifest summary{list-style:none;display:flex;flex-direction:column;align-items:flex-start;gap:2px;padding:14px 16px;border:1px solid #b8d8c2;border-radius:14px;background:#f2fbf4;cursor:pointer;-webkit-tap-highlight-color:transparent}.driver-pickup-manifest summary::-webkit-details-marker{display:none}.driver-pickup-manifest summary strong{font-size:1.05rem;color:#1f6637}.driver-pickup-manifest-body{padding:0 16px 14px;border:1px solid #b8d8c2;border-top:0;border-radius:0 0 14px 14px;margin-top:-8px;background:#f2fbf4}.driver-pickup-manifest-body p{margin:0;color:#536258}.driver-pickup-manifest-body ul{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 0;padding:0;list-style:none}.driver-pickup-manifest-body li{padding:7px 10px;background:#fff;border-radius:8px;color:#34483a}.driver-pickup-manifest-body li strong{color:#1f6637}
</style>

<div class="driver-route" id="driverRouteRoot"<?php echo ($selectedDriverId > 0 && !empty($driver) && empty($showSelector) && empty($routePrepMode)) ? ' data-route-view="now"' : ''; ?>
    data-driver-id="<?php echo (int)$selectedDriverId; ?>"
    data-driver-name="<?php echo htmlspecialchars($driver['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
    data-date="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>"
    data-total="<?php echo (int)$totalStops; ?>"
    data-completed="<?php echo (int)$driverCompletedStops; ?>">
    <?php echo bakery_ops_render_return_banner($returnTarget, $attentionLabel); ?>

    <?php if ($showSelector): ?>
    <section class="driver-whoami">
        <h1><?php bakery_te('driver.who_driving'); ?></h1>
        <p><?php bakery_te('driver.who_driving_hint'); ?></p>
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
        <p class="empty-state"><?php bakery_te('driver.no_active_drivers'); ?></p>
        <?php endif; ?>
    </section>

    <?php elseif ($selectedDriverId > 0 && $driver): ?>

    <header class="route-topbar">
        <div class="route-topbar-main">
            <div class="route-identity">
                <span class="route-live-dot" aria-hidden="true"></span>
                <div class="route-driver-label"><?php echo htmlspecialchars($driver['name']); ?></div>
            </div>
            <?php if (!$isAuthenticatedDriver && empty($staffDrivingAsSelf)): ?>
            <a class="route-change-link" href="?change_driver=1&amp;date=<?php echo urlencode($selectedDate); ?>"><?php bakery_te('driver.change_driver'); ?></a>
            <?php endif; ?>
        </div>
        <?php if ($totalStops > 0): ?>
        <div class="route-progress" aria-live="polite">
            <div class="route-progress-text" id="routeProgressText"><?php echo htmlspecialchars(bakery_t('driver.done_count', ['done' => $driverCompletedStops, 'total' => $totalStops]), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="route-progress-track"><div class="route-progress-fill" id="routeProgressFill" style="width: <?php echo $progressPct; ?>%;"></div></div>
        </div>
        <?php endif; ?>
    </header>

    <div class="now-strip" id="nowStrip">
        <button type="button" class="now-strip-date" id="nowStripDate" aria-controls="routeDateDisclosure" aria-expanded="false" aria-label="<?php echo htmlspecialchars(bakery_t('nav.choose_route_date'), ENT_QUOTES, 'UTF-8'); ?>">
            <span class="now-strip-date-kind"><?php echo htmlspecialchars($routeDateRelative, ENT_QUOTES, 'UTF-8'); ?></span>
            <strong data-driver-date="compact" data-driver-date-iso="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($routeDateCompact, ENT_QUOTES, 'UTF-8'); ?></strong>
        </button>
        <span class="now-strip-progress" id="nowStripProgress" aria-live="polite"<?php echo $totalStops > 0 ? '' : ' hidden'; ?>><?php echo (int)$driverCompletedStops; ?>/<?php echo (int)$totalStops; ?></span>
        <button type="button" class="driver-outbox-chip" id="driverOutboxChip" hidden></button>
        <button type="button" class="now-strip-refresh" id="nowStripRefresh" hidden><?php bakery_te('driver.route_changed_refresh'); ?></button>
        <div class="now-strip-views" role="group" aria-label="<?php echo htmlspecialchars(bakery_t('driver.view_switch_aria'), ENT_QUOTES, 'UTF-8'); ?>"<?php echo $routePrepMode ? ' hidden' : ''; ?>>
            <button type="button" class="now-strip-view" data-route-view-target="now" aria-pressed="true"><?php bakery_te('driver.view_now'); ?></button>
            <button type="button" class="now-strip-view" data-route-view-target="route" aria-pressed="false"><?php bakery_te('driver.view_route'); ?></button>
        </div>
    </div>

    <nav class="route-day-nav route-day-nav--<?php echo htmlspecialchars($routeDateKind, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(bakery_t('driver.route_day_nav_aria'), ENT_QUOTES, 'UTF-8'); ?>">
        <a class="route-day-nav-arrow route-day-nav-prev js-day-link"
           href="<?php echo htmlspecialchars(bakery_driver_route_day_url((int)$selectedDriverId, $previousDate), ENT_QUOTES, 'UTF-8'); ?>"
           aria-label="<?php echo htmlspecialchars(bakery_t('driver.previous_day'), ENT_QUOTES, 'UTF-8'); ?>">
            <span class="route-day-nav-arrow-icon" aria-hidden="true">‹</span>
            <span class="route-day-nav-arrow-label"><?php bakery_te('driver.prev_short'); ?></span>
        </a>
        <button type="button"
                class="route-day-nav-current"
                id="routeDayNavOpen"
                aria-expanded="false"
                aria-controls="routeDateDisclosure">
            <span class="route-day-nav-kicker"><?php echo htmlspecialchars($routeDateRelative, ENT_QUOTES, 'UTF-8'); ?></span>
            <strong class="route-day-nav-date" data-driver-date="weekday" data-driver-date-iso="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($routeDateLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
            <span class="route-day-nav-picker-hint"><?php bakery_te('driver.change_date'); ?></span>
        </button>
        <?php if ($routeForwardDate !== ''): ?>
        <a class="route-day-nav-arrow route-day-nav-next js-day-link"
           href="<?php echo htmlspecialchars(bakery_driver_route_day_url((int)$selectedDriverId, $routeForwardDate), ENT_QUOTES, 'UTF-8'); ?>"
           aria-label="<?php echo htmlspecialchars(bakery_t('driver.next_day_aria'), ENT_QUOTES, 'UTF-8'); ?>">
            <span class="route-day-nav-arrow-label"><?php bakery_te('driver.next_short'); ?></span>
            <span class="route-day-nav-arrow-icon" aria-hidden="true">›</span>
        </a>
        <?php else: ?>
        <span class="route-day-nav-arrow route-day-nav-next route-day-nav-arrow--end" aria-disabled="true">
            <span class="route-day-nav-arrow-label"><?php bakery_te('driver.next_short'); ?></span>
            <span class="route-day-nav-arrow-icon" aria-hidden="true">›</span>
        </span>
        <?php endif; ?>
    </nav>
    <?php if ($selectedDate !== $todayDate): ?>
    <div class="route-day-nav-today-wrap">
        <a class="route-day-nav-today js-day-link"
           href="<?php echo htmlspecialchars(bakery_driver_route_day_url((int)$selectedDriverId, $todayDate), ENT_QUOTES, 'UTF-8'); ?>">
            <?php bakery_te('driver.jump_to_today'); ?>
        </a>
    </div>
    <?php endif; ?>

    <details class="route-date-disclosure route-date-dock" id="routeDateDisclosure">
        <summary class="route-date-summary">
            <span class="route-date-label">
                <?php echo $selectedDate === $todayDate ? htmlspecialchars(bakery_t('common.today') . ' · ') : ''; ?><span data-driver-date="weekday" data-driver-date-iso="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($routeDateLabel, ENT_QUOTES, 'UTF-8'); ?></span>
            </span>
            <span class="route-date-summary-action">
                <span class="route-date-summary-action-open"><?php bakery_te('driver.change_date'); ?></span>
                <span class="route-date-summary-action-close"><?php bakery_te('common.close'); ?></span>
            </span>
        </summary>
        <div class="route-date-panel">
            <div class="route-day-picker" id="routeDayPicker">
                <a class="route-day-arrow js-day-link" href="<?php echo htmlspecialchars(bakery_driver_route_day_url((int)$selectedDriverId, $previousDate), ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(bakery_t('driver.previous_day'), ENT_QUOTES, 'UTF-8'); ?>">&lsaquo;</a>
                <div class="route-day-strip" aria-label="<?php echo htmlspecialchars(bakery_t('driver.choose_route_day'), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php foreach ($routeDayChoices as $routeDay):
                        $isSelectedRouteDay = $routeDay['date'] === $selectedDate;
                        $isTodayRouteDay = $routeDay['date'] === $todayDate;
                    ?>
                    <a class="route-day-chip js-day-link<?php echo $isSelectedRouteDay ? ' is-selected' : ''; ?><?php echo $isTodayRouteDay ? ' is-today' : ''; ?>"
                       href="<?php echo htmlspecialchars(bakery_driver_route_day_url((int)$selectedDriverId, $routeDay['date']), ENT_QUOTES, 'UTF-8'); ?>"
                       <?php echo $isSelectedRouteDay ? 'aria-current="date"' : ''; ?>>
                        <span class="route-day-weekday"><?php echo htmlspecialchars($routeDay['weekday']); ?></span>
                        <strong><?php echo htmlspecialchars($routeDay['day']); ?></strong>
                        <span class="route-day-month"><?php echo htmlspecialchars($routeDay['month']); ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php if ($routeForwardDate !== ''): ?>
                <a class="route-day-arrow js-day-link" href="<?php echo htmlspecialchars(bakery_driver_route_day_url((int)$selectedDriverId, $routeForwardDate), ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(bakery_t('driver.next_day_aria'), ENT_QUOTES, 'UTF-8'); ?>">&rsaquo;</a>
                <?php else: ?>
                <span class="route-day-arrow route-day-arrow--end" aria-disabled="true">&rsaquo;</span>
                <?php endif; ?>
            </div>
            <div class="route-date-tools">
                <?php if ($selectedDate !== $todayDate): ?>
                <a class="route-today-link js-day-link" href="<?php echo htmlspecialchars(bakery_driver_route_day_url((int)$selectedDriverId, $todayDate), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('driver.jump_to_today'); ?></a>
                <?php endif; ?>
                <label class="route-calendar-label" for="routeDateInput">
                    <?php bakery_te('driver.pick_date'); ?>
                    <input type="date" id="routeDateInput" value="<?php echo htmlspecialchars($selectedDate); ?>" max="<?php echo htmlspecialchars($dateInputMax); ?>" data-latest="<?php echo htmlspecialchars($routeLatestDate); ?>" data-driver-id="<?php echo (int)$selectedDriverId; ?>">
                </label>
            </div>
        </div>
    </details>
    <div class="route-date-backdrop" id="routeDateBackdrop" hidden></div>

    <?php if (!empty($driverDeskError)): ?>
    <div class="empty-state" role="alert"><p><?php echo htmlspecialchars($driverDeskError, ENT_QUOTES, 'UTF-8'); ?></p></div>
    <?php endif; ?>
    <?php if (!empty($driverDeskNotice)): ?>
    <p class="exception-desk__reported" role="status"><?php echo htmlspecialchars($driverDeskNotice, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="empty-state"><p><?php echo $error; ?></p></div>
    <?php elseif ($routePrepMode || $totalStops > 0): ?>

    <div class="route-dashboard<?php echo $routePrepMode ? ' route-dashboard--prep' : ''; ?>">
        <?php if ($routePrepMode): ?>
        <section class="route-prep" id="routePrepRoot"
            data-driver-id="<?php echo (int)$selectedDriverId; ?>"
            data-date="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">
            <p class="route-section-kicker"><?php bakery_te('driver.prep_kicker'); ?></p>
            <h1><?php bakery_te($routeDayDiff === 1 ? 'driver.prep_title' : 'driver.prep_title_date'); ?></h1>
            <p class="route-prep-intro"><?php bakery_te('driver.prep_intro'); ?></p>
            <p class="route-prep-counts">
                <strong><?php echo htmlspecialchars(bakery_t('driver.prep_stops', ['count' => number_format($totalStops)])); ?></strong>
                <?php if ($prepPieceTotal > 0): ?>
                <span><?php echo htmlspecialchars(bakery_t('driver.prep_pieces', ['count' => number_format($prepPieceTotal)])); ?></span>
                <?php endif; ?>
            </p>
            <div class="route-prep-toolbar">
                <button type="button" class="route-prep-add-btn js-route-prep-add" id="routePrepAddBtn"><?php bakery_te('driver.prep_add'); ?></button>
                <a class="route-prep-preview-link" href="<?php echo htmlspecialchars($prepDriveUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('driver.prep_drive_preview'); ?></a>
            </div>
        </section>
        <?php endif; ?>
        <div class="route-mobile-progress" aria-live="polite" <?php echo $totalStops > 0 && !$routePrepMode ? '' : 'hidden'; ?>>
            <div class="route-mobile-progress-row">
                <span class="route-mobile-progress-kind route-mobile-progress-kind--<?php echo htmlspecialchars($routeDateKind, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($routeDateRelative, ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="route-mobile-progress-count" id="routeMobileProgressText"><?php echo htmlspecialchars(bakery_t('driver.done_count', ['done' => $driverCompletedStops, 'total' => $totalStops])); ?></span>
            </div>
            <div class="route-progress-track"><div class="route-progress-fill" id="routeMobileProgressFill" style="width: <?php echo $progressPct; ?>%;"></div></div>
        </div>

        <section class="route-map<?php echo $routePrepMode ? ' route-map--prep' : ''; ?>" id="driverRouteMap" data-map-mode="view"
            data-default-map-scope="<?php echo $routePrepMode ? 'day' : 'next'; ?>"
            data-route-date="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>"
            data-driver-id="<?php echo (int)$selectedDriverId; ?>"
            <?php echo $totalStops === 0 ? 'hidden' : ''; ?>
            aria-label="<?php echo htmlspecialchars(bakery_t('driver.route_map_aria'), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="route-map-toolbar">
                <p class="route-map-live" id="routeMapLive"><?php bakery_te('driver.route_map'); ?></p>
                <div class="route-map-insights" id="routeMapInsights" aria-live="polite">
                    <span class="route-map-insight" id="routeMapDrive" hidden></span>
                    <span class="route-map-insight" id="routeMapWindow" hidden></span>
                </div>
                <div class="route-map-scope" role="group" aria-label="<?php echo htmlspecialchars(bakery_t('driver.map_scope_aria'), ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="button" class="route-map-scope-btn" id="routeMapNext" data-map-scope="next" aria-pressed="true"><?php bakery_te('driver.map_next'); ?></button>
                    <button type="button" class="route-map-scope-btn" id="routeMapNearby" data-map-scope="nearby" aria-pressed="false"><?php bakery_te('driver.map_nearby'); ?></button>
                    <button type="button" class="route-map-scope-btn" id="routeMapDay" data-map-scope="day" aria-pressed="false"><?php bakery_te('driver.map_day'); ?></button>
                </div>
                <div class="route-map-tools">
                    <button type="button" class="route-map-tool" id="routeMapLocate"><?php bakery_te('driver.map_me'); ?></button>
                    <button type="button" class="route-map-tool" id="routeMapFollow" aria-pressed="false"><?php bakery_te('driver.map_follow'); ?></button>
                    <button type="button" class="route-map-tool route-map-tool--zoom" id="routeMapZoomOut" aria-label="<?php echo htmlspecialchars(bakery_t('driver.map_zoom_out'), ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars(bakery_t('driver.map_zoom_out'), ENT_QUOTES, 'UTF-8'); ?>">&minus;</button>
                    <button type="button" class="route-map-tool route-map-tool--zoom" id="routeMapZoomIn" aria-label="<?php echo htmlspecialchars(bakery_t('driver.map_zoom_in'), ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars(bakery_t('driver.map_zoom_in'), ENT_QUOTES, 'UTF-8'); ?>">+</button>
                    <button type="button" class="route-map-tool" id="routeMapExpand" aria-pressed="false"><?php bakery_te('driver.map_expand'); ?></button>
                </div>
            </div>
            <section class="route-map-horizon" id="routeMapHorizon" aria-label="<?php echo htmlspecialchars(bakery_t('driver.map_horizon_aria'), ENT_QUOTES, 'UTF-8'); ?>" hidden>
                <div class="route-map-horizon-heading">
                    <strong><?php bakery_te('driver.map_horizon'); ?></strong>
                    <span id="routeMapHorizonCount"></span>
                </div>
                <div class="route-map-horizon-items" id="routeMapHorizonItems"></div>
            </section>
            <div class="route-map-canvas<?php echo $mapsReady ? '' : ' route-map-canvas--fallback'; ?>" id="routeMapCanvas">
                <?php if (!$mapsReady): ?>
                <p class="route-map-fallback"><?php bakery_te('driver.map_unavailable'); ?></p>
                <?php endif; ?>
            </div>
            <?php if ($mapsReady): ?>
            <button type="button" class="route-map-load" id="routeMapLoad" hidden><?php bakery_te('driver.show_map'); ?></button>
            <?php endif; ?>
            <p class="route-map-unmapped" id="routeMapUnmapped" hidden></p>
            <div class="route-map-sheet" id="routeMapSheet" hidden>
                <div class="route-map-sheet-top">
                    <div>
                        <p class="route-map-sheet-kicker" id="routeMapSheetKicker"></p>
                        <strong class="route-map-sheet-name" id="routeMapSheetName"></strong>
                    </div>
                    <button type="button" class="route-map-sheet-close" id="routeMapSheetClose"><?php bakery_te('common.close'); ?></button>
                </div>
                <p class="route-map-sheet-address" id="routeMapSheetAddress"></p>
                <p class="route-map-sheet-meta" id="routeMapSheetMeta"></p>
                <div class="route-map-sheet-actions">
                    <a class="route-map-sheet-btn route-map-sheet-btn--nav" id="routeMapSheetNavigate" target="_blank" rel="noopener"><?php bakery_te('driver.map_navigate'); ?></a>
                    <button type="button" class="route-map-sheet-btn" id="routeMapSheetGoNext"><?php bakery_te('driver.go_next'); ?></button>
                    <button type="button" class="route-map-sheet-btn" id="routeMapSheetPhoto"><?php bakery_te('driver.photo'); ?></button>
                </div>
            </div>
        </section>

        <?php if (!$routePrepMode): ?>
        <div class="route-primary-column">
            <!-- Next stop (primary on-route view — first on mobile) -->
            <section class="next-stop route-section-next" id="nextStopCard" <?php echo $heroStop ? '' : 'hidden'; ?> aria-live="polite"<?php if ($heroStop): ?> data-daily-order-id="<?php echo (int)$heroStop['daily_order_id']; ?>" data-assignment-id="<?php echo (int)($heroStop['assignment_id'] ?? 0); ?>" data-customer-name="<?php echo htmlspecialchars($heroStop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
        <?php if ($heroStop):
            $nextDisplayPhone = bakery_driver_stop_phone($heroStop);
            $phoneHref = preg_replace('/\D+/', '', $nextDisplayPhone);
            $phoneHref = $phoneHref !== '' ? 'tel:' . $phoneHref : '';
            $nextReceivingHours = bakery_driver_receiving_hours_label($heroStop);
            $nextCoords = bakery_driver_valid_latlng($heroStop['latitude'] ?? null, $heroStop['longitude'] ?? null);
            $mapsHref = bakery_driver_maps_url(
                $heroStop['customer_address'] ?? '',
                $nextCoords['lat'] ?? null,
                $nextCoords['lng'] ?? null
            );
            $stopNotes = bakery_driver_stop_notes($heroStop);
            $nextStatus = $heroStop['delivery_status'] ?? 'pending';
            $nextStatusClass = in_array($nextStatus, ['pending', 'in_transit', 'delivered', 'failed', 'cancelled'], true) ? $nextStatus : 'pending';
        ?>
        <p class="next-stop-eyebrow">
            <span><?php bakery_te('driver.next_stop'); ?><?php if ($heroStop['route_order']): ?> &middot; #<?php echo (int)$heroStop['route_order']; ?><?php endif; ?></span>
            <?php if ($remainingStops > 1): ?>
            <button type="button" class="change-next-btn" id="changeNextStopBtn"><?php bakery_te('driver.change_next'); ?></button>
            <?php endif; ?>
        </p>
        <div class="next-stop-heading-row">
            <h2 class="next-stop-store" id="nextStopStore"><?php echo htmlspecialchars($heroStop['customer_name']); ?></h2>
            <span class="status-badge status-badge--<?php echo htmlspecialchars($nextStatusClass); ?>" id="nextStopStatus"><?php echo htmlspecialchars(bakery_t('driver.status_' . $nextStatusClass)); ?></span>
        </div>
        <p class="next-stop-address" id="nextStopAddress"><?php echo htmlspecialchars($heroStop['customer_address'] ?: bakery_t('driver.no_address')); ?></p>
        <?php if ($phoneHref): ?>
        <a class="next-stop-phone" id="nextStopPhone" href="<?php echo htmlspecialchars($phoneHref); ?>"><?php echo htmlspecialchars($nextDisplayPhone); ?></a>
        <?php endif; ?>
        <div class="next-stop-meta" id="nextStopMeta">
            <?php if (!empty($heroStop['zone'])): ?><span><?php echo htmlspecialchars($heroStop['zone']); ?></span><?php endif; ?>
            <?php if ($nextReceivingHours !== ''): ?><span><?php echo htmlspecialchars($nextReceivingHours); ?></span><?php endif; ?>
            <?php if (!empty($heroStop['scheduled_delivery_time'])): ?><span><?php echo htmlspecialchars(bakery_locale_time(strtotime($heroStop['scheduled_delivery_time']))); ?></span><?php endif; ?>
            <?php if ((int)($heroStop['ordered_pieces'] ?? 0) > 0): ?><span><?php echo htmlspecialchars(bakery_t('driver.pieces_ordered', ['count' => number_format((int)$heroStop['ordered_pieces'])])); ?></span><?php endif; ?>
        </div>
        <?php if ($stopNotes !== ''): ?>
        <div class="next-stop-notes" id="nextStopNotes">
            <strong><?php bakery_te('driver.delivery_notes'); ?></strong>
            <p><?php echo nl2br(htmlspecialchars($stopNotes)); ?></p>
        </div>
        <?php endif; ?>

        <div class="next-stop-actions">
            <button type="button"
                class="route-btn route-btn--photo photo-complete-btn"
                data-start-step="photo"
                data-driver-id="<?php echo (int)$selectedDriverId; ?>"
                data-driver-name="<?php echo htmlspecialchars($heroStop['driver_name'] ?? $driver['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                data-customer-id="<?php echo (int)$heroStop['customer_id']; ?>"
                data-daily-order-id="<?php echo (int)$heroStop['daily_order_id']; ?>"
                data-assignment-id="<?php echo (int)($heroStop['assignment_id'] ?? 0); ?>"
                data-customer-name="<?php echo htmlspecialchars($heroStop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>"
                data-address="<?php echo htmlspecialchars($heroStop['customer_address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                data-date="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>"
                aria-label="<?php echo htmlspecialchars(bakery_t('driver.photo'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo bakery_driver_action_label('photo', bakery_t('driver.photo')); ?></button>
            <?php if ($mapsHref): ?>
                <a class="route-btn route-btn--navigate js-navigate-link"
                href="<?php echo htmlspecialchars($mapsHref); ?>"
                data-address="<?php echo htmlspecialchars($heroStop['customer_address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                data-lat="<?php echo $nextCoords ? htmlspecialchars((string)$nextCoords['lat'], ENT_QUOTES, 'UTF-8') : ''; ?>"
                data-lng="<?php echo $nextCoords ? htmlspecialchars((string)$nextCoords['lng'], ENT_QUOTES, 'UTF-8') : ''; ?>"
                target="_blank" rel="noopener"
                aria-label="<?php echo htmlspecialchars(bakery_t('driver.navigate'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo bakery_driver_action_label('navigate', bakery_t('driver.navigate')); ?></a>
            <?php endif; ?>
            <button type="button"
                class="route-btn route-btn--problem stop-problem-btn"
                data-daily-order-id="<?php echo (int)$heroStop['daily_order_id']; ?>"
                data-assignment-id="<?php echo (int)($heroStop['assignment_id'] ?? 0); ?>"
                data-customer-name="<?php echo htmlspecialchars($heroStop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>"
                aria-label="<?php echo htmlspecialchars(bakery_t('driver.problem'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo bakery_driver_action_label('problem', bakery_t('driver.problem')); ?></button>
        </div>
        <details class="next-stop-more">
                <summary class="next-stop-more-toggle"><?php bakery_te('driver.more_actions'); ?></summary>
                <div class="next-stop-more-body">
            <?php if ($phoneHref): ?>
            <a class="route-btn route-btn--call" href="<?php echo htmlspecialchars($phoneHref); ?>"><?php bakery_te('driver.call_store'); ?></a>
            <?php endif; ?>
            <button type="button"
                class="route-btn route-btn--skip skip-stop-btn"
                data-daily-order-id="<?php echo (int)$heroStop['daily_order_id']; ?>"
                data-customer-name="<?php echo htmlspecialchars($heroStop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('driver.skip_stop'); ?></button>
            <button type="button"
                class="route-btn fail-stop-btn"
                data-daily-order-id="<?php echo (int)$heroStop['daily_order_id']; ?>"
                data-assignment-id="<?php echo (int)($heroStop['assignment_id'] ?? 0); ?>"
                data-customer-name="<?php echo htmlspecialchars($heroStop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('exception_desk.cant_deliver'); ?></button>
            <?php if ($remainingStops > 1): ?>
            <button type="button" class="route-btn route-btn--adjust change-next-btn"><?php bakery_te('driver.adjust_route'); ?></button>
            <?php endif; ?>
                </div>
            </details>
        <?php echo bakery_exception_desk_driver_fail_form($heroStop, 'driver.php?date=' . rawurlencode($selectedDate)); ?>

        <button type="button" class="next-stop-details-toggle" id="nextStopDetailsToggle"
            data-daily-order-id="<?php echo (int)$heroStop['daily_order_id']; ?>"
            data-customer-id="<?php echo (int)$heroStop['customer_id']; ?>"
            aria-expanded="<?php echo ($openStopOrders && (int)$heroStop['daily_order_id'] === $openStopOrderId) ? 'true' : 'false'; ?>"
            aria-controls="nextStopOrderDetails"><?php bakery_te('driver.what_delivering'); ?></button>
        <div class="order-details-container next-stop-order-details" id="nextStopOrderDetails" style="display:<?php echo ($openStopOrders && (int)$heroStop['daily_order_id'] === $openStopOrderId) ? 'block' : 'none'; ?>;">
            <div class="order-details-loading"><?php bakery_te('driver.loading_order'); ?></div>
            <div class="order-details-content" style="display:none;"></div>
        </div>
        <?php endif; ?>
    </section>
    <div class="now-capture-host" id="nowCaptureHost" hidden></div>

    <section class="route-done-banner" id="routeDoneBanner" <?php echo $nextStop ? 'hidden' : ''; ?>>
        <h2><?php bakery_te('driver.route_complete'); ?></h2>
        <p><?php echo htmlspecialchars(bakery_t('driver.all_stops_done_for', [
            'driver' => $driver['name'],
            'date' => $selectedDate === $todayDate ? bakery_t('common.today') : $routeDateLabel,
        ])); ?></p>
    </section>

            <section class="route-overview route-section-overview" aria-label="<?php echo htmlspecialchars(bakery_t('driver.route_summary')); ?>">
                <div class="route-overview-heading">
                    <div>
                        <p class="route-section-kicker"><?php echo htmlspecialchars($selectedDate === $todayDate ? bakery_t('driver.today_at_glance') : bakery_t('driver.route_at_glance')); ?></p>
                        <h1><?php echo htmlspecialchars($selectedDate === $todayDate ? bakery_t('driver.route_ready') : bakery_t('driver.route_overview')); ?></h1>
                        <p class="route-overview-copy"><strong id="routeRemainingCount"><?php echo (int)$remainingStops; ?></strong> <?php echo htmlspecialchars(bakery_t($remainingStops === 1 ? 'driver.stop_left_one' : 'driver.stop_left_many')); ?></p>
                    </div>
                    <span class="route-progress-ring" style="--route-progress: <?php echo (int)$progressPct; ?>%;" aria-hidden="true"><strong id="routeProgressPercent"><?php echo (int)$progressPct; ?>%</strong><span><?php bakery_te('driver.done_short'); ?></span></span>
                </div>
                <div class="route-stat-grid" role="list" aria-label="<?php echo htmlspecialchars(bakery_t('driver.route_totals')); ?>">
                    <button type="button" class="route-stat route-stat--done" id="routeCompletedButton" role="listitem" aria-controls="pastStopsDetails" <?php echo $pastStopCount === 0 ? 'disabled' : ''; ?> title="<?php echo htmlspecialchars(bakery_t('driver.view_completed_stops')); ?>">
                        <strong id="routeCompletedCount"><?php echo (int)$driverCompletedStops; ?></strong>
                        <span><?php bakery_te('driver.completed_view_history'); ?></span>
                    </button>
                    <div class="route-stat route-stat--next" role="listitem">
                        <strong id="routeTotalCount"><?php echo (int)$totalStops; ?></strong>
                        <span><?php bakery_te('driver.total_stops'); ?></span>
                    </div>
                    <button type="button" class="route-stat route-stat--past" id="routePastStopsButton" role="listitem" aria-controls="pastStopsDetails" <?php echo $pastStopCount === 0 ? 'disabled' : ''; ?> title="<?php echo htmlspecialchars(bakery_t('driver.view_completed_stops')); ?>">
                        <strong id="routePastCount"><?php echo (int)$pastStopCount; ?></strong>
                        <span><?php bakery_te('driver.past_stops_label'); ?></span>
                    </button>
                </div>
            </section>

            <details class="driver-pickup-manifest route-section-manifest" aria-label="<?php echo htmlspecialchars(bakery_t('driver.pickup_manifest')); ?>"<?php echo $driverCompletedStops > 0 ? '' : ' open'; ?>>
                <summary>
                    <span class="route-section-kicker"><?php bakery_te('driver.before_leave'); ?></span>
                    <strong><?php bakery_te('driver.pickup_manifest'); ?></strong>
                </summary>
                <div class="driver-pickup-manifest-body">
                <?php if ($driverLoadItems): ?>
                    <ul><?php foreach ($driverLoadItems as $item): ?><li><strong><?php echo number_format($item['loaded_quantity']); ?></strong> <?php echo htmlspecialchars($item['name']); ?></li><?php endforeach; ?></ul>
                <?php else: ?>
                    <p><?php bakery_te('driver.no_pickup_assigned'); ?></p>
                <?php endif; ?>
                </div>
            </details>

            <?php if ($remainingStops === 0 && $totalStops > 0): ?>
                <p class="route-closeout-cta">
                    <a class="route-btn route-btn--primary" href="route_closeout.php?date=<?php echo urlencode($selectedDate); ?>&amp;driver_id=<?php echo (int)$selectedDriverId; ?>">
                        <?php bakery_te('driver.close_route'); ?>
                    </a>
                </p>
            <?php endif; ?>

        </div>

        <div class="route-secondary-column">

    <!-- Full stop list: upcoming first, past collapsed -->
    <section class="stop-list-section">
        <div class="stop-list-heading-row">
            <div>
                <p class="route-section-kicker"><?php bakery_te('driver.queue'); ?></p>
                <h3 class="stop-list-heading"><?php bakery_te('driver.upcoming_stops'); ?></h3>
            </div>
            <div class="stop-list-heading-actions">
                <?php if ($routeDateKind !== 'past'): ?>
                <a class="route-prep-edit-link" href="<?php echo htmlspecialchars($prepEditUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('driver.prep_edit_route'); ?></a>
                <?php endif; ?>
                <button type="button"
                    class="route-adjust-toggle"
                    id="routeAdjustToggle"
                    aria-pressed="false"
                    <?php echo $remainingStops > 1 ? '' : 'hidden'; ?>><?php bakery_te('driver.reorder'); ?></button>
                <?php if ($mapsReady): ?>
                <button type="button" class="route-suggest-btn" id="routeSuggestBtn" <?php echo $remainingStops >= 3 ? '' : 'hidden'; ?>><?php bakery_te('driver.suggest_order'); ?></button>
                <?php endif; ?>
                <span class="stop-list-count"><strong id="queueCount"><?php echo (int)$remainingStops; ?></strong> <?php bakery_te('driver.remaining'); ?></span>
            </div>
        </div>
        <p class="stop-list-helper" id="stopListHint"><?php bakery_te('driver.stop_list_hint'); ?></p>
        <p class="stop-list-helper stop-list-adjust-hint"><?php bakery_te('driver.adjust_hint'); ?></p>
        <div class="route-suggest-preview" id="routeSuggestPreview" role="status" aria-live="polite" hidden></div>
        <div class="stop-list" id="stopList">
            <?php
            foreach ($orderedStops as $stop):
                $status = $stop['delivery_status'] ?? 'pending';
                if (in_array($status, ['delivered', 'cancelled', 'failed'], true)) {
                    continue;
                }
                $isNext = $nextStop && (int)$nextStop['daily_order_id'] === (int)$stop['daily_order_id'];
                bakery_render_driver_stop_item($stop, $isNext, (int)$selectedDriverId, $selectedDate, $driver['name'] ?? '', 'active');
            endforeach;
            ?>
        </div>

        <details class="past-stops-details" id="pastStopsDetails"<?php echo empty($pastStops) ? ' hidden' : ''; ?>>
            <summary><span><?php bakery_te('driver.past_stops'); ?></span><span class="past-stops-summary-count"><?php echo count($pastStops); ?></span></summary>
            <p class="past-stops-hint"><?php bakery_te('driver.past_stops_hint'); ?></p>
            <div class="past-stops-list" id="pastStopsList">
                <?php foreach ($pastStops as $stop):
                    bakery_render_driver_stop_item($stop, false, (int)$selectedDriverId, $selectedDate, $driver['name'] ?? '', 'past');
                endforeach; ?>
            </div>
        </details>
    </section>

        </div>
        <?php else: ?>
        <section class="route-prep-list-section" aria-label="<?php echo htmlspecialchars(bakery_t('driver.prep_title'), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="route-prep-list" id="routePrepList">
                <?php
                $prepIndex = 0;
                $prepMovableStops = [];
                foreach ($orderedStops as $stop) {
                    $prepStatus = $stop['delivery_status'] ?? 'pending';
                    if (!in_array($prepStatus, ['delivered', 'in_transit', 'cancelled'], true)) {
                        $prepMovableStops[] = $stop;
                    }
                }
                $prepMovableCount = count($prepMovableStops);
                if ($orderedStops === []): ?>
                <p class="route-prep-empty" id="routePrepEmpty"><?php bakery_te('driver.prep_empty'); ?></p>
                <?php else:
                    foreach ($orderedStops as $stop) {
                        $prepStatus = $stop['delivery_status'] ?? 'pending';
                        $isMovable = !in_array($prepStatus, ['delivered', 'in_transit', 'cancelled'], true);
                        $moveIndex = $isMovable ? $prepIndex : 0;
                        if ($isMovable) {
                            $prepIndex++;
                        }
                        bakery_render_driver_prep_stop($stop, $isMovable ? $moveIndex : 0, $prepMovableCount, (int)$selectedDriverId, $selectedDate, $driver['name'] ?? '');
                    }
                endif; ?>
            </div>
            <button type="button" class="route-prep-add-btn route-prep-add-btn--list js-route-prep-add" id="routePrepAddListBtn"><?php bakery_te('driver.prep_add'); ?></button>
        </section>
        <div class="route-prep-sheet" id="routePrepSheet" hidden>
            <div class="route-prep-sheet-inner">
                <div class="route-prep-sheet-top">
                    <h2><?php bakery_te('driver.prep_add_title'); ?></h2>
                    <button type="button" class="route-prep-sheet-close" id="routePrepSheetClose"><?php bakery_te('driver.prep_close_add'); ?></button>
                </div>
                <label class="route-prep-search-label" for="routePrepSearch">
                    <span><?php bakery_te('driver.prep_search'); ?></span>
                    <input type="search" id="routePrepSearch" enterkeyhint="search" autocomplete="off" autocapitalize="off" placeholder="<?php echo htmlspecialchars(bakery_t('driver.prep_search_placeholder'), ENT_QUOTES, 'UTF-8'); ?>">
                </label>
                <div class="route-prep-results" id="routePrepResults"></div>
            </div>
        </div>
        <div class="route-prep-add-dock" id="routePrepAddDock">
            <button type="button" class="route-prep-add-btn route-prep-add-btn--dock js-route-prep-add" id="routePrepAddDockBtn"><?php bakery_te('driver.prep_add'); ?></button>
        </div>
        <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="empty-state">
        <h3><?php bakery_te($selectedDate === $todayDate ? 'driver.no_stops' : 'driver.no_stops_for_date'); ?></h3>
        <p><?php echo htmlspecialchars(bakery_t('driver.nothing_assigned', [
            'driver' => $driver['name'],
            'date' => $routeDateLabel,
        ])); ?></p>
        <?php if ($routeDateKind !== 'past'): ?>
        <p><a class="route-prep-empty-edit" href="<?php echo htmlspecialchars($prepEditUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('driver.prep_edit_route'); ?></a></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="empty-state">
        <h3><?php bakery_te('driver.not_found'); ?></h3>
        <p><?php bakery_te('driver.not_found_desc'); ?></p>
        <a class="route-change-link" href="?change_driver=1&amp;date=<?php echo urlencode($selectedDate); ?>"><?php bakery_te('driver.choose_another'); ?></a>
    </div>
    <?php endif; ?>
</div>

<div class="route-sticky-dock" id="routeStickyDock" <?php echo ($heroStop && empty($routePrepMode)) ? '' : 'hidden'; ?> aria-hidden="<?php echo ($heroStop && empty($routePrepMode)) ? 'false' : 'true'; ?>">
    <div class="route-sticky-dock-inner" id="routeStickyDockInner"><?php if ($heroStop && empty($routePrepMode)): ?><?php $dockMapsHref = bakery_driver_maps_url($heroStop['customer_address'] ?? '', bakery_driver_stop_coords($heroStop)); ?><?php if ($dockMapsHref !== ''): ?><a class="route-sticky-btn route-sticky-btn--nav js-navigate-stop" href="<?php echo htmlspecialchars($dockMapsHref, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" aria-label="<?php echo htmlspecialchars(bakery_t('driver.navigate'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo bakery_driver_action_label('navigate', bakery_t('driver.navigate')); ?></a><?php endif; ?><button type="button" class="route-sticky-btn route-sticky-btn--finish photo-complete-btn" data-start-step="photo" data-driver-id="<?php echo (int)$selectedDriverId; ?>" data-driver-name="<?php echo htmlspecialchars($heroStop['driver_name'] ?? ($driver['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-customer-id="<?php echo (int)$heroStop['customer_id']; ?>" data-daily-order-id="<?php echo (int)$heroStop['daily_order_id']; ?>" data-assignment-id="<?php echo (int)($heroStop['assignment_id'] ?? 0); ?>" data-customer-name="<?php echo htmlspecialchars($heroStop['customer_name'], ENT_QUOTES, 'UTF-8'); ?>" data-address="<?php echo htmlspecialchars($heroStop['customer_address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-date="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(bakery_t('driver.photo'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo bakery_driver_action_label('photo', bakery_t('driver.photo')); ?></button><?php endif; ?></div>
</div>

<div class="route-adjust-dock" id="routeAdjustDock" hidden>
    <p class="route-adjust-dock-hint"><?php bakery_te('driver.adjust_hint'); ?></p>
    <div class="route-adjust-dock-actions">
        <button type="button" class="route-adjust-cancel" id="routeAdjustCancel"><?php bakery_te('common.cancel'); ?></button>
        <button type="button" class="route-adjust-save" id="routeAdjustSave"><?php bakery_te('driver.save_route_order'); ?></button>
    </div>
</div>

<div class="route-success-toast" id="routeSuccessToast" role="status" aria-live="polite" hidden></div>

<!-- Photos / Complete modal -->
<div id="deliveryPhotoModal" class="photo-modal" style="display:none;" aria-hidden="true" aria-modal="true" role="dialog" aria-labelledby="deliveryPhotoModalTitle">
    <div class="photo-modal-content delivery-photo-modal-content">
        <div class="photo-modal-header">
            <span class="photo-modal-customer" id="deliveryModalCustomer"></span>
            <button type="button" class="photo-modal-close" id="deliveryPhotoModalClose" aria-label="<?php echo htmlspecialchars(bakery_t('driver.route_back')); ?>">‹</button>
            <div class="photo-modal-header-text">
                <span class="photo-modal-eyebrow" id="deliveryModalEyebrow"><?php bakery_te('driver.stop_workflow'); ?></span>
                <h3 id="deliveryPhotoModalTitle"><?php bakery_te('driver.arrival_photo'); ?></h3>
            </div>
        </div>

        <nav class="delivery-wizard-steps" id="deliveryWizardSteps" aria-label="<?php echo htmlspecialchars(bakery_t('driver.delivery_steps')); ?>">
            <button type="button" class="delivery-wizard-step is-active" data-step="photo" id="deliveryWizardStepPhoto">
                <span class="delivery-wizard-step-num">1</span>
                <span class="delivery-wizard-step-label"><?php bakery_te('driver.arrive'); ?></span>
            </button>
            <button type="button" class="delivery-wizard-step" data-step="delivery" id="deliveryWizardStepDelivery">
                <span class="delivery-wizard-step-num">2</span>
                <span class="delivery-wizard-step-label"><?php bakery_te('driver.confirm'); ?></span>
            </button>
            <button type="button" class="delivery-wizard-step" data-step="invoice" id="deliveryWizardStepInvoice">
                <span class="delivery-wizard-step-num">3</span>
                <span class="delivery-wizard-step-label"><?php bakery_te('driver.next'); ?></span>
            </button>
        </nav>

        <div class="photo-modal-body">
            <div class="photo-assignment-confirm" id="deliveryPhotoAssignment"></div>

            <div class="delivery-step-panel is-active" id="deliveryStepPhoto" data-step-panel="photo">
                <div class="delivery-arrive-orders" id="deliveryArriveOrders">
                    <button type="button" class="delivery-orders-toggle" id="deliveryOrdersToggle"
                        aria-expanded="false" aria-controls="deliveryOrderDetails"><?php bakery_te('driver.what_delivering'); ?></button>
                    <div class="order-details-container delivery-order-details" id="deliveryOrderDetails" hidden>
                        <div class="order-details-loading"><?php bakery_te('driver.loading_order'); ?></div>
                        <div class="order-details-content" hidden></div>
                    </div>
                </div>
                <div class="photo-upload-section delivery-camera-section" id="deliveryCameraSection">
                    <div class="delivery-camera-title-row">
                        <h4 id="deliveryCameraHeading"><?php bakery_te('driver.take_photo'); ?></h4>
                        <select id="deliveryPhotoType" class="photo-type-select" aria-label="<?php echo htmlspecialchars(bakery_t('driver.photo_type')); ?>">
                            <option value="Before" selected><?php bakery_te('driver.arrival_before'); ?></option>
                            <option value="After"><?php bakery_te('driver.departure_after'); ?></option>
                            <option value="Receipt"><?php bakery_te('driver.receipt'); ?></option>
                        </select>
                    </div>
                    <p class="delivery-photo-guidance" id="deliveryPhotoGuidance"><?php bakery_te('driver.photo_guidance'); ?></p>
                    <div class="delivery-camera-frame is-camera-idle" id="deliveryCameraFrame"
                        data-camera-idle="<?php echo htmlspecialchars(bakery_t('driver.camera_off'), ENT_QUOTES, 'UTF-8'); ?>"
                        data-native-camera-hint="<?php echo htmlspecialchars(bakery_t('driver.native_camera_hint'), ENT_QUOTES, 'UTF-8'); ?>"
                        data-saving-photo="<?php echo htmlspecialchars(bakery_t('driver.saving_photo'), ENT_QUOTES, 'UTF-8'); ?>">
                        <video id="deliveryCameraVideo" autoplay playsinline muted style="display:none;"></video>
                        <canvas id="deliveryCameraCanvas" class="hidden"></canvas>
                        <img id="deliveryPhotoPreview" alt="<?php echo htmlspecialchars(bakery_t('driver.captured_photo_preview')); ?>" style="display:none;">
                        <div class="delivery-camera-status" id="deliveryCameraStatus" aria-live="polite"></div>
                    </div>
                    <div class="photo-upload-controls delivery-camera-controls">
                        <button type="button" class="btn btn-primary delivery-shutter-btn" id="deliveryCaptureBtn"><?php bakery_te('driver.activate_camera'); ?></button>
                        <button type="button" class="btn btn-outline hidden" id="deliveryRetakeBtn"><?php bakery_te('driver.retake_photo'); ?></button>
                        <button type="button" class="btn btn-outline delivery-picker-btn" id="deliveryFilePickerBtn"><?php bakery_te('driver.use_phone_camera'); ?></button>
                        <button type="button" class="btn btn-outline delivery-inapp-btn" id="deliveryInAppCameraBtn" hidden><?php bakery_te('driver.use_in_app_camera'); ?></button>
                        <button type="button" class="btn btn-outline delivery-gallery-btn" id="deliveryGalleryPickerBtn"><?php bakery_te('driver.choose_photo'); ?></button>
                        <button type="button" class="delivery-skip-inline" id="deliverySkipPhotoInlineBtn"><?php bakery_te('driver.skip_photo'); ?></button>
                        <input type="file" id="deliveryFileInput" accept="image/*" capture="environment" hidden>
                        <input type="file" id="deliveryGalleryInput" accept="image/*" hidden>
                    </div>
                    <p class="delivery-camera-tip" id="deliveryCameraTip" hidden>
                        <span><?php bakery_te('driver.camera_tip_ios'); ?></span>
                        <button type="button" class="delivery-camera-tip-close" data-dismiss-tip><?php bakery_te('driver.camera_tip_ok'); ?></button>
                    </p>
                    <div class="delivery-no-photo" id="deliveryNoPhotoReasons" hidden>
                        <p class="delivery-no-photo-title"><?php bakery_te('driver.no_photo_why'); ?></p>
                        <div class="delivery-no-photo-chips">
                            <button type="button" class="delivery-no-photo-chip" data-proof-skip-reason="camera_broken"><?php bakery_te('driver.no_photo_reason_camera_broken'); ?></button>
                            <button type="button" class="delivery-no-photo-chip" data-proof-skip-reason="customer_declined"><?php bakery_te('driver.no_photo_reason_customer_declined'); ?></button>
                            <button type="button" class="delivery-no-photo-chip" data-proof-skip-reason="no_safe_spot"><?php bakery_te('driver.no_photo_reason_no_safe_spot'); ?></button>
                        </div>
                        <button type="button" class="delivery-no-photo-cancel" data-no-photo-cancel><?php bakery_te('driver.no_photo_take_one'); ?></button>
                    </div>
                    <textarea id="deliveryPhotoNotes" rows="2" placeholder="<?php echo htmlspecialchars(bakery_t('driver.photo_note_placeholder'), ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(bakery_t('driver.photo_notes')); ?>"></textarea>
                    <div class="photo-upload-progress" id="deliveryPhotoProgress">
                        <div class="photo-upload-progress-bar"><div class="photo-upload-progress-fill" id="deliveryPhotoProgressFill"></div></div>
                    </div>
                </div>

                <details class="existing-photos-section delivery-photos-gallery" id="deliveryPhotosGallerySection">
                    <summary><?php bakery_te('driver.saved_photos'); ?> <span id="deliveryPhotoCount" class="delivery-photo-count"></span></summary>
                    <div class="existing-photos-grid" id="deliveryPhotosGrid">
                        <div class="loading-photos"><?php bakery_te('driver.loading_photos'); ?></div>
                    </div>
                </details>
                <div class="delivery-review-actions" id="deliveryReviewActions" hidden>
                    <button type="button" class="btn btn-primary" id="deliveryReviewActivateCameraBtn"><?php bakery_te('driver.add_photo'); ?></button>
                </div>
            </div>

            <div class="delivery-step-panel" id="deliveryStepDelivery" data-step-panel="delivery" hidden>
                <div class="delivery-photo-strip" id="deliveryPhotoStrip">
                    <button type="button" class="btn btn-primary delivery-inline-take-photo" id="deliveryInlineTakePhotoBtn" hidden>&#128247; <?php bakery_te('driver.take_photo_now'); ?></button>
                    <div class="delivery-inline-proof" id="deliveryInlineProof" data-proof-state="" hidden>
                        <img id="deliveryInlineThumb" alt="<?php echo htmlspecialchars(bakery_t('driver.captured_photo_preview'), ENT_QUOTES, 'UTF-8'); ?>" hidden>
                        <span class="delivery-inline-proof-text">
                            <span class="delivery-inline-proof-label" id="deliveryInlineProofLabel" aria-live="polite"></span>
                            <button type="button" class="delivery-inline-retake" id="deliveryInlineRetake"><?php bakery_te('driver.retake'); ?></button>
                        </span>
                    </div>
                    <div class="delivery-inline-proof delivery-inline-proof--second" id="deliveryInlineProof2" data-proof-state="" hidden>
                        <img id="deliveryInlineThumb2" alt="<?php echo htmlspecialchars(bakery_t('driver.captured_photo_preview'), ENT_QUOTES, 'UTF-8'); ?>" hidden>
                        <span class="delivery-inline-proof-text">
                            <span class="delivery-inline-proof-label" id="deliveryInlineProofLabel2" aria-live="polite"></span>
                            <button type="button" class="delivery-inline-retake" id="deliveryInlineRetake2"><?php bakery_te('driver.retake'); ?></button>
                        </span>
                    </div>
                </div>
                <p class="delivery-totals-line" id="deliveryTotalsLine">
                    <span id="deliveryTotalsText"></span>
                    <button type="button" class="delivery-totals-adjust" id="deliveryTotalsAdjust" aria-controls="deliveryAdjustDetails" aria-expanded="false"><?php bakery_te('driver.adjust'); ?></button>
                    <button type="button" class="delivery-see-invoice" id="deliverySeeInvoice"><?php bakery_te('driver.see_invoice'); ?></button>
                </p>
                <section class="delivery-confirmation" id="deliveryConfirmation" aria-label="<?php echo htmlspecialchars(bakery_t('driver.confirm_delivery_quantities')); ?>">
                    <div class="delivery-confirmation-heading">
                        <div><strong><?php bakery_te('driver.confirm_delivery'); ?></strong><span id="deliveryConfirmationHint"><?php bakery_te('driver.confirm_delivery_hint'); ?></span></div>
                        <span class="delivery-confirmation-total" id="deliveryConfirmationTotal">$0.00</span>
                    </div>
                    <p id="deliveryEmptyOrderNotice" class="delivery-variance-alert" hidden role="alert"></p>
                    <button type="button" class="btn btn-outline" id="deliveryFlagEmptyBtn" hidden><?php bakery_te('driver.flag_for_office'); ?></button>
                    <p id="deliveryWellOverWarning" class="delivery-well-over" hidden></p>
                    <div class="delivery-variance-alert" id="deliveryVarianceAlert" hidden role="alert">
                        <strong><?php bakery_te('driver.variance_title'); ?></strong>
                        <span id="deliveryVarianceText"></span>
                        <label class="delivery-variance-ack" for="deliveryVarianceAck">
                            <input type="checkbox" id="deliveryVarianceAck">
                            <span><?php bakery_te('driver.variance_ack'); ?></span>
                        </label>
                    </div>
                    <div class="delivery-ordered-ref" id="deliveryOrderedRef" aria-live="polite"></div>
                    <p class="delivery-confirmation-breakdown" id="deliveryConfirmationBreakdown" aria-live="polite"></p>
                    <details class="delivery-adjust" id="deliveryAdjustDetails" open>
                        <summary class="delivery-adjust-summary"><?php bakery_te('driver.adjust_delivery'); ?></summary>
                        <div class="delivery-adjust-body">
                            <div class="delivery-confirmation-fields">
                                <label class="delivery-qty-label"><?php bakery_te('driver.pieces_delivered'); ?>
                                    <div class="quantity-stepper quantity-stepper--large">
                                        <button type="button" class="quantity-stepper-btn" data-quantity-target="deliveryPiecesInput" data-quantity-step="-1" aria-label="<?php echo htmlspecialchars(bakery_t('driver.decrease_pieces')); ?>">−</button>
                                        <input type="number" id="deliveryPiecesInput" min="0" step="1" inputmode="numeric" pattern="[0-9]*">
                                        <button type="button" class="quantity-stepper-btn" data-quantity-target="deliveryPiecesInput" data-quantity-step="1" aria-label="<?php echo htmlspecialchars(bakery_t('driver.increase_pieces')); ?>">+</button>
                                    </div>
                                </label>
                                <div id="deliveryProductCounts" class="delivery-product-counts" hidden>
                                    <p id="deliveryProductCountsPrompt"></p>
                                    <div id="deliveryProductCountList"></div>
                                </div>
                                <div id="deliverySpecialCounts" class="delivery-special-counts" hidden>
                                    <p id="deliverySpecialCountsLabel" class="delivery-special-counts__label"></p>
                                    <div id="deliverySpecialCountList"></div>
                                </div>
                                <label class="delivery-qty-label"><?php bakery_te('driver.credits_back'); ?>
                                    <div class="quantity-stepper quantity-stepper--large">
                                        <button type="button" class="quantity-stepper-btn" data-quantity-target="deliveryCreditsInput" data-quantity-step="-1" aria-label="<?php echo htmlspecialchars(bakery_t('driver.decrease_credits')); ?>">−</button>
                                        <input type="number" id="deliveryCreditsInput" min="0" step="1" value="0" inputmode="numeric" pattern="[0-9]*">
                                        <button type="button" class="quantity-stepper-btn" data-quantity-target="deliveryCreditsInput" data-quantity-step="1" aria-label="<?php echo htmlspecialchars(bakery_t('driver.increase_credits')); ?>">+</button>
                                    </div>
                                </label>
                            </div>
                            <p class="delivery-credit-stock-note" id="deliveryCreditStockNote"><?php bakery_te('driver.credits_return_stock'); ?></p>
                            <p class="delivery-credit-alloc-note" id="deliveryCreditAllocNote" hidden></p>
                            <div class="delivery-pricing-row" id="deliveryPricingRow" hidden>
                                <div class="delivery-pricing-alert" role="alert">
                                    <strong><?php bakery_te('driver.no_price_title'); ?></strong>
                                    <span><?php bakery_te('driver.no_price_hint'); ?></span>
                                </div>
                                <label><?php bakery_te('driver.price_per_piece'); ?>
                                    <input type="number" id="deliveryPricePerPieceInput" min="0.01" step="0.01" inputmode="decimal" placeholder="0.00">
                                </label>
                            </div>
                            <p class="delivery-prepaid-note" id="deliveryPrepaidNote" hidden></p>
                            <div class="delivery-cod-row" id="deliveryCodRow" hidden>
                                <label id="deliveryCodOverrideLabel" hidden><?php bakery_te('driver.cod_override_reason'); ?>
                                    <textarea id="deliveryCodOverrideReason" maxlength="255" rows="2"></textarea>
                                </label>
                                <label><?php bakery_te('driver.cash_collected'); ?>
                                    <input type="number" id="deliveryCashCollectedInput" min="0" step="0.01" inputmode="decimal" placeholder="0.00">
                                </label>
                                <p class="delivery-cod-hint"><?php bakery_te('driver.cod_hint'); ?></p>
                            </div>
                            <button type="button" class="btn btn-outline delivery-adjust-invoice-btn" id="deliveryWizardReviewBtn"><?php bakery_te('driver.review_invoice'); ?></button>
                        </div>
                    </details>
                </section>
            </div>

            <div class="delivery-step-panel" id="deliveryStepInvoice" data-step-panel="invoice" hidden>
                <section class="delivery-invoice-preview" id="deliveryInvoicePreview" aria-label="<?php echo htmlspecialchars(bakery_t('driver.invoice_preview')); ?>">
                    <div class="delivery-invoice-scroll">
                        <div class="delivery-invoice-brand"><span><?php bakery_te('driver.delivery_invoice'); ?></span><strong>Sour Flour Bakery</strong></div>
                        <div class="delivery-invoice-meta"><div><span><?php bakery_te('driver.invoice_date'); ?></span><strong id="deliveryInvoiceDate"></strong></div><div><span><?php bakery_te('driver.invoice_customer'); ?></span><strong id="deliveryInvoiceCustomer"></strong></div><div><span><?php bakery_te('driver.invoice_address'); ?></span><strong id="deliveryInvoiceAddress"></strong></div><div><span><?php bakery_te('driver.invoice_driver'); ?></span><strong id="deliveryInvoiceDriver"></strong></div></div>
                        <div class="delivery-invoice-items" id="deliveryInvoiceItems"></div>
                        <div class="delivery-invoice-lines"><div><span><?php bakery_te('driver.ordered_pieces'); ?></span><strong id="deliveryInvoiceOrderedPieces"></strong></div><div><span><?php bakery_te('driver.pieces_delivered'); ?></span><strong id="deliveryInvoicePieces"></strong></div><div><span><?php bakery_te('driver.credits_back'); ?></span><strong id="deliveryInvoiceCredits"></strong></div><div id="deliveryInvoicePriceRow"><span><?php bakery_te('driver.price_per_piece'); ?></span><strong id="deliveryInvoicePrice"></strong><input type="number" id="deliveryInvoicePriceInput" class="delivery-invoice-price-input" min="0.01" step="0.01" inputmode="decimal" placeholder="0.00" hidden aria-label="<?php echo htmlspecialchars(bakery_t('driver.invoice_price_per_piece')); ?>"></div><div id="deliveryInvoiceCashRow" hidden><span><?php bakery_te('driver.cash_collected'); ?></span><strong id="deliveryInvoiceCash"></strong></div></div>
                        <div class="delivery-invoice-total"><span><?php bakery_te('driver.invoice_total'); ?></span><strong id="deliveryInvoiceTotal"></strong></div>
                        <p class="delivery-invoice-note" id="deliveryInvoicePricingNote"></p>
                    </div>
                </section>
            </div>
        </div>

        <div class="photo-modal-footer">
            <div class="photo-upload-status" id="deliveryPhotoStatus" aria-live="polite"></div>

            <div class="delivery-variance-confirm" id="deliveryVarianceConfirm" hidden role="alertdialog" aria-labelledby="deliveryVarianceConfirmTitle">
                <p id="deliveryVarianceConfirmTitle"><strong><?php bakery_te('driver.variance_confirm_title'); ?></strong></p>
                <p id="deliveryVarianceConfirmText"></p>
                <div class="delivery-variance-confirm-actions">
                    <button type="button" class="btn btn-outline" id="deliveryVarianceCancelBtn"><?php bakery_te('driver.go_back'); ?></button>
                    <button type="button" class="btn btn-primary" id="deliveryVarianceOkBtn"><?php bakery_te('driver.save_anyway'); ?></button>
                </div>
            </div>

            <div class="delivery-wizard-actions" id="deliveryWizardActions">
                <button type="button" class="btn btn-outline delivery-wizard-back" id="deliveryWizardBackBtn" hidden><?php bakery_te('driver.back'); ?></button>
                <button type="button" class="btn btn-outline delivery-wizard-skip" id="deliveryWizardSkipBtn"><?php bakery_te('driver.skip_photo'); ?></button>
                <button type="button" class="btn btn-outline fail-stop-btn" id="deliveryWizardFailBtn"><?php bakery_te('exception_desk.cant_deliver'); ?></button>
                <button type="button" class="btn btn-outline delivery-second-photo-btn" id="deliverySecondPhotoBtn" hidden><?php bakery_te('driver.second_photo'); ?></button>
                <button type="button" class="complete-delivery-btn delivery-wizard-primary" id="deliveryWizardPrimaryBtn" aria-busy="false"><?php bakery_te('driver.delivered'); ?></button>
            </div>

            <div class="delivery-invoice-footer-actions" id="deliveryInvoiceFooterActions" hidden>
                <button type="button" class="btn btn-outline delivery-invoice-edit-saved" id="deliveryInvoiceEditSavedBtn" hidden><?php bakery_te('driver.edit_saved'); ?></button>
                <div class="delivery-invoice-actions" id="deliveryInvoiceActions">
                    <button type="button" class="btn btn-outline" id="deliveryInvoiceBackBtn"><?php bakery_te('driver.back_to_edit'); ?></button>
                    <button type="button" class="complete-delivery-btn" id="deliveryInvoiceConfirmBtn"><?php bakery_te('driver.confirm_save'); ?></button>
                </div>
            </div>

            <p class="delivery-submit-note" id="deliverySubmitNote"><?php bakery_te('driver.save_note'); ?></p>
        </div>
    </div>
</div>

<!-- Full-size photo viewer -->
<div id="deliveryPhotoViewer" class="photo-viewer-modal" style="display:none;" aria-hidden="true" role="dialog">
    <div class="photo-viewer-content">
        <span class="photo-viewer-close" id="deliveryPhotoViewerClose" role="button" tabindex="0" aria-label="<?php echo htmlspecialchars(bakery_t('driver.close')); ?>">&times;</span>
        <img id="deliveryViewerImage" alt="<?php echo htmlspecialchars(bakery_t('driver.delivery_photo_alt')); ?>">
        <div class="photo-viewer-info">
            <h4 id="deliveryViewerTitle"></h4>
            <p id="deliveryViewerMeta"></p>
            <div class="delivery-viewer-actions">
                <button type="button" class="btn btn-outline" id="deliveryViewerRetakeBtn"><?php bakery_te('driver.retake'); ?></button>
                <button type="button" class="btn btn-danger" id="deliveryViewerRemoveBtn"><?php bakery_te('driver.remove'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Skip stop modal -->
<div id="skipStopModal" class="skip-stop-modal" hidden aria-hidden="true" role="dialog" aria-labelledby="skipStopModalTitle">
    <div class="skip-stop-modal-backdrop" id="skipStopModalBackdrop"></div>
    <div class="skip-stop-modal-content">
        <h2 id="skipStopModalTitle"><?php bakery_te('driver.skip_stop_title'); ?></h2>
        <p class="skip-stop-modal-customer" id="skipStopModalCustomer"></p>
        <p class="skip-stop-modal-prompt"><?php bakery_te('driver.skip_stop_prompt'); ?></p>
        <label class="skip-stop-reason-label" for="skipStopReasonInput"><?php bakery_te('driver.skip_reason_label'); ?></label>
        <textarea id="skipStopReasonInput" rows="3" maxlength="500" required
            placeholder="<?php echo htmlspecialchars(bakery_t('driver.skip_reason_placeholder'), ENT_QUOTES, 'UTF-8'); ?>"
            aria-required="true"></textarea>
        <p class="skip-stop-error" id="skipStopError" hidden role="alert"></p>
        <div class="skip-stop-modal-actions">
            <button type="button" class="btn btn-outline" id="skipStopCancelBtn"><?php bakery_te('driver.skip_cancel'); ?></button>
            <button type="button" class="btn btn-danger" id="skipStopConfirmBtn"><?php bakery_te('driver.skip_confirm'); ?></button>
        </div>
    </div>
</div>

<!-- Failed stop report -->
<div id="failStopModal" class="exception-desk-modal" hidden aria-hidden="true" role="dialog" aria-labelledby="failStopModalTitle">
    <div class="exception-desk-modal__backdrop" id="failStopModalBackdrop"></div>
    <div class="exception-desk-modal__content exception-desk exception-desk--driver">
        <h2 id="failStopModalTitle"><?php bakery_te('driver.problem_title'); ?></h2>
        <p class="exception-desk__who" id="failStopModalCustomer"></p>
        <div class="problem-sheet-actions" id="problemSheetActions">
            <button type="button" class="problem-chip" data-problem-action="not_on_route"><?php bakery_te('driver.problem_not_on_route'); ?></button>
            <button type="button" class="problem-chip" data-problem-action="different_amount"><?php bakery_te('driver.problem_different_amount'); ?></button>
            <button type="button" class="problem-chip" data-problem-action="empty_order"><?php bakery_te('driver.problem_empty_order'); ?></button>
        </div>
        <p class="problem-sheet-error" id="problemSheetError" hidden role="alert"></p>
        <p class="exception-desk__detail"><?php bakery_te('driver.problem_cant_deliver_prompt'); ?></p>
        <form id="failStopForm">
            <?php echo bakery_exception_desk_csrf(); ?>
            <input type="hidden" name="action" value="report_failed_stop">
            <input type="hidden" name="assignment_id" id="failStopAssignmentId" value="">
            <input type="hidden" name="daily_order_id" id="failStopOrderId" value="">
            <?php echo bakery_exception_desk_reason_chips_html('reason_code'); ?>
            <label class="exception-desk__note-label" for="failStopNote"><?php bakery_te('exception_desk.driver_note'); ?></label>
            <textarea id="failStopNote" name="manager_note" rows="2" maxlength="2000"
                placeholder="<?php echo htmlspecialchars(bakery_t('exception_desk.driver_note_ph'), ENT_QUOTES, 'UTF-8'); ?>"></textarea>
            <p class="skip-stop-error" id="failStopError" hidden role="alert"></p>
            <div class="exception-desk__btn-row">
                <button type="button" class="exception-desk__btn" id="failStopCancelBtn"><?php bakery_te('driver.skip_cancel'); ?></button>
                <button type="submit" class="exception-desk__btn exception-desk__btn--primary" id="failStopConfirmBtn"><?php bakery_te('exception_desk.report'); ?></button>
            </div>
            <div class="exception-desk__hq-links" id="failStopHqLinks" data-hq-phone="+14155091210" data-store="" data-order-id="">
                <a class="exception-desk__hq-link" id="failStopCallLink" href="tel:+14155091210"><?php bakery_te('exception_desk.call_hq'); ?></a>
                <a class="exception-desk__hq-link" id="failStopSmsLink" href="sms:+14155091210"><?php bakery_te('exception_desk.text_hq'); ?></a>
                <a class="exception-desk__hq-link" id="failStopWaLink" href="https://wa.me/14155091210" target="_blank" rel="noopener"><?php bakery_te('exception_desk.whatsapp_hq'); ?></a>
            </div>
        </form>
    </div>
</div>

<script>
window.__DRIVER_PAGE_I18N__ = <?php echo json_encode([
    'next_stop' => bakery_t('driver.next_stop'),
    'no_address' => bakery_t('driver.no_address'),
    'pieces_ordered' => bakery_t('driver.pieces_ordered', ['count' => '__COUNT__']),
    'navigate' => bakery_t('driver.navigate'),
    'photo_finish' => bakery_t('driver.photo_finish'),
    'finish_stop' => bakery_t('driver.finish_stop'),
    'arrival_photo' => bakery_t('driver.arrival_photo'),
    'arrival_photo_failed' => bakery_t('driver.arrival_photo_failed'),
    'activate_camera' => bakery_t('driver.activate_camera'),
    'retake_photo_type' => bakery_t('driver.retake_photo_type'),
    'take_arrival_photo' => bakery_t('driver.take_arrival_photo'),
    'take_departure_photo' => bakery_t('driver.take_departure_photo'),
    'take_receipt_photo' => bakery_t('driver.take_receipt_photo'),
    'photo_guidance' => bakery_t('driver.photo_guidance'),
    'departure_guidance' => bakery_t('driver.departure_guidance'),
    'receipt_guidance' => bakery_t('driver.receipt_guidance'),
    'stop_workflow' => bakery_t('driver.stop_workflow'),
    'leave' => bakery_t('driver.leave'),
    'confirm' => bakery_t('driver.confirm'),
    'amounts' => bakery_t('driver.amounts'),
    'confirm_delivery' => bakery_t('driver.confirm_delivery'),
    'delivery_invoice' => bakery_t('driver.delivery_invoice'),
    'continue_to_delivery' => bakery_t('driver.continue_to_delivery'),
    'directions' => bakery_t('driver.directions'),
    'arrival_saved' => bakery_t('driver.arrival_photo_saved'),
    'continue' => bakery_t('driver.continue'),
    'review_invoice' => bakery_t('driver.review_invoice'),
    'save_delivery' => bakery_t('driver.save_delivery'),
    'save_and_leave_photo' => bakery_t('driver.save_and_leave_photo'),
    'outbox_queued' => bakery_t('driver.outbox_queued'),
    'outbox_synced' => bakery_t('driver.outbox_synced'),
    'outbox_failed' => bakery_t('driver.outbox_failed'),
    'phone_camera' => bakery_t('driver.phone_camera'),
    'skip_photo' => bakery_t('driver.skip_photo'),
    'skip_departure_photo' => bakery_t('driver.skip_departure_photo'),
    'proof_photo_required' => bakery_t('driver.proof_required'),
    'variance_ack_needed' => bakery_t('driver.variance_ack_needed'),
    'saved_leaving_later' => bakery_t('driver.saved_leaving_later'),
    'more_actions' => bakery_t('driver.more_actions'),
    'hq_message' => bakery_t('exception_desk.hq_message'),
    'confirm_save' => bakery_t('driver.confirm_save'),
    'delivery_photos' => bakery_t('driver.delivery_photos'),
    'call_store' => bakery_t('driver.call_store'),
    'what_delivering' => bakery_t('driver.what_delivering'),
    'store_orders' => bakery_t('driver.store_orders'),
    'loading_order' => bakery_t('driver.loading_order'),
    'delivery_notes' => bakery_t('driver.delivery_notes'),
    'skip_stop' => bakery_t('driver.skip_stop'),
    'adjust_route' => bakery_t('driver.adjust_route'),
    'adjust_hint' => bakery_t('driver.adjust_hint'),
    'go_next' => bakery_t('driver.go_next'),
    'move_next' => bakery_t('driver.move_next'),
    'move_later' => bakery_t('driver.move_later'),
    'save_route_order' => bakery_t('driver.save_route_order'),
    'route_order_saved' => bakery_t('driver.route_order_saved'),
    'route_order_error' => bakery_t('driver.route_order_error'),
    'change_next' => bakery_t('driver.change_next'),
    'prep_add' => bakery_t('driver.prep_add'),
    'prep_add_this' => bakery_t('driver.prep_add_this'),
    'prep_take' => bakery_t('driver.prep_take'),
    'prep_take_confirm' => bakery_t('driver.prep_take_confirm'),
    'prep_ask_manager' => bakery_t('driver.prep_ask_manager'),
    'prep_take_needs_approval' => bakery_t('driver.prep_take_needs_approval'),
    'prep_other_routes' => bakery_t('driver.prep_other_routes'),
    'prep_other_routes_count' => bakery_t('driver.prep_other_routes_count'),
    'prep_remove_confirm' => bakery_t('driver.prep_remove_confirm'),
    'prep_unassigned' => bakery_t('driver.prep_unassigned'),
    'prep_usual' => bakery_t('driver.prep_usual'),
    'prep_matches' => bakery_t('driver.prep_matches'),
    'prep_no_matches' => bakery_t('driver.prep_no_matches'),
    'prep_no_suggestions' => bakery_t('driver.prep_no_suggestions'),
    'prep_already' => bakery_t('driver.prep_already'),
    'prep_on_other' => bakery_t('driver.prep_on_other'),
    'prep_pieces' => bakery_t('driver.prep_pieces', ['count' => '__COUNT__']),
    'prep_saving' => bakery_t('driver.prep_saving'),
    'prep_stops' => bakery_t('driver.prep_stops', ['count' => '__COUNT__']),
    'prep_move_up' => bakery_t('driver.prep_move_up'),
    'prep_move_down' => bakery_t('driver.prep_move_down'),
    'prep_remove' => bakery_t('driver.prep_remove'),
    'route_map' => bakery_t('driver.route_map'),
    'map_me' => bakery_t('driver.map_me'),
    'map_follow' => bakery_t('driver.map_follow'),
    'map_following' => bakery_t('driver.map_following'),
    'map_fit' => bakery_t('driver.map_fit'),
    'map_next' => bakery_t('driver.map_next'),
    'map_nearby' => bakery_t('driver.map_nearby'),
    'map_day' => bakery_t('driver.map_day'),
    'map_scope_next' => bakery_t('driver.map_scope_next'),
    'map_scope_nearby' => bakery_t('driver.map_scope_nearby'),
    'map_scope_day' => bakery_t('driver.map_scope_day'),
    'map_zoom_in' => bakery_t('driver.map_zoom_in'),
    'map_zoom_out' => bakery_t('driver.map_zoom_out'),
    'map_horizon' => bakery_t('driver.map_horizon'),
    'map_horizon_aria' => bakery_t('driver.map_horizon_aria'),
    'map_drive' => bakery_t('driver.map_drive'),
    'map_day_drive' => bakery_t('driver.map_day_drive'),
    'map_duration_distance' => bakery_t('driver.map_duration_distance', ['duration' => '__DURATION__', 'distance' => '__DISTANCE__']),
    'map_window_opens' => bakery_t('driver.map_window_opens'),
    'map_window_due' => bakery_t('driver.map_window_due'),
    'map_window_late' => bakery_t('driver.map_window_late'),
    'map_window_by' => bakery_t('driver.map_window_by'),
    'done_count' => bakery_t('driver.done_count'),
    'map_window_range' => bakery_t('driver.map_window_range', ['from' => '__FROM__', 'to' => '__TO__']),
    'map_minutes_short' => bakery_t('driver.map_minutes_short', ['count' => '__COUNT__']),
    'map_hour_minutes_short' => bakery_t('driver.map_hour_minutes_short', ['hours' => '__HOURS__', 'minutes' => '__MINUTES__']),
    'map_expand' => bakery_t('driver.map_expand'),
    'map_collapse' => bakery_t('driver.map_collapse'),
    'map_navigate' => bakery_t('driver.map_navigate'),
    'map_you_are_here' => bakery_t('driver.map_you_are_here'),
    'map_next_distance' => bakery_t('driver.map_next_distance'),
    'map_remaining_after' => bakery_t('driver.map_remaining_after'),
    'map_no_location' => bakery_t('driver.map_no_location'),
    'map_unavailable' => bakery_t('driver.map_unavailable'),
    'map_unmapped' => bakery_t('driver.map_unmapped'),
    'map_pieces' => bakery_t('driver.map_pieces'),
    'map_done' => bakery_t('driver.map_done'),
    'map_need_location' => bakery_t('driver.map_need_location'),
    'miles_short' => bakery_t('driver.miles_short'),
    'feet_short' => bakery_t('driver.feet_short'),
    'next_label' => bakery_t('driver.next_label'),
    'no_address_short' => bakery_t('driver.no_address_short'),
    'route_complete' => bakery_t('driver.route_complete'),
    'status_cancelled' => bakery_t('driver.status_cancelled'),
    'skip_reason_required' => bakery_t('driver.skip_reason_required'),
    'skip_success' => bakery_t('driver.skip_success'),
    'unskip_stop' => bakery_t('driver.unskip_stop'),
    'unskip_success' => bakery_t('driver.unskip_success'),
    'cant_deliver' => bakery_t('exception_desk.cant_deliver'),
    'fail_report' => bakery_t('exception_desk.report'),
    'fail_other_note' => bakery_t('exception_desk.other_needs_note'),
    'fail_success' => bakery_t('exception_desk.reported'),
    'fail_error' => bakery_t('exception_desk.report_error'),
    'view_invoice_photos' => bakery_t('driver.view_invoice_photos'),
    'photo' => bakery_t('driver.photo'),
    'photo_btn' => bakery_t('driver.photo_btn'),
    'add_store_photo' => bakery_t('driver.add_store_photo'),
    'take_photo_now' => bakery_t('driver.take_photo_now'),
    'call' => bakery_t('driver.call'),
    'status_delivered' => bakery_t('driver.status_delivered'),
    'status_office_flag' => bakery_t('driver.status_office_flag'),
    'empty_order_call_office' => bakery_t('driver.empty_order_call_office'),
    'empty_order_enter_qty' => bakery_t('driver.empty_order_enter_qty'),
    'empty_order_flagged' => bakery_t('driver.empty_order_flagged'),
    'special_order_heading' => bakery_t('driver.special_order_heading'),
    'special_order_ordered' => bakery_t('driver.special_order_ordered'),
    'special_order_qty' => bakery_t('driver.special_order_qty'),
    'status_pending' => bakery_t('driver.status_pending'),
    'status_in_transit' => bakery_t('driver.status_in_transit'),
    'status_failed' => bakery_t('driver.status_failed'),
    'order_details_error' => bakery_t('driver.order_details_error'),
    'order_details_load_error' => bakery_t('driver.order_details_load_error'),
    'no_products_found' => bakery_t('driver.no_products_found'),
    'unknown_product' => bakery_t('driver.unknown_product'),
    'other' => bakery_t('driver.other'),
    'standard' => bakery_t('driver.standard'),
    'quantity' => bakery_t('driver.quantity'),
    'photo_view' => bakery_t('driver.view_photo'),
    'photo_retake' => bakery_t('driver.retake'),
    'photo_remove' => bakery_t('driver.remove'),
    'departure_photo_added' => bakery_t('driver.departure_photo_added'),
    'add_departure_photo' => bakery_t('driver.add_departure_photo'),
    'no_photos' => bakery_t('driver.no_photos'),
    'session_expired' => bakery_t('driver.session_expired'),
    'photo_service_invalid' => bakery_t('driver.photo_service_invalid'),
    'request_failed' => bakery_t('driver.request_failed'),
    'camera_api_unavailable' => bakery_t('driver.camera_api_unavailable'),
    'starting_camera' => bakery_t('driver.starting_camera'),
    'could_not_open_camera' => bakery_t('driver.could_not_open_camera'),
    'camera_permission_denied' => bakery_t('driver.camera_permission_denied'),
    'camera_https' => bakery_t('driver.camera_https'),
    'camera_ready_replacement' => bakery_t('driver.camera_ready_replacement'),
    'camera_ready' => bakery_t('driver.camera_ready'),
    'native_camera_hint' => bakery_t('driver.native_camera_hint'),
    'take_photo' => bakery_t('driver.take_photo'),
    'camera_not_ready' => bakery_t('driver.camera_not_ready'),
    'capture_failed' => bakery_t('driver.capture_failed'),
    'photo_captured_saving' => bakery_t('driver.photo_captured_saving'),
    'could_not_load_photos' => bakery_t('driver.could_not_load_photos'),
    'photo_removed' => bakery_t('driver.photo_removed'),
    'confirm_remove_photo' => bakery_t('driver.confirm_remove_photo'),
    'confirm_retake_photo' => bakery_t('driver.confirm_retake_photo'),
    'could_not_start_retake' => bakery_t('driver.could_not_start_retake'),
    'take_or_choose_photo' => bakery_t('driver.take_or_choose_photo'),
    'uploading_photo' => bakery_t('driver.uploading_photo'),
    'photo_upload_failed' => bakery_t('driver.photo_upload_failed'),
    'upload_failed' => bakery_t('driver.upload_failed'),
    'departure_saved' => bakery_t('driver.departure_saved'),
    'photo_saved_loading' => bakery_t('driver.photo_saved_loading'),
    'ordered_recording' => bakery_t('driver.ordered_recording'),
    'credits_suffix' => bakery_t('driver.credits_suffix'),
    'delivering_more' => bakery_t('driver.delivering_more'),
    'delivering_fewer' => bakery_t('driver.delivering_fewer'),
    'product_counts_prompt' => bakery_t('driver.product_counts_prompt'),
    'product_count_ordered' => bakery_t('driver.product_count_ordered'),
    'product_count_label' => bakery_t('driver.product_count_label'),
    'well_over_ordered' => bakery_t('driver.well_over_ordered'),
    'product_counts_mismatch' => bakery_t('driver.product_counts_mismatch'),
    'enter_cod_details' => bakery_t('driver.enter_cod_details'),
    'enter_delivery_details' => bakery_t('driver.enter_delivery_details'),
    'billable_pieces' => bakery_t('driver.billable_pieces'),
    'average_per_piece' => bakery_t('driver.average_per_piece'),
    'enter_price_below' => bakery_t('driver.enter_price_below'),
    'no_priced_items' => bakery_t('driver.no_priced_items'),
    'collect_cash_cod' => bakery_t('driver.collect_cash_cod'),
    'signature_receipt' => bakery_t('driver.signature_receipt'),
    'credits_exceed_pieces' => bakery_t('driver.credits_exceed_pieces'),
    'credits_return_stock' => bakery_t('driver.credits_return_stock'),
    'credits_allocation_rule' => bakery_t('driver.credits_allocation_rule'),
    'credits_allocation_preview' => bakery_t('driver.credits_allocation_preview'),
    'still_loading_pricing' => bakery_t('driver.still_loading_pricing'),
    'no_address_invoice' => bakery_t('driver.no_address_invoice'),
    'driver_entered_price' => bakery_t('driver.driver_entered_price'),
    'adjusted_from_ordered' => bakery_t('driver.adjusted_from_ordered'),
    'order_pricing_basis' => bakery_t('driver.order_pricing_basis'),
    'amount' => bakery_t('driver.amount'),
    'product' => bakery_t('driver.product'),
    'no_item_pricing' => bakery_t('driver.no_item_pricing'),
    'could_not_load_total' => bakery_t('driver.could_not_load_total'),
    'saving_delivery' => bakery_t('driver.saving_delivery'),
    'could_not_complete_delivery' => bakery_t('driver.could_not_complete_delivery'),
    'delivery_confirmed' => bakery_t('driver.delivery_confirmed'),
    'delivery_saved' => bakery_t('driver.delivery_saved'),
    'choose_image' => bakery_t('driver.choose_image'),
    'photo_selected_saving' => bakery_t('driver.photo_selected_saving'),
    'preparing_photo' => bakery_t('driver.preparing_photo'),
    'loading_photos' => bakery_t('driver.loading_photos'),
    'photo' => bakery_t('driver.photo'),
    'view' => bakery_t('driver.view'),
    'retake' => bakery_t('driver.retake'),
    'remove' => bakery_t('driver.remove'),
    'could_not_remove_photo' => bakery_t('driver.could_not_remove_photo'),
    'previous_photo_removed' => bakery_t('driver.previous_photo_removed'),
    'whole_numbers_required' => bakery_t('driver.whole_numbers_required'),
    'cash_required' => bakery_t('driver.cash_required'),
    'prepaid_square' => bakery_t('driver.prepaid_square'),
    'cod_override_needed' => bakery_t('driver.cod_override_needed'),
    'price_required' => bakery_t('driver.price_required'),
    'cancel' => bakery_t('common.cancel'),
    'cancel_photo' => bakery_t('driver.cancel_photo'),
    'review_saved_photos' => bakery_t('driver.review_saved_photos'),
    'variance_more_confirm' => bakery_t('driver.variance_more_confirm', ['count' => ':count', 'ordered' => ':ordered']),
    'variance_fewer_confirm' => bakery_t('driver.variance_fewer_confirm', ['count' => ':count', 'ordered' => ':ordered']),
    'order_pricing' => bakery_t('driver.order_pricing'),
    'nothing_saved_retry' => bakery_t('driver.nothing_saved_retry'),
    'loading' => bakery_t('common.loading'),
    'saving' => bakery_t('common.saving'),
    'skip_error' => bakery_t('driver.skip_error'),
    'this_stop' => bakery_t('driver.this_stop'),
    'confirm_restore' => bakery_t('driver.confirm_restore'),
    'unskip_error' => bakery_t('driver.unskip_error'),
    'no_zone' => bakery_t('driver.no_zone'),
    'delivery_saved_next' => bakery_t('driver.delivery_saved_next'),
    'view_photo' => bakery_t('driver.view_photo'),
    'past_stops' => bakery_t('driver.past_stops'),
    'total_prefix' => bakery_t('driver.total_prefix'),
    'cash_collected_prefix' => bakery_t('driver.cash_collected_prefix'),
    'delivered' => bakery_t('driver.delivered'),
    'saving_photo' => bakery_t('driver.saving_photo'),
    'no_photo' => bakery_t('driver.no_photo'),
    'no_photo_reason_camera_broken' => bakery_t('driver.no_photo_reason_camera_broken'),
    'no_photo_reason_customer_declined' => bakery_t('driver.no_photo_reason_customer_declined'),
    'no_photo_reason_no_safe_spot' => bakery_t('driver.no_photo_reason_no_safe_spot'),
    'totals_as_ordered' => bakery_t('driver.totals_as_ordered', ['count' => ':count']),
    'totals_delivering' => bakery_t('driver.totals_delivering', ['count' => ':count']),
    'im_here' => bakery_t('driver.im_here'),
    'problem' => bakery_t('driver.problem'),
    'view_now' => bakery_t('driver.view_now'),
    'view_route' => bakery_t('driver.view_route'),
    'make_next' => bakery_t('driver.make_next'),
    'move_up' => bakery_t('driver.move_up'),
    'move_down' => bakery_t('driver.move_down'),
    'stop_problem_aria' => bakery_t('driver.stop_problem_aria'),
    'lines_more' => bakery_t('driver.lines_more', ['count' => ':count']),
    'done_row' => bakery_t('driver.done_row', ['count' => ':count']),
    'reorder' => bakery_t('driver.reorder'),
    'suggest_order' => bakery_t('driver.suggest_order'),
    'suggest_preview' => bakery_t('driver.suggest_preview', ['moves' => ':moves', 'minutes' => ':minutes']),
    'suggest_no_gain' => bakery_t('driver.suggest_no_gain'),
    'suggest_window_flag' => bakery_t('driver.suggest_window_flag', ['names' => ':names']),
    'suggest_accept' => bakery_t('driver.suggest_accept'),
    'suggest_dismiss' => bakery_t('driver.suggest_dismiss'),
    'suggest_failed' => bakery_t('driver.suggest_failed'),
    'load_van' => bakery_t('driver.load_van'),
    'load_van_done' => bakery_t('driver.load_van_done'),
    'not_on_route_reason' => bakery_t('driver.problem_not_on_route_reason'),
    'route_updated' => bakery_t('driver.route_updated'),
    'data_saver_on' => bakery_t('driver.data_saver_on'),
    'data_saver_off' => bakery_t('driver.data_saver_off'),
    'maps_app_google' => bakery_t('driver.maps_app_google'),
    'maps_app_apple' => bakery_t('driver.maps_app_apple'),
    'second_photo' => bakery_t('driver.second_photo'),
    'second_photo_title' => bakery_t('driver.second_photo_title'),
    'second_photo_hint' => bakery_t('driver.second_photo_hint'),
    'second_photo_saving' => bakery_t('driver.second_photo_saving'),
    'second_photo_saved' => bakery_t('driver.second_photo_saved'),
    'first_photo_saved' => bakery_t('driver.first_photo_saved'),
    'second_photo_added' => bakery_t('driver.second_photo_added'),
    'second_photo_toast_action' => bakery_t('driver.second_photo_toast_action'),
    'take_second_photo' => bakery_t('driver.take_second_photo'),
    'back' => bakery_t('driver.back'),
    'use_phone_camera' => bakery_t('driver.use_phone_camera'),
    'use_in_app_camera' => bakery_t('driver.use_in_app_camera'),
], JSON_UNESCAPED_UNICODE); ?>;
window.__DRIVER_ROUTE_BOOT__ = <?php echo json_encode([
    'nextDayUrl' => $routeForwardDate !== '' ? bakery_driver_route_day_url((int)$selectedDriverId, (string)$routeForwardDate) : '',
    'previousDayUrl' => bakery_driver_route_day_url((int)$selectedDriverId, (string)$previousDate),
    'isToday' => $selectedDate === $todayDate,
    'routeDate' => $selectedDate,
    'locale' => function_exists('bakery_locale') ? bakery_locale() : 'en',
    'prepMode' => !empty($routePrepMode),
    'snapshotHash' => ($selectedDriverId > 0 && !empty($driver) && $totalStops > 0)
        ? bakery_driver_route_snapshot($db, (int)$selectedDriverId, $selectedDate)['hash']
        : '',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
<?php if (!empty($mapsReady) && $selectedDriverId > 0 && $driver && empty($showSelector) && $totalStops > 0): ?>
<script>
window.bakeryInitDriverRouteMap = window.bakeryInitDriverRouteMap || function () {
    window.__driverMapsApiReady = true;
    if (window.DriverRouteMap && typeof window.DriverRouteMap.init === 'function') {
        window.DriverRouteMap.init();
    }
};
(function () {
    var src = <?php echo json_encode(GOOGLE_MAPS_JS_API_URL . '?key=' . rawurlencode((string)GOOGLE_MAPS_API_KEY) . '&callback=bakeryInitDriverRouteMap', JSON_UNESCAPED_SLASHES); ?>;
    var loadButton = document.getElementById('routeMapLoad');
    var injected = false;
    function loadMap() {
        if (injected) return;
        injected = true;
        if (loadButton) loadButton.hidden = true;
        document.documentElement.classList.remove('driver-map-deferred');
        var tag = document.createElement('script');
        tag.async = true;
        tag.src = src;
        document.head.appendChild(tag);
    }
    window.bakeryLoadDriverMap = loadMap;
    if (window.DriverDataSaver && window.DriverDataSaver.active) {
        document.documentElement.classList.add('driver-map-deferred');
        if (loadButton) {
            loadButton.hidden = false;
            loadButton.addEventListener('click', loadMap);
        }
    } else {
        loadMap();
    }
})();
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
