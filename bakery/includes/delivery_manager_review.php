<?php
/**
 * Read-only delivery review for managers and administrators.
 *
 * Two weeks of driver stops, After photos, and each store's standing route
 * compared with the visits that actually happened. Nothing here writes.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/route_health.php';

/**
 * Same gate as Route photo journey. Logged-out callers get a login redirect.
 *
 * @param array<string, mixed>|null $user
 */
function bakery_delivery_manager_review_access_status(?array $user): int
{
    return bakery_route_photo_journey_access_status($user);
}

/**
 * @return array{from:string,to:string,clamped:bool}
 */
function bakery_delivery_manager_review_parse_range(string $today, ?string $fromRaw, ?string $toRaw): array
{
    return bakery_route_health_parse_range($today, $fromRaw, $toRaw);
}

function bakery_delivery_manager_review_parse_view(string $raw): string
{
    return strtolower(trim($raw)) === 'customers' ? 'customers' : 'drivers';
}

function bakery_delivery_manager_review_parse_filter(string $raw): string
{
    switch (strtolower(trim($raw))) {
        case 'short':
        case 'quiet':
        case 'missed':
        case 'off_driver':
        case 'no_standing':
        case 'extra':
            return strtolower(trim($raw));
        default:
            return 'all';
    }
}

function bakery_delivery_manager_review_parse_query(string $raw): string
{
    $raw = trim($raw);
    if (strlen($raw) > 80) {
        $raw = substr($raw, 0, 80);
    }
    return $raw;
}

function bakery_delivery_manager_review_positive_id($raw): int
{
    $id = (int)$raw;
    return $id > 0 ? $id : 0;
}

/**
 * @param array<string, mixed> $params
 */
function bakery_delivery_manager_review_href(array $params): string
{
    $clean = [];
    $view = bakery_delivery_manager_review_parse_view((string)($params['view'] ?? 'drivers'));
    $clean['view'] = $view;
    $from = trim((string)($params['from'] ?? ''));
    $to = trim((string)($params['to'] ?? ''));
    if ($from !== '') {
        $clean['from'] = $from;
    }
    if ($to !== '') {
        $clean['to'] = $to;
    }
    if ($view === 'drivers') {
        $driver = (int)($params['driver'] ?? 0);
        if ($driver > 0) {
            $clean['driver'] = $driver;
        }
    } else {
        $customer = (int)($params['customer'] ?? 0);
        if ($customer > 0) {
            $clean['customer'] = $customer;
        }
        $filter = bakery_delivery_manager_review_parse_filter((string)($params['filter'] ?? 'all'));
        if ($filter !== 'all') {
            $clean['filter'] = $filter;
        }
        $query = bakery_delivery_manager_review_parse_query((string)($params['q'] ?? ''));
        if ($query !== '') {
            $clean['q'] = $query;
        }
    }
    $base = defined('BASE_URL') ? (string)BASE_URL : '';
    return $base . 'delivery_manager_review.php?' . http_build_query($clean);
}

function bakery_delivery_manager_review_stop_bucket(string $status): string
{
    switch ($status) {
        case 'delivered':
            return 'delivered';
        case 'cancelled':
            return 'cancelled';
        case 'failed':
            return 'failed';
        default:
            return 'open';
    }
}

function bakery_delivery_manager_review_status_key(string $status): string
{
    switch ($status) {
        case 'delivered':
            return 'delivery_review.status_delivered';
        case 'failed':
            return 'delivery_review.status_failed';
        case 'cancelled':
            return 'delivery_review.status_cancelled';
        case 'not_routed':
            return 'delivery_review.status_not_routed';
        default:
            return 'delivery_review.status_open';
    }
}

function bakery_delivery_manager_review_why_key(string $reason): string
{
    switch ($reason) {
        case 'failed':
            return 'delivery_review.why_failed';
        case 'missing_after':
            return 'delivery_review.why_missing_after';
        default:
            return 'delivery_review.why_open';
    }
}

/**
 * @param array<string, int|float|string> $customer
 * @return array<string, bool>
 */
function bakery_delivery_manager_review_customer_flags(array $customer): array
{
    $expected = (int)($customer['expected'] ?? 0);
    $routed = (int)($customer['routed'] ?? 0);
    $delivered = (int)($customer['delivered'] ?? 0);
    $open = (int)($customer['open'] ?? 0);
    $failed = (int)($customer['failed'] ?? 0);
    $off = (int)($customer['off_count'] ?? 0);
    return [
        'quiet' => $expected > 0 && $routed === 0,
        'missed' => ($open + $failed) > 0,
        'short' => $expected > 0 && $delivered < $expected,
        'no_standing' => $expected === 0 && $routed > 0,
        'off_driver' => $off > 0,
        'extra' => $expected > 0 && $delivered > $expected,
        'on_track' => $expected > 0 && $delivered >= $expected && $open === 0 && $failed === 0,
    ];
}

