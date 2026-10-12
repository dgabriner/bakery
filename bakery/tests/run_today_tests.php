<?php
/**
 * Today list: ranked action items for today and tomorrow.
 *
 * Usage: php tests/run_today_tests.php
 * Database: bakerysf_test only. Never Staging or Live.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
$db = require $root . '/tests/harness.php';
require_once $root . '/includes/today_list.php';

function today_clear_session(): void
{
    $_SESSION = [];
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
}

function today_session(string $role): void
{
    today_clear_session();
    $_SESSION['user_id'] = 1;
    $_SESSION['user_email'] = 'today-list@example.test';
    $_SESSION['user_display_name'] = 'Today List';
    $_SESSION['user_role_slug'] = $role;
}

/**
 * @param list<array<string, mixed>> $items
 */
function today_match(array $items, string $kind, string $needle): ?array
{
    foreach ($items as $item) {
        if ((string)($item['kind'] ?? '') === $kind && strpos((string)($item['sentence'] ?? ''), $needle) !== false) {
            return $item;
        }
    }
    return null;
}

function today_index(array $items, string $kind, string $needle): int
{
    foreach ($items as $i => $item) {
        if ((string)($item['kind'] ?? '') === $kind && strpos((string)($item['sentence'] ?? ''), $needle) !== false) {
            return (int)$i;
        }
    }
    return -1;
}

echo "=== Page contracts ===\n";
$page = (string)file_get_contents($root . '/today.php');
$helper = (string)file_get_contents($root . '/includes/today_list.php');
$dashboard = (string)file_get_contents($root . '/index.php');
$manifest = (string)file_get_contents($root . '/scripts/deploy_manifest.ps1');
$nav = (string)file_get_contents($root . '/includes/navigation_catalog.php');
$dailyRun = (string)file_get_contents($root . '/includes/daily_run.php');

assert_true(strpos($page, "bakery_require_role(['administrator', 'manager'])") !== false, 'page is administrator and manager only');
assert_true(strpos($page, 'bakery_today_method_allowed') !== false, 'page checks the request method');
assert_true(bakery_today_method_allowed('GET') && bakery_today_method_allowed('HEAD'), 'GET and HEAD are allowed');
assert_true(!bakery_today_method_allowed('POST') && !bakery_today_method_allowed('PUT') && !bakery_today_method_allowed('DELETE'), 'other methods are refused');
assert_true(strpos($page, 'format') !== false && strpos($page, 'json') !== false, 'page serves a JSON variant');
assert_true(strpos($page, 'BAKERY_SKIP_REQUEST_SECURITY') !== false, 'JSON variant performs its own auth');
assert_true(strpos($helper, 'bakery_daily_status_auth_status') !== false, 'JSON auth reuses the daily status token check');
assert_true(strpos($helper, 'bakery_daily_run_build') !== false, 'list reuses Daily Run blockers');
assert_true(strpos($dailyRun, 'bakery_dashboard_command_center') !== false, 'Daily Run blockers already include the command center');
assert_true(strpos($helper, 'bakery_manager_phone_missed') !== false, 'list reuses missed failed stops');
assert_true(strpos($helper, 'bakery_daily_status_stops') !== false, 'list reuses daily status stop photos');
assert_true(strpos($helper, 'bakery_daily_status_unassigned_stops') !== false, 'list reuses daily status unassigned stops');
assert_true(strpos($helper, 'bakery_exception_desk_product_shortages') !== false, 'list reuses pack shortfalls');
assert_true(strpos($helper, 'bakery_billing_enrich_orders') !== false, 'list reuses billing line prices');
assert_true(strpos($helper, 'bakery_account_health_rows') !== false, 'list reuses account health for past-dated Pending orders');
assert_true(strpos($helper, 'bakery_exception_desk_sort') !== false, 'ranking reuses the exception desk sort');
assert_true(strpos($manifest, "'today.php'") !== false, 'deploy manifest root list includes today.php');
assert_true(strpos($dashboard, 'today.php') !== false, 'dashboard links to today');
assert_true(strpos($nav, "'href' => 'today.php'") === false, 'today is not a new top-level nav item');
assert_true(strpos($nav, "'today.php'") !== false, 'script registry allowlists today for the auth gate');
$roles = bakery_navigation_roles_for_script('today.php');
assert_true(in_array('administrator', $roles, true) && in_array('manager', $roles, true), 'administrators and managers may open today');
assert_true(!in_array('driver', $roles, true) && !in_array('baker', $roles, true), 'drivers and bakers are not allowlisted');

