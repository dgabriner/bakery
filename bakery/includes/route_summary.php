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

/**
 * Shared visit label for Route summary and Route photo journey.
 * A closed status stays closed. Photos without a closeout are arrived, not closed.
 */
function bakery_route_stop_visit_state(string $deliveryStatus, bool $hasArrival, bool $hasPod): string
{
    if (in_array($deliveryStatus, ['cancelled', 'failed', 'delivered'], true)) {
        return $deliveryStatus;
    }
    if ($hasArrival || $hasPod) {
        return 'arrived_not_closed';
    }
    if ($deliveryStatus === 'in_transit' || $deliveryStatus === 'rescheduled') {
        return $deliveryStatus;
    }
    return 'pending';
}

function bakery_route_summary_visit_status_key(string $visitState): string
{
    if ($visitState === 'arrived_not_closed') {
        return 'route_summary.status_arrived_not_closed';
    }
    return bakery_route_summary_status_key($visitState);
}

function bakery_route_summary_status_key(string $status): string
{
    $known = ['pending', 'in_transit', 'delivered', 'failed', 'cancelled', 'rescheduled'];
    if (in_array($status, $known, true)) {
        return 'route_summary.status_' . $status;
    }
    return 'route_summary.status_pending';
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
    ];

    foreach ($driversData as $driver) {
        $visibleStops = [];
        $driverPhotos = 0;
        $driverMissing = 0;
        $driverArrivalOnly = 0;
        $driverArrivalPhotos = 0;
        $driverPodPhotos = 0;
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
            $stop['visit_state'] = bakery_route_stop_visit_state($status, $hasArrival, $hasPod);
            $stop['hero_photo'] = bakery_route_summary_choose_hero_photo($photos);
            $stop['sold_amount'] = $sold;
            $stop['pieces'] = $pieces;
            $stop['show_time'] = bakery_route_summary_stop_time($stop);
            $stop['counts_toward_sold'] = bakery_route_summary_counts_toward_sold($stop);

            $stats['stops']++;
            if ($status === 'delivered') {
                $stats['delivered']++;
            } elseif ($status === 'failed') {
                $stats['failed']++;
            } elseif ($status !== 'cancelled') {
                $stats['pending']++;
            }
            $stats['arrival_photo_count'] += count($classified['arrival']);
            $stats['pod_photo_count'] += count($classified['pod']);
            $driverArrivalPhotos += count($classified['arrival']);
            $driverPodPhotos += count($classified['pod']);
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
