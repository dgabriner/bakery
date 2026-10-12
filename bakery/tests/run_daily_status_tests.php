<?php
/**
 * Read-only daily operations snapshot: auth, GET-only, payload shape.
 *
 * Usage: php tests/run_daily_status_tests.php
 * Targets bakerysf_test on loopback only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
$helper = $root . '/includes/daily_status.php';
$endpoint = $root . '/daily_status_api.php';

if (!is_file($helper) || !is_file($endpoint)) {
    echo "FAIL  daily status helper and endpoint exist\n";
    echo "\n0 passed, 1 failed\n";
    exit(1);
}

require_once $root . '/tests/isolate_test_db.php';
$db = require $root . '/tests/harness.php';
require_once $helper;
require_once $root . '/includes/daily_run.php';
require_once $root . '/includes/surveys.php';
require_once $root . '/includes/route_summary.php';

$date = '2099-06-03';
$weekday = bakery_standing_day_from_date($date);

function daily_status_clear_session(): void
{
    $_SESSION = [];
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
}

function daily_status_session(string $role): void
{
    daily_status_clear_session();
    $_SESSION['user_id'] = 1;
    $_SESSION['user_email'] = 'daily-status@example.test';
    $_SESSION['user_display_name'] = 'Daily Status';
    $_SESSION['user_role_slug'] = $role;
}

function daily_status_table_counts(PDO $db): array
{
    $names = [
        'customers',
        'drivers',
        'daily_orders',
        'daily_order_assignments',
        'standing_routes',
        'standing_orders',
        'driver_photos',
        'surveys',
        'survey_responses',
    ];
    $counts = [];
    foreach ($names as $name) {
        if (!table_exists($db, $name)) {
            $counts[$name] = null;
            continue;
        }
        $counts[$name] = (int)$db->query('SELECT COUNT(*) FROM `' . $name . '`')->fetchColumn();
    }
    return $counts;
}

$productId = (int)$db->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
assert_true($productId > 0, 'fixture catalog has a product for standing orders');

$prefix = 'DailyStatus ' . bin2hex(random_bytes(4));
$names = [
    'one' => $prefix . ' One',
    'two' => $prefix . ' Two',
    'three' => $prefix . ' Three',
    'four' => $prefix . ' Four',
    'five' => $prefix . ' Five',
];
$insertCustomer = $db->prepare(
    "INSERT INTO customers (name, address, is_active, sfb_origin) VALUES (?, '1 Test St', 1, 'human')"
);
$customerIds = [];
foreach ($names as $key => $name) {
    $insertCustomer->execute([$name]);
    $customerIds[$key] = (int)$db->lastInsertId();
}

$insertDriver = $db->prepare('INSERT INTO drivers (name) VALUES (?)');
$insertDriver->execute([$prefix . ' Ada']);
$adaId = (int)$db->lastInsertId();
$insertDriver->execute([$prefix . ' Bo']);
$boId = (int)$db->lastInsertId();

$insertStandingRoute = $db->prepare(
    'INSERT INTO standing_routes (day_of_week, driver_id, customer_id, route_order) VALUES (?, ?, ?, ?)'
);
$insertStandingRoute->execute([$weekday, $adaId, $customerIds['one'], 1]);
$insertStandingRoute->execute([$weekday, $adaId, $customerIds['two'], 2]);
$insertStandingRoute->execute([$weekday, $boId, $customerIds['three'], 1]);

$insertOrder = $db->prepare(
    "INSERT INTO daily_orders (customer_id, order_date, status, total_amount, delivery_confirmed_at)
     VALUES (?, ?, ?, 0, ?)"
);
$insertOrder->execute([$customerIds['one'], $date, 'delivered', $date . ' 09:16:00']);
$deliveredOrderId = (int)$db->lastInsertId();
$insertOrder->execute([$customerIds['four'], $date, 'pending', null]);
$unassignedOrderId = (int)$db->lastInsertId();

$db->prepare(
    "INSERT INTO daily_order_assignments
        (daily_order_id, driver_id, delivery_date, route_order, delivery_status, actual_delivery_time, scheduled_delivery_time)
     VALUES (?, ?, ?, 1, 'delivered', '09:15:00', '08:00:00')"
)->execute([$deliveredOrderId, $adaId, $date]);

$db->prepare(
    "INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, 4)"
)->execute([$customerIds['five'], $productId, $weekday]);

$db->prepare(
    "INSERT INTO driver_photos
        (driver_id, customer_id, delivery_date, filename, original_filename, file_path, file_size, mime_type, photo_type)
     VALUES (?, ?, ?, 'status-proof.jpg', 'status-proof.jpg', 'status-proof.jpg', 12, 'image/jpeg', 'After')"
)->execute([$adaId, $customerIds['one'], $date]);

$hqToken = bin2hex(random_bytes(16));
$adaVerify = bin2hex(random_bytes(16));
$adaOrder = bin2hex(random_bytes(16));
$insertSurvey = $db->prepare(
    "INSERT INTO surveys (token, mode, kind, audience, driver_id, delivery_date, status, title)
     VALUES (?, 'link', ?, 'driver', ?, ?, ?, ?)"
);
$insertSurvey->execute([$hqToken, 'store_verify', null, $date, 'open', 'HQ store verify']);
$insertSurvey->execute([$adaVerify, 'store_verify', $adaId, $date, 'open', 'Store verify']);
$insertSurvey->execute([$adaOrder, 'route_order', $adaId, $date, 'closed', 'Route order']);

$before = daily_status_table_counts($db);

echo "\n=== Auth ===\n";
daily_status_clear_session();
putenv('DAILY_STATUS_TOKEN');
unset($_ENV['DAILY_STATUS_TOKEN'], $_SERVER['DAILY_STATUS_TOKEN']);
$anonymous = bakery_daily_status_dispatch($db, 'GET', $date, $_SERVER);
assert_eq(401, $anonymous['status'], 'anonymous GET is 401');
assert_eq(false, $anonymous['body']['ok'] ?? null, 'anonymous body is not ok');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wrong-token';
putenv('DAILY_STATUS_TOKEN=correct-token');
$_ENV['DAILY_STATUS_TOKEN'] = 'correct-token';
$wrong = bakery_daily_status_dispatch($db, 'GET', $date, $_SERVER);
assert_eq(401, $wrong['status'], 'wrong bearer token is 401');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer correct-token';
$bearer = bakery_daily_status_dispatch($db, 'GET', $date, $_SERVER);
assert_eq(200, $bearer['status'], 'matching bearer token is allowed');

daily_status_clear_session();
putenv('DAILY_STATUS_TOKEN');
unset($_ENV['DAILY_STATUS_TOKEN'], $_SERVER['DAILY_STATUS_TOKEN']);
daily_status_session('manager');
$manager = bakery_daily_status_dispatch($db, 'GET', $date, $_SERVER);
assert_eq(200, $manager['status'], 'manager session is allowed without a token');

daily_status_session('administrator');
$admin = bakery_daily_status_dispatch($db, 'GET', $date, $_SERVER);
assert_eq(200, $admin['status'], 'administrator session is allowed without a token');

daily_status_session('driver');
$driver = bakery_daily_status_dispatch($db, 'GET', $date, $_SERVER);
assert_eq(403, $driver['status'], 'driver session cannot read the snapshot');

echo "\n=== GET only ===\n";
daily_status_session('manager');
foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
    $rejected = bakery_daily_status_dispatch($db, $method, $date, $_SERVER);
    assert_eq(405, $rejected['status'], $method . ' is rejected');
}
$badDate = bakery_daily_status_dispatch($db, 'GET', 'yesterday', $_SERVER);
assert_eq(400, $badDate['status'], 'invalid date is 400');

echo "\n=== Payload ===\n";
daily_status_clear_session();
putenv('DAILY_STATUS_TOKEN=correct-token');
$_ENV['DAILY_STATUS_TOKEN'] = 'correct-token';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer correct-token';
$payload = bakery_daily_status_dispatch($db, 'GET', $date, $_SERVER);
assert_eq(200, $payload['status'], 'authorized snapshot returns 200');
$body = $payload['body'];
assert_eq($date, $body['date'] ?? null, 'payload date matches the request');
assert_true(isset($body['generated_at']) && strtotime((string)$body['generated_at']) !== false, 'generated_at is a timestamp');
assert_eq(bakery_client_build_id(), $body['build'] ?? null, 'build matches bakery_client_build_id');

$run = bakery_daily_run_build($db, $date);
$expectedBlockers = [];
foreach ($run['blockers'] as $ex) {
    $expectedBlockers[] = [
        'type' => (string)($ex['type'] ?? ''),
        'severity' => (string)($ex['severity'] ?? ''),
        'title' => (string)($ex['title'] ?? ''),
        'count' => array_key_exists('count', $ex) ? $ex['count'] : null,
    ];
}
$actualBlockers = [];
foreach ($body['blockers'] ?? [] as $ex) {
    $actualBlockers[] = [
        'type' => (string)($ex['type'] ?? ''),
        'severity' => (string)($ex['severity'] ?? ''),
        'title' => (string)($ex['title'] ?? ''),
        'count' => array_key_exists('count', $ex) ? $ex['count'] : null,
    ];
}
assert_eq($expectedBlockers, $actualBlockers, 'blockers match Daily Run');

$byDriver = [];
foreach ($body['drivers'] ?? [] as $row) {
    $byDriver[(int)($row['driver_id'] ?? 0)] = $row;
}
assert_true(isset($byDriver[$adaId], $byDriver[$boId]), 'both seeded drivers are in the driver list');
assert_eq(1, (int)($byDriver[$adaId]['dated_stop_count'] ?? -1), 'Ada dated stop count is 1');
assert_eq(2, (int)($byDriver[$adaId]['standing_stop_count'] ?? -1), 'Ada standing stop count is 2');
assert_eq([$names['two']], array_values($byDriver[$adaId]['missing_standing_stops'] ?? []), 'Ada is missing Store Two');
assert_eq(0, (int)($byDriver[$boId]['dated_stop_count'] ?? -1), 'Bo dated stop count is 0');
assert_eq(1, (int)($byDriver[$boId]['standing_stop_count'] ?? -1), 'Bo standing stop count is 1');
assert_eq([$names['three']], array_values($byDriver[$boId]['missing_standing_stops'] ?? []), 'Bo is missing Store Three');

$unassignedStops = $body['unassigned']['stops'] ?? [];
$unassignedNames = array_map(static function ($row) {
    return (string)($row['store_name'] ?? '');
}, $unassignedStops);
assert_true(in_array($names['four'], $unassignedNames, true), 'unassigned dated stop lists Store Four');
$standingGaps = array_map(static function ($row) {
    return is_array($row) ? (string)($row['store_name'] ?? '') : (string)$row;
}, $body['unassigned']['standing_orders_without_route'] ?? []);
assert_true(in_array($names['five'], $standingGaps, true), 'standing order with no route lists Store Five');
assert_true(!in_array($names['two'], $standingGaps, true), 'a standing route is not reported as an order with no route');

$stops = $body['stops'] ?? [];
$delivered = null;
foreach ($stops as $stop) {
    if ((string)($stop['store_name'] ?? '') === $names['one']) {
        $delivered = $stop;
        break;
    }
}
assert_true(is_array($delivered), 'stops include the delivered store');
assert_eq($prefix . ' Ada', $delivered['driver_name'] ?? null, 'stop names the driver');
assert_eq('delivered', $delivered['delivery_status'] ?? null, 'stop delivery status matches the route');
assert_eq('09:15:00', $delivered['delivered_at'] ?? null, 'delivered time uses the recorded delivery time');
assert_eq(true, $delivered['photo_present'] ?? null, 'photo present is yes');
$photoUrl = (string)($delivered['photo_url'] ?? '');
assert_true(
    $photoUrl !== '' && strpos($photoUrl, 'status-proof.jpg') !== false && strpos($photoUrl, (string)BASE_URL) === 0,
    'photo URL is built with the app base path'
);
$fourInStops = false;
foreach ($stops as $stop) {
    if ((string)($stop['store_name'] ?? '') === $names['four']) {
        $fourInStops = true;
    }
}
assert_true(!$fourInStops, 'unassigned orders stay out of the routed stop list');

$survey = $body['survey'] ?? [];
assert_eq('open', $survey['lock_status'] ?? null, 'HQ lock-stores survey status is open');
assert_eq($hqToken, $survey['hq']['verify_token'] ?? null, 'HQ verify token matches the existing survey');
assert_true(
    strpos((string)($survey['hq']['verify_url'] ?? ''), $hqToken) !== false,
    'HQ verify link is the Survey Center link'
);
$surveyDrivers = [];
foreach ($survey['drivers'] ?? [] as $row) {
    $surveyDrivers[(int)($row['driver_id'] ?? 0)] = $row;
}
assert_eq($adaVerify, $surveyDrivers[$adaId]['verify_token'] ?? null, 'Ada verify token matches Survey Center');
assert_eq('open', $surveyDrivers[$adaId]['verify_status'] ?? null, 'Ada lock-stores survey is open');
assert_eq($adaOrder, $surveyDrivers[$adaId]['order_token'] ?? null, 'Ada order token matches Survey Center');
assert_eq('closed', $surveyDrivers[$adaId]['order_status'] ?? null, 'Ada set-order survey is closed');
$adaVerifyData = bakery_survey_store_verify_data($db, $adaId, $date);
assert_eq(
    count($adaVerifyData['assigned']),
    (int)($surveyDrivers[$adaId]['assigned_count'] ?? -1),
    'Ada survey assigned count matches store verify'
);
$boVerifyData = bakery_survey_store_verify_data($db, $boId, $date);
assert_eq(
    count($boVerifyData['assigned']),
    (int)($surveyDrivers[$boId]['assigned_count'] ?? -1),
    'Bo survey assigned count falls back to the standing route'
);
assert_eq('', $surveyDrivers[$boId]['verify_token'] ?? null, 'missing surveys are not minted');

$after = daily_status_table_counts($db);
assert_eq($before, $after, 'snapshot reads do not write');

echo "\n=== HTTP entrypoint ===\n";
$apiSrc = (string)file_get_contents($endpoint);
assert_true(strpos($apiSrc, 'daily_status.php') !== false, 'endpoint uses the shared snapshot helper');
assert_true(strpos($apiSrc, 'BAKERY_SKIP_REQUEST_SECURITY') !== false, 'endpoint performs its own auth instead of the HTML login redirect');

$port = 8097;
$base = 'http://127.0.0.1:' . $port;
$env = [
    'PATH' => (string)getenv('PATH'),
    'HOME' => (string)getenv('HOME'),
    'DB_NAME' => 'bakerysf_test',
    'USE_PROD_DB' => 'false',
    'APP_ENV' => 'local',
    'DAILY_STATUS_TOKEN' => 'correct-token',
];
$server = proc_open(
    'exec "' . PHP_BINARY . '" -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root),
    [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/bakery-daily-status-out.log', 'w'], 2 => ['file', sys_get_temp_dir() . '/bakery-daily-status-err.log', 'w']],
    $pipes,
    $root,
    $env
);
$ready = false;
for ($i = 0; $i < 30 && is_resource($server); $i++) {
    usleep(100000);
    $probe = @file_get_contents($base . '/build_id.php', false, stream_context_create([
        'http' => ['timeout' => 1, 'ignore_errors' => true],
    ]));
    if ($probe !== false) {
        $ready = true;
        break;
    }
}
assert_true($ready, 'loopback server started on bakerysf_test');

$httpStatus = static function (string $path, array $headers = []) use ($base): array {
    $headerLines = $headers;
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'follow_location' => 0,
            'ignore_errors' => true,
            'timeout' => 20,
            'header' => implode("\r\n", $headerLines),
        ],
    ]);
    $body = (string)@file_get_contents($base . $path, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $code = (int)$m[1];
        }
    }
    $decoded = json_decode($body, true);
    return [$code, is_array($decoded) ? $decoded : []];
};

if ($ready) {
    [$code] = $httpStatus('/daily_status_api.php?date=' . rawurlencode($date));
    assert_eq(401, $code, 'HTTP anonymous request is 401');
    $postCtx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'follow_location' => 0,
            'ignore_errors' => true,
            'timeout' => 20,
            'header' => "Authorization: Bearer correct-token\r\nContent-Type: application/x-www-form-urlencoded",
            'content' => 'date=' . $date,
        ],
    ]);
    @file_get_contents($base . '/daily_status_api.php?date=' . rawurlencode($date), false, $postCtx);
    $postCode = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $postCode = (int)$m[1];
        }
    }
    assert_eq(405, $postCode, 'HTTP POST is 405');
    [$okCode, $okBody] = $httpStatus('/daily_status_api.php?date=' . rawurlencode($date), [
        'Authorization: Bearer correct-token',
    ]);
    assert_eq(200, $okCode, 'HTTP bearer GET is 200');
    assert_eq($date, $okBody['date'] ?? null, 'HTTP payload date matches');
    assert_true(isset($okBody['blockers'], $okBody['drivers'], $okBody['stops'], $okBody['survey']), 'HTTP payload has the snapshot sections');
}

if (is_resource($server)) {
    proc_terminate($server);
    usleep(200000);
    proc_close($server);
}

$afterHttp = daily_status_table_counts($db);
assert_eq($before, $afterHttp, 'HTTP reads do not write');

$db->prepare('DELETE FROM surveys WHERE token IN (?, ?, ?)')->execute([$hqToken, $adaVerify, $adaOrder]);
$db->prepare('DELETE FROM driver_photos WHERE customer_id = ? AND delivery_date = ?')->execute([$customerIds['one'], $date]);
$db->prepare('DELETE FROM daily_orders WHERE order_date = ? AND customer_id IN (?, ?)')->execute([
    $date,
    $customerIds['one'],
    $customerIds['four'],
]);
$db->prepare('DELETE FROM standing_orders WHERE customer_id = ?')->execute([$customerIds['five']]);
$db->prepare('DELETE FROM standing_routes WHERE customer_id IN (?, ?, ?)')->execute([
    $customerIds['one'],
    $customerIds['two'],
    $customerIds['three'],
]);
$deleteCustomers = $db->prepare('DELETE FROM customers WHERE id = ?');
foreach ($customerIds as $id) {
    $deleteCustomers->execute([$id]);
}
$db->prepare('DELETE FROM drivers WHERE id IN (?, ?)')->execute([$adaId, $boId]);

echo "\n{$GLOBALS['TEST_PASS']} passed, {$GLOBALS['TEST_FAIL']} failed\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
