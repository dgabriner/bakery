<?php
/**
 * Route photo journey: filename credit, timeline math, flags, and photo access.
 *
 * Usage: php tests/run_route_photo_journey_tests.php
 * Database: bakerysf_test only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
$db = require $root . '/tests/harness.php';
require_once $root . '/includes/route_photo_journey.php';
require_once $root . '/includes/photo_handler.php';
require_once $root . '/includes/navigation_catalog.php';

$fail = 0;
$assert = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
};

$byName = static function (array $drivers, string $name): ?array {
    foreach ($drivers as $driver) {
        if ((string)($driver['name'] ?? '') === $name) {
            return $driver;
        }
    }
    return null;
};

echo "=== Filename credit ===\n";
$parsed = bakery_driver_photo_parse_filename('2026/10/driver2_customer70_After_20261020_110531_26514ba9.jpg');
$assert(
    is_array($parsed) && $parsed['driver_id'] === 2 && $parsed['customer_id'] === 70 && $parsed['photo_type'] === 'After',
    'After filename yields driver, customer, and type'
);
$before = bakery_driver_photo_parse_filename('driver7_customer71_Before_20261020_094329_d8a25256.jpg');
$assert(
    is_array($before) && $before['driver_id'] === 7 && $before['customer_id'] === 71 && $before['photo_type'] === 'Before',
    'Before filename yields the driver embedded in the name'
);
$assert(bakery_driver_photo_parse_filename('arrival-noe-cafe.jpg') === null, 'a name without the driver token is not parsed');
$credited = bakery_driver_photo_credited_driver_id([
    'filename' => 'driver4_customer10_After_20261020_102600_aaaaaaaa.jpg',
    'file_path' => '2026/10/driver4_customer10_After_20261020_102600_aaaaaaaa.jpg',
    'driver_id' => 2,
    'customer_id' => 99,
    'photo_type' => 'Before',
]);
$assert($credited === 4, 'filename driver wins over the driver_photos.driver_id column');

echo "\n=== Timeline, flags, and someone else ===\n";
$day = bakery_route_photo_journey_assemble([
    'date' => '2026-10-20',
    'selected_driver_id' => 0,
    'drivers' => [2 => 'Laura', 7 => 'Juan', 4 => 'Marcos', 8 => 'Boundary'],
    'clock_ins' => [
        7 => '2026-10-20 07:04:00',
        8 => '2026-10-20 08:00:00',
    ],
    'stops' => [
        journey_stop(2, 70, 'Casa latina', '2026-10-20 11:05:00', 279.45, 405, 1),
        journey_stop(2, 69, 'Casa Guadalupe', '2026-10-20 12:15:00', 187.00, 187, 2),
        journey_stop(2, 85, 'Lucky Pork', '2026-10-20 12:31:00', 0, 0, 3),
        journey_stop(2, 154, 'Evergreen', '2026-10-20 12:53:00', 40, 130, 4),
        journey_stop(2, 10, 'Golden Gate', '2026-10-20 10:26:00', 20, 10, 5),
        journey_stop(2, 11, 'Bay food', '2026-10-20 15:37:00', 15, 8, 6),
        journey_stop(7, 90, 'Fishtail', null, 12, 4, 1, 'pending', 2),
        journey_stop(7, 91, 'Super Discount', null, 30, 6, 2, 'pending', 2),
        journey_stop(7, 92, '1010 Market', null, 18, 5, 3, 'pending', 2),
        journey_stop(7, 71, 'Casa Lucas', '2026-10-20 09:44:00', 34.50, 123, 4),
        journey_stop(7, 73, 'City Discount', '2026-10-20 11:08:00', 82.80, 164, 5),
        journey_stop(7, 80, 'El chavo', '2026-10-20 13:03:00', 0, 0, 6),
        journey_stop(7, 63, 'Amigos', '2026-10-20 15:34:00', 151.11, 295, 7),
        journey_stop(8, 201, 'Ninety', '2026-10-20 09:31:00', 5, 1, 1),
        journey_stop(8, 202, 'Ninety one', '2026-10-20 11:03:00', 5, 1, 2),
    ],
    'photos' => [
        journey_photo(1, 2, 70, 'Before', '2026-10-20 10:56:00'),
        journey_photo(2, 2, 70, 'After', '2026-10-20 11:05:00'),
        journey_photo(3, 2, 69, 'Before', '2026-10-20 11:23:00'),
        journey_photo(4, 2, 69, 'After', '2026-10-20 12:15:00'),
        journey_photo(5, 2, 85, 'Before', '2026-10-20 12:18:00'),
        journey_photo(6, 2, 85, 'After', '2026-10-20 12:31:00'),
        journey_photo(7, 2, 154, 'Before', '2026-10-20 12:37:00'),
        journey_photo(8, 2, 154, 'After', '2026-10-20 12:53:00'),
        journey_photo(9, 2, 10, 'After', '2026-10-20 10:26:00', 4),
        journey_photo(10, 2, 11, 'After', '2026-10-20 15:37:00', 4),
        journey_photo(11, 7, 71, 'Before', '2026-10-20 09:43:00'),
        journey_photo(12, 7, 73, 'Before', '2026-10-20 11:07:00'),
        journey_photo(13, 7, 80, 'Before', '2026-10-20 13:00:00'),
        journey_photo(14, 7, 80, 'After', '2026-10-20 13:01:00'),
        journey_photo(15, 7, 63, 'Before', '2026-10-20 15:34:00'),
        journey_photo(16, 8, 201, 'Before', '2026-10-20 09:30:00'),
        journey_photo(17, 8, 202, 'Before', '2026-10-20 11:02:00'),
    ],
]);

$laura = $byName($day['drivers'], 'Laura');
$juan = $byName($day['drivers'], 'Juan');
$marcos = $byName($day['drivers'], 'Marcos');
$boundary = $byName($day['drivers'], 'Boundary');
$assert(is_array($laura) && is_array($juan) && is_array($marcos) && is_array($boundary), 'each driver with work is present');

$lauraNames = array_map(static fn(array $stop): string => (string)$stop['customer_name'], $laura['stops'] ?? []);
$assert($lauraNames === ['Casa latina', 'Casa Guadalupe', 'Lucky Pork', 'Evergreen'], 'Laura timeline is her own deliveries, not the morning plan');
$assert(array_column($laura['stops'], 'stay_minutes') === [9, 52, 13, 16], 'minutes at stop are completion minus first photo');
$assert(array_column($laura['stops'], 'gap_minutes') === [null, 18, 3, 6], 'Laura travel uses the previous delivery, and the first stop has no clock-in');
$assert(($laura['stops'][0]['gap_kind'] ?? '') === 'first', 'first stop without clock-in is marked first');
$assert(($laura['no_clock_in'] ?? false) === true, 'Laura is flagged for no clock-in');
$assert(($laura['delivered_count'] ?? -1) === 4 && ($laura['pending_count'] ?? -1) === 0, 'Laura delivered 4 and has nothing pending');
$assert(($laura['flags']['zero_dollar'] ?? []) === ['Lucky Pork'], 'a $0 delivered stop is flagged');
$assert(($laura['flags']['short_closes'] ?? []) === [], 'a delivery photo is not a short close');
$assert(($laura['flags']['long_gaps'] ?? []) === [], 'Laura has no gap over 90 min');
$assert((($laura['stops'][0]['photos'][0]['photo_type'] ?? '') === 'After'), 'thumbnail list leads with the After photo');
$assert((($laura['stops'][0]['badge'] ?? '') === 'pod'), 'an After photo badges as delivery photo');

$otherByStore = [];
foreach ($laura['other'] ?? [] as $note) {
    $otherByStore[(string)$note['customer_name']] = $note;
}
$assert(
    ($otherByStore['Golden Gate']['kind'] ?? '') === 'delivered_by' && ($otherByStore['Golden Gate']['driver_name'] ?? '') === 'Marcos',
    'a planned stop whose filename names another driver is marked delivered by that driver'
);
$assert(($otherByStore['Bay food']['driver_name'] ?? '') === 'Marcos', 'Bay food is credited to Marcos');
$assert(
    ($otherByStore['Fishtail']['kind'] ?? '') === 'moved_pending' && ($otherByStore['Fishtail']['driver_name'] ?? '') === 'Juan',
    'a morning-plan stop now on someone else and still open is marked moved'
);
$assert(
    array_map(static fn(array $stop): string => (string)$stop['customer_name'], $marcos['stops'] ?? []) === ['Golden Gate', 'Bay food'],
    'Marcos timeline follows the filename driver, not the planned route'
);

$juanNames = array_map(static fn(array $stop): string => (string)$stop['customer_name'], $juan['stops'] ?? []);
$assert(
    $juanNames === ['Casa Lucas', 'City Discount', 'El chavo', 'Amigos', 'Fishtail', 'Super Discount', '1010 Market'],
    'Juan timeline is delivery order, then pending stops'
);
$assert(array_column(array_slice($juan['stops'], 0, 4), 'stay_minutes') === [1, 1, 3, 0], 'Juan minutes at stop use completion time');
$assert(array_column(array_slice($juan['stops'], 0, 4), 'gap_minutes') === [159, 83, 112, 151], 'first travel is from clock-in and later travel is from the previous delivery');
$assert(($juan['stops'][0]['gap_kind'] ?? '') === 'clock', 'first stop with a clock-in is measured from clock-in');
$assert(($juan['stops'][0]['badge'] ?? '') === 'arrival' && ($juan['stops'][2]['badge'] ?? '') === 'pod', 'arrival-only and delivery-photo badges');
$assert(($juan['stops'][4]['badge'] ?? '') === 'pending', 'an open planned stop badges pending');
$assert(($juan['no_clock_in'] ?? true) === false && ($juan['clock_in_at'] ?? '') === '2026-10-20 07:04:00', 'Juan clock-in is kept');
$assert(($juan['delivered_count'] ?? -1) === 4 && ($juan['pending_count'] ?? -1) === 3, 'Juan delivered and pending counts');
$assert(($juan['pending_names'] ?? []) === ['Fishtail', 'Super Discount', '1010 Market'], 'pending names follow route order');
$assert(($juan['flags']['zero_dollar'] ?? []) === ['El chavo'], '$0 delivered stop is flagged and pending stops are not');
$assert(($juan['flags']['short_closes'] ?? []) === ['Casa Lucas', 'City Discount', 'Amigos'], 'under 2 min with no After photo is flagged');
$longGapStores = array_map(static fn(array $gap): string => (string)$gap['store'], $juan['flags']['long_gaps'] ?? []);
$assert($longGapStores === ['Casa Lucas', 'El chavo', 'Amigos'], 'gaps over 90 min are flagged and 83 min is not');

$assert(count($boundary['flags']['long_gaps'] ?? []) === 1 && ($boundary['flags']['long_gaps'][0]['store'] ?? '') === 'Ninety one', 'a 90 min gap is not over 90, and 91 min is');
$assert(($boundary['stops'][0]['gap_minutes'] ?? -1) === 90 && ($boundary['stops'][1]['gap_minutes'] ?? -1) === 91, 'boundary gaps are 90 and 91 minutes');

$pins = bakery_route_photo_journey_map_points($juan);
$numbered = array_values(array_filter($pins['stops'], static fn(array $pin): bool => ($pin['kind'] ?? '') !== 'pending'));
$pendingPins = array_values(array_filter($pins['stops'], static fn(array $pin): bool => ($pin['kind'] ?? '') === 'pending'));
$assert(array_column($numbered, 'n') === [1, 2, 3, 4], 'map numbers follow delivery order');
$assert(count($pendingPins) === 3 && array_key_exists('n', $pendingPins[0]) && $pendingPins[0]['n'] === null, 'pending stops are unnumbered red pins');
$assert(($pins['bakery']['label_key'] ?? '') === 'route_photo_journey.legend_bakery', 'map reuses a bakery pin');

$onlyLaura = bakery_route_photo_journey_assemble([
    'date' => '2026-10-20',
    'selected_driver_id' => 2,
    'drivers' => [2 => 'Laura', 7 => 'Juan'],
    'clock_ins' => [],
    'stops' => [
        journey_stop(2, 70, 'Casa latina', '2026-10-20 11:05:00', 10, 1, 1),
        journey_stop(7, 71, 'Casa Lucas', '2026-10-20 09:44:00', 10, 1, 1),
    ],
    'photos' => [
        journey_photo(1, 2, 70, 'After', '2026-10-20 11:05:00'),
        journey_photo(2, 7, 71, 'Before', '2026-10-20 09:43:00'),
    ],
]);
$assert(count($onlyLaura['drivers']) === 1 && ($onlyLaura['drivers'][0]['name'] ?? '') === 'Laura', 'driver picker limits the page to one driver');
$assert(count($onlyLaura['picker']) === 2, 'the picker still lists every driver with work that day');

echo "\n=== Photo URL and access ===\n";
$url = bakery_driver_photo_url('2026/10/status-proof.jpg', '/bake/');
$assert(
    $url === '/bake/driver_photo.php?file=' . rawurlencode('2026/10/status-proof.jpg')
        && strpos($url, '/uploads/driver_photos/') === false,
    'driver photo display URL is the handler, not the public uploads path'
);
$handler = new PhotoHandler();
$fromHandler = $handler->getPhotoUrl('status-proof.jpg');
$assert(strpos($fromHandler, 'driver_photo.php?file=') !== false && strpos($fromHandler, 'status-proof.jpg') !== false, 'PhotoHandler::getPhotoUrl uses the handler and keeps the file name');

$photoHandlerSrc = (string)file_get_contents($root . '/includes/photo_handler.php');
$assert(strpos($photoHandlerSrc, 'https://bakery.sourflour.org/uploads/driver_photos/') === false, 'photo handler does not hard-code the live uploads host');

$anonymous = bakery_driver_photo_authorize($db, null, 0, 'status-proof.jpg');
$assert(($anonymous['status'] ?? 0) === 403, 'logged-out photo authorize is 403');

$cashier = bakery_driver_photo_authorize($db, ['id' => 1, 'role_slug' => 'cashier', 'driver_id' => null], 0, '../includes/config.php');
$assert(($cashier['status'] ?? 0) === 403 && empty($cashier['path']), 'a cashier cannot read delivery photos');

$traversal = bakery_driver_photo_authorize($db, ['id' => 1, 'role_slug' => 'manager', 'driver_id' => null], 0, '../../includes/config.php');
$assert(($traversal['status'] ?? 0) === 404 && empty($traversal['path']), 'a manager cannot read outside driver_photos');

$pageAccess = [
    'logged out' => [null, 302],
    'driver' => [['role_slug' => 'driver'], 403],
    'cashier' => [['role_slug' => 'cashier'], 403],
    'manager' => [['role_slug' => 'manager'], 200],
    'administrator' => [['role_slug' => 'administrator'], 200],
];
foreach ($pageAccess as $label => $pair) {
    $assert(bakery_route_photo_journey_access_status($pair[0]) === $pair[1], 'journey page access for ' . $label . ' is ' . $pair[1]);
}

$roles = bakery_navigation_roles_for_script('route_photo_journey.php');
$assert(in_array('administrator', $roles, true) && in_array('manager', $roles, true), 'catalog allows administrator and manager');
$assert(!in_array('driver', $roles, true) && !in_array('cashier', $roles, true), 'catalog does not allow driver or cashier on the page');
$photoRoles = bakery_navigation_roles_for_script('driver_photo.php');
$assert(in_array('driver', $photoRoles, true) && in_array('manager', $photoRoles, true) && in_array('administrator', $photoRoles, true), 'photo handler allowlist includes the driver and managers');
$assert(!in_array('cashier', $photoRoles, true), 'photo handler allowlist excludes cashier');

$pageSrc = (string)file_get_contents($root . '/route_photo_journey.php');
$photoSrc = (string)file_get_contents($root . '/driver_photo.php');
$htaccess = (string)file_get_contents($root . '/uploads/driver_photos/.htaccess');
$rootHtaccess = (string)file_get_contents($root . '/.htaccess');
$nav = (string)file_get_contents($root . '/includes/nav.php');
$manifest = (string)file_get_contents($root . '/scripts/deploy_manifest.ps1');
$css = (string)file_get_contents($root . '/css/route_photo_journey.css');
$assert(strpos($pageSrc, 'bakery_route_photo_journey_access_status') !== false, 'page checks journey access');
$assert(strpos($pageSrc, 'leaflet') !== false, 'page reuses Leaflet');
$assert(strpos($css, '@media print') !== false, 'print CSS exists');
$assert(strpos($photoSrc, 'bakery_driver_photo_authorize') !== false, 'photo endpoint uses the authorize helper');
$assert(strpos($htaccess, 'Require all denied') !== false && strpos($htaccess, 'Allow from all') === false, 'driver_photos htaccess denies direct access');
$assert(strpos($rootHtaccess, 'uploads/driver_photos/') !== false, 'app htaccess blocks the public uploads URL');
$assert(strpos($nav, 'route_photo_journey.php') !== false, 'manager Routes menu links the page');
$assert(strpos($manifest, "'route_photo_journey.php'") !== false && strpos($manifest, "'driver_photo.php'") !== false, 'deploy root list includes the new pages');
$skipBody = '';
if (preg_match('/function Get-BakeryDeployExcludeNamePatterns[\s\S]*?return @\((.*?)\);/', $manifest, $skipMatch)) {
    $skipBody = $skipMatch[1];
}
$assert(strpos($skipBody, 'route_photo_journey.php') === false, 'deploy skip patterns were not edited to exclude the page');

$en = require $root . '/lang/en.php';
$es = require $root . '/lang/es.php';
$prefixes = ['route_photo_journey.', 'page.route_photo_journey', 'nav.item.route_photo_journey', 'nav.item_desc.route_photo_journey', 'manager_phone.journey'];
foreach ($prefixes as $prefix) {
    $enKeys = array_values(array_filter(array_keys($en), static fn(string $key): bool => strpos($key, $prefix) === 0));
    $esKeys = array_values(array_filter(array_keys($es), static fn(string $key): bool => strpos($key, $prefix) === 0));
    $assert($enKeys !== [] && $enKeys === $esKeys, $prefix . ' keys match in English and Spanish');
    foreach ($enKeys as $key) {
        $assert($en[$key] !== '' && $es[$key] !== '', $key . ' is not empty');
        $assert(strpos((string)$en[$key], "\u{2013}") === false && strpos((string)$en[$key], "\u{2014}") === false, $key . ' English has no en dash or em dash');
        $assert(strpos((string)$es[$key], "\u{2013}") === false && strpos((string)$es[$key], "\u{2014}") === false, $key . ' Spanish has no en dash or em dash');
    }
}

foreach ([
    $root . '/includes/route_photo_journey.php',
    $root . '/route_photo_journey.php',
    $root . '/driver_photo.php',
    $root . '/css/route_photo_journey.css',
    $root . '/includes/route_photo_journey.js',
] as $path) {
    $raw = (string)file_get_contents($path);
    $assert(strpos($raw, "\u{2013}") === false && strpos($raw, "\u{2014}") === false, basename($path) . ' has no en dash or em dash');
}

echo "\n=== Database load on bakerysf_test ===\n";
$assert(strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn()) === 'bakerysf_test', 'queries stay on bakerysf_test');

$prefix = 'Journey ' . bin2hex(random_bytes(3));
$date = '2037-03-16';
$weekday = (int)(new DateTimeImmutable($date))->format('N');
$created = [
    'customers' => [],
    'drivers' => [],
    'orders' => [],
    'users' => [],
    'photos' => [],
];
$photoFile = $root . '/uploads/driver_photos/journey-test/proof.jpg';
try {
    $insertCustomer = $db->prepare("INSERT INTO customers (name, address, latitude, longitude, is_active, sfb_origin) VALUES (?, '1 Test St', ?, ?, 1, 'human')");
    $insertCustomer->execute([$prefix . ' Latin', 37.7501, -122.4101]);
    $latinId = (int)$db->lastInsertId();
    $insertCustomer->execute([$prefix . ' Gate', 37.7602, -122.4202]);
    $gateId = (int)$db->lastInsertId();
    $insertCustomer->execute([$prefix . ' Open', 37.7703, -122.4303]);
    $openId = (int)$db->lastInsertId();
    $created['customers'] = [$latinId, $gateId, $openId];

    $insertDriver = $db->prepare('INSERT INTO drivers (name) VALUES (?)');
    $insertDriver->execute([$prefix . ' Laura']);
    $lauraId = (int)$db->lastInsertId();
    $insertDriver->execute([$prefix . ' Marcos']);
    $marcosId = (int)$db->lastInsertId();
    $created['drivers'] = [$lauraId, $marcosId];

    $insertOrder = $db->prepare(
        "INSERT INTO daily_orders (customer_id, order_date, status, total_amount, delivery_order_total, delivered_pieces, delivery_confirmed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $insertOrder->execute([$latinId, $date, 'delivered', 10, 10, 4, $date . ' 11:05:00']);
    $latinOrder = (int)$db->lastInsertId();
    $insertOrder->execute([$gateId, $date, 'delivered', 20, 20, 3, $date . ' 10:26:00']);
    $gateOrder = (int)$db->lastInsertId();
    $insertOrder->execute([$openId, $date, 'pending', 8, 8, null, null]);
    $openOrder = (int)$db->lastInsertId();
    $created['orders'] = [$latinOrder, $gateOrder, $openOrder];

    $insertAssignment = $db->prepare(
        "INSERT INTO daily_order_assignments (daily_order_id, driver_id, delivery_date, route_order, delivery_status, actual_delivery_time)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $insertAssignment->execute([$latinOrder, $lauraId, $date, 1, 'delivered', '11:05:00']);
    $insertAssignment->execute([$gateOrder, $lauraId, $date, 2, 'delivered', '10:26:00']);
    $insertAssignment->execute([$openOrder, $lauraId, $date, 3, 'pending', null]);
    $db->prepare('INSERT INTO standing_routes (day_of_week, driver_id, customer_id, route_order) VALUES (?, ?, ?, ?)')
        ->execute([$weekday, $lauraId, $openId, 3]);

    $afterName = 'driver' . $marcosId . '_customer' . $gateId . '_After_20990316_102600_abcd1234.jpg';
    $beforeName = 'driver' . $lauraId . '_customer' . $latinId . '_Before_20990316_105600_abcd1234.jpg';
    $insertPhoto = $db->prepare(
        "INSERT INTO driver_photos (driver_id, customer_id, delivery_date, filename, original_filename, file_path, file_size, mime_type, photo_type, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 120, 'image/jpeg', ?, ?)"
    );
    $insertPhoto->execute([$lauraId, $gateId, $date, $afterName, $afterName, 'journey-test/' . $afterName, 'After', $date . ' 10:26:00']);
    $created['photos'][] = (int)$db->lastInsertId();
    $insertPhoto->execute([$lauraId, $latinId, $date, $beforeName, $beforeName, 'journey-test/' . $beforeName, 'Before', $date . ' 10:56:00']);
    $latinPhotoId = (int)$db->lastInsertId();
    $created['photos'][] = $latinPhotoId;

    $roleId = (int)$db->query("SELECT id FROM roles WHERE slug = 'driver' LIMIT 1")->fetchColumn();
    $assert($roleId > 0, 'driver role exists on bakerysf_test');
    $email = 'journey-' . bin2hex(random_bytes(3)) . '@example.test';
    $db->prepare('INSERT INTO users (email, password_hash, login_code, display_name, role_id, driver_id, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
        ->execute([$email, 'x', '8' . str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT), $prefix . ' Laura', $roleId, $lauraId]);
    $userId = (int)$db->lastInsertId();
    $created['users'][] = $userId;
    if (table_exists($db, 'time_clock_punches')) {
        $db->prepare('INSERT INTO time_clock_punches (user_id, clock_in_at) VALUES (?, ?)')->execute([$userId, $date . ' 07:04:00']);
    }

    if (!is_dir(dirname($photoFile))) {
        mkdir(dirname($photoFile), 0755, true);
    }
    file_put_contents($photoFile, base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCfAA//2Q=='));
    $db->prepare('UPDATE driver_photos SET file_path = ? WHERE id = ?')->execute(['journey-test/proof.jpg', $latinPhotoId]);

    $loaded = bakery_route_photo_journey_load($db, $date, 0);
    $loadedLaura = null;
    $loadedMarcos = null;
    foreach ($loaded['drivers'] as $driver) {
        if ((int)$driver['id'] === $lauraId) {
            $loadedLaura = $driver;
        }
        if ((int)$driver['id'] === $marcosId) {
            $loadedMarcos = $driver;
        }
    }
    $assert(is_array($loadedMarcos), 'load credits the filename driver even when the row driver_id is the planned driver');
    $marcosStopNames = array_map(static fn(array $stop): string => (string)$stop['customer_name'], $loadedMarcos['stops'] ?? []);
    $assert($marcosStopNames === [$prefix . ' Gate'], 'database load puts the After photo on the filename driver');
    $assert(is_array($loadedLaura), 'planned driver is still on the page');
    $lauraStopNames = array_map(static fn(array $stop): string => (string)$stop['customer_name'], $loadedLaura['stops'] ?? []);
    $assert(in_array($prefix . ' Latin', $lauraStopNames, true), 'Laura keeps the stop whose filename is hers');
    $assert(!in_array($prefix . ' Gate', $lauraStopNames, true), 'Laura does not keep a stop the filename gives to Marcos');
    $noteNames = array_map(static fn(array $note): string => (string)$note['customer_name'], $loadedLaura['other'] ?? []);
    $assert(in_array($prefix . ' Gate', $noteNames, true), 'Laura is told Gate was delivered by someone else');
    if (table_exists($db, 'time_clock_punches')) {
        $assert(($loadedLaura['clock_in_at'] ?? '') === $date . ' 07:04:00', 'clock-in comes from time_clock_punches for the linked user');
    }
    $latinStop = null;
    foreach ($loadedLaura['stops'] as $stop) {
        if ((string)$stop['customer_name'] === $prefix . ' Latin') {
            $latinStop = $stop;
        }
    }
    $assert(is_array($latinStop) && ($latinStop['gap_kind'] ?? '') === 'clock' && ($latinStop['gap_minutes'] ?? 0) === 232, 'first stop travel is measured from clock-in');

    $managerOk = bakery_driver_photo_authorize($db, ['id' => 1, 'role_slug' => 'manager', 'driver_id' => null], $latinPhotoId, '');
    $assert(($managerOk['status'] ?? 0) === 200 && is_file((string)($managerOk['path'] ?? '')), 'manager can read the photo through the handler path');
    $ownerOk = bakery_driver_photo_authorize($db, ['id' => $userId, 'role_slug' => 'driver', 'driver_id' => $lauraId], $latinPhotoId, '');
    $assert(($ownerOk['status'] ?? 0) === 200, 'the driver who took the photo can read it');
    $otherDriver = bakery_driver_photo_authorize($db, ['id' => $userId, 'role_slug' => 'driver', 'driver_id' => $marcosId], $latinPhotoId, '');
    $assert(($otherDriver['status'] ?? 0) === 403, 'another driver cannot read the photo');

    $gatePhotoId = (int)$created['photos'][0];
    $filenameDriver = bakery_driver_photo_authorize($db, ['id' => 9, 'role_slug' => 'driver', 'driver_id' => $marcosId], $gatePhotoId, '');
    $columnDriver = bakery_driver_photo_authorize($db, ['id' => $userId, 'role_slug' => 'driver', 'driver_id' => $lauraId], $gatePhotoId, '');
    $assert(($filenameDriver['status'] ?? 0) === 403 || ($filenameDriver['status'] ?? 0) === 404, 'filename driver without a file on disk does not get someone else\'s bytes');
    $assert(($columnDriver['status'] ?? 0) === 403, 'the column driver cannot read a photo the filename gives to someone else');
    $assert(($filenameDriver['status'] ?? 0) !== 200, 'missing file is not served');
} finally {
    if ($created['photos']) {
        $ph = implode(',', array_fill(0, count($created['photos']), '?'));
        $db->prepare("DELETE FROM driver_photos WHERE id IN ($ph)")->execute($created['photos']);
    }
    if ($created['users']) {
        if (table_exists($db, 'time_clock_punches')) {
            $ph = implode(',', array_fill(0, count($created['users']), '?'));
            $db->prepare("DELETE FROM time_clock_punches WHERE user_id IN ($ph)")->execute($created['users']);
        }
        $ph = implode(',', array_fill(0, count($created['users']), '?'));
        $db->prepare("DELETE FROM users WHERE id IN ($ph)")->execute($created['users']);
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
    if (is_file($photoFile)) {
        unlink($photoFile);
    }
    $photoDir = dirname($photoFile);
    if (is_dir($photoDir)) {
        @rmdir($photoDir);
    }
}

echo "\n=== HTTP logged out ===\n";
$server = journey_test_server($root, 8098);
if ($server === null) {
    $assert(false, 'php -S started for access checks');
} else {
    $page = journey_test_status('http://127.0.0.1:8098/route_photo_journey.php');
    $photo = journey_test_status('http://127.0.0.1:8098/driver_photo.php?file=status-proof.jpg');
    $assert(in_array($page, [302, 303, 403], true), 'logged-out journey page is 403 or redirect (got ' . $page . ')');
    $assert(in_array($photo, [302, 303, 403], true), 'logged-out photo handler is 403 or redirect (got ' . $photo . ')');
    journey_test_stop($server);
}

echo "\n" . ($fail === 0 ? 'OK' : 'FAILED ' . $fail) . "\n";
exit($fail === 0 ? 0 : 1);

/**
 * @return array<string, mixed>
 */
