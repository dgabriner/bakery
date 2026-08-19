<?php
/**
 * Driver photo workflow contract checks.
 *
 * These protect the mobile/iPhone recovery paths that are easy to break with
 * markup or responsive-CSS changes, without requiring a camera in CI.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
$failures = 0;

function driver_photo_assert(string $label, bool $condition): void
{
    global $failures;
    if (!$condition) {
        echo "FAIL  {$label}\n";
        $failures++;
        return;
    }
    echo "PASS  {$label}\n";
}

function driver_photo_section(string $source, string $start, string $end): string
{
    $startAt = strpos($source, $start);
    if ($startAt === false) {
        return '';
    }
    $endAt = strpos($source, $end, $startAt + strlen($start));
    if ($endAt === false) {
        return substr($source, $startAt);
    }
    return substr($source, $startAt, $endAt - $startAt);
}

$page = (string)file_get_contents($root . '/driver.php');
$script = (string)file_get_contents($root . '/includes/driver_delivery.js');
$styles = (string)file_get_contents($root . '/css/driver.css');
$header = (string)file_get_contents($root . '/includes/header.php');
$handler = (string)file_get_contents($root . '/includes/photo_handler.php');
$ping = (string)file_get_contents($root . '/driver_session_ping.php');
$csrfScript = (string)file_get_contents($root . '/includes/csrf.js');
$config = (string)file_get_contents($root . '/includes/config.php');
$english = require $root . '/lang/en.php';
$spanish = require $root . '/lang/es.php';

driver_photo_assert(
    'phone camera input requests the rear camera',
    strpos($page, 'id="deliveryFileInput" accept="image/*" capture="environment"') !== false
);
driver_photo_assert(
    'photo library remains a separate recovery choice',
    strpos($page, 'id="deliveryGalleryInput" accept="image/*"') !== false
        && strpos($page, 'id="deliveryGalleryPickerBtn"') !== false
);
driver_photo_assert(
    'camera screen has a visible mobile status region',
    strpos($page, 'id="deliveryCameraStatus" aria-live="polite"') !== false
);
driver_photo_assert(
    'safe-area CSS is enabled by the viewport meta tag',
    strpos($header, 'viewport-fit=cover') !== false
);
driver_photo_assert(
    'compact layouts choose the native phone camera flow',
    strpos($script, 'function usesCompactCapture()') !== false
        && strpos($script, "window.matchMedia('(max-width: 768px), (pointer: coarse)')") !== false
        && strpos($script, 'function useNativeCamera()') !== false
);
driver_photo_assert(
    'the first stop tap opens the native camera picker synchronously',
    strpos($script, 'function openNativeCameraPicker()') !== false
        && strpos($script, 'autoOpenCamera: true') !== false
        && strpos($script, "opts.autoOpenCamera && startStep === 'photo'") !== false
        && strpos($script, 'input.click();') !== false
        && strpos($page, 'openStopDeliveryFromEl(stop, { autoOpenCamera: true })') !== false
);
driver_photo_assert(
    'driver session refresh returns and applies the current CSRF token',
    strpos($ping, "'csrf_token' => bakery_csrf_token()") !== false
        && strpos($csrfScript, 'window.bakerySetCsrfToken = setToken;') !== false
        && strpos($script, 'async function refreshRouteSession()') !== false
        && strpos($script, 'applyCsrfToken(data.csrf_token);') !== false
);
driver_photo_assert(
    'photo upload and delivery confirmation refresh CSRF immediately before saving',
    substr_count($script, 'await refreshRouteSession();') >= 3
);
driver_photo_assert(
    'returning from the native camera immediately refreshes the route session',
    strpos($script, "window.addEventListener('focus', keepRouteSessionAlive);") !== false
        && strpos($script, "window.addEventListener('pageshow', keepRouteSessionAlive);") !== false
);
driver_photo_assert(
    'periodic session rotation preserves concurrent camera-return requests',
    strpos($config, 'session_regenerate_id(false);') !== false
        && strpos($config, 'sibling request using the prior cookie') !== false
);
driver_photo_assert(
    'PHP session storage and its cookie survive the app session window',
    strpos($config, "ini_set('session.gc_maxlifetime', (string)\$sessionLifetime);") !== false
        && strpos($config, "ini_set('session.cookie_lifetime', (string)\$sessionLifetime);") !== false
        && strpos($config, "'lifetime' => \$sessionLifetime") !== false
);
driver_photo_assert(
    'large mobile photos are normalized to a bounded JPEG',
    strpos($script, 'var maxDimension = 1920;') !== false
        && strpos($script, "canvas.toBlob") !== false
        && strpos($script, "'image/jpeg'") !== false
);
driver_photo_assert(
    'mobile images with a missing browser MIME type still reach server validation',
    strpos($script, 'function looksLikeImageFile(file)') !== false
        && strpos($script, 'jpe?g|png|webp|gif|heic|heif') !== false
);

$uploadSection = driver_photo_section($script, 'async function uploadBlob', 'function getEffectivePricePerPiece');
driver_photo_assert(
    'photo upload is not blocked by a GPS request',
    $uploadSection !== '' && strpos($uploadSection, 'await getCoords') === false
);
driver_photo_assert(
    'photo uploads keep same-origin authentication',
    strpos($uploadSection, "credentials: 'same-origin'") !== false
);

$retakeSection = driver_photo_section($script, 'function retakePhotoFlow', 'function maybeEnableGpsTracking');
driver_photo_assert(
    'retake keeps the old proof until replacement upload succeeds',
    $retakeSection !== '' && strpos($retakeSection, 'deletePhoto(') === false
        && strpos($uploadSection, 'await deletePhoto(replacedPhotoId') !== false
);
driver_photo_assert(
    'modal cannot close during prepare, upload, or confirmation',
    strpos($script, 'if (state.uploading || state.preparing || state.submitting) return;') !== false
);
driver_photo_assert(
    'mobile modal supports stable and dynamic viewport heights',
    strpos($styles, 'height: 100svh;') !== false
        && strpos($styles, 'height: 100dvh;') !== false
);
driver_photo_assert(
    'narrow delivery actions use a non-overflowing grid',
    strpos($styles, '#deliveryPhotoModal .delivery-wizard-actions') !== false
        && strpos($styles, 'grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);') !== false
);
driver_photo_assert(
    'iPhone HEIC and HEIF uploads remain server-compatible',
    strpos($handler, "'image/heic', 'image/heif'") !== false
);

foreach (['driver.choose_photo', 'driver.camera_off', 'driver.native_camera_hint', 'driver.saving_photo', 'driver.preparing_photo'] as $key) {
    driver_photo_assert(
        "photo workflow translation exists in English and Spanish: {$key}",
        isset($english[$key], $spanish[$key])
            && trim((string)$english[$key]) !== ''
            && trim((string)$spanish[$key]) !== ''
    );
}

$deliveryHandler = (string)file_get_contents($root . '/complete_delivery.php');
$assignments = (string)file_get_contents($root . '/includes/driver_assignments.php');
driver_photo_assert(
    'My Route has compact adjust controls',
    strpos($page, 'id="routeAdjustToggle"') !== false
        && strpos($page, 'class="stop-go-next"') !== false
        && strpos($page, 'id="routeAdjustDock"') !== false
        && strpos($page, 'class="change-next-btn"') !== false
        && strpos($page, 'route-btn--adjust change-next-btn') !== false
);
driver_photo_assert(
    'adjust mode intercepts stop taps and skips the camera',
    strpos($page, 'tapStopForAdjust(stop)') !== false
        && strpos($page, 'function openStopDeliveryFromEl') !== false
        && strpos($page, "if (document.body.classList.contains('route-adjust-open'))") !== false
);
driver_photo_assert(
    'route reorder writes remaining dated stops only',
    strpos($assignments, 'function bakery_driver_reorder_remaining_stops') !== false
        && strpos($deliveryHandler, "case 'reorder_route':") !== false
);
foreach (['driver.adjust_route', 'driver.go_next', 'driver.adjust_hint', 'driver.save_route_order', 'driver.change_next'] as $key) {
    driver_photo_assert(
        "route adjust translation exists in English and Spanish: {$key}",
        isset($english[$key], $spanish[$key])
            && trim((string)$english[$key]) !== ''
            && trim((string)$spanish[$key]) !== ''
    );
}

driver_photo_assert(
    'My Route has a compact remaining-stop map',
    strpos($page, 'id="driverRouteMap"') !== false
        && strpos($page, 'data-map-mode="view"') !== false
        && strpos($page, 'id="routeMapCanvas"') !== false
        && strpos($page, 'includes/driver_route_map.js') !== false
        && strpos($page, 'bakeryInitDriverRouteMap') !== false
        && strpos($page, 'c.latitude, c.longitude') !== false
);
$routeMapScript = (string)file_get_contents($root . '/includes/driver_route_map.js');
driver_photo_assert(
    'route map follows dated order and does not optimize waypoints',
    strpos($routeMapScript, 'optimizeWaypoints: false') !== false
        && strpos($routeMapScript, 'window.DriverRouteMap') !== false
        && strpos($routeMapScript, 'reorder_route') === false
        && strpos($routeMapScript, 'watchPosition') !== false
);
driver_photo_assert(
    'route map gives tiny screens next, nearby, and full-day map scopes with explicit zoom',
    strpos($page, 'id="routeMapNext"') !== false
        && strpos($page, 'id="routeMapNearby"') !== false
        && strpos($page, 'id="routeMapDay"') !== false
        && strpos($page, 'id="routeMapZoomOut"') !== false
        && strpos($page, 'id="routeMapZoomIn"') !== false
        && strpos($routeMapScript, 'function visibleStops(stops)') !== false
        && strpos($routeMapScript, "remaining.slice(0, 3)") !== false
        && strpos($routeMapScript, 'function changeZoom(delta)') !== false
);
driver_photo_assert(
    'route map adds driving intelligence, delivery-window context, horizon planning, and remembered scope',
    strpos($page, 'id="routeMapDrive"') !== false
        && strpos($page, 'id="routeMapWindow"') !== false
        && strpos($page, 'id="routeMapHorizon"') !== false
        && strpos($page, 'data-deliver-after=') !== false
        && strpos($page, 'data-deliver-by=') !== false
        && strpos($routeMapScript, 'function applyDirectionsMetrics') !== false
        && strpos($routeMapScript, 'function windowStatus') !== false
        && strpos($routeMapScript, 'function renderHorizon') !== false
        && strpos($routeMapScript, 'bakery-route-map-scope:') !== false
);
foreach ([
    'driver.route_map',
    'driver.map_me',
    'driver.map_follow',
    'driver.map_fit',
    'driver.map_next',
    'driver.map_nearby',
    'driver.map_day',
    'driver.map_scope_aria',
    'driver.map_scope_next',
    'driver.map_scope_nearby',
    'driver.map_scope_day',
    'driver.map_zoom_in',
    'driver.map_zoom_out',
    'driver.map_horizon',
    'driver.map_horizon_aria',
    'driver.map_drive',
    'driver.map_day_drive',
    'driver.map_duration_distance',
    'driver.map_window_opens',
    'driver.map_window_due',
    'driver.map_window_late',
    'driver.map_window_by',
    'driver.map_window_range',
    'driver.map_minutes_short',
    'driver.map_hour_minutes_short',
    'driver.map_expand',
    'driver.map_navigate',
    'driver.map_next_distance',
] as $key) {
    driver_photo_assert(
        "route map translation exists in English and Spanish: {$key}",
        isset($english[$key], $spanish[$key])
            && trim((string)$english[$key]) !== ''
            && trim((string)$spanish[$key]) !== ''
    );
}

exit($failures > 0 ? 1 : 0);
