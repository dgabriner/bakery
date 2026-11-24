<?php
/**
 * Route photo journey: who actually delivered each stop, in photo order.
 *
 * The driver id inside the photo filename is the credit. The planned route
 * and driver_photos.driver_id are the morning plan, not the proof.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/route_summary.php';

/**
 * Managers and administrators only. Logged-out callers get a login redirect.
 *
 * @param array<string, mixed>|null $user
 */
function bakery_route_photo_journey_access_status(?array $user): int
{
    if ($user === null) {
        return 302;
    }
    $role = strtolower(trim((string)($user['role_slug'] ?? '')));
    if ($role === 'administrator' || $role === 'manager') {
        return 200;
    }
    return 403;
}

function bakery_route_photo_journey_badge_key(string $badge): string
{
    switch ($badge) {
        case 'pod':
            return 'route_photo_journey.badge_pod';
        case 'arrival':
            return 'route_photo_journey.badge_arrival';
        case 'pending':
            return 'route_photo_journey.badge_pending';
        case 'arrived_open':
            return 'route_photo_journey.badge_arrived_open';
        default:
            throw new InvalidArgumentException('Unknown journey badge: ' . $badge);
    }
}

function bakery_route_photo_journey_badge_class(string $badge): string
{
    switch ($badge) {
        case 'pod':
            return 'is-pod';
        case 'arrival':
            return 'is-arrival';
        case 'pending':
            return 'is-pending';
        case 'arrived_open':
            return 'is-arrived-open';
        default:
            throw new InvalidArgumentException('Unknown journey badge: ' . $badge);
    }
}

function bakery_route_photo_journey_format_clock(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return '';
    }
    return bakery_locale_time($value);
}

function bakery_route_photo_journey_duration_label(?int $minutes): string
{
    if ($minutes === null) {
        return '';
    }
    if ($minutes < 1) {
        return bakery_t('route_photo_journey.under_one_minute');
    }
    if ($minutes >= 60) {
        return bakery_t('route_photo_journey.hours_minutes', [
            'h' => intdiv($minutes, 60),
            'm' => $minutes % 60,
        ]);
    }
    return bakery_t('route_photo_journey.minutes', ['n' => $minutes]);
}

/**
 * @param array<string, mixed> $note
 */
function bakery_route_photo_journey_other_label(array $note): string
{
    $kind = (string)($note['kind'] ?? '');
    $params = [
        'store' => (string)($note['customer_name'] ?? ''),
        'driver' => (string)($note['driver_name'] ?? ''),
        'time' => bakery_route_photo_journey_format_clock((string)($note['time'] ?? '')),
    ];
    switch ($kind) {
        case 'delivered_by':
            if ($params['time'] === '') {
                return bakery_t('route_photo_journey.other_delivered_notime', $params);
            }
            return bakery_t('route_photo_journey.other_delivered', $params);
        case 'moved_pending':
            return bakery_t('route_photo_journey.other_moved', $params);
        default:
            throw new InvalidArgumentException('Unknown journey note: ' . $kind);
    }
}

/**
 * @param array<string, mixed> $stop
 */
function bakery_route_photo_journey_pieces_label(array $stop): string
{
    if (empty($stop['has_order'])) {
        return '';
    }
    $pieces = (int)($stop['pieces'] ?? 0);
    $amount = (float)($stop['amount'] ?? 0);
    if ($pieces > 0 && $amount > 0) {
        return bakery_t('route_photo_journey.pieces_amount', [
            'pieces' => $pieces,
            'amount' => number_format($amount, 2),
        ]);
    }
    if ($pieces > 0) {
        return bakery_t('route_photo_journey.pieces_only', ['pieces' => $pieces]);
    }
    if ($amount > 0) {
        return bakery_t('route_photo_journey.amount_only', ['amount' => number_format($amount, 2)]);
    }
    return '';
}

/**
 * @param array<string, mixed> $stop
 * @param array<string, mixed> $driver
 */
