<?php
/**
 * client_error_api.php auth / rate-limit / same-origin contracts.
 * CLI / local bakerysf_test only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/client_errors.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: tests must run with APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $msg) use (&$pass, &$fail): void {
    if ($ok) {
        echo "PASS  $msg\n";
        $pass++;
    } else {
        echo "FAIL  $msg\n";
        $fail++;
    }
};

$api = (string)file_get_contents($root . '/client_error_api.php');
$assert(strpos($api, 'BAKERY_SKIP_REQUEST_SECURITY') !== false, 'API is CSRF-exempt for sendBeacon');
$assert(strpos($api, 'bakery_client_error_rate_limit') !== false, 'API rate-limits beacons');
$assert(strpos($api, 'bakery_client_error_same_origin') !== false, 'API checks same-origin');
$assert(strpos($api, 'bakery_current_user()') !== false, 'API requires a logged-in session');

// Rate-limit helper keys off $_SESSION; avoid session_start after CLI output.
if (session_status() !== PHP_SESSION_ACTIVE) {
    $_SESSION = [];
}
$_SESSION['client_error_rl'] = null;
$first = bakery_client_error_rate_limit(3);
$assert($first['allowed'] === true, 'first beacon in window is allowed');
$second = bakery_client_error_rate_limit(3);
$third = bakery_client_error_rate_limit(3);
$blocked = bakery_client_error_rate_limit(3);
$assert($second['allowed'] && $third['allowed'], 'beacons under the cap are allowed');
$assert($blocked['allowed'] === false, '21st/cap+1 beacon is rate-limited');

bakery_client_errors_ensure($db);
$assert(bakery_client_errors_ready($db), 'client_errors table available');
$id = bakery_client_error_record($db, [
    'user_id' => null,
    'kind' => 'unhandledrejection',
    'message' => 'characterization beacon',
    'stack_head' => 'Error: characterization beacon',
    'page_path' => '/driver.php',
    'build_id' => 'test',
]);
$assert($id > 0, 'beacon row inserts');
$recent = bakery_client_errors_recent($db, 5);
$assert($recent !== [] && (string)$recent[0]['message'] === 'characterization beacon', 'recent list returns the beacon');
$db->prepare('DELETE FROM client_errors WHERE id = ?')->execute([$id]);

$manifest = (string)file_get_contents($root . '/scripts/deploy_manifest.ps1');
$assert(strpos($manifest, 'client_error_api.php') !== false, 'client_error_api.php is in the deploy root whitelist');

$auth = (string)file_get_contents($root . '/includes/auth.php');
$assert(strpos($auth, "'client_error_api.php'") !== false, 'client_error_api is in the public/skip gate list');

echo "\n=== client_error_api characterization: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
