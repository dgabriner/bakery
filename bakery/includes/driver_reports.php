<?php
/**
 * Read-only driver reports for managers and administrators.
 *
 * End of day and the one-page sheet reuse the photo journey and closeout
 * helpers. Tomorrow's plan reuses route deliveries, unassigned orders, and
 * standing stops missing from the dated route. Nothing here writes.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/route_photo_journey.php';
require_once __DIR__ . '/product_inventory.php';
require_once __DIR__ . '/driver_assignments.php';

/**
 * Same gate as Route photo journey. Logged-out callers get a login redirect.
 *
 * @param array<string, mixed>|null $user
 */
function bakery_driver_reports_access_status(?array $user): int
{
    return bakery_route_photo_journey_access_status($user);
}

function bakery_driver_reports_parse_tab(string $raw): string
{
    switch (strtolower(trim($raw))) {
        case 'sheet':
            return 'sheet';
        case 'plan':
            return 'plan';
        case 'summary':
            return 'summary';
        default:
            return 'summary';
    }
}

function bakery_driver_reports_default_date(string $tab, string $today): string
{
    $tab = bakery_driver_reports_parse_tab($tab);
    $today = route_manager_parse_date($today, date('Y-m-d'));
    if ($tab === 'plan') {
        return (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
    }
    return $today;
}

/**
 * Summary and the one-page sheet share a date. The plan tab keeps its own date
 * and opens on tomorrow when you arrive from another tab.
 */
function bakery_driver_reports_tab_date(string $target, string $current, string $currentDate, string $today): string
{
    $target = bakery_driver_reports_parse_tab($target);
    $current = bakery_driver_reports_parse_tab($current);
    $sameFamily = ($target === 'plan' && $current === 'plan') || ($target !== 'plan' && $current !== 'plan');
    $currentDate = route_manager_parse_date($currentDate, '');
    if ($sameFamily && $currentDate !== '') {
        return $currentDate;
    }
    return bakery_driver_reports_default_date($target, $today);
}

function bakery_driver_reports_query(string $tab, string $date): string
{
    $base = defined('BASE_URL') ? (string)BASE_URL : '';
    return $base . 'driver_reports.php?' . http_build_query([
        'tab' => bakery_driver_reports_parse_tab($tab),
        'date' => $date,
    ]);
}

function bakery_driver_reports_time_or_null($value): ?string
{
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return null;
    }
    if (strtotime($value) === false) {
        return null;
    }
    return $value;
}

/**
 * @return array{min:int,max:int}
 */
function bakery_driver_reports_minutes_per_stop(): array
{
    return ['min' => 30, 'max' => 45];
}

function bakery_driver_reports_day_minutes(): int
{
    return 8 * 60;
}

function bakery_driver_reports_stop_mark(array $stop): string
{
    $photos = $stop['photos'] ?? [];
    $hasPhoto = is_array($photos) && $photos !== [];
    if (!$hasPhoto && (!empty($stop['has_pod_photo']) || !empty($stop['has_arrival_photo']))) {
        $hasPhoto = true;
    }
    if ($hasPhoto) {
        return 'with_photo';
    }
    $status = (string)($stop['delivery_status'] ?? '');
    $departure = bakery_driver_reports_time_or_null($stop['delivered_at'] ?? null);
    $badge = (string)($stop['badge'] ?? '');
    if ($status === 'delivered' || ($departure !== null && $badge !== 'arrived_open')) {
        return 'delivered_no_photo';
    }
    return 'no_info';
}

function bakery_driver_reports_mark_key(string $mark): string
{
    switch ($mark) {
        case 'with_photo':
            return 'driver_reports.mark_with_photo';
        case 'delivered_no_photo':
            return 'driver_reports.mark_delivered_no_photo';
        case 'no_info':
            return 'driver_reports.mark_no_info';
        default:
            return 'driver_reports.mark_no_info';
    }
}

/**
 * Arrival order first. Stops with no recorded arrival follow in plan order
 * and stay unnumbered. Missing times stay blank.
 *
 * @param array<int, array<string, mixed>> $stops
 * @return list<array<string, mixed>>
 */
