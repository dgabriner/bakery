<?php
/**
 * Login History insight helpers — filters, time series, usage, and workflows.
 * Administrator-only presentation lives in login_history.php; this file stays
 * free of HTML so the aggregations can be tested on their own.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_login_history_live_seconds(): int
{
    return 600;
}

function bakery_login_history_views(): array
{
    return ['overview', 'time', 'usage', 'live', 'records'];
}

function bakery_login_history_ranges(): array
{
    return ['today', '7d', '14d', '30d', 'all'];
}

function bakery_login_history_duration($seconds): string
{
    $seconds = max(0, (int)$seconds);
    if (function_exists('bakery_t')) {
        if ($seconds < 60) {
            return bakery_t('login_history.duration_sec', ['n' => $seconds]);
        }
        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return bakery_t('login_history.duration_min', ['n' => $minutes]);
        }
        return bakery_t('login_history.duration_hr', [
            'h' => intdiv($minutes, 60),
            'm' => $minutes % 60,
        ]);
    }
    if ($seconds < 60) {
        return $seconds . ' sec';
    }
    $minutes = intdiv($seconds, 60);
    if ($minutes < 60) {
        return $minutes . ' min';
    }
    return intdiv($minutes, 60) . ' hr ' . ($minutes % 60) . ' min';
}

function bakery_login_history_when(?string $sqlTime, string $withTime = 'full'): string
{
    if ($sqlTime === null || $sqlTime === '' || $sqlTime === '0000-00-00 00:00:00') {
        return '';
    }
    try {
        $dt = new DateTimeImmutable($sqlTime);
    } catch (Throwable $e) {
        return $sqlTime;
    }
    $date = function_exists('bakery_localized_date_label')
        ? bakery_localized_date_label($dt, true)
        : $dt->format('M j, Y');
    if ($withTime === 'date') {
        return $date;
    }
    if ($withTime === 'time') {
        return $dt->format('g:i A');
    }
    return $date . ' · ' . $dt->format('g:i:s A');
}

function bakery_login_history_ago(?string $sqlTime, ?int $now = null): string
{
    if ($sqlTime === null || $sqlTime === '') {
        return '';
    }
    $timestamp = strtotime($sqlTime);
    if ($timestamp === false) {
        return bakery_login_history_when($sqlTime, 'date');
    }
    $now = $now ?? time();
    $diff = max(0, $now - $timestamp);
    if ($diff < 45) {
        return bakery_login_history_translate('login_history.ago_just_now', 'Just now');
    }
    if ($diff < 3600) {
        $minutes = max(1, (int)round($diff / 60));
        return function_exists('bakery_t')
            ? bakery_t('login_history.ago_min', ['n' => $minutes])
            : $minutes . ' min ago';
    }
    if ($diff < 86400) {
        $hours = max(1, (int)round($diff / 3600));
        return function_exists('bakery_t')
            ? bakery_t('login_history.ago_hr', ['n' => $hours])
            : $hours . ' hr ago';
    }
    if ($diff < 172800) {
        return bakery_login_history_translate('login_history.ago_yesterday', 'Yesterday');
    }
    if ($diff < 86400 * 7) {
        $days = (int)floor($diff / 86400);
        return function_exists('bakery_t')
            ? bakery_t('login_history.ago_days', ['n' => $days])
            : $days . ' days ago';
    }
    return bakery_login_history_when($sqlTime, 'date');
}

function bakery_login_history_noise_pages(): array
{
    return [
        'login_audit_api',
        'client_error_api',
        'driver_session_ping',
        'get_driver_orders',
        'get_customer_order_details',
        'upload_driver_photo',
        'customer_portal_api',
        'global_gps_handler',
    ];
}

function bakery_login_history_infer_range(string $from, string $until, string $today): string
{
    if ($from === '' && $until === '') {
        return 'all';
    }
    $end = $until !== '' ? $until : $today;
    if ($from === $today && $end === $today) {
        return 'today';
    }
    if ($end !== $today) {
        return '';
    }
    foreach (['7d' => 6, '14d' => 13, '30d' => 29] as $key => $back) {
        $expected = (new DateTimeImmutable($today))->modify('-' . $back . ' days')->format('Y-m-d');
        if ($from === $expected) {
            return $key;
        }
    }
    return '';
}

function bakery_login_history_previous_window(array $filters): ?array
{
    if (($filters['from'] ?? '') === '' || ($filters['until'] ?? '') === '') {
        return null;
    }
    try {
        $start = new DateTimeImmutable($filters['from']);
        $end = new DateTimeImmutable($filters['until']);
    } catch (Throwable $e) {
        return null;
    }
    if ($end < $start) {
        return null;
    }
    $days = (int)$start->diff($end)->format('%a') + 1;
    $prevEnd = $start->modify('-1 day');
    $prevStart = $prevEnd->modify('-' . ($days - 1) . ' days');
    return [
        'from' => $prevStart->format('Y-m-d'),
        'until' => $prevEnd->format('Y-m-d'),
        'days' => $days,
    ];
}

function bakery_login_history_delta($current, $previous): array
{
    $current = (int)$current;
    $previous = (int)$previous;
    if ($previous <= 0) {
        return [
            'previous' => $previous,
            'pct' => $current > 0 ? 100 : 0,
            'signed' => $current > 0 ? 100 : 0,
            'direction' => $current > 0 ? 'up' : 'flat',
        ];
    }
    $signed = (int)round(100 * ($current - $previous) / $previous);
    $direction = 'flat';
    if ($signed > 2) {
        $direction = 'up';
    } elseif ($signed < -2) {
        $direction = 'down';
    }
    return [
        'previous' => $previous,
        'pct' => abs($signed),
        'signed' => $signed,
        'direction' => $direction,
    ];
}

function bakery_login_history_peak_hour(array $hourly): ?int
{
    $max = 0;
    $peak = null;
    foreach ($hourly as $hour => $count) {
        $count = (int)$count;
        if ($count > $max) {
            $max = $count;
            $peak = (int)$hour;
        }
    }
    return $max > 0 ? $peak : null;
}

function bakery_login_history_format_hour(?int $hour): string
{
    if ($hour === null || $hour < 0 || $hour > 23) {
        return '';
    }
    $suffix = $hour >= 12 ? 'PM' : 'AM';
    $display = $hour % 12;
    if ($display === 0) {
        $display = 12;
    }
    return $display . ' ' . $suffix;
}

function bakery_login_history_credential_label(?string $method): string
{
    $method = (string)$method;
    if ($method === 'staff_4_digit_code') {
        return bakery_login_history_translate('login_history.cred_staff', 'Staff code');
    }
    if ($method === 'customer_4_digit_code') {
        return bakery_login_history_translate('login_history.cred_customer', 'Customer code');
    }
    return $method;
}

function bakery_login_history_known_workflows(): array
{
    return [
        ['keys' => ['production', 'pack_list'], 'label_key' => 'login_history.flow.bake_pack', 'label' => 'Bake then pack'],
        ['keys' => ['pack_list', 'driver_load'], 'label_key' => 'login_history.flow.pack_load', 'label' => 'Pack then load'],
        ['keys' => ['driver_load', 'driver'], 'label_key' => 'login_history.flow.load_route', 'label' => 'Load then route'],
        ['keys' => ['driver', 'complete_delivery'], 'label_key' => 'login_history.flow.route_confirm', 'label' => 'Route then confirm'],
        ['keys' => ['daily_orders', 'production'], 'label_key' => 'login_history.flow.orders_bake', 'label' => 'Orders then bake'],
        ['keys' => ['manager', 'daily_run'], 'label_key' => 'login_history.flow.manage_run', 'label' => 'Manager then Daily Run'],
        ['keys' => ['customer_portal', 'customer_portal_regular'], 'label_key' => 'login_history.flow.portal_regular', 'label' => 'Portal home then regular order'],
        ['keys' => ['customer_portal_calendar', 'customer_portal_delivery'], 'label_key' => 'login_history.flow.portal_delivery', 'label' => 'Calendar then delivery'],
    ];
}

function bakery_login_history_match_workflows(array $transitions): array
{
    $lookup = [];
    foreach ($transitions as $row) {
        $lookup[$row['from_key'] . '>' . $row['to_key']] = (int)$row['n'];
    }
    $matched = [];
    foreach (bakery_login_history_known_workflows() as $flow) {
        $pair = $flow['keys'][0] . '>' . $flow['keys'][1];
        if (empty($lookup[$pair])) {
            continue;
        }
        $matched[] = [
            'from_key' => $flow['keys'][0],
            'to_key' => $flow['keys'][1],
            'n' => $lookup[$pair],
            'label' => bakery_login_history_translate($flow['label_key'], $flow['label']),
            'from_label' => bakery_login_history_page_label($flow['keys'][0]),
            'to_label' => bakery_login_history_page_label($flow['keys'][1]),
        ];
    }
    usort($matched, static function (array $a, array $b): int {
        return $b['n'] <=> $a['n'];
    });
    return $matched;
}

function bakery_login_history_group_timeline(array $timeline): array
{
    $groups = [];
    foreach ($timeline as $event) {
        $day = !empty($event['timestamp']) ? date('Y-m-d', (int)$event['timestamp']) : '';
        $groups[$day][] = $event;
    }
    return $groups;
}

function bakery_login_history_screen_href(string $key): string
{
    $key = bakery_login_history_page_key($key);
    if ($key === '' || in_array($key, bakery_login_history_noise_pages(), true)) {
        return '';
    }
    $base = defined('BASE_URL') ? BASE_URL : '/';
    return $base . $key . '.php';
}

function bakery_login_history_session_quality(int $pages, int $unique): string
{
    if ($pages <= 1) {
        return 'brief';
    }
    if ($unique <= 3) {
        return 'focused';
    }
    return 'touring';
}

function bakery_login_history_url(array $replace = [], ?array $current = null): string
{
    $values = array_merge($current ?? $_GET, $replace);
    if (!array_key_exists('page', $replace)) {
        unset($values['page']);
    }
    if (($values['view'] ?? '') === 'overview') {
        unset($values['view']);
    }
    if (!array_key_exists('export', $replace)) {
        unset($values['export']);
    }
    foreach ($values as $key => $value) {
        if ($value === '' || $value === null) {
            unset($values[$key]);
            continue;
        }
        if (($value === 0 || $value === '0') && in_array((string)$key, ['user_id', 'customer_id', 'page'], true)) {
            unset($values[$key]);
        }
    }
    $base = defined('BASE_URL') ? BASE_URL : '/';
    return $base . 'login_history.php' . ($values ? '?' . http_build_query($values) : '');
}

function bakery_login_history_event(array $event): array
{
    $timestamp = strtotime((string)$event['occurred_at']);
    return [
        'occurred_at' => (string)$event['occurred_at'],
        'timestamp' => $timestamp === false ? 0 : $timestamp,
        'kind' => $event['kind'],
        'title' => $event['title'],
        'detail' => $event['detail'] ?? '',
        'path' => $event['path'] ?? '',
        'sort_id' => (int)($event['sort_id'] ?? 0),
    ];
}

function bakery_login_history_page_key(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    $path = explode('#', $path, 2)[0];
    $path = explode('?', $path, 2)[0];
    $base = basename($path);
    $base = (string)preg_replace('/\.php$/i', '', $base);
    return strtolower($base);
}

function bakery_login_history_page_sql_expr(string $column): string
{
    return "LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(COALESCE({$column}, ''), '?', 1), '/', -1), '.php', 1))";
}

function bakery_login_history_extra_pages(): array
{
    return [
        'login' => ['label' => 'Staff sign-in', 'label_key' => 'login_history.screen.login', 'area' => 'sign_in'],
        'customer_login' => ['label' => 'Customer sign-in', 'label_key' => 'login_history.screen.customer_login', 'area' => 'sign_in'],
        'customer_portal_login' => ['label' => 'Customer sign-in', 'label_key' => 'login_history.screen.customer_login', 'area' => 'sign_in'],
        'customer_portal' => ['label' => 'Customer home', 'label_key' => 'page.portal_home', 'area' => 'customer_portal'],
        'customer_portal_calendar' => ['label' => 'Delivery calendar', 'label_key' => 'page.portal_calendar', 'area' => 'customer_portal'],
        'customer_portal_regular' => ['label' => 'Regular order', 'label_key' => 'page.portal_regular_order', 'area' => 'customer_portal'],
        'customer_portal_history' => ['label' => 'Delivery history', 'label_key' => 'page.portal_history', 'area' => 'customer_portal'],
        'customer_portal_delivery' => ['label' => 'Delivery details', 'label_key' => 'page.portal_delivery', 'area' => 'customer_portal'],
        'customer_portal_deliveries' => ['label' => 'Upcoming deliveries', 'label_key' => 'page.portal_upcoming_deliveries', 'area' => 'customer_portal'],
        'customer_portal_billing' => ['label' => 'Customer billing', 'label_key' => 'page.portal_billing', 'area' => 'customer_portal'],
        'customer_portal_statement' => ['label' => 'Statement', 'label_key' => 'page.portal_statement', 'area' => 'customer_portal'],
        'customer_portal_account' => ['label' => 'Account', 'label_key' => 'page.portal_account', 'area' => 'customer_portal'],
        'customer_portal_notifications' => ['label' => 'Notifications', 'label_key' => 'page.portal_notifications', 'area' => 'customer_portal'],
        'customer_portal_issue' => ['label' => 'Service issue', 'label_key' => 'page.portal_issue', 'area' => 'customer_portal'],
        'customer_portal_delivery_photo' => ['label' => 'Delivery photo', 'label_key' => 'login_history.screen.delivery_photo', 'area' => 'customer_portal'],
        'complete_delivery' => ['label' => 'Complete delivery', 'label_key' => 'login_history.screen.complete_delivery', 'area' => 'delivery'],
        'call_headquarters' => ['label' => 'Call headquarters', 'label_key' => 'login_history.screen.call_hq', 'area' => 'delivery'],
        'driver_stops' => ['label' => "Today's stops", 'label_key' => 'page.driver_stops', 'area' => 'delivery'],
        'driver_list' => ['label' => 'Driver route list', 'label_key' => 'page.driver_list', 'area' => 'delivery'],
        'baker' => ['label' => 'Baker dashboard', 'label_key' => 'page.index_baker', 'area' => 'production'],
        'login_history' => ['label' => 'Login History', 'label_key' => 'nav.item.login_history', 'area' => 'administration'],
        'login_audit_api' => ['label' => 'Session heartbeat', 'label_key' => 'login_history.screen.heartbeat', 'area' => 'other'],
    ];
}

function bakery_login_history_page_catalog(): array
{
    static $catalog = null;
    if ($catalog !== null) {
        return $catalog;
    }
    $catalog = [];
    if (function_exists('bakery_navigation_catalog')) {
        foreach (bakery_navigation_catalog() as $group) {
            $area = (string)($group['key'] ?? 'other');
            foreach ($group['items'] ?? [] as $item) {
                $key = function_exists('bakery_navigation_item_page_key')
                    ? bakery_navigation_item_page_key($item)
                    : bakery_login_history_page_key((string)($item['href'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $catalog[$key] = [
                    'label' => (string)($item['label'] ?? $key),
                    'label_key' => 'nav.item.' . $key,
                    'area' => $area,
                ];
            }
        }
    }
    foreach (bakery_login_history_extra_pages() as $key => $meta) {
        if (!isset($catalog[$key])) {
            $catalog[$key] = $meta;
        }
    }
    return $catalog;
}

function bakery_login_history_translate(string $key, string $fallback): string
{
    if (!function_exists('bakery_t')) {
        return $fallback;
    }
    $text = bakery_t($key);
    return $text === $key ? $fallback : $text;
}

function bakery_login_history_page_meta(string $pathOrKey): array
{
    $key = bakery_login_history_page_key($pathOrKey);
    if ($key === '') {
        $key = strtolower(trim($pathOrKey));
    }
    $catalog = bakery_login_history_page_catalog();
    $meta = $catalog[$key] ?? [
        'label' => $key !== '' ? ucwords(str_replace('_', ' ', $key)) : bakery_login_history_translate('login_history.unknown_page', 'Unknown page'),
        'label_key' => '',
        'area' => 'other',
    ];
    $fallback = (string)($meta['label'] ?? $key);
    $labelKey = (string)($meta['label_key'] ?? '');
    return [
        'key' => $key,
        'label' => $labelKey !== '' ? bakery_login_history_translate($labelKey, $fallback) : $fallback,
        'area' => (string)($meta['area'] ?? 'other'),
    ];
}

function bakery_login_history_page_label(string $pathOrKey): string
{
    $meta = bakery_login_history_page_meta($pathOrKey);
    return $meta['label'] !== '' ? $meta['label'] : bakery_login_history_translate('login_history.unknown_page', 'Unknown page');
}

function bakery_login_history_area_label(string $area): string
{
    $fallbacks = [
        'workday' => 'Workday',
        'production' => 'Production',
        'orders' => 'Orders & customers',
        'delivery' => 'Delivery',
        'catalog' => 'Products & recipes',
        'insights' => 'Insights',
        'administration' => 'Administration',
        'customer_portal' => 'Customer portal',
        'sign_in' => 'Sign-in',
        'other' => 'Other screens',
    ];
    return bakery_login_history_translate('login_history.area.' . $area, $fallbacks[$area] ?? ucwords(str_replace('_', ' ', $area)));
}

function bakery_login_history_area_modules(string $area): array
{
    $keys = [];
    foreach (bakery_login_history_page_catalog() as $key => $meta) {
        if (($meta['area'] ?? 'other') === $area) {
            $keys[] = $key;
        }
    }
    return $keys;
}

function bakery_login_history_areas(): array
{
    $areas = [];
    foreach (bakery_login_history_page_catalog() as $meta) {
        $area = (string)($meta['area'] ?? 'other');
        $areas[$area] = bakery_login_history_area_label($area);
    }
    $areas['other'] = bakery_login_history_area_label('other');
    return $areas;
}

function bakery_login_history_collapse_path(array $keys): array
{
    $out = [];
    foreach ($keys as $key) {
        $key = bakery_login_history_page_key((string)$key);
        if ($key === '') {
            continue;
        }
        if ($out && end($out) === $key) {
            continue;
        }
        $out[] = $key;
    }
    return array_values($out);
}

function bakery_login_history_transitions_from_paths(array $sessionPaths): array
{
    $counts = [];
    foreach ($sessionPaths as $keys) {
        $path = bakery_login_history_collapse_path($keys);
        for ($i = 1, $n = count($path); $i < $n; $i++) {
            $pair = $path[$i - 1] . "\n" . $path[$i];
            $counts[$pair] = ($counts[$pair] ?? 0) + 1;
        }
    }
    arsort($counts);
    $rows = [];
    foreach ($counts as $pair => $n) {
        [$from, $to] = explode("\n", $pair, 2);
        $rows[] = ['from' => $from, 'to' => $to, 'n' => (int)$n];
    }
    return $rows;
}

function bakery_login_history_fill_days(string $from, string $until, array $rowsByDay, array $empty): array
{
    if ($from === '' || $until === '' || $from > $until) {
        return [];
    }
    try {
        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($until);
    } catch (Throwable $e) {
        return [];
    }
    if ($end < $start) {
        return [];
    }
    $days = (int)$start->diff($end)->format('%a') + 1;
    if ($days > 90) {
        $start = $end->modify('-89 days');
    }
    $out = [];
    for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
        $key = $day->format('Y-m-d');
        $out[] = array_merge($empty, ['day' => $key], $rowsByDay[$key] ?? []);
    }
    return $out;
}

function bakery_login_history_heatmap_grid(array $cells): array
{
    $grid = [];
    $max = 0;
    for ($weekday = 0; $weekday < 7; $weekday++) {
        for ($hour = 0; $hour < 24; $hour++) {
            $grid[$weekday][$hour] = 0;
        }
    }
    foreach ($cells as $cell) {
        $weekday = (int)($cell['weekday'] ?? -1);
        $hour = (int)($cell['hour'] ?? -1);
        $n = (int)($cell['n'] ?? 0);
        if ($weekday < 0 || $weekday > 6 || $hour < 0 || $hour > 23) {
            continue;
        }
        $grid[$weekday][$hour] += $n;
        if ($grid[$weekday][$hour] > $max) {
            $max = $grid[$weekday][$hour];
        }
    }
    return ['grid' => $grid, 'max' => $max];
}

function bakery_login_history_intensity(int $value, int $max): float
{
    if ($max <= 0 || $value <= 0) {
        return 0.0;
    }
    return round($value / $max, 3);
}

function bakery_login_history_is_live(array $row, ?int $now = null): bool
{
    if (($row['outcome'] ?? '') !== 'success' || !empty($row['logout_at'])) {
        return false;
    }
    $seen = strtotime((string)($row['last_seen_at'] ?? $row['login_at'] ?? ''));
    if ($seen === false) {
        return false;
    }
    $now = $now ?? time();
    return $seen >= ($now - bakery_login_history_live_seconds());
}

function bakery_login_history_parse_filters(array $get, ?string $today = null): array
{
    $today = $today ?: date('Y-m-d');
    $from = trim((string)($get['from'] ?? ''));
    $until = trim((string)($get['until'] ?? ''));
    if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $from = '';
    }
    if ($until !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) {
        $until = '';
    }

    $range = (string)($get['range'] ?? '');
    if (!in_array($range, bakery_login_history_ranges(), true)) {
        $range = '';
    }
    $explicitRange = $range !== '';
    $explicitDates = $from !== '' || $until !== '';
    if (!$explicitRange && !$explicitDates) {
        $range = '14d';
    }
    if ($range === 'today') {
        $from = $until = $today;
    } elseif ($range === '7d') {
        $from = (new DateTimeImmutable($today))->modify('-6 days')->format('Y-m-d');
        $until = $today;
    } elseif ($range === '14d') {
        $from = (new DateTimeImmutable($today))->modify('-13 days')->format('Y-m-d');
        $until = $today;
    } elseif ($range === '30d') {
        $from = (new DateTimeImmutable($today))->modify('-29 days')->format('Y-m-d');
        $until = $today;
    } elseif ($range === 'all') {
        $from = $until = '';
    } elseif ($range === '' && $explicitDates) {
        $range = bakery_login_history_infer_range($from, $until, $today);
    }

    $userId = (int)($get['user_id'] ?? 0);
    $customerId = (int)($get['customer_id'] ?? 0);
    $subject = trim((string)($get['subject'] ?? ''));
    if (preg_match('/^s-(\d+)$/', $subject, $m)) {
        $userId = (int)$m[1];
        $customerId = 0;
    } elseif (preg_match('/^c-(\d+)$/', $subject, $m)) {
        $customerId = (int)$m[1];
        $userId = 0;
    }
    if ($userId > 0) {
        $subject = 's-' . $userId;
    } elseif ($customerId > 0) {
        $subject = 'c-' . $customerId;
    } else {
        $subject = '';
    }

    $authType = (string)($get['auth_type'] ?? '');
    if (!in_array($authType, ['', 'staff', 'customer'], true)) {
        $authType = '';
    }
    $outcome = (string)($get['outcome'] ?? '');
    if (!in_array($outcome, ['', 'success', 'failure'], true)) {
        $outcome = '';
    }
    $role = strtolower(trim((string)($get['role'] ?? '')));
    if ($role !== '' && !preg_match('/^[a-z0-9_]{1,40}$/', $role)) {
        $role = '';
    }
    $device = (string)($get['device'] ?? '');
    if (!in_array($device, ['', 'Desktop', 'Mobile', 'Tablet'], true)) {
        $device = '';
    }
    $area = strtolower(trim((string)($get['area'] ?? '')));
    if ($area !== '' && !preg_match('/^[a-z0-9_]{1,40}$/', $area)) {
        $area = '';
    }
    $module = bakery_login_history_page_key((string)($get['module'] ?? ''));
    $session = (string)($get['session'] ?? '');
    if (!in_array($session, ['', 'live', 'idle', 'ended', 'failed'], true)) {
        $session = '';
    }
    $q = trim((string)($get['q'] ?? ''));
    if (strlen($q) > 80) {
        $q = substr($q, 0, 80);
    }
    $view = (string)($get['view'] ?? 'overview');
    if (!in_array($view, bakery_login_history_views(), true)) {
        $view = 'overview';
    }
    $page = max(1, (int)($get['page'] ?? 1));

    return [
        'from' => $from,
        'until' => $until,
        'range' => $range,
        'user_id' => $userId,
        'customer_id' => $customerId,
        'subject' => $subject,
        'auth_type' => $authType,
        'outcome' => $outcome,
        'role' => $role,
        'device' => $device,
        'area' => $area,
        'module' => $module,
        'session' => $session,
        'q' => $q,
        'view' => $view,
        'page' => $page,
        'today' => $today,
        'export' => (($get['export'] ?? '') === 'csv') ? 'csv' : '',
    ];
}

function bakery_login_history_has_filters(array $filters): bool
{
    if (!in_array((string)($filters['range'] ?? ''), ['', '14d'], true)) {
        return true;
    }
    foreach (['subject', 'auth_type', 'outcome', 'role', 'device', 'area', 'module', 'session', 'q'] as $key) {
        if (($filters[$key] ?? '') !== '') {
            return true;
        }
    }
    return !empty($filters['user_id']) || !empty($filters['customer_id']);
}

function bakery_login_history_chart_window(array $filters): array
{
    $until = $filters['until'] !== '' ? $filters['until'] : $filters['today'];
    $from = $filters['from'];
    try {
        $end = new DateTimeImmutable($until);
        $earliest = $end->modify('-59 days')->format('Y-m-d');
    } catch (Throwable $e) {
        return [$from, $until];
    }
    if ($from === '' || $from < $earliest) {
        $from = $earliest;
    }
    if ($from > $until) {
        $from = $until;
    }
    return [$from, $until];
}

function bakery_login_history_ready(PDO $db): array
{
    $audit = function_exists('bakery_login_audit_ready') && bakery_login_audit_ready($db);
    return [
        'audit' => $audit,
        'context' => $audit && function_exists('column_exists') && column_exists($db, 'login_audit', 'credential_method'),
        'activity' => $audit && function_exists('bakery_login_audit_activity_ready') && bakery_login_audit_activity_ready($db),
        'operational' => function_exists('table_exists') && table_exists($db, 'operational_events'),
    ];
}

function bakery_login_history_noise_clause(string $expr): array
{
    $noise = bakery_login_history_noise_pages();
    if (!$noise) {
        return ['1=1', []];
    }
    $placeholders = implode(',', array_fill(0, count($noise), '?'));
    return ["{$expr} NOT IN ({$placeholders})", $noise];
}

function bakery_login_history_query(PDO $db, string $sql, array $params = []): array
{
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('Login history query error: ' . $e->getMessage());
        return [];
    }
}

function bakery_login_history_from_sql(): string
{
    return 'login_audit la
            LEFT JOIN users u ON u.id = la.user_id
            LEFT JOIN roles r ON r.id = u.role_id
            LEFT JOIN customers c ON c.id = la.customer_id';
}

function bakery_login_history_live_sql(string $alias = 'la'): string
{
    $seconds = bakery_login_history_live_seconds();
    return "{$alias}.outcome = 'success' AND {$alias}.logout_at IS NULL
            AND {$alias}.last_seen_at >= DATE_SUB(NOW(), INTERVAL {$seconds} SECOND)";
}

/**
 * @param array<string, mixed> $opts time_column, page_column, skip_time
 * @return array{0:string,1:array}
 */
