<?php
/**
 * Route Summary — photo-first day review for the Route Manager.
 *
 * Read-only assembly of dated assignments, delivery snapshots, and photos.
 * Amounts come from Route Manager snapshot/cash helpers, never live catalog prices.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/route_manager.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/photo_capture.php';

/**
 * @return array<string, string>
 */
function bakery_route_summary_filters(): array
{
    return [
        'all' => 'route_summary.filter_all',
        'photos' => 'route_summary.filter_photos',
        'missing' => 'route_summary.filter_missing',
        'delivered' => 'route_summary.filter_delivered',
        'pending' => 'route_summary.filter_pending',
    ];
}

function bakery_route_summary_parse_filter($raw): string
{
    $filter = strtolower(trim((string)$raw));
    return array_key_exists($filter, bakery_route_summary_filters()) ? $filter : 'all';
}

/**
 * Prefer the departure (After) photo as the hero proof-of-delivery image.
 *
 * @param array<int, array<string, mixed>> $photos
 * @return array<string, mixed>|null
 */
function bakery_route_summary_choose_hero_photo(array $photos): ?array
{
    if ($photos === []) {
        return null;
    }

    $preferred = ['after', 'before', 'receipt'];
    foreach ($preferred as $type) {
        foreach ($photos as $photo) {
            if (strtolower((string)($photo['photo_type'] ?? '')) === $type) {
                return $photo;
            }
        }
    }

    return $photos[0];
}

function bakery_route_summary_sold_amount(array $delivery): float
{
    return route_manager_estimate_amount($delivery);
}

function bakery_route_summary_pieces(array $delivery): int
{
    $status = (string)($delivery['delivery_status'] ?? 'pending');
    if ($status === 'delivered' && isset($delivery['delivered_pieces']) && $delivery['delivered_pieces'] !== null && $delivery['delivered_pieces'] !== '') {
        return (int)$delivery['delivered_pieces'];
    }
    return (int)($delivery['item_count'] ?? 0);
}

function bakery_route_summary_counts_toward_sold(array $delivery): bool
{
    $status = (string)($delivery['delivery_status'] ?? 'pending');
    return $status !== 'cancelled' && $status !== 'failed';
}

function bakery_route_summary_format_money(float $amount): string
{
    return '$' . number_format($amount, 2);
}

function bakery_route_summary_format_time(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return '';
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return '';
    }
    return bakery_locale_time($timestamp);
}

function bakery_route_summary_stop_time(array $stop): string
{
    foreach (['actual_delivery_time', 'delivery_confirmed_at', 'scheduled_delivery_time'] as $field) {
        $formatted = bakery_route_summary_format_time($stop[$field] ?? null);
        if ($formatted !== '') {
            return $formatted;
        }
    }
    return '';
}

function bakery_route_summary_photo_type_key(string $type): string
{
    $normalized = strtolower(trim($type));
    if ($normalized === 'before') {
        return 'route_summary.photo_before';
    }
    if ($normalized === 'after') {
        return 'route_summary.photo_after';
    }
    if ($normalized === 'receipt') {
        return 'route_summary.photo_receipt';
    }
    return 'route_summary.photo';
}

function bakery_route_summary_is_arrival_photo(string $type): bool
{
    return strtolower(trim($type)) === 'before';
}

function bakery_route_summary_is_pod_photo(string $type): bool
{
    $normalized = strtolower(trim($type));
    return $normalized === 'after' || $normalized === 'receipt';
}

/**
 * Split a stop's photos into arrival (Before) and proof of delivery (After, Receipt).
 *
 * @param array<int, array<string, mixed>> $photos
 * @return array{arrival:array<int, array<string, mixed>>,pod:array<int, array<string, mixed>>}
 */
function bakery_route_summary_classify_photos(array $photos): array
{
    $arrival = [];
    $pod = [];
    foreach ($photos as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        $type = (string)($photo['photo_type'] ?? '');
        if (bakery_route_summary_is_arrival_photo($type)) {
            $arrival[] = $photo;
        } elseif (bakery_route_summary_is_pod_photo($type)) {
            $pod[] = $photo;
        }
    }
    return [
        'arrival' => $arrival,
        'pod' => $pod,
    ];
}