/**
 * @param array<string, int|float|string> $customer
 * @return array{key:string,params:array<string,int>}
 */
function bakery_delivery_manager_review_customer_reading(array $customer): array
{
    $expected = (int)($customer['expected'] ?? 0);
    $routed = (int)($customer['routed'] ?? 0);
    $delivered = (int)($customer['delivered'] ?? 0);
    $open = (int)($customer['open'] ?? 0);
    $failed = (int)($customer['failed'] ?? 0);
    $cancelled = (int)($customer['cancelled'] ?? 0);
    $params = [
        'expected' => $expected,
        'routed' => $routed,
        'delivered' => $delivered,
        'open' => $open,
        'failed' => $failed,
        'cancelled' => $cancelled,
    ];
    $flags = bakery_delivery_manager_review_customer_flags($customer);
    if (!empty($flags['quiet'])) {
        $key = 'delivery_review.reading_quiet';
    } elseif ($expected === 0 && $routed > 0 && !empty($flags['missed'])) {
        $key = 'delivery_review.reading_no_standing_missed';
    } elseif ($expected === 0 && $routed > 0) {
        $key = 'delivery_review.reading_no_standing';
    } elseif (!empty($flags['missed'])) {
        $key = 'delivery_review.reading_missed';
    } elseif (!empty($flags['short']) && $cancelled > 0) {
        $key = 'delivery_review.reading_short_cancelled';
    } elseif (!empty($flags['short'])) {
        $key = 'delivery_review.reading_short';
    } elseif (!empty($flags['extra'])) {
        $key = 'delivery_review.reading_extra';
    } else {
        $key = 'delivery_review.reading_on_track';
    }
    return ['key' => $key, 'params' => $params];
}

function bakery_delivery_manager_review_customer_rank(array $customer): int
{
    $flags = isset($customer['flags']) && is_array($customer['flags'])
        ? $customer['flags']
        : bakery_delivery_manager_review_customer_flags($customer);
    if (!empty($flags['quiet'])) {
        return 0;
    }
    if (!empty($flags['missed'])) {
        return 1;
    }
    if (!empty($flags['short'])) {
        return 2;
    }
    if (!empty($flags['off_driver'])) {
        return 3;
    }
    if (!empty($flags['no_standing'])) {
        return 4;
    }
    if (!empty($flags['extra'])) {
        return 5;
    }
    return 6;
}

function bakery_delivery_manager_review_customer_haystack(array $customer): string
{
    $parts = [
        (string)($customer['name'] ?? ''),
        (string)($customer['zone'] ?? ''),
    ];
    foreach ($customer['pattern'] ?? [] as $day) {
        if (!is_array($day)) {
            continue;
        }
        foreach ($day['drivers'] ?? [] as $driver) {
            $parts[] = (string)$driver;
        }
    }
    return strtolower(implode(' ', $parts));
}

function bakery_delivery_manager_review_customer_search_match(array $customer, string $query): bool
{
    $query = strtolower(bakery_delivery_manager_review_parse_query($query));
    if ($query === '') {
        return true;
    }
    return strpos(bakery_delivery_manager_review_customer_haystack($customer), $query) !== false;
}

function bakery_delivery_manager_review_customer_matches(array $customer, string $filter): bool
{
    $filter = bakery_delivery_manager_review_parse_filter($filter);
    if ($filter === 'all') {
        return true;
    }
    $flags = isset($customer['flags']) && is_array($customer['flags'])
        ? $customer['flags']
        : bakery_delivery_manager_review_customer_flags($customer);
    return !empty($flags[$filter]);
}

/**
 * @param array<int, array<string, mixed>> $customers
 * @return array<string, int>
 */
function bakery_delivery_manager_review_filter_counts(array $customers): array
{
    $counts = [
        'all' => count($customers),
        'short' => 0,
        'quiet' => 0,
        'missed' => 0,
        'off_driver' => 0,
        'no_standing' => 0,
        'extra' => 0,
    ];
    foreach ($customers as $customer) {
        if (!is_array($customer)) {
            continue;
        }
        $flags = isset($customer['flags']) && is_array($customer['flags'])
            ? $customer['flags']
            : bakery_delivery_manager_review_customer_flags($customer);
        foreach (['short', 'quiet', 'missed', 'off_driver', 'no_standing', 'extra'] as $flag) {
            if (!empty($flags[$flag])) {
                $counts[$flag]++;
            }
        }
    }
    return $counts;
}

/**
 * @param list<array{dow:int,drivers:list<string>}> $pattern
 */
