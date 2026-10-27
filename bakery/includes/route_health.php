<?php
/**
 * Read-only route health for managers and administrators.
 *
 * Day rows, the next sell day, repeat skips, and proof flags.
 * Nothing here writes, texts, or builds orders.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/route_stop_proof.php';
require_once __DIR__ . '/cron_run.php';
require_once __DIR__ . '/daily_status.php';

const BAKERY_ROUTE_HEALTH_REPEAT_SKIP_DAYS = 3;
const BAKERY_ROUTE_HEALTH_DATED_SHORT_PERCENT = 50;
const BAKERY_ROUTE_HEALTH_DEFAULT_DAYS = 14;
const BAKERY_ROUTE_HEALTH_MAX_DAYS = 62;

/**
 * Same gate as Route photo journey. Logged-out callers get a login redirect.
 *
 * @param array<string, mixed>|null $user
 */
function bakery_route_health_access_status(?array $user): int
{
    return bakery_route_photo_journey_access_status($user);
}

function bakery_route_health_valid_date(string $value): ?string
{
    $value = trim($value);
    $parsed = DateTime::createFromFormat('Y-m-d', $value);
    if (!$parsed || $parsed->format('Y-m-d') !== $value) {
        return null;
    }
    return $value;
}

/**
 * @return array{from:string,to:string,clamped:bool}
 */
function bakery_route_health_parse_range(string $today, ?string $fromRaw, ?string $toRaw): array
{
    $today = bakery_route_health_valid_date($today) ?? date('Y-m-d');
    $endDefault = new DateTimeImmutable($today);
    $startDefault = $endDefault->modify('-' . (BAKERY_ROUTE_HEALTH_DEFAULT_DAYS - 1) . ' days');
    $from = bakery_route_health_valid_date((string)$fromRaw);
    $to = bakery_route_health_valid_date((string)$toRaw);
    if ($from === null && $to === null) {
        return [
            'from' => $startDefault->format('Y-m-d'),
            'to' => $endDefault->format('Y-m-d'),
            'clamped' => false,
        ];
    }
    if ($from === null) {
        $from = $to;
    }
    if ($to === null) {
        $to = $from;
    }
    if ($from > $to) {
        $swap = $from;
        $from = $to;
        $to = $swap;
    }
    $clamped = false;
    $span = (int)(new DateTimeImmutable($to))->diff(new DateTimeImmutable($from))->days;
    if ($span > BAKERY_ROUTE_HEALTH_MAX_DAYS - 1) {
        $from = (new DateTimeImmutable($to))
            ->modify('-' . (BAKERY_ROUTE_HEALTH_MAX_DAYS - 1) . ' days')
            ->format('Y-m-d');
        $clamped = true;
    }
    return [
        'from' => $from,
        'to' => $to,
        'clamped' => $clamped,
    ];
}

function bakery_route_health_dated_is_short(int $dated, int $standing): bool
{
    if ($standing <= 0) {
        return false;
    }
    if ($dated <= 0) {
        return true;
    }
    return ($dated * 100) < ($standing * BAKERY_ROUTE_HEALTH_DATED_SHORT_PERCENT);
}

/**
 * @return list<string>
 */
function bakery_route_health_dates(string $from, string $to): array
{
    $dates = [];
    $cursor = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);
    while ($cursor <= $end) {
        $dates[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');
    }
    return $dates;
}

/**
 * @param array<string, mixed> $row
 */
function bakery_route_health_stop_status(array $row): string
{
    $raw = trim((string)($row['delivery_status'] ?? ''));
    if ($raw === '') {
        $raw = 'pending';
    }
    if ($raw === 'cancelled') {
        return 'cancelled';
    }
    $orderStatus = (string)($row['order_status'] ?? '');
    $confirmed = trim((string)($row['delivery_confirmed_at'] ?? ''));
    $confirmedSet = $confirmed !== '' && $confirmed !== '0000-00-00 00:00:00';
    if ($raw === 'delivered' || in_array($orderStatus, ['delivered', 'invoiced'], true) || $confirmedSet) {
        return 'delivered';
    }
    return $raw;
}

/**
 * @param array<string, mixed> $row
 */
function bakery_route_health_closed_at(string $date, array $row): ?string
{
    $confirmed = bakery_route_stop_time_or_null($row['delivery_confirmed_at'] ?? null);
    if ($confirmed !== null) {
        return $confirmed;
    }
    $actual = trim((string)($row['actual_delivery_time'] ?? ''));
    if ($actual === '') {
        return null;
    }
    if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $actual)) {
        if (strlen($actual) === 5) {
            $actual .= ':00';
        }
        return $date . ' ' . $actual;
    }
    return bakery_route_stop_time_or_null($actual);
}

