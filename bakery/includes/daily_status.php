<?php
/**
 * Read-only daily operations snapshot for monitoring.
 *
 * Reuses Daily Run blockers, Driver Assignment standing-vs-dated counts,
 * Route Summary stop/photo reads, and existing Survey Center tokens.
 * Does not mint surveys or write operational rows.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/common_functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/client_cache.php';
require_once __DIR__ . '/daily_run.php';
require_once __DIR__ . '/route_manager.php';
require_once __DIR__ . '/route_summary.php';
require_once __DIR__ . '/surveys.php';

/**
 * Server-side read token. Set DAILY_STATUS_TOKEN in the environment or .env.
 * Never commit a real value.
 */
function bakery_daily_status_configured_token(): string
{
    $raw = $_ENV['DAILY_STATUS_TOKEN'] ?? getenv('DAILY_STATUS_TOKEN');
    if ($raw === false || $raw === null) {
        return '';
    }
    return trim((string)$raw);
}

/**
 * Bearer token from the request, if the header is well formed.
 *
 * @param array<string, mixed> $server
 */
function bakery_daily_status_presented_token(array $server): string
{
    $header = (string)($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string)$name, 'Authorization') === 0) {
                    $header = (string)$value;
                    break;
                }
            }
        }
    }
    if (preg_match('/^Bearer\s+(\S+)\s*$/i', trim($header), $matches) !== 1) {
        return '';
    }
    return (string)$matches[1];
}

/**
 * 200 for an owner/manager session or a matching bearer token, 403 for any
 * other signed-in role, 401 when nobody authenticated.
 *
 * @param array<string, mixed> $server
 */
function bakery_daily_status_auth_status(array $server): int
{
    $user = bakery_current_user();
    if (is_array($user) && (int)($user['id'] ?? 0) > 0) {
        if (bakery_user_has_role(['administrator', 'manager'])) {
            return 200;
        }
        return 403;
    }

    $expected = bakery_daily_status_configured_token();
    $provided = bakery_daily_status_presented_token($server);
    if ($expected !== '' && $provided !== '' && hash_equals($expected, $provided)) {
        return 200;
    }
    return 401;
}

/**
 * @param array<string, mixed> $exception
 * @return array<string, mixed>
 */
function bakery_daily_status_public_blocker(array $exception): array
{
    return [
        'type' => (string)($exception['type'] ?? ''),
        'severity' => (string)($exception['severity'] ?? ''),
        'stage' => (string)($exception['stage'] ?? ''),
        'title' => (string)($exception['title'] ?? ''),
        'detail' => (string)($exception['detail'] ?? ''),
        'count' => array_key_exists('count', $exception) ? $exception['count'] : null,
        'href' => isset($exception['href']) ? (string)$exception['href'] : null,
        'action' => (string)($exception['action'] ?? ''),
    ];
}

/**
 * Recorded delivery time only. Scheduled time is not a delivery.
 *
 * @param array<string, mixed> $stop
 */
function bakery_daily_status_delivered_at(array $stop): ?string
{
    if ((string)($stop['delivery_status'] ?? '') !== 'delivered') {
        return null;
    }
    foreach (['actual_delivery_time', 'delivery_confirmed_at'] as $field) {
        $value = trim((string)($stop[$field] ?? ''));
        if ($value !== '' && $value !== '0000-00-00 00:00:00') {
            return $value;
        }
    }
    return null;
}

/**
 * @return array{orders:list<array<string,mixed>>,standing:list<array<string,mixed>>}
 */
