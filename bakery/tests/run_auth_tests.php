<?php
/**
 * Auth + CSRF tests (Checkpoint 0D).
 * Usage: C:\php\php.exe bakery/tests/run_auth_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

ob_start();

$root = dirname(__DIR__);

exec('"' . PHP_BINARY . '" ' . escapeshellarg($root . '/scripts/setup_local_db.php') . ' --reset', $setupOut, $setupCode);
if ($setupCode !== 0) {
    fwrite(STDERR, "setup_local_db failed\n" . implode("\n", $setupOut) . "\n");
    exit(1);
}
exec('"' . PHP_BINARY . '" ' . escapeshellarg($root . '/scripts/seed_local_users.php'), $seedOut, $seedCode);
if ($seedCode !== 0) {
    fwrite(STDERR, "seed_local_users failed\n" . implode("\n", $seedOut) . "\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/auth.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Must run with APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();
$pass = 0;
$fail = 0;

function t_assert($ok, $msg) {
    global $pass, $fail;
    if ($ok) {
        echo "PASS  $msg\n";
        $pass++;
    } else {
        echo "FAIL  $msg\n";
        $fail++;
    }
}

function auth_test_reset_session() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    session_id('auth-test-' . bin2hex(random_bytes(4)));
    session_start();
    $_SESSION = [];
}

auth_test_reset_session();
echo "=== Login success / failure ===\n";
t_assert(bakery_login($db, 'admin@local.test', 'LocalAdmin!234') === true, 'admin login succeeds');
$user = bakery_current_user();
t_assert($user && $user['role_slug'] === 'administrator', 'session has administrator role');
t_assert(!empty($_SESSION['csrf_token']), 'csrf token set after login');

bakery_logout();
auth_test_reset_session();
t_assert(bakery_current_user() === null, 'logout clears user');

t_assert(bakery_login($db, 'admin@local.test', 'wrong-password') === false, 'bad password fails');
t_assert(bakery_current_user() === null, 'no session user after failed login');

echo "=== Role permissions ===\n";
bakery_login($db, 'driver@local.test', 'LocalDriver!234');
t_assert(bakery_user_has_role(['driver']), 'driver has driver role');
t_assert(!bakery_user_has_role(['administrator', 'manager']), 'driver is not manager/admin');
t_assert(bakery_user_has_permission($db, 'delivery.execute'), 'driver has delivery.execute');
t_assert(!bakery_user_has_permission($db, 'admin.access'), 'driver lacks admin.access');

bakery_logout();
auth_test_reset_session();
bakery_login($db, 'manager@local.test', 'LocalManager!234');
t_assert(bakery_user_has_permission($db, 'ops.manage'), 'manager has ops.manage');
t_assert(!bakery_user_has_permission($db, 'admin.access'), 'manager lacks admin.access');

echo "=== CSRF ===\n";
$token = bakery_csrf_token();
$_POST['csrf_token'] = $token;
t_assert(bakery_verify_csrf() === true, 'valid csrf passes');
$_POST['csrf_token'] = 'invalid';
t_assert(bakery_verify_csrf() === false, 'invalid csrf fails');
unset($_POST['csrf_token']);
$_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
t_assert(bakery_verify_csrf() === true, 'csrf via header passes');
unset($_SERVER['HTTP_X_CSRF_TOKEN']);
t_assert(bakery_verify_csrf() === false, 'missing csrf fails');

echo "=== Session expiry (idle) ===\n";
bakery_logout();
auth_test_reset_session();
bakery_login($db, 'admin@local.test', 'LocalAdmin!234');
$_SESSION['auth_last_activity'] = time() - BAKERY_SESSION_IDLE_SECONDS - 10;
bakery_touch_session();
t_assert(empty($_SESSION['user_id']), 'idle timeout clears auth session');

echo "=== Public / protected script lists ===\n";
t_assert(in_array('login.php', bakery_public_scripts(), true), 'login is public');
t_assert(in_array('test.php', bakery_diagnostic_scripts(), true), 'test.php is diagnostic');
t_assert(in_array('complete_delivery.php', bakery_driver_scripts(), true), 'complete_delivery is driver-accessible');
t_assert(in_array('get_driver_orders.php', bakery_driver_scripts(), true), 'get_driver_orders is driver-accessible');

function auth_test_http_request($method, $url, $options = []) {
    $headers = $options['headers'] ?? [];
    if (!empty($options['cookie'])) {
        $headers[] = 'Cookie: ' . $options['cookie'];
    }
    if ($method === 'POST' && !empty($options['body'])) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $options['body'] ?? '',
            'follow_location' => 0,
            'timeout' => 5,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return [
        'body' => $body === false ? '' : $body,
        'headers' => $http_response_header ?? [],
    ];
}

function auth_test_status_line($headers) {
    return is_array($headers) ? ($headers[0] ?? '') : '';
}

function auth_test_collect_cookies($headers, $existing = '') {
    $jar = [];
    if ($existing !== '') {
        foreach (explode(';', $existing) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $jar[] = $part;
            }
        }
    }
    foreach ($headers as $header) {
        if (stripos($header, 'Set-Cookie:') === 0) {
            $value = trim(substr($header, strlen('Set-Cookie:')));
            $pair = explode(';', $value)[0];
            $name = explode('=', $pair)[0];
            $jar = array_values(array_filter($jar, function ($item) use ($name) {
                return stripos($item, $name . '=') !== 0;
            }));
            $jar[] = $pair;
        }
    }
    return implode('; ', $jar);
}

function auth_test_extract_csrf($html) {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $matches)) {
        return $matches[1];
    }
    if (preg_match('/name="csrf-token"\s+content="([^"]+)"/i', $html, $matches)) {
        return $matches[1];
    }
    return '';
}

echo "=== HTTP unauthenticated access ===\n";
$fixtureCustomerId = (int)$db->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();
$fixtureOrderId = (int)$db->query("SELECT id FROM daily_orders WHERE order_date='2026-08-03' ORDER BY id LIMIT 1")->fetchColumn();
if ($fixtureOrderId <= 0 && $fixtureCustomerId > 0) {
    $db->prepare("
        INSERT INTO daily_orders (customer_id, order_date, status, total_amount)
        VALUES (?, '2026-08-03', 'pending', 42.50)
    ")->execute([$fixtureCustomerId]);
    $fixtureOrderId = (int)$db->lastInsertId();
}
$db->prepare("DELETE FROM daily_order_assignments WHERE delivery_date='2026-08-03'")->execute();
if ($fixtureOrderId > 0) {
    $db->prepare("
        INSERT INTO daily_order_assignments (daily_order_id, driver_id, delivery_date, route_order, scheduled_delivery_time, delivery_status)
        VALUES (?, 1, '2026-08-03', 1, '08:00:00', 'pending')
    ")->execute([$fixtureOrderId]);
}
t_assert($fixtureOrderId > 0, 'HTTP fixture daily order exists for driver contract checks');

$serverProc = null;
$serverStarted = false;
try {
    $serverProc = proc_open(
        '"' . PHP_BINARY . '" -S 127.0.0.1:8091 -t ' . escapeshellarg($root),
        [
            0 => ['pipe', 'r'],
            1 => ['file', sys_get_temp_dir() . '/bakery-auth-server-out.log', 'w'],
            2 => ['file', sys_get_temp_dir() . '/bakery-auth-server-err.log', 'w'],
        ],
        $pipes,
        $root
    );
    if (is_resource($serverProc)) {
        $serverStarted = true;
        usleep(400000);
        $ctx = stream_context_create(['http' => ['follow_location' => 0, 'timeout' => 3]]);
        $headers = @get_headers('http://127.0.0.1:8091/index.php', true, $ctx);
        $statusLine = is_array($headers) ? ($headers[0] ?? '') : '';
        t_assert(
            strpos($statusLine, '302') !== false || strpos($statusLine, '301') !== false,
            'unauthenticated index.php redirects'
        );
        $loc = '';
        if (is_array($headers)) {
            $loc = is_array($headers['Location'] ?? null)
                ? ($headers['Location'][0] ?? '')
                : ($headers['Location'] ?? '');
        }
        t_assert(strpos($loc, 'login.php') !== false, 'redirect target is login.php');

        $loginHeaders = @get_headers('http://127.0.0.1:8091/login.php', true, $ctx);
        $loginStatus = is_array($loginHeaders) ? ($loginHeaders[0] ?? '') : '';
        t_assert(strpos($loginStatus, '200') !== false, 'login.php is reachable without auth');

        echo "=== get_driver_orders.php HTTP auth + CSRF ===\n";
        $unauthPost = auth_test_http_request(
            'POST',
            'http://127.0.0.1:8091/get_driver_orders.php',
            ['body' => 'driver_id=1&date=2026-08-03']
        );
        t_assert(strpos(auth_test_status_line($unauthPost['headers']), '401') !== false, 'unauthenticated POST get_driver_orders returns 401');
        $unauthJson = json_decode($unauthPost['body'], true);
        t_assert(is_array($unauthJson) && ($unauthJson['success'] ?? null) === false, 'unauthenticated POST returns JSON success=false');

        $loginPage = auth_test_http_request('GET', 'http://127.0.0.1:8091/login.php');
        $cookieJar = auth_test_collect_cookies($loginPage['headers']);
        $loginCsrf = auth_test_extract_csrf($loginPage['body']);
        t_assert($loginCsrf !== '', 'login page exposes csrf token');

        $loginPost = auth_test_http_request(
            'POST',
            'http://127.0.0.1:8091/login.php',
            [
                'cookie' => $cookieJar,
                'body' => http_build_query([
                    'csrf_token' => $loginCsrf,
                    'email' => 'driver@local.test',
                    'password' => 'LocalDriver!234',
                    'next' => '/bakery/driver_list.php',
                ]),
            ]
        );
        $cookieJar = auth_test_collect_cookies($loginPost['headers'], $cookieJar);
        t_assert(strpos(auth_test_status_line($loginPost['headers']), '302') !== false, 'driver login succeeds');

        $noCsrfPost = auth_test_http_request(
            'POST',
            'http://127.0.0.1:8091/get_driver_orders.php',
            [
                'cookie' => $cookieJar,
                'body' => 'driver_id=1&date=2026-08-03',
            ]
        );
        t_assert(strpos(auth_test_status_line($noCsrfPost['headers']), '403') !== false, 'authenticated POST without CSRF returns 403');

        $driverListPage = auth_test_http_request(
            'GET',
            'http://127.0.0.1:8091/driver_list.php?driver_id=1&date=2026-08-03',
            ['cookie' => $cookieJar]
        );
        $csrf = auth_test_extract_csrf($driverListPage['body']);
        t_assert($csrf !== '', 'driver_list page exposes csrf token when logged in');

        $authedPost = auth_test_http_request(
            'POST',
            'http://127.0.0.1:8091/get_driver_orders.php',
            [
                'cookie' => $cookieJar,
                'body' => http_build_query([
                    'driver_id' => 1,
                    'date' => '2099-01-01',
                    'csrf_token' => $csrf,
                ]),
            ]
        );
        t_assert(strpos(auth_test_status_line($authedPost['headers']), '200') !== false, 'authenticated POST with CSRF returns 200');
        $payload = json_decode($authedPost['body'], true);
        t_assert(is_array($payload) && ($payload['success'] ?? null) === true, 'authenticated POST returns success=true');
        t_assert(isset($payload['orders']) && is_array($payload['orders']), 'response includes orders array');
        t_assert(count($payload['orders']) === 0, 'empty assignments return orders=[] with success=true');

        $contractFields = [
            'daily_order_id',
            'customer_name',
            'customer_address',
            'zone',
            'route_order',
            'scheduled_delivery_time',
            'total_amount',
        ];
        $assignedPost = auth_test_http_request(
            'POST',
            'http://127.0.0.1:8091/get_driver_orders.php',
            [
                'cookie' => $cookieJar,
                'body' => http_build_query([
                    'driver_id' => 1,
                    'date' => '2026-08-03',
                    'csrf_token' => $csrf,
                ]),
            ]
        );
        $assignedPayload = json_decode($assignedPost['body'], true);
        t_assert(is_array($assignedPayload) && ($assignedPayload['success'] ?? null) === true, 'assigned date returns success=true');
        t_assert(!empty($assignedPayload['orders']), 'assigned date returns at least one order');
        if (!empty($assignedPayload['orders'])) {
            $first = $assignedPayload['orders'][0];
            foreach ($contractFields as $field) {
                t_assert(array_key_exists($field, $first), "order object includes contract field $field");
            }
            $extraFields = array_diff(array_keys($first), $contractFields);
            t_assert(count($extraFields) === 0, 'order object does not invent extra JSON fields');
        }
    } else {
        t_assert(false, 'could not start PHP built-in server for HTTP checks');
    }
} finally {
    if ($serverStarted && is_resource($serverProc)) {
        proc_terminate($serverProc);
        proc_close($serverProc);
    }
}

ob_end_flush();
echo "\nPassed: $pass  Failed: $fail\n";
exit($fail > 0 ? 1 : 0);
