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
require_once $root . '/tests/isolate_test_db.php';

bakery_reset_isolated_test_db($root);
$setupCode = 0;
$setupOut = [];
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
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/auth.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Must run with APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();
bakery_assert_local_test_target($db);
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
t_assert(bakery_login($db, '9001') === true, 'admin login succeeds');
$user = bakery_current_user();
t_assert($user && $user['role_slug'] === 'administrator', 'session has administrator role');
t_assert(!empty($_SESSION['csrf_token']), 'csrf token set after login');
t_assert(BAKERY_SESSION_IDLE_SECONDS >= 30 * 24 * 60 * 60, 'staff session allows a 30-day operational idle window');
t_assert(BAKERY_SESSION_ABSOLUTE_SECONDS >= 90 * 24 * 60 * 60, 'staff session has a 90-day maximum lifetime');
t_assert(BAKERY_DRIVER_SESSION_IDLE_SECONDS >= 60 * 24 * 60 * 60, 'driver session allows a 60-day operational idle window');
t_assert(BAKERY_DRIVER_SESSION_ABSOLUTE_SECONDS >= 180 * 24 * 60 * 60, 'driver session has a 180-day maximum lifetime');
t_assert(BAKERY_DRIVER_TRUST_SECONDS >= 400 * 24 * 60 * 60, 'driver trusted phone uses the browser-supported rolling maximum');
t_assert(table_exists($db, 'driver_trusted_devices'), 'driver trusted-device migration is installed');

bakery_logout();
auth_test_reset_session();
t_assert(bakery_current_user() === null, 'logout clears user');

t_assert(bakery_login($db, '0000') === false, 'bad code fails');
t_assert(bakery_current_user() === null, 'no session user after failed login');

echo "=== Role permissions ===\n";
bakery_login($db, '9003');
t_assert(bakery_user_has_role(['driver']), 'driver has driver role');
t_assert(!bakery_user_has_role(['administrator', 'manager']), 'driver is not manager/admin');
t_assert(bakery_user_has_permission($db, 'delivery.execute'), 'driver has delivery.execute');
t_assert(!bakery_user_has_permission($db, 'admin.access'), 'driver lacks admin.access');
$driverUser = bakery_current_user();
$trustedToken = bakery_issue_driver_trusted_device($db, $driverUser);
t_assert((bool)preg_match('/^[A-Za-z0-9_-]{43}$/', $trustedToken), 'driver code login issues an opaque trusted-phone token');
$trustedHash = hash('sha256', $trustedToken);
$trustedStmt = $db->prepare('SELECT user_id, revoked_at FROM driver_trusted_devices WHERE token_hash = ?');
$trustedStmt->execute([$trustedHash]);
$trustedRow = $trustedStmt->fetch(PDO::FETCH_ASSOC);
t_assert((int)($trustedRow['user_id'] ?? 0) === (int)$driverUser['id'], 'trusted-phone database record links to the driver user');
t_assert(empty($trustedRow['revoked_at']), 'new trusted-phone record is active');

auth_test_reset_session();
$_COOKIE[BAKERY_DRIVER_TRUST_COOKIE] = $trustedToken;
t_assert(bakery_restore_driver_trusted_device($db) === true, 'trusted phone rebuilds a missing PHP session');
t_assert((bakery_current_user()['role_slug'] ?? '') === 'driver', 'restored trusted-phone session keeps driver role');
t_assert(!empty($_SESSION['csrf_token']), 'restored trusted-phone session receives a new CSRF token');
$_SESSION['auth_login_at'] = time() - BAKERY_DRIVER_SESSION_ABSOLUTE_SECONDS - 10;
$_SESSION['auth_last_activity'] = time() - BAKERY_DRIVER_SESSION_IDLE_SECONDS - 10;
bakery_touch_session();
t_assert(bakery_current_user() === null, 'expired PHP driver session is cleared without revoking phone trust');
t_assert(bakery_restore_driver_trusted_device($db) === true, 'trusted phone silently replaces an expired PHP session');
$_SESSION['auth_login_at'] = time() - BAKERY_SESSION_ABSOLUTE_SECONDS - 10;
$_SESSION['auth_last_activity'] = time() - BAKERY_SESSION_IDLE_SECONDS - 10;
bakery_touch_session();
t_assert(bakery_current_user() !== null, 'driver route session remains valid through a full workday');

