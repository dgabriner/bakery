<?php
/**
 * Closeout Radar — admin-only access, page load, and close-gate / MRP detection.
 *
 * Usage: php tests/run_closeout_radar_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);

passthru('"' . PHP_BINARY . '" ' . escapeshellarg($root . '/scripts/setup_local_db.php') . ' --reset --force-reset', $setupCode);
if ($setupCode !== 0) {
    fwrite(STDERR, "Fixture reset failed\n");
    exit(1);
}
exec('"' . PHP_BINARY . '" ' . escapeshellarg($root . '/scripts/seed_local_users.php'), $seedOut, $seedCode);
if ($seedCode !== 0) {
    fwrite(STDERR, "seed_local_users failed\n" . implode("\n", $seedOut) . "\n");
    exit(1);
}

/** @var PDO $db */
$db = require __DIR__ . '/harness.php';
require_once $root . '/includes/closeout_radar.php';

echo "\n=== Closeout Radar helpers (delivery date 2026-08-03 Monday) ===\n";
$date = '2026-08-03';
assert_eq('1', date('N', strtotime($date)), '2026-08-03 is Monday');

$ordersBefore = (int)$db->query("SELECT COUNT(*) FROM daily_orders WHERE order_date = '$date'")->fetchColumn();
$radar = bakery_closeout_radar_build($db, $date);
$ordersAfter = (int)$db->query("SELECT COUNT(*) FROM daily_orders WHERE order_date = '$date'")->fetchColumn();
assert_eq($ordersBefore, $ordersAfter, 'radar build does not create daily orders');

assert_eq($date, $radar['delivery_date'], 'radar is keyed to the chosen delivery date');
assert_eq('not_yet', $radar['verdict'], 'cannot close when demand is not generated');
assert_true($radar['can_close'] === false, 'can_close is false while Confirm Demand is open');
assert_true(strpos($radar['blocking_reason'], 'Demand') !== false, 'blocking reason names Confirm Demand');

$demand = bakery_closeout_radar_gate($radar, 'demand');
assert_true(is_array($demand), 'demand gate is present');
assert_eq('blocked', $demand['status'], 'Confirm Demand is blocked when standing exists and daily orders do not');
assert_true($demand['count'] > 0, 'demand gate reports standing demand count');
assert_true(strpos((string)$demand['href'], 'daily_orders.php') !== false, 'demand gate links to daily_orders.php');
assert_true(strpos((string)$demand['href'], $date) !== false, 'demand gate preserves the delivery date in the link');

$unassigned = bakery_closeout_radar_gate($radar, 'unassigned');
assert_true(is_array($unassigned), 'unassigned gate is present');
$plan = bakery_closeout_radar_gate($radar, 'production_plan');
assert_true(is_array($plan), 'production plan gate is present');
assert_true(strpos((string)$plan['href'], 'production_center.php') !== false, 'plan gate links to production_center.php');
$load = bakery_closeout_radar_gate($radar, 'pickup_load');
assert_true(is_array($load), 'pickup load gate is present');
assert_true(strpos((string)$load['href'], 'driver_load.php') !== false, 'pickup gate links to driver_load.php');
$closeout = bakery_closeout_radar_gate($radar, 'route_closeout');
assert_true(is_array($closeout), 'route closeout gate is present');
$pod = bakery_closeout_radar_gate($radar, 'pod_incomplete');
assert_true(is_array($pod), 'POD / incomplete gate is present');
assert_true(strpos((string)$pod['href'], 'route_manager.php') !== false, 'POD gate links to route_manager.php');

echo "\n=== Missing weight_grams (Pan Dulce) ===\n";
$db->beginTransaction();
try {
    $db->exec("INSERT INTO products (name, dough_type_id, price, weight_grams) VALUES ('Radar Pan Dulce No Weight', 2, 1.50, NULL)");
    $productId = (int)$db->lastInsertId();
    standing_save($db, 1, $productId, 1, 6);

    $radarWeights = bakery_closeout_radar_build($db, $date);
    $names = array_column($radarWeights['mrp_holes']['missing_weights'] ?? [], 'name');
    assert_true(in_array('Radar Pan Dulce No Weight', $names, true), 'Pan Dulce product with null weight_grams is flagged');
    $weightHref = '';
    foreach ($radarWeights['mrp_holes']['missing_weights'] as $row) {
        if ($row['name'] === 'Radar Pan Dulce No Weight') {
            $weightHref = (string)$row['href'];
            break;
        }
    }
    assert_true(strpos($weightHref, 'products.php') !== false, 'missing-weight row links to products.php');
    $db->rollBack();
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $e;
}

echo "\n=== Empty dough formula ===\n";
$db->beginTransaction();
try {
    $db->exec("INSERT INTO dough_types (name, description, product_line_id) VALUES ('Radar Empty Formula Dough', 'No ingredients', 1)");
    $doughTypeId = (int)$db->lastInsertId();
    $db->exec("INSERT INTO products (name, dough_type_id, price, weight_grams) VALUES ('Radar Empty Formula Loaf', $doughTypeId, 4.00, 500)");
    $productId = (int)$db->lastInsertId();
    standing_save($db, 1, $productId, 1, 2);

    $radarFormulas = bakery_closeout_radar_build($db, $date);
    $formulaNames = array_column($radarFormulas['mrp_holes']['empty_formulas'] ?? [], 'name');
    assert_true(in_array('Radar Empty Formula Dough', $formulaNames, true), 'dough type with no formula ingredients is flagged');
    $formulaHref = '';
    foreach ($radarFormulas['mrp_holes']['empty_formulas'] as $row) {
        if ($row['name'] === 'Radar Empty Formula Dough') {
            $formulaHref = (string)$row['href'];
            break;
        }
    }
    assert_true(strpos($formulaHref, 'formulas.php') !== false, 'empty-formula row links to formulas.php');
    $db->rollBack();
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $e;
}

