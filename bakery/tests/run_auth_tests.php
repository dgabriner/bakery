<?php
/**
 * Auth + CSRF tests (Checkpoint 0D).
 * Usage: C:\php\php.exe bakery/tests/run_auth_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

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

// Fresh session simulation (after includes so config may have started a session)
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
session_id('auth-test-' . bin2hex(random_bytes(4)));
session_start();
$_SESSION = [];
echo "=== Login success / failure ===\n";
t_assert(bakery_login($db, 'admin@local.test', 'LocalAdmin!234') === true, 'admin login succeeds');
$user = bakery_current_user();
t_assert($user && $user['role_slug'] === 'administrator', 'session has administrator role');
t_assert(!empty($_SESSION['csrf_token']), 'csrf token set after login');

bakery_logout();
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION = [];
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
session_start();
$_SESSION = [];
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
session_start();
$_SESSION = [];
bakery_login($db, 'admin@local.test', 'LocalAdmin!234');
$_SESSION['auth_last_activity'] = time() - BAKERY_SESSION_IDLE_SECONDS - 10;
bakery_touch_session();
// touch_session logs out by destroying session
$loggedOut = bakery_current_user() === null || session_status() !== PHP_SESSION_ACTIVE;
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
t_assert(empty($_SESSION['user_id']), 'idle timeout clears auth session');

echo "=== Public / protected script lists ===\n";
t_assert(in_array('login.php', bakery_public_scripts(), true), 'login is public');
t_assert(in_array('test.php', bakery_diagnostic_scripts(), true), 'test.php is diagnostic');
t_assert(in_array('complete_delivery.php', bakery_driver_scripts(), true), 'complete_delivery is driver-accessible');

echo "=== HTTP unauthenticated access ===\n";
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
    } else {
        t_assert(false, 'could not start PHP built-in server for HTTP checks');
    }
} finally {
    if ($serverStarted && is_resource($serverProc)) {
        proc_terminate($serverProc);
        proc_close($serverProc);
    }
}

echo "\nPassed: $pass  Failed: $fail\n";
exit($fail > 0 ? 1 : 0);