function bakery_route_summary_photo_status(string $deliveryStatus, bool $hasArrival, bool $hasPod): string
{
    if ($hasPod) {
        return 'pod';
    }
    if ($deliveryStatus === 'delivered' && $hasArrival) {
        return 'arrival_only';
    }
    if ($hasArrival) {
        return 'arrival';
    }
    return 'none';
}

function bakery_route_summary_status_key(string $status): string
{
    $known = ['pending', 'in_transit', 'delivered', 'failed', 'cancelled', 'rescheduled'];
    if (in_array($status, $known, true)) {
        return 'route_summary.status_' . $status;
    }
    return 'route_summary.status_pending';
}

function bakery_route_summary_photo_time(array $photo): ?int
{
    $raw = trim((string)($photo['captured_at'] ?? ''));
    if ($raw === '') {
        $raw = trim((string)($photo['created_at'] ?? ''));
    }
    if ($raw === '' || $raw === '0000-00-00 00:00:00') {
        return null;
    }
    $timestamp = strtotime($raw);
    return $timestamp === false ? null : $timestamp;
}

function bakery_route_summary_stop_photo_time(array $stop): ?int
{
    $earliest = null;
    foreach ($stop['photos'] ?? [] as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        $timestamp = bakery_route_summary_photo_time($photo);
        if ($timestamp === null) {
            continue;
        }
        if ($earliest === null || $timestamp < $earliest) {
            $earliest = $timestamp;
        }
    }
    return $earliest;
}

/**
 * A photo taken before the previous stop on the same route.
 *
 * @param list<array<string, mixed>> $stops
 */
function bakery_route_summary_mark_out_of_order(array &$stops): void
{
    $indexed = [];
    foreach ($stops as $index => $stop) {
        $indexed[] = [
            'index' => $index,
            'order' => (int)($stop['route_order'] ?? 0),
            'time' => bakery_route_summary_stop_photo_time($stop),
        ];
    }
    usort($indexed, static function (array $left, array $right): int {
        $order = $left['order'] <=> $right['order'];
        return $order !== 0 ? $order : ($left['index'] <=> $right['index']);
    });
    $previousTime = null;
    foreach ($indexed as $row) {
        $stops[$row['index']]['photo_out_of_order'] = false;
        if ($row['time'] === null) {
            continue;
        }
        if ($previousTime !== null && ($row['time'] + 60) < $previousTime) {
            $stops[$row['index']]['photo_out_of_order'] = true;
        }
        if ($previousTime === null || $row['time'] > $previousTime) {
            $previousTime = $row['time'];
        }
    }
}

/**
 * @param list<array<string, mixed>> $stops
 */
function bakery_route_summary_mark_proof_flags(array &$stops): void
{
    bakery_route_summary_mark_out_of_order($stops);
    foreach ($stops as &$stop) {
        $status = (string)($stop['delivery_status'] ?? 'pending');
        $closed = $status === 'delivered';
        $photos = is_array($stop['photos'] ?? null) ? $stop['photos'] : [];
        $hasPhoto = $photos !== [];
        $booked = array_key_exists('booked_amount', $stop) ? $stop['booked_amount'] : null;
        $sold = (float)($stop['sold_amount'] ?? 0);
        $stop['negative_total'] = $sold < -0.004 || ($booked !== null && (float)$booked < -0.004);
        $stop['photo_without_closeout'] = $hasPhoto && !$closed && $status !== 'cancelled';
        $stop['closeout_without_photo'] = $closed && !$hasPhoto;
        $far = false;
        foreach ($photos as $photo) {
            if (!is_array($photo)) {
                continue;
            }
            if (bakery_photo_is_far_from_store(
                $photo['latitude'] ?? null,
                $photo['longitude'] ?? null,
                $stop['latitude'] ?? null,
                $stop['longitude'] ?? null
            )) {
                $far = true;
                break;
            }
        }
        $stop['photo_far'] = $far;
    }
    unset($stop);
}

function bakery_route_summary_matches_filter(array $stop, string $filter): bool
{
    $status = (string)($stop['delivery_status'] ?? 'pending');
    $hasPod = !empty($stop['has_pod_photo']);
    switch ($filter) {
        case 'photos':
            return $hasPod;
        case 'missing':
            return !$hasPod && $status !== 'cancelled';
        case 'delivered':
            return $status === 'delivered';
        case 'pending':
            return !in_array($status, ['delivered', 'cancelled'], true);
        default:
            return true;
    }
}

