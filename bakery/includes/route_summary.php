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
    return date('g:i A', $timestamp);
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
    $hasPhotos = !empty($stop['photos']);
    switch ($filter) {
        case 'photos':
            return $hasPhotos;
        case 'missing':
            return !$hasPhotos && $status !== 'cancelled';
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
        'photo_count' => 0,
        'sold' => 0.0,
        'pieces' => 0,
        'drivers' => 0,
    ];

    foreach ($driversData as $driver) {
        $visibleStops = [];
        $driverPhotos = 0;
        $driverMissing = 0;
        foreach ($driver['deliveries'] ?? [] as $delivery) {
            $driverId = (int)($driver['id'] ?? $delivery['driver_id'] ?? 0);
            $customerId = (int)($delivery['customer_id'] ?? 0);
            $photos = $photosByStop[$driverId . ':' . $customerId] ?? [];
            $status = (string)($delivery['delivery_status'] ?? 'pending');
            $sold = bakery_route_summary_sold_amount($delivery);
            $pieces = bakery_route_summary_pieces($delivery);
            $stop = $delivery;
            $stop['driver_id'] = $driverId;
            $stop['driver_name'] = (string)($driver['name'] ?? '');
            $stop['photos'] = $photos;
            $stop['photo_count'] = count($photos);
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
            if ($photos !== []) {
                $stats['with_photos']++;
                $stats['photo_count'] += count($photos);
                $driverPhotos += count($photos);
            } elseif ($status !== 'cancelled') {
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