bakery_logout();
$trustedStmt->execute([$trustedHash]);
$trustedRow = $trustedStmt->fetch(PDO::FETCH_ASSOC);
t_assert(!empty($trustedRow['revoked_at']), 'explicit logout revokes the trusted phone');
unset($_COOKIE[BAKERY_DRIVER_TRUST_COOKIE]);

echo "=== Driver Assistant pairing ===\n";
$assistantCode = '2937';
$codeProbe = $db->prepare('SELECT 1 FROM users WHERE login_code = ? LIMIT 1');
for ($attempt = 0; $attempt < 20; $attempt++) {
    $codeProbe->execute([$assistantCode]);
    if (!$codeProbe->fetchColumn()) {
        break;
    }
    $assistantCode = (string)random_int(2000, 8999);
}
$assistantCreated = bakery_upsert_code_user($db, [
    'email' => 'juan.assistant@local.test',
    'display_name' => 'Juan',
    'role' => 'driver_assistant',
    'code' => $assistantCode,
    'driver_id' => 1,
]);
t_assert($assistantCreated, 'Driver Assistant user can be created with a route driver');
$assistantStmt = $db->prepare(
    "SELECT u.id, u.driver_id, r.slug AS role_slug
     FROM users u JOIN roles r ON r.id = u.role_id WHERE u.email = ?"
);
$assistantStmt->execute(['juan.assistant@local.test']);
$assistant = $assistantStmt->fetch(PDO::FETCH_ASSOC);
t_assert(($assistant['role_slug'] ?? '') === 'driver_assistant', 'Juan has the Driver Assistant role');
t_assert(
    bakery_route_worker_driver_id($db, $assistant ?: null, '2099-08-17') === 1,
    'assistant defaults to the linked driver route'
);
$db->prepare(
    'INSERT INTO driver_assistant_assignments (assistant_user_id, driver_id, delivery_date)
     VALUES (?, 2, ?)'
)->execute([(int)$assistant['id'], '2099-08-17']);
t_assert(
    bakery_route_worker_driver_id($db, $assistant ?: null, '2099-08-17') === 2,
    'dated pairing overrides the default driver only for that day'
);
bakery_logout();
auth_test_reset_session();
t_assert(bakery_login($db, $assistantCode) === true, 'Driver Assistant code login succeeds');
t_assert(bakery_user_has_role(['driver_assistant']), 'assistant session has Driver Assistant role');
t_assert(bakery_user_has_permission($db, 'delivery.execute'), 'assistant has delivery.execute');
$_SESSION['auth_login_at'] = time() - BAKERY_SESSION_ABSOLUTE_SECONDS - 10;
$_SESSION['auth_last_activity'] = time() - BAKERY_SESSION_IDLE_SECONDS - 10;
bakery_touch_session();
t_assert(bakery_current_user() !== null, 'assistant route session remains valid through a full workday');

bakery_logout();
auth_test_reset_session();
bakery_login($db, '9002');
t_assert(bakery_user_has_permission($db, 'ops.manage'), 'manager has ops.manage');
t_assert(!bakery_user_has_permission($db, 'admin.access'), 'manager lacks admin.access');
t_assert(bakery_issue_driver_trusted_device($db, bakery_current_user()) === '', 'manager login never receives driver device trust');

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
bakery_login($db, '9001');
$_SESSION['auth_last_activity'] = time() - BAKERY_SESSION_IDLE_SECONDS - 10;
bakery_touch_session();
t_assert(empty($_SESSION['user_id']), 'idle timeout clears auth session');