/**
 * Attach photos and display fields to Route Manager driver/stop rows.
 *
 * @param array<int, array<string, mixed>> $driversData
 * @param array<string, array<int, array<string, mixed>>> $photosByStop
 * @return array<string, mixed>
 */
function bakery_route_summary_build_day(array $driversData, array $photosByStop, string $filter = 'all'): array
{
    $filter = bakery_route_summary_parse_filter($filter);
    $drivers = [];
    $stats = [
        'stops' => 0,
        'delivered' => 0,
        'pending' => 0,
        'failed' => 0,
        'with_photos' => 0,
        'missing_photos' => 0,
        'arrival_only' => 0,
        'photo_count' => 0,
        'arrival_photo_count' => 0,
        'pod_photo_count' => 0,
        'sold' => 0.0,
        'pieces' => 0,
        'drivers' => 0,
        'negative_stops' => 0,
        'photo_without_closeout' => 0,
        'closeout_without_photo' => 0,
        'photo_far' => 0,
        'photo_out_of_order' => 0,
    ];

    foreach ($driversData as $driver) {
        $visibleStops = [];
        $preparedStops = [];
        $driverPhotos = 0;
        $driverMissing = 0;
        $driverArrivalOnly = 0;
        $driverArrivalPhotos = 0;
        $driverPodPhotos = 0;
        $driverNegative = 0;
        foreach ($driver['deliveries'] ?? [] as $delivery) {
            $driverId = (int)($driver['id'] ?? $delivery['driver_id'] ?? 0);
            $customerId = (int)($delivery['customer_id'] ?? 0);
            $photos = $photosByStop[$driverId . ':' . $customerId] ?? [];
            $classified = bakery_route_summary_classify_photos($photos);
            $hasArrival = $classified['arrival'] !== [];
            $hasPod = $classified['pod'] !== [];
            $status = (string)($delivery['delivery_status'] ?? 'pending');
            $arrivalOnly = $status === 'delivered' && $hasArrival && !$hasPod;
            $sold = bakery_route_summary_sold_amount($delivery);
            $pieces = bakery_route_summary_pieces($delivery);
            $stop = $delivery;
            $stop['driver_id'] = $driverId;
            $stop['driver_name'] = (string)($driver['name'] ?? '');
            $stop['photos'] = $photos;
            $stop['photo_count'] = count($photos);
            $stop['arrival_photos'] = $classified['arrival'];
            $stop['pod_photos'] = $classified['pod'];
            $stop['arrival_count'] = count($classified['arrival']);
            $stop['pod_count'] = count($classified['pod']);
            $stop['has_arrival_photo'] = $hasArrival;
            $stop['has_pod_photo'] = $hasPod;
            $stop['arrival_only'] = $arrivalOnly;
            $stop['photo_status'] = bakery_route_summary_photo_status($status, $hasArrival, $hasPod);
            $stop['hero_photo'] = bakery_route_summary_choose_hero_photo($photos);
            $stop['sold_amount'] = $sold;
            $stop['booked_amount'] = route_manager_booked_amount($delivery);
            $stop['pieces'] = $pieces;
            $stop['show_time'] = bakery_route_summary_stop_time($stop);
            $stop['counts_toward_sold'] = bakery_route_summary_counts_toward_sold($stop);
            $stop['negative_total'] = false;
            $stop['photo_without_closeout'] = false;
            $stop['closeout_without_photo'] = false;
            $stop['photo_far'] = false;
            $stop['photo_out_of_order'] = false;
            $preparedStops[] = $stop;
        }

        bakery_route_summary_mark_proof_flags($preparedStops);
        foreach ($preparedStops as $stop) {
            $status = (string)($stop['delivery_status'] ?? 'pending');
            $photos = is_array($stop['photos'] ?? null) ? $stop['photos'] : [];
            $hasPod = !empty($stop['has_pod_photo']);
            $arrivalOnly = !empty($stop['arrival_only']);
            $sold = (float)($stop['sold_amount'] ?? 0);
            $pieces = (int)($stop['pieces'] ?? 0);

            $stats['stops']++;
            if ($status === 'delivered') {
                $stats['delivered']++;
            } elseif ($status === 'failed') {
                $stats['failed']++;
            } elseif ($status !== 'cancelled') {
                $stats['pending']++;
            }
            $stats['arrival_photo_count'] += (int)($stop['arrival_count'] ?? 0);
            $stats['pod_photo_count'] += (int)($stop['pod_count'] ?? 0);
            $driverArrivalPhotos += (int)($stop['arrival_count'] ?? 0);
            $driverPodPhotos += (int)($stop['pod_count'] ?? 0);
            if ($photos !== []) {
                $stats['photo_count'] += count($photos);
                $driverPhotos += count($photos);
            }
            if ($status === 'delivered' && $hasPod) {
                $stats['with_photos']++;
            }
            if ($arrivalOnly) {
                $stats['arrival_only']++;
                $driverArrivalOnly++;
            }
            if ($status !== 'cancelled' && !$hasPod) {
                $stats['missing_photos']++;
                $driverMissing++;
            }
            if (!empty($stop['negative_total'])) {
                $stats['negative_stops']++;
                $driverNegative++;
            }
            if (!empty($stop['photo_without_closeout'])) {
                $stats['photo_without_closeout']++;
            }
            if (!empty($stop['closeout_without_photo'])) {
                $stats['closeout_without_photo']++;
            }
            if (!empty($stop['photo_far'])) {
                $stats['photo_far']++;
            }
            if (!empty($stop['photo_out_of_order'])) {
                $stats['photo_out_of_order']++;
            }
            if ($stop['counts_toward_sold']) {
                $stats['sold'] += $sold;
                $stats['pieces'] += $pieces;
            }

            if (bakery_route_summary_matches_filter($stop, $filter)) {
                $visibleStops[] = $stop;
            }
        }

        if ($visibleStops === []) {
            continue;
        }

        $cashSummary = $driver['cash_summary'] ?? route_manager_compute_cash_summary($driver['deliveries'] ?? []);
        $drivers[] = [
            'id' => (int)$driver['id'],
            'name' => (string)($driver['name'] ?? ''),
            'stops' => $visibleStops,
            'stop_count' => count($visibleStops),
            'sold' => (float)($cashSummary['total_sold'] ?? 0),
            'photo_count' => $driverPhotos,
            'arrival_photo_count' => $driverArrivalPhotos,
            'pod_photo_count' => $driverPodPhotos,
            'arrival_only' => $driverArrivalOnly,
            'missing_photos' => $driverMissing,
            'negative_stops' => $driverNegative,
            'cash_summary' => $cashSummary,
        ];
    }

    $stats['sold'] = round($stats['sold'], 2);
    $stats['drivers'] = count($drivers);

    return [
        'drivers' => $drivers,
        'stats' => $stats,
        'filter' => $filter,
        'has_stops' => $stats['stops'] > 0,
        'visible_stops' => array_sum(array_map(static function ($driver) {
            return count($driver['stops']);
        }, $drivers)),
    ];
}