$en = require $root . '/lang/en.php';
$es = require $root . '/lang/es.php';
$enKeys = [];
$esKeys = [];
foreach ($en as $key => $_text) {
    if (strpos($key, 'today.') === 0) {
        $enKeys[] = $key;
    }
}
foreach ($es as $key => $_text) {
    if (strpos($key, 'today.') === 0) {
        $esKeys[] = $key;
    }
}
assert_true($enKeys !== [] && $enKeys === $esKeys, 'today keys match in English and Spanish');
foreach (['en' => $root . '/lang/en.php', 'es' => $root . '/lang/es.php'] as $label => $path) {
    $linesOf = file($path) ?: [];
    $indexes = [];
    foreach ($linesOf as $i => $line) {
        if (strpos($line, "'today.") !== false) {
            $indexes[] = $i;
        }
    }
    $contiguous = $indexes !== [] && ($indexes[count($indexes) - 1] - $indexes[0] + 1) === count($indexes);
    $uxLine = null;
    foreach ($linesOf as $i => $line) {
        if (strpos($line, "'ux.stage.demand'") !== false) {
            $uxLine = $i;
            break;
        }
    }
    $beforeUx = $indexes !== [] && $uxLine !== null && $indexes[count($indexes) - 1] < $uxLine;
    assert_true($contiguous, $label . ' today strings are one contiguous block');
    assert_true($beforeUx, $label . ' today strings stay one block before the ux catalog');
    $block = implode('', array_slice($linesOf, $indexes[0], count($indexes)));
    assert_true(strpos($block, "\u{2013}") === false && strpos($block, "\u{2014}") === false, $label . ' today copy has no en dash or em dash');
}

$clear = bakery_today_render_items([], true);
assert_true(strpos($clear, 'Nothing needs you today or tomorrow.') !== false, 'all-clear state names an empty day');
assert_true(strpos(bakery_today_render_items([], false), 'Nothing needs you today or tomorrow.') === false, 'a failed read is not all-clear');

echo "\n=== Auth ===\n";
today_clear_session();
putenv('DAILY_STATUS_TOKEN');
unset($_ENV['DAILY_STATUS_TOKEN'], $_SERVER['DAILY_STATUS_TOKEN']);
$anonymous = bakery_today_dispatch($db, 'GET', '2099-06-03', $_SERVER);
assert_eq(401, $anonymous['status'], 'anonymous JSON is 401');
assert_eq(false, $anonymous['body']['ok'] ?? null, 'anonymous body is not ok');

today_session('driver');
$driver = bakery_today_dispatch($db, 'GET', '2099-06-03', $_SERVER);
assert_eq(403, $driver['status'], 'driver JSON is 403');

today_clear_session();
putenv('DAILY_STATUS_TOKEN=correct-token');
$_ENV['DAILY_STATUS_TOKEN'] = 'correct-token';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wrong-token';
$wrong = bakery_today_dispatch($db, 'GET', '2099-06-03', $_SERVER);
assert_eq(401, $wrong['status'], 'wrong bearer token is 401');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer correct-token';
$badDate = bakery_today_dispatch($db, 'GET', '2099-13-40', $_SERVER);
assert_eq(400, $badDate['status'], 'invalid date is 400');
$post = bakery_today_dispatch($db, 'POST', '2099-06-03', $_SERVER);
assert_eq(405, $post['status'], 'POST is 405');
assert_eq('method_not_allowed', $post['body']['error'] ?? null, 'POST error is method_not_allowed');