echo "=== Public / protected script lists ===\n";
t_assert(in_array('guias.php', bakery_public_scripts(), true), 'driver guides page is public');
t_assert(in_array('login.php', bakery_public_scripts(), true), 'login is public');
t_assert(in_array('customer_qr_login.php', bakery_public_scripts(), true), 'customer QR entry is public');
t_assert(!in_array('baker.php', bakery_public_scripts(), true), 'baker.php is not public');
t_assert(in_array('test.php', bakery_diagnostic_scripts(), true), 'test.php is diagnostic');
t_assert(in_array('complete_delivery.php', bakery_driver_scripts(), true), 'complete_delivery is driver-accessible');
t_assert(in_array('driver_session_ping.php', bakery_driver_scripts(), true), 'driver session ping is driver-accessible');
t_assert(in_array('upload_driver_photo.php', bakery_driver_scripts(), true), 'upload_driver_photo is driver-accessible');
t_assert(in_array('get_driver_orders.php', bakery_driver_scripts(), true), 'get_driver_orders is driver-accessible');
t_assert(in_array('qr_login.php', bakery_driver_scripts(), true), 'customer QR generator is driver-accessible');
t_assert(in_array('production.php', bakery_baker_scripts(), true), 'production is baker-accessible');
t_assert(in_array('baker_mix.php', bakery_baker_scripts(), true), 'baker_mix is baker-accessible');
t_assert(in_array('pack_list.php', bakery_baker_scripts(), true), 'pack_list is baker-accessible');
t_assert(!in_array('index.php', bakery_baker_scripts(), true), 'index is not baker-accessible');
t_assert(!in_array('production_center.php', bakery_baker_scripts(), true), 'production center is not baker-accessible');
t_assert(in_array('product_photos.php', bakery_cashier_scripts(), true), 'product_photos is cashier-accessible');
t_assert(in_array('upload_product_photo.php', bakery_cashier_scripts(), true), 'upload_product_photo is cashier-accessible');
t_assert(in_array('cashier_add_product.php', bakery_cashier_scripts(), true), 'cashier_add_product is cashier-accessible');
t_assert(!in_array('products.php', bakery_cashier_scripts(), true), 'products CRUD is not cashier-accessible');
t_assert(!in_array('index.php', bakery_cashier_scripts(), true), 'index is not cashier-accessible');

echo "=== Baker role ===\n";
bakery_logout();
auth_test_reset_session();
 t_assert(bakery_ensure_baker_user($db) === false, 'baker account is not auto-provisioned without local env credentials');
$_SESSION['user_id'] = 1;
$_SESSION['user_email'] = 'baker-regression@example.test';
$_SESSION['user_display_name'] = 'Baker Regression';
$_SESSION['user_role_slug'] = 'baker';
$bakerUser = bakery_current_user();
t_assert($bakerUser && $bakerUser['role_slug'] === 'baker', 'baker role remains representable after secure seeding change');
t_assert(bakery_user_has_permission($db, 'ops.manage'), 'baker has ops.manage');
t_assert(($bakerUser['role_slug'] ?? '') !== 'administrator', 'baker session is not administrator');