function bakery_login_history_clause(array $filters, array $ready, array $opts = []): array
{
    $timeColumn = $opts['time_column'] ?? 'la.login_at';
    $pageColumn = $opts['page_column'] ?? null;
    $where = ['1=1'];
    $params = [];

    if (empty($opts['skip_time'])) {
        if ($filters['from'] !== '') {
            $where[] = "{$timeColumn} >= ?";
            $params[] = $filters['from'] . ' 00:00:00';
        }
        if ($filters['until'] !== '') {
            $where[] = "{$timeColumn} < DATE_ADD(?, INTERVAL 1 DAY)";
            $params[] = $filters['until'] . ' 00:00:00';
        }
    }
    if (!empty($filters['user_id'])) {
        $where[] = 'la.user_id = ?';
        $params[] = (int)$filters['user_id'];
    }
    if (!empty($filters['customer_id'])) {
        $where[] = 'la.customer_id = ?';
        $params[] = (int)$filters['customer_id'];
    }
    if ($filters['auth_type'] !== '') {
        $where[] = 'la.auth_type = ?';
        $params[] = $filters['auth_type'];
    }
    if ($filters['outcome'] !== '') {
        $where[] = 'la.outcome = ?';
        $params[] = $filters['outcome'];
    }
    if ($filters['role'] !== '') {
        $where[] = 'r.slug = ?';
        $params[] = $filters['role'];
    }
    if ($filters['device'] !== '') {
        $where[] = 'la.device_type = ?';
        $params[] = $filters['device'];
    }
    if ($filters['q'] !== '') {
        $like = '%' . $filters['q'] . '%';
        $where[] = '(la.principal LIKE ? OR u.display_name LIKE ? OR u.email LIKE ? OR c.name LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }

    $session = $filters['session'] ?? '';
    if ($session === 'live') {
        $where[] = bakery_login_history_live_sql('la');
    } elseif ($session === 'idle') {
        $seconds = bakery_login_history_live_seconds();
        $where[] = "la.outcome = 'success' AND la.logout_at IS NULL
                    AND (la.last_seen_at IS NULL OR la.last_seen_at < DATE_SUB(NOW(), INTERVAL {$seconds} SECOND))";
    } elseif ($session === 'ended') {
        $where[] = "la.outcome = 'success' AND la.logout_at IS NOT NULL";
    } elseif ($session === 'failed') {
        $where[] = "la.outcome = 'failure'";
    }

    $moduleKeys = [];
    if ($filters['module'] !== '') {
        $moduleKeys = [$filters['module']];
    } elseif ($filters['area'] !== '') {
        if ($filters['area'] === 'other') {
            $known = array_keys(bakery_login_history_page_catalog());
            if ($pageColumn) {
                $expr = bakery_login_history_page_sql_expr($pageColumn);
                if ($known) {
                    $placeholders = implode(',', array_fill(0, count($known), '?'));
                    $where[] = "({$expr} = '' OR {$expr} NOT IN ({$placeholders}))";
                    $params = array_merge($params, $known);
                }
            } else {
                $lastExpr = bakery_login_history_page_sql_expr('la.last_page_path');
                $uriExpr = bakery_login_history_page_sql_expr('la.request_uri');
                if ($known) {
                    $placeholders = implode(',', array_fill(0, count($known), '?'));
                    $notKnown = "({$lastExpr} = '' OR {$lastExpr} NOT IN ({$placeholders})) AND ({$uriExpr} = '' OR {$uriExpr} NOT IN ({$placeholders}))";
                    $where[] = $notKnown;
                    $params = array_merge($params, $known, $known);
                }
            }
        } else {
            $moduleKeys = bakery_login_history_area_modules($filters['area']);
            if (!$moduleKeys) {
                $where[] = '0=1';
            }
        }
    }

    if ($moduleKeys) {
        $placeholders = implode(',', array_fill(0, count($moduleKeys), '?'));
        if ($pageColumn) {
            $expr = bakery_login_history_page_sql_expr($pageColumn);
            $where[] = "{$expr} IN ({$placeholders})";
            $params = array_merge($params, $moduleKeys);
        } else {
            $lastExpr = bakery_login_history_page_sql_expr('la.last_page_path');
            $uriExpr = bakery_login_history_page_sql_expr('la.request_uri');
            $clause = "{$lastExpr} IN ({$placeholders}) OR {$uriExpr} IN ({$placeholders})";
            $params = array_merge($params, $moduleKeys, $moduleKeys);
            if (!empty($ready['activity'])) {
                $actExpr = bakery_login_history_page_sql_expr('laa_mod.page_path');
                $clause .= " OR EXISTS (
                    SELECT 1 FROM login_audit_activity laa_mod
                    WHERE laa_mod.login_audit_id = la.id AND {$actExpr} IN ({$placeholders})
                )";
                $params = array_merge($params, $moduleKeys);
            }
            $where[] = '(' . $clause . ')';
        }
    }

    return [implode(' AND ', $where), $params];
}