echo "\n=== Seeded day on bakerysf_test ===\n";
assert_eq('bakerysf_test', strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn()), 'queries stay on bakerysf_test');
assert_true(bakery_inventory_ready($db), 'finished-goods inventory is installed on bakerysf_test');

$date = '2099-06-03';
$tomorrow = '2099-06-04';
$past = '2099-06-01';
$product = $db->query('SELECT id, name FROM products ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
assert_true(is_array($product) && (int)$product['id'] > 0, 'fixture catalog has a product');
$productId = (int)$product['id'];
$productName = (string)$product['name'];

$prefix = 'Today ' . bin2hex(random_bytes(3));
$names = [
    'unassigned' => $prefix . ' Unassigned',
    'failed' => $prefix . ' Failed',
    'nophoto' => $prefix . ' Nophoto',
    'photo' => $prefix . ' Photo',
    'short' => $prefix . ' Short',
    'openbill' => $prefix . ' Openbill',
    'stale' => $prefix . ' Stale',
    'tomorrow' => $prefix . ' Tomorrow',
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
$driverId = (int)$db->lastInsertId();

$insertOrder = $db->prepare(
    "INSERT INTO daily_orders (customer_id, order_date, status, total_amount, delivery_confirmed_at)
     VALUES (?, ?, ?, 0, ?)"
);
$insertLine = $db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)'
);
$insertStop = $db->prepare(
    "INSERT INTO daily_order_assignments
        (daily_order_id, driver_id, delivery_date, route_order, delivery_status, actual_delivery_time)
     VALUES (?, ?, ?, ?, ?, ?)"
);

$insertOrder->execute([$customerIds['unassigned'], $date, 'pending', null]);
$unassignedOrderId = (int)$db->lastInsertId();
$insertLine->execute([$unassignedOrderId, $productId, 2, 0, 0]);

$insertOrder->execute([$customerIds['failed'], $date, 'pending', null]);
$failedOrderId = (int)$db->lastInsertId();
$insertLine->execute([$failedOrderId, $productId, 1, 2, 2]);
$insertStop->execute([$failedOrderId, $driverId, $date, 1, 'failed', null]);

$insertOrder->execute([$customerIds['nophoto'], $date, 'delivered', $date . ' 09:16:00']);
$nophotoOrderId = (int)$db->lastInsertId();
$insertLine->execute([$nophotoOrderId, $productId, 1, 2, 2]);
$insertStop->execute([$nophotoOrderId, $driverId, $date, 2, 'delivered', '09:15:00']);

$insertOrder->execute([$customerIds['photo'], $date, 'delivered', $date . ' 10:16:00']);
$photoOrderId = (int)$db->lastInsertId();
$insertLine->execute([$photoOrderId, $productId, 1, 2, 2]);
$insertStop->execute([$photoOrderId, $driverId, $date, 3, 'delivered', '10:15:00']);
$db->prepare(
    "INSERT INTO driver_photos
        (driver_id, customer_id, delivery_date, filename, original_filename, file_path, file_size, mime_type, photo_type)
     VALUES (?, ?, ?, 'today-proof.jpg', 'today-proof.jpg', 'today-proof.jpg', 12, 'image/jpeg', 'After')"
)->execute([$driverId, $customerIds['photo'], $date]);

$insertOrder->execute([$customerIds['short'], $date, 'pending', null]);
$shortOrderId = (int)$db->lastInsertId();
$insertLine->execute([$shortOrderId, $productId, 6, 2, 12]);
$insertStop->execute([$shortOrderId, $driverId, $date, 4, 'pending', null]);

$insertOrder->execute([$customerIds['openbill'], $date, 'delivered', null]);
$openOrderId = (int)$db->lastInsertId();
$insertLine->execute([$openOrderId, $productId, 1, 2, 2]);
$insertStop->execute([$openOrderId, $driverId, $date, 5, 'delivered', '11:15:00']);
$db->prepare(
    "INSERT INTO driver_photos
        (driver_id, customer_id, delivery_date, filename, original_filename, file_path, file_size, mime_type, photo_type)
     VALUES (?, ?, ?, 'today-open.jpg', 'today-open.jpg', 'today-open.jpg', 12, 'image/jpeg', 'After')"
)->execute([$driverId, $customerIds['openbill'], $date]);