function bakery_delivery_manager_review_pattern_label(array $pattern): string
{
    $names = function_exists('bakery_day_names') ? bakery_day_names(true) : [];
    $parts = [];
    foreach ($pattern as $day) {
        if (!is_array($day)) {
            continue;
        }
        $dow = (int)($day['dow'] ?? 0);
        $label = trim((string)($names[$dow] ?? ''));
        if ($label === '') {
            $label = (string)$dow;
        }
        $drivers = [];
        foreach ($day['drivers'] ?? [] as $driver) {
            $driver = trim((string)$driver);
            if ($driver !== '' && !in_array($driver, $drivers, true)) {
                $drivers[] = $driver;
            }
        }
        if ($drivers === []) {
            continue;
        }
        $parts[] = $label . ' ' . implode(', ', $drivers);
    }
    if ($parts === []) {
        return bakery_t('delivery_review.no_standing_pattern');
    }
    return implode(', ', $parts);
}

/**
 * @param array<string, mixed> $driver
 * @return list<array{key:string,params:array<string,int|string>}>
 */
function bakery_delivery_manager_review_driver_attention(array $driver): array
{
    $lines = [];
    $missing = (int)($driver['missing_after'] ?? 0);
    if ($missing > 0) {
        $lines[] = ['key' => 'delivery_review.attention_missing_after', 'params' => ['count' => $missing]];
    }
    $failed = (int)($driver['failed'] ?? 0);
    if ($failed > 0) {
        $lines[] = ['key' => 'delivery_review.attention_failed', 'params' => ['count' => $failed]];
    }
    $open = (int)($driver['open'] ?? 0);
    if ($open > 0) {
        $lines[] = ['key' => 'delivery_review.attention_open', 'params' => ['count' => $open]];
    }
    $quiet = (int)($driver['quiet_days'] ?? 0);
    if ($quiet > 0) {
        $lines[] = ['key' => 'delivery_review.attention_quiet_days', 'params' => ['count' => $quiet]];
    }
    $bulk = (int)($driver['bulk_days'] ?? 0);
    if ($bulk > 0) {
        $lines[] = ['key' => 'delivery_review.attention_bulk', 'params' => ['count' => $bulk]];
    }
    $repeat = is_array($driver['repeat'] ?? null) ? $driver['repeat'] : [];
    if ($repeat !== []) {
        $stores = [];
        foreach ($repeat as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string)($row['customer_name'] ?? ''));
            if ($name !== '') {
                $stores[] = $name;
            }
        }
        if ($stores !== []) {
            $lines[] = [
                'key' => 'delivery_review.attention_repeat',
                'params' => [
                    'count' => BAKERY_ROUTE_HEALTH_REPEAT_SKIP_DAYS,
                    'stores' => implode(', ', $stores),
                ],
            ];
        }
    }
    if ($lines === []) {
        $lines[] = ['key' => 'delivery_review.attention_clear', 'params' => []];
    }
    return $lines;
}

function bakery_delivery_manager_review_display_date(string $date): string
{
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        return $date;
    }
    $names = function_exists('bakery_day_names') ? bakery_day_names(true) : [];
    $dow = (int)$parsed->format('N');
    $day = trim((string)($names[$dow] ?? ''));
    if ($day === '') {
        $day = $parsed->format('D');
    }
    return $day . ' ' . $parsed->format('M j');
}

function bakery_delivery_manager_review_yes_no(?bool $value, bool $ready): string
{
    if (!$ready || $value === null) {
        return bakery_t('delivery_review.not_recorded');
    }
    return bakery_t($value ? 'delivery_review.yes' : 'delivery_review.no');
}

/**
 * Prefer an After photo. Links stay on driver_photo.php.
 *
 * @param array<int, array<string, mixed>> $photos
 */
function bakery_delivery_manager_review_photo_url(array $photos): string
{
    $fallback = null;
    $chosen = null;
    foreach ($photos as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        $path = trim((string)($photo['file_path'] ?? ''));
        if ($path === '' || !function_exists('bakery_driver_photo_relative_path') || bakery_driver_photo_relative_path($path) === '') {
            continue;
        }
        if (strcasecmp(bakery_route_stop_photo_type($photo), 'After') === 0) {
            $chosen = $photo;
            break;
        }
        if ($fallback === null) {
            $fallback = $photo;
        }
    }
    $use = is_array($chosen) ? $chosen : $fallback;
    if (!is_array($use) || !function_exists('bakery_driver_photo_url')) {
        return '';
    }
    $url = bakery_driver_photo_url((string)($use['file_path'] ?? ''));
    if ($url === '' || strpos($url, 'driver_photo.php') === false || strpos($url, 'uploads/driver_photos') !== false) {
        return '';
    }
    return $url;
}

/**
 * @param array<int, array<string, mixed>> $photos
 */
function bakery_delivery_manager_review_arrival(array $photos): ?string
{
    $arrival = null;
    foreach ($photos as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        $at = bakery_route_stop_time_or_null($photo['created_at'] ?? null);
        if ($at === null) {
            continue;
        }
        if ($arrival === null || (strtotime($at) ?: 0) < (strtotime($arrival) ?: 0)) {
            $arrival = $at;
        }
    }
    return $arrival;
}

/**
 * @return array<string, mixed>
 */
