<?php
/**
 * Administrator workspace modes.
 *
 * Full Admin keeps the whole app. Driver Manager and Production Manager
 * keep the screens for that job and send every other staff screen home.
 * The bar shows the daily job. Every other screen that job still opens
 * stays in More. This is a view for the administrator login. It does not
 * change other roles, and it does not remove the ability to switch back.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_admin_workspace_modes(): array
{
    return ['full', 'driver', 'production'];
}

function bakery_admin_workspace_mode_normalize(string $mode): string
{
    $mode = strtolower(trim($mode));
    return in_array($mode, bakery_admin_workspace_modes(), true) ? $mode : 'full';
}

function bakery_admin_workspace_mode_is_admin(): bool
{
    return function_exists('bakery_user_has_role') && bakery_user_has_role(['administrator']);
}

function bakery_admin_workspace_mode(): string
{
    if (!bakery_admin_workspace_mode_is_admin()) {
        return 'full';
    }
    return bakery_admin_workspace_mode_normalize((string)($_SESSION['admin_workspace_mode'] ?? 'full'));
}

function bakery_admin_workspace_mode_home(string $mode): string
{
    $mode = bakery_admin_workspace_mode_normalize($mode);
    if ($mode === 'driver') {
        return 'route_manager.php';
    }
    if ($mode === 'production') {
        return 'standing_orders_manager.php';
    }
    return 'index.php';
}

/**
 * Screens the administrator keeps while a focused mode is on.
 * Companion scripts are the saves and photo calls those screens already make.
 *
 * @return list<string>
 */
function bakery_admin_workspace_mode_pages(string $mode): array
{
    $mode = bakery_admin_workspace_mode_normalize($mode);
    if ($mode === 'full') {
        return [];
    }
    $shared = [
        'logout.php',
        'login_audit_api.php',
        'client_error_api.php',
        'time_clock.php',
        'text_comms.php',
        'text_comms_api.php',
        'text_media.php',
        'survey.php',
        'customers.php',
        'customer_record.php',
        'customer_schedule.php',
    ];
    if ($mode === 'driver') {
        return array_values(array_unique(array_merge($shared, [
            'route_manager.php',
            'route_summary.php',
            'route_health.php',
            'delivery_manager_review.php',
            'route_photo_journey.php',
            'driver_reports.php',
            'route_closeout.php',
            'route_analysis.php',
            'daily_route.php',
            'driver_assignment.php',
            'driver_assignment_api.php',
            'standing_routes.php',
            'drivers.php',
            'driver.php',
            'driver_stops.php',
            'driver_list.php',
            'driver_load.php',
            'driver_photo.php',
            'upload_driver_photo.php',
            'get_driver_orders.php',
            'get_customer_order_details.php',
            'complete_delivery.php',
            'driver_session_ping.php',
            'global_gps_handler.php',
            'map.php',
            'call_headquarters.php',
            'zones.php',
            'customer_routes.php',
        ])));
    }
    return array_values(array_unique(array_merge($shared, [
        'standing_orders_manager.php',
        'standing_orders_manager_api.php',
        'standing_routes.php',
        'daily_orders.php',
        'daily_orders_api.php',
        'special_orders.php',
        'zazie_weekly_sheet.php',
        'product_distribution.php',
        'production.php',
        'production_manager.php',
        'production_center.php',
        'production_center_api.php',
        'product_manager_plan.php',
        'ingredient_requirements.php',
        'baker_mix.php',
        'pack_list.php',
        'inventory.php',
        'driver_load.php',
        'pan_dulce_quantities.php',
        'counter_orders.php',
    ])));
}

function bakery_admin_workspace_mode_allows(string $mode, string $script): bool
{
    $mode = bakery_admin_workspace_mode_normalize($mode);
    if ($mode === 'full') {
        return true;
    }
    $script = strtolower(basename($script));
    return in_array($script, bakery_admin_workspace_mode_pages($mode), true);
}

/**
 * @param list<array<string, mixed>> $sections
 * @return list<array<string, mixed>>
 */
function bakery_admin_workspace_filter_sections(array $sections, string $mode): array
{
    $mode = bakery_admin_workspace_mode_normalize($mode);
    if ($mode === 'full') {
        return $sections;
    }
    $filtered = [];
    foreach ($sections as $section) {
        $groups = [];
        foreach ($section['groups'] ?? [] as $group) {
            $items = [];
            foreach ($group['items'] ?? [] as $item) {
                if (bakery_admin_workspace_item_visible($mode, $item)) {
                    $items[] = $item;
                }
            }
            if ($items === []) {
                continue;
            }
            $group['items'] = $items;
            $groups[] = $group;
        }
        if ($groups === []) {
            continue;
        }
        $section['groups'] = $groups;
        $filtered[] = $section;
    }
    return bakery_admin_workspace_mode_simplify_menu($filtered, $mode);
}