function bakery_driver_reports_sort_driven(array $stops): array
{
    $withArrival = [];
    $withoutArrival = [];
    foreach ($stops as $stop) {
        if (!is_array($stop)) {
            continue;
        }
        if (bakery_driver_reports_time_or_null($stop['arrival_at'] ?? null) !== null) {
            $withArrival[] = $stop;
        } else {
            $withoutArrival[] = $stop;
        }
    }
    $byArrival = static function (array $a, array $b): int {
        $ta = strtotime((string)bakery_driver_reports_time_or_null($a['arrival_at'] ?? null)) ?: 0;
        $tb = strtotime((string)bakery_driver_reports_time_or_null($b['arrival_at'] ?? null)) ?: 0;
        if ($ta !== $tb) {
            return $ta <=> $tb;
        }
        $name = strcasecmp((string)($a['customer_name'] ?? ''), (string)($b['customer_name'] ?? ''));
        if ($name !== 0) {
            return $name;
        }
        return ((int)($a['customer_id'] ?? 0)) <=> ((int)($b['customer_id'] ?? 0));
    };
    $byPlan = static function (array $a, array $b): int {
        $order = ((int)($a['route_order'] ?? 0)) <=> ((int)($b['route_order'] ?? 0));
        if ($order !== 0) {
            return $order;
        }
        return strcasecmp((string)($a['customer_name'] ?? ''), (string)($b['customer_name'] ?? ''));
    };
    usort($withArrival, $byArrival);
    usort($withoutArrival, $byPlan);

    $ordered = [];
    $driven = 0;
    foreach (array_merge($withArrival, $withoutArrival) as $stop) {
        $arrival = bakery_driver_reports_time_or_null($stop['arrival_at'] ?? null);
        $number = null;
        if ($arrival !== null) {
            $driven++;
            $number = $driven;
        }
        $ordered[] = [
            'customer_id' => (int)($stop['customer_id'] ?? 0),
            'customer_name' => (string)($stop['customer_name'] ?? ''),
            'route_order' => (int)($stop['route_order'] ?? 0),
            'arrival_at' => $arrival,
            'delivered_at' => bakery_driver_reports_time_or_null($stop['delivered_at'] ?? null),
            'badge' => (string)($stop['badge'] ?? ''),
            'delivery_status' => (string)($stop['delivery_status'] ?? ''),
            'photos' => bakery_driver_reports_public_photos(is_array($stop['photos'] ?? null) ? $stop['photos'] : []),
            'driven_order' => $number,
            'mark' => bakery_driver_reports_stop_mark($stop),
        ];
    }
    return $ordered;
}

/**
 * @param array<int, array<string, mixed>> $photos
 * @return list<array{id:int,photo_type:string,file_path:string,url:string,created_at:string}>
 */
function bakery_driver_reports_public_photos(array $photos): array
{
    $out = [];
    foreach ($photos as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        $out[] = [
            'id' => (int)($photo['id'] ?? 0),
            'photo_type' => (string)($photo['photo_type'] ?? ($photo['_photo_type'] ?? '')),
            'file_path' => (string)($photo['file_path'] ?? ''),
            'url' => (string)($photo['url'] ?? ''),
            'created_at' => (string)($photo['created_at'] ?? ''),
        ];
    }
    return $out;
}

function bakery_driver_reports_stop_label(array $stop): string
{
    $params = [
        'store' => (string)($stop['customer_name'] ?? ''),
        'plan' => (int)($stop['route_order'] ?? 0),
    ];
    $order = $stop['driven_order'] ?? null;
    if ($order !== null && (int)$order > 0) {
        $params['order'] = (int)$order;
        return bakery_t('driver_reports.stop_line', $params);
    }
    return bakery_t('driver_reports.stop_line_unnumbered', $params);
}

/**
 * @param array<int, array<string, mixed>> $stops
 * @return list<string>
 */
function bakery_driver_reports_bulk_stamp_names(array $stops): array
{
    $stamped = [];
    foreach ($stops as $stop) {
        if (!is_array($stop)) {
            continue;
        }
        if ((string)($stop['badge'] ?? '') === 'arrived_open') {
            continue;
        }
        $at = bakery_driver_reports_time_or_null($stop['delivered_at'] ?? null);
        if ($at === null) {
            continue;
        }
        $stamped[] = [
            'name' => (string)($stop['customer_name'] ?? ''),
            'ts' => strtotime($at) ?: 0,
        ];
    }
    usort($stamped, static function (array $a, array $b): int {
        if ($a['ts'] !== $b['ts']) {
            return $a['ts'] <=> $b['ts'];
        }
        return strcasecmp($a['name'], $b['name']);
    });
    $flagged = [];
    $count = count($stamped);
    for ($i = 0; $i < $count; $i++) {
        $cluster = [$stamped[$i]];
        for ($j = $i + 1; $j < $count; $j++) {
            if (($stamped[$j]['ts'] - $stamped[$i]['ts']) <= 300) {
                $cluster[] = $stamped[$j];
                continue;
            }
            break;
        }
        if (count($cluster) < 3) {
            continue;
        }
        foreach ($cluster as $row) {
            if ($row['name'] !== '' && !in_array($row['name'], $flagged, true)) {
                $flagged[] = $row['name'];
            }
        }
    }
    return $flagged;
}