function bakery_route_photo_journey_travel_label(array $stop, array $driver): string
{
    if ((string)($stop['badge'] ?? '') === 'pending') {
        return bakery_t('route_photo_journey.pending_label');
    }
    $kind = (string)($stop['gap_kind'] ?? 'first');
    $minutes = $stop['gap_minutes'] ?? null;
    $duration = bakery_route_photo_journey_duration_label($minutes === null ? null : (int)$minutes);
    switch ($kind) {
        case 'first':
            return bakery_t('route_photo_journey.travel_first');
        case 'clock':
            $from = bakery_t('route_photo_journey.travel_from_clock', [
                'time' => bakery_route_photo_journey_format_clock((string)($driver['clock_in_at'] ?? '')),
            ]);
            return trim($duration . ' ' . $from);
        case 'previous':
            return trim($duration . ' ' . bakery_t('route_photo_journey.travel_from_previous'));
        default:
            throw new InvalidArgumentException('Unknown journey gap: ' . $kind);
    }
}

function bakery_route_photo_journey_query(string $date, int $driverId): string
{
    $params = ['date' => $date];
    if ($driverId > 0) {
        $params['driver_id'] = $driverId;
    }
    $base = defined('BASE_URL') ? (string)BASE_URL : '';
    return $base . 'route_photo_journey.php?' . http_build_query($params);
}

/**
 * @param array<string, mixed> $photo
 */
function bakery_route_photo_journey_photo_type(array $photo): string
{
    $parsed = bakery_driver_photo_parse_filename((string)($photo['filename'] ?? ''))
        ?? bakery_driver_photo_parse_filename((string)($photo['file_path'] ?? ''));
    if ($parsed !== null) {
        return $parsed['photo_type'];
    }
    $raw = (string)($photo['photo_type'] ?? '');
    if (strcasecmp($raw, 'After') === 0) {
        return 'After';
    }
    if (strcasecmp($raw, 'Before') === 0) {
        return 'Before';
    }
    if (strcasecmp($raw, 'Receipt') === 0) {
        return 'Receipt';
    }
    return $raw;
}

/**
 * @param array<string, mixed> $photo
 */
function bakery_route_photo_journey_photo_customer_id(array $photo): int
{
    $parsed = bakery_driver_photo_parse_filename((string)($photo['filename'] ?? ''))
        ?? bakery_driver_photo_parse_filename((string)($photo['file_path'] ?? ''));
    if ($parsed !== null) {
        return $parsed['customer_id'];
    }
    return (int)($photo['customer_id'] ?? 0);
}

/**
 * @param array<int, array<string, mixed>> $photos
 * @return array<int, array<string, mixed>>
 */