/**
 * Scripts that stay on the bar for a focused mode. They stay openable.
 * They are left out of More so the same link is not listed twice.
 *
 * @return list<string>
 */
function bakery_admin_workspace_mode_focus_scripts(string $mode): array
{
    $mode = bakery_admin_workspace_mode_normalize($mode);
    if ($mode === 'driver') {
        return [
            'route_manager.php',
            'driver_assignment.php',
            'route_health.php',
            'delivery_manager_review.php',
            'driver.php',
        ];
    }
    if ($mode === 'production') {
        return [
            'standing_orders_manager.php',
            'daily_orders.php',
            'production_manager.php',
            'production_center.php',
            'pack_list.php',
        ];
    }
    return [];
}

/**
 * More opens with the next tools for the job, then the rest of the catalog.
 *
 * @return list<string>
 */
function bakery_admin_workspace_mode_more_lead(string $mode): array
{
    $mode = bakery_admin_workspace_mode_normalize($mode);
    if ($mode === 'driver') {
        return [
            'text_comms.php',
            'drivers.php',
            'route_closeout.php',
            'driver_load.php',
        ];
    }
    if ($mode === 'production') {
        return [
            'special_orders.php',
            'counter_orders.php',
            'product_manager_plan.php',
            'ingredient_requirements.php',
            'production.php',
        ];
    }
    return [];
}

function bakery_admin_workspace_item_script(array $item): string
{
    $href = (string)($item['href'] ?? '');
    if (function_exists('bakery_navigation_script_basename')) {
        return bakery_navigation_script_basename($href);
    }
    $path = parse_url($href, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = $href;
    }
    return strtolower(basename($path));
}

/**
 * One More group for a focused mode. Page access is unchanged.
 *
 * @param list<array<string, mixed>> $sections
 * @return list<array<string, mixed>>
 */
function bakery_admin_workspace_mode_simplify_menu(array $sections, string $mode): array
{
    $focus = bakery_admin_workspace_mode_focus_scripts($mode);
    if ($focus === []) {
        return $sections;
    }
    $pool = [];
    $seenHref = [];
    foreach ($sections as $section) {
        foreach ($section['groups'] ?? [] as $group) {
            foreach ($group['items'] ?? [] as $item) {
                $href = (string)($item['href'] ?? '');
                if ($href === '' || isset($seenHref[$href])) {
                    continue;
                }
                $seenHref[$href] = true;
                $pool[] = $item;
            }
        }
    }
    $byScript = [];
    foreach ($pool as $index => $item) {
        $byScript[bakery_admin_workspace_item_script($item)][] = $index;
    }
    $used = [];
    foreach ($focus as $script) {
        foreach ($byScript[$script] ?? [] as $index) {
            $used[$index] = true;
        }
    }
    $focusItems = [];
    foreach ($focus as $script) {
        foreach ($byScript[$script] ?? [] as $index) {
            $focusItems[] = $pool[$index];
        }
    }
    $more = [];
    $take = static function (string $script) use (&$used, &$more, $byScript, $pool): void {
        foreach ($byScript[$script] ?? [] as $index) {
            if (isset($used[$index])) {
                continue;
            }
            $used[$index] = true;
            $more[] = $pool[$index];
        }
    };
    foreach (bakery_admin_workspace_mode_more_lead($mode) as $script) {
        $take($script);
    }
    foreach ($pool as $index => $item) {
        if (isset($used[$index])) {
            continue;
        }
        $used[$index] = true;
        $more[] = $item;
    }
    $text = static function (string $key, string $fallback): string {
        if (!function_exists('bakery_t')) {
            return $fallback;
        }
        $translated = bakery_t($key);
        return $translated === $key ? $fallback : $translated;
    };
    $groups = [];
    if ($focusItems !== []) {
        $groups[] = [
            'key' => 'mode_focus',
            'label' => $text('mode.bar.today', 'Today'),
            'description' => $text('mode.bar.today_desc', 'The daily job.'),
            'items' => $focusItems,
        ];
    }
    if ($more !== []) {
        $groups[] = [
            'key' => 'mode_more',
            'label' => $text('mode.bar.more', 'More'),
            'description' => $text('mode.bar.more_desc', 'Other screens for this job.'),
            'items' => $more,
        ];
    }
    if ($groups === []) {
        return [];
    }
    return [[
        'key' => 'mode_more',
        'label' => $text('mode.bar.more', 'More'),
        'description' => $text('mode.bar.more_desc', 'Other screens for this job.'),
        'groups' => $groups,
    ]];
}