/**
 * @param array<int, array<string, mixed>> $stops
 * @param array<string, mixed>|null $closeout
 * @return array{
 *   arrived_not_closed:list<string>,
 *   bulk_stamps:list<string>,
 *   never_visited:list<string>,
 *   no_pickup_load:bool,
 *   route_not_closed:bool
 * }
 */
function bakery_driver_reports_flags(array $stops, ?array $closeout): array
{
    $arrived = [];
    $never = [];
    foreach ($stops as $stop) {
        if (!is_array($stop)) {
            continue;
        }
        $name = (string)($stop['customer_name'] ?? '');
        if ($name === '') {
            continue;
        }
        $badge = (string)($stop['badge'] ?? '');
        $status = (string)($stop['delivery_status'] ?? '');
        if ($badge === 'arrived_open' || (string)($stop['visit_state'] ?? '') === 'arrived_not_closed') {
            $arrived[] = $name;
        }
        $cancelled = $status === 'cancelled' || !empty($stop['cancelled']);
        $arrival = bakery_driver_reports_time_or_null($stop['arrival_at'] ?? null);
        $departure = bakery_driver_reports_time_or_null($stop['delivered_at'] ?? null);
        $visited = $arrival !== null
            || $departure !== null
            || in_array($status, ['delivered', 'failed'], true)
            || in_array($badge, ['pod', 'arrival', 'arrived_open'], true);
        if (!$cancelled && !$visited) {
            $never[] = $name;
        }
    }
    $hasStops = $stops !== [];
    $hasLoad = is_array($closeout) && ((int)($closeout['loaded_units'] ?? 0) > 0 || !empty($closeout['load_id']));
    $closed = is_array($closeout) && !empty($closeout['is_reconciled']);
    return [
        'arrived_not_closed' => $arrived,
        'bulk_stamps' => bakery_driver_reports_bulk_stamp_names($stops),
        'never_visited' => $never,
        'no_pickup_load' => $hasStops && !$hasLoad,
        'route_not_closed' => $hasStops && !$closed,
    ];
}

/**
 * @param array<string, mixed> $flags
 * @return list<string>
 */
function bakery_driver_reports_flag_lines(array $flags): array
{
    $lines = [];
    $arrived = $flags['arrived_not_closed'] ?? [];
    if (is_array($arrived) && $arrived !== []) {
        $lines[] = bakery_t('driver_reports.flag_arrived_not_closed', [
            'stores' => implode(', ', $arrived),
        ]);
    }
    $bulk = $flags['bulk_stamps'] ?? [];
    if (is_array($bulk) && $bulk !== []) {
        $lines[] = bakery_t('driver_reports.flag_bulk_stamps', [
            'stores' => implode(', ', $bulk),
        ]);
    }
    $never = $flags['never_visited'] ?? [];
    if (is_array($never) && $never !== []) {
        $lines[] = bakery_t('driver_reports.flag_never_visited', [
            'stores' => implode(', ', $never),
        ]);
    }
    if (!empty($flags['no_pickup_load'])) {
        $lines[] = bakery_t('driver_reports.flag_no_pickup');
    }
    if (!empty($flags['route_not_closed'])) {
        $lines[] = bakery_t('driver_reports.flag_route_open');
    }
    return $lines;
}

/**
 * @param array<string, mixed> $journeyDriver
 * @param array<int, array<string, mixed>> $closeoutStops
 * @param array<string, mixed>|null $closeoutRow
 * @return array<string, mixed>
 */