function bakery_delivery_manager_review_blank(string $from, string $to): array
{
    return [
        'from' => $from,
        'to' => $to,
        'drivers' => [],
        'customers' => [],
        'needs' => [],
        'totals' => [
            'routed' => 0,
            'delivered' => 0,
            'open' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'with_after' => 0,
            'short_customers' => 0,
            'quiet_customers' => 0,
            'missed_customers' => 0,
            'off_customers' => 0,
            'no_standing_customers' => 0,
            'extra_customers' => 0,
        ],
        'clock_ready' => false,
        'close_ready' => false,
    ];
}

/**
 * @return array<string, mixed>
 */
function bakery_delivery_manager_review_blank_customer(int $customerId, string $name, string $zone, bool $active): array
{
    return [
        'customer_id' => $customerId,
        'name' => $name,
        'zone' => $zone,
        'active' => $active,
        'pattern' => [],
        'expected' => 0,
        'routed' => 0,
        'delivered' => 0,
        'open' => 0,
        'failed' => 0,
        'cancelled' => 0,
        'with_after' => 0,
        'missing_after' => 0,
        'off_count' => 0,
        'last_delivered' => null,
        'visits' => [],
        'flags' => [],
        'reading' => ['key' => 'delivery_review.reading_on_track', 'params' => []],
    ];
}

/**
 * @return array<string, mixed>
 */
function bakery_delivery_manager_review_blank_day(string $date, bool $clockReady, bool $closeReady, array $clockIns, array $closed, int $driverId): array
{
    $key = $driverId . '|' . $date;
    return [
        'date' => $date,
        'standing_stops' => 0,
        'stops' => 0,
        'delivered' => 0,
        'open' => 0,
        'failed' => 0,
        'cancelled' => 0,
        'delivered_with_after' => 0,
        'first_arrival' => null,
        'last_close' => null,
        'clocked_in' => $clockReady ? !empty($clockIns[$key]) : null,
        'route_closed' => $closeReady ? !empty($closed[$key]) : null,
        'bulk_stores' => [],
    ];
}

/**
 * @param array<int, array<string, mixed>> $photos
 * @return list<array<string, mixed>>
 */
function bakery_delivery_manager_review_photos_for_stop(array $photos, int $driverId): array
{
    $mine = [];
    $all = [];
    foreach ($photos as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        $all[] = $photo;
        if ((int)($photo['driver_id'] ?? 0) === $driverId) {
            $mine[] = $photo;
        }
    }
    return $mine !== [] ? $mine : $all;
}

/**
 * @return array<string, mixed>
 */