function bakery_login_history_load_options(PDO $db, array $ready): array
{
    $users = [];
    $customers = [];
    $roles = [];
    $modules = [];
    if (empty($ready['audit'])) {
        return compact('users', 'customers', 'roles', 'modules');
    }
    $users = bakery_login_history_query(
        $db,
        'SELECT u.id, u.display_name, r.name AS role_name, r.slug AS role_slug
         FROM users u JOIN roles r ON r.id = u.role_id
         ORDER BY u.display_name'
    );
    $customers = bakery_login_history_query(
        $db,
        'SELECT DISTINCT c.id, c.name
         FROM customers c
         JOIN login_audit la ON la.customer_id = c.id
         ORDER BY c.name'
    );
    $roles = bakery_login_history_query(
        $db,
        'SELECT slug, name FROM roles ORDER BY name'
    );
    $pageExpr = bakery_login_history_page_sql_expr('path_source');
    if (!empty($ready['activity'])) {
        $modules = bakery_login_history_query(
            $db,
            "SELECT {$pageExpr} AS module_key, COUNT(*) AS n
             FROM (
                SELECT laa.page_path AS path_source
                FROM login_audit_activity laa
                WHERE laa.event_type = 'page_view'
                UNION ALL
                SELECT la.last_page_path FROM login_audit la WHERE la.last_page_path IS NOT NULL
             ) paths
             WHERE {$pageExpr} <> ''
             GROUP BY module_key
             ORDER BY n DESC
             LIMIT 80"
        );
    } else {
        $lastExpr = bakery_login_history_page_sql_expr('la.last_page_path');
        $modules = bakery_login_history_query(
            $db,
            "SELECT {$lastExpr} AS module_key, COUNT(*) AS n
             FROM login_audit la
             WHERE la.last_page_path IS NOT NULL AND {$lastExpr} <> ''
             GROUP BY module_key
             ORDER BY n DESC
             LIMIT 80"
        );
    }
    foreach ($modules as &$module) {
        $meta = bakery_login_history_page_meta((string)$module['module_key']);
        $module['label'] = $meta['label'];
        $module['area'] = $meta['area'];
    }
    unset($module);
    return compact('users', 'customers', 'roles', 'modules');
}