function bakery_admin_workspace_item_visible(string $mode, array $item): bool
{
    $href = (string)($item['href'] ?? '');
    $base = function_exists('bakery_navigation_script_basename')
        ? bakery_navigation_script_basename($href)
        : strtolower(basename((string)(parse_url($href, PHP_URL_PATH) ?: $href)));
    if (!bakery_admin_workspace_mode_allows($mode, $base)) {
        return false;
    }
    if ($base === 'text_comms.php' && bakery_admin_workspace_mode_normalize($mode) !== 'full') {
        $query = [];
        $raw = parse_url($href, PHP_URL_QUERY);
        if (is_string($raw) && $raw !== '') {
            parse_str($raw, $query);
        }
        return (string)($query['view'] ?? '') === 'surveys';
    }
    return true;
}

function bakery_admin_workspace_mode_set(string $mode): void
{
    $mode = bakery_admin_workspace_mode_normalize($mode);
    $_SESSION['admin_workspace_mode'] = $mode;
    if (PHP_SAPI === 'cli') {
        return;
    }
    $path = (defined('BASE_URL') && BASE_URL !== '') ? BASE_URL : '/';
    setcookie('bakery_admin_mode', $mode, [
        'expires' => time() + (180 * 86400),
        'path' => $path,
        'secure' => function_exists('isHTTPS') && isHTTPS(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['bakery_admin_mode'] = $mode;
}

function bakery_admin_workspace_mode_restore(): void
{
    if (!bakery_admin_workspace_mode_is_admin()) {
        return;
    }
    if (isset($_SESSION['admin_workspace_mode'])) {
        $_SESSION['admin_workspace_mode'] = bakery_admin_workspace_mode_normalize((string)$_SESSION['admin_workspace_mode']);
        return;
    }
    $cookie = bakery_admin_workspace_mode_normalize((string)($_COOKIE['bakery_admin_mode'] ?? ''));
    if ($cookie === 'full' && (string)($_COOKIE['bakery_admin_mode'] ?? '') === '') {
        return;
    }
    if (!isset($_COOKIE['bakery_admin_mode']) || (string)$_COOKIE['bakery_admin_mode'] === '') {
        return;
    }
    $_SESSION['admin_workspace_mode'] = $cookie;
}

function bakery_admin_workspace_mode_consume_post(): void
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        return;
    }
    if (!isset($_POST['bakery_admin_mode'])) {
        return;
    }
    if (!bakery_admin_workspace_mode_is_admin()) {
        return;
    }
    if (function_exists('bakery_require_csrf')) {
        bakery_require_csrf();
    }
    $mode = bakery_admin_workspace_mode_normalize((string)$_POST['bakery_admin_mode']);
    bakery_admin_workspace_mode_set($mode);
    $script = strtolower(basename((string)($_SERVER['PHP_SELF'] ?? '')));
    $target = bakery_admin_workspace_mode_home($mode);
    if ($script !== '' && bakery_admin_workspace_mode_allows($mode, $script)) {
        $query = $_GET;
        unset($query['bakery_admin_mode']);
        if ($mode !== 'full' && $script === 'text_comms.php') {
            $query['view'] = 'surveys';
        }
        $target = $script . ($query ? '?' . http_build_query($query) : '');
    }
    $base = defined('BASE_URL') ? BASE_URL : '/';
    header('Location: ' . $base . $target);
    exit;
}

function bakery_admin_workspace_mode_enforce(string $script): void
{
    if (!bakery_admin_workspace_mode_is_admin()) {
        return;
    }
    $mode = bakery_admin_workspace_mode();
    if ($mode === 'full') {
        return;
    }
    $script = strtolower(basename($script));
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($script === 'text_comms.php' && in_array($method, ['GET', 'HEAD'], true) && (string)($_GET['view'] ?? '') !== 'surveys') {
        $base = defined('BASE_URL') ? BASE_URL : '/';
        header('Location: ' . $base . 'text_comms.php?view=surveys');
        exit;
    }
    if (bakery_admin_workspace_mode_allows($mode, $script)) {
        return;
    }
    $wantsJson = (function_exists('is_ajax_request') && is_ajax_request())
        || (function_exists('bakery_wants_json') && bakery_wants_json())
        || str_ends_with($script, '_api.php');
    if ($wantsJson) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Switch workspace mode to open this screen']);
        exit;
    }
    $base = defined('BASE_URL') ? BASE_URL : '/';
    header('Location: ' . $base . bakery_admin_workspace_mode_home($mode));
    exit;
}