/**
 * Drivers that have at least one assigned stop on the date.
 *
 * @return array<int, array{id:int,name:string}>
 */
function bakery_route_summary_drivers_for_date(PDO $db, string $date): array
{
    $stmt = $db->prepare(
        'SELECT DISTINCT d.id, d.name
         FROM daily_order_assignments doa
         INNER JOIN drivers d ON d.id = doa.driver_id
         WHERE doa.delivery_date = ?
         ORDER BY d.name'
    );
    $stmt->execute([$date]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
        ];
    }
    return $rows;
}

function bakery_route_summary_query(string $date, int $driverId, string $filter): string
{
    $params = ['date' => $date];
    if ($driverId > 0) {
        $params['driver_id'] = (string)$driverId;
    }
    if ($filter !== 'all') {
        $params['filter'] = $filter;
    }
    $base = defined('BASE_URL') ? BASE_URL : '';
    return $base . 'route_summary.php?' . http_build_query($params);
}

/**
 * Load the photo-first day for the Route Manager.
 *
 * @param array<int, int> $driverIds
 * @return array<string, mixed>
 */
function bakery_route_summary_load(PDO $db, string $date, array $driverIds = [], string $filter = 'all'): array
{
    $driversData = route_manager_fetch_deliveries($db, $date, $driverIds);
    $photosByStop = route_manager_fetch_photos_for_date($db, $date, $driverIds);
    $day = bakery_route_summary_build_day($driversData, $photosByStop, $filter);
    $day['date'] = $date;
    $day['photos_available'] = table_exists($db, 'driver_photos');
    return $day;
}