function bakery_login_history_load_summary(PDO $db, array $filters, array $ready): array
{
    $summary = [
        'signins' => 0,
        'active' => 0,
        'users' => 0,
        'avg_seconds' => 0,
        'pages' => 0,
        'failures' => 0,
        'actions' => 0,
    ];
    if (empty($ready['audit'])) {
        return $summary;
    }
    [$whereSql, $params] = bakery_login_history_clause($filters, $ready);
    [$liveWhere, $liveParams] = bakery_login_history_clause($filters, $ready, ['skip_time' => true]);
    $liveSql = bakery_login_history_live_sql('la');
    $rows = bakery_login_history_query(
        $db,
        "SELECT COUNT(CASE WHEN la.outcome = 'success' THEN 1 END) AS signins,
                COUNT(DISTINCT CASE WHEN la.outcome = 'success' THEN CONCAT(la.auth_type, ':', COALESCE(la.user_id, la.customer_id, la.id)) END) AS users,
                COALESCE(AVG(CASE WHEN la.outcome = 'success' THEN la.duration_seconds END), 0) AS avg_seconds,
                COALESCE(SUM(la.page_views_count), 0) AS pages,
                COUNT(CASE WHEN la.outcome = 'failure' THEN 1 END) AS failures
         FROM " . bakery_login_history_from_sql() . " WHERE {$whereSql}",
        $params
    );
    $summary = array_merge($summary, $rows[0] ?? []);
    $liveRows = bakery_login_history_query(
        $db,
        "SELECT COUNT(*) AS active FROM " . bakery_login_history_from_sql() . " WHERE {$liveSql} AND {$liveWhere}",
        $liveParams
    );
    $summary['active'] = (int)($liveRows[0]['active'] ?? 0);
    if (!empty($ready['activity'])) {
        [$actWhere, $actParams] = bakery_login_history_clause($filters, $ready, [
            'time_column' => 'laa.occurred_at',
            'page_column' => 'laa.page_path',
        ]);
        $pageRows = bakery_login_history_query(
            $db,
            "SELECT COUNT(*) AS pages
             FROM login_audit_activity laa
             JOIN login_audit la ON la.id = laa.login_audit_id
             LEFT JOIN users u ON u.id = la.user_id
             LEFT JOIN roles r ON r.id = u.role_id
             LEFT JOIN customers c ON c.id = la.customer_id
             WHERE laa.event_type = 'page_view' AND {$actWhere}",
            $actParams
        );
        if ($pageRows) {
            $summary['pages'] = (int)$pageRows[0]['pages'];
        }
    }
    if (!empty($ready['operational'])) {
        $actionWhere = ['1=1'];
        $actionParams = [];
        if ($filters['from'] !== '') {
            $actionWhere[] = 'oe.occurred_at >= ?';
            $actionParams[] = $filters['from'] . ' 00:00:00';
        }
        if ($filters['until'] !== '') {
            $actionWhere[] = 'oe.occurred_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $actionParams[] = $filters['until'] . ' 00:00:00';
        }
        if (!empty($filters['user_id'])) {
            $actionWhere[] = 'oe.actor_user_id = ?';
            $actionParams[] = (int)$filters['user_id'];
        }
        if (!empty($filters['customer_id'])) {
            $actionWhere[] = 'oe.customer_id = ?';
            $actionParams[] = (int)$filters['customer_id'];
        }
        $actionRows = bakery_login_history_query(
            $db,
            'SELECT COUNT(*) AS actions FROM operational_events oe WHERE ' . implode(' AND ', $actionWhere),
            $actionParams
        );
        $summary['actions'] = (int)($actionRows[0]['actions'] ?? 0);
    }
    return $summary;
}

function bakery_login_history_decorate_session(array $row, ?int $now = null): array
{
    $isSuccess = ($row['outcome'] ?? '') === 'success';
    $isLive = bakery_login_history_is_live($row, $now);
    $displayName = $row['staff_name'] ?: ($row['customer_name'] ?: $row['principal'] ?? '');
    $role = $row['role_name'] ?: (($row['auth_type'] ?? '') === 'customer'
        ? bakery_login_history_translate('login_history.customer_portal', 'Customer portal')
        : bakery_login_history_translate('login_history.staff_app', 'Staff app'));
    $lastPage = (string)($row['last_page_path'] ?? '');
    $pageMeta = bakery_login_history_page_meta($lastPage);
    return array_merge($row, [
        'is_success' => $isSuccess,
        'is_live' => $isLive,
        'display_name' => $displayName,
        'role_label' => $role,
        'page_key' => $pageMeta['key'],
        'page_label' => $lastPage !== '' ? $pageMeta['label'] : '',
        'page_area' => $pageMeta['area'],
    ]);
}

function bakery_login_history_session_select(): string
{
    return "SELECT la.*, u.display_name AS staff_name, u.email AS staff_email, r.name AS role_name, r.slug AS role_slug,
                   c.name AS customer_name, c.phone AS customer_phone";
}

function bakery_login_history_load_sessions(PDO $db, array $filters, array $ready, int $limit, string $mode = 'recent'): array
{
    if (empty($ready['audit'])) {
        return [];
    }
    $extra = '';
    if ($mode === 'live') {
        $extra = ' AND ' . bakery_login_history_live_sql('la');
    } elseif ($mode === 'idle') {
        $seconds = bakery_login_history_live_seconds();
        $extra = " AND la.outcome = 'success' AND la.logout_at IS NULL
                   AND (la.last_seen_at IS NULL OR la.last_seen_at < DATE_SUB(NOW(), INTERVAL {$seconds} SECOND))";
    }
    $clauseOpts = in_array($mode, ['live', 'idle'], true) ? ['skip_time' => true] : [];
    [$whereSql, $params] = bakery_login_history_clause($filters, $ready, $clauseOpts);
    $order = $mode === 'live' || $mode === 'idle'
        ? 'la.last_seen_at DESC, la.id DESC'
        : 'la.login_at DESC, la.id DESC';
    $limit = max(1, min(100, $limit));
    $rows = bakery_login_history_query(
        $db,
        bakery_login_history_session_select() . ' FROM ' . bakery_login_history_from_sql()
        . " WHERE {$whereSql}{$extra} ORDER BY {$order} LIMIT {$limit}",
        $params
    );
    $now = time();
    return array_map(static function (array $row) use ($now) {
        return bakery_login_history_decorate_session($row, $now);
    }, $rows);
}

function bakery_login_history_load_records(PDO $db, array $filters, array $ready, int $perPage = 50): array
{
    $total = 0;
    $rows = [];
    if (empty($ready['audit'])) {
        return ['total' => 0, 'rows' => [], 'last_page' => 1];
    }
    [$whereSql, $params] = bakery_login_history_clause($filters, $ready);
    $countRows = bakery_login_history_query($db, 'SELECT COUNT(*) AS n FROM ' . bakery_login_history_from_sql() . " WHERE {$whereSql}", $params);
    $total = (int)($countRows[0]['n'] ?? 0);
    $perPage = max(1, min(100, $perPage));
    $page = max(1, (int)$filters['page']);
    $lastPage = max(1, (int)ceil($total / $perPage));
    if ($page > $lastPage) {
        $page = $lastPage;
    }
    $offset = ($page - 1) * $perPage;
    $fetched = bakery_login_history_query(
        $db,
        bakery_login_history_session_select() . ' FROM ' . bakery_login_history_from_sql()
        . " WHERE {$whereSql} ORDER BY la.login_at DESC, la.id DESC LIMIT {$perPage} OFFSET {$offset}",
        $params
    );
    $now = time();
    foreach ($fetched as $row) {
        $rows[] = bakery_login_history_decorate_session($row, $now);
    }
    return ['total' => $total, 'rows' => $rows, 'last_page' => $lastPage, 'page' => $page];
}