/**
 * Bar links for a focused mode. Full Admin keeps the existing shortcuts.
 *
 * @return list<array{href:string,label:string,short:string,page:string}>
 */
function bakery_admin_workspace_mode_switch_html(): string
{
    if (!bakery_admin_workspace_mode_is_admin() || !function_exists('bakery_csrf_field') || !function_exists('bakery_t')) {
        return '';
    }
    $current = bakery_admin_workspace_mode();
    $html = '<form class="bakery-nav__mode" method="post" action="" role="group" aria-label="'
        . htmlspecialchars(bakery_t('mode.switch_aria'), ENT_QUOTES, 'UTF-8') . '">';
    $html .= bakery_csrf_field();
    foreach (bakery_admin_workspace_modes() as $mode) {
        $active = $mode === $current;
        $html .= '<button class="bakery-nav__mode-btn' . ($active ? ' bakery-nav__mode-btn--active' : '')
            . '" type="submit" name="bakery_admin_mode" value="' . htmlspecialchars($mode, ENT_QUOTES, 'UTF-8')
            . '" title="' . htmlspecialchars(bakery_t('mode.' . $mode), ENT_QUOTES, 'UTF-8')
            . '" aria-pressed="' . ($active ? 'true' : 'false') . '">';
        $html .= '<span class="bakery-nav__label-full">' . htmlspecialchars(bakery_t('mode.' . $mode), ENT_QUOTES, 'UTF-8') . '</span>';
        $html .= '<span class="bakery-nav__label-short">' . htmlspecialchars(bakery_t('mode.' . $mode . '_short'), ENT_QUOTES, 'UTF-8') . '</span>';
        $html .= '</button>';
    }
    $html .= '</form>';
    return $html;
}

function bakery_admin_workspace_mode_shortcuts(string $mode): array
{
    $mode = bakery_admin_workspace_mode_normalize($mode);
    $label = static function (string $key, string $fallback): string {
        if (!function_exists('bakery_t')) {
            return $fallback;
        }
        $text = bakery_t($key);
        return $text === $key ? $fallback : $text;
    };
    $short = static function (string $key, string $fallback) use ($label): string {
        return $label('mode.bar.' . $key, $fallback);
    };
    if ($mode === 'driver') {
        return [
            ['href' => 'route_manager.php', 'label' => $label('nav.item.route_manager', 'Route Manager'), 'short' => $short('board', 'Board'), 'page' => 'route_manager'],
            ['href' => 'driver_assignment.php', 'label' => $label('nav.item.driver_assignment', 'Driver Assignment'), 'short' => $short('assign', 'Assign'), 'page' => 'driver_assignment'],
            ['href' => 'route_health.php', 'label' => $label('nav.item.route_health', 'Route health'), 'short' => $short('health', 'Health'), 'page' => 'route_health'],
            ['href' => 'delivery_manager_review.php', 'label' => $label('nav.item.delivery_manager_review', 'Delivery review'), 'short' => $short('review', 'Review'), 'page' => 'delivery_manager_review'],
            ['href' => 'driver.php?change_driver=1', 'label' => $label('nav.my_route', 'My Route'), 'short' => $label('nav.my_route_short', 'Route'), 'page' => 'driver'],
        ];
    }
    if ($mode === 'production') {
        return [
            ['href' => 'standing_orders_manager.php', 'label' => $label('nav.item.standing_orders_manager', 'Standing Orders'), 'short' => $short('standing', 'Standing'), 'page' => 'standing_orders_manager'],
            ['href' => 'daily_orders.php', 'label' => $label('nav.item.daily_orders', 'Daily Orders'), 'short' => $short('orders', 'Orders'), 'page' => 'daily_orders'],
            ['href' => 'production_manager.php', 'label' => $label('nav.item.production_manager', 'Production Manager'), 'short' => $short('dough', 'Dough'), 'page' => 'production_manager'],
            ['href' => 'production_center.php', 'label' => $label('nav.item.production_center', 'Production Center'), 'short' => $short('bake', 'Bake'), 'page' => 'production_center'],
            ['href' => 'pack_list.php', 'label' => $label('nav.item.pack_list', 'Pack List'), 'short' => $short('pack', 'Pack'), 'page' => 'pack_list'],
        ];
    }
    return [];
}