function bakery_driver_reports_summary_driver(array $journeyDriver, array $closeoutStops, ?array $closeoutRow): array
{
    $byCustomer = [];
    foreach ($closeoutStops as $row) {
        if (!is_array($row)) {
            continue;
        }
        $byCustomer[(int)($row['customer_id'] ?? 0)] = $row;
    }
    $stops = [];
    foreach ($journeyDriver['stops'] ?? [] as $stop) {
        if (!is_array($stop)) {
            continue;
        }
        $customerId = (int)($stop['customer_id'] ?? 0);
        $extra = $byCustomer[$customerId] ?? null;
        if (is_array($extra) && trim((string)($stop['delivery_status'] ?? '')) === '') {
            $stop['delivery_status'] = (string)($extra['delivery_status'] ?? '');
        }
        $stops[] = $stop;
    }
    $ordered = bakery_driver_reports_sort_driven($stops);
    return [
        'id' => (int)($journeyDriver['id'] ?? 0),
        'name' => (string)($journeyDriver['name'] ?? ''),
        'stops' => $ordered,
        'flags' => bakery_driver_reports_flags($ordered, $closeoutRow),
    ];
}

/**
 * Every driver on the journey. Managers are not scoped to one driver.
 *
 * @param array<string, mixed> $journey
 * @param array<int, array<int, array<string, mixed>>> $closeoutStopsByDriver
 * @param array<int, array<string, mixed>> $boardByDriver
 * @return list<array<string, mixed>>
 */
function bakery_driver_reports_summary_from_journey(array $journey, array $closeoutStopsByDriver, array $boardByDriver): array
{
    $drivers = [];
    foreach ($journey['drivers'] ?? [] as $driver) {
        if (!is_array($driver)) {
            continue;
        }
        $id = (int)($driver['id'] ?? 0);
        $stops = $closeoutStopsByDriver[$id] ?? [];
        $row = $boardByDriver[$id] ?? null;
        $drivers[] = bakery_driver_reports_summary_driver(
            $driver,
            is_array($stops) ? $stops : [],
            is_array($row) ? $row : null
        );
    }
    return $drivers;
}

/**
 * Proof or departure photo first, then arrival. URLs go through driver_photo.php.
 *
 * @param array<int, array<string, mixed>> $photos
 * @return array{id:int,url:string,kind:string}|null
 */
function bakery_driver_reports_thumbnail(array $photos): ?array
{
    $normalized = [];
    foreach ($photos as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        $type = (string)($photo['photo_type'] ?? ($photo['_photo_type'] ?? ''));
        $photo['photo_type'] = $type;
        $normalized[] = $photo;
    }
    $classified = bakery_route_summary_classify_photos($normalized);
    $chosen = null;
    $kind = '';
    foreach ($classified['pod'] as $photo) {
        if (strtolower((string)($photo['photo_type'] ?? '')) === 'after') {
            $chosen = $photo;
            $kind = 'proof';
            break;
        }
    }
    if ($chosen === null && $classified['pod'] !== []) {
        $chosen = $classified['pod'][0];
        $kind = 'proof';
    }
    if ($chosen === null && $classified['arrival'] !== []) {
        $chosen = $classified['arrival'][0];
        $kind = 'arrival';
    }
    if (!is_array($chosen)) {
        return null;
    }
    $relative = bakery_driver_photo_relative_path((string)($chosen['file_path'] ?? ''));
    if ($relative === '') {
        return null;
    }
    $url = bakery_driver_photo_url((string)($chosen['file_path'] ?? ''));
    if ($url === '' || strpos($url, 'driver_photo.php') === false || strpos($url, 'uploads/driver_photos') !== false) {
        return null;
    }
    return [
        'id' => (int)($chosen['id'] ?? 0),
        'url' => $url,
        'kind' => $kind,
    ];
}

/**
 * @param array<string, mixed> $stop
 * @param array<string, mixed>|null $previous
 * @return array<string, mixed>
 */