function bakery_login_history_load_daily(PDO $db, array $filters, array $ready): array
{
    [$chartFrom, $chartUntil] = bakery_login_history_chart_window($filters);
    $chartFilters = array_merge($filters, ['from' => $chartFrom, 'until' => $chartUntil]);
    $byDay = [];
    if (!empty($ready['audit'])) {
        [$whereSql, $params] = bakery_login_history_clause($chartFilters, $ready);
        foreach (bakery_login_history_query(
            $db,
            "SELECT DATE(la.login_at) AS day,
                    COUNT(CASE WHEN la.outcome = 'success' THEN 1 END) AS signins,
                    COUNT(CASE WHEN la.outcome = 'failure' THEN 1 END) AS failures,
                    COUNT(DISTINCT CASE WHEN la.outcome = 'success' THEN CONCAT(la.auth_type, ':', COALESCE(la.user_id, la.customer_id, la.id)) END) AS users,
                    COUNT(CASE WHEN la.auth_type = 'staff' AND la.outcome = 'success' THEN 1 END) AS staff_signins,
                    COUNT(CASE WHEN la.auth_type = 'customer' AND la.outcome = 'success' THEN 1 END) AS customer_signins
             FROM " . bakery_login_history_from_sql() . " WHERE {$whereSql}
             GROUP BY DATE(la.login_at)
             ORDER BY day",
            $params
        ) as $row) {
            $byDay[$row['day']] = $row;
        }
    }
    if (!empty($ready['activity'])) {
        [$actWhere, $actParams] = bakery_login_history_clause($chartFilters, $ready, [
            'time_column' => 'laa.occurred_at',
            'page_column' => 'laa.page_path',
        ]);
        foreach (bakery_login_history_query(
            $db,
            "SELECT DATE(laa.occurred_at) AS day, COUNT(*) AS pages
             FROM login_audit_activity laa
             JOIN login_audit la ON la.id = laa.login_audit_id
             LEFT JOIN users u ON u.id = la.user_id
             LEFT JOIN roles r ON r.id = u.role_id
             LEFT JOIN customers c ON c.id = la.customer_id
             WHERE laa.event_type = 'page_view' AND {$actWhere}
             GROUP BY DATE(laa.occurred_at)",
            $actParams
        ) as $row) {
            $day = $row['day'];
            if (!isset($byDay[$day])) {
                $byDay[$day] = ['day' => $day, 'signins' => 0, 'failures' => 0, 'users' => 0, 'staff_signins' => 0, 'customer_signins' => 0];
            }
            $byDay[$day]['pages'] = (int)$row['pages'];
        }
    }
    $filled = bakery_login_history_fill_days($chartFrom, $chartUntil, $byDay, [
        'signins' => 0,
        'failures' => 0,
        'users' => 0,
        'pages' => 0,
        'staff_signins' => 0,
        'customer_signins' => 0,
    ]);
    $maxSignins = 0;
    $maxPages = 0;
    $maxFailures = 0;
    foreach ($filled as $row) {
        $maxSignins = max($maxSignins, (int)$row['signins']);
        $maxPages = max($maxPages, (int)$row['pages']);
        $maxFailures = max($maxFailures, (int)$row['failures']);
    }
    return ['rows' => $filled, 'from' => $chartFrom, 'until' => $chartUntil, 'max_signins' => $maxSignins, 'max_pages' => $maxPages, 'max_failures' => $maxFailures];
}

function bakery_login_history_load_heatmap(PDO $db, array $filters, array $ready, string $source = 'login'): array
{
    [$chartFrom, $chartUntil] = bakery_login_history_chart_window($filters);
    $chartFilters = array_merge($filters, ['from' => $chartFrom, 'until' => $chartUntil, 'outcome' => $filters['outcome'] ?: 'success']);
    $cells = [];
    $hourly = array_fill(0, 24, 0);
    $weekday = array_fill(0, 7, 0);
    if ($source === 'pages' && !empty($ready['activity'])) {
        [$whereSql, $params] = bakery_login_history_clause($chartFilters, $ready, [
            'time_column' => 'laa.occurred_at',
            'page_column' => 'laa.page_path',
        ]);
        $expr = bakery_login_history_page_sql_expr('laa.page_path');
        [$noiseSql, $noiseParams] = bakery_login_history_noise_clause($expr);
        $cells = bakery_login_history_query(
            $db,
            "SELECT WEEKDAY(laa.occurred_at) AS weekday, HOUR(laa.occurred_at) AS hour, COUNT(*) AS n
             FROM login_audit_activity laa
             JOIN login_audit la ON la.id = laa.login_audit_id
             LEFT JOIN users u ON u.id = la.user_id
             LEFT JOIN roles r ON r.id = u.role_id
             LEFT JOIN customers c ON c.id = la.customer_id
             WHERE laa.event_type = 'page_view' AND {$expr} <> '' AND {$noiseSql} AND {$whereSql}
             GROUP BY WEEKDAY(laa.occurred_at), HOUR(laa.occurred_at)",
            array_merge($noiseParams, $params)
        );
    } elseif (!empty($ready['audit'])) {
        [$whereSql, $params] = bakery_login_history_clause($chartFilters, $ready);
        $cells = bakery_login_history_query(
            $db,
            "SELECT WEEKDAY(la.login_at) AS weekday, HOUR(la.login_at) AS hour, COUNT(*) AS n
             FROM " . bakery_login_history_from_sql() . " WHERE {$whereSql}
             GROUP BY WEEKDAY(la.login_at), HOUR(la.login_at)",
            $params
        );
    }
    foreach ($cells as $cell) {
        $h = (int)$cell['hour'];
        $d = (int)$cell['weekday'];
        $n = (int)$cell['n'];
        if ($h >= 0 && $h < 24) {
            $hourly[$h] += $n;
        }
        if ($d >= 0 && $d < 7) {
            $weekday[$d] += $n;
        }
    }
    $grid = bakery_login_history_heatmap_grid($cells);
    return [
        'grid' => $grid['grid'],
        'max' => $grid['max'],
        'hourly' => $hourly,
        'hourly_max' => $hourly ? max($hourly) : 0,
        'weekday' => $weekday,
        'weekday_max' => $weekday ? max($weekday) : 0,
        'from' => $chartFrom,
        'until' => $chartUntil,
        'peak_hour' => bakery_login_history_peak_hour($hourly),
        'source' => $source,
    ];
}

function bakery_login_history_load_pages(PDO $db, array $filters, array $ready, int $limit = 20): array
{
    if (empty($ready['activity'])) {
        return [];
    }
    [$whereSql, $params] = bakery_login_history_clause($filters, $ready, [
        'time_column' => 'laa.occurred_at',
        'page_column' => 'laa.page_path',
    ]);
    $limit = max(1, min(50, $limit));
    $expr = bakery_login_history_page_sql_expr('laa.page_path');
    [$noiseSql, $noiseParams] = bakery_login_history_noise_clause($expr);
    $rows = bakery_login_history_query(
        $db,
        "SELECT {$expr} AS module_key,
                COUNT(*) AS visits,
                COUNT(DISTINCT CONCAT(la.auth_type, ':', COALESCE(la.user_id, la.customer_id, la.id))) AS people,
                MAX(laa.occurred_at) AS last_seen_at
         FROM login_audit_activity laa
         JOIN login_audit la ON la.id = laa.login_audit_id
         LEFT JOIN users u ON u.id = la.user_id
         LEFT JOIN roles r ON r.id = u.role_id
         LEFT JOIN customers c ON c.id = la.customer_id
         WHERE laa.event_type = 'page_view' AND {$expr} <> '' AND {$noiseSql} AND {$whereSql}
         GROUP BY module_key
         ORDER BY visits DESC, last_seen_at DESC
         LIMIT {$limit}",
        array_merge($noiseParams, $params)
    );
    foreach ($rows as &$row) {
        $meta = bakery_login_history_page_meta((string)$row['module_key']);
        $row['label'] = $meta['label'];
        $row['area'] = $meta['area'];
        $row['area_label'] = bakery_login_history_area_label($meta['area']);
    }
    unset($row);
    return $rows;
}

function bakery_login_history_areas_from_pages(array $pages): array
{
    $areas = [];
    foreach ($pages as $page) {
        $area = $page['area'] ?: 'other';
        if (!isset($areas[$area])) {
            $areas[$area] = ['area' => $area, 'label' => bakery_login_history_area_label($area), 'visits' => 0, 'people' => 0];
        }
        $areas[$area]['visits'] += (int)$page['visits'];
        $areas[$area]['people'] += (int)$page['people'];
    }
    usort($areas, static function (array $a, array $b): int {
        return $b['visits'] <=> $a['visits'];
    });
    return array_values($areas);
}

function bakery_login_history_load_people(PDO $db, array $filters, array $ready, int $limit = 20): array
{
    if (empty($ready['audit'])) {
        return [];
    }
    [$whereSql, $params] = bakery_login_history_clause($filters, $ready);
    $limit = max(1, min(80, $limit));
    $liveSql = bakery_login_history_live_sql('la');
    $rows = bakery_login_history_query(
        $db,
        "SELECT CASE WHEN la.auth_type = 'customer' THEN 'customer' ELSE 'staff' END AS kind,
                COALESCE(la.user_id, 0) AS user_id,
                COALESCE(la.customer_id, 0) AS customer_id,
                COALESCE(NULLIF(u.display_name, ''), NULLIF(c.name, ''), la.principal) AS display_name,
                COALESCE(r.name, CASE WHEN la.auth_type = 'customer' THEN 'Customer portal' ELSE 'Staff' END) AS role_name,
                COALESCE(r.slug, la.auth_type) AS role_slug,
                COUNT(CASE WHEN la.outcome = 'success' THEN 1 END) AS sessions,
                COUNT(CASE WHEN la.outcome = 'failure' THEN 1 END) AS failed,
                COALESCE(SUM(la.page_views_count), 0) AS pages,
                MAX(COALESCE(la.last_seen_at, la.login_at)) AS last_seen_at,
                MAX(CASE WHEN {$liveSql} THEN 1 ELSE 0 END) AS is_live,
                SUBSTRING_INDEX(GROUP_CONCAT(la.last_page_path ORDER BY COALESCE(la.last_seen_at, la.login_at) DESC SEPARATOR '\n'), '\n', 1) AS last_page_path
         FROM " . bakery_login_history_from_sql() . "
         WHERE {$whereSql}
         GROUP BY kind, user_id, customer_id, display_name, role_name, role_slug
         ORDER BY is_live DESC, last_seen_at DESC
         LIMIT {$limit}",
        $params
    );

    $topByPerson = [];
    if (!empty($ready['activity']) && $rows) {
        $staffIds = [];
        $customerIds = [];
        foreach ($rows as $row) {
            if ($row['kind'] === 'customer' && (int)$row['customer_id'] > 0) {
                $customerIds[] = (int)$row['customer_id'];
            } elseif ((int)$row['user_id'] > 0) {
                $staffIds[] = (int)$row['user_id'];
            }
        }
        $expr = bakery_login_history_page_sql_expr('laa.page_path');
        [$actWhere, $actParams] = bakery_login_history_clause($filters, $ready, [
            'time_column' => 'laa.occurred_at',
            'page_column' => 'laa.page_path',
        ]);
        $personClause = [];
        if ($staffIds) {
            $personClause[] = 'la.user_id IN (' . implode(',', array_map('intval', $staffIds)) . ')';
        }
        if ($customerIds) {
            $personClause[] = 'la.customer_id IN (' . implode(',', array_map('intval', $customerIds)) . ')';
        }
        if ($personClause) {
            $topRows = bakery_login_history_query(
                $db,
                "SELECT la.auth_type, COALESCE(la.user_id, 0) AS user_id, COALESCE(la.customer_id, 0) AS customer_id,
                        {$expr} AS module_key, COUNT(*) AS visits
                 FROM login_audit_activity laa
                 JOIN login_audit la ON la.id = laa.login_audit_id
                 LEFT JOIN users u ON u.id = la.user_id
                 LEFT JOIN roles r ON r.id = u.role_id
                 LEFT JOIN customers c ON c.id = la.customer_id
                 WHERE laa.event_type = 'page_view' AND {$expr} <> '' AND {$actWhere}
                   AND (" . implode(' OR ', $personClause) . ")
                 GROUP BY la.auth_type, user_id, customer_id, module_key
                 ORDER BY visits DESC",
                $actParams
            );
            foreach ($topRows as $top) {
                $key = $top['auth_type'] . ':' . $top['user_id'] . ':' . $top['customer_id'];
                if (!isset($topByPerson[$key])) {
                    $topByPerson[$key] = $top;
                }
            }
        }
    }

    foreach ($rows as &$row) {
        $pageMeta = bakery_login_history_page_meta((string)($row['last_page_path'] ?? ''));
        $row['last_page_label'] = $pageMeta['label'];
        $row['last_page_key'] = $pageMeta['key'];
        $personKey = ($row['kind'] === 'customer' ? 'customer' : 'staff') . ':' . $row['user_id'] . ':' . $row['customer_id'];
        $top = $topByPerson[$personKey] ?? null;
        $row['top_page_key'] = $top['module_key'] ?? $pageMeta['key'];
        $row['top_page_label'] = $row['top_page_key'] !== '' ? bakery_login_history_page_label((string)$row['top_page_key']) : '';
        $row['subject'] = $row['kind'] === 'customer' ? 'c-' . (int)$row['customer_id'] : 's-' . (int)$row['user_id'];
    }
    unset($row);
    return $rows;
}