$insertOrder->execute([$customerIds['stale'], $past, 'pending', null]);
$insertOrder->execute([$customerIds['tomorrow'], $tomorrow, 'pending', null]);
$tomorrowOrderId = (int)$db->lastInsertId();
$insertLine->execute([$tomorrowOrderId, $productId, 1, 2, 2]);

$inventoryBefore = $db->prepare('SELECT available_quantity, loaded_quantity, produced_quantity FROM product_inventory_days WHERE delivery_date = ? AND product_id = ?');
$inventoryBefore->execute([$date, $productId]);
$previousInventory = $inventoryBefore->fetch(PDO::FETCH_ASSOC);
$db->prepare(
    'INSERT INTO product_inventory_days (delivery_date, product_id, available_quantity, produced_quantity, loaded_quantity)
     VALUES (?, ?, 0, 0, 0)
     ON DUPLICATE KEY UPDATE available_quantity = 0, loaded_quantity = 0, produced_quantity = 0'
)->execute([$date, $productId]);

$countTables = [
    'customers', 'drivers', 'daily_orders', 'daily_order_items', 'daily_order_assignments', 'driver_photos', 'product_inventory_days',
];
$counts = static function (PDO $db, array $names): array {
    $out = [];
    foreach ($names as $name) {
        $out[$name] = (int)$db->query('SELECT COUNT(*) FROM `' . $name . '`')->fetchColumn();
    }
    return $out;
};
$before = $counts($db, $countTables);

bakery_set_locale('en', false);
bakery_reset_locale_cache();
today_session('manager');
$english = bakery_today_dispatch($db, 'GET', $date, $_SERVER);
assert_eq(200, $english['status'], 'manager JSON is 200');
assert_eq(true, $english['body']['ok'] ?? null, 'manager body is ok');
assert_eq($date, $english['body']['date'] ?? null, 'payload date is the requested day');
assert_eq($tomorrow, $english['body']['tomorrow'] ?? null, 'payload includes tomorrow');
assert_eq(false, $english['body']['clear'] ?? null, 'a day with work is not all-clear');
assert_eq(true, $english['body']['complete'] ?? null, 'seeded checks all loaded');
$items = $english['body']['items'] ?? [];
assert_true(is_array($items) && $items !== [], 'payload has items');

$unassigned = today_match($items, 'unassigned', $names['unassigned']);
$failed = today_match($items, 'failed', $names['failed']);
$nophoto = today_match($items, 'unphotographed', $names['nophoto']);
$openbill = today_match($items, 'unconfirmed', $names['openbill']);
$unpriced = today_match($items, 'unpriced', $names['unassigned']);
$pack = today_match($items, 'pack', $productName);
$stale = today_match($items, 'stale_pending', $names['stale']);
$tomorrowItem = today_match($items, 'unassigned', $names['tomorrow']);
assert_true($unassigned !== null, 'unassigned stop is on the list');
assert_true($failed !== null, 'failed stop is on the list');
assert_true($nophoto !== null, 'delivered stop without a photo is on the list');
assert_true(today_match($items, 'unphotographed', $names['photo']) === null, 'a photographed delivery is not listed as missing a photo');
assert_true($openbill !== null, 'never-confirmed delivery is on the list');
assert_true($unpriced !== null && strpos((string)$unpriced['sentence'], $productName) !== false, 'unpriced line names the product');
assert_true($pack !== null, 'pack shortfall names the short product');
assert_true($stale !== null, 'past-dated Pending order is on the list');
assert_true($tomorrowItem !== null && ($tomorrowItem['when'] ?? '') === 'tomorrow', 'tomorrow unassigned stop is marked tomorrow');