function bakery_daily_status_load_routes(PDO $db, string $date): array
{
    $weekday = bakery_standing_day_from_date($date);
    $origin = bakery_sfb_ops_origin_clause('c', $db);
    $orders = [];
    if (table_exists($db, 'daily_orders')) {
        $stmt = $db->prepare(
            "SELECT
                do.id,
                do.customer_id,
                c.is_active AS customer_active,
                c.name AS customer_name,
                doa.driver_id AS assigned_driver_id,
                d.name AS assigned_driver_name
             FROM daily_orders do
             JOIN customers c ON do.customer_id = c.id
             {$origin}
             LEFT JOIN daily_order_assignments doa
               ON do.id = doa.daily_order_id AND doa.delivery_date = do.order_date
             LEFT JOIN drivers d ON doa.driver_id = d.id
             WHERE do.order_date = ?
             ORDER BY doa.route_order, c.name"
        );
        $stmt->execute([$date]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $standing = [];
    if (table_exists($db, 'standing_routes')) {
        $stmt = $db->prepare(
            "SELECT
                sr.customer_id,
                c.is_active AS customer_active,
                sr.driver_id,
                c.name AS customer_name,
                d.name AS driver_name
             FROM standing_routes sr
             JOIN customers c ON sr.customer_id = c.id
             {$origin}
             JOIN drivers d ON sr.driver_id = d.id
             WHERE CASE WHEN sr.day_of_week = 0 THEN 7 ELSE sr.day_of_week END = ?
             ORDER BY d.name, COALESCE(sr.route_order, 2147483647), c.name"
        );
        $stmt->execute([$weekday]);
        $standing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    return ['orders' => $orders, 'standing' => $standing];
}

/**
 * @param list<array<string,mixed>> $orders
 * @param list<array<string,mixed>> $standing
 * @return list<array<string,mixed>>
 */
function bakery_daily_status_drivers(array $orders, array $standing): array
{
    $datedRows = [];
    $datedCustomers = [];
    $assignedDriverByCustomer = [];
    foreach ($orders as $order) {
        $driverId = (int)($order['assigned_driver_id'] ?? 0);
        if ($driverId <= 0) {
            continue;
        }
        $customerId = (int)($order['customer_id'] ?? 0);
        $assignedDriverByCustomer[$customerId] = $driverId;
        if ((int)($order['customer_active'] ?? 1) === 1) {
            $datedCustomers[$driverId][$customerId] = true;
        }
        if (!isset($datedRows[$driverId])) {
            $datedRows[$driverId] = [
                'driver_id' => $driverId,
                'driver_name' => (string)($order['assigned_driver_name'] ?? ''),
                'dated_stop_count' => 0,
                'standing_stop_count' => 0,
                'missing_standing_stops' => [],
            ];
        }
        $datedRows[$driverId]['dated_stop_count']++;
    }

    $standingRows = [];
    $standingCustomers = [];
    foreach ($standing as $route) {
        $driverId = (int)($route['driver_id'] ?? 0);
        $customerId = (int)($route['customer_id'] ?? 0);
        if ($driverId <= 0) {
            continue;
        }
        if (!isset($standingRows[$driverId])) {
            $standingRows[$driverId] = [
                'driver_id' => $driverId,
                'driver_name' => (string)($route['driver_name'] ?? ''),
                'dated_stop_count' => 0,
                'standing_stop_count' => 0,
                'missing_standing_stops' => [],
            ];
        }
        $standingRows[$driverId]['standing_stop_count']++;
        if ((int)($route['customer_active'] ?? 1) === 1) {
            $standingCustomers[$driverId][$customerId] = true;
        }
        if (($assignedDriverByCustomer[$customerId] ?? 0) !== $driverId) {
            $standingRows[$driverId]['missing_standing_stops'][] = (string)($route['customer_name'] ?? '');
        }
    }

    $merged = $standingRows;
    foreach ($datedRows as $driverId => $row) {
        if (!isset($merged[$driverId])) {
            $merged[$driverId] = $row;
            continue;
        }
        $merged[$driverId]['dated_stop_count'] = $row['dated_stop_count'];
        if ($merged[$driverId]['driver_name'] === '') {
            $merged[$driverId]['driver_name'] = $row['driver_name'];
        }
    }

    $drivers = array_values($merged);
    usort($drivers, static function (array $a, array $b): int {
        return strcasecmp((string)$a['driver_name'], (string)$b['driver_name']);
    });
    foreach ($drivers as &$driver) {
        $driverId = (int)$driver['driver_id'];
        $driver['survey_assigned_count'] = isset($datedCustomers[$driverId]) && $datedCustomers[$driverId] !== []
            ? count($datedCustomers[$driverId])
            : count($standingCustomers[$driverId] ?? []);
    }
    unset($driver);
    return $drivers;
}

/**
 * @param list<array<string,mixed>> $orders
 * @return list<array{store_name:string,daily_order_id:int}>
 */
function bakery_daily_status_unassigned_stops(array $orders): array
{
    $stops = [];
    $seen = [];
    foreach ($orders as $order) {
        if ((int)($order['assigned_driver_id'] ?? 0) > 0) {
            continue;
        }
        $orderId = (int)($order['id'] ?? 0);
        if ($orderId <= 0 || isset($seen[$orderId])) {
            continue;
        }
        $seen[$orderId] = true;
        $stops[] = [
            'store_name' => (string)($order['customer_name'] ?? ''),
            'daily_order_id' => $orderId,
        ];
    }
    return $stops;
}

/**
 * Standing product orders for this weekday whose store has no standing route
 * and no dated assignment.
 *
 * @return list<array{store_name:string}>
 */
function bakery_daily_status_standing_orders_without_route(PDO $db, string $date): array
{
    if (!table_exists($db, 'standing_orders') || !table_exists($db, 'customers')) {
        return [];
    }
    $weekday = bakery_standing_day_from_date($date);
    $origin = bakery_sfb_ops_origin_clause('c', $db);
    $routeGap = table_exists($db, 'standing_routes')
        ? "AND NOT EXISTS (
                SELECT 1 FROM standing_routes sr
                WHERE sr.customer_id = c.id
                  AND CASE WHEN sr.day_of_week = 0 THEN 7 ELSE sr.day_of_week END = ?
           )"
        : '';
    $datedGap = table_exists($db, 'daily_orders') && table_exists($db, 'daily_order_assignments')
        ? "AND NOT EXISTS (
                SELECT 1
                FROM daily_orders do
                INNER JOIN daily_order_assignments doa
                  ON doa.daily_order_id = do.id AND doa.delivery_date = do.order_date
                WHERE do.customer_id = c.id AND do.order_date = ?
           )"
        : '';
    $sql = "SELECT DISTINCT c.name AS store_name
            FROM standing_orders so
            JOIN customers c ON c.id = so.customer_id AND c.is_active = 1
            {$origin}
            WHERE CASE WHEN so.day_of_week = 0 THEN 7 ELSE so.day_of_week END = ?
            {$routeGap}
            {$datedGap}
            ORDER BY c.name";
    $params = [$weekday];
    if ($routeGap !== '') {
        $params[] = $weekday;
    }
    if ($datedGap !== '') {
        $params[] = $date;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = ['store_name' => (string)$row['store_name']];
    }
    return $rows;
}

/**
 * @return list<array<string,mixed>>
 */
function bakery_daily_status_stops(PDO $db, string $date): array
{
    $driversData = route_manager_fetch_deliveries($db, $date);
    $photosByStop = route_manager_fetch_photos_for_date($db, $date);
    $stops = [];
    foreach ($driversData as $driver) {
        $driverId = (int)($driver['id'] ?? 0);
        $driverName = (string)($driver['name'] ?? '');
        foreach ($driver['deliveries'] ?? [] as $delivery) {
            if (!is_array($delivery)) {
                continue;
            }
            $customerId = (int)($delivery['customer_id'] ?? 0);
            $photos = $photosByStop[$driverId . ':' . $customerId] ?? [];
            $hero = $photos !== [] ? bakery_route_summary_choose_hero_photo($photos) : null;
            $photoUrl = is_array($hero) ? trim((string)($hero['url'] ?? '')) : '';
            $stops[] = [
                'store_name' => (string)($delivery['customer_name'] ?? ''),
                'driver_id' => $driverId,
                'driver_name' => $driverName,
                'delivery_status' => (string)($delivery['delivery_status'] ?? 'pending'),
                'delivered_at' => bakery_daily_status_delivered_at($delivery),
                'photo_present' => $photoUrl !== '',
                'photo_url' => $photoUrl !== '' ? $photoUrl : null,
            ];
        }
    }
    return $stops;
}

/**
 * @param list<array<string,mixed>> $rows newest id first
 * @return array<string,array<string,mixed>>
 */
function bakery_daily_status_pick_surveys(array $rows): array
{
    $open = [];
    $any = [];
    foreach ($rows as $row) {
        $driverId = (int)($row['driver_id'] ?? 0);
        $kind = (string)($row['kind'] ?? '');
        if ($kind !== 'store_verify' && $kind !== 'route_order') {
            continue;
        }
        $key = $driverId . '|' . $kind;
        if (!isset($any[$key])) {
            $any[$key] = $row;
        }
        if ((string)($row['status'] ?? '') === 'open' && !isset($open[$key])) {
            $open[$key] = $row;
        }
    }
    return $open + $any;
}

function bakery_daily_status_survey_link(string $token, string $date): string
{
    if ($token === '') {
        return '';
    }
    return bakery_survey_link_url($token, $date);
}

/**
 * @param array<string,array<string,mixed>> $picked
 * @return array{token:string,url:string,status:string}
 */
function bakery_daily_status_survey_slot(array $picked, int $driverId, string $kind, string $date): array
{
    $row = $picked[$driverId . '|' . $kind] ?? null;
    $token = is_array($row) ? (string)($row['token'] ?? '') : '';
    $status = is_array($row) ? (string)($row['status'] ?? '') : '';
    if ($status === '') {
        $status = 'not_issued';
    }
    return [
        'token' => $token,
        'url' => bakery_daily_status_survey_link($token, $date),
        'status' => $status,
    ];
}

/**
 * Existing Survey Center tokens only. Does not call the ensure/mint helpers.
 *
 * @param array<int,int> $assignedCounts
 * @return array<string,mixed>
 */
function bakery_daily_status_survey(PDO $db, string $date, array $assignedCounts): array
{
    $empty = [
        'date' => $date,
        'lock_status' => 'not_issued',
        'hq' => [
            'verify_token' => '',
            'verify_url' => '',
            'verify_status' => 'not_issued',
            'order_token' => '',
            'order_url' => '',
            'order_status' => 'not_issued',
        ],
        'drivers' => [],
    ];
    if (!table_exists($db, 'surveys')) {
        return $empty;
    }

    $stmt = $db->prepare(
        "SELECT id, token, kind, driver_id, status
         FROM surveys
         WHERE delivery_date = ?
           AND kind IN ('store_verify', 'route_order')
         ORDER BY id DESC"
    );
    $stmt->execute([$date]);
    $picked = bakery_daily_status_pick_surveys($stmt->fetchAll(PDO::FETCH_ASSOC));
    $verify = bakery_daily_status_survey_slot($picked, 0, 'store_verify', $date);
    $order = bakery_daily_status_survey_slot($picked, 0, 'route_order', $date);

    $drivers = [];
    foreach (bakery_get_drivers($db) as $driver) {
        $driverId = (int)($driver['id'] ?? 0);
        if ($driverId <= 0) {
            continue;
        }
        $driverVerify = bakery_daily_status_survey_slot($picked, $driverId, 'store_verify', $date);
        $driverOrder = bakery_daily_status_survey_slot($picked, $driverId, 'route_order', $date);
        $drivers[] = [
            'driver_id' => $driverId,
            'driver_name' => (string)($driver['name'] ?? ''),
            'assigned_count' => (int)($assignedCounts[$driverId] ?? 0),
            'verify_token' => $driverVerify['token'],
            'verify_url' => $driverVerify['url'],
            'verify_status' => $driverVerify['status'],
            'order_token' => $driverOrder['token'],
            'order_url' => $driverOrder['url'],
            'order_status' => $driverOrder['status'],
        ];
    }

    return [
        'date' => $date,
        'lock_status' => $verify['status'],
        'hq' => [
            'verify_token' => $verify['token'],
            'verify_url' => $verify['url'],
            'verify_status' => $verify['status'],
            'order_token' => $order['token'],
            'order_url' => $order['url'],
            'order_status' => $order['status'],
        ],
        'drivers' => $drivers,
    ];
}

/**
 * One JSON object for an operating date. SELECT only.
 *
 * @return array<string,mixed>
 */
function bakery_daily_status_snapshot(PDO $db, string $date): array
{
    $date = bakery_driver_validate_delivery_date($date);
    $run = bakery_daily_run_build($db, $date);
    $blockers = [];
    foreach ($run['blockers'] ?? [] as $exception) {
        if (is_array($exception)) {
            $blockers[] = bakery_daily_status_public_blocker($exception);
        }
    }

    $routes = bakery_daily_status_load_routes($db, $date);
    $driverRows = bakery_daily_status_drivers($routes['orders'], $routes['standing']);
    $assignedCounts = [];
    $drivers = [];
    foreach ($driverRows as $row) {
        $driverId = (int)$row['driver_id'];
        $assignedCounts[$driverId] = (int)$row['survey_assigned_count'];
        $drivers[] = [
            'driver_id' => $driverId,
            'driver_name' => (string)$row['driver_name'],
            'dated_stop_count' => (int)$row['dated_stop_count'],
            'standing_stop_count' => (int)$row['standing_stop_count'],
            'missing_standing_stops' => array_values($row['missing_standing_stops']),
        ];
    }

    return [
        'date' => $date,
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'build' => bakery_client_build_id(),
        'blockers' => $blockers,
        'drivers' => $drivers,
        'unassigned' => [
            'stops' => bakery_daily_status_unassigned_stops($routes['orders']),
            'standing_orders_without_route' => bakery_daily_status_standing_orders_without_route($db, $date),
        ],
        'stops' => bakery_daily_status_stops($db, $date),
        'survey' => bakery_daily_status_survey($db, $date, $assignedCounts),
    ];
}

/**
 * @param array<string,mixed> $server
 * @return array{status:int,body:array<string,mixed>}
 */
function bakery_daily_status_dispatch(PDO $db, string $method, string $dateRaw, array $server): array
{
    if (strtoupper($method) !== 'GET') {
        return ['status' => 405, 'body' => ['ok' => false, 'error' => 'method_not_allowed']];
    }
    $auth = bakery_daily_status_auth_status($server);
    if ($auth !== 200) {
        return [
            'status' => $auth,
            'body' => ['ok' => false, 'error' => $auth === 403 ? 'forbidden' : 'unauthorized'],
        ];
    }
    try {
        $date = bakery_driver_validate_delivery_date($dateRaw);
    } catch (RuntimeException $e) {
        return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_date']];
    }
    try {
        $body = bakery_daily_status_snapshot($db, $date);
        $body['ok'] = true;
        return ['status' => 200, 'body' => $body];
    } catch (Throwable $e) {
        error_log('daily_status: ' . $e->getMessage());
        return ['status' => 500, 'body' => ['ok' => false, 'error' => 'unavailable']];
    }
}