function bakery_delivery_manager_review_load(PDO $db, string $from, string $to): array
{
    $from = bakery_route_health_valid_date($from) ?? '';
    $to = bakery_route_health_valid_date($to) ?? '';
    if ($from === '' || $to === '') {
        return bakery_delivery_manager_review_blank($from, $to);
    }
    if ($from > $to) {
        $swap = $from;
        $from = $to;
        $to = $swap;
    }
    $blank = bakery_delivery_manager_review_blank($from, $to);
    $dates = bakery_route_health_dates($from, $to);
    $origin = function_exists('bakery_sfb_ops_origin_clause') ? bakery_sfb_ops_origin_clause('c', $db) : '';
    $confirmedSql = function_exists('column_exists') && column_exists($db, 'daily_orders', 'delivery_confirmed_at')
        ? 'do.delivery_confirmed_at'
        : 'NULL';
    $notesSql = function_exists('column_exists') && column_exists($db, 'daily_order_assignments', 'notes')
        ? 'doa.notes'
        : "''";
    $zoneSql = function_exists('column_exists') && column_exists($db, 'customers', 'zone')
        ? 'c.zone'
        : "''";

    $stopRows = [];
    if (function_exists('table_exists') && table_exists($db, 'daily_order_assignments') && table_exists($db, 'daily_orders')) {
        $stmt = $db->prepare(
            "SELECT doa.driver_id, d.name AS driver_name, doa.delivery_date, doa.route_order,
                    doa.delivery_status, doa.actual_delivery_time, do.status AS order_status,
                    {$confirmedSql} AS delivery_confirmed_at, {$notesSql} AS notes,
                    c.id AS customer_id, c.name AS customer_name, {$zoneSql} AS customer_zone,
                    c.is_active AS customer_active
             FROM daily_order_assignments doa
             INNER JOIN drivers d ON d.id = doa.driver_id
             INNER JOIN daily_orders do ON do.id = doa.daily_order_id AND do.order_date = doa.delivery_date
             INNER JOIN customers c ON c.id = do.customer_id
             WHERE doa.delivery_date >= ? AND doa.delivery_date <= ?
             {$origin}
             ORDER BY doa.delivery_date, d.name, doa.route_order, c.name"
        );
        $stmt->execute([$from, $to]);
        $stopRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $photosByStop = [];
    if (function_exists('table_exists') && table_exists($db, 'driver_photos')) {
        $photoStmt = $db->prepare(
            'SELECT id, driver_id, customer_id, delivery_date, filename, file_path, photo_type, created_at
             FROM driver_photos
             WHERE delivery_date >= ? AND delivery_date <= ?
             ORDER BY created_at, id'
        );
        $photoStmt->execute([$from, $to]);
        foreach ($photoStmt->fetchAll(PDO::FETCH_ASSOC) as $photo) {
            $key = (int)$photo['customer_id'] . '|' . (string)$photo['delivery_date'];
            $photosByStop[$key][] = $photo;
        }
    }

    $clockReady = function_exists('table_exists') && table_exists($db, 'time_clock_punches') && table_exists($db, 'users');
    $clockIns = [];
    if ($clockReady) {
        $end = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
        $clockStmt = $db->prepare(
            'SELECT u.driver_id, p.clock_in_at
             FROM time_clock_punches p
             INNER JOIN users u ON u.id = p.user_id
             WHERE u.driver_id IS NOT NULL AND u.driver_id > 0
               AND p.clock_in_at >= ? AND p.clock_in_at < ?'
        );
        $clockStmt->execute([$from . ' 00:00:00', $end . ' 00:00:00']);
        foreach ($clockStmt->fetchAll(PDO::FETCH_ASSOC) as $punch) {
            $day = substr(trim((string)($punch['clock_in_at'] ?? '')), 0, 10);
            if ($day === '') {
                continue;
            }
            $clockIns[(int)$punch['driver_id'] . '|' . $day] = true;
        }
    }

    $closeReady = function_exists('table_exists') && table_exists($db, 'driver_loads');
    $closed = [];
    if ($closeReady) {
        $loadStmt = $db->prepare(
            'SELECT driver_id, delivery_date, status
             FROM driver_loads
             WHERE delivery_date >= ? AND delivery_date <= ?'
        );
        $loadStmt->execute([$from, $to]);
        foreach ($loadStmt->fetchAll(PDO::FETCH_ASSOC) as $load) {
            $closed[(int)$load['driver_id'] . '|' . (string)$load['delivery_date']] = (string)$load['status'] === 'reconciled';
        }
    }

    $standingRows = [];
    if (function_exists('table_exists') && table_exists($db, 'standing_routes')) {
        $standStmt = $db->query(
            "SELECT sr.day_of_week, sr.driver_id, d.name AS driver_name,
                    c.id AS customer_id, c.name AS customer_name, {$zoneSql} AS customer_zone
             FROM standing_routes sr
             INNER JOIN drivers d ON d.id = sr.driver_id
             INNER JOIN customers c ON c.id = sr.customer_id AND c.is_active = 1
             WHERE 1 = 1 {$origin}"
        );
        $standingRows = $standStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $customers = [];
    $patternMap = [];
    $standingOnDate = [];
    $driverStanding = [];
    foreach ($standingRows as $route) {
        if (!is_array($route)) {
            continue;
        }
        $customerId = (int)$route['customer_id'];
        $driverId = (int)$route['driver_id'];
        if ($customerId <= 0 || $driverId <= 0) {
            continue;
        }
        $stored = (int)$route['day_of_week'];
        $dow = $stored === 0 ? 7 : $stored;
        $driverName = (string)$route['driver_name'];
        $customerName = (string)$route['customer_name'];
        $zone = trim((string)($route['customer_zone'] ?? ''));
        if (!isset($customers[$customerId])) {
            $customers[$customerId] = bakery_delivery_manager_review_blank_customer($customerId, $customerName, $zone, true);
        } elseif ($customers[$customerId]['zone'] === '' && $zone !== '') {
            $customers[$customerId]['zone'] = $zone;
        }
        $patternMap[$customerId][$dow][$driverName] = true;
        foreach ($dates as $date) {
            $weekday = function_exists('bakery_standing_day_from_date')
                ? (int)bakery_standing_day_from_date($date)
                : (int)(new DateTimeImmutable($date))->format('N');
            $match = function_exists('bakery_standing_day_match_values')
                ? bakery_standing_day_match_values($weekday)
                : [$weekday];
            if (!in_array($stored, array_map('intval', $match), true)) {
                continue;
            }
            $standingOnDate[$customerId][$date][$driverId] = $driverName;
            $driverStanding[$driverId][$date][$customerId] = $driverName;
        }
    }

    $drivers = [];
    $days = [];
    $stopHad = [];
    $openCounts = [];
    $deliveredForBulk = [];
    $ensureDriver = static function (int $driverId, string $driverName) use (&$drivers): void {
        if ($driverId <= 0 || isset($drivers[$driverId])) {
            if ($driverId > 0 && $driverName !== '' && ($drivers[$driverId]['driver_name'] ?? '') === '') {
                $drivers[$driverId]['driver_name'] = $driverName;
            }
            return;
        }
        $drivers[$driverId] = [
            'driver_id' => $driverId,
            'driver_name' => $driverName,
            'standing_stops' => 0,
            'stops' => 0,
            'delivered' => 0,
            'open' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'delivered_with_after' => 0,
            'missing_after' => 0,
            'quiet_days' => 0,
            'days_worked' => 0,
            'clocked_days' => 0,
            'closed_days' => 0,
            'listed_days' => 0,
            'bulk_days' => 0,
            'attention_score' => 0,
            'repeat' => [],
            'day_rows' => [],
            'stop_rows' => [],
        ];
    };
    $ensureDay = static function (int $driverId, string $driverName, string $date) use (&$days, &$ensureDriver, $clockReady, $closeReady, $clockIns, $closed): array {
        $ensureDriver($driverId, $driverName);
        if (!isset($days[$driverId][$date])) {
            $days[$driverId][$date] = bakery_delivery_manager_review_blank_day($date, $clockReady, $closeReady, $clockIns, $closed, $driverId);
        }
        return $days[$driverId][$date];
    };

    foreach ($driverStanding as $driverId => $byDate) {
        foreach ($byDate as $date => $customerIds) {
            $name = '';
            foreach ($customerIds as $standingName) {
                $name = (string)$standingName;
                break;
            }
            $ensureDay((int)$driverId, $name, (string)$date);
            $days[$driverId][$date]['standing_stops'] = count($customerIds);
        }
    }

    foreach ($stopRows as $stop) {
        if (!is_array($stop)) {
            continue;
        }
        $driverId = (int)$stop['driver_id'];
        $customerId = (int)$stop['customer_id'];
        $date = (string)$stop['delivery_date'];
        $driverName = (string)$stop['driver_name'];
        $customerName = (string)$stop['customer_name'];
        $zone = trim((string)($stop['customer_zone'] ?? ''));
        $active = (int)($stop['customer_active'] ?? 1) === 1;
        if (!isset($customers[$customerId])) {
            $customers[$customerId] = bakery_delivery_manager_review_blank_customer($customerId, $customerName, $zone, $active);
        } else {
            if ($customers[$customerId]['zone'] === '' && $zone !== '') {
                $customers[$customerId]['zone'] = $zone;
            }
            if (!$active) {
                $customers[$customerId]['active'] = false;
            }
        }
        $bucket = bakery_delivery_manager_review_stop_bucket(bakery_route_health_stop_status($stop));
        $photos = bakery_delivery_manager_review_photos_for_stop($photosByStop[$customerId . '|' . $date] ?? [], $driverId);
        $hasAfter = $bucket === 'delivered' && bakery_route_stop_has_after_photo($photos);
        $photoUrl = bakery_delivery_manager_review_photo_url($photos);
        $arrival = bakery_delivery_manager_review_arrival($photos);
        $closedAt = bakery_route_health_closed_at($date, $stop);
        $note = trim((string)($stop['notes'] ?? ''));
        if (strlen($note) > 500) {
            $note = substr($note, 0, 500);
        }
        $standingDrivers = $standingOnDate[$customerId][$date] ?? [];
        $off = $standingDrivers !== [] && !isset($standingDrivers[$driverId]);
        $ensureDay($driverId, $driverName, $date);
        $days[$driverId][$date]['stops']++;
        $days[$driverId][$date][$bucket]++;
        if ($hasAfter) {
            $days[$driverId][$date]['delivered_with_after']++;
        }
        if ($arrival !== null) {
            $current = $days[$driverId][$date]['first_arrival'];
            if ($current === null || (strtotime($arrival) ?: 0) < (strtotime((string)$current) ?: 0)) {
                $days[$driverId][$date]['first_arrival'] = $arrival;
            }
        }
        if ($closedAt !== null) {
            $currentClose = $days[$driverId][$date]['last_close'];
            if ($currentClose === null || (strtotime($closedAt) ?: 0) > (strtotime((string)$currentClose) ?: 0)) {
                $days[$driverId][$date]['last_close'] = $closedAt;
            }
        }
        $customers[$customerId]['routed']++;
        $customers[$customerId][$bucket]++;
        if ($bucket === 'delivered') {
            if ($hasAfter) {
                $customers[$customerId]['with_after']++;
            } else {
                $customers[$customerId]['missing_after']++;
            }
            $last = (string)($customers[$customerId]['last_delivered'] ?? '');
            if ($last === '' || $date > $last) {
                $customers[$customerId]['last_delivered'] = $date;
            }
        }
        if ($off) {
            $customers[$customerId]['off_count']++;
        }
        if ($bucket === 'open' || $bucket === 'failed') {
            if (!isset($openCounts[$driverId][$customerId])) {
                $openCounts[$driverId][$customerId] = [
                    'customer_id' => $customerId,
                    'customer_name' => $customerName,
                    'times' => 0,
                ];
            }
            $openCounts[$driverId][$customerId]['times']++;
        }
        if ($bucket === 'delivered') {
            $deliveredForBulk[$driverId . '|' . $date][] = [
                'customer_name' => $customerName,
                'badge' => 'pod',
                'delivered_at' => $closedAt,
            ];
        }
        $standingList = [];
        foreach ($standingDrivers as $standingId => $standingName) {
            $standingList[] = ['driver_id' => (int)$standingId, 'driver_name' => (string)$standingName];
        }
        $customers[$customerId]['visits'][] = [
            'date' => $date,
            'standing_drivers' => $standingList,
            'actual_driver_id' => $driverId,
            'actual_driver_name' => $driverName,
            'status' => $bucket,
            'off_driver' => $off,
            'extra' => $standingDrivers === [],
            'has_after' => $hasAfter,
            'photo_url' => $photoUrl,
            'arrival_at' => $arrival,
            'closed_at' => $closedAt,
            'note' => $note,
        ];
        $stopHad[$customerId][$date] = true;
        $drivers[$driverId]['stop_rows'][] = [
            'date' => $date,
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'route_order' => (int)($stop['route_order'] ?? 0),
            'status' => $bucket,
            'has_after' => $hasAfter,
            'photo_url' => $photoUrl,
            'arrival_at' => $arrival,
            'closed_at' => $closedAt,
            'note' => $note,
            'bulk' => false,
        ];
    }

    foreach ($standingOnDate as $customerId => $byDate) {
        foreach ($byDate as $date => $standingDrivers) {
            if (!empty($stopHad[$customerId][$date])) {
                continue;
            }
            $standingList = [];
            foreach ($standingDrivers as $standingId => $standingName) {
                $standingList[] = ['driver_id' => (int)$standingId, 'driver_name' => (string)$standingName];
            }
            $customers[$customerId]['visits'][] = [
                'date' => (string)$date,
                'standing_drivers' => $standingList,
                'actual_driver_id' => 0,
                'actual_driver_name' => '',
                'status' => 'not_routed',
                'off_driver' => false,
                'extra' => false,
                'has_after' => false,
                'photo_url' => '',
                'arrival_at' => null,
                'closed_at' => null,
                'note' => '',
            ];
        }
    }

    $needs = [];
    foreach ($drivers as $driverId => &$driver) {
        $driverDays = $days[$driverId] ?? [];
        ksort($driverDays);
        foreach ($deliveredForBulk as $key => $group) {
            [$bulkDriver, $bulkDate] = explode('|', (string)$key, 2);
            if ((int)$bulkDriver !== (int)$driverId || !isset($driverDays[$bulkDate])) {
                continue;
            }
            $names = bakery_route_stop_bulk_stamp_names($group);
            if ($names === []) {
                continue;
            }
            $driverDays[$bulkDate]['bulk_stores'] = $names;
            $driver['bulk_days']++;
            foreach ($driver['stop_rows'] as &$stopRow) {
                if ((string)$stopRow['date'] === $bulkDate && in_array((string)$stopRow['customer_name'], $names, true)) {
                    $stopRow['bulk'] = true;
                }
            }
            unset($stopRow);
        }
        foreach ($driver['stop_rows'] as $stopRow) {
            $reason = '';
            if ((string)$stopRow['status'] === 'failed') {
                $reason = 'failed';
            } elseif ((string)$stopRow['status'] === 'open') {
                $reason = 'open';
            } elseif ((string)$stopRow['status'] === 'delivered' && empty($stopRow['has_after'])) {
                $reason = 'missing_after';
            }
            if ($reason === '') {
                continue;
            }
            $needs[] = [
                'driver_id' => (int)$driverId,
                'driver_name' => (string)$driver['driver_name'],
                'date' => (string)$stopRow['date'],
                'customer_id' => (int)$stopRow['customer_id'],
                'customer_name' => (string)$stopRow['customer_name'],
                'status' => (string)$stopRow['status'],
                'reason' => $reason,
                'photo_url' => (string)$stopRow['photo_url'],
                'note' => (string)$stopRow['note'],
                'closed_at' => $stopRow['closed_at'],
                'arrival_at' => $stopRow['arrival_at'],
            ];
        }
        $repeat = [];
        foreach ($openCounts[$driverId] ?? [] as $row) {
            if ((int)$row['times'] < BAKERY_ROUTE_HEALTH_REPEAT_SKIP_DAYS) {
                continue;
            }
            $repeat[] = $row;
        }
        usort($repeat, static function (array $a, array $b): int {
            $times = ((int)$b['times']) <=> ((int)$a['times']);
            if ($times !== 0) {
                return $times;
            }
            return strcasecmp((string)$a['customer_name'], (string)$b['customer_name']);
        });
        $driver['repeat'] = $repeat;
        foreach ($driverDays as $day) {
            $driver['standing_stops'] += (int)$day['standing_stops'];
            $driver['stops'] += (int)$day['stops'];
            $driver['delivered'] += (int)$day['delivered'];
            $driver['open'] += (int)$day['open'];
            $driver['failed'] += (int)$day['failed'];
            $driver['cancelled'] += (int)$day['cancelled'];
            $driver['delivered_with_after'] += (int)$day['delivered_with_after'];
            $driver['listed_days']++;
            if ((int)$day['stops'] > 0) {
                $driver['days_worked']++;
            }
            if ((int)$day['standing_stops'] > 0 && (int)$day['stops'] === 0) {
                $driver['quiet_days']++;
            }
            if (!empty($day['clocked_in'])) {
                $driver['clocked_days']++;
            }
            if (!empty($day['route_closed'])) {
                $driver['closed_days']++;
            }
        }
        $driver['missing_after'] = max(0, (int)$driver['delivered'] - (int)$driver['delivered_with_after']);
        $driver['day_rows'] = array_values($driverDays);
        usort($driver['stop_rows'], static function (array $a, array $b): int {
            $date = strcmp((string)$a['date'], (string)$b['date']);
            if ($date !== 0) {
                return $date;
            }
            $order = ((int)$a['route_order']) <=> ((int)$b['route_order']);
            if ($order !== 0) {
                return $order;
            }
            return strcasecmp((string)$a['customer_name'], (string)$b['customer_name']);
        });
        $driver['attention_score'] = (int)$driver['missing_after']
            + ((int)$driver['failed'] * 2)
            + (int)$driver['open']
            + ((int)$driver['quiet_days'] * 2)
            + (int)$driver['bulk_days']
            + count($driver['repeat']);
    }
    unset($driver);

    $driverList = array_values($drivers);
    usort($driverList, static function (array $a, array $b): int {
        $score = ((int)$b['attention_score']) <=> ((int)$a['attention_score']);
        if ($score !== 0) {
            return $score;
        }
        return strcasecmp((string)$a['driver_name'], (string)$b['driver_name']);
    });

    usort($needs, static function (array $a, array $b): int {
        $date = strcmp((string)$b['date'], (string)$a['date']);
        if ($date !== 0) {
            return $date;
        }
        $driver = strcasecmp((string)$a['driver_name'], (string)$b['driver_name']);
        if ($driver !== 0) {
            return $driver;
        }
        return strcasecmp((string)$a['customer_name'], (string)$b['customer_name']);
    });

    $totals = $blank['totals'];
    $customerList = [];
    foreach ($customers as $customerId => $customer) {
        $expectedDates = array_keys($standingOnDate[$customerId] ?? []);
        $customer['expected'] = count($expectedDates);
        $pattern = [];
        $daysForPattern = $patternMap[$customerId] ?? [];
        ksort($daysForPattern);
        foreach ($daysForPattern as $dow => $driverNames) {
            $names = array_keys($driverNames);
            sort($names, SORT_FLAG_CASE | SORT_STRING);
            $pattern[] = ['dow' => (int)$dow, 'drivers' => $names];
        }
        $customer['pattern'] = $pattern;
        $customer['flags'] = bakery_delivery_manager_review_customer_flags($customer);
        $customer['reading'] = bakery_delivery_manager_review_customer_reading($customer);
        usort($customer['visits'], static function (array $a, array $b): int {
            $date = strcmp((string)$a['date'], (string)$b['date']);
            if ($date !== 0) {
                return $date;
            }
            return strcasecmp((string)$a['actual_driver_name'], (string)$b['actual_driver_name']);
        });
        $totals['routed'] += (int)$customer['routed'];
        $totals['delivered'] += (int)$customer['delivered'];
        $totals['open'] += (int)$customer['open'];
        $totals['failed'] += (int)$customer['failed'];
        $totals['cancelled'] += (int)$customer['cancelled'];
        $totals['with_after'] += (int)$customer['with_after'];
        if (!empty($customer['flags']['short'])) {
            $totals['short_customers']++;
        }
        if (!empty($customer['flags']['quiet'])) {
            $totals['quiet_customers']++;
        }
        if (!empty($customer['flags']['missed'])) {
            $totals['missed_customers']++;
        }
        if (!empty($customer['flags']['off_driver'])) {
            $totals['off_customers']++;
        }
        if (!empty($customer['flags']['no_standing'])) {
            $totals['no_standing_customers']++;
        }
        if (!empty($customer['flags']['extra'])) {
            $totals['extra_customers']++;
        }
        $customerList[] = $customer;
    }
    usort($customerList, static function (array $a, array $b): int {
        $rank = bakery_delivery_manager_review_customer_rank($a) <=> bakery_delivery_manager_review_customer_rank($b);
        if ($rank !== 0) {
            return $rank;
        }
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });

    return [
        'from' => $from,
        'to' => $to,
        'drivers' => $driverList,
        'customers' => $customerList,
        'needs' => $needs,
        'totals' => $totals,
        'clock_ready' => $clockReady,
        'close_ready' => $closeReady,
    ];
}