/**
 * @param array<int, array<string, mixed>> $photos
 */
function bakery_route_health_photo_url(array $photos): string
{
    $chosen = null;
    foreach ($photos as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        $path = trim((string)($photo['file_path'] ?? ''));
        if ($path === '' || !function_exists('bakery_driver_photo_relative_path')) {
            continue;
        }
        if (bakery_driver_photo_relative_path($path) === '') {
            continue;
        }
        $type = bakery_route_stop_photo_type($photo);
        if (strcasecmp($type, 'After') !== 0) {
            $chosen = $photo;
            break;
        }
        if ($chosen === null) {
            $chosen = $photo;
        }
    }
    if (!is_array($chosen) || !function_exists('bakery_driver_photo_url')) {
        return '';
    }
    $url = bakery_driver_photo_url((string)($chosen['file_path'] ?? ''));
    if ($url === '' || strpos($url, 'driver_photo.php') === false || strpos($url, 'uploads/driver_photos') !== false) {
        return '';
    }
    return $url;
}

/**
 * @return array{ran:bool,missed:bool,last_run_pt:string,message_key:string,message_params:array{when:string}}
 */
function bakery_route_health_overnight(PDO $db, ?DateTimeImmutable $now = null): array
{
    $status = bakery_demand_scheduler_board_status($db, $now);
    $missed = !empty($status['missed']);
    $when = trim((string)($status['last_run_pt'] ?? ''));
    if (!$missed) {
        $key = 'route_health.overnight_ran';
    } elseif ($when !== '') {
        $key = 'route_health.overnight_missed_last';
    } else {
        $key = 'route_health.overnight_missed';
    }
    return [
        'ran' => !$missed,
        'missed' => $missed,
        'last_run_pt' => $when,
        'message_key' => $key,
        'message_params' => ['when' => $when],
    ];
}

/**
 * Active standing stops versus dated orders for one sell day.
 *
 * @return array{date:string,drivers:list<array<string,mixed>>,overnight:array<string,mixed>,build_href:string}
 */
function bakery_route_health_tomorrow(PDO $db, string $date, ?DateTimeImmutable $now = null): array
{
    $valid = bakery_route_health_valid_date($date);
    $date = $valid ?? $date;
    $loaded = bakery_daily_status_load_routes($db, $date);
    $rows = [];
    foreach ($loaded['standing'] as $route) {
        if (!is_array($route) || (int)($route['customer_active'] ?? 1) !== 1) {
            continue;
        }
        $driverId = (int)($route['driver_id'] ?? 0);
        if ($driverId <= 0) {
            continue;
        }
        if (!isset($rows[$driverId])) {
            $rows[$driverId] = [
                'driver_id' => $driverId,
                'driver_name' => (string)($route['driver_name'] ?? ''),
                'dated' => 0,
                'standing' => 0,
                'short' => false,
            ];
        }
        $rows[$driverId]['standing']++;
    }
    foreach ($loaded['orders'] as $order) {
        if (!is_array($order) || (int)($order['customer_active'] ?? 1) !== 1) {
            continue;
        }
        $driverId = (int)($order['assigned_driver_id'] ?? 0);
        if ($driverId <= 0) {
            continue;
        }
        if (!isset($rows[$driverId])) {
            $rows[$driverId] = [
                'driver_id' => $driverId,
                'driver_name' => (string)($order['assigned_driver_name'] ?? ''),
                'dated' => 0,
                'standing' => 0,
                'short' => false,
            ];
        }
        $rows[$driverId]['dated']++;
        if ($rows[$driverId]['driver_name'] === '') {
            $rows[$driverId]['driver_name'] = (string)($order['assigned_driver_name'] ?? '');
        }
    }
    $drivers = array_values($rows);
    foreach ($drivers as &$driver) {
        $driver['short'] = bakery_route_health_dated_is_short((int)$driver['dated'], (int)$driver['standing']);
    }
    unset($driver);
    usort($drivers, static function (array $a, array $b): int {
        if ((bool)$a['short'] !== (bool)$b['short']) {
            return !empty($a['short']) ? -1 : 1;
        }
        return strcasecmp((string)$a['driver_name'], (string)$b['driver_name']);
    });
    return [
        'date' => $date,
        'drivers' => $drivers,
        'overnight' => bakery_route_health_overnight($db, $now),
        'build_href' => 'daily_orders.php?date=' . rawurlencode($date),
    ];
}

/**
 * @return array<string, mixed>
 */
