<?php
/**
 * Shared stop proof for Route health and Driver reports.
 *
 * An After photo is delivery proof. A Before photo is arrival only.
 * The filename wins over the photo_type column, same as Route photo journey.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/route_photo_journey.php';

const BAKERY_ROUTE_STOP_BULK_STAMP_MINUTES = 5;
const BAKERY_ROUTE_STOP_BULK_STAMP_MIN_STOPS = 3;

/**
 * @param array<string, mixed> $photo
 */
function bakery_route_stop_photo_type(array $photo): string
{
    return bakery_route_photo_journey_photo_type($photo);
}

/**
 * @param array<int, mixed> $photos
 */
function bakery_route_stop_has_after_photo(array $photos): bool
{
    foreach ($photos as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        if (strcasecmp(bakery_route_stop_photo_type($photo), 'After') === 0) {
            return true;
        }
    }
    return false;
}

function bakery_route_stop_time_or_null($value): ?string
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
 * with_photo only when the stop has an After photo.
 * A Before photo, or a receipt, is not delivery proof.
 *
 * @param array<string, mixed> $stop
 */
function bakery_route_stop_photo_mark(array $stop): string
{
    $photos = $stop['photos'] ?? [];
    if (!is_array($photos)) {
        $photos = [];
    }
    if (bakery_route_stop_has_after_photo($photos)) {
        return 'with_photo';
    }
    if ($photos === [] && !empty($stop['has_pod_photo']) && empty($stop['has_arrival_photo'])) {
        return 'with_photo';
    }
    $status = (string)($stop['delivery_status'] ?? '');
    $departure = bakery_route_stop_time_or_null($stop['delivered_at'] ?? null);
    $badge = (string)($stop['badge'] ?? '');
    if ($status === 'delivered' || ($departure !== null && $badge !== 'arrived_open')) {
        return 'delivered_no_photo';
    }
    return 'no_info';
}

/**
 * Several delivered closes inside the named minute window.
 *
 * @param array<int, mixed> $stops
 * @return list<string>
 */
function bakery_route_stop_bulk_stamp_names(array $stops): array
{
    $window = BAKERY_ROUTE_STOP_BULK_STAMP_MINUTES * 60;
    $minimum = BAKERY_ROUTE_STOP_BULK_STAMP_MIN_STOPS;
    $stamped = [];
    foreach ($stops as $stop) {
        if (!is_array($stop)) {
            continue;
        }
        if ((string)($stop['badge'] ?? '') === 'arrived_open') {
            continue;
        }
        $at = bakery_route_stop_time_or_null($stop['delivered_at'] ?? null);
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
            if (($stamped[$j]['ts'] - $stamped[$i]['ts']) <= $window) {
                $cluster[] = $stamped[$j];
                continue;
            }
            break;
        }
        if (count($cluster) < $minimum) {
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