function journey_stop(
    int $plannedDriverId,
    int $customerId,
    string $name,
    ?string $completion,
    float $amount,
    int $pieces,
    int $routeOrder,
    string $status = 'delivered',
    int $standingDriverId = 0
): array {
    return [
        'planned_driver_id' => $plannedDriverId,
        'standing_driver_id' => $standingDriverId,
        'customer_id' => $customerId,
        'customer_name' => $name,
        'latitude' => 37.75 + ($customerId / 10000),
        'longitude' => -122.41 - ($customerId / 10000),
        'route_order' => $routeOrder,
        'delivery_status' => $status,
        'cancelled' => $status === 'cancelled',
        'completion_at' => $completion,
        'pieces' => $pieces,
        'amount' => $amount,
        'has_order' => true,
    ];
}

/**
 * @return array<string, mixed>
 */
function journey_photo(
    int $id,
    int $columnDriverId,
    int $customerId,
    string $type,
    string $createdAt,
    ?int $filenameDriverId = null
): array {
    $driverId = $filenameDriverId ?? $columnDriverId;
    $stamp = '20261020_000000';
    $filename = 'driver' . $driverId . '_customer' . $customerId . '_' . $type . '_' . $stamp . '_aaaaaaaa.jpg';
    return [
        'id' => $id,
        'driver_id' => $columnDriverId,
        'customer_id' => $customerId,
        'filename' => $filename,
        'file_path' => '2026/10/' . $filename,
        'photo_type' => $type,
        'created_at' => $createdAt,
    ];
}