function bakery_route_health_load(PDO $db, string $from, string $to, ?DateTimeImmutable $now = null): array
{
    $from = bakery_route_health_valid_date($from) ?? $from;
    $to = bakery_route_health_valid_date($to) ?? $to;
    if ($from > $to) {
        $swap = $from;
        $from = $to;
        $to = $swap;
    }
    $dates = bakery_route_health_dates($from, $to);
    $origin = function_exists('bakery_sfb_ops_origin_clause') ? bakery_sfb_ops_origin_clause('c', $db) : '';
    $confirmedSql = function_exists('column_exists') && column_exists($db, 'daily_orders', 'delivery_confirmed_at')
        ? 'do.delivery_confirmed_at'
        : 'NULL';
    $stops = [];
    if (function_exists('table_exists') && table_exists($db, 'daily_order_assignments') && table_exists($db, 'daily_orders')) {
        $stmt = $db->prepare(
            "SELECT doa.driver_id, d.name AS driver_name, doa.delivery_date, doa.route_order,
                    doa.delivery_status, doa.actual_delivery_time, do.status AS order_status,
                    {$confirmedSql} AS delivery_confirmed_at, c.id AS customer_id, c.name AS customer_name
             FROM daily_order_assignments doa
             INNER JOIN drivers d ON d.id = doa.driver_id
             INNER JOIN daily_orders do ON do.id = doa.daily_order_id AND do.order_date = doa.delivery_date
             INNER JOIN customers c ON c.id = do.customer_id
             WHERE doa.delivery_date >= ? AND doa.delivery_date <= ?
             {$origin}
             ORDER BY doa.delivery_date, d.name, doa.route_order, c.name"
        );
        $stmt->execute([$from, $to]);
        $stops = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
            $stamp = trim((string)($punch['clock_in_at'] ?? ''));
            $day = substr($stamp, 0, 10);
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
            $key = (int)$load['driver_id'] . '|' . (string)$load['delivery_date'];
            $closed[$key] = (string)$load['status'] === 'reconciled';
        }
    }

    $standing = [];
    if (function_exists('table_exists') && table_exists($db, 'standing_routes')) {
        $standStmt = $db->query(
            "SELECT sr.day_of_week, sr.driver_id, d.name AS driver_name
             FROM standing_routes sr
             INNER JOIN drivers d ON d.id = sr.driver_id
             INNER JOIN customers c ON c.id = sr.customer_id AND c.is_active = 1
             WHERE 1 = 1 {$origin}"
        );
        $standing = $standStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $days = [];
    $openByCustomer = [];
    $missingAfter = [];
    $deliveredForBulk = [];

    foreach ($stops as $stop) {
        if (!is_array($stop)) {
            continue;
        }
        $status = bakery_route_health_stop_status($stop);
        if ($status === 'cancelled') {
            continue;
        }
        $driverId = (int)$stop['driver_id'];
        $date = (string)$stop['delivery_date'];
        $customerId = (int)$stop['customer_id'];
        $name = (string)$stop['customer_name'];
        $driverName = (string)$stop['driver_name'];
        $key = $driverId . '|' . $date;
        if (!isset($days[$key])) {
            $days[$key] = bakery_route_health_blank_day($driverId, $driverName, $date, $clockReady, $closeReady, $clockIns, $closed);
        }
        $days[$key]['stops']++;
        $photos = $photosByStop[$customerId . '|' . $date] ?? [];
        $hasAfter = bakery_route_stop_has_after_photo($photos);
        if ($status === 'delivered') {
            $days[$key]['delivered']++;
            if ($hasAfter) {
                $days[$key]['delivered_with_after']++;
            } else {
                $missingAfter[] = [
                    'customer_id' => $customerId,
                    'customer_name' => $name,
                    'driver_id' => $driverId,
                    'driver_name' => $driverName,
                    'date' => $date,
                    'photo_url' => bakery_route_health_photo_url($photos),
                ];
            }
            $deliveredForBulk[$key][] = [
                'customer_name' => $name,
                'badge' => 'pod',
                'delivered_at' => bakery_route_health_closed_at($date, $stop),
            ];
        } else {
            $days[$key]['open']++;
            if (!isset($openByCustomer[$customerId])) {
                $openByCustomer[$customerId] = [
                    'customer_id' => $customerId,
                    'customer_name' => $name,
                    'dates' => [],
                    'drivers' => [],
                ];
            }
            if (!in_array($date, $openByCustomer[$customerId]['dates'], true)) {
                $openByCustomer[$customerId]['dates'][] = $date;
            }
            if ($driverName !== '' && !in_array($driverName, $openByCustomer[$customerId]['drivers'], true)) {
                $openByCustomer[$customerId]['drivers'][] = $driverName;
            }
        }
    }

    $standingByDate = [];
    foreach ($dates as $date) {
        $weekday = function_exists('bakery_standing_day_from_date') ? (int)bakery_standing_day_from_date($date) : (int)(new DateTimeImmutable($date))->format('N');
        $match = function_exists('bakery_standing_day_match_values')
            ? bakery_standing_day_match_values($weekday)
            : [$weekday];
        foreach ($standing as $route) {
            $stored = (int)($route['day_of_week'] ?? 0);
            if (!in_array($stored, array_map('intval', $match), true)) {
                continue;
            }
            $driverId = (int)$route['driver_id'];
            $standingByDate[$date][$driverId] = (string)$route['driver_name'];
        }
    }
    foreach ($standingByDate as $date => $drivers) {
        foreach ($drivers as $driverId => $driverName) {
            $key = $driverId . '|' . $date;
            if (isset($days[$key])) {
                continue;
            }
            $days[$key] = bakery_route_health_blank_day((int)$driverId, $driverName, (string)$date, $clockReady, $closeReady, $clockIns, $closed);
        }
    }

    $dayRows = array_values($days);
    usort($dayRows, static function (array $a, array $b): int {
        $date = strcmp((string)$a['date'], (string)$b['date']);
        if ($date !== 0) {
            return $date;
        }
        return strcasecmp((string)$a['driver_name'], (string)$b['driver_name']);
    });

    $repeat = [];
    foreach ($openByCustomer as $row) {
        $times = count($row['dates']);
        if ($times < BAKERY_ROUTE_HEALTH_REPEAT_SKIP_DAYS) {
            continue;
        }
        sort($row['dates']);
        $row['times'] = $times;
        $repeat[] = $row;
    }
    usort($repeat, static function (array $a, array $b): int {
        $times = ((int)$b['times']) <=> ((int)$a['times']);
        if ($times !== 0) {
            return $times;
        }
        return strcasecmp((string)$a['customer_name'], (string)$b['customer_name']);
    });

    $bulk = [];
    foreach ($deliveredForBulk as $key => $group) {
        $names = bakery_route_stop_bulk_stamp_names($group);
        if ($names === []) {
            continue;
        }
        [$driverId, $date] = explode('|', $key, 2);
        $driverName = '';
        foreach ($dayRows as $day) {
            if ((int)$day['driver_id'] === (int)$driverId && (string)$day['date'] === $date) {
                $driverName = (string)$day['driver_name'];
                break;
            }
        }
        $bulk[] = [
            'driver_id' => (int)$driverId,
            'driver_name' => $driverName,
            'date' => $date,
            'stores' => $names,
        ];
    }
    usort($bulk, static function (array $a, array $b): int {
        $date = strcmp((string)$a['date'], (string)$b['date']);
        if ($date !== 0) {
            return $date;
        }
        return strcasecmp((string)$a['driver_name'], (string)$b['driver_name']);
    });

    usort($missingAfter, static function (array $a, array $b): int {
        $date = strcmp((string)$a['date'], (string)$b['date']);
        if ($date !== 0) {
            return $date;
        }
        $driver = strcasecmp((string)$a['driver_name'], (string)$b['driver_name']);
        if ($driver !== 0) {
            return $driver;
        }
        return strcasecmp((string)$a['customer_name'], (string)$b['customer_name']);
    });

    $stopTotal = 0;
    $deliveredTotal = 0;
    foreach ($dayRows as $day) {
        $stopTotal += (int)$day['stops'];
        $deliveredTotal += (int)$day['delivered'];
    }

    return [
        'from' => $from,
        'to' => $to,
        'days' => $dayRows,
        'repeat_skips' => $repeat,
        'missing_after' => $missingAfter,
        'bulk_stamps' => $bulk,
        'clock_ready' => $clockReady,
        'close_ready' => $closeReady,
        'stop_total' => $stopTotal,
        'delivered_total' => $deliveredTotal,
        'now' => $now,
    ];
}

/**
 * @param array<string, bool> $clockIns
 * @param array<string, bool> $closed
 * @return array<string, mixed>
 */
function bakery_route_health_blank_day(
    int $driverId,
    string $driverName,
    string $date,
    bool $clockReady,
    bool $closeReady,
    array $clockIns,
    array $closed
): array {
    $key = $driverId . '|' . $date;
    return [
        'driver_id' => $driverId,
        'driver_name' => $driverName,
        'date' => $date,
        'stops' => 0,
        'delivered' => 0,
        'open' => 0,
        'delivered_with_after' => 0,
        'route_closed' => $closeReady ? !empty($closed[$key]) : null,
        'clocked_in' => $clockReady ? !empty($clockIns[$key]) : null,
    ];
}

function bakery_route_health_yes_no(?bool $value, bool $ready): string
{
    if (!$ready || $value === null) {
        return bakery_t('route_health.not_recorded');
    }
    return bakery_t($value ? 'route_health.yes' : 'route_health.no');
}

function bakery_route_health_display_date(string $date): string
{
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    if (!$parsed) {
        return $date;
    }
    return $parsed->format('M j');
}
