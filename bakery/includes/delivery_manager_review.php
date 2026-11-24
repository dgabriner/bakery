<?php
/**
 * Read-only delivery review for managers and administrators.
 *
 * Driver stops, After photos, and each store's standing route compared with
 * the visits that actually happened. Nothing here writes.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/route_health.php';

const BAKERY_DELIVERY_MANAGER_REVIEW_STRIP_DAYS = 31;

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

/**
 * Store filters in the order the chips are shown.
 *
 * @return list<string>
 */
function bakery_delivery_manager_review_filters(): array
{
    return ['all', 'missed', 'unassigned', 'quiet', 'short', 'missing_after', 'off_driver', 'no_standing', 'extra', 'on_track'];
}

function bakery_delivery_manager_review_parse_filter(string $raw): string
{
    $raw = strtolower(trim($raw));
    return in_array($raw, bakery_delivery_manager_review_filters(), true) ? $raw : 'all';
}

function bakery_delivery_manager_review_parse_sort(string $raw): string
{
    $raw = strtolower(trim($raw));
    return in_array($raw, ['name', 'gap', 'last'], true) ? $raw : 'attention';
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

function bakery_delivery_manager_review_parse_day(string $raw): string
{
    return bakery_route_health_valid_date(trim($raw)) ?? '';
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
            $day = bakery_delivery_manager_review_parse_day((string)($params['day'] ?? ''));
            if ($day !== '') {
                $clean['day'] = $day;
            }
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
        $sort = bakery_delivery_manager_review_parse_sort((string)($params['sort'] ?? ''));
        if ($sort !== 'attention') {
            $clean['sort'] = $sort;
        }
        $query = bakery_delivery_manager_review_parse_query((string)($params['q'] ?? ''));
        if ($query !== '') {
            $clean['q'] = $query;
        }
    }
    $base = defined('BASE_URL') ? (string)BASE_URL : '';
    return $base . 'delivery_manager_review.php?' . http_build_query($clean);
}

/**
 * Quick ranges that end today.
 *
 * @return list<array{days:int,from:string,to:string}>
 */
function bakery_delivery_manager_review_presets(string $today): array
{
    $today = bakery_route_health_valid_date($today) ?? date('Y-m-d');
    $end = new DateTimeImmutable($today);
    $presets = [];
    foreach ([7, 14, 28] as $days) {
        $presets[] = [
            'days' => $days,
            'from' => $end->modify('-' . ($days - 1) . ' days')->format('Y-m-d'),
            'to' => $today,
        ];
    }
    return $presets;
}

/**
 * The window of the same length that ends the day before this one starts.
 *
 * @return array{from:string,to:string,days:int}
 */
function bakery_delivery_manager_review_previous_range(string $from, string $to): array
{
    $from = bakery_route_health_valid_date($from) ?? '';
    $to = bakery_route_health_valid_date($to) ?? '';
    if ($from === '' || $to === '') {
        return ['from' => '', 'to' => '', 'days' => 0];
    }
    if ($from > $to) {
        $swap = $from;
        $from = $to;
        $to = $swap;
    }
    $days = count(bakery_route_health_dates($from, $to));
    $end = (new DateTimeImmutable($from))->modify('-1 day');
    return [
        'from' => $end->modify('-' . ($days - 1) . ' days')->format('Y-m-d'),
        'to' => $end->format('Y-m-d'),
        'days' => $days,
    ];
}

function bakery_delivery_manager_review_percent(int $part, int $whole): ?int
{
    if ($whole <= 0) {
        return null;
    }
    return (int)round(($part / $whole) * 100);
}

/**
 * The middle clock time of a set of moments, as HH:MM. Null when none.
 *
 * @param array<int, mixed> $moments
 */
function bakery_delivery_manager_review_usual_clock(array $moments): ?string
{
    $minutes = [];
    foreach ($moments as $moment) {
        $at = bakery_route_stop_time_or_null($moment);
        if ($at === null) {
            continue;
        }
        $stamp = strtotime($at);
        if ($stamp === false) {
            continue;
        }
        $minutes[] = ((int)date('G', $stamp) * 60) + (int)date('i', $stamp);
    }
    if ($minutes === []) {
        return null;
    }
    sort($minutes);
    $count = count($minutes);
    $mid = intdiv($count, 2);
    $value = $count % 2 === 1
        ? $minutes[$mid]
        : (int)round(($minutes[$mid - 1] + $minutes[$mid]) / 2);
    return sprintf('%02d:%02d', intdiv($value, 60), $value % 60);
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
        case 'unassigned':
            return 'delivery_review.status_unassigned';
        case 'paused':
            return 'delivery_review.status_paused';
        default:
            return 'delivery_review.status_open';
    }
}