function bakery_driver_reports_onepager_stop(array $stop, ?array $previous): array
{
    $arrival = bakery_driver_reports_time_or_null($stop['arrival_at'] ?? null);
    $departure = bakery_driver_reports_time_or_null($stop['delivered_at'] ?? null);
    if ((string)($stop['badge'] ?? '') === 'arrived_open') {
        $departure = null;
    }
    $atStop = null;
    if ($arrival !== null && $departure !== null) {
        $atStop = bakery_route_photo_journey_minutes($arrival, $departure);
    }
    $since = null;
    if (is_array($previous)) {
        $prevDeparture = bakery_driver_reports_time_or_null($previous['delivered_at'] ?? null);
        if ((string)($previous['badge'] ?? '') === 'arrived_open') {
            $prevDeparture = null;
        }
        if ($arrival !== null && $prevDeparture !== null) {
            $since = bakery_route_photo_journey_minutes($prevDeparture, $arrival);
        }
    }
    $photos = $stop['photos'] ?? [];
    return [
        'customer_id' => (int)($stop['customer_id'] ?? 0),
        'customer_name' => (string)($stop['customer_name'] ?? ''),
        'route_order' => (int)($stop['route_order'] ?? 0),
        'driven_order' => isset($stop['driven_order']) && $stop['driven_order'] !== null ? (int)$stop['driven_order'] : null,
        'arrival_at' => $arrival,
        'departure_at' => $departure,
        'minutes_at_stop' => $atStop,
        'minutes_since_previous' => $since,
        'thumbnail' => bakery_driver_reports_thumbnail(is_array($photos) ? $photos : []),
        'mark' => (string)($stop['mark'] ?? bakery_driver_reports_stop_mark($stop)),
    ];
}

/**
 * @param array<string, mixed> $summaryDriver
 * @return array<string, mixed>
 */
function bakery_driver_reports_onepager_driver(array $summaryDriver): array
{
    $rows = [];
    $previous = null;
    foreach ($summaryDriver['stops'] ?? [] as $stop) {
        if (!is_array($stop)) {
            continue;
        }
        $rows[] = bakery_driver_reports_onepager_stop($stop, $previous);
        $previous = $stop;
    }
    return [
        'id' => (int)($summaryDriver['id'] ?? 0),
        'name' => (string)($summaryDriver['name'] ?? ''),
        'stops' => $rows,
    ];
}

function bakery_driver_reports_minutes_label(?int $minutes): string
{
    if ($minutes === null) {
        return '';
    }
    return bakery_route_photo_journey_duration_label($minutes);
}

/**
 * @param array<int, array<string, mixed>> $driversData
 * @param array<int, array<string, mixed>> $unassigned
 * @param array<int, array<string, mixed>> $missingStanding
 * @return array{drivers:list<array<string,mixed>>,no_van:list<array<string,mixed>>,faltan:list<array<string,mixed>>}
 */
function bakery_driver_reports_plan_build(array $driversData, array $unassigned, array $missingStanding): array
{
    $window = bakery_driver_reports_minutes_per_stop();
    $dayMinutes = bakery_driver_reports_day_minutes();
    $going = [];
    foreach ($driversData as $driver) {
        if (!is_array($driver)) {
            continue;
        }
        foreach ($driver['deliveries'] ?? [] as $delivery) {
            if (!is_array($delivery)) {
                continue;
            }
            $customerId = (int)($delivery['customer_id'] ?? 0);
            $pieces = (int)($delivery['item_count'] ?? ($delivery['pieces'] ?? 0));
            if ($customerId > 0 && $pieces > 0) {
                $going[$customerId] = true;
            }
        }
    }

    $unassignedIds = [];
    $faltan = [];
    foreach ($unassigned as $row) {
        if (!is_array($row)) {
            continue;
        }
        $customerId = (int)($row['id'] ?? ($row['customer_id'] ?? 0));
        $name = trim((string)($row['name'] ?? ($row['customer_name'] ?? '')));
        if ($customerId <= 0 || $name === '') {
            continue;
        }
        $unassignedIds[$customerId] = true;
        $faltan[] = [
            'customer_id' => $customerId,
            'name' => $name,
        ];
    }

    $noVan = [];
    $seenNoVan = [];
    $addNoVan = static function (int $customerId, string $name, int $routeOrder) use (&$noVan, &$seenNoVan, $going, $unassignedIds): void {
        $name = trim($name);
        if ($customerId <= 0 || $name === '' || isset($going[$customerId]) || isset($unassignedIds[$customerId]) || isset($seenNoVan[$customerId])) {
            return;
        }
        $seenNoVan[$customerId] = true;
        $noVan[] = [
            'customer_id' => $customerId,
            'customer_name' => $name,
            'route_order' => $routeOrder,
        ];
    };

    $drivers = [];
    foreach ($driversData as $driver) {
        if (!is_array($driver)) {
            continue;
        }
        $stops = [];
        foreach ($driver['deliveries'] ?? [] as $delivery) {
            if (!is_array($delivery)) {
                continue;
            }
            $customerId = (int)($delivery['customer_id'] ?? 0);
            $name = (string)($delivery['customer_name'] ?? '');
            $routeOrder = (int)($delivery['route_order'] ?? 0);
            $pieces = (int)($delivery['item_count'] ?? ($delivery['pieces'] ?? 0));
            if ($customerId <= 0) {
                continue;
            }
            if ($pieces > 0) {
                $stops[] = [
                    'customer_id' => $customerId,
                    'customer_name' => $name,
                    'route_order' => $routeOrder,
                ];
                continue;
            }
            $addNoVan($customerId, $name, $routeOrder);
        }
        if ($stops === []) {
            continue;
        }
        usort($stops, static function (array $a, array $b): int {
            $order = ((int)$a['route_order']) <=> ((int)$b['route_order']);
            if ($order !== 0) {
                return $order;
            }
            return strcasecmp((string)$a['customer_name'], (string)$b['customer_name']);
        });
        $count = count($stops);
        $maxMinutes = $count * (int)$window['max'];
        $drivers[] = [
            'id' => (int)($driver['id'] ?? 0),
            'name' => (string)($driver['name'] ?? ''),
            'stops' => $stops,
            'stop_count' => $count,
            'minutes_min' => $count * (int)$window['min'],
            'minutes_max' => $maxMinutes,
            'overloaded' => $maxMinutes > $dayMinutes,
        ];
    }

    foreach ($missingStanding as $group) {
        if (!is_array($group)) {
            continue;
        }
        foreach ($group['stops'] ?? [] as $stop) {
            if (!is_array($stop)) {
                continue;
            }
            $addNoVan(
                (int)($stop['customer_id'] ?? 0),
                (string)($stop['customer_name'] ?? ''),
                (int)($stop['route_order'] ?? 0)
            );
        }
    }

    return [
        'drivers' => $drivers,
        'no_van' => $noVan,
        'faltan' => $faltan,
    ];
}