function bakery_login_history_load_devices(PDO $db, array $filters, array $ready): array
{
    if (empty($ready['audit'])) {
        return [];
    }
    $deviceFilters = $filters;
    if ($deviceFilters['outcome'] === '') {
        $deviceFilters['outcome'] = 'success';
    }
    [$whereSql, $params] = bakery_login_history_clause($deviceFilters, $ready);
    return bakery_login_history_query(
        $db,
        "SELECT COALESCE(NULLIF(la.device_type, ''), 'Unknown') AS device_type, COUNT(*) AS n
         FROM " . bakery_login_history_from_sql() . " WHERE {$whereSql}
         GROUP BY device_type
         ORDER BY n DESC",
        $params
    );
}

function bakery_login_history_load_actions(PDO $db, array $filters, array $ready, int $limit = 12): array
{
    if (empty($ready['operational'])) {
        return [];
    }
    $where = ['1=1'];
    $params = [];
    if ($filters['from'] !== '') {
        $where[] = 'oe.occurred_at >= ?';
        $params[] = $filters['from'] . ' 00:00:00';
    }
    if ($filters['until'] !== '') {
        $where[] = 'oe.occurred_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $params[] = $filters['until'] . ' 00:00:00';
    }
    if (!empty($filters['user_id'])) {
        $where[] = 'oe.actor_user_id = ?';
        $params[] = (int)$filters['user_id'];
    }
    if (!empty($filters['customer_id'])) {
        $where[] = 'oe.customer_id = ?';
        $params[] = (int)$filters['customer_id'];
    }
    $limit = max(1, min(30, $limit));
    $rows = bakery_login_history_query(
        $db,
        'SELECT oe.event_type, COUNT(*) AS n, MAX(oe.occurred_at) AS last_at
         FROM operational_events oe
         WHERE ' . implode(' AND ', $where) . "
         GROUP BY oe.event_type
         ORDER BY n DESC
         LIMIT {$limit}",
        $params
    );
    foreach ($rows as &$row) {
        $row['label'] = ucwords(str_replace('_', ' ', (string)$row['event_type']));
    }
    unset($row);
    return $rows;
}

function bakery_login_history_load_transitions(PDO $db, array $filters, array $ready, int $limit = 18): array
{
    if (empty($ready['activity'])) {
        return [];
    }
    [$whereSql, $params] = bakery_login_history_clause($filters, $ready, [
        'time_column' => 'laa.occurred_at',
        'page_column' => 'laa.page_path',
    ]);
    $expr = bakery_login_history_page_sql_expr('laa.page_path');
    $limit = max(1, min(40, $limit));
    $rows = bakery_login_history_query(
        $db,
        "SELECT prev_page AS from_key, page_key AS to_key, COUNT(*) AS n
         FROM (
            SELECT laa.login_audit_id,
                   {$expr} AS page_key,
                   LAG({$expr}) OVER (PARTITION BY laa.login_audit_id ORDER BY laa.occurred_at, laa.id) AS prev_page
            FROM login_audit_activity laa
            JOIN login_audit la ON la.id = laa.login_audit_id
            LEFT JOIN users u ON u.id = la.user_id
            LEFT JOIN roles r ON r.id = u.role_id
            LEFT JOIN customers c ON c.id = la.customer_id
            WHERE laa.event_type = 'page_view' AND {$expr} <> '' AND {$whereSql}
         ) steps
         WHERE prev_page IS NOT NULL AND prev_page <> '' AND prev_page <> page_key
         GROUP BY from_key, to_key
         ORDER BY n DESC
         LIMIT {$limit}",
        $params
    );
    foreach ($rows as &$row) {
        $row['from_label'] = bakery_login_history_page_label((string)$row['from_key']);
        $row['to_label'] = bakery_login_history_page_label((string)$row['to_key']);
    }
    unset($row);
    return $rows;
}

function bakery_login_history_load_session_paths(PDO $db, array $filters, array $ready, int $limit = 12): array
{
    if (empty($ready['activity'])) {
        return [];
    }
    $sessions = bakery_login_history_load_sessions($db, array_merge($filters, ['outcome' => $filters['outcome'] ?: 'success']), $ready, $limit, 'recent');
    if (!$sessions) {
        return [];
    }
    $ids = array_map(static function (array $row): int {
        return (int)$row['id'];
    }, $sessions);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $expr = bakery_login_history_page_sql_expr('laa.page_path');
    $events = bakery_login_history_query(
        $db,
        "SELECT laa.login_audit_id, {$expr} AS module_key
         FROM login_audit_activity laa
         WHERE laa.event_type = 'page_view' AND laa.login_audit_id IN ({$placeholders})
         ORDER BY laa.login_audit_id, laa.occurred_at, laa.id",
        $ids
    );
    $bySession = [];
    foreach ($events as $event) {
        $bySession[(int)$event['login_audit_id']][] = (string)$event['module_key'];
    }
    $paths = [];
    foreach ($sessions as $session) {
        $keys = bakery_login_history_collapse_path($bySession[(int)$session['id']] ?? []);
        if (!$keys) {
            continue;
        }
        $paths[] = [
            'id' => (int)$session['id'],
            'display_name' => $session['display_name'],
            'login_at' => $session['login_at'],
            'pages' => $keys,
            'labels' => array_map('bakery_login_history_page_label', $keys),
        ];
    }
    return $paths;
}

function bakery_login_history_load_investigation(PDO $db, array $filters, array $ready): array
{
    $empty = [
        'person' => null,
        'kind' => '',
        'sessions' => 0,
        'failed' => 0,
        'pages' => 0,
        'actions' => 0,
        'last_active' => null,
        'unique_pages' => 0,
        'timeline' => [],
        'top_pages' => [],
    ];
    $userId = (int)$filters['user_id'];
    $customerId = (int)$filters['customer_id'];
    if ($userId <= 0 && $customerId <= 0) {
        return $empty;
    }

    if ($userId > 0) {
        $people = bakery_login_history_query(
            $db,
            'SELECT u.id, u.display_name, u.email, r.name AS role_name, r.slug AS role_slug
             FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1',
            [$userId]
        );
        $person = $people[0] ?? null;
        $kind = 'staff';
    } else {
        $people = bakery_login_history_query($db, 'SELECT id, name AS display_name, phone AS email FROM customers WHERE id = ? LIMIT 1', [$customerId]);
        $person = $people[0] ?? null;
        if ($person) {
            $person['role_name'] = bakery_login_history_translate('login_history.customer_portal', 'Customer portal');
            $person['role_slug'] = 'customer';
        }
        $kind = 'customer';
    }
    if (!$person || empty($ready['audit'])) {
        $empty['person'] = $person;
        $empty['kind'] = $kind;
        return $empty;
    }

    $sessionFilters = array_merge($filters, [
        'user_id' => $userId,
        'customer_id' => $customerId,
        'auth_type' => '',
        'outcome' => '',
        'session' => '',
        'module' => '',
        'area' => '',
        'role' => '',
        'q' => '',
    ]);
    [$whereSql, $params] = bakery_login_history_clause($sessionFilters, $ready);
    $sessions = bakery_login_history_query(
        $db,
        'SELECT la.* FROM login_audit la
         LEFT JOIN users u ON u.id = la.user_id
         LEFT JOIN roles r ON r.id = u.role_id
         LEFT JOIN customers c ON c.id = la.customer_id
         WHERE ' . $whereSql . ' ORDER BY la.login_at DESC, la.id DESC LIMIT 400',
        $params
    );

    $investigation = $empty;
    $investigation['person'] = $person;
    $investigation['kind'] = $kind;
    $timeline = [];
    foreach ($sessions as $session) {
        $isSuccess = $session['outcome'] === 'success';
        if ($isSuccess) {
            $investigation['sessions']++;
        } else {
            $investigation['failed']++;
        }
        $seen = $session['last_seen_at'] ?: $session['login_at'];
        if (!$investigation['last_active'] || strtotime((string)$seen) > strtotime((string)$investigation['last_active'])) {
            $investigation['last_active'] = $seen;
        }
        $timeline[] = bakery_login_history_event([
            'occurred_at' => $session['login_at'],
            'kind' => 'session',
            'title' => $isSuccess
                ? bakery_login_history_translate('login_history.signed_in', 'Signed in')
                : bakery_login_history_translate('login_history.sign_in_failed', 'Sign-in failed'),
            'detail' => $isSuccess
                ? (($session['device_type'] ?: bakery_login_history_translate('login_history.unknown_device', 'Unknown device'))
                    . ' · ' . ($session['browser'] ?: bakery_login_history_translate('login_history.unknown_browser', 'Unknown browser'))
                    . ' · ' . ($session['ip_address'] ?: 'IP unavailable'))
                : ($session['failure_reason'] ?: bakery_login_history_translate('login_history.invalid_credentials', 'Invalid credentials')),
            'sort_id' => (int)$session['id'],
        ]);
        if ($isSuccess && !empty($session['logout_at'])) {
            $timeline[] = bakery_login_history_event([
                'occurred_at' => $session['logout_at'],
                'kind' => 'session',
                'title' => bakery_login_history_translate('login_history.signed_out', 'Signed out'),
                'detail' => bakery_login_history_translate('login_history.session_length', 'Session length')
                    . ': ' . bakery_login_history_duration($session['duration_seconds']),
                'sort_id' => (int)$session['id'],
            ]);
        }
    }

    $seenPaths = [];
    if (!empty($ready['activity'])) {
        [$actWhere, $actParams] = bakery_login_history_clause($sessionFilters, $ready, [
            'time_column' => 'laa.occurred_at',
        ]);
        $activities = bakery_login_history_query(
            $db,
            "SELECT laa.id, laa.occurred_at, laa.page_path, laa.page_title
             FROM login_audit_activity laa
             JOIN login_audit la ON la.id = laa.login_audit_id
             LEFT JOIN users u ON u.id = la.user_id
             LEFT JOIN roles r ON r.id = u.role_id
             LEFT JOIN customers c ON c.id = la.customer_id
             WHERE laa.event_type = 'page_view' AND {$actWhere}
             ORDER BY laa.occurred_at DESC, laa.id DESC
             LIMIT 800",
            $actParams
        );
        $pageCounts = [];
        foreach ($activities as $activity) {
            $path = (string)($activity['page_path'] ?? '');
            $key = bakery_login_history_page_key($path);
            if ($key !== '') {
                $seenPaths[$key] = true;
                $pageCounts[$key] = ($pageCounts[$key] ?? 0) + 1;
            }
            $investigation['pages']++;
            $title = $activity['page_title'] ?: bakery_login_history_page_label($path);
            $timeline[] = bakery_login_history_event([
                'occurred_at' => $activity['occurred_at'],
                'kind' => 'navigation',
                'title' => $title,
                'detail' => bakery_login_history_translate('login_history.nav_recorded', 'Navigation recorded in this signed-in session'),
                'path' => $path,
                'sort_id' => (int)$activity['id'],
            ]);
        }
        arsort($pageCounts);
        foreach (array_slice($pageCounts, 0, 8, true) as $key => $n) {
            $investigation['top_pages'][] = [
                'module_key' => $key,
                'label' => bakery_login_history_page_label($key),
                'visits' => $n,
            ];
        }
    }
    $investigation['unique_pages'] = count($seenPaths);

    if (!empty($ready['operational'])) {
        $actionWhere = ['1=1'];
        $actionParams = [];
        if ($userId > 0) {
            $actionWhere[] = 'oe.actor_user_id = ?';
            $actionParams[] = $userId;
        } else {
            $actionWhere[] = 'oe.customer_id = ?';
            $actionParams[] = $customerId;
        }
        if ($filters['from'] !== '') {
            $actionWhere[] = 'oe.occurred_at >= ?';
            $actionParams[] = $filters['from'] . ' 00:00:00';
        }
        if ($filters['until'] !== '') {
            $actionWhere[] = 'oe.occurred_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $actionParams[] = $filters['until'] . ' 00:00:00';
        }
        $actions = bakery_login_history_query(
            $db,
            'SELECT oe.id, oe.occurred_at, oe.event_type, oe.summary
             FROM operational_events oe WHERE ' . implode(' AND ', $actionWhere) . '
             ORDER BY oe.occurred_at DESC, oe.id DESC
             LIMIT 400',
            $actionParams
        );
        foreach ($actions as $action) {
            $investigation['actions']++;
            $timeline[] = bakery_login_history_event([
                'occurred_at' => $action['occurred_at'],
                'kind' => 'action',
                'title' => $action['summary'] ?: ucwords(str_replace('_', ' ', (string)$action['event_type'])),
                'detail' => bakery_login_history_translate('login_history.op_action', 'Recorded operational action'),
                'sort_id' => (int)$action['id'],
            ]);
        }
    }

    usort($timeline, static function (array $a, array $b): int {
        return ($b['timestamp'] <=> $a['timestamp']) ?: ($b['sort_id'] <=> $a['sort_id']);
    });
    $investigation['timeline_total'] = count($timeline);
    $investigation['timeline'] = array_slice($timeline, 0, 400);
    return $investigation;
}

