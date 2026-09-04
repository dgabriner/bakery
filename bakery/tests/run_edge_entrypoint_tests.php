<?php
/**
 * Mission 33 — every root script passes through bakery_enforce_request_security.
 *
 * Source-level contracts plus an HTTP pass on loopback: unauthenticated hits to
 * the formerly ungated scripts must redirect to login (or 401 JSON), and ping.php
 * must not disclose paths or the PHP version. bakerysf_test only.
 *
 * Usage: php tests/run_edge_entrypoint_tests.php
 */
require __DIR__ . '/isolate_test_db.php';
define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: run with APP_ENV=local\n");
    exit(1);
}
$db = check_mysql_connection();
bakery_assert_local_test_target($db);

$pass = 0;
$fail = 0;
$assert = function ($ok, $label) use (&$pass, &$fail) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

// ---- every deployable root script bootstraps through database.php ------------
$public = bakery_public_scripts();
$allowedWithoutGate = array_merge($public, [
    'ping.php',            // liveness line only; ?probe=1 bootstraps and requires administrator
    'build_id.php',        // public build stamp for client_refresh.js
    'deploy_status.php',   // public hosted-promotion status JSON
    'migration_status.php',
    'schema_status.php',
    'health_local.php',
]);
$ungated = [];
foreach (glob($root . '/*.php') ?: [] as $path) {
    $name = basename($path);
    if (in_array($name, $allowedWithoutGate, true)) {
        continue;
    }
    $src = (string)file_get_contents($path);
    // Redirect-only stubs (legacy URLs) touch no data and may skip the gate.
    $isRedirectStub = strpos($src, "header('Location:") !== false && strpos($src, '$db') === false && strlen($src) < 800;
    if (!$isRedirectStub && strpos($src, 'includes/database.php') === false) {
        $ungated[] = $name;
    }
}
$assert($ungated === [], 'every non-public root script loads includes/database.php (ungated: ' . implode(', ', $ungated) . ')');

foreach (['oauth_setup.php', 'oauth_callback.php', 'setup_directories.php'] as $name) {
    $src = (string)file_get_contents($root . '/' . $name);
    $assert(strpos($src, "define('ACCESS_ALLOWED', true)") !== false && strpos($src, 'includes/database.php') !== false, "$name bootstraps through the auth gate");
    $assert(in_array($name, bakery_diagnostic_scripts(), true), "$name is administrator-only (diagnostic list)");
}

$ping = (string)file_get_contents($root . '/ping.php');
$assert(strpos($ping, "bakery_require_role(['administrator'])") !== false, 'ping.php probe branch requires administrator');
$assert(!file_exists($root . '/assets/api/get_route.php'), 'assets/api/get_route.php (never defined ACCESS_ALLOWED, no callers) is gone');

// ---- *_api.php answers JSON ----------------------------------------------------
$_SERVER['SCRIPT_NAME'] = '/bakery/anything_new_api.php';
$_SERVER['HTTP_ACCEPT'] = 'text/html';
$assert(bakery_wants_json() === true, 'any *_api.php script is treated as JSON');
$_SERVER['SCRIPT_NAME'] = '/bakery/customers.php';
$assert(bakery_wants_json() === false, 'ordinary pages are not JSON');

// ---- HTTP pass --------------------------------------------------------------------
$port = 8096;
$base = 'http://127.0.0.1:' . $port;
$stale = @file_get_contents($base . '/build_id.php', false, stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]));
$server = null;
if ($stale !== false) {
    echo "NOTE  port {$port} already answers; skipping HTTP pass\n";
} else {
    $env = ['PATH' => (string)getenv('PATH'), 'HOME' => (string)getenv('HOME'), 'DB_NAME' => 'bakerysf_test', 'USE_PROD_DB' => 'false', 'APP_ENV' => 'local'];
    $server = proc_open(
        'exec "' . PHP_BINARY . '" -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root),
        [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/bakery-edge-out.log', 'w'], 2 => ['file', sys_get_temp_dir() . '/bakery-edge-err.log', 'w']],
        $pipes,
        $root,
        $env
    );
    $ready = false;
    for ($i = 0; $i < 30 && is_resource($server); $i++) {
        usleep(100000);
        if (@file_get_contents($base . '/build_id.php', false, stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]])) !== false) {
            $ready = true;
            break;
        }
    }
    if (!$ready) {
        echo "FAIL  php -S did not become ready\n";
        $fail++;
    } else {
        $ctx = stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true, 'timeout' => 5]]);
        $status = function (string $path) use ($base, $ctx): array {
            $body = (string)@file_get_contents($base . $path, false, $ctx);
            $code = 0;
            $location = '';
            foreach ($http_response_header ?? [] as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                    $code = (int)$m[1];
                }
                if (stripos($h, 'Location:') === 0) {
                    $location = trim(substr($h, 9));
                }
            }
            return [$code, $location, $body];
        };
        foreach (['/oauth_setup.php', '/oauth_callback.php?code=forged', '/setup_directories.php', '/ping.php?probe=1'] as $path) {
            [$code, $location, $body] = $status($path);
            $assert(in_array($code, [301, 302], true) && strpos($location, 'login.php') !== false, "unauthenticated $path redirects to login (got $code)");
            $assert(strpos($body, 'SUCCESS') === false && strpos($body, 'Created') === false, "$path performed no work for a stranger");
        }
        [$code, , $body] = $status('/ping.php');
        $assert($code === 200 && strpos($body, 'ping ok') !== false, 'ping.php still answers liveness');
        $assert(strpos($body, $root) === false && strpos($body, 'PHP ' . PHP_VERSION) === false && strpos($body, 'bytes') === false, 'ping.php discloses no path, version, or file inventory');
        [$code, , $body] = $status('/anything_new_api.php');
        $assert($code === 404, 'unknown *_api.php is a 404, not a page');
    }
    if (is_resource($server)) {
        proc_terminate($server);
        usleep(200000);
        proc_close($server);
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