echo "=== Cashier role / Sarita ===\n";
bakery_logout();
auth_test_reset_session();
t_assert(bakery_ensure_sarita_cashier($db) === true, 'Sarita cashier can be ensured');
$sarita = $db->query(
    "SELECT u.login_code, u.display_name, u.is_active, r.slug AS role_slug
     FROM users u JOIN roles r ON r.id = u.role_id
     WHERE u.email = 'sarita@sourflour.local' LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
t_assert($sarita && $sarita['role_slug'] === 'cashier', 'Sarita has cashier role');
t_assert($sarita && $sarita['login_code'] === '8989', 'Sarita login code is 8989');
t_assert($sarita && (int)$sarita['is_active'] === 1, 'Sarita is active');
t_assert(bakery_login($db, '8989'), 'Sarita can sign in with code 8989');
$cashierUser = bakery_current_user();
t_assert($cashierUser && $cashierUser['role_slug'] === 'cashier', 'logged-in Sarita session is cashier');
t_assert($cashierUser && $cashierUser['display_name'] === 'Sarita', 'logged-in display name is Sarita');
t_assert(bakery_user_has_permission($db, 'ops.manage'), 'cashier has ops.manage for catalog photo work');
bakery_logout();
auth_test_reset_session();
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
                    'code' => '9003',
                    'next' => '/bakery/driver_list.php',
                ]),
            ]
        );
        $cookieJar = auth_test_collect_cookies($loginPost['headers'], $cookieJar);
        t_assert(strpos(auth_test_status_line($loginPost['headers']), '302') !== false, 'driver login succeeds');
        t_assert(strpos($cookieJar, BAKERY_DRIVER_TRUST_COOKIE . '=') !== false, 'driver login sets a durable trusted-phone cookie');

        $sessionOnlyJar = implode('; ', array_values(array_filter(explode('; ', $cookieJar), function ($pair) {
            return stripos($pair, session_name() . '=') === 0;
        })));
        $existingSessionPage = auth_test_http_request(
            'GET',
            'http://127.0.0.1:8091/driver.php?driver_id=1&date=2026-08-03',
            ['cookie' => $sessionOnlyJar]
        );
        $existingSessionJar = auth_test_collect_cookies($existingSessionPage['headers'], $sessionOnlyJar);
        t_assert(
            strpos($existingSessionJar, BAKERY_DRIVER_TRUST_COOKIE . '=') !== false,
            'already-signed-in driver phone auto-enrolls without another code login'
        );

        $trustedOnlyJar = implode('; ', array_values(array_filter(explode('; ', $cookieJar), function ($pair) {
            return stripos($pair, BAKERY_DRIVER_TRUST_COOKIE . '=') === 0;
        })));
        $trustedRestorePage = auth_test_http_request(
            'GET',
            'http://127.0.0.1:8091/driver.php?driver_id=1&date=2026-08-03',
            ['cookie' => $trustedOnlyJar]
        );
        t_assert(strpos(auth_test_status_line($trustedRestorePage['headers']), '200') !== false, 'trusted phone opens My Route after PHP session loss');
        t_assert(auth_test_extract_csrf($trustedRestorePage['body']) !== '', 'trusted-phone restore creates an authenticated CSRF session');

        $noCsrfPost = auth_test_http_request(
            'POST',
            'http://127.0.0.1:8091/get_driver_orders.php',
            [
                'cookie' => $cookieJar,
                'body' => 'driver_id=1&date=2026-08-03',
            ]
        );
        t_assert(strpos(auth_test_status_line($noCsrfPost['headers']), '403') !== false, 'authenticated POST without CSRF returns 403');

        $driverWorkspacePage = auth_test_http_request(
            'GET',
            'http://127.0.0.1:8091/driver.php?driver_id=1&date=2026-08-03',
            ['cookie' => $cookieJar]
        );
        $csrf = auth_test_extract_csrf($driverWorkspacePage['body']);
        t_assert($csrf !== '', 'driver workspace exposes csrf token when logged in');

        $driverPing = auth_test_http_request(
            'GET',
            'http://127.0.0.1:8091/driver_session_ping.php',
            ['cookie' => $cookieJar]
        );
        $driverPingPayload = json_decode($driverPing['body'], true);
        t_assert(strpos(auth_test_status_line($driverPing['headers']), '200') !== false, 'driver session ping returns 200');
        t_assert(is_array($driverPingPayload) && ($driverPingPayload['success'] ?? null) === true, 'driver session ping confirms authenticated route');
        t_assert(
            is_array($driverPingPayload) && !empty($driverPingPayload['csrf_token']),
            'driver session ping returns a fresh CSRF token'
        );
        t_assert(
            is_array($driverPingPayload) && hash_equals($csrf, (string)($driverPingPayload['csrf_token'] ?? '')),
            'driver session ping CSRF matches the authenticated route session'
        );

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
    // Windows: proc_terminate alone can leave php -S listening and block later suites.
    if ($serverStarted && is_resource($serverProc)) {
        $status = @proc_get_status($serverProc);
        if (is_array($status) && !empty($status['pid'])) {
            if (stripos(PHP_OS, 'WIN') === 0) {
                @exec('taskkill /F /T /PID ' . (int)$status['pid'] . ' 2>NUL');
            } else {
                @posix_kill((int)$status['pid'], 15);
            }
        }
        @proc_terminate($serverProc);
        @proc_close($serverProc);
    }
}

ob_end_flush();
echo "\nPassed: $pass  Failed: $fail\n";
exit($fail > 0 ? 1 : 0);