/**
 * Stops on one driver's closeout, with arrival and proof photo status.
 *
 * @return list<array<string, mixed>>
 */
function bakery_route_closeout_stops(PDO $db, string $date, int $driverId): array
{
    if ($driverId <= 0) {
        return [];
    }
    $driversData = route_manager_fetch_deliveries($db, $date, [$driverId]);
    $photosByStop = route_manager_fetch_photos_for_date($db, $date, [$driverId]);
    $stops = [];
    foreach ($driversData as $driver) {
        if ((int)($driver['id'] ?? 0) !== $driverId) {
            continue;
        }
        foreach ($driver['deliveries'] ?? [] as $delivery) {
            if (!is_array($delivery)) {
                continue;
            }
            $status = (string)($delivery['delivery_status'] ?? 'pending');
            if ($status === 'cancelled') {
                continue;
            }
            $customerId = (int)($delivery['customer_id'] ?? 0);
            $photos = $photosByStop[$driverId . ':' . $customerId] ?? [];
            $classified = bakery_route_summary_classify_photos($photos);
            $hasArrival = $classified['arrival'] !== [];
            $hasPod = $classified['pod'] !== [];
            $stops[] = [
                'customer_id' => $customerId,
                'customer_name' => (string)($delivery['customer_name'] ?? ''),
                'route_order' => (int)($delivery['route_order'] ?? 0),
                'delivery_status' => $status,
                'photo_status' => bakery_route_summary_photo_status($status, $hasArrival, $hasPod),
                'arrival_only' => $status === 'delivered' && $hasArrival && !$hasPod,
                'has_arrival_photo' => $hasArrival,
                'has_pod_photo' => $hasPod,
            ];
        }
    }
    usort($stops, static function ($a, $b) {
        $order = ((int)$a['route_order']) <=> ((int)$b['route_order']);
        if ($order !== 0) {
            return $order;
        }
        return strcasecmp((string)$a['customer_name'], (string)$b['customer_name']);
    });
    return $stops;
}

/**
 * @param list<array<string, mixed>> $stops
 * @return array{after:int,arrival_only:int,no_photo:int,open:int}
 */
function bakery_route_closeout_counts(array $stops): array
{
    $counts = [
        'after' => 0,
        'arrival_only' => 0,
        'no_photo' => 0,
        'open' => 0,
    ];
    foreach ($stops as $stop) {
        if (!is_array($stop)) {
            continue;
        }
        $status = (string)($stop['delivery_status'] ?? 'pending');
        if ($status === 'cancelled') {
            continue;
        }
        if ($status !== 'delivered') {
            $counts['open']++;
            continue;
        }
        if (!empty($stop['has_pod_photo'])) {
            $counts['after']++;
        } elseif (!empty($stop['has_arrival_photo'])) {
            $counts['arrival_only']++;
        } else {
            $counts['no_photo']++;
        }
    }
    return $counts;
}

/**
 * Per-driver closeout counts and a Spanish note that is only copied.
 *
 * @return list<array{driver_id:int,driver_name:string,counts:array{after:int,arrival_only:int,no_photo:int,open:int},body:string}>
 */
function bakery_route_closeout_driver_splits(PDO $db, string $date): array
{
    if (!function_exists('bakery_driver_closeout_sms_body')) {
        require_once __DIR__ . '/driver_day_sms.php';
    }
    $day = bakery_route_summary_load($db, $date);
    $splits = [];
    foreach ($day['drivers'] as $driver) {
        if (!is_array($driver)) {
            continue;
        }
        $counts = bakery_route_closeout_counts($driver['stops'] ?? []);
        $name = (string)($driver['name'] ?? '');
        $splits[] = [
            'driver_id' => (int)($driver['id'] ?? 0),
            'driver_name' => $name,
            'counts' => $counts,
            'body' => bakery_driver_closeout_sms_body([
                'driver_name' => $name,
                'date' => $date,
                'counts' => $counts,
            ]),
        ];
    }
    return $splits;
}