echo "\n=== Demand gate clears after generating daily orders ===\n";
$gen = generate_from_standing($db, $date);
assert_true($gen['items_created'] > 0, 'generated daily order items for Monday');
$radarAfter = bakery_closeout_radar_build($db, $date);
$demandAfter = bakery_closeout_radar_gate($radarAfter, 'demand');
assert_eq('clear', $demandAfter['status'], 'Confirm Demand clears once daily orders exist');
$unassignedAfter = bakery_closeout_radar_gate($radarAfter, 'unassigned');
assert_eq('blocked', $unassignedAfter['status'], 'unassigned orders block close after demand is generated');
assert_true($unassignedAfter['count'] > 0, 'unassigned count is the leftover daily orders');
assert_true(strpos((string)$unassignedAfter['href'], 'driver_assignment.php') !== false, 'unassigned gate links to driver_assignment.php');
assert_eq('not_yet', $radarAfter['verdict'], 'still cannot close while orders are unassigned');

function radar_http_request($method, $url, $options = []) {
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

function radar_http_status($headers) {
    return is_array($headers) ? ($headers[0] ?? '') : '';
}

function radar_collect_cookies($headers, $existing = '') {
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

function radar_extract_csrf($html) {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $matches)) {
        return $matches[1];
    }
    if (preg_match('/name="csrf-token"\s+content="([^"]+)"/i', $html, $matches)) {
        return $matches[1];
    }
    return '';
}

function radar_login($base, $code) {
    $loginPage = radar_http_request('GET', $base . '/login.php');
    $cookie = radar_collect_cookies($loginPage['headers']);
    $csrf = radar_extract_csrf($loginPage['body']);
    $post = radar_http_request(
        'POST',
        $base . '/login.php',
        [
            'cookie' => $cookie,
            'body' => http_build_query([
                'csrf_token' => $csrf,
                'code' => $code,
                'next' => '/closeout_radar.php',
            ]),
        ]
    );
    $cookie = radar_collect_cookies($post['headers'], $cookie);
    return [$cookie, $post];
}

echo "\n=== HTTP auth + page load ===\n";
$serverProc = null;
$serverStarted = false;
$base = 'http://127.0.0.1:8094';
try {
    $serverProc = proc_open(
        '"' . PHP_BINARY . '" -S 127.0.0.1:8094 -t ' . escapeshellarg($root),
        [
            0 => ['pipe', 'r'],
            1 => ['file', sys_get_temp_dir() . '/bakery-radar-server-out.log', 'w'],
            2 => ['file', sys_get_temp_dir() . '/bakery-radar-server-err.log', 'w'],
        ],
        $pipes,
        $root
    );
    if (!is_resource($serverProc)) {
        assert_true(false, 'could not start PHP built-in server for Closeout Radar HTTP checks');
    } else {
        $serverStarted = true;
        usleep(400000);

        $unauth = radar_http_request('GET', $base . '/closeout_radar.php');
        $unauthStatus = radar_http_status($unauth['headers']);
        assert_true(
            strpos($unauthStatus, '302') !== false || strpos($unauthStatus, '301') !== false,
            'unauthenticated closeout_radar.php redirects'
        );
        $loc = '';
        foreach ($unauth['headers'] as $header) {
            if (stripos($header, 'Location:') === 0) {
                $loc = trim(substr($header, strlen('Location:')));
                break;
            }
        }
        assert_true(strpos($loc, 'login.php') !== false, 'unauthenticated redirect target is login.php');

        list($managerCookie) = radar_login($base, '9002');
        $managerPage = radar_http_request('GET', $base . '/closeout_radar.php', ['cookie' => $managerCookie]);
        $managerStatus = radar_http_status($managerPage['headers']);
        assert_true(strpos($managerStatus, '403') !== false, 'manager is forbidden from Closeout Radar');

        list($adminCookie, $adminLogin) = radar_login($base, '9001');
        assert_true(strpos(radar_http_status($adminLogin['headers']), '302') !== false, 'administrator login succeeds');
        $adminPage = radar_http_request(
            'GET',
            $base . '/closeout_radar.php?date=' . $date,
            ['cookie' => $adminCookie]
        );
        $adminStatus = radar_http_status($adminPage['headers']);
        assert_true(strpos($adminStatus, '200') !== false, 'administrator can load Closeout Radar');
        assert_true(strpos($adminPage['body'], 'Closeout Radar') !== false, 'page heading is Closeout Radar');
        assert_true(strpos($adminPage['body'], 'Delivery date') !== false, 'page labels the delivery date');
        assert_true(strpos($adminPage['body'], 'Can we close') !== false, 'page answers can we close');
        assert_true(strpos($adminPage['body'], '9001') === false, 'page does not print a login code');
        assert_true(stripos($adminPage['body'], '@sourflour') === false, 'page does not print staff email');
    }
} finally {
    if ($serverStarted && is_resource($serverProc)) {
        $status = @proc_get_status($serverProc);
        if (is_array($status) && !empty($status['pid'])) {
            @posix_kill((int)$status['pid'], 15);
            usleep(200000);
            @posix_kill((int)$status['pid'], 9);
        }
        @proc_terminate($serverProc);
        @proc_close($serverProc);
    }
}

echo "\n=== Summary ===\n";
echo "Passed: {$GLOBALS['TEST_PASS']}\n";
echo "Failed: {$GLOBALS['TEST_FAIL']}\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