function bakery_login_history_load_dwell(PDO $db, array $filters, array $ready): array
{
    if (empty($ready['activity'])) {
        return [];
    }
    [$whereSql, $params] = bakery_login_history_clause($filters, $ready, [
        'time_column' => 'laa.occurred_at',
        'page_column' => 'laa.page_path',
    ]);
    $expr = bakery_login_history_page_sql_expr('laa.page_path');
    [$noiseSql, $noiseParams] = bakery_login_history_noise_clause($expr);
    $rows = bakery_login_history_query(
        $db,
        "SELECT page_key, AVG(dwell_seconds) AS avg_dwell
         FROM (
            SELECT {$expr} AS page_key,
                   TIMESTAMPDIFF(
                       SECOND,
                       laa.occurred_at,
                       LEAD(laa.occurred_at) OVER (PARTITION BY laa.login_audit_id ORDER BY laa.occurred_at, laa.id)
                   ) AS dwell_seconds
            FROM login_audit_activity laa
            JOIN login_audit la ON la.id = laa.login_audit_id
            LEFT JOIN users u ON u.id = la.user_id
            LEFT JOIN roles r ON r.id = u.role_id
            LEFT JOIN customers c ON c.id = la.customer_id
            WHERE laa.event_type = 'page_view' AND {$expr} <> '' AND {$noiseSql} AND {$whereSql}
         ) steps
         WHERE dwell_seconds BETWEEN 3 AND 5400
         GROUP BY page_key",
        array_merge($noiseParams, $params)
    );
    $out = [];
    foreach ($rows as $row) {
        $out[(string)$row['page_key']] = (int)round((float)$row['avg_dwell']);
    }
    return $out;
}

function bakery_login_history_attach_dwell(array $pages, array $dwell): array
{
    foreach ($pages as &$page) {
        $key = (string)($page['module_key'] ?? '');
        $page['avg_dwell'] = $dwell[$key] ?? 0;
    }
    unset($page);
    return $pages;
}

function bakery_login_history_load_failures(PDO $db, array $filters, array $ready, int $limit = 8): array
{
    if (empty($ready['audit'])) {
        return [];
    }
    $failFilters = array_merge($filters, ['outcome' => 'failure', 'session' => '']);
    [$whereSql, $params] = bakery_login_history_clause($failFilters, $ready);
    $limit = max(1, min(20, $limit));
    return bakery_login_history_query(
        $db,
        "SELECT COALESCE(NULLIF(la.principal, ''), 'Unknown') AS principal,
                la.ip_address,
                la.auth_type,
                COUNT(*) AS n,
                MAX(la.login_at) AS last_at
         FROM " . bakery_login_history_from_sql() . "
         WHERE {$whereSql}
         GROUP BY principal, la.ip_address, la.auth_type
         HAVING n >= 2
         ORDER BY n DESC, last_at DESC
         LIMIT {$limit}",
        $params
    );
}

function bakery_login_history_load_role_presence(PDO $db, array $ready, string $today): array
{
    if (empty($ready['audit'])) {
        return [];
    }
    $liveSql = bakery_login_history_live_sql('la');
    $people = bakery_login_history_query(
        $db,
        "SELECT u.id, u.display_name, r.slug AS role_slug, r.name AS role_name,
                MAX(COALESCE(la.last_seen_at, la.login_at)) AS last_seen_at,
                MAX(CASE WHEN {$liveSql} THEN 1 ELSE 0 END) AS is_live,
                SUBSTRING_INDEX(GROUP_CONCAT(la.last_page_path ORDER BY COALESCE(la.last_seen_at, la.login_at) DESC SEPARATOR '\n'), '\n', 1) AS last_page_path
         FROM users u
         JOIN roles r ON r.id = u.role_id
         LEFT JOIN login_audit la ON la.user_id = u.id AND la.outcome = 'success'
         WHERE u.is_active = 1
         GROUP BY u.id, u.display_name, r.slug, r.name
         ORDER BY r.name, u.display_name"
    );
    $groups = [];
    foreach ($people as $person) {
        $slug = (string)$person['role_slug'];
        if ($slug === 'driver_assistant') {
            $slug = 'driver';
        }
        if (!isset($groups[$slug])) {
            $groups[$slug] = [
                'role' => $slug,
                'label' => $person['role_name'] === 'Driver Assistant'
                    ? bakery_login_history_translate('login_history.role_drivers', 'Drivers')
                    : (string)$person['role_name'],
                'live' => [],
                'today' => [],
                'quiet' => [],
                'total' => 0,
            ];
            if ($slug === 'driver') {
                $groups[$slug]['label'] = bakery_login_history_translate('login_history.role_drivers', 'Drivers');
            }
        }
        $groups[$slug]['total']++;
        $meta = bakery_login_history_page_meta((string)($person['last_page_path'] ?? ''));
        $row = [
            'id' => (int)$person['id'],
            'display_name' => (string)$person['display_name'],
            'last_seen_at' => $person['last_seen_at'],
            'is_live' => !empty($person['is_live']),
            'page_label' => $meta['label'],
            'seen_today' => is_string($person['last_seen_at']) && strpos($person['last_seen_at'], $today) === 0,
            'subject' => 's-' . (int)$person['id'],
        ];
        if ($row['is_live']) {
            $groups[$slug]['live'][] = $row;
        } elseif ($row['seen_today']) {
            $groups[$slug]['today'][] = $row;
        } else {
            $groups[$slug]['quiet'][] = $row;
        }
    }
    $order = ['baker', 'driver', 'manager', 'administrator'];
    $out = [];
    foreach ($order as $slug) {
        if (isset($groups[$slug])) {
            $out[] = $groups[$slug];
            unset($groups[$slug]);
        }
    }
    foreach ($groups as $group) {
        $out[] = $group;
    }
    return $out;
}

function bakery_login_history_briefing_lines(array $ctx): array
{
    $lines = [];
    $live = (int)($ctx['live_count'] ?? 0);
    if ($live > 0) {
        $page = (string)($ctx['live_page'] ?? '');
        $lines[] = function_exists('bakery_t')
            ? bakery_t('login_history.brief_live', ['n' => $live, 'page' => $page !== '' ? $page : bakery_t('login_history.unknown_page')])
            : $live . ' people are signed in now.';
    } else {
        $lines[] = bakery_login_history_translate('login_history.brief_quiet', 'Nobody is in an open session right now.');
    }
    if ((int)($ctx['failures'] ?? 0) > 0) {
        $lines[] = function_exists('bakery_t')
            ? bakery_t('login_history.brief_failures', ['n' => (int)$ctx['failures']])
            : (int)$ctx['failures'] . ' failed sign-ins in this window.';
    }
    $peakHour = $ctx['peak_hour'] ?? null;
    if ($peakHour !== null && $peakHour !== '') {
        $lines[] = function_exists('bakery_t')
            ? bakery_t('login_history.brief_peak', ['hour' => bakery_login_history_format_hour((int)$peakHour)])
            : 'Work peaks around ' . bakery_login_history_format_hour((int)$peakHour) . '.';
    }
    $quietRoles = $ctx['quiet_roles'] ?? [];
    if ($quietRoles) {
        $lines[] = function_exists('bakery_t')
            ? bakery_t('login_history.brief_missing', ['roles' => implode(', ', $quietRoles)])
            : 'Not seen today: ' . implode(', ', $quietRoles) . '.';
    }
    $delta = $ctx['delta_signins'] ?? null;
    if (is_array($delta) && ($delta['direction'] ?? 'flat') !== 'flat') {
        $key = $delta['direction'] === 'up' ? 'login_history.brief_up' : 'login_history.brief_down';
        $lines[] = function_exists('bakery_t')
            ? bakery_t($key, ['n' => (int)$delta['pct']])
            : 'Sign-ins are ' . $delta['direction'] . ' ' . (int)$delta['pct'] . '% versus the previous window.';
    }
    return $lines;
}