function bakery_delivery_manager_review_status_tone(string $status, bool $hasAfter = true): string
{
    switch ($status) {
        case 'delivered':
            return $hasAfter ? 'success' : 'warning';
        case 'failed':
        case 'open':
        case 'unassigned':
            return 'danger';
        default:
            return 'neutral';
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
 * @param array<string, mixed> $customer
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
    $unassigned = (int)($customer['unassigned'] ?? 0);
    return [
        'quiet' => $expected > 0 && $routed === 0,
        'missed' => ($open + $failed) > 0,
        'unassigned' => $unassigned > 0,
        'short' => $expected > 0 && $delivered < $expected,
        'no_standing' => $expected === 0 && $routed > 0,
        'off_driver' => $off > 0,
        'extra' => $expected > 0 && $delivered > $expected,
        'missing_after' => (int)($customer['missing_after'] ?? 0) > 0,
        'paused' => (int)($customer['paused'] ?? 0) > 0,
        'on_track' => $expected > 0 && $delivered >= $expected && $open === 0 && $failed === 0 && $unassigned === 0,
    ];
}

/**
 * @param array<string, mixed> $customer
 * @return array<string, bool>
 */
function bakery_delivery_manager_review_flags_of(array $customer): array
{
    return isset($customer['flags']) && is_array($customer['flags'])
        ? $customer['flags']
        : bakery_delivery_manager_review_customer_flags($customer);
}

/**
 * One sentence on where the store stands. The counts sit beside it on the page.
 *
 * @param array<string, mixed> $customer
 * @return array{key:string,params:array<string,int>}
 */
function bakery_delivery_manager_review_customer_reading(array $customer): array
{
    $expected = (int)($customer['expected'] ?? 0);
    $routed = (int)($customer['routed'] ?? 0);
    $cancelled = (int)($customer['cancelled'] ?? 0);
    $params = [
        'expected' => $expected,
        'routed' => $routed,
        'delivered' => (int)($customer['delivered'] ?? 0),
        'open' => (int)($customer['open'] ?? 0),
        'failed' => (int)($customer['failed'] ?? 0),
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
    } elseif ($expected === 0 && $routed === 0 && !empty($flags['unassigned'])) {
        $key = 'delivery_review.reading_unassigned';
    } elseif ($expected === 0 && $routed === 0 && !empty($flags['paused'])) {
        $key = 'delivery_review.reading_paused';
    } else {
        $key = 'delivery_review.reading_on_track';
    }
    return ['key' => $key, 'params' => $params];
}

/**
 * The single label a store carries in the list.
 *
 * @param array<string, mixed> $customer
 * @return array{key:string,tone:string}
 */
function bakery_delivery_manager_review_customer_status(array $customer): array
{
    $flags = bakery_delivery_manager_review_flags_of($customer);
    if (!empty($flags['unassigned'])) {
        return ['key' => 'delivery_review.state_unassigned', 'tone' => 'danger'];
    }
    if (!empty($flags['missed'])) {
        return ['key' => 'delivery_review.state_missed', 'tone' => 'danger'];
    }
    if (!empty($flags['quiet'])) {
        return ['key' => 'delivery_review.state_quiet', 'tone' => 'warning'];
    }
    if (!empty($flags['short'])) {
        return ['key' => 'delivery_review.state_short', 'tone' => 'warning'];
    }
    if (!empty($flags['missing_after'])) {
        return ['key' => 'delivery_review.state_missing_after', 'tone' => 'warning'];
    }
    if (!empty($flags['no_standing'])) {
        return ['key' => 'delivery_review.state_no_standing', 'tone' => 'info'];
    }
    if (!empty($flags['extra'])) {
        return ['key' => 'delivery_review.state_extra', 'tone' => 'info'];
    }
    if (!empty($flags['off_driver'])) {
        return ['key' => 'delivery_review.state_off_driver', 'tone' => 'info'];
    }
    if (!empty($flags['on_track'])) {
        return ['key' => 'delivery_review.state_on_track', 'tone' => 'success'];
    }
    if (!empty($flags['paused'])) {
        return ['key' => 'delivery_review.state_paused', 'tone' => 'neutral'];
    }
    return ['key' => 'delivery_review.state_none', 'tone' => 'neutral'];
}

function bakery_delivery_manager_review_customer_rank(array $customer): int
{
    $flags = bakery_delivery_manager_review_flags_of($customer);
    if (!empty($flags['unassigned']) || !empty($flags['missed'])) {
        return 0;
    }
    if (!empty($flags['quiet'])) {
        return 1;
    }
    if (!empty($flags['short'])) {
        return 2;
    }
    if (!empty($flags['missing_after'])) {
        return 3;
    }
    if (!empty($flags['off_driver'])) {
        return 4;
    }
    if (!empty($flags['no_standing'])) {
        return 5;
    }
    if (!empty($flags['extra'])) {
        return 6;
    }
    return 7;
}

/**
 * @param array<int, array<string, mixed>> $customers
 * @return list<array<string, mixed>>
 */
function bakery_delivery_manager_review_sort_customers(array $customers, string $sort): array
{
    $sort = bakery_delivery_manager_review_parse_sort($sort);
    $customers = array_values($customers);
    $byName = static function (array $a, array $b): int {
        return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    };
    usort($customers, static function (array $a, array $b) use ($sort, $byName): int {
        if ($sort === 'name') {
            return $byName($a, $b);
        }
        if ($sort === 'gap') {
            $gapA = (int)($a['expected'] ?? 0) - (int)($a['delivered'] ?? 0);
            $gapB = (int)($b['expected'] ?? 0) - (int)($b['delivered'] ?? 0);
            return ($gapB <=> $gapA) ?: $byName($a, $b);
        }
        if ($sort === 'last') {
            $lastA = (string)($a['last_delivered'] ?? '');
            $lastB = (string)($b['last_delivered'] ?? '');
            return strcmp($lastA, $lastB) ?: $byName($a, $b);
        }
        $rank = bakery_delivery_manager_review_customer_rank($a) <=> bakery_delivery_manager_review_customer_rank($b);
        if ($rank !== 0) {
            return $rank;
        }
        $loadA = (int)($a['open'] ?? 0) + (int)($a['failed'] ?? 0) + (int)($a['unassigned'] ?? 0);
        $loadB = (int)($b['open'] ?? 0) + (int)($b['failed'] ?? 0) + (int)($b['unassigned'] ?? 0);
        return ($loadB <=> $loadA) ?: $byName($a, $b);
    });
    return $customers;
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
    foreach ($customer['driver_mix'] ?? [] as $driver) {
        if (is_array($driver)) {
            $parts[] = (string)($driver['driver_name'] ?? '');
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
    $flags = bakery_delivery_manager_review_flags_of($customer);
    return !empty($flags[$filter]);
}

/**
 * @param array<int, array<string, mixed>> $customers
 * @return array<string, int>
 */
function bakery_delivery_manager_review_filter_counts(array $customers): array
{
    $counts = array_fill_keys(bakery_delivery_manager_review_filters(), 0);
    $counts['all'] = count($customers);
    foreach ($customers as $customer) {
        if (!is_array($customer)) {
            continue;
        }
        $flags = bakery_delivery_manager_review_flags_of($customer);
        foreach ($counts as $flag => $unused) {
            if ($flag !== 'all' && !empty($flags[$flag])) {
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
 * Weekdays the store was actually delivered, with how many times each.
 *
 * @param array<int, int> $actualDays
 */
function bakery_delivery_manager_review_actual_days_label(array $actualDays): string
{
    $names = function_exists('bakery_day_names') ? bakery_day_names(true) : [];
    ksort($actualDays);
    $parts = [];
    foreach ($actualDays as $dow => $times) {
        if ((int)$times <= 0) {
            continue;
        }
        $label = trim((string)($names[(int)$dow] ?? ''));
        $parts[] = ($label !== '' ? $label : (string)$dow) . ' ' . (int)$times;
    }
    return implode(', ', $parts);
}

/**
 * @param array<int, array<string, mixed>> $mix
 */
function bakery_delivery_manager_review_driver_mix_label(array $mix): string
{
    $parts = [];
    foreach ($mix as $row) {
        if (!is_array($row) || (int)($row['stops'] ?? 0) <= 0) {
            continue;
        }
        $parts[] = trim((string)($row['driver_name'] ?? '')) . ' ' . (int)$row['stops'];
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
    $failed = (int)($driver['failed'] ?? 0);
    if ($failed > 0) {
        $lines[] = ['key' => 'delivery_review.attention_failed', 'params' => ['count' => $failed]];
    }
    $open = (int)($driver['open'] ?? 0);
    if ($open > 0) {
        $lines[] = ['key' => 'delivery_review.attention_open', 'params' => ['count' => $open]];
    }
    $missing = (int)($driver['missing_after'] ?? 0);
    if ($missing > 0) {
        $lines[] = ['key' => 'delivery_review.attention_missing_after', 'params' => ['count' => $missing]];
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
    $bulk = (int)($driver['bulk_days'] ?? 0);
    if ($bulk > 0) {
        $lines[] = ['key' => 'delivery_review.attention_bulk', 'params' => ['count' => $bulk]];
    }
    $unclocked = (int)($driver['unclocked_days'] ?? 0);
    if ($unclocked > 0) {
        $lines[] = ['key' => 'delivery_review.attention_unclocked', 'params' => ['count' => $unclocked]];
    }
    $quiet = (int)($driver['quiet_days'] ?? 0);
    if ($quiet > 0) {
        $lines[] = ['key' => 'delivery_review.attention_quiet_days', 'params' => ['count' => $quiet]];
    }
    $covered = (int)($driver['covered_stops'] ?? 0);
    if ($covered > 0) {
        $lines[] = ['key' => 'delivery_review.attention_covered', 'params' => ['count' => $covered]];
    }
    $covering = (int)($driver['covering_stops'] ?? 0);
    if ($covering > 0) {
        $lines[] = ['key' => 'delivery_review.attention_covering', 'params' => ['count' => $covering]];
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

/**
 * How one driver day went, for the day strip.
 *
 * clean: every stop delivered with an After photo (or cancelled).
 * photo_gap: delivered, some without an After photo.
 * problem: a stop is open or failed.
 * not_out: standing stops that day and nobody ran them.
 * covered: no stops for this driver, another driver ran the standing stops.
 * idle: nothing called for.
 *
 * @param array<string, mixed> $day
 */
function bakery_delivery_manager_review_day_state(array $day): string
{
    $stops = (int)($day['stops'] ?? 0);
    if ($stops === 0) {
        if ((int)($day['unrouted_stops'] ?? 0) > 0) {
            return 'not_out';
        }
        if ((int)($day['covered_stops'] ?? 0) > 0) {
            return 'covered';
        }
        $standing = (int)($day['standing_stops'] ?? 0) - (int)($day['paused_stops'] ?? 0);
        if ($standing > 0 && !array_key_exists('unrouted_stops', $day)) {
            return 'not_out';
        }
        return 'idle';
    }
    if (((int)($day['open'] ?? 0) + (int)($day['failed'] ?? 0)) > 0) {
        return 'problem';
    }
    if ((int)($day['delivered'] ?? 0) > (int)($day['delivered_with_after'] ?? 0)) {
        return 'photo_gap';
    }
    return 'clean';
}

/**
 * @param array<string, mixed> $visit
 */
function bakery_delivery_manager_review_visit_state(array $visit): string
{
    switch ((string)($visit['status'] ?? '')) {
        case 'delivered':
            return !empty($visit['has_after']) ? 'clean' : 'photo_gap';
        case 'open':
        case 'failed':
        case 'unassigned':
            return 'problem';
        case 'cancelled':
            return 'cancelled';
        case 'paused':
            return 'paused';
        case 'not_routed':
            return 'not_out';
        default:
            return 'idle';
    }
}

function bakery_delivery_manager_review_state_weight(string $state): int
{
    switch ($state) {
        case 'problem':
            return 6;
        case 'photo_gap':
            return 5;
        case 'clean':
            return 4;
        case 'cancelled':
            return 3;
        case 'not_out':
            return 2;
        case 'covered':
        case 'paused':
            return 1;
        default:
            return 0;
    }
}

function bakery_delivery_manager_review_state_key(string $state): string
{
    switch ($state) {
        case 'clean':
            return 'delivery_review.cell_clean';
        case 'photo_gap':
            return 'delivery_review.cell_photo_gap';
        case 'problem':
            return 'delivery_review.cell_problem';
        case 'cancelled':
            return 'delivery_review.cell_cancelled';
        case 'not_out':
            return 'delivery_review.cell_not_out';
        case 'covered':
            return 'delivery_review.cell_covered';
        case 'paused':
            return 'delivery_review.cell_paused';
        default:
            return 'delivery_review.cell_idle';
    }
}

/**
 * The most recent dates of the window that fit in a strip.
 *
 * @param list<string> $dates
 * @return list<string>
 */
function bakery_delivery_manager_review_strip_dates(array $dates): array
{
    return array_values(array_slice($dates, -BAKERY_DELIVERY_MANAGER_REVIEW_STRIP_DAYS));
}

/**
 * One cell per date for a store. The worst thing that happened that day wins.
 *
 * @param array<string, mixed> $customer
 * @param list<string> $dates
 * @return list<array{date:string,state:string}>
 */
function bakery_delivery_manager_review_customer_strip(array $customer, array $dates): array
{
    $byDate = [];
    foreach ($customer['visits'] ?? [] as $visit) {
        if (!is_array($visit)) {
            continue;
        }
        $date = (string)($visit['date'] ?? '');
        $state = bakery_delivery_manager_review_visit_state($visit);
        if (!isset($byDate[$date])
            || bakery_delivery_manager_review_state_weight($state) > bakery_delivery_manager_review_state_weight($byDate[$date])) {
            $byDate[$date] = $state;
        }
    }
    $strip = [];
    foreach ($dates as $date) {
        $strip[] = ['date' => (string)$date, 'state' => $byDate[$date] ?? 'idle'];
    }
    return $strip;
}

/**
 * One cell per date for a driver.
 *
 * @param array<string, mixed> $driver
 * @param list<string> $dates
 * @return list<array{date:string,state:string,stops:int,delivered:int}>
 */
function bakery_delivery_manager_review_driver_strip(array $driver, array $dates): array
{
    $byDate = [];
    foreach ($driver['day_rows'] ?? [] as $day) {
        if (is_array($day)) {
            $byDate[(string)($day['date'] ?? '')] = $day;
        }
    }
    $strip = [];
    foreach ($dates as $date) {
        $day = $byDate[$date] ?? null;
        $strip[] = [
            'date' => (string)$date,
            'state' => is_array($day)
                ? (string)($day['state'] ?? bakery_delivery_manager_review_day_state($day))
                : 'idle',
            'stops' => is_array($day) ? (int)($day['stops'] ?? 0) : 0,
            'delivered' => is_array($day) ? (int)($day['delivered'] ?? 0) : 0,
        ];
    }
    return $strip;
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
        'dates' => [],
        'drivers' => [],
        'customers' => [],
        'needs' => [],
        'days' => [],
        'totals' => [
            'routed' => 0,
            'delivered' => 0,
            'open' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'with_after' => 0,
            'missing_after' => 0,
            'unassigned' => 0,
            'paused' => 0,
            'expected' => 0,
            'standing_routed' => 0,
            'unrouted' => 0,
            'unrouted_customers' => 0,
            'off_visits' => 0,
            'short_customers' => 0,
            'quiet_customers' => 0,
            'missed_customers' => 0,
            'unassigned_customers' => 0,
            'off_customers' => 0,
            'no_standing_customers' => 0,
            'extra_customers' => 0,
            'on_track_customers' => 0,
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
        'unassigned' => 0,
        'paused' => 0,
        'not_routed' => 0,
        'last_delivered' => null,
        'driver_mix' => [],
        'actual_days' => [],
        'usual_close' => null,
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
        'kept_stops' => 0,
        'covered_stops' => 0,
        'covering_stops' => 0,
        'unrouted_stops' => 0,
        'paused_stops' => 0,
        'first_arrival' => null,
        'last_close' => null,
        'clocked_in' => $clockReady ? !empty($clockIns[$key]) : null,
        'route_closed' => $closeReady ? !empty($closed[$key]) : null,
        'bulk_stores' => [],
        'state' => 'idle',
    ];
}

/**
 * @return array<string, int|string>
 */
function bakery_delivery_manager_review_blank_date(string $date): array
{
    return [
        'date' => $date,
        'drivers_out' => 0,
        'stops' => 0,
        'delivered' => 0,
        'open' => 0,
        'failed' => 0,
        'cancelled' => 0,
        'with_after' => 0,
        'unassigned' => 0,
        'standing' => 0,
        'unrouted' => 0,
        'paused' => 0,
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
 * Dates a store was paused, skipped, or taken off the route by a manager.
 * A standing visit on one of those dates is explained, not missed.
 *
 * @param list<string> $dates
 * @return array<int, array<string, bool>>
 */
function bakery_delivery_manager_review_paused_dates(PDO $db, string $from, string $to, array $dates): array
{
    $paused = [];
    if (!function_exists('table_exists')) {
        return $paused;
    }
    $inWindow = array_fill_keys($dates, true);
    $mark = static function (int $customerId, string $date) use (&$paused, $inWindow): void {
        if ($customerId > 0 && isset($inWindow[$date])) {
            $paused[$customerId][$date] = true;
        }
    };
    if (table_exists($db, 'standing_order_pauses')) {
        $start = new DateTimeImmutable($from);
        $weekFrom = $start->modify('-' . ((int)$start->format('N') - 1) . ' days')->format('Y-m-d');
        $stmt = $db->prepare(
            'SELECT customer_id, week_start FROM standing_order_pauses WHERE week_start >= ? AND week_start <= ?'
        );
        $stmt->execute([$weekFrom, $to]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $week = bakery_route_health_valid_date(substr((string)$row['week_start'], 0, 10));
            if ($week === null) {
                continue;
            }
            $cursor = new DateTimeImmutable($week);
            for ($i = 0; $i < 7; $i++) {
                $mark((int)$row['customer_id'], $cursor->modify('+' . $i . ' days')->format('Y-m-d'));
            }
        }
    }
    if (table_exists($db, 'customer_delivery_skips')) {
        $stmt = $db->prepare(
            'SELECT customer_id, skip_date FROM customer_delivery_skips WHERE skip_date >= ? AND skip_date <= ?'
        );
        $stmt->execute([$from, $to]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mark((int)$row['customer_id'], substr((string)$row['skip_date'], 0, 10));
        }
    }
    if (table_exists($db, 'customer_delivery_pauses')) {
        $stmt = $db->prepare(
            'SELECT customer_id, pause_start, pause_end FROM customer_delivery_pauses WHERE pause_start <= ? AND pause_end >= ?'
        );
        $stmt->execute([$to, $from]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $start = substr((string)$row['pause_start'], 0, 10);
            $end = substr((string)$row['pause_end'], 0, 10);
            foreach ($dates as $date) {
                if ($date >= $start && $date <= $end) {
                    $mark((int)$row['customer_id'], $date);
                }
            }
        }
    }
    if (table_exists($db, 'dated_route_removals')) {
        $stmt = $db->prepare(
            'SELECT customer_id, delivery_date FROM dated_route_removals WHERE delivery_date >= ? AND delivery_date <= ?'
        );
        $stmt->execute([$from, $to]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mark((int)$row['customer_id'], substr((string)$row['delivery_date'], 0, 10));
        }
    }
    return $paused;
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
    $hasTable = static function (string $table) use ($db): bool {
        return function_exists('table_exists') && table_exists($db, $table);
    };
    $hasColumn = static function (string $table, string $column) use ($db): bool {
        return function_exists('column_exists') && column_exists($db, $table, $column);
    };
    $origin = function_exists('bakery_sfb_ops_origin_clause') ? bakery_sfb_ops_origin_clause('c', $db) : '';
    $confirmedSql = $hasColumn('daily_orders', 'delivery_confirmed_at') ? 'do.delivery_confirmed_at' : 'NULL';
    $notesSql = $hasColumn('daily_order_assignments', 'notes') ? 'doa.notes' : "''";
    $zoneSql = $hasColumn('customers', 'zone') ? 'c.zone' : "''";
    $stopsReady = $hasTable('daily_order_assignments') && $hasTable('daily_orders');

    $stopRows = [];
    if ($stopsReady) {
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

    // Orders with product on them that are not delivered, not cancelled, and never got a driver.
    $orderRows = [];
    if ($stopsReady && $hasTable('daily_order_items')) {
        $orderStmt = $db->prepare(
            "SELECT do.order_date, c.id AS customer_id, c.name AS customer_name,
                    {$zoneSql} AS customer_zone, c.is_active AS customer_active
             FROM daily_orders do
             INNER JOIN customers c ON c.id = do.customer_id
             WHERE do.order_date >= ? AND do.order_date <= ?
               AND do.status NOT IN ('cancelled', 'delivered', 'invoiced')
               AND {$confirmedSql} IS NULL
               AND EXISTS (
                   SELECT 1 FROM daily_order_items doi
                   WHERE doi.daily_order_id = do.id AND doi.quantity > 0
               )
               AND NOT EXISTS (
                   SELECT 1 FROM daily_order_assignments doa
                   WHERE doa.daily_order_id = do.id AND doa.driver_id <> 0
               )
             {$origin}
             ORDER BY do.order_date, c.name"
        );
        $orderStmt->execute([$from, $to]);
        $orderRows = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $photosByStop = [];
    if ($hasTable('driver_photos')) {
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

    $clockReady = $hasTable('time_clock_punches') && $hasTable('users');
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

    $closeReady = $hasTable('driver_loads');
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
    if ($hasTable('standing_routes')) {
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

    $pausedOn = bakery_delivery_manager_review_paused_dates($db, $from, $to, $dates);

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
    $dateTotals = [];
    foreach ($dates as $date) {
        $dateTotals[$date] = bakery_delivery_manager_review_blank_date($date);
    }
    $stopHad = [];
    $stopDrivers = [];
    $orderOnly = [];
    $openCounts = [];
    $deliveredForBulk = [];
    $mix = [];
    $actualDays = [];
    $closeTimes = [];
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
            'due' => 0,
            'delivered' => 0,
            'open' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'delivered_with_after' => 0,
            'missing_after' => 0,
            'delivered_rate' => null,
            'after_rate' => null,
            'kept_stops' => 0,
            'covered_stops' => 0,
            'covering_stops' => 0,
            'unrouted_stops' => 0,
            'paused_stops' => 0,
            'quiet_days' => 0,
            'days_worked' => 0,
            'clocked_days' => 0,
            'unclocked_days' => 0,
            'closed_days' => 0,
            'unclosed_days' => 0,
            'listed_days' => 0,
            'bulk_days' => 0,
            'usual_start' => null,
            'usual_finish' => null,
            'attention_score' => 0,
            'repeat' => [],
            'day_rows' => [],
            'stop_rows' => [],
        ];
    };
    $ensureDay = static function (int $driverId, string $driverName, string $date) use (&$days, &$ensureDriver, $clockReady, $closeReady, $clockIns, $closed): void {
        $ensureDriver($driverId, $driverName);
        if (!isset($days[$driverId][$date])) {
            $days[$driverId][$date] = bakery_delivery_manager_review_blank_day($date, $clockReady, $closeReady, $clockIns, $closed, $driverId);
        }
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
        $hasAfter = $bucket === 'delivered' && bakery_route_stop_has_proof_photo($photos);
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
        if ($off) {
            $days[$driverId][$date]['covering_stops']++;
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
        if (isset($dateTotals[$date])) {
            $dateTotals[$date]['stops']++;
            $dateTotals[$date][$bucket]++;
            if ($hasAfter) {
                $dateTotals[$date]['with_after']++;
            }
        }
        $customers[$customerId]['routed']++;
        $customers[$customerId][$bucket]++;
        if (!isset($mix[$customerId][$driverId])) {
            $mix[$customerId][$driverId] = [
                'driver_id' => $driverId,
                'driver_name' => $driverName,
                'stops' => 0,
                'delivered' => 0,
            ];
        }
        $mix[$customerId][$driverId]['stops']++;
        if ($bucket === 'delivered') {
            $mix[$customerId][$driverId]['delivered']++;
            if ($hasAfter) {
                $customers[$customerId]['with_after']++;
            } else {
                $customers[$customerId]['missing_after']++;
            }
            $last = (string)($customers[$customerId]['last_delivered'] ?? '');
            if ($last === '' || $date > $last) {
                $customers[$customerId]['last_delivered'] = $date;
            }
            $dow = (int)(new DateTimeImmutable($date))->format('N');
            $actualDays[$customerId][$dow] = ($actualDays[$customerId][$dow] ?? 0) + 1;
            if ($closedAt !== null) {
                $closeTimes[$customerId][] = $closedAt;
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
        $stopDrivers[$customerId][$date][$driverId] = true;
        $drivers[$driverId]['stop_rows'][] = [
            'date' => $date,
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'route_order' => (int)($stop['route_order'] ?? 0),
            'status' => $bucket,
            'has_after' => $hasAfter,
            'off_driver' => $off,
            'photo_url' => $photoUrl,
            'arrival_at' => $arrival,
            'closed_at' => $closedAt,
            'note' => $note,
            'bulk' => false,
        ];
    }

    $emptyVisit = static function (string $date, array $standingDrivers, string $status): array {
        $standingList = [];
        foreach ($standingDrivers as $standingId => $standingName) {
            $standingList[] = ['driver_id' => (int)$standingId, 'driver_name' => (string)$standingName];
        }
        return [
            'date' => $date,
            'standing_drivers' => $standingList,
            'actual_driver_id' => 0,
            'actual_driver_name' => '',
            'status' => $status,
            'off_driver' => false,
            'extra' => $standingDrivers === [],
            'has_after' => false,
            'photo_url' => '',
            'arrival_at' => null,
            'closed_at' => null,
            'note' => '',
        ];
    };

    foreach ($orderRows as $order) {
        if (!is_array($order)) {
            continue;
        }
        $customerId = (int)$order['customer_id'];
        $date = substr((string)$order['order_date'], 0, 10);
        if ($customerId <= 0 || !empty($stopHad[$customerId][$date]) || !empty($orderOnly[$customerId][$date])) {
            continue;
        }
        $zone = trim((string)($order['customer_zone'] ?? ''));
        if (!isset($customers[$customerId])) {
            $customers[$customerId] = bakery_delivery_manager_review_blank_customer(
                $customerId,
                (string)$order['customer_name'],
                $zone,
                (int)($order['customer_active'] ?? 1) === 1
            );
        }
        $orderOnly[$customerId][$date] = true;
        $customers[$customerId]['unassigned']++;
        $customers[$customerId]['visits'][] = $emptyVisit($date, $standingOnDate[$customerId][$date] ?? [], 'unassigned');
        if (isset($dateTotals[$date])) {
            $dateTotals[$date]['unassigned']++;
        }
    }

    foreach ($standingOnDate as $customerId => $byDate) {
        foreach ($byDate as $date => $standingDrivers) {
            $date = (string)$date;
            $isPaused = !empty($pausedOn[$customerId][$date]);
            $routedThatDay = !empty($stopHad[$customerId][$date]);
            if (isset($dateTotals[$date]) && ($routedThatDay || !$isPaused || !empty($orderOnly[$customerId][$date]))) {
                $dateTotals[$date]['standing']++;
            }
            if ($routedThatDay || !empty($orderOnly[$customerId][$date])) {
                continue;
            }
            if ($isPaused) {
                $customers[$customerId]['paused']++;
                $customers[$customerId]['visits'][] = $emptyVisit($date, $standingDrivers, 'paused');
                if (isset($dateTotals[$date])) {
                    $dateTotals[$date]['paused']++;
                }
                continue;
            }
            $customers[$customerId]['not_routed']++;
            $customers[$customerId]['visits'][] = $emptyVisit($date, $standingDrivers, 'not_routed');
            if (isset($dateTotals[$date])) {
                $dateTotals[$date]['unrouted']++;
            }
        }
    }

    // What became of each driver's standing stops: ran them, someone else did, paused, or nobody.
    foreach ($driverStanding as $driverId => $byDate) {
        foreach ($byDate as $date => $customerIds) {
            foreach ($customerIds as $customerId => $unused) {
                $who = $stopDrivers[$customerId][$date] ?? [];
                if ($who !== []) {
                    $days[$driverId][$date][isset($who[$driverId]) ? 'kept_stops' : 'covered_stops']++;
                } elseif (empty($orderOnly[$customerId][$date]) && !empty($pausedOn[$customerId][$date])) {
                    $days[$driverId][$date]['paused_stops']++;
                } else {
                    $days[$driverId][$date]['unrouted_stops']++;
                }
            }
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
        $starts = [];
        $finishes = [];
        foreach ($driverDays as $dayDate => $day) {
            $day['state'] = bakery_delivery_manager_review_day_state($day);
            $driverDays[$dayDate] = $day;
            $driver['standing_stops'] += (int)$day['standing_stops'];
            $driver['stops'] += (int)$day['stops'];
            $driver['delivered'] += (int)$day['delivered'];
            $driver['open'] += (int)$day['open'];
            $driver['failed'] += (int)$day['failed'];
            $driver['cancelled'] += (int)$day['cancelled'];
            $driver['delivered_with_after'] += (int)$day['delivered_with_after'];
            $driver['kept_stops'] += (int)$day['kept_stops'];
            $driver['covered_stops'] += (int)$day['covered_stops'];
            $driver['covering_stops'] += (int)$day['covering_stops'];
            $driver['unrouted_stops'] += (int)$day['unrouted_stops'];
            $driver['paused_stops'] += (int)$day['paused_stops'];
            $driver['listed_days']++;
            if ((int)$day['stops'] > 0) {
                $driver['days_worked']++;
                if ($day['clocked_in'] === false) {
                    $driver['unclocked_days']++;
                }
                if ($day['route_closed'] === false) {
                    $driver['unclosed_days']++;
                }
                if ($day['first_arrival'] !== null) {
                    $starts[] = $day['first_arrival'];
                }
                if ($day['last_close'] !== null) {
                    $finishes[] = $day['last_close'];
                }
                if (isset($dateTotals[$dayDate])) {
                    $dateTotals[$dayDate]['drivers_out']++;
                }
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
        $driver['due'] = max(0, (int)$driver['stops'] - (int)$driver['cancelled']);
        $driver['missing_after'] = max(0, (int)$driver['delivered'] - (int)$driver['delivered_with_after']);
        $driver['delivered_rate'] = bakery_delivery_manager_review_percent((int)$driver['delivered'], (int)$driver['due']);
        $driver['after_rate'] = bakery_delivery_manager_review_percent((int)$driver['delivered_with_after'], (int)$driver['delivered']);
        $driver['usual_start'] = bakery_delivery_manager_review_usual_clock($starts);
        $driver['usual_finish'] = bakery_delivery_manager_review_usual_clock($finishes);
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
        $standingDates = $standingOnDate[$customerId] ?? [];
        $customer['expected'] = max(0, count($standingDates) - (int)$customer['paused']);
        foreach ($standingDates as $date => $unused) {
            if (!empty($stopHad[$customerId][$date])) {
                $totals['standing_routed']++;
            }
        }
        $pattern = [];
        $daysForPattern = $patternMap[$customerId] ?? [];
        ksort($daysForPattern);
        foreach ($daysForPattern as $dow => $driverNames) {
            $names = array_keys($driverNames);
            sort($names, SORT_FLAG_CASE | SORT_STRING);
            $pattern[] = ['dow' => (int)$dow, 'drivers' => $names];
        }
        $customer['pattern'] = $pattern;
        $driverMix = array_values($mix[$customerId] ?? []);
        usort($driverMix, static function (array $a, array $b): int {
            return (((int)$b['stops']) <=> ((int)$a['stops']))
                ?: strcasecmp((string)$a['driver_name'], (string)$b['driver_name']);
        });
        $customer['driver_mix'] = $driverMix;
        $customer['actual_days'] = $actualDays[$customerId] ?? [];
        ksort($customer['actual_days']);
        $customer['usual_close'] = bakery_delivery_manager_review_usual_clock($closeTimes[$customerId] ?? []);
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
        $totals['missing_after'] += (int)$customer['missing_after'];
        $totals['unassigned'] += (int)$customer['unassigned'];
        $totals['paused'] += (int)$customer['paused'];
        $totals['expected'] += (int)$customer['expected'];
        $totals['unrouted'] += (int)$customer['not_routed'];
        if ((int)$customer['not_routed'] > 0) {
            $totals['unrouted_customers']++;
        }
        $totals['off_visits'] += (int)$customer['off_count'];
        foreach ([
            'short' => 'short_customers',
            'quiet' => 'quiet_customers',
            'missed' => 'missed_customers',
            'unassigned' => 'unassigned_customers',
            'off_driver' => 'off_customers',
            'no_standing' => 'no_standing_customers',
            'extra' => 'extra_customers',
            'on_track' => 'on_track_customers',
        ] as $flag => $totalKey) {
            if (!empty($customer['flags'][$flag])) {
                $totals[$totalKey]++;
            }
        }
        $customerList[] = $customer;
    }
    $customerList = bakery_delivery_manager_review_sort_customers($customerList, 'attention');

    return [
        'from' => $from,
        'to' => $to,
        'dates' => $dates,
        'drivers' => $driverList,
        'customers' => $customerList,
        'needs' => $needs,
        'days' => array_values($dateTotals),
        'totals' => $totals,
        'clock_ready' => $clockReady,
        'close_ready' => $closeReady,
    ];
}

/**
 * The headline numbers for one window.
 *
 * @param array<string, mixed> $report
 * @return array<string, int|null>
 */
function bakery_delivery_manager_review_summary(array $report, string $today): array
{
    $totals = is_array($report['totals'] ?? null) ? $report['totals'] : [];
    $routed = (int)($totals['routed'] ?? 0);
    $cancelled = (int)($totals['cancelled'] ?? 0);
    $delivered = (int)($totals['delivered'] ?? 0);
    $withAfter = (int)($totals['with_after'] ?? 0);
    $open = (int)($totals['open'] ?? 0);
    $failed = (int)($totals['failed'] ?? 0);
    $expected = (int)($totals['expected'] ?? 0);
    $standingRouted = (int)($totals['standing_routed'] ?? 0);
    $stale = 0;
    foreach ($report['needs'] ?? [] as $need) {
        if (is_array($need) && (string)($need['reason'] ?? '') === 'open' && (string)($need['date'] ?? '') < $today) {
            $stale++;
        }
    }
    $due = max(0, $routed - $cancelled);
    return [
        'due' => $due,
        'delivered' => $delivered,
        'delivered_rate' => bakery_delivery_manager_review_percent($delivered, $due),
        'with_after' => $withAfter,
        'after_rate' => bakery_delivery_manager_review_percent($withAfter, $delivered),
        'open' => $open,
        'failed' => $failed,
        'cancelled' => $cancelled,
        'problem' => $open + $failed,
        'stale_open' => $stale,
        'unassigned' => (int)($totals['unassigned'] ?? 0),
        'expected' => $expected,
        'standing_routed' => $standingRouted,
        'standing_rate' => bakery_delivery_manager_review_percent($standingRouted, $expected),
    ];
}

/**
 * Plain findings for the top of the page, most urgent first.
 * Each one names what it counted and links to where the detail is.
 *
 * @param array<string, mixed> $report
 * @return list<array{tone:string,key:string,params:array<string,int|string>,href:string}>
 */
function bakery_delivery_manager_review_insights(array $report, string $today): array
{
    $from = (string)($report['from'] ?? '');
    $to = (string)($report['to'] ?? '');
    $totals = is_array($report['totals'] ?? null) ? $report['totals'] : [];
    $drivers = is_array($report['drivers'] ?? null) ? $report['drivers'] : [];
    $link = static function (array $params, string $anchor = '') use ($from, $to): string {
        return bakery_delivery_manager_review_href(['from' => $from, 'to' => $to] + $params) . $anchor;
    };
    $perDriver = static function (array $rows, string $field): string {
        $parts = [];
        foreach ($rows as $row) {
            $parts[] = trim((string)$row['driver_name']) . ' ' . (int)$row[$field];
        }
        return implode(', ', $parts);
    };
    $withField = static function (string $field) use ($drivers): array {
        $rows = [];
        foreach ($drivers as $driver) {
            if (is_array($driver) && (int)($driver[$field] ?? 0) > 0) {
                $rows[] = $driver;
            }
        }
        usort($rows, static function (array $a, array $b) use ($field): int {
            return (((int)$b[$field]) <=> ((int)$a[$field]))
                ?: strcasecmp((string)$a['driver_name'], (string)$b['driver_name']);
        });
        return $rows;
    };

    $stale = 0;
    $oldest = '';
    $openToday = 0;
    $failed = 0;
    $missing = 0;
    $missingBy = [];
    $weekdays = [];
    foreach ($report['needs'] ?? [] as $need) {
        if (!is_array($need)) {
            continue;
        }
        $reason = (string)($need['reason'] ?? '');
        $date = (string)($need['date'] ?? '');
        if ($reason === 'missing_after') {
            $missing++;
            $driverId = (int)($need['driver_id'] ?? 0);
            if (!isset($missingBy[$driverId])) {
                $missingBy[$driverId] = ['driver_id' => $driverId, 'driver_name' => (string)($need['driver_name'] ?? ''), 'count' => 0];
            }
            $missingBy[$driverId]['count']++;
            continue;
        }
        if ($reason === 'failed') {
            $failed++;
        } elseif ($date < $today) {
            $stale++;
            if ($oldest === '' || $date < $oldest) {
                $oldest = $date;
            }
        } else {
            $openToday++;
        }
        $parsed = bakery_route_health_valid_date($date);
        if ($parsed !== null) {
            $dow = (int)(new DateTimeImmutable($parsed))->format('N');
            $weekdays[$dow] = ($weekdays[$dow] ?? 0) + 1;
        }
    }

    $out = [];
    if ($stale > 0) {
        $out[] = [
            'tone' => 'danger',
            'key' => 'delivery_review.insight_stale_open',
            'params' => ['count' => $stale, 'date' => bakery_delivery_manager_review_display_date($oldest)],
            'href' => $link(['view' => 'drivers'], '#delivery-review-needs'),
        ];
    }
    if ($failed > 0) {
        $out[] = [
            'tone' => 'danger',
            'key' => 'delivery_review.insight_failed',
            'params' => ['count' => $failed],
            'href' => $link(['view' => 'drivers'], '#delivery-review-needs'),
        ];
    }
    $unassigned = (int)($totals['unassigned'] ?? 0);
    if ($unassigned > 0) {
        $out[] = [
            'tone' => 'danger',
            'key' => 'delivery_review.insight_unassigned',
            'params' => ['count' => $unassigned],
            'href' => $link(['view' => 'customers', 'filter' => 'unassigned']),
        ];
    }
    if ($missing > 0) {
        usort($missingBy, static function (array $a, array $b): int {
            return (((int)$b['count']) <=> ((int)$a['count']))
                ?: strcasecmp((string)$a['driver_name'], (string)$b['driver_name']);
        });
        $top = $missingBy[0];
        $out[] = [
            'tone' => 'warning',
            'key' => 'delivery_review.insight_missing_after',
            'params' => ['count' => $missing, 'driver' => (string)$top['driver_name'], 'most' => (int)$top['count']],
            'href' => $link(['view' => 'drivers', 'driver' => (int)$top['driver_id']], '#delivery-review-photos'),
        ];
    }
    $repeatStores = [];
    foreach ($drivers as $driver) {
        foreach (is_array($driver) ? ($driver['repeat'] ?? []) : [] as $row) {
            $name = trim((string)($row['customer_name'] ?? ''));
            if ($name !== '' && !in_array($name, $repeatStores, true)) {
                $repeatStores[] = $name;
            }
        }
    }
    if ($repeatStores !== []) {
        $out[] = [
            'tone' => 'warning',
            'key' => 'delivery_review.insight_repeat',
            'params' => [
                'times' => BAKERY_ROUTE_HEALTH_REPEAT_SKIP_DAYS,
                'stores' => implode(', ', array_slice($repeatStores, 0, 6)),
            ],
            'href' => $link(['view' => 'customers', 'filter' => 'missed']),
        ];
    }
    $bulk = $withField('bulk_days');
    if ($bulk !== []) {
        $out[] = [
            'tone' => 'warning',
            'key' => 'delivery_review.insight_bulk',
            'params' => [
                'stops' => BAKERY_ROUTE_STOP_BULK_STAMP_MIN_STOPS,
                'minutes' => BAKERY_ROUTE_STOP_BULK_STAMP_MINUTES,
                'drivers' => $perDriver($bulk, 'bulk_days'),
            ],
            'href' => $link(['view' => 'drivers', 'driver' => (int)$bulk[0]['driver_id']]),
        ];
    }
    // Only speak about the clock or route close when someone used it in this window.
    $clockUsed = $withField('clocked_days') !== [];
    $unclocked = $withField('unclocked_days');
    if (!empty($report['clock_ready']) && $clockUsed && $unclocked !== []) {
        $out[] = [
            'tone' => 'warning',
            'key' => 'delivery_review.insight_unclocked',
            'params' => ['drivers' => $perDriver($unclocked, 'unclocked_days')],
            'href' => $link(['view' => 'drivers', 'driver' => (int)$unclocked[0]['driver_id']]),
        ];
    }
    $closeUsed = $withField('closed_days') !== [];
    $unclosed = $withField('unclosed_days');
    if (!empty($report['close_ready']) && $closeUsed && $unclosed !== []) {
        $out[] = [
            'tone' => 'warning',
            'key' => 'delivery_review.insight_unclosed',
            'params' => ['drivers' => $perDriver($unclosed, 'unclosed_days')],
            'href' => $link(['view' => 'drivers', 'driver' => (int)$unclosed[0]['driver_id']]),
        ];
    }
    if ($openToday > 0) {
        $out[] = [
            'tone' => 'info',
            'key' => 'delivery_review.insight_open_today',
            'params' => ['count' => $openToday],
            'href' => $link(['view' => 'drivers'], '#delivery-review-needs'),
        ];
    }
    $unrouted = (int)($totals['unrouted'] ?? 0);
    if ($unrouted > 0) {
        $out[] = [
            'tone' => 'info',
            'key' => 'delivery_review.insight_standing_gap',
            'params' => [
                'count' => $unrouted,
                'expected' => (int)($totals['expected'] ?? 0),
                'stores' => (int)($totals['unrouted_customers'] ?? 0),
            ],
            'href' => $link(['view' => 'customers', 'filter' => 'short']),
        ];
    }
    $quietStores = (int)($totals['quiet_customers'] ?? 0);
    if ($quietStores > 0) {
        $out[] = [
            'tone' => 'info',
            'key' => 'delivery_review.insight_quiet_stores',
            'params' => ['count' => $quietStores],
            'href' => $link(['view' => 'customers', 'filter' => 'quiet']),
        ];
    }
    $offVisits = (int)($totals['off_visits'] ?? 0);
    if ($offVisits > 0) {
        $out[] = [
            'tone' => 'info',
            'key' => 'delivery_review.insight_off_driver',
            'params' => ['count' => $offVisits],
            'href' => $link(['view' => 'customers', 'filter' => 'off_driver']),
        ];
    }
    $problemTotal = array_sum($weekdays);
    if ($problemTotal >= 4 && count($weekdays) > 1) {
        arsort($weekdays);
        $topDow = (int)array_key_first($weekdays);
        $topCount = (int)$weekdays[$topDow];
        if (($topCount * 100) >= ($problemTotal * 40)) {
            $names = function_exists('bakery_day_names') ? bakery_day_names(false) : [];
            $out[] = [
                'tone' => 'info',
                'key' => 'delivery_review.insight_weekday',
                'params' => [
                    'day' => (string)($names[$topDow] ?? (string)$topDow),
                    'count' => $topCount,
                    'total' => $problemTotal,
                ],
                'href' => $link(['view' => 'drivers'], '#delivery-review-needs'),
            ];
        }
    }
    if ($out === []) {
        $out[] = [
            'tone' => 'success',
            'key' => 'delivery_review.insight_clear',
            'params' => [],
            'href' => '',
        ];
    }
    return $out;
}

const BAKERY_DELIVERY_MANAGER_REVIEW_WALL_DAYS = 14;
const BAKERY_DELIVERY_MANAGER_REVIEW_WALL_ROWS = 12;
const BAKERY_DELIVERY_MANAGER_REVIEW_CARD_STOPS = 4;
const BAKERY_DELIVERY_MANAGER_REVIEW_CARD_LIMIT = 6;

/**
 * @param list<array{date:string,state:string}> $cells
 */
function bakery_delivery_manager_review_wall_heat(array $cells): int
{
    $score = 0;
    foreach ($cells as $cell) {
        switch ((string)($cell['state'] ?? '')) {
            case 'problem':
                $score += 8;
                break;
            case 'photo_gap':
                $score += 4;
                break;
            case 'not_out':
                $score += 3;
                break;
            case 'cancelled':
                $score += 2;
                break;
            case 'covered':
                $score += 1;
                break;
        }
    }
    return $score;
}

/**
 * Weekday labels for the last two weeks of the strip.
 *
 * @param array<string, mixed> $report
 * @return list<array{date:string,label:string}>
 */
function bakery_delivery_manager_review_wall_dates(array $report): array
{
    $dates = bakery_delivery_manager_review_strip_dates(is_array($report['dates'] ?? null) ? $report['dates'] : []);
    $dates = array_values(array_slice($dates, -BAKERY_DELIVERY_MANAGER_REVIEW_WALL_DAYS));
    $names = function_exists('bakery_day_names') ? bakery_day_names(true) : [];
    $out = [];
    foreach ($dates as $date) {
        $date = (string)$date;
        $parsed = DateTime::createFromFormat('Y-m-d', $date);
        $label = $date;
        if ($parsed && $parsed->format('Y-m-d') === $date) {
            $dow = (int)$parsed->format('N');
            $short = trim((string)($names[$dow] ?? ''));
            $label = $short !== '' ? $short : $parsed->format('D');
        }
        $out[] = ['date' => $date, 'label' => $label];
    }
    return $out;
}

/**
 * Colored days. Hot rows first. A focused person is the only row.
 *
 * @param array<string, mixed> $report
 * @return array{kind:string,dates:list<array{date:string,label:string}>,rows:list<array<string,mixed>>,calm_rows:list<array<string,mixed>>,hidden:int}
 */
function bakery_delivery_manager_review_wall(array $report, string $kind, int $focusId = 0): array
{
    $dated = bakery_delivery_manager_review_wall_dates($report);
    $dates = array_column($dated, 'date');
    $from = (string)($report['from'] ?? '');
    $to = (string)($report['to'] ?? '');
    $look = [];
    $calm = [];
    $source = $kind === 'stores'
        ? (is_array($report['customers'] ?? null) ? $report['customers'] : [])
        : (is_array($report['drivers'] ?? null) ? $report['drivers'] : []);
    foreach ($source as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ($kind === 'stores') {
            $id = (int)($row['customer_id'] ?? 0);
            $name = (string)($row['name'] ?? '');
            $cells = bakery_delivery_manager_review_customer_strip($row, $dates);
            $href = bakery_delivery_manager_review_href([
                'view' => 'customers',
                'from' => $from,
                'to' => $to,
                'customer' => $id,
            ]);
        } else {
            $id = (int)($row['driver_id'] ?? 0);
            $name = (string)($row['driver_name'] ?? '');
            $cells = bakery_delivery_manager_review_driver_strip($row, $dates);
            $href = bakery_delivery_manager_review_href([
                'view' => 'drivers',
                'from' => $from,
                'to' => $to,
                'driver' => $id,
            ]);
        }
        if ($focusId > 0 && $id !== $focusId) {
            continue;
        }
        $item = [
            'id' => $id,
            'name' => $name,
            'href' => $href,
            'cells' => $cells,
            'heat' => bakery_delivery_manager_review_wall_heat($cells),
        ];
        if ($focusId > 0 || (int)$item['heat'] > 0) {
            $look[] = $item;
        } else {
            $calm[] = $item;
        }
    }
    $byHeat = static function (array $a, array $b): int {
        return (((int)$b['heat']) <=> ((int)$a['heat']))
            ?: strcasecmp((string)$a['name'], (string)$b['name']);
    };
    usort($look, $byHeat);
    usort($calm, static function (array $a, array $b): int {
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });
    $hidden = 0;
    if ($focusId === 0 && count($look) > BAKERY_DELIVERY_MANAGER_REVIEW_WALL_ROWS) {
        $hidden = count($look) - BAKERY_DELIVERY_MANAGER_REVIEW_WALL_ROWS;
        $look = array_slice($look, 0, BAKERY_DELIVERY_MANAGER_REVIEW_WALL_ROWS);
    }
    return [
        'kind' => $kind === 'stores' ? 'stores' : 'drivers',
        'dates' => $dated,
        'rows' => $look,
        'calm_rows' => $focusId > 0 ? [] : $calm,
        'hidden' => $hidden,
    ];
}

function bakery_delivery_manager_review_brief_tone(string $key, string $fallback = 'info'): string
{
    if (preg_match('/failed|open|missed|quiet|unassigned|stale/', $key) === 1) {
        return 'bad';
    }
    if (preg_match('/missing|short|bulk|photo/', $key) === 1) {
        return 'warn';
    }
    if (preg_match('/clear|on_track|extra/', $key) === 1) {
        return 'good';
    }
    return $fallback;
}

/**
 * The stops and stores a manager can act on. Photos and quiet stores stay one card each.
 *
 * @param array<string, mixed> $report
 * @return array{cards:list<array<string,mixed>>,more:int}
 */
function bakery_delivery_manager_review_cards(array $report, string $today): array
{
    $from = (string)($report['from'] ?? '');
    $to = (string)($report['to'] ?? '');
    $link = static function (array $params) use ($from, $to): string {
        return bakery_delivery_manager_review_href(['from' => $from, 'to' => $to] + $params);
    };
    $individuals = [];
    $missingBy = [];
    $openToday = 0;
    foreach ($report['needs'] ?? [] as $need) {
        if (!is_array($need)) {
            continue;
        }
        $reason = (string)($need['reason'] ?? '');
        $date = (string)($need['date'] ?? '');
        $driverId = (int)($need['driver_id'] ?? 0);
        $driverName = (string)($need['driver_name'] ?? '');
        $customerId = (int)($need['customer_id'] ?? 0);
        $customerName = (string)($need['customer_name'] ?? '');
        if ($reason === 'missing_after') {
            if (!isset($missingBy[$driverId])) {
                $missingBy[$driverId] = [
                    'driver_id' => $driverId,
                    'driver_name' => $driverName,
                    'count' => 0,
                    'date' => $date,
                    'photo_url' => (string)($need['photo_url'] ?? ''),
                ];
            }
            $missingBy[$driverId]['count']++;
            if ($date > (string)$missingBy[$driverId]['date']) {
                $missingBy[$driverId]['date'] = $date;
            }
            continue;
        }
        if ($reason === 'open' && $date >= $today) {
            $openToday++;
            continue;
        }
        $failed = $reason === 'failed';
        $individuals[] = [
            'rank' => $failed ? 1 : 0,
            'sort_date' => $date,
            'tone' => 'bad',
            'eyebrow_key' => $failed ? 'delivery_review.eyebrow_failed' : 'delivery_review.eyebrow_open',
            'title' => $customerName,
            'title_key' => '',
            'title_params' => [],
            'sentence_key' => $failed ? 'delivery_review.card_failed' : 'delivery_review.card_open',
            'sentence_params' => ['driver' => $driverName],
            'when' => $date,
            'review_href' => $link(['view' => 'drivers', 'driver' => $driverId, 'day' => $date]) . '#day-' . $date,
            'action' => 'sheet',
            'action_date' => $date,
            'action_driver' => $driverId,
            'action_customer' => $customerId,
            'photo_url' => '',
        ];
    }
    foreach ($report['customers'] ?? [] as $customer) {
        if (!is_array($customer) || (int)($customer['unassigned'] ?? 0) <= 0) {
            continue;
        }
        $when = '';
        foreach ($customer['visits'] ?? [] as $visit) {
            if (!is_array($visit) || (string)($visit['status'] ?? '') !== 'unassigned') {
                continue;
            }
            $date = (string)($visit['date'] ?? '');
            if ($date !== '' && ($when === '' || $date > $when)) {
                $when = $date;
            }
        }
        $customerId = (int)($customer['customer_id'] ?? 0);
        $individuals[] = [
            'rank' => 2,
            'sort_date' => $when,
            'tone' => 'bad',
            'eyebrow_key' => 'delivery_review.eyebrow_unassigned',
            'title' => (string)($customer['name'] ?? ''),
            'title_key' => '',
            'title_params' => [],
            'sentence_key' => 'delivery_review.card_unassigned',
            'sentence_params' => [],
            'when' => $when,
            'review_href' => $link(['view' => 'customers', 'customer' => $customerId, 'filter' => 'unassigned']),
            'action' => $when !== '' ? 'assign' : 'record',
            'action_date' => $when,
            'action_driver' => 0,
            'action_customer' => $customerId,
            'photo_url' => '',
        ];
    }
    usort($individuals, static function (array $a, array $b): int {
        $rank = ((int)$a['rank']) <=> ((int)$b['rank']);
        if ($rank !== 0) {
            return $rank;
        }
        $date = ((int)$a['rank']) === 0
            ? strcmp((string)$a['sort_date'], (string)$b['sort_date'])
            : strcmp((string)$b['sort_date'], (string)$a['sort_date']);
        return $date !== 0 ? $date : strcasecmp((string)$a['title'], (string)$b['title']);
    });

    $summaries = [];
    if ($missingBy !== []) {
        $missingRows = array_values($missingBy);
        $missingCount = 0;
        foreach ($missingRows as $row) {
            $missingCount += (int)$row['count'];
        }
        usort($missingRows, static function (array $a, array $b): int {
            return (((int)$b['count']) <=> ((int)$a['count']))
                ?: strcasecmp((string)$a['driver_name'], (string)$b['driver_name']);
        });
        $top = $missingRows[0];
        $summaries[] = [
            'tone' => 'warn',
            'eyebrow_key' => 'delivery_review.eyebrow_photo',
            'title' => (string)$top['driver_name'],
            'title_key' => '',
            'title_params' => [],
            'sentence_key' => 'delivery_review.insight_missing_after',
            'sentence_params' => [
                'count' => $missingCount,
                'driver' => (string)$top['driver_name'],
                'most' => (int)$top['count'],
            ],
            'when' => (string)$top['date'],
            'review_href' => $link(['view' => 'drivers', 'driver' => (int)$top['driver_id']]) . '#delivery-review-photos',
            'action' => 'photos',
            'action_date' => (string)$top['date'],
            'action_driver' => (int)$top['driver_id'],
            'action_customer' => 0,
            'photo_url' => (string)$top['photo_url'],
        ];
    }
    $quiet = [];
    foreach ($report['customers'] ?? [] as $customer) {
        if (is_array($customer) && !empty(bakery_delivery_manager_review_customer_flags($customer)['quiet'])) {
            $quiet[] = $customer;
        }
    }
    if ($quiet !== []) {
        usort($quiet, static function (array $a, array $b): int {
            return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });
        if (count($quiet) === 1) {
            $reading = bakery_delivery_manager_review_customer_reading($quiet[0]);
            $customerId = (int)($quiet[0]['customer_id'] ?? 0);
            $summaries[] = [
                'tone' => 'bad',
                'eyebrow_key' => 'delivery_review.eyebrow_quiet',
                'title' => (string)($quiet[0]['name'] ?? ''),
                'title_key' => '',
                'title_params' => [],
                'sentence_key' => (string)$reading['key'],
                'sentence_params' => is_array($reading['params'] ?? null) ? $reading['params'] : [],
                'when' => '',
                'review_href' => $link(['view' => 'customers', 'customer' => $customerId, 'filter' => 'quiet']),
                'action' => 'standing',
                'action_date' => '',
                'action_driver' => 0,
                'action_customer' => $customerId,
                'photo_url' => '',
            ];
        } else {
            $summaries[] = [
                'tone' => 'bad',
                'eyebrow_key' => 'delivery_review.eyebrow_quiet',
                'title' => '',
                'title_key' => 'delivery_review.quiet_title',
                'title_params' => ['count' => count($quiet)],
                'sentence_key' => 'delivery_review.card_quiet',
                'sentence_params' => [],
                'when' => '',
                'review_href' => $link(['view' => 'customers', 'filter' => 'quiet']),
                'action' => 'standing',
                'action_date' => '',
                'action_driver' => 0,
                'action_customer' => 0,
                'photo_url' => '',
            ];
        }
    }
    if ($openToday > 0) {
        $summaries[] = [
            'tone' => 'info',
            'eyebrow_key' => 'delivery_review.eyebrow_today',
            'title' => '',
            'title_key' => 'delivery_review.today',
            'title_params' => [],
            'sentence_key' => 'delivery_review.insight_open_today',
            'sentence_params' => ['count' => $openToday],
            'when' => $today,
            'review_href' => $link(['view' => 'drivers']) . '#delivery-review-needs',
            'action' => 'sheet',
            'action_date' => $today,
            'action_driver' => 0,
            'action_customer' => 0,
            'photo_url' => '',
        ];
    }

    $shown = array_slice($individuals, 0, BAKERY_DELIVERY_MANAGER_REVIEW_CARD_STOPS);
    $more = count($individuals) - count($shown);
    foreach ($summaries as $card) {
        if (count($shown) >= BAKERY_DELIVERY_MANAGER_REVIEW_CARD_LIMIT) {
            $more++;
            continue;
        }
        $shown[] = $card;
    }
    $cards = [];
    foreach ($shown as $card) {
        unset($card['rank'], $card['sort_date']);
        $cards[] = $card;
    }
    return ['cards' => $cards, 'more' => $more];
}

/**
 * The front door: one sentence, the cards, and the day wall.
 *
 * @param array<string, mixed> $report
 * @return array{tone:string,lead:array{key:string,params:array<string,mixed>},cards:list<array<string,mixed>>,more:int,wall:array<string,mixed>}
 */
function bakery_delivery_manager_review_brief(array $report, string $today, int $focusDriver = 0, int $focusCustomer = 0, string $wallKind = 'drivers'): array
{
    $lead = ['key' => 'delivery_review.insight_clear', 'params' => []];
    $tone = 'good';
    $cards = ['cards' => [], 'more' => 0];
    if ($focusDriver > 0) {
        foreach ($report['drivers'] ?? [] as $driver) {
            if (!is_array($driver) || (int)($driver['driver_id'] ?? 0) !== $focusDriver) {
                continue;
            }
            $lines = bakery_delivery_manager_review_driver_attention($driver);
            $lead = $lines[0];
            $tone = bakery_delivery_manager_review_brief_tone((string)$lead['key']);
            break;
        }
        $wall = bakery_delivery_manager_review_wall($report, 'drivers', $focusDriver);
    } elseif ($focusCustomer > 0) {
        foreach ($report['customers'] ?? [] as $customer) {
            if (!is_array($customer) || (int)($customer['customer_id'] ?? 0) !== $focusCustomer) {
                continue;
            }
            $reading = bakery_delivery_manager_review_customer_reading($customer);
            $lead = [
                'key' => (string)$reading['key'],
                'params' => is_array($reading['params'] ?? null) ? $reading['params'] : [],
            ];
            $tone = bakery_delivery_manager_review_brief_tone((string)$lead['key']);
            break;
        }
        $wall = bakery_delivery_manager_review_wall($report, 'stores', $focusCustomer);
    } else {
        $insights = bakery_delivery_manager_review_insights($report, $today);
        $lead = [
            'key' => (string)($insights[0]['key'] ?? 'delivery_review.insight_clear'),
            'params' => is_array($insights[0]['params'] ?? null) ? $insights[0]['params'] : [],
        ];
        $insightTone = (string)($insights[0]['tone'] ?? '');
        if ($insightTone === 'danger') {
            $tone = 'bad';
        } elseif ($insightTone === 'warning') {
            $tone = 'warn';
        } elseif ($insightTone === 'success') {
            $tone = 'good';
        } else {
            $tone = 'info';
        }
        $cards = bakery_delivery_manager_review_cards($report, $today);
        $wall = bakery_delivery_manager_review_wall($report, $wallKind === 'stores' ? 'stores' : 'drivers', 0);
    }
    return [
        'tone' => $tone,
        'lead' => $lead,
        'cards' => $cards['cards'],
        'more' => (int)$cards['more'],
        'wall' => $wall,
    ];
}