function journey_test_status(string $url): int
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 5,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code;
}

/** @return resource|null */
function journey_test_server(string $root, int $port)
{
    $env = [
        'PATH' => (string)getenv('PATH'),
        'HOME' => (string)getenv('HOME'),
        'DB_NAME' => 'bakerysf_test',
        'USE_PROD_DB' => 'false',
        'APP_ENV' => 'local',
    ];
    $proc = proc_open(
        '"' . PHP_BINARY . '" -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root),
        [
            0 => ['pipe', 'r'],
            1 => ['file', sys_get_temp_dir() . '/bakery-journey-out.log', 'w'],
            2 => ['file', sys_get_temp_dir() . '/bakery-journey-err.log', 'w'],
        ],
        $pipes,
        $root,
        $env
    );
    if (!is_resource($proc)) {
        return null;
    }
    for ($i = 0; $i < 30; $i++) {
        usleep(100000);
        $code = journey_test_status('http://127.0.0.1:' . $port . '/login.php');
        if ($code > 0) {
            return $proc;
        }
    }
    proc_terminate($proc);
    return null;
}

function journey_test_stop($proc): void
{
    if (!is_resource($proc)) {
        return;
    }
    $status = proc_get_status($proc);
    if (!empty($status['pid'])) {
        posix_kill((int)$status['pid'], 15);
    }
    proc_close($proc);
}
