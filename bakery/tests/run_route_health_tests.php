<?php
/**
 * Route health: read-only day table, next sell day, repeat skips, proof.
 *
 * Usage: php tests/run_route_health_tests.php
 * Database: bakerysf_test only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
$proofFile = $root . '/includes/route_stop_proof.php';
$healthFile = $root . '/includes/route_health.php';
$pageFile = $root . '/route_health.php';
if (!is_file($proofFile) || !is_file($healthFile) || !is_file($pageFile)) {
    echo "FAIL  route health page, health include, and shared proof include exist\n";
    echo "1 FAILED\n";
    exit(1);
}

require_once $root . '/tests/isolate_test_db.php';
$db = require $root . '/tests/harness.php';
require_once $proofFile;
require_once $healthFile;
require_once $root . '/includes/driver_reports.php';
require_once $root . '/includes/navigation_catalog.php';

$fail = 0;
$assert = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
};

echo "=== After photo is the only with-photo mark ===\n";
$beforeName = 'driver2_customer70_Before_20261022_110000_aaaaaaaa.jpg';
$afterName = 'driver2_customer70_After_20261022_111000_bbbbbbbb.jpg';
$beforeStop = [
    'delivery_status' => 'delivered',
    'delivered_at' => '2026-10-22 11:12:00',
    'badge' => 'arrival',
    'has_arrival_photo' => true,
    'has_pod_photo' => false,
    'photos' => [[
        'id' => 8,
        'photo_type' => 'After',
        'filename' => $beforeName,
        'file_path' => $beforeName,
    ]],
];
$assert(bakery_route_stop_has_after_photo($beforeStop['photos']) === false, 'a Before filename is not an After photo even when the column says After');
$assert(bakery_route_stop_photo_mark($beforeStop) === 'delivered_no_photo', 'a delivered stop with only a Before photo is not with photo');
$assert(bakery_driver_reports_stop_mark($beforeStop) === 'delivered_no_photo', 'driver reports uses the same After-photo mark');

$afterStop = [
    'delivery_status' => 'delivered',
    'delivered_at' => '2026-10-22 11:12:00',
    'badge' => 'pod',
    'photos' => [[
        'id' => 9,
        'photo_type' => 'Before',
        'filename' => $afterName,
        'file_path' => $afterName,
    ]],
];
$assert(bakery_route_stop_photo_mark($afterStop) === 'with_photo', 'an After filename counts as with photo');
$assert(bakery_driver_reports_stop_mark($afterStop) === 'with_photo', 'driver reports agrees an After filename is with photo');

$receiptStop = [
    'delivery_status' => 'delivered',
    'delivered_at' => '2026-10-22 11:20:00',
    'photos' => [[
        'photo_type' => 'Receipt',
        'filename' => 'driver2_customer70_Receipt_20261022_112000_cccccccc.jpg',
        'file_path' => 'driver2_customer70_Receipt_20261022_112000_cccccccc.jpg',
    ]],
];
$assert(bakery_route_stop_photo_mark($receiptStop) === 'delivered_no_photo', 'a receipt is not an After delivery photo');

$openStop = [
    'delivery_status' => 'pending',
    'photos' => [],
];
$assert(bakery_route_stop_photo_mark($openStop) === 'no_info', 'an open stop with no photo is no info');

echo "\n=== Bulk stamp threshold ===\n";
$assert(BAKERY_ROUTE_STOP_BULK_STAMP_MINUTES === 5, 'bulk stamp window is 5 minutes');
$assert(BAKERY_ROUTE_STOP_BULK_STAMP_MIN_STOPS === 3, 'bulk stamp needs several stops');
$assert(BAKERY_ROUTE_HEALTH_REPEAT_SKIP_DAYS === 3, 'repeat skips start at 3 open days');
$cluster = [
    ['customer_name' => 'A', 'badge' => 'pod', 'delivered_at' => '2026-10-22 10:00:00'],
    ['customer_name' => 'B', 'badge' => 'pod', 'delivered_at' => '2026-10-22 10:02:00'],
    ['customer_name' => 'C', 'badge' => 'pod', 'delivered_at' => '2026-10-22 10:05:00'],
    ['customer_name' => 'Later', 'badge' => 'pod', 'delivered_at' => '2026-10-22 11:00:00'],
];
$assert(bakery_route_stop_bulk_stamp_names($cluster) === ['A', 'B', 'C'], 'three closes inside 5 minutes are one bulk stamp');
$assert(bakery_driver_reports_bulk_stamp_names($cluster) === ['A', 'B', 'C'], 'driver reports uses the same bulk stamp list');
$pair = [
    ['customer_name' => 'A', 'badge' => 'pod', 'delivered_at' => '2026-10-22 10:00:00'],
    ['customer_name' => 'B', 'badge' => 'pod', 'delivered_at' => '2026-10-22 10:04:00'],
];
$assert(bakery_route_stop_bulk_stamp_names($pair) === [], 'two closes are not several');

echo "\n=== Dated orders far below standing ===\n";
$assert(bakery_route_health_dated_is_short(0, 47) === true, 'zero dated orders against standing stops is short');
$assert(bakery_route_health_dated_is_short(10, 47) === true, 'dated orders far below standing are short');
$assert(bakery_route_health_dated_is_short(24, 47) === false, 'dated orders at half of standing are not short');
$assert(bakery_route_health_dated_is_short(0, 0) === false, 'a driver with no standing stops is not short');

$range = bakery_route_health_parse_range('2099-11-14', '', '');
$assert($range['from'] === '2099-11-01' && $range['to'] === '2099-11-14' && $range['clamped'] === false, 'an empty range defaults to the last 14 days');
$swapped = bakery_route_health_parse_range('2099-11-14', '2099-11-10', '2099-11-04');
$assert($swapped['from'] === '2099-11-04' && $swapped['to'] === '2099-11-10', 'a reversed range is ordered');
$wide = bakery_route_health_parse_range('2099-11-14', '2099-01-01', '2099-11-14');
$assert($wide['clamped'] === true && $wide['to'] === '2099-11-14', 'a very long range is clamped');
$span = (int)(new DateTimeImmutable($wide['to']))->diff(new DateTimeImmutable($wide['from']))->days;
$assert($span === BAKERY_ROUTE_HEALTH_MAX_DAYS - 1, 'the clamp keeps the maximum number of days');

echo "\n=== Page is read only and gated ===\n";
$pageAccess = [
    'logged out' => [null, 302],
    'driver' => [['role_slug' => 'driver'], 403],
    'cashier' => [['role_slug' => 'cashier'], 403],
    'manager' => [['role_slug' => 'manager'], 200],
    'administrator' => [['role_slug' => 'administrator'], 200],
];
foreach ($pageAccess as $label => $pair) {
    $status = bakery_route_health_access_status($pair[0]);
    $assert($status === $pair[1], 'route health access for ' . $label . ' is ' . $pair[1]);
    $assert($status === bakery_route_photo_journey_access_status($pair[0]), 'route health uses the photo journey gate for ' . $label);
}
$roles = bakery_navigation_roles_for_script('route_health.php');
$assert(in_array('administrator', $roles, true) && in_array('manager', $roles, true), 'catalog allows administrator and manager');
$assert(!in_array('driver', $roles, true) && !in_array('cashier', $roles, true), 'catalog does not allow driver or cashier');

$nav = (string)file_get_contents($root . '/includes/nav.php');
$catalog = (string)file_get_contents($root . '/includes/navigation_catalog.php');
$manager = (string)file_get_contents($root . '/manager.php');
$phone = (string)file_get_contents($root . '/includes/manager_phone.php');
$pageSrc = (string)file_get_contents($pageFile);
$includeSrc = (string)file_get_contents($healthFile);
$proofSrc = (string)file_get_contents($proofFile);
$reportsSrc = (string)file_get_contents($root . '/includes/driver_reports.php');
$manifest = (string)file_get_contents($root . '/scripts/deploy_manifest.ps1');

$navSummary = strpos($nav, 'route_summary.php');
$navHealth = strpos($nav, 'route_health.php');
$navJourney = strpos($nav, 'route_photo_journey.php');
$assert($navSummary !== false && $navHealth !== false && $navJourney !== false && $navSummary < $navHealth && $navHealth < $navJourney, 'manager nav lists route health between route summary and route photo journey');
$assert(strpos($catalog, "'href' => 'route_health.php'") !== false, 'catalog lists route health');
$assert(strpos($manager, 'route_health.php?date=') !== false, 'manager page links route health');
$assert(strpos($phone, 'route_health.php?date=') !== false, 'phone manager page links route health');
$assert(strpos($pageSrc, 'bakery_route_health_access_status') !== false, 'page checks the role gate');
$assert(stripos($pageSrc, 'method="post"') === false && stripos($includeSrc, 'method="post"') === false, 'the page does not post');
foreach ([$pageSrc, $includeSrc, $proofSrc] as $src) {
    $assert(preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE)\b/i', $src) !== 1, 'route health source has no write SQL');
    $assert(stripos($src, 'mail(') === false && stripos($src, 'twilio') === false, 'route health source does not send mail or text');
}
$assert(strpos($reportsSrc, 'bakery_route_stop_photo_mark') !== false, 'driver reports calls the shared photo mark');
$assert(strpos($reportsSrc, 'bakery_route_stop_bulk_stamp_names') !== false, 'driver reports calls the shared bulk stamp list');
$assert(strpos($pageSrc, 'daily_orders.php?date=') !== false, 'the page links to Build from standing');
$assert(strpos($manifest, "'route_health.php'") !== false, 'deploy root list includes the page');
$skipBody = '';
if (preg_match('/function Get-BakeryDeployExcludeNamePatterns[\s\S]*?return @\(([\s\S]*?)\)\s*\}/', $manifest, $skipMatch)) {
    $skipBody = $skipMatch[1];
}
$assert(strpos($skipBody, 'route_health.php') === false, 'deploy skip patterns do not exclude the page');
$docs = (string)file_get_contents($root . '/docs/ROUTE_HEALTH.md');
$assert($docs !== '' && stripos($docs, 'read only') !== false, 'docs note says the page is read only');
$assert(stripos($docs, 'not approved') !== false, 'docs note says the page is not approved to ship');

echo "\n=== Strings ===\n";
$en = require $root . '/lang/en.php';
$es = require $root . '/lang/es.php';
$prefixes = ['route_health.', 'page.route_health', 'nav.item.route_health', 'nav.item_desc.route_health', 'manager_phone.route_health'];
foreach ($prefixes as $prefix) {
    $enKeys = array_values(array_filter(array_keys($en), static fn(string $key): bool => strpos($key, $prefix) === 0));
    $esKeys = array_values(array_filter(array_keys($es), static fn(string $key): bool => strpos($key, $prefix) === 0));
    sort($enKeys);
    sort($esKeys);
    $assert($enKeys !== [] && $enKeys === $esKeys, $prefix . ' keys match in English and Spanish');
    foreach ($enKeys as $key) {
        $assert($en[$key] !== '' && $es[$key] !== '', $key . ' is not empty');
        $assert(strpos((string)$en[$key], "\u{2013}") === false && strpos((string)$en[$key], "\u{2014}") === false, $key . ' English has no en dash or em dash');
        $assert(strpos((string)$es[$key], "\u{2013}") === false && strpos((string)$es[$key], "\u{2014}") === false, $key . ' Spanish has no en dash or em dash');
    }
}
foreach ([
    $pageFile,
    $healthFile,
    $proofFile,
    $root . '/css/route_health.css',
    $root . '/docs/ROUTE_HEALTH.md',
] as $path) {
    $raw = (string)file_get_contents($path);
    $assert(strpos($raw, "\u{2013}") === false && strpos($raw, "\u{2014}") === false, basename($path) . ' has no en dash or em dash');
}

echo "\n=== Database load on bakerysf_test ===\n";
$assert(strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn()) === 'bakerysf_test', 'queries stay on bakerysf_test');

$d1 = '2099-11-02';
$d2 = '2099-11-03';
$d3 = '2099-11-04';
$sell = '2099-11-05';
$weekday1 = bakery_standing_day_from_date($d1);
$sellWeekday = bakery_standing_day_from_date($sell);
$prefix = 'RouteHealth ' . bin2hex(random_bytes(3));
$created = [
    'customers' => [],
    'drivers' => [],
    'orders' => [],
    'photos' => [],
    'users' => [],
    'loads' => [],
];
$cronPath = bakery_cron_storage_dir() . '/demand_scheduler.json';
$cronBackup = is_file($cronPath) ? (string)file_get_contents($cronPath) : null;
$eventBefore = 0;
if (table_exists($db, 'operational_events')) {
    $eventBefore = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM operational_events')->fetchColumn();
}

try {
    $insertCustomer = $db->prepare(
        "INSERT INTO customers (name, address, is_active, sfb_origin) VALUES (?, '1 Test St', ?, 'human')"
    );
    $names = ['Skip', 'Twice', 'BeforeOnly', 'AfterProof', 'BulkA', 'BulkB', 'BulkC', 'Later', 'Idle', 'StandA', 'StandB', 'StandC', 'StandD', 'DatedB', 'Inactive'];
    $customerIds = [];
    foreach ($names as $name) {
        $active = $name === 'Inactive' ? 0 : 1;
        $insertCustomer->execute([$prefix . ' ' . $name, $active]);
        $customerIds[$name] = (int)$db->lastInsertId();
        $created['customers'][] = $customerIds[$name];
    }
    $insertDriver = $db->prepare('INSERT INTO drivers (name) VALUES (?)');
    $insertDriver->execute([$prefix . ' Ada']);
    $adaId = (int)$db->lastInsertId();
    $insertDriver->execute([$prefix . ' Bo']);
    $boId = (int)$db->lastInsertId();
    $insertDriver->execute([$prefix . ' Cal']);
    $calId = (int)$db->lastInsertId();
    $created['drivers'] = [$adaId, $boId, $calId];

    $insertOrder = $db->prepare(
        'INSERT INTO daily_orders (customer_id, order_date, status, total_amount, delivery_confirmed_at) VALUES (?, ?, ?, 0, ?)'
    );
    $insertAssignment = $db->prepare(
        'INSERT INTO daily_order_assignments (daily_order_id, driver_id, delivery_date, route_order, delivery_status, actual_delivery_time)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $addStop = static function (int $customerId, int $driverId, string $date, int $order, string $status, ?string $time) use ($db, $insertOrder, $insertAssignment, &$created): void {
        $confirmed = null;
        if ($status === 'delivered' && $time !== null) {
            $confirmed = $date . ' ' . $time;
        }
        $insertOrder->execute([$customerId, $date, $status === 'delivered' ? 'delivered' : 'pending', $confirmed]);
        $orderId = (int)$db->lastInsertId();
        $created['orders'][] = $orderId;
        $insertAssignment->execute([$orderId, $driverId, $date, $order, $status, $time]);
    };

    $addStop($customerIds['Skip'], $adaId, $d1, 1, 'pending', null);
    $addStop($customerIds['Skip'], $adaId, $d2, 1, 'pending', null);
    $addStop($customerIds['Skip'], $adaId, $d3, 1, 'pending', null);
    $addStop($customerIds['Twice'], $adaId, $d1, 2, 'pending', null);
    $addStop($customerIds['Twice'], $adaId, $d2, 2, 'pending', null);
    $addStop($customerIds['BeforeOnly'], $adaId, $d1, 3, 'delivered', '09:10:00');
    $addStop($customerIds['AfterProof'], $adaId, $d1, 4, 'delivered', '09:40:00');
    $addStop($customerIds['BulkA'], $adaId, $d1, 5, 'delivered', '10:00:00');
    $addStop($customerIds['BulkB'], $adaId, $d1, 6, 'delivered', '10:02:00');
    $addStop($customerIds['BulkC'], $adaId, $d1, 7, 'delivered', '10:04:00');
    $addStop($customerIds['Later'], $adaId, $d1, 8, 'delivered', '11:30:00');
    $addStop($customerIds['StandB'], $calId, $sell, 1, 'pending', null);

    $beforeFile = 'driver' . $adaId . '_customer' . $customerIds['BeforeOnly'] . '_Before_' . str_replace('-', '', $d1) . '_091000_aaaaaaaa.jpg';
    $afterFile = 'driver' . $adaId . '_customer' . $customerIds['AfterProof'] . '_After_' . str_replace('-', '', $d1) . '_094000_bbbbbbbb.jpg';
    $insertPhoto = $db->prepare(
        'INSERT INTO driver_photos (driver_id, customer_id, delivery_date, filename, original_filename, file_path, file_size, mime_type, photo_type)
         VALUES (?, ?, ?, ?, ?, ?, 20, \'image/jpeg\', ?)'
    );
    $insertPhoto->execute([$adaId, $customerIds['BeforeOnly'], $d1, $beforeFile, $beforeFile, $beforeFile, 'After']);
    $created['photos'][] = (int)$db->lastInsertId();
    $insertPhoto->execute([$adaId, $customerIds['AfterProof'], $d1, $afterFile, $afterFile, $afterFile, 'After']);
    $created['photos'][] = (int)$db->lastInsertId();

    $insertStanding = $db->prepare(
        'INSERT INTO standing_routes (day_of_week, driver_id, customer_id, route_order) VALUES (?, ?, ?, ?)'
    );
    $insertStanding->execute([$weekday1, $boId, $customerIds['Idle'], 1]);
    $insertStanding->execute([$sellWeekday, $adaId, $customerIds['StandA'], 1]);
    $insertStanding->execute([$sellWeekday, $adaId, $customerIds['Inactive'], 2]);
    $insertStanding->execute([$sellWeekday, $calId, $customerIds['StandB'], 1]);
    $insertStanding->execute([$sellWeekday, $calId, $customerIds['StandC'], 2]);
    $insertStanding->execute([$sellWeekday, $calId, $customerIds['StandD'], 3]);
    $insertStanding->execute([$sellWeekday, $calId, $customerIds['DatedB'], 4]);

    $roleId = (int)$db->query("SELECT id FROM roles WHERE slug = 'driver' LIMIT 1")->fetchColumn();
    $assert($roleId > 0, 'driver role exists on bakerysf_test');
    $email = 'route-health-' . bin2hex(random_bytes(3)) . '@example.test';
    $db->prepare('INSERT INTO users (email, password_hash, login_code, display_name, role_id, driver_id, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
        ->execute([$email, 'x', '7' . str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT), $prefix . ' Ada', $roleId, $adaId]);
    $userId = (int)$db->lastInsertId();
    $created['users'][] = $userId;
    if (table_exists($db, 'time_clock_punches')) {
        $db->prepare('INSERT INTO time_clock_punches (user_id, clock_in_at, clock_out_at) VALUES (?, ?, ?)')
            ->execute([$userId, $d1 . ' 07:05:00', $d1 . ' 15:00:00']);
    }
    if (table_exists($db, 'driver_loads')) {
        $db->prepare('INSERT INTO driver_loads (driver_id, delivery_date, status) VALUES (?, ?, ?)')
            ->execute([$adaId, $d1, 'reconciled']);
        $created['loads'][] = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO driver_loads (driver_id, delivery_date, status) VALUES (?, ?, ?)')
            ->execute([$adaId, $d2, 'loaded']);
        $created['loads'][] = (int)$db->lastInsertId();
    }

    $loaded = bakery_route_health_load($db, $d1, $d3, new DateTimeImmutable('2099-11-04 12:00:00', new DateTimeZone('America/Los_Angeles')));
    $assert($loaded['from'] === $d1 && $loaded['to'] === $d3, 'load keeps the requested range');
    $adaD1 = null;
    $boD1 = null;
    foreach ($loaded['days'] as $row) {
        if ((int)$row['driver_id'] === $adaId && $row['date'] === $d1) {
            $adaD1 = $row;
        }
        if ((int)$row['driver_id'] === $boId && $row['date'] === $d1) {
            $boD1 = $row;
        }
    }
    $assert(is_array($adaD1), 'Ada has a row on the first day');
    $assert(is_array($adaD1) && (int)$adaD1['stops'] === 8, 'Ada has 8 stops on the first day');
    $assert(is_array($adaD1) && (int)$adaD1['delivered'] === 6, 'Ada delivered 6 stops on the first day');
    $assert(is_array($adaD1) && (int)$adaD1['open'] === 2, 'Ada left 2 stops open on the first day');
    $assert(is_array($adaD1) && (int)$adaD1['delivered_with_after'] === 1, 'only the After photo counts as delivery proof');
    $assert(is_array($adaD1) && $adaD1['route_closed'] === true, 'a reconciled load is a closed route');
    $assert(is_array($adaD1) && $adaD1['clocked_in'] === true, 'a punch linked to the driver is a clock-in');
    $assert(is_array($boD1) && (int)$boD1['stops'] === 0, 'a standing driver with no routed stops is listed at 0');
    $assert(is_array($boD1) && $boD1['clocked_in'] === false && $boD1['route_closed'] === false, 'Bo has no clock-in and no closed route');

    $adaD2 = null;
    foreach ($loaded['days'] as $row) {
        if ((int)$row['driver_id'] === $adaId && $row['date'] === $d2) {
            $adaD2 = $row;
        }
    }
    $assert(is_array($adaD2) && $adaD2['route_closed'] === false, 'a loaded route that is not reconciled is not closed');

    $skip = null;
    $twice = null;
    foreach ($loaded['repeat_skips'] as $row) {
        if ((int)$row['customer_id'] === $customerIds['Skip']) {
            $skip = $row;
        }
        if ((int)$row['customer_id'] === $customerIds['Twice']) {
            $twice = $row;
        }
    }
    $assert(is_array($skip) && (int)$skip['times'] === 3, 'a stop open 3 times is a repeat skip');
    $assert(is_array($skip) && in_array($prefix . ' Ada', $skip['drivers'], true), 'the repeat skip names the driver');
    $assert(is_array($skip) && $skip['dates'] === [$d1, $d2, $d3], 'the repeat skip lists the dates');
    $assert($twice === null, 'a stop open twice is not a repeat skip');

    $missingNames = array_map(static fn(array $row): string => (string)$row['customer_name'], $loaded['missing_after']);
    $assert(in_array($prefix . ' BeforeOnly', $missingNames, true), 'a delivered Before photo is missing an After photo');
    $assert(!in_array($prefix . ' AfterProof', $missingNames, true), 'a delivered After photo is not a missing-proof flag');
    $assert(!in_array($prefix . ' Skip', $missingNames, true), 'an open stop is not a missing After photo');
    $afterUrl = '';
    foreach ($loaded['missing_after'] as $row) {
        if ((string)$row['customer_name'] === $prefix . ' BeforeOnly') {
            $afterUrl = (string)($row['photo_url'] ?? '');
        }
    }
    $assert(strpos($afterUrl, 'driver_photo.php') !== false, 'proof links go through driver_photo.php');
    $assert(strpos($afterUrl, 'uploads/driver_photos') === false, 'proof links do not point at the uploads folder');

    $bulkNames = [];
    foreach ($loaded['bulk_stamps'] as $row) {
        if ((int)$row['driver_id'] === $adaId && $row['date'] === $d1) {
            $bulkNames = $row['stores'];
        }
    }
    sort($bulkNames);
    $expectedBulk = [$prefix . ' BulkA', $prefix . ' BulkB', $prefix . ' BulkC'];
    sort($expectedBulk);
    $assert($bulkNames === $expectedBulk, 'three closes in a few minutes are flagged and the later stop is not');

    $tomorrow = bakery_route_health_tomorrow($db, $sell, new DateTimeImmutable('2099-11-04 12:00:00', new DateTimeZone('America/Los_Angeles')));
    $byDriver = [];
    foreach ($tomorrow['drivers'] as $row) {
        $byDriver[(int)$row['driver_id']] = $row;
    }
    $assert((int)($byDriver[$adaId]['standing'] ?? -1) === 1, 'inactive standing stops are not counted');
    $assert((int)($byDriver[$adaId]['dated'] ?? -1) === 0 && !empty($byDriver[$adaId]['short']), 'zero dated orders against standing stops is marked short');
    $assert((int)($byDriver[$calId]['standing'] ?? -1) === 4 && (int)($byDriver[$calId]['dated'] ?? -1) === 1 && !empty($byDriver[$calId]['short']), 'one dated order against four standing stops is short');
    $assert($tomorrow['date'] === $sell, 'tomorrow check uses the requested sell day');
    $assert(!empty($tomorrow['overnight']['missed']), 'a sell morning with no overnight run for that slot is missed');
    $assert(strpos((string)$tomorrow['build_href'], 'daily_orders.php?date=' . $sell) !== false, 'build link opens the existing standing flow for the sell day');
    $assert(stripos((string)$tomorrow['build_href'], 'action=') === false, 'build link does not post a generate action');

    $slot = new DateTimeImmutable('2099-11-04 03:10:00', new DateTimeZone('America/Los_Angeles'));
    bakery_cron_record_run($db, 'demand_scheduler', 'ok', ['source' => 'route-health-test'], $slot);
    $ran = bakery_route_health_overnight($db, new DateTimeImmutable('2099-11-04 12:00:00', new DateTimeZone('America/Los_Angeles')));
    $assert(empty($ran['missed']) && !empty($ran['ran']), 'a successful run after the due slot is recorded as ran');
} finally {
    if (table_exists($db, 'operational_events')) {
        $db->prepare("DELETE FROM operational_events WHERE id > ? AND event_type = 'cron_run' AND summary LIKE 'Cron demand_scheduler %'")
            ->execute([$eventBefore]);
    }
    if ($cronBackup === null) {
        if (is_file($cronPath)) {
            unlink($cronPath);
        }
    } else {
        file_put_contents($cronPath, $cronBackup);
    }
    if ($created['loads'] && table_exists($db, 'driver_loads')) {
        $ph = implode(',', array_fill(0, count($created['loads']), '?'));
        $db->prepare("DELETE FROM driver_loads WHERE id IN ($ph)")->execute($created['loads']);
    }
    if ($created['users'] && table_exists($db, 'time_clock_punches')) {
        $ph = implode(',', array_fill(0, count($created['users']), '?'));
        $db->prepare("DELETE FROM time_clock_punches WHERE user_id IN ($ph)")->execute($created['users']);
    }
    if ($created['users']) {
        $ph = implode(',', array_fill(0, count($created['users']), '?'));
        $db->prepare("DELETE FROM users WHERE id IN ($ph)")->execute($created['users']);
    }
    if ($created['photos']) {
        $ph = implode(',', array_fill(0, count($created['photos']), '?'));
        $db->prepare("DELETE FROM driver_photos WHERE id IN ($ph)")->execute($created['photos']);
    }
    if ($created['orders']) {
        $ph = implode(',', array_fill(0, count($created['orders']), '?'));
        $db->prepare("DELETE FROM daily_order_assignments WHERE daily_order_id IN ($ph)")->execute($created['orders']);
        $db->prepare("DELETE FROM daily_orders WHERE id IN ($ph)")->execute($created['orders']);
    }
    if ($created['customers']) {
        $ph = implode(',', array_fill(0, count($created['customers']), '?'));
        $db->prepare("DELETE FROM standing_routes WHERE customer_id IN ($ph)")->execute($created['customers']);
        $db->prepare("DELETE FROM customers WHERE id IN ($ph)")->execute($created['customers']);
    }
    if ($created['drivers']) {
        $ph = implode(',', array_fill(0, count($created['drivers']), '?'));
        $db->prepare("DELETE FROM drivers WHERE id IN ($ph)")->execute($created['drivers']);
    }
}

echo "\n" . ($fail === 0 ? "ALL PASS\n" : ($fail . " FAILED\n"));
exit($fail === 0 ? 0 : 1);