assert_eq('critical', $unassigned['severity'] ?? null, 'unassigned stop is critical');
assert_eq('critical', $failed['severity'] ?? null, 'failed stop is critical');
assert_eq('warning', $nophoto['severity'] ?? null, 'missing photo is a warning');
assert_eq('info', $openbill['severity'] ?? null, 'never confirmed stays info, matching the command center');
assert_eq('warning', $unpriced['severity'] ?? null, 'unpriced line is a warning');
assert_eq('critical', $pack['severity'] ?? null, 'pack shortfall is critical');
assert_eq('warning', $stale['severity'] ?? null, 'past-dated Pending order is a warning');
assert_eq('past', $stale['when'] ?? null, 'past-dated Pending order is earlier than today');

assert_true(strpos((string)$unassigned['href'], 'driver_assignment.php') !== false && strpos((string)$unassigned['href'], 'filter=unassigned') !== false, 'unassigned stop links to Driver Assignment');
assert_true(strpos((string)$failed['href'], 'manager.php') !== false && strpos((string)$failed['href'], 'view=missed') !== false, 'failed stop links to Missed');
assert_true(strpos((string)$nophoto['href'], 'route_summary.php') !== false && strpos((string)$nophoto['href'], 'filter=missing') !== false, 'missing photo links to Route Summary');
assert_true(strpos((string)$openbill['href'], 'billing_center.php') !== false, 'never confirmed links to Billing Center');
assert_true(strpos((string)$unpriced['href'], 'billing_center.php') !== false, 'unpriced line links to Billing Center');
assert_true(strpos((string)$pack['href'], 'pack_list.php') !== false && strpos((string)$pack['href'], 'attention=shortfall') !== false, 'pack shortfall links to the pack list');
assert_true(strpos((string)$stale['href'], 'account_health.php') !== false && strpos((string)$stale['href'], 'sort=stale') !== false, 'past-dated Pending order links to Account Health');

$duplicateBlocker = false;
foreach ($items as $item) {
    if ((string)($item['kind'] ?? '') === 'daily_run'
        && in_array((string)($item['type'] ?? ''), ['delivery_unassigned', 'delivery_failed', 'production_fg_shortfall', 'invoice_unconfirmed'], true)
        && (string)($item['date'] ?? '') === $date
    ) {
        $duplicateBlocker = true;
    }
}
assert_true(!$duplicateBlocker, 'per-stop items replace the matching Daily Run aggregate for that date');

$failedAt = today_index($items, 'failed', $names['failed']);
$tomorrowAt = today_index($items, 'unassigned', $names['tomorrow']);
$unpricedAt = today_index($items, 'unpriced', $names['unassigned']);
$staleAt = today_index($items, 'stale_pending', $names['stale']);
assert_true($failedAt >= 0 && $tomorrowAt > $failedAt, 'today critical ranks before tomorrow critical');
assert_true($unpricedAt > $tomorrowAt, 'critical ranks before warning');
assert_true($staleAt > $unpricedAt, 'today warning ranks before earlier warning');

$after = $counts($db, $countTables);
assert_eq($before, $after, 'building the list does not write');

bakery_set_locale('es', false);
bakery_reset_locale_cache();
$spanish = bakery_today_dispatch($db, 'GET', $date, $_SERVER);
$spanishItems = $spanish['body']['items'] ?? [];
$spanishUnassigned = today_match(is_array($spanishItems) ? $spanishItems : [], 'unassigned', $names['unassigned']);
assert_true($spanishUnassigned !== null && strpos((string)$spanishUnassigned['sentence'], 'no está en una ruta') !== false, 'Spanish sentence is translated');
$spanishHtml = bakery_today_render_items([], true);
assert_true(strpos($spanishHtml, 'Hoy y mañana no hay nada que hacer.') !== false, 'Spanish all-clear is translated');
bakery_set_locale('en', false);
bakery_reset_locale_cache();