function bakery_driver_reports_plan_stop_label(array $stop): string
{
    $name = (string)($stop['customer_name'] ?? ($stop['name'] ?? ''));
    $order = (int)($stop['route_order'] ?? 0);
    if ($order <= 0) {
        return $name;
    }
    return bakery_t('driver_reports.plan_stop', [
        'store' => $name,
        'plan' => $order,
    ]);
}

/**
 * @return array{date:string,drivers:list<array<string,mixed>>}
 */
function bakery_driver_reports_load_summary(PDO $db, string $date): array
{
    $date = route_manager_parse_date($date, date('Y-m-d'));
    $journey = bakery_route_photo_journey_load($db, $date, 0);
    $boardByDriver = [];
    if (bakery_inventory_closeout_ready($db)) {
        foreach (bakery_inventory_closeout_board($db, $date) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $boardByDriver[(int)($row['driver_id'] ?? 0)] = $row;
        }
    }
    $closeoutByDriver = [];
    foreach ($journey['drivers'] ?? [] as $driver) {
        if (!is_array($driver)) {
            continue;
        }
        $id = (int)($driver['id'] ?? 0);
        if ($id > 0) {
            $closeoutByDriver[$id] = bakery_route_closeout_stops($db, $date, $id);
        }
    }
    return [
        'date' => $date,
        'drivers' => bakery_driver_reports_summary_from_journey($journey, $closeoutByDriver, $boardByDriver),
    ];
}

/**
 * @return array{date:string,drivers:list<array<string,mixed>>}
 */
function bakery_driver_reports_load_sheet(PDO $db, string $date): array
{
    $summary = bakery_driver_reports_load_summary($db, $date);
    $drivers = [];
    foreach ($summary['drivers'] as $driver) {
        $drivers[] = bakery_driver_reports_onepager_driver($driver);
    }
    return [
        'date' => (string)$summary['date'],
        'drivers' => $drivers,
    ];
}

/**
 * @return array{date:string,drivers:list<array<string,mixed>>,no_van:list<array<string,mixed>>,faltan:list<array<string,mixed>>}
 */
function bakery_driver_reports_load_plan(PDO $db, string $date): array
{
    $date = route_manager_parse_date($date, date('Y-m-d'));
    $plan = bakery_driver_reports_plan_build(
        route_manager_fetch_deliveries($db, $date, []),
        bakery_unassigned_for_date($db, $date),
        bakery_route_missing_standing_by_driver($db, $date)
    );
    $plan['date'] = $date;
    return $plan;
}