function bakery_route_photo_journey_sort_photos(array $photos, bool $latestFirst): array
{
    usort($photos, static function (array $a, array $b) use ($latestFirst): int {
        $ta = strtotime((string)($a['created_at'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
        if ($ta !== $tb) {
            return $latestFirst ? ($tb <=> $ta) : ($ta <=> $tb);
        }
        $ia = (int)($a['id'] ?? 0);
        $ib = (int)($b['id'] ?? 0);
        return $latestFirst ? ($ib <=> $ia) : ($ia <=> $ib);
    });
    return $photos;
}

function bakery_route_photo_journey_minutes(?string $start, ?string $end): ?int
{
    $start = trim((string)$start);
    $end = trim((string)$end);
    if ($start === '' || $end === '') {
        return null;
    }
    $from = strtotime($start);
    $to = strtotime($end);
    if ($from === false || $to === false) {
        return null;
    }
    return max(0, (int)floor(($to - $from) / 60));
}

/**
 * @param array<string, mixed> $driver
 * @return array{bakery:array<string,mixed>,stops:array<int,array<string,mixed>>}
 */
function bakery_route_photo_journey_map_points(array $driver): array
{
    $pins = [];
    foreach ($driver['stops'] ?? [] as $stop) {
        if (!is_array($stop)) {
            continue;
        }
        $lat = $stop['latitude'] ?? null;
        $lng = $stop['longitude'] ?? null;
        if ($lat === null || $lng === null || ((float)$lat === 0.0 && (float)$lng === 0.0)) {
            continue;
        }
        $pending = (string)($stop['badge'] ?? '') === 'pending';
        $pins[] = [
            'n' => $pending ? null : ($stop['order'] ?? null),
            'kind' => $pending ? 'pending' : 'visited',
            'badge' => (string)($stop['badge'] ?? ''),
            'lat' => (float)$lat,
            'lng' => (float)$lng,
            'name' => (string)($stop['customer_name'] ?? ''),
        ];
    }
    return [
        'bakery' => [
            'lat' => 37.7749,
            'lng' => -122.4194,
            'label_key' => 'route_photo_journey.legend_bakery',
        ],
        'stops' => $pins,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array{drivers:array<int,array<string,mixed>>,picker:array<int,array<string,mixed>>}
 */
function bakery_route_photo_journey_assemble(array $input): array
{
    $driverNames = [];
    foreach ($input['drivers'] ?? [] as $id => $name) {
        $driverNames[(int)$id] = (string)$name;
    }
    $clockIns = [];
    foreach ($input['clock_ins'] ?? [] as $id => $at) {
        $clockIns[(int)$id] = (string)$at;
    }
    $selected = (int)($input['selected_driver_id'] ?? 0);

    $photosByCustomer = [];
    foreach ($input['photos'] ?? [] as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        $customerId = bakery_route_photo_journey_photo_customer_id($photo);
        if ($customerId <= 0) {
            continue;
        }
        $photo['_photo_type'] = bakery_route_photo_journey_photo_type($photo);
        $photo['_credited_driver_id'] = bakery_driver_photo_credited_driver_id($photo);
        $photo['_customer_id'] = $customerId;
        $photosByCustomer[$customerId][] = $photo;
    }

    $credit = [];
    foreach ($photosByCustomer as $customerId => $list) {
        $pod = array_values(array_filter($list, static function (array $photo): bool {
            return in_array((string)$photo['_photo_type'], ['After', 'Receipt'], true);
        }));
        $latest = $pod !== [];
        $pool = bakery_route_photo_journey_sort_photos($latest ? $pod : $list, $latest);
        $driverId = (int)($pool[0]['_credited_driver_id'] ?? 0);
        if ($driverId <= 0) {
            continue;
        }
        $own = array_values(array_filter($list, static function (array $photo) use ($driverId): bool {
            return (int)$photo['_credited_driver_id'] === $driverId;
        }));
        $credit[(int)$customerId] = [
            'driver_id' => $driverId,
            'photos' => $own,
        ];
    }

    $visitedByDriver = [];
    $pendingByDriver = [];
    $otherByDriver = [];
    $seenVisited = [];

    foreach ($input['stops'] ?? [] as $stop) {
        if (!is_array($stop)) {
            continue;
        }
        $status = (string)($stop['delivery_status'] ?? 'pending');
        if (!empty($stop['cancelled']) || $status === 'cancelled') {
            continue;
        }
        $customerId = (int)($stop['customer_id'] ?? 0);
        $planned = (int)($stop['planned_driver_id'] ?? 0);
        $standing = (int)($stop['standing_driver_id'] ?? 0);
        $credited = (int)($credit[$customerId]['driver_id'] ?? 0);
        $store = (string)($stop['customer_name'] ?? '');

        if ($credited > 0 && empty($seenVisited[$customerId])) {
            $seenVisited[$customerId] = true;
            $visitedByDriver[$credited][] = bakery_route_photo_journey_visited_stop(
                $stop,
                $credit[$customerId]['photos']
            );
        } elseif ($credited === 0 && $planned > 0) {
            $pendingByDriver[$planned][] = bakery_route_photo_journey_pending_stop($stop);
        }

        if ($planned > 0 && $credited > 0 && $planned !== $credited) {
            $otherByDriver[$planned][] = bakery_route_photo_journey_other_note(
                $store,
                $status === 'delivered' ? 'delivered_by' : 'moved_pending',
                $credited,
                (string)($driverNames[$credited] ?? ''),
                $status === 'delivered' ? (string)($stop['completion_at'] ?? '') : ''
            );
        }
        if ($standing > 0 && $standing !== $planned && $standing !== $credited) {
            if ($credited > 0 && $status === 'delivered') {
                $otherByDriver[$standing][] = bakery_route_photo_journey_other_note(
                    $store,
                    'delivered_by',
                    $credited,
                    (string)($driverNames[$credited] ?? ''),
                    (string)($stop['completion_at'] ?? '')
                );
            } elseif ($status === 'delivered') {
                $otherByDriver[$standing][] = bakery_route_photo_journey_other_note(
                    $store,
                    'delivered_by',
                    $planned,
                    (string)($driverNames[$planned] ?? ''),
                    (string)($stop['completion_at'] ?? '')
                );
            } else {
                $otherByDriver[$standing][] = bakery_route_photo_journey_other_note(
                    $store,
                    'moved_pending',
                    $planned,
                    (string)($driverNames[$planned] ?? ''),
                    ''
                );
            }
        }
    }

    $driverIds = array_values(array_unique(array_merge(
        array_keys($visitedByDriver),
        array_keys($pendingByDriver),
        array_keys($otherByDriver)
    )));
    $sections = [];
    foreach ($driverIds as $driverId) {
        $driverId = (int)$driverId;
        $sections[] = bakery_route_photo_journey_finish_driver(
            $driverId,
            (string)($driverNames[$driverId] ?? ('Driver ' . $driverId)),
            $clockIns[$driverId] ?? null,
            $visitedByDriver[$driverId] ?? [],
            $pendingByDriver[$driverId] ?? [],
            $otherByDriver[$driverId] ?? []
        );
    }
    usort($sections, static function (array $a, array $b): int {
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });
    $picker = array_map(static function (array $section): array {
        return ['id' => (int)$section['id'], 'name' => (string)$section['name']];
    }, $sections);
    if ($selected > 0) {
        $sections = array_values(array_filter($sections, static function (array $section) use ($selected): bool {
            return (int)$section['id'] === $selected;
        }));
    }
    return ['drivers' => $sections, 'picker' => $picker];
}

/**
 * @param array<string, mixed> $stop
 * @param array<int, array<string, mixed>> $photos
 * @return array<string, mixed>
 */
function bakery_route_photo_journey_visited_stop(array $stop, array $photos): array
{
    $ordered = bakery_route_photo_journey_display_photos($photos);
    $arrival = null;
    foreach ($photos as $photo) {
        $at = trim((string)($photo['created_at'] ?? ''));
        if ($at === '') {
            continue;
        }
        if ($arrival === null || (strtotime($at) ?: 0) < (strtotime($arrival) ?: 0)) {
            $arrival = $at;
        }
    }
    $hasPodPhoto = false;
    $hasArrivalPhoto = false;
    foreach ($photos as $photo) {
        $type = (string)($photo['_photo_type'] ?? bakery_route_photo_journey_photo_type($photo));
        if ($type === 'After' || $type === 'Receipt') {
            $hasPodPhoto = true;
        } elseif ($type === 'Before') {
            $hasArrivalPhoto = true;
        }
    }
    $status = (string)($stop['delivery_status'] ?? 'pending');
    $visit = bakery_route_stop_visit_state($status, $hasArrivalPhoto, $hasPodPhoto);
    if ($visit === 'arrived_not_closed') {
        $badge = 'arrived_open';
        $delivered = null;
    } else {
        $delivered = bakery_route_photo_journey_delivered_at($stop, $ordered);
        $badge = 'arrival';
        foreach ($ordered as $photo) {
            if (in_array((string)$photo['photo_type'], ['After', 'Receipt'], true)) {
                $badge = 'pod';
                break;
            }
        }
    }
    $row = bakery_route_photo_journey_stop_base($stop);
    $row['arrival_at'] = $arrival;
    $row['delivered_at'] = $delivered;
    $row['stay_minutes'] = bakery_route_photo_journey_minutes($arrival, $delivered) ?? 0;
    $row['badge'] = $badge;
    $row['photos'] = $ordered;
    $row['gap_minutes'] = null;
    $row['gap_kind'] = 'first';
    $row['order'] = null;
    return $row;
}

/**
 * @param array<string, mixed> $stop
 * @return array<string, mixed>
 */
function bakery_route_photo_journey_pending_stop(array $stop): array
{
    $row = bakery_route_photo_journey_stop_base($stop);
    $row['arrival_at'] = null;
    $row['delivered_at'] = null;
    $row['stay_minutes'] = 0;
    $row['badge'] = 'pending';
    $row['photos'] = [];
    $row['gap_minutes'] = null;
    $row['gap_kind'] = 'first';
    $row['order'] = null;
    return $row;
}

/**
 * @param array<string, mixed> $stop
 * @return array<string, mixed>
 */
function bakery_route_photo_journey_stop_base(array $stop): array
{
    $lat = $stop['latitude'] ?? null;
    $lng = $stop['longitude'] ?? null;
    return [
        'customer_id' => (int)($stop['customer_id'] ?? 0),
        'customer_name' => (string)($stop['customer_name'] ?? ''),
        'latitude' => $lat === null || $lat === '' ? null : (float)$lat,
        'longitude' => $lng === null || $lng === '' ? null : (float)$lng,
        'route_order' => (int)($stop['route_order'] ?? 0),
        'pieces' => (int)($stop['pieces'] ?? 0),
        'amount' => (float)($stop['amount'] ?? 0),
        'has_order' => !empty($stop['has_order']),
    ];
}

/**
 * After photo first, then the earliest arrival photo.
 *
 * @param array<int, array<string, mixed>> $photos
 * @return array<int, array<string, mixed>>
 */
function bakery_route_photo_journey_display_photos(array $photos): array
{
    $pod = [];
    $before = [];
    $rest = [];
    foreach ($photos as $photo) {
        $type = (string)($photo['_photo_type'] ?? bakery_route_photo_journey_photo_type($photo));
        $photo['_photo_type'] = $type;
        if ($type === 'After' || $type === 'Receipt') {
            $pod[] = $photo;
        } elseif ($type === 'Before') {
            $before[] = $photo;
        } else {
            $rest[] = $photo;
        }
    }
    $pod = bakery_route_photo_journey_sort_photos($pod, true);
    $before = bakery_route_photo_journey_sort_photos($before, false);
    $out = [];
    foreach (array_merge($pod, $before, $rest) as $photo) {
        $path = (string)($photo['file_path'] ?? '');
        $out[] = [
            'id' => (int)($photo['id'] ?? 0),
            'photo_type' => (string)$photo['_photo_type'],
            'created_at' => (string)($photo['created_at'] ?? ''),
            'file_path' => $path,
            'url' => $path !== '' ? bakery_driver_photo_url($path) : '',
        ];
    }
    return $out;
}

/**
 * @param array<string, mixed> $stop
 * @param array<int, array<string, mixed>> $photos
 */
function bakery_route_photo_journey_delivered_at(array $stop, array $photos): ?string
{
    $completion = trim((string)($stop['completion_at'] ?? ''));
    if ($completion !== '') {
        return $completion;
    }
    $pod = array_values(array_filter($photos, static function (array $photo): bool {
        return in_array((string)($photo['photo_type'] ?? ''), ['After', 'Receipt'], true);
    }));
    $pool = bakery_route_photo_journey_sort_photos($pod !== [] ? $pod : $photos, true);
    $at = trim((string)($pool[0]['created_at'] ?? ''));
    return $at !== '' ? $at : null;
}

/**
 * @return array<string, mixed>
 */
function bakery_route_photo_journey_other_note(
    string $store,
    string $kind,
    int $driverId,
    string $driverName,
    string $time
): array {
    return [
        'customer_name' => $store,
        'kind' => $kind,
        'driver_id' => $driverId,
        'driver_name' => $driverName,
        'time' => $time,
    ];
}

/**
 * @param array<int, array<string, mixed>> $visited
 * @param array<int, array<string, mixed>> $pending
 * @param array<int, array<string, mixed>> $other
 * @return array<string, mixed>
 */
function bakery_route_photo_journey_finish_driver(
    int $driverId,
    string $name,
    ?string $clockIn,
    array $visited,
    array $pending,
    array $other
): array {
    usort($visited, static function (array $a, array $b): int {
        $ta = strtotime((string)($a['arrival_at'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['arrival_at'] ?? '')) ?: 0;
        if ($ta !== $tb) {
            return $ta <=> $tb;
        }
        return strcasecmp((string)$a['customer_name'], (string)$b['customer_name']);
    });
    $clockIn = trim((string)$clockIn);
    $previousDelivered = null;
    foreach ($visited as $index => &$stop) {
        if ($index === 0) {
            if ($clockIn !== '') {
                $stop['gap_kind'] = 'clock';
                $stop['gap_minutes'] = bakery_route_photo_journey_minutes($clockIn, (string)($stop['arrival_at'] ?? ''));
            } else {
                $stop['gap_kind'] = 'first';
                $stop['gap_minutes'] = null;
            }
        } else {
            $stop['gap_kind'] = 'previous';
            $stop['gap_minutes'] = bakery_route_photo_journey_minutes($previousDelivered, (string)($stop['arrival_at'] ?? ''));
        }
        $stop['order'] = $index + 1;
        $previousDelivered = (string)($stop['delivered_at'] ?? '');
    }
    unset($stop);

    usort($pending, static function (array $a, array $b): int {
        $order = ((int)$a['route_order']) <=> ((int)$b['route_order']);
        if ($order !== 0) {
            return $order;
        }
        return strcasecmp((string)$a['customer_name'], (string)$b['customer_name']);
    });

    $longGaps = [];
    $shortCloses = [];
    $zeroDollar = [];
    foreach ($visited as $stop) {
        $store = (string)$stop['customer_name'];
        $gap = $stop['gap_minutes'];
        if ($gap !== null && $gap > 90) {
            $longGaps[] = ['store' => $store, 'minutes' => (int)$gap];
        }
        if ((string)$stop['badge'] === 'arrived_open') {
            continue;
        }
        if ((int)$stop['stay_minutes'] < 2 && (string)$stop['badge'] !== 'pod') {
            $shortCloses[] = $store;
        }
        if (!empty($stop['has_order']) && (float)$stop['amount'] <= 0) {
            $zeroDollar[] = $store;
        }
    }

    $pendingNames = array_map(static function (array $stop): string {
        return (string)$stop['customer_name'];
    }, $pending);

    return [
        'id' => $driverId,
        'name' => $name,
        'clock_in_at' => $clockIn !== '' ? $clockIn : null,
        'no_clock_in' => $clockIn === '',
        'delivered_count' => count(array_filter($visited, static function (array $stop): bool {
            return (string)($stop['badge'] ?? '') !== 'arrived_open';
        })),
        'pending_count' => count($pending),
        'pending_names' => $pendingNames,
        'stops' => array_merge($visited, $pending),
        'other' => $other,
        'flags' => [
            'no_clock_in' => $clockIn === '',
            'long_gaps' => $longGaps,
            'short_closes' => $shortCloses,
            'zero_dollar' => $zeroDollar,
        ],
    ];
}

/**
 * @return array{drivers:array<int,array<string,mixed>>,picker:array<int,array<string,mixed>>,date:string}
 */
function bakery_route_photo_journey_load(PDO $db, string $date, int $selectedDriverId): array
{
    $date = route_manager_parse_date($date, $date);
    $weekday = (int)(new DateTimeImmutable($date))->format('N');
    $deliveries = route_manager_fetch_deliveries($db, $date, []);
    $standing = bakery_route_photo_journey_standing_drivers($db, $weekday);
    $photos = bakery_route_photo_journey_photos($db, $date);
    $names = [];
    $stops = [];
    $knownCustomers = [];

    foreach ($deliveries as $driver) {
        $driverId = (int)($driver['id'] ?? 0);
        if ($driverId > 0) {
            $names[$driverId] = (string)($driver['name'] ?? '');
        }
        foreach ($driver['deliveries'] ?? [] as $delivery) {
            if (!is_array($delivery)) {
                continue;
            }
            $customerId = (int)($delivery['customer_id'] ?? 0);
            $knownCustomers[$customerId] = true;
            $status = (string)($delivery['delivery_status'] ?? 'pending');
            $stops[] = [
                'planned_driver_id' => $driverId,
                'standing_driver_id' => (int)($standing[$customerId] ?? 0),
                'customer_id' => $customerId,
                'customer_name' => (string)($delivery['customer_name'] ?? ''),
                'latitude' => $delivery['latitude'] ?? null,
                'longitude' => $delivery['longitude'] ?? null,
                'route_order' => (int)($delivery['route_order'] ?? 0),
                'delivery_status' => $status,
                'cancelled' => $status === 'cancelled',
                'completion_at' => bakery_route_photo_journey_completion($date, $delivery),
                'pieces' => bakery_route_summary_pieces($delivery),
                'amount' => route_manager_estimate_amount($delivery),
                'has_order' => (int)($delivery['daily_order_id'] ?? 0) > 0,
            ];
        }
    }

    foreach ($photos as $photo) {
        $customerId = bakery_route_photo_journey_photo_customer_id($photo);
        $columnDriver = (int)($photo['driver_id'] ?? 0);
        $credited = bakery_driver_photo_credited_driver_id($photo);
        foreach ([$columnDriver, $credited, (int)($standing[$customerId] ?? 0)] as $id) {
            if ($id > 0 && !isset($names[$id])) {
                $names[$id] = '';
            }
        }
        if ($customerId <= 0 || isset($knownCustomers[$customerId])) {
            continue;
        }
        $knownCustomers[$customerId] = true;
        $stops[] = [
            'planned_driver_id' => 0,
            'standing_driver_id' => (int)($standing[$customerId] ?? 0),
            'customer_id' => $customerId,
            'customer_name' => (string)($photo['customer_name'] ?? ''),
            'latitude' => $photo['latitude'] ?? null,
            'longitude' => $photo['longitude'] ?? null,
            'route_order' => 0,
            'delivery_status' => 'delivered',
            'cancelled' => false,
            'completion_at' => null,
            'pieces' => 0,
            'amount' => 0.0,
            'has_order' => false,
        ];
    }

    $names = bakery_route_photo_journey_fill_names($db, $names);
    $assembled = bakery_route_photo_journey_assemble([
        'date' => $date,
        'selected_driver_id' => $selectedDriverId,
        'drivers' => $names,
        'clock_ins' => bakery_route_photo_journey_clock_ins($db, $date),
        'stops' => $stops,
        'photos' => $photos,
    ]);
    $assembled['date'] = $date;
    return $assembled;
}

/**
 * @param array<string, mixed> $delivery
 */
function bakery_route_photo_journey_completion(string $date, array $delivery): ?string
{
    $confirmed = trim((string)($delivery['delivery_confirmed_at'] ?? ''));
    if ($confirmed !== '' && $confirmed !== '0000-00-00 00:00:00') {
        return $confirmed;
    }
    $actual = trim((string)($delivery['actual_delivery_time'] ?? ''));
    if ($actual === '') {
        return null;
    }
    if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $actual)) {
        return $date . ' ' . $actual;
    }
    return $actual;
}

/**
 * @return array<int, int>
 */
function bakery_route_photo_journey_standing_drivers(PDO $db, int $weekday): array
{
    if (!function_exists('table_exists') || !table_exists($db, 'standing_routes')) {
        return [];
    }
    $stmt = $db->prepare('SELECT customer_id, driver_id FROM standing_routes WHERE day_of_week = ? ORDER BY route_order, id');
    $stmt->execute([$weekday]);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $customerId = (int)$row['customer_id'];
        if (!isset($map[$customerId])) {
            $map[$customerId] = (int)$row['driver_id'];
        }
    }
    return $map;
}

/**
 * @return array<int, array<string, mixed>>
 */
function bakery_route_photo_journey_photos(PDO $db, string $date): array
{
    if (!function_exists('table_exists') || !table_exists($db, 'driver_photos')) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT p.id, p.driver_id, p.customer_id, p.filename, p.original_filename, p.file_path,
                p.photo_type, p.created_at, p.delivery_date, c.name AS customer_name,
                c.latitude, c.longitude
         FROM driver_photos p
         LEFT JOIN customers c ON c.id = p.customer_id
         WHERE p.delivery_date = ?
         ORDER BY p.created_at ASC, p.id ASC'
    );
    $stmt->execute([$date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return array<int, string>
 */
function bakery_route_photo_journey_clock_ins(PDO $db, string $date): array
{
    if (!function_exists('table_exists') || !table_exists($db, 'time_clock_punches') || !table_exists($db, 'users')) {
        return [];
    }
    $start = $date . ' 00:00:00';
    $end = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    $stmt = $db->prepare(
        'SELECT u.driver_id, MIN(p.clock_in_at) AS clock_in_at
         FROM time_clock_punches p
         INNER JOIN users u ON u.id = p.user_id
         WHERE u.driver_id IS NOT NULL
           AND u.driver_id > 0
           AND p.clock_in_at >= ?
           AND p.clock_in_at < ?
         GROUP BY u.driver_id'
    );
    $stmt->execute([$start, $end]);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int)$row['driver_id']] = (string)$row['clock_in_at'];
    }
    return $map;
}

/**
 * @param array<int, string> $names
 * @return array<int, string>
 */
function bakery_route_photo_journey_fill_names(PDO $db, array $names): array
{
    $missing = [];
    foreach ($names as $id => $name) {
        if (trim($name) === '') {
            $missing[] = (int)$id;
        }
    }
    if ($missing === [] || !function_exists('table_exists') || !table_exists($db, 'drivers')) {
        return $names;
    }
    $placeholders = implode(',', array_fill(0, count($missing), '?'));
    $stmt = $db->prepare("SELECT id, name FROM drivers WHERE id IN ($placeholders)");
    $stmt->execute($missing);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $names[(int)$row['id']] = (string)$row['name'];
    }
    return $names;
}
