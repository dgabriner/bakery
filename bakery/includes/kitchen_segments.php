<?php
/**
 * Sticky Mix / Bake / Pack segment control for the baker Today workspace.
 * URLs stay baker_mix.php / production.php / pack_list.php; the segment is nav.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * @return 'mix'|'bake'|'pack'|''
 */
function bakery_kitchen_segment_for_page(?string $page = null): string
{
    $page = $page ?? basename((string)($_SERVER['PHP_SELF'] ?? ''), '.php');
    switch ($page) {
        case 'baker_mix':
            return 'mix';
        case 'production':
            return 'bake';
        case 'pack_list':
            return 'pack';
        default:
            return '';
    }
}

function bakery_baker_home_delivery_date(?string $today = null): string
{
    $today = $today ?? date('Y-m-d');
    return date('Y-m-d', strtotime($today . ' +1 day'));
}

function bakery_staff_selected_date(?string $raw, string $fallback): string
{
    $raw = trim((string)$raw);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
        return $fallback;
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    if (!$dt || $dt->format('Y-m-d') !== $raw) {
        return $fallback;
    }
    return $raw;
}

function bakery_baker_nav_workspace_date(?string $requestedDate, ?string $today = null): string
{
    return bakery_staff_selected_date($requestedDate, bakery_baker_home_delivery_date($today));
}

function bakery_baker_delivery_aria(string $deliveryDate): string
{
    if (!function_exists('bakery_production_cadence_bake_date_for_delivery')) {
        require_once __DIR__ . '/production_cadence.php';
    }
    $deliveryLabel = date('D, M j', strtotime($deliveryDate));
    $bakeDate = bakery_production_cadence_bake_date_for_delivery(
        BAKERY_PRODUCTION_CADENCE_DAILY,
        $deliveryDate
    );
    if ($bakeDate === null) {
        return bakery_t('nav.baker_delivery', ['date' => $deliveryLabel]);
    }
    return bakery_t('nav.baker_delivery_aria', [
        'delivery' => $deliveryLabel,
        'bake' => date('D, M j', strtotime($bakeDate)),
    ]);
}

function bakery_driver_nav_route_href(string $baseUrl, ?string $requestedDate, int $driverId, ?string $today = null): string
{
    $today = $today ?? date('Y-m-d');
    $query = ['date' => bakery_staff_selected_date($requestedDate, $today)];
    if ($driverId > 0) {
        $query['driver_id'] = $driverId;
    }
    return $baseUrl . 'driver.php?' . http_build_query($query);
}

function bakery_driver_nav_pack_href(string $baseUrl, ?string $requestedDate, ?string $today = null): string
{
    $today = $today ?? date('Y-m-d');
    $date = bakery_staff_selected_date($requestedDate, $today);
    return $baseUrl . 'pack_list.php?date=' . rawurlencode($date);
}

function bakery_kitchen_segment_date(?string $date = null): string
{
    $raw = $date ?? ($_GET['date'] ?? bakery_baker_home_delivery_date());
    return bakery_staff_selected_date($raw, bakery_baker_home_delivery_date());
}

/**
 * Preserve line filter query when hopping Mix ↔ Bake ↔ Pack.
 */
function bakery_kitchen_segment_query(string $date): string
{
    $params = ['date' => $date];
    foreach (['line', 'product_line_id', 'view', 'driver_id'] as $key) {
        if (isset($_GET[$key]) && (string)$_GET[$key] !== '') {
            $params[$key] = (string)$_GET[$key];
        }
    }
    return http_build_query($params);
}

function bakery_kitchen_segments_html(?string $active = null, ?string $date = null): string
{
    $active = $active ?: bakery_kitchen_segment_for_page();
    if ($active === '') {
        return '';
    }
    $date = bakery_kitchen_segment_date($date);
    $q = bakery_kitchen_segment_query($date);
    $base = defined('BASE_URL') ? BASE_URL : '';
    $segments = [
        'mix' => ['href' => $base . 'baker_mix.php?' . $q, 'label' => bakery_t('kitchen.segment_mix')],
        'bake' => ['href' => $base . 'production.php?' . $q, 'label' => bakery_t('kitchen.segment_bake')],
        'pack' => ['href' => $base . 'pack_list.php?' . $q, 'label' => bakery_t('kitchen.segment_pack')],
    ];
    $html = '<nav class="kitchen-segments" aria-label="' . htmlspecialchars(bakery_t('kitchen.segments_aria'), ENT_QUOTES, 'UTF-8') . '">';
    foreach ($segments as $key => $seg) {
        $isActive = $key === $active;
        $html .= '<a class="kitchen-segments__btn' . ($isActive ? ' is-active' : '') . '" href="'
            . htmlspecialchars($seg['href'], ENT_QUOTES, 'UTF-8') . '"'
            . ($isActive ? ' aria-current="page"' : '') . '>'
            . htmlspecialchars($seg['label'], ENT_QUOTES, 'UTF-8')
            . '</a>';
    }
    $html .= '</nav>';
    return $html;
}

function bakery_kitchen_segments_render(?string $active = null, ?string $date = null): void
{
    echo bakery_kitchen_segments_html($active, $date);
}