function bakery_login_history_active_chips(array $filters, array $options): array
{
    $chips = [];
    if (!empty($filters['subject'])) {
        $label = $filters['subject'];
        if (strpos($filters['subject'], 's-') === 0) {
            $id = (int)substr($filters['subject'], 2);
            foreach ($options['users'] ?? [] as $user) {
                if ((int)$user['id'] === $id) {
                    $label = $user['display_name'];
                    break;
                }
            }
        } elseif (strpos($filters['subject'], 'c-') === 0) {
            $id = (int)substr($filters['subject'], 2);
            foreach ($options['customers'] ?? [] as $customer) {
                if ((int)$customer['id'] === $id) {
                    $label = $customer['name'];
                    break;
                }
            }
        }
        $chips[] = [
            'label' => bakery_login_history_translate('login_history.person', 'Person') . ': ' . $label,
            'url' => bakery_login_history_url(['subject' => '', 'user_id' => 0, 'customer_id' => 0]),
        ];
    }
    $simple = [
        'role' => ['login_history.role', 'Role'],
        'auth_type' => ['login_history.auth_type', 'Sign-in type'],
        'session' => ['login_history.session', 'Session state'],
        'device' => ['login_history.device', 'Device'],
        'area' => ['login_history.area', 'Work area'],
        'q' => ['login_history.search', 'Search'],
    ];
    foreach ($simple as $key => $meta) {
        if (($filters[$key] ?? '') === '') {
            continue;
        }
        $value = (string)$filters[$key];
        if ($key === 'area') {
            $value = bakery_login_history_area_label($value);
        } elseif ($key === 'module') {
            $value = bakery_login_history_page_label($value);
        }
        $chips[] = [
            'label' => bakery_login_history_translate($meta[0], $meta[1]) . ': ' . $value,
            'url' => bakery_login_history_url([$key => '']),
        ];
    }
    if (($filters['module'] ?? '') !== '') {
        $chips[] = [
            'label' => bakery_login_history_translate('login_history.page', 'Screen') . ': ' . bakery_login_history_page_label($filters['module']),
            'url' => bakery_login_history_url(['module' => '']),
        ];
    }
    return $chips;
}

function bakery_login_history_csv_headers(): array
{
    return ['login_at', 'name', 'role', 'type', 'outcome', 'duration_seconds', 'device', 'browser', 'ip', 'last_screen', 'location_status'];
}

function bakery_login_history_csv_row(array $row): array
{
    return [
        (string)($row['login_at'] ?? ''),
        (string)($row['display_name'] ?? ''),
        (string)($row['role_label'] ?? ''),
        (string)($row['auth_type'] ?? ''),
        (string)($row['outcome'] ?? ''),
        (int)($row['duration_seconds'] ?? 0),
        (string)($row['device_type'] ?? ''),
        (string)($row['browser'] ?? ''),
        (string)($row['ip_address'] ?? ''),
        (string)($row['page_label'] ?? ''),
        (string)($row['location_status'] ?? ''),
    ];
}

function bakery_login_history_empty_payload(array $filters, array $ready): array
{
    return [
        'filters' => $filters,
        'ready' => $ready,
        'options' => ['users' => [], 'customers' => [], 'roles' => [], 'modules' => []],
        'summary' => [
            'signins' => 0, 'active' => 0, 'users' => 0, 'avg_seconds' => 0,
            'pages' => 0, 'failures' => 0, 'actions' => 0,
        ],
        'live' => [],
        'idle' => [],
        'recent' => [],
        'records' => ['total' => 0, 'rows' => [], 'last_page' => 1, 'page' => 1],
        'daily' => ['rows' => [], 'from' => '', 'until' => '', 'max_signins' => 0, 'max_pages' => 0, 'max_failures' => 0],
        'heatmap' => ['grid' => [], 'max' => 0, 'hourly' => [], 'hourly_max' => 0, 'weekday' => [], 'weekday_max' => 0, 'peak_hour' => null],
        'work_heatmap' => ['grid' => [], 'max' => 0, 'hourly' => [], 'hourly_max' => 0, 'weekday' => [], 'weekday_max' => 0, 'peak_hour' => null],
        'pages' => [],
        'areas' => [],
        'people' => [],
        'devices' => [],
        'actions' => [],
        'transitions' => [],
        'workflows' => [],
        'session_paths' => [],
        'failures' => [],
        'browser_errors' => [],
        'roles' => [],
        'comparison' => null,
        'briefing' => [],
        'chips' => [],
        'investigation' => bakery_login_history_load_investigation_stub(),
    ];
}

function bakery_login_history_load_investigation_stub(): array
{
    return [
        'person' => null,
        'kind' => '',
        'sessions' => 0,
        'failed' => 0,
        'pages' => 0,
        'actions' => 0,
        'last_active' => null,
        'unique_pages' => 0,
        'timeline' => [],
        'top_pages' => [],
        'timeline_total' => 0,
        'timeline_groups' => [],
    ];
}

function bakery_login_history_load(PDO $db, array $filters, array $ready): array
{
    $data = bakery_login_history_empty_payload($filters, $ready);
    $data['options'] = bakery_login_history_load_options($db, $ready);
    if (empty($ready['audit'])) {
        if (!empty($filters['user_id']) || !empty($filters['customer_id'])) {
            $data['investigation'] = bakery_login_history_load_investigation($db, $filters, $ready);
        }
        return $data;
    }

    $view = $filters['view'];
    $data['summary'] = bakery_login_history_load_summary($db, $filters, $ready);
    $data['chips'] = bakery_login_history_active_chips($filters, $data['options']);

    $previous = bakery_login_history_previous_window($filters);
    if ($previous) {
        $prevSummary = bakery_login_history_load_summary($db, array_merge($filters, $previous), $ready);
        $data['comparison'] = [
            'window' => $previous,
            'signins' => bakery_login_history_delta($data['summary']['signins'], $prevSummary['signins']),
            'users' => bakery_login_history_delta($data['summary']['users'], $prevSummary['users']),
            'pages' => bakery_login_history_delta($data['summary']['pages'], $prevSummary['pages']),
            'failures' => bakery_login_history_delta($data['summary']['failures'], $prevSummary['failures']),
            'actions' => bakery_login_history_delta($data['summary']['actions'], $prevSummary['actions']),
        ];
    }

    $data['live'] = bakery_login_history_load_sessions($db, $filters, $ready, $view === 'live' ? 40 : 8, 'live');
    $data['roles'] = bakery_login_history_load_role_presence($db, $ready, $filters['today']);
    if ($view === 'live') {
        $data['idle'] = bakery_login_history_load_sessions($db, $filters, $ready, 30, 'idle');
    }
    if (in_array($view, ['overview', 'time'], true)) {
        $data['daily'] = bakery_login_history_load_daily($db, $filters, $ready);
    }
    $data['work_heatmap'] = bakery_login_history_load_heatmap($db, $filters, $ready, 'pages');
    if ($view === 'time') {
        $data['heatmap'] = bakery_login_history_load_heatmap($db, $filters, $ready, 'login');
    }
    if (in_array($view, ['overview', 'usage'], true)) {
        $pageLimit = $view === 'usage' ? 50 : 8;
        $data['pages'] = bakery_login_history_load_pages($db, $filters, $ready, $pageLimit);
        $data['pages'] = bakery_login_history_attach_dwell($data['pages'], bakery_login_history_load_dwell($db, $filters, $ready));
        $data['people'] = bakery_login_history_load_people($db, $filters, $ready, $view === 'usage' ? 40 : 8);
    }
    if ($view === 'usage') {
        $data['areas'] = bakery_login_history_areas_from_pages($data['pages']);
        $data['pages'] = array_slice($data['pages'], 0, 24);
        $data['devices'] = bakery_login_history_load_devices($db, $filters, $ready);
        $data['actions'] = bakery_login_history_load_actions($db, $filters, $ready);
        $data['transitions'] = bakery_login_history_load_transitions($db, $filters, $ready);
        $data['workflows'] = bakery_login_history_match_workflows($data['transitions']);
        $data['session_paths'] = bakery_login_history_load_session_paths($db, $filters, $ready);
    }
    if (in_array($view, ['overview', 'records'], true) || $filters['export'] === 'csv') {
        $recordFilters = $filters;
        if ($filters['export'] === 'csv') {
            $data['records'] = bakery_login_history_load_records($db, array_merge($recordFilters, ['page' => 1]), $ready, 1000);
        } elseif ($view === 'overview') {
            $recordFilters['page'] = 1;
            $data['recent'] = bakery_login_history_load_sessions($db, $filters, $ready, 6, 'recent');
            $data['failures'] = bakery_login_history_load_failures($db, $filters, $ready);
            $data['browser_errors'] = bakery_client_errors_recent($db, 12);
        } else {
            $data['records'] = bakery_login_history_load_records($db, $recordFilters, $ready, 50);
        }
    }
    if (!empty($filters['user_id']) || !empty($filters['customer_id'])) {
        $data['investigation'] = bakery_login_history_load_investigation($db, $filters, $ready);
        $data['investigation']['timeline_groups'] = bakery_login_history_group_timeline($data['investigation']['timeline']);
    }

    $quietRoles = [];
    foreach ($data['roles'] as $group) {
        if ($group['quiet'] && !$group['live'] && in_array($group['role'], ['baker', 'driver', 'manager'], true)) {
            $quietRoles[] = $group['label'];
        }
    }
    $livePage = '';
    if (!empty($data['live'][0]['page_label'])) {
        $livePage = (string)$data['live'][0]['page_label'];
    } elseif (!empty($data['pages'][0]['label'])) {
        $livePage = (string)$data['pages'][0]['label'];
    }
    $data['briefing'] = bakery_login_history_briefing_lines([
        'live_count' => count($data['live']),
        'live_page' => $livePage,
        'failures' => (int)$data['summary']['failures'],
        'peak_hour' => $data['work_heatmap']['peak_hour'] ?? $data['heatmap']['peak_hour'] ?? null,
        'quiet_roles' => $quietRoles,
        'delta_signins' => $data['comparison']['signins'] ?? null,
    ]);
    return $data;
}