echo "\n=== HTTP ===\n";
$port = 8098;
$base = 'http://127.0.0.1:' . $port;
$env = [
    'PATH' => (string)getenv('PATH'),
    'HOME' => (string)getenv('HOME'),
    'DB_NAME' => 'bakerysf_test',
    'USE_PROD_DB' => 'false',
    'APP_ENV' => 'local',
    'DAILY_STATUS_TOKEN' => 'correct-token',
];
foreach (['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_PORT'] as $dbKey) {
    $value = getenv($dbKey);
    if ($value !== false && $value !== '') {
        $env[$dbKey] = (string)$value;
    }
}
$server = proc_open(
    'exec "' . PHP_BINARY . '" -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root),
    [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/bakery-today-out.log', 'w'], 2 => ['file', sys_get_temp_dir() . '/bakery-today-err.log', 'w']],
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

if ($ready) {
    $http = static function (string $path, string $method = 'GET', array $headers = []) use ($base): array {
        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'follow_location' => 0,
                'ignore_errors' => true,
                'timeout' => 90,
                'header' => implode("\r\n", $headers),
            ],
        ]);
        $body = (string)@file_get_contents($base . $path, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $code = (int)$m[1];
            }
        }
        return [$code, $body];
    };
    [$anonCode] = $http('/today.php?format=json&date=' . rawurlencode($date));
    assert_eq(401, $anonCode, 'HTTP anonymous JSON is 401');
    [$htmlCode] = $http('/today.php?date=' . rawurlencode($date));
    assert_true($htmlCode === 302 || $htmlCode === 303, 'HTTP HTML without a session redirects to login');
    [$postCode] = $http('/today.php?format=json&date=' . rawurlencode($date), 'POST', [
        'Authorization: Bearer correct-token',
    ]);
    assert_eq(405, $postCode, 'HTTP POST JSON is 405');
    [$headCode, $headBody] = $http('/today.php?format=json&date=' . rawurlencode($date), 'HEAD', [
        'Authorization: Bearer correct-token',
    ]);
    assert_eq(200, $headCode, 'HTTP HEAD JSON is 200');
    assert_eq('', $headBody, 'HTTP HEAD JSON has no body');
    [$okCode, $okBody] = $http('/today.php?format=json&date=' . rawurlencode($date), 'GET', [
        'Authorization: Bearer correct-token',
    ]);
    $decoded = json_decode($okBody, true);
    assert_eq(200, $okCode, 'HTTP bearer GET is 200');
    assert_true(is_array($decoded) && ($decoded['ok'] ?? false) === true && ($decoded['clear'] ?? true) === false, 'HTTP payload lists work');
}

if (is_resource($server)) {
    proc_terminate($server);
    usleep(200000);
    proc_close($server);
}

$afterHttp = $counts($db, $countTables);
assert_eq($before, $afterHttp, 'HTTP reads do not write');

if (is_array($previousInventory)) {
    $db->prepare(
        'UPDATE product_inventory_days SET available_quantity = ?, loaded_quantity = ?, produced_quantity = ? WHERE delivery_date = ? AND product_id = ?'
    )->execute([
        (int)$previousInventory['available_quantity'],
        (int)$previousInventory['loaded_quantity'],
        (int)$previousInventory['produced_quantity'],
        $date,
        $productId,
    ]);
} else {
    $db->prepare('DELETE FROM product_inventory_days WHERE delivery_date = ? AND product_id = ?')->execute([$date, $productId]);
}
$db->prepare('DELETE FROM driver_photos WHERE driver_id = ?')->execute([$driverId]);
$deleteOrders = $db->prepare('DELETE FROM daily_orders WHERE customer_id = ?');
foreach ($customerIds as $id) {
    $deleteOrders->execute([$id]);
}
$db->prepare('DELETE FROM drivers WHERE id = ?')->execute([$driverId]);
$deleteCustomers = $db->prepare('DELETE FROM customers WHERE id = ?');
foreach ($customerIds as $id) {
    $deleteCustomers->execute([$id]);
}

echo "\n{$GLOBALS['TEST_PASS']} passed, {$GLOBALS['TEST_FAIL']} failed\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
